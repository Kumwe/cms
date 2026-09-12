<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Delivery\Http\Api\Business;

use DateTimeImmutable;
use Kumwe\Context\Value\AuthenticationStrength;
use Kumwe\App\Application\Authorization\AuthorizationDecision;
use Kumwe\App\Application\Authorization\AuthorizationGateway;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Context\Value\SiteContext;
use Kumwe\Transaction\Contract\TransactionManager;
use Kumwe\App\BusinessDefinition\Application\FieldTypeDefinitionResolver;
use Kumwe\App\BusinessDefinition\Domain\BuiltInFieldTypes;
use Kumwe\App\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\App\BusinessDefinition\Domain\FieldTypeDefinition;
use Kumwe\App\BusinessRecord\Application\BusinessRecordDefinitionResolver;
use Kumwe\App\BusinessRecord\Application\RecordFingerprint;
use Kumwe\App\BusinessRecord\Application\RecordMutationResult;
use Kumwe\App\BusinessRecord\Application\ResolvedBusinessDefinition;
use Kumwe\App\BusinessRecord\Domain\BusinessRecordIdempotency;
use Kumwe\App\BusinessRecord\Domain\BusinessRecordIdempotencyState;
use Kumwe\App\BusinessSecurity\Application\BusinessRecordAccessController;
use Kumwe\App\BusinessSecurity\Application\BusinessRecordAccessPlan;
use Kumwe\Extension\Spi\BusinessSecurity\Application\FieldDisclosurePlan;
use Kumwe\App\BusinessSecurity\Policy\RecordPolicyConstant;
use Kumwe\App\BusinessSecurity\Policy\RecordPolicySchema;
use Kumwe\App\BusinessSecurity\Policy\RecordPolicySet;
use Kumwe\App\BusinessSurface\Application\BusinessOperationStatusRepository;
use Kumwe\App\BusinessSurface\Application\BusinessOperationStatusService;
use Kumwe\App\BusinessSurface\Application\BusinessRecordProjector;
use Kumwe\App\BusinessSurface\Application\BusinessSurfaceCatalog;
use Kumwe\App\BusinessSurface\Application\Custom\CustomBusinessActionHandlerRegistry;
use Kumwe\App\BusinessSurface\Application\Custom\CustomBusinessActionLedgerResult;
use Kumwe\App\BusinessSurface\Application\Custom\CustomBusinessReferenceRegistry;
use Kumwe\App\BusinessSurface\Application\Custom\CustomBusinessSurfaceDispatcher;
use Kumwe\App\BusinessSurface\Application\Custom\CustomBusinessViewHandlerRegistry;
use Kumwe\App\Delivery\Http\Api\Business\BusinessOperationStatusApiHandler;
use Kumwe\App\Delivery\Http\Api\ProblemDetailsResponseFactory;
use Kumwe\App\Extension\Runtime\RuntimeMaterializationState;
use Kumwe\App\Extension\Application\ExtensionExecutionGate;
use Kumwe\App\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\App\Tests\Support\AuthorizationContext;
use Kumwe\Extension\Spi\Application\Automation\IdempotencyKey;
use Kumwe\Extension\Spi\BusinessSurface\Application\Custom\CustomBusinessActionDeclaration;
use Kumwe\Extension\Spi\BusinessSurface\Application\Custom\CustomBusinessActionHandler;
use Kumwe\Extension\Spi\BusinessSurface\Application\Custom\CustomBusinessActionResult;
use Laminas\Diactoros\ServerRequestFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use ReflectionClass;
use Kumwe\App\Application\Authorization\ExecutionContextAttribute;

#[CoversClass(BusinessOperationStatusApiHandler::class)]
/**
 * Verifies the REST operation-status adapter preserves projection, integrity and non-enumeration rules.
 *
 * @since  2.0.0
 */
final class BusinessOperationStatusApiHandlerTest extends TestCase
{
    /**
     * Published definition identity used by all status fixtures.
     *
     * @var    string
     * @since  2.0.0
     */
    private const DEFINITION_ID = '018f22e2-7c8b-7ab0-8f3a-88e8026bb601';

    /**
     * Internal record key that must never appear in a response.
     *
     * @var    string
     * @since  2.0.0
     */
    private const RECORD_KEY = '018f22e2-7c8b-7ab0-8f3a-88e8026bb602';

    /**
     * Shared idempotency ledger row identity.
     *
     * @var    string
     * @since  2.0.0
     */
    private const ENTRY_ID = '018f22e2-7c8b-7ab0-8f3a-88e8026bb603';

    /**
     * Caller operation identity used for canonical mutation lookup.
     *
     * @var    string
     * @since  2.0.0
     */
    private const OPERATION_ID = 'record-create-0001';

    /**
     * Approval request identity exposed by the approval status fixture.
     *
     * @var    string
     * @since  2.0.0
     */
    private const APPROVAL_ID = '018f22e2-7c8b-7ab0-8f3a-88e8026bb604';

    /**
     * Prove the HTTP response exactly matches the shared mutation projector and omits storage keys.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testReturnsTheExactSharedProjectedStatusDocument(): void
    {
        [$principal, $context] = $this->identity();
        $fingerprints = new RecordFingerprint(str_repeat('fingerprint-key-', 3));
        $plan = $this->accessPlan();
        $stored = (new RecordMutationResult(
            self::DEFINITION_ID,
            2,
            self::RECORD_KEY,
            'INV-0001',
            4,
            'approved',
            'create',
        ))->toArray();
        $entry = new BusinessRecordIdempotency(
            self::ENTRY_ID,
            str_repeat('a', 64),
            'default',
            null,
            $context->actorId(),
            'business.record.create',
            self::OPERATION_ID,
            str_repeat('b', 64),
            $fingerprints->digest([
                'context' => $context->authorizationFingerprint(),
                'record_access' => $plan->digest(),
            ]),
            BusinessRecordIdempotencyState::Completed,
            $stored,
            $fingerprints->digest($stored),
            new DateTimeImmutable('2026-08-10T12:00:00+00:00'),
            new DateTimeImmutable('2026-08-10T12:00:01+00:00'),
            new DateTimeImmutable('2026-08-10T13:00:00+00:00'),
        );

        $response = $this->handler($entry, $fingerprints, $plan)->handle(
            $this->request($principal, $context, self::OPERATION_ID),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame([
            'operation_id' => self::OPERATION_ID,
            'state' => 'completed',
            'operation' => 'business.record.create',
            'created_at' => '2026-08-10T12:00:00+00:00',
            'completed_at' => '2026-08-10T12:00:01+00:00',
            'expires_at' => '2026-08-10T13:00:00+00:00',
            'result' => [
                'definition_version' => 2,
                'record_id' => 'INV-0001',
                'version' => 4,
                'workflow_state' => 'approved',
                'operation' => 'create',
                'deleted' => false,
                'replayed' => true,
            ],
        ], $this->body($response));
        self::assertStringNotContainsString('record_key', (string) $response->getBody());
    }

    /**
     * Prove every unavailable operation collapses to one stable non-enumerating problem.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testUnavailableOperationUsesOneNonEnumeratingProblem(): void
    {
        [$principal, $context] = $this->identity();
        $fingerprints = new RecordFingerprint(str_repeat('fingerprint-key-', 3));
        $response = $this->handler(null, $fingerprints, $this->accessPlan())->handle(
            $this->request($principal, $context, 'missing-operation-0001'),
        );

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame('application/problem+json', $response->getHeaderLine('Content-Type'));
        self::assertSame(
            'urn:kumwe:problem:business-operation-not-found',
            $this->body($response)['type'],
        );
        self::assertStringNotContainsString('missing-operation-0001', $this->body($response)['detail']);
    }

    /**
     * Prove approval status exposes its request identity without its internal definition binding.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testProjectsAnApprovalRequestStatusWithoutItsInternalDefinitionBinding(): void
    {
        [$principal, $context] = $this->identity();
        $fingerprints = new RecordFingerprint(str_repeat('fingerprint-key-', 3));
        $plan = $this->accessPlan();
        $stored = [
            'definition_id' => self::DEFINITION_ID,
            'approval_request_id' => self::APPROVAL_ID,
        ];
        $entry = new BusinessRecordIdempotency(
            self::ENTRY_ID,
            str_repeat('a', 64),
            'default',
            null,
            $context->actorId(),
            'business.record.action_approval_request',
            'request-action-0001',
            str_repeat('b', 64),
            $fingerprints->digest([
                'context' => $context->authorizationFingerprint(),
                'record_access' => $plan->digest(),
            ]),
            BusinessRecordIdempotencyState::Completed,
            $stored,
            $fingerprints->digest($stored),
            new DateTimeImmutable('2026-08-10T12:00:00+00:00'),
            new DateTimeImmutable('2026-08-10T12:00:01+00:00'),
            new DateTimeImmutable('2026-08-10T13:00:00+00:00'),
        );

        $response = $this->handler($entry, $fingerprints, $plan)->handle(
            $this->request($principal, $context, 'request-action-0001'),
        );
        $body = $this->body($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['approval_request_id' => self::APPROVAL_ID], $body['result']);
        self::assertStringNotContainsString(self::DEFINITION_ID, (string) $response->getBody());
        self::assertStringNotContainsString('record_key', (string) $response->getBody());
    }

    /**
     * Prove a tagged custom-action status revalidates and returns its exact bounded public result.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testProjectsAContractValidatedCustomActionStatus(): void
    {
        [$principal, $context] = $this->identity();
        $fingerprints = new RecordFingerprint(str_repeat('fingerprint-key-', 3));
        $plan = $this->accessPlan('business.record.action', ['recalculate']);
        $definition = $this->customDefinition();
        $runtime = $this->trustedRuntime();
        $entry = $this->customEntry($context, $fingerprints, $plan, $definition, $runtime);

        $response = $this->handler(
            $entry,
            $fingerprints,
            $plan,
            $definition,
            $this->customDispatcher($definition),
            $runtime,
        )->handle($this->request($principal, $context, 'custom-action-status-0001'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([
            'definition_version' => 2,
            'record_id' => 'ASSET-0001',
            'version' => 3,
            'workflow_state' => null,
            'operation' => 'action',
            'deleted' => false,
            'replayed' => true,
            'result' => ['status' => 'done'],
        ], $this->body($response)['result']);
        self::assertStringNotContainsString('handler', (string) $response->getBody());
        self::assertStringNotContainsString('schema', (string) $response->getBody());
        self::assertStringNotContainsString(self::DEFINITION_ID, (string) $response->getBody());
    }

    /**
     * Prove a tagged status whose extension publication changed collapses to the shared missing response.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testCustomActionStatusRuntimeDriftDoesNotEnumerateTheOperation(): void
    {
        [$principal, $context] = $this->identity();
        $fingerprints = new RecordFingerprint(str_repeat('fingerprint-key-', 3));
        $plan = $this->accessPlan('business.record.action', ['recalculate']);
        $definition = $this->customDefinition();
        $storedRuntime = $this->trustedRuntime();
        $entry = $this->customEntry($context, $fingerprints, $plan, $definition, $storedRuntime);
        $currentRuntime = new RuntimeMaterializationState(
            'test-replica',
            8,
            str_repeat('e', 64),
            str_repeat('f', 64),
            true,
        );

        $response = $this->handler(
            $entry,
            $fingerprints,
            $plan,
            $definition,
            $this->customDispatcher($definition),
            $currentRuntime,
        )->handle($this->request($principal, $context, 'custom-action-status-0001'));

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(
            'urn:kumwe:problem:business-operation-not-found',
            $this->body($response)['type'],
        );
        self::assertStringNotContainsString('custom-action-status-0001', $this->body($response)['detail']);
    }

    /**
     * Prove malformed operation identities use the bounded validation problem rather than lookup.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testMalformedOperationIdentityUsesAStableValidationProblem(): void
    {
        [$principal, $context] = $this->identity();
        $fingerprints = new RecordFingerprint(str_repeat('fingerprint-key-', 3));
        $response = $this->handler(null, $fingerprints, $this->accessPlan())->handle(
            $this->request($principal, $context, 'bad'),
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('urn:kumwe:problem:invalid-business-operation', $this->body($response)['type']);
        self::assertStringNotContainsString('bad', $this->body($response)['detail']);
    }

    /**
     * Build a fully policy-valid shared status service behind the HTTP adapter.
     *
     * @param   BusinessRecordIdempotency|null $entry        Repository result for this test.
     * @param   RecordFingerprint              $fingerprints Shared keyed digest service.
     * @param   BusinessRecordAccessPlan       $plan         Stable policy plan bound to the ledger entry.
     * @param   EntityTypeDefinition|null       $definition   Definition to resolve, or the ordinary fixture.
     * @param   CustomBusinessSurfaceDispatcher|null $custom  Custom registry bridge, or an empty one.
     * @param   RuntimeMaterializationState|null $runtime      Runtime generation, or an unavailable fixture.
     *
     * @return  BusinessOperationStatusApiHandler  Configured thin adapter.
     *
     * @since   2.0.0
     */
    private function handler(
        ?BusinessRecordIdempotency $entry,
        RecordFingerprint $fingerprints,
        BusinessRecordAccessPlan $plan,
        ?EntityTypeDefinition $definition = null,
        ?CustomBusinessSurfaceDispatcher $custom = null,
        ?RuntimeMaterializationState $runtime = null,
    ): BusinessOperationStatusApiHandler {
        $definition ??= EntityTypeDefinition::fromArray($this->definition());
        $resolved = (new ReflectionClass(ResolvedBusinessDefinition::class))->newInstanceWithoutConstructor();
        (new ReflectionClass(ResolvedBusinessDefinition::class))
            ->getProperty('definition')
            ->setValue($resolved, $definition);

        $definitions = $this->createStub(BusinessRecordDefinitionResolver::class);
        $definitions->method('forCreate')->willReturn($resolved);
        $definitions->method('activeInstalled')->willReturn([$resolved]);
        $access = $this->createStub(BusinessRecordAccessController::class);
        $access->method('plan')->willReturn($plan);
        $types = $this->createStub(FieldTypeDefinitionResolver::class);
        $types->method('get')->willReturn($this->uuidType());
        $authorization = $this->createStub(AuthorizationGateway::class);
        $authorization->method('decide')->willReturn(new AuthorizationDecision(true, 'test', 'allowed'));
        $transactions = $this->createStub(TransactionManager::class);
        $transactions->method('transactional')->willReturnCallback(
            static fn (callable $operation): mixed => $operation(),
        );
        $operations = $this->createStub(BusinessOperationStatusRepository::class);
        $operations->method('find')->willReturn($entry);
        $runtime ??= RuntimeMaterializationState::unavailable('test-replica');
        if ($custom === null) {
            $references = new CustomBusinessReferenceRegistry();
            $custom = new CustomBusinessSurfaceDispatcher(
                new CustomBusinessViewHandlerRegistry($references),
                new CustomBusinessActionHandlerRegistry($references),
                $authorization,
                $this->createStub(ExtensionExecutionGate::class),
            );
        }
        $catalog = new BusinessSurfaceCatalog(
            $definitions,
            $access,
            $types,
            $authorization,
            $transactions,
            $runtime,
            $custom,
        );
        $clock = new class implements ClockInterface {
            /**
             * Return the fixed instant shared by expiry-sensitive status fixtures.
             *
             * @return  DateTimeImmutable  Stable test instant.
             *
             * @since   2.0.0
             */
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('2026-08-10T12:30:00+00:00');
            }
        };

        return new BusinessOperationStatusApiHandler(
            new BusinessOperationStatusService(
                $operations,
                $transactions,
                $definitions,
                $access,
                $fingerprints,
                $catalog,
                new BusinessRecordProjector(),
                $custom,
                $runtime,
                $clock,
            ),
            new ProblemDetailsResponseFactory(),
        );
    }

    /**
     * Build the access-plan digest both catalog and ledger validation share.
     *
     * @param   string        $operation  Policy operation represented by the plan.
     * @param   list<string>  $actions    Explicit action handles admitted by the plan.
     *
     * @return  BusinessRecordAccessPlan  Read-capable policy plan.
     *
     * @since   2.0.0
     */
    private function accessPlan(
        string $operation = 'business.record.create',
        array $actions = [],
    ): BusinessRecordAccessPlan {
        return new BusinessRecordAccessPlan(
            self::DEFINITION_ID,
            $operation,
            new RecordPolicySet(new RecordPolicySchema([]), [new RecordPolicyConstant(true)]),
            new FieldDisclosurePlan(['detail' => ['id']]),
            str_repeat('c', 64),
            actions: $actions,
        );
    }

    /**
     * Create matching authenticated principal and API execution context.
     *
     * @return  array{AuthenticatedPrincipal, ExecutionContext}  Matching identity pair.
     *
     * @since   2.0.0
     */
    private function identity(): array
    {
        $principal = AuthorizationContext::principal(['business.record.read']);

        return [$principal, $principal->context(
            SiteContext::default(),
            AuthenticationStrength::BearerToken,
            'business-operation-api-test-0001',
        )];
    }

    /**
     * Create one authenticated matched operation-status request.
     *
     * @param   AuthenticatedPrincipal $principal Authenticated bearer principal.
     * @param   ExecutionContext       $context   Matching API execution context.
     * @param   string                 $operation Routed operation identity.
     *
     * @return  ServerRequestInterface  Prepared request.
     *
     * @since   2.0.0
     */
    private function request(
        AuthenticatedPrincipal $principal,
        ExecutionContext $context,
        string $operation,
    ): ServerRequestInterface {
        return (new ServerRequestFactory())
            ->createServerRequest(
                'GET',
                'https://kumwe.test/api/v1/business/operations/' . rawurlencode($operation),
            )
            ->withAttribute(AuthenticatedPrincipal::REQUEST_ATTRIBUTE, $principal)
            ->withAttribute(ExecutionContextAttribute::NAME, $context)
            ->withAttribute(BusinessOperationStatusApiHandler::OPERATION_ATTRIBUTE, $operation);
    }

    /**
     * Store one checksum-valid tagged result under the caller's exact current policy fingerprint.
     *
     * @param   ExecutionContext             $context       Authenticated operation owner.
     * @param   RecordFingerprint            $fingerprints  Shared keyed result digest service.
     * @param   BusinessRecordAccessPlan     $plan          Exact action policy used at execution.
     * @param   EntityTypeDefinition         $definition    Published custom-action definition.
     * @param   RuntimeMaterializationState  $runtime       Trusted publication executed.
     *
     * @return  BusinessRecordIdempotency  Completed shared ledger entry.
     *
     * @since   2.0.0
     */
    private function customEntry(
        ExecutionContext $context,
        RecordFingerprint $fingerprints,
        BusinessRecordAccessPlan $plan,
        EntityTypeDefinition $definition,
        RuntimeMaterializationState $runtime,
    ): BusinessRecordIdempotency {
        $operationId = IdempotencyKey::fromString('custom-action-status-0001');
        $stored = CustomBusinessActionLedgerResult::capture(
            $definition,
            $definition->actions()[0],
            'ASSET-0001',
            $runtime->generation,
            $runtime->publicationChecksum,
            new CustomBusinessActionResult(['status' => 'done'], 3, $operationId),
        )->toArray();

        return new BusinessRecordIdempotency(
            self::ENTRY_ID,
            str_repeat('a', 64),
            'default',
            null,
            $context->actorId(),
            'business.record.action',
            $operationId->value(),
            str_repeat('b', 64),
            $fingerprints->digest([
                'context' => $context->authorizationFingerprint(),
                'record_access' => $plan->digest(),
            ]),
            BusinessRecordIdempotencyState::Completed,
            $stored,
            $fingerprints->digest($stored),
            new DateTimeImmutable('2026-08-10T12:00:00+00:00'),
            new DateTimeImmutable('2026-08-10T12:00:01+00:00'),
            new DateTimeImmutable('2026-08-10T13:00:00+00:00'),
        );
    }

    /**
     * Register the exact signed custom action contract without an executable status-path callback.
     *
     * @param   EntityTypeDefinition  $definition  Definition whose owner claims the contract.
     *
     * @return  CustomBusinessSurfaceDispatcher  Live owner-aware contract resolver.
     *
     * @since   2.0.0
     */
    private function customDispatcher(EntityTypeDefinition $definition): CustomBusinessSurfaceDispatcher
    {
        $references = new CustomBusinessReferenceRegistry();
        $actions = new CustomBusinessActionHandlerRegistry($references);
        $actions->register(
            $definition->owner,
            $this->customContract(),
            $this->createStub(CustomBusinessActionHandler::class),
        );

        return new CustomBusinessSurfaceDispatcher(
            new CustomBusinessViewHandlerRegistry($references),
            $actions,
            $this->createStub(AuthorizationGateway::class),
            $this->createStub(ExtensionExecutionGate::class),
        );
    }

    /**
     * Build the closed command and result schemas used by the tagged status fixture.
     *
     * @return  CustomBusinessActionDeclaration  Exact registered custom-action contract.
     *
     * @since   2.0.0
     */
    private function customContract(): CustomBusinessActionDeclaration
    {
        return CustomBusinessActionDeclaration::fromManifest([
            'handler' => 'acme.editor.actions.recalculate',
            'schema' => 'acme.editor.schemas.recalculate_v1',
            'command_schema' => [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'mode' => ['type' => 'string', 'enum' => ['full', 'delta'], 'maxLength' => 5],
                ],
                'required' => ['mode'],
            ],
            'result_schema' => [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'status' => ['type' => 'string', 'const' => 'done', 'maxLength' => 4],
                ],
                'required' => ['status'],
            ],
        ]);
    }

    /**
     * Build one published extension definition carrying the registered custom action tuple.
     *
     * @return  EntityTypeDefinition  Custom-action status fixture definition.
     *
     * @since   2.0.0
     */
    private function customDefinition(): EntityTypeDefinition
    {
        $definition = $this->definition();
        $definition['owner'] = ['type' => 'extension', 'identifier' => 'acme/editor'];
        $definition['handle'] = 'acme.editor.operation_status_test';
        $definition['actions'] = [[
            'handle' => 'recalculate',
            'label' => 'Recalculate',
            'capability' => 'acme.editor.manage',
            'administrator' => true,
            'handler' => 'acme.editor.actions.recalculate',
            'schema' => 'acme.editor.schemas.recalculate_v1',
        ]];

        return EntityTypeDefinition::fromArray($definition);
    }

    /**
     * Build the trusted extension runtime generation bound into custom tagged results.
     *
     * @return  RuntimeMaterializationState  Stable trusted publication state.
     *
     * @since   2.0.0
     */
    private function trustedRuntime(): RuntimeMaterializationState
    {
        return new RuntimeMaterializationState(
            'test-replica',
            7,
            str_repeat('d', 64),
            str_repeat('e', 64),
            true,
        );
    }

    /**
     * Return the minimal published definition the status visibility gate resolves.
     *
     * @return  array<string, mixed>  Valid published definition document.
     *
     * @since   2.0.0
     */
    private function definition(): array
    {
        return [
            'id' => self::DEFINITION_ID,
            'owner' => ['type' => 'site', 'identifier' => 'default'],
            'site' => 'default',
            'handle' => 'site.default.operation_status_test',
            'singular_label' => 'Operation test record',
            'plural_label' => 'Operation test records',
            'status' => 'published',
            'definition_version' => 2,
            'storage_mode' => 'relational',
            'identity_strategy' => 'uuid',
            'scope' => 'site',
            'audit_enabled' => true,
            'revisions_enabled' => true,
            'fields' => [[
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
            ]],
            'relationships' => [],
            'views' => [],
            'actions' => [],
            'workflow' => null,
            'compatibility_metadata' => [],
            'administrator_exposure' => true,
            'portal_exposure' => false,
            'public_exposure' => false,
            'soft_delete_enabled' => true,
            'record_invariants' => [],
            'portal_operations' => [],
        ];
    }

    /**
     * Resolve the built-in UUID type without adding a second field-type source.
     *
     * @return  FieldTypeDefinition  Core UUID field type.
     *
     * @since   2.0.0
     */
    private function uuidType(): FieldTypeDefinition
    {
        foreach (BuiltInFieldTypes::all() as $type) {
            if ($type->id === 'core.uuid') {
                return $type;
            }
        }

        self::fail('The built-in UUID field type is missing.');
    }

    /**
     * Decode one JSON or Problem Details response.
     *
     * @param   ResponseInterface $response Response to decode.
     *
     * @return  array<string, mixed>  Decoded object.
     *
     * @since   2.0.0
     */
    private function body(ResponseInterface $response): array
    {
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 32, JSON_THROW_ON_ERROR);

        return $body;
    }
}
