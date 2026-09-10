<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Support;

use DateTimeImmutable;
use Kumwe\Context\Value\MembershipContext;
use Kumwe\App\Application\Authorization\MembershipContextValidator;
use Kumwe\App\Application\Authorization\AuthorizationGateway;
use Kumwe\Context\Value\SiteContext;
use Kumwe\App\Application\Presentation\Dashboard\DashboardPreferenceService;
use Kumwe\App\Application\Presentation\Preference\PresentationAccessGroup;
use Kumwe\App\Application\Presentation\Preference\PresentationPreferenceManager;
use Kumwe\App\Application\Presentation\Preference\PresentationPreferencePolicy;
use Kumwe\App\Audit\Application\AuditRecorder;
use Kumwe\App\Audit\Domain\AuditEvent;
use Kumwe\Extension\Spi\Contribution\ContributionOwner;
use Kumwe\App\InterfaceStandard\CustomizationScope;
use Kumwe\App\InterfaceStandard\CustomizationSlot;
use Kumwe\App\InterfaceStandard\SurfaceId;
use Kumwe\App\Delivery\Http\Dashboard\DashboardPreferenceFormDecoder;
use Kumwe\App\Presentation\Application\Dashboard\DashboardPreferenceFormPresenter;
use Psr\Clock\ClockInterface;

/**
 * Wires the real dashboard preference service over deterministic in-memory test boundaries.
 *
 * @since  2.0.0
 */
final readonly class DashboardPreferenceTestRuntime
{
    /**
     * Preference rows written or seeded by the scenario.
     *
     * @var    InMemoryPresentationPreferenceRepository
     * @since  2.0.0
     */
    public InMemoryPresentationPreferenceRepository $preferences;

    /**
     * Canonical access-group projection used by manager authorization.
     *
     * @var    InMemoryPresentationAccessGroupRepository
     * @since  2.0.0
     */
    public InMemoryPresentationAccessGroupRepository $groups;

    /**
     * Real application service under test.
     *
     * @var    DashboardPreferenceService
     * @since  2.0.0
     */
    public DashboardPreferenceService $service;

    /**
     * Real presentation mapper used by dashboard form projection scenarios.
     *
     * @var    DashboardPreferenceFormPresenter
     * @since  2.0.0
     */
    public DashboardPreferenceFormPresenter $presenter;

    /**
     * Real browser-form decoder used by delivery and protocol-refusal scenarios.
     *
     * @var    DashboardPreferenceFormDecoder
     * @since  2.0.0
     */
    public DashboardPreferenceFormDecoder $decoder;

    /**
     * Build the runtime with optional stable role projections.
     *
     * @param  list<PresentationAccessGroup>  $groups         Live access groups visible to preference delivery.
     * @param  ?AuthorizationGateway          $authorization  Optional decision boundary for denial scenarios.
     *
     * @since  2.0.0
     */
    public function __construct(array $groups = [], ?AuthorizationGateway $authorization = null)
    {
        $this->preferences = new InMemoryPresentationPreferenceRepository();
        $this->groups = new InMemoryPresentationAccessGroupRepository($groups);
        $memberships = new DashboardPreferenceCurrentMemberships();
        $authorization ??= AuthorizationContext::gateway(memberships: $memberships);
        $manager = new PresentationPreferenceManager(
            $this->preferences,
            new DashboardPreferenceAllowAllPolicy(),
            $authorization,
            new DashboardPreferenceNullAuditRecorder(),
            new DashboardPreferenceFixedClock(),
            new ImmediateTransactionManager(),
            $memberships,
            $this->groups,
        );
        $this->service = new DashboardPreferenceService($manager, $this->groups, $authorization);
        $this->presenter = new DashboardPreferenceFormPresenter();
        $this->decoder = new DashboardPreferenceFormDecoder();
    }
}

/**
 * Admits every dashboard test surface while production authorization remains active in the manager.
 *
 * @since  2.0.0
 */
final readonly class DashboardPreferenceAllowAllPolicy implements PresentationPreferencePolicy
{
    /**
     * Admit every surface tuple supplied by a focused dashboard service test.
     *
     * @param   SurfaceId           $surface  Test dashboard surface.
     * @param   ContributionOwner   $owner    Test contribution owner.
     * @param   CustomizationSlot   $slot     Test dashboard slot.
     * @param   CustomizationScope  $scope    Test hierarchy layer.
     *
     * @return  bool  Always true.
     *
     * @since   2.0.0
     */
    public function allows(
        SurfaceId $surface,
        ContributionOwner $owner,
        CustomizationSlot $slot,
        CustomizationScope $scope,
    ): bool {
        return true;
    }

    /**
     * Accept every surface tuple supplied by a focused dashboard service test.
     *
     * @param   SurfaceId           $surface  Test dashboard surface.
     * @param   ContributionOwner   $owner    Test contribution owner.
     * @param   CustomizationSlot   $slot     Test dashboard slot.
     * @param   CustomizationScope  $scope    Test hierarchy layer.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function assertAllowed(
        SurfaceId $surface,
        ContributionOwner $owner,
        CustomizationSlot $slot,
        CustomizationScope $scope,
    ): void {
    }
}

/**
 * Accepts membership snapshots so unrelated workspace checks never obscure dashboard service scenarios.
 *
 * @since  2.0.0
 */
final readonly class DashboardPreferenceCurrentMemberships implements MembershipContextValidator
{
    /**
     * Treat each supplied membership snapshot as current for unrelated workspace test paths.
     *
     * @param   string             $subjectId   Actor owning the membership.
     * @param   SiteContext        $site        Exact site being evaluated.
     * @param   MembershipContext  $membership  Supplied membership snapshot.
     * @param   bool               $lock        Whether a mutation requested a pessimistic read.
     *
     * @return  bool  Always true.
     *
     * @since   2.0.0
     */
    public function current(
        string $subjectId,
        SiteContext $site,
        MembershipContext $membership,
        bool $lock = false,
    ): bool {
        return true;
    }
}

/**
 * Discards audit events after the manager proves it reached the canonical recorder boundary.
 *
 * @since  2.0.0
 */
final readonly class DashboardPreferenceNullAuditRecorder implements AuditRecorder
{
    /**
     * Accept a manager audit event without retaining irrelevant mutable fixture state.
     *
     * @param   AuditEvent  $event  Validated preference mutation event.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function record(AuditEvent $event): void
    {
    }
}

/**
 * Supplies a deterministic mutation instant.
 *
 * @since  2.0.0
 */
final readonly class DashboardPreferenceFixedClock implements ClockInterface
{
    /**
     * Return the fixed dashboard preference mutation instant.
     *
     * @return  DateTimeImmutable  Stable test time.
     *
     * @since   2.0.0
     */
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-08-15T12:00:00+00:00');
    }
}
