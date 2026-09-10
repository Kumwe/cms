<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Infrastructure\Mcp;

use InvalidArgumentException;
use Kumwe\App\BusinessSurface\Application\BusinessHistoryUseCase;
use Kumwe\App\BusinessSurface\Application\BusinessMutationPlanService;
use Kumwe\App\BusinessSurface\Application\BusinessOperationStatusService;
use Kumwe\App\BusinessSurface\Application\BusinessSurface;
use Kumwe\App\BusinessSurface\Application\BusinessSurfaceCatalog;
use Kumwe\App\BusinessSurface\Application\BusinessSurfaceService;
use Kumwe\App\Infrastructure\Mcp\BusinessMcpHandlers;
use Kumwe\App\Infrastructure\Mcp\McpMutationGuard;
use Kumwe\Context\Value\ExecutionContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(BusinessMcpHandlers::class)]
/**
 * Verifies the generated-business MCP delegate's closed mutation and bounded read contracts.
 *
 * @since  2.0.0
 */
final class BusinessMcpHandlersTest extends TestCase
{
    /**
     * Prove the mutation vocabulary resolves only its exact shared capabilities.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testMutationCapabilitiesAreClosedAndExact(): void
    {
        $expected = [
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

        foreach ($expected as $operation => $capability) {
            self::assertSame($capability, BusinessMcpHandlers::capabilityFor($operation));
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The generated-business MCP operation is unsupported.');
        BusinessMcpHandlers::capabilityFor('approve_action');
    }

    #[DataProvider('invalidOperationIds')]
    /**
     * Prove operation-status identifiers use the same bounded MCP idempotency grammar.
     *
     * @param   string  $operationId  Invalid candidate operation identity.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testOperationStatusRejectsIdentifiersOutsideTheMcpGuardGrammar(string $operationId): void
    {
        $handler = (new ReflectionClass(BusinessMcpHandlers::class))->newInstanceWithoutConstructor();
        $context = (new ReflectionClass(ExecutionContext::class))->newInstanceWithoutConstructor();
        self::assertInstanceOf(BusinessMcpHandlers::class, $handler);
        self::assertInstanceOf(ExecutionContext::class, $context);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('MCP operationId must be a stable 16 to 128 character identifier.');
        $handler->operationStatus($context, $operationId);
    }

    /**
     * Prove mutation planning and execution share one canonical relation input document.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testPlanAndExecutionShareOneExactCanonicalRelationInput(): void
    {
        $method = (new ReflectionClass(BusinessMcpHandlers::class))->getMethod('planInput');

        self::assertSame([
            'operation_id' => 'relation-operation-0001',
            'definition' => 'crm.contact',
            'record' => 'contact-1',
            'expected_version' => 7,
            'relationship' => 'invoices',
            'target' => 'invoice-1',
            'position' => 3,
            'target_values' => ['amount' => '12.50'],
        ], $method->invoke(
            null,
            'relation-operation-0001',
            'relate',
            'crm.contact',
            'contact-1',
            7,
            [],
            'invoices',
            'invoice-1',
            3,
            ['amount' => '12.50'],
            [],
            null,
            [],
            null,
        ));
    }

    /**
     * Prove an existing-record mutation plan cannot omit optimistic concurrency evidence.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testPlanInputRefusesMissingExistingRecordVersion(): void
    {
        $method = (new ReflectionClass(BusinessMcpHandlers::class))->getMethod('planInput');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The mutation plan requires a positive expected version.');
        $method->invoke(
            null,
            'update-operation-0001',
            'update',
            'crm.contact',
            'contact-1',
            null,
            ['name' => 'Ada'],
            null,
            null,
            null,
            [],
            [],
            null,
            [],
            null,
        );
    }

    /**
     * Prove MCP history forwards the exact surface, definition, record, bound and cursor.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testHistoryDelegatesToTheSharedBoundedHistoryPort(): void
    {
        $context = (new ReflectionClass(ExecutionContext::class))->newInstanceWithoutConstructor();
        $history = $this->createMock(BusinessHistoryUseCase::class);
        $expected = [
            'items' => [],
            'has_more' => false,
            'next_before_version' => null,
        ];
        $history->expects(self::once())
            ->method('history')
            ->with(
                $context,
                BusinessSurface::Mcp,
                'acme.invoice',
                'INV-0001',
                25,
                8,
            )
            ->willReturn($expected);
        $handler = new BusinessMcpHandlers(
            (new ReflectionClass(BusinessSurfaceCatalog::class))->newInstanceWithoutConstructor(),
            (new ReflectionClass(BusinessSurfaceService::class))->newInstanceWithoutConstructor(),
            (new ReflectionClass(BusinessMutationPlanService::class))->newInstanceWithoutConstructor(),
            (new ReflectionClass(McpMutationGuard::class))->newInstanceWithoutConstructor(),
            (new ReflectionClass(BusinessOperationStatusService::class))->newInstanceWithoutConstructor(),
            $history,
        );

        self::assertSame($expected, $handler->history($context, 'acme.invoice', 'INV-0001', 25, 8));
    }

    /**
     * Supply operation identities outside the shared MCP grammar.
     *
     * @return  iterable<string, array{string}>  Invalid operation identities keyed by failure case.
     *
     * @since   2.0.0
     */
    public static function invalidOperationIds(): iterable
    {
        yield 'too short' => ['123456789012345'];
        yield 'invalid first character' => ['-123456789012345'];
        yield 'invalid punctuation' => ['123456789012345/'];
        yield 'too long' => [str_repeat('a', 129)];
    }
}
