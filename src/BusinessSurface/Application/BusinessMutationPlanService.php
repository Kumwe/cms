<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessSurface\Application;

use DateInterval;
use InvalidArgumentException;
use JsonException;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\App\Application\Persistence\TransactionManager;
use Kumwe\App\BusinessDefinition\Domain\ScopeMode;
use Kumwe\App\BusinessRecord\Application\BusinessRecordDefinitionResolver;
use Kumwe\App\BusinessRecord\Application\BusinessRecordService;
use Kumwe\App\BusinessRecord\Application\RecordFingerprint;
use Kumwe\App\BusinessRecord\Application\RecordRequestGuard;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordDefinitionUnavailable;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordVersionConflict;
use Kumwe\App\BusinessRecord\Application\Query\ReadRecordQuery;
use Kumwe\App\BusinessRecord\Domain\RecordScope;
use Kumwe\App\BusinessRecord\Domain\RecordValueGuard;
use Kumwe\App\BusinessRecord\Domain\EncryptedEnvelope;
use Kumwe\App\BusinessSecurity\Application\BusinessRecordAccessController;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * Issues and verifies short-lived generated-business mutation plans.
 *
 * A plan is a sealed, stateless binding rather than permission of its own. It captures the
 * exact mutation payload, actor and membership context, delivery surface, active definition version,
 * trusted runtime generation, canonical record-policy plan, and current source-record version. Execution
 * re-derives every binding immediately before entering the mutation service; any change makes the plan
 * uniformly stale, while the record service still performs its transactional optimistic check afterward.
 *
 * @since  2.0.0
 */
final readonly class BusinessMutationPlanService
{
    /**
     * Authenticated-data label preventing a sealed plan from being opened as a record secret.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string ASSOCIATED_DATA = 'kumwe:business-mutation-plan:v2';

    /**
     * Seconds a mutation plan remains eligible for revalidation.
     *
     * @var    int
     * @since  2.0.0
     */
    private const int LIFETIME_SECONDS = 300;

    /**
     * Largest signed plan token accepted from a delivery adapter.
     *
     * @var    int
     * @since  2.0.0
     */
    private const int MAX_TOKEN_BYTES = 4096;

    /**
     * Closed generated-business mutation vocabulary.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private const OPERATIONS = [
        'create',
        'update',
        'archive',
        'restore',
        'delete',
        'relate',
        'unrelate',
        'reorder',
        'request_action',
        'execute_action',
    ];

    /**
     * Bind planning to the shared metadata, record, policy, fingerprint, transaction and clock seams.
     *
     * @param  BusinessSurfaceCatalog            $catalog       Policy-filtered metadata and runtime generation.
     * @param  BusinessRecordService             $records       Canonical bounded current-record read boundary.
     * @param  BusinessRecordDefinitionResolver  $definitions   Trusted active definition resolver.
     * @param  BusinessRecordAccessController    $access        Canonical record-policy planner.
     * @param  RecordFingerprint                 $fingerprints  Installation-keyed binding digests.
     * @param  MutationPlanCipher                $cipher        Plan-purpose authenticated encryption, keyed
     *         separately from record secrets so neither rotation entangles the other.
     * @param  TransactionManager                $transactions  Stable definition, policy and record snapshot.
     * @param  ClockInterface                    $clock         Trusted plan issue and expiry time.
     *
     * @since  2.0.0
     */
    public function __construct(
        private BusinessSurfaceCatalog $catalog,
        private BusinessRecordService $records,
        private BusinessRecordDefinitionResolver $definitions,
        private BusinessRecordAccessController $access,
        private RecordFingerprint $fingerprints,
        private MutationPlanCipher $cipher,
        private TransactionManager $transactions,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Plan one exact mutation against the current trusted runtime and policy snapshot.
     *
     * @param   ExecutionContext      $context    Authenticated actor and current membership context.
     * @param   BusinessSurface       $surface    Exact generated delivery surface.
     * @param   string                $operation  Closed generated-business mutation name.
     * @param   array<string, mixed>  $input      Canonical operation arguments including operation identity.
     *
     * @return  array<string, mixed>  Signed plan, current binding summary and five-minute expiry.
     *
     * @throws  InvalidArgumentException  When the operation or canonical input shape is invalid.
     * @throws  BusinessRecordVersionConflict  When the submitted version is no longer current.
     *
     * @since   2.0.0
     */
    public function create(
        ExecutionContext $context,
        BusinessSurface $surface,
        string $operation,
        array $input,
    ): array {
        $this->assertInput($operation, $input);

        return $this->transactions->transactional(function () use ($context, $surface, $operation, $input): array {
            $binding = $this->binding($context, $surface, $operation, $input);
            $now = $this->clock->now();
            $expires = $now->add(new DateInterval(sprintf('PT%dS', self::LIFETIME_SECONDS)));
            $document = [
                'version' => 1,
                'surface' => $surface->value,
                'operation' => $operation,
                'operation_id' => $input['operation_id'],
                'definition_id' => $binding['definition_id'],
                'definition_version' => $binding['definition_version'],
                'definition_checksum' => $binding['definition_checksum'],
                'runtime_binding' => $binding['runtime_binding'],
                'policy_binding' => $binding['policy_binding'],
                'context_binding' => $context->authorizationFingerprint(),
                'record_id' => $binding['record_id'],
                'record_version' => $binding['record_version'],
                'input_binding' => $this->fingerprints->digest($input),
                'issued_at' => $now->getTimestamp(),
                'expires_at' => $expires->getTimestamp(),
            ];

            return [
                'plan' => $this->encode($document),
                'operation' => $operation,
                'operation_id' => $input['operation_id'],
                'definition_version' => $binding['definition_version'],
                'record_id' => $binding['record_id'],
                'record_version' => $binding['record_version'],
                'destructive' => in_array($operation, ['delete', 'execute_action'], true),
                'approval_required' => $binding['approval_required'],
                'expires_at' => $expires->format(DATE_ATOM),
            ];
        });
    }

    /**
     * Re-prove every signed binding immediately before the planned mutation is executed.
     *
     * @param   ExecutionContext      $context    Current authenticated actor and membership.
     * @param   BusinessSurface       $surface    Exact generated delivery surface.
     * @param   string                $plan       Signed opaque plan returned by `create()`.
     * @param   string                $operation  Mutation about to execute.
     * @param   array<string, mixed>  $input      Canonical current mutation arguments.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the plan is malformed, expired, stale or bound elsewhere.
     * @throws  BusinessRecordVersionConflict  When the planned source record has changed.
     *
     * @since   2.0.0
     */
    public function assertCanExecute(
        ExecutionContext $context,
        BusinessSurface $surface,
        string $plan,
        string $operation,
        array $input,
    ): void {
        $this->assertInput($operation, $input);
        $document = $this->decode($plan);
        $this->assertDocumentBinding($document, $context, $surface, $operation, $input, false);

        $this->transactions->transactional(function () use (
            $context,
            $surface,
            $operation,
            $input,
            $document,
        ): void {
            $current = $this->binding($context, $surface, $operation, $input);
            foreach (
                [
                    'definition_id', 'definition_version', 'definition_checksum', 'runtime_binding',
                    'policy_binding', 'record_id', 'record_version',
                ] as $key
            ) {
                if ($current[$key] !== $document[$key]) {
                    throw self::invalid();
                }
            }
        });
    }

    /**
     * Re-prove every authority binding before returning a mutation guard's completed replay.
     *
     * This verifier is deliberately narrower than execution validation and may be called only after
     * `McpMutationGuard` has authenticated an exact completed replay. The original plan may have expired,
     * and a successful mutation may have advanced the source record version, so those two execution-only
     * facts are not rechecked. Operation, input, context, surface, definition, runtime, policy and record
     * identity remain exact; this method grants no authority to execute the mutation again.
     *
     * @param   ExecutionContext      $context    Current authenticated actor and membership.
     * @param   BusinessSurface       $surface    Exact generated delivery surface.
     * @param   string                $plan       Signed opaque plan used by the completed mutation.
     * @param   string                $operation  Mutation whose result is about to be replayed.
     * @param   array<string, mixed>  $input      Canonical current mutation arguments.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When any retained binding is malformed, stale or bound elsewhere.
     *
     * @since   2.0.0
     */
    public function assertCanReplay(
        ExecutionContext $context,
        BusinessSurface $surface,
        string $plan,
        string $operation,
        array $input,
    ): void {
        $this->assertInput($operation, $input);
        $document = $this->decode($plan);
        $this->assertDocumentBinding($document, $context, $surface, $operation, $input, true);

        $this->transactions->transactional(function () use (
            $context,
            $surface,
            $operation,
            $input,
            $document,
        ): void {
            $current = $this->binding($context, $surface, $operation, $input, false);
            foreach (
                [
                    'definition_id', 'definition_version', 'definition_checksum', 'runtime_binding',
                    'policy_binding', 'record_id',
                ] as $key
            ) {
                if ($current[$key] !== $document[$key]) {
                    throw self::invalid();
                }
            }
        });
    }

    /**
     * Verify the plan's exact caller, adapter, operation and input identity.
     *
     * @param   array<string, mixed>  $document      Authenticated plan document.
     * @param   ExecutionContext      $context       Current authenticated actor and membership.
     * @param   BusinessSurface       $surface       Exact generated delivery surface.
     * @param   string                $operation     Mutation being executed or replayed.
     * @param   array<string, mixed>  $input         Canonical current mutation arguments.
     * @param   bool                  $allowExpired  Whether a completed guard replay may outlive execution expiry.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When any exact identity binding differs.
     *
     * @since   2.0.0
     */
    private function assertDocumentBinding(
        array $document,
        ExecutionContext $context,
        BusinessSurface $surface,
        string $operation,
        array $input,
        bool $allowExpired,
    ): void {
        $now = $this->clock->now()->getTimestamp();
        $contextBinding = $document['context_binding'] ?? null;
        $inputBinding = $document['input_binding'] ?? null;
        if (
            $document['version'] !== 1
            || $document['surface'] !== $surface->value
            || $document['operation'] !== $operation
            || $document['operation_id'] !== $input['operation_id']
            || (!$allowExpired && $document['expires_at'] <= $now)
            || $document['issued_at'] > $now
            || !is_string($contextBinding)
            || !is_string($inputBinding)
            || !hash_equals($contextBinding, $context->authorizationFingerprint())
            || !hash_equals($inputBinding, $this->fingerprints->digest($input))
        ) {
            throw self::invalid();
        }
    }

    /**
     * Resolve the current canonical definition, policy and optional source-record binding.
     *
     * @param   ExecutionContext      $context                Current actor and scope.
     * @param   BusinessSurface       $surface                Exact generated surface.
     * @param   string                $operation              Closed mutation name.
     * @param   array<string, mixed>  $input                  Validated canonical mutation input.
     * @param   bool                  $validateRecordVersion  Whether to prove the current source version.
     *
     * @return  array<string, mixed>  Definition, runtime, policy, record and approval binding.
     *
     * @since   2.0.0
     */
    private function binding(
        ExecutionContext $context,
        BusinessSurface $surface,
        string $operation,
        array $input,
        bool $validateRecordVersion = true,
    ): array {
        $definition = $input['definition'];
        if (!is_string($definition)) {
            throw self::invalid();
        }
        $metadata = $this->catalog->definition(
            $context,
            $surface,
            $definition,
            self::surfaceOperation($operation),
        );
        $resolved = $this->definitions->forCreate($context, $definition);
        if (
            $resolved->definition->id !== ($metadata['id'] ?? null)
            || $resolved->definition->definitionVersion !== ($metadata['version'] ?? null)
            || $resolved->definition->checksum() !== ($metadata['checksum'] ?? null)
        ) {
            throw new BusinessRecordDefinitionUnavailable();
        }
        $organization = in_array(
            $resolved->definition->scope,
            [ScopeMode::Organization, ScopeMode::SiteOrganization],
            true,
        ) ? $context->organization()?->identifier() : null;
        $scope = RecordScope::forDefinition($resolved->definition->scope, $context->site(), $organization);
        $policy = $this->access->plan(
            $context,
            self::capability($operation),
            $resolved,
            $scope,
        );
        $recordId = null;
        $recordVersion = null;
        if ($operation !== 'create') {
            $recordId = $input['record'];
            if (!is_string($recordId)) {
                throw self::invalid();
            }
            if ($validateRecordVersion) {
                $expectedVersion = $input['expected_version'];
                if (!is_int($expectedVersion)) {
                    throw self::invalid();
                }
                $record = $this->records->read(new ReadRecordQuery(
                    $context,
                    $definition,
                    $recordId,
                    $organization,
                    includeArchived: true,
                    includeDeleted: true,
                ));
                $actualVersion = $record->version;
                if ($actualVersion !== $expectedVersion) {
                    throw new BusinessRecordVersionConflict($expectedVersion, $actualVersion);
                }
                $recordVersion = $actualVersion;
            }
        }

        return [
            'definition_id' => $resolved->definition->id,
            'definition_version' => $resolved->definition->definitionVersion,
            'definition_checksum' => $resolved->definition->checksum(),
            'runtime_binding' => $this->catalog->generation([$metadata]),
            'policy_binding' => $policy->digest(),
            'record_id' => $recordId,
            'record_version' => $recordVersion,
            'approval_required' => $this->approvalRequired($operation, $input, $metadata),
        ];
    }

    /**
     * Validate one canonical operation input before any metadata or record lookup.
     *
     * @param   string                $operation  Closed mutation name.
     * @param   array<string, mixed>  $input      Canonical operation arguments.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the operation-specific shape or value bounds are invalid.
     *
     * @since   2.0.0
     */
    private function assertInput(string $operation, array $input): void
    {
        if (!in_array($operation, self::OPERATIONS, true)) {
            throw new InvalidArgumentException('The generated-business mutation plan operation is unsupported.');
        }
        $required = match ($operation) {
            'create' => ['operation_id', 'definition', 'values', 'record'],
            'update' => ['operation_id', 'definition', 'record', 'expected_version', 'values'],
            'archive', 'restore', 'delete' => ['operation_id', 'definition', 'record', 'expected_version'],
            'relate' => [
                'operation_id', 'definition', 'record', 'expected_version', 'relationship', 'target',
                'position', 'target_values',
            ],
            'unrelate' => ['operation_id', 'definition', 'record', 'expected_version', 'relationship', 'target'],
            'reorder' => [
                'operation_id', 'definition', 'record', 'expected_version', 'relationship', 'ordered_record_ids',
            ],
            'request_action' => ['operation_id', 'definition', 'record', 'expected_version', 'action', 'input'],
            'execute_action' => [
                'operation_id', 'definition', 'record', 'expected_version', 'action', 'input',
                'approval_request_id',
            ],
        };
        $keys = array_keys($input);
        sort($keys, SORT_STRING);
        $expectedKeys = $required;
        sort($expectedKeys, SORT_STRING);
        if (
            $keys !== $expectedKeys
            || !is_string($input['operation_id'] ?? null)
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{7,127}$/D', $input['operation_id']) !== 1
            || !is_string($input['definition'] ?? null)
        ) {
            throw self::invalid();
        }
        RecordRequestGuard::definition($input['definition']);
        RecordValueGuard::assertValue($input);

        if ($operation === 'create') {
            if (!is_array($input['values']) || ($input['record'] !== null && !is_string($input['record']))) {
                throw self::invalid();
            }
            $this->assertValues($input['values'], false);
            if (is_string($input['record'])) {
                RecordRequestGuard::record($input['record']);
            }
            return;
        }
        if (!is_string($input['record']) || !is_int($input['expected_version'])) {
            throw self::invalid();
        }
        RecordRequestGuard::record($input['record']);
        RecordRequestGuard::expectedVersion($input['expected_version']);

        match ($operation) {
            'update' => $this->assertValues($input['values'], false),
            'relate' => $this->assertRelationInput($input),
            'unrelate' => $this->assertUnrelateInput($input),
            'reorder' => $this->assertReorderInput($input),
            'request_action' => $this->assertActionInput($input, false),
            'execute_action' => $this->assertActionInput($input, true),
            default => null,
        };
    }

    /**
     * Validate a dynamic business value map.
     *
     * @param   mixed  $values      Candidate value map.
     * @param   bool   $allowEmpty  Whether an empty input is valid.
     *
     * @return  null
     *
     * @since   2.0.0
     */
    private function assertValues(mixed $values, bool $allowEmpty): null
    {
        if (!is_array($values) || ($values !== [] && array_is_list($values))) {
            throw self::invalid();
        }
        foreach (array_keys($values) as $key) {
            if (!is_string($key)) {
                throw self::invalid();
            }
        }
        /** @var array<string, mixed> $values */
        RecordRequestGuard::values($values, $allowEmpty);

        return null;
    }

    /**
     * Validate relate-specific handles, target, position and owned-line values.
     *
     * @param   array<string, mixed>  $input  Canonical relate input.
     *
     * @return  null
     *
     * @since   2.0.0
     */
    private function assertRelationInput(array $input): null
    {
        if (
            !is_string($input['relationship'])
            || !is_string($input['target'])
            || ($input['position'] !== null && (
                !is_int($input['position'])
                || $input['position'] < 0
                || $input['position'] > 1_000_000
            ))
        ) {
            throw self::invalid();
        }
        RecordRequestGuard::handle($input['relationship'], 'relationship');
        RecordRequestGuard::record($input['target']);
        $this->assertValues($input['target_values'], true);

        return null;
    }

    /**
     * Validate unrelate-specific handles and target identity.
     *
     * @param   array<string, mixed>  $input  Canonical unrelate input.
     *
     * @return  null
     *
     * @since   2.0.0
     */
    private function assertUnrelateInput(array $input): null
    {
        if (!is_string($input['relationship']) || !is_string($input['target'])) {
            throw self::invalid();
        }
        RecordRequestGuard::handle($input['relationship'], 'relationship');
        RecordRequestGuard::record($input['target']);

        return null;
    }

    /**
     * Validate a complete bounded ordered-relationship identity list.
     *
     * @param   array<string, mixed>  $input  Canonical reorder input.
     *
     * @return  null
     *
     * @since   2.0.0
     */
    private function assertReorderInput(array $input): null
    {
        $records = $input['ordered_record_ids'];
        if (!is_string($input['relationship']) || !is_array($records) || !array_is_list($records)) {
            throw self::invalid();
        }
        if (count($records) > 1000) {
            throw self::invalid();
        }
        RecordRequestGuard::handle($input['relationship'], 'relationship');
        $seen = [];
        foreach ($records as $record) {
            if (!is_string($record) || isset($seen[$record])) {
                throw self::invalid();
            }
            RecordRequestGuard::record($record);
            $seen[$record] = true;
        }

        return null;
    }

    /**
     * Validate an action handle, input map and optional independent approval identity.
     *
     * @param   array<string, mixed>  $input          Canonical action input.
     * @param   bool                  $allowApproval  Whether an approval request identity may be supplied.
     *
     * @return  null
     *
     * @since   2.0.0
     */
    private function assertActionInput(array $input, bool $allowApproval): null
    {
        if (!is_string($input['action'])) {
            throw self::invalid();
        }
        RecordRequestGuard::handle($input['action'], 'action');
        $this->assertValues($input['input'], true);
        if ($allowApproval) {
            $request = $input['approval_request_id'];
            if ($request !== null && (!is_string($request) || !Uuid::isValid($request))) {
                throw self::invalid();
            }
        }

        return null;
    }

    /**
     * Determine whether the planned declared action is high impact.
     *
     * @param   string                $operation  Planned mutation.
     * @param   array<string, mixed>  $input      Canonical mutation input.
     * @param   array<string, mixed>  $metadata   Policy-filtered definition metadata.
     *
     * @return  bool  True only for a visible high-impact action.
     *
     * @since   2.0.0
     */
    private function approvalRequired(string $operation, array $input, array $metadata): bool
    {
        if (!in_array($operation, ['request_action', 'execute_action'], true)) {
            return false;
        }
        $actions = $metadata['actions'] ?? null;
        if (!is_array($actions) || !array_is_list($actions)) {
            throw self::invalid();
        }
        foreach ($actions as $action) {
            if (is_array($action) && ($action['handle'] ?? null) === $input['action']) {
                return ($action['high_impact'] ?? false) === true;
            }
        }

        throw self::invalid();
    }

    /**
     * Map a mutation onto the exact metadata operation used to plan it.
     *
     * @param   string  $operation  Closed mutation name.
     *
     * @return  BusinessSurfaceOperation  Shared generated-surface operation.
     *
     * @since   2.0.0
     */
    private static function surfaceOperation(string $operation): BusinessSurfaceOperation
    {
        return match ($operation) {
            'create' => BusinessSurfaceOperation::Create,
            'update' => BusinessSurfaceOperation::Update,
            'archive' => BusinessSurfaceOperation::Archive,
            'restore' => BusinessSurfaceOperation::Restore,
            'delete' => BusinessSurfaceOperation::Delete,
            'relate', 'unrelate' => BusinessSurfaceOperation::Relation,
            'reorder' => BusinessSurfaceOperation::Reorder,
            'request_action' => BusinessSurfaceOperation::Approval,
            'execute_action' => BusinessSurfaceOperation::Action,
            default => throw new InvalidArgumentException(
                'The generated-business mutation plan operation is unsupported.',
            ),
        };
    }

    /**
     * Map a mutation onto the exact canonical record capability its policy plan evaluates.
     *
     * @param   string  $operation  Closed mutation name.
     *
     * @return  string  Exact dotted business-record capability.
     *
     * @since   2.0.0
     */
    private static function capability(string $operation): string
    {
        return 'business.record.' . match ($operation) {
            'create', 'update', 'archive', 'restore', 'delete' => $operation,
            'relate', 'unrelate', 'reorder' => 'relate',
            'request_action', 'execute_action' => 'action',
            default => throw new InvalidArgumentException(
                'The generated-business mutation plan operation is unsupported.',
            ),
        };
    }

    /**
     * Seal one small scalar plan document into an opaque authenticated token.
     *
     * @param   array<string, mixed>  $document  Validated binding document.
     *
     * @return  string  Versioned base64url authenticated-encryption envelope.
     *
     * @throws  JsonException  When the internal scalar document cannot be encoded.
     *
     * @since   2.0.0
     */
    private function encode(array $document): string
    {
        $json = json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $envelope = $this->cipher->encrypt($json, self::ASSOCIATED_DATA);
        $storage = json_encode($envelope->toStorage(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return 'v2.' . self::base64UrlEncode($storage);
    }

    /**
     * Decode and authenticate one opaque plan without disclosing which check failed.
     *
     * @param   string  $plan  Candidate versioned plan token.
     *
     * @return  array<string, mixed>  Authenticated scalar binding document.
     *
     * @throws  InvalidArgumentException  When any token, signature, JSON or member check fails.
     *
     * @since   2.0.0
     */
    private function decode(string $plan): array
    {
        try {
            if (
                strlen($plan) > self::MAX_TOKEN_BYTES
                || preg_match('/^v2\.([A-Za-z0-9_-]+)$/D', $plan, $matches) !== 1
            ) {
                throw self::invalid();
            }
            $storage = json_decode(self::base64UrlDecode($matches[1]), true, 8, JSON_THROW_ON_ERROR);
            if (
                !is_array($storage) || array_is_list($storage)
                || array_keys($storage) !== ['ciphertext', 'nonce', 'key_id', 'algorithm']
            ) {
                throw self::invalid();
            }
            foreach ($storage as $value) {
                if (!is_string($value)) {
                    throw self::invalid();
                }
            }
            /** @var array{ciphertext: string, nonce: string, key_id: string, algorithm: string} $storage */
            $json = $this->cipher->decrypt(EncryptedEnvelope::fromStorage($storage), self::ASSOCIATED_DATA);
            $object = json_decode($json, false, 16, JSON_THROW_ON_ERROR);
            $document = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
            if (!$object instanceof \stdClass || !is_array($document)) {
                throw self::invalid();
            }
            foreach (array_keys($document) as $key) {
                if (!is_string($key)) {
                    throw self::invalid();
                }
            }
            /** @var array<string, mixed> $document */
            self::assertPlanDocument($document);

            return $document;
        } catch (Throwable) {
            throw self::invalid();
        }
    }

    /**
     * Assert the decoded plan has exactly the signed scalar binding vocabulary.
     *
     * @param   array<string, mixed>  $document  Authenticated decoded document.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When a member is absent, extra, or misshapen.
     *
     * @since   2.0.0
     */
    private static function assertPlanDocument(array $document): void
    {
        $keys = [
            'version', 'surface', 'operation', 'operation_id', 'definition_id', 'definition_version',
            'definition_checksum',
            'runtime_binding', 'policy_binding', 'context_binding', 'record_id', 'record_version',
            'input_binding', 'issued_at', 'expires_at',
        ];
        $actual = array_keys($document);
        sort($actual, SORT_STRING);
        sort($keys, SORT_STRING);
        if (
            $actual !== $keys
            || !is_int($document['version'])
            || !is_string($document['surface'])
            || !is_string($document['operation'])
            || !is_string($document['operation_id'])
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{7,127}$/D', $document['operation_id']) !== 1
            || !is_string($document['definition_id'])
            || !Uuid::isValid($document['definition_id'])
            || !is_int($document['definition_version'])
            || $document['definition_version'] < 1
            || ($document['record_id'] !== null && !is_string($document['record_id']))
            || ($document['record_version'] !== null && (
                !is_int($document['record_version'])
                || $document['record_version'] < 1
            ))
            || !is_int($document['issued_at'])
            || !is_int($document['expires_at'])
            || BusinessSurface::tryFrom($document['surface']) === null
            || !in_array($document['operation'], self::OPERATIONS, true)
            || $document['expires_at'] <= $document['issued_at']
            || $document['expires_at'] - $document['issued_at'] > self::LIFETIME_SECONDS
        ) {
            throw self::invalid();
        }
        foreach (
            [
                'definition_checksum', 'runtime_binding', 'policy_binding', 'context_binding', 'input_binding',
            ] as $key
        ) {
            if (!is_string($document[$key]) || preg_match('/^[a-f0-9]{64}$/D', $document[$key]) !== 1) {
                throw self::invalid();
            }
        }
    }

    /**
     * Render bytes through the unpadded base64url alphabet used by bounded record cursors.
     *
     * @param   string  $value  Raw bytes.
     *
     * @return  string  Unpadded URL-safe base64.
     *
     * @since   2.0.0
     */
    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    /**
     * Decode an unpadded base64url payload strictly.
     *
     * @param   string  $value  Encoded payload segment.
     *
     * @return  string  Decoded bytes.
     *
     * @throws  InvalidArgumentException  When the segment is not canonical base64url.
     *
     * @since   2.0.0
     */
    private static function base64UrlDecode(string $value): string
    {
        $padding = (4 - strlen($value) % 4) % 4;
        $decoded = base64_decode(strtr($value, '-_', '+/') . str_repeat('=', $padding), true);
        if ($decoded === false || self::base64UrlEncode($decoded) !== $value) {
            throw self::invalid();
        }

        return $decoded;
    }

    /**
     * Build the one non-enumerating plan rejection used for every token or binding mismatch.
     *
     * @return  InvalidArgumentException  Stable caller-safe rejection.
     *
     * @since   2.0.0
     */
    private static function invalid(): InvalidArgumentException
    {
        return new InvalidArgumentException('The generated-business mutation plan is invalid or stale.');
    }
}
