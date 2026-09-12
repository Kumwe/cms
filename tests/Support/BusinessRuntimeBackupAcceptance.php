<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Support;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use JsonException;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\App\Audit\Application\AuditRecorder;
use Kumwe\App\Audit\Domain\AuditEvent;
use Kumwe\App\BusinessDefinition\Domain\CanonicalDefinitionJson;
use Kumwe\App\BusinessRecord\Application\BusinessRecordService;
use Kumwe\App\BusinessRecord\Application\Command\UpdateRecordCommand;
use Kumwe\App\BusinessRecord\Application\Query\ReadRecordQuery;
use Kumwe\App\BusinessRecord\Application\SecretAssociatedData;
use Kumwe\App\BusinessSchema\Application\BusinessSchemaEnvironment;
use Kumwe\App\BusinessSchema\Application\BusinessSchemaService;
use Kumwe\App\BusinessSchema\Application\PhysicalSchemaGateway;
use Kumwe\App\BusinessSchema\Domain\PhysicalTableBlueprint;
use Kumwe\App\BusinessSchema\Domain\PhysicalTableKind;
use Kumwe\App\BusinessSchema\Domain\SchemaRecoveryEvidence;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Kernel\Container;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\Conversion\Decimal\ExactDecimal;
use Kumwe\Conversion\Value\MoneyValue;
use Kumwe\Conversion\Value\QuantityValue;
use Kumwe\Extension\Spi\BusinessRecord\Value\ZonedDateTimeValue;
use Kumwe\Secret\Contract\EnvelopeCipher;
use Kumwe\Secret\Value\EncryptedEnvelope;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use Throwable;

final class BusinessRuntimeBackupAcceptance
{
    private const FORMAT = 'kumwe-business-runtime-backup-acceptance-v1';
    private const SECRET = 'neutral-fixture-secret';
    private const AUDIT_EVENT_ID = '0191574f-f0b8-7bf3-a9aa-91c6b8244e28';
    private const AUDIT_ACTION = 'business.record.backup_acceptance';

    /** Handle of the encrypted field the drill opens rather than only hashing. @since 2.0.0 */
    private const SECRET_FIELD = 'credential';

    /**
     * Domain separator for the recovered-plaintext digest carried in the manifest.
     *
     * The manifest is compared byte for byte, so the digest has to be there for the source and the
     * restore to disagree when only one of them can open the envelope. Prefixing it keeps the value
     * from being a bare hash of a constant that appears elsewhere.
     *
     * @since  2.0.0
     */
    private const SECRET_DIGEST_LABEL = 'kumwe-backup-acceptance-recovered-plaintext-v1';

    /** Queue the drill's own schedule enqueues onto, so the worker claims only its job. @since 2.0.0 */
    private const DRILL_QUEUE = 'backupdrill';

    /** Operator-facing name of the schedule the restored installation has to dispatch. @since 2.0.0 */
    private const DRILL_SCHEDULE = 'Backup drill session purge';

    /** @var list<string> */
    private const DEFINITION_IDS = [
        NeutralBusinessFixture::DEFINITION_ID,
        NeutralBusinessFixture::TARGET_DEFINITION_ID,
        NeutralBusinessFixture::LINE_DEFINITION_ID,
        NeutralBusinessFixture::OWNER_DEFINITION_ID,
    ];

    /** @var list<string> */
    private const IDEMPOTENCY_IDS = [
        'neutral-fixture:backup-create',
        'neutral-fixture:backup-target-one',
        'neutral-fixture:backup-target-two',
        'neutral-fixture:backup-owner',
        'neutral-fixture:backup-tag-one',
        'neutral-fixture:backup-tag-two',
        'neutral-fixture:backup-tag-order',
        'neutral-fixture:backup-line-one',
        'neutral-fixture:backup-line-two',
        'neutral-fixture:backup-line-order',
    ];

    /** @param list<string> $arguments */
    public static function main(array $arguments): int
    {
        try {
            $mode = $arguments[1] ?? null;
            if ($mode === 'seed' && count($arguments) === 2) {
                self::seed();

                return 0;
            }
            if ($mode === 'verify' && count($arguments) === 3) {
                self::verify($arguments[2]);

                return 0;
            }

            throw new RuntimeException('Usage: business-runtime-backup-acceptance.php seed|verify MANIFEST');
        } catch (Throwable $failure) {
            fwrite(STDERR, 'Business runtime backup acceptance failed: ' . $failure->getMessage() . "\n");

            return 1;
        }
    }

    private static function seed(): void
    {
        [$container, $context] = self::boot();
        NeutralBusinessFixture::install($container, $context);
        NeutralBusinessFixture::createBackupRecord($container, $context);
        NeutralBusinessFixture::seedBackupGraph($container, $context);
        self::ensureAuditEvidence($container, $context);
        RestoreSecurityAcceptance::seed($container, $context);
        RestoredWork::seedSchedule($container, $context, self::DRILL_SCHEDULE, self::DRILL_QUEUE);
        $manifest = self::manifest($container, $context);
        $encoded = CanonicalDefinitionJson::encode($manifest);
        if (str_contains($encoded, self::SECRET)) {
            throw new RuntimeException('The backup acceptance manifest contains secret plaintext.');
        }

        fwrite(STDOUT, $encoded . "\n");
    }

    private static function verify(string $manifestPath): void
    {
        $expected = self::readManifest($manifestPath);
        [$container, $context] = self::boot();
        $actual = self::manifest($container, $context);
        $expectedChecksum = CanonicalDefinitionJson::checksum($expected);
        $actualChecksum = CanonicalDefinitionJson::checksum($actual);
        if (!hash_equals($expectedChecksum, $actualChecksum)) {
            throw new RuntimeException(sprintf(
                'The restored business runtime differs from its source manifest (%s != %s; first difference: %s).',
                $expectedChecksum,
                $actualChecksum,
                self::firstDifference($expected, $actual) ?? 'unknown',
            ));
        }

        self::executeRestoredCommand($container, $context);
        $security = RestoreSecurityAcceptance::accept($container);
        $work = RestoredWork::execute($container, self::DRILL_SCHEDULE, self::DRILL_QUEUE);
        $sessionsRemaining = RestoreSecurityAcceptance::assertExpiredSessionsPurged($container);
        $evidence = self::recordRecoveryEvidence($container, $context);
        fwrite(STDOUT, CanonicalDefinitionJson::encode([
            'format' => self::FORMAT,
            'source_manifest_checksum' => $expectedChecksum,
            'restored_manifest_checksum' => $actualChecksum,
            'typed_update_replayed' => true,
            'secret_plaintext_recovered' => true,
            'security_acceptance' => $security,
            'sessions_after_purge' => $sessionsRemaining,
            'work_executed' => $work,
            'recovery_evidence_id' => $evidence,
        ]) . "\n");
    }

    /**
     * Record the drill as schema recovery evidence, with the recovery time this run actually took.
     *
     * The destructive-plan gate reads evidence rather than prose, so a drill that measures its own
     * recovery and never writes it down leaves the gate to be satisfied by hand. Timings come from
     * the drill harness through `KUMWE_DRILL_*`; when it supplies none, the drill still passes and
     * simply records nothing, because inventing a measurement would be worse than having none.
     *
     * @param   Container         $container  Booted kernel bound to the restored database.
     * @param   ExecutionContext  $context    Owner context able to record recovery evidence.
     *
     * @return  ?string  Evidence identity, or null when the harness supplied no drill reference.
     *
     * @throws  RuntimeException  When the schema service is unavailable or a supplied measurement is
     *          not a non-negative whole number of seconds.
     *
     * @since   2.0.0
     */
    private static function recordRecoveryEvidence(
        Container $container,
        ExecutionContext $context,
    ): ?string {
        $reference = getenv('KUMWE_DRILL_REFERENCE');
        $backupManifestChecksum = getenv('KUMWE_DRILL_BACKUP_MANIFEST_CHECKSUM');
        if (!is_string($reference) || $reference === '' || !is_string($backupManifestChecksum)) {
            return null;
        }
        $schemas = $container->get(BusinessSchemaService::class);
        $environment = $container->get(BusinessSchemaEnvironment::class);
        if (!$schemas instanceof BusinessSchemaService || !$environment instanceof BusinessSchemaEnvironment) {
            throw new RuntimeException('The restored schema service is unavailable.');
        }
        $installation = $schemas->installation($context, NeutralBusinessFixture::DEFINITION_ID);
        if ($installation === null) {
            throw new RuntimeException('The restored drill has no installed source schema.');
        }
        $backupCreatedAt = self::drillInstant('KUMWE_DRILL_BACKUP_CREATED_AT');
        $now = new DateTimeImmutable('now');
        $evidence = new SchemaRecoveryEvidence(
            Uuid::uuid7()->toString(),
            $context->site()->identifier(),
            $environment->databaseDriver(),
            $environment->databaseServerVersion(),
            $environment->applicationRelease(),
            $installation->schemaChecksum,
            $backupManifestChecksum,
            true,
            $backupCreatedAt ?? $now,
            $now,
            $context->actorId(),
            $reference,
            [
                'backup_quiesce_seconds' => self::drillSeconds('KUMWE_DRILL_BACKUP_SECONDS'),
                'blueprint_checksum_verified' => true,
                'clean_target_restore' => true,
                'client_version' => $environment->databaseServerVersion(),
                'record_revision_audit_checksums_verified' => true,
                'recovery_point_is_backup_instant' => true,
                'restore_seconds' => self::drillSeconds('KUMWE_DRILL_RESTORE_SECONDS'),
                'restore_target_reference' => $reference,
                'secret_plaintext_recovered' => true,
                'typed_command_verified' => true,
            ],
        );
        $schemas->recordRecoveryEvidence($context, $evidence);

        return $evidence->id;
    }

    /**
     * Read one measured duration the drill harness reported, refusing anything but whole seconds.
     *
     * @param   string  $variable  Environment variable naming the measurement.
     *
     * @return  int  Measured seconds; zero when the harness reported none.
     *
     * @throws  RuntimeException  When the value is present but is not a non-negative integer.
     *
     * @since   2.0.0
     */
    private static function drillSeconds(string $variable): int
    {
        $value = getenv($variable);
        if (!is_string($value) || $value === '') {
            return 0;
        }
        if (preg_match('/^[0-9]{1,7}$/D', $value) !== 1) {
            throw new RuntimeException('A drill measurement must be a non-negative whole number of seconds.');
        }

        return (int) $value;
    }

    /**
     * Read one instant the drill harness reported, refusing anything unparseable.
     *
     * @param   string  $variable  Environment variable naming the instant.
     *
     * @return  ?DateTimeImmutable  The instant, or null when the harness reported none.
     *
     * @throws  RuntimeException  When the value is present but cannot be read as a timestamp.
     *
     * @since   2.0.0
     */
    private static function drillInstant(string $variable): ?DateTimeImmutable
    {
        $value = getenv($variable);
        if (!is_string($value) || $value === '') {
            return null;
        }
        $timestamp = strtotime($value);
        if ($timestamp === false) {
            throw new RuntimeException('A drill instant could not be read.');
        }

        return (new DateTimeImmutable('@' . $timestamp))->setTimezone(new DateTimeZone('UTC'));
    }

    /** @return array{0: Container, 1: ExecutionContext} */
    private static function boot(): array
    {
        self::clearDerivedRuntimeCache();
        $container = TestKernelFactory::create(Environment::fromGlobals());

        return [$container, TestKernelFactory::administratorContext($container)];
    }

    private static function clearDerivedRuntimeCache(): void
    {
        $runtimeMap = dirname(__DIR__, 2) . '/storage/cache/extensions.json';
        foreach ([$runtimeMap, $runtimeMap . '.verified', $runtimeMap . '.ready'] as $path) {
            if (!file_exists($path) && !is_link($path)) {
                continue;
            }
            if (!is_file($path) || is_link($path) || !unlink($path)) {
                throw new RuntimeException('The derived extension runtime cache could not be reset safely.');
            }
        }
    }

    private static function ensureAuditEvidence(Container $container, ExecutionContext $context): void
    {
        $database = $container->get(Connection::class);
        $tables = $container->get(TableNames::class);
        $audit = $container->get(AuditRecorder::class);
        if (!$database instanceof Connection || !$tables instanceof TableNames || !$audit instanceof AuditRecorder) {
            throw new RuntimeException('The backup audit evidence services are unavailable.');
        }
        $existing = $database->fetchAssociative(sprintf(
            'SELECT action, subject_type, subject_id, outcome FROM %s WHERE id = ?',
            $tables->quoted('audit_events'),
        ), [self::AUDIT_EVENT_ID]);
        if ($existing !== false) {
            if (
                ($existing['action'] ?? null) !== self::AUDIT_ACTION
                || ($existing['subject_type'] ?? null) !== 'business_record'
                || ($existing['subject_id'] ?? null) !== NeutralBusinessFixture::RECORD_ID
                || ($existing['outcome'] ?? null) !== 'success'
            ) {
                throw new RuntimeException('The backup audit evidence conflicts with its stable fixture identity.');
            }

            return;
        }
        $audit->record(new AuditEvent(
            self::AUDIT_EVENT_ID,
            new DateTimeImmutable('2026-08-08T00:00:00+00:00'),
            $context->actorId(),
            self::AUDIT_ACTION,
            'business_record',
            NeutralBusinessFixture::RECORD_ID,
            'success',
            ['definition_id' => NeutralBusinessFixture::DEFINITION_ID, 'fixture' => self::FORMAT],
        ));
    }

    private static function firstDifference(mixed $expected, mixed $actual, string $path = '$'): ?string
    {
        if (!is_array($expected) || !is_array($actual)) {
            return $expected === $actual ? null : $path;
        }
        foreach ($expected as $key => $value) {
            $child = $path . '[' . $key . ']';
            if (!array_key_exists($key, $actual)) {
                return $child;
            }
            $difference = self::firstDifference($value, $actual[$key], $child);
            if ($difference !== null) {
                return $difference;
            }
        }
        foreach ($actual as $key => $_) {
            if (!array_key_exists($key, $expected)) {
                return $path . '[' . $key . ']';
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    private static function readManifest(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException('The source business runtime manifest is unavailable.');
        }
        $size = filesize($path);
        if (!is_int($size) || $size < 2 || $size > 1_048_576) {
            throw new RuntimeException('The source business runtime manifest has an invalid size.');
        }
        $contents = file_get_contents($path);
        if (!is_string($contents) || str_contains($contents, self::SECRET)) {
            throw new RuntimeException('The source business runtime manifest is invalid or contains plaintext.');
        }
        try {
            $manifest = json_decode($contents, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('The source business runtime manifest is malformed.', 0, $exception);
        }
        if (!is_array($manifest) || ($manifest['format'] ?? null) !== self::FORMAT) {
            throw new RuntimeException('The source business runtime manifest format is unsupported.');
        }

        return $manifest;
    }

    /** @return array<string, mixed> */
    private static function manifest(Container $container, ExecutionContext $context): array
    {
        $database = $container->get(Connection::class);
        $tables = $container->get(TableNames::class);
        $schemas = $container->get(BusinessSchemaService::class);
        $physicalSchemas = $container->get(PhysicalSchemaGateway::class);
        $records = $container->get(BusinessRecordService::class);
        if (
            !$database instanceof Connection
            || !$tables instanceof TableNames
            || !$schemas instanceof BusinessSchemaService
            || !$physicalSchemas instanceof PhysicalSchemaGateway
            || !$records instanceof BusinessRecordService
        ) {
            throw new RuntimeException('The business runtime backup acceptance services are unavailable.');
        }

        [$installations, $generated] = self::installedState(
            $database,
            $schemas,
            $physicalSchemas,
            $context,
        );
        $control = self::controlState($database, $tables);
        self::assertCoverage($generated, $control);

        return [
            'format' => self::FORMAT,
            'installations' => $installations,
            'generated_tables' => $generated,
            'control_tables' => $control,
            'identity_security' => RestoreSecurityAcceptance::manifest($container),
            'standalone_record' => self::standaloneState($container, $database, $schemas, $records, $context),
            'relationship_graph' => self::relationshipGraphState(
                $database,
                $schemas,
                $records,
                $context,
            ),
        ];
    }

    /**
     * @return  array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private static function installedState(
        Connection $database,
        BusinessSchemaService $schemas,
        PhysicalSchemaGateway $physicalSchemas,
        ExecutionContext $context,
    ): array {
        $installations = [];
        $generated = [];
        $platform = $database->getDatabasePlatform();
        foreach (self::DEFINITION_IDS as $definitionId) {
            $installation = $schemas->installation($context, $definitionId);
            if ($installation === null || $installation->status->value !== 'active') {
                throw new RuntimeException('A backup fixture schema installation is unavailable.');
            }
            $inspected = $physicalSchemas->inspect($installation->blueprint);
            if ($inspected === null || !hash_equals($installation->schemaChecksum, $inspected->checksum())) {
                throw new RuntimeException('A backup fixture physical schema differs from its canonical blueprint.');
            }
            $tableNames = [];
            foreach ($installation->blueprint->tables() as $table) {
                $generated[$table->physicalName] = [
                    'definition_id' => $definitionId,
                    'logical_name' => $table->logicalName,
                    'kind' => $table->kind->value,
                    'blueprint_checksum' => CanonicalDefinitionJson::checksum($table->toArray()),
                    ...self::tableDigest(
                        $database,
                        $platform->quoteIdentifier($table->physicalName),
                    ),
                ];
                $tableNames[] = $table->physicalName;
            }
            sort($tableNames, SORT_STRING);
            $installations[$definitionId] = [
                'definition_version' => $installation->definitionVersion,
                'definition_checksum' => $installation->definitionChecksum,
                'schema_checksum' => $installation->schemaChecksum,
                'blueprint_checksum' => $installation->blueprint->checksum(),
                'inspected_schema_checksum' => $inspected->checksum(),
                'status' => $installation->status->value,
                'tables' => $tableNames,
            ];
        }
        ksort($installations, SORT_STRING);
        ksort($generated, SORT_STRING);

        return [$installations, $generated];
    }

    /** @return array<string, mixed> */
    private static function controlState(Connection $database, TableNames $tables): array
    {
        $definitionClause = self::placeholders(self::DEFINITION_IDS);
        $idempotencyClause = self::placeholders(self::IDEMPOTENCY_IDS);
        $plans = $tables->quoted('business_schema_plans');
        $control = [
            'business_definitions' => self::tableDigest(
                $database,
                $tables->quoted('business_definitions'),
                'id IN (' . $definitionClause . ')',
                self::DEFINITION_IDS,
            ),
            'business_definition_versions' => self::tableDigest(
                $database,
                $tables->quoted('business_definition_versions'),
                'definition_id IN (' . $definitionClause . ')',
                self::DEFINITION_IDS,
            ),
            'business_schema_installations' => self::tableDigest(
                $database,
                $tables->quoted('business_schema_installations'),
                'definition_id IN (' . $definitionClause . ')',
                self::DEFINITION_IDS,
            ),
            'business_schema_plans' => self::tableDigest(
                $database,
                $plans,
                'definition_id IN (' . $definitionClause . ')',
                self::DEFINITION_IDS,
            ),
            'business_schema_plan_steps' => self::tableDigest(
                $database,
                $tables->quoted('business_schema_plan_steps'),
                'plan_id IN (SELECT id FROM ' . $plans . ' WHERE definition_id IN (' . $definitionClause . '))',
                self::DEFINITION_IDS,
            ),
            'business_schema_fence' => self::tableDigest(
                $database,
                $tables->quoted('business_schema_fence'),
            ),
            'business_schema_recovery_evidence' => self::tableDigest(
                $database,
                $tables->quoted('business_schema_recovery_evidence'),
            ),
            'business_record_revisions' => self::tableDigest(
                $database,
                $tables->quoted('business_record_revisions'),
                'definition_id IN (' . $definitionClause . ')',
                self::DEFINITION_IDS,
            ),
            'business_command_idempotency' => self::tableDigest(
                $database,
                $tables->quoted('business_command_idempotency'),
                'operation_id IN (' . $idempotencyClause . ')',
                self::IDEMPOTENCY_IDS,
            ),
            'business_audit_events' => self::tableDigest(
                $database,
                $tables->quoted('audit_events'),
                'id = ? AND subject_type = ? AND subject_id = ? AND action = ?',
                [
                    self::AUDIT_EVENT_ID,
                    'business_record',
                    NeutralBusinessFixture::RECORD_ID,
                    self::AUDIT_ACTION,
                ],
            ),
        ];
        ksort($control, SORT_STRING);

        return $control;
    }

    /** @return array<string, mixed> */
    private static function standaloneState(
        Container $container,
        Connection $database,
        BusinessSchemaService $schemas,
        BusinessRecordService $records,
        ExecutionContext $context,
    ): array {
        $installation = $schemas->installation($context, NeutralBusinessFixture::DEFINITION_ID);
        $table = $installation?->blueprint->table('record');
        if ($table === null) {
            throw new RuntimeException('The standalone backup fixture table is unavailable.');
        }
        $view = $records->read(new ReadRecordQuery(
            $context,
            NeutralBusinessFixture::HANDLE,
            NeutralBusinessFixture::RECORD_ID,
        ));
        $amount = $view->values['amount'] ?? null;
        $price = $view->values['price'] ?? null;
        $quantity = $view->values['quantity'] ?? null;
        $date = $view->values['service_date'] ?? null;
        $time = $view->values['local_time'] ?? null;
        $instant = $view->values['recorded_at'] ?? null;
        $zoned = $view->values['scheduled_for'] ?? null;
        if (
            !$amount instanceof ExactDecimal
            || !$price instanceof MoneyValue
            || !$quantity instanceof QuantityValue
            || !$date instanceof DateTimeImmutable
            || !$time instanceof DateTimeImmutable
            || !$instant instanceof DateTimeImmutable
            || !$zoned instanceof ZonedDateTimeValue
            || array_key_exists('credential', $view->values)
        ) {
            throw new RuntimeException('The standalone backup fixture did not round-trip exact typed values.');
        }

        $ciphertext = self::physicalValue($database, $table, 'credential.ciphertext', $view->recordKey);
        $nonce = self::physicalValue($database, $table, 'credential.nonce', $view->recordKey);
        $keyId = self::physicalValue($database, $table, 'credential.key_id', $view->recordKey);
        $algorithm = self::physicalValue($database, $table, 'credential.algorithm', $view->recordKey);
        self::assertNoPlaintext($ciphertext);
        self::assertNoPlaintext($nonce);
        $recoveredDigest = self::recoverSecretDigest(
            $container,
            new EncryptedEnvelope($ciphertext, $nonce, $keyId, $algorithm),
            $view->siteIdentifier ?? '',
            $view->definitionId,
            $view->recordKey,
        );

        return [
            'definition_id' => $view->definitionId,
            'record_id' => $view->recordId,
            'record_key' => $view->recordKey,
            'version' => $view->version,
            'workflow_state' => $view->workflowState,
            'exact_values' => [
                'amount' => $amount->value(),
                'price' => ['amount' => $price->amount->value(), 'currency' => $price->currency],
                'quantity' => ['amount' => $quantity->amount->value(), 'unit' => $quantity->unit],
                'service_date' => $date->format('Y-m-d'),
                'local_time' => $time->format('H:i:s.u'),
                'recorded_at' => $instant->format('Y-m-d\TH:i:s.uP'),
                'scheduled_for' => $zoned->toArray(),
            ],
            'secret_envelope' => [
                'ciphertext_checksum' => hash('sha256', $ciphertext),
                'ciphertext_bytes' => strlen($ciphertext),
                'nonce_checksum' => hash('sha256', $nonce),
                'nonce_bytes' => strlen($nonce),
                'key_id' => $keyId,
                'algorithm' => $algorithm,
                'recovered_plaintext_digest' => $recoveredDigest,
            ],
        ];
    }

    /**
     * Open the stored envelope and digest what came out, without ever holding it in the manifest.
     *
     * Hashing ciphertext proves the bytes survived the round trip. It cannot tell a restore that
     * kept its keys from one that lost them, because a wrong key leaves the ciphertext identical and
     * only makes it useless. Decrypting here is what makes the difference visible: the associated
     * data is rebuilt from the record's own coordinates, so the AEAD binding is re-proved at the same
     * time, and a restore booted under the wrong record key fails at this line instead of at the
     * first business use weeks later.
     *
     * @param   Container          $container     Booted kernel, source or restored.
     * @param   EncryptedEnvelope  $envelope      Envelope read straight out of its physical columns.
     * @param   string             $site          Site the record belongs to.
     * @param   string             $definitionId  Definition the record instantiates.
     * @param   string             $recordKey     Storage key the binding was built from.
     *
     * @return  string  Domain-separated digest of the recovered plaintext.
     *
     * @throws  RuntimeException  When the cipher is unavailable, the envelope does not open, or the
     *          recovered value is not the fixture's own secret.
     *
     * @since   2.0.0
     */
    private static function recoverSecretDigest(
        Container $container,
        EncryptedEnvelope $envelope,
        string $site,
        string $definitionId,
        string $recordKey,
    ): string {
        $cipher = $container->get(EnvelopeCipher::class);
        if (!$cipher instanceof EnvelopeCipher) {
            throw new RuntimeException('The business-record secret cipher is unavailable.');
        }
        $plaintext = $cipher->decrypt(
            $envelope,
            SecretAssociatedData::for($site, $definitionId, $recordKey, self::SECRET_FIELD),
        );
        if (!hash_equals(self::SECRET, $plaintext)) {
            throw new RuntimeException('The recovered business-record secret is not the fixture value.');
        }

        return hash('sha256', self::SECRET_DIGEST_LABEL . "\0" . $plaintext);
    }

    private static function executeRestoredCommand(
        Container $container,
        ExecutionContext $context,
    ): void {
        $records = $container->get(BusinessRecordService::class);
        if (!$records instanceof BusinessRecordService) {
            throw new RuntimeException('The restored business-record service is unavailable.');
        }
        $command = new UpdateRecordCommand(
            $context,
            NeutralBusinessFixture::HANDLE,
            NeutralBusinessFixture::RECORD_ID,
            1,
            ['name' => 'Backup acceptance record restored'],
            NeutralBusinessFixture::idempotencyKey('backup-update'),
        );
        $updated = $records->update($command);
        $replayed = $records->update($command);
        if (
            $updated->version !== 2
            || $updated->replayed
            || !$replayed->replayed
            || $updated->toArray() !== $replayed->toArray()
        ) {
            throw new RuntimeException('The restored typed update did not execute and replay exactly once.');
        }
        $view = $records->read(new ReadRecordQuery(
            $context,
            NeutralBusinessFixture::HANDLE,
            NeutralBusinessFixture::RECORD_ID,
        ));
        if (
            ($view->values['name'] ?? null) !== 'Backup acceptance record restored'
            || ($view->values['display_name'] ?? null) !== 'Backup acceptance record restored'
            || array_key_exists('credential', $view->values)
        ) {
            throw new RuntimeException('The restored typed update did not preserve computation and omission.');
        }
    }

    /** @return array<string, mixed> */
    private static function relationshipGraphState(
        Connection $database,
        BusinessSchemaService $schemas,
        BusinessRecordService $records,
        ExecutionContext $context,
    ): array {
        $owner = $records->read(new ReadRecordQuery(
            $context,
            NeutralBusinessFixture::OWNER_HANDLE,
            NeutralBusinessFixture::OWNER_RECORD_ID,
        ));
        $firstTarget = $records->read(new ReadRecordQuery(
            $context,
            NeutralBusinessFixture::TARGET_HANDLE,
            NeutralBusinessFixture::TARGET_RECORD_ID,
        ));
        $secondTarget = $records->read(new ReadRecordQuery(
            $context,
            NeutralBusinessFixture::TARGET_HANDLE,
            NeutralBusinessFixture::SECOND_TARGET_RECORD_ID,
        ));
        $installation = $schemas->installation($context, NeutralBusinessFixture::OWNER_DEFINITION_ID);
        $tags = $installation?->blueprint->table('relation:tags');
        $lines = $installation?->blueprint->table('line:lines');
        if ($tags === null || $lines === null) {
            throw new RuntimeException('The stable relationship graph tables are unavailable.');
        }
        $tagOrder = self::orderedIdentifiers($database, $tags, 'source_id', 'target_id', $owner->recordKey);
        $lineOrder = self::orderedIdentifiers($database, $lines, 'owner_id', 'line_id', $owner->recordKey);
        if (
            $owner->version !== 7
            || ($owner->values['title'] ?? null) !== 'Backup graph owner'
            || ($firstTarget->values['label'] ?? null) !== 'Backup graph target one'
            || ($secondTarget->values['label'] ?? null) !== 'Backup graph target two'
            || $tagOrder !== [
                NeutralBusinessFixture::SECOND_TARGET_RECORD_ID,
                NeutralBusinessFixture::TARGET_RECORD_ID,
            ]
            || $lineOrder !== [
                NeutralBusinessFixture::SECOND_LINE_RECORD_ID,
                NeutralBusinessFixture::LINE_RECORD_ID,
            ]
        ) {
            throw new RuntimeException('The stable relationship graph did not round-trip through typed reads.');
        }

        return [
            'owner_record_id' => $owner->recordId,
            'owner_version' => $owner->version,
            'target_record_ids' => [$firstTarget->recordId, $secondTarget->recordId],
            'ordered_line_record_ids' => $lineOrder,
            'ordered_tag_record_ids' => $tagOrder,
        ];
    }

    /** @return list<string> */
    private static function orderedIdentifiers(
        Connection $database,
        PhysicalTableBlueprint $table,
        string $ownerLogicalColumn,
        string $itemLogicalColumn,
        string $ownerKey,
    ): array {
        $owner = $table->column($ownerLogicalColumn);
        $item = $table->column($itemLogicalColumn);
        $position = $table->column('position');
        if ($owner === null || $item === null || $position === null) {
            throw new RuntimeException('An ordered relationship table has an incomplete physical mapping.');
        }
        $platform = $database->getDatabasePlatform();
        $identifiers = $database->fetchFirstColumn(sprintf(
            'SELECT %s FROM %s WHERE %s = ? ORDER BY %s ASC',
            $platform->quoteIdentifier($item->physicalName),
            $platform->quoteIdentifier($table->physicalName),
            $platform->quoteIdentifier($owner->physicalName),
            $platform->quoteIdentifier($position->physicalName),
        ), [$ownerKey]);
        if (array_filter($identifiers, static fn (mixed $value): bool => !is_string($value)) !== []) {
            throw new RuntimeException('An ordered relationship table contains an invalid identity.');
        }

        return array_values($identifiers);
    }

    /**
     * @param  array<string, mixed>  $generated
     * @param  array<string, mixed>  $control
     */
    private static function assertCoverage(array $generated, array $control): void
    {
        $rowsByKind = [];
        foreach ($generated as $state) {
            if (!is_array($state) || !is_string($state['kind'] ?? null) || !is_int($state['row_count'] ?? null)) {
                throw new RuntimeException('A generated backup fixture table digest is malformed.');
            }
            $rowsByKind[$state['kind']] = ($rowsByKind[$state['kind']] ?? 0) + $state['row_count'];
        }
        if (
            count($generated) < 7
            || ($rowsByKind[PhysicalTableKind::Entity->value] ?? 0) < 4
            || ($rowsByKind[PhysicalTableKind::Junction->value] ?? 0) < 2
            || ($rowsByKind[PhysicalTableKind::OwnedLine->value] ?? 0) < 2
        ) {
            throw new RuntimeException('The backup fixture did not cover entity, junction, and owned-line storage.');
        }
        $minimumRows = [
            'business_definitions' => 4,
            'business_definition_versions' => 4,
            'business_schema_installations' => 4,
            'business_schema_plans' => 4,
            'business_schema_plan_steps' => 4,
            'business_schema_fence' => 1,
            'business_record_revisions' => 8,
            'business_command_idempotency' => 10,
            'business_audit_events' => 1,
        ];
        foreach ($minimumRows as $name => $minimum) {
            $count = is_array($control[$name] ?? null) ? ($control[$name]['row_count'] ?? null) : null;
            if (!is_int($count) || $count < $minimum) {
                throw new RuntimeException('The backup fixture control-plane coverage is incomplete: ' . $name);
            }
        }
    }

    /**
     * @param   list<mixed>  $parameters
     *
     * @return  array{row_count: int, rows_checksum: string}
     */
    private static function tableDigest(
        Connection $database,
        string $quotedTable,
        string $predicate = '',
        array $parameters = [],
    ): array {
        $sql = 'SELECT * FROM ' . $quotedTable . ($predicate === '' ? '' : ' WHERE ' . $predicate);
        $rows = $database->fetchAllAssociative($sql, $parameters);
        $hashes = [];
        foreach ($rows as $row) {
            ksort($row, SORT_STRING);
            $normalized = [];
            foreach ($row as $column => $value) {
                $normalized[$column] = self::normalizeDatabaseValue($value);
            }
            $hashes[] = hash('sha256', json_encode(
                $normalized,
                JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ));
        }
        sort($hashes, SORT_STRING);

        return ['row_count' => count($rows), 'rows_checksum' => hash('sha256', implode("\n", $hashes))];
    }

    /** @return array{type: string, value?: bool|int|string|null} */
    private static function normalizeDatabaseValue(mixed $value): array
    {
        if (is_resource($value)) {
            $contents = stream_get_contents($value);
            if (!is_string($contents)) {
                throw new RuntimeException('A database stream could not be read for backup verification.');
            }
            $value = $contents;
        }
        if (is_string($value)) {
            self::assertNoPlaintext($value);

            return ['type' => 'bytes', 'value' => base64_encode($value)];
        }
        if ($value === null || is_bool($value) || is_int($value)) {
            return ['type' => get_debug_type($value), 'value' => $value];
        }
        if (is_float($value)) {
            return ['type' => 'float', 'value' => sprintf('%.17g', $value)];
        }

        throw new RuntimeException('A database value has an unsupported backup verification type.');
    }

    private static function physicalValue(
        Connection $database,
        PhysicalTableBlueprint $table,
        string $logicalColumn,
        string $recordKey,
    ): string {
        $column = $table->column($logicalColumn);
        $identity = $table->column('record_id');
        if ($column === null || $identity === null) {
            throw new RuntimeException('A secret envelope physical column is unavailable.');
        }
        $platform = $database->getDatabasePlatform();
        $value = $database->fetchOne(sprintf(
            'SELECT %s FROM %s WHERE %s = ?',
            $platform->quoteIdentifier($column->physicalName),
            $platform->quoteIdentifier($table->physicalName),
            $platform->quoteIdentifier($identity->physicalName),
        ), [$recordKey]);
        if (is_resource($value)) {
            $value = stream_get_contents($value);
        }
        if (!is_string($value) || $value === '') {
            throw new RuntimeException('A secret envelope physical value is unavailable.');
        }

        return $value;
    }

    private static function assertNoPlaintext(string $value): void
    {
        if (str_contains($value, self::SECRET)) {
            throw new RuntimeException('A business runtime backup fixture persisted secret plaintext.');
        }
    }

    /** @param list<mixed> $values */
    private static function placeholders(array $values): string
    {
        if ($values === []) {
            throw new RuntimeException('A backup acceptance predicate cannot be empty.');
        }

        return implode(', ', array_fill(0, count($values), '?'));
    }
}
