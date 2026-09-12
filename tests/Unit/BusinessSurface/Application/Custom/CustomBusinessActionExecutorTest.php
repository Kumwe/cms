<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\BusinessSurface\Application\Custom;

use DateTimeImmutable;
use Kumwe\Context\Value\AuthenticationStrength;
use Kumwe\App\Application\Authorization\AuthorizationGateway;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Context\Value\SiteContext;
use Kumwe\Transaction\Contract\TransactionManager;
use Kumwe\App\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\App\BusinessRecord\Application\BusinessRecordCustomActionGuard;
use Kumwe\App\BusinessRecord\Application\BusinessRecordDefinitionResolver;
use Kumwe\App\BusinessRecord\Application\BusinessRecordIdempotencyRepository;
use Kumwe\App\BusinessRecord\Application\BusinessRecordMutationFence;
use Kumwe\App\BusinessRecord\Application\BusinessRecordMutationGeneration;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordDefinitionUnavailable;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordIdempotencyConflict;
use Kumwe\App\BusinessRecord\Application\RecordFingerprint;
use Kumwe\App\BusinessRecord\Application\ResolvedBusinessDefinition;
use Kumwe\App\BusinessRecord\Domain\BusinessRecordIdempotency;
use Kumwe\App\BusinessRecord\Domain\BusinessRecordIdempotencyState;
use Kumwe\App\BusinessSchema\Domain\SchemaInstallation;
use Kumwe\App\BusinessSchema\Domain\SchemaInstallationStatus;
use Kumwe\App\BusinessSecurity\Application\BusinessRecordAccessController;
use Kumwe\App\BusinessSecurity\Application\BusinessRecordAccessPlan;
use Kumwe\Extension\Spi\BusinessSecurity\Application\FieldDisclosurePlan;
use Kumwe\App\BusinessSecurity\Policy\RecordPolicyConstant;
use Kumwe\App\BusinessSecurity\Policy\RecordPolicySchema;
use Kumwe\App\BusinessSecurity\Policy\RecordPolicySet;
use Kumwe\App\BusinessSurface\Application\CustomBusinessActionExecutor;
use Kumwe\App\BusinessSurface\Application\Custom\CustomBusinessActionHandlerRegistry;
use Kumwe\App\BusinessSurface\Application\Custom\CustomBusinessActionLedgerResult;
use Kumwe\App\BusinessSurface\Application\Custom\CustomBusinessReferenceRegistry;
use Kumwe\App\BusinessSurface\Application\Custom\CustomBusinessSurfaceDispatcher;
use Kumwe\App\BusinessSurface\Application\Custom\CustomBusinessViewHandlerRegistry;
use Kumwe\App\Extension\Runtime\RuntimeMaterializationState;
use Kumwe\App\Extension\Application\ExtensionExecutionGate;
use Kumwe\App\Tests\Support\AuthorizationContext;
use Kumwe\Extension\Spi\Application\Automation\IdempotencyKey;
use Kumwe\Extension\Spi\BusinessSurface\Application\Custom\CustomBusinessActionCommand;
use Kumwe\Extension\Spi\BusinessSurface\Application\Custom\CustomBusinessActionDeclaration;
use Kumwe\Extension\Spi\BusinessSurface\Application\Custom\CustomBusinessActionHandler;
use Kumwe\Extension\Spi\BusinessSurface\Application\Custom\CustomBusinessActionResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use ReflectionClass;
use Kumwe\App\Extension\Runtime\ExtensionExecutionContext;

#[CoversClass(CustomBusinessActionExecutor::class)]
#[CoversClass(CustomBusinessActionLedgerResult::class)]
/**
 * Verifies custom actions share the canonical durable at-most-once mutation boundary.
 *
 * @since  2.0.0
 */
final class CustomBusinessActionExecutorTest extends TestCase
{
    /**
     * Immutable definition identity used by the installed generation fixture.
     *
     * @var    string
     * @since  2.0.0
     */
    private const DEFINITION_ID = '018f4f24-98d8-7ad4-8f3f-38c909178b6b';

    /**
     * Prove a retry returns the exact tagged result without entering record or extension code twice.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testReplaysTheExactCustomResultThroughTheCanonicalLedger(): void
    {
        [$executor, $command, $entry] = $this->fixture(2);

        $fresh = $executor->execute($command);
        $replay = $executor->execute($command);

        self::assertSame(['status' => 'done'], $fresh->data);
        self::assertFalse($fresh->replayed);
        self::assertSame($fresh->data, $replay->data);
        self::assertSame(3, $replay->recordVersion);
        self::assertTrue($replay->replayed);
        self::assertSame($command->idempotencyKey->value(), $replay->operationId->value());
        self::assertSame(CustomBusinessActionLedgerResult::KIND, $entry()['kind'] ?? null);
    }

    /**
     * Prove native JSON storage may reorder object keys without invalidating a complete tagged result.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testReconstructsACompleteCustomResultAfterJsonKeyReordering(): void
    {
        $stored = new CustomBusinessActionLedgerResult(
            self::DEFINITION_ID,
            2,
            str_repeat('a', 64),
            7,
            str_repeat('b', 64),
            'acme.editor.actions.recalculate',
            'acme.editor.schemas.recalculate_v1',
            '018f4f24-98d8-7ad4-8f3f-38c909178b70',
            3,
            null,
            'recalculate',
            false,
            false,
            ['status' => 'done'],
        );
        $document = $stored->toArray();
        ksort($document, SORT_STRING);

        $restored = CustomBusinessActionLedgerResult::fromArray($document);

        self::assertSame($stored->toArray(), $restored->toArray());
    }

    /**
     * Prove one operation identity cannot replay a different contract input.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRejectsAKeyReusedWithDifferentCustomInput(): void
    {
        [$executor, $command] = $this->fixture(2);
        $executor->execute($command);

        try {
            $executor->execute(new CustomBusinessActionCommand(
                $command->context,
                $command->definitionIdentifier,
                $command->recordId,
                $command->expectedVersion,
                $command->action,
                $command->idempotencyKey,
                ['mode' => 'delta'],
                $command->organizationIdentifier,
            ));
            self::fail('A reused custom-action key with different input should fail.');
        } catch (BusinessRecordIdempotencyConflict $exception) {
            self::assertSame('business_record.idempotency_key_reused', $exception->stableCode());
        }
    }

    /**
     * Prove the reusable SDK command cannot substitute its own authorization context at the App boundary.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRejectsACommandCarryingAForeignExecutionContext(): void
    {
        [$executor, $command] = $this->fixture(1);
        $executor->execute($command);

        try {
            $executor->execute(new CustomBusinessActionCommand(
                $this->createStub(\Kumwe\Extension\Spi\Application\ExecutionContext::class),
                $command->definitionIdentifier,
                $command->recordId,
                $command->expectedVersion,
                $command->action,
                IdempotencyKey::fromString('operation:foreign-context-0001'),
                $command->input,
                $command->organizationIdentifier,
            ));
            self::fail('A foreign execution context entered the App mutation boundary.');
        } catch (BusinessRecordDefinitionUnavailable) {
            self::addToAssertionCount(1);
        }
    }

    /**
     * Assemble one fully fenced custom executor around a mutable in-memory mock ledger.
     *
     * @param   int  $lookups  Expected number of ledger lookups across the test.
     *
     * @return  array{
     *              CustomBusinessActionExecutor,
     *              CustomBusinessActionCommand,
     *              \Closure(): array<string, mixed>
     *          }
     *          Configured executor, exact command, and stored-document reader.
     *
     * @since   2.0.0
     */
    private function fixture(int $lookups): array
    {
        $definition = self::definition();
        [$resolved, $generation] = self::generation($definition);
        $context = self::context();
        $plan = new BusinessRecordAccessPlan(
            self::DEFINITION_ID,
            'business.record.action',
            new RecordPolicySet(new RecordPolicySchema([]), [new RecordPolicyConstant(true)]),
            new FieldDisclosurePlan(['detail' => ['id']]),
            str_repeat('a', 64),
            actions: ['recalculate'],
        );
        $authorization = $this->createStub(AuthorizationGateway::class);
        $references = new CustomBusinessReferenceRegistry();
        $actions = new CustomBusinessActionHandlerRegistry($references);
        $handler = $this->createMock(CustomBusinessActionHandler::class);
        $handler->expects(self::once())
            ->method('handle')
            ->willReturnCallback(static fn (CustomBusinessActionCommand $command): CustomBusinessActionResult => (
                new CustomBusinessActionResult(
                    ['status' => 'done'],
                    3,
                    $command->idempotencyKey,
                )
            ));
        $actions->register($definition->owner, self::contract(), $handler);
        $dispatcher = new CustomBusinessSurfaceDispatcher(
            new CustomBusinessViewHandlerRegistry($references),
            $actions,
            $authorization,
            $this->createStub(ExtensionExecutionGate::class),
        );
        $guard = $this->createMock(BusinessRecordCustomActionGuard::class);
        $guard->expects(self::once())->method('guardCustomAction');
        $definitions = $this->createStub(BusinessRecordDefinitionResolver::class);
        $definitions->method('forCreate')->willReturn($resolved);
        $fence = $this->createStub(BusinessRecordMutationFence::class);
        $fence->method('lock')->willReturn($generation);
        $access = $this->createStub(BusinessRecordAccessController::class);
        $access->method('plan')->willReturn($plan);
        [$idempotency, $entry] = $this->idempotency($lookups);
        $transactions = $this->createStub(TransactionManager::class);
        $transactions->method('transactional')->willReturnCallback(
            static fn (callable $operation): mixed => $operation(),
        );
        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn(new DateTimeImmutable('2026-08-10T12:00:00+00:00'));
        $executor = new CustomBusinessActionExecutor(
            $dispatcher,
            $guard,
            $idempotency,
            $fence,
            $definitions,
            $access,
            new RecordFingerprint(str_repeat('custom-action-key-', 3)),
            $transactions,
            $clock,
            new RuntimeMaterializationState(
                'test-replica',
                7,
                str_repeat('b', 64),
                str_repeat('c', 64),
                true,
            ),
        );
        $command = new CustomBusinessActionCommand(
            ExtensionExecutionContext::of($context),
            $definition->handle,
            '018f4f24-98d8-7ad4-8f3f-38c909178b70',
            2,
            'recalculate',
            IdempotencyKey::fromString('custom-action-0001'),
            ['mode' => 'full'],
        );

        return [$executor, $command, $entry];
    }

    /**
     * Build a checksum-valid mutable ledger mock with canonical completion semantics.
     *
     * @param   int  $lookups  Expected number of exact scope lookups.
     *
     * @return  array{BusinessRecordIdempotencyRepository&MockObject, \Closure(): array<string, mixed>}
     *          Repository mock and reader for its completed result.
     *
     * @since   2.0.0
     */
    private function idempotency(int $lookups): array
    {
        $entry = null;
        $repository = $this->createMock(BusinessRecordIdempotencyRepository::class);
        $repository->expects(self::exactly($lookups))
            ->method('find')
            ->willReturnCallback(static function (string $scope) use (&$entry): ?BusinessRecordIdempotency {
                return $entry instanceof BusinessRecordIdempotency && $entry->scopeDigest === $scope
                    ? $entry
                    : null;
            });
        $repository->expects(self::once())
            ->method('begin')
            ->willReturnCallback(static function (BusinessRecordIdempotency $claim) use (&$entry): void {
                $entry = $claim;
            });
        $repository->expects(self::once())
            ->method('complete')
            ->willReturnCallback(static function (
                string $id,
                array $result,
                string $checksum,
                DateTimeImmutable $completedAt,
            ) use (&$entry): void {
                self::assertInstanceOf(BusinessRecordIdempotency::class, $entry);
                self::assertSame($entry->id, $id);
                $entry = new BusinessRecordIdempotency(
                    $entry->id,
                    $entry->scopeDigest,
                    $entry->siteIdentifier,
                    $entry->organizationIdentifier,
                    $entry->actorId,
                    $entry->operation,
                    $entry->operationId,
                    $entry->requestFingerprint,
                    $entry->authorizationFingerprint,
                    BusinessRecordIdempotencyState::Completed,
                    $result,
                    $checksum,
                    $entry->createdAt,
                    $completedAt,
                    $entry->expiresAt,
                );
            });

        return [$repository, static function () use (&$entry): array {
            self::assertInstanceOf(BusinessRecordIdempotency::class, $entry);
            return $entry->result() ?? [];
        }];
    }

    /**
     * Construct an active installed definition pair and matching exclusive mutation generation.
     *
     * @param   EntityTypeDefinition  $definition  Published custom-action definition.
     *
     * @return  array{ResolvedBusinessDefinition, BusinessRecordMutationGeneration}  Matching fence values.
     *
     * @since   2.0.0
     */
    private static function generation(EntityTypeDefinition $definition): array
    {
        $schemaChecksum = str_repeat('d', 64);
        $installation = (new ReflectionClass(SchemaInstallation::class))->newInstanceWithoutConstructor();
        $installationReflection = new ReflectionClass(SchemaInstallation::class);
        foreach (
            [
            'definitionId' => $definition->id,
            'siteIdentifier' => $definition->siteIdentifier,
            'ownerIdentifier' => $definition->owner->identifier,
            'definitionVersion' => $definition->definitionVersion,
            'definitionChecksum' => $definition->checksum(),
            'schemaChecksum' => $schemaChecksum,
            'status' => SchemaInstallationStatus::Active,
            ] as $property => $value
        ) {
            $installationReflection->getProperty($property)->setValue($installation, $value);
        }
        $resolved = (new ReflectionClass(ResolvedBusinessDefinition::class))->newInstanceWithoutConstructor();
        $resolvedReflection = new ReflectionClass(ResolvedBusinessDefinition::class);
        $resolvedReflection->getProperty('definition')->setValue($resolved, $definition);
        $resolvedReflection->getProperty('installation')->setValue($resolved, $installation);

        return [$resolved, new BusinessRecordMutationGeneration(
            $definition->id,
            $definition->siteIdentifier,
            $definition->owner->identifier,
            $definition->definitionVersion,
            $definition->checksum(),
            $schemaChecksum,
            SchemaInstallationStatus::Active,
        )];
    }

    /**
     * Build the signed custom action contract used for request and result validation.
     *
     * @return  CustomBusinessActionDeclaration  Closed command and result schemas.
     *
     * @since   2.0.0
     */
    private static function contract(): CustomBusinessActionDeclaration
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
     * Build a published extension-owned definition with one typed custom action.
     *
     * @return  EntityTypeDefinition  Published action definition.
     *
     * @since   2.0.0
     */
    private static function definition(): EntityTypeDefinition
    {
        return EntityTypeDefinition::fromArray([
            'id' => self::DEFINITION_ID,
            'owner' => ['type' => 'extension', 'identifier' => 'acme/editor'],
            'site' => 'default',
            'handle' => 'acme.editor.asset',
            'singular_label' => 'Asset',
            'plural_label' => 'Assets',
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
            'actions' => [[
                'handle' => 'recalculate',
                'label' => 'Recalculate',
                'capability' => 'acme.editor.manage',
                'administrator' => true,
                'handler' => 'acme.editor.actions.recalculate',
                'schema' => 'acme.editor.schemas.recalculate_v1',
            ]],
            'workflow' => null,
            'compatibility_metadata' => [],
            'administrator_exposure' => true,
            'portal_exposure' => false,
            'public_exposure' => false,
            'soft_delete_enabled' => true,
            'record_invariants' => [],
            'portal_operations' => [],
        ]);
    }

    /**
     * Mint one API execution context with both common and custom action grants.
     *
     * @return  ExecutionContext  Authenticated custom-action context.
     *
     * @since   2.0.0
     */
    private static function context(): ExecutionContext
    {
        $principal = AuthorizationContext::principal([
            'business.record.action',
            'acme.editor.manage',
        ]);

        return $principal->context(
            SiteContext::default(),
            AuthenticationStrength::BearerToken,
            'custom-action-executor-0001',
        );
    }
}
