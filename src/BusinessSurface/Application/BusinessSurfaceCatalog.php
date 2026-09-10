<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessSurface\Application;

use InvalidArgumentException;
use Kumwe\App\Application\Authorization\AuthorizationGateway;
use Kumwe\App\Application\Authorization\AuthorizationResource;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\App\Application\Persistence\TransactionManager;
use Kumwe\App\BusinessDefinition\Application\FieldTypeDefinitionResolver;
use Kumwe\App\BusinessDefinition\Domain\ActionDefinition;
use Kumwe\App\BusinessDefinition\Domain\DocumentViewDefinition;
use Kumwe\App\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\App\BusinessDefinition\Domain\FieldDefinition;
use Kumwe\App\BusinessDefinition\Domain\FieldTypeDefinition;
use Kumwe\App\BusinessDefinition\Domain\InvalidBusinessDefinition;
use Kumwe\App\BusinessDefinition\Domain\PortalOperation;
use Kumwe\App\BusinessDefinition\Domain\ScopeMode;
use Kumwe\App\BusinessDefinition\Domain\ViewDefinition;
use Kumwe\App\BusinessRecord\Application\BusinessRecordDefinitionResolver;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordDefinitionUnavailable;
use Kumwe\App\BusinessRecord\Application\ResolvedBusinessDefinition;
use Kumwe\App\BusinessRecord\Domain\RecordScope;
use Kumwe\App\BusinessSecurity\Application\BusinessRecordAccessCatalogPlanner;
use Kumwe\App\BusinessSecurity\Application\BusinessRecordAccessController;
use Kumwe\App\BusinessSecurity\Application\BusinessRecordAccessOperationCatalogPlanner;
use Kumwe\App\BusinessSecurity\Application\BusinessRecordAccessPlan;
use Kumwe\Extension\Spi\BusinessSecurity\Application\FieldAccessUsage;
use Kumwe\App\BusinessSecurity\Policy\RecordPolicyConstant;
use Kumwe\App\BusinessSurface\Application\Custom\CustomBusinessSurfaceDispatcher;
use Kumwe\App\Extension\Runtime\RuntimeMaterializationState;
use Kumwe\Extension\Spi\Identity\Domain\Capability;
use Kumwe\App\Localization\Application\ActiveLocale;
use Kumwe\App\Localization\Application\SupportedLocales;
use Ramsey\Uuid\Uuid;

/**
 * Produces the policy-filtered business metadata every generated delivery adapter consumes.
 *
 * The catalog resolves only active installed definitions, verifies the authenticated surface and
 * operation, and asks the canonical record-policy controller inside a transaction before describing a
 * field, view, relation, or action. Denied metadata is omitted rather than annotated as denied, so JSON,
 * CLI, MCP, OpenAPI and browser consumers cannot use schema discovery as a side channel. The returned
 * arrays contain presentation facts and JSON Schema fragments only; raw policies, storage names,
 * internal record keys, expression trees and executable extension objects never cross this boundary.
 *
 * @since  2.0.0
 */
final readonly class BusinessSurfaceCatalog implements BusinessApprovalExposureCatalog
{
    /**
     * Build the shared generated-surface catalog.
     *
     * @param  BusinessRecordDefinitionResolver  $definitions     Resolves trusted active installations.
     * @param  BusinessRecordAccessController    $access          Produces canonical row and field policy plans.
     * @param  FieldTypeDefinitionResolver       $fieldTypes      Resolves immutable logical field families.
     * @param  AuthorizationGateway              $authorization   Enforces the operation capability first.
     * @param  TransactionManager                $transactions    Holds definition and policy generations stable.
     * @param  RuntimeMaterializationState       $runtime         Trusted extension generation this process serves.
     * @param  ?CustomBusinessSurfaceDispatcher  $customBusiness  Active custom contracts, or null to fail closed.
     * @param  ?ActiveLocale                     $active          Locale of an HTTP or worker unit of work; null
     *         retains source labels for callers outside a localized runtime.
     *
     * @since  2.0.0
     */
    public function __construct(
        private BusinessRecordDefinitionResolver $definitions,
        private BusinessRecordAccessController $access,
        private FieldTypeDefinitionResolver $fieldTypes,
        private AuthorizationGateway $authorization,
        private TransactionManager $transactions,
        private RuntimeMaterializationState $runtime,
        private ?CustomBusinessSurfaceDispatcher $customBusiness = null,
        private ?ActiveLocale $active = null,
    ) {
    }

    /**
     * Discover the entity types visible for one exact surface and operation.
     *
     * A missing global grant returns an empty collection, matching navigation semantics. Per-definition
     * policy is resolved before metadata is added, and portal definitions additionally require the exact
     * operation opt-in; entity-level portal exposure alone grants nothing.
     *
     * @param   ExecutionContext          $context    Authenticated actor, site and membership.
     * @param   BusinessSurface           $surface    Adapter requesting the metadata.
     * @param   BusinessSurfaceOperation  $operation  Operation the metadata will drive.
     *
     * @return  list<array<string, mixed>>  Canonically ordered safe definition documents.
     *
     * @since   2.0.0
     */
    public function definitions(
        ExecutionContext $context,
        BusinessSurface $surface,
        BusinessSurfaceOperation $operation = BusinessSurfaceOperation::Browse,
    ): array {
        if (!$this->surfaceMatches($context, $surface) || !$this->allowed($context, $operation)) {
            return [];
        }

        return $this->transactions->transactional(function () use ($context, $surface, $operation): array {
            $resolvedDefinitions = $this->definitions->activeInstalled($context);
            $targets = [];
            $resources = [];
            $eligible = [];
            foreach ($resolvedDefinitions as $candidate) {
                $targets[$candidate->definition->handle] = $candidate->definition;
                try {
                    $scope = $this->scope($context, $candidate->definition);
                } catch (InvalidArgumentException) {
                    continue;
                }
                $requested = $this->exposed($candidate->definition, $surface, $operation);
                $resources[] = ['resolved' => $candidate, 'scope' => $scope, 'requested' => $requested];
                if ($requested) {
                    $eligible[] = ['resolved' => $candidate, 'scope' => $scope];
                }
            }
            if ($eligible === []) {
                return [];
            }
            $plans = [];
            if ($this->access instanceof BusinessRecordAccessCatalogPlanner) {
                $plans = $this->access->catalogPlans($context, $operation->capability(), $resources);
            } else {
                foreach ($eligible as $resource) {
                    $resolved = $resource['resolved'];
                    $plans[$resolved->definition->id] = $this->access->plan(
                        $context,
                        $operation->capability(),
                        $resolved,
                        $resource['scope'],
                    );
                }
            }
            $items = [];
            foreach ($eligible as $resource) {
                $resolved = $resource['resolved'];
                $plan = $plans[$resolved->definition->id] ?? null;
                if (!$plan instanceof BusinessRecordAccessPlan || !$this->mayExposeRows($plan)) {
                    continue;
                }
                try {
                    $items[] = $this->document($resolved, $surface, $operation, $plan, $targets);
                } catch (InvalidBusinessDefinition) {
                    continue;
                }
            }

            usort(
                $items,
                static fn (array $left, array $right): int => $left['handle'] <=> $right['handle'],
            );

            return $items;
        });
    }

    /**
     * Inspect one definition without revealing whether a filtered-out definition exists.
     *
     * @param   ExecutionContext          $context     Authenticated actor, site and membership.
     * @param   BusinessSurface           $surface     Adapter requesting the metadata.
     * @param   string                    $identifier  Definition UUID or handle.
     * @param   BusinessSurfaceOperation  $operation   Operation the metadata will drive.
     *
     * @return  array<string, mixed>  Safe definition document.
     *
     * @throws  BusinessRecordDefinitionUnavailable  When absent, disabled, unexposed, or denied.
     *
     * @since   2.0.0
     */
    public function definition(
        ExecutionContext $context,
        BusinessSurface $surface,
        string $identifier,
        BusinessSurfaceOperation $operation = BusinessSurfaceOperation::Read,
    ): array {
        if (!$this->surfaceMatches($context, $surface) || !$this->allowed($context, $operation)) {
            throw new BusinessRecordDefinitionUnavailable();
        }

        return $this->transactions->transactional(function () use (
            $context,
            $surface,
            $identifier,
            $operation,
        ): array {
            $target = null;
            $targetScope = null;
            $targets = [];
            $resources = [];
            foreach ($this->definitions->activeInstalled($context) as $resolved) {
                $targets[$resolved->definition->handle] = $resolved->definition;
                $requested = $resolved->definition->id === $identifier
                    || $resolved->definition->handle === $identifier;
                try {
                    $scope = $this->scope($context, $resolved->definition);
                } catch (InvalidArgumentException) {
                    continue;
                }
                $resources[] = ['resolved' => $resolved, 'scope' => $scope, 'requested' => $requested];
                if ($requested) {
                    $target = $resolved;
                    $targetScope = $scope;
                }
            }
            if (
                !($target instanceof ResolvedBusinessDefinition)
                || !($targetScope instanceof RecordScope)
                || !$this->exposed($target->definition, $surface, $operation)
            ) {
                throw new BusinessRecordDefinitionUnavailable();
            }
            if ($this->access instanceof BusinessRecordAccessCatalogPlanner) {
                $plans = $this->access->catalogPlans($context, $operation->capability(), $resources);
                $plan = $plans[$target->definition->id] ?? null;
                if (!$plan instanceof BusinessRecordAccessPlan) {
                    throw new BusinessRecordDefinitionUnavailable();
                }
            } else {
                $plan = $this->access->plan(
                    $context,
                    $operation->capability(),
                    $target,
                    $targetScope,
                );
            }
            if (!$this->mayExposeRows($plan)) {
                throw new BusinessRecordDefinitionUnavailable();
            }

            try {
                return $this->document($target, $surface, $operation, $plan, $targets);
            } catch (InvalidBusinessDefinition) {
                throw new BusinessRecordDefinitionUnavailable();
            }
        });
    }

    /**
     * Resolve every policy-visible operation from one active-definition and policy snapshot.
     *
     * Generated browser models need the complete closed operation map for control gating. Resolving the
     * definition once per operation would repeat installation, membership, policy-lock, and policy-row reads;
     * this method holds one transaction and lets capable access adapters batch every distinct capability.
     *
     * @param   ExecutionContext  $context     Authenticated actor, site and membership.
     * @param   BusinessSurface   $surface     Adapter requesting the operation map.
     * @param   string            $identifier  Definition UUID or handle.
     *
     * @return  array<string, true>  Available generated operation values keyed to true.
     *
     * @since   2.0.0
     */
    public function operations(
        ExecutionContext $context,
        BusinessSurface $surface,
        string $identifier,
    ): array {
        if (!$this->surfaceMatches($context, $surface)) {
            return [];
        }
        $authorized = [];
        foreach (BusinessSurfaceOperation::cases() as $operation) {
            if ($operation !== BusinessSurfaceOperation::Discover && $this->allowed($context, $operation)) {
                $authorized[] = $operation;
            }
        }
        if ($authorized === []) {
            return [];
        }

        return $this->transactions->transactional(function () use (
            $context,
            $surface,
            $identifier,
            $authorized,
        ): array {
            $target = null;
            $targetScope = null;
            $targets = [];
            $resources = [];
            foreach ($this->definitions->activeInstalled($context) as $resolved) {
                $targets[$resolved->definition->handle] = $resolved->definition;
                $requested = $resolved->definition->id === $identifier
                    || $resolved->definition->handle === $identifier;
                try {
                    $scope = $this->scope($context, $resolved->definition);
                } catch (InvalidArgumentException) {
                    continue;
                }
                $resources[] = ['resolved' => $resolved, 'scope' => $scope, 'requested' => $requested];
                if ($requested) {
                    $target = $resolved;
                    $targetScope = $scope;
                }
            }
            if (!($target instanceof ResolvedBusinessDefinition) || !($targetScope instanceof RecordScope)) {
                return [];
            }
            $operations = array_values(array_filter(
                $authorized,
                fn (BusinessSurfaceOperation $operation): bool => $this->exposed(
                    $target->definition,
                    $surface,
                    $operation,
                ),
            ));
            if ($operations === []) {
                return [];
            }
            $plans = $this->operationPlans($context, $operations, $resources, $target, $targetScope);
            $available = [];
            foreach ($operations as $operation) {
                $plan = $plans[$operation->capability()] ?? null;
                if (!($plan instanceof BusinessRecordAccessPlan) || !$this->mayExposeRows($plan)) {
                    continue;
                }
                try {
                    $this->document($target, $surface, $operation, $plan, $targets);
                } catch (InvalidBusinessDefinition) {
                    continue;
                }
                $available[$operation->value] = true;
            }

            return $available;
        });
    }

    /**
     * Resolve active action declarations for already-authorized approval projections.
     *
     * This predicate intentionally does not ask the authorization gateway for the action execution
     * capability and does not plan record access. ApprovalQueryService has already established requester,
     * checker, or manager visibility; requiring the maker's action grant here would violate separation of
     * duties. The returned ceiling proves only that the exact active definition and action are still offered
     * on the authenticated surface, including the portal's explicit approval operation opt-in.
     *
     * @param ExecutionContext $context Authenticated actor, site, and membership.
     * @param BusinessSurface $surface Generated adapter presenting the approval.
     * @param   list<array{request_id: string, definition_id: string, action: string}>  $requests
     *          Canonical business-record approval bindings, bounded to one hundred entries.
     *
     * @return  array<string, true>  Exposed request UUIDs keyed to true.
     *
     * @throws  InvalidArgumentException  When a binding is malformed, duplicated inconsistently, or unbounded.
     *
     * @since   2.0.0
     */
    public function approvalActions(
        ExecutionContext $context,
        BusinessSurface $surface,
        array $requests,
    ): array {
        if (!array_is_list($requests) || count($requests) > 100) {
            throw new InvalidArgumentException('Business approval exposure requires at most one hundred bindings.');
        }
        if (!$this->surfaceMatches($context, $surface) || $requests === []) {
            return [];
        }
        $requested = [];
        $identities = [];
        foreach ($requests as $request) {
            if (
                !is_array($request)
                || array_is_list($request)
                || array_diff(array_keys($request), ['request_id', 'definition_id', 'action']) !== []
                || !isset($request['request_id'], $request['definition_id'], $request['action'])
                || !is_string($request['request_id'])
                || !is_string($request['definition_id'])
                || !is_string($request['action'])
                || !Uuid::isValid($request['request_id'])
                || !Uuid::isValid($request['definition_id'])
                || preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $request['action']) !== 1
            ) {
                throw new InvalidArgumentException('A business approval exposure binding is invalid.');
            }
            $identity = $request['definition_id'] . ':' . $request['action'];
            if (isset($identities[$request['request_id']]) && $identities[$request['request_id']] !== $identity) {
                throw new InvalidArgumentException('A business approval exposure request is bound inconsistently.');
            }
            $identities[$request['request_id']] = $identity;
            $requested[$request['definition_id']][$request['action']][$request['request_id']] = true;
        }

        return $this->transactions->transactional(function () use ($context, $surface, $requested): array {
            $available = [];
            foreach ($this->definitions->activeInstalled($context) as $resolved) {
                $actions = $requested[$resolved->definition->id] ?? null;
                if ($actions === null) {
                    continue;
                }
                try {
                    $this->scope($context, $resolved->definition);
                } catch (InvalidArgumentException) {
                    continue;
                }
                if (!$this->exposed($resolved->definition, $surface, BusinessSurfaceOperation::Approval)) {
                    continue;
                }
                foreach ($resolved->definition->actions() as $action) {
                    if (
                        !isset($actions[$action->handle])
                        || !$action->highImpact
                        || !$this->actionExposed($action, $surface)
                    ) {
                        continue;
                    }
                    if (
                        ($action->handler !== null || $action->schema !== null)
                        && $this->customBusiness?->actionContractSchemas(
                            $resolved->definition,
                            $action->handle,
                        ) === null
                    ) {
                        continue;
                    }
                    $available += $actions[$action->handle];
                }
            }

            return $available;
        });
    }

    /**
     * Plan every distinct capability with the strongest batching port an access adapter provides.
     *
     * @param ExecutionContext $context Authenticated actor and exact scope.
     * @param list<BusinessSurfaceOperation> $operations Surface-exposed operations to evaluate.
     * @param   list<array{resolved: ResolvedBusinessDefinition, scope: RecordScope, requested: bool}>  $resources
     *          Active definition snapshot with one requested target.
     * @param ResolvedBusinessDefinition $target Requested active definition.
     * @param RecordScope $targetScope Exact authenticated target scope.
     *
     * @return  array<string, BusinessRecordAccessPlan>  Target plans keyed by dotted capability.
     *
     * @since   2.0.0
     */
    private function operationPlans(
        ExecutionContext $context,
        array $operations,
        array $resources,
        ResolvedBusinessDefinition $target,
        RecordScope $targetScope,
    ): array {
        $capabilities = array_values(array_unique(array_map(
            static fn (BusinessSurfaceOperation $operation): string => $operation->capability(),
            $operations,
        )));
        if ($this->access instanceof BusinessRecordAccessOperationCatalogPlanner) {
            $catalog = $this->access->catalogOperationPlans($context, $capabilities, $resources);
            $plans = [];
            foreach ($catalog as $capability => $candidates) {
                $plan = $candidates[$target->definition->id] ?? null;
                if ($plan instanceof BusinessRecordAccessPlan) {
                    $plans[$capability] = $plan;
                }
            }

            return $plans;
        }
        $plans = [];
        foreach ($capabilities as $capability) {
            if ($this->access instanceof BusinessRecordAccessCatalogPlanner) {
                $catalog = $this->access->catalogPlans($context, $capability, $resources);
                $plans[$capability] = $catalog[$target->definition->id] ?? null;
                continue;
            }
            $plans[$capability] = $this->access->plan($context, $capability, $target, $targetScope);
        }

        return array_filter(
            $plans,
            static fn (?BusinessRecordAccessPlan $plan): bool => $plan instanceof BusinessRecordAccessPlan,
        );
    }

    /**
     * Produce the generation key caches and mutation plans bind to.
     *
     * @param   list<array<string, mixed>>  $definitions  Safe catalog documents for one site and operation.
     *
     * @return  string  Lowercase SHA-256 over runtime generation, publication and definition checksums.
     *
     * @since   2.0.0
     */
    public function generation(array $definitions): string
    {
        $checksums = [];
        foreach ($definitions as $definition) {
            $checksum = $definition['checksum'] ?? null;
            if (!is_string($checksum) || preg_match('/^[a-f0-9]{64}$/D', $checksum) !== 1) {
                throw new InvalidArgumentException('A business-surface definition checksum is invalid.');
            }
            $checksums[] = $checksum;
        }
        sort($checksums, SORT_STRING);

        return hash('sha256', implode(':', [
            (string) $this->runtime->generation,
            $this->runtime->publicationChecksum,
            ...$checksums,
        ]));
    }

    /**
     * Describe one definition through the resolved field-policy plan.
     *
     * @param   ResolvedBusinessDefinition           $resolved   Trusted definition and active installation.
     * @param   BusinessSurface                      $surface    Adapter receiving the result.
     * @param   BusinessSurfaceOperation             $operation  Operation whose policy was planned.
     * @param   BusinessRecordAccessPlan             $plan       Canonical field, action and relation decision.
     * @param   array<string, EntityTypeDefinition>  $targets    Active definitions keyed by declared handle.
     *
     * @return  array<string, mixed>  Safe entity metadata and JSON Schema fragments.
     *
     * @since   2.0.0
     */
    private function document(
        ResolvedBusinessDefinition $resolved,
        BusinessSurface $surface,
        BusinessSurfaceOperation $operation,
        BusinessRecordAccessPlan $plan,
        array $targets,
    ): array {
        $definition = $resolved->definition;
        $fields = [];
        $allowedHandles = [];
        $unconditionalQueryHandles = [];
        foreach ($definition->fields() as $field) {
            $related = $field->type === 'core.entity_reference'
                ? $plan->related($field->handle)
                : null;
            $target = $field->configuration['target'] ?? null;
            if (
                !$this->fieldAvailable($field, $operation, $plan)
                || ($field->type === 'core.entity_reference' && (
                    !is_string($target)
                    || $related === null
                    || !$this->relatedAvailable($target, $surface, $operation, $related, $targets)
                ))
            ) {
                continue;
            }
            $fields[] = $this->field($field, $plan);
            $allowedHandles[$field->handle] = true;
            if ($field->visibilityCondition === null) {
                $unconditionalQueryHandles[$field->handle] = true;
            }
        }
        $relationships = [];
        $allowedRelationships = [];
        foreach ($definition->relationships() as $relationship) {
            $related = $plan->related($relationship->handle);
            if (
                $related === null
                || !$this->relatedAvailable(
                    $relationship->target,
                    $surface,
                    $operation,
                    $related,
                    $targets,
                )
            ) {
                continue;
            }
            $relationships[] = [
                'handle' => $relationship->handle,
                'label' => $relationship->label,
                'kind' => $relationship->kind->value,
                'target' => $relationship->target,
                'required' => $relationship->required,
                'ordered' => $relationship->ordered,
            ];
            $allowedRelationships[$relationship->handle] = true;
        }
        $views = [];
        foreach ($definition->views() as $view) {
            if (!$this->viewExposed($view, $surface)) {
                continue;
            }
            $customContract = null;
            if ($view->handler !== null || $view->schema !== null) {
                $customContract = $this->customBusiness?->viewContractSchemas($definition, $view->handle);
                if ($customContract === null) {
                    continue;
                }
            }
            $projected = array_values(array_filter(
                $view->fields,
                static fn (string $field): bool => isset($allowedHandles[$field]),
            ));
            if ($projected === []) {
                continue;
            }
            $item = [
                'handle' => $view->handle,
                'label' => $view->label,
                'kind' => $view->kind,
                'custom' => $view->handler !== null && $view->schema !== null,
                'fields' => $projected,
                'filters' => array_values(array_filter(
                    $view->filters,
                    fn (string $field): bool => isset($unconditionalQueryHandles[$field])
                        && $plan->fields->allows(FieldAccessUsage::Filter, $field),
                )),
                'sorts' => array_values(array_filter(
                    $view->sorts,
                    fn (string $field): bool => isset($unconditionalQueryHandles[$field])
                        && $plan->fields->allows(FieldAccessUsage::Sort, $field),
                )),
            ];
            if ($customContract !== null) {
                $item['custom_contract'] = $customContract;
            }
            if ($view->document !== null) {
                $item['document'] = $this->documentRoles($view->document, $allowedHandles, $allowedRelationships);
            }
            $views[] = $item;
        }
        $actions = [];
        foreach ($definition->actions() as $action) {
            if (!$this->actionExposed($action, $surface) || !$plan->allowsAction($action->handle)) {
                continue;
            }
            $customContract = null;
            if ($action->handler !== null || $action->schema !== null) {
                $customContract = $this->customBusiness?->actionContractSchemas($definition, $action->handle);
                if ($customContract === null) {
                    continue;
                }
            }
            $item = $this->action($action);
            if ($customContract !== null) {
                $item['custom_contract'] = $customContract;
                $properties = $customContract['command_schema']['properties'] ?? null;
                if (!is_array($properties) || ($properties !== [] && array_is_list($properties))) {
                    continue;
                }
            }
            $actions[] = $item;
        }

        return [
            'id' => $definition->id,
            'handle' => $definition->handle,
            'singular_label' => $definition->singularLabelIn($this->locale()),
            'plural_label' => $definition->pluralLabelIn($this->locale()),
            'version' => $definition->definitionVersion,
            'checksum' => $definition->checksum(),
            'owner' => $definition->owner->toArray(),
            'scope' => $definition->scope->value,
            'soft_delete' => $definition->softDeleteEnabled,
            'workflow' => $definition->workflow === null ? null : [
                'initial_state' => $definition->workflow->initialState,
                'states' => array_map(
                    static fn (string $state): array => [
                        'handle' => $state,
                        'label' => ucfirst(str_replace('_', ' ', $state)),
                    ],
                    $definition->workflow->states,
                ),
            ],
            'operation' => $operation->value,
            'fields' => $fields,
            'views' => $views,
            'actions' => $actions,
            'relationships' => $relationships,
        ];
    }

    /**
     * Prove one nested plan may expose its exact active target through this generated surface.
     *
     * The target must remain active, match the plan resource, publish its identity for reference use,
     * and, on the portal, explicitly expose the target-side operation needed to read or select it.
     * Administrator exposure continues to control navigation only; authenticated API, CLI, and MCP
     * boundaries retain their existing capability-and-policy exposure model.
     *
     * @param   string                               $targetHandle  Declared target definition handle.
     * @param   BusinessSurface                      $surface       Adapter receiving the metadata.
     * @param   BusinessSurfaceOperation             $operation     Source operation being described.
     * @param   BusinessRecordAccessPlan             $access        Exact nested target policy plan.
     * @param   array<string, EntityTypeDefinition>  $targets       Active definitions by handle.
     *
     * @return  bool  True only when target identity and surface exposure both remain available.
     *
     * @since   2.0.0
     */
    private function relatedAvailable(
        string $targetHandle,
        BusinessSurface $surface,
        BusinessSurfaceOperation $operation,
        BusinessRecordAccessPlan $access,
        array $targets,
    ): bool {
        $target = $targets[$targetHandle] ?? null;
        if (
            !($target instanceof EntityTypeDefinition)
            || !hash_equals($target->id, $access->resourceIdentifier)
        ) {
            return false;
        }
        $identity = $this->identityField($target);
        if (
            $identity === null
            || !$access->fields->allows(FieldAccessUsage::PublicReference, $identity->handle)
            || !$this->mayExposeRows($access)
        ) {
            return false;
        }
        if ($surface !== BusinessSurface::Portal) {
            return true;
        }

        $targetOperation = match ($operation) {
            BusinessSurfaceOperation::Discover,
            BusinessSurfaceOperation::Browse,
            BusinessSurfaceOperation::Create,
            BusinessSurfaceOperation::Update,
            BusinessSurfaceOperation::Relation => BusinessSurfaceOperation::Browse,
            BusinessSurfaceOperation::History => BusinessSurfaceOperation::History,
            BusinessSurfaceOperation::Report => BusinessSurfaceOperation::Report,
            BusinessSurfaceOperation::Export => BusinessSurfaceOperation::Export,
            BusinessSurfaceOperation::Reorder => BusinessSurfaceOperation::Reorder,
            default => BusinessSurfaceOperation::Read,
        };

        return $this->exposed($target, $surface, $targetOperation);
    }

    /**
     * Omit target metadata when its nested row policy is provably empty without querying business rows.
     *
     * Arbitrary predicates may match some rows and therefore remain discoverable; only an empty or wholly
     * false allow set, or a constant-true deny, proves no row can ever be selected. This conservative
     * check avoids turning metadata discovery into an unbounded data query while still honoring explicit
     * default-deny and constant-deny policies.
     *
     * @param   BusinessRecordAccessPlan  $access  Exact nested target policy plan.
     *
     * @return  bool  False only when the bounded policy is statically certain to deny every row.
     *
     * @since   2.0.0
     */
    private function mayExposeRows(BusinessRecordAccessPlan $access): bool
    {
        foreach ($access->records->denies as $deny) {
            if ($deny instanceof RecordPolicyConstant && $deny->value) {
                return false;
            }
        }
        foreach ($access->records->allows as $allow) {
            if (!($allow instanceof RecordPolicyConstant) || $allow->value) {
                return true;
            }
        }

        return false;
    }

    /**
     * Find the one public identity field a validated definition declares.
     *
     * @param   EntityTypeDefinition  $definition  Active target definition.
     *
     * @return  FieldDefinition|null  UUID or natural identity field, or null for a malformed target.
     *
     * @since   2.0.0
     */
    private function identityField(EntityTypeDefinition $definition): ?FieldDefinition
    {
        foreach ($definition->fields() as $field) {
            if (in_array($field->type, ['core.uuid', 'core.reference_identity'], true)) {
                return $field;
            }
        }

        return null;
    }

    /**
     * Describe one policy-visible field and its exact wire schema.
     *
     * @param   FieldDefinition           $field  Declarative field metadata.
     * @param   BusinessRecordAccessPlan  $plan   Explicit per-use disclosure sets.
     *
     * @return  array<string, mixed>  Presentation flags and bounded JSON Schema.
     *
     * @since   2.0.0
     */
    private function field(FieldDefinition $field, BusinessRecordAccessPlan $plan): array
    {
        $type = $this->fieldTypes->get($field->type);

        return [
            'handle' => $field->handle,
            'label' => $field->labelIn($this->locale()),
            'description' => $field->descriptionIn($this->locale()),
            'help_text' => $field->helpTextIn($this->locale()),
            'type' => $field->type,
            'value_type' => $type->valueType,
            'required' => $field->required,
            'nullable' => $field->nullable,
            'read_only' => $field->readOnly || $field->computed || $field->serverOnly,
            'write_only' => $field->type === 'core.secret',
            'immutable_after_create' => $field->immutableAfterCreate,
            'conditional' => $field->visibilityCondition !== null || $field->editabilityCondition !== null,
            'form_group' => $field->formGroup,
            'order' => $field->order,
            'placements' => $field->placements,
            'uses' => [
                'create' => $field->createVisible && !$field->readOnly && !$field->serverOnly
                    && $plan->fields->allows(FieldAccessUsage::Create, $field->handle),
                'update' => $field->updateVisible && !$field->readOnly && !$field->serverOnly
                    && !$field->immutableAfterCreate
                    && $plan->fields->allows(FieldAccessUsage::Update, $field->handle),
                'detail' => $field->readVisible
                    && $plan->fields->allows(FieldAccessUsage::Detail, $field->handle),
                'list' => $field->readVisible && in_array('list', $field->placements, true)
                    && $plan->fields->allows(FieldAccessUsage::List, $field->handle),
                'filter' => $field->visibilityCondition === null && $field->filterable
                    && $plan->fields->allows(FieldAccessUsage::Filter, $field->handle),
                'search' => $field->visibilityCondition === null && $field->searchable
                    && $plan->fields->allows(FieldAccessUsage::Search, $field->handle),
                'sort' => $field->visibilityCondition === null && $field->sortable
                    && $plan->fields->allows(FieldAccessUsage::Sort, $field->handle),
                'report' => $field->visibilityCondition === null && $field->reportable
                    && $plan->fields->allows(FieldAccessUsage::Report, $field->handle),
                'export' => $field->visibilityCondition === null && $field->exportable
                    && $plan->fields->allows(FieldAccessUsage::Export, $field->handle),
            ],
            'schema' => $this->schema($field, $type),
        ];
    }

    /**
     * Return the locale user-facing definition text is projected in.
     *
     * @return  string|\Kumwe\App\Localization\Domain\LocaleTag  Active locale, or the source tag when this
     *          catalog is used outside a locale unit of work.
     *
     * @since   2.0.0
     */
    private function locale(): string|\Kumwe\App\Localization\Domain\LocaleTag
    {
        return $this->active?->locale() ?? SupportedLocales::SOURCE;
    }

    /**
     * Produce a bounded JSON Schema fragment without floating-point formats.
     *
     * @param   FieldDefinition       $field  Field declaration supplying precision and configuration.
     * @param   ?FieldTypeDefinition  $type   Immutable logical value family, resolved when omitted.
     *
     * @return  array<string, mixed>  JSON Schema 2020-12 compatible fragment.
     *
     * @since   2.0.0
     */
    public function schema(FieldDefinition $field, ?FieldTypeDefinition $type = null): array
    {
        $type ??= $this->fieldTypes->get($field->type);
        $schema = match ($field->type) {
            'core.uuid', 'core.media_reference', 'core.entity_reference' => [
                'type' => 'string', 'format' => 'uuid',
            ],
            'core.integer' => ['type' => 'integer'],
            'core.boolean' => ['type' => 'boolean'],
            'core.date' => ['type' => 'string', 'format' => 'date'],
            'core.local_time' => ['type' => 'string', 'format' => 'time'],
            'core.instant' => ['type' => 'string', 'format' => 'date-time'],
            'core.email' => ['type' => 'string', 'format' => 'email'],
            'core.url' => ['type' => 'string', 'format' => 'uri'],
            'core.decimal' => ['type' => 'string', 'pattern' => '^-?[0-9]+(?:\\.[0-9]+)?$'],
            'core.money' => $this->exactObject('currency'),
            'core.quantity' => $this->exactObject('unit'),
            'core.zoned_datetime' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['instant', 'timezone'],
                'properties' => [
                    'instant' => ['type' => 'string', 'format' => 'date-time'],
                    'timezone' => ['type' => 'string', 'maxLength' => 64],
                ],
            ],
            'core.ordered_lines' => [
                'type' => 'array', 'maxItems' => 1000, 'items' => ['type' => 'object', 'maxProperties' => 128],
            ],
            'core.embedded_value', 'core.bounded_json' => [
                'type' => 'object', 'maxProperties' => 128,
            ],
            default => match ($type->valueType) {
                'integer' => ['type' => 'integer'],
                'boolean' => ['type' => 'boolean'],
                'object' => ['type' => 'object', 'maxProperties' => 128],
                'collection' => ['type' => 'array', 'maxItems' => 1000],
                default => ['type' => 'string'],
            },
        };
        if ($field->length !== null && ($schema['type'] ?? null) === 'string') {
            $schema['maxLength'] = $field->length;
        }
        if ($field->type === 'core.enum') {
            $schema['enum'] = $field->configuration['options'] ?? [];
        }
        if ($field->nullable) {
            $schema['type'] = [$schema['type'] ?? 'string', 'null'];
        }
        if ($field->readOnly || $field->computed || $field->serverOnly) {
            $schema['readOnly'] = true;
        }
        if ($field->type === 'core.secret') {
            $schema['writeOnly'] = true;
        }

        return $schema;
    }

    /**
     * Build the exact amount plus qualifier object used by money and quantity.
     *
     * @param   string  $qualifier  `currency` or `unit`.
     *
     * @return  array<string, mixed>  Closed composite JSON Schema.
     *
     * @since   2.0.0
     */
    private function exactObject(string $qualifier): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['amount', $qualifier],
            'properties' => [
                'amount' => ['type' => 'string', 'pattern' => '^-?[0-9]+(?:\\.[0-9]+)?$'],
                $qualifier => ['type' => 'string', 'maxLength' => 32],
            ],
        ];
    }

    /**
     * Flatten one declared action without its executable condition tree.
     *
     * @param   ActionDefinition  $action  Policy-visible action.
     *
     * @return  array<string, mixed>  Stable action identity and safety annotations.
     *
     * @since   2.0.0
     */
    private function action(ActionDefinition $action): array
    {
        return [
            'handle' => $action->handle,
            'label' => $action->label,
            'bulk' => $action->bulk,
            'high_impact' => $action->highImpact,
            'transition' => $action->transition,
            'conditional' => $action->condition !== null,
        ];
    }

    /**
     * Determine whether a field can appear for the operation at all.
     *
     * @param   FieldDefinition           $field      Candidate field.
     * @param   BusinessSurfaceOperation  $operation  Requested operation.
     * @param   BusinessRecordAccessPlan  $plan       Canonical disclosure plan.
     *
     * @return  bool  True only when both definition and policy permit the use.
     *
     * @since   2.0.0
     */
    private function fieldAvailable(
        FieldDefinition $field,
        BusinessSurfaceOperation $operation,
        BusinessRecordAccessPlan $plan,
    ): bool {
        $usage = $operation->fieldUsage();
        if (!$plan->fields->allows($usage, $field->handle)) {
            return false;
        }

        return match ($usage) {
            FieldAccessUsage::Create => $field->createVisible && !$field->readOnly && !$field->serverOnly,
            FieldAccessUsage::Update => $field->updateVisible && !$field->readOnly && !$field->serverOnly
                && !$field->immutableAfterCreate,
            FieldAccessUsage::Detail, FieldAccessUsage::List => $field->readVisible,
            FieldAccessUsage::Filter => $field->visibilityCondition === null && $field->filterable,
            FieldAccessUsage::Search => $field->visibilityCondition === null && $field->searchable,
            FieldAccessUsage::Sort => $field->visibilityCondition === null && $field->sortable,
            FieldAccessUsage::Report => $field->visibilityCondition === null && $field->reportable,
            FieldAccessUsage::Export => $field->visibilityCondition === null && $field->exportable,
            default => true,
        };
    }

    /**
     * Ensure authenticated provenance agrees with the adapter asking for metadata.
     *
     * @param   ExecutionContext  $context  Authenticated context.
     * @param   BusinessSurface   $surface  Claimed adapter surface.
     *
     * @return  bool  True only for an exact match.
     *
     * @since   2.0.0
     */
    private function surfaceMatches(ExecutionContext $context, BusinessSurface $surface): bool
    {
        return BusinessSurface::fromAuthenticated($context->surface()) === $surface;
    }

    /**
     * Ask the shared authorization gateway whether discovery may exercise the operation.
     *
     * @param   ExecutionContext          $context    Authenticated actor.
     * @param   BusinessSurfaceOperation  $operation  Requested operation.
     *
     * @return  bool  True only for an explicit effective grant.
     *
     * @since   2.0.0
     */
    private function allowed(ExecutionContext $context, BusinessSurfaceOperation $operation): bool
    {
        return $this->authorization->decide(
            $context,
            Capability::fromString($operation->capability()),
            AuthorizationResource::collection('business_record'),
        )->allowed;
    }

    /**
     * Resolve scope exclusively from authenticated context dimensions.
     *
     * @param   ExecutionContext      $context     Authenticated site and membership.
     * @param   EntityTypeDefinition  $definition  Definition whose scope mode is applied.
     *
     * @return  RecordScope  Validated site/organization tuple.
     *
     * @since   2.0.0
     */
    private function scope(ExecutionContext $context, EntityTypeDefinition $definition): RecordScope
    {
        $organization = in_array(
            $definition->scope,
            [ScopeMode::Organization, ScopeMode::SiteOrganization],
            true,
        ) ? $context->organization()?->identifier() : null;

        return RecordScope::forDefinition($definition->scope, $context->site(), $organization);
    }

    /**
     * Apply entity exposure and the portal's exact operation allow-list.
     *
     * @param   EntityTypeDefinition      $definition  Candidate entity.
     * @param   BusinessSurface           $surface     Adapter requesting it.
     * @param   BusinessSurfaceOperation  $operation   Operation being offered.
     *
     * @return  bool  True only when the definition explicitly exposes the boundary and operation.
     *
     * @since   2.0.0
     */
    private function exposed(
        EntityTypeDefinition $definition,
        BusinessSurface $surface,
        BusinessSurfaceOperation $operation,
    ): bool {
        if ($surface === BusinessSurface::Portal) {
            return $definition->portalExposure
                && $definition->allowsPortalOperation($this->portalOperation($operation));
        }

        return $surface !== BusinessSurface::Administrator || $definition->administratorExposure;
    }

    /**
     * Map generated operations onto the existing capability-sized portal choices.
     *
     * @param   BusinessSurfaceOperation  $operation  Generated operation.
     *
     * @return  PortalOperation  Closed definition-level opt-in.
     *
     * @since   2.0.0
     */
    private function portalOperation(BusinessSurfaceOperation $operation): PortalOperation
    {
        return PortalOperation::from(match ($operation) {
            BusinessSurfaceOperation::Discover => 'browse',
            default => $operation->value,
        });
    }

    /**
     * Apply one view's exact surface flag.
     *
     * @param   ViewDefinition   $view     Candidate view.
     * @param   BusinessSurface  $surface  Adapter requesting it.
     *
     * @return  bool  Whether the view was declared for this boundary.
     *
     * @since   2.0.0
     */
    private function viewExposed(ViewDefinition $view, BusinessSurface $surface): bool
    {
        return match ($surface) {
            BusinessSurface::Portal => $view->portal,
            BusinessSurface::Administrator => $view->administrator,
            BusinessSurface::Api, BusinessSurface::Cli, BusinessSurface::Mcp => true,
        };
    }

    /**
     * Apply one action's exact surface flag.
     *
     * @param   ActionDefinition  $action   Candidate action.
     * @param   BusinessSurface   $surface  Adapter requesting it.
     *
     * @return  bool  Whether the action was declared for this boundary.
     *
     * @since   2.0.0
     */
    private function actionExposed(ActionDefinition $action, BusinessSurface $surface): bool
    {
        return match ($surface) {
            BusinessSurface::Portal => $action->portal,
            BusinessSurface::Administrator => $action->administrator,
            BusinessSurface::Api, BusinessSurface::Cli, BusinessSurface::Mcp => true,
        };
    }

    /**
     * Intersect one declared document block with the fields and relationships this plan disclosed.
     *
     * Denied roles are dropped rather than annotated, exactly as fields and relationships are, so a caller
     * cannot learn from the document metadata that a number field, a party, or a line collection exists
     * behind a policy that hides it. A fully denied block still describes an empty document header.
     *
     * @param   DocumentViewDefinition  $document       Declared documentary roles.
     * @param   array<string, mixed>    $fields         Disclosed field metadata keyed by handle.
     * @param   array<string, true>     $relationships  Disclosed relationship handles.
     *
     * @return  array<string, mixed>  Policy-filtered identity, groups, parties, lines and totals roles.
     *
     * @since   2.0.0
     */
    private function documentRoles(
        DocumentViewDefinition $document,
        array $fields,
        array $relationships,
    ): array {
        $groups = [];
        foreach ($document->groups as $group) {
            $disclosed = array_values(array_filter(
                $group['fields'],
                static fn (string $field): bool => isset($fields[$field]),
            ));
            if ($disclosed !== []) {
                $groups[] = ['label' => $group['label'], 'fields' => $disclosed];
            }
        }

        return [
            'identity' => $document->identity !== null && isset($fields[$document->identity])
                ? $document->identity
                : null,
            'groups' => $groups,
            'parties' => array_values(array_filter(
                $document->parties,
                static fn (array $party): bool => isset($relationships[$party['relationship']]),
            )),
            'lines' => $document->lines !== null && isset($relationships[$document->lines])
                ? $document->lines
                : null,
            'totals' => array_values(array_filter(
                $document->totals,
                static fn (string $field): bool => isset($fields[$field]),
            )),
        ];
    }
}
