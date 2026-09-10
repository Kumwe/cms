<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\BusinessRecord;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Kumwe\App\Kernel\Container;
use Kumwe\App\Application\Authorization\AuthorizationResource;
use Kumwe\App\Application\Authorization\OwnershipScope;
use Kumwe\App\Application\Authorization\OwnershipScopeNotPermitted;
use Kumwe\App\Application\Authorization\OwnershipScopeRule;
use Kumwe\App\Application\Authorization\ResourceOwnership;
use Kumwe\App\Application\Authorization\ResourceOwnershipScopePolicy;
use Kumwe\Extension\Spi\Application\Automation\IdempotencyKey;
use Kumwe\App\Application\Persistence\TransactionManager;
use Kumwe\App\BusinessDefinition\Application\BusinessDefinitionCompatibilityAnalyzer;
use Kumwe\App\BusinessDefinition\Application\BusinessDefinitionRepository;
use Kumwe\App\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\App\BusinessDefinition\Domain\InvalidBusinessDefinition;
use Kumwe\App\BusinessDefinition\Infrastructure\Persistence\DoctrineBusinessDefinitionRepository;
use Kumwe\Sequence\Contract\NumberSequenceAllocator;
use Kumwe\App\BusinessRecord\Application\BusinessRecordService;
use Kumwe\App\BusinessRecord\Application\Command\CreateRecordCommand;
use Kumwe\App\BusinessRecord\Application\Query\ReadRecordQuery;
use Kumwe\App\BusinessRecord\Infrastructure\Persistence\DoctrineBusinessNumberSequenceAllocator;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Tests\Support\NeutralBusinessFixture;
use Kumwe\App\Tests\Support\TestKernelFactory;
use Pdo\Pgsql;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use RuntimeException;

/**
 * Pins where document type and legal entity live in the counter identity, on the unchanged allocator.
 *
 * The `V2-ERP-002` widening resolves to an already-present identity: a counter is the five-coordinate
 * tuple of site, definition, field handle, scope key and period key, so the document type (definition and
 * field) and the legal entity (site, with the organization branch in the scope key) partition counters
 * without any change to how the counter advances. This suite proves that partition mechanically — every
 * coordinate isolates its own contiguous run while the neighbouring runs stand still — first at the
 * allocator seam and then through the real create command, where two allocated-number fields on one
 * definition draw from runs exclusively their own.
 *
 * @since  2.0.0
 */
#[CoversClass(DoctrineBusinessNumberSequenceAllocator::class)]
#[CoversClass(DoctrineBusinessDefinitionRepository::class)]
#[CoversClass(BusinessDefinitionCompatibilityAnalyzer::class)]
final class BusinessNumberSequenceIdentityIntegrationTest extends TestCase
{
    /**
     * Every identity coordinate isolates its own contiguous run on the one shared counter table.
     *
     * The first run is advanced twice, each neighbouring coordinate — another document type, another
     * field handle, another legal entity's site, two organization branches, another period — starts its
     * own run at one, and the first run then continues at three: nothing any neighbour allocated
     * interleaved with it.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testEachIdentityCoordinateIsolatesItsOwnContiguousRun(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $allocator = $this->allocator($container);
        $database = $this->connection($container);
        $invoice = Uuid::uuid7()->toString();
        $credit = Uuid::uuid7()->toString();
        $now = new DateTimeImmutable('2026-08-18T09:00:00', new DateTimeZone('UTC'));

        $database->beginTransaction();
        try {
            self::assertSame(1, $allocator->allocate('default', $invoice, 'document_number', '-', '2026', $now));
            self::assertSame(2, $allocator->allocate('default', $invoice, 'document_number', '-', '2026', $now));
            self::assertSame(
                1,
                $allocator->allocate('default', $credit, 'document_number', '-', '2026', $now),
                'Another definition is another document type and draws from its own counter.',
            );
            self::assertSame(
                1,
                $allocator->allocate('default', $invoice, 'voucher_number', '-', '2026', $now),
                'Another allocated-number field of the same definition keeps its own run.',
            );
            self::assertSame(
                1,
                $allocator->allocate('entity-b', $invoice, 'document_number', '-', '2026', $now),
                'Another site is another legal entity and never interleaves with the first.',
            );
            self::assertSame(
                1,
                $allocator->allocate('default', $invoice, 'document_number', 'north-branch', '2026', $now),
                'A per-organization scope key gives each branch its own run.',
            );
            self::assertSame(
                1,
                $allocator->allocate('default', $invoice, 'document_number', 'south-branch', '2026', $now),
            );
            self::assertSame(
                1,
                $allocator->allocate('default', $invoice, 'document_number', '-', '2027', $now),
                'A new period starts a new run without touching the old one.',
            );
            self::assertSame(
                3,
                $allocator->allocate('default', $invoice, 'document_number', '-', '2026', $now),
                'The original run continues contiguously; no neighbouring counter consumed from it.',
            );
            $database->commit();
        } catch (\Throwable $failure) {
            $database->rollBack();
            throw $failure;
        }

        self::assertSame(1, $this->stored($container, 'default', $invoice, 'document_number', '-', '2027'));
        self::assertSame(3, $this->stored($container, 'default', $invoice, 'document_number', '-', '2026'));
        self::assertSame(1, $this->stored($container, 'entity-b', $invoice, 'document_number', '-', '2026'));
        self::assertSame(1, $this->stored($container, 'default', $credit, 'document_number', '-', '2026'));
        self::assertSame(
            1,
            $this->stored($container, 'default', $invoice, 'document_number', 'north-branch', '2026'),
        );
    }

    /**
     * PostgreSQL's one-shot statement policy keeps an identical locking read current on every execution.
     *
     * This is the minimal PDO-only form of the allocator choreography that exposed a stale empty result
     * with native prepared statements under PHP 8.5. The production connection deliberately asks
     * pdo_pgsql to send each one-shot query with its parameters in a single call; nine executions of the
     * same five-coordinate statement must therefore observe every update without an unrelated statement
     * being interleaved as a cache-breaking workaround.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testPostgreSqlOneShotStatementsDoNotReturnAStaleCounter(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $database = $this->connection($container);
        if (!$database->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            self::markTestSkipped('The pdo_pgsql statement-policy reproduction applies only to PostgreSQL.');
        }
        $driverOptions = $database->getParams()['driverOptions'] ?? null;
        self::assertIsArray($driverOptions);
        self::assertTrue($driverOptions[Pgsql::ATTR_DISABLE_PREPARES] ?? false);
        $pdo = $database->getNativeConnection();
        self::assertInstanceOf(Pgsql::class, $pdo);

        $table = 'kumwe_pdo_sequence_reproduction';
        $pdo->beginTransaction();
        try {
            $pdo->exec(sprintf(
                'CREATE TEMPORARY TABLE %s ('
                . 'site_identifier TEXT NOT NULL, definition_id TEXT NOT NULL, field_handle TEXT NOT NULL, '
                . 'scope_key TEXT NOT NULL, period_key TEXT NOT NULL, current_value BIGINT NOT NULL, '
                . 'PRIMARY KEY (site_identifier, definition_id, field_handle, scope_key, period_key)'
                . ') ON COMMIT DROP',
                $table,
            ));
            $select = $pdo->prepare(sprintf(
                'SELECT current_value FROM %s WHERE site_identifier = ? AND definition_id = ? '
                . 'AND field_handle = ? AND scope_key = ? AND period_key = ? FOR UPDATE',
                $table,
            ));
            $insert = $pdo->prepare(sprintf(
                'INSERT INTO %s '
                . '(site_identifier, definition_id, field_handle, scope_key, period_key, current_value) '
                . 'VALUES (?, ?, ?, ?, ?, 0)',
                $table,
            ));
            $update = $pdo->prepare(sprintf(
                'UPDATE %s SET current_value = ? WHERE site_identifier = ? AND definition_id = ? '
                . 'AND field_handle = ? AND scope_key = ? AND period_key = ? AND current_value = ?',
                $table,
            ));
            $first = ['default', 'invoice', 'document_number', '-', '2026'];
            $runs = [
                [$first, 1],
                [$first, 2],
                [['default', 'credit', 'document_number', '-', '2026'], 1],
                [['default', 'invoice', 'voucher_number', '-', '2026'], 1],
                [['entity-b', 'invoice', 'document_number', '-', '2026'], 1],
                [['default', 'invoice', 'document_number', 'north-branch', '2026'], 1],
                [['default', 'invoice', 'document_number', 'south-branch', '2026'], 1],
                [['default', 'invoice', 'document_number', '-', '2027'], 1],
                [$first, 3],
            ];
            foreach ($runs as [$coordinates, $expected]) {
                $select->execute($coordinates);
                $stored = $select->fetchColumn();
                $select->closeCursor();
                if ($stored === false) {
                    self::assertTrue($insert->execute($coordinates));
                    $current = 0;
                } else {
                    $current = (int) $stored;
                }
                self::assertTrue($update->execute([$current + 1, ...$coordinates, $current]));
                self::assertSame(1, $update->rowCount());
                self::assertSame($expected, $current + 1);
            }
        } finally {
            $pdo->rollBack();
        }
    }

    /**
     * Two allocated-number fields on one published definition keep independent runs through real creates.
     *
     * Each created record carries both numbers, and each field's run is contiguous from one under its own
     * declared scope, reset and format — the create-path form of the same identity partition the allocator
     * seam proves above.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTwoSequenceFieldsOnOnePublishedDefinitionKeepIndependentRuns(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $context = TestKernelFactory::administratorContext($container);
        $records = $this->records($container);

        $document = NeutralBusinessFixture::document($this->suffix(), Uuid::uuid7()->toString());
        $document['fields'][] = $this->sequenceField('document_number', 'yearly', 'SEQ-', 4);
        $document['fields'][] = $this->sequenceField('voucher_number', 'monthly', 'VCH-', 3);
        $definition = NeutralBusinessFixture::install($container, $context, $document);

        $year = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y');
        $month = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m');
        for ($index = 1; $index <= 2; ++$index) {
            $recordId = Uuid::uuid7()->toString();
            $records->create(new CreateRecordCommand(
                $context,
                $definition->id,
                NeutralBusinessFixture::recordValues(sprintf('Identity run %d', $index)),
                IdempotencyKey::fromString('sequence-identity-' . Uuid::uuid7()->toString()),
                recordId: $recordId,
            ));
            $view = $records->read(new ReadRecordQuery($context, $definition->id, $recordId));
            self::assertSame(sprintf('SEQ-%s-%04d', $year, $index), $view->values['document_number'] ?? null);
            self::assertSame(sprintf('VCH-%s-%03d', $month, $index), $view->values['voucher_number'] ?? null);
        }
        self::assertSame(2, $this->stored($container, 'default', $definition->id, 'document_number', '-', $year));
        self::assertSame(2, $this->stored($container, 'default', $definition->id, 'voucher_number', '-', $month));
    }

    /**
     * A non-site record keeps its run on the definition's immutable catalog-site coordinate.
     *
     * The ownership model first proves that neither the definition nor its records can be widened beyond
     * a site. The repository then refuses a whole catalog-tuple move of the same UUID while saving and an
     * isolated site move at its lower-level publication seam. A second installation-scoped record must
     * continue at two on the original site and no counter may appear under the refused site, proving the
     * fallback coordinate is stable without a sentinel or migration.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testNonSiteSequenceCoordinateSurvivesRefusedOwnershipWideningAndCatalogMove(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $context = TestKernelFactory::administratorContext($container);
        $records = $this->records($container);
        $policy = $container->get(ResourceOwnershipScopePolicy::class);
        $repository = $container->get(BusinessDefinitionRepository::class);
        $transactions = $container->get(TransactionManager::class);
        $clock = $container->get(ClockInterface::class);
        self::assertInstanceOf(ResourceOwnershipScopePolicy::class, $policy);
        self::assertInstanceOf(BusinessDefinitionRepository::class, $repository);
        self::assertInstanceOf(TransactionManager::class, $transactions);
        self::assertInstanceOf(ClockInterface::class, $clock);

        $suffix = $this->suffix();
        $document = NeutralBusinessFixture::document($suffix, Uuid::uuid7()->toString());
        $document['scope'] = 'installation';
        $document['fields'][] = $this->sequenceField('document_number', 'never', 'IMM-', 4);
        $definition = NeutralBusinessFixture::install($container, $context, $document);

        $firstId = Uuid::uuid7()->toString();
        $records->create(new CreateRecordCommand(
            $context,
            $definition->id,
            NeutralBusinessFixture::recordValues('Immutable counter owner one'),
            IdempotencyKey::fromString('sequence-site-invariant-' . Uuid::uuid7()->toString()),
            recordId: $firstId,
        ));
        $first = $records->read(new ReadRecordQuery($context, $definition->id, $firstId));
        self::assertSame('IMM-0001', $first->values['document_number'] ?? null);
        self::assertSame(1, $this->stored($container, 'default', $definition->id, 'document_number', '-', ''));

        $siteOnlyResources = [
            'business_definition' => $definition->id,
            'business_record' => $first->recordKey,
        ];
        foreach ($siteOnlyResources as $category => $id) {
            self::assertSame(OwnershipScopeRule::SiteOnly, $policy->rule($category));
            try {
                ResourceOwnership::of(
                    AuthorizationResource::item($category, $id),
                    OwnershipScope::installation(),
                    $policy,
                );
                self::fail(sprintf('%s ownership must not widen beyond its catalog site.', $category));
            } catch (OwnershipScopeNotPermitted) {
                // The typed owner cannot be assembled, so no registry write can widen this category.
            }
        }

        $movedSite = 'moved' . $suffix;
        $movedDocument = $definition->toArray();
        $movedDocument['status'] = 'draft';
        $movedDocument['definition_version'] = 0;
        $movedDocument['site'] = $movedSite;
        $movedDocument['owner'] = ['type' => 'site', 'identifier' => $movedSite];
        $movedDocument['handle'] = 'site.' . $movedSite . '.sequence_owner';
        $moved = EntityTypeDefinition::fromArray($movedDocument);
        try {
            $transactions->transactional(static fn () => $repository->saveDraft(
                $moved,
                $context->actorId(),
                $clock->now(),
                null,
            ));
            self::fail('A definition UUID must not be moved to another catalog site or owner.');
        } catch (InvalidBusinessDefinition $refused) {
            self::assertSame(
                'A business-definition identity, catalog site, handle or owner cannot be changed.',
                $refused->getMessage(),
            );
        }

        $draftDocument = $definition->toArray();
        $draftDocument['status'] = 'draft';
        $draftDocument['definition_version'] = 0;
        $draft = EntityTypeDefinition::fromArray($draftDocument);
        $storedDraft = $transactions->transactional(static fn () => $repository->saveDraft(
            $draft,
            $context->actorId(),
            $clock->now(),
            0,
        ));
        $movedPublicationDocument = $draft->toArray();
        $movedPublicationDocument['site'] = $movedSite;
        $movedDraft = EntityTypeDefinition::fromArray($movedPublicationDocument);
        $movedPublished = $movedDraft->published($definition->definitionVersion + 1);
        $movedPlan = (new BusinessDefinitionCompatibilityAnalyzer())->analyze($definition, $movedDraft);
        try {
            $transactions->transactional(static fn () => $repository->publish(
                $movedPublished,
                $movedPlan,
                $context->actorId(),
                $clock->now(),
                $storedDraft->revision,
            ));
            self::fail('Publication must not move a definition UUID to another catalog site.');
        } catch (InvalidBusinessDefinition $refused) {
            self::assertSame(
                'A business-definition identity, catalog site, handle or owner cannot be changed.',
                $refused->getMessage(),
            );
        }

        self::assertSame(1, $this->stored($container, 'default', $definition->id, 'document_number', '-', ''));
        self::assertSame(0, $this->stored($container, $movedSite, $definition->id, 'document_number', '-', ''));
        $secondId = Uuid::uuid7()->toString();
        $records->create(new CreateRecordCommand(
            $context,
            $definition->id,
            NeutralBusinessFixture::recordValues('Immutable counter owner two'),
            IdempotencyKey::fromString('sequence-site-invariant-' . Uuid::uuid7()->toString()),
            recordId: $secondId,
        ));
        $second = $records->read(new ReadRecordQuery($context, $definition->id, $secondId));
        self::assertSame('IMM-0002', $second->values['document_number'] ?? null);
        self::assertSame(2, $this->stored($container, 'default', $definition->id, 'document_number', '-', ''));
        self::assertSame(0, $this->stored($container, $movedSite, $definition->id, 'document_number', '-', ''));
    }

    /**
     * Declare one closed allocated-number field for the fixture document.
     *
     * @param   string  $handle   Field handle the run is allocated under.
     * @param   string  $reset    Reset period the sequence declares.
     * @param   string  $prefix   Literal head of the rendered number.
     * @param   int     $padding  Digits the counter is padded to.
     *
     * @return  array<string, mixed>  A closed, required, unique `core.sequence` field declaration.
     *
     * @since   2.0.0
     */
    private function sequenceField(string $handle, string $reset, string $prefix, int $padding): array
    {
        return [
            'handle' => $handle,
            'label' => ucfirst(str_replace('_', ' ', $handle)),
            'type' => 'core.sequence',
            'configuration' => [
                'scope' => 'site',
                'reset' => $reset,
                'prefix' => $prefix,
                'padding' => $padding,
                'timezone' => 'UTC',
            ],
            'required' => true,
            'nullable' => false,
            'length' => 36,
            'unique' => true,
            'indexed' => true,
            'immutable_after_create' => true,
            'server_only' => true,
            'read_only' => true,
            'create_visible' => false,
            'update_visible' => false,
            'sortable' => true,
            'filterable' => true,
        ];
    }

    /**
     * Read the committed value one counter identity stands at, or zero when it has no row.
     *
     * @param   Container  $container       Integration container holding the connection and table map.
     * @param   string     $siteIdentifier  Site or legal-entity coordinate of the counter.
     * @param   string     $definitionId    Definition coordinate of the counter.
     * @param   string     $fieldHandle     Field-handle coordinate of the counter.
     * @param   string     $scopeKey        Scope-key coordinate of the counter.
     * @param   string     $periodKey       Period-key coordinate of the counter.
     *
     * @return  int  The stored `current_value`, or zero when the identity names no row yet.
     *
     * @since   2.0.0
     */
    private function stored(
        Container $container,
        string $siteIdentifier,
        string $definitionId,
        string $fieldHandle,
        string $scopeKey,
        string $periodKey,
    ): int {
        $stored = $this->connection($container)->fetchOne(sprintf(
            'SELECT current_value FROM %s WHERE site_identifier = ? AND definition_id = ? AND field_handle = ? '
            . 'AND scope_key = ? AND period_key = ?',
            $this->tables($container)->quoted('business_number_sequences'),
        ), [$siteIdentifier, $definitionId, $fieldHandle, $scopeKey, $periodKey]);

        return $stored === false ? 0 : (int) $stored;
    }

    /**
     * Mint a fresh fixture suffix so each run installs its own definition and its own empty counters.
     *
     * @return  string  Twelve lowercase characters unique to this run.
     *
     * @since   2.0.0
     */
    private function suffix(): string
    {
        return strtolower(substr(str_replace('-', '', Uuid::uuid7()->toString()), -12));
    }

    /**
     * Resolve the allocator under test from the integration container.
     *
     * @param   Container  $container  Integration container.
     *
     * @return  NumberSequenceAllocator  The installed allocator.
     *
     * @since   2.0.0
     */
    private function allocator(Container $container): NumberSequenceAllocator
    {
        $allocator = $container->get(NumberSequenceAllocator::class);
        if (!$allocator instanceof NumberSequenceAllocator) {
            throw new RuntimeException('The business number sequence allocator is unavailable.');
        }

        return $allocator;
    }

    /**
     * Resolve the record service from the integration container.
     *
     * @param   Container  $container  Integration container.
     *
     * @return  BusinessRecordService  The installed record service.
     *
     * @since   2.0.0
     */
    private function records(Container $container): BusinessRecordService
    {
        $records = $container->get(BusinessRecordService::class);
        if (!$records instanceof BusinessRecordService) {
            throw new RuntimeException('The business record service is unavailable.');
        }

        return $records;
    }

    /**
     * Resolve the live integration connection from the container.
     *
     * @param   Container  $container  Integration container.
     *
     * @return  Connection  The installation's connection.
     *
     * @since   2.0.0
     */
    private function connection(Container $container): Connection
    {
        $connection = $container->get(Connection::class);
        if (!$connection instanceof Connection) {
            throw new RuntimeException('The integration connection is unavailable.');
        }

        return $connection;
    }

    /**
     * Resolve the prefixed table-name map from the container.
     *
     * @param   Container  $container  Integration container.
     *
     * @return  TableNames  The installation's table map.
     *
     * @since   2.0.0
     */
    private function tables(Container $container): TableNames
    {
        $tables = $container->get(TableNames::class);
        if (!$tables instanceof TableNames) {
            throw new RuntimeException('The integration table map is unavailable.');
        }

        return $tables;
    }
}
