<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessReporting\Domain;

use DateTimeImmutable;
use InvalidArgumentException;
use Kumwe\App\BusinessDefinition\Domain\CanonicalDefinitionJson;
use Kumwe\App\Identity\Domain\GrantScope;
use Kumwe\Context\Value\AuthenticatedSurface;
use Kumwe\Extension\Spi\BusinessReporting\Domain\ReportDefinitionGuard;
use Kumwe\Extension\Spi\Identity\Domain\Capability;
use LogicException;

/**
 * Immutable export request, authority snapshot, durable state and stored-byte evidence.
 *
 * @since  2.0.0
 */
final readonly class ExportArtifact
{
    /**
     * Maximum rows in one persisted authority-grant chunk.
     *
     * @var    int
     * @since  2.0.0
     */
    private const AUTHORITY_GRANT_CHUNK_SIZE = 512;

    /**
     * Maximum chunks in one persisted authority snapshot.
     *
     * @var    int
     * @since  2.0.0
     */
    private const MAX_AUTHORITY_GRANT_CHUNKS = 512;

    /**
     * Validated report parameters captured when the export was requested.
     *
     * @var    array<string, mixed>
     * @since  2.0.0
     */
    public array $parameters;

    /**
     * Exact effective grant ceiling captured from the requesting human principal.
     *
     * @var    ?list<array{capability: string, scope_type: string, scope_identifier: ?string}>
     * @since  2.0.0
     */
    public ?array $authorityGrantRows;

    /**
     * Reconstitute one fully validated export ledger entry.
     *
     * @param   string                $id                      Canonical artifact UUID.
     * @param   string                $reportIdentifier        Namespaced report contribution handle.
     * @param   int                   $reportVersion           Immutable report version.
     * @param   string                $definitionChecksum      Exact report document checksum.
     * @param   string                $actorId                 Original accountable actor.
     * @param   string                $siteIdentifier          Original site scope.
     * @param   ?string               $organizationIdentifier  Original organization scope.
     * @param   ?string               $workspaceIdentifier     Original workspace scope.
     * @param   AuthenticatedSurface  $surface                 Original delivery boundary.
     * @param   string                $authorityFingerprint    Original authority digest.
     * @param   string                $policySnapshot          Original record policy-plan digest.
     * @param   array<string, mixed>  $parameters              Validated report parameters, stored privately.
     * @param   string                $parameterDigest         Canonical digest of parameters and scope.
     * @param   ExportArtifactStatus  $status                  Durable lifecycle state.
     * @param   DateTimeImmutable     $createdAt               Request instant.
     * @param   DateTimeImmutable     $expiresAt               Last instant download is allowed.
     * @param   ?DateTimeImmutable    $startedAt               Worker start instant.
     * @param   ?DateTimeImmutable    $completedAt             Terminal instant.
     * @param   string                $filename                Safe attachment filename.
     * @param   ?string               $storageKey              Opaque private-store key after completion.
     * @param   ?int                  $size                    Stored byte size after completion.
     * @param   ?string               $checksum                Stored-byte SHA-256 after completion.
     * @param   ?int                  $rowCount                Exported row count after completion.
     * @param   ?string               $queryDigest             Executed policy-filtered query digest.
     * @param   ?string               $failureCode             Safe machine code after failure.
     * @param   int                   $version                 Optimistic metadata version.
     * @param ?array<mixed> $authorityGrantRows Candidate exact grant ceiling of the requesting credential.
     *
     * @throws  InvalidArgumentException  When metadata shape or lifecycle invariants are invalid.
     *
     * @since   2.0.0
     */
    public function __construct(
        public string $id,
        public string $reportIdentifier,
        public int $reportVersion,
        public string $definitionChecksum,
        public string $actorId,
        public string $siteIdentifier,
        public ?string $organizationIdentifier,
        public ?string $workspaceIdentifier,
        public AuthenticatedSurface $surface,
        public string $authorityFingerprint,
        public string $policySnapshot,
        array $parameters,
        public string $parameterDigest,
        public ExportArtifactStatus $status,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $expiresAt,
        public ?DateTimeImmutable $startedAt,
        public ?DateTimeImmutable $completedAt,
        public string $filename,
        public ?string $storageKey,
        public ?int $size,
        public ?string $checksum,
        public ?int $rowCount,
        public ?string $queryDigest,
        public ?string $failureCode,
        public int $version,
        ?array $authorityGrantRows = null,
    ) {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $id) !== 1) {
            throw new InvalidArgumentException('An export artifact id must be a canonical lowercase UUID.');
        }
        ReportDefinitionGuard::identifier($reportIdentifier, 'export report identifier');
        foreach ([$definitionChecksum, $authorityFingerprint, $policySnapshot, $parameterDigest] as $digest) {
            if (preg_match('/^[0-9a-f]{64}$/D', $digest) !== 1) {
                throw new InvalidArgumentException('An export artifact digest is invalid.');
            }
        }
        if (
            $reportVersion < 1 || $version < 1 || $version > 16 || $expiresAt <= $createdAt
            || strlen($actorId) > 191 || strlen($siteIdentifier) > 191
            || preg_match('/[\x00-\x1f]/', $actorId . $siteIdentifier) === 1
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,126}\.csv$/D', $filename) !== 1
        ) {
            throw new InvalidArgumentException('Export artifact identity, lifetime or filename is invalid.');
        }
        $this->authorityGrantRows = $authorityGrantRows === null
            ? null
            : self::normalizeAuthorityGrantRows($authorityGrantRows);
        CanonicalDefinitionJson::encode($parameters);
        if (count($parameters) > 32) {
            throw new InvalidArgumentException('An export artifact has too many report parameters.');
        }
        $terminal = $status === ExportArtifactStatus::Completed || $status === ExportArtifactStatus::Failed;
        if (
            ($status === ExportArtifactStatus::Queued) !== ($startedAt === null)
            || $terminal !== ($completedAt !== null)
            || ($status === ExportArtifactStatus::Completed) !== ($storageKey !== null)
            || ($status === ExportArtifactStatus::Completed) !== ($size !== null)
            || ($status === ExportArtifactStatus::Completed) !== ($checksum !== null)
            || ($status === ExportArtifactStatus::Completed) !== ($rowCount !== null)
            || ($status === ExportArtifactStatus::Completed) !== ($queryDigest !== null)
            || ($status === ExportArtifactStatus::Failed) !== ($failureCode !== null)
        ) {
            throw new InvalidArgumentException('An export artifact lifecycle is inconsistent.');
        }
        if (($size !== null && $size < 1) || ($rowCount !== null && $rowCount < 0)) {
            throw new InvalidArgumentException('An export artifact size or row count is invalid.');
        }
        if (
            $storageKey !== null && preg_match(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}'
                . '\.[0-9a-f]{32}\.csv$/D',
                $storageKey,
            ) !== 1
        ) {
            throw new InvalidArgumentException('An export artifact storage key is invalid.');
        }
        foreach ([$checksum, $queryDigest] as $digest) {
            if ($digest !== null && preg_match('/^[0-9a-f]{64}$/D', $digest) !== 1) {
                throw new InvalidArgumentException('An export artifact completion digest is invalid.');
            }
        }
        if ($failureCode !== null && preg_match('/^[a-z][a-z0-9_.-]{0,62}$/D', $failureCode) !== 1) {
            throw new InvalidArgumentException('An export artifact failure code is invalid.');
        }
        $this->parameters = $parameters;
    }

    /**
     * Move a queued artifact into its worker-owned running state.
     *
     * @param   DateTimeImmutable  $now  Worker start instant.
     *
     * @return  self  New running metadata version.
     *
     * @throws  LogicException  When the artifact is not queued or already expired.
     *
     * @since   2.0.0
     */
    public function start(DateTimeImmutable $now): self
    {
        if ($this->status !== ExportArtifactStatus::Queued || $this->expiresAt <= $now) {
            throw new LogicException('Only an unexpired queued export can start.');
        }

        return $this->copy(ExportArtifactStatus::Running, $now, null, null, null, null, null, null, null);
    }

    /**
     * Complete a running artifact with immutable stored-byte evidence.
     *
     * @param   DateTimeImmutable  $now          Completion instant.
     * @param   string             $storageKey   Opaque private storage key.
     * @param   int                $size         Stored byte count.
     * @param   string             $checksum     Stored-byte SHA-256.
     * @param   int                $rowCount     Exported data row count.
     * @param   string             $queryDigest  Executed report query digest.
     *
     * @return  self  New completed metadata version.
     *
     * @throws  LogicException  When the artifact is not running.
     *
     * @since   2.0.0
     */
    public function complete(
        DateTimeImmutable $now,
        string $storageKey,
        int $size,
        string $checksum,
        int $rowCount,
        string $queryDigest,
    ): self {
        if ($this->status !== ExportArtifactStatus::Running) {
            throw new LogicException('Only a running export can complete.');
        }

        return $this->copy(
            ExportArtifactStatus::Completed,
            $this->startedAt,
            $now,
            $storageKey,
            $size,
            $checksum,
            $rowCount,
            $queryDigest,
            null,
        );
    }

    /**
     * Fail a queued or running artifact with a redacted machine code.
     *
     * @param   DateTimeImmutable  $now   Failure instant.
     * @param   string             $code  Stable non-sensitive failure code.
     *
     * @return  self  New failed metadata version.
     *
     * @throws  LogicException  When the artifact is already terminal.
     *
     * @since   2.0.0
     */
    public function fail(DateTimeImmutable $now, string $code): self
    {
        if ($this->status === ExportArtifactStatus::Completed || $this->status === ExportArtifactStatus::Failed) {
            throw new LogicException('A terminal export cannot fail again.');
        }

        return $this->copy(
            ExportArtifactStatus::Failed,
            $this->startedAt ?? $now,
            $now,
            null,
            null,
            null,
            null,
            null,
            $code,
        );
    }

    /**
     * Export the exact private repository document.
     *
     * @return  array<string, mixed>  Canonically encodable metadata including validated parameters.
     *
     * @since   2.0.0
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'report_identifier' => $this->reportIdentifier,
            'report_version' => $this->reportVersion,
            'definition_checksum' => $this->definitionChecksum,
            'actor_id' => $this->actorId,
            'site_identifier' => $this->siteIdentifier,
            'organization_identifier' => $this->organizationIdentifier,
            'workspace_identifier' => $this->workspaceIdentifier,
            'surface' => $this->surface->value,
            'authority_fingerprint' => $this->authorityFingerprint,
            ...($this->authorityGrantRows === null ? [] : [
                'authority_grants' => array_chunk(
                    $this->authorityGrantRows,
                    self::AUTHORITY_GRANT_CHUNK_SIZE,
                ),
            ]),
            'policy_snapshot' => $this->policySnapshot,
            'parameters' => $this->parameters,
            'parameter_digest' => $this->parameterDigest,
            'status' => $this->status->value,
            'created_at' => $this->createdAt->format('Y-m-d\TH:i:s.uP'),
            'expires_at' => $this->expiresAt->format('Y-m-d\TH:i:s.uP'),
            'started_at' => $this->startedAt?->format('Y-m-d\TH:i:s.uP'),
            'completed_at' => $this->completedAt?->format('Y-m-d\TH:i:s.uP'),
            'filename' => $this->filename,
            'storage_key' => $this->storageKey,
            'size' => $this->size,
            'checksum' => $this->checksum,
            'row_count' => $this->rowCount,
            'query_digest' => $this->queryDigest,
            'failure_code' => $this->failureCode,
            'version' => $this->version,
        ];
    }

    /**
     * Reconstitute metadata written by `toArray()`.
     *
     * @param   array<string, mixed>  $document  Private repository document.
     *
     * @return  self  Validated artifact state.
     *
     * @throws  InvalidArgumentException  When a value or key is invalid.
     *
     * @since   2.0.0
     */
    public static function fromArray(array $document): self
    {
        $expected = [
            'id', 'report_identifier', 'report_version', 'definition_checksum', 'actor_id', 'site_identifier',
            'organization_identifier', 'workspace_identifier', 'surface', 'authority_fingerprint',
            'policy_snapshot', 'parameters', 'parameter_digest', 'status', 'created_at', 'expires_at',
            'started_at', 'completed_at', 'filename', 'storage_key', 'size', 'checksum', 'row_count',
            'query_digest', 'failure_code', 'version',
        ];
        $hasAuthorityGrants = array_key_exists('authority_grants', $document);
        if ($hasAuthorityGrants) {
            $expected[] = 'authority_grants';
        }
        $keys = array_keys($document);
        sort($keys, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($keys !== $expected) {
            throw new InvalidArgumentException('An export artifact document has missing or unknown keys.');
        }
        $authorityGrantRows = null;
        if ($hasAuthorityGrants) {
            $authorityGrantRows = self::flattenAuthorityGrantChunks($document['authority_grants']);
        }
        try {
            if (
                !is_array($document['parameters'])
                || ($document['parameters'] !== [] && array_is_list($document['parameters']))
            ) {
                throw new InvalidArgumentException('Export artifact parameters must form an object.');
            }
            /** @var array<string, mixed> $parameters */
            $parameters = $document['parameters'];
            return new self(
                self::string($document, 'id'),
                self::string($document, 'report_identifier'),
                self::integer($document, 'report_version'),
                self::string($document, 'definition_checksum'),
                self::string($document, 'actor_id'),
                self::string($document, 'site_identifier'),
                self::nullableString($document, 'organization_identifier'),
                self::nullableString($document, 'workspace_identifier'),
                AuthenticatedSurface::from(self::string($document, 'surface')),
                self::string($document, 'authority_fingerprint'),
                self::string($document, 'policy_snapshot'),
                $parameters,
                self::string($document, 'parameter_digest'),
                ExportArtifactStatus::from(self::string($document, 'status')),
                new DateTimeImmutable(self::string($document, 'created_at')),
                new DateTimeImmutable(self::string($document, 'expires_at')),
                self::date($document, 'started_at'),
                self::date($document, 'completed_at'),
                self::string($document, 'filename'),
                self::nullableString($document, 'storage_key'),
                self::nullableInteger($document, 'size'),
                self::nullableString($document, 'checksum'),
                self::nullableInteger($document, 'row_count'),
                self::nullableString($document, 'query_digest'),
                self::nullableString($document, 'failure_code'),
                self::integer($document, 'version'),
                authorityGrantRows: $authorityGrantRows,
            );
        } catch (\ValueError | \TypeError | \Exception $exception) {
            if ($exception instanceof InvalidArgumentException) {
                throw $exception;
            }
            throw new InvalidArgumentException('An export artifact document has an invalid value.', 0, $exception);
        }
    }

    /**
     * Create a state-transitioned copy of the export artifact.
     *
     * @param   ExportArtifactStatus  $status       Durable state to record for the receipt.
     * @param   ?DateTimeImmutable    $startedAt    Timestamp at which generation began, when started.
     * @param   ?DateTimeImmutable    $completedAt  Timestamp at which processing completed, when applicable.
     * @param   ?string               $storageKey   Confined storage key of completed export bytes.
     * @param   ?int                  $size         Completed artifact size in bytes, when available.
     * @param   ?string               $checksum     Content digest used to verify immutable bytes.
     * @param   ?int                  $rowCount     Number of exported rows, when generation completed.
     * @param   ?string               $queryDigest  Digest of the policy-filtered report query, when completed.
     * @param   ?string               $failureCode  Stable sanitized rejection code, when generation failed.
     *
     * @return  self  Artifact carrying the requested immutable state transition.
     *
     * @since   2.0.0
     */
    private function copy(
        ExportArtifactStatus $status,
        ?DateTimeImmutable $startedAt,
        ?DateTimeImmutable $completedAt,
        ?string $storageKey,
        ?int $size,
        ?string $checksum,
        ?int $rowCount,
        ?string $queryDigest,
        ?string $failureCode,
    ): self {
        return new self(
            $this->id,
            $this->reportIdentifier,
            $this->reportVersion,
            $this->definitionChecksum,
            $this->actorId,
            $this->siteIdentifier,
            $this->organizationIdentifier,
            $this->workspaceIdentifier,
            $this->surface,
            $this->authorityFingerprint,
            $this->policySnapshot,
            $this->parameters,
            $this->parameterDigest,
            $status,
            $this->createdAt,
            $this->expiresAt,
            $startedAt,
            $completedAt,
            $this->filename,
            $storageKey,
            $size,
            $checksum,
            $rowCount,
            $queryDigest,
            $failureCode,
            $this->version + 1,
            $this->authorityGrantRows,
        );
    }

    /**
     * Validate and canonicalize the requesting principal's persisted grant ceiling.
     *
     * @param   array<mixed>  $rows  Candidate exact capability-and-scope rows.
     *
     * @return  list<array{capability: string, scope_type: string, scope_identifier: ?string}>
     *          Sorted, duplicate-free grant rows.
     *
     * @throws  InvalidArgumentException  When rows are malformed, duplicated, or out of canonical order.
     *
     * @since   2.0.0
     */
    private static function normalizeAuthorityGrantRows(array $rows): array
    {
        if (!array_is_list($rows)) {
            throw new InvalidArgumentException('Export artifact authority grants must form a list.');
        }
        if (count($rows) > self::AUTHORITY_GRANT_CHUNK_SIZE * self::MAX_AUTHORITY_GRANT_CHUNKS) {
            throw new InvalidArgumentException('An export artifact has too many authority grants.');
        }
        $normalized = [];
        $previous = null;
        foreach ($rows as $row) {
            if (!is_array($row) || array_is_list($row)) {
                throw new InvalidArgumentException('An export artifact authority grant is invalid.');
            }
            $keys = array_keys($row);
            sort($keys, SORT_STRING);
            if ($keys !== ['capability', 'scope_identifier', 'scope_type']) {
                throw new InvalidArgumentException('An export artifact authority grant is invalid.');
            }
            $capability = $row['capability'];
            $scopeType = $row['scope_type'];
            $scopeIdentifier = $row['scope_identifier'];
            if (!is_string($capability) || !is_string($scopeType)) {
                throw new InvalidArgumentException('An export artifact authority grant is invalid.');
            }
            $validatedCapability = Capability::fromString($capability);
            if ($validatedCapability->value() !== $capability) {
                throw new InvalidArgumentException('An export artifact authority grant is invalid.');
            }
            if ($scopeType === 'global') {
                if ($scopeIdentifier !== null) {
                    throw new InvalidArgumentException('An export artifact authority grant is invalid.');
                }
                $validatedScope = GrantScope::global();
            } elseif (!is_string($scopeIdentifier)) {
                throw new InvalidArgumentException('An export artifact authority grant is invalid.');
            } else {
                $validatedScope = GrantScope::named($scopeType, $scopeIdentifier);
            }
            if (
                $validatedScope->type() !== $scopeType
                || $validatedScope->identifier() !== $scopeIdentifier
            ) {
                throw new InvalidArgumentException('An export artifact authority grant is invalid.');
            }
            $key = implode("\0", [$capability, $scopeType, $scopeIdentifier ?? '']);
            if ($previous !== null && strcmp($previous, $key) >= 0) {
                throw new InvalidArgumentException('Export artifact authority grants must be canonical.');
            }
            $previous = $key;
            $normalized[] = [
                'capability' => $capability,
                'scope_type' => $scopeType,
                'scope_identifier' => $scopeIdentifier,
            ];
        }

        return $normalized;
    }

    /**
     * Validate canonical persisted grant chunks and restore the flat in-memory snapshot.
     *
     * Every non-final chunk is exactly 512 rows and the final chunk contains 1 to 512 rows. This gives
     * each flat snapshot one serialized shape while keeping every collection inside the canonical JSON
     * encoder's width limit. An empty outer list is the sole representation of an empty captured snapshot.
     *
     * @param   mixed  $candidate  Persisted authority-grant chunks.
     *
     * @return  list<mixed>  Flattened rows for canonical domain validation by the constructor.
     *
     * @throws  InvalidArgumentException  When the outer list or a chunk has a non-canonical shape.
     *
     * @since   2.0.0
     */
    private static function flattenAuthorityGrantChunks(mixed $candidate): array
    {
        if (!is_array($candidate) || !array_is_list($candidate)) {
            throw new InvalidArgumentException('Export artifact authority grants must form a list of chunks.');
        }
        if (count($candidate) > self::MAX_AUTHORITY_GRANT_CHUNKS) {
            throw new InvalidArgumentException('An export artifact has too many authority-grant chunks.');
        }

        $rows = [];
        $finalChunkIndex = count($candidate) - 1;
        foreach ($candidate as $index => $chunk) {
            if (!is_array($chunk) || !array_is_list($chunk)) {
                throw new InvalidArgumentException('An export artifact authority-grant chunk is invalid.');
            }
            $rowCount = count($chunk);
            if (
                $rowCount < 1
                || $rowCount > self::AUTHORITY_GRANT_CHUNK_SIZE
                || ($index !== $finalChunkIndex && $rowCount !== self::AUTHORITY_GRANT_CHUNK_SIZE)
            ) {
                throw new InvalidArgumentException('An export artifact authority-grant chunk is invalid.');
            }
            foreach ($chunk as $row) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * Read a required string from the supplied data.
     *
     * @param   array<string, mixed>  $document  Serialized document from which the named member is read.
     * @param   string                $key       Array or row key whose value is being read.
     *
     * @return  string  Required string stored under the requested key.
     *
     * @since   2.0.0
     */
    private static function string(array $document, string $key): string
    {
        if (!is_string($document[$key])) {
            throw new InvalidArgumentException('An export artifact string is invalid.');
        }
        return $document[$key];
    }

    /**
     * Read an optional string from the supplied data.
     *
     * @param   array<string, mixed>  $document  Serialized document from which the named member is read.
     * @param   string                $key       Array or row key whose value is being read.
     *
     * @return  ?string  String stored under the key, or null when the member is absent.
     *
     * @since   2.0.0
     */
    private static function nullableString(array $document, string $key): ?string
    {
        if ($document[$key] !== null && !is_string($document[$key])) {
            throw new InvalidArgumentException('An export artifact nullable string is invalid.');
        }
        return $document[$key];
    }

    /**
     * Read and validate an integer value.
     *
     * @param   array<string, mixed>  $document  Serialized document from which the named member is read.
     * @param   string                $key       Array or row key whose value is being read.
     *
     * @return  int  Integer stored under the requested key.
     *
     * @since   2.0.0
     */
    private static function integer(array $document, string $key): int
    {
        if (!is_int($document[$key])) {
            throw new InvalidArgumentException('An export artifact integer is invalid.');
        }
        return $document[$key];
    }

    /**
     * Read an optional integer from the supplied data.
     *
     * @param   array<string, mixed>  $document  Serialized document from which the named member is read.
     * @param   string                $key       Array or row key whose value is being read.
     *
     * @return  ?int  Integer stored under the key, or null when the member is absent.
     *
     * @since   2.0.0
     */
    private static function nullableInteger(array $document, string $key): ?int
    {
        if ($document[$key] !== null && !is_int($document[$key])) {
            throw new InvalidArgumentException('An export artifact nullable integer is invalid.');
        }
        return $document[$key];
    }

    /**
     * Read an immutable timestamp from the supplied row.
     *
     * @param   array<string, mixed>  $document  Serialized document from which the named member is read.
     * @param   string                $key       Array or row key whose value is being read.
     *
     * @return  ?DateTimeImmutable  Timestamp stored under the requested key.
     *
     * @since   2.0.0
     */
    private static function date(array $document, string $key): ?DateTimeImmutable
    {
        $value = self::nullableString($document, $key);
        return $value === null ? null : new DateTimeImmutable($value);
    }
}
