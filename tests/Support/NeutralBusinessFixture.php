<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Support;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Kumwe\App\Kernel\Container;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Extension\Spi\Application\Automation\IdempotencyKey;
use Kumwe\Transaction\Contract\TransactionManager;
use Kumwe\App\BusinessDefinition\Application\BusinessDefinitionService;
use Kumwe\App\BusinessDefinition\Application\PackageDefinitionSynchronizer;
use Kumwe\App\BusinessDefinition\Domain\CanonicalDefinitionJson;
use Kumwe\App\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\App\BusinessDefinition\Domain\FieldDefinition;
use Kumwe\App\BusinessDefinition\Domain\IdentityStrategy;
use Kumwe\App\BusinessRecord\Application\BusinessRecordService;
use Kumwe\App\BusinessRecord\Application\Command\CreateRecordCommand;
use Kumwe\App\BusinessRecord\Application\Command\RelateRecordsCommand;
use Kumwe\App\BusinessRecord\Application\Command\ReorderRecordLinesCommand;
use Kumwe\App\BusinessRecord\Application\RecordMutationResult;
use Kumwe\App\BusinessSchema\Application\BusinessSchemaService;
use Kumwe\App\BusinessSchema\Domain\SchemaInstallationStatus;
use Kumwe\App\BusinessSchema\Domain\SchemaPlanStatus;
use Kumwe\Extension\Spi\BusinessSecurity\Application\FieldAccessUsage;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Ramsey\Uuid\Uuid;
use RuntimeException;

/**
 * Portable, domain-neutral data used by runtime and clean-target restore acceptance.
 *
 * The stable document and record identifiers deliberately make backup verification
 * independent of database-generated values. Integration tests can use document()
 * with a suffix and fresh definition ID to remain safely repeatable.
 */
final class NeutralBusinessFixture
{
    /** @var list<string> Explicit operations the legacy-neutral fixture admits. @since 2.0.0 */
    private const RECORD_OPERATIONS = [
        'business.record.action',
        'business.record.archive',
        'business.record.browse',
        'business.record.create',
        'business.record.delete',
        'business.record.export',
        'business.record.history',
        'business.record.read',
        'business.record.relate',
        'business.record.report',
        'business.record.restore',
        'business.record.update',
    ];

    public const DEFINITION_ID = '0191574f-f0b8-7bf3-a9aa-91c6b8244e10';

    public const HANDLE = 'site.default.neutral_business_record';

    public const RECORD_ID = '0191574f-f0b8-7bf3-a9aa-91c6b8244e11';

    public const SECOND_RECORD_ID = '0191574f-f0b8-7bf3-a9aa-91c6b8244e12';

    public const BACKUP_GRAPH_SUFFIX = 'backupgraph';

    /**
     * Stable suffix the site-owned document fixture installs under, so one pair serves every case.
     *
     * A definition is a schema plan and a bounded per-site resource, so a suite that installs the same
     * document shape once per test method pays for it twice over. Records are kept apart by their own
     * identities instead.
     */
    public const DOCUMENT_SUFFIX = 'aggdoc';

    public const TARGET_DEFINITION_ID = '0191574f-f0b8-7bf3-a9aa-91c6b8244e20';

    public const LINE_DEFINITION_ID = '0191574f-f0b8-7bf3-a9aa-91c6b8244e21';

    public const OWNER_DEFINITION_ID = '0191574f-f0b8-7bf3-a9aa-91c6b8244e22';

    public const TARGET_HANDLE = 'site.default.neutral_target_backupgraph';

    public const LINE_HANDLE = 'site.default.neutral_line_backupgraph';

    public const OWNER_HANDLE = 'site.default.neutral_owner_backupgraph';

    public const TARGET_RECORD_ID = '0191574f-f0b8-7bf3-a9aa-91c6b8244e23';

    public const SECOND_TARGET_RECORD_ID = '0191574f-f0b8-7bf3-a9aa-91c6b8244e24';

    public const OWNER_RECORD_ID = '0191574f-f0b8-7bf3-a9aa-91c6b8244e25';

    public const LINE_RECORD_ID = '0191574f-f0b8-7bf3-a9aa-91c6b8244e26';

    public const SECOND_LINE_RECORD_ID = '0191574f-f0b8-7bf3-a9aa-91c6b8244e27';

    /**
     * Definition handles intentionally shared by separate tests and by later suite processes.
     *
     * These fixtures use stable record or idempotency identities to prove replay and backup behavior. They
     * must therefore remain active between suite passes; every other neutral fixture carries a run-unique
     * suffix and belongs to the transient process scope.
     *
     * @return  list<string>  Stable site-owned handles retained across integration-suite processes.
     *
     * @since  2.0.0
     */
    public static function persistentDefinitionHandles(): array
    {
        return [
            self::HANDLE,
            self::TARGET_HANDLE,
            self::LINE_HANDLE,
            self::OWNER_HANDLE,
            'site.default.doc_line_' . self::DOCUMENT_SUFFIX,
            'site.default.doc_header_' . self::DOCUMENT_SUFFIX,
        ];
    }

    /** @return array<string, mixed> */
    public static function backupDocument(): array
    {
        return self::document();
    }

    /**
     * @return array<string, mixed>
     */
    public static function document(?string $suffix = null, ?string $definitionId = null): array
    {
        if ($suffix !== null && preg_match('/^[a-z0-9]{1,20}$/D', $suffix) !== 1) {
            throw new RuntimeException('A neutral fixture suffix must contain 1 to 20 lowercase letters or digits.');
        }
        if ($suffix !== null && $definitionId === null) {
            throw new RuntimeException('A repeatable fixture document requires an explicit fresh definition ID.');
        }
        $handle = self::HANDLE . ($suffix === null ? '' : '_' . $suffix);

        return [
            'id' => $definitionId ?? self::DEFINITION_ID,
            'owner' => ['type' => 'site', 'identifier' => 'default'],
            'site' => 'default',
            'handle' => $handle,
            'singular_label' => 'Neutral record',
            'plural_label' => 'Neutral records',
            'status' => 'draft',
            'definition_version' => 0,
            'storage_mode' => 'relational',
            'identity_strategy' => 'uuid',
            'scope' => 'site',
            'audit_enabled' => true,
            'revisions_enabled' => true,
            'soft_delete_enabled' => true,
            'fields' => [
                [
                    'handle' => 'id',
                    'label' => 'ID',
                    'type' => 'core.uuid',
                    'required' => true,
                    'nullable' => false,
                    'unique' => true,
                    'indexed' => true,
                    'immutable_after_create' => true,
                    'server_only' => true,
                    'read_only' => true,
                ],
                [
                    'handle' => 'name',
                    'label' => 'Name',
                    'type' => 'core.text',
                    'required' => true,
                    'nullable' => false,
                    'length' => 160,
                    'normalizers' => ['trim', 'unicode_nfc'],
                    'validators' => [
                        ['rule' => 'min_length', 'value' => 2],
                        ['rule' => 'max_length', 'value' => 160],
                    ],
                    'unique' => true,
                    'indexed' => true,
                    'searchable' => true,
                    'filterable' => true,
                    'sortable' => true,
                    'reportable' => true,
                    'exportable' => true,
                ],
                [
                    'handle' => 'status',
                    'label' => 'Status',
                    'type' => 'core.enum',
                    'required' => true,
                    'nullable' => false,
                    'default' => 'draft',
                    'configuration' => ['options' => ['draft', 'ready']],
                    'filterable' => true,
                    'sortable' => true,
                ],
                [
                    'handle' => 'enabled',
                    'label' => 'Enabled',
                    'type' => 'core.boolean',
                    'required' => true,
                    'nullable' => false,
                    'default' => false,
                    'filterable' => true,
                    'sortable' => true,
                ],
                [
                    'handle' => 'evolution_code',
                    'label' => 'Evolution code',
                    'type' => 'core.text',
                    'required' => false,
                    'nullable' => true,
                    'length' => 160,
                    'filterable' => true,
                ],
                [
                    'handle' => 'amount',
                    'label' => 'Amount',
                    'type' => 'core.decimal',
                    'required' => true,
                    'nullable' => false,
                    'precision' => 65,
                    'scale' => 30,
                    'normalizers' => ['decimal_scale'],
                    'validators' => [
                        ['rule' => 'decimal'],
                        ['rule' => 'min', 'value' => '0.000000'],
                    ],
                    'indexed' => true,
                    'filterable' => true,
                    'sortable' => true,
                    'reportable' => true,
                    'exportable' => true,
                ],
                [
                    'handle' => 'price',
                    'label' => 'Price',
                    'type' => 'core.money',
                    'required' => true,
                    'nullable' => false,
                    'precision' => 65,
                    'scale' => 30,
                    'configuration' => ['currency' => 'NAD'],
                    'reportable' => true,
                ],
                [
                    'handle' => 'quantity',
                    'label' => 'Quantity',
                    'type' => 'core.quantity',
                    'required' => true,
                    'nullable' => false,
                    'precision' => 65,
                    'scale' => 30,
                    'configuration' => ['unit' => 'unit'],
                    'reportable' => true,
                ],
                [
                    'handle' => 'service_date',
                    'label' => 'Service date',
                    'type' => 'core.date',
                    'required' => true,
                    'nullable' => false,
                    'filterable' => true,
                    'sortable' => true,
                ],
                [
                    'handle' => 'local_time',
                    'label' => 'Local time',
                    'type' => 'core.local_time',
                    'required' => true,
                    'nullable' => false,
                    'sortable' => true,
                ],
                [
                    'handle' => 'recorded_at',
                    'label' => 'Recorded at',
                    'type' => 'core.instant',
                    'required' => true,
                    'nullable' => false,
                    'filterable' => true,
                    'sortable' => true,
                ],
                [
                    'handle' => 'scheduled_for',
                    'label' => 'Scheduled for',
                    'type' => 'core.zoned_datetime',
                    'required' => true,
                    'nullable' => false,
                ],
                [
                    'handle' => 'credential',
                    'label' => 'Credential',
                    'type' => 'core.secret',
                    'required' => true,
                    'nullable' => false,
                    'sensitivity' => 'secret',
                    'exportable' => false,
                ],
                [
                    'handle' => 'display_name',
                    'label' => 'Display name',
                    'type' => 'core.computed',
                    'length' => 160,
                    'server_only' => true,
                    'computed' => true,
                    'read_only' => true,
                    'formula' => [
                        'op' => 'field',
                        'type' => 'string',
                        'field' => 'name',
                    ],
                    'computation_mode' => 'stored',
                    'searchable' => true,
                    'filterable' => true,
                    'sortable' => true,
                ],
            ],
            'relationships' => [],
            'views' => [[
                'handle' => 'list',
                'label' => 'Neutral records',
                'kind' => 'list',
                'fields' => ['name', 'status', 'amount', 'price', 'quantity', 'service_date'],
                'filters' => ['name', 'status', 'amount', 'service_date'],
                'sorts' => ['name', 'status', 'amount', 'service_date'],
                'administrator' => true,
                'portal' => false,
                'public' => false,
            ]],
            'actions' => [[
                'handle' => 'approve',
                'label' => 'Approve',
                'capability' => 'business.record.action',
                'administrator' => true,
                'portal' => false,
                'public' => false,
                'condition' => [
                    'op' => 'gte',
                    'type' => 'boolean',
                    'args' => [
                        ['op' => 'field', 'type' => 'decimal', 'field' => 'amount'],
                        ['op' => 'literal', 'type' => 'decimal', 'value' => '0.000000'],
                    ],
                ],
                'transition' => 'approve',
            ]],
            'workflow' => [
                'initial_state' => 'draft',
                'states' => ['draft', 'approved'],
                'transitions' => [[
                    'handle' => 'approve',
                    'from' => 'draft',
                    'to' => 'approved',
                    'capability' => 'business.record.action',
                ]],
            ],
            'record_invariants' => [[
                'handle' => 'non_negative_amount',
                'message' => 'Amount must not be negative.',
                'condition' => [
                    'op' => 'gte',
                    'type' => 'boolean',
                    'args' => [
                        ['op' => 'field', 'type' => 'decimal', 'field' => 'amount'],
                        ['op' => 'literal', 'type' => 'decimal', 'value' => '0.000000'],
                    ],
                ],
            ]],
            'compatibility_metadata' => [],
            'administrator_exposure' => true,
            'portal_exposure' => false,
            'public_exposure' => false,
        ];
    }

    /**
     * Version-two draft whose only structural change widens a column every stored value already fits.
     *
     * Nothing is dropped, renamed or transformed, so a plan built from this document must be judged a pure
     * relaxation: rows pinned to version one stay valid under version two and no re-pin is owed.
     *
     * @param   string  $suffix        Lowercase handle suffix keeping repeated fixtures apart.
     * @param   string  $definitionId  Fresh definition UUID this document belongs to.
     *
     * @return  array<string, mixed>  Version-two draft whose only change widens one column.
     *
     * @since   2.0.0
     */
    public static function relaxationDocument(string $suffix, string $definitionId): array
    {
        $document = self::document($suffix, $definitionId);
        foreach ($document['fields'] as &$field) {
            if (!is_array($field) || ($field['handle'] ?? null) !== 'evolution_code') {
                continue;
            }
            $field['length'] = 400;
            $field['validators'] = [['rule' => 'max_length', 'value' => 400]];
        }
        unset($field);

        return $document;
    }

    /**
     * Version-two draft for the explicit schema-evolution acceptance contract.
     *
     * @return array<string, mixed>
     */
    public static function evolutionDocument(?string $suffix = null, ?string $definitionId = null): array
    {
        $document = self::document($suffix, $definitionId);
        foreach ($document['fields'] as &$field) {
            if (!is_array($field)) {
                continue;
            }
            if (($field['handle'] ?? null) === 'status') {
                $field['handle'] = 'lifecycle_status';
                $field['label'] = 'Lifecycle status';
            } elseif (($field['handle'] ?? null) === 'evolution_code') {
                $field['required'] = true;
                $field['nullable'] = false;
            } elseif (($field['handle'] ?? null) === 'service_date') {
                $field['indexed'] = true;
            }
        }
        unset($field);
        foreach ($document['views'] as &$view) {
            if (!is_array($view)) {
                continue;
            }
            foreach (['fields', 'filters', 'sorts'] as $key) {
                if (!is_array($view[$key] ?? null)) {
                    continue;
                }
                $view[$key] = array_map(
                    static fn (mixed $handle): mixed => $handle === 'status' ? 'lifecycle_status' : $handle,
                    $view[$key],
                );
            }
        }
        unset($view);
        $document['compatibility_metadata'] = [
            'column_renames' => ['record/status' => 'lifecycle_status'],
            'backfills' => [
                'evolution_code' => ['expression' => [
                    'op' => 'field',
                    'type' => 'string',
                    'field' => 'name',
                ]],
            ],
            'repins' => [$document['handle'] => 2],
        ];

        return $document;
    }

    /** @return array<string, mixed> */
    public static function recordValues(string $name = 'Backup acceptance record'): array
    {
        return [
            'name' => $name,
            'amount' => '12345678901234567890123456789012345.123456789012345678901234567890',
            'price' => [
                'amount' => '99999999999999999999999999999999999.999999999999999999999999999999',
                'currency' => 'nad',
            ],
            'quantity' => [
                'amount' => '12345678901234567890123456789012345.000000000000000000000000000001',
                'unit' => 'unit',
            ],
            'service_date' => '2026-08-08',
            'local_time' => '13:14:15.123456',
            'recorded_at' => '2026-08-08T11:14:15.123456Z',
            'scheduled_for' => [
                'instant' => '2026-08-08T11:14:15.123456Z',
                'timezone' => 'Africa/Windhoek',
            ],
            'credential' => 'neutral-fixture-secret',
        ];
    }

    /** @return array<string, mixed> */
    public static function evolutionRecordValues(
        string $name = 'Evolved acceptance record',
        string $evolutionCode = 'EVOLVED-001',
    ): array {
        return [...self::recordValues($name), 'evolution_code' => $evolutionCode];
    }

    /** @return array<string, mixed> */
    public static function referenceTargetDocument(string $suffix, string $definitionId): array
    {
        self::assertGraphIdentity($suffix, $definitionId);

        return [
            'id' => $definitionId,
            'owner' => ['type' => 'site', 'identifier' => 'default'],
            'site' => 'default',
            'handle' => 'site.default.neutral_reference_target_' . $suffix,
            'singular_label' => 'Neutral reference target',
            'plural_label' => 'Neutral reference targets',
            'status' => 'draft',
            'definition_version' => 0,
            'storage_mode' => 'relational',
            'identity_strategy' => 'reference',
            'scope' => 'site',
            'audit_enabled' => true,
            'revisions_enabled' => true,
            'soft_delete_enabled' => false,
            'fields' => [
                self::referenceIdentityField(),
                [
                    'handle' => 'label',
                    'label' => 'Label',
                    'type' => 'core.text',
                    'required' => true,
                    'nullable' => false,
                    'length' => 120,
                    'filterable' => true,
                    'sortable' => true,
                ],
            ],
            'relationships' => [],
            'views' => [self::listView('Neutral reference targets', ['code', 'label'])],
            'actions' => [],
            'workflow' => null,
            'compatibility_metadata' => [],
            'administrator_exposure' => true,
            'portal_exposure' => false,
            'public_exposure' => false,
        ];
    }

    /** @return array<string, mixed> */
    public static function entityReferenceOwnerDocument(
        string $suffix,
        string $definitionId,
        string $targetHandle,
    ): array {
        self::assertGraphIdentity($suffix, $definitionId);

        return [
            'id' => $definitionId,
            'owner' => ['type' => 'site', 'identifier' => 'default'],
            'site' => 'default',
            'handle' => 'site.default.neutral_reference_owner_' . $suffix,
            'singular_label' => 'Neutral reference owner',
            'plural_label' => 'Neutral reference owners',
            'status' => 'draft',
            'definition_version' => 0,
            'storage_mode' => 'relational',
            'identity_strategy' => 'uuid',
            'scope' => 'site',
            'audit_enabled' => true,
            'revisions_enabled' => true,
            'soft_delete_enabled' => true,
            'fields' => [
                self::identityField(),
                [
                    'handle' => 'title',
                    'label' => 'Title',
                    'type' => 'core.text',
                    'required' => true,
                    'nullable' => false,
                    'length' => 120,
                    'filterable' => true,
                ],
                [
                    'handle' => 'target_ref',
                    'label' => 'Target reference',
                    'type' => 'core.entity_reference',
                    'required' => true,
                    'nullable' => false,
                    'configuration' => ['target' => $targetHandle],
                    'indexed' => true,
                    'filterable' => true,
                    'sortable' => true,
                ],
            ],
            'relationships' => [],
            'views' => [self::listView('Neutral reference owners', ['title', 'target_ref'])],
            'actions' => [],
            'workflow' => null,
            'compatibility_metadata' => [],
            'administrator_exposure' => true,
            'portal_exposure' => false,
            'public_exposure' => false,
        ];
    }

    /**
     * @return array{left: array<string, mixed>, right: array<string, mixed>}
     */
    public static function inverseRelationshipDocuments(
        string $suffix,
        string $leftDefinitionId,
        string $rightDefinitionId,
        string $extensionIdentifier,
    ): array {
        self::assertGraphIdentity($suffix, $leftDefinitionId);
        self::assertGraphIdentity($suffix, $rightDefinitionId);
        if (preg_match('#^[a-z0-9][a-z0-9._-]*/[a-z0-9][a-z0-9._-]*$#D', $extensionIdentifier) !== 1) {
            throw new RuntimeException('A neutral inverse-graph owner identifier is invalid.');
        }
        $namespace = str_replace('/', '.', $extensionIdentifier);
        $leftHandle = $namespace . '.inverse_left';
        $rightHandle = $namespace . '.inverse_right';

        return [
            'left' => self::inverseRelationshipDocument(
                $leftDefinitionId,
                $extensionIdentifier,
                $leftHandle,
                'Left',
                'rights',
                $rightHandle,
                'lefts',
            ),
            'right' => self::inverseRelationshipDocument(
                $rightDefinitionId,
                $extensionIdentifier,
                $rightHandle,
                'Right',
                'lefts',
                $leftHandle,
                'rights',
            ),
        ];
    }

    /** @return array<string, mixed> */
    public static function relationTargetDocument(string $suffix, string $definitionId): array
    {
        self::assertGraphIdentity($suffix, $definitionId);

        return [
            'id' => $definitionId,
            'owner' => ['type' => 'site', 'identifier' => 'default'],
            'site' => 'default',
            'handle' => 'site.default.neutral_target_' . $suffix,
            'singular_label' => 'Neutral target',
            'plural_label' => 'Neutral targets',
            'status' => 'draft',
            'definition_version' => 0,
            'storage_mode' => 'relational',
            'identity_strategy' => 'uuid',
            'scope' => 'site',
            'audit_enabled' => true,
            'revisions_enabled' => true,
            'fields' => [
                self::identityField(),
                [
                    'handle' => 'label',
                    'label' => 'Label',
                    'type' => 'core.text',
                    'required' => true,
                    'nullable' => false,
                    'length' => 120,
                    'unique' => true,
                    'indexed' => true,
                    'searchable' => true,
                    'filterable' => true,
                    'sortable' => true,
                ],
            ],
            'relationships' => [],
            'views' => [self::listView('Neutral targets', ['label'])],
            'actions' => [],
            'workflow' => null,
            'compatibility_metadata' => [],
            'administrator_exposure' => true,
            'portal_exposure' => false,
            'public_exposure' => false,
        ];
    }

    /** @return array<string, mixed> */
    public static function ownedLineDocument(string $suffix, string $definitionId): array
    {
        self::assertGraphIdentity($suffix, $definitionId);

        return [
            'id' => $definitionId,
            'owner' => ['type' => 'site', 'identifier' => 'default'],
            'site' => 'default',
            'handle' => 'site.default.neutral_line_' . $suffix,
            'singular_label' => 'Neutral line',
            'plural_label' => 'Neutral lines',
            'status' => 'draft',
            'definition_version' => 0,
            'storage_mode' => 'relational',
            'identity_strategy' => 'uuid',
            'scope' => 'site',
            'audit_enabled' => true,
            'revisions_enabled' => true,
            'fields' => [
                self::identityField(),
                [
                    'handle' => 'description',
                    'label' => 'Description',
                    'type' => 'core.text',
                    'required' => true,
                    'nullable' => false,
                    'length' => 160,
                    'filterable' => true,
                ],
                [
                    'handle' => 'units',
                    'label' => 'Units',
                    'type' => 'core.decimal',
                    'required' => true,
                    'nullable' => false,
                    'precision' => 18,
                    'scale' => 3,
                ],
            ],
            'relationships' => [],
            'views' => [self::listView('Neutral lines', ['description', 'units'])],
            'actions' => [],
            'workflow' => null,
            'compatibility_metadata' => [],
            'administrator_exposure' => true,
            'portal_exposure' => false,
            'public_exposure' => false,
        ];
    }

    /**
     * Line entity of the neutral document fixture: a description, a unique code and an exact amount.
     *
     * The code is unique so a test can force a collision partway through a batched insert and prove that
     * a document refused on its nine hundredth line leaves nothing behind.
     *
     * @return array<string, mixed>
     */
    public static function documentLineDocument(
        string $suffix,
        string $definitionId,
        ?string $extensionIdentifier = null,
    ): array {
        self::assertGraphIdentity($suffix, $definitionId);

        return [
            'id' => $definitionId,
            'owner' => self::documentOwner($extensionIdentifier),
            'site' => 'default',
            'handle' => self::documentHandle('doc_line', $suffix, $extensionIdentifier),
            'singular_label' => 'Document line',
            'plural_label' => 'Document lines',
            'status' => 'draft',
            'definition_version' => 0,
            'storage_mode' => 'relational',
            'identity_strategy' => 'uuid',
            'scope' => 'site',
            'audit_enabled' => true,
            'revisions_enabled' => true,
            'fields' => [
                self::identityField(),
                [
                    'handle' => 'code',
                    'label' => 'Code',
                    'type' => 'core.text',
                    'required' => true,
                    'nullable' => false,
                    'length' => 80,
                    'unique' => true,
                    'indexed' => true,
                    'filterable' => true,
                ],
                [
                    'handle' => 'description',
                    'label' => 'Description',
                    'type' => 'core.text',
                    'required' => true,
                    'nullable' => false,
                    'length' => 160,
                    'filterable' => true,
                ],
                [
                    'handle' => 'amount',
                    'label' => 'Amount',
                    'type' => 'core.decimal',
                    'required' => true,
                    'nullable' => false,
                    'precision' => 18,
                    'scale' => 2,
                    'reportable' => true,
                ],
            ],
            'relationships' => [],
            'views' => [self::listView('Document lines', ['code', 'description', 'amount'])],
            'actions' => [],
            'workflow' => null,
            'compatibility_metadata' => [],
            'administrator_exposure' => true,
            'portal_exposure' => false,
            'public_exposure' => false,
        ];
    }

    /**
     * Header entity of the neutral document fixture, optionally declaring the rules that span its lines.
     *
     * With the aggregate invariants declared this is the vertical-neutral shape every document-shaped
     * business object has: a total that must agree with its lines, and a bounded line count. Nothing here
     * names an invoice, an order or any other vertical, which is the point.
     *
     * @return array<string, mixed>
     */
    public static function documentHeaderDocument(
        string $suffix,
        string $definitionId,
        string $lineHandle,
        ?string $extensionIdentifier = null,
        bool $withAggregateInvariants = true,
        int $maximumLines = 1000,
    ): array {
        self::assertGraphIdentity($suffix, $definitionId);
        $document = [
            'id' => $definitionId,
            'owner' => self::documentOwner($extensionIdentifier),
            'site' => 'default',
            'handle' => self::documentHandle('doc_header', $suffix, $extensionIdentifier),
            'singular_label' => 'Document',
            'plural_label' => 'Documents',
            'status' => 'draft',
            'definition_version' => 0,
            'storage_mode' => 'relational',
            'identity_strategy' => 'uuid',
            'scope' => 'site',
            'audit_enabled' => true,
            'revisions_enabled' => true,
            'soft_delete_enabled' => false,
            'fields' => [
                self::identityField(),
                [
                    'handle' => 'title',
                    'label' => 'Title',
                    'type' => 'core.text',
                    'required' => true,
                    'nullable' => false,
                    'length' => 160,
                    'filterable' => true,
                    'sortable' => true,
                ],
                [
                    'handle' => 'total',
                    'label' => 'Total',
                    'type' => 'core.decimal',
                    'required' => true,
                    'nullable' => false,
                    'precision' => 18,
                    'scale' => 2,
                    'reportable' => true,
                ],
            ],
            'relationships' => [[
                'handle' => 'lines',
                'label' => 'Lines',
                'kind' => 'owned_line_collection',
                'target' => $lineHandle,
                'ordered' => true,
                'on_delete' => 'cascade',
            ]],
            'views' => [self::listView('Documents', ['title', 'total'])],
            'actions' => [],
            'workflow' => null,
            'compatibility_metadata' => [],
            'administrator_exposure' => true,
            'portal_exposure' => false,
            'public_exposure' => false,
        ];
        if ($withAggregateInvariants) {
            $document['record_invariants'] = [
                [
                    'handle' => 'total_agrees_with_lines',
                    'message' => 'The document total must equal the sum of its lines.',
                    'condition' => [
                        'op' => 'eq',
                        'type' => 'boolean',
                        'args' => [
                            ['op' => 'field', 'type' => 'decimal', 'field' => 'total'],
                            [
                                'op' => 'line_aggregate',
                                'type' => 'decimal',
                                'lines' => 'lines',
                                'field' => 'amount',
                                'aggregate' => 'sum',
                            ],
                        ],
                    ],
                ],
                [
                    'handle' => 'line_count_within_bounds',
                    'message' => 'The document carries more lines than it admits.',
                    'condition' => [
                        'op' => 'lte',
                        'type' => 'boolean',
                        'args' => [
                            [
                                'op' => 'line_aggregate',
                                'type' => 'integer',
                                'lines' => 'lines',
                                'aggregate' => 'count',
                            ],
                            ['op' => 'literal', 'type' => 'integer', 'value' => $maximumLines],
                        ],
                    ],
                ],
            ];
        }

        return $document;
    }

    /** @return array<string, mixed> */
    public static function relationshipOwnerDocument(
        string $suffix,
        string $definitionId,
        string $targetHandle,
        string $lineHandle,
    ): array {
        self::assertGraphIdentity($suffix, $definitionId);

        return [
            'id' => $definitionId,
            'owner' => ['type' => 'site', 'identifier' => 'default'],
            'site' => 'default',
            'handle' => 'site.default.neutral_owner_' . $suffix,
            'singular_label' => 'Neutral owner',
            'plural_label' => 'Neutral owners',
            'status' => 'draft',
            'definition_version' => 0,
            'storage_mode' => 'relational',
            'identity_strategy' => 'uuid',
            'scope' => 'site',
            'audit_enabled' => true,
            'revisions_enabled' => true,
            'fields' => [
                self::identityField(),
                [
                    'handle' => 'title',
                    'label' => 'Title',
                    'type' => 'core.text',
                    'required' => true,
                    'nullable' => false,
                    'length' => 160,
                    'filterable' => true,
                    'sortable' => true,
                ],
                [
                    'handle' => 'field_lines',
                    'label' => 'Field lines',
                    'type' => 'core.ordered_lines',
                    'configuration' => ['target' => $lineHandle],
                ],
            ],
            'relationships' => [
                [
                    'handle' => 'primary_target',
                    'label' => 'Primary target',
                    'kind' => 'one_to_one',
                    'target' => $targetHandle,
                    'on_delete' => 'set_null',
                ],
                [
                    'handle' => 'category',
                    'label' => 'Category',
                    'kind' => 'many_to_one',
                    'target' => $targetHandle,
                    'on_delete' => 'restrict',
                ],
                [
                    'handle' => 'members',
                    'label' => 'Members',
                    'kind' => 'one_to_many',
                    'target' => $targetHandle,
                    'ordered' => true,
                    'on_delete' => 'restrict',
                ],
                [
                    'handle' => 'tags',
                    'label' => 'Tags',
                    'kind' => 'many_to_many',
                    'target' => $targetHandle,
                    'ordered' => true,
                    'on_delete' => 'restrict',
                ],
                [
                    'handle' => 'lines',
                    'label' => 'Lines',
                    'kind' => 'owned_line_collection',
                    'target' => $lineHandle,
                    'ordered' => true,
                    'on_delete' => 'cascade',
                ],
            ],
            'views' => [self::listView('Neutral owners', ['title'])],
            'actions' => [],
            'workflow' => null,
            'compatibility_metadata' => [],
            'administrator_exposure' => true,
            'portal_exposure' => false,
            'public_exposure' => false,
        ];
    }

    public static function idempotencyKey(string $operation): IdempotencyKey
    {
        if (preg_match('/^[a-z][a-z0-9-]{0,63}$/D', $operation) !== 1) {
            throw new RuntimeException('A neutral fixture operation identifier is invalid.');
        }

        return IdempotencyKey::fromString('neutral-fixture:' . $operation);
    }

    /** Publish and install a fixture document through the real application boundaries. */
    public static function install(
        Container $container,
        ExecutionContext $context,
        ?array $document = null,
    ): EntityTypeDefinition {
        $document ??= self::backupDocument();
        $definitions = $container->get(BusinessDefinitionService::class);
        $schemas = $container->get(BusinessSchemaService::class);
        if (!$definitions instanceof BusinessDefinitionService || !$schemas instanceof BusinessSchemaService) {
            throw new RuntimeException('The business runtime fixture services are unavailable.');
        }

        $identifier = $document['handle'] ?? null;
        if (!is_string($identifier)) {
            throw new RuntimeException('The business runtime fixture handle is unavailable.');
        }
        $entry = null;
        foreach ($definitions->catalog($context) as $candidate) {
            if ($candidate->handle === $identifier) {
                $entry = $candidate;
                break;
            }
        }
        if ($entry === null) {
            $draft = $definitions->saveDraft($context, EntityTypeDefinition::fromArray($document));
            $published = $definitions->publish($context, $draft->definition->id, $draft->revision);
        } elseif ($entry->publishedVersion === null) {
            $draft = $definitions->draft($context, $entry->id);
            $published = $definitions->publish($context, $entry->id, $draft->revision);
        } else {
            $published = $definitions->published($context, $entry->id, $entry->publishedVersion);
        }

        $installation = $schemas->installation($context, $published->definition->id);
        if ($installation?->status === SchemaInstallationStatus::Active) {
            self::grantRecordAccess($container, $context, $published->definition);

            return $published->definition;
        }
        $plan = $schemas->createPlan($context, $published->definition->id);
        if ($plan->status === SchemaPlanStatus::PendingApproval) {
            $confirmation = $plan->risk->requiresHighImpactAuthorization() ? $plan->checksum() : null;
            $plan = $schemas->approve($context, $plan->id, $plan->checksum(), $confirmation, null);
        }
        if ($plan->status === SchemaPlanStatus::Approved) {
            $schemas->execute($context, $plan->id);
        } elseif (
            in_array(
                $plan->status,
                [SchemaPlanStatus::Executing, SchemaPlanStatus::Failed, SchemaPlanStatus::RecoveryRequired],
                true,
            )
        ) {
            $schemas->recover($context, $plan->id);
        }
        $installation = $schemas->installation($context, $published->definition->id);
        if ($installation?->status !== SchemaInstallationStatus::Active) {
            throw new RuntimeException('The neutral business fixture schema did not become active.');
        }
        self::grantRecordAccess($container, $context, $published->definition);

        return $published->definition;
    }

    /**
     * Publish and install definitions the way an extension package contributes them, not an administrator.
     *
     * An administrator may only edit definitions the current site owns, so an extension-owned contract
     * cannot arrive through the draft-and-publish path at all. This is the real contribution seam: the
     * package synchronizer publishes the set, the ordinary schema-plan path installs it, and core then
     * enforces whatever the extension declared without having heard of it.
     *
     * @param   Container                   $container             Real integration container.
     * @param   ExecutionContext            $context              Trusted test actor.
     * @param   string                      $extensionIdentifier  Owner identifier, as `vendor/package`.
     * @param   list<array<string, mixed>>  $documents            Canonical definition documents to publish,
     *          in dependency order.
     *
     * @return  list<EntityTypeDefinition>  The published definitions, in the order supplied.
     */
    public static function installContributed(
        Container $container,
        ExecutionContext $context,
        string $extensionIdentifier,
        array $documents,
    ): array {
        $synchronizer = $container->get(PackageDefinitionSynchronizer::class);
        $transactions = $container->get(TransactionManager::class);
        $schemas = $container->get(BusinessSchemaService::class);
        if (
            !$synchronizer instanceof PackageDefinitionSynchronizer
            || !$transactions instanceof TransactionManager
            || !$schemas instanceof BusinessSchemaService
        ) {
            throw new RuntimeException('The extension contribution fixture services are unavailable.');
        }
        $definitions = array_map(
            static fn (array $document): EntityTypeDefinition => EntityTypeDefinition::fromArray(
                [...$document, 'status' => 'published', 'definition_version' => 1],
            ),
            $documents,
        );
        $transactions->transactional(static function () use (
            $synchronizer,
            $extensionIdentifier,
            $context,
            $definitions,
        ): void {
            $synchronizer->synchronize(
                $extensionIdentifier,
                '1.0.0',
                $context->site(),
                [],
                $definitions,
                true,
                $context->actorId(),
            );
        });
        $wanted = array_map(
            static fn (EntityTypeDefinition $definition): string => $definition->id,
            $definitions,
        );
        foreach ($schemas->plans($context) as $plan) {
            if (!in_array($plan->definitionId, $wanted, true)) {
                continue;
            }
            if ($plan->status === SchemaPlanStatus::PendingApproval) {
                $schemas->approve(
                    $context,
                    $plan->id,
                    $plan->checksum(),
                    $plan->risk->requiresHighImpactAuthorization() ? $plan->checksum() : null,
                    null,
                );
            }
            $current = $schemas->plan($context, $plan->id);
            if ($current->status === SchemaPlanStatus::Approved) {
                $schemas->execute($context, $current->id);
            }
        }
        foreach ($definitions as $definition) {
            $installation = $schemas->installation($context, $definition->id);
            if ($installation?->status !== SchemaInstallationStatus::Active) {
                throw new RuntimeException('A contributed fixture definition did not become active.');
            }
            self::grantRecordAccess($container, $context, $definition);
        }

        return $definitions;
    }

    /**
     * Install explicit typed allow policies for a legacy-neutral integration definition.
     *
     * Production record access has no implicit allow path. This fixture preserves the pre-security
     * integration contract by creating auditable per-definition rows through the same physical catalog
     * the runtime consumes. Tests that exercise default deny remove these rows before installing their
     * narrower policies.
     *
     * @param   Container             $container   Real integration container supplying persistence.
     * @param   ExecutionContext      $context     Trusted test actor recorded as policy author.
     * @param   EntityTypeDefinition  $definition Published definition whose legacy access is made explicit.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public static function grantRecordAccess(
        Container $container,
        ExecutionContext $context,
        EntityTypeDefinition $definition,
    ): void {
        $database = $container->get(Connection::class);
        $tables = $container->get(TableNames::class);
        if (!$database instanceof Connection || !$tables instanceof TableNames) {
            throw new RuntimeException('The business-record policy fixture persistence is unavailable.');
        }
        $predicate = ['type' => 'constant', 'value' => true];
        $fields = self::recordFieldRules($definition);
        $checksum = CanonicalDefinitionJson::checksum(['ast' => $predicate, 'fields' => $fields]);
        $at = new DateTimeImmutable('2026-01-01T00:00:00+00:00');
        foreach (self::RECORD_OPERATIONS as $operation) {
            $policyCode = self::recordPolicyCode($definition->id, $operation);
            if (
                $database->fetchOne(sprintf(
                    'SELECT policy_code FROM %s WHERE policy_code = ?',
                    $tables->quoted('resource_policies'),
                ), [$policyCode]) !== false
            ) {
                continue;
            }
            $database->insert($tables->raw('resource_policies'), [
                'id' => Uuid::uuid7()->toString(),
                'policy_code' => $policyCode,
                'owner_kind' => 'core',
                'owner_identifier' => 'core',
                'capability_code' => $operation,
                'resource_type' => 'business_record',
                'action' => $operation,
                'effect' => 'allow',
                'scope_type' => 'site',
                'organization_id' => null,
                'entity_definition_id' => $definition->id,
                'canonical_ast' => $predicate,
                'field_rules' => $fields,
                'ast_checksum' => $checksum,
                'policy_version' => 1,
                'priority' => -1000,
                'status' => 'active',
                'created_by' => $context->actorId(),
                'created_at' => $at,
                'updated_at' => $at,
            ], [
                'canonical_ast' => Types::JSON,
                'field_rules' => Types::JSON,
                'created_at' => Types::DATETIME_IMMUTABLE,
                'updated_at' => Types::DATETIME_IMMUTABLE,
            ]);
        }
    }

    /**
     * Remove only this fixture's explicit rows so a test can exercise deny-by-default policy.
     *
     * @param   Container  $container     Real integration container supplying persistence.
     * @param   string     $definitionId  Definition whose fixture-owned policy rows are removed.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public static function removeRecordAccess(Container $container, string $definitionId): void
    {
        $database = $container->get(Connection::class);
        $tables = $container->get(TableNames::class);
        if (!$database instanceof Connection || !$tables instanceof TableNames) {
            throw new RuntimeException('The business-record policy fixture persistence is unavailable.');
        }
        foreach (self::RECORD_OPERATIONS as $operation) {
            $database->delete($tables->raw('resource_policies'), [
                'policy_code' => self::recordPolicyCode($definitionId, $operation),
            ]);
        }
    }

    /**
     * Derive explicit legacy field and action grants from the published fixture definition.
     *
     * @param   EntityTypeDefinition  $definition  Definition whose immutable field flags are honored.
     *
     * @return  array<string,list<string>>  Every field usage plus the explicit action list.
     *
     * @since   2.0.0
     */
    private static function recordFieldRules(EntityTypeDefinition $definition): array
    {
        $allowed = [];
        foreach (FieldAccessUsage::cases() as $usage) {
            $allowed[$usage->value] = [];
        }
        foreach ($definition->fields() as $field) {
            $readable = $field->readVisible;
            $queryable = $readable
                && !in_array($field->sensitivity->value, ['restricted', 'secret'], true);
            self::addRecordField(
                $allowed,
                FieldAccessUsage::Create,
                $field,
                $field->createVisible && !$field->serverOnly && !$field->computed && $field->formula === null,
            );
            self::addRecordField(
                $allowed,
                FieldAccessUsage::Update,
                $field,
                $field->updateVisible && !$field->serverOnly && !$field->readOnly
                    && !$field->computed && $field->formula === null,
            );
            self::addRecordField($allowed, FieldAccessUsage::Detail, $field, $readable);
            self::addRecordField(
                $allowed,
                FieldAccessUsage::List,
                $field,
                $readable,
            );
            self::addRecordField($allowed, FieldAccessUsage::Mcp, $field, $readable);
            self::addRecordField($allowed, FieldAccessUsage::Include, $field, $readable);
            self::addRecordField($allowed, FieldAccessUsage::Filter, $field, $queryable && $field->filterable);
            self::addRecordField($allowed, FieldAccessUsage::Relation, $field, $queryable && $field->filterable);
            self::addRecordField($allowed, FieldAccessUsage::Search, $field, $queryable && $field->searchable);
            self::addRecordField($allowed, FieldAccessUsage::Sort, $field, $queryable && $field->sortable);
            self::addRecordField($allowed, FieldAccessUsage::Aggregate, $field, $queryable && $field->reportable);
            self::addRecordField($allowed, FieldAccessUsage::Report, $field, $queryable && $field->reportable);
            self::addRecordField($allowed, FieldAccessUsage::Export, $field, $queryable && $field->exportable);
            self::addRecordField(
                $allowed,
                FieldAccessUsage::PublicReference,
                $field,
                $queryable && $field->type === (
                    $definition->identityStrategy === IdentityStrategy::Uuid
                        ? 'core.uuid'
                        : 'core.reference_identity'
                ),
            );
            self::addRecordField(
                $allowed,
                FieldAccessUsage::Audit,
                $field,
                $readable,
            );
        }
        $allowed['actions'] = array_map(
            static fn ($action): string => $action->handle,
            $definition->actions(),
        );

        return $allowed;
    }

    /**
     * Add one field to one explicit fixture usage when its immutable flags admit it.
     *
     * @param   array<string,list<string>>  $allowed    Field rules being assembled.
     * @param   FieldAccessUsage           $usage      Exact field usage receiving the handle.
     * @param   FieldDefinition            $field      Published field under consideration.
     * @param   bool                       $condition  Whether the definition permits this usage.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private static function addRecordField(
        array &$allowed,
        FieldAccessUsage $usage,
        FieldDefinition $field,
        bool $condition,
    ): void {
        if ($condition) {
            $allowed[$usage->value][] = $field->handle;
        }
    }

    /**
     * Derive a deterministic fixture-owned policy code for one definition and operation.
     *
     * @param   string  $definitionId  Published definition UUID.
     * @param   string  $operation     Closed business-record operation.
     *
     * @return  string  Stable unique code below the persisted identifier bound.
     *
     * @since   2.0.0
     */
    private static function recordPolicyCode(string $definitionId, string $operation): string
    {
        return 'test.fixture.record.'
            . str_replace('-', '', $definitionId)
            . '.'
            . substr($operation, strlen('business.record.'));
    }

    /** Create or replay the stable backup row through the real transactional boundary. */
    public static function createBackupRecord(
        Container $container,
        ExecutionContext $context,
    ): RecordMutationResult {
        $records = $container->get(BusinessRecordService::class);
        if (!$records instanceof BusinessRecordService) {
            throw new RuntimeException('The business-record runtime fixture service is unavailable.');
        }

        return $records->create(new CreateRecordCommand(
            $context,
            self::HANDLE,
            self::recordValues(),
            self::idempotencyKey('backup-create'),
            recordId: self::RECORD_ID,
        ));
    }

    /**
     * Seed generated record, junction, and owned-line tables with deterministic backup data.
     *
     * Replaying this helper is safe because every application mutation uses the same
     * stable request and idempotency key.
     *
     * @return array{
     *   definition_ids: list<string>,
     *   owner_handle: string,
     *   owner_record_id: string,
     *   target_handle: string,
     *   target_record_ids: list<string>,
     *   line_record_ids: list<string>,
     *   owner_version: int
     * }
     */
    public static function seedBackupGraph(Container $container, ExecutionContext $context): array
    {
        $target = self::install(
            $container,
            $context,
            self::relationTargetDocument(self::BACKUP_GRAPH_SUFFIX, self::TARGET_DEFINITION_ID),
        );
        $line = self::install(
            $container,
            $context,
            self::ownedLineDocument(self::BACKUP_GRAPH_SUFFIX, self::LINE_DEFINITION_ID),
        );
        $owner = self::install(
            $container,
            $context,
            self::relationshipOwnerDocument(
                self::BACKUP_GRAPH_SUFFIX,
                self::OWNER_DEFINITION_ID,
                $target->handle,
                $line->handle,
            ),
        );
        if (
            $target->handle !== self::TARGET_HANDLE
            || $line->handle !== self::LINE_HANDLE
            || $owner->handle !== self::OWNER_HANDLE
        ) {
            throw new RuntimeException('The stable backup graph handles changed unexpectedly.');
        }
        $records = $container->get(BusinessRecordService::class);
        if (!$records instanceof BusinessRecordService) {
            throw new RuntimeException('The business-record runtime fixture service is unavailable.');
        }
        $records->create(new CreateRecordCommand(
            $context,
            $target->handle,
            ['label' => 'Backup graph target one'],
            self::idempotencyKey('backup-target-one'),
            recordId: self::TARGET_RECORD_ID,
        ));
        $records->create(new CreateRecordCommand(
            $context,
            $target->handle,
            ['label' => 'Backup graph target two'],
            self::idempotencyKey('backup-target-two'),
            recordId: self::SECOND_TARGET_RECORD_ID,
        ));
        $records->create(new CreateRecordCommand(
            $context,
            $owner->handle,
            ['title' => 'Backup graph owner'],
            self::idempotencyKey('backup-owner'),
            recordId: self::OWNER_RECORD_ID,
        ));

        $version = $records->relate(new RelateRecordsCommand(
            $context,
            $owner->handle,
            self::OWNER_RECORD_ID,
            1,
            'tags',
            self::TARGET_RECORD_ID,
            self::idempotencyKey('backup-tag-one'),
            0,
        ))->version;
        $version = $records->relate(new RelateRecordsCommand(
            $context,
            $owner->handle,
            self::OWNER_RECORD_ID,
            $version,
            'tags',
            self::SECOND_TARGET_RECORD_ID,
            self::idempotencyKey('backup-tag-two'),
            1,
        ))->version;
        $version = $records->reorder(new ReorderRecordLinesCommand(
            $context,
            $owner->handle,
            self::OWNER_RECORD_ID,
            $version,
            'tags',
            [self::SECOND_TARGET_RECORD_ID, self::TARGET_RECORD_ID],
            self::idempotencyKey('backup-tag-order'),
        ))->version;
        $version = $records->relate(new RelateRecordsCommand(
            $context,
            $owner->handle,
            self::OWNER_RECORD_ID,
            $version,
            'lines',
            self::LINE_RECORD_ID,
            self::idempotencyKey('backup-line-one'),
            0,
            targetValues: ['description' => 'Backup line one', 'units' => '1.000'],
        ))->version;
        $version = $records->relate(new RelateRecordsCommand(
            $context,
            $owner->handle,
            self::OWNER_RECORD_ID,
            $version,
            'lines',
            self::SECOND_LINE_RECORD_ID,
            self::idempotencyKey('backup-line-two'),
            1,
            targetValues: ['description' => 'Backup line two', 'units' => '2.000'],
        ))->version;
        $version = $records->reorder(new ReorderRecordLinesCommand(
            $context,
            $owner->handle,
            self::OWNER_RECORD_ID,
            $version,
            'lines',
            [self::SECOND_LINE_RECORD_ID, self::LINE_RECORD_ID],
            self::idempotencyKey('backup-line-order'),
        ))->version;

        return [
            'definition_ids' => [$target->id, $line->id, $owner->id],
            'owner_handle' => $owner->handle,
            'owner_record_id' => self::OWNER_RECORD_ID,
            'target_handle' => $target->handle,
            'target_record_ids' => [self::TARGET_RECORD_ID, self::SECOND_TARGET_RECORD_ID],
            'line_record_ids' => [self::LINE_RECORD_ID, self::SECOND_LINE_RECORD_ID],
            'owner_version' => $version,
        ];
    }

    /**
     * Owner block for a document fixture, so the same shape can be declared by the site or an extension.
     *
     * @return array<string, string>
     */
    private static function documentOwner(?string $extensionIdentifier): array
    {
        if ($extensionIdentifier === null) {
            return ['type' => 'site', 'identifier' => 'default'];
        }
        if (preg_match('#^[a-z0-9][a-z0-9._-]*/[a-z0-9][a-z0-9._-]*$#D', $extensionIdentifier) !== 1) {
            throw new RuntimeException('A neutral document fixture extension identifier is invalid.');
        }

        return ['type' => 'extension', 'identifier' => $extensionIdentifier];
    }

    /** Namespaced handle for a document fixture, under the site or under the contributing extension. */
    private static function documentHandle(string $name, string $suffix, ?string $extensionIdentifier): string
    {
        if ($extensionIdentifier === null) {
            return 'site.default.' . $name . '_' . $suffix;
        }

        return str_replace('/', '.', $extensionIdentifier) . '.' . $name;
    }

    /** @return array<string, mixed> */
    private static function identityField(): array
    {
        return [
            'handle' => 'id',
            'label' => 'ID',
            'type' => 'core.uuid',
            'required' => true,
            'nullable' => false,
            'unique' => true,
            'indexed' => true,
            'immutable_after_create' => true,
            'server_only' => true,
            'read_only' => true,
        ];
    }

    /** @return array<string, mixed> */
    private static function referenceIdentityField(): array
    {
        return [
            'handle' => 'code',
            'label' => 'Code',
            'type' => 'core.reference_identity',
            'required' => true,
            'nullable' => false,
            'length' => 80,
            'normalizers' => ['trim', 'uppercase'],
            'unique' => true,
            'indexed' => true,
            'immutable_after_create' => true,
            'filterable' => true,
            'sortable' => true,
        ];
    }

    /** @return array<string, mixed> */
    private static function inverseRelationshipDocument(
        string $definitionId,
        string $extensionIdentifier,
        string $handle,
        string $label,
        string $relationshipHandle,
        string $targetHandle,
        string $inverseHandle,
    ): array {
        return [
            'id' => $definitionId,
            'owner' => ['type' => 'extension', 'identifier' => $extensionIdentifier],
            'site' => 'default',
            'handle' => $handle,
            'singular_label' => $label . ' inverse fixture',
            'plural_label' => $label . ' inverse fixtures',
            'status' => 'published',
            'definition_version' => 1,
            'storage_mode' => 'relational',
            'identity_strategy' => 'uuid',
            'scope' => 'site',
            'audit_enabled' => true,
            'revisions_enabled' => true,
            'soft_delete_enabled' => true,
            'fields' => [
                self::identityField(),
                [
                    'handle' => 'label',
                    'label' => 'Label',
                    'type' => 'core.text',
                    'required' => true,
                    'nullable' => false,
                    'length' => 120,
                    'filterable' => true,
                    'sortable' => true,
                ],
            ],
            'relationships' => [[
                'handle' => $relationshipHandle,
                'label' => ucfirst($relationshipHandle),
                'kind' => 'many_to_many',
                'target' => $targetHandle,
                'inverse' => $inverseHandle,
                'ordered' => true,
                'on_delete' => 'restrict',
            ]],
            'views' => [self::listView($label . ' inverse fixtures', ['label'])],
            'actions' => [],
            'workflow' => null,
            'compatibility_metadata' => [],
            'administrator_exposure' => true,
            'portal_exposure' => false,
            'public_exposure' => false,
        ];
    }

    /** @param list<string> $fields @return array<string, mixed> */
    private static function listView(string $label, array $fields): array
    {
        return [
            'handle' => 'list',
            'label' => $label,
            'kind' => 'list',
            'fields' => $fields,
            'filters' => [],
            'sorts' => [],
            'administrator' => true,
            'portal' => false,
            'public' => false,
        ];
    }

    private static function assertGraphIdentity(string $suffix, string $definitionId): void
    {
        if (
            preg_match('/^[a-z0-9]{1,12}$/D', $suffix) !== 1
            || !\Ramsey\Uuid\Uuid::isValid($definitionId)
        ) {
            throw new RuntimeException('A neutral relationship fixture identity is invalid.');
        }
    }

    private function __construct()
    {
    }
}
