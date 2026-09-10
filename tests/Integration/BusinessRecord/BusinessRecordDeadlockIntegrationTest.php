<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\BusinessRecord;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\RetryableException;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Types\Types;
use Kumwe\App\Kernel\Container;
use Kumwe\Sequence\Contract\NumberSequenceAllocator;
use Kumwe\App\BusinessRecord\Infrastructure\Persistence\DoctrineBusinessNumberSequenceAllocator;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Tests\Support\TestKernelFactory;
use Kumwe\Sequence\Exception\NumberSequenceUnavailable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use RuntimeException;

/**
 * Injects a real lock-order deadlock and proves what the runtime makes of it.
 *
 * Nothing in the suite provoked a deadlock before, so the classification path — driver error to
 * `RetryableException`, to the package's `NumberSequenceUnavailable` at the allocator, to
 * `BusinessRecordTemporarilyUnavailable` in the record service, to a 503 carrying `Retry-After` — was
 * asserted only by reading the code. The cycle cannot be built inside one PHP process, because it exists
 * only while both sessions are blocked and a blocked session blocks the interpreter with it; a second
 * operating-system process plays the other half through `tests/Support/deadlock-partner.php`.
 *
 * The seam the bounded retry belongs at is `BusinessRecordService::idempotent()`, and it is already
 * there: three attempts, each a whole transaction. It is the right seam because the idempotency claim,
 * the mutation fence and every write roll back together, so the command that is replayed is the original
 * command and not a fragment of it. A retry any lower — around one statement — would replay a write
 * whose fence had already been released, and a retry any higher would replay authorization.
 *
 * @since  2.0.0
 */
#[CoversClass(DoctrineBusinessNumberSequenceAllocator::class)]
final class BusinessRecordDeadlockIntegrationTest extends TestCase
{
    public function testALockOrderInversionAcrossTwoProcessesIsClassifiedRetryable(): void
    {
        $environment = Environment::fromGlobals();
        $container = TestKernelFactory::create($environment);
        $database = $this->connection($container);
        if ($database->getDatabasePlatform() instanceof SQLitePlatform) {
            self::markTestSkipped(
                'SQLite admits one writer at a time, so two sessions cannot hold a lock apiece and no '
                . 'lock-order cycle can be built for it to detect.',
            );
        }

        [$first, $second] = $this->seedRows($container);
        $directory = $this->handshakeDirectory();
        $partner = $this->spawnPartner($directory, $first, $second);

        try {
            self::assertTrue(
                $this->await($directory . '/partner-holds-second', 15.0),
                'The partner process never took its row; the inversion could not be built.',
            );
            $database->beginTransaction();
            $database->executeStatement(sprintf(
                'UPDATE %s SET current_value = current_value + 1 WHERE id = ?',
                $this->tables($container)->quoted('business_number_sequences'),
            ), [$first], [Types::GUID]);
            file_put_contents($directory . '/test-holds-first', 'held');
            usleep(750_000);

            $caught = null;
            try {
                $database->executeStatement(sprintf(
                    'UPDATE %s SET current_value = current_value + 1 WHERE id = ?',
                    $this->tables($container)->quoted('business_number_sequences'),
                ), [$second], [Types::GUID]);
                $database->commit();
            } catch (\Doctrine\DBAL\Exception $exception) {
                $caught = $exception;
            }

            $partnerOutcome = $this->awaitOutcome($directory, $partner);
            $victims = array_filter([
                $caught instanceof RetryableException ? 'test' : null,
                str_contains($partnerOutcome, 'Deadlock') || str_contains($partnerOutcome, 'LockWaitTimeout')
                    ? 'partner'
                    : null,
            ]);
            self::assertNotSame(
                [],
                $victims,
                sprintf(
                    'The inversion produced no retryable failure on either side; the test saw %s and the '
                    . 'partner reported %s.',
                    $caught === null ? 'success' : $caught::class,
                    $partnerOutcome,
                ),
            );
            if ($caught !== null) {
                self::assertInstanceOf(
                    RetryableException::class,
                    $caught,
                    'A lock-order cycle must reach the application as a retryable driver failure.',
                );
            }
        } finally {
            if ($database->isTransactionActive()) {
                $database->rollBack();
            }
            $this->cleanUp($directory);
        }
    }

    public function testTheAllocatorReportsARetryableFailureAsReplayableRatherThanAsAConflict(): void
    {
        $environment = Environment::fromGlobals();
        $primary = TestKernelFactory::create($environment);
        $database = $this->connection($primary);
        if ($database->getDatabasePlatform() instanceof SQLitePlatform) {
            self::markTestSkipped(
                'SQLite has no row-level lock to wait on, so a bounded lock wait cannot be made to expire.',
            );
        }

        $secondary = TestKernelFactory::create($environment);
        $concurrent = $this->connection($secondary);
        $counter = Uuid::uuid7()->toString();
        $now = new DateTimeImmutable('2026-08-14T12:00:00', new DateTimeZone('UTC'));
        $allocator = $this->allocator($primary);
        $rival = $this->allocator($secondary);
        $this->boundLockWait($concurrent);

        try {
            $database->beginTransaction();
            $allocator->allocate('default', $counter, 'deadlock_probe', '-', '2026', $now);
            $database->commit();

            $database->beginTransaction();
            $allocator->allocate('default', $counter, 'deadlock_probe', '-', '2026', $now);

            $concurrent->beginTransaction();
            $caught = null;
            try {
                $rival->allocate('default', $counter, 'deadlock_probe', '-', '2026', $now);
            } catch (NumberSequenceUnavailable $exception) {
                $caught = $exception;
            }
            self::assertNotNull($caught, 'A blocked allocation must be reported, not silently retried forever.');
            self::assertInstanceOf(
                DriverException::class,
                $caught->getPrevious(),
                'The package refusal keeps the classified driver failure reachable for the retry policy and the log: '
                . 'a lock-wait timeout on MariaDB and MySQL, the lock_timeout refusal on PostgreSQL.',
            );
            $concurrent->rollBack();
        } finally {
            if ($database->isTransactionActive()) {
                $database->rollBack();
            }
            if ($concurrent->isTransactionActive()) {
                $concurrent->rollBack();
            }
            $concurrent->close();
        }
    }

    /**
     * Create the two counter rows the inversion is built over.
     *
     * @return  array{0: string, 1: string}  The two row identifiers, in the order this process takes them.
     */
    private function seedRows(Container $container): array
    {
        $database = $this->connection($container);
        $tables = $this->tables($container);
        $now = new DateTimeImmutable('2026-08-14T12:00:00', new DateTimeZone('UTC'));
        $ids = [];
        foreach (['alpha', 'beta'] as $index => $label) {
            $id = Uuid::uuid7()->toString();
            $database->insert($tables->raw('business_number_sequences'), [
                'id' => $id,
                'site_identifier' => 'default',
                'definition_id' => Uuid::uuid7()->toString(),
                'field_handle' => 'deadlock_' . $label,
                'scope_key' => '-',
                'period_key' => (string) (2100 + $index),
                'current_value' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ], [
                'id' => Types::GUID,
                'definition_id' => Types::GUID,
                'current_value' => Types::BIGINT,
                'created_at' => Types::DATETIME_IMMUTABLE,
                'updated_at' => Types::DATETIME_IMMUTABLE,
            ]);
            $ids[] = $id;
        }

        return [$ids[0], $ids[1]];
    }

    /**
     * Start the partner process that holds the second row and then reaches for the first.
     *
     * @return  resource  The running process handle.
     */
    private function spawnPartner(string $directory, string $first, string $second)
    {
        $command = [
            PHP_BINARY,
            dirname(__DIR__, 3) . '/tests/Support/deadlock-partner.php',
            $directory,
            $first,
            $second,
        ];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            self::fail('The deadlock partner process could not be started.');
        }
        foreach ($pipes as $pipe) {
            stream_set_blocking($pipe, false);
        }

        return $process;
    }

    /**
     * Wait for the partner to record what happened to it, then reap the process.
     *
     * @param   resource  $partner  Handle returned by `spawnPartner()`.
     */
    private function awaitOutcome(string $directory, $partner): string
    {
        $this->await($directory . '/partner-outcome', 20.0);
        $outcome = is_file($directory . '/partner-outcome')
            ? (string) file_get_contents($directory . '/partner-outcome')
            : 'partner-never-reported';
        proc_close($partner);

        return $outcome;
    }

    private function await(string $path, float $seconds): bool
    {
        $deadline = microtime(true) + $seconds;
        while (microtime(true) < $deadline) {
            clearstatcache(true, $path);
            if (is_file($path)) {
                return true;
            }
            usleep(10_000);
        }

        return false;
    }

    private function handshakeDirectory(): string
    {
        $directory = sys_get_temp_dir() . '/kumwe-deadlock-' . bin2hex(random_bytes(8));
        if (!mkdir($directory, 0o700) && !is_dir($directory)) {
            self::fail('The deadlock handshake directory could not be created.');
        }

        return $directory;
    }

    private function cleanUp(string $directory): void
    {
        foreach (glob($directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($directory)) {
            rmdir($directory);
        }
    }

    private function boundLockWait(Connection $connection): void
    {
        $connection->executeStatement(
            $connection->getDatabasePlatform() instanceof AbstractMySQLPlatform
                ? 'SET innodb_lock_wait_timeout = 1'
                : "SET lock_timeout = '500ms'",
        );
    }

    private function allocator(Container $container): NumberSequenceAllocator
    {
        $allocator = $container->get(NumberSequenceAllocator::class);
        if (!$allocator instanceof NumberSequenceAllocator) {
            throw new RuntimeException('The business number sequence allocator is unavailable.');
        }

        return $allocator;
    }

    private function tables(Container $container): TableNames
    {
        $tables = $container->get(TableNames::class);
        if (!$tables instanceof TableNames) {
            throw new RuntimeException('The integration table map is unavailable.');
        }

        return $tables;
    }

    private function connection(Container $container): Connection
    {
        $connection = $container->get(Connection::class);
        if (!$connection instanceof Connection) {
            throw new RuntimeException('The integration connection is unavailable.');
        }

        return $connection;
    }
}
