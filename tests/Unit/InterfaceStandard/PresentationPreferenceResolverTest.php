<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\InterfaceStandard;

use DateTimeImmutable;
use InvalidArgumentException;
use Kumwe\Context\Value\SiteContext;
use Kumwe\App\Application\Authorization\SystemIdentity;
use Kumwe\App\Application\Presentation\Preference\PresentationAccessGroup;
use Kumwe\App\Application\Presentation\Preference\PresentationAccessGroupCatalog;
use Kumwe\App\Application\Presentation\Preference\PresentationPreferencePolicy;
use Kumwe\App\Application\Presentation\Preference\RegisteredPresentationPreferencePolicy;
use Kumwe\Extension\Spi\Contribution\ContributionOwner;
use Kumwe\App\Extension\Contribution\OwnedRuntimeContributionRegistry;
use Kumwe\App\InterfaceStandard\CustomizationScope;
use Kumwe\App\InterfaceStandard\CustomizationSlot;
use Kumwe\App\InterfaceStandard\PresentationPreference;
use Kumwe\App\InterfaceStandard\PresentationPreferenceKey;
use Kumwe\App\InterfaceStandard\SurfaceArea;
use Kumwe\App\InterfaceStandard\SurfaceDefinition;
use Kumwe\App\InterfaceStandard\SurfaceId;
use Kumwe\App\Presentation\Application\Preference\PresentationPreferenceContext;
use Kumwe\App\Presentation\Application\Preference\PresentationPreferenceResolver;
use Kumwe\App\Tests\Support\AuthorizationContext;
use Kumwe\App\Tests\Support\InMemoryPresentationPreferenceRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Verifies hierarchy precedence, compatibility fallback, and live registry admission.
 *
 * @since  2.0.0
 */
#[CoversClass(PresentationPreferenceContext::class)]
#[CoversClass(PresentationPreferenceResolver::class)]
#[CoversClass(RegisteredPresentationPreferencePolicy::class)]
final class PresentationPreferenceResolverTest extends TestCase
{
    /**
     * Proves administrator resolution applies site, administrator, role/workspace, then user values.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAdministratorHierarchyResolvesLowToHighPrecedence(): void
    {
        $repository = new InMemoryPresentationPreferenceRepository();
        $surface = SurfaceId::fromString('core.administrator.settings');
        $owner = ContributionOwner::core();
        $context = AuthorizationContext::human(
            [],
            membership: AuthorizationContext::membership(workspace: 'workspace:operations'),
        );
        foreach (
            [
            [CustomizationScope::Site, 'default', 'comfortable'],
            [CustomizationScope::Administrator, null, 'compact'],
            [CustomizationScope::RoleWorkspace, 'workspace:operations', 'touch'],
            [CustomizationScope::User, $context->actorId(), 'comfortable'],
            ] as $index => [$scope, $scopeId, $value]
        ) {
            $repository->seed(PresentationPreference::create(
                $surface,
                $owner,
                $scope,
                $scopeId,
                CustomizationSlot::Density,
                $value,
                $index + 1,
                'actor:administrator',
                new DateTimeImmutable('2026-08-11T12:00:00Z'),
            ));
        }
        $resolver = new PresentationPreferenceResolver($repository, new AllowAllPresentationPreferencePolicy());

        $resolution = $resolver->resolve(
            $surface,
            $owner,
            CustomizationSlot::Density,
            'comfortable',
            PresentationPreferenceContext::fromExecutionContext(SurfaceArea::Administrator, $context),
        );

        self::assertSame('comfortable', $resolution->value->value());
        self::assertSame(CustomizationScope::User, $resolution->source);
        self::assertSame(4, $resolution->version);
        self::assertTrue($resolution->customized());
    }

    /**
     * Proves portal resolution never consumes installation administrator defaults.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testPortalHierarchyExcludesAdministratorLayer(): void
    {
        $repository = new InMemoryPresentationPreferenceRepository();
        $surface = SurfaceId::fromString('core.portal.home');
        $owner = ContributionOwner::core();
        $context = AuthorizationContext::human([]);
        $repository->seed(PresentationPreference::create(
            $surface,
            $owner,
            CustomizationScope::Administrator,
            null,
            CustomizationSlot::Density,
            'compact',
            1,
            'actor:administrator',
            new DateTimeImmutable('2026-08-11T12:00:00Z'),
        ));
        $resolver = new PresentationPreferenceResolver($repository, new AllowAllPresentationPreferencePolicy());

        $resolution = $resolver->resolve(
            $surface,
            $owner,
            CustomizationSlot::Density,
            'comfortable',
            PresentationPreferenceContext::fromExecutionContext(SurfaceArea::Portal, $context),
        );

        self::assertSame('comfortable', $resolution->value->value());
        self::assertNull($resolution->source);
    }

    /**
     * Proves an unattended execution identity never becomes an authenticated user preference layer.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testSystemContextDoesNotResolveUserLayer(): void
    {
        $repository = new InMemoryPresentationPreferenceRepository();
        $surface = SurfaceId::fromString('core.administrator.settings');
        $owner = ContributionOwner::core();
        $context = AuthorizationContext::system(SystemIdentity::Worker)->context(
            SiteContext::default(),
            'preference-system-resolution-test',
        );
        $repository->seed(PresentationPreference::create(
            $surface,
            $owner,
            CustomizationScope::User,
            $context->actorId(),
            CustomizationSlot::Density,
            'touch',
            1,
            $context->actorId(),
            new DateTimeImmutable('2026-08-11T12:00:00Z'),
        ));
        $resolver = new PresentationPreferenceResolver($repository, new AllowAllPresentationPreferencePolicy());

        $resolution = $resolver->resolve(
            $surface,
            $owner,
            CustomizationSlot::Density,
            'comfortable',
            PresentationPreferenceContext::fromExecutionContext(SurfaceArea::Administrator, $context),
        );

        self::assertSame('comfortable', $resolution->value->value());
        self::assertNull($resolution->source);
    }

    /**
     * Proves a removed higher-layer slot yields a diagnostic and retains the lower safe value.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRemovedSlotFallsBackWithDiagnostic(): void
    {
        $repository = new InMemoryPresentationPreferenceRepository();
        $surface = SurfaceId::fromString('core.administrator.settings');
        $owner = ContributionOwner::core();
        $context = AuthorizationContext::human([]);
        $repository->seed(PresentationPreference::create(
            $surface,
            $owner,
            CustomizationScope::Site,
            'default',
            CustomizationSlot::Density,
            'compact',
            1,
            'actor:administrator',
            new DateTimeImmutable('2026-08-11T12:00:00Z'),
        ));
        $repository->seed(PresentationPreference::create(
            $surface,
            $owner,
            CustomizationScope::User,
            $context->actorId(),
            CustomizationSlot::Density,
            'touch',
            2,
            $context->actorId(),
            new DateTimeImmutable('2026-08-11T12:00:00Z'),
        ));
        $policy = new SelectivePresentationPreferencePolicy([CustomizationScope::Site]);
        $resolver = new PresentationPreferenceResolver($repository, $policy);

        $resolution = $resolver->resolve(
            $surface,
            $owner,
            CustomizationSlot::Density,
            'comfortable',
            PresentationPreferenceContext::fromExecutionContext(SurfaceArea::Administrator, $context),
        );

        self::assertSame('compact', $resolution->value->value());
        self::assertSame(CustomizationScope::Site, $resolution->source);
        self::assertSame(['kis.preference.slot-removed'], $resolution->diagnostics);
    }

    /**
     * Proves access-group lists use stable identity order and a personal row replaces the full union.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAccessGroupListsUnionDeterministicallyBeforeUserReplacement(): void
    {
        $repository = new InMemoryPresentationPreferenceRepository();
        $surface = SurfaceId::fromString('core.administrator.dashboard');
        $owner = ContributionOwner::core();
        $context = AuthorizationContext::human(
            [],
            membership: AuthorizationContext::membership(workspace: 'workspace:operations'),
        );
        $first = PresentationAccessGroup::fromRole(
            '018f22e2-7c8b-7ab0-8f3a-88e8026bb302',
            'finance',
            'Finance',
        );
        $second = PresentationAccessGroup::fromRole(
            '018f22e2-7c8b-7ab0-8f3a-88e8026bb303',
            'operations',
            'Operations',
        );
        foreach (
            [
            [CustomizationScope::Administrator, null, ['core.dashboard.administrator'], 1],
            [CustomizationScope::RoleWorkspace, $first->id, ['core.dashboard.finance', 'core.dashboard.shared'], 2],
            [CustomizationScope::RoleWorkspace, $second->id, ['core.dashboard.operations', 'core.dashboard.shared'], 3],
            [CustomizationScope::RoleWorkspace, 'workspace:operations', ['core.dashboard.workspace'], 4],
            ] as [$scope, $scopeId, $cards, $version]
        ) {
            $repository->seed(PresentationPreference::create(
                $surface,
                $owner,
                $scope,
                $scopeId,
                CustomizationSlot::DashboardCards,
                $cards,
                $version,
                $context->actorId(),
                new DateTimeImmutable('2026-08-11T12:00:00Z'),
            ));
        }
        $resolver = new PresentationPreferenceResolver($repository, new AllowAllPresentationPreferencePolicy());
        $preferenceContext = PresentationPreferenceContext::fromExecutionContext(
            SurfaceArea::Administrator,
            $context,
        );

        $resolution = $resolver->resolveListForAccessGroups(
            $surface,
            $owner,
            CustomizationSlot::DashboardCards,
            ['core.dashboard.default'],
            $preferenceContext,
            new PresentationAccessGroupCatalog([$first, $second], null),
        );

        self::assertSame([
            'core.dashboard.finance',
            'core.dashboard.shared',
            'core.dashboard.operations',
            'core.dashboard.workspace',
        ], $resolution->value->value());
        self::assertSame(CustomizationScope::RoleWorkspace, $resolution->source);
        self::assertNull($resolution->version);
        self::assertSame([], $resolution->diagnostics);
        self::assertSame(['find' => 0, 'find_many' => 1], $repository->readCounts());

        $repository->seed(PresentationPreference::create(
            $surface,
            $owner,
            CustomizationScope::User,
            $context->actorId(),
            CustomizationSlot::DashboardCards,
            ['core.dashboard.personal'],
            9,
            $context->actorId(),
            new DateTimeImmutable('2026-08-11T12:00:00Z'),
        ));
        $personal = $resolver->resolveListForAccessGroups(
            $surface,
            $owner,
            CustomizationSlot::DashboardCards,
            ['core.dashboard.default'],
            $preferenceContext,
            new PresentationAccessGroupCatalog([$first, $second], null),
        );

        self::assertSame(['core.dashboard.personal'], $personal->value->value());
        self::assertSame(CustomizationScope::User, $personal->source);
        self::assertSame(9, $personal->version);
        self::assertSame(['find' => 0, 'find_many' => 2], $repository->readCounts());
    }

    /**
     * Proves an incomplete effective-role catalogue never applies a misleading prefix union.
     *
     * Current-workspace and lower layers remain valid because they are complete server context. A personal
     * row may still replace that fallback, while neither the retained nor omitted projected role is queried.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testIncompleteAccessGroupCatalogSkipsTheWholeProjectedRoleAggregate(): void
    {
        $repository = new InMemoryPresentationPreferenceRepository();
        $surface = SurfaceId::fromString('core.administrator.dashboard');
        $owner = ContributionOwner::core();
        $context = AuthorizationContext::human(
            [],
            membership: AuthorizationContext::membership(workspace: 'workspace:operations'),
        );
        $first = PresentationAccessGroup::fromRole(
            '018f22e2-7c8b-7ab0-8f3a-88e8026bb302',
            'finance',
            'Finance',
        );
        $omitted = PresentationAccessGroup::fromRole(
            '018f22e2-7c8b-7ab0-8f3a-88e8026bb303',
            'operations',
            'Operations',
        );
        foreach (
            [
            [CustomizationScope::Administrator, null, ['core.dashboard.administrator'], 1],
            [CustomizationScope::RoleWorkspace, 'workspace:operations', ['core.dashboard.workspace'], 2],
            [CustomizationScope::RoleWorkspace, $first->id, ['core.dashboard.first-role'], 3],
            [CustomizationScope::RoleWorkspace, $omitted->id, ['core.dashboard.omitted-role'], 4],
            ] as [$scope, $scopeId, $cards, $version]
        ) {
            $repository->seed(PresentationPreference::create(
                $surface,
                $owner,
                $scope,
                $scopeId,
                CustomizationSlot::DashboardCards,
                $cards,
                $version,
                $context->actorId(),
                new DateTimeImmutable('2026-08-15T12:00:00Z'),
            ));
        }
        $resolver = new PresentationPreferenceResolver($repository, new AllowAllPresentationPreferencePolicy());
        $preferenceContext = PresentationPreferenceContext::fromExecutionContext(
            SurfaceArea::Administrator,
            $context,
        );
        $catalog = new PresentationAccessGroupCatalog([$first], $omitted);

        $fallback = $resolver->resolveListForAccessGroups(
            $surface,
            $owner,
            CustomizationSlot::DashboardCards,
            ['core.dashboard.default'],
            $preferenceContext,
            $catalog,
        );

        self::assertSame(['core.dashboard.workspace'], $fallback->value->value());
        self::assertSame(CustomizationScope::RoleWorkspace, $fallback->source);
        self::assertSame(['kis.preference.access-group-catalog-incomplete'], $fallback->diagnostics);
        self::assertSame(['find' => 0, 'find_many' => 1], $repository->readCounts());

        $repository->seed(PresentationPreference::create(
            $surface,
            $owner,
            CustomizationScope::User,
            $context->actorId(),
            CustomizationSlot::DashboardCards,
            ['core.dashboard.personal'],
            5,
            $context->actorId(),
            new DateTimeImmutable('2026-08-15T12:00:00Z'),
        ));
        $personal = $resolver->resolveListForAccessGroups(
            $surface,
            $owner,
            CustomizationSlot::DashboardCards,
            ['core.dashboard.default'],
            $preferenceContext,
            $catalog,
        );

        self::assertSame(['core.dashboard.personal'], $personal->value->value());
        self::assertSame(CustomizationScope::User, $personal->source);
        self::assertSame(['kis.preference.access-group-catalog-incomplete'], $personal->diagnostics);
        self::assertSame(['find' => 0, 'find_many' => 2], $repository->readCounts());
    }

    /**
     * Proves removed group rows fall back and a composed union remains inside the slot bound.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAccessGroupListFallbackAndBoundedUnionRemainSafe(): void
    {
        $surface = SurfaceId::fromString('core.administrator.dashboard');
        $owner = ContributionOwner::core();
        $context = AuthorizationContext::human([]);
        $first = PresentationAccessGroup::fromRole(
            '018f22e2-7c8b-7ab0-8f3a-88e8026bb302',
            'finance',
            'Finance',
        );
        $second = PresentationAccessGroup::fromRole(
            '018f22e2-7c8b-7ab0-8f3a-88e8026bb303',
            'operations',
            'Operations',
        );
        $repository = new InMemoryPresentationPreferenceRepository();
        $repository->seed(PresentationPreference::create(
            $surface,
            $owner,
            CustomizationScope::Administrator,
            null,
            CustomizationSlot::DashboardCards,
            ['core.dashboard.administrator'],
            1,
            $context->actorId(),
            new DateTimeImmutable('2026-08-11T12:00:00Z'),
        ));
        $repository->seed(PresentationPreference::create(
            $surface,
            $owner,
            CustomizationScope::RoleWorkspace,
            $first->id,
            CustomizationSlot::DashboardCards,
            ['core.dashboard.removed'],
            2,
            $context->actorId(),
            new DateTimeImmutable('2026-08-11T12:00:00Z'),
        ));
        $fallback = (new PresentationPreferenceResolver(
            $repository,
            new SelectivePresentationPreferencePolicy([CustomizationScope::Administrator]),
        ))->resolveListForAccessGroups(
            $surface,
            $owner,
            CustomizationSlot::DashboardCards,
            ['core.dashboard.default'],
            PresentationPreferenceContext::fromExecutionContext(SurfaceArea::Administrator, $context),
            new PresentationAccessGroupCatalog([$first], null),
        );

        self::assertSame(['core.dashboard.administrator'], $fallback->value->value());
        self::assertSame(CustomizationScope::Administrator, $fallback->source);
        self::assertSame(['kis.preference.slot-removed'], $fallback->diagnostics);

        $boundedRepository = new InMemoryPresentationPreferenceRepository();
        foreach (
            [
            [$first, array_map(static fn (int $index): string => 'core.dashboard.finance-' . $index, range(1, 40))],
            [$second, array_map(static fn (int $index): string => 'core.dashboard.operations-' . $index, range(1, 40))],
            ] as $index => [$group, $cards]
        ) {
            $boundedRepository->seed(PresentationPreference::create(
                $surface,
                $owner,
                CustomizationScope::RoleWorkspace,
                $group->id,
                CustomizationSlot::DashboardCards,
                $cards,
                $index + 1,
                $context->actorId(),
                new DateTimeImmutable('2026-08-11T12:00:00Z'),
            ));
        }
        $bounded = (new PresentationPreferenceResolver(
            $boundedRepository,
            new AllowAllPresentationPreferencePolicy(),
        ))->resolveListForAccessGroups(
            $surface,
            $owner,
            CustomizationSlot::DashboardCards,
            [],
            PresentationPreferenceContext::fromExecutionContext(SurfaceArea::Administrator, $context),
            new PresentationAccessGroupCatalog([$first, $second], null),
        );

        self::assertCount(64, $bounded->value->value());
        self::assertSame('core.dashboard.finance-1', $bounded->value->value()[0] ?? null);
        self::assertSame('core.dashboard.operations-24', $bounded->value->value()[63] ?? null);
        self::assertSame(['kis.preference.group-list-truncated'], $bounded->diagnostics);
    }

    /**
     * Proves scalar presentation slots cannot accidentally acquire access-group list semantics.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAccessGroupListResolutionRejectsScalarSlot(): void
    {
        $resolver = new PresentationPreferenceResolver(
            new InMemoryPresentationPreferenceRepository(),
            new AllowAllPresentationPreferencePolicy(),
        );
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('dashboard cards and navigation shortcuts');

        $resolver->resolveListForAccessGroups(
            SurfaceId::fromString('core.administrator.dashboard'),
            ContributionOwner::core(),
            CustomizationSlot::LandingWorkspace,
            [],
            PresentationPreferenceContext::fromExecutionContext(
                SurfaceArea::Administrator,
                AuthorizationContext::human([]),
            ),
            new PresentationAccessGroupCatalog([], null),
        );
    }

    /**
     * Proves an access-group row from a namespace-colliding stale owner cannot affect composition.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAccessGroupListResolutionIgnoresStaleOwner(): void
    {
        $repository = new InMemoryPresentationPreferenceRepository();
        $surface = SurfaceId::fromString('acme.tools.widgets.dashboard');
        $group = PresentationAccessGroup::fromRole(
            '018f22e2-7c8b-7ab0-8f3a-88e8026bb302',
            'finance',
            'Finance',
        );
        $repository->seed(PresentationPreference::create(
            $surface,
            ContributionOwner::extension('acme.tools/widgets'),
            CustomizationScope::RoleWorkspace,
            $group->id,
            CustomizationSlot::DashboardCards,
            ['acme.tools.widgets.stale'],
            1,
            AuthorizationContext::SUBJECT,
            new DateTimeImmutable('2026-08-11T12:00:00Z'),
        ));
        $resolution = (new PresentationPreferenceResolver(
            $repository,
            new AllowAllPresentationPreferencePolicy(),
        ))->resolveListForAccessGroups(
            $surface,
            ContributionOwner::extension('acme/tools.widgets'),
            CustomizationSlot::DashboardCards,
            ['acme.tools.widgets.default'],
            PresentationPreferenceContext::fromExecutionContext(
                SurfaceArea::Administrator,
                AuthorizationContext::human([]),
            ),
            new PresentationAccessGroupCatalog([$group], null),
        );

        self::assertSame(['acme.tools.widgets.default'], $resolution->value->value());
        self::assertNull($resolution->source);
        self::assertSame(['kis.preference.owner-stale'], $resolution->diagnostics);
    }

    /**
     * Proves the production policy treats each declared scope as an area-safe slot-specific ceiling.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRegisteredPolicyTreatsLiveCustomizationDeclarationAsScopeCeiling(): void
    {
        $owner = ContributionOwner::core();
        $surfaces = new OwnedRuntimeContributionRegistry('interface surface');
        $surface = SurfaceDefinition::fromArray($owner, $this->surface());
        $surfaces->register($owner, $surface);
        $portal = $this->surface();
        $portal['surface'] = 'core.portal.settings';
        $portal['area'] = 'portal';
        $portal['actor'] = 'portal';
        $portal['purpose'] = 'Manage approved presentation settings for the current portal actor.';
        $surfaces->register($owner, SurfaceDefinition::fromArray($owner, $portal));
        $policy = new RegisteredPresentationPreferencePolicy($surfaces);

        $administrator = SurfaceId::fromString('core.administrator.settings');
        foreach (CustomizationScope::cases() as $scope) {
            self::assertTrue($policy->allows(
                $administrator,
                $owner,
                CustomizationSlot::Density,
                $scope,
            ));
        }
        self::assertFalse($policy->allows(
            $administrator,
            $owner,
            CustomizationSlot::Columns,
            CustomizationScope::User,
        ));
        self::assertTrue($policy->allows(
            $administrator,
            $owner,
            CustomizationSlot::Columns,
            CustomizationScope::Administrator,
        ));
        self::assertTrue($policy->allows(
            $administrator,
            $owner,
            CustomizationSlot::Columns,
            CustomizationScope::RoleWorkspace,
        ));
        self::assertFalse($policy->allows(
            $administrator,
            $owner,
            CustomizationSlot::Columns,
            CustomizationScope::Site,
        ));

        $portalSurface = SurfaceId::fromString('core.portal.settings');
        self::assertTrue($policy->allows(
            $portalSurface,
            $owner,
            CustomizationSlot::Density,
            CustomizationScope::Site,
        ));
        self::assertTrue($policy->allows(
            $portalSurface,
            $owner,
            CustomizationSlot::Density,
            CustomizationScope::RoleWorkspace,
        ));
        self::assertTrue($policy->allows(
            $portalSurface,
            $owner,
            CustomizationSlot::Density,
            CustomizationScope::User,
        ));
        self::assertFalse($policy->allows(
            $portalSurface,
            $owner,
            CustomizationSlot::Density,
            CustomizationScope::Administrator,
        ));

        $this->expectException(InvalidArgumentException::class);
        $policy->assertAllowed(
            $administrator,
            $owner,
            CustomizationSlot::ThemeMode,
            CustomizationScope::User,
        );
    }

    /**
     * Return one conformant surface with a single customization permission.
     *
     * @return  array<string, mixed>
     *
     * @since   2.0.0
     */
    private function surface(): array
    {
        return [
            'surface' => 'core.administrator.settings',
            'standard' => 'kis-1.0',
            'area' => 'administrator',
            'actor' => 'administrator',
            'intent' => 'settings',
            'resource' => 'site-settings',
            'purpose' => 'Manage approved presentation settings for the current site.',
            'pattern' => 'settings-workspace',
            'capabilities' => ['settings.manage'],
            'states' => ['default', 'error', 'permission-reduced', 'read-only'],
            'customization' => [
                ['slot' => 'density', 'scope' => 'user'],
                ['slot' => 'columns', 'scope' => 'role-workspace'],
            ],
            'responsive' => [[
                'element' => 'settings-form',
                'priority' => 'essential',
                'may_collapse' => false,
            ]],
            'icon' => 'settings',
        ];
    }
}

/**
 * Test policy admitting every slot and scope pair.
 *
 * @since  2.0.0
 */
final readonly class AllowAllPresentationPreferencePolicy implements PresentationPreferencePolicy
{
    /** @inheritDoc */
    public function allows(
        SurfaceId $surface,
        ContributionOwner $owner,
        CustomizationSlot $slot,
        CustomizationScope $scope,
    ): bool {
        return true;
    }

    /** @inheritDoc */
    public function assertAllowed(
        SurfaceId $surface,
        ContributionOwner $owner,
        CustomizationSlot $slot,
        CustomizationScope $scope,
    ): void {
    }
}

/**
 * Test policy admitting only nominated hierarchy layers.
 *
 * @since  2.0.0
 */
final readonly class SelectivePresentationPreferencePolicy implements PresentationPreferencePolicy
{
    /**
     * @param  list<CustomizationScope>  $scopes  Layers admitted by this test policy.
     *
     * @since  2.0.0
     */
    public function __construct(private array $scopes)
    {
    }

    /** @inheritDoc */
    public function allows(
        SurfaceId $surface,
        ContributionOwner $owner,
        CustomizationSlot $slot,
        CustomizationScope $scope,
    ): bool {
        return in_array($scope, $this->scopes, true);
    }

    /** @inheritDoc */
    public function assertAllowed(
        SurfaceId $surface,
        ContributionOwner $owner,
        CustomizationSlot $slot,
        CustomizationScope $scope,
    ): void {
        if (!$this->allows($surface, $owner, $slot, $scope)) {
            throw new InvalidArgumentException('Not allowed by the selective test policy.');
        }
    }
}
