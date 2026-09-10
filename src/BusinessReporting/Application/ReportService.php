<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessReporting\Application;

use DateTimeInterface;
use InvalidArgumentException;
use Kumwe\Context\Value\AuthenticatedSurface;
use Kumwe\App\Application\Authorization\AuthorizationGateway;
use Kumwe\App\Application\Authorization\AuthorizationResource;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\App\BusinessDefinition\Domain\CanonicalDefinitionJson;
use Kumwe\App\BusinessDefinition\Domain\DecimalValue;
use Kumwe\App\BusinessRecord\Application\BusinessRecordView;
use Kumwe\App\BusinessRecord\Application\Exception\InvalidBusinessRecordQuery;
use Kumwe\Extension\Spi\BusinessRecord\Application\BusinessRecordQueryPurpose;
use Kumwe\Conversion\Value\ConvertedMoneyValue;
use Kumwe\Conversion\Value\ConvertedQuantityValue;
use Kumwe\Conversion\Decimal\ExactDecimal;
use Kumwe\Extension\Spi\BusinessRecord\Query\BooleanFilter;
use Kumwe\Extension\Spi\BusinessRecord\Query\BooleanOperator;
use Kumwe\Extension\Spi\BusinessRecord\Query\ComparisonFilter;
use Kumwe\Extension\Spi\BusinessRecord\Query\ComparisonOperator;
use Kumwe\Extension\Spi\BusinessRecord\Query\NullFilter;
use Kumwe\Extension\Spi\BusinessRecord\Query\RecordCursor;
use Kumwe\Extension\Spi\BusinessRecord\Query\RecordFilter;
use Kumwe\Extension\Spi\BusinessRecord\Query\RecordProjection;
use Kumwe\Extension\Spi\BusinessRecord\Query\RecordQuerySpecification;
use Kumwe\Extension\Spi\BusinessRecord\Query\RelationFilter;
use Kumwe\Extension\Spi\BusinessRecord\Query\RelationQuantifier;
use Kumwe\Extension\Spi\BusinessRecord\Query\SetFilter;
use Kumwe\Extension\Spi\BusinessRecord\Query\TextFilter;
use Kumwe\Extension\Spi\BusinessRecord\Query\TextOperator;
use Kumwe\App\BusinessReporting\Domain\ReportAggregateDefinition;
use Kumwe\App\BusinessReporting\Domain\ReportAggregateFunction;
use Kumwe\App\BusinessReporting\Domain\ReportColumnDefinition;
use Kumwe\App\BusinessReporting\Domain\ReportDefinition;
use Kumwe\App\BusinessReporting\Domain\ReportFilterDefinition;
use Kumwe\App\BusinessReporting\Domain\ReportFilterOperator;
use Kumwe\App\BusinessReporting\Domain\ReportRelationQuantifier;
use Kumwe\App\BusinessReporting\Domain\ReportSortDefinition;
use Kumwe\App\BusinessReporting\Domain\ReportSortDirection;
use Kumwe\Extension\Spi\BusinessReporting\Domain\ReportValueType;
use Kumwe\Extension\Spi\Identity\Domain\Capability;
use Throwable;

/**
 * Executes immutable reports exclusively over policy-filtered business-record browse pages.
 *
 * Grouping, formulas and ordering happen only after `BusinessRecordService` has applied row policy and
 * omitted disallowed fields. Missing fields remain missing through formulas and aggregates, preventing a
 * conditional field denial from being converted into a value or grouping key downstream.
 *
 * @since  2.0.0
 */
final readonly class ReportService
{
    /**
     * Wire reporting to definitions, the policy-aware record seam and capability enforcement.
     *
     * @param   ReportDefinitionRegistry     $reports            Active report contributions.
     * @param   BusinessRecordReportReader   $records            Canonical record browse adapter.
     * @param   AuthorizationGateway         $authorization      Deny-by-default permission gateway.
     * @param   ReportScopeResolver          $scopes             Installed source-scope resolver.
     * @param   int                          $maximumExportRows  Absolute expanded-row bound for one export.
     * @param   ?RecordExportReportProvider  $recordExports      Derived record-set export reports; null
     *          keeps resolution limited to contributed reports.
     *
     * @throws  InvalidArgumentException  When the export bound is outside 1 to 100000.
     *
     * @since   2.0.0
     */
    public function __construct(
        private ReportDefinitionRegistry $reports,
        private BusinessRecordReportReader $records,
        private AuthorizationGateway $authorization,
        private ReportScopeResolver $scopes,
        private int $maximumExportRows = 100_000,
        private ?RecordExportReportProvider $recordExports = null,
    ) {
        if ($maximumExportRows < 1 || $maximumExportRows > 100_000) {
            throw new InvalidArgumentException('The report export row limit is invalid.');
        }
    }

    /**
     * Execute a report or export under the caller's current authority.
     *
     * @param   ReportExecutionRequest  $request  Authenticated report input.
     *
     * @return  ReportExecutionResult  Fully bounded disclosure-safe result.
     *
     * @throws  ReportUnavailable  When the report is absent or not exposed to the current surface.
     * @throws  ReportRowLimitExceeded  When the materialized row count passes its purpose-specific bound.
     *
     * @since   2.0.0
     */
    public function execute(ReportExecutionRequest $request): ReportExecutionResult
    {
        $report = $this->report($request->context, $request->reportIdentifier);
        if (!$this->isAvailable($request->context, $report, $request->purpose)) {
            throw new ReportUnavailable('The report is unavailable.');
        }
        $parameters = $this->bindParameters($report, $request->parameters);
        $organizationIdentifier = $this->scopes->resolve(
            $request->context,
            $report,
            $request->organizationIdentifier,
        );
        $filter = $this->compileFilters($report, $parameters);
        [$fields, $includes] = $this->projection($report);
        $limit = $request->purpose === BusinessRecordQueryPurpose::Export
            ? $this->maximumExportRows
            : $report->synchronousRowCap;
        $rows = [];
        $after = null;
        do {
            $specification = new RecordQuerySpecification(
                filter: $filter,
                after: $after,
                pageSize: 200,
                projection: new RecordProjection($fields, $includes),
            );
            try {
                $page = $this->records->browse(
                    $request->context,
                    $report->sourceDefinition,
                    $specification,
                    $organizationIdentifier,
                    $request->purpose,
                );
            } catch (InvalidBusinessRecordQuery $exception) {
                throw new ReportUnavailable('The report is unavailable.', previous: $exception);
            }
            foreach ($page->records as $record) {
                foreach ($this->projectRecord($report, $record) as $row) {
                    $rows[] = $row;
                    if (count($rows) > $limit) {
                        throw new ReportRowLimitExceeded('The report result exceeds its row limit.');
                    }
                }
            }
            $after = $page->nextCursor;
        } while ($after instanceof RecordCursor);

        $this->assertCompleteProjection($report, $rows);
        $rows = $this->materialize($report, $rows);
        if (count($rows) > $limit) {
            throw new ReportRowLimitExceeded('The report result exceeds its row limit.');
        }
        $labels = $this->labels($report);
        $types = $this->types($report);
        $queryDigest = CanonicalDefinitionJson::checksum([
            'report_checksum' => $report->checksum(),
            'parameters' => $parameters,
            'organization' => $organizationIdentifier,
            'purpose' => $request->purpose->value,
        ]);

        return new ReportExecutionResult(
            $report->identifier(),
            $report->checksum(),
            $queryDigest,
            $labels,
            $types,
            $rows,
            $report->drillDowns,
        );
    }

    /**
     * List active reports that the current actor can actually execute on this surface and exact resource.
     *
     * @param   ExecutionContext            $context  Authenticated actor and current site or membership scope.
     * @param   BusinessRecordQueryPurpose  $purpose  Report or export authority to evaluate.
     *
     * @return  list<ReportDefinition>  Authorized definitions in stable registry order.
     *
     * @since   2.0.0
     */
    public function available(
        ExecutionContext $context,
        BusinessRecordQueryPurpose $purpose = BusinessRecordQueryPurpose::Report,
    ): array {
        return array_values(array_filter(
            $this->reports->all(),
            fn (ReportDefinition $report): bool => $this->isAvailable($context, $report, $purpose),
        ));
    }

    /**
     * Evaluate one report with the same surface, custom-capability, and core-capability rules as execution.
     *
     * @param   ExecutionContext            $context  Authenticated actor and current site or membership scope.
     * @param   ReportDefinition            $report   Active immutable report definition.
     * @param   BusinessRecordQueryPurpose  $purpose  Report or export authority to evaluate.
     *
     * @return  bool  True only when both audited authorization decisions permit the exact report item.
     *
     * @since   2.0.0
     */
    public function isAvailable(
        ExecutionContext $context,
        ReportDefinition $report,
        BusinessRecordQueryPurpose $purpose = BusinessRecordQueryPurpose::Report,
    ): bool {
        if (!$this->surfaceAvailable($report, $context->surface())) {
            return false;
        }
        $resource = AuthorizationResource::item('business_report', $report->identifier());
        if (
            !$this->authorization->decide(
                $context,
                Capability::fromString($report->requiredCapability),
                $resource,
            )->allowed
        ) {
            return false;
        }

        if (
            !$this->authorization->decide(
                $context,
                Capability::fromString('business.record.' . $purpose->value),
                $resource,
            )->allowed
        ) {
            return false;
        }
        try {
            $this->scopes->resolve($context, $report, $context->organization()?->identifier());
        } catch (Throwable) {
            return false;
        }

        return true;
    }

    /**
     * Resolve a contributed report, or fall back to one derived record-set export report.
     *
     * @param   ExecutionContext  $context     Authenticated actor and site scope.
     * @param   string            $identifier  Namespaced report handle.
     *
     * @return  ReportDefinition  Contributed or derived immutable definition.
     *
     * @throws  ReportUnavailable  When neither the registry nor derivation can answer the handle.
     *
     * @since   2.0.0
     */
    private function report(ExecutionContext $context, string $identifier): ReportDefinition
    {
        try {
            return $this->reports->get($identifier);
        } catch (ReportUnavailable $exception) {
            if ($this->recordExports === null) {
                throw $exception;
            }

            return $this->recordExports->resolve($context, $identifier);
        }
    }

    /**
     * Bind and validate caller-supplied report parameters.
     *
     * @param   ReportDefinition      $report    Signed report definition governing query behavior.
     * @param   array<string, mixed>  $supplied  Caller-provided values keyed by report parameter identifier.
     *
     * @return  array<string, mixed>
     *
     * @since   2.0.0
     */
    private function bindParameters(ReportDefinition $report, array $supplied): array
    {
        $declared = [];
        $bound = [];
        foreach ($report->parameters as $parameter) {
            $declared[$parameter->name] = true;
            $value = array_key_exists($parameter->name, $supplied)
                ? $supplied[$parameter->name]
                : $parameter->defaultValue;
            if ($value === null && !$parameter->required) {
                continue;
            }
            $bound[$parameter->name] = $parameter->assertValue($value);
        }
        if (array_diff_key($supplied, $declared) !== []) {
            throw new InvalidArgumentException('A report execution contains an undeclared parameter.');
        }
        ksort($bound, SORT_STRING);

        return $bound;
    }

    /**
     * Compile declared report filters into business-record filters.
     *
     * @param   ReportDefinition      $report      Signed report definition governing query behavior.
     * @param   array<string, mixed>  $parameters  Validated parameter values used to compile report filters.
     *
     * @return  ?RecordFilter  Combined predicate, or null when the report declares no filters.
     *
     * @since   2.0.0
     */
    private function compileFilters(ReportDefinition $report, array $parameters): ?RecordFilter
    {
        $filters = [];
        foreach ($report->filters as $definition) {
            $parameter = $definition->parameter;
            if ($parameter !== null && !array_key_exists($parameter, $parameters)) {
                continue;
            }
            [$relationship, $field] = $this->splitPath($definition->fieldPath);
            $filter = $this->compileFilter(
                $definition,
                $field,
                $parameter === null ? null : $parameters[$parameter],
            );
            if ($relationship !== null) {
                $filter = new RelationFilter($relationship, match ($definition->quantifier) {
                    ReportRelationQuantifier::Any => RelationQuantifier::Any,
                    ReportRelationQuantifier::None => RelationQuantifier::None,
                    ReportRelationQuantifier::All => RelationQuantifier::All,
                }, $filter);
            }
            $filters[] = $filter;
        }
        if ($filters === []) {
            return null;
        }

        return count($filters) === 1 ? $filters[0] : new BooleanFilter(BooleanOperator::All, $filters);
    }

    /**
     * Compile one declared report filter into a safe query predicate.
     *
     * @param   ReportFilterDefinition  $definition  Signed contribution definition governing the operation.
     * @param   string                  $field       Declared field path targeted by the predicate.
     * @param   mixed                   $value       Candidate value being validated or normalized.
     *
     * @return  RecordFilter  Business-record predicate compiled from the declared filter.
     *
     * @since   2.0.0
     */
    private function compileFilter(ReportFilterDefinition $definition, string $field, mixed $value): RecordFilter
    {
        return match ($definition->operator) {
            ReportFilterOperator::Equal => new ComparisonFilter($field, ComparisonOperator::Equal, $value),
            ReportFilterOperator::NotEqual => new ComparisonFilter($field, ComparisonOperator::NotEqual, $value),
            ReportFilterOperator::LessThan => new ComparisonFilter($field, ComparisonOperator::LessThan, $value),
            ReportFilterOperator::LessThanOrEqual => new ComparisonFilter(
                $field,
                ComparisonOperator::LessThanOrEqual,
                $value,
            ),
            ReportFilterOperator::GreaterThan => new ComparisonFilter($field, ComparisonOperator::GreaterThan, $value),
            ReportFilterOperator::GreaterThanOrEqual => new ComparisonFilter(
                $field,
                ComparisonOperator::GreaterThanOrEqual,
                $value,
            ),
            ReportFilterOperator::Contains => new TextFilter($field, TextOperator::Contains, $this->text($value)),
            ReportFilterOperator::StartsWith => new TextFilter($field, TextOperator::StartsWith, $this->text($value)),
            ReportFilterOperator::EndsWith => new TextFilter($field, TextOperator::EndsWith, $this->text($value)),
            ReportFilterOperator::In => new SetFilter($field, $this->set($value)),
            ReportFilterOperator::NotIn => new SetFilter($field, $this->set($value), true),
            ReportFilterOperator::IsNull => new NullFilter($field),
            ReportFilterOperator::IsNotNull => new NullFilter($field, false),
        };
    }

    /**
     * Split a declared one-hop field path into its components.
     *
     * @param   string  $path  Declared one-hop field path to split and validate.
     *
     * @return  array{0: ?string, 1: string}
     *
     * @since   2.0.0
     */
    private function splitPath(string $path): array
    {
        $parts = explode('.', $path, 2);

        return count($parts) === 1 ? [null, $parts[0]] : [$parts[0], $parts[1]];
    }

    /**
     * Compile the report column projection for policy-safe record access.
     *
     * @param   ReportDefinition  $report  Signed report definition governing query behavior.
     *
     * @return  array{0: list<string>, 1: list<string>}
     *
     * @since   2.0.0
     */
    private function projection(ReportDefinition $report): array
    {
        $fields = [];
        $includes = [];
        foreach ($report->columns as $column) {
            [$relationship, $field] = $this->splitPath($column->sourcePath);
            if ($relationship === null) {
                $fields[] = $field;
            } else {
                $includes[] = $relationship;
            }
        }

        return [array_values(array_unique($fields)), array_values(array_unique($includes))];
    }

    /**
     * Project one policy-filtered business record into report cells.
     *
     * @param   ReportDefinition    $report  Signed report definition governing query behavior.
     * @param   BusinessRecordView  $record  Policy-filtered business record being projected.
     *
     * @return  list<array<string, bool|int|string|null>>
     *
     * @since   2.0.0
     */
    private function projectRecord(ReportDefinition $report, BusinessRecordView $record): array
    {
        $root = [];
        $relationColumns = [];
        $relationship = null;
        foreach ($report->columns as $column) {
            [$candidate, $field] = $this->splitPath($column->sourcePath);
            if ($candidate === null) {
                if (array_key_exists($field, $record->values)) {
                    $root[$column->alias] = $this->cell($record->values[$field], $column);
                }
                continue;
            }
            $relationship = $candidate;
            $relationColumns[] = [$column, $field];
        }
        if ($relationship === null) {
            return [$root];
        }
        $relatedRows = $record->includes[$relationship] ?? [];
        if ($relatedRows === []) {
            return [$root];
        }
        $rows = [];
        foreach ($relatedRows as $related) {
            $row = $root;
            foreach ($relationColumns as [$column, $field]) {
                if (array_key_exists($field, $related->values)) {
                    $row[$column->alias] = $this->cell($related->values[$field], $column);
                }
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Refuse a partially disclosed projection before grouping, formulas, labels, or ordering can expose it.
     *
     * @param   ReportDefinition                           $report  Signed definition naming every required source.
     * @param   list<array<string, bool|int|string|null>>  $rows    Policy-filtered rows before materialization.
     *
     * @return  void
     *
     * @throws  ReportUnavailable  When policy or conditional visibility omitted any requested source value.
     *
     * @since   2.0.0
     */
    private function assertCompleteProjection(ReportDefinition $report, array $rows): void
    {
        if ($rows === []) {
            foreach ($report->columns as $column) {
                if (str_contains($column->sourcePath, '.')) {
                    throw new ReportUnavailable('The report is unavailable.');
                }
            }

            return;
        }
        foreach ($rows as $row) {
            foreach ($report->columns as $column) {
                if (!array_key_exists($column->alias, $row)) {
                    throw new ReportUnavailable('The report is unavailable.');
                }
            }
        }
    }

    /**
     * Normalize one projected value for its declared report column.
     *
     * A converted amount or quantity is spelled out in full rather than reduced to its figure. The report
     * row is the last place the structure exists — from here the value travels as a cell in a downloaded
     * artifact somebody keeps — so the rate or factor, the as-at instant, the provider and the rounding
     * are written into the value itself, and a reader outside the system can still tell a converted
     * figure from an agreed one.
     *
     * @param   mixed                   $value   Candidate value being validated or normalized.
     * @param   ReportColumnDefinition  $column  Column definition controlling value normalization.
     *
     * @return  bool|int|string|null
     *
     * @since   2.0.0
     */
    private function cell(mixed $value, ReportColumnDefinition $column): bool|int|string|null
    {
        if ($value instanceof ConvertedMoneyValue || $value instanceof ConvertedQuantityValue) {
            $value = $value->toPortableString();
        } elseif ($value instanceof ExactDecimal) {
            $value = $value->value();
        } elseif ($value instanceof DateTimeInterface) {
            $value = $column->type === ReportValueType::Date
                ? $value->format('Y-m-d')
                : $value->format('Y-m-d\TH:i:s.uP');
        }
        if ($value !== null && !$column->type->accepts($value)) {
            throw new ReportUnavailable('A report column value contradicts its declared type.');
        }
        if (!is_bool($value) && !is_int($value) && !is_string($value) && $value !== null) {
            throw new ReportUnavailable('A report column cannot disclose a structured value.');
        }

        return $value;
    }

    /**
     * Materialize report groups, aggregates, and formula rows.
     *
     * @param   ReportDefinition                           $report  Signed report definition governing query behavior.
     * @param   list<array<string, bool|int|string|null>>  $rows    Policy-filtered report rows being transformed.
     *
     * @return  list<array<string, bool|int|string|null>>
     *
     * @since   2.0.0
     */
    private function materialize(ReportDefinition $report, array $rows): array
    {
        if ($report->groups !== [] || $report->aggregates !== []) {
            $rows = $this->group($report, $rows);
        }
        foreach ($rows as &$row) {
            foreach ($report->formulas as $formula) {
                $dependencies = $formula->expression->dependencies();
                if (array_diff_key(array_fill_keys($dependencies, true), $row) !== []) {
                    throw new ReportUnavailable('The report is unavailable.');
                }
                /** @var array<string, bool|int|string|null> $values */
                $values = array_intersect_key($row, array_fill_keys($dependencies, true));
                $value = $formula->expression->evaluate($values);
                if ($value !== null && !$formula->type->accepts($value)) {
                    throw new ReportUnavailable('A report formula result contradicts its declared type.');
                }
                if (!is_bool($value) && !is_int($value) && !is_string($value) && $value !== null) {
                    throw new ReportUnavailable('A report formula produced a structured value.');
                }
                $row[$formula->alias] = $value;
            }
        }
        unset($row);
        $this->sort($report, $rows);

        return $rows;
    }

    /**
     * Group report rows by the declared grouping fields.
     *
     * @param   ReportDefinition                           $report  Signed report definition governing query behavior.
     * @param   list<array<string, bool|int|string|null>>  $rows    Policy-filtered report rows being transformed.
     *
     * @return  list<array<string, bool|int|string|null>>
     *
     * @since   2.0.0
     */
    private function group(ReportDefinition $report, array $rows): array
    {
        $buckets = [];
        foreach ($rows as $row) {
            $key = [];
            $groupRow = [];
            foreach ($report->groups as $group) {
                if (!array_key_exists($group->columnAlias, $row)) {
                    throw new ReportUnavailable('The report is unavailable.');
                }
                $key[] = $row[$group->columnAlias];
                $groupRow[$group->columnAlias] = $row[$group->columnAlias];
            }
            $digest = CanonicalDefinitionJson::checksum($key);
            $buckets[$digest] ??= ['row' => $groupRow, 'members' => []];
            $buckets[$digest]['members'][] = $row;
        }
        if ($report->groups === [] && $buckets === []) {
            $buckets['all'] = ['row' => [], 'members' => $rows];
        }
        $result = [];
        foreach ($buckets as $bucket) {
            $output = $bucket['row'];
            foreach ($report->aggregates as $aggregate) {
                $value = $this->aggregate($aggregate, $bucket['members']);
                $output[$aggregate->alias] = $value;
            }
            $result[] = $output;
        }

        return $result;
    }

    /**
     * Calculate one declared aggregate across the supplied rows.
     *
     * @param   ReportAggregateDefinition                  $aggregate  Aggregate definition to calculate.
     * @param   list<array<string, bool|int|string|null>>  $rows       Policy-filtered report rows being transformed.
     *
     * @return  int|string|null
     *
     * @since   2.0.0
     */
    private function aggregate(ReportAggregateDefinition $aggregate, array $rows): int|string|null
    {
        if ($aggregate->function === ReportAggregateFunction::Count) {
            return count($rows);
        }
        $values = [];
        foreach ($rows as $row) {
            if ($aggregate->columnAlias !== null && !array_key_exists($aggregate->columnAlias, $row)) {
                throw new ReportUnavailable('The report is unavailable.');
            }
            if ($aggregate->columnAlias !== null && $row[$aggregate->columnAlias] !== null) {
                $values[] = $row[$aggregate->columnAlias];
            }
        }
        if ($values === []) {
            return null;
        }
        if (
            $aggregate->function === ReportAggregateFunction::Minimum
            || $aggregate->function === ReportAggregateFunction::Maximum
        ) {
            $selected = array_shift($values);
            foreach ($values as $value) {
                if (
                    $selected === null || $this->compare($value, $selected) * (
                    $aggregate->function === ReportAggregateFunction::Minimum ? 1 : -1
                    ) < 0
                ) {
                    $selected = $value;
                }
            }

            return is_bool($selected) ? (int) $selected : $selected;
        }
        $sum = DecimalValue::fromString('0');
        foreach ($values as $value) {
            if (!is_int($value) && !is_string($value)) {
                throw new ReportUnavailable('A numeric report aggregate received a non-numeric value.');
            }
            $sum = $sum->add(DecimalValue::fromString((string) $value));
        }
        if ($aggregate->function === ReportAggregateFunction::Average) {
            return $sum->divide(DecimalValue::fromString((string) count($values)), 6)->value();
        }

        return $sum->value();
    }

    /**
     * Sort report rows by the declared stable ordering.
     *
     * @param   ReportDefinition                           $report  Signed report definition governing query behavior.
     * @param   list<array<string, bool|int|string|null>>  $rows    Policy-filtered report rows being transformed.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function sort(ReportDefinition $report, array &$rows): void
    {
        if ($report->sorts === []) {
            return;
        }
        $decorated = [];
        foreach ($rows as $index => $row) {
            $decorated[] = ['index' => $index, 'row' => $row];
        }
        usort($decorated, function (array $left, array $right) use ($report): int {
            foreach ($report->sorts as $sort) {
                $comparison = $this->compareSort($left['row'], $right['row'], $sort);
                if ($comparison !== 0) {
                    return $comparison;
                }
            }

            return $left['index'] <=> $right['index'];
        });
        $rows = array_map(static fn (array $item): array => $item['row'], $decorated);
    }

    /**
     * Compare two rows using the declared report sort rules.
     *
     * @param   array<string, bool|int|string|null>  $left   Left row in the deterministic comparison.
     * @param   array<string, bool|int|string|null>  $right  Right row in the deterministic comparison.
     * @param   ReportSortDefinition                 $sort   Sort definition supplying field and direction.
     *
     * @return  int  Negative, zero, or positive ordering result for the declared sort.
     *
     * @since   2.0.0
     */
    private function compareSort(array $left, array $right, ReportSortDefinition $sort): int
    {
        $leftValue = $left[$sort->outputAlias] ?? null;
        $rightValue = $right[$sort->outputAlias] ?? null;
        if ($leftValue === null || $rightValue === null) {
            $comparison = $leftValue === $rightValue ? 0 : ($leftValue === null ? 1 : -1);
            if (!$sort->nullsLast) {
                $comparison *= -1;
            }
        } else {
            $comparison = $this->compare($leftValue, $rightValue);
        }

        return $sort->direction === ReportSortDirection::Ascending ? $comparison : -$comparison;
    }

    /**
     * Compare two normalized report values deterministically.
     *
     * @param   bool|int|string  $left   Left normalized value or row in the deterministic comparison.
     * @param   bool|int|string  $right  Right normalized value or row in the deterministic comparison.
     *
     * @return  int  Negative, zero, or positive ordering result.
     *
     * @since   2.0.0
     */
    private function compare(bool|int|string $left, bool|int|string $right): int
    {
        if ((is_int($left) || $this->decimal($left)) && (is_int($right) || $this->decimal($right))) {
            return DecimalValue::fromString((string) $left)->compare(DecimalValue::fromString((string) $right));
        }

        return (string) $left <=> (string) $right;
    }

    /**
     * Normalize a value into its decimal comparison representation.
     *
     * @param   bool|int|string  $value  Candidate value being validated or normalized.
     *
     * @return  bool  Whether the value is a canonical decimal string.
     *
     * @since   2.0.0
     */
    private function decimal(bool|int|string $value): bool
    {
        return is_string($value) && preg_match('/^-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/D', $value) === 1;
    }

    /**
     * Return output labels keyed by report column identifier.
     *
     * @param   ReportDefinition  $report  Signed report definition governing query behavior.
     *
     * @return  array<string, string>
     *
     * @since   2.0.0
     */
    private function labels(ReportDefinition $report): array
    {
        $labels = [];
        $groupAliases = array_fill_keys(array_map(
            static fn ($group): string => $group->columnAlias,
            $report->groups,
        ), true);
        foreach ($report->columns as $column) {
            if (($report->groups === [] && $report->aggregates === []) || isset($groupAliases[$column->alias])) {
                $labels[$column->alias] = $column->label;
            }
        }
        foreach ($report->aggregates as $aggregate) {
            $labels[$aggregate->alias] = $aggregate->alias;
        }
        foreach ($report->formulas as $formula) {
            $labels[$formula->alias] = $formula->label;
        }

        return $labels;
    }

    /**
     * Return output value types keyed by report column identifier.
     *
     * @param   ReportDefinition  $report  Signed report definition governing query behavior.
     *
     * @return  array<string, ReportValueType>
     *
     * @since   2.0.0
     */
    private function types(ReportDefinition $report): array
    {
        $types = [];
        $columns = [];
        $groupAliases = array_fill_keys(array_map(
            static fn ($group): string => $group->columnAlias,
            $report->groups,
        ), true);
        foreach ($report->columns as $column) {
            $columns[$column->alias] = $column->type;
            if (($report->groups === [] && $report->aggregates === []) || isset($groupAliases[$column->alias])) {
                $types[$column->alias] = $column->type;
            }
        }
        foreach ($report->aggregates as $aggregate) {
            $types[$aggregate->alias] = match ($aggregate->function) {
                ReportAggregateFunction::Count => ReportValueType::Integer,
                ReportAggregateFunction::Sum, ReportAggregateFunction::Average => ReportValueType::Decimal,
                default => $aggregate->columnAlias === null
                    ? throw new ReportUnavailable('A report aggregate source type is unavailable.')
                    : ($columns[$aggregate->columnAlias]
                        ?? throw new ReportUnavailable('A report aggregate source type is unavailable.')),
            };
        }
        foreach ($report->formulas as $formula) {
            $types[$formula->alias] = $formula->type;
        }

        return $types;
    }

    /**
     * Test the definition's signed delivery-surface exposure without performing an authorization decision.
     *
     * @param   ReportDefinition      $report   Active immutable report definition.
     * @param   AuthenticatedSurface  $surface  Delivery surface requesting discovery or execution.
     *
     * @return  bool  Whether the definition exposes itself to this surface.
     *
     * @since   2.0.0
     */
    private function surfaceAvailable(ReportDefinition $report, AuthenticatedSurface $surface): bool
    {
        return !(
            ($surface === AuthenticatedSurface::Administrator && !$report->administratorVisible)
            || ($surface === AuthenticatedSurface::Portal && !$report->portalVisible)
            || $surface === AuthenticatedSurface::Recovery
        );
    }

    /**
     * Normalize a scalar report value into bounded text.
     *
     * @param   mixed  $value  Candidate value being validated or normalized.
     *
     * @return  string  Bounded textual representation safe for report output.
     *
     * @since   2.0.0
     */
    private function text(mixed $value): string
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException('A report text filter requires one string parameter.');
        }

        return $value;
    }

    /**
     * Normalize a report value into a deterministic string set.
     *
     * @param   mixed  $value  Candidate value being validated or normalized.
     *
     * @return  non-empty-list<mixed>
     *
     * @since   2.0.0
     */
    private function set(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value) || $value === []) {
            throw new InvalidArgumentException('A report set filter requires a non-empty list parameter.');
        }

        return $value;
    }
}
