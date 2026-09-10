<?php

declare(strict_types=1);

namespace Kumwe\App\Application\Authorization;

use Kumwe\App\Application\Persistence\TransactionManager;
use Kumwe\App\Audit\Application\AuditRecorder;
use Kumwe\App\Audit\Domain\AuditEvent;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Context\Value\SiteContext;
use Kumwe\Extension\Spi\Identity\Domain\Capability;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;

/**
 * Declares site groups and changes their membership, under one capability and one audit trail.
 *
 * A group is the object an operator inspects, changes and audits, which is the whole reason ownership is
 * held at a declared scope instead of accumulated in per-row sharing lists. Both inclusion and exclusion
 * pass through here, so neither can happen quietly: the same installation-global capability gates both,
 * and each leaves an entry naming the group, the site and the membership the declaration ended with.
 *
 * Excluding a site withdraws its reach into everything the group owns, which is exactly what exclusion
 * means and is therefore not guarded further. What is guarded is emptying a group entirely, because a
 * group with no members owns resources no site can reach; that is refused rather than allowed to strand
 * them silently.
 *
 * @since  2.0.0
 */
final readonly class SiteGroupAdministration
{
    /**
     * Capability every declaration change is gated on.
     *
     * @var    string
     * @since  2.0.0
     */
    private const CAPABILITY = 'sites.group.manage';

    /**
     * Wire the service to the gateway, registries and audit sink it decides and records with.
     *
     * @param  AuthorizationGateway  $authorization  Guard consulted before any declaration is read or written.
     * @param  SiteGroupRegistry     $groups         Read side the current declaration is resolved from.
     * @param  SiteGroupWriter       $writer         Write side the declaration is stored through.
     * @param  TransactionManager    $transactions   Scope the declaration change and its audit share.
     * @param  AuditRecorder         $audit          Sink every declaration change is recorded to.
     * @param  ClockInterface        $clock          Source of the instant recorded on the audit entry.
     *
     * @since  2.0.0
     */
    public function __construct(
        private AuthorizationGateway $authorization,
        private SiteGroupRegistry $groups,
        private SiteGroupWriter $writer,
        private TransactionManager $transactions,
        private AuditRecorder $audit,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Declare a group, or replace an existing declaration's membership wholesale.
     *
     * @param   ExecutionContext  $context     Caller identity, site and provenance the change runs under.
     * @param   string            $identifier  Identifier the group is declared under.
     * @param   string            $name        Operator-facing label for the group.
     * @param   list<string>      $sites       Complete membership the declaration should end with.
     *
     * @return  SiteGroup  The stored declaration.
     *
     * @throws  AuthorizationDenied  When the caller may not administer group declarations.
     * @throws  \InvalidArgumentException  When the identifier, name or membership is invalid.
     *
     * @since   2.0.0
     */
    public function define(
        ExecutionContext $context,
        string $identifier,
        string $name,
        array $sites,
    ): SiteGroup {
        $group = new SiteGroup($identifier, $name, $sites);
        // A group being declared for the first time has no ownership row to resolve, so the decision is
        // taken against the family. The capability is installation-global either way.
        $this->assertAllowedOn($context, AuthorizationResource::collection('site_group'));

        return $this->transactions->transactional(function () use ($context, $group): SiteGroup {
            $this->writer->save($group);
            $this->record($context, 'sites.group.define', $group->identifier, [
                'members' => $group->members,
            ]);

            return $group;
        });
    }

    /**
     * Bring one site into a declared group.
     *
     * @param   ExecutionContext  $context     Caller identity, site and provenance the change runs under.
     * @param   string            $identifier  Identifier of the declared group.
     * @param   SiteContext       $site        Site being included.
     *
     * @return  void
     *
     * @throws  AuthorizationDenied  When the caller may not administer group declarations.
     * @throws  SiteGroupUnknown  When no such group is declared.
     *
     * @since   2.0.0
     */
    public function addSite(ExecutionContext $context, string $identifier, SiteContext $site): void
    {
        $this->assertAllowed($context, $identifier);
        $this->transactions->transactional(function () use ($context, $identifier, $site): void {
            $this->writer->addSite($identifier, $site);
            $this->record($context, 'sites.group.include', $identifier, [
                'site' => $site->identifier(),
            ]);
        });
    }

    /**
     * Take one site back out of a declared group, refusing to leave the group with no members.
     *
     * @param   ExecutionContext  $context     Caller identity, site and provenance the change runs under.
     * @param   string            $identifier  Identifier of the declared group.
     * @param   SiteContext       $site        Site being excluded.
     *
     * @return  void
     *
     * @throws  AuthorizationDenied  When the caller may not administer group declarations.
     * @throws  SiteGroupUnknown  When no such group is declared.
     * @throws  OwnershipNarrowingRefused  When the site is the group's last member, since everything the
     *          group owns would become unreachable.
     *
     * @since   2.0.0
     */
    public function removeSite(ExecutionContext $context, string $identifier, SiteContext $site): void
    {
        $this->assertAllowed($context, $identifier);
        $group = $this->groups->group($identifier);
        if ($group->members === [$site->identifier()]) {
            throw new OwnershipNarrowingRefused(
                AuthorizationResource::item('site_group', $identifier),
                OwnershipScope::group($group),
                [$site->identifier()],
            );
        }

        $this->transactions->transactional(function () use ($context, $identifier, $site): void {
            $this->writer->removeSite($identifier, $site);
            $this->record($context, 'sites.group.exclude', $identifier, [
                'site' => $site->identifier(),
            ]);
        });
    }

    /**
     * Require the installation-global capability that gates every declaration change.
     *
     * A declaration change is authorized against the group as a resource, so the decision, its policy and
     * its reason land in the authorization trail beside every other decision rather than in a private log.
     *
     * @param   ExecutionContext  $context     Caller whose authority is being checked.
     * @param   string            $identifier  Identifier of the group being changed.
     *
     * @return  void
     *
     * @throws  AuthorizationDenied  When the caller may not administer group declarations.
     *
     * @since   2.0.0
     */
    private function assertAllowed(ExecutionContext $context, string $identifier): void
    {
        $this->assertAllowedOn($context, AuthorizationResource::item('site_group', $identifier));
    }

    /**
     * Require the gating capability against an exact authorization target.
     *
     * @param   ExecutionContext       $context   Caller whose authority is being checked.
     * @param   AuthorizationResource  $resource  Group, or the group family for a first declaration.
     *
     * @return  void
     *
     * @throws  AuthorizationDenied  When the caller may not administer group declarations.
     *
     * @since   2.0.0
     */
    private function assertAllowedOn(ExecutionContext $context, AuthorizationResource $resource): void
    {
        $this->authorization->assertAllowed($context, Capability::fromString(self::CAPABILITY), $resource);
    }

    /**
     * Write one declaration change to the audit trail.
     *
     * @param   ExecutionContext      $context     Caller recorded as the actor.
     * @param   string                $action      Machine token naming the declaration change.
     * @param   string                $identifier  Identifier of the group that changed.
     * @param   array<string, mixed>  $metadata    Context describing what changed.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function record(
        ExecutionContext $context,
        string $action,
        string $identifier,
        array $metadata,
    ): void {
        $this->audit->record(new AuditEvent(
            Uuid::uuid7()->toString(),
            $this->clock->now(),
            $context->actorId(),
            $action,
            'site_group',
            $identifier,
            'success',
            [...$metadata, 'acting_site' => $context->site()->identifier()],
        ));
    }
}
