<?php

declare(strict_types=1);

namespace Kumwe\App\Application\Presentation\Dashboard;

use InvalidArgumentException;
use Kumwe\App\Application\Authorization\AuthorizationDenied;
use Kumwe\App\Application\Authorization\AuthorizationGateway;
use Kumwe\App\Application\Authorization\AuthorizationResource;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\App\Application\Presentation\Preference\PresentationAccessGroupRepository;
use Kumwe\App\Application\Presentation\Preference\PresentationPreferenceManager;
use Kumwe\Extension\Spi\Contribution\ContributionOwner;
use Kumwe\App\InterfaceStandard\CustomizationScope;
use Kumwe\App\InterfaceStandard\CustomizationSlot;
use Kumwe\App\InterfaceStandard\PresentationPreference;
use Kumwe\App\InterfaceStandard\PresentationPreferenceKey;
use Kumwe\App\InterfaceStandard\SurfaceArea;
use Kumwe\App\InterfaceStandard\SurfaceId;
use Kumwe\Extension\Spi\Identity\Domain\Capability;
use RuntimeException;

/**
 * Queries authorized dashboard preference state and executes strictly allowlisted mutations.
 *
 * This application use case owns authorization-sensitive role paging and audited mutations but no HTTP fields,
 * message identifiers, or form model. Delivery decodes commands and Presentation maps typed query state to views.
 *
 * @since  2.0.0
 */
final readonly class DashboardPreferenceService
{
    /**
     * Maximum access groups edited by one dashboard query.
     *
     * One role keeps the server-rendered editor compact even when both live choice catalogues are dense.
     * Deterministic paging and exact search make every role reachable without rendering repeated full forms.
     *
     * @var    int
     * @since  2.0.0
     */
    public const ACCESS_GROUP_PAGE_SIZE = 1;

    /**
     * Largest live catalogue one flat dashboard command may describe.
     *
     * @var    int
     * @since  2.0.0
     */
    private const MAXIMUM_FORM_ITEMS = 256;

    /**
     * Bind dashboard preferences to the canonical mutation boundary and role projection.
     *
     * @param  PresentationPreferenceManager      $preferences    Authorized row query and mutation service.
     * @param  PresentationAccessGroupRepository  $groups         Read-only canonical role projection.
     * @param  AuthorizationGateway               $authorization  Canonical collection-list decision boundary.
     *
     * @since  2.0.0
     */
    public function __construct(
        private PresentationPreferenceManager $preferences,
        private PresentationAccessGroupRepository $groups,
        private AuthorizationGateway $authorization,
    ) {
    }

    /**
     * Read personal rows and a bounded set of manageable canonical role rows.
     *
     * One installation-global collection decision precedes the role-catalogue query, so denial cannot disclose
     * role names, counts, or paging state. One preference batch then reads the displayed canonical row. The manager
     * repeats only that constant collection decision; exact-role authorization and existence locking remain writes.
     *
     * @param   ExecutionContext           $context              Authenticated actor requesting preference state.
     * @param   SurfaceArea                $area                 Administrator or portal delivery area.
     * @param   SurfaceId                  $surface              Exact dashboard surface.
     * @param   ContributionOwner          $owner                Current owner of the dashboard surface.
     * @param   bool                       $includeAccessGroups  Whether manageable canonical role rows are considered.
     * @param   ?DashboardPreferenceQuery  $query                Validated access-group page and search.
     *
     * @return  DashboardPreferenceState  Typed authorized rows and bounded role-browser evidence.
     *
     * @throws  InvalidArgumentException  When area or actor context cannot address dashboard preferences.
     * @throws  RuntimeException  When a collaborator returns an inconsistent authorized key set.
     *
     * @since   2.0.0
     */
    public function read(
        ExecutionContext $context,
        SurfaceArea $area,
        SurfaceId $surface,
        ContributionOwner $owner,
        bool $includeAccessGroups = false,
        ?DashboardPreferenceQuery $query = null,
    ): DashboardPreferenceState {
        self::assertArea($area);
        if ($context->principal() === null) {
            throw new InvalidArgumentException('Dashboard preference state requires an authenticated human actor.');
        }
        $query ??= new DashboardPreferenceQuery();

        if ($includeAccessGroups) {
            try {
                $this->authorization->assertAllowed(
                    $context,
                    Capability::fromString('users.manage'),
                    AuthorizationResource::collection('role'),
                );
            } catch (AuthorizationDenied) {
                $includeAccessGroups = false;
            }
        }
        if ($includeAccessGroups) {
            $groupCatalog = $this->groups->catalog(
                self::ACCESS_GROUP_PAGE_SIZE,
                ($query->page - 1) * self::ACCESS_GROUP_PAGE_SIZE,
                $query->search,
            );
            $groups = $groupCatalog->groups;
            $rawHasNext = $groupCatalog->hasNext();
        } else {
            $groups = [];
            $rawHasNext = false;
        }
        $personalWidgetKey = new PresentationPreferenceKey(
            $surface,
            CustomizationSlot::DashboardCards,
            CustomizationScope::User,
            $context->actorId(),
        );
        $personalShortcutKey = new PresentationPreferenceKey(
            $surface,
            CustomizationSlot::NavigationShortcuts,
            CustomizationScope::User,
            $context->actorId(),
        );
        $keys = [$personalWidgetKey, $personalShortcutKey];
        foreach ($groups as $group) {
            $keys[] = new PresentationPreferenceKey(
                $surface,
                CustomizationSlot::DashboardCards,
                CustomizationScope::RoleWorkspace,
                $group->id,
            );
            $keys[] = new PresentationPreferenceKey(
                $surface,
                CustomizationSlot::NavigationShortcuts,
                CustomizationScope::RoleWorkspace,
                $group->id,
            );
        }
        $preferences = $this->preferences->readMany($context, $owner, $keys, $groups);
        foreach ($groups as $group) {
            $widgetKey = new PresentationPreferenceKey(
                $surface,
                CustomizationSlot::DashboardCards,
                CustomizationScope::RoleWorkspace,
                $group->id,
            );
            $shortcutKey = new PresentationPreferenceKey(
                $surface,
                CustomizationSlot::NavigationShortcuts,
                CustomizationScope::RoleWorkspace,
                $group->id,
            );
            $hasWidgets = array_key_exists($widgetKey->auditSubjectId(), $preferences);
            $hasShortcuts = array_key_exists($shortcutKey->auditSubjectId(), $preferences);
            if ($hasWidgets !== $hasShortcuts) {
                throw new RuntimeException('A dashboard preference query returned a partial access-group scope.');
            }
            if (!$hasWidgets) {
                $groups = [];
                $rawHasNext = false;
                break;
            }
        }
        $accessGroups = [];
        foreach ($groups as $group) {
            $widgetKey = new PresentationPreferenceKey(
                $surface,
                CustomizationSlot::DashboardCards,
                CustomizationScope::RoleWorkspace,
                $group->id,
            );
            $shortcutKey = new PresentationPreferenceKey(
                $surface,
                CustomizationSlot::NavigationShortcuts,
                CustomizationScope::RoleWorkspace,
                $group->id,
            );
            $hasWidgets = array_key_exists($widgetKey->auditSubjectId(), $preferences);
            $hasShortcuts = array_key_exists($shortcutKey->auditSubjectId(), $preferences);
            $accessGroups[] = new DashboardPreferenceAccessGroupState(
                $surface,
                $group,
                self::preference($preferences, $widgetKey),
                self::preference($preferences, $shortcutKey),
            );
        }

        return new DashboardPreferenceState(
            $surface,
            $context->actorId(),
            self::preference($preferences, $personalWidgetKey),
            self::preference($preferences, $personalShortcutKey),
            $accessGroups,
            $includeAccessGroups,
            $query,
            $includeAccessGroups && $query->page > 1,
            $includeAccessGroups
                && $query->page < DashboardPreferenceQuery::MAXIMUM_PAGE
                && $rawHasNext,
            $includeAccessGroups
                && $query->page === DashboardPreferenceQuery::MAXIMUM_PAGE
                && $rawHasNext,
        );
    }

    /**
     * Execute one typed dashboard-card or navigation-shortcut save or reset.
     *
     * Both actor-facing areas may target the actor's user row or a canonical `role:<uuid>` row, whose live
     * existence and exact `users.manage` authorization are rechecked by the manager. Catalogue membership of
     * the submitted identifiers is the caller's duty — the complete live catalogue may exceed this service's
     * bounded-list ceiling, so delivery proves membership against the full catalogue (`assertMutation`) and
     * this method enforces that the supplied lists are bounded, unique, well-formed surface identifiers that
     * admit every submitted identifier before audited persistence is reached.
     *
     * @param   ExecutionContext             $context             Authenticated actor performing the mutation.
     * @param   SurfaceArea                  $area                Administrator or portal delivery area.
     * @param   SurfaceId                    $surface             Exact dashboard surface.
     * @param   ContributionOwner            $owner               Current owner of the dashboard surface.
     * @param   DashboardPreferenceMutation  $mutation            Typed command decoded by delivery.
     * @param   list<string>                 $allowedWidgetIds    Caller-validated admissible widget identifiers.
     * @param   list<string>                 $allowedShortcutIds  Caller-validated admissible navigation identifiers.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When target or the current live catalogue is invalid.
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When policy refuses the target.
     * @throws  \Kumwe\App\Application\Presentation\Preference\PresentationPreferenceVersionConflict
     *          When the exact stored row changed after its version was observed.
     *
     * @since   2.0.0
     */
    public function mutate(
        ExecutionContext $context,
        SurfaceArea $area,
        SurfaceId $surface,
        ContributionOwner $owner,
        DashboardPreferenceMutation $mutation,
        array $allowedWidgetIds,
        array $allowedShortcutIds,
    ): void {
        self::assertArea($area);
        $key = self::key($context, $surface, $mutation);
        if ($mutation->reset) {
            $this->preferences->reset($context, $owner, $key, $mutation->expectedVersion);
            return;
        }

        $allowed = self::allowlist($mutation->slot === CustomizationSlot::DashboardCards
            ? $allowedWidgetIds
            : $allowedShortcutIds);
        foreach ($mutation->submittedIds as $identifier) {
            if (!isset($allowed[$identifier])) {
                throw new InvalidArgumentException('A dashboard preference contains an unknown identifier.');
            }
        }
        $this->preferences->put(
            $context,
            $owner,
            $key,
            $mutation->selectedIds,
            $mutation->expectedVersion,
        );
    }

    /**
     * Read one exact typed entry from an authorized batch result.
     *
     * @param   array<string, PresentationPreference|null>  $preferences  Authorized exact batch result.
     * @param   PresentationPreferenceKey                   $key          Required durable identity.
     *
     * @return  ?PresentationPreference  Exact row, or null when this authorized layer inherits.
     *
     * @throws  RuntimeException  When the batch omitted or corrupted an authorized key.
     *
     * @since   2.0.0
     */
    private static function preference(array $preferences, PresentationPreferenceKey $key): ?PresentationPreference
    {
        $identity = $key->auditSubjectId();
        if (!array_key_exists($identity, $preferences)) {
            throw new RuntimeException('A dashboard preference query omitted an authorized scope.');
        }
        return $preferences[$identity];
    }

    /**
     * Build the only preference targets supported by dashboard delivery.
     *
     * @param   ExecutionContext             $context   Authenticated actor performing the mutation.
     * @param   SurfaceId                    $surface   Exact dashboard surface.
     * @param   DashboardPreferenceMutation  $mutation  Typed slot, scope, identity, and selection command.
     *
     * @return  PresentationPreferenceKey  Exact admitted user or role access-group key.
     *
     * @throws  InvalidArgumentException  When scope is unsupported, foreign, or malformed.
     *
     * @since   2.0.0
     */
    private static function key(
        ExecutionContext $context,
        SurfaceId $surface,
        DashboardPreferenceMutation $mutation,
    ): PresentationPreferenceKey {
        if ($context->principal() === null) {
            throw new InvalidArgumentException('Dashboard preference mutation requires an authenticated actor.');
        }
        if ($mutation->scope === CustomizationScope::User) {
            if ($mutation->scopeId !== $context->actorId()) {
                throw new InvalidArgumentException('A dashboard user preference may target only the actor.');
            }

            return new PresentationPreferenceKey(
                $surface,
                $mutation->slot,
                CustomizationScope::User,
                $mutation->scopeId,
            );
        }

        return new PresentationPreferenceKey(
            $surface,
            $mutation->slot,
            CustomizationScope::RoleWorkspace,
            $mutation->scopeId,
        );
    }

    /**
     * Validate and index the caller's live semantic catalogue.
     *
     * @param   list<string>  $identifiers  Current capability-filtered identifiers.
     *
     * @return  array<string, true>  Unique exact lookup.
     *
     * @throws  InvalidArgumentException  When the list is unbounded, malformed, or duplicated.
     *
     * @since   2.0.0
     */
    private static function allowlist(array $identifiers): array
    {
        if (!array_is_list($identifiers) || count($identifiers) > self::MAXIMUM_FORM_ITEMS) {
            throw new InvalidArgumentException('A dashboard preference allowlist is malformed or unbounded.');
        }
        $allowed = [];
        foreach ($identifiers as $identifier) {
            if (!is_string($identifier) || isset($allowed[$identifier])) {
                throw new InvalidArgumentException('A dashboard preference allowlist contains an invalid item.');
            }
            try {
                SurfaceId::fromString($identifier);
            } catch (InvalidArgumentException $exception) {
                throw new InvalidArgumentException(
                    'A dashboard preference allowlist contains an invalid item.',
                    0,
                    $exception,
                );
            }
            $allowed[$identifier] = true;
        }

        return $allowed;
    }

    /**
     * Restrict the use case to the two actor-facing dashboard areas.
     *
     * @param   SurfaceArea  $area  Candidate delivery area.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When public or template delivery is requested.
     *
     * @since   2.0.0
     */
    private static function assertArea(SurfaceArea $area): void
    {
        if (!in_array($area, [SurfaceArea::Administrator, SurfaceArea::Portal], true)) {
            throw new InvalidArgumentException(
                'Dashboard preference delivery is available only to administrator and portal areas.',
            );
        }
    }
}
