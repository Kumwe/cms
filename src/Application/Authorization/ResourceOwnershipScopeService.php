<?php

declare(strict_types=1);

namespace Kumwe\App\Application\Authorization;

use Kumwe\Transaction\Contract\TransactionManager;
use Kumwe\App\Audit\Application\AuditRecorder;
use Kumwe\App\Audit\Domain\AuditEvent;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Extension\Spi\Identity\Domain\Capability;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;

/**
 * Changes which scope owns a living resource, in the one direction the caller asked for.
 *
 * Membership and sharing have to be changeable after the fact, or a business group ends up rewiring its
 * data every time it decides to share something. Nothing moves when a scope changes: the resource stays
 * exactly where it is and only its owner widens or narrows, so there is no migration, no window in which
 * the resource is unowned, and no transaction that spans sites.
 *
 * The two directions are deliberately not symmetric. Widening only adds reach, so it needs the
 * capability, the category's permission and an audit entry. Narrowing takes reach away from sites that
 * may already have built records around the resource, so it additionally proves that nothing in those
 * sites still refers to it and refuses with the referencing sites named when something does. An operator
 * who widens casually and expects to narrow casually will be told why, in advance, rather than
 * discovering stranded references afterwards. Leaving the installation scope is refused outright: its
 * membership is every site there is, so the guard would have nothing to look at and would answer that
 * nothing is stranded for the wrong reason.
 *
 * @since  2.0.0
 */
final readonly class ResourceOwnershipScopeService
{
    /**
     * Capability both directions are gated on.
     *
     * @var    string
     * @since  2.0.0
     */
    private const CAPABILITY = 'ownership.scope.manage';

    /**
     * Wire the service to the gateway, registry, catalogue and audit sink it decides and records with.
     *
     * @param  AuthorizationGateway          $authorization  Guard consulted before anything is read or written.
     * @param  ResourceSiteOwnership         $ownership      Resolver for the scope that owns the resource now.
     * @param  ResourceSiteOwnershipWriter   $writer         Registry the compare-and-set is issued against.
     * @param  ResourceOwnershipScopePolicy  $scopes         Catalogue holding this build's frozen category table.
     * @param  ResourceOwnershipReferences   $references     Inspector naming sites that would be stranded.
     * @param  TransactionManager            $transactions   Scope the ownership change and its audit share.
     * @param  AuditRecorder                 $audit          Sink both directions are recorded to.
     * @param  ClockInterface                $clock          Source of the instant recorded on the audit entry.
     *
     * @since  2.0.0
     */
    public function __construct(
        private AuthorizationGateway $authorization,
        private ResourceSiteOwnership $ownership,
        private ResourceSiteOwnershipWriter $writer,
        private ResourceOwnershipScopePolicy $scopes,
        private ResourceOwnershipReferences $references,
        private TransactionManager $transactions,
        private AuditRecorder $audit,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Bring a resource into a wider owner: a site into a group, or a group into the installation.
     *
     * @param   ExecutionContext       $context   Caller identity, site and provenance the change runs under.
     * @param   AuthorizationResource  $resource  Resource whose owner is widening.
     * @param   OwnershipScope         $target    Wider owner it moves to; must contain every current site.
     *
     * @return  OwnershipScope  The scope now recorded as the owner.
     *
     * @throws  AuthorizationDenied  When the caller may not change this resource's scope.
     * @throws  OwnershipScopeNotPermitted  When the resource's category may not be owned at that level.
     * @throws  OwnershipScopeChangeRejected  When the target is not strictly wider than the current owner.
     * @throws  AuthorizationResourceOwnershipUnknown  When the resource has no ownership record.
     * @throws  ResourceSiteOwnershipConflict  When another caller changed the owner first.
     *
     * @since   2.0.0
     */
    public function widen(
        ExecutionContext $context,
        AuthorizationResource $resource,
        OwnershipScope $target,
    ): OwnershipScope {
        $this->authorization->assertAllowed($context, Capability::fromString(self::CAPABILITY), $resource);
        $current = $this->ownership->scopeFor($resource);
        if (!$this->widens($current, $target)) {
            throw new OwnershipScopeChangeRejected($resource, $current, $target, 'widen');
        }

        return $this->apply($context, $resource, $current, $target, 'ownership.scope.widen', []);
    }

    /**
     * Take a resource back out of a wider owner, refusing while another member site still refers to it.
     *
     * @param   ExecutionContext       $context   Caller identity, site and provenance the change runs under.
     * @param   AuthorizationResource  $resource  Resource whose owner is narrowing.
     * @param   OwnershipScope         $target    Narrower owner it moves to; every one of its sites must
     *          already be inside the current owner.
     *
     * @return  OwnershipScope  The scope now recorded as the owner.
     *
     * @throws  AuthorizationDenied  When the caller may not change this resource's scope.
     * @throws  OwnershipScopeNotPermitted  When the resource's category may not be owned at that level.
     * @throws  OwnershipScopeChangeRejected  When the target is not strictly narrower than the current owner.
     * @throws  OwnershipNarrowingUnbounded  When the current owner is the installation, whose membership
     *          cannot be enumerated and therefore cannot be proven safe to leave.
     * @throws  OwnershipNarrowingRefused  When a site about to lose reach still refers to the resource.
     * @throws  AuthorizationResourceOwnershipUnknown  When the resource has no ownership record.
     * @throws  ResourceSiteOwnershipConflict  When another caller changed the owner first.
     *
     * @since   2.0.0
     */
    public function narrow(
        ExecutionContext $context,
        AuthorizationResource $resource,
        OwnershipScope $target,
    ): OwnershipScope {
        $this->authorization->assertAllowed($context, Capability::fromString(self::CAPABILITY), $resource);
        $current = $this->ownership->scopeFor($resource);
        if (!$this->widens($target, $current)) {
            throw new OwnershipScopeChangeRejected($resource, $current, $target, 'narrow');
        }
        if ($current->isInstallation()) {
            throw new OwnershipNarrowingUnbounded($resource, $current);
        }

        $losing = array_values(array_diff($current->sites, $target->sites));
        $referencing = $losing === [] ? [] : $this->references->sitesReferencing($resource, $losing);
        if ($referencing !== []) {
            throw new OwnershipNarrowingRefused($resource, $target, $referencing);
        }

        return $this->apply($context, $resource, $current, $target, 'ownership.scope.narrow', [
            'released_sites' => $losing,
        ]);
    }

    /**
     * Prove the pairing, write the compare-and-set and record the change, all in one transaction.
     *
     * @param   ExecutionContext       $context   Caller whose identity is recorded against the change.
     * @param   AuthorizationResource  $resource  Resource whose owner is changing.
     * @param   OwnershipScope         $current   Owner the change is issued against.
     * @param   OwnershipScope         $target    Owner it moves to.
     * @param   string                 $action    Audit action naming the direction.
     * @param   array<string, mixed>   $extra     Additional audit metadata for that direction.
     *
     * @return  OwnershipScope  The scope now recorded as the owner.
     *
     * @throws  OwnershipScopeNotPermitted  When the resource's category may not be owned at that level.
     * @throws  ResourceSiteOwnershipConflict  When another caller changed the owner first.
     *
     * @since   2.0.0
     */
    private function apply(
        ExecutionContext $context,
        AuthorizationResource $resource,
        OwnershipScope $current,
        OwnershipScope $target,
        string $action,
        array $extra,
    ): OwnershipScope {
        $owner = ResourceOwnership::of($resource, $target, $this->scopes);

        return $this->transactions->transactional(function () use (
            $context,
            $resource,
            $current,
            $target,
            $owner,
            $action,
            $extra,
        ): OwnershipScope {
            $this->writer->reassign($owner, $current);
            $this->audit->record(new AuditEvent(
                Uuid::uuid7()->toString(),
                $this->clock->now(),
                $context->actorId(),
                $action,
                $resource->type(),
                $resource->identifier(),
                'success',
                [
                    'from_scope' => $current->describe(),
                    'to_scope' => $target->describe(),
                    'acting_site' => $context->site()->identifier(),
                    ...$extra,
                ],
            ));

            return $target;
        });
    }

    /**
     * Whether one scope reaches strictly further than another, in level and in membership.
     *
     * Both halves matter. A group is a wider *level* than a site, but a group that does not contain the
     * site it is replacing would take reach away as well as add it, and that is a move neither direction
     * can make safe on its own.
     *
     * @param   OwnershipScope  $narrower  Scope expected to be contained.
     * @param   OwnershipScope  $wider     Scope expected to contain it.
     *
     * @return  bool  True only when the wider scope is a strictly higher level and covers every site of
     *          the narrower one.
     *
     * @since   2.0.0
     */
    private function widens(OwnershipScope $narrower, OwnershipScope $wider): bool
    {
        if (!$wider->level->widerThan($narrower->level)) {
            return false;
        }
        if ($wider->isInstallation()) {
            return true;
        }

        return array_diff($narrower->sites, $wider->sites) === [];
    }
}
