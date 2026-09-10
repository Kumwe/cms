<?php

declare(strict_types=1);

namespace Kumwe\App\Application\Automation\Job;

use Kumwe\App\Application\Automation\IdempotencyPurger;
use Kumwe\App\Application\Automation\JobHandler;
use Kumwe\App\Application\Authorization\AuthorizationGateway;
use Kumwe\App\Application\Authorization\AuthorizationResource;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Extension\Spi\Identity\Domain\Capability;

/**
 * Scheduled job that drains the expired HTTP idempotency ledger in bounded batches.
 *
 * Every replayable API write leaves a row that must outlive its retry window and may then be deleted,
 * so the ledger only stays a fixed size if something sweeps it on a schedule. Deleting the whole
 * backlog in one statement would hold locks across live request traffic, so the run is split into
 * batches and stops as soon as a batch comes back short — the backlog is drained, and whatever arrives
 * afterwards is the next run's work. The job type is installation-global: it sweeps the shared ledger
 * once per installation under the maintenance system principal, not once per site.
 *
 * @since  2.0.0
 */
final readonly class PurgeIdempotencyRecordsHandler implements JobHandler
{
    /**
     * Bind the handler to the ledger purger and the gateway that guards it.
     *
     * @param  IdempotencyPurger     $records        Purger that owns the expiry predicate and the deletes.
     * @param  AuthorizationGateway  $authorization  Decides whether the job context may run the sweep.
     *
     * @since  2.0.0
     */
    public function __construct(
        private IdempotencyPurger $records,
        private AuthorizationGateway $authorization,
    ) {
    }

    /**
     * Report the job type a schedule or queued job names this handler by.
     *
     * @return  string  The constant `system.idempotency.purge`.
     *
     * @since   2.0.0
     */
    public function type(): string
    {
        return 'system.idempotency.purge';
    }

    /**
     * Delete expired idempotency records, at most `batch_size` per batch and `maximum_batches` batches.
     *
     * The capability is re-asserted against this job type rather than trusted from whoever created the
     * schedule, because a queued job outlives the request that scheduled it. Only the batch count is
     * bounded here; the batch size is passed straight through, and the purger refuses a value it
     * cannot honour before deleting anything.
     *
     * @param   array<string, mixed>  $payload  Optional integer `batch_size` (default 1000) and
     *          `maximum_batches` (default 10).
     * @param   ExecutionContext      $context  System context the automation capability is checked against.
     *
     * @return  void
     *
     * @throws  \InvalidArgumentException  When either limit is not an integer, when the batch count is
     *          outside 1 to 100, or when the purger rejects the batch size.
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the job context may not
     *          manage this installation-wide job type.
     *
     * @since   2.0.0
     */
    public function handle(array $payload, ExecutionContext $context): void
    {
        $this->authorization->assertAllowed(
            $context,
            Capability::fromString('automation.manage'),
            AuthorizationResource::item('automation_installation', $this->type()),
        );
        $batchSize = $payload['batch_size'] ?? 1_000;
        $maximumBatches = $payload['maximum_batches'] ?? 10;
        if (!is_int($batchSize) || !is_int($maximumBatches) || $maximumBatches < 1 || $maximumBatches > 100) {
            throw new \InvalidArgumentException('Idempotency purge limits are invalid.');
        }
        for ($batch = 0; $batch < $maximumBatches; $batch++) {
            if ($this->records->purgeExpired($batchSize) < $batchSize) {
                return;
            }
        }
    }
}
