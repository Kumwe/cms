<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessRecord\Application;

use InvalidArgumentException;
use Kumwe\Transaction\Contract\TransactionManager;
use Psr\Clock\ClockInterface;

/**
 * Bounded database retention entry point for the command idempotency ledger.
 *
 * Every typed record mutation writes a ledger entry so a retried command replays its stored result
 * instead of running twice, which means the table only ever grows until something deletes what has
 * expired. This class is that something: it owns the clock and the transaction, leaving the repository
 * to own the delete statement, and it refuses an unbounded batch so a sweep can never hold a long
 * write transaction against live record traffic. `PurgeBusinessRecordIdempotencyHandler` drives it on a
 * schedule, calling it repeatedly rather than asking for one large batch.
 *
 * @since  2.0.0
 */
final readonly class BusinessRecordIdempotencyPurger
{
    /**
     * Bind the purger to the ledger, the transaction scope, and the clock that dates expiry.
     *
     * @param  BusinessRecordIdempotencyRepository  $entries       Ledger the expired rows are deleted from.
     * @param  TransactionManager                   $transactions  Opens the transaction each batch needs.
     * @param  ClockInterface                       $clock         Supplies the instant expiry is measured
     *         against.
     *
     * @since  2.0.0
     */
    public function __construct(
        private BusinessRecordIdempotencyRepository $entries,
        private TransactionManager $transactions,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Delete one bounded batch of ledger entries that expired at or before now.
     *
     * The batch runs in its own transaction, so a caller sweeping repeatedly commits each batch before
     * starting the next. A return below `$limit` means the ledger held nothing more to expire at the
     * instant the batch read it, which is the signal for a driving loop to stop early.
     *
     * @param   int  $limit  Most entries to delete in this batch, between 1 and 1000.
     *
     * @return  int  Number of entries actually deleted.
     *
     * @throws  InvalidArgumentException  When the batch size falls outside the range 1 to 1000.
     *
     * @since   2.0.0
     */
    public function purge(int $limit = 500): int
    {
        if ($limit < 1 || $limit > 1000) {
            throw new InvalidArgumentException('The idempotency purge batch must contain between 1 and 1000 entries.');
        }

        return $this->transactions->transactional(
            fn (): int => $this->entries->purgeExpired($this->clock->now(), $limit),
        );
    }
}
