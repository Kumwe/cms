<?php

declare(strict_types=1);

namespace Kumwe\App\Infrastructure\Mcp;

use InvalidArgumentException;
use Kumwe\App\BusinessSurface\Application\BusinessHistoryUseCase;
use Kumwe\App\BusinessSurface\Application\BusinessMutationPlanService;
use Kumwe\App\BusinessSurface\Application\BusinessOperationStatusService;
use Kumwe\App\BusinessSurface\Application\BusinessSurface;
use Kumwe\App\BusinessSurface\Application\BusinessSurfaceCatalog;
use Kumwe\App\BusinessSurface\Application\BusinessSurfaceOperation;
use Kumwe\App\BusinessSurface\Application\BusinessSurfaceService;
use Kumwe\Context\Value\ExecutionContext;

/**
 * Implements the bounded generated-business MCP tools behind Kumwe's authenticated MCP handler.
 *
 * The delegate is deliberately context-free: `KumweMcpHandlers` resolves and authorizes the current
 * credential before passing its MCP-bound `ExecutionContext` here. Discovery and inspection come from
 * the shared policy-filtered catalog, record behavior comes from `BusinessSurfaceService` and its
 * `BusinessRecordService` mutation boundary, and every mutation additionally crosses `McpMutationGuard` so
 * transport retries replay instead of writing twice.
 * No approval vote or step-up proof operation is exposed.
 *
 * @since  2.0.0
 */
final readonly class BusinessMcpHandlers
{
    /**
     * Narrow revision reader, injectable independently for delivery-contract tests.
     *
     * @var    BusinessHistoryUseCase
     * @since  2.0.0
     */
    private BusinessHistoryUseCase $history;

    /**
     * Exact authorization capability associated with every generated-business mutation.
     *
     * @var    array<string, string>
     * @since  2.0.0
     */
    private const MUTATION_CAPABILITIES = [
        'create' => 'business.record.create',
        'update' => 'business.record.update',
        'archive' => 'business.record.archive',
        'restore' => 'business.record.restore',
        'delete' => 'business.record.delete',
        'relate' => 'business.record.relate',
        'unrelate' => 'business.record.relate',
        'reorder' => 'business.record.relate',
        'request_action' => 'business.record.action',
        'execute_action' => 'business.record.action',
    ];

    /**
     * Bind generated metadata, shared surface behavior, and the MCP replay fence.
     *
     * @param  BusinessSurfaceCatalog          $catalog     Shared policy-filtered generated metadata.
     * @param  BusinessSurfaceService          $business    Shared generated-business use-case facade.
     * @param  BusinessMutationPlanService     $plans       Signed runtime, policy and version plan binder.
     * @param  McpMutationGuard                $mutations   Credential-bound MCP mutation and replay fence.
     * @param  BusinessOperationStatusService  $operations  Caller-bound canonical record-ledger status lookup.
     * @param  ?BusinessHistoryUseCase         $history     Shared bounded history port; defaults to the facade.
     *
     * @since  2.0.0
     */
    public function __construct(
        private BusinessSurfaceCatalog $catalog,
        private BusinessSurfaceService $business,
        private BusinessMutationPlanService $plans,
        private McpMutationGuard $mutations,
        private BusinessOperationStatusService $operations,
        ?BusinessHistoryUseCase $history = null,
    ) {
        $this->history = $history ?? $business;
    }

    /**
     * Discover every generated entity visible to this MCP actor.
     *
     * @param   ExecutionContext  $context  Authenticated MCP execution context.
     *
     * @return  array{items: list<array<string, mixed>>, truncated: bool}  Bounded policy-filtered metadata.
     *
     * @since   2.0.0
     */
    public function discover(ExecutionContext $context): array
    {
        $items = $this->business->discover($context, BusinessSurface::Mcp);

        return ['items' => array_slice($items, 0, 200), 'truncated' => count($items) > 200];
    }

    /**
     * Inspect one generated entity without exposing unavailable definitions.
     *
     * @param   ExecutionContext  $context     Authenticated MCP execution context.
     * @param   string            $definition  Definition UUID or handle.
     *
     * @return  array{definition: array<string, mixed>}  Policy-filtered schema, views, actions and relations.
     *
     * @since   2.0.0
     */
    public function inspect(ExecutionContext $context, string $definition): array
    {
        return ['definition' => $this->catalog->definition(
            $context,
            BusinessSurface::Mcp,
            $definition,
            BusinessSurfaceOperation::Read,
        )];
    }

    /**
     * Execute one policy-visible typed custom business view.
     *
     * @param   ExecutionContext      $context     Authenticated MCP execution context.
     * @param   string                $definition  Definition UUID or handle.
     * @param   string                $view        Declared custom view handle.
     * @param   array<string, mixed>  $query       Shared bounded record-query document.
     * @param   array<string, mixed>  $parameters  Contract-validated custom parameters.
     * @param   ?string               $record      Optional public record identity for detail-like views.
     *
     * @return  array<string, mixed>  Policy-filtered metadata and contract-validated result data.
     *
     * @since   2.0.0
     */
    public function view(
        ExecutionContext $context,
        string $definition,
        string $view,
        array $query = [],
        array $parameters = [],
        ?string $record = null,
    ): array {
        return $this->business->customView(
            $context,
            BusinessSurface::Mcp,
            $definition,
            $view,
            $query,
            $parameters,
            $record,
        );
    }

    /**
     * Search one generated entity through the shared bounded query grammar.
     *
     * @param   ExecutionContext      $context     Authenticated MCP execution context.
     * @param   string                $definition  Definition UUID or handle.
     * @param   array<string, mixed>  $query       Closed query document compiled by the shared factory.
     *
     * @return  array<string, mixed>  Policy-filtered definition metadata and one bounded record page.
     *
     * @since   2.0.0
     */
    public function search(ExecutionContext $context, string $definition, array $query = []): array
    {
        return $this->business->browse($context, BusinessSurface::Mcp, $definition, $query);
    }

    /**
     * Read one generated business record.
     *
     * @param   ExecutionContext  $context          Authenticated MCP execution context.
     * @param   string            $definition       Definition UUID or handle.
     * @param   string            $record           Public record identity.
     * @param   bool              $includeArchived  Whether an archived row may be returned.
     * @param   bool              $includeDeleted   Whether a soft-deleted row may be returned.
     *
     * @return  array<string, mixed>  Safe definition, record and semantic field models.
     *
     * @since   2.0.0
     */
    public function read(
        ExecutionContext $context,
        string $definition,
        string $record,
        bool $includeArchived = false,
        bool $includeDeleted = false,
    ): array {
        return $this->business->read(
            $context,
            BusinessSurface::Mcp,
            $definition,
            $record,
            $includeArchived,
            $includeDeleted,
        );
    }

    /**
     * Read one bounded page of policy-filtered generated record history.
     *
     * @param   ExecutionContext  $context        Authenticated MCP execution context.
     * @param   string            $definition     Definition UUID or handle.
     * @param   string            $record         Public record identity.
     * @param   int               $limit          Maximum revisions, from 1 through 200.
     * @param   ?int              $beforeVersion  Exclusive positive record-version cursor.
     *
     * @return  array<string, mixed>  Omission-safe revisions and continuation metadata.
     *
     * @since   2.0.0
     */
    public function history(
        ExecutionContext $context,
        string $definition,
        string $record,
        int $limit = 100,
        ?int $beforeVersion = null,
    ): array {
        return $this->history->history(
            $context,
            BusinessSurface::Mcp,
            $definition,
            $record,
            $limit,
            $beforeVersion,
        );
    }

    /**
     * Plan one exact generated-business mutation against current runtime, policy and record state.
     *
     * @param   ExecutionContext      $context            Authenticated MCP execution context.
     * @param   string                $operationId        Identity the plan and eventual mutation share.
     * @param   string                $operation          Closed generated-business mutation name.
     * @param   string                $definition         Definition UUID or handle.
     * @param   ?string               $record             Existing or optional create record identity.
     * @param   ?int                  $expectedVersion    Current version for an existing record.
     * @param   array<string, mixed>  $values             Create or update values.
     * @param   ?string               $relationship       Relation handle for relation mutations.
     * @param   ?string               $target             Target record identity for relation mutations.
     * @param   ?int                  $position           Optional ordered relation position.
     * @param   array<string, mixed>  $targetValues       Owned-line values for relate.
     * @param   list<string>          $orderedRecordIds   Complete relationship order for reorder.
     * @param   ?string               $action             Declared action handle.
     * @param   array<string, mixed>  $input              Typed action input.
     * @param   ?string               $approvalRequestId  Independent approval UUID for action execution.
     *
     * @return  array<string, mixed>  Signed five-minute plan and its safe current bindings.
     *
     * @since   2.0.0
     */
    public function planMutation(
        ExecutionContext $context,
        string $operationId,
        string $operation,
        string $definition,
        ?string $record = null,
        ?int $expectedVersion = null,
        array $values = [],
        ?string $relationship = null,
        ?string $target = null,
        ?int $position = null,
        array $targetValues = [],
        array $orderedRecordIds = [],
        ?string $action = null,
        array $input = [],
        ?string $approvalRequestId = null,
    ): array {
        return $this->plans->create(
            $context,
            BusinessSurface::Mcp,
            $operation,
            self::planInput(
                self::operationId($operationId),
                $operation,
                $definition,
                $record,
                $expectedVersion,
                $values,
                $relationship,
                $target,
                $position,
                $targetValues,
                $orderedRecordIds,
                $action,
                $input,
                $approvalRequestId,
            ),
        );
    }

    /**
     * Create one generated business record under both MCP and record-layer idempotency.
     *
     * @param   ExecutionContext      $context      Authorized MCP execution context.
     * @param   string                $operationId  Caller-chosen 16 to 128 character operation identity.
     * @param   string                $plan         Signed mutation plan for these exact arguments.
     * @param   string                $definition   Definition UUID or handle.
     * @param   array<string, mixed>  $values       Typed values keyed by definition field handle.
     * @param   ?string               $record       Optional caller-chosen public record identity.
     *
     * @return  array<string, mixed>  Safe mutation result or an identical replay.
     *
     * @since   2.0.0
     */
    public function create(
        ExecutionContext $context,
        string $operationId,
        string $plan,
        string $definition,
        array $values,
        ?string $record = null,
    ): array {
        return $this->mutate($context, 'create', $operationId, $plan, [
            'definition' => $definition,
            'values' => $values,
            'record' => $record,
        ], fn (): array => $this->business->create(
            $context,
            BusinessSurface::Mcp,
            $definition,
            $values,
            $operationId,
            $record,
        ));
    }

    /**
     * Update one generated record at the exact version the caller inspected.
     *
     * @param   ExecutionContext      $context          Authorized MCP execution context.
     * @param   string                $operationId      Caller-chosen operation identity.
     * @param   string                $plan             Signed mutation plan for these exact arguments.
     * @param   string                $definition       Definition UUID or handle.
     * @param   string                $record           Public record identity.
     * @param   int                   $expectedVersion  Optimistic version the caller read.
     * @param   array<string, mixed>  $values           Replacement values keyed by field handle.
     *
     * @return  array<string, mixed>  Safe mutation result or an identical replay.
     *
     * @since   2.0.0
     */
    public function update(
        ExecutionContext $context,
        string $operationId,
        string $plan,
        string $definition,
        string $record,
        int $expectedVersion,
        array $values,
    ): array {
        return $this->mutate($context, 'update', $operationId, $plan, [
            'definition' => $definition,
            'record' => $record,
            'expected_version' => $expectedVersion,
            'values' => $values,
        ], fn (): array => $this->business->update(
            $context,
            BusinessSurface::Mcp,
            $definition,
            $record,
            $expectedVersion,
            $values,
            $operationId,
        ));
    }

    /**
     * Archive one generated record at an expected version.
     *
     * @param   ExecutionContext  $context          Authorized MCP execution context.
     * @param   string            $operationId      Caller-chosen operation identity.
     * @param   string            $plan             Signed mutation plan for these exact arguments.
     * @param   string            $definition       Definition UUID or handle.
     * @param   string            $record           Public record identity.
     * @param   int               $expectedVersion  Optimistic version the caller read.
     *
     * @return  array<string, mixed>  Safe mutation result or an identical replay.
     *
     * @since   2.0.0
     */
    public function archive(
        ExecutionContext $context,
        string $operationId,
        string $plan,
        string $definition,
        string $record,
        int $expectedVersion,
    ): array {
        return $this->recordMutation(
            $context,
            'archive',
            $operationId,
            $plan,
            $definition,
            $record,
            $expectedVersion,
            fn (): array => $this->business->archive(
                $context,
                BusinessSurface::Mcp,
                $definition,
                $record,
                $expectedVersion,
                $operationId,
            ),
        );
    }

    /**
     * Restore one archived or soft-deleted generated record at an expected version.
     *
     * @param   ExecutionContext  $context          Authorized MCP execution context.
     * @param   string            $operationId      Caller-chosen operation identity.
     * @param   string            $plan             Signed mutation plan for these exact arguments.
     * @param   string            $definition       Definition UUID or handle.
     * @param   string            $record           Public record identity.
     * @param   int               $expectedVersion  Optimistic version the caller read.
     *
     * @return  array<string, mixed>  Safe mutation result or an identical replay.
     *
     * @since   2.0.0
     */
    public function restore(
        ExecutionContext $context,
        string $operationId,
        string $plan,
        string $definition,
        string $record,
        int $expectedVersion,
    ): array {
        return $this->recordMutation(
            $context,
            'restore',
            $operationId,
            $plan,
            $definition,
            $record,
            $expectedVersion,
            fn (): array => $this->business->restore(
                $context,
                BusinessSurface::Mcp,
                $definition,
                $record,
                $expectedVersion,
                $operationId,
            ),
        );
    }

    /**
     * Delete one generated record at an expected version.
     *
     * @param   ExecutionContext  $context          Authorized MCP execution context.
     * @param   string            $operationId      Caller-chosen operation identity.
     * @param   string            $plan             Signed mutation plan for these exact arguments.
     * @param   string            $definition       Definition UUID or handle.
     * @param   string            $record           Public record identity.
     * @param   int               $expectedVersion  Optimistic version the caller read.
     *
     * @return  array<string, mixed>  Safe mutation result or an identical replay.
     *
     * @since   2.0.0
     */
    public function delete(
        ExecutionContext $context,
        string $operationId,
        string $plan,
        string $definition,
        string $record,
        int $expectedVersion,
    ): array {
        return $this->recordMutation(
            $context,
            'delete',
            $operationId,
            $plan,
            $definition,
            $record,
            $expectedVersion,
            fn (): array => $this->business->delete(
                $context,
                BusinessSurface::Mcp,
                $definition,
                $record,
                $expectedVersion,
                $operationId,
            ),
        );
    }

    /**
     * Relate a generated record to an existing target or a new owned line.
     *
     * @param   ExecutionContext      $context          Authorized MCP execution context.
     * @param   string                $operationId      Caller-chosen operation identity.
     * @param   string                $plan             Signed mutation plan for these exact arguments.
     * @param   string                $definition       Definition UUID or handle.
     * @param   string                $record           Public source record identity.
     * @param   int                   $expectedVersion  Optimistic source version the caller read.
     * @param   string                $relationship     Declared relationship handle.
     * @param   string                $target           Public target identity or new owned-line identity.
     * @param   ?int                  $position         Optional zero-based ordered position.
     * @param   array<string, mixed>  $targetValues     Values used only when creating an owned line.
     *
     * @return  array<string, mixed>  Safe mutation result or an identical replay.
     *
     * @since   2.0.0
     */
    public function relate(
        ExecutionContext $context,
        string $operationId,
        string $plan,
        string $definition,
        string $record,
        int $expectedVersion,
        string $relationship,
        string $target,
        ?int $position = null,
        array $targetValues = [],
    ): array {
        return $this->mutate($context, 'relate', $operationId, $plan, [
            'definition' => $definition,
            'record' => $record,
            'expected_version' => $expectedVersion,
            'relationship' => $relationship,
            'target' => $target,
            'position' => $position,
            'target_values' => $targetValues,
        ], fn (): array => $this->business->relate(
            $context,
            BusinessSurface::Mcp,
            $definition,
            $record,
            $expectedVersion,
            $relationship,
            $target,
            $operationId,
            $position,
            $targetValues,
        ));
    }

    /**
     * Remove one generated-record relationship link.
     *
     * @param   ExecutionContext  $context          Authorized MCP execution context.
     * @param   string            $operationId      Caller-chosen operation identity.
     * @param   string            $plan             Signed mutation plan for these exact arguments.
     * @param   string            $definition       Definition UUID or handle.
     * @param   string            $record           Public source record identity.
     * @param   int               $expectedVersion  Optimistic source version the caller read.
     * @param   string            $relationship     Declared relationship handle.
     * @param   string            $target           Public target identity.
     *
     * @return  array<string, mixed>  Safe mutation result or an identical replay.
     *
     * @since   2.0.0
     */
    public function unrelate(
        ExecutionContext $context,
        string $operationId,
        string $plan,
        string $definition,
        string $record,
        int $expectedVersion,
        string $relationship,
        string $target,
    ): array {
        return $this->mutate($context, 'unrelate', $operationId, $plan, [
            'definition' => $definition,
            'record' => $record,
            'expected_version' => $expectedVersion,
            'relationship' => $relationship,
            'target' => $target,
        ], fn (): array => $this->business->unrelate(
            $context,
            BusinessSurface::Mcp,
            $definition,
            $record,
            $expectedVersion,
            $relationship,
            $target,
            $operationId,
        ));
    }

    /**
     * Reorder every member of one ordered generated-record relationship exactly once.
     *
     * @param   ExecutionContext  $context           Authorized MCP execution context.
     * @param   string            $operationId       Caller-chosen operation identity.
     * @param   string            $plan              Signed mutation plan for these exact arguments.
     * @param   string            $definition        Definition UUID or handle.
     * @param   string            $record            Public source record identity.
     * @param   int               $expectedVersion   Optimistic source version the caller read.
     * @param   string            $relationship      Declared ordered relationship handle.
     * @param   list<string>      $orderedRecordIds  Complete target identity list in its new order.
     *
     * @return  array<string, mixed>  Safe mutation result or an identical replay.
     *
     * @since   2.0.0
     */
    public function reorder(
        ExecutionContext $context,
        string $operationId,
        string $plan,
        string $definition,
        string $record,
        int $expectedVersion,
        string $relationship,
        array $orderedRecordIds,
    ): array {
        return $this->mutate($context, 'reorder', $operationId, $plan, [
            'definition' => $definition,
            'record' => $record,
            'expected_version' => $expectedVersion,
            'relationship' => $relationship,
            'ordered_record_ids' => $orderedRecordIds,
        ], fn (): array => $this->business->reorder(
            $context,
            BusinessSurface::Mcp,
            $definition,
            $record,
            $expectedVersion,
            $relationship,
            $orderedRecordIds,
            $operationId,
        ));
    }

    /**
     * Request maker-checker approval for one exact high-impact action attempt.
     *
     * This creates only the request. MCP deliberately exposes no vote, approve, reject, or step-up proof
     * method, so an agent cannot satisfy the independent checker requirement itself.
     *
     * @param   ExecutionContext      $context          Authorized MCP execution context.
     * @param   string                $operationId      Caller-chosen operation identity.
     * @param   string                $plan             Signed mutation plan for these exact arguments.
     * @param   string                $definition       Definition UUID or handle.
     * @param   string                $record           Public record identity.
     * @param   int                   $expectedVersion  Optimistic record version the caller read.
     * @param   string                $action           Declared high-impact action handle.
     * @param   array<string, mixed>  $input            Typed action input, empty for current core actions.
     *
     * @return  array<string, mixed>  Approval request identity or an identical replay.
     *
     * @since   2.0.0
     */
    public function requestAction(
        ExecutionContext $context,
        string $operationId,
        string $plan,
        string $definition,
        string $record,
        int $expectedVersion,
        string $action,
        array $input = [],
    ): array {
        return $this->mutate($context, 'request_action', $operationId, $plan, [
            'definition' => $definition,
            'record' => $record,
            'expected_version' => $expectedVersion,
            'action' => $action,
            'input' => $input,
        ], fn (): array => $this->business->requestActionApproval(
            $context,
            BusinessSurface::Mcp,
            $definition,
            $record,
            $expectedVersion,
            $action,
            $operationId,
            $input,
        ));
    }

    /**
     * Execute one ordinary declared action; a high-impact attempt fails closed without browser step-up.
     *
     * @param   ExecutionContext      $context            Authorized MCP execution context.
     * @param   string                $operationId        Caller-chosen operation identity.
     * @param   string                $plan               Signed mutation plan for these exact arguments.
     * @param   string                $definition         Definition UUID or handle.
     * @param   string                $record             Public record identity.
     * @param   int                   $expectedVersion    Optimistic record version the caller read.
     * @param   string                $action             Declared action handle.
     * @param   array<string, mixed>  $input              Typed action input.
     * @param   ?string               $approvalRequestId  Independently approved request UUID when required.
     *
     * @return  array<string, mixed>  Safe mutation result or an identical replay.
     *
     * @since   2.0.0
     */
    public function executeAction(
        ExecutionContext $context,
        string $operationId,
        string $plan,
        string $definition,
        string $record,
        int $expectedVersion,
        string $action,
        array $input = [],
        ?string $approvalRequestId = null,
    ): array {
        return $this->mutate($context, 'execute_action', $operationId, $plan, [
            'definition' => $definition,
            'record' => $record,
            'expected_version' => $expectedVersion,
            'action' => $action,
            'input' => $input,
            'approval_request_id' => $approvalRequestId,
        ], fn (): array => $this->business->action(
            $context,
            BusinessSurface::Mcp,
            $definition,
            $record,
            $expectedVersion,
            $action,
            $operationId,
            $input,
            $approvalRequestId,
        ));
    }

    /**
     * Inspect one caller- and credential-bound MCP mutation outcome.
     *
     * @param   ExecutionContext  $context      Authorized MCP execution context.
     * @param   string            $operationId  Operation identity used for the original mutation.
     *
     * @return  array<string, mixed>  Caller-bound completed state and omission-safe canonical result.
     *
     * @since   2.0.0
     */
    public function operationStatus(
        ExecutionContext $context,
        string $operationId,
    ): array {
        $operationId = self::operationId($operationId);

        return $this->operations->get($context, $operationId);
    }

    /**
     * Resolve the capability required by one generated-business mutation.
     *
     * @param   string  $operation  Closed generated-business mutation name.
     *
     * @return  string  Exact business-record capability.
     *
     * @throws  InvalidArgumentException  When the operation is outside the published mutation vocabulary.
     *
     * @since   2.0.0
     */
    public static function capabilityFor(string $operation): string
    {
        return self::MUTATION_CAPABILITIES[$operation]
            ?? throw new InvalidArgumentException('The generated-business MCP operation is unsupported.');
    }

    /**
     * Run one mutation under a stable generated-business MCP operation name.
     *
     * @template TResult of array<string, mixed>
     *
     * @param   ExecutionContext      $context      Authorized MCP execution context.
     * @param   string                $operation    Closed mutation name.
     * @param   string                $operationId  Caller-chosen operation identity.
     * @param   string                $plan         Signed plan bound to the current mutation state.
     * @param   array<string, mixed>  $input        Canonical mutation arguments excluding the identity.
     * @param   callable(): TResult   $mutation     Shared surface mutation invoked at most once.
     *
     * @return  TResult  First mutation result or the credential-bound replay.
     *
     * @since   2.0.0
     */
    private function mutate(
        ExecutionContext $context,
        string $operation,
        string $operationId,
        string $plan,
        array $input,
        callable $mutation,
    ): array {
        $plannedInput = ['operation_id' => $operationId, ...$input];
        $executed = false;
        /** @var TResult $result */
        $result = $this->mutations->run(
            $context,
            self::guardOperation($operation),
            $operationId,
            ['plan' => $plan, ...$plannedInput],
            function () use (&$executed, $context, $operation, $plan, $plannedInput, $mutation): array {
                $this->plans->assertCanExecute(
                    $context,
                    BusinessSurface::Mcp,
                    $plan,
                    $operation,
                    $plannedInput,
                );
                $executed = true;

                return $mutation();
            },
        );
        if (!$executed) {
            $this->plans->assertCanReplay(
                $context,
                BusinessSurface::Mcp,
                $plan,
                $operation,
                $plannedInput,
            );
        }

        return $result;
    }

    /**
     * Run an expected-version record lifecycle mutation through the common replay input shape.
     *
     * @template TResult of array<string, mixed>
     *
     * @param   ExecutionContext     $context          Authorized MCP execution context.
     * @param   string               $operation        Archive, restore, or delete.
     * @param   string               $operationId      Caller-chosen operation identity.
     * @param   string               $plan             Signed plan bound to the mutation.
     * @param   string               $definition       Definition UUID or handle.
     * @param   string               $record           Public record identity.
     * @param   int                  $expectedVersion  Optimistic version the caller read.
     * @param   callable(): TResult  $mutation         Shared surface mutation invoked at most once.
     *
     * @return  TResult  First mutation result or the credential-bound replay.
     *
     * @since   2.0.0
     */
    private function recordMutation(
        ExecutionContext $context,
        string $operation,
        string $operationId,
        string $plan,
        string $definition,
        string $record,
        int $expectedVersion,
        callable $mutation,
    ): array {
        return $this->mutate($context, $operation, $operationId, $plan, [
            'definition' => $definition,
            'record' => $record,
            'expected_version' => $expectedVersion,
        ], $mutation);
    }

    /**
     * Build the canonical input shared by plan issuance and execution revalidation.
     *
     * @param   string                $operationId        Shared plan and mutation identity.
     * @param   string                $operation          Closed generated-business mutation name.
     * @param   string                $definition         Definition UUID or handle.
     * @param   ?string               $record             Existing or optional create record identity.
     * @param   ?int                  $expectedVersion    Current version for existing-record mutations.
     * @param   array<string, mixed>  $values             Create or update values.
     * @param   ?string               $relationship       Relation handle.
     * @param   ?string               $target             Target record identity.
     * @param   ?int                  $position           Optional ordered position.
     * @param   array<string, mixed>  $targetValues       Optional owned-line values.
     * @param   list<string>          $orderedRecordIds   Complete ordered target list.
     * @param   ?string               $action             Declared action handle.
     * @param   array<string, mixed>  $input              Typed action input.
     * @param   ?string               $approvalRequestId  Independent approval identity.
     *
     * @return  array<string, mixed>  Exact snake-case operation input.
     *
     * @throws  InvalidArgumentException  When an operation-specific required member is absent.
     *
     * @since   2.0.0
     */
    private static function planInput(
        string $operationId,
        string $operation,
        string $definition,
        ?string $record,
        ?int $expectedVersion,
        array $values,
        ?string $relationship,
        ?string $target,
        ?int $position,
        array $targetValues,
        array $orderedRecordIds,
        ?string $action,
        array $input,
        ?string $approvalRequestId,
    ): array {
        return match ($operation) {
            'create' => [
                'operation_id' => $operationId,
                'definition' => $definition,
                'values' => $values,
                'record' => $record,
            ],
            'update' => [
                'operation_id' => $operationId,
                'definition' => $definition,
                'record' => self::required($record, 'record'),
                'expected_version' => self::version($expectedVersion),
                'values' => $values,
            ],
            'archive', 'restore', 'delete' => [
                'operation_id' => $operationId,
                'definition' => $definition,
                'record' => self::required($record, 'record'),
                'expected_version' => self::version($expectedVersion),
            ],
            'relate' => [
                'operation_id' => $operationId,
                'definition' => $definition,
                'record' => self::required($record, 'record'),
                'expected_version' => self::version($expectedVersion),
                'relationship' => self::required($relationship, 'relationship'),
                'target' => self::required($target, 'target'),
                'position' => $position,
                'target_values' => $targetValues,
            ],
            'unrelate' => [
                'operation_id' => $operationId,
                'definition' => $definition,
                'record' => self::required($record, 'record'),
                'expected_version' => self::version($expectedVersion),
                'relationship' => self::required($relationship, 'relationship'),
                'target' => self::required($target, 'target'),
            ],
            'reorder' => [
                'operation_id' => $operationId,
                'definition' => $definition,
                'record' => self::required($record, 'record'),
                'expected_version' => self::version($expectedVersion),
                'relationship' => self::required($relationship, 'relationship'),
                'ordered_record_ids' => $orderedRecordIds,
            ],
            'request_action' => [
                'operation_id' => $operationId,
                'definition' => $definition,
                'record' => self::required($record, 'record'),
                'expected_version' => self::version($expectedVersion),
                'action' => self::required($action, 'action'),
                'input' => $input,
            ],
            'execute_action' => [
                'operation_id' => $operationId,
                'definition' => $definition,
                'record' => self::required($record, 'record'),
                'expected_version' => self::version($expectedVersion),
                'action' => self::required($action, 'action'),
                'input' => $input,
                'approval_request_id' => $approvalRequestId,
            ],
            default => throw new InvalidArgumentException(
                'The generated-business MCP operation is unsupported.',
            ),
        };
    }

    /**
     * Require one operation-specific plan string.
     *
     * @param   ?string  $value  Candidate value.
     * @param   string   $name   Safe member name for the rejection.
     *
     * @return  string  Non-empty original value.
     *
     * @throws  InvalidArgumentException  When the member is absent or empty.
     *
     * @since   2.0.0
     */
    private static function required(?string $value, string $name): string
    {
        if ($value === null || $value === '') {
            throw new InvalidArgumentException('The mutation plan requires ' . $name . '.');
        }

        return $value;
    }

    /**
     * Require one positive optimistic version for a planned existing-record mutation.
     *
     * @param   ?int  $version  Candidate expected version.
     *
     * @return  int  Positive expected version.
     *
     * @throws  InvalidArgumentException  When the version is absent or below one.
     *
     * @since   2.0.0
     */
    private static function version(?int $version): int
    {
        if ($version === null || $version < 1) {
            throw new InvalidArgumentException('The mutation plan requires a positive expected version.');
        }

        return $version;
    }

    /**
     * Require the MCP mutation guard's exact operation-identity grammar.
     *
     * @param   string  $operationId  Candidate caller-chosen identity.
     *
     * @return  string  Validated 16 to 128 character identity.
     *
     * @throws  InvalidArgumentException  When the identity is outside the MCP guard grammar.
     *
     * @since   2.0.0
     */
    private static function operationId(string $operationId): string
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{15,127}$/D', $operationId) !== 1) {
            throw new InvalidArgumentException('MCP operationId must be a stable 16 to 128 character identifier.');
        }

        return $operationId;
    }

    /**
     * Bind a closed public mutation name to the internal MCP ledger operation.
     *
     * @param   string  $operation  Closed mutation name.
     *
     * @return  string  Unprefixed operation name accepted by `McpMutationGuard`.
     *
     * @throws  InvalidArgumentException  When the operation is outside the published mutation vocabulary.
     *
     * @since   2.0.0
     */
    private static function guardOperation(string $operation): string
    {
        self::capabilityFor($operation);

        return 'business_record.' . $operation;
    }
}
