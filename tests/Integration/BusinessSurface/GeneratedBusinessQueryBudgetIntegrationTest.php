<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\BusinessSurface;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Logging\Middleware;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\Types;
use Kumwe\App\Kernel\Container;
use Kumwe\App\Application\Authorization\AuthorizationGateway;
use Kumwe\Context\Value\AuthenticationStrength;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Context\Value\SiteContext;
use Kumwe\App\Application\Persistence\TransactionManager;
use Kumwe\App\BusinessDefinition\Application\BusinessDefinitionRepository;
use Kumwe\App\BusinessDefinition\Application\FieldTypeRegistry;
use Kumwe\App\BusinessDefinition\Infrastructure\Persistence\DoctrineBusinessDefinitionRepository;
use Kumwe\App\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\App\BusinessRecord\Application\BusinessRecordDefinitionResolver;
use Kumwe\App\BusinessRecord\Application\BusinessRecordMutationFence;
use Kumwe\App\BusinessRecord\Application\BusinessRecordService;
use Kumwe\App\BusinessRecord\Application\Command\CreateRecordCommand;
use Kumwe\App\BusinessRecord\Application\Command\RelateRecordsCommand;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordDefinitionUnavailable;
use Kumwe\App\BusinessRecord\Application\InstalledBusinessRecordDefinitionResolver;
use Kumwe\App\BusinessRecord\Application\RecordBrowseResult;
use Kumwe\App\BusinessRecord\Application\RecordCursorCodec;
use Kumwe\App\BusinessRecord\Application\RecordRuleValidator;
use Kumwe\App\BusinessRecord\Application\RecordValueCodec;
use Kumwe\App\BusinessRecord\Application\ResolvedBusinessDefinition;
use Kumwe\App\BusinessRecord\Domain\RecordScope;
use Kumwe\App\BusinessRecord\Infrastructure\Persistence\DoctrineBusinessRecordQueryCompiler;
use Kumwe\App\BusinessRecord\Infrastructure\Persistence\DoctrineBusinessRecordReadRepository;
use Kumwe\Extension\Spi\BusinessRecord\Query\RecordProjection;
use Kumwe\Extension\Spi\BusinessRecord\Query\RecordQuerySpecification;
use Kumwe\App\BusinessSchema\Application\BusinessSchemaInstallationRepository;
use Kumwe\App\BusinessSchema\Infrastructure\Persistence\DoctrineBusinessSchemaInstallationRepository;
use Kumwe\App\BusinessSecurity\Application\BusinessRecordAccessController;
use Kumwe\App\BusinessSecurity\Application\MembershipDirectory;
use Kumwe\App\BusinessSecurity\Infrastructure\Persistence\DoctrineBusinessRecordAccessController;
use Kumwe\App\BusinessSurface\Application\BusinessSurface;
use Kumwe\App\BusinessSurface\Application\BusinessSurfaceCatalog;
use Kumwe\App\BusinessSurface\Application\BusinessSurfaceOperation;
use Kumwe\App\Extension\Runtime\RuntimeMaterializationState;
use Kumwe\App\Infrastructure\Persistence\DoctrineTransactionManager;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Tests\Support\BusinessQueryCounter;
use Kumwe\App\Tests\Support\NeutralBusinessFixture;
use Kumwe\App\Tests\Support\TestKernelFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;

#[CoversClass(InstalledBusinessRecordDefinitionResolver::class)]
#[CoversClass(BusinessSurfaceCatalog::class)]
#[CoversClass(DoctrineBusinessRecordAccessController::class)]
#[CoversClass(DoctrineBusinessRecordReadRepository::class)]
/**
 * Proves generated discovery and relationship hydration keep constant database-query budgets.
 *
 * @since  2.0.0
 */
final class GeneratedBusinessQueryBudgetIntegrationTest extends TestCase
{
    /**
     * Compare active-definition discovery after one and twelve definitions are installed on an isolated site.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testActiveInstalledDefinitionQueryBudgetDoesNotGrowBelowBatchLimit(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $administrator = TestKernelFactory::administratorContext($container);
        $principal = $administrator->principal();
        self::assertNotNull($principal);
        $suffix = strtolower(substr(str_replace('-', '', Uuid::uuid7()->toString()), -10));
        $site = 'querybudget' . $suffix;
        $this->createSite($container, $site);
        $context = $principal->context(
            SiteContext::fromString($site),
            AuthenticationStrength::Password,
            'integration-query-budget-' . $suffix,
        );

        $this->installDefinition($container, $context, $site, $suffix, 1);
        $counter = new BusinessQueryCounter();
        $countedConnection = $this->countedConnection(
            $this->service($container, Connection::class),
            $counter,
        );
        try {
            $resolver = $this->definitionResolver($container, $countedConnection);
            [$small, $smallQueries] = $this->activeInstalled($resolver, $context, $counter);
            for ($index = 2; $index <= 12; ++$index) {
                $this->installDefinition($container, $context, $site, $suffix, $index);
            }
            [$large, $largeQueries] = $this->activeInstalled($resolver, $context, $counter);
        } finally {
            $countedConnection->close();
        }

        self::assertCount(1, $small);
        self::assertCount(12, $large);
        self::assertSame(3, $smallQueries, 'Discovery should use catalog, installation, and version queries.');
        self::assertSame(
            $smallQueries,
            $largeQueries,
            'Discovery below the published-version batch limit must not issue one query per definition.',
        );
    }

    /**
     * Compare full catalog discovery and targeted metadata after one and twelve definitions exist.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testGeneratedCatalogPolicyQueryBudgetDoesNotGrowWithDefinitionCount(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $administrator = TestKernelFactory::administratorContext($container);
        $principal = $administrator->principal();
        self::assertNotNull($principal);
        $suffix = strtolower(substr(str_replace('-', '', Uuid::uuid7()->toString()), -10));
        $site = 'catalogbudget' . $suffix;
        $this->createSite($container, $site);
        $context = $principal->context(
            SiteContext::fromString($site),
            AuthenticationStrength::Password,
            'integration-catalog-budget-' . $suffix,
        );
        $first = $this->installDefinition($container, $context, $site, $suffix, 1);
        $counter = new BusinessQueryCounter();
        $countedConnection = $this->countedConnection(
            $this->service($container, Connection::class),
            $counter,
        );
        try {
            $resolver = $this->definitionResolver($container, $countedConnection);
            $catalog = $this->catalog($container, $countedConnection, $resolver);
            $counter->reset();
            $small = $catalog->definitions(
                $context,
                BusinessSurface::Administrator,
                BusinessSurfaceOperation::Browse,
            );
            $smallQueries = $counter->queries();
            $counter->reset();
            $targeted = $catalog->definition(
                $context,
                BusinessSurface::Administrator,
                $first->handle,
                BusinessSurfaceOperation::Browse,
            );
            $smallTargetedQueries = $counter->queries();
            for ($index = 2; $index <= 12; ++$index) {
                $this->installDefinition($container, $context, $site, $suffix, $index);
            }
            $counter->reset();
            $large = $catalog->definitions(
                $context,
                BusinessSurface::Administrator,
                BusinessSurfaceOperation::Browse,
            );
            $largeQueries = $counter->queries();
            $counter->reset();
            $largeTargeted = $catalog->definition(
                $context,
                BusinessSurface::Administrator,
                $first->handle,
                BusinessSurfaceOperation::Browse,
            );
            $largeTargetedQueries = $counter->queries();
        } finally {
            $countedConnection->close();
        }

        self::assertCount(1, $small);
        self::assertCount(12, $large);
        self::assertSame($first->handle, $targeted['handle']);
        self::assertSame($targeted, $largeTargeted);
        self::assertSame(
            $smallQueries,
            $largeQueries,
            'Catalog discovery must batch its policy lock and policy rows across definitions.',
        );
        self::assertSame(
            $smallTargetedQueries,
            $largeTargetedQueries,
            'Targeted metadata must not plan every active definition on the site.',
        );
    }

    /**
     * Prove a complete screen-operation map uses one definition, membership, and policy-row snapshot.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testGeneratedOperationMapUsesOneBoundedPolicySnapshot(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $administrator = TestKernelFactory::administratorContext($container);
        $principal = $administrator->principal();
        self::assertNotNull($principal);
        $suffix = strtolower(substr(str_replace('-', '', Uuid::uuid7()->toString()), -10));
        $site = 'operationbudget' . $suffix;
        $this->createSite($container, $site);
        $context = $principal->context(
            SiteContext::fromString($site),
            AuthenticationStrength::Password,
            'integration-operation-budget-' . $suffix,
        );
        $first = $this->installDefinition($container, $context, $site, $suffix, 1);
        $counter = new BusinessQueryCounter();
        $countedConnection = $this->countedConnection(
            $this->service($container, Connection::class),
            $counter,
        );
        try {
            $resolver = $this->definitionResolver($container, $countedConnection);
            $catalog = $this->catalog($container, $countedConnection, $resolver);
            $counter->reset();
            $small = $catalog->operations($context, BusinessSurface::Administrator, $first->handle);
            $smallQueries = $counter->queries();
            $expected = [];
            foreach (BusinessSurfaceOperation::cases() as $operation) {
                if ($operation === BusinessSurfaceOperation::Discover) {
                    continue;
                }
                try {
                    $catalog->definition($context, BusinessSurface::Administrator, $first->handle, $operation);
                    $expected[$operation->value] = true;
                } catch (BusinessRecordDefinitionUnavailable) {
                    // Omission is the expected representation of a filtered operation.
                }
            }
            for ($index = 2; $index <= 12; ++$index) {
                $this->installDefinition($container, $context, $site, $suffix, $index);
            }
            $counter->reset();
            $large = $catalog->operations($context, BusinessSurface::Administrator, $first->handle);
            $largeQueries = $counter->queries();
        } finally {
            $countedConnection->close();
        }

        self::assertArrayHasKey('browse', $small);
        self::assertSame($expected, $small);
        self::assertSame($small, $large);
        self::assertLessThanOrEqual(
            7,
            $smallQueries,
            'A complete operation map must batch installation, membership, lock, and policy-row reads.',
        );
        self::assertSame(
            $smallQueries,
            $largeQueries,
            'A screen operation map must not issue one query per active definition.',
        );
    }

    /**
     * Compare screen metadata and operation gating with no traversals and five relationship declarations.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTargetedCatalogPolicyQueryBudgetDoesNotGrowWithRelationshipWidth(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $administrator = TestKernelFactory::administratorContext($container);
        $principal = $administrator->principal();
        self::assertNotNull($principal);
        $suffix = strtolower(substr(str_replace('-', '', Uuid::uuid7()->toString()), -10));
        $site = 'targetbudget' . $suffix;
        $this->createSite($container, $site);
        $context = $principal->context(
            SiteContext::fromString($site),
            AuthenticationStrength::Password,
            'integration-target-budget-' . $suffix,
        );
        $plain = $this->installDefinition($container, $context, $site, $suffix, 1);
        $target = $this->installSiteDocument(
            $container,
            $context,
            $site,
            NeutralBusinessFixture::relationTargetDocument($suffix, Uuid::uuid7()->toString()),
            'relation_target',
        );
        $line = $this->installSiteDocument(
            $container,
            $context,
            $site,
            NeutralBusinessFixture::ownedLineDocument($suffix, Uuid::uuid7()->toString()),
            'owned_line',
        );
        $owner = $this->installSiteDocument(
            $container,
            $context,
            $site,
            NeutralBusinessFixture::relationshipOwnerDocument(
                $suffix,
                Uuid::uuid7()->toString(),
                $target->handle,
                $line->handle,
            ),
            'relation_owner',
        );
        $counter = new BusinessQueryCounter();
        $countedConnection = $this->countedConnection(
            $this->service($container, Connection::class),
            $counter,
        );
        try {
            $resolver = $this->definitionResolver($container, $countedConnection);
            $catalog = $this->catalog($container, $countedConnection, $resolver);
            $counter->reset();
            $plainMetadata = $catalog->definition(
                $context,
                BusinessSurface::Administrator,
                $plain->handle,
                BusinessSurfaceOperation::Browse,
            );
            $plainQueries = $counter->queries();
            $counter->reset();
            $ownerMetadata = $catalog->definition(
                $context,
                BusinessSurface::Administrator,
                $owner->handle,
                BusinessSurfaceOperation::Browse,
            );
            $ownerQueries = $counter->queries();
            $counter->reset();
            $plainOperations = $catalog->operations(
                $context,
                BusinessSurface::Administrator,
                $plain->handle,
            );
            $plainOperationQueries = $counter->queries();
            $counter->reset();
            $ownerOperations = $catalog->operations(
                $context,
                BusinessSurface::Administrator,
                $owner->handle,
            );
            $ownerOperationQueries = $counter->queries();
        } finally {
            $countedConnection->close();
        }

        self::assertSame($plain->handle, $plainMetadata['handle']);
        self::assertCount(5, $ownerMetadata['relationships']);
        self::assertSame(
            $plainQueries,
            $ownerQueries,
            'Targeted metadata must batch policy plans for every related target in the active snapshot.',
        );
        self::assertArrayHasKey('read', $plainOperations);
        self::assertArrayHasKey('read', $ownerOperations);
        self::assertSame(
            $plainOperationQueries,
            $ownerOperationQueries,
            'Screen operation gating must not issue one query per relationship declaration.',
        );
    }

    /**
     * Compare a one-row page with a twelve-row page through the real DBAL read repository.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRelationshipIncludeQueryBudgetDoesNotGrowWithPageSize(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $context = TestKernelFactory::administratorContext($container);
        $suffix = strtolower(substr(str_replace('-', '', Uuid::uuid7()->toString()), -10));
        $target = NeutralBusinessFixture::install(
            $container,
            $context,
            NeutralBusinessFixture::relationTargetDocument($suffix, Uuid::uuid7()->toString()),
        );
        $line = NeutralBusinessFixture::install(
            $container,
            $context,
            NeutralBusinessFixture::ownedLineDocument($suffix, Uuid::uuid7()->toString()),
        );
        $owner = NeutralBusinessFixture::install(
            $container,
            $context,
            NeutralBusinessFixture::relationshipOwnerDocument(
                $suffix,
                Uuid::uuid7()->toString(),
                $target->handle,
                $line->handle,
            ),
        );
        $records = $this->service($container, BusinessRecordService::class);
        for ($index = 1; $index <= 12; ++$index) {
            $targetId = Uuid::uuid7()->toString();
            $ownerId = Uuid::uuid7()->toString();
            $records->create(new CreateRecordCommand(
                $context,
                $target->handle,
                ['label' => 'Query budget target ' . $index . ' ' . $suffix],
                NeutralBusinessFixture::idempotencyKey('budget-target-' . $index . '-' . $suffix),
                recordId: $targetId,
            ));
            $records->create(new CreateRecordCommand(
                $context,
                $owner->handle,
                ['title' => 'Query budget owner ' . $index],
                NeutralBusinessFixture::idempotencyKey('budget-owner-' . $index . '-' . $suffix),
                recordId: $ownerId,
            ));
            $records->relate(new RelateRecordsCommand(
                $context,
                $owner->handle,
                $ownerId,
                1,
                'tags',
                $targetId,
                NeutralBusinessFixture::idempotencyKey('budget-relate-' . $index . '-' . $suffix),
            ));
        }

        $counter = new BusinessQueryCounter();
        $countedConnection = $this->countedConnection(
            $this->service($container, Connection::class),
            $counter,
        );
        try {
            $reader = $this->reader($container, $countedConnection);
            [$small, $smallQueries] = $this->browse(
                $container,
                $context,
                $reader,
                $counter,
                $owner->handle,
                1,
            );
            [$large, $largeQueries] = $this->browse(
                $container,
                $context,
                $reader,
                $counter,
                $owner->handle,
                12,
            );
        } finally {
            $countedConnection->close();
        }

        self::assertCount(1, $small->records);
        self::assertCount(12, $large->records);
        self::assertSame(2, $smallQueries, 'A page with one include should use one page and one include query.');
        self::assertSame(
            $smallQueries,
            $largeQueries,
            'Relationship hydration must not issue one query per row.',
        );
        foreach ($large->records as $record) {
            self::assertCount(1, $record->includes['tags']);
        }
    }

    /**
     * Open an independently instrumented connection to the integration database.
     *
     * @param   Connection            $source   Production-composed connection supplying exact parameters.
     * @param   BusinessQueryCounter  $counter  Driver-middleware query counter.
     *
     * @return  Connection  Real connection whose executed statements are counted.
     *
     * @since   2.0.0
     */
    private function countedConnection(Connection $source, BusinessQueryCounter $counter): Connection
    {
        $configuration = new Configuration();
        $configuration->setMiddlewares([new Middleware($counter)]);
        $connection = DriverManager::getConnection($source->getParams(), $configuration);
        $platform = $connection->getDatabasePlatform();
        $connection->executeStatement(match (true) {
            $platform instanceof PostgreSQLPlatform => "SET TIME ZONE 'UTC'",
            $platform instanceof AbstractMySQLPlatform => "SET time_zone = '+00:00'",
            default => self::fail('The integration database platform is unsupported.'),
        });
        $counter->reset();

        return $connection;
    }

    /**
     * Construct the production active-definition resolver with both repositories on the counted connection.
     *
     * @param   Container   $container   Real composition root supplying the physical table-name resolver.
     * @param   Connection  $connection  Instrumented DBAL connection.
     *
     * @return  InstalledBusinessRecordDefinitionResolver  Resolver using the real batched repositories.
     *
     * @since   2.0.0
     */
    private function definitionResolver(
        Container $container,
        Connection $connection,
    ): InstalledBusinessRecordDefinitionResolver {
        $tables = $this->service($container, TableNames::class);

        return new InstalledBusinessRecordDefinitionResolver(
            new DoctrineBusinessDefinitionRepository($connection, $tables),
            new DoctrineBusinessSchemaInstallationRepository($connection, $tables),
        );
    }

    /**
     * Construct the production generated catalog with policy and definition reads instrumented.
     *
     * @param   Container                                  $container   Real application container.
     * @param   Connection                                 $connection  Instrumented DBAL connection.
     * @param   InstalledBusinessRecordDefinitionResolver  $resolver    Batched active resolver.
     *
     * @return  BusinessSurfaceCatalog  Catalog whose SQL budget is observable through the counter.
     *
     * @since   2.0.0
     */
    private function catalog(
        Container $container,
        Connection $connection,
        InstalledBusinessRecordDefinitionResolver $resolver,
    ): BusinessSurfaceCatalog {
        $access = new DoctrineBusinessRecordAccessController(
            $connection,
            $this->service($container, TableNames::class),
            $resolver,
            $this->service($container, MembershipDirectory::class),
            $this->service($container, ClockInterface::class),
        );

        return new BusinessSurfaceCatalog(
            $resolver,
            $access,
            $this->service($container, FieldTypeRegistry::class),
            $this->service($container, AuthorizationGateway::class),
            new DoctrineTransactionManager($connection),
            $this->service($container, RuntimeMaterializationState::class),
        );
    }

    /**
     * Measure one complete active-definition discovery call.
     *
     * @param   InstalledBusinessRecordDefinitionResolver  $resolver  Real batched definition resolver.
     * @param   ExecutionContext                           $context   Isolated integration-site context.
     * @param   BusinessQueryCounter                       $counter   Driver query counter to reset and read.
     *
     * @return  array{list<ResolvedBusinessDefinition>, int}  Resolved definitions and physical query count.
     *
     * @since   2.0.0
     */
    private function activeInstalled(
        InstalledBusinessRecordDefinitionResolver $resolver,
        ExecutionContext $context,
        BusinessQueryCounter $counter,
    ): array {
        $counter->reset();
        $resolved = $resolver->activeInstalled($context);

        return [$resolved, $counter->queries()];
    }

    /**
     * Create the stable site authority row used by authorization and publication namespace locking.
     *
     * @param   Container  $container  Real application container supplying database and physical names.
     * @param   string     $site       Unique bounded site identifier for this query-budget scenario.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function createSite(Container $container, string $site): void
    {
        $database = $this->service($container, Connection::class);
        $tables = $this->service($container, TableNames::class);
        $database->insert($tables->raw('sites'), [
            'identifier' => $site,
            'name' => 'Query budget ' . $site,
            'created_at' => new DateTimeImmutable('now', new DateTimeZone('UTC')),
            'enabled' => true,
            'policy_generation' => 1,
        ], [
            'created_at' => Types::DATETIME_IMMUTABLE,
            'enabled' => Types::BOOLEAN,
            'policy_generation' => Types::BIGINT,
        ]);
    }

    /**
     * Publish and install one definition owned by the isolated query-budget site.
     *
     * @param   Container         $container  Real application container.
     * @param   ExecutionContext  $context    Isolated integration-site context.
     * @param   string            $site       Site identifier and owner namespace.
     * @param   string            $suffix     Per-run collision-resistant suffix.
     * @param   int               $index      One-based definition number.
     *
     * @return  EntityTypeDefinition  Installed active definition.
     *
     * @since   2.0.0
     */
    private function installDefinition(
        Container $container,
        ExecutionContext $context,
        string $site,
        string $suffix,
        int $index,
    ): EntityTypeDefinition {
        $document = NeutralBusinessFixture::document(
            'q' . $index . $suffix,
            Uuid::uuid7()->toString(),
        );
        $document['site'] = $site;
        $document['owner'] = ['type' => 'site', 'identifier' => $site];
        $document['handle'] = 'site.' . $site . '.query_budget_' . $index;
        return NeutralBusinessFixture::install($container, $context, $document);
    }

    /**
     * Re-home a graph fixture document onto one isolated query-budget site before installation.
     *
     * @param   Container             $container  Real application container.
     * @param   ExecutionContext      $context    Isolated integration-site context.
     * @param   string                $site       Site identifier and owner namespace.
     * @param   array<string, mixed>  $document   Draft fixture document to re-home.
     * @param   string                $handle     Short unique handle suffix.
     *
     * @return  EntityTypeDefinition  Installed active definition.
     *
     * @since   2.0.0
     */
    private function installSiteDocument(
        Container $container,
        ExecutionContext $context,
        string $site,
        array $document,
        string $handle,
    ): EntityTypeDefinition {
        $document['site'] = $site;
        $document['owner'] = ['type' => 'site', 'identifier' => $site];
        $document['handle'] = 'site.' . $site . '.' . $handle;

        return NeutralBusinessFixture::install($container, $context, $document);
    }

    /**
     * Construct the production DBAL reader with only its connection instrumented.
     *
     * @param   Container   $container   Real composition root supplying reader dependencies.
     * @param   Connection  $connection  Instrumented DBAL connection.
     *
     * @return  DoctrineBusinessRecordReadRepository  Real physical-table reader.
     *
     * @since   2.0.0
     */
    private function reader(
        Container $container,
        Connection $connection,
    ): DoctrineBusinessRecordReadRepository {
        $definitions = $this->service($container, BusinessDefinitionRepository::class);
        $installations = $this->service($container, BusinessSchemaInstallationRepository::class);
        $values = $this->service($container, RecordValueCodec::class);
        $cursors = $this->service($container, RecordCursorCodec::class);
        $fence = $this->service($container, BusinessRecordMutationFence::class);
        $queries = new DoctrineBusinessRecordQueryCompiler(
            $connection,
            $definitions,
            $installations,
            $values,
            $cursors,
            $fence,
        );

        return new DoctrineBusinessRecordReadRepository(
            $connection,
            $values,
            $this->service($container, RecordRuleValidator::class),
            $queries,
            $cursors,
            $definitions,
            $installations,
            $fence,
        );
    }

    /**
     * Run one counted browse under the same transaction, schema fence and access plan as production.
     *
     * @param   Container                             $container   Real application container.
     * @param   ExecutionContext                      $context     Authenticated integration administrator.
     * @param   DoctrineBusinessRecordReadRepository  $reader      Instrumented physical reader.
     * @param   BusinessQueryCounter                  $counter     Driver query counter to reset and read.
     * @param   string                                $definition  Installed owner handle.
     * @param   int                                   $pageSize    Number of source rows requested.
     *
     * @return  array{RecordBrowseResult, int}  Browse result and exact DBAL statement count.
     *
     * @since   2.0.0
     */
    private function browse(
        Container $container,
        ExecutionContext $context,
        DoctrineBusinessRecordReadRepository $reader,
        BusinessQueryCounter $counter,
        string $definition,
        int $pageSize,
    ): array {
        $transactions = $this->service($container, TransactionManager::class);
        $resolver = $this->service($container, BusinessRecordDefinitionResolver::class);
        $fence = $this->service($container, BusinessRecordMutationFence::class);
        $access = $this->service($container, BusinessRecordAccessController::class);
        $counter->reset();
        $result = $transactions->transactional(function () use (
            $context,
            $reader,
            $resolver,
            $fence,
            $access,
            $definition,
            $pageSize,
        ): RecordBrowseResult {
            $generation = $fence->shared($context->site(), $definition);
            $resolved = $resolver->forCreate($context, $definition);
            $generation->assertMatches($resolved);
            $scope = RecordScope::forDefinition($resolved->definition->scope, $context->site(), null);
            $plan = $access->plan($context, 'business.record.browse', $resolved, $scope);

            return $reader->browse(
                $resolved,
                $scope,
                new RecordQuerySpecification(
                    pageSize: $pageSize,
                    projection: new RecordProjection(['title'], ['tags']),
                ),
                $plan,
            );
        });

        return [$result, $counter->queries()];
    }

    /**
     * Resolve one strongly typed service from the real container.
     *
     * @template T of object
     *
     * @param   Container        $container  Real application container.
     * @param   class-string<T>  $class      Requested service type.
     *
     * @return  T  Requested service.
     *
     * @since   2.0.0
     */
    private function service(Container $container, string $class): object
    {
        $service = $container->get($class);
        self::assertInstanceOf($class, $service);

        return $service;
    }
}
