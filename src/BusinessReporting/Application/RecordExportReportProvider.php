<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessReporting\Application;

use InvalidArgumentException;
use Kumwe\App\BusinessDefinition\Application\FieldTypeDefinitionResolver;
use Kumwe\App\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\App\BusinessDefinition\Domain\FieldDefinition;
use Kumwe\App\BusinessDefinition\Domain\InvalidBusinessDefinition;
use Kumwe\App\BusinessDefinition\Domain\PortalOperation;
use Kumwe\App\BusinessRecord\Application\BusinessRecordDefinitionResolver;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordDefinitionUnavailable;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordSchemaUnavailable;
use Kumwe\App\BusinessReporting\Domain\ReportColumnDefinition;
use Kumwe\App\BusinessReporting\Domain\ReportDefinition;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Extension\Spi\BusinessReporting\Domain\ReportValueType;

/**
 * Derives the record-set CSV export report for one installed business definition.
 *
 * Generated collection views advertise an export affordance for definitions that never contributed a
 * signed report. This provider closes that gap without a parallel export path: it derives one
 * deterministic `ReportDefinition` from the installed definition's declared exportable fields, and the
 * existing `ExportService` and `ReportService` then authorize, queue, generate, audit and stream it
 * exactly like a contributed report. Derivation reads only the immutable published definition version,
 * so request-time, verification-time and worker-time derivations of the same installed version always
 * agree on version and checksum, and a definition upgrade invalidates outstanding artifacts honestly.
 *
 * @since  2.0.0
 */
final readonly class RecordExportReportProvider
{
    /**
     * Identifier prefix reserved for derived record-set export reports.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string IDENTIFIER_PREFIX = 'core.record-export.';

    /**
     * Largest column list one derived export may disclose, matching the report definition bound.
     *
     * @var    int
     * @since  2.0.0
     */
    private const int MAXIMUM_COLUMNS = 64;

    /**
     * Longest string-family field length a derived export column may carry, matching report cells.
     *
     * @var    int
     * @since  2.0.0
     */
    private const int MAXIMUM_STRING_LENGTH = 4096;

    /**
     * Wire derivation to the installed-definition catalog and the immutable field-type structures.
     *
     * @param  BusinessRecordDefinitionResolver  $definitions  Active installed source-definition resolver.
     * @param  FieldTypeDefinitionResolver       $fieldTypes   Immutable logical field-family resolver.
     *
     * @since  2.0.0
     */
    public function __construct(
        private BusinessRecordDefinitionResolver $definitions,
        private FieldTypeDefinitionResolver $fieldTypes,
    ) {
    }

    /**
     * Resolve a derived record-export report from its reserved namespaced identifier.
     *
     * @param   ExecutionContext  $context     Authenticated actor and site scope.
     * @param   string            $identifier  Candidate `core.record-export.<definition-handle>` identifier.
     *
     * @return  ReportDefinition  Deterministic derived report for the installed definition version.
     *
     * @throws  ReportUnavailable  When the identifier is outside the reserved namespace, the definition
     *          is not installed and active, or it discloses no exportable scalar field.
     *
     * @since   2.0.0
     */
    public function resolve(ExecutionContext $context, string $identifier): ReportDefinition
    {
        if (!str_starts_with($identifier, self::IDENTIFIER_PREFIX)) {
            throw new ReportUnavailable('The report is unavailable.');
        }
        $report = $this->forDefinition($context, substr($identifier, strlen(self::IDENTIFIER_PREFIX)));
        if (!hash_equals($report->identifier(), $identifier)) {
            throw new ReportUnavailable('The report is unavailable.');
        }

        return $report;
    }

    /**
     * Derive the record-set export report for one installed business definition.
     *
     * @param   ExecutionContext  $context     Authenticated actor and site scope.
     * @param   string            $definition  Definition handle naming the installed record type.
     *
     * @return  ReportDefinition  Deterministic derived report for the installed definition version.
     *
     * @throws  ReportUnavailable  When the definition is not installed and active, or it discloses no
     *          exportable scalar field.
     *
     * @since   2.0.0
     */
    public function forDefinition(ExecutionContext $context, string $definition): ReportDefinition
    {
        try {
            $entity = $this->definitions->forCreate($context, $definition)->definition;

            return new ReportDefinition(
                self::IDENTIFIER_PREFIX . $entity->handle,
                $entity->definitionVersion,
                mb_substr($entity->pluralLabel . ' export', 0, 191),
                $entity->handle,
                'business.record.export',
                [],
                [],
                $this->columns($entity),
                administratorVisible: $entity->administratorExposure,
                portalVisible: $entity->allowsPortalOperation(PortalOperation::Export),
            );
        } catch (
            BusinessRecordDefinitionUnavailable
            | BusinessRecordSchemaUnavailable
            | InvalidBusinessDefinition
            | InvalidArgumentException $exception
        ) {
            throw new ReportUnavailable('The report is unavailable.', 0, $exception);
        }
    }

    /**
     * Project the definition's declared exportable scalar fields into bounded report columns.
     *
     * Only unconditionally disclosed fields are eligible, mirroring the generated catalog's export
     * use flag; conditionally visible fields would make one hidden row abort a whole record-set
     * export. Structured value families stay excluded because a report cell must remain scalar.
     *
     * @param   EntityTypeDefinition  $entity  Installed published definition version.
     *
     * @return  non-empty-list<ReportColumnDefinition>  Columns in declaration order, at most 64.
     *
     * @throws  InvalidArgumentException  When no field qualifies for a scalar export column.
     *
     * @since   2.0.0
     */
    private function columns(EntityTypeDefinition $entity): array
    {
        $columns = [];
        foreach ($entity->fields() as $field) {
            if (!$field->exportable || $field->visibilityCondition !== null) {
                continue;
            }
            $type = $this->columnType($field);
            if ($type === null) {
                continue;
            }
            $columns[] = new ReportColumnDefinition($field->handle, $field->label, $field->handle, $type);
            if (count($columns) === self::MAXIMUM_COLUMNS) {
                break;
            }
        }
        if ($columns === []) {
            throw new InvalidArgumentException('A record export requires at least one exportable scalar field.');
        }

        return $columns;
    }

    /**
     * Map one declared field onto its scalar report value type, or null when it cannot be a column.
     *
     * @param   FieldDefinition  $field  Declared exportable field.
     *
     * @return  ?ReportValueType  Scalar column type, or null for structured or unbounded values.
     *
     * @since   2.0.0
     */
    private function columnType(FieldDefinition $field): ?ReportValueType
    {
        $core = match ($field->type) {
            'core.uuid', 'core.entity_reference', 'core.media_reference' => ReportValueType::Identifier,
            'core.integer' => ReportValueType::Integer,
            'core.boolean' => ReportValueType::Boolean,
            'core.decimal' => ReportValueType::Decimal,
            'core.date' => ReportValueType::Date,
            'core.instant' => ReportValueType::DateTime,
            default => null,
        };
        if ($core !== null) {
            return $core;
        }
        if ($field->length !== null && $field->length > self::MAXIMUM_STRING_LENGTH) {
            return null;
        }

        return match ($this->fieldTypes->get($field->type)->valueType) {
            'boolean' => ReportValueType::Boolean,
            'integer' => ReportValueType::Integer,
            'string' => ReportValueType::String,
            default => null,
        };
    }
}
