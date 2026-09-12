<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Application\Automation;

use DateTimeImmutable;
use InvalidArgumentException;
use Kumwe\App\Application\Automation\Job\PurgeBusinessRecordIdempotencyHandler;
use Kumwe\App\Application\Authorization\AuthorizationDenied;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Context\Value\SiteContext;
use Kumwe\App\Application\Authorization\SystemIdentity;
use Kumwe\Transaction\Testing\ImmediateTransactionManager;
use Kumwe\App\BusinessRecord\Application\BusinessRecordIdempotencyPurger;
use Kumwe\App\BusinessRecord\Application\BusinessRecordIdempotencyRepository;
use Kumwe\App\BusinessRecord\Domain\BusinessRecordIdempotency;
use Kumwe\App\Tests\Support\AuthorizationContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

#[CoversClass(PurgeBusinessRecordIdempotencyHandler::class)]
#[CoversClass(BusinessRecordIdempotencyPurger::class)]
final class PurgeBusinessRecordIdempotencyHandlerTest extends TestCase
{
    public function testDrainsTheBacklogInBoundedBatches(): void
    {
        $entries = new CountingBusinessRecordIdempotencyRepository([500, 500, 12]);

        $this->handler($entries)->handle(['batch_size' => 500, 'maximum_batches' => 10], $this->context());

        self::assertSame(3, $entries->calls);
    }

    public function testStopsAtTheMaximumBatchLimit(): void
    {
        $entries = new CountingBusinessRecordIdempotencyRepository([500, 500, 500, 500]);

        $this->handler($entries)->handle(['batch_size' => 500, 'maximum_batches' => 2], $this->context());

        self::assertSame(2, $entries->calls);
    }

    public function testAppliesItsDefaultsWhenThePayloadIsEmpty(): void
    {
        $entries = new CountingBusinessRecordIdempotencyRepository([3]);

        $this->handler($entries)->handle([], $this->context());

        self::assertSame(1, $entries->calls);
        self::assertSame([500], $entries->limits);
    }

    public function testRejectsABatchSizeBeyondTheRepositoryBound(): void
    {
        $entries = new CountingBusinessRecordIdempotencyRepository([]);

        $this->expectException(InvalidArgumentException::class);
        $this->handler($entries)->handle(['batch_size' => 1_001], $this->context());
    }

    public function testRejectsANonIntegerBatchSize(): void
    {
        $entries = new CountingBusinessRecordIdempotencyRepository([]);

        $this->expectException(InvalidArgumentException::class);
        $this->handler($entries)->handle(['batch_size' => '500'], $this->context());
    }

    public function testRejectsAnOrdinaryWorkerPrincipalBeforePurging(): void
    {
        $entries = new CountingBusinessRecordIdempotencyRepository([]);

        $this->expectException(AuthorizationDenied::class);
        $this->handler($entries)->handle([], AuthorizationContext::system(SystemIdentity::Worker)->context(
            SiteContext::default(),
            'wrong-global-principal',
        ));

        self::assertSame(0, $entries->calls);
    }

    public function testDeclaresTheScheduledJobType(): void
    {
        self::assertSame(
            'business.record.idempotency.purge',
            $this->handler(new CountingBusinessRecordIdempotencyRepository([]))->type(),
        );
    }

    private function context(): ExecutionContext
    {
        return AuthorizationContext::system(SystemIdentity::InstallationMaintenance)->context(
            SiteContext::default(),
            'business-record-idempotency-purge-test',
        );
    }

    private function handler(
        BusinessRecordIdempotencyRepository $entries,
    ): PurgeBusinessRecordIdempotencyHandler {
        return new PurgeBusinessRecordIdempotencyHandler(
            new BusinessRecordIdempotencyPurger(
                $entries,
                new ImmediateTransactionManager(),
                new FixedClock(new DateTimeImmutable('2026-08-08T12:00:00+00:00')),
            ),
            AuthorizationContext::gateway(),
        );
    }
}

final class CountingBusinessRecordIdempotencyRepository implements BusinessRecordIdempotencyRepository
{
    public int $calls = 0;

    /** @var list<int> */
    public array $limits = [];

    /** @param list<int> $results */
    public function __construct(private array $results)
    {
    }

    public function find(string $scopeDigest): ?BusinessRecordIdempotency
    {
        return null;
    }

    public function begin(BusinessRecordIdempotency $entry): void
    {
    }

    public function complete(
        string $id,
        array $result,
        string $resultChecksum,
        DateTimeImmutable $completedAt,
    ): void {
    }

    public function purgeExpired(DateTimeImmutable $now, int $limit): int
    {
        $this->limits[] = $limit;

        return $this->results[$this->calls++] ?? 0;
    }
}

final class FixedClock implements ClockInterface
{
    public function __construct(private readonly DateTimeImmutable $now)
    {
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }
}
