<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Application\Automation;

use Kumwe\App\Application\Automation\IdempotencyPurger;
use Kumwe\App\Application\Automation\Job\PurgeIdempotencyRecordsHandler;
use Kumwe\Context\Value\SiteContext;
use Kumwe\App\Application\Authorization\SystemIdentity;
use Kumwe\App\Tests\Support\AuthorizationContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PurgeIdempotencyRecordsHandler::class)]
final class PurgeIdempotencyRecordsHandlerTest extends TestCase
{
    public function testProcessesBoundedBatchesUntilTheBacklogIsDrained(): void
    {
        $purger = new CountingIdempotencyPurger([100, 100, 25]);
        $handler = $this->handler($purger);

        $handler->handle(['batch_size' => 100, 'maximum_batches' => 10], $this->context());

        self::assertSame(3, $purger->calls);
    }

    public function testHonoursMaximumBatchLimit(): void
    {
        $purger = new CountingIdempotencyPurger([100, 100, 100]);
        $handler = $this->handler($purger);

        $handler->handle(['batch_size' => 100, 'maximum_batches' => 2], $this->context());

        self::assertSame(2, $purger->calls);
    }

    public function testRejectsAnOrdinaryWorkerPrincipalBeforePurging(): void
    {
        $purger = new CountingIdempotencyPurger([]);

        $this->expectException(\Kumwe\App\Application\Authorization\AuthorizationDenied::class);
        $this->handler($purger)->handle([], AuthorizationContext::system(SystemIdentity::Worker)->context(
            SiteContext::default(),
            'wrong-global-principal',
        ));
    }

    private function context(): \Kumwe\Context\Value\ExecutionContext
    {
        return AuthorizationContext::system(SystemIdentity::InstallationMaintenance)->context(
            SiteContext::default(),
            'idempotency-purge-test',
        );
    }

    private function handler(IdempotencyPurger $purger): PurgeIdempotencyRecordsHandler
    {
        return new PurgeIdempotencyRecordsHandler(
            $purger,
            AuthorizationContext::gateway(),
        );
    }
}

final class CountingIdempotencyPurger implements IdempotencyPurger
{
    public int $calls = 0;

    /** @param list<int> $results */
    public function __construct(private array $results)
    {
    }

    public function purgeExpired(int $batchSize = 1_000): int
    {
        return $this->results[$this->calls++] ?? 0;
    }
}
