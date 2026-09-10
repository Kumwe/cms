<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\BusinessSchema\Infrastructure;

use DateTimeImmutable;
use Kumwe\App\BusinessDefinition\Application\BusinessDefinitionRepository;
use Kumwe\App\BusinessDefinition\Application\BusinessDefinitionValidator;
use Kumwe\App\BusinessDefinition\Application\DefinitionCatalogEntry;
use Kumwe\App\BusinessDefinition\Application\DefinitionVersionRecord;
use Kumwe\App\BusinessDefinition\Application\FieldTypeRegistry;
use Kumwe\App\BusinessDefinition\Domain\CompatibilityPlan;
use Kumwe\App\BusinessDefinition\Domain\DefinitionStatus;
use Kumwe\App\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\App\BusinessSchema\Domain\PhysicalForeignKeyBlueprint;
use Kumwe\App\BusinessSchema\Domain\PhysicalNameCompiler;
use Kumwe\App\BusinessSchema\Infrastructure\Schema\CanonicalDefinitionPhysicalSchemaCompiler;
use Kumwe\Context\Value\SiteContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

#[CoversClass(CanonicalDefinitionPhysicalSchemaCompiler::class)]
final class CanonicalDefinitionPhysicalSchemaCompilerTest extends TestCase
{
    public function testReferenceIdentityUsesGuidPrimaryKeyAndScopedAlternateUniqueIndex(): void
    {
        $definition = EntityTypeDefinition::fromArray(self::document(
            '018f4f24-98d8-7ad4-8f3f-38c909178b6b',
            'site.default.asset',
            'reference',
        ));
        $blueprint = $this->compiler()->compile($definition, SiteContext::fromString('default'));
        $table = $blueprint->table('record');

        self::assertNotNull($table);
        self::assertSame('guid', $table->column('record_id')?->doctrineType);
        self::assertSame([$table->column('record_id')?->physicalName], $table->primaryKey);
        $identityIndex = array_values(array_filter(
            $table->indexes(),
            static fn ($index): bool => $index->logicalName === 'field.reference',
        ))[0] ?? null;
        self::assertNotNull($identityIndex);
        self::assertTrue($identityIndex->unique);
        self::assertSame([
            $table->column('site_identifier')?->physicalName,
            $table->column('reference')?->physicalName,
        ], $identityIndex->columns);
    }

    public function testConstraintNamesCannotCollideAcrossDefinitions(): void
    {
        $left = $this->compiler()->compile(EntityTypeDefinition::fromArray(self::document(
            '018f4f24-98d8-7ad4-8f3f-38c909178b6b',
            'site.default.asset',
        )), SiteContext::fromString('default'));
        $right = $this->compiler()->compile(EntityTypeDefinition::fromArray(self::document(
            '018f4f24-98d8-7ad4-8f3f-38c909178b6c',
            'site.default.contact',
        )), SiteContext::fromString('default'));
        $leftNames = array_map(
            static fn ($index): string => strtolower($index->physicalName),
            $left->table('record')?->indexes() ?? []
        );
        $rightNames = array_map(
            static fn ($index): string => strtolower($index->physicalName),
            $right->table('record')?->indexes() ?? []
        );

        self::assertSame([], array_intersect($leftNames, $rightNames));
        foreach ([...$leftNames, ...$rightNames] as $name) {
            self::assertLessThanOrEqual(63, strlen($name));
        }
    }

    public function testVirtualFormulaIsOmittedAndStoredFormulaUsesItsExactResultType(): void
    {
        $document = self::document(
            '018f4f24-98d8-7ad4-8f3f-38c909178b6b',
            'site.default.asset',
        );
        $document['fields'][] = self::formula('virtual_total', 'integer', 'virtual');
        $document['fields'][] = self::formula('stored_total', 'decimal', 'stored', 18, 4);
        $table = $this->compiler()->compile(
            EntityTypeDefinition::fromArray($document),
            SiteContext::fromString('default'),
        )->table('record');

        self::assertNotNull($table);
        self::assertNull($table->column('virtual_total'));
        self::assertSame('decimal', $table->column('stored_total')?->doctrineType);
        self::assertSame(18, $table->column('stored_total')?->options['precision'] ?? null);
        self::assertSame(4, $table->column('stored_total')?->options['scale'] ?? null);
    }

    public function testStructuredRuntimeDefaultIsNotEmittedAsANonPortableJsonDatabaseDefault(): void
    {
        $document = self::document(
            '018f4f24-98d8-7ad4-8f3f-38c909178b6b',
            'site.default.asset',
        );
        $document['fields'][] = [
            'handle' => 'preferences',
            'label' => 'Preferences',
            'type' => 'core.bounded_json',
            'default' => ['layout' => 'compact'],
            'configuration' => ['max_bytes' => 1024],
        ];

        $column = $this->compiler()->compile(
            EntityTypeDefinition::fromArray($document),
            SiteContext::fromString('default'),
        )->table('record')?->column('preferences');

        self::assertNotNull($column);
        self::assertSame('json', $column->doctrineType);
        self::assertArrayNotHasKey('default', $column->options);
    }

    public function testPortableTextLengthBoundaryIsPreservedWithoutSilentCapping(): void
    {
        $document = self::document(
            '018f4f24-98d8-7ad4-8f3f-38c909178b6b',
            'site.default.asset',
        );
        $document['fields'][1]['length'] = 1000;
        $document['fields'][1]['indexed'] = false;
        $definition = EntityTypeDefinition::fromArray($document);
        (new BusinessDefinitionValidator(new FieldTypeRegistry()))->validateGraph([$definition]);

        $column = $this->compiler()->compile(
            $definition,
            SiteContext::fromString('default'),
        )->table('record')?->column('external_reference');

        self::assertSame(1000, $column?->options['length'] ?? null);
    }

    public function testForeignKeySupportIndexIsAlwaysExplicitInThePortableBlueprint(): void
    {
        $indexes = [];
        $foreignKey = new PhysicalForeignKeyBlueprint(
            'source',
            'fk_source',
            ['source_id'],
            'parent_table',
            ['record_id'],
            'CASCADE',
        );
        $arguments = ['relation_table', &$indexes, [$foreignKey]];

        (new ReflectionMethod(
            CanonicalDefinitionPhysicalSchemaCompiler::class,
            'ensureForeignKeyIndexes',
        ))->invokeArgs($this->compiler(), $arguments);

        self::assertCount(1, $indexes);
        self::assertSame('foreign_key.source', $indexes[0]->logicalName);
        self::assertSame(['source_id'], $indexes[0]->columns);
    }

    /**
     * Proves a reversal link compiles to a restricted target column on the correcting record's own table.
     *
     * The reversal side stores exactly as a many-to-one: one column, one supporting index, one RESTRICT
     * foreign key onto its own record table — so the record a correction names can never be deleted out
     * from under it by the storage engine, and no junction table is invented for either side.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAReversalCompilesToARestrictedSelfTargetColumn(): void
    {
        $definition = self::reversalDefinition();
        $blueprint = $this->compiler($definition)->compile($definition, SiteContext::fromString('default'));
        $record = $blueprint->table('record');

        self::assertNotNull($record);
        $column = $record->column('relation:reverses.target_id');
        self::assertNotNull($column, 'The reversal side carries the storage, as a many-to-one would.');
        self::assertTrue($column->nullable, 'An ordinary record reverses nothing.');
        $foreignKey = null;
        foreach ($record->foreignKeys() as $candidate) {
            if ($candidate->logicalName === 'relation.reverses') {
                $foreignKey = $candidate;
            }
        }
        self::assertNotNull($foreignKey);
        self::assertSame($record->physicalName, $foreignKey->foreignTable, 'A reversal points at its own table.');
        self::assertSame('RESTRICT', $foreignKey->onDelete);
        self::assertNull(
            $record->column('relation:reversed_by.target_id'),
            'The one-to-many inverse reads the reversal column and stores nothing of its own.',
        );
        self::assertCount(
            1,
            $blueprint->tables(),
            'Neither side of the reversal pair may emit a junction table.',
        );
    }

    /**
     * Build a compiler able to resolve the given definitions as published targets.
     *
     * @param   EntityTypeDefinition  $targets  Definitions the stubbed repository resolves by handle.
     *
     * @return  CanonicalDefinitionPhysicalSchemaCompiler  Compiler over the stubbed catalog.
     *
     * @since   2.0.0
     */
    private function compiler(EntityTypeDefinition ...$targets): CanonicalDefinitionPhysicalSchemaCompiler
    {
        $repository = $this->createStub(BusinessDefinitionRepository::class);
        if ($targets !== []) {
            $entries = [];
            $versions = [];
            $publishedAt = new DateTimeImmutable('2026-01-01T00:00:00+00:00');
            foreach ($targets as $definition) {
                $entries[$definition->handle] = new DefinitionCatalogEntry(
                    $definition->id,
                    $definition->siteIdentifier,
                    $definition->handle,
                    $definition->owner,
                    true,
                    0,
                    $definition->definitionVersion,
                    DefinitionStatus::Published,
                    $publishedAt,
                );
                $versions[$definition->handle] = new DefinitionVersionRecord(
                    $definition,
                    new CompatibilityPlan(null, 1, null, $definition->checksum(), []),
                    DefinitionStatus::Published,
                    '00000000-0000-7000-8000-000000000001',
                    $publishedAt,
                );
            }
            $repository->method('entry')->willReturnCallback(
                static fn (SiteContext $site, string $identifier): ?DefinitionCatalogEntry =>
                    $entries[$identifier] ?? null,
            );
            $repository->method('published')->willReturnCallback(
                static fn (SiteContext $site, string $identifier, ?int $version = null): ?DefinitionVersionRecord =>
                    $versions[$identifier] ?? null,
            );
        }

        return new CanonicalDefinitionPhysicalSchemaCompiler(
            $repository,
            new FieldTypeRegistry(),
            new PhysicalNameCompiler('kumwe_'),
        );
    }

    /**
     * The neutral asset definition extended with a reciprocal same-definition reversal pair.
     *
     * @return  EntityTypeDefinition  Published definition declaring `reverses` and `reversed_by`.
     *
     * @since   2.0.0
     */
    private static function reversalDefinition(): EntityTypeDefinition
    {
        $document = self::document(
            '018f4f24-98d8-7ad4-8f3f-38c909178b6b',
            'site.default.asset',
        );
        $document['relationships'] = [
            [
                'handle' => 'reverses',
                'label' => 'Reverses',
                'kind' => 'reversal',
                'target' => 'site.default.asset',
                'inverse' => 'reversed_by',
                'on_delete' => 'restrict',
            ],
            [
                'handle' => 'reversed_by',
                'label' => 'Reversed by',
                'kind' => 'one_to_many',
                'target' => 'site.default.asset',
                'inverse' => 'reverses',
                'on_delete' => 'restrict',
            ],
        ];

        return EntityTypeDefinition::fromArray($document);
    }

    /** @return array<string, mixed> */
    private static function document(string $id, string $handle, string $identity = 'uuid'): array
    {
        $identityType = $identity === 'reference' ? 'core.reference_identity' : 'core.uuid';

        return [
            'id' => $id,
            'owner' => ['type' => 'site', 'identifier' => 'default'],
            'site' => 'default',
            'handle' => $handle,
            'singular_label' => 'Record',
            'plural_label' => 'Records',
            'status' => 'published',
            'definition_version' => 1,
            'storage_mode' => 'relational',
            'identity_strategy' => $identity,
            'scope' => 'site',
            'audit_enabled' => true,
            'revisions_enabled' => true,
            'fields' => [
                [
                    'handle' => $identity === 'reference' ? 'reference' : 'id',
                    'label' => 'Identity',
                    'type' => $identityType,
                    'required' => true,
                    'nullable' => false,
                    'unique' => true,
                    'indexed' => true,
                    'immutable_after_create' => true,
                    'server_only' => true,
                    'read_only' => true,
                ],
                [
                    'handle' => 'external_reference',
                    'label' => 'External reference',
                    'type' => 'core.text',
                    'length' => 100,
                    'indexed' => true,
                ],
            ],
            'relationships' => [],
            'views' => [],
            'actions' => [],
            'workflow' => null,
            'compatibility_metadata' => [],
        ];
    }

    /** @return array<string, mixed> */
    private static function formula(
        string $handle,
        string $type,
        string $mode,
        ?int $precision = null,
        ?int $scale = null,
    ): array {
        return [
            'handle' => $handle,
            'label' => $handle,
            'type' => 'core.computed',
            'nullable' => true,
            'server_only' => true,
            'computed' => true,
            'read_only' => true,
            'formula' => ['op' => 'literal', 'type' => $type, 'value' => $type === 'decimal' ? '1.2500' : 1],
            'computation_mode' => $mode,
            'precision' => $precision,
            'scale' => $scale,
        ];
    }
}
