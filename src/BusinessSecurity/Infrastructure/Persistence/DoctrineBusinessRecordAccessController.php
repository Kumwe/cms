<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessSecurity\Infrastructure\Persistence;

use DateTimeZone;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use InvalidArgumentException;
use JsonException;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\App\BusinessDefinition\Domain\CanonicalDefinitionJson;
use Kumwe\App\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\App\BusinessDefinition\Domain\FieldDefinition;
use Kumwe\App\BusinessDefinition\Domain\IdentityStrategy;
use Kumwe\App\BusinessDefinition\Domain\ScopeMode;
use Kumwe\App\BusinessRecord\Application\BusinessRecordDefinitionResolver;
use Kumwe\App\BusinessRecord\Application\ResolvedBusinessDefinition;
use Kumwe\App\BusinessRecord\Domain\RecordScope;
use Kumwe\App\BusinessSecurity\Application\BusinessRecordAccessCatalogPlanner;
use Kumwe\App\BusinessSecurity\Application\BusinessRecordAccessController;
use Kumwe\App\BusinessSecurity\Application\BusinessRecordAccessOperationCatalogPlanner;
use Kumwe\App\BusinessSecurity\Application\BusinessRecordAccessPlan;
use Kumwe\Extension\Spi\BusinessSecurity\Application\FieldAccessUsage;
use Kumwe\Extension\Spi\BusinessSecurity\Application\FieldDisclosurePlan;
use Kumwe\App\BusinessSecurity\Application\MembershipDirectory;
use Kumwe\App\BusinessSecurity\Policy\RecordPolicyBoolean;
use Kumwe\App\BusinessSecurity\Policy\RecordPolicyBooleanOperator;
use Kumwe\Extension\Spi\BusinessSecurity\Policy\RecordPolicyComparison;
use Kumwe\Extension\Spi\BusinessSecurity\Policy\RecordPolicyComparisonOperator;
use Kumwe\App\BusinessSecurity\Policy\RecordPolicyConstant;
use Kumwe\App\BusinessSecurity\Policy\RecordPolicyNullCheck;
use Kumwe\Extension\Spi\BusinessSecurity\Policy\RecordPolicyPredicate;
use Kumwe\App\BusinessSecurity\Policy\RecordPolicySchema;
use Kumwe\App\BusinessSecurity\Policy\RecordPolicySet;
use Kumwe\Extension\Spi\BusinessSecurity\Policy\RecordPolicyValueType;
use Kumwe\App\BusinessSchema\Domain\SchemaEvolutionHints;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Psr\Clock\ClockInterface;
use RuntimeException;

/**
 * Resolves canonical row, field, relationship and action policy before a record repository is called.
 *
 * @since  2.0.0
 */
final readonly class DoctrineBusinessRecordAccessController implements
    BusinessRecordAccessOperationCatalogPlanner,
    BusinessRecordAccessController
{
    /**
     * Configure the policy catalog and trusted definition, membership, and clock dependencies.
     *
     * @param  Connection                        $database     Policy catalog connection.
     * @param  TableNames                        $tables       Portable table-name compiler.
     * @param  BusinessRecordDefinitionResolver  $definitions  Resolves related target definitions.
     * @param  MembershipDirectory               $memberships  Live membership freshness gate.
     * @param  ClockInterface                    $clock        Trusted instant for temporal policy attributes.
     *
     * @since  2.0.0
     */
    public function __construct(
        private Connection $database,
        private TableNames $tables,
        private BusinessRecordDefinitionResolver $definitions,
        private MembershipDirectory $memberships,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Resolve a bounded access plan for one actor, operation, definition, and record scope.
     *
     * @param   ExecutionContext            $context    Actor and exact authenticated scope.
     * @param   string                      $operation  Dotted business-record operation identifier.
     * @param   ResolvedBusinessDefinition  $resolved   Pinned entity definition being accessed.
     * @param   RecordScope                 $scope      Organization and optional workspace scope.
     *
     * @return  BusinessRecordAccessPlan  Immutable row, field, relation, and action decision.
     *
     * @since   2.0.0
     */
    public function plan(
        ExecutionContext $context,
        string $operation,
        ResolvedBusinessDefinition $resolved,
        RecordScope $scope,
    ): BusinessRecordAccessPlan {
        $this->assertMembership($context, $operation);
        $this->lockPolicySnapshot($context);

        return $this->resolvePlan($context, $operation, $resolved, $scope, 0);
    }

    /**
     * Resolve an active generated catalog from one policy snapshot and one batched policy read.
     *
     * @param ExecutionContext $context Actor and exact authenticated scope.
     * @param string $operation Dotted business-record operation identifier.
     * @param   list<array{resolved: ResolvedBusinessDefinition, scope: RecordScope, requested: bool}>  $resources
     *          Bounded active definitions, scopes, and top-level plan selections.
     *
     * @return  array<string, BusinessRecordAccessPlan>  Plans keyed by definition UUID.
     *
     * @throws  RuntimeException  When the resource batch is malformed, duplicated, or exceeds 4096 entries.
     *
     * @since   2.0.0
     */
    public function catalogPlans(
        ExecutionContext $context,
        string $operation,
        array $resources,
    ): array {
        if (!array_is_list($resources) || count($resources) > 4096) {
            throw new RuntimeException('A business-record policy catalog exceeds its safe bound.');
        }
        if ($resources === []) {
            return [];
        }
        $resolvedByHandle = [];
        $definitionIds = [];
        foreach ($resources as $resource) {
            $resolved = $resource['resolved'] ?? null;
            $scope = $resource['scope'] ?? null;
            $requested = $resource['requested'] ?? null;
            if (
                !($resolved instanceof ResolvedBusinessDefinition)
                || !($scope instanceof RecordScope)
                || !is_bool($requested)
                || isset($definitionIds[$resolved->definition->id])
                || isset($resolvedByHandle[$resolved->definition->handle])
            ) {
                throw new RuntimeException('A business-record policy catalog resource is invalid.');
            }
            $this->assertScope($context, $scope);
            $definitionIds[$resolved->definition->id] = true;
            $resolvedByHandle[$resolved->definition->handle] = $resolved;
        }
        $this->assertMembership($context, $operation);
        $this->lockPolicySnapshot($context);
        $rows = $this->rowsForDefinitions($context, $operation, array_keys($definitionIds));
        $plans = [];
        foreach ($resources as $resource) {
            if (!$resource['requested']) {
                continue;
            }
            $resolved = $resource['resolved'];
            $plans[$resolved->definition->id] = $this->resolvePlan(
                $context,
                $operation,
                $resolved,
                $resource['scope'],
                0,
                $rows,
                $resolvedByHandle,
            );
        }

        return $plans;
    }

    /**
     * Resolve several generated operations from one membership, policy-generation, and policy-row snapshot.
     *
     * @param ExecutionContext $context Actor and exact authenticated scope.
     * @param list<string> $operations Unique dotted business-record capabilities, capped at 32.
     * @param   list<array{resolved: ResolvedBusinessDefinition, scope: RecordScope, requested: bool}>  $resources
     *          Bounded active definitions, scopes, and top-level plan selections.
     *
     * @return  array<string, array<string, BusinessRecordAccessPlan>>  Plans by capability and definition UUID.
     *
     * @throws  RuntimeException  When an operation or resource batch is malformed, duplicated, or unbounded.
     *
     * @since   2.0.0
     */
    public function catalogOperationPlans(
        ExecutionContext $context,
        array $operations,
        array $resources,
    ): array {
        if (
            !array_is_list($operations)
            || $operations === []
            || count($operations) > 32
        ) {
            throw new RuntimeException('A business-record operation catalog is invalid or unbounded.');
        }
        $seenOperations = [];
        foreach ($operations as $operation) {
            if (
                !is_string($operation)
                || preg_match('/^business\.record\.[a-z][a-z0-9_.-]{0,62}$/D', $operation) !== 1
                || isset($seenOperations[$operation])
            ) {
                throw new RuntimeException('A business-record operation catalog contains an invalid capability.');
            }
            $seenOperations[$operation] = true;
        }
        if (!array_is_list($resources) || $resources === [] || count($resources) > 4096) {
            throw new RuntimeException('A business-record policy catalog exceeds its safe bound.');
        }
        $resolvedByHandle = [];
        $definitionIds = [];
        foreach ($resources as $resource) {
            $resolved = $resource['resolved'] ?? null;
            $scope = $resource['scope'] ?? null;
            $requested = $resource['requested'] ?? null;
            if (
                !($resolved instanceof ResolvedBusinessDefinition)
                || !($scope instanceof RecordScope)
                || !is_bool($requested)
                || isset($definitionIds[$resolved->definition->id])
                || isset($resolvedByHandle[$resolved->definition->handle])
            ) {
                throw new RuntimeException('A business-record policy catalog resource is invalid.');
            }
            $this->assertScope($context, $scope);
            $definitionIds[$resolved->definition->id] = true;
            $resolvedByHandle[$resolved->definition->handle] = $resolved;
        }
        $this->assertMembershipOperations($context, $operations);
        $this->lockPolicySnapshot($context);
        $rows = $this->rowsForDefinitionOperations($context, $operations, array_keys($definitionIds));
        $catalog = [];
        foreach ($operations as $operation) {
            $plans = [];
            foreach ($resources as $resource) {
                if (!$resource['requested']) {
                    continue;
                }
                $resolved = $resource['resolved'];
                $plans[$resolved->definition->id] = $this->resolvePlan(
                    $context,
                    $operation,
                    $resolved,
                    $resource['scope'],
                    0,
                    $rows[$operation],
                    $resolvedByHandle,
                );
            }
            $catalog[$operation] = $plans;
        }

        return $catalog;
    }

    /**
     * Hold a shared lock on the site's policy generation through plan execution.
     *
     * Policy administration increments this row in the same transaction as every conditional-policy
     * write. Shared locks therefore compose with concurrent record reads while forcing a policy writer
     * either to commit before this plan is resolved or to wait until its record operation finishes.
     *
     * @param   ExecutionContext  $context  Actor carrying the exact site whose policy snapshot is used.
     *
     * @return  void
     *
     * @throws  RuntimeException  When no transaction can retain the lock, the platform has no supported
     *          shared-lock syntax, or the authenticated site has disappeared.
     *
     * @since   2.0.0
     */
    private function lockPolicySnapshot(ExecutionContext $context): void
    {
        if (!$this->database->isTransactionActive()) {
            throw new RuntimeException('A business-record policy plan requires an active transaction.');
        }
        $platform = $this->database->getDatabasePlatform();
        $lock = match (true) {
            $platform instanceof AbstractMySQLPlatform => ' LOCK IN SHARE MODE',
            $platform instanceof PostgreSQLPlatform => ' FOR SHARE',
            default => throw new RuntimeException('The database has no shared business-policy lock.'),
        };
        $generation = $this->database->fetchOne(sprintf(
            'SELECT policy_generation FROM %s WHERE identifier = ?%s',
            $this->tables->quoted('sites'),
            $lock,
        ), [$context->site()->identifier()]);
        if (!is_int($generation) && (!is_string($generation) || !ctype_digit($generation))) {
            throw new RuntimeException('The authenticated site policy generation is unavailable.');
        }
        if ((int) $generation < 1) {
            throw new RuntimeException('The authenticated site policy generation is invalid.');
        }
    }

    /**
     * Resolve one plan and at most two bounded layers of related-target plans.
     *
     * @param ExecutionContext $context Actor and exact authenticated scope.
     * @param   string                                          $operation           Business operation.
     * @param   ResolvedBusinessDefinition                      $resolved            Pinned entity definition.
     * @param   RecordScope                                     $scope               Exact repository scope.
     * @param   int                                             $depth               Current related-resource depth.
     * @param   array<string, list<array<string, mixed>>>|null  $catalogRows         Batched policies by UUID.
     * @param   array<string, ResolvedBusinessDefinition>|null  $catalogDefinitions  Active targets by handle.
     *
     * @return  BusinessRecordAccessPlan  Immutable compiled authorization input.
     *
     * @throws  RuntimeException  When persisted policy is malformed or contradicts its definition.
     *
     * @since   2.0.0
     */
    private function resolvePlan(
        ExecutionContext $context,
        string $operation,
        ResolvedBusinessDefinition $resolved,
        RecordScope $scope,
        int $depth,
        ?array $catalogRows = null,
        ?array $catalogDefinitions = null,
    ): BusinessRecordAccessPlan {
        $this->assertScope($context, $scope);
        $rows = $catalogRows === null
            ? $this->rows($context, $operation, $resolved)
            : ($catalogRows[$resolved->definition->id] ?? []);
        $schema = $this->schema($resolved->definition);
        $allows = [];
        $denies = [];
        $fields = $this->emptyFields();
        $fieldAllows = [];
        $fieldDenies = $this->emptyFields();
        $actions = [];
        $actionAllows = [];
        $actionDenies = [];
        foreach ($rows as $row) {
            $effect = $this->string($row, 'effect');
            if (!in_array($effect, ['allow', 'deny'], true)) {
                throw new RuntimeException('A stored business-record policy effect is invalid.');
            }
            $predicate = $this->predicate(
                $this->document($row['canonical_ast'] ?? null),
                $context,
                $resolved,
                $operation,
            );
            if ($effect === 'allow') {
                $allows[] = $predicate;
            } else {
                $denies[] = $predicate;
            }
            $mayMatch = !($predicate instanceof RecordPolicyConstant) || $predicate->value;
            $rules = $this->document($row['field_rules'] ?? null);
            $knownRules = [...array_map(
                static fn (FieldAccessUsage $usage): string => $usage->value,
                FieldAccessUsage::cases(),
            ), 'actions'];
            if (array_diff(array_keys($rules), $knownRules) !== []) {
                throw new RuntimeException('A stored field policy contains an unknown usage.');
            }
            $rowFields = $this->emptyFields();
            foreach (FieldAccessUsage::cases() as $usage) {
                $configured = $rules[$usage->value] ?? [];
                if (!is_array($configured) || !array_is_list($configured)) {
                    throw new RuntimeException('A stored field-policy usage must be a list.');
                }
                $currentFields = [];
                foreach ($configured as $field) {
                    if (!is_string($field)) {
                        throw new RuntimeException('A stored field policy references an unavailable field.');
                    }
                    $currentField = $this->currentField($resolved->definition, $field);
                    if (!$this->fieldAvailable($resolved->definition, $usage, $currentField)) {
                        throw new RuntimeException('A stored field policy references an unavailable field.');
                    }
                    $currentFields[] = $currentField;
                }
                if ($effect === 'allow' && $mayMatch) {
                    $rowFields[$usage->value] = array_values(array_unique($currentFields));
                } elseif ($effect === 'deny' && $mayMatch) {
                    array_push($fieldDenies[$usage->value], ...$currentFields);
                }
            }
            $configuredActions = $rules['actions'] ?? [];
            if (!is_array($configuredActions) || !array_is_list($configuredActions)) {
                throw new RuntimeException('A stored action policy must be a list.');
            }
            foreach ($configuredActions as $action) {
                if (!is_string($action) || !$this->knownAction($resolved->definition, $action)) {
                    throw new RuntimeException('A stored action policy references an unavailable action.');
                }
            }
            if ($effect === 'allow' && $mayMatch) {
                $fieldAllows[] = $rowFields;
                $actionAllows[] = array_values(array_unique($configuredActions));
            } elseif ($effect === 'deny' && $mayMatch) {
                array_push($actionDenies, ...$configuredActions);
            }
        }
        if ($rows !== []) {
            foreach (FieldAccessUsage::cases() as $usage) {
                if ($fieldAllows === []) {
                    $fields[$usage->value] = [];
                    continue;
                }
                $permitted = $fieldAllows[0][$usage->value];
                foreach (array_slice($fieldAllows, 1) as $allowed) {
                    $permitted = array_values(array_intersect($permitted, $allowed[$usage->value]));
                }
                $fields[$usage->value] = $permitted;
            }
            if ($actionAllows !== []) {
                $actions = $actionAllows[0];
                foreach (array_slice($actionAllows, 1) as $allowed) {
                    $actions = array_values(array_intersect($actions, $allowed));
                }
            }
        }
        foreach (FieldAccessUsage::cases() as $usage) {
            $fields[$usage->value] = array_values(array_diff(
                array_unique($fields[$usage->value]),
                array_unique($fieldDenies[$usage->value]),
            ));
        }
        $actions = array_values(array_diff(array_unique($actions), array_unique($actionDenies)));
        $related = $depth < 2
            ? $this->related(
                $context,
                $operation,
                $resolved,
                $depth + 1,
                $catalogRows,
                $catalogDefinitions,
            )
            : [];

        $policyFingerprints = $this->policyFingerprints($context, $rows);

        return new BusinessRecordAccessPlan(
            $resolved->definition->id,
            $operation,
            new RecordPolicySet($schema, $allows, $denies),
            new FieldDisclosurePlan($fields),
            $policyFingerprints['strict'],
            $related,
            $actions,
            $policyFingerprints['durable'],
        );
    }

    /**
     * Load only policies that match the exact action, definition and organization before evaluation.
     *
     * @param   ExecutionContext            $context    Actor and exact authenticated scope.
     * @param   string                      $operation  Business operation matched against stored policies.
     * @param   ResolvedBusinessDefinition  $resolved   Definition whose resource policies are loaded.
     *
     * @return  list<array<string,mixed>>  Canonically ordered matching policy rows.
     *
     * @since   2.0.0
     */
    private function rows(
        ExecutionContext $context,
        string $operation,
        ResolvedBusinessDefinition $resolved,
    ): array {
        return $this->database->fetchAllAssociative(sprintf(
            'SELECT p.policy_code, p.effect, p.canonical_ast, p.field_rules, p.ast_checksum, '
            . 'p.policy_version, p.owner_kind, p.owner_identifier FROM %s p '
            . 'LEFT JOIN %s o ON o.id = p.organization_id '
            . "LEFT JOIN %s e ON p.owner_kind = 'extension' AND e.identifier = p.owner_identifier "
            . "WHERE p.status = 'active' AND p.resource_type = 'business_record' AND p.action = ? "
            . 'AND (p.entity_definition_id IS NULL OR p.entity_definition_id = ?) '
            . 'AND (p.organization_id IS NULL OR (o.identifier = ? AND o.site_identifier = ? '
            . "AND o.status = 'active')) "
            . "AND (p.owner_kind = 'core' OR (p.owner_kind = 'extension' AND e.status = 'active')) "
            . 'ORDER BY p.priority DESC, p.policy_code',
            $this->tables->quoted('resource_policies'),
            $this->tables->quoted('organizations'),
            $this->tables->quoted('extensions'),
        ), [
            $operation,
            $resolved->definition->id,
            $context->organization()?->identifier() ?? '__no_organization__',
            $context->site()->identifier(),
        ]);
    }

    /**
     * Load policies for every catalog definition in one bounded statement while preserving plan order.
     *
     * Global policies are copied into every definition's row list at their exact priority position, so a
     * batched plan has the same canonical fingerprint and field intersection as an individual `plan()` call.
     * The caller already bounds the UUID list at 4096 and all values remain bound parameters.
     *
     * @param   ExecutionContext  $context        Actor and exact authenticated scope.
     * @param   string            $operation      Business-record operation matched by stored policies.
     * @param   list<string>      $definitionIds  Unique active definition UUIDs.
     *
     * @return  array<string, list<array<string, mixed>>>  Policy rows keyed by definition UUID.
     *
     * @since   2.0.0
     */
    private function rowsForDefinitions(
        ExecutionContext $context,
        string $operation,
        array $definitionIds,
    ): array {
        $grouped = array_fill_keys($definitionIds, []);
        $rows = $this->database->fetchAllAssociative(sprintf(
            'SELECT p.policy_code, p.effect, p.canonical_ast, p.field_rules, p.ast_checksum, '
            . 'p.policy_version, p.owner_kind, p.owner_identifier, p.entity_definition_id FROM %s p '
            . 'LEFT JOIN %s o ON o.id = p.organization_id '
            . "LEFT JOIN %s e ON p.owner_kind = 'extension' AND e.identifier = p.owner_identifier "
            . "WHERE p.status = 'active' AND p.resource_type = 'business_record' AND p.action = ? "
            . 'AND (p.entity_definition_id IS NULL OR p.entity_definition_id IN (?)) '
            . 'AND (p.organization_id IS NULL OR (o.identifier = ? AND o.site_identifier = ? '
            . "AND o.status = 'active')) "
            . "AND (p.owner_kind = 'core' OR (p.owner_kind = 'extension' AND e.status = 'active')) "
            . 'ORDER BY p.priority DESC, p.policy_code',
            $this->tables->quoted('resource_policies'),
            $this->tables->quoted('organizations'),
            $this->tables->quoted('extensions'),
        ), [
            $operation,
            $definitionIds,
            $context->organization()?->identifier() ?? '__no_organization__',
            $context->site()->identifier(),
        ], [
            ParameterType::STRING,
            ArrayParameterType::STRING,
            ParameterType::STRING,
            ParameterType::STRING,
        ]);
        foreach ($rows as $row) {
            $definitionId = $row['entity_definition_id'] ?? null;
            unset($row['entity_definition_id']);
            if ($definitionId === null) {
                foreach ($definitionIds as $candidate) {
                    $grouped[$candidate][] = $row;
                }
                continue;
            }
            if (!is_string($definitionId) || !array_key_exists($definitionId, $grouped)) {
                throw new RuntimeException('A batched business-record policy names an unexpected resource.');
            }
            $grouped[$definitionId][] = $row;
        }

        return $grouped;
    }

    /**
     * Load policies for several exact operations and definitions in one bounded database statement.
     *
     * @param   ExecutionContext  $context        Actor and exact authenticated scope.
     * @param   list<string>      $operations     Validated unique capabilities.
     * @param   list<string>      $definitionIds  Validated unique active definition UUIDs.
     *
     * @return  array<string, array<string, list<array<string, mixed>>>>  Rows by capability and definition UUID.
     *
     * @throws  RuntimeException  When a returned policy names an operation or definition outside the request.
     *
     * @since   2.0.0
     */
    private function rowsForDefinitionOperations(
        ExecutionContext $context,
        array $operations,
        array $definitionIds,
    ): array {
        $grouped = [];
        foreach ($operations as $operation) {
            $grouped[$operation] = array_fill_keys($definitionIds, []);
        }
        $rows = $this->database->fetchAllAssociative(sprintf(
            'SELECT p.action, p.policy_code, p.effect, p.canonical_ast, p.field_rules, p.ast_checksum, '
            . 'p.policy_version, p.owner_kind, p.owner_identifier, p.entity_definition_id FROM %s p '
            . 'LEFT JOIN %s o ON o.id = p.organization_id '
            . "LEFT JOIN %s e ON p.owner_kind = 'extension' AND e.identifier = p.owner_identifier "
            . "WHERE p.status = 'active' AND p.resource_type = 'business_record' AND p.action IN (?) "
            . 'AND (p.entity_definition_id IS NULL OR p.entity_definition_id IN (?)) '
            . 'AND (p.organization_id IS NULL OR (o.identifier = ? AND o.site_identifier = ? '
            . "AND o.status = 'active')) "
            . "AND (p.owner_kind = 'core' OR (p.owner_kind = 'extension' AND e.status = 'active')) "
            . 'ORDER BY p.action, p.priority DESC, p.policy_code',
            $this->tables->quoted('resource_policies'),
            $this->tables->quoted('organizations'),
            $this->tables->quoted('extensions'),
        ), [
            $operations,
            $definitionIds,
            $context->organization()?->identifier() ?? '__no_organization__',
            $context->site()->identifier(),
        ], [
            ArrayParameterType::STRING,
            ArrayParameterType::STRING,
            ParameterType::STRING,
            ParameterType::STRING,
        ]);
        foreach ($rows as $row) {
            $operation = $row['action'] ?? null;
            $definitionId = $row['entity_definition_id'] ?? null;
            unset($row['action'], $row['entity_definition_id']);
            if (!is_string($operation) || !array_key_exists($operation, $grouped)) {
                throw new RuntimeException('A batched business-record policy names an unexpected operation.');
            }
            if ($definitionId === null) {
                foreach ($definitionIds as $candidate) {
                    $grouped[$operation][$candidate][] = $row;
                }
                continue;
            }
            if (!is_string($definitionId) || !array_key_exists($definitionId, $grouped[$operation])) {
                throw new RuntimeException('A batched business-record policy names an unexpected resource.');
            }
            $grouped[$operation][$definitionId][] = $row;
        }

        return $grouped;
    }

    /**
     * Resolve relationship and entity-reference targets under the same authenticated scope.
     *
     * @param ExecutionContext $context Actor and exact authenticated scope.
     * @param string $operation Parent operation inherited by every target plan.
     * @param ResolvedBusinessDefinition $resolved Source definition declaring the target handles.
     * @param int $depth Depth assigned to each directly related target.
     * @param   array<string, list<array<string, mixed>>>|null  $catalogRows         Batched policies by UUID.
     * @param   array<string, ResolvedBusinessDefinition>|null  $catalogDefinitions  Active targets by handle.
     *
     * @return  array<string, BusinessRecordAccessPlan>  Target plans keyed by source handle.
     *
     * @since   2.0.0
     */
    private function related(
        ExecutionContext $context,
        string $operation,
        ResolvedBusinessDefinition $resolved,
        int $depth,
        ?array $catalogRows = null,
        ?array $catalogDefinitions = null,
    ): array {
        $targets = [];
        foreach ($resolved->definition->fields() as $field) {
            if (
                in_array($field->type, ['core.entity_reference', 'core.ordered_lines'], true)
                && is_string($field->configuration['target'] ?? null)
            ) {
                $targets[$field->handle] = $field->configuration['target'];
            }
        }
        foreach ($resolved->definition->relationships() as $relationship) {
            $targets[$relationship->handle] = $relationship->target;
        }
        $plans = [];
        $byTarget = [];
        foreach ($targets as $handle => $targetHandle) {
            if (isset($byTarget[$targetHandle])) {
                $plans[$handle] = $byTarget[$targetHandle];
                continue;
            }
            if ($catalogDefinitions === null) {
                $target = $operation === 'business.record.history'
                    ? $this->definitions->forHistory($context, $targetHandle)
                    : $this->definitions->forCreate($context, $targetHandle);
            } else {
                $target = $catalogDefinitions[$targetHandle] ?? null;
                if (!($target instanceof ResolvedBusinessDefinition)) {
                    continue;
                }
            }
            try {
                $targetScope = RecordScope::forDefinition(
                    $target->definition->scope,
                    $context->site(),
                    in_array(
                        $target->definition->scope,
                        [ScopeMode::Organization, ScopeMode::SiteOrganization],
                        true,
                    ) ? $context->organization()?->identifier() : null,
                );
            } catch (InvalidArgumentException $exception) {
                if ($catalogDefinitions === null) {
                    throw $exception;
                }
                continue;
            }
            $plan = $this->resolvePlan(
                $context,
                $operation,
                $target,
                $targetScope,
                $depth,
                $catalogRows,
                $catalogDefinitions,
            );
            $byTarget[$targetHandle] = $plan;
            $plans[$handle] = $plan;
        }

        return $plans;
    }

    /**
     * Build the closed policy type schema from supported scalar fields.
     *
     * @param   EntityTypeDefinition  $definition  Definition supplying typed policy fields.
     *
     * @return  RecordPolicySchema  Field handles and portable comparison domains.
     *
     * @since   2.0.0
     */
    private function schema(EntityTypeDefinition $definition): RecordPolicySchema
    {
        $fields = [];
        foreach ($definition->fields() as $field) {
            $type = match ($field->type) {
                'core.boolean' => RecordPolicyValueType::Boolean,
                'core.integer' => RecordPolicyValueType::Integer,
                'core.decimal' => RecordPolicyValueType::Decimal,
                'core.string', 'core.text', 'core.rich_text', 'core.email', 'core.url', 'core.phone',
                'core.uuid', 'core.media_reference', 'core.reference_identity', 'core.enum',
                'core.entity_reference' => RecordPolicyValueType::String,
                'core.date', 'core.local_time', 'core.instant' => RecordPolicyValueType::Temporal,
                default => null,
            };
            if ($type !== null) {
                $fields[$field->handle] = $type;
            }
        }

        return new RecordPolicySchema($fields);
    }

    /**
     * Build explicit empty field sets for every supported disclosure usage.
     *
     * @return  array<string, list<string>>  Empty field handles keyed by usage identifier.
     *
     * @since   2.0.0
     */
    private function emptyFields(): array
    {
        $fields = [];
        foreach (FieldAccessUsage::cases() as $usage) {
            $fields[$usage->value] = [];
        }

        return $fields;
    }

    /**
     * Parse one bounded canonical predicate document.
     *
     * @param   array<string,mixed>         $document   Stored AST node.
     * @param   ExecutionContext            $context    Actor and exact authenticated scope.
     * @param   ResolvedBusinessDefinition  $resolved   Definition whose resource attributes may be addressed.
     * @param   string                      $operation  Operation available as a closed resource attribute.
     *
     * @return  RecordPolicyPredicate  Closed executable-free policy node.
     *
     * @since   2.0.0
     */
    private function predicate(
        array $document,
        ExecutionContext $context,
        ResolvedBusinessDefinition $resolved,
        string $operation,
    ): RecordPolicyPredicate {
        $type = $document['type'] ?? null;
        if ($type === 'constant' && is_bool($document['value'] ?? null) && count($document) === 2) {
            return new RecordPolicyConstant($document['value']);
        }
        if (
            $type === 'null'
            && is_string($document['field'] ?? null)
            && is_bool($document['is_null'] ?? null)
            && count($document) === 3
        ) {
            return new RecordPolicyNullCheck(
                $this->currentField($resolved->definition, $document['field']),
                $document['is_null'],
            );
        }
        if ($type === 'comparison' && count($document) === 5) {
            $field = $document['field'] ?? null;
            $operator = $document['operator'] ?? null;
            $valueType = $document['value_type'] ?? null;
            $value = $document['value'] ?? null;
            if (!is_string($field) || !is_string($operator) || !is_string($valueType)) {
                throw new RuntimeException('A stored comparison policy is invalid.');
            }

            $comparison = new RecordPolicyComparison(
                $this->currentField($resolved->definition, $field),
                RecordPolicyComparisonOperator::tryFrom($operator)
                    ?? throw new RuntimeException('A stored comparison operator is invalid.'),
                RecordPolicyValueType::tryFrom($valueType)
                    ?? throw new RuntimeException('A stored comparison type is invalid.'),
                is_string($value) || is_int($value) || is_bool($value)
                    ? $value
                    : throw new RuntimeException('A stored comparison literal is invalid.'),
            );
            $this->assertTemporalFieldDomain($resolved->definition, $comparison);

            return $comparison;
        }
        if ($type === 'attribute_comparison' && count($document) === 6) {
            $source = $document['source'] ?? null;
            $attribute = $document['attribute'] ?? null;
            $operator = $document['operator'] ?? null;
            $valueType = $document['value_type'] ?? null;
            $value = $document['value'] ?? null;
            if (
                !is_string($source)
                || !is_string($attribute)
                || !is_string($operator)
                || !is_string($valueType)
                || (!is_string($value) && !is_int($value) && !is_bool($value))
            ) {
                throw new RuntimeException('A stored attribute comparison is invalid.');
            }
            $typeValue = RecordPolicyValueType::tryFrom($valueType)
                ?? throw new RuntimeException('A stored attribute comparison type is invalid.');
            $comparison = new RecordPolicyComparison(
                'attribute',
                RecordPolicyComparisonOperator::tryFrom($operator)
                    ?? throw new RuntimeException('A stored attribute comparison operator is invalid.'),
                $typeValue,
                $value,
            );
            [$actual, $actualType] = $this->attribute($source, $attribute, $context, $resolved, $operation);
            $expected = $comparison->value;
            if ($actualType !== $typeValue) {
                throw new RuntimeException('A stored attribute comparison type does not match its attribute.');
            }
            if (
                $typeValue === RecordPolicyValueType::Temporal
                && is_string($actual)
                && $this->temporalDomain($actual) !== $this->temporalDomain((string) $expected)
            ) {
                throw new RuntimeException('A stored attribute comparison uses another temporal domain.');
            }
            if (
                $typeValue === RecordPolicyValueType::Temporal
                && is_string($actual)
                && preg_match('/^[0-9]{2}:[0-9]{2}:[0-9]{2}$/D', (string) $expected) === 1
            ) {
                $expected = (string) $expected . '.000000';
            }

            return new RecordPolicyConstant(
                $actual !== null
                && $this->compare($actual, $expected, $comparison->operator),
            );
        }
        if ($type === 'field_attribute_comparison' && count($document) === 6) {
            $field = $document['field'] ?? null;
            $source = $document['source'] ?? null;
            $attribute = $document['attribute'] ?? null;
            $operator = $document['operator'] ?? null;
            $valueType = $document['value_type'] ?? null;
            if (
                !is_string($field)
                || !is_string($source)
                || !is_string($attribute)
                || !is_string($operator)
                || !is_string($valueType)
            ) {
                throw new RuntimeException('A stored field-to-attribute comparison is invalid.');
            }
            $typeValue = RecordPolicyValueType::tryFrom($valueType)
                ?? throw new RuntimeException('A stored field-to-attribute type is invalid.');
            [$actual, $actualType] = $this->attribute($source, $attribute, $context, $resolved, $operation);
            if ($actualType !== $typeValue) {
                throw new RuntimeException('A stored field-to-attribute type does not match its attribute.');
            }
            if ($actual === null) {
                return new RecordPolicyConstant(false);
            }

            $comparison = new RecordPolicyComparison(
                $this->currentField($resolved->definition, $field),
                RecordPolicyComparisonOperator::tryFrom($operator)
                    ?? throw new RuntimeException('A stored field-to-attribute operator is invalid.'),
                $typeValue,
                $actual,
            );
            $this->assertTemporalFieldDomain($resolved->definition, $comparison);

            return $comparison;
        }
        if ($type === 'attribute_null' && count($document) === 4) {
            $source = $document['source'] ?? null;
            $attribute = $document['attribute'] ?? null;
            $isNull = $document['is_null'] ?? null;
            if (!is_string($source) || !is_string($attribute) || !is_bool($isNull)) {
                throw new RuntimeException('A stored attribute null check is invalid.');
            }
            [$actual] = $this->attribute($source, $attribute, $context, $resolved, $operation);

            return new RecordPolicyConstant($isNull === ($actual === null));
        }
        if ($type === 'boolean' && count($document) === 3) {
            $operator = $document['operator'] ?? null;
            $children = $document['children'] ?? null;
            if (!is_string($operator) || !is_array($children) || !array_is_list($children)) {
                throw new RuntimeException('A stored boolean policy is invalid.');
            }
            $parsed = [];
            foreach ($children as $child) {
                if (
                    !is_array($child)
                    || array_is_list($child)
                    || array_any(array_keys($child), static fn (mixed $key): bool => !is_string($key))
                ) {
                    throw new RuntimeException('A stored boolean child policy is invalid.');
                }
                /** @var array<string, mixed> $child */
                $parsed[] = $this->predicate($child, $context, $resolved, $operation);
            }

            return new RecordPolicyBoolean(
                RecordPolicyBooleanOperator::tryFrom($operator)
                    ?? throw new RuntimeException('A stored boolean operator is invalid.'),
                $parsed,
            );
        }

        throw new RuntimeException('A stored business-record policy AST is unsupported.');
    }

    /**
     * Resolve one closed principal, context, membership, or resource attribute.
     *
     * @param   string                      $source     Allowlisted attribute source.
     * @param   string                      $attribute  Allowlisted attribute name.
     * @param   ExecutionContext            $context    Actor and exact authenticated scope.
     * @param   ResolvedBusinessDefinition  $resolved   Definition supplying resource attributes.
     * @param   string                      $operation  Operation supplying the resource-operation attribute.
     *
     * @return  array{string|int|bool|null, RecordPolicyValueType}  Exact value and declared scalar type.
     *
     * @throws  RuntimeException  When the source and attribute pair is not allowlisted.
     *
     * @since   2.0.0
     */
    private function attribute(
        string $source,
        string $attribute,
        ExecutionContext $context,
        ResolvedBusinessDefinition $resolved,
        string $operation,
    ): array {
        return match ($source . '.' . $attribute) {
            'principal.id' => [$context->actorId(), RecordPolicyValueType::String],
            'principal.security_epoch' => [
                $context->principal()?->securityEpoch(),
                RecordPolicyValueType::Integer,
            ],
            'context.site' => [$context->site()->identifier(), RecordPolicyValueType::String],
            'context.organization' => [
                $context->organization()?->identifier(),
                RecordPolicyValueType::String,
            ],
            'context.workspace' => [$context->workspace()?->identifier(), RecordPolicyValueType::String],
            'context.surface' => [$context->surface()->value, RecordPolicyValueType::String],
            'context.authentication_strength' => [
                $context->authenticationStrength()->value,
                RecordPolicyValueType::String,
            ],
            'context.now' => [
                $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s.u\\Z'),
                RecordPolicyValueType::Temporal,
            ],
            'context.today' => [
                $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d'),
                RecordPolicyValueType::Temporal,
            ],
            'context.time' => [
                $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('H:i:s.u'),
                RecordPolicyValueType::Temporal,
            ],
            'membership.id' => [$context->membership()?->membershipId(), RecordPolicyValueType::String],
            'membership.version' => [
                $context->membership()?->membershipVersion(),
                RecordPolicyValueType::Integer,
            ],
            'membership.policy_generation' => [
                $context->membership()?->policyGeneration(),
                RecordPolicyValueType::Integer,
            ],
            'resource.definition_id' => [$resolved->definition->id, RecordPolicyValueType::String],
            'resource.definition_version' => [
                $resolved->definition->definitionVersion,
                RecordPolicyValueType::Integer,
            ],
            'resource.operation' => [$operation, RecordPolicyValueType::String],
            'resource.scope_mode' => [$resolved->definition->scope->value, RecordPolicyValueType::String],
            default => throw new RuntimeException('A stored policy attribute is not allowlisted.'),
        };
    }

    /**
     * Require a temporal literal to use the exact domain of its definition field.
     *
     * The public policy scalar remains `temporal`, while storage distinguishes dates, local times, and
     * UTC instants. Rejecting a cross-domain predicate here keeps the in-memory evaluator and every SQL
     * platform from assigning different meaning to the same persisted bytes.
     *
     * @param   EntityTypeDefinition    $definition  Definition supplying the field's exact temporal kind.
     * @param   RecordPolicyComparison  $comparison  Validated comparison whose literal is checked.
     *
     * @return  void
     *
     * @throws  RuntimeException  When a temporal comparison crosses date, time, and instant domains.
     *
     * @since   2.0.0
     */
    private function assertTemporalFieldDomain(
        EntityTypeDefinition $definition,
        RecordPolicyComparison $comparison,
    ): void {
        if ($comparison->valueType !== RecordPolicyValueType::Temporal) {
            return;
        }
        $fieldType = null;
        foreach ($definition->fields() as $field) {
            if ($field->handle === $comparison->field) {
                $fieldType = $field->type;
                break;
            }
        }
        $expected = match ($fieldType) {
            'core.date' => 'date',
            'core.local_time' => 'time',
            'core.instant' => 'instant',
            default => null,
        };
        if ($expected === null || $this->temporalDomain((string) $comparison->value) !== $expected) {
            throw new RuntimeException('A stored comparison uses another temporal field domain.');
        }
    }

    /**
     * Classify one already-canonical temporal scalar without parsing or timezone coercion.
     *
     * @param   string  $value  Canonical date, local time, or UTC instant.
     *
     * @return  ?string  Closed domain name, or null for malformed trusted state.
     *
     * @since   2.0.0
     */
    private function temporalDomain(string $value): ?string
    {
        return match (true) {
            preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $value) === 1 => 'date',
            preg_match('/^[0-9]{2}:[0-9]{2}:[0-9]{2}(?:\.[0-9]{6})?$/D', $value) === 1 => 'time',
            preg_match(
                '/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}\.[0-9]{6}Z$/D',
                $value,
            ) === 1 => 'instant',
            default => null,
        };
    }

    /**
     * Compare two already type-checked scalar attributes without coercion.
     *
     * @param   string|int|bool                 $actual    Trusted attribute value.
     * @param   string|int|bool                 $expected  Canonical policy literal.
     * @param   RecordPolicyComparisonOperator  $operator  Closed comparison operation.
     *
     * @return  bool  Comparison result under the closed portable operator set.
     *
     * @since   2.0.0
     */
    private function compare(
        string|int|bool $actual,
        string|int|bool $expected,
        RecordPolicyComparisonOperator $operator,
    ): bool {
        if (get_debug_type($actual) !== get_debug_type($expected)) {
            return false;
        }
        $comparison = $actual <=> $expected;

        return match ($operator) {
            RecordPolicyComparisonOperator::Equal => $comparison === 0,
            RecordPolicyComparisonOperator::NotEqual => $comparison !== 0,
            RecordPolicyComparisonOperator::LessThan => $comparison < 0,
            RecordPolicyComparisonOperator::LessThanOrEqual => $comparison <= 0,
            RecordPolicyComparisonOperator::GreaterThan => $comparison > 0,
            RecordPolicyComparisonOperator::GreaterThanOrEqual => $comparison >= 0,
        };
    }

    /**
     * Bind strict and durable plans to exact stored policy bytes, versions, owners, and caller authority.
     *
     * @param   ExecutionContext           $context  Actor and exact authenticated scope.
     * @param   list<array<string,mixed>>  $rows     Matching policy rows in canonical priority order.
     *
     * @return  array{strict: string, durable: string}  Credential-bound and approval-bound policy fingerprints.
     *
     * @throws  RuntimeException  When a stored checksum is malformed or does not match the documents.
     *
     * @since   2.0.0
     */
    private function policyFingerprints(ExecutionContext $context, array $rows): array
    {
        $policies = [];
        foreach ($rows as $row) {
            $documentChecksum = CanonicalDefinitionJson::checksum([
                'ast' => $this->document($row['canonical_ast'] ?? null),
                'fields' => $this->document($row['field_rules'] ?? null),
            ]);
            $storedChecksum = $this->string($row, 'ast_checksum');
            if (!hash_equals($storedChecksum, $documentChecksum)) {
                throw new RuntimeException('A stored business-record policy checksum is invalid.');
            }
            $policies[] = [
                'code' => $this->string($row, 'policy_code'),
                'effect' => $this->string($row, 'effect'),
                'checksum' => $documentChecksum,
                'version' => $this->positiveInteger($row, 'policy_version'),
                'owner_kind' => $this->string($row, 'owner_kind'),
                'owner_identifier' => $this->string($row, 'owner_identifier'),
            ];
        }

        return [
            'strict' => CanonicalDefinitionJson::checksum([
                'authorization' => $context->authorizationFingerprint(),
                'policies' => $policies,
            ]),
            'durable' => CanonicalDefinitionJson::checksum([
                'authorization' => $context->approvalFingerprint(),
                'policies' => $policies,
            ]),
        ];
    }

    /**
     * Decode a JSON object with a strict depth and size bound.
     *
     * @param   mixed  $value  Driver-decoded array or stored JSON text.
     *
     * @return  array<string,mixed>  Decoded object.
     *
     * @throws  RuntimeException  When the policy document is malformed or oversized.
     *
     * @since   2.0.0
     */
    private function document(mixed $value): array
    {
        try {
            if (is_string($value)) {
                if (strlen($value) > 65_536) {
                    throw new RuntimeException('A stored policy document exceeds 65536 bytes.');
                }
                $value = json_decode($value, true, 16, JSON_THROW_ON_ERROR);
            }
        } catch (JsonException $exception) {
            throw new RuntimeException('A stored policy document is invalid JSON.', 0, $exception);
        }
        if (
            !is_array($value)
            || array_is_list($value)
            || array_any(array_keys($value), static fn (mixed $key): bool => !is_string($key))
        ) {
            throw new RuntimeException('A stored policy document must be an object.');
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * Enforce immutable definition ceilings before a policy may grant one field usage.
     *
     * Dynamic policy can only narrow the schema contract. It cannot make a server-only field writable,
     * a hidden field readable, a sensitive field queryable, or a non-reportable/exportable field leave
     * through those surfaces.
     *
     * @param   EntityTypeDefinition  $definition  Definition supplying immutable field flags.
     * @param   FieldAccessUsage      $usage       Exact access the stored policy attempts to grant or deny.
     * @param   string                $handle      Field handle named by the stored rule.
     *
     * @return  bool  True only when the definition itself admits that field and usage.
     *
     * @since   2.0.0
     */
    private function fieldAvailable(
        EntityTypeDefinition $definition,
        FieldAccessUsage $usage,
        string $handle,
    ): bool {
        $field = null;
        foreach ($definition->fields() as $candidate) {
            if ($candidate->handle === $handle) {
                $field = $candidate;
                break;
            }
        }
        if (!$field instanceof FieldDefinition) {
            return false;
        }
        $readable = $field->readVisible;
        $queryable = $readable
            && !in_array($field->sensitivity->value, ['restricted', 'secret'], true);

        return match ($usage) {
            FieldAccessUsage::Create => $field->createVisible
                && !$field->serverOnly
                && !$field->computed
                && $field->formula === null,
            FieldAccessUsage::Update => $field->updateVisible
                && !$field->serverOnly
                && !$field->readOnly
                && !$field->computed
                && $field->formula === null,
            FieldAccessUsage::Detail,
            FieldAccessUsage::List,
            FieldAccessUsage::Audit,
            FieldAccessUsage::Mcp,
            FieldAccessUsage::Include => $readable,
            FieldAccessUsage::Filter => $queryable && $field->filterable,
            FieldAccessUsage::Relation => $queryable && $field->filterable,
            FieldAccessUsage::Search => $queryable && $field->searchable,
            FieldAccessUsage::Sort => $queryable && $field->sortable,
            FieldAccessUsage::Aggregate,
            FieldAccessUsage::Report => $queryable && $field->reportable,
            FieldAccessUsage::Export => $queryable && $field->exportable,
            FieldAccessUsage::PublicReference => $queryable && $field->type === (
                $definition->identityStrategy === IdentityStrategy::Uuid
                    ? 'core.uuid'
                    : 'core.reference_identity'
            ),
        };
    }

    /**
     * Resolve a stored policy handle through the definition's explicit record-column rename contract.
     *
     * Policies remain immutable audit documents while a published evolution may deliberately rename a
     * field. Only the trusted, validated schema-evolution hint can bridge that name; a removed or misspelled
     * policy field without an exact rename remains unavailable and therefore fails closed.
     *
     * @param   EntityTypeDefinition  $definition  Current published definition and its validated hints.
     * @param   string                $handle      Field handle persisted in the policy document.
     *
     * @return  string  Current field handle, or the original handle when no rename is declared.
     *
     * @since   2.0.0
     */
    private function currentField(EntityTypeDefinition $definition, string $handle): string
    {
        return SchemaEvolutionHints::fromDefinition($definition)->renameForTable('record')[$handle] ?? $handle;
    }

    /**
     * Report whether a definition declares an action handle.
     *
     * @param   EntityTypeDefinition  $definition  Definition searched for the action.
     * @param   string                $handle      Action handle to validate.
     *
     * @return  bool  Whether the action is declared.
     *
     * @since   2.0.0
     */
    private function knownAction(EntityTypeDefinition $definition, string $handle): bool
    {
        return array_any($definition->actions(), static fn ($action): bool => $action->handle === $handle);
    }

    /**
     * Require the versioned membership carried by the context to remain live.
     *
     * Mutation plans lock the membership row for the remainder of the caller's transaction. Read plans
     * still compare every generation without taking a write-oriented lock, so revoked or changed
     * membership cannot retain field or row authority through a stale execution context.
     *
     * @param   ExecutionContext  $context    Context carrying the membership snapshot.
     * @param   string            $operation  Operation deciding whether a mutation lock is required.
     *
     * @return  void
     *
     * @throws  RuntimeException  When the context carries a stale membership snapshot.
     *
     * @since   2.0.0
     */
    private function assertMembership(ExecutionContext $context, string $operation): void
    {
        $this->assertMembershipOperations($context, [$operation]);
    }

    /**
     * Revalidate one membership at the strictest freshness level required by a closed operation set.
     *
     * @param   ExecutionContext  $context     Actor carrying optional organization membership evidence.
     * @param   list<string>      $operations  Exact operations about to share one policy snapshot.
     *
     * @return  void
     *
     * @throws  RuntimeException  When the authenticated membership is stale for any requested operation.
     *
     * @since   2.0.0
     */
    private function assertMembershipOperations(ExecutionContext $context, array $operations): void
    {
        $membership = $context->membership();
        if ($membership === null) {
            return;
        }
        $readOperations = [
            'business.record.read',
            'business.record.browse',
            'business.record.history',
            'business.record.report',
            'business.record.export',
        ];
        $write = false;
        foreach ($operations as $operation) {
            if (!in_array($operation, $readOperations, true)) {
                $write = true;
                break;
            }
        }
        if (
            !$this->memberships->current(
                $context->actorId(),
                $context->site(),
                $membership,
                $write,
            )
        ) {
            throw new RuntimeException('The business-record authorization context is stale.');
        }
    }

    /**
     * Require repository scope to match the authenticated context exactly.
     *
     * @param   ExecutionContext  $context  Actor and exact authenticated scope.
     * @param   RecordScope       $scope    Repository scope that must match the context.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function assertScope(ExecutionContext $context, RecordScope $scope): void
    {
        try {
            $organization = in_array(
                $scope->mode,
                [ScopeMode::Organization, ScopeMode::SiteOrganization],
                true,
            ) ? $context->organization()?->identifier() : null;
            $scope->assertRequest($context->site(), $organization);
        } catch (InvalidArgumentException $exception) {
            throw new RuntimeException('The business-record scope is not authenticated.', 0, $exception);
        }
    }

    /**
     * Read one required stored string column.
     *
     * @param   array<string,mixed>  $row     Stored policy row.
     * @param   string               $column  Column whose value is required.
     *
     * @return  string  Required string value.
     *
     * @throws  RuntimeException  When the stored value is not a string.
     *
     * @since   2.0.0
     */
    private function string(array $row, string $column): string
    {
        $value = $row[$column] ?? null;
        if (!is_string($value)) {
            throw new RuntimeException(sprintf('Stored policy column %s is invalid.', $column));
        }

        return $value;
    }

    /**
     * Read one required positive stored integer in a driver-independent representation.
     *
     * @param   array<string,mixed>  $row     Stored policy row.
     * @param   string               $column  Column whose value is required.
     *
     * @return  int  Canonical positive integer independent of PDO integer-string behavior.
     *
     * @throws  RuntimeException  When the stored value is absent, non-canonical, or outside SQL INTEGER.
     *
     * @since   2.0.0
     */
    private function positiveInteger(array $row, string $column): int
    {
        $value = $row[$column] ?? null;
        if (is_int($value)) {
            $parsed = $value;
        } elseif (is_string($value) && preg_match('/^[1-9][0-9]{0,9}$/D', $value) === 1) {
            $parsed = (int) $value;
        } else {
            throw new RuntimeException(sprintf('Stored policy column %s is invalid.', $column));
        }
        if ($parsed < 1 || $parsed > 2_147_483_647) {
            throw new RuntimeException(sprintf('Stored policy column %s is invalid.', $column));
        }

        return $parsed;
    }
}
