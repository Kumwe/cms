<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Studio\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Types;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Studio\Domain\Projection\ContentBlueprintBinding;
use Kumwe\App\Studio\Domain\Projection\EntryCompositionOverrides;
use Kumwe\App\Studio\Infrastructure\Persistence\DoctrineContentProjectionBindingRepository;
use Kumwe\Context\Value\SiteContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Proves persisted Studio projection metadata is site-scoped and revalidated on every read.
 *
 * @since  2.0.0
 */
#[CoversClass(DoctrineContentProjectionBindingRepository::class)]
#[UsesClass(ContentBlueprintBinding::class)]
#[UsesClass(EntryCompositionOverrides::class)]
final class DoctrineContentProjectionBindingRepositoryTest extends TestCase
{
    /**
     * Content type lookup binds the site and immutable version before hydrating the exact Blueprint coordinate.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testBlueprintReadIsSiteScopedAndRevalidated(): void
    {
        $site = SiteContext::fromString('Publisher-Namibia');
        $database = $this->createMock(Connection::class);
        $database->expects(self::once())->method('quoteSingleIdentifier')
            ->with('kumwe_studio_content_blueprint_bindings')
            ->willReturn('`kumwe_studio_content_blueprint_bindings`');
        $database->expects(self::once())->method('fetchAssociative')->with(
            'SELECT blueprint_id, blueprint_version, blueprint_revision, binding_revision '
                . 'FROM `kumwe_studio_content_blueprint_bindings` '
                . 'WHERE site_identifier = ? AND content_type_id = ? AND content_type_version = ?',
            ['publisher-namibia', self::typeId(), 4],
            [ParameterType::STRING, Types::GUID, ParameterType::INTEGER],
        )->willReturn([
            'blueprint_id' => 'kumwe.blueprints/article',
            'blueprint_version' => '1.5.0',
            'blueprint_revision' => 'artifact-22',
            'binding_revision' => '8',
        ]);

        $binding = $this->repository($database)->blueprint($site, self::typeId(), 4);

        self::assertNotNull($binding);
        self::assertSame($site, $binding->site);
        self::assertSame(self::typeId(), $binding->contentTypeId);
        self::assertSame(4, $binding->contentTypeVersion);
        self::assertSame('kumwe.blueprints/article', $binding->blueprintId);
        self::assertSame('1.5.0', $binding->blueprintVersion);
        self::assertSame('artifact-22', $binding->blueprintRevision);
        self::assertSame(8, $binding->revision);
    }

    /**
     * An absent binding and absent override are represented by null without fabricating default rows.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testMissingProjectionMetadataReturnsNull(): void
    {
        $database = $this->createMock(Connection::class);
        $database->expects(self::exactly(2))->method('quoteSingleIdentifier')->willReturnCallback(
            static fn (string $name): string => '`' . $name . '`',
        );
        $database->expects(self::exactly(2))->method('fetchAssociative')->willReturn(false);
        $repository = $this->repository($database);

        self::assertNull($repository->blueprint(SiteContext::default(), self::typeId(), 4));
        self::assertNull($repository->overrides(SiteContext::default(), self::entryId()));
    }

    /**
     * Drivers returning JSON text or a decoded object both produce the same canonical immutable overrides.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testOverrideReadAcceptsBothPortableDriverJsonShapes(): void
    {
        foreach (
            [
                '{"hero/main":{"tone":"quiet"}}',
                (object) ['hero/main' => (object) ['tone' => 'quiet']],
            ] as $stored
        ) {
            $site = SiteContext::fromString('publisher-namibia');
            $database = $this->createMock(Connection::class);
            $database->expects(self::once())->method('quoteSingleIdentifier')
                ->with('kumwe_studio_entry_composition_overrides')
                ->willReturn('`kumwe_studio_entry_composition_overrides`');
            $database->expects(self::once())->method('fetchAssociative')->with(
                'SELECT override_values, override_revision '
                    . 'FROM `kumwe_studio_entry_composition_overrides` '
                    . 'WHERE site_identifier = ? AND content_entry_id = ?',
                ['publisher-namibia', self::entryId()],
                [ParameterType::STRING, Types::GUID],
            )->willReturn(['override_values' => $stored, 'override_revision' => 3]);

            $overrides = $this->repository($database)->overrides($site, self::entryId());

            self::assertNotNull($overrides);
            self::assertSame($site, $overrides->site);
            self::assertSame(self::entryId(), $overrides->entryId);
            self::assertSame(3, $overrides->revision);
            self::assertSame('{"hero/main":{"tone":"quiet"}}', $overrides->canonical());
        }
    }

    /**
     * Malformed driver scalars fail loudly instead of becoming plausible projection metadata.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testMalformedStoredProjectionMetadataIsRefused(): void
    {
        $blueprintRows = [
            'missing ID' => [
                'blueprint_id' => '',
                'blueprint_version' => '1.0.0',
                'blueprint_revision' => null,
                'binding_revision' => 1,
            ],
            'missing version' => [
                'blueprint_id' => 'blueprint/article',
                'blueprint_version' => '',
                'blueprint_revision' => null,
                'binding_revision' => 1,
            ],
            'empty optional revision' => [
                'blueprint_id' => 'blueprint/article',
                'blueprint_version' => '1.0.0',
                'blueprint_revision' => '',
                'binding_revision' => 1,
            ],
            'non-canonical binding revision' => [
                'blueprint_id' => 'blueprint/article',
                'blueprint_version' => '1.0.0',
                'blueprint_revision' => null,
                'binding_revision' => '01',
            ],
        ];
        foreach ($blueprintRows as $label => $row) {
            $database = $this->databaseReturning('studio_content_blueprint_bindings', $row);
            try {
                $this->repository($database)->blueprint(SiteContext::default(), self::typeId(), 4);
                self::fail(sprintf('The malformed Blueprint row %s was accepted.', $label));
            } catch (RuntimeException $failure) {
                self::assertStringContainsString('Stored Studio', $failure->getMessage(), $label);
            }
        }

        foreach (['{not-json', '[]', 42] as $stored) {
            $database = $this->databaseReturning('studio_entry_composition_overrides', [
                'override_values' => $stored,
                'override_revision' => 1,
            ]);
            try {
                $this->repository($database)->overrides(SiteContext::default(), self::entryId());
                self::fail('A malformed override row was accepted.');
            } catch (RuntimeException $failure) {
                self::assertSame('Stored Studio entry overrides are not a JSON object.', $failure->getMessage());
            }
        }
    }

    /**
     * Build the Doctrine reader over the supplied connection test double.
     *
     * @param   Connection  $database  Connection returning the row under test.
     *
     * @return  DoctrineContentProjectionBindingRepository
     *
     * @since   2.0.0
     */
    private function repository(Connection $database): DoctrineContentProjectionBindingRepository
    {
        return new DoctrineContentProjectionBindingRepository($database, new TableNames($database, 'kumwe_'));
    }

    /**
     * Build a connection returning one row from one named projection table.
     *
     * @param   string                $logicalTable  Logical table selected by the repository method.
     * @param   array<string, mixed>  $row           Driver row returned to the adapter.
     *
     * @return  Connection
     *
     * @since   2.0.0
     */
    private function databaseReturning(string $logicalTable, array $row): Connection
    {
        $database = $this->createMock(Connection::class);
        $database->expects(self::once())->method('quoteSingleIdentifier')
            ->with('kumwe_' . $logicalTable)
            ->willReturn('`kumwe_' . $logicalTable . '`');
        $database->expects(self::once())->method('fetchAssociative')->willReturn($row);

        return $database;
    }

    /**
     * Return the stable Content type UUID used in binding queries.
     *
     * @return  string
     *
     * @since   2.0.0
     */
    private static function typeId(): string
    {
        return '018f22e2-7c8b-7ab0-8f3a-88e8026be300';
    }

    /**
     * Return the stable Content entry UUID used in override queries.
     *
     * @return  string
     *
     * @since   2.0.0
     */
    private static function entryId(): string
    {
        return '018f22e2-7c8b-7ab0-8f3a-88e8026be400';
    }
}
