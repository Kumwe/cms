<?php

declare(strict_types=1);

namespace Kumwe\App\Application\Automation\Job;

use InvalidArgumentException;
use Kumwe\App\Application\Automation\JobHandler;
use Kumwe\App\Application\Authorization\AuthorizationGateway;
use Kumwe\App\Application\Authorization\AuthorizationResource;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\App\BusinessRecord\Application\BusinessRecordIdempotencyPurger;
use Kumwe\Extension\Spi\Identity\Domain\Capability;

/**
 * Bounded retention driver for the business-record command idempotency ledger.
 *
 * The ledger is written by every typed record mutation and is otherwise append-only,
 * so an installation-global schedule owns its expiry. Batching keeps each transaction
 * short enough to avoid blocking concurrent record traffic.
 *
 * @since  2.0.0
 */
final readonly class PurgeBusinessRecordIdempotencyHandler implements JobHandler
{
    /**
     * Largest batch a payload may ask for, matching the bound the purger itself enforces.
     *
     * Rejecting an oversized request here rather than letting the purger raise it keeps the whole
     * payload validated before the first batch runs, so a mistyped schedule fails without deleting
     * anything.
     *
     * @var    int
     * @since  2.0.0
     */
    private const MAXIMUM_BATCH_SIZE = 1000;

    /**
     * Bind the handler to the ledger purger and the gateway that guards it.
     *
     * @param  BusinessRecordIdempotencyPurger  $records        Purger that runs one bounded delete per call.
     * @param  AuthorizationGateway             $authorization  Decides whether the job context may sweep.
     *
     * @since  2.0.0
     */
    public function __construct(
        private BusinessRecordIdempotencyPurger $records,
        private AuthorizationGateway $authorization,
    ) {
    }

    /**
     * Report the job type a schedule or queued job names this handler by.
     *
     * @return  string  The constant `business.record.idempotency.purge`.
     *
     * @since   2.0.0
     */
    public function type(): string
    {
        return 'business.record.idempotency.purge';
    }

    /**
     * Delete expired ledger entries, at most `batch_size` per batch and `maximum_batches` batches.
     *
     * The capability is re-asserted against this job type rather than trusted from whoever created the
     * schedule, because a queued job outlives the request that scheduled it. The loop stops early on
     * the first batch that comes back short, which is the signal that nothing expired is left; a run
     * that exhausts its batch count simply leaves the remainder to the next occurrence.
     *
     * @param   array<string, mixed>  $payload  Optional integer `batch_size` (default 500, at most
     *          1000) and `maximum_batches` (default 10, at most 100).
     * @param   ExecutionContext      $context  System context the automation capability is checked against.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When either limit is not an integer or falls outside its range.
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
        $batchSize = $payload['batch_size'] ?? 500;
        $maximumBatches = $payload['maximum_batches'] ?? 10;
        if (
            !is_int($batchSize)
            || !is_int($maximumBatches)
            || $batchSize < 1
            || $batchSize > self::MAXIMUM_BATCH_SIZE
            || $maximumBatches < 1
            || $maximumBatches > 100
        ) {
            throw new InvalidArgumentException('Business-record idempotency purge limits are invalid.');
        }
        for ($batch = 0; $batch < $maximumBatches; $batch++) {
            if ($this->records->purge($batchSize) < $batchSize) {
                return;
            }
        }
    }
}
