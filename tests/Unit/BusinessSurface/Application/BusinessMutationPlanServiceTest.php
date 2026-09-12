<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\BusinessSurface\Application;

use DateTimeImmutable;
use InvalidArgumentException;
use Kumwe\Context\Value\AuthenticatedSurface;
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
use Kumwe\App\BusinessRecord\Application\BusinessRecordService;
use Kumwe\App\BusinessRecord\Application\RecordFingerprint;
use Kumwe\App\BusinessRecord\Application\ResolvedBusinessDefinition;
use Kumwe\App\BusinessSecurity\Application\BusinessRecordAccessController;
use Kumwe\App\BusinessSecurity\Application\BusinessRecordAccessPlan;
use Kumwe\App\BusinessSecurity\Policy\RecordPolicyConstant;
use Kumwe\App\BusinessSecurity\Policy\RecordPolicySchema;
use Kumwe\App\BusinessSecurity\Policy\RecordPolicySet;
use Kumwe\App\BusinessSurface\Application\BusinessMutationPlanService;
use Kumwe\App\BusinessSurface\Application\BusinessSurface;
use Kumwe\App\BusinessSurface\Application\BusinessSurfaceCatalog;
use Kumwe\App\BusinessSurface\Infrastructure\Security\KeyRingMutationPlanCipher;
use Kumwe\App\Extension\Runtime\RuntimeMaterializationState;
use Kumwe\App\Tests\Support\AuthorizationContext;
use Kumwe\Extension\Spi\BusinessSecurity\Application\FieldDisclosurePlan;
use Kumwe\Secret\Provider\KeyRingKeyProvider;
use Kumwe\Secret\Value\KeyMaterial;
use Kumwe\Secret\Value\KeyRing;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use ReflectionClass;

#[CoversClass(BusinessMutationPlanService::class)]
#[CoversClass(KeyRingMutationPlanCipher::class)]
/**
 * Proves MCP mutation plans are sealed, bounded, short-lived, and context bound.
 *
 * @since  2.0.0
 */
final class BusinessMutationPlanServiceTest extends TestCase
{
    /**
     * Published definition identity shared by replay-binding fixtures.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string DEFINITION_ID = '018f22e2-7c8b-7ab0-8f3a-88e8026bb701';

    /**
     * Proves a valid opaque plan round-trips while a byte change fails uniformly.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testOpaqueSealedPlanRoundTripsButTamperingFailsUniformly(): void
    {
        $service = $this->service();
        $document = $this->document();
        $encode = (new ReflectionClass($service))->getMethod('encode');
        $decode = (new ReflectionClass($service))->getMethod('decode');
        $plan = $encode->invoke($service, $document);

        self::assertIsString($plan);
        self::assertStringNotContainsString($document['policy_binding'], $plan);
        self::assertStringNotContainsString($document['definition_id'], $plan);
        self::assertSame($document, $decode->invoke($service, $plan));

        $last = substr($plan, -1);
        $tampered = substr($plan, 0, -1) . ($last === '0' ? '1' : '0');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The generated-business mutation plan is invalid or stale.');
        $decode->invoke($service, $tampered);
    }

    /**
     * Proves expiry is enforced before any business runtime collaborator is consulted.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testExpiredPlanIsRejectedBeforeAnyRuntimeLookup(): void
    {
        $service = $this->service();
        $context = AuthorizationContext::human(['business.record.read', 'business.record.create']);
        $document = $this->document();
        $document['context_binding'] = $context->authorizationFingerprint();
        $input = [
            'operation_id' => 'create-operation-0001',
            'definition' => 'crm.contact',
            'values' => ['name' => 'Ada'],
            'record' => null,
        ];
        $document['input_binding'] = (new RecordFingerprint(str_repeat('k', 32)))->digest($input);
        $encode = (new ReflectionClass($service))->getMethod('encode');
        $plan = $encode->invoke($service, $document);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The generated-business mutation plan is invalid or stale.');
        $service->assertCanExecute($context, BusinessSurface::Mcp, $plan, 'create', $input);
    }

    /**
     * Proves an operation identity change invalidates an otherwise current create plan.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testCreatePlanCannotExecuteUnderAnotherOperationIdentity(): void
    {
        $service = $this->service();
        $context = AuthorizationContext::human(['business.record.read', 'business.record.create']);
        $document = $this->document();
        $document['issued_at'] = (new DateTimeImmutable('2026-08-10T11:59:00+00:00'))->getTimestamp();
        $document['expires_at'] = (new DateTimeImmutable('2026-08-10T12:04:00+00:00'))->getTimestamp();
        $document['context_binding'] = $context->authorizationFingerprint();
        $input = [
            'operation_id' => 'another-operation-0001',
            'definition' => 'crm.contact',
            'values' => ['name' => 'Ada'],
            'record' => null,
        ];
        $document['input_binding'] = (new RecordFingerprint(str_repeat('k', 32)))->digest($input);
        $encode = (new ReflectionClass($service))->getMethod('encode');
        $plan = $encode->invoke($service, $document);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The generated-business mutation plan is invalid or stale.');
        $service->assertCanExecute($context, BusinessSurface::Mcp, $plan, 'create', $input);
    }

    /**
     * Proves canonical mutation input is closed and relationship positions are bounded.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testCanonicalInputRefusesExtraMembersAndUnboundedPositions(): void
    {
        $service = $this->service();
        $assertInput = (new ReflectionClass($service))->getMethod('assertInput');

        try {
            $assertInput->invoke($service, 'create', [
                'operation_id' => 'create-operation-0001',
                'definition' => 'crm.contact',
                'values' => ['name' => 'Ada'],
                'record' => null,
                'organization' => 'caller-controlled',
            ]);
            self::fail('An extra mutation plan member was accepted.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('The generated-business mutation plan is invalid or stale.', $exception->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The generated-business mutation plan is invalid or stale.');
        $assertInput->invoke($service, 'relate', [
            'operation_id' => 'relation-operation-0001',
            'definition' => 'crm.contact',
            'record' => 'contact-1',
            'expected_version' => 4,
            'relationship' => 'invoices',
            'target' => 'invoice-1',
            'position' => 1_000_001,
            'target_values' => [],
        ]);
    }

    /**
     * Proves a completed replay retains every authority binding while tolerating only expiry and record advance.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testCompletedReplayIgnoresOnlyExecutionExpiryAndSourceRecordAdvance(): void
    {
        [$service, $definition, $access, $runtime] = $this->replayService(7, hash('sha256', 'policy-a'));
        $context = $this->mcpContext();
        $input = $this->updateInput();
        $plan = $this->replayPlan($service, $context, $input, $definition, $access, $runtime);

        $service->assertCanReplay($context, BusinessSurface::Mcp, $plan, 'update', $input);

        self::addToAssertionCount(1);
    }

    /**
     * Proves a completed guard result is withheld when current record-policy state has drifted.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testCompletedReplayRejectsPolicyDriftOutsideTheContextFingerprint(): void
    {
        [$issuer, $definition, $access, $runtime] = $this->replayService(7, hash('sha256', 'policy-a'));
        [$replayer] = $this->replayService(7, hash('sha256', 'policy-b'));
        $context = $this->mcpContext();
        $input = $this->updateInput();
        $plan = $this->replayPlan($issuer, $context, $input, $definition, $access, $runtime);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The generated-business mutation plan is invalid or stale.');
        $replayer->assertCanReplay($context, BusinessSurface::Mcp, $plan, 'update', $input);
    }

    /**
     * Proves a completed guard result is withheld after the trusted runtime generation changes.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testCompletedReplayRejectsRuntimeDrift(): void
    {
        [$issuer, $definition, $access, $runtime] = $this->replayService(7, hash('sha256', 'policy-a'));
        [$replayer] = $this->replayService(8, hash('sha256', 'policy-a'));
        $context = $this->mcpContext();
        $input = $this->updateInput();
        $plan = $this->replayPlan($issuer, $context, $input, $definition, $access, $runtime);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The generated-business mutation plan is invalid or stale.');
        $replayer->assertCanReplay($context, BusinessSurface::Mcp, $plan, 'update', $input);
    }

    /**
     * Construct a planner whose codec and pre-lookup validation paths are fully usable.
     *
     * @return  BusinessMutationPlanService  Planner with inert runtime collaborators.
     *
     * @since   2.0.0
     */
    private function service(): BusinessMutationPlanService
    {
        return new BusinessMutationPlanService(
            self::withoutConstructor(BusinessSurfaceCatalog::class),
            self::withoutConstructor(BusinessRecordService::class),
            $this->createStub(BusinessRecordDefinitionResolver::class),
            $this->createStub(BusinessRecordAccessController::class),
            new RecordFingerprint(str_repeat('k', 32)),
            new KeyRingMutationPlanCipher(new KeyRingKeyProvider(new KeyRing(
                new KeyMaterial('mutation-plan-test', str_repeat('s', 32)),
            ))),
            $this->createStub(TransactionManager::class),
            new class implements ClockInterface {
                /**
                 * Return the fixed instant used to test plan freshness.
                 *
                 * @return  DateTimeImmutable  Stable planner clock time.
                 *
                 * @since   2.0.0
                 */
                public function now(): DateTimeImmutable
                {
                    return new DateTimeImmutable('2026-08-10T12:00:00+00:00');
                }
            },
        );
    }

    /**
     * Build a fully usable replay verifier with selectable policy and runtime generations.
     *
     * @param   int     $runtimeGeneration  Trusted runtime generation served by this process.
     * @param   string  $policyFingerprint  Current canonical record-policy fingerprint.
     *
     * @return  array{BusinessMutationPlanService, EntityTypeDefinition, BusinessRecordAccessPlan,
     *          RuntimeMaterializationState}  Planner and the exact bindings used to seal a replay fixture.
     *
     * @since   2.0.0
     */
    private function replayService(int $runtimeGeneration, string $policyFingerprint): array
    {
        $definition = EntityTypeDefinition::fromArray($this->definition());
        $resolved = (new ReflectionClass(ResolvedBusinessDefinition::class))->newInstanceWithoutConstructor();
        (new ReflectionClass(ResolvedBusinessDefinition::class))
            ->getProperty('definition')
            ->setValue($resolved, $definition);
        $definitions = $this->createStub(BusinessRecordDefinitionResolver::class);
        $definitions->method('activeInstalled')->willReturn([$resolved]);
        $definitions->method('forCreate')->willReturn($resolved);
        $accessPlan = new BusinessRecordAccessPlan(
            self::DEFINITION_ID,
            'business.record.update',
            new RecordPolicySet(new RecordPolicySchema([]), [new RecordPolicyConstant(true)]),
            new FieldDisclosurePlan(['update' => ['name']]),
            $policyFingerprint,
        );
        $access = $this->createStub(BusinessRecordAccessController::class);
        $access->method('plan')->willReturn($accessPlan);
        $fieldTypes = $this->createStub(FieldTypeDefinitionResolver::class);
        $fieldTypes->method('get')->willReturn($this->fieldType('core.text'));
        $authorization = $this->createStub(AuthorizationGateway::class);
        $authorization->method('decide')->willReturn(new AuthorizationDecision(true, 'test', 'allowed'));
        $transactions = $this->createStub(TransactionManager::class);
        $transactions->method('transactional')->willReturnCallback(
            static fn (callable $operation): mixed => $operation(),
        );
        $runtime = new RuntimeMaterializationState(
            'mutation-plan-test',
            $runtimeGeneration,
            hash('sha256', 'publication'),
            hash('sha256', 'runtime-hmac'),
            true,
        );
        $catalog = new BusinessSurfaceCatalog(
            $definitions,
            $access,
            $fieldTypes,
            $authorization,
            $transactions,
            $runtime,
        );
        $service = new BusinessMutationPlanService(
            $catalog,
            self::withoutConstructor(BusinessRecordService::class),
            $definitions,
            $access,
            new RecordFingerprint(str_repeat('k', 32)),
            new KeyRingMutationPlanCipher(new KeyRingKeyProvider(new KeyRing(
                new KeyMaterial('mutation-plan-test', str_repeat('s', 32)),
            ))),
            $transactions,
            $this->clock('2026-08-10T13:00:00+00:00'),
        );

        return [$service, $definition, $accessPlan, $runtime];
    }

    /**
     * Seal one expired update plan whose source version represents the pre-mutation record.
     *
     * @param   BusinessMutationPlanService  $service     Planner supplying the authenticated codec.
     * @param   ExecutionContext             $context     Exact MCP actor and credential state.
     * @param   array<string, mixed>         $input       Canonical update input.
     * @param   EntityTypeDefinition         $definition  Exact published definition.
     * @param   BusinessRecordAccessPlan     $access      Exact canonical policy plan.
     * @param   RuntimeMaterializationState  $runtime     Runtime state used by the original execution.
     *
     * @return  string  Opaque authenticated plan token.
     *
     * @since   2.0.0
     */
    private function replayPlan(
        BusinessMutationPlanService $service,
        ExecutionContext $context,
        array $input,
        EntityTypeDefinition $definition,
        BusinessRecordAccessPlan $access,
        RuntimeMaterializationState $runtime,
    ): string {
        $issued = new DateTimeImmutable('2026-08-10T12:00:00+00:00');
        $document = [
            'version' => 1,
            'surface' => 'mcp',
            'operation' => 'update',
            'operation_id' => $input['operation_id'],
            'definition_id' => $definition->id,
            'definition_version' => $definition->definitionVersion,
            'definition_checksum' => $definition->checksum(),
            'runtime_binding' => hash('sha256', implode(':', [
                (string) $runtime->generation,
                $runtime->publicationChecksum,
                $definition->checksum(),
            ])),
            'policy_binding' => $access->digest(),
            'context_binding' => $context->authorizationFingerprint(),
            'record_id' => $input['record'],
            'record_version' => $input['expected_version'],
            'input_binding' => (new RecordFingerprint(str_repeat('k', 32)))->digest($input),
            'issued_at' => $issued->getTimestamp(),
            'expires_at' => $issued->modify('+5 minutes')->getTimestamp(),
        ];
        $encode = (new ReflectionClass($service))->getMethod('encode');
        $plan = $encode->invoke($service, $document);
        self::assertIsString($plan);

        return $plan;
    }

    /**
     * Return the MCP execution context whose fingerprint the replay token binds.
     *
     * @return  ExecutionContext  Provenance-bound MCP context with update authority.
     *
     * @since   2.0.0
     */
    private function mcpContext(): ExecutionContext
    {
        return AuthorizationContext::principal(['business.record.update'])->context(
            SiteContext::default(),
            AuthenticationStrength::BearerToken,
            'mutation-plan-replay-test-0001',
            surface: AuthenticatedSurface::Mcp,
        );
    }

    /**
     * Return the exact canonical update input used by all replay tests.
     *
     * @return  array<string, mixed>  Closed update input with the original source version.
     *
     * @since   2.0.0
     */
    private function updateInput(): array
    {
        return [
            'operation_id' => 'update-operation-0001',
            'definition' => 'site.default.mutation_plan_test',
            'record' => 'record-1',
            'expected_version' => 4,
            'values' => ['name' => 'Ada'],
        ];
    }

    /**
     * Return the minimal published definition whose update metadata remains visible.
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
            'handle' => 'site.default.mutation_plan_test',
            'singular_label' => 'Mutation plan test',
            'plural_label' => 'Mutation plan tests',
            'status' => 'published',
            'definition_version' => 3,
            'storage_mode' => 'relational',
            'identity_strategy' => 'uuid',
            'scope' => 'site',
            'audit_enabled' => true,
            'revisions_enabled' => true,
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
                    'length' => 120,
                ],
            ],
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
     * Resolve one built-in field type for catalog metadata projection.
     *
     * @param   string  $identifier  Built-in field-type identifier.
     *
     * @return  FieldTypeDefinition  Matching immutable field type.
     *
     * @since   2.0.0
     */
    private function fieldType(string $identifier): FieldTypeDefinition
    {
        foreach (BuiltInFieldTypes::all() as $type) {
            if ($type->id === $identifier) {
                return $type;
            }
        }

        self::fail('The requested built-in field type is missing.');
    }

    /**
     * Build a fixed immutable clock at one test instant.
     *
     * @param   string  $instant  ISO-8601 test instant.
     *
     * @return  ClockInterface  Clock returning the requested instant.
     *
     * @since   2.0.0
     */
    private function clock(string $instant): ClockInterface
    {
        return new class ($instant) implements ClockInterface {
            /**
             * Capture the fixed instant.
             *
             * @param  string  $instant  ISO-8601 test instant.
             *
             * @since  2.0.0
             */
            public function __construct(private string $instant)
            {
            }

            /**
             * Return the fixed instant.
             *
             * @return  DateTimeImmutable  Stable test time.
             *
             * @since   2.0.0
             */
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable($this->instant);
            }
        };
    }

    /**
     * Return one structurally valid, already-expired signed plan document.
     *
     * @return  array<string, mixed>  Complete scalar plan vocabulary.
     *
     * @since   2.0.0
     */
    private function document(): array
    {
        return [
            'version' => 1,
            'surface' => 'mcp',
            'operation' => 'create',
            'operation_id' => 'create-operation-0001',
            'definition_id' => '018f22e2-7c8b-7ab0-8f3a-88e8026bb301',
            'definition_version' => 3,
            'definition_checksum' => str_repeat('1', 64),
            'runtime_binding' => str_repeat('2', 64),
            'policy_binding' => str_repeat('3', 64),
            'context_binding' => str_repeat('4', 64),
            'record_id' => null,
            'record_version' => null,
            'input_binding' => str_repeat('5', 64),
            'issued_at' => 1_754_828_400,
            'expires_at' => 1_754_828_700,
        ];
    }

    /**
     * Instantiate a final collaborator without executing its unrelated constructor graph.
     *
     * @template T of object
     *
     * @param   class-string<T>  $class  Class to instantiate.
     *
     * @return  T  Uninitialized object used only as an untouched constructor dependency.
     *
     * @since   2.0.0
     */
    private static function withoutConstructor(string $class): object
    {
        return (new ReflectionClass($class))->newInstanceWithoutConstructor();
    }
}
