<?php

declare(strict_types=1);

namespace Kumwe\App\Audit\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Generator;
use InvalidArgumentException;
use Kumwe\App\Application\Authorization\AuthorizationGateway;
use Kumwe\App\Application\Authorization\AuthorizationResource;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Transaction\Contract\TransactionManager;
use Kumwe\App\Audit\Application\AuditArchiveStorage;
use Kumwe\App\Audit\Application\AuditMetadataRedactor;
use Kumwe\App\Audit\Application\AuditRecorder;
use Kumwe\App\Audit\Application\AuditTrailExport;
use Kumwe\App\Audit\Application\AuditTrailExporter;
use Kumwe\App\Audit\Domain\AuditEvent;
use Kumwe\Extension\Spi\Identity\Domain\Capability;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use Throwable;

/**
 * Writes a protected, redacted NDJSON archive of one audit-trail range and records the export itself.
 *
 * This is the only sanctioned full read of the trail. The identity screen deliberately shows a filtered
 * projection without metadata, so before this existed an incident required raw database access to
 * preserve evidence. The archive is streamed straight into private `0600` storage, one JSON object per
 * line preceded by a manifest line, with every row's stored digest and witness link carried alongside
 * its fields so an auditor can recompute the chain from the file. Credential-shaped metadata is redacted
 * on the way out; because redaction happens after the digest was stored, a redacted archive still lets a
 * verifier confirm the *unredacted* rows through the anchor ledger, and the manifest names the newest
 * anchor at export time so the range is tied to sealed evidence. The export writes an
 * `audit.trail.exported` event of its own inside the same transaction, so taking a copy of the trail is
 * itself in the trail.
 *
 * @since  2.0.0
 */
final readonly class DoctrineAuditTrailExporter implements AuditTrailExporter
{
    /**
     * Rows fetched per batch while streaming a range into the archive.
     *
     * @var    int
     * @since  2.0.0
     */
    private const int BATCH_SIZE = 500;

    /**
     * Bind the exporter to its persistence, storage, audit and authorization collaborators.
     *
     * @param  Connection            $database       Connection the audit tables live on.
     * @param  TableNames            $tables         Resolver for prefixed physical table names.
     * @param  TransactionManager    $transactions   Commits the export event with the manifest read.
     * @param  AuditArchiveStorage   $archives       Private storage the archive bytes are published to.
     * @param  AuditRecorder         $audit          Trail the export itself is recorded in.
     * @param  ClockInterface        $clock          Supplies the export instant.
     * @param  AuthorizationGateway  $authorization  Decides whether the caller may export the trail.
     *
     * @since  2.0.0
     */
    public function __construct(
        private Connection $database,
        private TableNames $tables,
        private TransactionManager $transactions,
        private AuditArchiveStorage $archives,
        private AuditRecorder $audit,
        private ClockInterface $clock,
        private AuthorizationGateway $authorization,
    ) {
    }

    /**
     * Export one position range of the trail into a checksummed private NDJSON archive.
     *
     * @param   ExecutionContext  $context       Actor the export is authorized and audited under.
     * @param   ?int              $fromPosition  First position to include, or null for the trail's start.
     * @param   ?int              $toPosition    Last position to include, or null for the current head.
     *
     * @return  AuditTrailExport  Manifest naming the archive, its range, counts and anchor reference.
     *
     * @throws  InvalidArgumentException  When the requested range is inverted or not positive.
     * @throws  RuntimeException  When the range holds no events or the archive cannot be written.
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor may not export
     *          the audit trail.
     *
     * @since   2.0.0
     */
    public function export(
        ExecutionContext $context,
        ?int $fromPosition = null,
        ?int $toPosition = null,
    ): AuditTrailExport {
        $this->authorization->assertAllowed(
            $context,
            Capability::fromString('audit.export'),
            AuthorizationResource::collection('audit_trail'),
        );
        if (($fromPosition !== null && $fromPosition < 1) || ($toPosition !== null && $toPosition < 1)) {
            throw new InvalidArgumentException('An audit export range must use positive positions.');
        }
        if ($fromPosition !== null && $toPosition !== null && $toPosition < $fromPosition) {
            throw new InvalidArgumentException('An audit export range must not be inverted.');
        }

        return $this->transactions->transactional(function () use ($context, $fromPosition, $toPosition) {
            $from = $fromPosition ?? 1;
            $to = $toPosition ?? AuditLedger::optionalPosition($this->database->fetchOne(sprintf(
                'SELECT MAX(position) FROM %s',
                $this->tables->quoted('audit_events'),
            )), 'trail head position');
            if ($to === null || $to < $from) {
                throw new RuntimeException('The requested audit export range holds no events.');
            }
            $anchor = AuditLedger::tail($this->database, $this->tables)?->sequence;
            $now = $this->clock->now();
            $archiveId = Uuid::uuid7()->toString();
            $events = 0;
            $redacted = 0;
            $archive = $this->archives->store(
                $archiveId,
                $this->lines($archiveId, $from, $to, $anchor, $now->format('Y-m-d H:i:s'), $events, $redacted),
            );
            if ($events < 1) {
                throw new RuntimeException('The requested audit export range holds no events.');
            }
            $this->audit->record(new AuditEvent(
                Uuid::uuid7()->toString(),
                $now,
                $context->actorId(),
                'audit.trail.exported',
                'audit_archive',
                $archiveId,
                'success',
                [
                    'archive_key' => $archive->key,
                    'archive_sha256' => $archive->checksum,
                    'archive_bytes' => $archive->size,
                    'from_position' => $from,
                    'to_position' => $to,
                    'event_count' => $events,
                    'redacted_values' => $redacted,
                    'anchor_sequence' => $anchor,
                    'request_id' => $context->requestId(),
                ],
            ));

            return new AuditTrailExport($archive, $from, $to, $events, $redacted, $anchor);
        });
    }

    /**
     * Stream the archive's manifest line and one NDJSON line per exported event.
     *
     * @param   string  $archiveId  Canonical UUID naming this archive.
     * @param   int     $from       First position to include, inclusive.
     * @param   int     $to         Last position to include, inclusive.
     * @param   ?int    $anchor     Newest anchor sequence at export time, or null when none exists.
     * @param   string  $at         Export instant formatted as `Y-m-d H:i:s`.
     * @param   int     $events     Running count of exported events, raised in place.
     * @param   int     $redacted   Running count of redacted values, raised in place.
     *
     * @return  Generator<int, string>  Ordered NDJSON chunks.
     *
     * @throws  RuntimeException  When a stored row is malformed or cannot be encoded.
     *
     * @since   2.0.0
     */
    private function lines(
        string $archiveId,
        int $from,
        int $to,
        ?int $anchor,
        string $at,
        int &$events,
        int &$redacted,
    ): Generator {
        yield $this->line([
            'kumwe_audit_archive' => 1,
            'archive_id' => $archiveId,
            'from_position' => $from,
            'to_position' => $to,
            'anchor_sequence' => $anchor,
            'exported_at' => $at,
        ]);
        $cursor = $from - 1;
        do {
            $rows = $this->database->fetchAllAssociative(sprintf(
                'SELECT position, id, occurred_at, actor_id, action, subject_type, subject_id, outcome, '
                . 'metadata, digest, previous_digest FROM %s WHERE position > ? AND position <= ? '
                . 'ORDER BY position ASC LIMIT %d',
                $this->tables->quoted('audit_events'),
                self::BATCH_SIZE,
            ), [$cursor, $to]);
            foreach ($rows as $row) {
                $position = AuditLedger::position($row['position'] ?? null, 'event position');
                $cursor = $position;
                $events++;
                yield $this->line([
                    'position' => $position,
                    'id' => $row['id'] ?? null,
                    'occurred_at' => AuditLedger::instant($row['occurred_at'] ?? null, 'event occurrence instant'),
                    'actor_id' => $row['actor_id'] ?? null,
                    'action' => $row['action'] ?? null,
                    'subject_type' => $row['subject_type'] ?? null,
                    'subject_id' => $row['subject_id'] ?? null,
                    'outcome' => $row['outcome'] ?? null,
                    'metadata' => AuditMetadataRedactor::redact($this->metadata($row['metadata'] ?? null), $redacted),
                    'digest' => AuditLedger::digest($row['digest'] ?? null, 'event digest'),
                    'previous_digest' => AuditLedger::optionalDigest(
                        $row['previous_digest'] ?? null,
                        'event witness link',
                    ),
                ]);
            }
        } while (count($rows) === self::BATCH_SIZE);
    }

    /**
     * Encode one archive record as a single NDJSON line.
     *
     * @param   array<string, mixed>  $record  Values to encode.
     *
     * @return  string  Compact JSON object followed by a newline.
     *
     * @throws  RuntimeException  When the record cannot be encoded as JSON.
     *
     * @since   2.0.0
     */
    private function line(array $record): string
    {
        try {
            return json_encode(
                $record,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ) . "\n";
        } catch (Throwable $exception) {
            throw new RuntimeException('An audit archive record cannot be encoded.', 0, $exception);
        }
    }

    /**
     * Decode one stored metadata column into the object the redactor works over.
     *
     * @param   mixed  $value  Raw driver value: a JSON string, a stream, or null.
     *
     * @return  array<string, mixed>  Decoded metadata, empty when the row stored none.
     *
     * @throws  RuntimeException  When the stored metadata cannot be decoded.
     *
     * @since   2.0.0
     */
    private function metadata(mixed $value): array
    {
        if (is_resource($value)) {
            $value = stream_get_contents($value);
        }
        if (!is_string($value) || $value === '') {
            return [];
        }
        try {
            $decoded = json_decode($value, true, 64, JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            throw new RuntimeException('A stored audit row carries unreadable metadata.', 0, $exception);
        }
        if (!is_array($decoded)) {
            return [];
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
