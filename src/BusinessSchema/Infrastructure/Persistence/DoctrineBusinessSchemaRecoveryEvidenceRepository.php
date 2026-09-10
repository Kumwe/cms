<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessSchema\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use JsonException;
use Kumwe\App\BusinessSchema\Application\BusinessSchemaRecoveryEvidenceRepository;
use Kumwe\App\BusinessSchema\Domain\SchemaRecoveryEvidence;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\Context\Value\SiteContext;
use RuntimeException;

/**
 * Append-only drill store over the prefixed `business_schema_recovery_evidence` table.
 *
 * A high-risk plan cites an evidence identifier when it is approved and the executor resolves that same
 * identifier again as the run begins, so the record behind it must never come to mean something different
 * from what the approver signed off. Immutability is enforced rather than assumed: a save against an
 * identifier already present reads the stored record back, recomputes its content digest, and accepts the
 * write only when the two digests agree — which keeps a retried submission harmless and turns a re-scoped
 * drill into a refusal. There is no update or delete path at all. Reads are site-scoped, and because
 * driver rows arrive untyped every column is proved as it is mapped before `SchemaRecoveryEvidence`
 * revalidates the record as a whole.
 *
 * @since  2.0.0
 */
final readonly class DoctrineBusinessSchemaRecoveryEvidenceRepository implements
    BusinessSchemaRecoveryEvidenceRepository
{
    /**
     * Bind the store to the connection its statements run on and the resolver that names the table.
     *
     * @param  Connection  $database  DBAL connection carrying the caller's transaction, when one is open.
     * @param  TableNames  $tables    Resolver applying the prefix to `business_schema_recovery_evidence`.
     *
     * @since  2.0.0
     */
    public function __construct(private Connection $database, private TableNames $tables)
    {
    }

    /**
     * Read one recovery-evidence record within a site.
     *
     * The site is part of the criteria, so a plan on one site can never resolve a drill another site
     * recorded, however the identifier was obtained.
     *
     * @param   SiteContext  $site        Site the evidence must belong to.
     * @param   string       $evidenceId  UUID the verified drill was recorded under.
     *
     * @return  ?SchemaRecoveryEvidence  The stored drill record, or null when this site holds none under
     *          that identifier.
     *
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects the read.
     * @throws  RuntimeException  When a stored column is absent, empty, wrongly typed, or holds invalid JSON.
     * @throws  \Kumwe\App\BusinessSchema\Domain\InvalidBusinessSchema  When the stored row no longer
     *          satisfies the evidence rules, such as a verification that precedes its own backup.
     * @throws  \Kumwe\App\BusinessDefinition\Domain\InvalidBusinessDefinition  When the stored details hold
     *          a value that cannot be canonically encoded.
     *
     * @since   2.0.0
     */
    public function find(SiteContext $site, string $evidenceId): ?SchemaRecoveryEvidence
    {
        $row = $this->database->fetchAssociative(sprintf(
            'SELECT * FROM %s WHERE site_identifier = ? AND id = ?',
            $this->tables->quoted('business_schema_recovery_evidence'),
        ), [$site->identifier(), $evidenceId]);
        if ($row === false) {
            return null;
        }

        return SchemaRecoveryEvidence::fromArray([
            'id' => $this->string($row, 'id'),
            'site_identifier' => $this->string($row, 'site_identifier'),
            'database_driver' => $this->string($row, 'database_driver'),
            'database_server_version' => $this->string($row, 'database_server_version'),
            'application_release' => $this->string($row, 'application_release'),
            'source_schema_checksum' => $this->string($row, 'source_schema_checksum'),
            'backup_manifest_checksum' => $this->string($row, 'backup_manifest_checksum'),
            'restore_tested' => $this->boolean($row, 'restore_tested'),
            'backup_created_at' => $this->date($row['backup_created_at'] ?? null),
            'verified_at' => $this->date($row['verified_at'] ?? null),
            'verified_by' => $this->string($row, 'verified_by'),
            'drill_reference' => $this->string($row, 'drill_reference'),
            'details' => $this->jsonObject($row['details'] ?? null),
        ]);
    }

    /**
     * Insert a recovery-evidence record so a later approval can cite it, refusing to alter one already held.
     *
     * The identifier is probed first. When it is free the record is inserted and that is the end of it; when
     * it is taken the stored record is read back through `find()` and its content checksum compared with the
     * incoming one, so a byte-identical re-save succeeds silently and anything else is refused. The probe is
     * deliberately not site-scoped even though the read-back is: an identifier already used by another site
     * must not be reused here either.
     *
     * @param   SchemaRecoveryEvidence  $evidence  Verified drill result, already self-validated on construction.
     *
     * @return  void
     *
     * @throws  RuntimeException  When the identifier is already held by a record whose content differs, or by
     *          one this site cannot read back.
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects the probe or the insert, including when a
     *          concurrent writer claimed the identifier first.
     * @throws  \Kumwe\App\BusinessSchema\Domain\InvalidBusinessSchema  When the record already held under the
     *          identifier no longer satisfies the evidence rules.
     * @throws  \Kumwe\App\BusinessDefinition\Domain\InvalidBusinessDefinition  When the details of the record
     *          already held cannot be canonically encoded.
     *
     * @since   2.0.0
     */
    public function save(SchemaRecoveryEvidence $evidence): void
    {
        $exists = $this->database->fetchOne(sprintf(
            'SELECT id FROM %s WHERE id = ?',
            $this->tables->quoted('business_schema_recovery_evidence'),
        ), [$evidence->id]);
        if ($exists !== false) {
            $stored = $this->find(SiteContext::fromString($evidence->siteIdentifier), $evidence->id);
            if ($stored === null || !hash_equals($stored->checksum(), $evidence->checksum())) {
                throw new RuntimeException('Immutable schema recovery evidence cannot be replaced.');
            }
            return;
        }
        $this->database->insert($this->tables->raw('business_schema_recovery_evidence'), [
            'id' => $evidence->id,
            'site_identifier' => $evidence->siteIdentifier,
            'database_driver' => $evidence->databaseDriver,
            'database_server_version' => $evidence->databaseServerVersion,
            'application_release' => $evidence->applicationRelease,
            'source_schema_checksum' => $evidence->sourceSchemaChecksum,
            'backup_manifest_checksum' => $evidence->backupManifestChecksum,
            'restore_tested' => $evidence->restoreTested,
            'backup_created_at' => $evidence->backupCreatedAt,
            'verified_at' => $evidence->verifiedAt,
            'verified_by' => $evidence->verifiedBy,
            'drill_reference' => $evidence->drillReference,
            'details' => $evidence->details,
        ], [
            'restore_tested' => Types::BOOLEAN,
            'backup_created_at' => Types::DATETIME_IMMUTABLE,
            'verified_at' => Types::DATETIME_IMMUTABLE,
            'details' => Types::JSON,
        ]);
    }

    /**
     * Read a column that has to carry text, refusing an absent or empty one.
     *
     * @param   array<string, mixed>  $row  Driver row being mapped.
     * @param   string                $key  Column to read out of it.
     *
     * @return  string  The stored text, never an empty string.
     *
     * @throws  RuntimeException  When the column is absent, holds a non-string, or holds an empty string.
     *
     * @since   2.0.0
     */
    private function string(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new RuntimeException('Stored recovery evidence property ' . $key . ' is invalid.');
        }
        return $value;
    }

    /**
     * Read a flag column across engines that store booleans as small integers.
     *
     * A native boolean, the integers 0 and 1, and their decimal strings are all accepted; anything else is
     * refused rather than coerced, because this flag decides whether a backup was restored or merely taken.
     *
     * @param   array<string, mixed>  $row  Driver row being mapped.
     * @param   string                $key  Column to read out of it.
     *
     * @return  bool  The stored flag.
     *
     * @throws  RuntimeException  When the column is absent, or holds anything other than a boolean, 0, 1,
     *          `'0'`, or `'1'`.
     *
     * @since   2.0.0
     */
    private function boolean(array $row, string $key): bool
    {
        $value = $row[$key] ?? null;
        if (is_bool($value)) {
            return $value;
        }
        if (in_array($value, [0, '0'], true)) {
            return false;
        }
        if (in_array($value, [1, '1'], true)) {
            return true;
        }
        throw new RuntimeException('Stored recovery evidence property ' . $key . ' is invalid.');
    }

    /**
     * Turn the stored details column into the string-keyed document the drill record is rebuilt from.
     *
     * Drivers disagree over whether a JSON column arrives decoded, so text is decoded here and an
     * already-decoded array is taken as it is. An empty array is accepted — a drill need record no extra
     * proofs — while any other list is refused.
     *
     * @param   mixed  $value  Raw `details` column value, decoded by the driver or still encoded.
     *
     * @return  array<string, mixed>  The decoded details document.
     *
     * @throws  RuntimeException  When the column is not valid JSON, or decodes to anything other than a
     *          string-keyed object.
     *
     * @since   2.0.0
     */
    private function jsonObject(mixed $value): array
    {
        if (is_string($value)) {
            try {
                $value = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new RuntimeException('Stored recovery evidence JSON is invalid.', 0, $exception);
            }
        }
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new RuntimeException('Stored recovery evidence details are invalid.');
        }
        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * Render a timestamp column as the text `SchemaRecoveryEvidence::fromArray()` parses.
     *
     * A driver may return a date object or the raw column text depending on how the column was typed; an
     * object is formatted with microseconds and its offset, and text passes through untouched.
     *
     * @param   mixed  $value  Raw `backup_created_at` or `verified_at` column value.
     *
     * @return  string  The timestamp as text the schema document layer can read back.
     *
     * @throws  RuntimeException  When the value is neither a date object nor a non-empty string.
     *
     * @since   2.0.0
     */
    private function date(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d\TH:i:s.uP');
        }
        if (!is_string($value) || $value === '') {
            throw new RuntimeException('Stored recovery evidence timestamp is invalid.');
        }
        return $value;
    }
}
