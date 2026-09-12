<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\Mcp;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Kumwe\Transaction\Contract\TransactionManager;
use Kumwe\App\Infrastructure\Mcp\McpMutationGuard;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Tests\Support\TestKernelFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

#[CoversClass(McpMutationGuard::class)]
final class McpMutationGuardIntegrationTest extends TestCase
{
    /**
     * Proves nested object key order does not change an MCP idempotency binding.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testNestedMapOrderDoesNotChangeTheIdempotencyBinding(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $guard = $container->get(McpMutationGuard::class);
        self::assertInstanceOf(McpMutationGuard::class, $guard);
        $context = TestKernelFactory::administratorContext($container);
        $operationId = 'nested-canonical-' . Uuid::uuid7()->toString();
        $calls = 0;
        $mutation = static function () use (&$calls): array {
            ++$calls;

            return ['applied' => true];
        };

        $first = $guard->run($context, 'probe.nested', $operationId, [
            'items' => [['beta' => 2, 'alpha' => 1]],
            'metadata' => ['zulu' => ['two' => 2, 'one' => 1], 'alpha' => true],
        ], $mutation);
        $replay = $guard->run($context, 'probe.nested', $operationId, [
            'metadata' => ['alpha' => true, 'zulu' => ['one' => 1, 'two' => 2]],
            'items' => [['alpha' => 1, 'beta' => 2]],
        ], $mutation);

        self::assertSame(['applied' => true], $first);
        self::assertSame($first, $replay);
        self::assertSame(1, $calls);
    }

    public function testExpiredLeaseIsFencedAndRecovered(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $guard = $container->get(McpMutationGuard::class);
        $database = $container->get(Connection::class);
        $tables = $container->get(TableNames::class);
        self::assertInstanceOf(McpMutationGuard::class, $guard);
        self::assertInstanceOf(Connection::class, $database);
        self::assertInstanceOf(TableNames::class, $tables);
        $context = TestKernelFactory::administratorContext($container);
        $principal = $context->principal();
        self::assertNotNull($principal);
        $operationId = 'stale-lease-' . Uuid::uuid7()->toString();
        $input = ['probe' => 'recover'];
        $now = new DateTimeImmutable();
        $database->insert($tables->raw('idempotency'), [
            'id' => Uuid::uuid7()->toString(),
            'idempotency_key' => $operationId,
            'subject' => $principal->subject(),
            'operation' => 'mcp.probe.recover',
            'request_digest' => hash('sha256', json_encode($input, JSON_THROW_ON_ERROR)),
            'authorization_fingerprint' => $context->authorizationFingerprint(),
            'state' => 'in_progress',
            'owner_token' => str_repeat('a', 64),
            'lease_expires_at' => $now->modify('-1 minute'),
            'attempt' => 1,
            'created_at' => $now->modify('-5 minutes'),
            'expires_at' => $now->modify('+1 day'),
        ], [
            'lease_expires_at' => Types::DATETIME_IMMUTABLE,
            'created_at' => Types::DATETIME_IMMUTABLE,
            'expires_at' => Types::DATETIME_IMMUTABLE,
        ]);

        self::assertSame(
            ['recovered' => true],
            $guard->run($context, 'probe.recover', $operationId, $input, static fn (): array => [
                'recovered' => true,
            ]),
        );
        $row = $database->fetchAssociative(sprintf(
            'SELECT state, attempt FROM %s WHERE subject = ? AND operation = ? AND idempotency_key = ?',
            $tables->quoted('idempotency'),
        ), [$principal->subject(), 'mcp.probe.recover', $operationId]);
        self::assertIsArray($row);
        self::assertSame('completed', $row['state']);
        self::assertSame('2', (string) $row['attempt']);
    }

    public function testFailureBeforeCompletionRollsBackMutationAndReleasesLease(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $guard = $container->get(McpMutationGuard::class);
        $database = $container->get(Connection::class);
        $tables = $container->get(TableNames::class);
        $transactions = $container->get(TransactionManager::class);
        self::assertInstanceOf(McpMutationGuard::class, $guard);
        self::assertInstanceOf(Connection::class, $database);
        self::assertInstanceOf(TableNames::class, $tables);
        self::assertInstanceOf(TransactionManager::class, $transactions);
        $context = TestKernelFactory::administratorContext($container);
        $principal = $context->principal();
        self::assertNotNull($principal);
        $operationId = 'kill-point-' . Uuid::uuid7()->toString();
        $auditId = Uuid::uuid7()->toString();
        $callbacks = [];
        try {
            $guard->run($context, 'probe.kill', $operationId, [], function () use (
                $database,
                $tables,
                $auditId,
                $transactions,
                &$callbacks,
            ): array {
                $transactions->afterCommit(static function () use (&$callbacks): void {
                    $callbacks[] = 'commit';
                });
                $transactions->afterRollback(static function () use (&$callbacks): void {
                    $callbacks[] = 'rollback';
                });
                $database->insert($tables->raw('audit_events'), [
                    'id' => $auditId,
                    'occurred_at' => new DateTimeImmutable(),
                    'actor_id' => null,
                    'action' => 'probe.kill',
                    'subject_type' => 'test',
                    'subject_id' => null,
                    'outcome' => 'failure',
                    'metadata' => [],
                ], ['occurred_at' => Types::DATETIME_IMMUTABLE, 'metadata' => Types::JSON]);
                throw new \RuntimeException('simulated process failure before completion');
            });
            self::fail('The simulated failure must escape the mutation guard.');
        } catch (\RuntimeException $exception) {
            self::assertSame('simulated process failure before completion', $exception->getMessage());
        }
        self::assertFalse($database->fetchOne(sprintf(
            'SELECT id FROM %s WHERE id = ?',
            $tables->quoted('audit_events'),
        ), [$auditId]));
        self::assertFalse($database->fetchOne(sprintf(
            'SELECT id FROM %s WHERE subject = ? AND operation = ? AND idempotency_key = ?',
            $tables->quoted('idempotency'),
        ), [$principal->subject(), 'mcp.probe.kill', $operationId]));
        self::assertSame(['rollback'], $callbacks);
    }
}
