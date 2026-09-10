<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessSurface\Application;

use InvalidArgumentException;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\App\Application\Persistence\TransactionManager;
use Kumwe\App\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\App\BusinessDefinition\Domain\ScopeMode;
use Kumwe\App\BusinessRecord\Application\BusinessRecordDefinitionResolver;
use Kumwe\App\BusinessRecord\Application\RecordFingerprint;
use Kumwe\App\BusinessRecord\Application\RecordMutationResult;
use Kumwe\App\BusinessRecord\Domain\BusinessRecordIdempotencyState;
use Kumwe\App\BusinessRecord\Domain\RecordScope;
use Kumwe\App\BusinessSecurity\Application\BusinessRecordAccessController;
use Kumwe\App\BusinessSecurity\Application\BusinessRecordAccessPlan;
use Kumwe\App\BusinessSurface\Application\Custom\CustomBusinessActionLedgerResult;
use Kumwe\Extension\Spi\BusinessSurface\Application\Custom\CustomBusinessSchema;
use Kumwe\App\BusinessSurface\Application\Custom\CustomBusinessSurfaceDispatcher;
use Kumwe\App\Extension\Runtime\RuntimeMaterializationState;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;

/**
 * Resolves an operation outcome only for the exact actor, scope, policy and delivery surface that owns it.
 *
 * @since  2.0.0
 */
final readonly class BusinessOperationStatusService
{
    /**
     * Configure caller-bound status validation and safe result projection.
     *
     * @param  BusinessOperationStatusRepository  $operations    Scoped verified ledger lookup.
     * @param  TransactionManager                 $transactions  Holds the status proof snapshot stable.
     * @param  BusinessRecordDefinitionResolver   $definitions   Trusted active definition resolver.
     * @param  BusinessRecordAccessController     $access        Canonical record-policy planner.
     * @param  RecordFingerprint                  $fingerprints  Keyed authorization digest service.
     * @param  BusinessSurfaceCatalog             $catalog       Shared surface and exposure gate.
     * @param  BusinessRecordProjector            $projector     Internal-identity-safe result projector.
     * @param  CustomBusinessSurfaceDispatcher    $custom        Active typed custom-action contracts.
     * @param  RuntimeMaterializationState        $runtime       Exact trusted extension generation.
     * @param  ClockInterface                     $clock         Expiry authority.
     *
     * @since  2.0.0
     */
    public function __construct(
        private BusinessOperationStatusRepository $operations,
        private TransactionManager $transactions,
        private BusinessRecordDefinitionResolver $definitions,
        private BusinessRecordAccessController $access,
        private RecordFingerprint $fingerprints,
        private BusinessSurfaceCatalog $catalog,
        private BusinessRecordProjector $projector,
        private CustomBusinessSurfaceDispatcher $custom,
        private RuntimeMaterializationState $runtime,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Return one completed mutation outcome without making operation identifiers enumerable.
     *
     * @param   ExecutionContext  $context      Authenticated actor and exact membership.
     * @param   string            $operationId  Caller-supplied operation identity.
     *
     * @return  array<string, mixed>  Safe status and projected result.
     *
     * @throws  BusinessOperationNotFound  When absent, expired, ambiguous, denied or bound to changed policy.
     * @throws  InvalidArgumentException  When the operation identifier is malformed.
     *
     * @since   2.0.0
     */
    public function get(ExecutionContext $context, string $operationId): array
    {
        return $this->resolve($context, $operationId);
    }

    /**
     * Return one completed mutation outcome with its already-authorized browser route reference.
     *
     * The ordinary status contract deliberately omits the definition identity. Generated browser pages
     * need a safe return link after a successful lookup, so this variant adds only the active definition
     * handle after the same actor, scope, policy, surface, expiry and ledger proofs have all succeeded.
     *
     * @param   ExecutionContext  $context      Authenticated actor and exact membership.
     * @param   string            $operationId  Caller-supplied operation identity.
     *
     * @return  array<string, mixed>  Safe status, projected result, and definition route reference.
     *
     * @throws  BusinessOperationNotFound  When absent, expired, ambiguous, denied or bound to changed policy.
     * @throws  InvalidArgumentException  When the operation identifier is malformed.
     *
     * @since   2.0.0
     */
    public function getWithDefinitionReference(ExecutionContext $context, string $operationId): array
    {
        return $this->resolve($context, $operationId, true);
    }

    /**
     * Resolve one caller-bound status document and optionally retain its safe definition reference.
     *
     * @param   ExecutionContext  $context                     Authenticated actor and exact membership.
     * @param   string            $operationId                 Caller-supplied operation identity.
     * @param   bool              $includeDefinitionReference  Whether to add the active definition handle.
     *
     * @return  array<string, mixed>  Safe status projection.
     *
     * @throws  BusinessOperationNotFound  When any availability or binding proof fails.
     * @throws  InvalidArgumentException  When the operation identifier is malformed.
     *
     * @since   2.0.0
     */
    private function resolve(
        ExecutionContext $context,
        string $operationId,
        bool $includeDefinitionReference = false,
    ): array {
        return $this->transactions->transactional(fn (): array => $this->resolveInTransaction(
            $context,
            $operationId,
            $includeDefinitionReference,
        ));
    }

    /**
     * Re-prove one operation while its ledger, definition and policy generations share a transaction.
     *
     * @param   ExecutionContext  $context                     Authenticated actor and exact membership.
     * @param   string            $operationId                 Caller-supplied operation identity.
     * @param   bool              $includeDefinitionReference  Whether to add the active definition handle.
     *
     * @return  array<string, mixed>  Safe status projection.
     *
     * @throws  BusinessOperationNotFound  When any availability or binding proof fails.
     * @throws  InvalidArgumentException  When the operation identifier is malformed.
     *
     * @since   2.0.0
     */
    private function resolveInTransaction(
        ExecutionContext $context,
        string $operationId,
        bool $includeDefinitionReference,
    ): array {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{7,127}$/D', $operationId) !== 1) {
            throw new InvalidArgumentException('A business operation identity is invalid.');
        }
        $entry = $this->operations->find($context, $operationId, $this->clock->now());
        $storedResult = $entry?->result();
        if ($entry === null || $entry->state !== BusinessRecordIdempotencyState::Completed || $storedResult === null) {
            throw new BusinessOperationNotFound();
        }
        $definitionReference = null;
        try {
            $approvalResult = $this->approvalResult($entry->operation, $storedResult);
            $customResult = $approvalResult === null && CustomBusinessActionLedgerResult::recognizes($storedResult)
                ? CustomBusinessActionLedgerResult::fromArray($storedResult)
                : null;
            $result = $approvalResult === null && $customResult === null
                ? RecordMutationResult::fromArray($storedResult)
                : null;
            $definitionId = match (true) {
                $approvalResult !== null => $approvalResult['definition_id'],
                $customResult !== null => $customResult->definitionId,
                $result !== null => $result->definitionId,
                default => null,
            };
            if (!is_string($definitionId)) {
                throw new BusinessOperationNotFound();
            }
            $resolved = $this->definitions->forCreate($context, $definitionId);
            $scope = RecordScope::forDefinition(
                $resolved->definition->scope,
                $context->site(),
                in_array(
                    $resolved->definition->scope,
                    [ScopeMode::Organization, ScopeMode::SiteOrganization],
                    true,
                ) ? $context->organization()?->identifier() : null,
            );
            $policyOperation = in_array(
                $entry->operation,
                ['business.record.unrelate', 'business.record.reorder'],
                true,
            ) ? 'business.record.relate' : match ($entry->operation) {
                'business.record.action_approval_request' => 'business.record.action',
                default => $entry->operation,
            };
            $plan = $this->access->plan($context, $policyOperation, $resolved, $scope);
            $authorization = $this->fingerprints->digest([
                'context' => $context->authorizationFingerprint(),
                'record_access' => $plan->digest(),
            ]);
            $surface = BusinessSurface::fromAuthenticated($context->surface());
            if ($surface === null || !hash_equals($authorization, $entry->authorizationFingerprint)) {
                throw new BusinessOperationNotFound();
            }
            if ($customResult !== null) {
                $this->assertCustomResult(
                    $context,
                    $surface,
                    $entry->operation,
                    $resolved->definition,
                    $plan,
                    $customResult,
                );
            }
            $this->catalog->definition(
                $context,
                $surface,
                $definitionId,
                BusinessSurfaceOperation::Status,
            );
            $definitionReference = $resolved->definition->handle;
        } catch (BusinessOperationNotFound $exception) {
            throw $exception;
        } catch (\Throwable) {
            throw new BusinessOperationNotFound();
        }

        $status = [
            'operation_id' => $operationId,
            'state' => $entry->state->value,
            'operation' => $entry->operation,
            'created_at' => $entry->createdAt->format(DATE_ATOM),
            'completed_at' => $entry->completedAt?->format(DATE_ATOM),
            'expires_at' => $entry->expiresAt->format(DATE_ATOM),
            'result' => match (true) {
                $approvalResult !== null => ['approval_request_id' => $approvalResult['approval_request_id']],
                $customResult !== null => $customResult->publicResult(),
                default => $this->projector->mutation($result ?? throw new BusinessOperationNotFound()),
            },
        ];
        if ($includeDefinitionReference) {
            $status['definition_reference'] = $definitionReference;
        }

        return $status;
    }

    /**
     * Re-prove a tagged custom result against the exact current definition, runtime, contract and policy.
     *
     * @param   ExecutionContext                  $context     Current authenticated actor and surface.
     * @param   BusinessSurface                   $surface     Authenticated generated delivery boundary.
     * @param   string                            $operation   Stored ledger operation identity.
     * @param   EntityTypeDefinition              $definition  Current active installed definition.
     * @param   BusinessRecordAccessPlan          $plan        Current action policy decision.
     * @param   CustomBusinessActionLedgerResult  $result      Strictly parsed tagged stored result.
     *
     * @return  void
     *
     * @throws  BusinessOperationNotFound  When any activation, declaration, policy, or schema binding drifted.
     *
     * @since   2.0.0
     */
    private function assertCustomResult(
        ExecutionContext $context,
        BusinessSurface $surface,
        string $operation,
        EntityTypeDefinition $definition,
        BusinessRecordAccessPlan $plan,
        CustomBusinessActionLedgerResult $result,
    ): void {
        if (
            $operation !== 'business.record.action'
            || !$this->runtime->trusted
            || $result->runtimeGeneration !== $this->runtime->generation
            || !hash_equals($result->runtimeChecksum, $this->runtime->publicationChecksum)
            || $result->definitionId !== $definition->id
            || $result->definitionVersion !== $definition->definitionVersion
            || !hash_equals($result->definitionChecksum, $definition->checksum())
            || !$plan->allowsAction($result->action)
        ) {
            throw new BusinessOperationNotFound();
        }
        $declaration = null;
        foreach ($definition->actions() as $candidate) {
            if ($candidate->handle === $result->action) {
                $declaration = $candidate;
                break;
            }
        }
        if (
            $declaration === null
            || $declaration->transition !== null
            || $declaration->handler !== $result->handler
            || $declaration->schema !== $result->schema
        ) {
            throw new BusinessOperationNotFound();
        }
        $metadata = $this->catalog->definition(
            $context,
            $surface,
            $definition->id,
            BusinessSurfaceOperation::Action,
        );
        $actions = $metadata['actions'] ?? null;
        if (!is_array($actions) || !array_is_list($actions)) {
            throw new BusinessOperationNotFound();
        }
        $available = false;
        foreach ($actions as $action) {
            if (is_array($action) && ($action['handle'] ?? null) === $result->action) {
                $available = true;
                break;
            }
        }
        if (!$available) {
            throw new BusinessOperationNotFound();
        }
        $schemas = $this->custom->actionContractSchemas($definition, $result->action)
            ?? throw new BusinessOperationNotFound();
        CustomBusinessSchema::fromArray($schemas['result_schema'])->assertValid(
            $result->data,
            'action result',
        );
    }

    /**
     * Recognize and validate the internal ledger result for an action-approval request.
     *
     * The definition UUID remains internal evidence used to re-plan access. Only the approval request UUID
     * is copied into the status document after that proof succeeds.
     *
     * @param   string                $operation  Ledger operation name.
     * @param   array<string, mixed>  $result     Checksum-verified stored result.
     *
     * @return  array{definition_id: string, approval_request_id: string|null}|null  Validated approval result,
     *          or null when this is an ordinary record mutation.
     *
     * @throws  BusinessOperationNotFound  When an approval result is malformed.
     *
     * @since   2.0.0
     */
    private function approvalResult(string $operation, array $result): ?array
    {
        if ($operation !== 'business.record.action_approval_request') {
            return null;
        }
        if (array_keys($result) !== ['definition_id', 'approval_request_id']) {
            throw new BusinessOperationNotFound();
        }
        $definitionId = $result['definition_id'];
        $approvalRequestId = $result['approval_request_id'];
        if (
            !is_string($definitionId) || !Uuid::isValid($definitionId)
            || (
                $approvalRequestId !== null
                && (!is_string($approvalRequestId) || !Uuid::isValid($approvalRequestId))
            )
        ) {
            throw new BusinessOperationNotFound();
        }

        return [
            'definition_id' => $definitionId,
            'approval_request_id' => $approvalRequestId,
        ];
    }
}
