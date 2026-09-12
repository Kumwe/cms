<?php

declare(strict_types=1);

namespace Kumwe\App\Audit\Infrastructure\Persistence;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Kumwe\App\Application\Authorization\AuthorizationGateway;
use Kumwe\App\Application\Authorization\AuthorizationResource;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Transaction\Contract\TransactionManager;
use Kumwe\App\Audit\Application\AuditAnchorWriter;
use Kumwe\App\Audit\Application\AuditRecorder;
use Kumwe\App\Audit\Domain\AuditAnchorDigest;
use Kumwe\App\Audit\Domain\AuditEvent;
use Kumwe\Extension\Spi\Identity\Domain\Capability;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use RuntimeException;

/**
 * Seals settled ranges of `audit_events` into the chained `audit_anchors` ledger.
 *
 * Each run seals the contiguous position range between the newest ledger entry and a settle boundary:
 * the highest position whose occurrence instant is older than the settle window. The window exists
 * because positions are allocated at insert time inside still-open transactions — a range sealed up to
 * the raw head could gain a row when a concurrent transaction that pre-allocated a position inside it
 * commits later, which would read as tampering. Sealing only rows older than the window (far longer
 * than any sane transaction) makes the anchored count and rolling digest stable, at the cost of the
 * newest few minutes staying in the unsealed tail until the next run. Anchor writes are serialized by
 * the scheduled job's fenced lease, never by locking anything the recorders touch, and both the anchor
 * row and its own `audit.anchor.recorded` event commit in one transaction.
 *
 * @since  2.0.0
 */
final readonly class DoctrineAuditAnchorWriter implements AuditAnchorWriter
{
    /**
     * Rows hashed per fetch batch while folding a range into its rolling digest.
     *
     * @var    int
     * @since  2.0.0
     */
    private const int BATCH_SIZE = 1000;

    /**
     * Bind the writer to its persistence, clock, audit and authorization collaborators.
     *
     * @param   Connection            $database       Connection the audit and anchor tables live on.
     * @param   TableNames            $tables         Resolver for prefixed physical table names.
     * @param   TransactionManager    $transactions   Commits the anchor row and its event as one unit.
     * @param   AuditRecorder         $audit          Trail the anchor write itself is recorded in.
     * @param   ClockInterface        $clock          Supplies the settle cutoff and creation instants.
     * @param   AuthorizationGateway  $authorization  Decides whether the caller may manage the trail.
     * @param   int                   $settleSeconds  Age a row must reach before it may be sealed, from
     *          60 to 86400 seconds; the bound on transaction
     *          duration the anchor layer tolerates.
     *
     * @throws  \InvalidArgumentException  When the settle window is outside its bounds.
     *
     * @since   2.0.0
     */
    public function __construct(
        private Connection $database,
        private TableNames $tables,
        private TransactionManager $transactions,
        private AuditRecorder $audit,
        private ClockInterface $clock,
        private AuthorizationGateway $authorization,
        private int $settleSeconds = 900,
    ) {
        if ($settleSeconds < 60 || $settleSeconds > 86400) {
            throw new \InvalidArgumentException('The audit anchor settle window is invalid.');
        }
    }

    /**
     * Seal every settled audit row past the newest ledger entry into one new anchor.
     *
     * @param   ExecutionContext  $context  Actor the anchor write is authorized and audited under.
     *
     * @return  ?int  Sequence number of the anchor written, or null when nothing settled is unsealed.
     *
     * @throws  RuntimeException  When the ledger or a walked row is malformed.
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor may not manage
     *          the audit trail.
     *
     * @since   2.0.0
     */
    public function anchor(ExecutionContext $context): ?int
    {
        $this->authorization->assertAllowed(
            $context,
            Capability::fromString('audit.manage'),
            AuthorizationResource::collection('audit_trail'),
        );
        $now = $this->clock->now();
        $cutoff = $now->modify(sprintf('-%d seconds', $this->settleSeconds));

        return $this->transactions->transactional(function () use ($context, $now, $cutoff): ?int {
            $tail = AuditLedger::tail($this->database, $this->tables);
            $from = AuditLedger::boundary($this->database, $this->tables, 'anchor') + 1;
            $boundary = $this->database->fetchOne(sprintf(
                'SELECT MAX(position) FROM %s WHERE occurred_at <= ?',
                $this->tables->quoted('audit_events'),
            ), [$cutoff], [Types::DATETIME_IMMUTABLE]);
            $boundary = AuditLedger::optionalPosition($boundary, 'settle boundary');
            if ($boundary === null || $boundary < $from) {
                return null;
            }
            [$rowCount, $rollingDigest] = $this->fold($from, $boundary);
            if ($rowCount < 1) {
                return null;
            }
            $sequence = ($tail->sequence ?? 0) + 1;
            $anchorId = Uuid::uuid7()->toString();
            $createdAt = $now->format('Y-m-d H:i:s');
            $digest = AuditAnchorDigest::compute(
                $sequence,
                'anchor',
                $from,
                $boundary,
                $rowCount,
                $rollingDigest,
                $tail?->digest,
                null,
                $createdAt,
            );
            $this->database->insert($this->tables->raw('audit_anchors'), [
                'id' => $anchorId,
                'sequence' => $sequence,
                'kind' => 'anchor',
                'from_position' => $from,
                'to_position' => $boundary,
                'row_count' => $rowCount,
                'rolling_digest' => $rollingDigest,
                'previous_digest' => $tail?->digest,
                'digest' => $digest,
                'archive_sha256' => null,
                'created_at' => new DateTimeImmutable($createdAt),
            ], ['created_at' => Types::DATETIME_IMMUTABLE]);
            $this->audit->record(new AuditEvent(
                Uuid::uuid7()->toString(),
                $now,
                $context->actorId(),
                'audit.anchor.recorded',
                'audit_anchor',
                $anchorId,
                'success',
                [
                    'sequence' => $sequence,
                    'from_position' => $from,
                    'to_position' => $boundary,
                    'row_count' => $rowCount,
                    'rolling_digest' => $rollingDigest,
                ],
            ));

            return $sequence;
        });
    }

    /**
     * Fold one position range into its row count and rolling digest, batch by batch.
     *
     * @param   int  $from  First position of the range, inclusive.
     * @param   int  $to    Last position of the range, inclusive.
     *
     * @return  array{int, string}  Row count and rolling digest of the range as stored right now.
     *
     * @throws  RuntimeException  When a walked row carries a malformed position or digest.
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
                'SELECT position, digest FROM %s WHERE position > ? AND position <= ? '
                . 'ORDER BY position ASC LIMIT %d',
                $this->tables->quoted('audit_events'),
                self::BATCH_SIZE,
            ), [$cursor, $to]);
            foreach ($rows as $row) {
                $position = AuditLedger::position($row['position'] ?? null, 'anchored row position');
                $digest = AuditLedger::digest($row['digest'] ?? null, 'anchored row digest');
                hash_update($hash, $position . ':' . $digest . "\n");
                $count++;
                $cursor = $position;
            }
        } while (count($rows) === self::BATCH_SIZE);

        return [$count, hash_final($hash)];
    }
}
