<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Application\Presentation\Dashboard;

use DateTimeImmutable;
use InvalidArgumentException;
use Kumwe\App\Application\Authorization\AuthorizationDenied;
use Kumwe\App\Application\Authorization\AuthorizationGateway;
use Kumwe\App\Application\Authorization\AuthorizationResource;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\App\Application\Presentation\Dashboard\DashboardPreferenceAccessGroupState;
use Kumwe\App\Application\Presentation\Dashboard\DashboardPreferenceMutation;
use Kumwe\App\Application\Presentation\Dashboard\DashboardPreferenceQuery;
use Kumwe\App\Application\Presentation\Dashboard\DashboardPreferenceService;
use Kumwe\App\Application\Presentation\Dashboard\DashboardPreferenceState;
use Kumwe\App\Application\Presentation\Preference\PresentationAccessGroup;
use Kumwe\App\Application\Presentation\Preference\PresentationAccessGroupCatalog;
use Kumwe\App\Application\Presentation\Preference\PresentationPreferenceManager;
use Kumwe\Extension\Spi\Contribution\ContributionOwner;
use Kumwe\App\InterfaceStandard\CustomizationScope;
use Kumwe\App\InterfaceStandard\CustomizationSlot;
use Kumwe\App\InterfaceStandard\PresentationPreference;
use Kumwe\App\InterfaceStandard\PresentationPreferenceKey;
use Kumwe\App\InterfaceStandard\SurfaceArea;
use Kumwe\App\InterfaceStandard\SurfaceId;
use Kumwe\Extension\Spi\Identity\Domain\Capability;
use Kumwe\App\Delivery\Http\Dashboard\DashboardPreferenceFormDecoder;
use Kumwe\App\Tests\Support\AuthorizationContext;
use Kumwe\App\Tests\Support\DashboardPreferenceTestRuntime;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Verifies exact dashboard form projection and strict live-catalog mutation delivery.
 *
 * @since  2.0.0
 */
#[CoversClass(DashboardPreferenceService::class)]
#[CoversClass(DashboardPreferenceState::class)]
#[CoversClass(DashboardPreferenceAccessGroupState::class)]
#[CoversClass(DashboardPreferenceMutation::class)]
#[CoversClass(PresentationAccessGroupCatalog::class)]
#[UsesClass(DashboardPreferenceFormDecoder::class)]
#[UsesClass(PresentationPreferenceManager::class)]
final class DashboardPreferenceServiceTest extends TestCase
{
    /**
     * Canonical role UUID used by access-group scenarios.
     *
     * @var    string
     * @since  2.0.0
     */
    private const ROLE_ID = '018f22e2-7c8b-7ab0-8f3a-88e8026bb303';

    /**
     * Proves exact rows retain their own versions while personal absence remains typed as inheritance.
     *
     * Portal deliberately exercises access-group forms too: area does not grant authority, `users.manage` does.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testBuildsExactPersonalAndAuthorizedPortalAccessGroupForms(): void
    {
        $group = PresentationAccessGroup::fromRole(self::ROLE_ID, 'operations', 'Operations');
        $runtime = new DashboardPreferenceTestRuntime([$group]);
        $context = AuthorizationContext::human(['users.manage']);
        $surface = SurfaceId::fromString('core.portal.home');
        $this->seed(
            $runtime,
            $surface,
            CustomizationScope::User,
            $context->actorId(),
            CustomizationSlot::DashboardCards,
            ['core.portal-approvals', 'core.dashboard.access-context'],
            4,
        );
        $this->seed(
            $runtime,
            $surface,
            CustomizationScope::RoleWorkspace,
            $group->id,
            CustomizationSlot::NavigationShortcuts,
            ['core.portal-business-records'],
            2,
        );

        $state = $runtime->service->read(
            $context,
            SurfaceArea::Portal,
            $surface,
            ContributionOwner::core(),
            true,
        );

        self::assertCount(1, $state->accessGroups);
        self::assertSame(
            ['core.portal-approvals', 'core.dashboard.access-context'],
            $state->personalWidgets?->value()->value(),
        );
        self::assertSame(4, $state->personalWidgets?->version());
        self::assertNull($state->personalShortcuts);
        self::assertSame($group->id, $state->accessGroups[0]->group->id);
        self::assertNull($state->accessGroups[0]->widgets);
        self::assertSame(
            ['core.portal-business-records'],
            $state->accessGroups[0]->shortcuts?->value()->value(),
        );
        self::assertSame(2, $state->accessGroups[0]->shortcuts?->version());
    }

    /**
     * Proves collection denial preserves the personal form while omitting the entire role catalogue.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testOmitsUnauthorizedGroupsWithoutHidingThePersonalForm(): void
    {
        $group = PresentationAccessGroup::fromRole(self::ROLE_ID, 'operations', 'Operations');
        $runtime = new DashboardPreferenceTestRuntime([$group]);
        $context = AuthorizationContext::human(['portal.access']);

        $state = $runtime->service->read(
            $context,
            SurfaceArea::Portal,
            SurfaceId::fromString('core.portal.home'),
            ContributionOwner::core(),
            true,
        );

        self::assertSame($context->actorId(), $state->userScopeId);
        self::assertNull($state->personalWidgets);
        self::assertSame([], $state->accessGroups);
    }

    /**
     * Proves a large role catalogue stays one compact form per page and roles beyond 64 remain reachable.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testBoundsAccessGroupFormsAndBatchesTheirPreferenceRead(): void
    {
        $groups = [];
        for ($index = 1; $index <= 65; $index++) {
            $groups[] = PresentationAccessGroup::fromRole(
                sprintf('018f22e2-7c8b-7ab0-8f3a-%012x', $index),
                sprintf('group-%03d', $index),
                sprintf('Group %03d', $index),
            );
        }
        $runtime = new DashboardPreferenceTestRuntime(array_reverse($groups));
        $first = $runtime->service->read(
            AuthorizationContext::human(['users.manage']),
            SurfaceArea::Administrator,
            SurfaceId::fromString('core.administrator.dashboard'),
            ContributionOwner::core(),
            true,
        );
        $tail = $runtime->service->read(
            AuthorizationContext::human(['users.manage']),
            SurfaceArea::Administrator,
            SurfaceId::fromString('core.administrator.dashboard'),
            ContributionOwner::core(),
            true,
            new DashboardPreferenceQuery(65),
        );

        self::assertCount(1, $first->accessGroups);
        self::assertSame($groups[0]->id, $first->accessGroups[0]->group->id);
        self::assertFalse($first->accessGroupHasPrevious);
        self::assertTrue($first->accessGroupHasNext);
        self::assertCount(1, $tail->accessGroups);
        self::assertSame($groups[64]->id, $tail->accessGroups[0]->group->id);
        self::assertTrue($tail->accessGroupHasPrevious);
        self::assertFalse($tail->accessGroupHasNext);
        self::assertSame([
            ['limit' => 1, 'offset' => 0, 'search' => ''],
            ['limit' => 1, 'offset' => 64, 'search' => ''],
        ], $runtime->groups->catalogQueries());
        self::assertSame(['find' => 0, 'find_many' => 2], $runtime->preferences->readCounts());
    }

    /**
     * Proves a scoped grant never leaks role names, counts, or forward-page state through the catalogue.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testCollectionDenialReturnsPersonalStateWithoutReadingTheRoleCatalog(): void
    {
        $group = PresentationAccessGroup::fromRole(self::ROLE_ID, 'operations', 'Operations');
        $runtime = new DashboardPreferenceTestRuntime([$group]);

        $state = $runtime->service->read(
            AuthorizationContext::siteScoped('users.manage'),
            SurfaceArea::Administrator,
            SurfaceId::fromString('core.administrator.dashboard'),
            ContributionOwner::core(),
            true,
            new DashboardPreferenceQuery(2, 'Operations'),
        );

        self::assertFalse($state->accessGroupAdministration);
        self::assertSame([], $state->accessGroups);
        self::assertFalse($state->accessGroupHasPrevious);
        self::assertFalse($state->accessGroupHasNext);
        self::assertSame([], $runtime->groups->catalogQueries());
        self::assertSame(['find' => 0, 'find_many' => 1], $runtime->preferences->readCounts());
    }

    /**
     * Proves role catalogue reads use only the installation-global collection decision.
     *
     * `users.manage` is global-only and its role policy is installation-global, so canonical role rows all
     * require the same grant. Exact item authorization is deliberately reserved for a mutation and its lock.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRoleCatalogUsesAConstantCollectionAuthorizationBudget(): void
    {
        $group = PresentationAccessGroup::fromRole(
            '018f22e2-7c8b-7ab0-8f3a-88e8026bb304',
            'group-001',
            'Canonical global role',
        );
        $targets = [];
        $authorization = $this->createStub(AuthorizationGateway::class);
        $authorization->method('assertAllowed')->willReturnCallback(static function (
            ExecutionContext $context,
            Capability $capability,
            AuthorizationResource $resource,
        ) use (&$targets): void {
            $targets[] = $capability->value() . ':' . $resource->type() . ':' . $resource->identifier();
        });
        $runtime = new DashboardPreferenceTestRuntime([$group], $authorization);

        $state = $runtime->service->read(
            AuthorizationContext::human(['users.manage']),
            SurfaceArea::Administrator,
            SurfaceId::fromString('core.administrator.dashboard'),
            ContributionOwner::core(),
            true,
            new DashboardPreferenceQuery(),
        );

        self::assertTrue($state->accessGroupAdministration);
        self::assertSame([$group->id], array_map(
            static fn (DashboardPreferenceAccessGroupState $group): string => $group->group->id,
            $state->accessGroups,
        ));
        self::assertFalse($state->accessGroupHasPrevious);
        self::assertFalse($state->accessGroupHasNext);
        self::assertFalse($state->accessGroupBrowseLimit);
        self::assertSame(['users.manage:role:*', 'users.manage:role:*'], $targets);
        self::assertSame(['find' => 0, 'find_many' => 1], $runtime->preferences->readCounts());
    }

    /**
     * Proves raw forward evidence at the numeric browse cap becomes a search instruction, never an unsafe link.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testMaximumBrowsePageClampsForwardNavigationToTargetedSearch(): void
    {
        $groups = [];
        for ($index = 1; $index <= DashboardPreferenceQuery::MAXIMUM_PAGE + 1; $index++) {
            $groups[] = PresentationAccessGroup::fromRole(
                sprintf('018f22e2-7c8b-7ab0-8f3b-%012x', $index),
                sprintf('maximum-page-%05d', $index),
                sprintf('Maximum page %05d', $index),
            );
        }
        $completeRuntime = new DashboardPreferenceTestRuntime(array_slice($groups, 0, -1));
        $complete = $completeRuntime->service->read(
            AuthorizationContext::human(['users.manage']),
            SurfaceArea::Administrator,
            SurfaceId::fromString('core.administrator.dashboard'),
            ContributionOwner::core(),
            true,
            new DashboardPreferenceQuery(DashboardPreferenceQuery::MAXIMUM_PAGE),
        );
        $runtime = new DashboardPreferenceTestRuntime($groups);

        $state = $runtime->service->read(
            AuthorizationContext::human(['users.manage']),
            SurfaceArea::Administrator,
            SurfaceId::fromString('core.administrator.dashboard'),
            ContributionOwner::core(),
            true,
            new DashboardPreferenceQuery(DashboardPreferenceQuery::MAXIMUM_PAGE),
        );

        self::assertCount(1, $complete->accessGroups);
        self::assertFalse($complete->accessGroupHasNext);
        self::assertFalse($complete->accessGroupBrowseLimit);
        self::assertCount(1, $state->accessGroups);
        self::assertTrue($state->accessGroupHasPrevious);
        self::assertFalse($state->accessGroupHasNext);
        self::assertTrue($state->accessGroupBrowseLimit);
        self::assertSame([[
            'limit' => 1,
            'offset' => 99,
            'search' => '',
        ]], $runtime->groups->catalogQueries());
    }

    /**
     * Proves an exact code search reaches a canonical role beyond the numeric browse window.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testUniqueSearchReachesRoleBeyondTheNumericBrowseWindow(): void
    {
        $groups = [];
        for ($index = 1; $index <= 128; $index++) {
            $groups[] = PresentationAccessGroup::fromRole(
                sprintf('018f22e2-7c8b-7ab0-8f3c-%012x', $index),
                sprintf('searchable-role-%03d', $index),
                sprintf('Searchable role %03d', $index),
            );
        }
        $runtime = new DashboardPreferenceTestRuntime($groups);

        $state = $runtime->service->read(
            AuthorizationContext::human(['users.manage']),
            SurfaceArea::Administrator,
            SurfaceId::fromString('core.administrator.dashboard'),
            ContributionOwner::core(),
            true,
            new DashboardPreferenceQuery(1, 'searchable-role-128'),
        );

        self::assertSame($groups[127]->id, $state->accessGroups[0]->group->id);
        self::assertFalse($state->accessGroupHasNext);
        self::assertSame([[
            'limit' => 1,
            'offset' => 0,
            'search' => 'searchable-role-128',
        ]], $runtime->groups->catalogQueries());
    }

    /**
     * Proves an intentional empty personal list remains distinct from an absent inherited row.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testExactEmptyPersonalRowWinsOverTheRenderedFallback(): void
    {
        $runtime = new DashboardPreferenceTestRuntime();
        $context = AuthorizationContext::human(['portal.access']);
        $surface = SurfaceId::fromString('core.portal.home');
        $this->seed(
            $runtime,
            $surface,
            CustomizationScope::User,
            $context->actorId(),
            CustomizationSlot::DashboardCards,
            [],
            3,
        );

        $state = $runtime->service->read(
            $context,
            SurfaceArea::Portal,
            $surface,
            ContributionOwner::core(),
            false,
        );

        self::assertSame([], $state->personalWidgets?->value()->value());
        self::assertSame(3, $state->personalWidgets?->version());
    }

    /**
     * Proves save reconstructs checked identifiers by exact submitted order and reset deletes the exact row.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testSavesOrderedPersonalSelectionAndResetsItOptimistically(): void
    {
        $runtime = new DashboardPreferenceTestRuntime();
        $context = AuthorizationContext::human(['administrator.access', 'content.read']);
        $surface = SurfaceId::fromString('core.administrator.dashboard');
        $key = new PresentationPreferenceKey(
            $surface,
            CustomizationSlot::DashboardCards,
            CustomizationScope::User,
            $context->actorId(),
        );
        $runtime->service->mutate(
            $context,
            SurfaceArea::Administrator,
            $surface,
            ContributionOwner::core(),
            $runtime->decoder->decode([
                'action' => 'dashboard-cards.save',
                'scope' => 'user',
                'scope_id' => $context->actorId(),
                'expected_version' => '0',
                'item_0' => 'core.dashboard.content-summary',
                'selected_0' => '1',
                'order_0' => '20',
                'item_1' => '2acme.sales__orders',
                'selected_1' => '1',
                'order_1' => '10',
                'item_2' => 'core.settings',
                'order_2' => '1',
            ]),
            ['core.dashboard.content-summary', '2acme.sales__orders', 'core.settings'],
            [],
        );

        self::assertSame(
            ['2acme.sales__orders', 'core.dashboard.content-summary'],
            $runtime->preferences->find($key)?->value()->value(),
        );
        self::assertSame(1, $runtime->preferences->find($key)?->version());

        $runtime->service->mutate(
            $context,
            SurfaceArea::Administrator,
            $surface,
            ContributionOwner::core(),
            $runtime->decoder->decode([
                'action' => 'dashboard-cards.reset',
                'scope' => 'user',
                'scope_id' => $context->actorId(),
                'expected_version' => '1',
                'item_stale' => 'withdrawn.widget',
            ]),
            [],
            [],
        );

        self::assertNull($runtime->preferences->find($key));
    }

    /**
     * Proves portal delivery admits a canonical access-group target when exact role authority is present.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testPortalCanSaveAnAuthorizedAccessGroupSelection(): void
    {
        $group = PresentationAccessGroup::fromRole(self::ROLE_ID, 'operations', 'Operations');
        $runtime = new DashboardPreferenceTestRuntime([$group]);
        $context = AuthorizationContext::human(['portal.access', 'users.manage']);
        $surface = SurfaceId::fromString('core.portal.home');
        $runtime->service->mutate(
            $context,
            SurfaceArea::Portal,
            $surface,
            ContributionOwner::core(),
            $runtime->decoder->decode([
                'action' => 'dashboard-cards.save',
                'scope' => 'role-workspace',
                'scope_id' => $group->id,
                'expected_version' => '0',
                'item_0' => 'core.dashboard.access-context',
                'selected_0' => '1',
                'order_0' => '1',
            ]),
            ['core.dashboard.access-context'],
            [],
        );

        $stored = $runtime->preferences->find(new PresentationPreferenceKey(
            $surface,
            CustomizationSlot::DashboardCards,
            CustomizationScope::RoleWorkspace,
            $group->id,
        ));
        self::assertSame(['core.dashboard.access-context'], $stored?->value()->value());
        self::assertSame(['group:' . $group->id], $runtime->groups->locks());
    }

    /**
     * Proves portal area alone never grants access-group preference authority.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testPortalAccessGroupSaveStillRequiresUsersManage(): void
    {
        $group = PresentationAccessGroup::fromRole(self::ROLE_ID, 'operations', 'Operations');
        $runtime = new DashboardPreferenceTestRuntime([$group]);
        $context = AuthorizationContext::human(['portal.access']);
        $this->expectException(AuthorizationDenied::class);

        $runtime->service->mutate(
            $context,
            SurfaceArea::Portal,
            SurfaceId::fromString('core.portal.home'),
            ContributionOwner::core(),
            $runtime->decoder->decode([
                'action' => 'dashboard-cards.save',
                'scope' => 'role-workspace',
                'scope_id' => $group->id,
                'expected_version' => '0',
            ]),
            [],
            [],
        );
    }

    /**
     * Proves malformed flat form projections fail before any preference row is written.
     *
     * @param   array<string, string>  $form     Candidate malicious or ambiguous form.
     * @param   list<string>           $allowed  Current caller-supplied widget catalog.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    #[DataProvider('invalidForms')]
    public function testRejectsMalformedDuplicateOrderedAndUnknownSelections(array $form, array $allowed): void
    {
        $runtime = new DashboardPreferenceTestRuntime();
        $context = AuthorizationContext::human(['administrator.access']);
        $this->expectException(InvalidArgumentException::class);

        $runtime->service->mutate(
            $context,
            SurfaceArea::Administrator,
            SurfaceId::fromString('core.administrator.dashboard'),
            ContributionOwner::core(),
            $runtime->decoder->decode($form),
            $allowed,
            [],
        );
    }

    /**
     * Supply invalid forms covering identity, syntax, duplicate, live-catalog, and order ambiguity.
     *
     * @return  iterable<string, array{array<string, string>, list<string>}>  Invalid scenario arguments.
     *
     * @since   2.0.0
     */
    public static function invalidForms(): iterable
    {
        $base = [
            'action' => 'dashboard-cards.save',
            'scope' => 'user',
            'scope_id' => AuthorizationContext::SUBJECT,
            'expected_version' => '0',
        ];
        yield 'unknown live identifier' => [[
            ...$base,
            'item_0' => 'withdrawn.widget',
            'selected_0' => '1',
            'order_0' => '1',
        ], ['core.settings']];
        yield 'duplicate identifier' => [[
            ...$base,
            'item_0' => 'core.settings',
            'order_0' => '1',
            'item_1' => 'core.settings',
            'order_1' => '2',
        ], ['core.settings']];
        yield 'duplicate order' => [[
            ...$base,
            'item_0' => 'core.settings',
            'selected_0' => '1',
            'order_0' => '1',
            'item_1' => 'core.access',
            'selected_1' => '1',
            'order_1' => '1',
        ], ['core.settings', 'core.access']];
        yield 'missing order' => [[
            ...$base,
            'item_0' => 'core.settings',
            'selected_0' => '1',
        ], ['core.settings']];
        yield 'non-contiguous index' => [[
            ...$base,
            'item_1' => 'core.settings',
            'order_1' => '1',
        ], ['core.settings']];
        yield 'selected index without item' => [[
            ...$base,
            'selected_0' => '1',
        ], ['core.settings']];
        yield 'non-canonical index' => [[
            ...$base,
            'item_00' => 'core.settings',
            'order_00' => '1',
        ], ['core.settings']];
        yield 'foreign user' => [[
            ...$base,
            'scope_id' => '018f22e2-7c8b-7ab0-8f3a-88e8026bb399',
        ], []];
        yield 'malformed role' => [[
            ...$base,
            'scope' => 'role-workspace',
            'scope_id' => 'role:operations',
        ], []];
        yield 'unsupported action' => [[...$base, 'action' => 'dashboard-cards.publish'], []];
        yield 'non-canonical version' => [[...$base, 'expected_version' => '01'], []];
        yield 'version above integer range' => [[
            ...$base,
            'expected_version' => str_repeat('9', strlen((string) PHP_INT_MAX)),
        ], []];
        yield 'invalid selection flag' => [[
            ...$base,
            'item_0' => 'core.settings',
            'selected_0' => 'yes',
            'order_0' => '1',
        ], ['core.settings']];
        yield 'duplicate live allowlist' => [$base, ['core.settings', 'core.settings']];
        yield 'invalid surface grammar in allowlist' => [$base, ['core']];
    }

    /**
     * Proves a dashboard-card form cannot select more than the KIS slot maximum.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRejectsASelectionBeyondTheDashboardCardLimit(): void
    {
        $runtime = new DashboardPreferenceTestRuntime();
        $context = AuthorizationContext::human(['administrator.access']);
        $form = [
            'action' => 'dashboard-cards.save',
            'scope' => 'user',
            'scope_id' => $context->actorId(),
            'expected_version' => '0',
        ];
        $allowed = [];
        for ($index = 0; $index < 65; $index++) {
            $identifier = 'core.widget-' . $index;
            $allowed[] = $identifier;
            $form['item_' . $index] = $identifier;
            $form['selected_' . $index] = '1';
            $form['order_' . $index] = (string) ($index + 1);
        }
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('KIS limit');

        $runtime->service->mutate(
            $context,
            SurfaceArea::Administrator,
            SurfaceId::fromString('core.administrator.dashboard'),
            ContributionOwner::core(),
            $runtime->decoder->decode($form),
            $allowed,
            [],
        );
    }

    /**
     * Seed one exact row without exercising mutation behavior irrelevant to form projection.
     *
     * @param   DashboardPreferenceTestRuntime  $runtime  In-memory test runtime receiving the row.
     * @param   SurfaceId                      $surface  Exact dashboard surface.
     * @param   CustomizationScope             $scope    Personal or access-group hierarchy layer.
     * @param   string                         $scopeId  Actor or stable group identity.
     * @param   CustomizationSlot              $slot     Dashboard list slot.
     * @param   list<string>                   $value    Ordered semantic identifiers.
     * @param   int                            $version  Positive fixture version.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function seed(
        DashboardPreferenceTestRuntime $runtime,
        SurfaceId $surface,
        CustomizationScope $scope,
        string $scopeId,
        CustomizationSlot $slot,
        array $value,
        int $version,
    ): void {
        $runtime->preferences->seed(PresentationPreference::create(
            $surface,
            ContributionOwner::core(),
            $scope,
            $scopeId,
            $slot,
            $value,
            $version,
            AuthorizationContext::SUBJECT,
            new DateTimeImmutable('2026-08-15T11:00:00+00:00'),
        ));
    }
}
