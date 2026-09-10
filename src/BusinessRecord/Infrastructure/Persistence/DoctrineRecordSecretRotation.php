<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessRecord\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use Kumwe\App\Application\Authorization\AuthorizationGateway;
use Kumwe\App\Application\Authorization\AuthorizationResource;
use Kumwe\App\Application\Persistence\TransactionManager;
use Kumwe\App\Audit\Application\AuditRecorder;
use Kumwe\App\Audit\Domain\AuditEvent;
use Kumwe\App\BusinessDefinition\Application\BusinessDefinitionRepository;
use Kumwe\App\BusinessRecord\Application\BusinessRecordMutationFence;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordException;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordSchemaUnavailable;
use Kumwe\App\BusinessRecord\Application\RecordSecretRotation;
use Kumwe\App\BusinessRecord\Application\RecordSecretRotationReport;
use Kumwe\App\BusinessRecord\Application\SecretAssociatedData;
use Kumwe\App\BusinessRecord\Application\SecretCipher;
use Kumwe\App\BusinessRecord\Application\SecretKeyProvider;
use Kumwe\App\BusinessRecord\Domain\EncryptedEnvelope;
use Kumwe\App\BusinessSchema\Application\BusinessSchemaInstallationRepository;
use Kumwe\App\BusinessSchema\Domain\PhysicalTableBlueprint;
use Kumwe\App\BusinessSchema\Domain\SchemaInstallation;
use Kumwe\App\BusinessSchema\Domain\SchemaInstallationStatus;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Extension\Spi\Identity\Domain\Capability;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;

/**
 * Re-seals stored `core.secret` columns under the active key, in bounded, resumable chunks.
 *
 * The pass reads only rows whose stored key identifier is not the active one, opens each envelope with
 * the key it names, seals it again under the active key, and writes it back guarded on the identifier it
 * read. That predicate is the entire progress state: a re-sealed row stops matching it, so a pass that is
 * interrupted — killed worker, lost connection, expired lease — leaves every committed row done and the
 * next pass reads exactly what is left. There is no cursor to persist and therefore none to corrupt, and
 * running the pass again against a finished installation reads nothing.
 *
 * The guard is what makes it safe beside live traffic. An ordinary write that replaces a secret between
 * the read and the update has already sealed it under the active key, so the guarded update matches no
 * row; the pass counts that row as superseded and moves on rather than restoring the value it read. The
 * row's optimistic version is deliberately neither checked nor bumped: re-keying changes no business
 * value, so it must not invalidate a concurrent reader's version or manufacture a conflict.
 *
 * Rewriting the columns directly, rather than driving `BusinessRecordService`, follows what this codebase
 * already does for the one comparable operation — `DoctrineBusinessSchemaRecordRepinGateway` re-encodes
 * stored rows under the schema executor's fence in exactly this shape — and it is also the safer of the
 * two here. A rotation is not a mutation: routing it through the record service would mint a revision and
 * an audit entry per record for a change no user made, bump every record's version, re-run workflow and
 * field-access rules, and carry the plaintext through several more layers than it has to. Authorization,
 * the installation fence, transactions and audit are still honoured — they are taken here instead of
 * inherited — and the plaintext exists only between `decrypt()` and `encrypt()` inside one method.
 *
 * Revision snapshots are deliberately left alone. A revision row is checksummed over its snapshot and
 * every reader re-derives that checksum, so re-sealing a snapshot would make an honest rewrite
 * indistinguishable from tampering and destroy the only integrity evidence the history has. The key ring
 * keeps those envelopes readable instead; the consequence, stated in `docs/business-security.md`, is that
 * a retired key may be dropped only once revision history that names it has passed out of retention.
 *
 * @since  2.0.0
 */
final readonly class DoctrineRecordSecretRotation implements RecordSecretRotation
{
    /**
     * Rows read per transaction, so one chunk never holds an installation fence for long.
     *
     * @var    int
     * @since  2.0.0
     */
    private const int CHUNK_ROWS = 100;

    /**
     * Bind the pass to persistence, key material, the fence, and its authorization and audit seams.
     *
     * @param  Connection                            $database       Connection the generated record tables
     *         live on.
     * @param  BusinessDefinitionRepository          $definitions    Catalog naming the site's definitions.
     * @param  BusinessSchemaInstallationRepository  $installations  Installed blueprints, re-read under the
     *         fence so the columns written are the columns the lock is holding.
     * @param  BusinessRecordMutationFence           $fence          Holds an installation still for the
     *         length of one chunk, so no schema plan moves the table underneath it.
     * @param  SecretCipher                          $cipher         Key-ring cipher: opens by the envelope's
     *         identifier, seals under the active key.
     * @param  SecretKeyProvider                     $keys           Names the active key the pass is moving
     *         rows onto.
     * @param  TransactionManager                    $transactions   Commits each chunk with its audit entry.
     * @param  AuditRecorder                         $audit          Trail each chunk's work is recorded in.
     * @param  AuthorizationGateway                  $authorization  Decides whether the actor may re-key.
     * @param  ClockInterface                        $clock          Stamps the audit entries.
     *
     * @since  2.0.0
     */
    public function __construct(
        private Connection $database,
        private BusinessDefinitionRepository $definitions,
        private BusinessSchemaInstallationRepository $installations,
        private BusinessRecordMutationFence $fence,
        private SecretCipher $cipher,
        private SecretKeyProvider $keys,
        private TransactionManager $transactions,
        private AuditRecorder $audit,
        private AuthorizationGateway $authorization,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Re-seal up to `$batchSize` stored secrets on the caller's site under the active key.
     *
     * @param   ExecutionContext  $context    Actor the pass is authorized and audited under.
     * @param   int               $batchSize  Most rows this pass may read, from 1 to 1000.
     *
     * @return  RecordSecretRotationReport  Counts, skipped installations, and whether work remains.
     *
     * @throws  InvalidArgumentException  When the batch size falls outside its range.
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor may not re-key.
     * @throws  \Kumwe\App\BusinessRecord\Domain\SecretKeyUnavailable  When a stored envelope names a key
     *          this deployment does not hold.
     * @throws  \RuntimeException  When a stored envelope fails authenticated decryption.
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects a chunk read or one of its updates.
     *
     * @since   2.0.0
     */
    public function rotate(ExecutionContext $context, int $batchSize): RecordSecretRotationReport
    {
        $this->authorization->assertAllowed(
            $context,
            Capability::fromString('business.record.rekey'),
            AuthorizationResource::collection('business_record'),
        );
        if ($batchSize < 1 || $batchSize > 1_000) {
            throw new InvalidArgumentException('A record secret rotation batch must hold 1 to 1000 rows.');
        }
        $site = $context->site()->identifier();
        $activeKeyId = $this->keys->activeKeyId();
        $examined = 0;
        $resealed = 0;
        $superseded = 0;
        $scanned = 0;
        $skipped = [];
        $exhausted = false;
        foreach ($this->sealedInstallations($context) as $installation) {
            if ($exhausted) {
                break;
            }
            $tables = $this->secretTables($installation);
            if ($tables === []) {
                continue;
            }
            if ($installation->status !== SchemaInstallationStatus::Active) {
                $skipped[] = [
                    'definition_id' => $installation->definitionId,
                    'status' => $installation->status->value,
                ];
                continue;
            }
            ++$scanned;
            foreach ($tables as $table) {
                if ($exhausted) {
                    break;
                }
                while (true) {
                    if ($examined >= $batchSize) {
                        $exhausted = true;
                        break;
                    }
                    $limit = min(self::CHUNK_ROWS, $batchSize - $examined);
                    try {
                        $chunk = $this->rotateTransaction(
                            $context,
                            $site,
                            $installation->definitionId,
                            $table,
                            $activeKeyId,
                            $limit,
                        );
                    } catch (BusinessRecordException $unavailable) {
                        $skipped[] = [
                            'definition_id' => $installation->definitionId,
                            'status' => $unavailable->stableCode(),
                        ];
                        break 2;
                    }
                    $examined += $chunk['read'];
                    $resealed += $chunk['resealed'];
                    $superseded += $chunk['superseded'];
                    if ($chunk['read'] < $limit) {
                        break;
                    }
                }
            }
        }

        return new RecordSecretRotationReport(
            $activeKeyId,
            $examined,
            $resealed,
            $superseded,
            $scanned,
            $skipped,
            !$exhausted && $skipped === [],
        );
    }

    /**
     * Run one chunk inside its own transaction, so a killed pass loses at most that chunk.
     *
     * Narrowing the transaction to a single chunk is what keeps the installation fence short-lived: the
     * lock a chunk takes is released as soon as its hundred rows commit, rather than being held for the
     * length of a whole campaign.
     *
     * @param   ExecutionContext  $context       Actor the chunk is authorized and audited under.
     * @param   string            $site          Site identifier the installation must belong to.
     * @param   string            $definitionId  Definition whose installation is fenced and rotated.
     * @param   string            $table         Logical table name within that installation.
     * @param   string            $activeKeyId   Identifier every re-sealed envelope will carry.
     * @param   int               $limit         Most rows this chunk may read.
     *
     * @return  array{read: int, resealed: int, superseded: int}  What the chunk did.
     *
     * @throws  BusinessRecordException  When the installation cannot be fenced or no longer describes the
     *          table, which excludes that definition from this pass rather than failing the pass.
     *
     * @since   2.0.0
     */
    private function rotateTransaction(
        ExecutionContext $context,
        string $site,
        string $definitionId,
        string $table,
        string $activeKeyId,
        int $limit,
    ): array {
        /** @var array{read: int, resealed: int, superseded: int} $chunk */
        $chunk = $this->transactions->transactional(fn (): array => $this->rotateChunk(
            $context,
            $site,
            $definitionId,
            $table,
            $activeKeyId,
            $limit,
        ));

        return $chunk;
    }

    /**
     * Read the installations of the caller's site that could hold sealed values at all.
     *
     * The catalog is the site's own list, so a pass can never reach another site's tables even before
     * authorization is considered, and the batch read keeps this to two statements however many
     * definitions a site holds.
     *
     * @param   ExecutionContext  $context  Actor whose site's catalog is read.
     *
     * @return  list<SchemaInstallation>  Installations belonging to that site, in definition order.
     *
     * @since   2.0.0
     */
    private function sealedInstallations(ExecutionContext $context): array
    {
        $identifiers = [];
        foreach ($this->definitions->catalog($context->site()) as $entry) {
            $identifiers[] = $entry->id;
        }
        if ($identifiers === []) {
            return [];
        }
        $installations = [];
        foreach ($this->installations->findBatch($identifiers) as $installation) {
            if ($installation->siteIdentifier === $context->site()->identifier()) {
                $installations[] = $installation;
            }
        }

        return $installations;
    }

    /**
     * Name the logical tables of one installation that carry at least one secret field.
     *
     * A secret is the only field type compiled into a `.key_id` component, and a field handle cannot
     * contain a dot, so the presence of that suffix identifies a sealed column without consulting the
     * definition document at all.
     *
     * @param   SchemaInstallation  $installation  Installation whose blueprint is inspected.
     *
     * @return  list<string>  Logical table names holding sealed columns; empty when none do.
     *
     * @since   2.0.0
     */
    private function secretTables(SchemaInstallation $installation): array
    {
        $tables = [];
        foreach ($installation->blueprint->tables() as $table) {
            if ($this->secretFields($table) !== []) {
                $tables[] = $table->logicalName;
            }
        }

        return $tables;
    }

    /**
     * Resolve the four physical columns each sealed field of a table occupies.
     *
     * @param   PhysicalTableBlueprint  $table  Installed table being inspected.
     *
     * @return  array<string, array{ciphertext: string, nonce: string, key_id: string, algorithm: string}>
     *          Physical column names of each component, keyed by the field handle that owns them; a field
     *          missing any component is left out rather than half-rotated.
     *
     * @since   2.0.0
     */
    private function secretFields(PhysicalTableBlueprint $table): array
    {
        $fields = [];
        foreach ($table->columns() as $column) {
            if (!str_ends_with($column->logicalName, '.key_id')) {
                continue;
            }
            $handle = substr($column->logicalName, 0, -strlen('.key_id'));
            $components = [];
            foreach (['ciphertext', 'nonce', 'key_id', 'algorithm'] as $component) {
                $part = $table->column($handle . '.' . $component);
                if ($part === null) {
                    continue 2;
                }
                $components[$component] = $part->physicalName;
            }
            /** @var array{ciphertext: string, nonce: string, key_id: string, algorithm: string} $components */
            $fields[$handle] = $components;
        }
        ksort($fields, SORT_STRING);

        return $fields;
    }

    /**
     * Re-seal one chunk of one table, inside the caller's transaction and behind the installation fence.
     *
     * The fence is taken first and the installation re-read under it, so the columns this writes are the
     * columns the lock is actually holding rather than the ones a read before the lock happened to see.
     *
     * @param   ExecutionContext  $context       Actor the chunk is audited under.
     * @param   string            $site          Site identifier the installation must belong to.
     * @param   string            $definitionId  Definition whose installation is fenced and rotated.
     * @param   string            $table         Logical table name within that installation.
     * @param   string            $activeKeyId   Identifier every re-sealed envelope will carry.
     * @param   int               $limit         Most rows this chunk may read.
     *
     * @return  array{read: int, resealed: int, superseded: int}  What the chunk did.
     *
     * @throws  BusinessRecordSchemaUnavailable  When the fenced installation no longer describes the
     *          table this chunk was asked to rotate.
     * @throws  \Kumwe\App\BusinessRecord\Domain\SecretKeyUnavailable  When a row names a key that is not
     *          held; the pass stops rather than looping on a row it can never advance.
     * @throws  \RuntimeException  When a stored envelope fails authenticated decryption.
     *
     * @since   2.0.0
     */
    private function rotateChunk(
        ExecutionContext $context,
        string $site,
        string $definitionId,
        string $table,
        string $activeKeyId,
        int $limit,
    ): array {
        $generation = $this->fence->lock($context, $definitionId);
        $installation = $this->installations->find($definitionId);
        if (
            $installation === null
            || $installation->siteIdentifier !== $site
            || !hash_equals($installation->schemaChecksum, $generation->schemaChecksum)
        ) {
            throw new BusinessRecordSchemaUnavailable('The fenced installation changed during re-keying.');
        }
        $blueprint = $installation->blueprint->table($table)
            ?? throw new BusinessRecordSchemaUnavailable('A re-keyed table is absent from its installation.');
        $fields = $this->secretFields($blueprint);
        if ($fields === [] || count($blueprint->primaryKey) !== 1) {
            throw new BusinessRecordSchemaUnavailable('A re-keyed table has no single-column sealed shape.');
        }
        $identity = $blueprint->physicalColumn($blueprint->primaryKey[0])
            ?? throw new BusinessRecordSchemaUnavailable('A re-keyed table has no usable identity column.');
        $selected = [$identity->physicalName];
        $predicates = [];
        foreach ($fields as $components) {
            foreach ($components as $physical) {
                $selected[] = $physical;
            }
            $predicates[] = sprintf('%s <> ?', $this->quote($components['key_id']));
        }
        $rows = $this->database->fetchAllAssociative(sprintf(
            'SELECT %s FROM %s WHERE %s ORDER BY %s LIMIT %d',
            implode(', ', array_map($this->quote(...), $selected)),
            $this->quote($blueprint->physicalName),
            implode(' OR ', $predicates),
            $this->quote($identity->physicalName),
            $limit,
        ), array_fill(0, count($predicates), $activeKeyId));

        $resealed = 0;
        $superseded = 0;
        $sources = [];
        foreach ($rows as $row) {
            $key = $row[$identity->physicalName] ?? null;
            if (!is_string($key) && !is_int($key)) {
                throw new BusinessRecordSchemaUnavailable('A re-keyed row has no usable identity value.');
            }
            $recordKey = (string) $key;
            $assignments = [];
            $values = [];
            $types = [];
            $guards = [sprintf('%s = ?', $this->quote($identity->physicalName))];
            $guardValues = [$key];
            $guardTypes = [$identity->doctrineType];
            foreach ($fields as $handle => $components) {
                $storedKeyId = $this->text($row[$components['key_id']] ?? null);
                if ($storedKeyId === null || $storedKeyId === $activeKeyId) {
                    continue;
                }
                $sources[$storedKeyId] = true;
                $envelope = $this->envelope($row, $components, $storedKeyId);
                $binding = SecretAssociatedData::for($site, $definitionId, $recordKey, $handle);
                $sealed = $this->cipher->encrypt($this->cipher->decrypt($envelope, $binding), $binding);
                $parts = [
                    'ciphertext' => $sealed->ciphertext,
                    'nonce' => $sealed->nonce,
                    'key_id' => $sealed->keyId,
                    'algorithm' => $sealed->algorithm,
                ];
                foreach ($parts as $component => $value) {
                    $column = $blueprint->physicalColumn($components[$component])
                        ?? throw new BusinessRecordSchemaUnavailable('A sealed component lost its column.');
                    $assignments[] = $this->quote($column->physicalName) . ' = ?';
                    $values[] = $value;
                    $types[] = $column->doctrineType;
                }
                $keyColumn = $blueprint->physicalColumn($components['key_id'])
                    ?? throw new BusinessRecordSchemaUnavailable('A sealed component lost its column.');
                $guards[] = sprintf('%s = ?', $this->quote($components['key_id']));
                $guardValues[] = $storedKeyId;
                $guardTypes[] = $keyColumn->doctrineType;
            }
            if ($assignments === []) {
                continue;
            }
            $affected = $this->database->executeStatement(sprintf(
                'UPDATE %s SET %s WHERE %s',
                $this->quote($blueprint->physicalName),
                implode(', ', $assignments),
                implode(' AND ', $guards),
            ), [...$values, ...$guardValues], [...$types, ...$guardTypes]);
            if ($affected === 1) {
                ++$resealed;
                continue;
            }
            ++$superseded;
        }
        if ($resealed > 0) {
            $this->recordPass($context, $definitionId, $blueprint, $activeKeyId, array_keys($sources), $resealed);
        }

        return ['read' => count($rows), 'resealed' => $resealed, 'superseded' => $superseded];
    }

    /**
     * Rebuild the stored envelope of one sealed field from the row that holds it.
     *
     * Byte columns come back as streams on some drivers, which is why the two binary components are
     * normalized before the envelope re-validates them; a damaged nonce or an unsupported algorithm is
     * refused here rather than being carried into a decryption attempt.
     *
     * @param array<string, mixed> $row Row as the driver returned it.
     * @param   array{ciphertext: string, nonce: string, key_id: string, algorithm: string}  $components
     *          Physical column names of this field's four parts.
     * @param string $storedKeyId Identifier already read from the row.
     *
     * @return  EncryptedEnvelope  The stored envelope, re-validated.
     *
     * @throws  BusinessRecordSchemaUnavailable  When a component is missing or unreadable.
     * @throws  InvalidArgumentException  When the stored parts do not form a valid envelope.
     *
     * @since   2.0.0
     */
    private function envelope(array $row, array $components, string $storedKeyId): EncryptedEnvelope
    {
        $ciphertext = $this->text($row[$components['ciphertext']] ?? null);
        $nonce = $this->text($row[$components['nonce']] ?? null);
        $algorithm = $this->text($row[$components['algorithm']] ?? null);
        if ($ciphertext === null || $nonce === null || $algorithm === null) {
            throw new BusinessRecordSchemaUnavailable('A sealed record column is incomplete.');
        }

        return new EncryptedEnvelope($ciphertext, $nonce, $storedKeyId, $algorithm);
    }

    /**
     * Record what one chunk re-sealed, in the same transaction that re-sealed it.
     *
     * The entry names identifiers and counts only. It deliberately does not name which records were
     * touched or which field handles were sealed: a rotation is a property of the installation, and
     * saying that a particular record's secret moved would disclose something the record itself protects.
     *
     * @param   ExecutionContext        $context       Actor the entry is attributed to.
     * @param   string                  $definitionId  Definition the chunk belonged to.
     * @param   PhysicalTableBlueprint  $table         Table the chunk rewrote.
     * @param   string                  $activeKeyId   Identifier the rows now carry.
     * @param   list<string>            $sources       Identifiers the rows carried before, deduplicated.
     * @param   int                     $resealed      Rows this chunk re-sealed.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function recordPass(
        ExecutionContext $context,
        string $definitionId,
        PhysicalTableBlueprint $table,
        string $activeKeyId,
        array $sources,
        int $resealed,
    ): void {
        sort($sources, SORT_STRING);
        $this->audit->record(new AuditEvent(
            Uuid::uuid7()->toString(),
            $this->clock->now(),
            $context->actorId(),
            'business.record.secret.rekeyed',
            'business_record',
            $definitionId,
            'success',
            [
                'definition_id' => $definitionId,
                'table' => $table->logicalName,
                'sealed_fields' => count($this->secretFields($table)),
                'rows_resealed' => $resealed,
                'from_key_ids' => $sources,
                'to_key_id' => $activeKeyId,
            ],
        ));
    }

    /**
     * Read one stored column as text, draining a stream when the driver hands one back.
     *
     * @param   mixed  $value  Raw driver value.
     *
     * @return  ?string  The column's bytes, or null when the column was NULL.
     *
     * @throws  BusinessRecordSchemaUnavailable  When a binary column cannot be read, or the value is
     *          neither a stream nor a string.
     *
     * @since   2.0.0
     */
    private function text(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_resource($value)) {
            $contents = stream_get_contents($value);

            return is_string($contents)
                ? $contents
                : throw new BusinessRecordSchemaUnavailable('A sealed record column could not be read.');
        }

        return is_string($value)
            ? $value
            : throw new BusinessRecordSchemaUnavailable('A sealed record column holds an unexpected value.');
    }

    /**
     * Quote one identifier for the connected platform.
     *
     * Table and column names reach these statements from the installed blueprint rather than from a
     * request, and quoting keeps a name colliding with a reserved word usable on every supported engine.
     *
     * @param   string  $identifier  Single installed table or column name, never a dotted path.
     *
     * @return  string  The identifier quoted the way the connected driver expects.
     *
     * @throws  \Doctrine\DBAL\Exception  When the platform to quote for cannot be resolved.
     *
     * @since   2.0.0
     */
    private function quote(string $identifier): string
    {
        return $this->database->quoteSingleIdentifier($identifier);
    }
}
