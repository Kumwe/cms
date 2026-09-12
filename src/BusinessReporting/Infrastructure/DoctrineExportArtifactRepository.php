<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessReporting\Infrastructure;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Types\Types;
use JsonException;
use Kumwe\Transaction\Contract\TransactionManager;
use Kumwe\App\BusinessDefinition\Domain\CanonicalDefinitionJson;
use Kumwe\App\BusinessReporting\Application\ExportArtifactRepository;
use Kumwe\App\BusinessReporting\Application\ExportVersionConflict;
use Kumwe\App\BusinessReporting\Domain\ExportArtifact;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use RuntimeException;

/**
 * Append-only DBAL ledger for export metadata that shares the job and audit transaction.
 *
 * @since  2.0.0
 */
final readonly class DoctrineExportArtifactRepository implements ExportArtifactRepository
{
    /**
     * Bind metadata versions to the authoritative application database transaction.
     *
     * @param  Connection          $database      Shared application connection.
     * @param  TableNames          $tables        Portable physical table-name compiler.
     * @param  TransactionManager  $transactions  Nested transaction boundary for compare-and-append.
     *
     * @since  2.0.0
     */
    public function __construct(
        private Connection $database,
        private TableNames $tables,
        private TransactionManager $transactions,
    ) {
    }

    /**
     * Append version one without replacing an existing artifact.
     *
     * @param   ExportArtifact  $artifact  New queued artifact metadata.
     *
     * @return  void
     *
     * @throws  ExportVersionConflict  When the artifact does not start at one or already exists.
     *
     * @since   2.0.0
     */
    public function add(ExportArtifact $artifact): void
    {
        if ($artifact->version !== 1) {
            throw new ExportVersionConflict('A new export artifact must start at version one.');
        }

        try {
            $this->transactions->transactional(function () use ($artifact): void {
                $this->insert($artifact);
            });
        } catch (UniqueConstraintViolationException $exception) {
            throw new ExportVersionConflict('The export artifact already exists.', 0, $exception);
        }
    }

    /**
     * Read and integrity-check the highest immutable metadata version.
     *
     * @param   string  $id  Canonical artifact UUID.
     *
     * @return  ?ExportArtifact  Current state, or null when no version exists.
     *
     * @throws  RuntimeException  When durable metadata is corrupt.
     *
     * @since   2.0.0
     */
    public function find(string $id): ?ExportArtifact
    {
        $this->assertId($id);
        $row = $this->database->fetchAssociative(sprintf(
            'SELECT artifact_id, version, status, site_identifier, actor_id, expires_at, document, '
            . 'document_checksum FROM %s WHERE artifact_id = ? ORDER BY version DESC LIMIT 1',
            $this->tables->quoted('business_report_export_artifacts'),
        ), [$id], [Types::GUID]);
        if ($row === false) {
            return null;
        }

        return $this->map($row, $id);
    }

    /**
     * Append exactly the next version after checking the currently visible head.
     *
     * @param   ExportArtifact  $artifact         New immutable state.
     * @param   int             $expectedVersion  Previously observed head version.
     *
     * @return  void
     *
     * @throws  ExportVersionConflict  When the head or proposed version differs.
     *
     * @since   2.0.0
     */
    public function save(ExportArtifact $artifact, int $expectedVersion): void
    {
        if ($expectedVersion < 1 || $artifact->version !== $expectedVersion + 1) {
            throw new ExportVersionConflict('The export artifact changed concurrently.');
        }

        try {
            $this->transactions->transactional(function () use ($artifact, $expectedVersion): void {
                $current = $this->database->fetchOne(sprintf(
                    'SELECT MAX(version) FROM %s WHERE artifact_id = ?',
                    $this->tables->quoted('business_report_export_artifacts'),
                ), [$artifact->id], [Types::GUID]);
                if (!is_int($current) && !is_string($current)) {
                    throw new ExportVersionConflict('The export artifact changed concurrently.');
                }
                if ((int) $current !== $expectedVersion) {
                    throw new ExportVersionConflict('The export artifact changed concurrently.');
                }
                $this->insert($artifact);
            });
        } catch (UniqueConstraintViolationException $exception) {
            throw new ExportVersionConflict('The export artifact changed concurrently.', 0, $exception);
        }
    }

    /**
     * Insert one immutable canonical document and its indexed integrity evidence.
     *
     * @param   ExportArtifact  $artifact  Metadata version being appended.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function insert(ExportArtifact $artifact): void
    {
        $document = CanonicalDefinitionJson::encode($artifact->toArray());
        if (strlen($document) > 1_048_576) {
            throw new RuntimeException('Export metadata exceeds its durable storage ceiling.');
        }
        $this->database->insert($this->tables->raw('business_report_export_artifacts'), [
            'artifact_id' => $artifact->id,
            'version' => $artifact->version,
            'status' => $artifact->status->value,
            'site_identifier' => $artifact->siteIdentifier,
            'actor_id' => $artifact->actorId,
            'expires_at' => $artifact->expiresAt,
            'document' => $document,
            'document_checksum' => hash('sha256', $document),
        ], [
            'artifact_id' => Types::GUID,
            'version' => Types::SMALLINT,
            'expires_at' => Types::DATETIME_IMMUTABLE,
        ]);
    }

    /**
     * Reconstitute one row only after checking its digest and indexed columns.
     *
     * @param   array<string, mixed>  $row          Database row returned by DBAL.
     * @param   string                $requestedId  Canonical identifier used in the lookup.
     *
     * @return  ExportArtifact  Validated current metadata.
     *
     * @throws  RuntimeException  When a value, digest, or denormalized column is inconsistent.
     *
     * @since   2.0.0
     */
    private function map(array $row, string $requestedId): ExportArtifact
    {
        $document = $row['document'] ?? null;
        $checksum = $row['document_checksum'] ?? null;
        if (
            !is_string($document) || strlen($document) > 1_048_576 || !is_string($checksum)
            || preg_match('/^[0-9a-f]{64}$/D', $checksum) !== 1
            || !hash_equals($checksum, hash('sha256', $document))
        ) {
            throw new RuntimeException('Export metadata storage integrity failed.');
        }
        try {
            $decoded = json_decode($document, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Export metadata is not valid JSON.', 0, $exception);
        }
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new RuntimeException('Export metadata is not an object.');
        }
        /** @var array<string, mixed> $decoded */
        $artifact = ExportArtifact::fromArray($decoded);
        $version = $row['version'] ?? null;
        if (
            (!is_int($version) && !is_string($version))
            || $artifact->id !== $requestedId
            || $artifact->version !== (int) $version
            || $artifact->status->value !== ($row['status'] ?? null)
            || $artifact->siteIdentifier !== ($row['site_identifier'] ?? null)
            || $artifact->actorId !== ($row['actor_id'] ?? null)
        ) {
            throw new RuntimeException('Export metadata identity does not match its immutable row.');
        }

        return $artifact;
    }

    /**
     * Reject malformed identifiers before they enter a lookup.
     *
     * @param   string  $id  Candidate artifact UUID.
     *
     * @return  void
     *
     * @throws  RuntimeException  When the id is not canonical.
     *
     * @since   2.0.0
     */
    private function assertId(string $id): void
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $id) !== 1) {
            throw new RuntimeException('An export metadata id is invalid.');
        }
    }
}
