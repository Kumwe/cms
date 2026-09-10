<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessSurface\Application;

use DateInterval;
use DateTimeImmutable;
use InvalidArgumentException;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\App\Application\Persistence\TransactionManager;
use Kumwe\App\BusinessDefinition\Domain\ActionDefinition;
use Kumwe\App\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\App\BusinessDefinition\Domain\ScopeMode;
use Kumwe\App\BusinessRecord\Application\BusinessRecordCustomActionGuard;
use Kumwe\App\BusinessRecord\Application\BusinessRecordDefinitionResolver;
use Kumwe\App\BusinessRecord\Application\BusinessRecordIdempotencyRepository;
use Kumwe\App\BusinessRecord\Application\BusinessRecordMutationFence;
use Kumwe\App\BusinessRecord\Application\Command\ExecuteRecordActionCommand;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordActionRejected;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordDefinitionUnavailable;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordIdempotencyConflict;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordIdempotencyRace;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordNotFound;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordTemporarilyUnavailable;
use Kumwe\App\BusinessRecord\Application\RecordFingerprint;
use Kumwe\App\BusinessRecord\Application\ResolvedBusinessDefinition;
use Kumwe\App\BusinessRecord\Domain\BusinessRecordIdempotency;
use Kumwe\App\BusinessRecord\Domain\BusinessRecordIdempotencyState;
use Kumwe\App\BusinessRecord\Domain\RecordScope;
use Kumwe\App\BusinessSecurity\Application\BusinessRecordAccessController;
use Kumwe\App\BusinessSurface\Application\Custom\CustomBusinessActionLedgerResult;
use Kumwe\App\BusinessSurface\Application\Custom\CustomBusinessSurfaceDispatcher;
use Kumwe\Extension\Spi\BusinessSurface\Application\Custom\CustomBusinessActionCommand;
use Kumwe\Extension\Spi\BusinessSurface\Application\Custom\CustomBusinessActionResult;
use Kumwe\Extension\Spi\BusinessSurface\Application\Custom\CustomBusinessSchema;
use Kumwe\App\Extension\Runtime\RuntimeMaterializationState;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use Kumwe\App\Extension\Runtime\ExtensionExecutionContext;

/**
 * Executes typed custom actions through the canonical transaction, fence, policy and idempotency ledger.
 *
 * Extension code runs only after an exclusive definition generation, current row/action guard, exact
 * approval, and durable in-progress claim are held. Its registry-validated result is stored as a tagged
 * bounded envelope in the same transaction, so a retry never re-enters the handler and operation-status
 * reads can reproduce the exact caller-visible result without exposing activation references.
 *
 * @since  2.0.0
 */
final readonly class CustomBusinessActionExecutor
{
    /**
     * Build the shared custom action mutation boundary.
     *
     * @param  CustomBusinessSurfaceDispatcher      $dispatcher    Exact owner/handler/schema dispatcher.
     * @param  BusinessRecordCustomActionGuard      $guard         Canonical row, condition and approval guard.
     * @param  BusinessRecordIdempotencyRepository  $idempotency   Shared at-most-once operation ledger.
     * @param  BusinessRecordMutationFence          $fence         Exclusive installed-definition fence.
     * @param  BusinessRecordDefinitionResolver     $definitions   Trusted active definition resolver.
     * @param  BusinessRecordAccessController       $access        Canonical record-policy planner.
     * @param  RecordFingerprint                    $fingerprints  Keyed request, scope and result digests.
     * @param  TransactionManager                   $transactions  Transaction enclosing claim, handler and result.
     * @param  ClockInterface                       $clock         Trusted claim and expiry time.
     * @param  RuntimeMaterializationState          $runtime       Exact trusted handler publication generation.
     *
     * @since  2.0.0
     */
    public function __construct(
        private CustomBusinessSurfaceDispatcher $dispatcher,
        private BusinessRecordCustomActionGuard $guard,
        private BusinessRecordIdempotencyRepository $idempotency,
        private BusinessRecordMutationFence $fence,
        private BusinessRecordDefinitionResolver $definitions,
        private BusinessRecordAccessController $access,
        private RecordFingerprint $fingerprints,
        private TransactionManager $transactions,
        private ClockInterface $clock,
        private RuntimeMaterializationState $runtime,
    ) {
    }

    /**
     * Execute or replay one custom action under its exact immutable request and authority binding.
     *
     * @param   CustomBusinessActionCommand  $command  Validated typed action command.
     *
     * @return  CustomBusinessActionResult  Fresh registry result or exact durable replay.
     *
     * @throws  BusinessRecordIdempotencyConflict  When a key is reused, unfinished, expired, or corrupt.
     * @throws  BusinessRecordTemporarilyUnavailable  When runtime is untrusted or three transient attempts fail.
     * @throws  BusinessRecordDefinitionUnavailable  When the exact custom contract is inactive.
     * @throws  \Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordPostingPeriodClosed  When the
     *          record's declared posting date falls inside a closed posting period.
     *
     * @since   2.0.0
     */
    public function execute(CustomBusinessActionCommand $command): CustomBusinessActionResult
    {
        $context = self::context($command);
        if (
            !$this->runtime->trusted
            || $this->runtime->generation < 0
            || preg_match('/^[a-f0-9]{64}$/D', $this->runtime->publicationChecksum) !== 1
        ) {
            throw new BusinessRecordTemporarilyUnavailable();
        }
        $operation = 'business.record.action';
        // The posting-period lock is declared to run before the mutation fence, so it is evaluated
        // here, ahead of the transaction the fence is taken in, through the same guard that later
        // proves the attempt under the fence.
        $this->guard->guardCustomActionPostingPeriod(new ExecuteRecordActionCommand(
            $context,
            $command->definitionIdentifier,
            $command->recordId,
            $command->expectedVersion,
            $command->action,
            $command->idempotencyKey,
            $command->input,
            $command->organizationIdentifier,
            $command->approvalRequestId,
        ));
        $authenticatedOrganization = $context->organization()?->identifier();
        $scopeDigest = $this->fingerprints->digest([
            'site' => $context->site()->identifier(),
            'organization' => $authenticatedOrganization,
            'actor' => $context->actorId(),
            'operation' => $operation,
            'key' => $command->idempotencyKey->value(),
        ]);

        for ($attempt = 0; $attempt < 3; ++$attempt) {
            try {
                return $this->transactions->transactional(function () use (
                    $command,
                    $context,
                    $operation,
                    $scopeDigest,
                    $authenticatedOrganization,
                ): CustomBusinessActionResult {
                    $generation = $this->fence->lock($context, $command->definitionIdentifier);
                    $resolved = $this->definitions->forCreate(
                        $context,
                        $command->definitionIdentifier,
                    );
                    $generation->assertMatches($resolved);
                    $action = $this->action($resolved->definition, $command->action);
                    $requestFingerprint = $this->fingerprints->digest($this->requestBinding(
                        $command,
                        $resolved->definition,
                        $action,
                    ));
                    $scope = $this->scope($resolved, $command, $context);
                    $plan = $this->access->plan($context, $operation, $resolved, $scope);
                    if (!$plan->allowsAction($command->action)) {
                        throw new BusinessRecordNotFound();
                    }
                    $authorizationFingerprint = $this->fingerprints->digest([
                        'context' => $context->authorizationFingerprint(),
                        'record_access' => $plan->digest(),
                    ]);
                    $now = $this->clock->now();
                    $existing = $this->idempotency->find($scopeDigest);
                    if ($existing !== null) {
                        return $this->replay(
                            $existing,
                            $requestFingerprint,
                            $authorizationFingerprint,
                            $now,
                            $resolved->definition,
                            $action,
                            $command,
                        );
                    }
                    $entry = new BusinessRecordIdempotency(
                        Uuid::uuid7()->toString(),
                        $scopeDigest,
                        $context->site()->identifier(),
                        $authenticatedOrganization,
                        $context->actorId(),
                        $operation,
                        $command->idempotencyKey->value(),
                        $requestFingerprint,
                        $authorizationFingerprint,
                        BusinessRecordIdempotencyState::InProgress,
                        null,
                        null,
                        $now,
                        null,
                        $now->add(new DateInterval('P1D')),
                    );
                    $this->idempotency->begin($entry);
                    $this->guard->guardCustomAction(new ExecuteRecordActionCommand(
                        $context,
                        $command->definitionIdentifier,
                        $command->recordId,
                        $command->expectedVersion,
                        $command->action,
                        $command->idempotencyKey,
                        $command->input,
                        $command->organizationIdentifier,
                        $command->approvalRequestId,
                    ), $generation);
                    $result = $this->dispatcher->action($resolved->definition, $command);
                    if ($result->recordVersion < $command->expectedVersion) {
                        throw new BusinessRecordActionRejected(
                            'A custom action returned a record version older than its precondition.',
                        );
                    }
                    $stored = CustomBusinessActionLedgerResult::capture(
                        $resolved->definition,
                        $action,
                        $command->recordId,
                        $this->runtime->generation,
                        $this->runtime->publicationChecksum,
                        $result,
                    );
                    $document = $stored->toArray();
                    $this->idempotency->complete(
                        $entry->id,
                        $document,
                        $this->fingerprints->digest($document),
                        $now,
                    );

                    return $result;
                });
            } catch (BusinessRecordIdempotencyRace) {
                continue;
            } catch (BusinessRecordTemporarilyUnavailable $exception) {
                if ($attempt === 2) {
                    throw $exception;
                }
            }
        }

        throw new BusinessRecordTemporarilyUnavailable();
    }

    /**
     * Prove and reconstruct a completed custom result without invoking extension code again.
     *
     * @param   BusinessRecordIdempotency    $entry                     Existing ledger claim.
     * @param   string                       $requestFingerprint        Current exact request digest.
     * @param   string                       $authorizationFingerprint  Current exact authority digest.
     * @param   DateTimeImmutable            $now                       Trusted expiry time.
     * @param   EntityTypeDefinition         $definition                Definition held by the fence.
     * @param   ActionDefinition             $action                    Current exact custom declaration.
     * @param   CustomBusinessActionCommand  $command                   Current typed command.
     *
     * @return  CustomBusinessActionResult  Stored result marked as an outer-ledger replay.
     *
     * @throws  BusinessRecordIdempotencyConflict  When any replay proof fails.
     * @throws  BusinessRecordDefinitionUnavailable  When the active contract no longer matches.
     *
     * @since   2.0.0
     */
    private function replay(
        BusinessRecordIdempotency $entry,
        string $requestFingerprint,
        string $authorizationFingerprint,
        DateTimeImmutable $now,
        EntityTypeDefinition $definition,
        ActionDefinition $action,
        CustomBusinessActionCommand $command,
    ): CustomBusinessActionResult {
        if (!$entry->matches($requestFingerprint, $authorizationFingerprint) || $now >= $entry->expiresAt) {
            throw new BusinessRecordIdempotencyConflict('key_reused');
        }
        $document = $entry->result();
        if (!$entry->isCompleted() || $document === null) {
            throw new BusinessRecordIdempotencyConflict('in_progress');
        }
        if (
            $entry->resultChecksum === null
            || !hash_equals($entry->resultChecksum, $this->fingerprints->digest($document))
        ) {
            throw new BusinessRecordIdempotencyConflict('corrupt');
        }
        try {
            $stored = CustomBusinessActionLedgerResult::fromArray($document);
        } catch (InvalidArgumentException) {
            throw new BusinessRecordIdempotencyConflict('corrupt');
        }
        if (
            $action->handler === null
            || $action->schema === null
            || $stored->definitionId !== $definition->id
            || $stored->definitionVersion !== $definition->definitionVersion
            || !hash_equals($stored->definitionChecksum, $definition->checksum())
            || $stored->runtimeGeneration !== $this->runtime->generation
            || !hash_equals($stored->runtimeChecksum, $this->runtime->publicationChecksum)
            || $stored->handler !== $action->handler
            || $stored->schema !== $action->schema
            || $stored->action !== $action->handle
            || $stored->recordId !== $command->recordId
        ) {
            throw new BusinessRecordIdempotencyConflict('key_reused');
        }
        $schemas = $this->dispatcher->actionContractSchemas($definition, $action->handle)
            ?? throw new BusinessRecordDefinitionUnavailable();
        CustomBusinessSchema::fromArray($schemas['result_schema'])->assertValid(
            $stored->data,
            'action result',
        );

        return $stored->result($command->idempotencyKey, true);
    }

    /**
     * Build the canonical request digest input, including definition, contract and runtime generations.
     *
     * @param   CustomBusinessActionCommand  $command     Current typed command.
     * @param   EntityTypeDefinition         $definition  Active installed definition.
     * @param   ActionDefinition             $action      Exact custom declaration.
     *
     * @return  array<string, mixed>  Canonical exact request binding.
     *
     * @since   2.0.0
     */
    private function requestBinding(
        CustomBusinessActionCommand $command,
        EntityTypeDefinition $definition,
        ActionDefinition $action,
    ): array {
        return [
            'definition_id' => $definition->id,
            'definition_version' => $definition->definitionVersion,
            'definition_checksum' => $definition->checksum(),
            'runtime_generation' => $this->runtime->generation,
            'runtime_checksum' => $this->runtime->publicationChecksum,
            'handler' => $action->handler,
            'schema' => $action->schema,
            'record_id' => $command->recordId,
            'expected_version' => $command->expectedVersion,
            'action' => $command->action,
            'input' => $command->input,
            'organization' => $command->organizationIdentifier,
            'approval_request_id' => $command->approvalRequestId,
        ];
    }

    /**
     * Resolve one complete custom declaration without disclosing which reference is unavailable.
     *
     * @param   EntityTypeDefinition  $definition  Active installed definition.
     * @param   string                $handle      Requested action handle.
     *
     * @return  ActionDefinition  Exact custom declaration.
     *
     * @throws  BusinessRecordDefinitionUnavailable  When absent, ordinary, or inactive.
     *
     * @since   2.0.0
     */
    private function action(EntityTypeDefinition $definition, string $handle): ActionDefinition
    {
        foreach ($definition->actions() as $action) {
            if (
                $action->handle === $handle
                && $action->handler !== null
                && $action->schema !== null
                && $action->transition === null
            ) {
                if ($this->dispatcher->actionContractSchemas($definition, $handle) === null) {
                    break;
                }
                return $action;
            }
        }

        throw new BusinessRecordDefinitionUnavailable();
    }

    /**
     * Derive the immutable record scope exclusively from the authenticated execution context.
     *
     * @param   ResolvedBusinessDefinition   $resolved  Definition and installation held by the fence.
     * @param   CustomBusinessActionCommand  $command   Authenticated typed command.
     * @param   ExecutionContext             $context   Host-issued authorization context.
     *
     * @return  RecordScope  Exact installation/site/organization scope.
     *
     * @throws  BusinessRecordDefinitionUnavailable  When command scope differs from authenticated scope.
     *
     * @since   2.0.0
     */
    private function scope(
        ResolvedBusinessDefinition $resolved,
        CustomBusinessActionCommand $command,
        ExecutionContext $context,
    ): RecordScope {
        $requiresOrganization = in_array(
            $resolved->definition->scope,
            [ScopeMode::Organization, ScopeMode::SiteOrganization],
            true,
        );
        $organization = $requiresOrganization ? $context->organization()?->identifier() : null;
        if ($organization !== $command->organizationIdentifier) {
            throw new BusinessRecordDefinitionUnavailable();
        }

        try {
            return RecordScope::forDefinition($resolved->definition->scope, $context->site(), $organization);
        } catch (InvalidArgumentException) {
            throw new BusinessRecordDefinitionUnavailable();
        }
    }

    /**
     * Recover the host-issued authorization context from the reusable SDK command envelope.
     *
     * @param   CustomBusinessActionCommand  $command  Canonical command received by the host boundary.
     *
     * @return  ExecutionContext  Exact App authority context originally placed in the command.
     *
     * @throws  BusinessRecordDefinitionUnavailable  When a caller supplies a foreign context implementation.
     *
     * @since   2.0.0
     */
    private static function context(CustomBusinessActionCommand $command): ExecutionContext
    {
        return ExtensionExecutionContext::host($command->context) ?? throw new BusinessRecordDefinitionUnavailable();
    }
}
