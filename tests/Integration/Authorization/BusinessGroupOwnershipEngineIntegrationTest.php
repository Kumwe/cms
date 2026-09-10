<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\Authorization;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Logging\Middleware;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\Types;
use Kumwe\App\Kernel\Container;
use Kumwe\App\Application\Authorization\AuthorizationDenied;
use Kumwe\App\Application\Authorization\AuthorizationGateway;
use Kumwe\App\Application\Authorization\AuthorizationResource;
use Kumwe\App\Application\Authorization\DenyByDefaultAuthorizationGateway;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\App\Application\Authorization\OwnershipNarrowingRefused;
use Kumwe\App\Application\Authorization\OwnershipScope;
use Kumwe\App\Application\Authorization\OwnershipScopeLevel;
use Kumwe\App\Application\Authorization\OwnershipScopeNotPermitted;
use Kumwe\App\Application\Authorization\ResourceOwnership;
use Kumwe\App\Application\Authorization\ResourceOwnershipScopePolicy;
use Kumwe\App\Application\Authorization\ResourceOwnershipScopeService;
use Kumwe\App\Application\Authorization\ResourceSiteOwnership;
use Kumwe\App\Application\Authorization\ResourceSiteOwnershipWriter;
use Kumwe\Context\Value\SiteContext;
use Kumwe\App\Application\Authorization\SiteGroup;
use Kumwe\App\Application\Authorization\SiteGroupAdministration;
use Kumwe\App\BusinessReporting\Application\ConsolidatedGroupReportScope;
use Kumwe\Extension\Spi\Identity\Domain\Capability;
use Kumwe\App\Infrastructure\Authorization\DoctrineGrantScopeOwnershipReferences;
use Kumwe\App\Infrastructure\Authorization\DoctrineResourceSiteOwnership;
use Kumwe\App\Infrastructure\Authorization\DoctrineResourceSiteOwnershipWriter;
use Kumwe\App\Infrastructure\Authorization\DoctrineSiteGroupRegistry;
use Kumwe\App\Infrastructure\Authorization\DoctrineSiteGroupWriter;
use Kumwe\App\Infrastructure\Persistence\Migration\ResourceOwnershipScopeMigration;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Tests\Support\AuthorizationContext;
use Kumwe\App\Tests\Support\BusinessQueryCounter;
use Kumwe\App\Tests\Support\TestKernelFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

#[CoversClass(ResourceOwnershipScopeMigration::class)]
#[CoversClass(DoctrineResourceSiteOwnership::class)]
#[CoversClass(DoctrineResourceSiteOwnershipWriter::class)]
#[CoversClass(DoctrineSiteGroupRegistry::class)]
#[CoversClass(DoctrineSiteGroupWriter::class)]
#[CoversClass(DoctrineGrantScopeOwnershipReferences::class)]
#[CoversClass(ResourceOwnershipScopeService::class)]
#[CoversClass(SiteGroupAdministration::class)]
#[CoversClass(ConsolidatedGroupReportScope::class)]
#[CoversClass(DenyByDefaultAuthorizationGateway::class)]
#[CoversClass(ResourceOwnership::class)]
#[CoversClass(ResourceOwnershipScopePolicy::class)]
#[CoversClass(OwnershipScope::class)]
/**
 * Proves the business-group ownership model of ADR 0001 against the stored registry on a real engine.
 *
 * Work package `P3-F` owes the proof on the engine matrix that the unit suite cannot give: the widened
 * schema, the declared groups, the containment decision, the frozen category table, the asymmetric and
 * audited scope changes, the consolidated read boundary and the hot path's query budget, each running
 * against the migrated database rather than in-memory doubles. The suite runs identically on MariaDB,
 * MySQL and PostgreSQL via the merge workflow's engine matrix. A site-owned resource behaving exactly as
 * it did before groups existed is additionally asserted by the pre-existing isolation tests, which this
 * package deliberately leaves unmodified.
 *
 * @since  2.0.0
 */
final class BusinessGroupOwnershipEngineIntegrationTest extends TestCase
{
    /**
     * The booted kernel this test's scenario runs against, memoized for the teardown to clean through.
     *
     * @var    ?Container
     * @since  2.0.0
     */
    private ?Container $kernel = null;

    /**
     * Ownership rows this test recorded, as resource type and identifier pairs.
     *
     * @var    list<array{string, string}>
     * @since  2.0.0
     */
    private array $recordedOwnership = [];

    /**
     * Role rows this test created for the reference guard.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private array $recordedRoles = [];

    /**
     * Group declarations this test made.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private array $recordedGroups = [];

    /**
     * Site rows this test created.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private array $recordedSites = [];

    /**
     * Remove everything the scenario declared, so the shared suite database is left as it was found.
     *
     * The order respects the schema: ownership rows first, because a group may not be dropped while it
     * still owns something; then roles, whose grants cascade; then groups, whose membership cascades;
     * then the sites themselves.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    protected function tearDown(): void
    {
        $kernel = $this->kernel;
        if ($kernel !== null) {
            $database = $this->connection($kernel);
            $tables = $this->tables($kernel);
            foreach ($this->recordedOwnership as [$type, $identifier]) {
                $database->delete($tables->raw('resource_site_ownership'), [
                    'resource_type' => $type,
                    'resource_id' => $identifier,
                ]);
            }
            foreach ($this->recordedRoles as $role) {
                $database->delete($tables->raw('roles'), ['id' => $role]);
            }
            foreach ($this->recordedGroups as $group) {
                $database->delete($tables->raw('site_groups'), ['identifier' => $group]);
            }
            foreach ($this->recordedSites as $site) {
                $database->delete($tables->raw('sites'), ['identifier' => $site]);
            }
        }

        parent::tearDown();
    }

    /**
     * The forward migration replays cleanly on the migrated schema and changes nothing the second time.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheOwnershipScopeMigrationReplaysCleanlyOnTheMigratedSchema(): void
    {
        $container = $this->container();
        $database = $this->connection($container);
        $tables = $this->tables($container);
        $rows = static fn (string $table): string => (string) $database->fetchOne(
            sprintf('SELECT COUNT(*) FROM %s', $tables->quoted($table)),
        );
        $before = [$rows('resource_site_ownership'), $rows('role_capability_grants'), $rows('capabilities')];

        (new ResourceOwnershipScopeMigration($tables))->up($database);
        $between = [$rows('resource_site_ownership'), $rows('role_capability_grants'), $rows('capabilities')];
        (new ResourceOwnershipScopeMigration($tables))->up($database);
        $after = [$rows('resource_site_ownership'), $rows('role_capability_grants'), $rows('capabilities')];

        self::assertSame($before, $between, 'A replay must add no ownership, grant or capability rows.');
        self::assertSame($between, $after, 'A second replay must be exactly as idle as the first.');
        $schema = $database->createSchemaManager();
        self::assertTrue($schema->tablesExist([$tables->raw('site_groups'), $tables->raw('site_group_members')]));
        $ownership = $schema->introspectTableByUnquotedName($tables->raw('resource_site_ownership'));
        self::assertTrue($ownership->hasColumn('scope_level'));
        self::assertTrue($ownership->hasColumn('group_identifier'));
        self::assertFalse($ownership->getColumn('site_identifier')->getNotnull());
        self::assertSame('0', (string) $database->fetchOne(sprintf(
            'SELECT COUNT(*) FROM %s WHERE scope_level IS NULL OR scope_level = ?',
            $tables->quoted('resource_site_ownership'),
        ), ['']));
    }

    /**
     * In a four-business installation, every member site of one declared group sees a group-owned
     * client and the site outside the group sees nothing.
     *
     * This is the four-business scenario ADR 0001 exists to serve, exercised against the stored
     * registry: four legal-entity sites on one installation, three sharing a client by explicit
     * declaration, the fourth explicitly outside.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAGroupOwnedClientIsVisibleToEveryMemberSiteAndInvisibleOutside(): void
    {
        $container = $this->container();
        $suffix = $this->suffix();
        [$siteA, $siteB, $siteC, $siteD] = $this->createSites($container, $suffix, 4);
        $group = $this->defineGroup($container, 'erpfg' . $suffix, [$siteA, $siteB, $siteD]);
        $client = $this->createClientOwnedBy($container, $siteA);
        $this->widenToGroup($container, $client, $group, $siteA);
        $gateway = $this->gateway($container);
        $action = Capability::fromString('ownership.scope.manage');
        $resource = AuthorizationResource::item('client', $client);

        foreach ([$siteA, $siteB, $siteD] as $member) {
            $decision = $gateway->decide($this->scopedContext($container, $member), $action, $resource);
            self::assertTrue($decision->allowed, sprintf('%s must see the group-owned client.', $member));
            self::assertSame('matching_effective_grant', $decision->reason);
        }
        $outside = $gateway->decide($this->scopedContext($container, $siteC), $action, $resource);
        self::assertFalse($outside->allowed, 'A site outside the group must see nothing it owns.');
        self::assertSame('resource_site_mismatch', $outside->reason);
    }

    /**
     * Overlapping declared groups resolve independently: shared membership pools nothing between them.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testOverlappingGroupsResolveIndependently(): void
    {
        $container = $this->container();
        $suffix = $this->suffix();
        [$siteA, $siteB, $siteC] = $this->createSites($container, $suffix, 3);
        $groupAb = $this->defineGroup($container, 'erpfab' . $suffix, [$siteA, $siteB]);
        $groupAc = $this->defineGroup($container, 'erpfac' . $suffix, [$siteA, $siteC]);
        $clientAb = $this->createClientOwnedBy($container, $siteA);
        $clientAc = $this->createClientOwnedBy($container, $siteA);
        $this->widenToGroup($container, $clientAb, $groupAb, $siteA);
        $this->widenToGroup($container, $clientAc, $groupAc, $siteA);
        $gateway = $this->gateway($container);
        $action = Capability::fromString('ownership.scope.manage');
        $sees = static fn (string $site, string $client): bool => $gateway->decide(
            TestKernelFactory::contextFromGrantRows($container, [[
                'capability' => 'ownership.scope.manage',
                'scope_type' => 'site',
                'scope_identifier' => $site,
            ]], $site),
            $action,
            AuthorizationResource::item('client', $client),
        )->allowed;

        self::assertTrue($sees($siteA, $clientAb), 'The overlap site reaches the first group\'s client.');
        self::assertTrue($sees($siteA, $clientAc), 'The overlap site reaches the second group\'s client.');
        self::assertTrue($sees($siteB, $clientAb));
        self::assertFalse($sees($siteB, $clientAc), 'Membership of one group must not open the other.');
        self::assertTrue($sees($siteC, $clientAc));
        self::assertFalse($sees($siteC, $clientAb), 'Membership of one group must not open the other.');
    }

    /**
     * A site-owned resource still resolves at site scope and refuses every other site, group or none.
     *
     * The stored row is read back as a site-level scope whose containment test reduces to the identifier
     * equality it replaced, so even a fellow group member of the owning site is refused exactly as it was
     * before the widening existed. The pre-existing isolation tests assert the same property unmodified.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testASiteOwnedResourceStillBehavesExactlyAsToday(): void
    {
        $container = $this->container();
        $suffix = $this->suffix();
        [$siteA, $siteB] = $this->createSites($container, $suffix, 2);
        $this->defineGroup($container, 'erpfsg' . $suffix, [$siteA, $siteB]);
        $client = $this->createClientOwnedBy($container, $siteA);
        $scope = $this->ownership($container)->scopeFor(AuthorizationResource::item('client', $client));
        self::assertSame(OwnershipScopeLevel::Site, $scope->level);
        self::assertSame($siteA, $scope->identifier);

        $gateway = $this->gateway($container);
        $action = Capability::fromString('ownership.scope.manage');
        $resource = AuthorizationResource::item('client', $client);
        self::assertTrue($gateway->decide($this->scopedContext($container, $siteA), $action, $resource)->allowed);
        $fellow = $gateway->decide($this->scopedContext($container, $siteB), $action, $resource);
        self::assertFalse($fellow->allowed, 'Sharing a group with the owner must grant nothing by itself.');
        self::assertSame('resource_site_mismatch', $fellow->reason);
    }

    /**
     * A group-scoped owner for a ledger is refused by the frozen table, the service and the schema.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAGroupScopedOwnerForALedgerIsRefused(): void
    {
        $container = $this->container();
        $suffix = $this->suffix();
        [$siteA, $siteB] = $this->createSites($container, $suffix, 2);
        $groupId = $this->defineGroup($container, 'erpfl' . $suffix, [$siteA, $siteB]);
        $group = $this->group($container, $groupId);
        $policy = $container->get(ResourceOwnershipScopePolicy::class);
        self::assertInstanceOf(ResourceOwnershipScopePolicy::class, $policy);
        $ledger = Uuid::uuid7()->toString();

        try {
            ResourceOwnership::of(
                AuthorizationResource::item('ledger', $ledger),
                OwnershipScope::group($group),
                $policy,
            );
            self::fail('The frozen category table must refuse a group-owned ledger.');
        } catch (OwnershipScopeNotPermitted) {
            // The registry-side guard is the assertion: the pairing cannot even be assembled.
        }

        $service = $this->scopeService($container);
        try {
            $service->widen(
                $this->scopedContext($container, $siteA),
                AuthorizationResource::item('ledger', $ledger),
                OwnershipScope::group($group),
            );
            self::fail('No authorization policy may bind a scope change to a ledger at all.');
        } catch (AuthorizationDenied) {
            // The ledger category is not even bound to the scope-change capability.
        }

        $database = $this->connection($container);
        $tables = $this->tables($container);
        try {
            $database->insert($tables->raw('resource_site_ownership'), [
                'resource_type' => 'client',
                'resource_id' => $ledger,
                'site_identifier' => $siteA,
                'scope_level' => OwnershipScopeLevel::Group->value,
                'group_identifier' => $groupId,
            ]);
            self::fail('The schema must refuse an ownership row that spells two owners.');
        } catch (DbalException) {
            // The check constraint holds on this engine.
        }
        self::assertFalse($database->fetchOne(sprintf(
            'SELECT resource_id FROM %s WHERE resource_type = ? AND resource_id = ?',
            $tables->quoted('resource_site_ownership'),
        ), ['client', $ledger]), 'The refused row must not be stored.');
    }

    /**
     * Widening a client to a group writes one audit entry and the change is reversible by narrowing.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testWideningIsAuditedAndReversible(): void
    {
        $container = $this->container();
        $suffix = $this->suffix();
        [$siteA, $siteB] = $this->createSites($container, $suffix, 2);
        $groupId = $this->defineGroup($container, 'erpfw' . $suffix, [$siteA, $siteB]);
        $client = $this->createClientOwnedBy($container, $siteA);
        $service = $this->scopeService($container);
        $context = $this->scopedContext($container, $siteA);
        $resource = AuthorizationResource::item('client', $client);

        $widened = $service->widen($context, $resource, OwnershipScope::group($this->group($container, $groupId)));
        self::assertSame(OwnershipScopeLevel::Group, $widened->level);
        $entry = $this->auditEntry($container, 'ownership.scope.widen', $client);
        self::assertSame('site:' . $siteA, $entry['from_scope']);
        self::assertSame('group:' . $groupId, $entry['to_scope']);

        $narrowed = $service->narrow($context, $resource, OwnershipScope::site(SiteContext::fromString($siteA)));
        self::assertSame(OwnershipScopeLevel::Site, $narrowed->level);
        self::assertSame($siteA, $narrowed->identifier);
        $reversal = $this->auditEntry($container, 'ownership.scope.narrow', $client);
        self::assertSame('group:' . $groupId, $reversal['from_scope']);
        self::assertSame('site:' . $siteA, $reversal['to_scope']);
        $stored = $this->ownership($container)->scopeFor($resource);
        self::assertSame(OwnershipScopeLevel::Site, $stored->level);
        self::assertSame($siteA, $stored->identifier);
    }

    /**
     * Narrowing is refused, naming the site, while a member site's grant still refers to the resource,
     * and succeeds once the reference is gone.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testNarrowingIsRefusedWhileAReferenceStandsAndSucceedsOnceItIsGone(): void
    {
        $container = $this->container();
        $database = $this->connection($container);
        $tables = $this->tables($container);
        $suffix = $this->suffix();
        [$siteA, $siteB] = $this->createSites($container, $suffix, 2);
        $groupId = $this->defineGroup($container, 'erpfn' . $suffix, [$siteA, $siteB]);
        $client = $this->createClientOwnedBy($container, $siteA);
        $this->widenToGroup($container, $client, $groupId, $siteA);

        $roleId = Uuid::uuid7()->toString();
        $grantId = Uuid::uuid7()->toString();
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $database->insert($tables->raw('roles'), [
            'id' => $roleId,
            'code' => 'erpf-reference-' . $suffix,
            'name' => 'Ownership reference probe',
            'created_at' => $now,
        ], ['created_at' => Types::DATETIME_IMMUTABLE]);
        $writer = $container->get(ResourceSiteOwnershipWriter::class);
        self::assertInstanceOf(ResourceSiteOwnershipWriter::class, $writer);
        $writer->record(AuthorizationResource::item('role', $roleId), SiteContext::fromString($siteB));
        $this->recordedOwnership[] = ['role', $roleId];
        $this->recordedRoles[] = $roleId;
        $database->insert($tables->raw('role_capability_grants'), [
            'id' => $grantId,
            'role_id' => $roleId,
            'capability_code' => 'ownership.scope.manage',
            'scope_type' => 'client',
            'scope_identifier' => $client,
            'granted_at' => $now,
            'granted_by' => null,
        ], ['granted_at' => Types::DATETIME_IMMUTABLE]);

        $service = $this->scopeService($container);
        $context = $this->scopedContext($container, $siteA);
        $resource = AuthorizationResource::item('client', $client);
        $target = OwnershipScope::site(SiteContext::fromString($siteA));
        try {
            $service->narrow($context, $resource, $target);
            self::fail('Narrowing must be refused while the losing site still refers to the resource.');
        } catch (OwnershipNarrowingRefused $refused) {
            self::assertSame([$siteB], $refused->referencingSites, 'The refusal must name the stranded site.');
        }
        self::assertSame(
            OwnershipScopeLevel::Group,
            $this->ownership($container)->scopeFor($resource)->level,
            'A refused narrowing must change nothing.',
        );

        $database->delete($tables->raw('role_capability_grants'), ['id' => $grantId]);
        $narrowed = $service->narrow($context, $resource, $target);
        self::assertSame(OwnershipScopeLevel::Site, $narrowed->level);
        self::assertSame($siteA, $narrowed->identifier);
    }

    /**
     * Disabling one member site withdraws exactly that site's access to what the group owns.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testDisablingOneMemberSiteWithdrawsOnlyThatSitesAccess(): void
    {
        $container = $this->container();
        $suffix = $this->suffix();
        [$siteA, $siteB, $siteC] = $this->createSites($container, $suffix, 3);
        $groupId = $this->defineGroup($container, 'erpfd' . $suffix, [$siteA, $siteB, $siteC]);
        $client = $this->createClientOwnedBy($container, $siteA);
        $this->widenToGroup($container, $client, $groupId, $siteA);
        $gateway = $this->gateway($container);
        $action = Capability::fromString('ownership.scope.manage');
        $resource = AuthorizationResource::item('client', $client);

        try {
            $this->setSiteEnabled($container, $siteB, false);
            $withdrawn = $gateway->decide($this->scopedContext($container, $siteB), $action, $resource);
            self::assertFalse($withdrawn->allowed, 'A disabled site must lose its reach into the group.');
            self::assertSame('resource_site_mismatch', $withdrawn->reason);
            foreach ([$siteA, $siteC] as $unaffected) {
                self::assertTrue(
                    $gateway->decide($this->scopedContext($container, $unaffected), $action, $resource)->allowed,
                    sprintf('%s must keep its reach while another member is disabled.', $unaffected),
                );
            }
        } finally {
            $this->setSiteEnabled($container, $siteB, true);
        }
        self::assertTrue(
            $gateway->decide($this->scopedContext($container, $siteB), $action, $resource)->allowed,
            'Re-enabling the site must restore its reach without any ownership change.',
        );
    }

    /**
     * A consolidated group report resolves to exactly the enabled member sites and refuses outsiders.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAConsolidatedGroupReportReturnsExactlyTheMemberSites(): void
    {
        $container = $this->container();
        $suffix = $this->suffix();
        [$siteA, $siteB, $siteC] = $this->createSites($container, $suffix, 3);
        $groupId = $this->defineGroup($container, 'erpfr' . $suffix, [$siteA, $siteB]);
        $scope = $container->get(ConsolidatedGroupReportScope::class);
        self::assertInstanceOf(ConsolidatedGroupReportScope::class, $scope);
        $reader = static fn (string $site): ExecutionContext => TestKernelFactory::contextFromGrantRows(
            $container,
            [[
                'capability' => 'reports.consolidated.read',
                'scope_type' => 'site',
                'scope_identifier' => $site,
            ]],
            $site,
        );

        self::assertSame(
            [$siteA, $siteB],
            $scope->sitesFor($reader($siteA), $groupId),
            'The report boundary must be exactly the declared membership, never wider.',
        );

        try {
            $scope->sitesFor($reader($siteC), $groupId);
            self::fail('A caller outside the group must not learn its reporting boundary.');
        } catch (AuthorizationDenied) {
            // The group's own ownership scope settles who may consolidate it.
        }

        try {
            $this->setSiteEnabled($container, $siteB, false);
            self::assertSame(
                [$siteA],
                $scope->sitesFor($reader($siteA), $groupId),
                'A disabled member must drop out of the reporting boundary.',
            );
        } finally {
            $this->setSiteEnabled($container, $siteB, true);
        }
    }

    /**
     * A group-owned decision costs exactly the queries a site-owned decision costs: one per call.
     *
     * The containment test must not become a join per authorization call. Ownership resolution issues the
     * single row lookup either way, and group membership is answered from the declared set the registry
     * read once, so the counter proves the budget instead of the code path merely claiming it.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheAuthorizationHotPathIssuesNoAdditionalQueryPerCall(): void
    {
        $container = $this->container();
        $suffix = $this->suffix();
        [$siteA, $siteB] = $this->createSites($container, $suffix, 2);
        $groupId = $this->defineGroup($container, 'erpfq' . $suffix, [$siteA, $siteB]);
        $siteOwned = $this->createClientOwnedBy($container, $siteA);
        $groupOwned = $this->createClientOwnedBy($container, $siteA);
        $this->widenToGroup($container, $groupOwned, $groupId, $siteA);

        $counter = new BusinessQueryCounter();
        $counted = $this->countedConnection($this->connection($container), $counter);
        $tables = new TableNames($counted, $this->tables($container)->prefix());
        $registry = new DoctrineSiteGroupRegistry($counted, $tables);
        $gateway = AuthorizationContext::gateway(
            ownership: new DoctrineResourceSiteOwnership($counted, $tables, $registry),
        );
        $context = AuthorizationContext::siteScoped('ownership.scope.manage', $siteA);
        $action = Capability::fromString('ownership.scope.manage');

        try {
            $registry->all();
            $counter->reset();
            $siteDecision = $gateway->decide($context, $action, AuthorizationResource::item('client', $siteOwned));
            $siteQueries = $counter->queries();
            $counter->reset();
            $groupDecision = $gateway->decide($context, $action, AuthorizationResource::item('client', $groupOwned));
            $groupQueries = $counter->queries();
            $counter->reset();
            $repeat = $gateway->decide($context, $action, AuthorizationResource::item('client', $groupOwned));
            $repeatQueries = $counter->queries();
        } finally {
            $counted->close();
        }

        self::assertTrue($siteDecision->allowed);
        self::assertTrue($groupDecision->allowed);
        self::assertTrue($repeat->allowed);
        self::assertSame(1, $siteQueries, 'A site-owned decision must cost one ownership lookup.');
        self::assertSame(
            $siteQueries,
            $groupQueries,
            'A group-owned decision must not add a membership query to the hot path.',
        );
        self::assertSame($siteQueries, $repeatQueries, 'The budget must hold on every call, not the first.');
    }

    /**
     * Boot the migrated kernel the ownership model is resolved from.
     *
     * @return  Container  Fully wired container over the engine the suite is pointed at.
     *
     * @since   2.0.0
     */
    private function container(): Container
    {
        return $this->kernel ??= TestKernelFactory::create(Environment::fromGlobals());
    }

    /**
     * Resolve the shared authoritative connection.
     *
     * @param   Container  $container  Booted kernel container.
     *
     * @return  Connection  The connection the stored registry is read from and written to.
     *
     * @since   2.0.0
     */
    private function connection(Container $container): Connection
    {
        $connection = $container->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }

    /**
     * Resolve the prefixed physical table map.
     *
     * @param   Container  $container  Booted kernel container.
     *
     * @return  TableNames  Resolver applying the configured prefix.
     *
     * @since   2.0.0
     */
    private function tables(Container $container): TableNames
    {
        $tables = $container->get(TableNames::class);
        self::assertInstanceOf(TableNames::class, $tables);

        return $tables;
    }

    /**
     * Resolve the container's deny-by-default gateway.
     *
     * @param   Container  $container  Booted kernel container.
     *
     * @return  AuthorizationGateway  The gateway every visibility assertion decides through.
     *
     * @since   2.0.0
     */
    private function gateway(Container $container): AuthorizationGateway
    {
        $gateway = $container->get(AuthorizationGateway::class);
        self::assertInstanceOf(AuthorizationGateway::class, $gateway);

        return $gateway;
    }

    /**
     * Resolve the container's stored ownership resolver.
     *
     * @param   Container  $container  Booted kernel container.
     *
     * @return  ResourceSiteOwnership  Reader answering from `resource_site_ownership`.
     *
     * @since   2.0.0
     */
    private function ownership(Container $container): ResourceSiteOwnership
    {
        $ownership = $container->get(ResourceSiteOwnership::class);
        self::assertInstanceOf(ResourceSiteOwnership::class, $ownership);

        return $ownership;
    }

    /**
     * Resolve the audited scope-change service.
     *
     * @param   Container  $container  Booted kernel container.
     *
     * @return  ResourceOwnershipScopeService  The one write path for widening and narrowing.
     *
     * @since   2.0.0
     */
    private function scopeService(Container $container): ResourceOwnershipScopeService
    {
        $service = $container->get(ResourceOwnershipScopeService::class);
        self::assertInstanceOf(ResourceOwnershipScopeService::class, $service);

        return $service;
    }

    /**
     * A collision-resistant lowercase token naming this test's sites and groups.
     *
     * @return  string  Ten hexadecimal characters unique to one test run.
     *
     * @since   2.0.0
     */
    private function suffix(): string
    {
        return bin2hex(random_bytes(5));
    }

    /**
     * Create enabled site rows for one test scenario.
     *
     * @param   Container  $container  Booted kernel container.
     * @param   string     $suffix     Collision-resistant token shared by the scenario's sites.
     * @param   int        $count      How many sites the scenario needs.
     *
     * @return  list<string>  The new site identifiers in declaration order.
     *
     * @since   2.0.0
     */
    private function createSites(Container $container, string $suffix, int $count): array
    {
        $database = $this->connection($container);
        $tables = $this->tables($container);
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $sites = [];
        foreach (range(1, $count) as $index) {
            $site = 'erpf' . chr(96 + $index) . $suffix;
            $database->insert($tables->raw('sites'), [
                'identifier' => $site,
                'name' => 'Business ' . $site,
                'created_at' => $now,
                'enabled' => true,
                'policy_generation' => 1,
            ], [
                'created_at' => Types::DATETIME_IMMUTABLE,
                'enabled' => Types::BOOLEAN,
                'policy_generation' => Types::BIGINT,
            ]);
            $sites[] = $site;
            $this->recordedSites[] = $site;
        }

        return $sites;
    }

    /**
     * Declare a group through the audited administration service.
     *
     * @param   Container     $container   Booted kernel container.
     * @param   string        $identifier  Identifier the group is declared under.
     * @param   list<string>  $members     Complete membership the declaration ends with.
     *
     * @return  string  The declared group identifier.
     *
     * @since   2.0.0
     */
    private function defineGroup(Container $container, string $identifier, array $members): string
    {
        $administration = $container->get(SiteGroupAdministration::class);
        self::assertInstanceOf(SiteGroupAdministration::class, $administration);
        $administration->define(
            TestKernelFactory::administratorContext($container),
            $identifier,
            'Group ' . $identifier,
            $members,
        );
        $this->recordedGroups[] = $identifier;

        return $identifier;
    }

    /**
     * Resolve one declared group from the container's registry.
     *
     * @param   Container  $container   Booted kernel container.
     * @param   string     $identifier  Declared group identifier.
     *
     * @return  SiteGroup  The declaration with its enabled members.
     *
     * @since   2.0.0
     */
    private function group(Container $container, string $identifier): SiteGroup
    {
        $registry = $container->get(DoctrineSiteGroupRegistry::class);
        self::assertInstanceOf(DoctrineSiteGroupRegistry::class, $registry);

        return $registry->group($identifier);
    }

    /**
     * Record a new client resource born owned by one site, the way every resource is born.
     *
     * @param   Container  $container  Booted kernel container.
     * @param   string     $site       Site that owns the client until an operator widens the scope.
     *
     * @return  string  The client resource identifier.
     *
     * @since   2.0.0
     */
    private function createClientOwnedBy(Container $container, string $site): string
    {
        $writer = $container->get(ResourceSiteOwnershipWriter::class);
        self::assertInstanceOf(ResourceSiteOwnershipWriter::class, $writer);
        $client = Uuid::uuid7()->toString();
        $writer->record(AuthorizationResource::item('client', $client), SiteContext::fromString($site));
        $this->recordedOwnership[] = ['client', $client];

        return $client;
    }

    /**
     * Widen one client from its owning site to a declared group through the audited service.
     *
     * @param   Container  $container  Booted kernel container.
     * @param   string     $client     Client resource identifier being widened.
     * @param   string     $groupId    Declared group the client moves to.
     * @param   string     $site       Owning site the change is authorized and issued from.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function widenToGroup(Container $container, string $client, string $groupId, string $site): void
    {
        $widened = $this->scopeService($container)->widen(
            $this->scopedContext($container, $site),
            AuthorizationResource::item('client', $client),
            OwnershipScope::group($this->group($container, $groupId)),
        );
        self::assertSame(OwnershipScopeLevel::Group, $widened->level);
    }

    /**
     * Build a caller holding the scope-management capability at exactly one site.
     *
     * @param   Container  $container  Booted kernel container.
     * @param   string     $site       Site the caller executes in and is granted at.
     *
     * @return  ExecutionContext  Provenance-bound context for that site.
     *
     * @since   2.0.0
     */
    private function scopedContext(Container $container, string $site): ExecutionContext
    {
        return TestKernelFactory::contextFromGrantRows($container, [[
            'capability' => 'ownership.scope.manage',
            'scope_type' => 'site',
            'scope_identifier' => $site,
        ]], $site);
    }

    /**
     * Flip one site's enabled flag and drop the memoized group declarations that cached it.
     *
     * @param   Container  $container  Booted kernel container.
     * @param   string     $site       Site being disabled or re-enabled.
     * @param   bool       $enabled    New enabled state.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function setSiteEnabled(Container $container, string $site, bool $enabled): void
    {
        $this->connection($container)->update(
            $this->tables($container)->raw('sites'),
            ['enabled' => $enabled],
            ['identifier' => $site],
            ['enabled' => Types::BOOLEAN],
        );
        $registry = $container->get(DoctrineSiteGroupRegistry::class);
        self::assertInstanceOf(DoctrineSiteGroupRegistry::class, $registry);
        $registry->forget();
    }

    /**
     * Read the single audit entry one scope change wrote for one resource.
     *
     * @param   Container  $container  Booted kernel container.
     * @param   string     $action     Audit action naming the direction.
     * @param   string     $subjectId  Resource identifier the entry describes.
     *
     * @return  array<string, mixed>  The decoded audit metadata.
     *
     * @since   2.0.0
     */
    private function auditEntry(Container $container, string $action, string $subjectId): array
    {
        $database = $this->connection($container);
        $tables = $this->tables($container);
        $rows = $database->fetchAllAssociative(sprintf(
            'SELECT metadata FROM %s WHERE action = ? AND subject_type = ? AND subject_id = ?',
            $tables->quoted('audit_events'),
        ), [$action, 'client', $subjectId]);
        self::assertCount(1, $rows, sprintf('Exactly one %s entry must be recorded.', $action));
        $metadata = json_decode((string) $rows[0]['metadata'], true, 8, JSON_THROW_ON_ERROR);
        self::assertIsArray($metadata);

        return $metadata;
    }

    /**
     * Open an independent connection whose executed statements are counted.
     *
     * @param   Connection            $source   Connection whose parameters the counted session copies.
     * @param   BusinessQueryCounter  $counter  Driver query counter receiving the middleware's log lines.
     *
     * @return  Connection  Session used to measure the authorization hot path's physical query budget.
     *
     * @since   2.0.0
     */
    private function countedConnection(Connection $source, BusinessQueryCounter $counter): Connection
    {
        $configuration = new Configuration();
        $configuration->setMiddlewares([new Middleware($counter)]);
        $connection = DriverManager::getConnection($source->getParams(), $configuration);
        $platform = $connection->getDatabasePlatform();
        if ($platform instanceof AbstractMySQLPlatform) {
            $connection->executeStatement("SET time_zone = '+00:00'");
        } elseif ($platform instanceof PostgreSQLPlatform) {
            $connection->executeStatement("SET TIME ZONE 'UTC'");
        }
        $counter->reset();

        return $connection;
    }
}
