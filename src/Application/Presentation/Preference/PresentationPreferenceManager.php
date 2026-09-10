<?php

declare(strict_types=1);

namespace Kumwe\App\Application\Presentation\Preference;

use InvalidArgumentException;
use Kumwe\App\Application\Authorization\AuthorizationDenied;
use Kumwe\App\Application\Authorization\AuthorizationGateway;
use Kumwe\App\Application\Authorization\AuthorizationResource;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\App\Application\Authorization\MembershipContextValidator;
use Kumwe\App\Application\Persistence\TransactionManager;
use Kumwe\App\Audit\Application\AuditRecorder;
use Kumwe\App\Audit\Domain\AuditEvent;
use Kumwe\Extension\Spi\Contribution\ContributionOwner;
use Kumwe\Extension\Spi\Identity\Domain\Capability;
use Kumwe\App\InterfaceStandard\CustomizationScope;
use Kumwe\App\InterfaceStandard\PresentationPreference;
use Kumwe\App\InterfaceStandard\PresentationPreferenceKey;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use RuntimeException;

/**
 * Audited application service for preference create, update, import, export, and reset operations.
 *
 * Mutation admission crosses four independent fences before persistence: portable value validation,
 * current owner-bound surface permission, actor/scope authorization, and atomic optimistic versioning.
 * Imports are parsed under their source compatibility metadata but rebased onto the destination row's
 * next version and attributed to the importing actor, preventing an export from impersonating its
 * original author or overwriting a concurrent destination change.
 *
 * @since  2.0.0
 */
final readonly class PresentationPreferenceManager
{
    /**
     * Bind preference mutations to storage, live KIS policy, authorization, audit, time, and transactions.
     *
     * @param  PresentationPreferenceRepository   $preferences    Atomic persistence boundary.
     * @param  PresentationPreferencePolicy       $policy         Current surface owner and customization admission.
     * @param  AuthorizationGateway               $authorization  Canonical capability decision boundary.
     * @param  AuditRecorder                      $audit          Recorder sharing the preference transaction.
     * @param  ClockInterface                     $clock          Source of durable update and audit timestamps.
     * @param  TransactionManager                 $transactions   Atomic scope joining preference and audit writes.
     * @param  MembershipContextValidator         $memberships    Live authority for role/workspace scope identity.
     * @param  PresentationAccessGroupRepository  $accessGroups   Canonical role projection and lock boundary.
     *
     * @since  2.0.0
     */
    public function __construct(
        private PresentationPreferenceRepository $preferences,
        private PresentationPreferencePolicy $policy,
        private AuthorizationGateway $authorization,
        private AuditRecorder $audit,
        private ClockInterface $clock,
        private TransactionManager $transactions,
        private MembershipContextValidator $memberships,
        private PresentationAccessGroupRepository $accessGroups,
    ) {
    }

    /**
     * Create or update one safe presentation value at its exact hierarchy layer.
     *
     * @param   ExecutionContext           $context          Authenticated actor and current site.
     * @param   ContributionOwner          $owner            Expected active owner of the surface.
     * @param   PresentationPreferenceKey  $key              Exact surface, slot, scope, and scope identity.
     * @param   mixed                      $value            Candidate slot-specific value.
     * @param   int                        $expectedVersion  Zero for creation, otherwise last observed version.
     *
     * @return  PresentationPreference  Newly persisted exact successor record.
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor may not manage the layer.
     * @throws  PresentationPreferenceVersionConflict  When another mutation changed the row.
     * @throws  InvalidArgumentException  When scope, current surface admission, value, or version is invalid.
     *
     * @since   2.0.0
     */
    public function put(
        ExecutionContext $context,
        ContributionOwner $owner,
        PresentationPreferenceKey $key,
        mixed $value,
        int $expectedVersion,
    ): PresentationPreference {
        return $this->persist(
            $context,
            $owner,
            $key,
            $value,
            $expectedVersion,
            $expectedVersion === 0 ? 'interface.preference.create' : 'interface.preference.update',
            [],
        );
    }

    /**
     * Revalidate and rebase a portable preference export into the destination hierarchy.
     *
     * The source version remains audit metadata only. The destination expected version controls the
     * compare-and-swap and the importing actor becomes `updated_by`, which makes import portable without
     * weakening concurrency or attribution.
     *
     * @param   ExecutionContext      $context          Authenticated importing actor and current site.
     * @param   array<string, mixed>  $document         Exact portable preference document.
     * @param   int                   $expectedVersion  Destination version, or zero when no record exists.
     *
     * @return  PresentationPreference  Revalidated destination record with a rebased next version.
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor may not manage the layer.
     * @throws  PresentationPreferenceVersionConflict  When the destination version changed.
     * @throws  InvalidArgumentException  When compatibility, owner, scope, current admission, or value is invalid.
     *
     * @since   2.0.0
     */
    public function import(
        ExecutionContext $context,
        array $document,
        int $expectedVersion,
    ): PresentationPreference {
        $source = PresentationPreference::fromArray($document);

        return $this->persist(
            $context,
            $source->owner(),
            PresentationPreferenceKey::fromPreference($source),
            $source->value()->value(),
            $expectedVersion,
            'interface.preference.import',
            [
                'source_version' => $source->version(),
                'source_updated_by' => $source->updatedBy(),
                'source_updated_at' => $source->toArray()['updated_at'],
                'source_sha256' => hash('sha256', json_encode($source->toArray(), JSON_THROW_ON_ERROR)),
            ],
        );
    }

    /**
     * Export one authorized current record in the portable schema.
     *
     * Export uses mutation-level authorization because it contains update attribution, unlike the safe
     * effective value returned by the resolver. An absent row returns null without inventing a default.
     *
     * @param   ExecutionContext           $context  Authenticated actor and current site.
     * @param   ContributionOwner          $owner    Expected active owner of the surface.
     * @param   PresentationPreferenceKey  $key      Exact record to export.
     *
     * @return  ?array<string, mixed>  Portable preference document, or null when the layer has no record.
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor may not manage the layer.
     * @throws  InvalidArgumentException  When scope or current surface admission is invalid.
     *
     * @since   2.0.0
     */
    public function export(
        ExecutionContext $context,
        ContributionOwner $owner,
        PresentationPreferenceKey $key,
    ): ?array {
        $this->policy->assertAllowed($key->surface, $owner, $key->slot, $key->scope);
        $this->authorize($context, $key);
        $preference = $this->preferences->find($key);
        if ($preference === null) {
            return null;
        }
        $this->assertCurrentOwner($preference, $owner);

        return $preference->toArray();
    }

    /**
     * Read a bounded authorized key set through one preference-store query.
     *
     * Role keys may be supplied only with typed groups returned by the canonical bounded catalogue. The core
     * `users.manage` capability is global-only and its role policy is installation-global, so one collection
     * decision proves the same grant required by every canonical role read without an item-by-item ownership
     * query and decision-log amplification. Mutation still rechecks the exact role and locks its existence.
     * A denied role collection is omitted, while authorized absent rows remain present with null. A stored row
     * whose owner went stale is returned as null exactly like an absent row, matching the resolver's
     * `kis.preference.owner-stale` degradation, so one legacy row cannot fail the whole read.
     *
     * @param   ExecutionContext                 $context            Authenticated actor and current site.
     * @param   ContributionOwner                $owner              Expected current surface owner.
     * @param   list<PresentationPreferenceKey>  $keys               Unique exact records, up to 256.
     * @param   list<PresentationAccessGroup>    $knownAccessGroups  Canonical groups used by role keys.
     *
     * @return  array<string, PresentationPreference|null>  Authorized rows keyed by durable identity.
     *
     * @throws  InvalidArgumentException  When keys or groups are malformed, duplicated, unbounded, or unrelated.
     * @throws  RuntimeException  When persistence returns a row outside the exact authorized key set.
     *
     * @since   2.0.0
     */
    public function readMany(
        ExecutionContext $context,
        ContributionOwner $owner,
        array $keys,
        array $knownAccessGroups = [],
    ): array {
        if (
            !array_is_list($keys)
            || count($keys) > 256
            || !array_is_list($knownAccessGroups)
            || count($knownAccessGroups) > 256
        ) {
            throw new InvalidArgumentException('A preference export batch must be bounded lists.');
        }
        $known = [];
        foreach ($knownAccessGroups as $group) {
            if (!$group instanceof PresentationAccessGroup || isset($known[$group->id])) {
                throw new InvalidArgumentException('A preference export batch contains an invalid access group.');
            }
            $known[$group->id] = $group;
        }

        $authorizedKeys = [];
        $authorized = [];
        $preferences = [];
        $roleCatalogAllowed = null;
        $seen = [];
        foreach ($keys as $key) {
            if (!$key instanceof PresentationPreferenceKey || isset($seen[$key->auditSubjectId()])) {
                throw new InvalidArgumentException('A preference export batch contains an invalid key.');
            }
            $seen[$key->auditSubjectId()] = true;
            $this->policy->assertAllowed($key->surface, $owner, $key->slot, $key->scope);
            $scopeId = $key->scopeId;
            $roleId = $scopeId === null ? null : PresentationAccessGroup::roleIdFromIdentifier($scopeId);
            if ($roleId === null) {
                try {
                    $this->authorize($context, $key);
                } catch (AuthorizationDenied) {
                    continue;
                }
            } else {
                if ($scopeId === null) {
                    throw new InvalidArgumentException('A batched access-group identity is inconsistent.');
                }
                if (!array_key_exists($scopeId, $known)) {
                    throw new InvalidArgumentException('A batched access-group read requires a canonical row.');
                }
                if ($roleCatalogAllowed === null) {
                    try {
                        $this->authorization->assertAllowed(
                            $context,
                            Capability::fromString('users.manage'),
                            AuthorizationResource::collection('role'),
                        );
                        $roleCatalogAllowed = true;
                    } catch (AuthorizationDenied) {
                        $roleCatalogAllowed = false;
                    }
                }
                if (!$roleCatalogAllowed) {
                    continue;
                }
            }
            $authorizedKeys[] = $key;
            $identity = $key->auditSubjectId();
            $authorized[$identity] = $key;
            $preferences[$identity] = null;
        }

        foreach ($this->preferences->findMany($authorizedKeys) as $identity => $preference) {
            $key = $authorized[$identity] ?? null;
            if ($key === null || !PresentationPreferenceKey::fromPreference($preference)->equals($key)) {
                throw new RuntimeException('The preference repository returned an unauthorized batch row.');
            }
            if ($preference->owner()->identifier() !== $owner->identifier()) {
                // A stored row whose owner went stale degrades like an absent row on the read path, the same
                // way the resolver reports `kis.preference.owner-stale`; mutation still refuses the stale owner.
                continue;
            }
            $preferences[$identity] = $preference;
        }

        return $preferences;
    }

    /**
     * Delete one exact layer so the resolver reveals the next valid lower preference or KIS default.
     *
     * @param   ExecutionContext           $context          Authenticated actor and current site.
     * @param   ContributionOwner          $owner            Exact owner stored with the removable record.
     * @param   PresentationPreferenceKey  $key              Exact layer to reset.
     * @param   int                        $expectedVersion  Positive version last observed by the actor.
     *
     * @return  void
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor may not manage the layer.
     * @throws  PresentationPreferenceVersionConflict  When the record is absent or its version changed.
     * @throws  InvalidArgumentException  When scope, stored owner, or version is invalid.
     *
     * @since   2.0.0
     */
    public function reset(
        ExecutionContext $context,
        ContributionOwner $owner,
        PresentationPreferenceKey $key,
        int $expectedVersion,
    ): void {
        if ($expectedVersion < 1) {
            throw new InvalidArgumentException('A KIS preference reset requires a positive expected version.');
        }
        $this->authorize($context, $key);
        $current = $this->preferences->find($key);
        if ($current !== null) {
            $this->assertCurrentOwner($current, $owner);
        }
        $this->transactions->transactional(function () use ($context, $expectedVersion, $key, $owner): void {
            $this->authorize($context, $key, true);
            $this->preferences->delete($key, $owner, $expectedVersion);
            $this->audit->record($this->auditEvent(
                $context,
                $owner,
                $key,
                'interface.preference.reset',
                ['from_version' => $expectedVersion, 'to_version' => null],
            ));
        });
    }

    /**
     * Validate, authorize, compare-and-swap, and audit a create, update, or import.
     *
     * @param   ExecutionContext           $context          Authenticated actor and current site.
     * @param   ContributionOwner          $owner            Expected active owner of the surface.
     * @param   PresentationPreferenceKey  $key              Exact destination layer.
     * @param   mixed                      $value            Candidate slot-specific value.
     * @param   int                        $expectedVersion  Destination version, or zero for creation.
     * @param   string                     $action           Audit action naming this mutation kind.
     * @param   array<string, mixed>       $metadata         Additional safe import provenance.
     *
     * @return  PresentationPreference  Persisted exact successor record.
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor may not manage the layer.
     * @throws  InvalidArgumentException  When input or the existing record owner is invalid.
     * @throws  PresentationPreferenceVersionConflict  When the destination version changed.
     *
     * @since   2.0.0
     */
    private function persist(
        ExecutionContext $context,
        ContributionOwner $owner,
        PresentationPreferenceKey $key,
        mixed $value,
        int $expectedVersion,
        string $action,
        array $metadata,
    ): PresentationPreference {
        if ($expectedVersion < 0 || $expectedVersion === PHP_INT_MAX) {
            throw new InvalidArgumentException('A KIS preference expected version cannot be advanced.');
        }
        $this->policy->assertAllowed($key->surface, $owner, $key->slot, $key->scope);
        $this->authorize($context, $key);
        $preference = PresentationPreference::create(
            $key->surface,
            $owner,
            $key->scope,
            $key->scopeId,
            $key->slot,
            $value,
            $expectedVersion + 1,
            $context->actorId(),
            $this->clock->now(),
        );
        $current = $this->preferences->find($key);
        if ($current !== null) {
            $this->assertCurrentOwner($current, $owner);
        }

        return $this->transactions->transactional(function () use (
            $action,
            $context,
            $expectedVersion,
            $key,
            $metadata,
            $owner,
            $preference,
        ): PresentationPreference {
            $this->authorize($context, $key, true);
            $this->preferences->save($preference, $expectedVersion);
            $this->audit->record($this->auditEvent(
                $context,
                $owner,
                $key,
                $action,
                [
                    'from_version' => $expectedVersion === 0 ? null : $expectedVersion,
                    'to_version' => $preference->version(),
                    ...$metadata,
                ],
            ));

            return $preference;
        });
    }

    /**
     * Enforce layer identity and actor authority before any preference mutation or attributed export.
     *
     * A user may manage only its own user-scoped value. Site defaults require site-scoped settings
     * authority, administrator defaults require installation-global administrator-theme authority, and
     * workspace defaults additionally require the exact live membership selection carried by the execution
     * context. A `role:<uuid>` access-group default instead requires `users.manage` on that exact current role.
     * Mutation paths call this both before and inside their write transaction.
     *
     * @param   ExecutionContext           $context         Authenticated actor and current site.
     * @param   PresentationPreferenceKey  $key             Layer being read with attribution or mutated.
     * @param   bool                       $lockMembership  Whether live workspace or role identity is locked for write.
     *
     * @return  void
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When capability policy refuses access.
     * @throws  InvalidArgumentException  When a layer identifier is absent, foreign to the site, or unexpected.
     *
     * @since   2.0.0
     */
    private function authorize(
        ExecutionContext $context,
        PresentationPreferenceKey $key,
        bool $lockMembership = false,
    ): void {
        if ($key->scope === CustomizationScope::User) {
            if ($context->principal() === null || $key->scopeId !== $context->actorId()) {
                throw new InvalidArgumentException('A user preference may target only the authenticated actor.');
            }
            return;
        }
        if ($key->scope === CustomizationScope::Administrator) {
            $this->authorization->assertAllowed(
                $context,
                Capability::fromString('themes.administrator.manage'),
                AuthorizationResource::item('theme', 'administrator'),
            );
            return;
        }
        if ($key->scope === CustomizationScope::Site) {
            if ($key->scopeId !== $context->site()->identifier()) {
                throw new InvalidArgumentException('A site preference must target the execution context site.');
            }
            $this->assertSiteSettingsAuthority($context);
            return;
        }

        $scopeId = $key->scopeId;
        if ($scopeId === null) {
            throw new InvalidArgumentException('A role/workspace preference requires a named scope identity.');
        }
        $roleId = PresentationAccessGroup::roleIdFromIdentifier($scopeId);
        if ($roleId !== null) {
            $this->authorization->assertAllowed(
                $context,
                Capability::fromString('users.manage'),
                AuthorizationResource::item('role', $roleId),
            );
            if (!$this->accessGroups->exists($scopeId, $lockMembership)) {
                throw new InvalidArgumentException(
                    'A role/workspace preference requires a current presentation access group.',
                );
            }
            return;
        }
        if (str_starts_with($scopeId, 'role:')) {
            throw new InvalidArgumentException('A presentation access-group preference identity is invalid.');
        }

        $membership = $context->membership();
        $workspace = $context->workspace();
        if (
            $membership === null
            || $workspace === null
            || $key->scopeId !== $workspace->identifier()
            || !$this->memberships->current(
                $context->actorId(),
                $context->site(),
                $membership,
                $lockMembership,
            )
        ) {
            throw new InvalidArgumentException(
                'A role/workspace preference requires the actor\'s current validated workspace context.',
            );
        }
        $this->assertSiteSettingsAuthority($context);
    }

    /**
     * Require site-scoped settings authority for a site or validated role/workspace default.
     *
     * @param   ExecutionContext  $context  Authenticated actor and exact current site.
     *
     * @return  void
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When site policy refuses management.
     *
     * @since   2.0.0
     */
    private function assertSiteSettingsAuthority(ExecutionContext $context): void
    {
        $this->authorization->assertAllowed(
            $context,
            Capability::fromString('settings.manage'),
            AuthorizationResource::item('site', $context->site()->identifier()),
        );
    }

    /**
     * Refuse to mutate or export a stale record that belongs to another active owner generation.
     *
     * @param   PresentationPreference  $preference  Existing stored record.
     * @param   ContributionOwner       $owner       Expected current surface owner.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When stored and current owners differ.
     *
     * @since   2.0.0
     */
    private function assertCurrentOwner(
        PresentationPreference $preference,
        ContributionOwner $owner,
    ): void {
        if ($preference->owner()->identifier() !== $owner->identifier()) {
            throw new InvalidArgumentException('A stale KIS preference owner must be reset before mutation.');
        }
    }

    /**
     * Build one success event to store inside the same transaction as the preference mutation.
     *
     * @param   ExecutionContext           $context   Authenticated accountable actor.
     * @param   ContributionOwner          $owner     Current owner of the semantic surface.
     * @param   PresentationPreferenceKey  $key       Complete mutated preference identity.
     * @param   string                     $action    Stable audit action for the mutation kind.
     * @param   array<string, mixed>       $metadata  Version and optional import provenance.
     *
     * @return  AuditEvent  Validated event containing no preference value.
     *
     * @since   2.0.0
     */
    private function auditEvent(
        ExecutionContext $context,
        ContributionOwner $owner,
        PresentationPreferenceKey $key,
        string $action,
        array $metadata,
    ): AuditEvent {
        return new AuditEvent(
            Uuid::uuid7()->toString(),
            $this->clock->now(),
            $context->actorId(),
            $action,
            'interface-preference',
            $key->auditSubjectId(),
            'success',
            [
                'surface' => $key->surface->value(),
                'owner' => $owner->identifier(),
                'scope' => $key->scope->value,
                'scope_id' => $key->scopeId,
                'slot' => $key->slot->value,
                ...$metadata,
            ],
        );
    }
}
