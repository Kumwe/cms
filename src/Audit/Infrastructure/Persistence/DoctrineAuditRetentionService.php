<?php

declare(strict_types=1);

namespace Kumwe\App\Audit\Infrastructure\Persistence;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use InvalidArgumentException;
use Kumwe\App\Application\Authorization\AuthorizationGateway;
use Kumwe\App\Application\Authorization\AuthorizationResource;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\App\Application\Persistence\TransactionManager;
use Kumwe\App\Audit\Application\AuditRecorder;
use Kumwe\App\Audit\Application\AuditRetentionResult;
use Kumwe\App\Audit\Application\AuditRetentionService;
use Kumwe\App\Audit\Application\AuditTrailExporter;
use Kumwe\App\Audit\Domain\AuditAnchorDigest;
use Kumwe\App\Audit\Domain\AuditEvent;
use Kumwe\Extension\Spi\Identity\Domain\Capability;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use RuntimeException;

/**
 * Archives and then prunes audit rows that have aged past a configured retention window.
 *
 * Retention here is a transformation of evidence, never its destruction. A pass only considers rows that
 * are already sealed by an anchor, and it prunes whole anchored ranges rather than an arbitrary cut, so
 * the archived range is exactly a range some anchor already fixed a count and a rolling digest for. The
 * order is fixed and fails closed at every step: export the range to a checksummed private archive,
 * chain a `prune` mark carrying that archive's checksum and the range's rolling digest into the anchor
 * ledger, delete the rows through the one guarded window the append-only triggers accept, and record the
 * prune as an audit event of its own. All four happen in one transaction, so a failure anywhere leaves
 * the trail exactly as it was. A window of zero is not a configuration this class accepts — the job that
 * drives it simply does not call when retention is unconfigured, which is why an installation that never
 * sets a window keeps its trail forever.
 *
 * None of that order depends on the triggers actually being installed. On a server that refused them the
 * window `AuditAppendOnlyGuard::withPruneAllowed()` opens is a no-op, and every step that carries the
 * evidence — the archive, the checksum, the chained prune mark, the row count reconciliation — runs
 * exactly as it does on a guarded server. Retention is therefore correct in both postures, and the
 * post-delete count check still fails the pass closed if the range was not removed exactly.
 *
 * @since  2.0.0
 */
final readonly class DoctrineAuditRetentionService implements AuditRetentionService
{
    /**
     * Bind the retention service to its persistence, exporter, audit and authorization collaborators.
     *
     * @param  Connection            $database       Connection the audit tables live on.
     * @param  TableNames            $tables         Resolver for prefixed physical table names.
     * @param  TransactionManager    $transactions   Commits archive, mark, delete and event as one unit.
     * @param  AuditTrailExporter    $exporter       Writes the protected archive the prune preserves.
     * @param  AuditRecorder         $audit          Trail the prune itself is recorded in.
     * @param  ClockInterface        $clock          Supplies the cutoff and the prune instant.
     * @param  AuthorizationGateway  $authorization  Decides whether the caller may manage the trail.
     *
     * @since  2.0.0
     */
    public function __construct(
        private Connection $database,
        private TableNames $tables,
        private TransactionManager $transactions,
        private AuditTrailExporter $exporter,
        private AuditRecorder $audit,
        private ClockInterface $clock,
        private AuthorizationGateway $authorization,
    ) {
    }

    /**
     * Archive and prune every anchored audit row older than the retention window.
     *
     * @param   ExecutionContext  $context        Actor the pass is authorized and audited under.
     * @param   int               $retentionDays  Window in days; rows older than this become prunable.
     *
     * @return  AuditRetentionResult  What was archived and pruned, with its ledger and archive evidence.
     *
     * @throws  InvalidArgumentException  When the window is not a positive number of days.
     * @throws  RuntimeException  When the guarded delete does not remove exactly the archived range.
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor may not manage
     *          the audit trail.
     *
     * @since   2.0.0
     */
    public function prune(ExecutionContext $context, int $retentionDays): AuditRetentionResult
    {
        $this->authorization->assertAllowed(
            $context,
            Capability::fromString('audit.manage'),
            AuthorizationResource::collection('audit_trail'),
        );
        if ($retentionDays < 1 || $retentionDays > 36_500) {
            throw new InvalidArgumentException('The audit retention window must be between 1 and 36500 days.');
        }
        $now = $this->clock->now();
        $cutoff = $now->modify(sprintf('-%d days', $retentionDays));

        return $this->transactions->transactional(function () use ($context, $now, $cutoff): AuditRetentionResult {
            $from = AuditLedger::boundary($this->database, $this->tables, 'prune') + 1;
            $to = $this->prunableThrough($cutoff);
            if ($to === null || $to < $from) {
                return new AuditRetentionResult(0);
            }
            $export = $this->exporter->export($context, $from, $to);
            [$count, $rolling] = $this->fold($from, $to);
            $tail = AuditLedger::tail($this->database, $this->tables);
            $sequence = ($tail->sequence ?? 0) + 1;
            $markId = Uuid::uuid7()->toString();
            $createdAt = $now->format('Y-m-d H:i:s');
            $this->database->insert($this->tables->raw('audit_anchors'), [
                'id' => $markId,
                'sequence' => $sequence,
                'kind' => 'prune',
                'from_position' => $from,
                'to_position' => $to,
                'row_count' => $count,
                'rolling_digest' => $rolling,
                'previous_digest' => $tail?->digest,
                'digest' => AuditAnchorDigest::compute(
                    $sequence,
                    'prune',
                    $from,
                    $to,
                    $count,
                    $rolling,
                    $tail?->digest,
                    $export->archive->checksum,
                    $createdAt,
                ),
                'archive_sha256' => $export->archive->checksum,
                'created_at' => new DateTimeImmutable($createdAt),
            ], ['created_at' => Types::DATETIME_IMMUTABLE]);
            $deleted = AuditAppendOnlyGuard::withPruneAllowed(
                $this->database,
                $this->tables,
                fn (): int => (int) $this->database->executeStatement(sprintf(
                    'DELETE FROM %s WHERE position >= ? AND position <= ?',
                    $this->tables->quoted('audit_events'),
                ), [$from, $to]),
            );
            if ($deleted !== $count) {
                throw new RuntimeException('The audit retention pass did not remove exactly the archived range.');
            }
            $this->audit->record(new AuditEvent(
                Uuid::uuid7()->toString(),
                $now,
                $context->actorId(),
                'audit.trail.pruned',
                'audit_anchor',
                $markId,
                'success',
                [
                    'sequence' => $sequence,
                    'from_position' => $from,
                    'to_position' => $to,
                    'row_count' => $count,
                    'archive_key' => $export->archive->key,
                    'archive_sha256' => $export->archive->checksum,
                    'rolling_digest' => $rolling,
                ],
            ));

            return new AuditRetentionResult(
                $count,
                $from,
                $to,
                $export->archive->key,
                $export->archive->checksum,
                $sequence,
            );
        });
    }

    /**
     * Find the highest anchored position whose whole range has aged past the cutoff.
     *
     * The answer is always an anchor boundary, so a pruned range is exactly a range some anchor already
     * sealed and the ledger keeps a count and rolling digest for it after the rows are gone.
     *
     * @param   DateTimeImmutable  $cutoff  Instant rows must predate to become prunable.
     *
     * @return  ?int  Highest prunable position, or null when no whole anchored range has aged out.
     *
     * @throws  RuntimeException  When a stored boundary is malformed.
     *
     * @since   2.0.0
     */
    private function prunableThrough(DateTimeImmutable $cutoff): ?int
    {
        $youngest = AuditLedger::optionalPosition($this->database->fetchOne(sprintf(
            'SELECT MIN(position) FROM %s WHERE occurred_at > ?',
            $this->tables->quoted('audit_events'),
        ), [$cutoff], [Types::DATETIME_IMMUTABLE]), 'retention cutoff position');
        $anchored = AuditLedger::boundary($this->database, $this->tables, 'anchor');
        if ($anchored < 1) {
            return null;
        }
        $ceiling = $youngest === null ? $anchored : min($anchored, $youngest - 1);
        if ($ceiling < 1) {
            return null;
        }

        return AuditLedger::optionalPosition($this->database->fetchOne(sprintf(
            'SELECT MAX(to_position) FROM %s WHERE kind = ? AND to_position <= ?',
            $this->tables->quoted('audit_anchors'),
        ), ['anchor', $ceiling]), 'retention anchor boundary');
    }

    /**
     * Fold the range about to be pruned into its row count and rolling digest.
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
                $position = AuditLedger::position($row['position'] ?? null, 'pruned row position');
                $digest = AuditLedger::digest($row['digest'] ?? null, 'pruned row digest');
                hash_update($hash, $position . ':' . $digest . "\n");
                $count++;
                $cursor = $position;
            }
        } while (count($rows) === 1000);

        return [$count, hash_final($hash)];
    }
}
