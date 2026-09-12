<?php

declare(strict_types=1);

namespace Kumwe\App\Infrastructure\Mcp;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Types\Types;
use InvalidArgumentException;
use JsonException;
use Kumwe\Transaction\Contract\TransactionManager;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\App\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use Throwable;

/**
 * Fenced MCP mutation lease whose completion is atomic with the protected database mutation.
 *
 * Every write an MCP client makes goes through here, so a tool call that is retried, duplicated by a
 * flaky transport, or replayed by a second client cannot apply twice: the caller's `operationId` is
 * claimed as an idempotency row before the mutation runs, and a repeat either replays the stored result
 * or is refused. The claim is a fence rather than a marker — ownership is re-checked inside the same
 * transaction as the mutation and released by the same statement that stores the result, so an attempt
 * whose lease lapsed and was taken over by another cannot commit its work. A replay is served only to a
 * caller whose canonical input digest and authorization fingerprint still match the ones the claim was
 * made with, which keeps one credential from collecting another's result by guessing its identifier.
 *
 * @since  2.0.0
 */
final readonly class McpMutationGuard
{
    /**
     * Relative offset from now for a fresh lease, after which another attempt may fence this one out.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string LEASE = '+2 minutes';
    /**
     * Relative offset from now until a record stops being replayable and its identifier can be reclaimed.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string RETENTION = '+24 hours';

    /**
     * Bind the guard to the store, table map, clock and transaction scope it fences with.
     *
     * @param  Connection          $database      Connection carrying the shared `idempotency` table.
     * @param  TableNames          $tables        Resolves the physical `idempotency` table name.
     * @param  ClockInterface      $clock         Supplies the instant lease and retention windows run from.
     * @param  TransactionManager  $transactions  Scope the ownership re-check, the mutation and the
     *         result write must share for completion to be atomic.
     *
     * @since  2.0.0
     */
    public function __construct(
        private Connection $database,
        private TableNames $tables,
        private ClockInterface $clock,
        private TransactionManager $transactions,
    ) {
    }

    /**
     * Run a mutation once for this operation identifier and replay its stored result on every repeat.
     *
     * This is the entry point every MCP write is expected to take. A repeat carrying the same arguments
     * under the same credential gets the first run's result back untouched; one whose arguments or
     * authorization state differ, or that arrives while the first attempt still holds a live lease, is
     * refused rather than applied a second time.
     *
     * @template TResult of array<string, mixed>
     *
     * @param   ExecutionContext      $context      Authenticated caller whose subject the claim is
     *          scoped to and whose authorization fingerprint a replay must still match.
     * @param   string                $operation    Tool operation name, stored prefixed with `mcp.`.
     * @param   string                $operationId  Client-chosen idempotency key: 16 to 128 characters,
     *          opening with a letter or digit, then letters, digits, `.`, `_`, `:` or `-`.
     * @param   array<string, mixed>  $input        Arguments the mutation was asked for, hashed
     *          canonically so reusing the identifier with different arguments is refused.
     * @param   callable(): TResult   $mutation     The write to perform, invoked at most once per
     *          identifier and from inside the fenced transaction.
     *
     * @return  TResult  The mutation's own return value on the first run, or the stored copy on a repeat.
     *
     * @throws  InvalidArgumentException  When the context carries no human principal, the identifier is
     *          malformed, or the identifier is already bound to different input or a different credential.
     * @throws  RuntimeException  When another attempt holds a live lease, when this attempt's lease was
     *          lost before completion, or when a stored result is missing or fails its integrity check.
     * @throws  JsonException  When the arguments or the result cannot be encoded, or a stored result
     *          cannot be decoded.
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects one of the claim, lease or completion
     *          statements.
     *
     * @since   2.0.0
     */
    public function run(
        ExecutionContext $context,
        string $operation,
        string $operationId,
        array $input,
        callable $mutation,
    ): array {
        return $this->execute($context, $operation, $operationId, $input, $mutation);
    }

    /**
     * Claim the identifier, run the mutation under that claim, and record the result atomically.
     *
     * The claim is taken before the transaction opens so a competing attempt is turned away without any
     * work being started, then proved again inside it: the completion statement matches on the owner
     * token, the authorization fingerprint and an unexpired lease, so an attempt that was fenced out
     * while its mutation ran fails instead of committing. A failure after the claim is held deletes this
     * attempt's in-progress row, which frees the identifier for an immediate retry rather than pinning it
     * until the lease lapses.
     *
     * @template TResult of array<string, mixed>
     *
     * @param   ExecutionContext      $context      Authenticated caller whose subject the claim is
     *          scoped to and whose authorization fingerprint is written into it.
     * @param   string                $operation    Unprefixed tool operation name; `mcp.` is added here.
     * @param   string                $operationId  Client-chosen idempotency key: 16 to 128 characters,
     *          opening with a letter or digit, then letters, digits, `.`, `_`, `:` or `-`.
     * @param   array<string, mixed>  $input        Arguments the mutation was asked for, hashed
     *          canonically so reusing the identifier with different arguments is refused.
     * @param   callable(): TResult   $mutation     The write to perform, invoked at most once per
     *          identifier and from inside the fenced transaction.
     *
     * @return  TResult  The mutation's own return value on the first run, or the stored copy on a repeat.
     *
     * @throws  InvalidArgumentException  When the context carries no human principal, the identifier is
     *          malformed, or the identifier is already bound to different input or a different credential.
     * @throws  RuntimeException  When another attempt holds a live lease, when this attempt's lease was
     *          lost before completion, or when a stored result is missing or fails its integrity check.
     * @throws  JsonException  When the arguments or the result cannot be encoded, or a stored result
     *          cannot be decoded.
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects one of the claim, lease or completion
     *          statements.
     *
     * @since   2.0.0
     */
    private function execute(
        ExecutionContext $context,
        string $operation,
        string $operationId,
        array $input,
        callable $mutation,
    ): array {
        $principal = AuthenticatedPrincipal::of($context)
            ?? throw new InvalidArgumentException('MCP mutations require a human execution context.');
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{15,127}$/D', $operationId) !== 1) {
            throw new InvalidArgumentException('MCP operationId must be a stable 16 to 128 character identifier.');
        }

        $operation = 'mcp.' . $operation;
        $digest = hash('sha256', $this->canonicalJson($input));
        $owner = Uuid::uuid7()->toString();
        $replay = $this->acquire($context, $principal, $operation, $operationId, $digest, $owner);
        if ($replay !== null) {
            /** @var TResult $replay */
            return $replay;
        }

        try {
            /** @var TResult $result */
            $result = $this->transactions->transactional(function () use (
                $context,
                $principal,
                $operation,
                $operationId,
                $owner,
                $mutation,
            ): array {
                $this->assertLeaseOwner($context, $principal, $operation, $operationId, $owner);
                $result = $mutation();
                $encoded = json_encode(
                    $result,
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                );
                $now = $this->clock->now();
                $affected = $this->database->executeStatement(sprintf(
                    "UPDATE %s SET state = 'completed', owner_token = NULL, locked_until = NULL, "
                    . 'lease_owner = NULL, lease_expires_at = NULL, result_status = 200, result_body = ?, '
                    . 'result_body_digest = ?, completed_at = ? '
                    . 'WHERE subject = ? AND operation = ? AND idempotency_key = ? AND owner_token = ? '
                    . "AND authorization_fingerprint = ? AND state = 'in_progress' AND lease_expires_at > ?",
                    $this->tables->quoted('idempotency'),
                ), [
                    $encoded,
                    hash('sha256', $encoded),
                    $now,
                    $principal->subject(),
                    $operation,
                    $operationId,
                    $owner,
                    $context->authorizationFingerprint(),
                    $now,
                ], [
                    Types::TEXT,
                    Types::STRING,
                    Types::DATETIME_IMMUTABLE,
                    Types::STRING,
                    Types::STRING,
                    Types::STRING,
                    Types::STRING,
                    Types::STRING,
                    Types::DATETIME_IMMUTABLE,
                ]);
                $this->assertUpdated($affected, 'The MCP mutation lease was lost before completion.');

                return $result;
            });

            return $result;
        } catch (Throwable $exception) {
            $this->database->executeStatement(sprintf(
                "DELETE FROM %s WHERE subject = ? AND operation = ? AND idempotency_key = ? "
                . "AND owner_token = ? AND state = 'in_progress'",
                $this->tables->quoted('idempotency'),
            ), [$principal->subject(), $operation, $operationId, $owner]);
            throw $exception;
        }
    }

    /**
     * Take the in-progress claim for an identifier, or work out what an existing claim means.
     *
     * The claim is an insert rather than a read followed by a write, so the unique index settles the
     * race between concurrent attempts. When the insert collides the stored row decides the outcome: a
     * record past its retention window or one still in progress with a lapsed lease is reset onto this
     * attempt, a completed record is replayed, and anything else means a live attempt already owns the
     * identifier and this caller must wait.
     *
     * @param   ExecutionContext        $context      Caller whose authorization fingerprint is written
     *          into a new claim and compared against an existing one.
     * @param   AuthenticatedPrincipal  $principal    Subject the claim is scoped to.
     * @param   string                  $operation    Prefixed operation name the claim is keyed on.
     * @param   string                  $operationId  Idempotency key the claim is keyed on.
     * @param   string                  $digest       Canonical hash of this attempt's arguments.
     * @param   string                  $owner        Token this attempt proves lease ownership with.
     *
     * @return  array<string, mixed>|null  The stored result to hand back as a replay, or null when this
     *          attempt now holds a fresh lease and must run the mutation itself.
     *
     * @throws  InvalidArgumentException  When the identifier is already bound to different arguments, or
     *          to a different credential or authorization state.
     * @throws  RuntimeException  When another attempt holds a live lease, when an expired record changed
     *          while it was being reclaimed, when the record vanished mid-acquisition, or when a stored
     *          result fails its integrity check.
     * @throws  JsonException  When a stored result is not valid JSON.
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects the claim, the lookup or the reset.
     *
     * @since   2.0.0
     */
    private function acquire(
        ExecutionContext $context,
        AuthenticatedPrincipal $principal,
        string $operation,
        string $operationId,
        string $digest,
        string $owner,
    ): ?array {
        $now = $this->clock->now();
        try {
            $this->insert($context, $principal, $operation, $operationId, $digest, $owner, $now);
            return null;
        } catch (UniqueConstraintViolationException) {
            $row = $this->record($principal, $operation, $operationId);
            if ($this->time($row, 'expires_at') <= $now) {
                if ($this->reset($context, $principal, $operation, $operationId, $digest, $owner, $now, true)) {
                    return null;
                }
                throw new RuntimeException('The expired MCP operation changed while it was being acquired.');
            }

            $storedDigest = $this->string($row, 'request_digest');
            if (!hash_equals($storedDigest, $digest)) {
                throw new InvalidArgumentException('The MCP operationId was already used with different input.');
            }
            if (
                !hash_equals(
                    $this->string($row, 'authorization_fingerprint'),
                    $context->authorizationFingerprint(),
                )
            ) {
                throw new InvalidArgumentException(
                    'The MCP operationId belongs to a different credential or authorization state.',
                );
            }
            if (($row['state'] ?? null) === 'completed') {
                return $this->decodeResult($row);
            }
            if (
                ($row['state'] ?? null) === 'in_progress'
                && $this->time($row, 'lease_expires_at') <= $now
                && $this->reset($context, $principal, $operation, $operationId, $digest, $owner, $now, false)
            ) {
                return null;
            }

            throw new McpToolRefusal(
                'operation.in_progress',
                'The requested operation is still in progress.',
                true,
            );
        }
    }

    /**
     * Write the in-progress row that makes this attempt the lease owner.
     *
     * The unique index over subject, operation and idempotency key is what turns this insert into the
     * arbitration point: a collision is how the caller learns the identifier is already spoken for,
     * without a read that another attempt could slip past.
     *
     * @param   ExecutionContext        $context      Caller whose authorization fingerprint is stored
     *          alongside the claim.
     * @param   AuthenticatedPrincipal  $principal    Subject the record is scoped to.
     * @param   string                  $operation    Prefixed operation name to key the record on.
     * @param   string                  $operationId  Idempotency key to key the record on.
     * @param   string                  $digest       Canonical hash of this attempt's arguments, kept for
     *          comparison against any later attempt.
     * @param   string                  $owner        Token this attempt proves lease ownership with.
     * @param   DateTimeImmutable       $now          Instant the lease and retention windows run from.
     *
     * @return  void
     *
     * @throws  UniqueConstraintViolationException  When a record already exists for this subject,
     *          operation and identifier.
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects the insert for any other reason.
     *
     * @since   2.0.0
     */
    private function insert(
        ExecutionContext $context,
        AuthenticatedPrincipal $principal,
        string $operation,
        string $operationId,
        string $digest,
        string $owner,
        DateTimeImmutable $now,
    ): void {
        $leaseUntil = $now->modify(self::LEASE);
        $this->database->insert($this->tables->raw('idempotency'), [
            'id' => Uuid::uuid7()->toString(),
            'idempotency_key' => $operationId,
            'subject' => $principal->subject(),
            'operation' => $operation,
            'request_digest' => $digest,
            'authorization_fingerprint' => $context->authorizationFingerprint(),
            'state' => 'in_progress',
            'owner_token' => $owner,
            'locked_until' => $leaseUntil,
            'lease_owner' => $owner,
            'lease_expires_at' => $leaseUntil,
            'attempt' => 1,
            'created_at' => $now,
            'expires_at' => $now->modify(self::RETENTION),
        ], [
            'locked_until' => Types::DATETIME_IMMUTABLE,
            'lease_expires_at' => Types::DATETIME_IMMUTABLE,
            'created_at' => Types::DATETIME_IMMUTABLE,
            'expires_at' => Types::DATETIME_IMMUTABLE,
        ]);
    }

    /**
     * Reclaim an abandoned record for this attempt and bump its attempt counter.
     *
     * The predicate that decides whether the record may be taken lives in the `WHERE` clause rather than
     * in a preceding read, so two attempts racing to recover the same record cannot both win — the loser
     * learns that from an affected-row count of zero. Every result field is cleared in the same
     * statement, so a stale body from the previous attempt can never be replayed against the new one.
     *
     * @param   ExecutionContext        $context      Caller whose authorization fingerprint replaces the
     *          stored one.
     * @param   AuthenticatedPrincipal  $principal    Subject the record is scoped to.
     * @param   string                  $operation    Prefixed operation name the record is keyed on.
     * @param   string                  $operationId  Idempotency key the record is keyed on.
     * @param   string                  $digest       Canonical hash of this attempt's arguments.
     * @param   string                  $owner        Token this attempt takes the lease with.
     * @param   DateTimeImmutable       $now          Instant the new lease and retention windows run from.
     * @param   bool                    $expired      True to reclaim on the retention window having
     *          closed, false to reclaim an in-progress record whose lease has lapsed.
     *
     * @return  bool  True when this attempt took the record over; false when another attempt got there
     *          first and the row no longer satisfied the condition.
     *
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects the update.
     *
     * @since   2.0.0
     */
    private function reset(
        ExecutionContext $context,
        AuthenticatedPrincipal $principal,
        string $operation,
        string $operationId,
        string $digest,
        string $owner,
        DateTimeImmutable $now,
        bool $expired,
    ): bool {
        $condition = $expired ? 'expires_at <= ?' : "state = 'in_progress' AND lease_expires_at <= ?";
        $leaseUntil = $now->modify(self::LEASE);
        $affected = $this->database->executeStatement(sprintf(
            "UPDATE %s SET request_digest = ?, authorization_fingerprint = ?, state = 'in_progress', "
            . 'owner_token = ?, locked_until = ?, lease_owner = ?, lease_expires_at = ?, attempt = attempt + 1, '
            . 'result_status = NULL, result_body = NULL, result_body_digest = NULL, completed_at = NULL, '
            . 'created_at = ?, expires_at = ? WHERE subject = ? AND operation = ? AND idempotency_key = ? AND %s',
            $this->tables->quoted('idempotency'),
            $condition,
        ), [
            $digest,
            $context->authorizationFingerprint(),
            $owner,
            $leaseUntil,
            $owner,
            $leaseUntil,
            $now,
            $now->modify(self::RETENTION),
            $principal->subject(),
            $operation,
            $operationId,
            $now,
        ], [
            Types::STRING,
            Types::STRING,
            Types::STRING,
            Types::DATETIME_IMMUTABLE,
            Types::STRING,
            Types::DATETIME_IMMUTABLE,
            Types::DATETIME_IMMUTABLE,
            Types::DATETIME_IMMUTABLE,
            Types::STRING,
            Types::STRING,
            Types::STRING,
            Types::DATETIME_IMMUTABLE,
        ]);

        return (string) $affected === '1';
    }

    /**
     * Fail unless this attempt still holds a live, unchanged lease on the record.
     *
     * This is the first statement inside the transaction and locks the row with `FOR UPDATE` on every
     * platform but SQLite, whose single-writer model makes the row lock redundant. Re-checking ownership,
     * authorization fingerprint and lease expiry here is what stops a fenced-out attempt before its
     * mutation touches anything, rather than after.
     *
     * @param   ExecutionContext        $context      Caller whose authorization fingerprint must still
     *          match the one stored with the claim.
     * @param   AuthenticatedPrincipal  $principal    Subject the record is scoped to.
     * @param   string                  $operation    Prefixed operation name the record is keyed on.
     * @param   string                  $operationId  Idempotency key the record is keyed on.
     * @param   string                  $owner        Token this attempt claimed the lease with.
     *
     * @return  void
     *
     * @throws  RuntimeException  When the record is gone, owned by another attempt, no longer in
     *          progress, bound to a different authorization state, or past its lease expiry.
     * @throws  \Doctrine\DBAL\Exception  When the platform cannot be resolved or the driver rejects the
     *          locking read.
     *
     * @since   2.0.0
     */
    private function assertLeaseOwner(
        ExecutionContext $context,
        AuthenticatedPrincipal $principal,
        string $operation,
        string $operationId,
        string $owner,
    ): void {
        $lock = $this->database->getDatabasePlatform() instanceof SQLitePlatform ? '' : ' FOR UPDATE';
        $row = $this->database->fetchAssociative(sprintf(
            'SELECT owner_token, authorization_fingerprint, state, lease_expires_at FROM %s '
            . 'WHERE subject = ? AND operation = ? AND idempotency_key = ?%s',
            $this->tables->quoted('idempotency'),
            $lock,
        ), [$principal->subject(), $operation, $operationId]);
        if (
            $row === false
            || ($row['owner_token'] ?? null) !== $owner
            || ($row['state'] ?? null) !== 'in_progress'
            || !hash_equals($this->string($row, 'authorization_fingerprint'), $context->authorizationFingerprint())
            || $this->time($row, 'lease_expires_at') <= $this->clock->now()
        ) {
            throw new RuntimeException('The MCP mutation lease is no longer owned by this request.');
        }
    }

    /**
     * Read the claim an identifier already holds, after the insert that collided with it.
     *
     * @param   AuthenticatedPrincipal  $principal    Subject the record is scoped to.
     * @param   string                  $operation    Prefixed operation name the record is keyed on.
     * @param   string                  $operationId  Idempotency key the record is keyed on.
     *
     * @return  array<string, mixed>  The row keyed by column name, carrying the request digest,
     *          authorization fingerprint, state, stored result and both expiry instants.
     *
     * @throws  RuntimeException  When no row is found, meaning it was purged between the failed insert
     *          and this read.
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects the read.
     *
     * @since   2.0.0
     */
    private function record(AuthenticatedPrincipal $principal, string $operation, string $operationId): array
    {
        $row = $this->database->fetchAssociative(sprintf(
            'SELECT request_digest, authorization_fingerprint, state, result_body, result_body_digest, '
            . 'lease_expires_at, expires_at FROM %s '
            . 'WHERE subject = ? AND operation = ? AND idempotency_key = ?',
            $this->tables->quoted('idempotency'),
        ), [$principal->subject(), $operation, $operationId]);
        if ($row === false) {
            throw new RuntimeException('The MCP idempotency record disappeared during acquisition.');
        }

        /** @var array<string, mixed> $row */
        return $row;
    }

    /**
     * Decode a completed record's stored result once it has been checked against its digest.
     *
     * The digest comparison is not caution about JSON parsing: this body is what every later repeat of
     * the operation receives, so one that was truncated or edited behind Kumwe's back must be refused
     * rather than handed back as though the original caller had seen it.
     *
     * @param   array<string, mixed>  $row  Completed record as read from the idempotency table.
     *
     * @return  array<string, mixed>  The stored result, always a string-keyed map.
     *
     * @throws  RuntimeException  When the body is missing, does not match its stored digest, or decodes
     *          to a list or a non-array.
     * @throws  JsonException  When the stored body is not valid JSON.
     *
     * @since   2.0.0
     */
    private function decodeResult(array $row): array
    {
        $body = $this->string($row, 'result_body');
        if (!hash_equals($this->string($row, 'result_body_digest'), hash('sha256', $body))) {
            throw new RuntimeException('The stored MCP operation result failed its integrity check.');
        }
        $result = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($result) || array_is_list($result)) {
            throw new RuntimeException('The stored MCP operation result is invalid.');
        }

        /** @var array<string, mixed> $result */
        return $result;
    }

    /**
     * Read one column of an idempotency row as a non-empty string.
     *
     * @param   array<string, mixed>  $row    Record as read from the idempotency table.
     * @param   string                $field  Column name to read.
     *
     * @return  string  The column value, guaranteed non-empty.
     *
     * @throws  RuntimeException  When the column is absent, is not a string, or is empty.
     *
     * @since   2.0.0
     */
    private function string(array $row, string $field): string
    {
        $value = $row[$field] ?? null;
        if (!is_string($value) || $value === '') {
            throw new RuntimeException(sprintf('The MCP idempotency field %s is invalid.', $field));
        }

        return $value;
    }

    /**
     * Read one column of an idempotency row as an instant.
     *
     * Drivers differ over whether a timestamp column arrives already hydrated, so a raw string is parsed
     * here instead of at each comparison against the clock.
     *
     * @param   array<string, mixed>  $row    Record as read from the idempotency table.
     * @param   string                $field  Column name to read.
     *
     * @return  DateTimeImmutable  The column value as an instant.
     *
     * @throws  RuntimeException  When the column is absent, or is neither an instant nor a string.
     * @throws  \DateMalformedStringException  When the string cannot be read as a date.
     *
     * @since   2.0.0
     */
    private function time(array $row, string $field): DateTimeImmutable
    {
        $value = $row[$field] ?? null;
        if ($value instanceof DateTimeImmutable) {
            return $value;
        }
        if (!is_string($value)) {
            throw new RuntimeException(sprintf('The MCP idempotency field %s is invalid.', $field));
        }

        return new DateTimeImmutable($value);
    }

    /**
     * Fail unless a statement affected exactly one row.
     *
     * Drivers report the affected count as an int or as a numeric string, so both are compared in string
     * form rather than cast.
     *
     * @param   int|string  $affected  Row count reported by the statement that was just executed.
     * @param   string      $message   Operator-facing sentence to fail with.
     *
     * @return  void
     *
     * @throws  RuntimeException  When the count is anything other than one.
     *
     * @since   2.0.0
     */
    private function assertUpdated(int|string $affected, string $message): void
    {
        if ((string) $affected !== '1') {
            throw new RuntimeException($message);
        }
    }

    /**
     * Encode request arguments in a stable form so identical arguments always hash identically.
     *
     * Every object-shaped map is key-sorted recursively while list order is retained, so semantically
     * identical nested arguments hash alike across clients without treating an ordered list as a set.
     *
     * @param   array<string, mixed>  $input  Request arguments as the tool handler collected them.
     *
     * @return  string  Canonical JSON, ready to hash.
     *
     * @throws  JsonException  When the arguments contain a value that cannot be encoded.
     *
     * @since   2.0.0
     */
    private function canonicalJson(array $input): string
    {
        return json_encode(
            $this->canonicalValue($input),
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
    }

    /**
     * Recursively order map keys while preserving list order and scalar types.
     *
     * @param   mixed  $value  Decoded MCP argument value.
     *
     * @return  mixed  Canonical array structure or the original scalar.
     *
     * @since   2.0.0
     */
    private function canonicalValue(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map($this->canonicalValue(...), $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalValue($item);
        }

        return $value;
    }
}
