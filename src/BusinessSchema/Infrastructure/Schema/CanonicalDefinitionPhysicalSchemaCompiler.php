<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessSchema\Infrastructure\Schema;

use Kumwe\App\BusinessDefinition\Application\BusinessDefinitionRepository;
use Kumwe\App\BusinessDefinition\Application\FieldTypeDefinitionResolver;
use Kumwe\App\BusinessDefinition\Domain\ComputationMode;
use Kumwe\App\BusinessDefinition\Domain\DeleteBehavior;
use Kumwe\App\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\App\BusinessDefinition\Domain\FieldDefinition;
use Kumwe\App\BusinessDefinition\Domain\IdentityStrategy;
use Kumwe\App\BusinessDefinition\Domain\RelationshipDefinition;
use Kumwe\App\BusinessDefinition\Domain\RelationshipKind;
use Kumwe\App\BusinessDefinition\Domain\ScopeMode;
use Kumwe\App\BusinessSchema\Application\DefinitionPhysicalSchemaCompiler;
use Kumwe\App\BusinessSchema\Domain\InvalidBusinessSchema;
use Kumwe\App\BusinessSchema\Domain\PhysicalColumnBlueprint;
use Kumwe\App\BusinessSchema\Domain\PhysicalForeignKeyBlueprint;
use Kumwe\App\BusinessSchema\Domain\PhysicalIndexBlueprint;
use Kumwe\App\BusinessSchema\Domain\PhysicalNameCompiler;
use Kumwe\App\BusinessSchema\Domain\PhysicalSchemaBlueprint;
use Kumwe\App\BusinessSchema\Domain\PhysicalTableBlueprint;
use Kumwe\App\BusinessSchema\Domain\PhysicalTableKind;
use Kumwe\App\BusinessSchema\Domain\SchemaEvolutionHints;
use Kumwe\Context\Value\SiteContext;
use Kumwe\Sequence\Value\NumberSequenceFormat;

/**
 * Compiles immutable definition metadata into a portable, canonical physical blueprint.
 *
 * This is the only implementation of the compiler port, and it is what makes a schema plan bindable: one
 * published definition compiled twice in the same site yields the same tables, columns, indexes and keys,
 * so the planner, the approving operator and the executor can compare checksums instead of structures.
 * Determinism is bought deliberately. Fields and relationships are walked in handle order, every emitted
 * collection is sorted before a blueprint sees it, and physical identifiers come from
 * `PhysicalNameCompiler` rather than from anything positional.
 *
 * What it emits stays inside the portable Doctrine subset the supported engines agree on: one record
 * table per definition, a junction or line table for each collection relationship this side has to
 * materialize, a line table for each `core.ordered_lines` field, tenancy columns leading every generated
 * index so a unique field is unique within its scope, and a covering index behind every foreign key so
 * introspection reads alike everywhere. Relationship and reference targets are resolved through the
 * definition catalog at the version the source's evolution hints pin, never at whatever happens to be
 * published at the moment of compilation.
 *
 * @since  2.0.0
 */
final readonly class CanonicalDefinitionPhysicalSchemaCompiler implements DefinitionPhysicalSchemaCompiler
{
    /**
     * Wire the compiler to the catalog, field-type resolver and name compiler it reads through.
     *
     * @param  BusinessDefinitionRepository  $definitions  Catalog every relationship and reference target
     *         is resolved through, within the site being compiled.
     * @param  FieldTypeDefinitionResolver   $fieldTypes   Resolver consulted for the storage kind of a
     *         field type this compiler has no native mapping for.
     * @param  PhysicalNameCompiler          $names        Compiler turning logical handles into the
     *         prefixed, length-bounded identifiers that are installed.
     *
     * @since  2.0.0
     */
    public function __construct(
        private BusinessDefinitionRepository $definitions,
        private FieldTypeDefinitionResolver $fieldTypes,
        private PhysicalNameCompiler $names,
    ) {
    }

    /**
     * Compile every physical table one published definition version installs.
     *
     * The definition's own record table is always emitted. A collection relationship this side has to
     * materialize adds a junction or line table, an ordered-line field adds a line table, and a singular
     * relationship adds nothing here because it lives as a column on the record table instead. Tables are
     * ordered by logical name before they are handed over, so the checksum an operator approved does not
     * move because a later edit rearranged the definition's declarations.
     *
     * @param   EntityTypeDefinition  $definition  Published definition version to compile; a draft is
     *          refused, as is one belonging to another site.
     * @param   SiteContext           $site        Site owning the definition, and the site every
     *          relationship and reference target is resolved in.
     *
     * @return  PhysicalSchemaBlueprint  The tables, carrying the definition's version and checksum so a
     *          later stage can prove it is looking at the same bytes.
     *
     * @throws  InvalidBusinessSchema  When the definition belongs to another site or has no published
     *          version, an ordered-line field declares no string target, a target definition is
     *          unavailable or not published at the pinned version, or a compiled table breaks a
     *          physical-schema rule.
     * @throws  \Kumwe\App\BusinessDefinition\Domain\InvalidBusinessDefinition  When a field names a type
     *          the resolver cannot produce, or the definition's own canonical document cannot be encoded.
     *
     * @since   2.0.0
     */
    public function compile(EntityTypeDefinition $definition, SiteContext $site): PhysicalSchemaBlueprint
    {
        if ($definition->siteIdentifier !== $site->identifier() || $definition->definitionVersion < 1) {
            throw new InvalidBusinessSchema('Only a published definition in the requested site can be compiled.');
        }

        $record = $this->recordTable($definition, $site);
        $tables = [$record];
        foreach ($this->sortedRelationships($definition) as $relationship) {
            $target = $this->targetFor($definition, $site, $relationship->target);
            if (
                !$this->materializes($definition, $target, $relationship)
                || in_array($relationship->kind, [
                    RelationshipKind::OneToOne,
                    RelationshipKind::ManyToOne,
                    RelationshipKind::Reversal,
                ], true)
            ) {
                continue;
            }
            $tables[] = $relationship->kind === RelationshipKind::OwnedLineCollection
                ? $this->lineTable($definition, $target, $relationship->handle)
                : $this->junctionTable($definition, $target, $relationship);
        }
        foreach ($this->sortedFields($definition) as $field) {
            if ($field->type !== 'core.ordered_lines') {
                continue;
            }
            $target = $field->configuration['target'] ?? null;
            if (!is_string($target)) {
                throw new InvalidBusinessSchema('An ordered-line field requires a declared target definition.');
            }
            $tables[] = $this->lineTable(
                $definition,
                $this->targetFor($definition, $site, $target),
                $field->handle,
            );
        }
        usort(
            $tables,
            static fn (PhysicalTableBlueprint $left, PhysicalTableBlueprint $right): int =>
                strcmp($left->logicalName, $right->logicalName),
        );

        return new PhysicalSchemaBlueprint(
            $definition->id,
            $definition->definitionVersion,
            $definition->checksum(),
            $tables,
        );
    }

    /**
     * Compile the table the definition's own records live in.
     *
     * Everything a record needs sits here: the runtime control columns, one or more columns for each
     * stored field, and a target column for each singular relationship this side materializes. A UUID
     * identity field, an ordered-line field and a virtually computed field each get no column, and a field
     * handle that would collide with a control column is refused rather than left to overwrite runtime
     * state. A unique or indexed field gets an index led by the tenancy columns, so uniqueness holds
     * within a site or an organization rather than across the installation, and an entity reference gets a
     * column typed from its target's identity plus a foreign key onto it.
     *
     * A singular relationship becomes a target column that is nullable unless the relationship is
     * required, with its own scoped index, and its foreign key is pinned to `RESTRICT` whatever delete
     * behaviour was declared: `BusinessRecordService` performs the clearing itself so the source record
     * still receives an optimistic-version bump, a revision and audit evidence.
     *
     * @param   EntityTypeDefinition  $definition  Definition whose fields, relationships, scope, workflow
     *          binding and soft-delete flag decide the table's shape.
     * @param   SiteContext           $site        Site in which reference and relationship targets are
     *          resolved.
     *
     * @return  PhysicalTableBlueprint  The `record` table, with its columns, indexes and keys sorted.
     *
     * @throws  InvalidBusinessSchema  When the definition carries no single valid identity field, a field
     *          handle collides with a control column, an entity-reference or relationship target is
     *          missing or unpublished, an indexed field has storage no engine can index whole, or the
     *          assembled table breaks a physical-schema rule.
     *
     * @since   2.0.0
     */
    private function recordTable(EntityTypeDefinition $definition, SiteContext $site): PhysicalTableBlueprint
    {
        $physicalTable = $this->names->entityTable($definition->id, $definition->handle);
        $columns = $this->recordControlColumns($definition);
        $indexes = [];
        $foreignKeys = [];
        $identityField = $this->identityField($definition);

        foreach ($this->sortedFields($definition) as $field) {
            if (
                ($field === $identityField && $definition->identityStrategy === IdentityStrategy::Uuid)
                || $field->type === 'core.ordered_lines'
                || ($field->computed && $field->computationMode === ComputationMode::Virtual)
            ) {
                continue;
            }
            $this->assertFieldHandleAvailable($field, [
                'record_id', 'definition_version', 'site_identifier', 'organization_identifier', 'version',
                'workflow_state', 'created_by', 'created_at', 'updated_by', 'updated_at', 'archived_by',
                'archived_at', 'deleted_by', 'deleted_at',
            ]);
            $referenceIdentity = null;
            if ($field->type === 'core.entity_reference') {
                $targetHandle = $field->configuration['target'] ?? null;
                if (!is_string($targetHandle)) {
                    throw new InvalidBusinessSchema('An entity-reference field requires a declared target.');
                }
                $referenceIdentity = $this->identityColumnFor($this->targetFor(
                    $definition,
                    $site,
                    $targetHandle,
                ));
            }
            $fieldColumns = $this->fieldColumns($field, $referenceIdentity);
            array_push($columns, ...$fieldColumns);
            $fieldPhysicalColumns = array_map(
                static fn (PhysicalColumnBlueprint $column): string => $column->physicalName,
                $fieldColumns,
            );
            $indexedColumns = [
                ...$this->scopePhysicalColumns($columns, $definition),
                ...$fieldPhysicalColumns,
            ];
            if ($field->unique || $field->indexed) {
                $this->assertIndexable($field, $fieldColumns);
                $logical = 'field.' . $field->handle;
                $indexes[] = new PhysicalIndexBlueprint(
                    $logical,
                    $this->names->index($physicalTable, $logical, $indexedColumns),
                    $indexedColumns,
                    $field->unique,
                );
            }
            if ($field->type === 'core.entity_reference') {
                $targetHandle = $field->configuration['target'] ?? null;
                if (!is_string($targetHandle)) {
                    throw new InvalidBusinessSchema('An entity-reference field requires a declared target.');
                }
                $target = $this->targetFor($definition, $site, $targetHandle);
                $targetIdentity = $this->identityColumnFor($target);
                $foreignKeys[] = new PhysicalForeignKeyBlueprint(
                    'field.' . $field->handle,
                    $this->names->foreignKey($physicalTable, 'field.' . $field->handle, $fieldPhysicalColumns),
                    $fieldPhysicalColumns,
                    $this->names->entityTable($target->id, $target->handle),
                    [$targetIdentity->physicalName],
                );
            }
        }

        foreach ($this->sortedRelationships($definition) as $relationship) {
            $target = $this->targetFor($definition, $site, $relationship->target);
            if (
                !$this->materializes($definition, $target, $relationship)
                || !in_array($relationship->kind, [
                    RelationshipKind::OneToOne,
                    RelationshipKind::ManyToOne,
                    RelationshipKind::Reversal,
                ], true)
            ) {
                continue;
            }
            $targetIdentity = $this->identityColumnFor($target);
            $logical = 'relation:' . $relationship->handle . '.target_id';
            $local = new PhysicalColumnBlueprint(
                $logical,
                $this->names->column($logical),
                $targetIdentity->doctrineType,
                $targetIdentity->options,
                !$relationship->required,
            );
            $columns[] = $local;
            $indexes[] = new PhysicalIndexBlueprint(
                'relation.' . $relationship->handle,
                $this->names->index($physicalTable, 'relation.' . $relationship->handle, [
                    ...$this->scopePhysicalColumns($columns, $definition),
                    $local->physicalName,
                ]),
                [...$this->scopePhysicalColumns($columns, $definition), $local->physicalName],
                $relationship->kind === RelationshipKind::OneToOne || $relationship->unique,
            );
            $foreignKeys[] = new PhysicalForeignKeyBlueprint(
                'relation.' . $relationship->handle,
                $this->names->foreignKey(
                    $physicalTable,
                    'relation.' . $relationship->handle,
                    [$local->physicalName],
                ),
                [$local->physicalName],
                $this->names->entityTable($target->id, $target->handle),
                [$targetIdentity->physicalName],
                // Singular set-null is executed by BusinessRecordService so the source receives
                // optimistic-version, revision, and audit evidence. The physical FK must restrict
                // inactive or otherwise uncoordinated source rows from being changed implicitly.
                'RESTRICT',
            );
        }

        // Every emitted index is led by the scope columns, so a definition declaring at least one indexed
        // field or materialized relationship already reaches its rows through the scope. A definition
        // declaring neither used to compile a table with only its primary key, and every policy-filtered
        // page over it was a full scan the moment the installation grew — which is what the declared
        // hot-plan gate now refuses. The bare scope index is emitted only when nothing else leads with
        // the scope, so already-indexed installations compile to the byte-identical blueprint they did.
        $scopeColumns = $this->scopePhysicalColumns($columns, $definition);
        if ($scopeColumns !== []) {
            $scoped = false;
            foreach ($indexes as $index) {
                if ($this->leftPrefix($index->columns, $scopeColumns)) {
                    $scoped = true;
                    break;
                }
            }
            if (!$scoped) {
                $indexes[] = new PhysicalIndexBlueprint(
                    'scope',
                    $this->names->index($physicalTable, 'scope', $scopeColumns),
                    $scopeColumns,
                    false,
                );
            }
        }
        $this->sortColumns($columns);
        $identity = $this->column($columns, 'record_id');
        $this->ensureForeignKeyIndexes(
            $physicalTable,
            $indexes,
            $foreignKeys,
        );
        $this->sortIndexes($indexes);
        $this->sortForeignKeys($foreignKeys);

        return new PhysicalTableBlueprint(
            'record',
            $physicalTable,
            PhysicalTableKind::Entity,
            $columns,
            [$identity->physicalName],
            $indexes,
            $foreignKeys,
            [
                'definition_handle' => $definition->handle,
                'identity_field' => $identityField->handle,
                'identity_strategy' => $definition->identityStrategy->value,
                'scope' => $definition->scope->value,
                'soft_delete' => $definition->softDeleteEnabled,
            ],
        );
    }

    /**
     * Build the runtime-managed columns every record table carries.
     *
     * Identity, definition version, optimistic version and the created and updated audit stamps are
     * unconditional. The tenancy columns follow the declared scope mode, `workflow_state` appears only
     * when the definition binds a workflow, and the delete stamps only when soft delete is enabled, so a
     * definition never installs a column its runtime would leave permanently empty.
     *
     * @param   EntityTypeDefinition  $definition  Definition whose scope, workflow binding and soft-delete
     *          flag decide which optional columns are present.
     *
     * @return  list<PhysicalColumnBlueprint>  The control columns, unsorted; the caller orders them once
     *          the field columns have been added.
     *
     * @since   2.0.0
     */
    private function recordControlColumns(EntityTypeDefinition $definition): array
    {
        $columns = [
            $this->control('record_id', 'guid'),
            $this->control('definition_version', 'integer'),
            $this->control('version', 'integer', ['default' => 1]),
            $this->control('created_by', 'string', ['length' => 191]),
            $this->control('created_at', 'datetime_immutable'),
            $this->control('updated_by', 'string', ['length' => 191]),
            $this->control('updated_at', 'datetime_immutable'),
        ];
        if (in_array($definition->scope, [ScopeMode::Site, ScopeMode::SiteOrganization], true)) {
            $columns[] = $this->control('site_identifier', 'string', ['length' => 191]);
        }
        if (in_array($definition->scope, [ScopeMode::Organization, ScopeMode::SiteOrganization], true)) {
            $columns[] = $this->control('organization_identifier', 'string', ['length' => 191]);
        }
        if ($definition->workflow !== null) {
            $columns[] = $this->control('workflow_state', 'string', ['length' => 63]);
        }
        $columns[] = $this->control('archived_by', 'string', ['length' => 191], true);
        $columns[] = $this->control('archived_at', 'datetime_immutable', [], true);
        if ($definition->softDeleteEnabled) {
            $columns[] = $this->control('deleted_by', 'string', ['length' => 191], true);
            $columns[] = $this->control('deleted_at', 'datetime_immutable', [], true);
        }

        return $columns;
    }

    /**
     * Compile the link table that carries a relationship neither record table can hold in a column.
     *
     * Rows are the pairs themselves: the source and target record identities form the primary key, and the
     * source's tenancy columns come along so a link is scoped exactly as the record that owns it. The
     * target index is unique when only one source may claim a target, an ordered relationship gains a
     * second unique index over source and position, and deleting a source cascades its links away while
     * the target side installs the delete behaviour the relationship declared.
     *
     * @param   EntityTypeDefinition    $source        Definition declaring the relationship, which also
     *          supplies the table's tenancy columns.
     * @param   EntityTypeDefinition    $target        Definition on the other side, whose record table the
     *          target column points at.
     * @param   RelationshipDefinition  $relationship  Relationship being materialized; its kind,
     *          uniqueness, ordering and delete behaviour shape the table.
     *
     * @return  PhysicalTableBlueprint  The junction table, named `relation:` followed by the handle.
     *
     * @throws  InvalidBusinessSchema  When a generated identifier is not portable, or the assembled table
     *          breaks a physical-schema rule.
     *
     * @since   2.0.0
     */
    private function junctionTable(
        EntityTypeDefinition $source,
        EntityTypeDefinition $target,
        RelationshipDefinition $relationship,
    ): PhysicalTableBlueprint {
        $logical = 'relation:' . $relationship->handle;
        $physicalTable = $this->names->relationTable($source->id, $relationship->handle);
        $sourceIdentity = $this->identityColumnFor($source);
        $targetIdentity = $this->identityColumnFor($target);
        $columns = [
            $this->typedControl('source_id', $sourceIdentity),
            $this->typedControl('target_id', $targetIdentity),
            $this->control('position', 'integer', [], !$relationship->ordered),
            $this->control('version', 'integer', ['default' => 1]),
            $this->control('created_by', 'string', ['length' => 191]),
            $this->control('created_at', 'datetime_immutable'),
            $this->control('updated_by', 'string', ['length' => 191]),
            $this->control('updated_at', 'datetime_immutable'),
            ...$this->scopeControlColumns($source),
        ];
        $this->sortColumns($columns);
        $sourceColumn = $this->column($columns, 'source_id');
        $targetColumn = $this->column($columns, 'target_id');
        $indexes = [new PhysicalIndexBlueprint(
            'target',
            $this->names->index($physicalTable, 'target', [
                ...$this->scopePhysicalColumns($columns, $source),
                $targetColumn->physicalName,
            ]),
            [...$this->scopePhysicalColumns($columns, $source), $targetColumn->physicalName],
            $relationship->kind === RelationshipKind::OneToMany || $relationship->unique,
        )];
        if ($relationship->ordered) {
            $position = $this->column($columns, 'position');
            $indexes[] = new PhysicalIndexBlueprint(
                'position',
                $this->names->index($physicalTable, 'position', [
                    ...$this->scopePhysicalColumns($columns, $source),
                    $sourceColumn->physicalName,
                    $position->physicalName,
                ]),
                [
                    ...$this->scopePhysicalColumns($columns, $source),
                    $sourceColumn->physicalName,
                    $position->physicalName,
                ],
                true,
            );
        }
        $foreignKeys = [
            new PhysicalForeignKeyBlueprint(
                'source',
                $this->names->foreignKey($physicalTable, 'source', [$sourceColumn->physicalName]),
                [$sourceColumn->physicalName],
                $this->names->entityTable($source->id, $source->handle),
                [$sourceIdentity->physicalName],
                'CASCADE',
            ),
            new PhysicalForeignKeyBlueprint(
                'target',
                $this->names->foreignKey($physicalTable, 'target', [$targetColumn->physicalName]),
                [$targetColumn->physicalName],
                $this->names->entityTable($target->id, $target->handle),
                [$targetIdentity->physicalName],
                $this->deleteAction($relationship->onDelete),
            ),
        ];
        $this->ensureForeignKeyIndexes(
            $physicalTable,
            $indexes,
            $foreignKeys,
        );
        $this->sortIndexes($indexes);
        $this->sortForeignKeys($foreignKeys);

        return new PhysicalTableBlueprint(
            $logical,
            $physicalTable,
            PhysicalTableKind::Junction,
            $columns,
            [$sourceColumn->physicalName, $targetColumn->physicalName],
            $indexes,
            $foreignKeys,
            ['relationship_kind' => $relationship->kind->value, 'target_definition_id' => $target->id],
        );
    }

    /**
     * Compile the child table that holds one owner's ordered lines.
     *
     * A line is not an independent record. It is keyed by a `line_id` of its own, carries the owner's
     * identity and tenancy columns, cascades away with the owner, and is kept in a single dense order by a
     * unique index over owner and position. The line definition's fields become columns of this table
     * rather than of a table of their own, under the same rules the record table applies but measured
     * against this table's control columns; an entity reference declared on a line is resolved in the line
     * definition's own site and given its own foreign key.
     *
     * @param   EntityTypeDefinition  $owner           Definition the lines hang from, supplying the owner
     *          identity column and the tenancy columns.
     * @param   EntityTypeDefinition  $lineDefinition  Definition describing one line, whose fields become
     *          this table's columns.
     * @param   string                $handle          Relationship or ordered-line field handle the
     *          collection is known by, which the table name is compiled from.
     *
     * @return  PhysicalTableBlueprint  The line table, named `line:` followed by that handle.
     *
     * @throws  InvalidBusinessSchema  When the line definition carries no single valid identity field, a
     *          line field handle collides with a control column, a referenced target is missing or
     *          unpublished, an indexed line field has storage no engine can index whole, or the assembled
     *          table breaks a physical-schema rule.
     *
     * @since   2.0.0
     */
    private function lineTable(
        EntityTypeDefinition $owner,
        EntityTypeDefinition $lineDefinition,
        string $handle,
    ): PhysicalTableBlueprint {
        $logical = 'line:' . $handle;
        $physicalTable = $this->names->lineTable($owner->id, $handle);
        $ownerIdentity = $this->identityColumnFor($owner);
        $lineIdentity = $this->identityField($lineDefinition);
        $columns = [
            $this->typedControl('owner_id', $ownerIdentity),
            $this->control(
                'line_id',
                'guid',
            ),
            $this->control('position', 'integer'),
            $this->control('version', 'integer', ['default' => 1]),
            $this->control('created_by', 'string', ['length' => 191]),
            $this->control('created_at', 'datetime_immutable'),
            $this->control('updated_by', 'string', ['length' => 191]),
            $this->control('updated_at', 'datetime_immutable'),
            ...$this->scopeControlColumns($owner),
        ];
        $indexes = [];
        $fieldForeignKeys = [];
        foreach ($this->sortedFields($lineDefinition) as $field) {
            if (
                ($field === $lineIdentity && $lineDefinition->identityStrategy === IdentityStrategy::Uuid)
                || $field->type === 'core.ordered_lines'
                || ($field->computed && $field->computationMode === ComputationMode::Virtual)
            ) {
                continue;
            }
            $this->assertFieldHandleAvailable($field, [
                'line_id', 'owner_id', 'site_identifier', 'organization_identifier', 'position', 'version',
                'created_by', 'created_at', 'updated_by', 'updated_at',
            ]);
            $referenceIdentity = null;
            $referenceTarget = null;
            if ($field->type === 'core.entity_reference') {
                $targetHandle = $field->configuration['target'] ?? null;
                if (!is_string($targetHandle)) {
                    throw new InvalidBusinessSchema('A line entity-reference field requires a declared target.');
                }
                $referenceTarget = $this->targetFor(
                    $lineDefinition,
                    SiteContext::fromString($lineDefinition->siteIdentifier),
                    $targetHandle,
                );
                $referenceIdentity = $this->identityColumnFor($referenceTarget);
            }
            $fieldColumns = $this->fieldColumns($field, $referenceIdentity);
            array_push($columns, ...$fieldColumns);
            if ($referenceTarget !== null && $referenceIdentity !== null) {
                $physicalColumns = array_map(
                    static fn (PhysicalColumnBlueprint $column): string => $column->physicalName,
                    $fieldColumns,
                );
                $fieldForeignKeys[] = new PhysicalForeignKeyBlueprint(
                    'field.' . $field->handle,
                    $this->names->foreignKey($physicalTable, 'field.' . $field->handle, $physicalColumns),
                    $physicalColumns,
                    $this->names->entityTable($referenceTarget->id, $referenceTarget->handle),
                    [$referenceIdentity->physicalName],
                );
            }
            if ($field->unique || $field->indexed) {
                $this->assertIndexable($field, $fieldColumns);
                $physical = [
                    ...$this->scopePhysicalColumns($columns, $owner),
                    ...array_map(
                        static fn (PhysicalColumnBlueprint $column): string => $column->physicalName,
                        $fieldColumns,
                    ),
                ];
                $indexLogical = 'field.' . $field->handle;
                $indexes[] = new PhysicalIndexBlueprint(
                    $indexLogical,
                    $this->names->index($physicalTable, $indexLogical, $physical),
                    $physical,
                    $field->unique,
                );
            }
        }
        $this->sortColumns($columns);
        $lineId = $this->column($columns, 'line_id');
        $ownerId = $this->column($columns, 'owner_id');
        $position = $this->column($columns, 'position');
        $indexes[] = new PhysicalIndexBlueprint(
            'owner_position',
            $this->names->index($physicalTable, 'owner_position', [
                ...$this->scopePhysicalColumns($columns, $owner),
                $ownerId->physicalName,
                $position->physicalName,
            ]),
            [
                ...$this->scopePhysicalColumns($columns, $owner),
                $ownerId->physicalName,
                $position->physicalName,
            ],
            true,
        );
        $foreignKeys = [new PhysicalForeignKeyBlueprint(
            'owner',
            $this->names->foreignKey($physicalTable, 'owner', [$ownerId->physicalName]),
            [$ownerId->physicalName],
            $this->names->entityTable($owner->id, $owner->handle),
            [$ownerIdentity->physicalName],
            'CASCADE',
        ), ...$fieldForeignKeys];
        $this->ensureForeignKeyIndexes(
            $physicalTable,
            $indexes,
            $foreignKeys,
        );
        $this->sortIndexes($indexes);
        $this->sortForeignKeys($foreignKeys);

        return new PhysicalTableBlueprint(
            $logical,
            $physicalTable,
            PhysicalTableKind::OwnedLine,
            $columns,
            [$lineId->physicalName],
            $indexes,
            $foreignKeys,
            [
                'target_definition_id' => $lineDefinition->id,
                'target_definition_version' => $lineDefinition->definitionVersion,
                'target_identity_field' => $lineIdentity->handle,
            ],
        );
    }

    /**
     * Map one field onto the columns that store its value.
     *
     * Most types compile to a single column, but a composite value spreads over one column per component
     * (amount and currency, amount and unit, instant and timezone, and the four an encrypted secret
     * needs), which is why the answer is a list. Nullability is decided once for the whole field, and a
     * default that survived validation is folded into the options of the column it belongs to.
     *
     * @param   FieldDefinition           $field              Field to map; its type selects the shape and
     *          its length, precision and scale bound it.
     * @param   ?PhysicalColumnBlueprint  $referenceIdentity  Identity column of the referenced entity,
     *          supplied only for `core.entity_reference` so the local column copies its exact storage.
     *
     * @return  list<PhysicalColumnBlueprint>  One column for a scalar field, several for a composite
     *          value, in the order the compiler appends them.
     *
     * @throws  InvalidBusinessSchema  When an entity reference arrives without its target identity, an
     *          exact numeric declares no precision and scale, a stored formula has no portable result
     *          type, a custom type has no portable storage mapping, or a declared default is not exactly
     *          expressible.
     *
     * @since   2.0.0
     */
    private function fieldColumns(
        FieldDefinition $field,
        ?PhysicalColumnBlueprint $referenceIdentity = null,
    ): array {
        $nullable = !$field->required || $field->nullable;
        $physicalDefaults = $this->physicalDefaults($field);
        $column = function (
            string $logical,
            string $type,
            array $options = [],
            ?bool $isNullable = null,
        ) use (
            $physicalDefaults,
            $nullable
        ): PhysicalColumnBlueprint {
            /** @var array<string, mixed> $options */
            return new PhysicalColumnBlueprint(
                $logical,
                $this->names->column($logical),
                $type,
                [
                    ...(array_key_exists($logical, $physicalDefaults)
                        ? ['default' => $physicalDefaults[$logical]]
                        : []),
                    ...$options,
                ],
                $isNullable ?? $nullable,
            );
        };

        return match ($field->type) {
            'core.uuid', 'core.media_reference' => [$column($field->handle, 'guid')],
            'core.entity_reference' => [$column(
                $field->handle,
                $referenceIdentity->doctrineType
                    ?? throw new InvalidBusinessSchema('An entity-reference storage mapping has no target identity.'),
                $referenceIdentity->options,
            )],
            'core.reference_identity' => [$column($field->handle, 'string', [
                'length' => min($field->length ?? 191, 191),
            ])],
            'core.text', 'core.email', 'core.phone', 'core.enum', 'core.sequence' => [$column(
                $field->handle,
                'string',
                ['length' => $this->stringLength($field)],
            )],
            'core.computed' => [$this->computedColumn($field, $column)],
            'core.rich_text', 'core.url' => [$column($field->handle, 'text')],
            'core.integer' => [$column($field->handle, 'integer')],
            'core.decimal' => [$column($field->handle, 'decimal', $this->decimalOptions($field))],
            'core.money' => [
                $column($field->handle . '.amount', 'decimal', $this->decimalOptions($field)),
                $column($field->handle . '.currency', 'ascii_string', ['length' => 3, 'fixed' => true]),
            ],
            'core.quantity' => [
                $column($field->handle . '.amount', 'decimal', $this->decimalOptions($field)),
                $column($field->handle . '.unit', 'string', ['length' => 63]),
            ],
            'core.boolean' => [$column($field->handle, 'boolean')],
            'core.date' => [$column($field->handle, 'date_immutable')],
            'core.local_time' => [$column($field->handle, 'time_immutable')],
            'core.instant' => [$column($field->handle, 'datetime_immutable')],
            'core.zoned_datetime' => [
                $column($field->handle . '.instant', 'datetime_immutable'),
                $column($field->handle . '.timezone', 'ascii_string', ['length' => 64]),
            ],
            'core.embedded_value', 'core.bounded_json' => [$column($field->handle, 'json')],
            'core.secret' => [
                $column($field->handle . '.ciphertext', 'blob'),
                $column($field->handle . '.nonce', 'binary', ['length' => 24, 'fixed' => true]),
                $column($field->handle . '.key_id', 'string', ['length' => 191]),
                $column($field->handle . '.algorithm', 'ascii_string', ['length' => 32]),
            ],
            default => [$this->customColumn($field, $column)],
        };
    }

    /**
     * Resolve the database defaults a field's declared default compiles to.
     *
     * Only types whose values a DDL default reproduces exactly get one at all: a reference, a JSON or
     * value-object payload, TEXT storage and custom storage are left to the record service, because those
     * defaults are not portable across all three engines. A composite default has to supply its exact
     * component document, and every component is measured against its own grammar, so a currency, a unit,
     * an instant or a timezone cannot reach DDL malformed. An encrypted secret is refused outright rather
     * than given a plaintext default.
     *
     * @param   FieldDefinition  $field  Field whose declared default is being compiled.
     *
     * @return  array<string, bool|int|string>  Physical logical column to validated exact default; empty
     *          when the field declares no default, or its type carries none into DDL.
     *
     * @throws  InvalidBusinessSchema  When a secret declares a default, a scalar default is not an exact
     *          bool, int or string, a composite default is not its exact component document, or a
     *          component fails its own grammar.
     *
     * @since   2.0.0
     */
    private function physicalDefaults(FieldDefinition $field): array
    {
        if ($field->default === null) {
            return [];
        }
        if ($field->type === 'core.secret') {
            throw new InvalidBusinessSchema('An encrypted secret can never compile a plaintext database default.');
        }
        if (
            !in_array($field->type, [
            'core.boolean',
            'core.date',
            'core.decimal',
            'core.email',
            'core.enum',
            'core.instant',
            'core.integer',
            'core.local_time',
            'core.money',
            'core.phone',
            'core.quantity',
            'core.text',
            'core.zoned_datetime',
            ], true)
        ) {
            // Defaults for references, JSON/value objects, TEXT, and custom storage are applied by the
            // record service. Emitting those values as DDL defaults is not portable across all three engines.
            return [];
        }
        $components = match ($field->type) {
            'core.money' => ['amount', 'currency'],
            'core.quantity' => ['amount', 'unit'],
            'core.zoned_datetime' => ['instant', 'timezone'],
            default => null,
        };
        if ($components === null) {
            if (!is_bool($field->default) && !is_int($field->default) && !is_string($field->default)) {
                throw new InvalidBusinessSchema('A physical field default must be an exact scalar.');
            }

            return [$field->handle => $field->default];
        }
        if (
            !is_array($field->default) || array_is_list($field->default)
            || count($field->default) !== count($components)
            || array_diff(array_keys($field->default), $components) !== []
        ) {
            throw new InvalidBusinessSchema(
                'A composite field default must contain its exact ordered component document.',
            );
        }
        $result = [];
        foreach ($components as $component) {
            $value = $field->default[$component];
            if (!is_bool($value) && !is_int($value) && !is_string($value)) {
                throw new InvalidBusinessSchema('A composite database default component must be an exact scalar.');
            }
            if ($component === 'amount') {
                $valid = is_string($value)
                    && preg_match('/^-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/D', $value) === 1;
            } elseif ($component === 'currency') {
                $valid = is_string($value) && preg_match('/^[A-Z]{3}$/D', $value) === 1;
            } elseif ($component === 'unit') {
                $valid = is_string($value) && $value !== '' && strlen($value) <= 63;
            } elseif ($component === 'instant') {
                $valid = is_string($value) && strtotime($value) !== false;
            } else {
                $valid = is_string($value) && in_array($value, timezone_identifiers_list(), true);
            }
            if (!$valid) {
                throw new InvalidBusinessSchema('A composite database default component is invalid.');
            }
            $result[$field->handle . '.' . $component] = $value;
        }

        return $result;
    }

    /**
     * Map a field type this compiler has no native rule for onto a portable column.
     *
     * The storage kind the type was registered with is read from the field-type resolver and translated
     * into the Doctrine subset, so an extension-contributed type either installs the same column on every
     * supported engine or is refused. String storage additionally carries the bounded length the field
     * asks for.
     *
     * @param FieldDefinition $field Field whose declared type is being resolved.
     * @param   callable(string, string, array<string, mixed>, ?bool): PhysicalColumnBlueprint  $column
     *          Column factory from `fieldColumns()`, which applies the field's nullability and default.
     *
     * @return  PhysicalColumnBlueprint  The single column the resolved storage kind maps to.
     *
     * @throws  InvalidBusinessSchema  When the registered storage kind has no portable Doctrine mapping.
     * @throws  \Kumwe\App\BusinessDefinition\Domain\InvalidBusinessDefinition  When the field type cannot
     *          be resolved at all.
     *
     * @since   2.0.0
     */
    private function customColumn(FieldDefinition $field, callable $column): PhysicalColumnBlueprint
    {
        $storage = $this->fieldTypes->get($field->type)->storageType;
        $type = match ($storage) {
            'guid' => 'guid',
            'string' => 'string',
            'text' => 'text',
            'integer' => 'integer',
            'boolean' => 'boolean',
            'date' => 'date_immutable',
            'time' => 'time_immutable',
            'datetime' => 'datetime_immutable',
            'json' => 'json',
            default => throw new InvalidBusinessSchema('A field type has no portable physical storage mapping.'),
        };
        $options = $type === 'string' ? ['length' => $this->stringLength($field)] : [];

        return $column($field->handle, $type, $options, null);
    }

    /**
     * Map a stored computed field onto the column its formula result type requires.
     *
     * Only a stored computation reaches here; a virtual one is skipped before any column is built. That is
     * why the formula must declare a result type, and why the type has to be one the portable Doctrine
     * subset can carry. A decimal result additionally takes the field's own precision and scale, so the
     * value rounds the same way on every engine.
     *
     * @param FieldDefinition $field Computed field whose formula result decides the storage.
     * @param   callable(string, string, array<string, mixed>, ?bool): PhysicalColumnBlueprint  $column
     *          Column factory from `fieldColumns()`, which applies the field's nullability and default.
     *
     * @return  PhysicalColumnBlueprint  The single column the formula's result type maps to.
     *
     * @throws  InvalidBusinessSchema  When the formula declares no result type, the result type has no
     *          portable mapping, or a decimal result is missing its precision and scale.
     *
     * @since   2.0.0
     */
    private function computedColumn(FieldDefinition $field, callable $column): PhysicalColumnBlueprint
    {
        $resultType = $field->formula->type
            ?? throw new InvalidBusinessSchema('A stored computed field requires a typed formula.');
        [$type, $options] = match ($resultType) {
            'boolean' => ['boolean', []],
            'integer' => ['integer', []],
            'decimal' => ['decimal', $this->decimalOptions($field)],
            'string' => ['string', ['length' => $this->stringLength($field)]],
            'date' => ['date_immutable', []],
            'time' => ['time_immutable', []],
            'datetime' => ['datetime_immutable', []],
            default => throw new InvalidBusinessSchema('A stored formula result type has no portable DBAL mapping.'),
        };

        return $column($field->handle, $type, $options, null);
    }

    /**
     * Decide the character length a string-backed column is created with.
     *
     * Each type carries both the length used when the field declares none and a ceiling it cannot exceed,
     * so a declared length can never widen a column past what a portable index or an engine row limit
     * tolerates.
     *
     * @param   FieldDefinition  $field  Field whose declared length is being clamped.
     *
     * @return  int  At most 320 for an email address, 191 for a phone number or an enumeration, exactly the
     *          widest number an allocated-number format can render, and 1000 for every other string-backed
     *          type.
     *
     * @since   2.0.0
     */
    private function stringLength(FieldDefinition $field): int
    {
        return match ($field->type) {
            'core.email' => min($field->length ?? 320, 320),
            'core.phone' => min($field->length ?? 64, 191),
            'core.enum' => min($field->length ?? 191, 191),
            'core.sequence' => NumberSequenceFormat::MAXIMUM_LENGTH,
            default => min($field->length ?? 191, 1000),
        };
    }

    /**
     * Read the exact-numeric options a decimal column has to be created with.
     *
     * @param   FieldDefinition  $field  Field expected to declare both precision and scale.
     *
     * @return  array{precision: int, scale: int}
     *
     * @throws  InvalidBusinessSchema  When the field leaves either precision or scale undeclared.
     *
     * @since   2.0.0
     */
    private function decimalOptions(FieldDefinition $field): array
    {
        if ($field->precision === null || $field->scale === null) {
            throw new InvalidBusinessSchema('An exact numeric field requires precision and scale.');
        }

        return ['precision' => $field->precision, 'scale' => $field->scale];
    }

    /**
     * Build one runtime-managed column, named after the control handle itself.
     *
     * Control columns are not author-declared, so the logical name doubles as the handle the physical name
     * is compiled from, and the same handle resolves to the same installed column name in every table it
     * appears in.
     *
     * @param   string                $logical   Control-column handle, such as `record_id` or `position`.
     * @param   string                $type      Doctrine type, from the portable subset.
     * @param   array<string, mixed>  $options   Portable Doctrine options, such as a length or a default.
     * @param   bool                  $nullable  Whether the installed column accepts NULL.
     *
     * @return  PhysicalColumnBlueprint  The column, with its physical name already compiled.
     *
     * @throws  InvalidBusinessSchema  When the handle is not a metadata identifier, or the type and
     *          options are not a portable combination.
     *
     * @since   2.0.0
     */
    private function control(
        string $logical,
        string $type,
        array $options = [],
        bool $nullable = false,
    ): PhysicalColumnBlueprint {
        return new PhysicalColumnBlueprint($logical, $this->names->column($logical), $type, $options, $nullable);
    }

    /**
     * Build a control column that copies another column's exact storage.
     *
     * A key column and the column it points at have to agree on type and options for a foreign key to
     * install at all, so the endpoint columns of a junction or line table are cloned from the identity
     * column of the table they reference instead of being declared a second time.
     *
     * @param   string                   $logical    Handle the copy is installed under, such as
     *          `source_id` or `owner_id`.
     * @param   PhysicalColumnBlueprint  $prototype  Column whose Doctrine type and options are copied.
     *
     * @return  PhysicalColumnBlueprint  A non-nullable column carrying the prototype's storage under the
     *          new handle.
     *
     * @throws  InvalidBusinessSchema  When the handle is not a metadata identifier, or the copied type and
     *          options are not a portable combination.
     *
     * @since   2.0.0
     */
    private function typedControl(string $logical, PhysicalColumnBlueprint $prototype): PhysicalColumnBlueprint
    {
        return $this->control($logical, $prototype->doctrineType, $prototype->options);
    }

    /**
     * Resolve the one field that carries the definition's declared identity.
     *
     * The identity strategy names the type that field must have, and a physical schema is only built on an
     * identity that cannot repeat or move, so a match that is optional, nullable, non-unique or mutable
     * after creation is refused here rather than installed and relied upon later.
     *
     * @param   EntityTypeDefinition  $definition  Definition whose identity strategy selects the field.
     *
     * @return  FieldDefinition  The single `core.uuid` or `core.reference_identity` field the strategy
     *          demands.
     *
     * @throws  InvalidBusinessSchema  When no field or more than one matches the strategy's type, or the
     *          single match is not required, non-null, unique and immutable after create.
     *
     * @since   2.0.0
     */
    private function identityField(EntityTypeDefinition $definition): FieldDefinition
    {
        $type = $definition->identityStrategy === IdentityStrategy::Uuid ? 'core.uuid' : 'core.reference_identity';
        $matches = array_values(array_filter(
            $definition->fields(),
            static fn (FieldDefinition $field): bool => $field->type === $type,
        ));
        if (count($matches) !== 1) {
            throw new InvalidBusinessSchema('A compiled definition requires exactly one identity field.');
        }
        if (
            !$matches[0]->required || $matches[0]->nullable || !$matches[0]->unique
            || !$matches[0]->immutableAfterCreate
        ) {
            throw new InvalidBusinessSchema(
                'A physical schema requires a required, non-null, unique, immutable identity field.',
            );
        }

        return $matches[0];
    }

    /**
     * Describe the column a foreign key into a definition's record table has to point at.
     *
     * Every compiled record table is keyed by the surrogate `record_id` GUID whatever identity strategy
     * its definition declares, so the answer does not vary with the definition supplied; taking one keeps
     * the call sites reading as a property of the table being referenced rather than as a constant.
     *
     * @param   EntityTypeDefinition  $definition  Definition whose record table is being referenced.
     *
     * @return  PhysicalColumnBlueprint  A `record_id` GUID column, matching the primary key every compiled
     *          record table installs.
     *
     * @since   2.0.0
     */
    private function identityColumnFor(EntityTypeDefinition $definition): PhysicalColumnBlueprint
    {
        return $this->control(
            'record_id',
            'guid',
        );
    }

    /**
     * Resolve the published definition version a reference or relationship target compiles against.
     *
     * The catalog entry is consulted first, so an unreachable handle fails by name rather than as a
     * missing version. The version itself comes from the re-pin the source definition declares in its
     * evolution hints, falling back to whichever version the catalog head publishes. That pinning is what
     * keeps a compiled blueprint stable while the target definition goes on being republished.
     *
     * @param   EntityTypeDefinition  $source  Definition being compiled, whose evolution hints decide
     *          which version of the target is pinned.
     * @param   SiteContext           $site    Site both definitions belong to.
     * @param   string                $handle  Namespaced handle of the target definition.
     *
     * @return  EntityTypeDefinition  The target at its pinned version, or at the version the catalog head
     *          serves when the source pins none.
     *
     * @throws  InvalidBusinessSchema  When the site holds no definition under the handle, that version was
     *          never published, or the source declares a malformed schema-evolution hint.
     *
     * @since   2.0.0
     */
    private function targetFor(
        EntityTypeDefinition $source,
        SiteContext $site,
        string $handle,
    ): EntityTypeDefinition {
        $entry = $this->definitions->entry($site, $handle);
        if ($entry === null) {
            throw new InvalidBusinessSchema('A physical relationship target is unavailable: ' . $handle);
        }
        $version = SchemaEvolutionHints::fromDefinition($source)->repin($handle);
        $record = $this->definitions->published($site, $handle, $version);
        if ($record === null) {
            throw new InvalidBusinessSchema('A physical relationship target is not published: ' . $handle);
        }

        return $record->definition;
    }

    /**
     * Decide whether this side of a relationship is the one that emits physical storage.
     *
     * A relationship declared without an inverse, and an owned line collection, always materializes. Where
     * two sides name each other only one may carry the storage, or the pair would install twice: the
     * many-to-one and reversal sides win over their one-to-many partner, and any other pairing is settled
     * by comparing each side's `entity#relationship` handles, so both definitions reach the same verdict
     * independently.
     *
     * @param   EntityTypeDefinition    $source        Definition being compiled.
     * @param   EntityTypeDefinition    $target        Definition on the other side, searched for the
     *          reciprocal relationship.
     * @param   RelationshipDefinition  $relationship  The side being considered.
     *
     * @return  bool  True when this side must emit the column or table; false when the inverse side
     *          already carries it.
     *
     * @throws  InvalidBusinessSchema  When the target declares no relationship matching the named inverse
     *          back to the source.
     *
     * @since   2.0.0
     */
    private function materializes(
        EntityTypeDefinition $source,
        EntityTypeDefinition $target,
        RelationshipDefinition $relationship,
    ): bool {
        if ($relationship->inverse === null || $relationship->kind === RelationshipKind::OwnedLineCollection) {
            return true;
        }
        $inverse = null;
        foreach ($target->relationships() as $candidate) {
            if ($candidate->handle === $relationship->inverse && $candidate->target === $source->handle) {
                $inverse = $candidate;
                break;
            }
        }
        if ($inverse === null) {
            throw new InvalidBusinessSchema('A compiled relationship inverse is unavailable.');
        }
        if (
            in_array($relationship->kind, [RelationshipKind::ManyToOne, RelationshipKind::Reversal], true)
            && $inverse->kind === RelationshipKind::OneToMany
        ) {
            return true;
        }
        if (
            $relationship->kind === RelationshipKind::OneToMany
            && in_array($inverse->kind, [RelationshipKind::ManyToOne, RelationshipKind::Reversal], true)
        ) {
            return false;
        }

        return strcmp(
            $source->handle . '#' . $relationship->handle,
            $target->handle . '#' . $inverse->handle,
        ) < 0;
    }

    /**
     * Build the tenancy columns a generated table copies from the definition it hangs from.
     *
     * A junction or line table is scoped exactly as the records it belongs to, so it installs the same
     * scope columns and leads its indexes with them.
     *
     * @param   EntityTypeDefinition  $definition  Definition whose scope mode decides which identifiers
     *          the generated table carries.
     *
     * @return  list<PhysicalColumnBlueprint>  The site and organization columns the mode names, in that
     *          order; empty under installation scope.
     *
     * @since   2.0.0
     */
    private function scopeControlColumns(EntityTypeDefinition $definition): array
    {
        $columns = [];
        if (in_array($definition->scope, [ScopeMode::Site, ScopeMode::SiteOrganization], true)) {
            $columns[] = $this->control('site_identifier', 'string', ['length' => 191]);
        }
        if (in_array($definition->scope, [ScopeMode::Organization, ScopeMode::SiteOrganization], true)) {
            $columns[] = $this->control('organization_identifier', 'string', ['length' => 191]);
        }

        return $columns;
    }

    /**
     * List the installed names of the tenancy columns an index or key has to lead with.
     *
     * Leading with the scope columns is what makes a unique field unique within its site or organization
     * rather than across the whole installation, and lets the same index serve a scoped lookup.
     *
     * @param   list<PhysicalColumnBlueprint>  $columns     Columns assembled for the table so far, which
     *          must already hold every scope column the mode names.
     * @param   EntityTypeDefinition           $definition  Definition whose scope mode decides which
     *          columns come back.
     *
     * @return  list<string>  Physical names, site before organization; empty under installation scope.
     *
     * @throws  InvalidBusinessSchema  When the assembled columns are missing a scope column the mode
     *          requires.
     *
     * @since   2.0.0
     */
    private function scopePhysicalColumns(array $columns, EntityTypeDefinition $definition): array
    {
        $physical = [];
        foreach (['site_identifier', 'organization_identifier'] as $logical) {
            if (
                ($logical === 'site_identifier'
                    && !in_array($definition->scope, [ScopeMode::Site, ScopeMode::SiteOrganization], true))
                || ($logical === 'organization_identifier'
                    && !in_array($definition->scope, [ScopeMode::Organization, ScopeMode::SiteOrganization], true))
            ) {
                continue;
            }
            $physical[] = $this->column($columns, $logical)->physicalName;
        }

        return $physical;
    }

    /**
     * List the definition's fields in handle order.
     *
     * Compilation walks fields in this order rather than in declaration order, so rearranging a
     * definition's fields cannot move the blueprint a plan was approved against.
     *
     * @param   EntityTypeDefinition  $definition  Definition whose fields are being ordered.
     *
     * @return  list<FieldDefinition>  Every declared field, sorted by handle.
     *
     * @since   2.0.0
     */
    private function sortedFields(EntityTypeDefinition $definition): array
    {
        $fields = $definition->fields();
        usort($fields, static fn (FieldDefinition $left, FieldDefinition $right): int =>
            strcmp($left->handle, $right->handle));

        return $fields;
    }

    /**
     * List the definition's declared relationships in handle order.
     *
     * Both passes over the relationships — the one emitting tables and the one emitting record columns —
     * read this order, so the tables and the columns of a definition are stable across compilations.
     *
     * @param   EntityTypeDefinition  $definition  Definition whose relationships are being ordered.
     *
     * @return  list<RelationshipDefinition>  Every declared relationship, sorted by handle; ordered-line
     *          fields are not folded in.
     *
     * @since   2.0.0
     */
    private function sortedRelationships(EntityTypeDefinition $definition): array
    {
        $relationships = $definition->relationships();
        usort($relationships, static fn (RelationshipDefinition $left, RelationshipDefinition $right): int =>
            strcmp($left->handle, $right->handle));

        return $relationships;
    }

    /**
     * Put a table's collected columns into logical-name order, in place.
     *
     * This is the order the table blueprint stores them in, so sorting before the compiler resolves its
     * key and index columns keeps the two views of the collection identical.
     *
     * @param   list<PhysicalColumnBlueprint>  $columns  Columns collected so far, reordered by reference.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function sortColumns(array &$columns): void
    {
        usort($columns, static fn (PhysicalColumnBlueprint $left, PhysicalColumnBlueprint $right): int =>
            strcmp($left->logicalName, $right->logicalName));
    }

    /**
     * Put a table's collected indexes into logical-name order, in place.
     *
     * Fields, relationships and foreign-key support each contribute indexes at different points, so the
     * collection is ordered once at the end rather than left in the order it happened to be filled.
     *
     * @param   list<PhysicalIndexBlueprint>  $indexes  Indexes collected so far, reordered by reference.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function sortIndexes(array &$indexes): void
    {
        usort($indexes, static fn (PhysicalIndexBlueprint $left, PhysicalIndexBlueprint $right): int =>
            strcmp($left->logicalName, $right->logicalName));
    }

    /**
     * Put a table's collected foreign keys into logical-name order, in place.
     *
     * @param   list<PhysicalForeignKeyBlueprint>  $keys  Constraints collected so far, reordered by
     *          reference.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function sortForeignKeys(array &$keys): void
    {
        usort($keys, static fn (PhysicalForeignKeyBlueprint $left, PhysicalForeignKeyBlueprint $right): int =>
            strcmp($left->logicalName, $right->logicalName));
    }

    /**
     * Append a covering index for every foreign key no existing index already leads with.
     *
     * MySQL and MariaDB otherwise synthesize engine-named indexes for FK columns.
     * Persisting the portable support indexes keeps all three engines' introspection exact.
     *
     * An index whose leading columns are exactly the constraint's local columns already serves it, so a
     * wider scoped index counts as support and nothing is installed twice.
     *
     * @param   string                             $physicalTable  Installed table name the generated index
     *          names are compiled under.
     * @param   list<PhysicalIndexBlueprint>       $indexes        Indexes assembled so far; support indexes are
     *          appended here by reference.
     * @param   list<PhysicalForeignKeyBlueprint>  $foreignKeys    Constraints that each need a covering
     *          index.
     *
     * @return  void
     *
     * @throws  InvalidBusinessSchema  When a generated support index name, or the column list it covers,
     *          is not portable.
     *
     * @since   2.0.0
     */
    private function ensureForeignKeyIndexes(
        string $physicalTable,
        array &$indexes,
        array $foreignKeys,
    ): void {
        foreach ($foreignKeys as $foreignKey) {
            $supported = false;
            foreach ($indexes as $index) {
                if ($this->leftPrefix($index->columns, $foreignKey->localColumns)) {
                    $supported = true;
                    break;
                }
            }
            if ($supported) {
                continue;
            }
            $logical = 'foreign_key.' . $foreignKey->logicalName;
            $indexes[] = new PhysicalIndexBlueprint(
                $logical,
                $this->names->index($physicalTable, $logical, $foreignKey->localColumns),
                $foreignKey->localColumns,
            );
        }
    }

    /**
     * Report whether an index opens with exactly the columns a foreign key needs.
     *
     * An engine may serve a constraint from any index whose leading columns match its local columns, so a
     * wider index counts as support and no further one has to be installed.
     *
     * @param   list<string>  $columns  Physical columns of a candidate index, in index order.
     * @param   list<string>  $prefix   Physical local columns of the constraint, in constraint order.
     *
     * @return  bool  True when the candidate's leading columns are exactly the constraint's columns.
     *
     * @since   2.0.0
     */
    private function leftPrefix(array $columns, array $prefix): bool
    {
        return array_slice($columns, 0, count($prefix)) === $prefix;
    }

    /**
     * Find an assembled column by its logical name.
     *
     * Every call site asks for a column this compiler has just built, so an absent one is a compiler
     * defect rather than a miss, and it is raised as an invalid schema instead of answered with null.
     *
     * @param   list<PhysicalColumnBlueprint>  $columns  Columns assembled for the table so far.
     * @param   string                         $logical  Logical name to resolve, such as `record_id` or a
     *          scope column.
     *
     * @return  PhysicalColumnBlueprint  The matching column.
     *
     * @throws  InvalidBusinessSchema  When no assembled column carries that logical name.
     *
     * @since   2.0.0
     */
    private function column(array $columns, string $logical): PhysicalColumnBlueprint
    {
        foreach ($columns as $column) {
            if ($column->logicalName === $logical) {
                return $column;
            }
        }
        throw new InvalidBusinessSchema('A compiled control column is missing: ' . $logical);
    }

    /**
     * Refuse an index on a field whose storage no supported engine can index whole.
     *
     * A text, blob or JSON column, and any column whose declared length is over 191, is past what a
     * portable full-value index covers, so the field is rejected while it is being compiled rather than
     * installed with an index the supported engines would not agree on.
     *
     * @param   FieldDefinition                $field    Field asking to be unique or indexed; named in the
     *          failure message.
     * @param   list<PhysicalColumnBlueprint>  $columns  Columns that field compiled to.
     *
     * @return  void
     *
     * @throws  InvalidBusinessSchema  When any of those columns cannot carry a portable full-value index.
     *
     * @since   2.0.0
     */
    private function assertIndexable(FieldDefinition $field, array $columns): void
    {
        foreach ($columns as $column) {
            $length = $column->options['length'] ?? null;
            if (in_array($column->doctrineType, ['text', 'blob', 'json'], true) || (is_int($length) && $length > 191)) {
                throw new InvalidBusinessSchema(sprintf(
                    'Field %s cannot have a portable full-value index for its declared storage.',
                    $field->handle,
                ));
            }
        }
    }

    /**
     * Translate a declared delete behaviour into the referential action a foreign key installs.
     *
     * @param   DeleteBehavior  $behavior  Behaviour the relationship declares for its target side.
     *
     * @return  string  `RESTRICT`, `CASCADE` or `SET NULL`, spelled the way every supported engine
     *          accepts it.
     *
     * @since   2.0.0
     */
    private function deleteAction(DeleteBehavior $behavior): string
    {
        return match ($behavior) {
            DeleteBehavior::Restrict => 'RESTRICT',
            DeleteBehavior::Cascade => 'CASCADE',
            DeleteBehavior::SetNull => 'SET NULL',
        };
    }

    /**
     * Refuse a business field whose handle is already taken by a runtime control column.
     *
     * Control columns and field columns are compiled through the same name compiler, so a field claiming a
     * control handle would resolve to the very column the runtime keeps its own state in.
     *
     * @param   FieldDefinition  $field     Field being compiled; its handle is named in the failure.
     * @param   list<string>     $reserved  Control-column handles the table being compiled already uses.
     *
     * @return  void
     *
     * @throws  InvalidBusinessSchema  When the field handle matches one of those reserved handles.
     *
     * @since   2.0.0
     */
    private function assertFieldHandleAvailable(FieldDefinition $field, array $reserved): void
    {
        if (in_array($field->handle, $reserved, true)) {
            throw new InvalidBusinessSchema(
                'Business field ' . $field->handle . ' collides with a physical runtime control column.',
            );
        }
    }
}
