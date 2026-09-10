<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\InterfaceStandard;

use DateTimeImmutable;
use InvalidArgumentException;
use Kumwe\App\Application\Authorization\AuthorizationDenied;
use Kumwe\App\Application\Authorization\AuthorizationDecision;
use Kumwe\App\Application\Authorization\AuthorizationDecisionRecorder;
use Kumwe\App\Application\Authorization\AuthorizationResource;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Context\Value\MembershipContext;
use Kumwe\App\Application\Authorization\MembershipContextValidator;
use Kumwe\Context\Value\SiteContext;
use Kumwe\App\Application\Authorization\SystemIdentity;
use Kumwe\App\Application\Presentation\Preference\PresentationAccessGroup;
use Kumwe\App\Application\Presentation\Preference\PresentationPreferenceManager;
use Kumwe\App\Application\Presentation\Preference\PresentationPreferencePolicy;
use Kumwe\App\Application\Presentation\Preference\PresentationPreferenceRepository;
use Kumwe\App\Application\Presentation\Preference\PresentationPreferenceVersionConflict;
use Kumwe\App\Audit\Application\AuditRecorder;
use Kumwe\App\Audit\Domain\AuditEvent;
use Kumwe\Extension\Spi\Contribution\ContributionOwner;
use Kumwe\Extension\Spi\Identity\Domain\Capability;
use Kumwe\App\InterfaceStandard\CustomizationScope;
use Kumwe\App\InterfaceStandard\CustomizationSlot;
use Kumwe\App\InterfaceStandard\PresentationPreference;
use Kumwe\App\InterfaceStandard\PresentationPreferenceKey;
use Kumwe\App\InterfaceStandard\SurfaceArea;
use Kumwe\App\InterfaceStandard\SurfaceId;
use Kumwe\App\Presentation\Application\Preference\PresentationPreferenceContext;
use Kumwe\App\Presentation\Application\Preference\PresentationPreferenceResolver;
use Kumwe\App\Tests\Support\AuthorizationContext;
use Kumwe\App\Tests\Support\ImmediateTransactionManager;
use Kumwe\App\Tests\Support\InMemoryPresentationAccessGroupRepository;
use Kumwe\App\Tests\Support\InMemoryPresentationPreferenceRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use RuntimeException;

/**
 * Verifies preference mutations remain authorized, optimistic, portable, audited, and resettable.
 *
 * @since  2.0.0
 */
#[CoversClass(PresentationPreferenceManager::class)]
final class PresentationPreferenceManagerTest extends TestCase
{
    /**
     * Canonical role selected by access-group authorization scenarios.
     *
     * @var    string
     * @since  2.0.0
     */
    private const ACCESS_GROUP_ROLE_ID = '018f22e2-7c8b-7ab0-8f3a-88e8026bb303';

    /**
     * Proves an authenticated actor may create, update, export, and reset only its own user layer.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testSelfServiceMutationIsOptimisticAuditedAndResettable(): void
    {
        $repository = new InMemoryPresentationPreferenceRepository();
        $audit = new PreferenceAuditRecorder();
        $manager = $this->manager($repository, $audit);
        $context = AuthorizationContext::human([]);
        $owner = ContributionOwner::core();
        $key = new PresentationPreferenceKey(
            SurfaceId::fromString('core.administrator.settings'),
            CustomizationSlot::Density,
            CustomizationScope::User,
            $context->actorId(),
        );

        $created = $manager->put($context, $owner, $key, 'compact', 0);
        $updated = $manager->put($context, $owner, $key, 'touch', 1);
        $export = $manager->export($context, $owner, $key);

        self::assertSame(1, $created->version());
        self::assertSame(2, $updated->version());
        self::assertSame('touch', $export['value'] ?? null);
        self::assertSame([
            'interface.preference.create',
            'interface.preference.update',
        ], array_map(static fn (AuditEvent $event): string => $event->action(), $audit->events));

        $manager->reset($context, $owner, $key, 2);
        self::assertNull($repository->find($key));
        self::assertSame('interface.preference.reset', $audit->events[2]->action());

        $resolution = (new PresentationPreferenceResolver(
            $repository,
            new ManagerAllowAllPresentationPreferencePolicy(),
        ))->resolve(
            $key->surface,
            $owner,
            $key->slot,
            'comfortable',
            PresentationPreferenceContext::fromExecutionContext(SurfaceArea::Administrator, $context),
        );
        self::assertSame('comfortable', $resolution->value->value());
        self::assertFalse($resolution->customized());
    }

    /**
     * Proves a stale update cannot overwrite a newer preference or append a success audit event.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testStaleVersionFailsWithoutSuccessAudit(): void
    {
        $repository = new InMemoryPresentationPreferenceRepository();
        $audit = new PreferenceAuditRecorder();
        $manager = $this->manager($repository, $audit);
        $context = AuthorizationContext::human([]);
        $key = new PresentationPreferenceKey(
            SurfaceId::fromString('core.administrator.settings'),
            CustomizationSlot::Density,
            CustomizationScope::User,
            $context->actorId(),
        );
        $manager->put($context, ContributionOwner::core(), $key, 'compact', 0);
        $this->expectException(PresentationPreferenceVersionConflict::class);

        try {
            $manager->put($context, ContributionOwner::core(), $key, 'touch', 0);
        } finally {
            self::assertCount(1, $audit->events);
            self::assertSame('compact', $repository->find($key)?->value()->value());
        }
    }

    /**
     * Proves import revalidates source compatibility but rebases destination version and attribution.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testImportRebasesVersionAndActorWithProvenanceAudit(): void
    {
        $repository = new InMemoryPresentationPreferenceRepository();
        $audit = new PreferenceAuditRecorder();
        $manager = $this->manager($repository, $audit);
        $context = AuthorizationContext::human(['themes.administrator.manage']);
        $document = [
            'schema' => 1,
            'standard' => 'kis-1.0',
            'surface' => 'core.administrator.settings',
            'owner' => 'core',
            'scope' => 'administrator',
            'scope_id' => null,
            'slot' => 'density',
            'value' => 'compact',
            'version' => 9,
            'updated_by' => 'actor:exporter',
            'updated_at' => '2026-08-10T12:00:00Z',
        ];

        $imported = $manager->import($context, $document, 0);

        self::assertSame(1, $imported->version());
        self::assertSame($context->actorId(), $imported->updatedBy());
        self::assertSame('interface.preference.import', $audit->events[0]->action());
        self::assertSame(9, $audit->events[0]->metadata()['source_version'] ?? null);
        self::assertArrayHasKey('source_sha256', $audit->events[0]->metadata());
    }

    /**
     * Proves the foundation never turns site settings authority into foreign-user impersonation.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testForeignUserLayerIsUnsupportedEvenWithSettingsAuthority(): void
    {
        $manager = $this->manager(new InMemoryPresentationPreferenceRepository(), new PreferenceAuditRecorder());
        $context = AuthorizationContext::human(['settings.manage']);
        $key = new PresentationPreferenceKey(
            SurfaceId::fromString('core.administrator.settings'),
            CustomizationSlot::Density,
            CustomizationScope::User,
            'actor:other',
        );
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('authenticated actor');

        $manager->put($context, ContributionOwner::core(), $key, 'compact', 0);
    }

    /**
     * Proves site-scoped settings authority cannot alter the installation administrator layer.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testSiteSettingsAuthorityCannotWriteAdministratorLayer(): void
    {
        $manager = $this->manager(new InMemoryPresentationPreferenceRepository(), new PreferenceAuditRecorder());
        $context = AuthorizationContext::siteScoped('settings.manage');
        $key = new PresentationPreferenceKey(
            SurfaceId::fromString('core.administrator.settings'),
            CustomizationSlot::Density,
            CustomizationScope::Administrator,
            null,
        );
        $this->expectException(AuthorizationDenied::class);

        $manager->put($context, ContributionOwner::core(), $key, 'compact', 0);
    }

    /**
     * Proves an unattended actor cannot use its system identifier as a user preference identity.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testSystemContextCannotWriteUserLayer(): void
    {
        $manager = $this->manager(new InMemoryPresentationPreferenceRepository(), new PreferenceAuditRecorder());
        $context = AuthorizationContext::system(SystemIdentity::Worker)->context(
            SiteContext::default(),
            'preference-system-user-test',
        );
        $key = new PresentationPreferenceKey(
            SurfaceId::fromString('core.administrator.settings'),
            CustomizationSlot::Density,
            CustomizationScope::User,
            $context->actorId(),
        );
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('authenticated actor');

        $manager->put($context, ContributionOwner::core(), $key, 'compact', 0);
    }

    /**
     * Proves a site preference cannot cross the authenticated execution site's boundary.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testSiteScopeMustMatchExecutionContext(): void
    {
        $manager = $this->manager(new InMemoryPresentationPreferenceRepository(), new PreferenceAuditRecorder());
        $context = AuthorizationContext::human(['settings.manage']);
        $key = new PresentationPreferenceKey(
            SurfaceId::fromString('core.administrator.settings'),
            CustomizationSlot::Density,
            CustomizationScope::Site,
            'another-site',
        );
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('execution context site');

        $manager->put($context, ContributionOwner::core(), $key, 'compact', 0);
    }

    /**
     * Proves a role/workspace write is bound to the exact live selection and rechecked for the write.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRoleWorkspaceScopeRequiresCurrentExactMembership(): void
    {
        $memberships = new PreferenceMembershipValidator(true);
        $manager = $this->manager(
            new InMemoryPresentationPreferenceRepository(),
            new PreferenceAuditRecorder(),
            $memberships,
        );
        $context = AuthorizationContext::human(
            ['settings.manage'],
            membership: AuthorizationContext::membership(workspace: 'workspace:operations'),
        );
        $key = new PresentationPreferenceKey(
            SurfaceId::fromString('core.administrator.settings'),
            CustomizationSlot::Density,
            CustomizationScope::RoleWorkspace,
            'workspace:operations',
        );

        $stored = $manager->put($context, ContributionOwner::core(), $key, 'compact', 0);

        self::assertSame('compact', $stored->value()->value());
        self::assertContains(false, $memberships->locks);
        self::assertContains(true, $memberships->locks);
    }

    /**
     * Proves a foreign workspace identifier is refused before capability evaluation.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRoleWorkspaceScopeCannotTargetForeignWorkspace(): void
    {
        $manager = $this->manager(new InMemoryPresentationPreferenceRepository(), new PreferenceAuditRecorder());
        $context = AuthorizationContext::human(
            ['settings.manage'],
            membership: AuthorizationContext::membership(workspace: 'workspace:operations'),
        );
        $key = new PresentationPreferenceKey(
            SurfaceId::fromString('core.administrator.settings'),
            CustomizationSlot::Density,
            CustomizationScope::RoleWorkspace,
            'workspace:foreign',
        );
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('current validated workspace');

        $manager->put($context, ContributionOwner::core(), $key, 'compact', 0);
    }

    /**
     * Proves a stale or unverifiable membership cannot write its otherwise matching workspace layer.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRoleWorkspaceScopeFailsClosedForStaleMembership(): void
    {
        $manager = $this->manager(
            new InMemoryPresentationPreferenceRepository(),
            new PreferenceAuditRecorder(),
            new PreferenceMembershipValidator(false),
        );
        $context = AuthorizationContext::human(
            ['settings.manage'],
            membership: AuthorizationContext::membership(workspace: 'workspace:operations'),
        );
        $key = new PresentationPreferenceKey(
            SurfaceId::fromString('core.administrator.settings'),
            CustomizationSlot::Density,
            CustomizationScope::RoleWorkspace,
            'workspace:operations',
        );
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('current validated workspace');

        $manager->put($context, ContributionOwner::core(), $key, 'compact', 0);
    }

    /**
     * Proves a role access-group default uses exact-role authority and locks live role existence for write.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRoleAccessGroupScopeRequiresExactAuthorityAndLockedExistence(): void
    {
        $group = PresentationAccessGroup::fromRole(
            self::ACCESS_GROUP_ROLE_ID,
            'operations',
            'Operations',
        );
        $groups = new InMemoryPresentationAccessGroupRepository([$group]);
        $decisions = new PreferenceAuthorizationDecisionRecorder();
        $manager = $this->manager(
            new InMemoryPresentationPreferenceRepository(),
            new PreferenceAuditRecorder(),
            accessGroups: $groups,
            decisions: $decisions,
        );
        $context = AuthorizationContext::human(['users.manage']);
        $key = new PresentationPreferenceKey(
            SurfaceId::fromString('core.administrator.settings'),
            CustomizationSlot::Density,
            CustomizationScope::RoleWorkspace,
            $group->id,
        );

        $stored = $manager->put($context, ContributionOwner::core(), $key, 'compact', 0);

        self::assertSame('compact', $stored->value()->value());
        self::assertSame(['group:' . $group->id], $groups->locks());
        self::assertSame([
            'users.manage:role:' . self::ACCESS_GROUP_ROLE_ID,
            'users.manage:role:' . self::ACCESS_GROUP_ROLE_ID,
        ], $decisions->targets);
    }

    /**
     * Proves a bounded role form projection authorizes once and reads all exact preference rows once.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRoleAccessGroupBatchReadHasAConstantQueryAndAuthorizationBudget(): void
    {
        $group = PresentationAccessGroup::fromRole(
            self::ACCESS_GROUP_ROLE_ID,
            'operations',
            'Operations',
        );
        $secondGroup = PresentationAccessGroup::fromRole(
            '018f22e2-7c8b-7ab0-8f3a-88e8026bb305',
            'reviewers',
            'Reviewers',
        );
        $repository = new InMemoryPresentationPreferenceRepository();
        $context = AuthorizationContext::human(['users.manage']);
        $surface = SurfaceId::fromString('core.administrator.dashboard');
        $owner = ContributionOwner::core();
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
        $secondWidgetKey = new PresentationPreferenceKey(
            $surface,
            CustomizationSlot::DashboardCards,
            CustomizationScope::RoleWorkspace,
            $secondGroup->id,
        );
        $secondShortcutKey = new PresentationPreferenceKey(
            $surface,
            CustomizationSlot::NavigationShortcuts,
            CustomizationScope::RoleWorkspace,
            $secondGroup->id,
        );
        $repository->seed(PresentationPreference::create(
            $surface,
            $owner,
            CustomizationScope::RoleWorkspace,
            $group->id,
            CustomizationSlot::DashboardCards,
            ['core.dashboard.access-context'],
            3,
            $context->actorId(),
            new DateTimeImmutable('2026-08-15T12:00:00Z'),
        ));
        $groups = new InMemoryPresentationAccessGroupRepository([$group, $secondGroup]);
        $decisions = new PreferenceAuthorizationDecisionRecorder();
        $manager = $this->manager(
            $repository,
            new PreferenceAuditRecorder(),
            accessGroups: $groups,
            decisions: $decisions,
        );

        $preferences = $manager->readMany(
            $context,
            $owner,
            [$widgetKey, $shortcutKey, $secondWidgetKey, $secondShortcutKey],
            [$group, $secondGroup],
        );

        self::assertSame(
            ['core.dashboard.access-context'],
            $preferences[$widgetKey->auditSubjectId()]?->value()->value(),
        );
        self::assertArrayHasKey($shortcutKey->auditSubjectId(), $preferences);
        self::assertNull($preferences[$shortcutKey->auditSubjectId()]);
        self::assertArrayHasKey($secondWidgetKey->auditSubjectId(), $preferences);
        self::assertNull($preferences[$secondWidgetKey->auditSubjectId()]);
        self::assertArrayHasKey($secondShortcutKey->auditSubjectId(), $preferences);
        self::assertNull($preferences[$secondShortcutKey->auditSubjectId()]);
        self::assertSame(['find' => 0, 'find_many' => 1], $repository->readCounts());
        self::assertSame([], $groups->locks());
        self::assertSame(
            ['users.manage:role:*'],
            $decisions->targets,
        );
    }

    /**
     * Proves a stored row with a stale owner reads as absent instead of failing the whole batch.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testBatchReadDegradesAStaleOwnerRowToAbsent(): void
    {
        $repository = new InMemoryPresentationPreferenceRepository();
        $context = AuthorizationContext::human([]);
        $surface = SurfaceId::fromString('acme.widgets.settings');
        $key = new PresentationPreferenceKey(
            $surface,
            CustomizationSlot::Density,
            CustomizationScope::User,
            $context->actorId(),
        );
        $repository->seed(PresentationPreference::create(
            $surface,
            ContributionOwner::extension('acme/widgets'),
            CustomizationScope::User,
            $context->actorId(),
            CustomizationSlot::Density,
            'compact',
            1,
            $context->actorId(),
            new DateTimeImmutable('2026-08-15T12:00:00Z'),
        ));
        $manager = $this->manager($repository, new PreferenceAuditRecorder());

        $preferences = $manager->readMany($context, ContributionOwner::core(), [$key]);

        self::assertArrayHasKey($key->auditSubjectId(), $preferences);
        self::assertNull($preferences[$key->auditSubjectId()]);
    }

    /**
     * Proves a batch adapter cannot disclose a stored row outside the manager's exact authorized key set.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testBatchReadRejectsARepositoryRowForAnotherKey(): void
    {
        $context = AuthorizationContext::human([]);
        $surface = SurfaceId::fromString('core.administrator.dashboard');
        $owner = ContributionOwner::core();
        $requested = new PresentationPreferenceKey(
            $surface,
            CustomizationSlot::DashboardCards,
            CustomizationScope::User,
            $context->actorId(),
        );
        $foreign = PresentationPreference::create(
            $surface,
            $owner,
            CustomizationScope::User,
            $context->actorId(),
            CustomizationSlot::NavigationShortcuts,
            ['core.administrator.content'],
            1,
            $context->actorId(),
            new DateTimeImmutable('2026-08-15T12:00:00Z'),
        );
        $repository = $this->createStub(PresentationPreferenceRepository::class);
        $repository->method('findMany')->willReturn([
            PresentationPreferenceKey::fromPreference($foreign)->auditSubjectId() => $foreign,
        ]);
        $manager = $this->manager($repository, new PreferenceAuditRecorder());
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unauthorized batch row');

        $manager->readMany($context, $owner, [$requested]);
    }

    /**
     * Proves a deleted access group cannot receive a preference even when the actor manages roles.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRoleAccessGroupScopeRequiresCurrentRoleExistence(): void
    {
        $manager = $this->manager(
            new InMemoryPresentationPreferenceRepository(),
            new PreferenceAuditRecorder(),
            accessGroups: new InMemoryPresentationAccessGroupRepository(),
        );
        $context = AuthorizationContext::human(['users.manage']);
        $key = new PresentationPreferenceKey(
            SurfaceId::fromString('core.administrator.settings'),
            CustomizationSlot::Density,
            CustomizationScope::RoleWorkspace,
            'role:' . self::ACCESS_GROUP_ROLE_ID,
        );
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('current presentation access group');

        $manager->put($context, ContributionOwner::core(), $key, 'compact', 0);
    }

    /**
     * Proves role existence cannot be probed through a preference write without canonical role authority.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRoleAccessGroupScopeChecksAuthorityBeforeExistence(): void
    {
        $group = PresentationAccessGroup::fromRole(
            self::ACCESS_GROUP_ROLE_ID,
            'operations',
            'Operations',
        );
        $groups = new InMemoryPresentationAccessGroupRepository([$group]);
        $manager = $this->manager(
            new InMemoryPresentationPreferenceRepository(),
            new PreferenceAuditRecorder(),
            accessGroups: $groups,
        );
        $context = AuthorizationContext::human([]);
        $key = new PresentationPreferenceKey(
            SurfaceId::fromString('core.administrator.settings'),
            CustomizationSlot::Density,
            CustomizationScope::RoleWorkspace,
            $group->id,
        );
        $this->expectException(AuthorizationDenied::class);

        try {
            $manager->put($context, ContributionOwner::core(), $key, 'compact', 0);
        } finally {
            self::assertSame([], $groups->locks());
        }
    }

    /**
     * Proves a malformed reserved role identity cannot fall through to current-workspace authorization.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testMalformedRoleAccessGroupIdentityFailsClosed(): void
    {
        $manager = $this->manager(new InMemoryPresentationPreferenceRepository(), new PreferenceAuditRecorder());
        $context = AuthorizationContext::human(
            ['settings.manage', 'users.manage'],
            membership: AuthorizationContext::membership(workspace: 'role:operations'),
        );
        $key = new PresentationPreferenceKey(
            SurfaceId::fromString('core.administrator.settings'),
            CustomizationSlot::Density,
            CustomizationScope::RoleWorkspace,
            'role:operations',
        );
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('identity is invalid');

        $manager->put($context, ContributionOwner::core(), $key, 'compact', 0);
    }

    /**
     * Proves reset remains available after an upgrade removes a previously allowed slot.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRemovedSlotCanStillBeResetByItsOwner(): void
    {
        $repository = new InMemoryPresentationPreferenceRepository();
        $context = AuthorizationContext::human([]);
        $owner = ContributionOwner::core();
        $preference = PresentationPreference::create(
            SurfaceId::fromString('core.administrator.settings'),
            $owner,
            CustomizationScope::User,
            $context->actorId(),
            CustomizationSlot::Density,
            'compact',
            1,
            $context->actorId(),
            new DateTimeImmutable('2026-08-11T14:00:00Z'),
        );
        $repository->seed($preference);
        $manager = new PresentationPreferenceManager(
            $repository,
            new DenyAllPresentationPreferencePolicy(),
            AuthorizationContext::gateway(),
            new PreferenceAuditRecorder(),
            new PreferenceClock(),
            new ImmediateTransactionManager(),
            new PreferenceMembershipValidator(true),
            new InMemoryPresentationAccessGroupRepository(),
        );

        $manager->reset($context, $owner, PresentationPreferenceKey::fromPreference($preference), 1);

        self::assertNull($repository->find(PresentationPreferenceKey::fromPreference($preference)));
    }

    /**
     * Build the application service with deterministic in-memory boundaries.
     *
     * @param   PresentationPreferenceRepository           $repository    Preference store for the test.
     * @param   PreferenceAuditRecorder                    $audit         Capturing audit sink.
     * @param   ?PreferenceMembershipValidator             $memberships   Optional live membership decision.
     * @param   ?InMemoryPresentationAccessGroupRepository  $accessGroups  Optional canonical role projection.
     * @param   ?AuthorizationDecisionRecorder              $decisions     Optional authorization evidence sink.
     *
     * @return  PresentationPreferenceManager
     *
     * @since   2.0.0
     */
    private function manager(
        PresentationPreferenceRepository $repository,
        PreferenceAuditRecorder $audit,
        ?PreferenceMembershipValidator $memberships = null,
        ?InMemoryPresentationAccessGroupRepository $accessGroups = null,
        ?AuthorizationDecisionRecorder $decisions = null,
    ): PresentationPreferenceManager {
        $memberships ??= new PreferenceMembershipValidator(true);
        $accessGroups ??= new InMemoryPresentationAccessGroupRepository();

        return new PresentationPreferenceManager(
            $repository,
            new ManagerAllowAllPresentationPreferencePolicy(),
            AuthorizationContext::gateway($decisions, memberships: $memberships),
            $audit,
            new PreferenceClock(),
            new ImmediateTransactionManager(),
            $memberships,
            $accessGroups,
        );
    }
}

/**
 * Captures the exact capability and resource identities preference authorization evaluates.
 *
 * @since  2.0.0
 */
final class PreferenceAuthorizationDecisionRecorder implements AuthorizationDecisionRecorder
{
    /**
     * Capability, resource type, and identifier triplets observed in decision order.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    public array $targets = [];

    /**
     * Record the exact capability and resource identity evaluated by the authorization gateway.
     *
     * @param   ExecutionContext       $context   Actor and site context evaluated by the gateway.
     * @param   Capability             $action    Capability whose decision was recorded.
     * @param   AuthorizationResource  $resource  Exact resource target used for the decision.
     * @param   AuthorizationDecision  $decision  Allow or deny result produced by the gateway.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function record(
        ExecutionContext $context,
        Capability $action,
        AuthorizationResource $resource,
        AuthorizationDecision $decision,
    ): void {
        $this->targets[] = $action->value() . ':' . $resource->type() . ':' . $resource->identifier();
    }
}

/**
 * Controllable live-membership authority recording whether a mutation lock was requested.
 *
 * @since  2.0.0
 */
final class PreferenceMembershipValidator implements MembershipContextValidator
{
    /**
     * Membership validation lock modes observed in call order.
     *
     * @var    list<bool>
     * @since  2.0.0
     */
    public array $locks = [];

    /**
     * @param  bool  $current  Whether every supplied membership snapshot is current.
     *
     * @since  2.0.0
     */
    public function __construct(private bool $current)
    {
    }

    /** @inheritDoc */
    public function current(
        string $subjectId,
        SiteContext $site,
        MembershipContext $membership,
        bool $lock = false,
    ): bool {
        $this->locks[] = $lock;

        return $this->current;
    }
}

/**
 * Captures preference audit events for atomic mutation assertions.
 *
 * @since  2.0.0
 */
final class PreferenceAuditRecorder implements AuditRecorder
{
    /**
     * Successfully recorded events in call order.
     *
     * @var    list<AuditEvent>
     * @since  2.0.0
     */
    public array $events = [];

    /**
     * Capture one event.
     *
     * @param   AuditEvent  $event  Validated mutation record.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function record(AuditEvent $event): void
    {
        $this->events[] = $event;
    }
}

/**
 * Fixed instant for deterministic preference attribution.
 *
 * @since  2.0.0
 */
final readonly class PreferenceClock implements ClockInterface
{
    /**
     * Return the fixed test instant.
     *
     * @return  DateTimeImmutable
     *
     * @since   2.0.0
     */
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-08-11T14:00:00Z');
    }
}

/**
 * Admits every customization pair so manager tests isolate mutation behavior.
 *
 * @since  2.0.0
 */
final readonly class ManagerAllowAllPresentationPreferencePolicy implements PresentationPreferencePolicy
{
    /**
     * Admit every pair supplied by a test.
     *
     * @param   SurfaceId           $surface  Test surface.
     * @param   ContributionOwner   $owner    Test owner.
     * @param   CustomizationSlot   $slot     Test slot.
     * @param   CustomizationScope  $scope    Test scope.
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
     * Accept every pair supplied by a test.
     *
     * @param   SurfaceId           $surface  Test surface.
     * @param   ContributionOwner   $owner    Test owner.
     * @param   CustomizationSlot   $slot     Test slot.
     * @param   CustomizationScope  $scope    Test scope.
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
 * Rejects every live mutation pair so reset compatibility can be tested independently.
 *
 * @since  2.0.0
 */
final readonly class DenyAllPresentationPreferencePolicy implements PresentationPreferencePolicy
{
    /**
     * Reject every pair supplied by a test.
     *
     * @param   SurfaceId           $surface  Test surface.
     * @param   ContributionOwner   $owner    Test owner.
     * @param   CustomizationSlot   $slot     Test slot.
     * @param   CustomizationScope  $scope    Test scope.
     *
     * @return  bool  Always false.
     *
     * @since   2.0.0
     */
    public function allows(
        SurfaceId $surface,
        ContributionOwner $owner,
        CustomizationSlot $slot,
        CustomizationScope $scope,
    ): bool {
        return false;
    }

    /**
     * Reject every pair supplied by a test.
     *
     * @param   SurfaceId           $surface  Test surface.
     * @param   ContributionOwner   $owner    Test owner.
     * @param   CustomizationSlot   $slot     Test slot.
     * @param   CustomizationScope  $scope    Test scope.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  Always, because this policy models a removed permission.
     *
     * @since   2.0.0
     */
    public function assertAllowed(
        SurfaceId $surface,
        ContributionOwner $owner,
        CustomizationSlot $slot,
        CustomizationScope $scope,
    ): void {
        throw new InvalidArgumentException('The test preference permission was removed.');
    }
}
