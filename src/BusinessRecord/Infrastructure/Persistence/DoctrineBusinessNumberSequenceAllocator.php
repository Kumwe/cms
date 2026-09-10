<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessRecord\Infrastructure\Persistence;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\RetryableException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Types\Types;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\Sequence\Contract\NumberSequenceAllocator;
use Kumwe\Sequence\Exception\NumberSequenceUnavailable;
use LogicException;
use Ramsey\Uuid\Uuid;
use RuntimeException;

/**
 * Gapless document-number allocation over one row of `business_number_sequences`.
 *
 * The mechanism is the one `DoctrineOutboxStore::append()` and `ExtensionRegistryFenceAllocator` already
 * prove on this schema: take the counter row `FOR UPDATE`, then advance it with a compare-and-set that
 * has to affect exactly one row. What makes it a *business* allocator rather than an infrastructure
 * fence is where the transaction comes from — this class opens none. It joins the transaction
 * `BusinessRecordService` already has open around the whole create command, behind the mutation fence
 * and the authorization plan, so the number, the row, the revision and the audit entry commit together
 * or not at all. A create that fails validation, loses an optimistic check or is refused by policy hands
 * its number straight back.
 *
 * The exclusive lock is held from allocation to commit, which is exactly what contiguity costs:
 * concurrent creates against one counter are serialized for the length of the enclosing transaction.
 * The `scope` and `reset` keys on a `core.sequence` field are the operator's lever over that — a
 * per-organization yearly counter contends only with its own branch and its own year.
 *
 * SQLite is the one platform where the `FOR UPDATE` degrades to nothing, because SQLite has no row-level
 * locking. Contiguity survives there for a different reason: SQLite admits one writer at a time, and the
 * compare-and-set is still asserted, so a reader that raced ahead of a committing writer fails its
 * advance and is replayed rather than issuing a duplicate.
 *
 * @since  2.0.0
 */
final readonly class DoctrineBusinessNumberSequenceAllocator implements NumberSequenceAllocator
{
    /**
     * Wire the allocator to the counter table it advances.
     *
     * @param  Connection  $database  Connection whose already-open transaction every allocation joins.
     * @param  TableNames  $tables    Resolver for the prefixed `business_number_sequences` table name.
     *
     * @since  2.0.0
     */
    public function __construct(private Connection $database, private TableNames $tables)
    {
    }

    /**
     * Reserve the next value of one counter, holding it against every other allocator until commit.
     *
     * The counter row is created on first use. Two commands racing to create the *same* counter is the
     * one case the row lock cannot arbitrate, because there is no row to lock yet; the unique index
     * settles it instead, and the loser is reported as temporarily unavailable so the record service
     * replays the whole command against the row the winner committed. That replay is safe precisely
     * because the idempotency claim rolls back with the transaction.
     *
     * @param   string             $siteIdentifier  Site the numbered record belongs to, or the immutable
     *          definition catalog site when the record scope itself carries no site dimension.
     * @param   string             $definitionId    UUID of the definition declaring the sequence field.
     * @param   string             $fieldHandle     Handle of the `core.sequence` field being filled.
     * @param   string             $scopeKey        Tenancy key from `NumberSequenceFormat::counter()`.
     * @param   string             $periodKey       Period key from that same call; empty for a lifetime run.
     * @param   DateTimeImmutable  $now             Instant stamped on the counter row.
     *
     * @return  int  The reserved value, exactly one higher than the last committed allocation.
     *
     * @throws  LogicException  When the caller has no transaction open, which would make the allocation
     *          survive a rolled-back command and tear a hole in the run.
     * @throws  RuntimeException  When the counter row holds something other than a non-negative integer.
     * @throws  NumberSequenceUnavailable  When a concurrent allocator created this counter first, held the
     *          row past this session's lock wait, deadlocked with it, or won the compare-and-set on a
     *          platform without row locks. Every one of those is the same answer to the caller: nothing was
     *          reserved, so replay the command; the driver failure stays chained as the previous exception.
     *
     * @since   2.0.0
     */
    public function allocate(
        string $siteIdentifier,
        string $definitionId,
        string $fieldHandle,
        string $scopeKey,
        string $periodKey,
        DateTimeImmutable $now,
    ): int {
        if (!$this->database->isTransactionActive()) {
            throw new LogicException('Business number allocation requires the command transaction to be open.');
        }
        $coordinates = [$siteIdentifier, $definitionId, $fieldHandle, $scopeKey, $periodKey];

        try {
            $current = $this->locked($coordinates);
            if ($current === null) {
                $this->seed($coordinates, $now);
                $current = 0;
            }
            $next = $current + 1;
            $advanced = $this->database->executeStatement(sprintf(
                'UPDATE %s SET current_value = ?, updated_at = ? WHERE site_identifier = ? AND definition_id = ? '
                . 'AND field_handle = ? AND scope_key = ? AND period_key = ? AND current_value = ?',
                $this->tables->quoted('business_number_sequences'),
            ), [$next, $now, ...$coordinates, $current], [
                Types::BIGINT,
                Types::DATETIME_IMMUTABLE,
                Types::STRING,
                Types::GUID,
                Types::STRING,
                Types::STRING,
                Types::STRING,
                Types::BIGINT,
            ]);
        } catch (DbalException $exception) {
            if (!$this->contention($exception)) {
                throw $exception;
            }
            throw new NumberSequenceUnavailable($exception);
        }
        if ($advanced !== 1) {
            throw new NumberSequenceUnavailable();
        }

        return $next;
    }

    /**
     * Decide whether a driver failure means "someone else holds this counter" rather than "this is broken".
     *
     * DBAL classifies a MySQL lock-wait timeout and either engine's deadlock as `RetryableException`, which
     * is the whole answer on MariaDB and MySQL. PostgreSQL is the exception worth naming: a `lock_timeout`
     * arrives as SQLSTATE `55P03`, which DBAL leaves as a bare `DriverException` even though it means
     * exactly what a MySQL lock-wait timeout means — the row was held elsewhere and this session reserved
     * nothing. Treating it as contention is what keeps the allocator's contract identical across the four
     * engines; every other driver failure is a real fault and is left to escape.
     *
     * @param   DbalException  $exception  Driver failure raised while reading or advancing the counter.
     *
     * @return  bool  True when the failure is contention on the counter row and the command may be replayed.
     *
     * @since   2.0.0
     */
    private function contention(DbalException $exception): bool
    {
        if ($exception instanceof RetryableException || $exception instanceof UniqueConstraintViolationException) {
            return true;
        }

        return $exception instanceof DriverException && $exception->getSQLState() === '55P03';
    }

    /**
     * Read and lock the counter row, or report that it does not exist yet.
     *
     * @param   list<string>  $coordinates  Site, definition, field handle, scope key and period key.
     *
     * @return  ?int  The committed value the run stands at, or null when no counter row exists.
     *
     * @throws  RuntimeException  When the stored value is not a non-negative integer.
     *
     * @since   2.0.0
     */
    private function locked(array $coordinates): ?int
    {
        $stored = $this->database->fetchOne(sprintf(
            'SELECT current_value FROM %s WHERE site_identifier = ? AND definition_id = ? AND field_handle = ? '
            . 'AND scope_key = ? AND period_key = ?%s',
            $this->tables->quoted('business_number_sequences'),
            $this->database->getDatabasePlatform() instanceof SQLitePlatform ? '' : ' FOR UPDATE',
        ), $coordinates, [Types::STRING, Types::GUID, Types::STRING, Types::STRING, Types::STRING]);
        if ($stored === false || $stored === null) {
            return null;
        }
        if (!is_int($stored) && (!is_string($stored) || preg_match('/^[0-9]+$/D', $stored) !== 1)) {
            throw new RuntimeException('A business number sequence holds a value that is not a counter.');
        }

        return (int) $stored;
    }

    /**
     * Create the counter row on its first use, letting the unique index settle a first-use race.
     *
     * @param   list<string>       $coordinates  Site, definition, field handle, scope key and period key.
     * @param   DateTimeImmutable  $now          Instant stamped on the new row.
     *
     * @return  void
     *
     * @throws  DbalException  When the driver refuses the insert, including the unique violation a lost
     *          first-use race raises; `allocate()` classifies it and reports contention as replayable.
     *
     * @since   2.0.0
     */
    private function seed(array $coordinates, DateTimeImmutable $now): void
    {
        [$siteIdentifier, $definitionId, $fieldHandle, $scopeKey, $periodKey] = $coordinates;
        $this->database->insert($this->tables->raw('business_number_sequences'), [
            'id' => Uuid::uuid7()->toString(),
            'site_identifier' => $siteIdentifier,
            'definition_id' => $definitionId,
            'field_handle' => $fieldHandle,
            'scope_key' => $scopeKey,
            'period_key' => $periodKey,
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
    }
}
