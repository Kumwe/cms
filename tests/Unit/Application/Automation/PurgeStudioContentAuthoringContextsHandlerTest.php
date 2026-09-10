<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Application\Automation;

use InvalidArgumentException;
use Kumwe\App\Application\Authorization\AuthorizationDenied;
use Kumwe\App\Application\Authorization\SystemIdentity;
use Kumwe\App\Application\Automation\Job\PurgeStudioContentAuthoringContextsHandler;
use Kumwe\App\Studio\Application\Authoring\ContentStudioAuthoringContextPurger;
use Kumwe\App\Tests\Support\AuthorizationContext;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Context\Value\SiteContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the authorization and bounded-loop policy of Studio context retention.
 *
 * @since  2.0.0
 */
#[CoversClass(PurgeStudioContentAuthoringContextsHandler::class)]
final class PurgeStudioContentAuthoringContextsHandlerTest extends TestCase
{
    /**
     * Full batches continue until one short result proves the observed backlog drained.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testProcessesBoundedBatchesUntilTheBacklogIsDrained(): void
    {
        $purger = new CountingStudioContentAuthoringContextPurger([100, 100, 25]);
        $handler = $this->handler($purger);

        self::assertSame('studio.content-authoring-context.purge', $handler->type());
        $handler->handle(['batch_size' => 100, 'maximum_batches' => 10], $this->context());

        self::assertSame([100, 100, 100], $purger->batchSizes);
    }

    /**
     * A full backlog cannot make one scheduled occurrence exceed its declared batch budget.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testHonoursTheMaximumBatchLimit(): void
    {
        $purger = new CountingStudioContentAuthoringContextPurger([100, 100, 100]);

        $this->handler($purger)->handle(
            ['batch_size' => 100, 'maximum_batches' => 2],
            $this->context(),
        );

        self::assertSame([100, 100], $purger->batchSizes);
    }

    /**
     * Every malformed limit fails before the retention port receives its first call.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRejectsMalformedLimitsBeforePurging(): void
    {
        $cases = [
            ['batch_size' => '1000'],
            ['batch_size' => 0],
            ['batch_size' => 10_001],
            ['maximum_batches' => 0],
            ['maximum_batches' => 101],
        ];

        foreach ($cases as $payload) {
            $purger = new CountingStudioContentAuthoringContextPurger([]);
            try {
                $this->handler($purger)->handle($payload, $this->context());
                self::fail('Malformed Studio context retention limits were accepted.');
            } catch (InvalidArgumentException $exception) {
                self::assertSame(
                    'Studio Content authoring context purge limits are invalid.',
                    $exception->getMessage(),
                );
                self::assertSame([], $purger->batchSizes);
            }
        }
    }

    /**
     * An ordinary queue worker cannot exercise an installation-wide maintenance authority.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRejectsAnOrdinaryWorkerPrincipalBeforePurging(): void
    {
        $purger = new CountingStudioContentAuthoringContextPurger([]);

        try {
            $this->handler($purger)->handle([], AuthorizationContext::system(SystemIdentity::Worker)->context(
                SiteContext::default(),
                'studio-context-purge-wrong-principal',
            ));
            self::fail('An ordinary worker principal purged Studio Content authoring contexts.');
        } catch (AuthorizationDenied) {
            self::assertSame([], $purger->batchSizes);
        }
    }

    /**
     * Build the handler with the production authorization policy set.
     *
     * @param   ContentStudioAuthoringContextPurger  $purger  Retention spy receiving batch sizes.
     *
     * @return  PurgeStudioContentAuthoringContextsHandler  Handler under test.
     *
     * @since   2.0.0
     */
    private function handler(
        ContentStudioAuthoringContextPurger $purger,
    ): PurgeStudioContentAuthoringContextsHandler {
        return new PurgeStudioContentAuthoringContextsHandler($purger, AuthorizationContext::gateway());
    }

    /**
     * Build the only internal principal permitted to run global retention.
     *
     * @return  ExecutionContext  Installation-maintenance execution context.
     *
     * @since   2.0.0
     */
    private function context(): ExecutionContext
    {
        return AuthorizationContext::system(SystemIdentity::InstallationMaintenance)->context(
            SiteContext::default(),
            'studio-content-authoring-context-purge-test',
        );
    }
}

/**
 * Records bounded retention calls and returns a scripted backlog sequence.
 *
 * @since  2.0.0
 */
final class CountingStudioContentAuthoringContextPurger implements ContentStudioAuthoringContextPurger
{
    /**
     * Batch sizes received in order.
     *
     * @var    list<int>
     * @since  2.0.0
     */
    public array $batchSizes = [];

    /**
     * Store the affected-row count returned by each pass.
     *
     * @param   list<int>  $results  Scripted affected-row counts.
     *
     * @since   2.0.0
     */
    public function __construct(private array $results)
    {
    }

    /**
     * Record one batch request and return its scripted count.
     *
     * @param   int  $batchSize  Requested pass limit.
     *
     * @return  int  Scripted affected-row count, or zero after the script drains.
     *
     * @since   2.0.0
     */
    public function purgeExpired(int $batchSize = 1_000): int
    {
        $this->batchSizes[] = $batchSize;

        return array_shift($this->results) ?? 0;
    }
}
