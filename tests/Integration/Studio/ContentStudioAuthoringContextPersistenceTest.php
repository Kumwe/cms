<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\Studio;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Kumwe\App\Infrastructure\Persistence\Migration\StudioContentAuthoringContextMigration;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Studio\Application\Authoring\ContentStudioAuthoringContextBinding;
use Kumwe\App\Studio\Application\Authoring\ContentStudioAuthoringTarget;
use Kumwe\App\Studio\Domain\Authoring\StudioAuthoringIntent;
use Kumwe\App\Studio\Infrastructure\Persistence\DoctrineContentStudioAuthoringContextPurger;
use Kumwe\App\Studio\Infrastructure\Persistence\DoctrineContentStudioAuthoringContextRepository;
use Kumwe\Context\Value\AuthenticatedSurface;
use Kumwe\Context\Value\SiteContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use RuntimeException;

/**
 * Exercises the real migration and Doctrine contextual-authoring store through SQLite DBAL.
 *
 * @since  2.0.0
 */
#[CoversClass(DoctrineContentStudioAuthoringContextRepository::class)]
#[CoversClass(DoctrineContentStudioAuthoringContextPurger::class)]
#[CoversClass(StudioContentAuthoringContextMigration::class)]
#[UsesClass(ContentStudioAuthoringContextBinding::class)]
#[UsesClass(ContentStudioAuthoringTarget::class)]
final class ContentStudioAuthoringContextPersistenceTest extends TestCase
{
    /**
     * Replay creates one secret-free table and round-trips an exact typed create target.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testMigrationIsIdempotentAndExactBindingsRoundTripWithoutBrowserAuthorityData(): void
    {
        $database = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $tables = new TableNames($database, 'kumwe_');
        $migration = new StudioContentAuthoringContextMigration($tables);

        $migration->up($database);
        $migration->up($database);

        self::assertSame('20260827010000_studio_content_authoring_contexts', $migration->id());
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $migration->checksum());
        $repository = new DoctrineContentStudioAuthoringContextRepository($database, $tables);
        $binding = self::binding();
        $repository->add($binding);

        $loaded = $repository->find($binding->contextKey);
        self::assertInstanceOf(ContentStudioAuthoringContextBinding::class, $loaded);
        self::assertEquals($binding, $loaded);
        self::assertNull($repository->find('contexts/' . str_repeat('f', 64)));
        $table = $database->createSchemaManager()->introspectTableByUnquotedName(
            $tables->raw('studio_content_authoring_contexts'),
        );
        $columns = array_keys($table->getColumns());
        self::assertContains(['expires_at'], array_map(
            static fn ($index): array => array_map(
                static fn ($column): string => $column->getColumnName()->getIdentifier()->getValue(),
                $index->getIndexedColumns(),
            ),
            $table->getIndexes(),
        ));
        self::assertSame('2026-08-27T00:00:00+00:00', $loaded->createdAt->format('c'));
        self::assertSame('2026-08-27T08:00:00+00:00', $loaded->expiresAt->format('c'));
        foreach (['cookie', 'credential', 'csrf', 'capabilities', 'endpoint', 'configuration'] as $forbidden) {
            self::assertNotContains($forbidden, $columns);
        }
    }

    /**
     * Exact-case comparison defeats permissive collations and corrupt persisted enums fail loudly.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRepositoryRejectsCaseFoldedKeysAndMalformedStoredMetadata(): void
    {
        $database = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $tables = new TableNames($database, 'kumwe_');
        $this->createCaseInsensitiveTable($database, $tables);
        $repository = new DoctrineContentStudioAuthoringContextRepository($database, $tables);
        $binding = self::binding();
        $repository->add($binding);

        self::assertNull($repository->find(strtoupper($binding->contextKey)));
        $database->update(
            $tables->raw('studio_content_authoring_contexts'),
            ['intent' => 'not-an-intent'],
            ['context_key' => $binding->contextKey],
        );

        try {
            $repository->find($binding->contextKey);
            self::fail('A malformed stored target must fail closed.');
        } catch (RuntimeException $exception) {
            self::assertSame('Stored Studio Content authoring context metadata is invalid.', $exception->getMessage());
        }
        $database->update(
            $tables->raw('studio_content_authoring_contexts'),
            ['intent' => StudioAuthoringIntent::Create->value, 'expires_at' => 'not-an-instant'],
            ['context_key' => $binding->contextKey],
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Stored Studio Content authoring context metadata is invalid.');
        $repository->find($binding->contextKey);
    }

    /**
     * Retention removes only contexts at or beyond their hard expiry in stable bounded passes.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRetentionPurgesExpiredContextsInBoundedOldestFirstPasses(): void
    {
        $database = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $tables = new TableNames($database, 'kumwe_');
        (new StudioContentAuthoringContextMigration($tables))->up($database);
        $repository = new DoctrineContentStudioAuthoringContextRepository($database, $tables);
        $oldest = self::binding('oldest-expired', '2026-08-27T00:00:00+00:00', '2026-08-27T02:00:00+00:00');
        $boundaryFirst = self::binding(
            'boundary-expired-first',
            '2026-08-27T01:00:00+00:00',
            '2026-08-27T08:00:00+00:00',
        );
        $boundarySecond = self::binding(
            'boundary-expired-second',
            '2026-08-27T01:00:01+00:00',
            '2026-08-27T08:00:00+00:00',
        );
        $live = self::binding('still-live', '2026-08-27T02:00:00+00:00', '2026-08-27T08:00:01+00:00');
        foreach ([$boundarySecond, $live, $oldest, $boundaryFirst] as $binding) {
            $repository->add($binding);
        }
        $clock = new class implements ClockInterface {
            /**
             * Hold the exact retention cutoff.
             *
             * @return  DateTimeImmutable  Fixed expiry boundary.
             *
             * @since   2.0.0
             */
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('2026-08-27T08:00:00+00:00');
            }
        };
        $purger = new DoctrineContentStudioAuthoringContextPurger($database, $tables, $clock);
        $boundaryKeys = [$boundaryFirst->contextKey, $boundarySecond->contextKey];
        sort($boundaryKeys, SORT_STRING);

        self::assertSame(
            [$oldest->contextKey, $boundaryKeys[0]],
            $purger->expiredCandidates($clock->now(), 2),
        );
        self::assertSame(2, $purger->purgeExpired(2));
        self::assertNull($repository->find($oldest->contextKey));
        self::assertNull($repository->find($boundaryKeys[0]));
        self::assertNotNull($repository->find($boundaryKeys[1]));
        self::assertNotNull($repository->find($live->contextKey));
        self::assertSame(1, $purger->purgeExpired(2));
        self::assertNull($repository->find($boundaryKeys[1]));
        self::assertNotNull($repository->find($live->contextKey));
        self::assertSame(0, $purger->purgeExpired(2));
    }

    /**
     * Build one complete immutable contextual-authoring binding.
     *
     * @param   string  $seed       Stable material used to derive a distinct opaque context key.
     * @param   string  $createdAt  ISO-8601 creation instant.
     * @param   string  $expiresAt  ISO-8601 exclusive expiry instant.
     *
     * @return  ContentStudioAuthoringContextBinding  Secret-free persistence fixture.
     *
     * @since   2.0.0
     */
    private static function binding(
        string $seed = 'persistence-context',
        string $createdAt = '2026-08-27T00:00:00+00:00',
        string $expiresAt = '2026-08-27T08:00:00+00:00',
    ): ContentStudioAuthoringContextBinding {
        return new ContentStudioAuthoringContextBinding(
            'contexts/' . hash('sha256', $seed),
            '018f22e2-7c8b-7ab0-8f3a-88e8026bb301',
            SiteContext::DEFAULT,
            'organization-1',
            'workspace-1',
            AuthenticatedSurface::Administrator->value,
            hash('sha256', 'administrator-session'),
            hash('sha256', 'administrator-authority'),
            new ContentStudioAuthoringTarget(
                StudioAuthoringIntent::Create,
                'content-model:018f22e2-7c8b-7ab0-8f3a-88e8026be810',
                '0.0.3',
                'content-type-v3',
                null,
                null,
                '/administrator/content/new?content_type=018f22e2-7c8b-7ab0-8f3a-88e8026be810',
            ),
            new DateTimeImmutable($createdAt),
            new DateTimeImmutable($expiresAt),
        );
    }

    /**
     * Create a schema-equivalent table whose key collation deliberately folds ASCII case.
     *
     * @param   Connection  $database  In-memory SQLite connection.
     * @param   TableNames  $tables    Prefix-aware test table compiler.
     *
     * @return  void
     *
     * @throws  \Doctrine\DBAL\Exception  When SQLite refuses the test schema.
     *
     * @since   2.0.0
     */
    private function createCaseInsensitiveTable(Connection $database, TableNames $tables): void
    {
        $database->executeStatement(sprintf(
            'CREATE TABLE %s ('
                . 'context_key VARCHAR(240) COLLATE NOCASE NOT NULL PRIMARY KEY, '
                . 'actor_id VARCHAR(191) NOT NULL, site_identifier VARCHAR(191) NOT NULL, '
                . 'organization_identifier VARCHAR(191) NULL, workspace_identifier VARCHAR(191) NULL, '
                . 'surface VARCHAR(63) NOT NULL, session_binding VARCHAR(64) NOT NULL, '
                . 'authority_binding VARCHAR(64) NOT NULL, intent VARCHAR(16) NOT NULL, '
                . 'model_identifier VARCHAR(240) NULL, model_version VARCHAR(80) NULL, '
                . 'model_revision VARCHAR(200) NULL, entry_identifier VARCHAR(240) NULL, '
                . 'entry_revision VARCHAR(200) NULL, return_path VARCHAR(500) NOT NULL, '
                . 'created_at DATETIME NOT NULL, expires_at DATETIME NOT NULL)',
            $tables->quoted('studio_content_authoring_contexts'),
        ));
    }
}
