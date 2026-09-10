<?php

declare(strict_types=1);

namespace Kumwe\App\Audit\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use Kumwe\App\Application\Authorization\AuthorizationGateway;
use Kumwe\App\Application\Authorization\AuthorizationResource;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\App\Audit\Application\AuditTrailVerifier;
use Kumwe\App\Audit\Domain\AuditAnchorDigest;
use Kumwe\App\Audit\Domain\AuditEnforcementState;
use Kumwe\App\Audit\Domain\AuditEventDigest;
use Kumwe\App\Audit\Domain\AuditVerificationFinding;
use Kumwe\App\Audit\Domain\AuditVerificationReport;
use Kumwe\Extension\Spi\Identity\Domain\Capability;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use RuntimeException;
use Throwable;

/**
 * Re-derives the audit trail's tamper evidence from the rows the database holds right now.
 *
 * The walk answers four different tampering questions with four different mechanisms, which is why all
 * of them are needed. A **mutated** row fails its own recomputed digest, because the digest covers every
 * evidentiary field of the event. A **deleted** row breaks the witness link of the row that named it,
 * and — once the range has been anchored — changes that range's row count and rolling digest. A
 * **reordered** pair keeps both digests valid but changes the anchored rolling digest, which folds each
 * position together with its digest. An **inserted** row lands inside an anchored range and changes the
 * same two anchored values. Bare position gaps are deliberately *not* treated as evidence: a rolled-back
 * transaction consumes an auto-increment value, so gaps occur in a perfectly intact trail. What settles
 * the question is the anchored count for the range, not the spacing of the numbers.
 *
 * Verification is read-only and stops at the first divergence, because everything after a broken link is
 * no longer reliable evidence. It is safe to run against a live installation: rows appended after the
 * head observed at the start of the pass are simply left to the next one.
 *
 * Every pass also asks the server whether the append-only guards are actually installed, and carries the
 * answer in the report. The question is put to the catalog on each run rather than read from anything
 * the migration recorded, so the report stays true after a dump is restored onto a server that never
 * accepted the triggers, after a DBA grants the privilege that was missing, and after someone drops
 * them. Enforcement is prevention and this walk is detection; a report that only said "intact" would let
 * an installation with no prevention at all look exactly like one that has it.
 *
 * @since  2.0.0
 */
final readonly class DoctrineAuditTrailVerifier implements AuditTrailVerifier
{
    /**
     * How many recent event digests the walk keeps to resolve witness links against.
     *
     * A row links to whichever row was head when it was written, which under concurrency is not always
     * its immediate predecessor. The window absorbs that spread while keeping the walk's memory bounded;
     * a link that resolves to nothing inside it is reported rather than assumed benign.
     *
     * @var    int
     * @since  2.0.0
     */
    private const int LINK_WINDOW = 4096;

    /**
     * Bind the verifier to its connection, table map and authorization gateway.
     *
     * @param  Connection            $database       Connection the audit tables live on.
     * @param  TableNames            $tables         Resolver for prefixed physical table names.
     * @param  AuthorizationGateway  $authorization  Decides whether the caller may verify the trail.
     *
     * @since  2.0.0
     */
    public function __construct(
        private Connection $database,
        private TableNames $tables,
        private AuthorizationGateway $authorization,
    ) {
    }

    /**
     * Walk the anchor ledger and then the trail, reporting the first divergence found.
     *
     * @param   ExecutionContext  $context    Actor the verification is authorized under.
     * @param   int               $batchSize  Rows fetched per batch during the walk, from 1 to 10000.
     *
     * @return  AuditVerificationReport  Counts of what was re-checked, the append-only enforcement state
     *          observed on this server, and the first divergence if any.
     *
     * @throws  InvalidArgumentException  When the batch size is outside its bounds.
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor may not verify
     *          the audit trail.
     *
     * @since   2.0.0
     */
    public function verify(ExecutionContext $context, int $batchSize = 1000): AuditVerificationReport
    {
        $this->authorization->assertAllowed(
            $context,
            Capability::fromString('audit.manage'),
            AuthorizationResource::collection('audit_trail'),
        );
        if ($batchSize < 1 || $batchSize > 10_000) {
            throw new InvalidArgumentException('The audit verification batch size must be between 1 and 10000.');
        }
        $head = AuditLedger::optionalPosition($this->database->fetchOne(sprintf(
            'SELECT MAX(position) FROM %s',
            $this->tables->quoted('audit_events'),
        )), 'trail head position') ?? 0;
        $enforcement = AuditAppendOnlyGuard::state($this->database, $this->tables);
        try {
            $ledger = AuditLedger::all($this->database, $this->tables);
        } catch (RuntimeException $exception) {
            return new AuditVerificationReport(0, 0, $head, $enforcement, new AuditVerificationFinding(
                'anchor.row.malformed',
                0,
                $exception->getMessage(),
            ));
        }
        $ledgerFinding = $this->verifyLedger($ledger);
        if ($ledgerFinding !== null) {
            return new AuditVerificationReport(0, 0, $head, $enforcement, $ledgerFinding);
        }
        $prunedThrough = 0;
        foreach ($ledger as $entry) {
            if ($entry->kind === 'prune') {
                $prunedThrough = max($prunedThrough, $entry->toPosition);
            }
        }
        $rangeFinding = $this->verifyRanges($ledger);
        if ($rangeFinding !== null) {
            return new AuditVerificationReport(0, count($ledger), $head, $enforcement, $rangeFinding);
        }

        return $this->walk($ledger, $prunedThrough, $head, $batchSize, $enforcement);
    }

    /**
     * Check the anchor ledger's own chain: gapless sequence, contiguous ranges, recomputed digests.
     *
     * @param   list<AuditLedgerEntry>  $ledger  Ledger entries ascending by sequence.
     *
     * @return  ?AuditVerificationFinding  The first ledger divergence, or null when the chain holds.
     *
     * @since   2.0.0
     */
    private function verifyLedger(array $ledger): ?AuditVerificationFinding
    {
        $expectedSequence = 1;
        $expected = ['anchor' => 1, 'prune' => 1];
        $sealed = [];
        $previousDigest = null;
        foreach ($ledger as $entry) {
            if ($entry->sequence !== $expectedSequence) {
                return new AuditVerificationFinding(
                    'anchor.sequence.gap',
                    $entry->sequence,
                    sprintf('Anchor sequence %d was expected but %d was stored.', $expectedSequence, $entry->sequence),
                    $entry->id,
                );
            }
            if ($entry->previousDigest !== $previousDigest) {
                return new AuditVerificationFinding(
                    'anchor.chain.broken',
                    $entry->sequence,
                    'The anchor does not link to the digest of the anchor before it.',
                    $entry->id,
                );
            }
            if ($entry->fromPosition !== $expected[$entry->kind] || $entry->toPosition < $entry->fromPosition) {
                return new AuditVerificationFinding(
                    'anchor.range.discontinuous',
                    $entry->sequence,
                    sprintf(
                        'The anchor covers positions %d to %d where %d was expected to start the range.',
                        $entry->fromPosition,
                        $entry->toPosition,
                        $expected[$entry->kind],
                    ),
                    $entry->id,
                );
            }
            if ($entry->kind === 'prune' && !isset($sealed[$entry->toPosition])) {
                return new AuditVerificationFinding(
                    'anchor.prune.unaligned',
                    $entry->sequence,
                    'A prune mark ends somewhere no anchor sealed, so the archived range is unverifiable.',
                    $entry->id,
                );
            }
            $digest = AuditAnchorDigest::compute(
                $entry->sequence,
                $entry->kind,
                $entry->fromPosition,
                $entry->toPosition,
                $entry->rowCount,
                $entry->rollingDigest,
                $entry->previousDigest,
                $entry->archiveSha256,
                $entry->createdAt,
            );
            if (!hash_equals($entry->digest, $digest)) {
                return new AuditVerificationFinding(
                    'anchor.digest.mismatch',
                    $entry->sequence,
                    'The stored anchor digest disagrees with its recomputation.',
                    $entry->id,
                );
            }
            if ($entry->kind === 'anchor') {
                $sealed[$entry->toPosition] = true;
            }
            $previousDigest = $entry->digest;
            $expected[$entry->kind] = $entry->toPosition + 1;
            $expectedSequence++;
        }

        return null;
    }

    /**
     * Re-derive each sealed range against the rows the trail holds now.
     *
     * @param   list<AuditLedgerEntry>  $ledger  Verified ledger entries ascending by sequence.
     *
     * @return  ?AuditVerificationFinding  The first range divergence, or null when every range agrees.
     *
     * @since   2.0.0
     */
    private function verifyRanges(array $ledger): ?AuditVerificationFinding
    {
        $prunedThrough = 0;
        foreach ($ledger as $entry) {
            if ($entry->kind === 'prune') {
                $prunedThrough = max($prunedThrough, $entry->toPosition);
            }
        }
        foreach ($ledger as $entry) {
            if ($entry->kind === 'anchor' && $entry->toPosition <= $prunedThrough) {
                // The range was archived and pruned; its own prune mark carries the evidence now.
                continue;
            }
            try {
                [$count, $rolling] = $this->fold($entry->fromPosition, $entry->toPosition);
            } catch (RuntimeException $exception) {
                return new AuditVerificationFinding(
                    'event.row.malformed',
                    $entry->fromPosition,
                    $exception->getMessage(),
                );
            }
            if ($entry->kind === 'prune') {
                if ($count !== 0) {
                    return new AuditVerificationFinding(
                        'anchor.prune.reinserted',
                        $entry->fromPosition,
                        sprintf('%d rows exist inside the pruned range sealed by anchor %d.', $count, $entry->sequence),
                        $entry->id,
                    );
                }
                continue;
            }
            if ($count !== $entry->rowCount) {
                return new AuditVerificationFinding(
                    'anchor.count.mismatch',
                    $entry->fromPosition,
                    sprintf(
                        'Anchor %d sealed %d rows over positions %d to %d; %d are present now.',
                        $entry->sequence,
                        $entry->rowCount,
                        $entry->fromPosition,
                        $entry->toPosition,
                        $count,
                    ),
                    $entry->id,
                );
            }
            if (!hash_equals($entry->rollingDigest, $rolling)) {
                return new AuditVerificationFinding(
                    'anchor.rolling.mismatch',
                    $entry->fromPosition,
                    sprintf(
                        'The rolling digest of positions %d to %d disagrees with anchor %d.',
                        $entry->fromPosition,
                        $entry->toPosition,
                        $entry->sequence,
                    ),
                    $entry->id,
                );
            }
        }

        return null;
    }

    /**
     * Walk every stored event, recomputing its digest and resolving its witness link.
     *
     * @param   list<AuditLedgerEntry>  $ledger         Verified ledger entries, for the anchored counts.
     * @param   int                     $prunedThrough  Highest position an archived prune mark covers.
     * @param   int                     $head           Highest position present when the pass started.
     * @param   int                     $batchSize      Rows fetched per batch.
     * @param   AuditEnforcementState   $enforcement    Guard state observed at the start of the pass.
     *
     * @return  AuditVerificationReport  Counts re-checked, and the first divergence if one was found.
     *
     * @since   2.0.0
     */
    private function walk(
        array $ledger,
        int $prunedThrough,
        int $head,
        int $batchSize,
        AuditEnforcementState $enforcement,
    ): AuditVerificationReport {
        $anchors = count($ledger);
        $verified = 0;
        $cursor = 0;
        $window = [];
        $first = true;
        while (true) {
            $rows = $this->database->fetchAllAssociative(sprintf(
                'SELECT position, id, occurred_at, actor_id, action, subject_type, subject_id, outcome, '
                . 'metadata, digest, previous_digest FROM %s WHERE position > ? AND position <= ? '
                . 'ORDER BY position ASC LIMIT %d',
                $this->tables->quoted('audit_events'),
                $batchSize,
            ), [$cursor, $head]);
            if ($rows === []) {
                return new AuditVerificationReport($verified, $anchors, $head, $enforcement);
            }
            foreach ($rows as $row) {
                try {
                    $position = AuditLedger::position($row['position'] ?? null, 'event position');
                    $stored = AuditLedger::digest($row['digest'] ?? null, 'event digest');
                    $link = AuditLedger::optionalDigest($row['previous_digest'] ?? null, 'event witness link');
                    $identifier = $this->identifier($row['id'] ?? null);
                    $digest = $this->digestOf($row, $identifier);
                } catch (RuntimeException $exception) {
                    return new AuditVerificationReport(
                        $verified,
                        $anchors,
                        $head,
                        $enforcement,
                        new AuditVerificationFinding(
                            'event.row.malformed',
                            $cursor + 1,
                            $exception->getMessage(),
                        ),
                    );
                }
                $cursor = $position;
                if (!hash_equals($stored, $digest)) {
                    return new AuditVerificationReport(
                        $verified,
                        $anchors,
                        $head,
                        $enforcement,
                        new AuditVerificationFinding(
                            'event.digest.mismatch',
                            $position,
                            'The stored event digest disagrees with its recomputation from the row.',
                            $identifier,
                        ),
                    );
                }
                $archived = $position <= $prunedThrough + 1;
                if ($link === null) {
                    if (!$first && !$archived) {
                        return new AuditVerificationReport(
                            $verified,
                            $anchors,
                            $head,
                            $enforcement,
                            new AuditVerificationFinding(
                                'event.link.missing',
                                $position,
                                'The event carries no witness link although it is not the first of the trail.',
                                $identifier,
                            ),
                        );
                    }
                } elseif (!isset($window[$link]) && !$archived) {
                    return new AuditVerificationReport(
                        $verified,
                        $anchors,
                        $head,
                        $enforcement,
                        new AuditVerificationFinding(
                            'event.link.unresolved',
                            $position,
                            'The event witnesses a predecessor digest no retained earlier row carries.',
                            $identifier,
                        ),
                    );
                }
                $window[$stored] = true;
                if (count($window) > self::LINK_WINDOW) {
                    array_shift($window);
                }
                $first = false;
                $verified++;
            }
        }
    }

    /**
     * Fold one position range into its present row count and rolling digest.
     *
     * @param   int  $from  First position of the range, inclusive.
     * @param   int  $to    Last position of the range, inclusive.
     *
     * @return  array{int, string}  Row count and rolling digest of the range as stored right now.
     *
     * @throws  RuntimeException  When a row in the range carries a malformed position or digest.
     *
     * @since   2.0.0
     */
    private function fold(int $from, int $to): array
    {
        $hash = hash_init('sha256');
        hash_update($hash, AuditAnchorDigest::CHAIN_CONTEXT . "\n");
        $count = 0;
        $cursor = $from - 1;
        do {
            $rows = $this->database->fetchAllAssociative(sprintf(
                'SELECT position, digest FROM %s WHERE position > ? AND position <= ? ORDER BY position ASC LIMIT 1000',
                $this->tables->quoted('audit_events'),
            ), [$cursor, $to]);
            foreach ($rows as $row) {
                $position = AuditLedger::position($row['position'] ?? null, 'anchored row position');
                $digest = AuditLedger::digest($row['digest'] ?? null, 'anchored row digest');
                hash_update($hash, $position . ':' . $digest . "\n");
                $count++;
                $cursor = $position;
            }
        } while (count($rows) === 1000);

        return [$count, hash_final($hash)];
    }

    /**
     * Recompute one stored row's canonical event digest.
     *
     * @param   array<string, mixed>  $row         Associative row as the driver returned it.
     * @param   string                $identifier  Validated canonical UUID of the row.
     *
     * @return  string  Lowercase hexadecimal SHA-256 of the canonical event document.
     *
     * @throws  RuntimeException  When a stored field is unusable.
     *
     * @since   2.0.0
     */
    private function digestOf(array $row, string $identifier): string
    {
        $metadata = $row['metadata'] ?? null;
        if (is_resource($metadata)) {
            $metadata = stream_get_contents($metadata);
        }
        $decoded = [];
        if (is_string($metadata) && $metadata !== '') {
            try {
                $decoded = json_decode($metadata, true, 64, JSON_THROW_ON_ERROR);
            } catch (Throwable $exception) {
                throw new RuntimeException('A stored audit row carries unreadable metadata.', 0, $exception);
            }
        }
        if (!is_array($decoded)) {
            $decoded = [];
        }
        /** @var array<string, mixed> $decoded */
        return AuditEventDigest::compute(
            $identifier,
            AuditLedger::instant($row['occurred_at'] ?? null, 'event occurrence instant'),
            $this->optionalText($row['actor_id'] ?? null),
            $this->text($row['action'] ?? null),
            $this->text($row['subject_type'] ?? null),
            $this->optionalText($row['subject_id'] ?? null),
            $this->text($row['outcome'] ?? null),
            $decoded,
        );
    }

    /**
     * Validate the stored row identifier.
     *
     * @param   mixed  $value  Raw driver value.
     *
     * @return  string  The stored canonical UUID.
     *
     * @throws  RuntimeException  When the identifier is absent or empty.
     *
     * @since   2.0.0
     */
    private function identifier(mixed $value): string
    {
        if (!is_string($value) || $value === '') {
            throw new RuntimeException('A stored audit row carries no identifier.');
        }

        return $value;
    }

    /**
     * Read a mandatory text column from a fetched row.
     *
     * @param   mixed  $value  Raw driver value.
     *
     * @return  string  The stored text.
     *
     * @throws  RuntimeException  When the column does not hold text.
     *
     * @since   2.0.0
     */
    private function text(mixed $value): string
    {
        if (!is_string($value)) {
            throw new RuntimeException('A stored audit row carries an invalid token.');
        }

        return $value;
    }

    /**
     * Read a nullable text column from a fetched row.
     *
     * @param   mixed  $value  Raw driver value.
     *
     * @return  ?string  The stored text, or null when the column is empty.
     *
     * @throws  RuntimeException  When the column holds something other than text or null.
     *
     * @since   2.0.0
     */
    private function optionalText(mixed $value): ?string
    {
        return $value === null ? null : $this->text($value);
    }
}
