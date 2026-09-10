<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\Studio;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Types\Types;
use Kumwe\App\Infrastructure\Persistence\Migration\StudioContentProjectionMigration;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Studio\Application\Host\StudioPersistenceRace;
use Kumwe\App\Studio\Domain\Projection\ContentBlueprintBinding;
use Kumwe\App\Studio\Domain\Projection\EntryCompositionOverrides;
use Kumwe\App\Studio\Infrastructure\Persistence\DoctrineContentProjectionBindingRepository;
use Kumwe\Context\Value\SiteContext;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the real projection migration and Doctrine reader preserve site isolation and exact metadata.
 *
 * @since  2.0.0
 */
#[CoversClass(StudioContentProjectionMigration::class)]
#[CoversClass(DoctrineContentProjectionBindingRepository::class)]
#[UsesClass(ContentBlueprintBinding::class)]
#[UsesClass(EntryCompositionOverrides::class)]
final class StudioContentProjectionPersistenceTest extends TestCase
{
    /**
     * The binding write store requires the provisioner's transaction and preserves exact metadata.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testInitialBindingWriteRequiresATransactionAndRoundTrips(): void
    {
        $database = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $database->executeStatement('PRAGMA foreign_keys = ON');
        $tables = new TableNames($database, 'kumwe_');
        $this->createContentParents($database, $tables);
        (new StudioContentProjectionMigration($tables))->up($database);
        $this->insertContentParents($database, $tables);
        $repository = new DoctrineContentProjectionBindingRepository($database, $tables);
        $binding = new ContentBlueprintBinding(
            SiteContext::fromString('publisher-namibia'),
            self::typeId(),
            4,
            'content.blueprint/018f22e2-7c8b-7ab0-8f3a-88e8026be500-v4',
            '1.0.0',
            null,
            1,
        );

        try {
            $repository->add($binding);
            self::fail('Provisioning must own one transaction across the binding and artifact writes.');
        } catch (LogicException) {
            // The write seam cannot be used outside the provisioner's atomic unit of work.
        }

        $database->beginTransaction();
        $repository->add($binding);
        $database->commit();

        $stored = $repository->blueprint($binding->site, self::typeId(), 4);
        self::assertEquals($binding, $stored);
    }

    /**
     * A concurrent initial insert is translated to the provisioner's retryable race signal.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testDuplicateInitialBindingIsReportedAsAProvisioningRace(): void
    {
        $database = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $database->executeStatement('PRAGMA foreign_keys = ON');
        $tables = new TableNames($database, 'kumwe_');
        $this->createContentParents($database, $tables);
        (new StudioContentProjectionMigration($tables))->up($database);
        $this->insertContentParents($database, $tables);
        $repository = new DoctrineContentProjectionBindingRepository($database, $tables);
        $binding = new ContentBlueprintBinding(
            SiteContext::fromString('publisher-namibia'),
            self::typeId(),
            4,
            'content.blueprint/018f22e2-7c8b-7ab0-8f3a-88e8026be500-v4',
            '1.0.0',
            null,
            1,
        );
        $database->beginTransaction();
        $repository->add($binding);
        $reported = false;

        try {
            $repository->add($binding);
            self::fail('The duplicate coordinate must be surfaced as a provisioning race.');
        } catch (StudioPersistenceRace) {
            // The composition service can now discard the transaction and read the winner.
            $reported = true;
        } finally {
            $database->rollBack();
        }
        self::assertTrue($reported);
    }

    /**
     * Migration replay, repository reads, cross-site misses and Content cascades hold through SQLite DBAL.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testProjectionMetadataRoundTripsInsideItsOwningSite(): void
    {
        $database = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $database->executeStatement('PRAGMA foreign_keys = ON');
        $tables = new TableNames($database, 'kumwe_');
        $this->createContentParents($database, $tables);
        $migration = new StudioContentProjectionMigration($tables);
        $migration->up($database);
        $migration->up($database);
        $this->insertContentParents($database, $tables);
        try {
            $this->insertBinding($database, $tables, 'publisher-botswana');
            self::fail('A binding cannot claim a site different from its Content definition.');
        } catch (ForeignKeyConstraintViolationException) {
            // The composite tenant coordinate is enforced by the database.
        }
        try {
            $this->insertOverrides($database, $tables, 'publisher-botswana');
            self::fail('Composition overrides cannot claim a site different from their Content entry.');
        } catch (ForeignKeyConstraintViolationException) {
            // The composite tenant coordinate is enforced by the database.
        }
        $this->insertBinding($database, $tables, 'publisher-namibia');
        $this->insertOverrides($database, $tables, 'publisher-namibia');
        $repository = new DoctrineContentProjectionBindingRepository($database, $tables);
        $owner = SiteContext::fromString('publisher-namibia');
        $other = SiteContext::fromString('publisher-botswana');

        $binding = $repository->blueprint($owner, self::typeId(), 4);
        $overrides = $repository->overrides($owner, self::entryId());

        self::assertNotNull($binding);
        self::assertSame('kumwe.blueprints/article', $binding->blueprintId);
        self::assertSame('1.5.0', $binding->blueprintVersion);
        self::assertSame('artifact-22', $binding->blueprintRevision);
        self::assertSame(3, $binding->revision);
        self::assertNotNull($overrides);
        self::assertSame('{"hero/main":{"tone":"quiet"}}', $overrides->canonical());
        self::assertSame(6, $overrides->revision);
        self::assertNull($repository->blueprint($other, self::typeId(), 4));
        self::assertNull($repository->overrides($other, self::entryId()));

        $database->delete($tables->raw('content_type_definition_versions'), [
            'content_type_id' => self::typeId(),
            'version' => 4,
        ], ['content_type_id' => Types::GUID, 'version' => Types::INTEGER]);
        $database->delete(
            $tables->raw('content_entries'),
            ['id' => self::entryId()],
            ['id' => Types::GUID],
        );
        self::assertSame(0, (int) $database->fetchOne(sprintf(
            'SELECT COUNT(*) FROM %s',
            $tables->quoted('studio_content_blueprint_bindings'),
        )));
        self::assertSame(0, (int) $database->fetchOne(sprintf(
            'SELECT COUNT(*) FROM %s',
            $tables->quoted('studio_entry_composition_overrides'),
        )));
    }

    /**
     * Create only the two authoritative Content tables the projection foreign keys need.
     *
     * @param   Connection  $database  SQLite integration connection.
     * @param   TableNames  $tables    Prefix-aware test table compiler.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function createContentParents(Connection $database, TableNames $tables): void
    {
        $database->executeStatement(sprintf(
            'CREATE TABLE %s (site_identifier VARCHAR(191) NOT NULL, content_type_id VARCHAR(36) NOT NULL, '
                . 'version INTEGER NOT NULL, PRIMARY KEY (content_type_id, version))',
            $tables->quoted('content_type_definition_versions'),
        ));
        $database->executeStatement(sprintf(
            'CREATE TABLE %s (site_identifier VARCHAR(191) NOT NULL, id VARCHAR(36) NOT NULL PRIMARY KEY)',
            $tables->quoted('content_entries'),
        ));
    }

    /**
     * Insert the authoritative parent coordinates before optional projection metadata.
     *
     * @param   Connection  $database  SQLite integration connection.
     * @param   TableNames  $tables    Prefix-aware test table compiler.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function insertContentParents(Connection $database, TableNames $tables): void
    {
        $database->insert($tables->raw('content_type_definition_versions'), [
            'site_identifier' => 'publisher-namibia',
            'content_type_id' => self::typeId(),
            'version' => 4,
        ], [
            'site_identifier' => Types::STRING,
            'content_type_id' => Types::GUID,
            'version' => Types::INTEGER,
        ]);
        $database->insert(
            $tables->raw('content_entries'),
            ['site_identifier' => 'publisher-namibia', 'id' => self::entryId()],
            ['site_identifier' => Types::STRING, 'id' => Types::GUID],
        );
    }

    /**
     * Insert a Blueprint binding under the supplied claimed tenant.
     *
     * @param   Connection  $database       SQLite integration connection.
     * @param   TableNames  $tables         Prefix-aware test table compiler.
     * @param   string      $siteIdentifier  Claimed binding owner.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function insertBinding(Connection $database, TableNames $tables, string $siteIdentifier): void
    {
        $database->insert($tables->raw('studio_content_blueprint_bindings'), [
            'site_identifier' => $siteIdentifier,
            'content_type_id' => self::typeId(),
            'content_type_version' => 4,
            'blueprint_id' => 'kumwe.blueprints/article',
            'blueprint_version' => '1.5.0',
            'blueprint_revision' => 'artifact-22',
            'binding_revision' => 3,
        ], [
            'site_identifier' => Types::STRING,
            'content_type_id' => Types::GUID,
            'content_type_version' => Types::INTEGER,
            'blueprint_id' => Types::STRING,
            'blueprint_version' => Types::STRING,
            'blueprint_revision' => Types::STRING,
            'binding_revision' => Types::INTEGER,
        ]);
    }

    /**
     * Insert entry composition overrides under the supplied claimed tenant.
     *
     * @param   Connection  $database       SQLite integration connection.
     * @param   TableNames  $tables         Prefix-aware test table compiler.
     * @param   string      $siteIdentifier  Claimed override owner.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function insertOverrides(Connection $database, TableNames $tables, string $siteIdentifier): void
    {
        $database->insert($tables->raw('studio_entry_composition_overrides'), [
            'site_identifier' => $siteIdentifier,
            'content_entry_id' => self::entryId(),
            'override_values' => ['hero/main' => ['tone' => 'quiet']],
            'override_revision' => 6,
        ], [
            'site_identifier' => Types::STRING,
            'content_entry_id' => Types::GUID,
            'override_values' => Types::JSON,
            'override_revision' => Types::INTEGER,
        ]);
    }

    /**
     * Return the definition UUID shared by the parent and binding rows.
     *
     * @return  string
     *
     * @since   2.0.0
     */
    private static function typeId(): string
    {
        return '018f22e2-7c8b-7ab0-8f3a-88e8026be500';
    }

    /**
     * Return the entry UUID shared by the parent and override rows.
     *
     * @return  string
     *
     * @since   2.0.0
     */
    private static function entryId(): string
    {
        return '018f22e2-7c8b-7ab0-8f3a-88e8026be600';
    }
}
