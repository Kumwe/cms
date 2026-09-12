<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\BusinessRecord;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Kumwe\App\Kernel\Container;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Context\Value\SiteContext;
use Kumwe\App\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\App\BusinessRecord\Application\BusinessRecordService;
use Kumwe\App\BusinessRecord\Application\Command\CreateRecordCommand;
use Kumwe\App\BusinessRecord\Application\RecordSecretRotation;
use Kumwe\App\BusinessRecord\Application\SecretAssociatedData;
use Kumwe\App\BusinessRecord\Infrastructure\Persistence\DoctrineRecordSecretRotation;
use Kumwe\App\BusinessSchema\Application\BusinessSchemaInstallationRepository;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Tests\Support\NeutralBusinessFixture;
use Kumwe\App\Tests\Support\TestKernelFactory;
use Kumwe\Secret\Contract\EnvelopeCipher;
use Kumwe\Secret\Contract\KeyProvider;
use Kumwe\Secret\Value\EncryptedEnvelope;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use RuntimeException;

/**
 * Proves record secrets survive a key rotation on a real database, one bounded pass at a time.
 *
 * @since  2.0.0
 */
#[CoversClass(DoctrineRecordSecretRotation::class)]
final class RecordSecretRotationIntegrationTest extends TestCase
{
    /**
     * Run-unique site that keeps the rotation campaign independent of durable fixtures from earlier processes.
     *
     * @var    SiteContext|null
     * @since  2.0.0
     */
    private static ?SiteContext $rotationSite = null;

    /**
     * Readable stem the rotated deployment's record secret is assembled from.
     *
     * It is repeated rather than written out at full length so no line of this file resembles a
     * credential to a secret scanner, and so a reader can see nothing here was ever real key material.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string RECORD_SECRET_STEM = 'rotation-fixture-';

    /**
     * Identifier that secret is configured under.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string ROTATED_KEY_ID = 'record-encryption-v2';

    /**
     * Bounded passes the rollback may spend before it reports the installation as still stranded.
     *
     * Generous enough that no plausible fixture database exhausts it, finite so a rotation that cannot
     * make progress fails the test instead of spinning.
     *
     * @var    int
     * @since  2.0.0
     */
    private const int ROLLBACK_PASSES = 64;

    /**
     * Restore the process environment so a later test still boots the unrotated deployment.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    protected function tearDown(): void
    {
        putenv('RECORD_ENCRYPTION_KEY');
        putenv('RECORD_ENCRYPTION_KEY_ID');
    }

    /**
     * Hand the isolated fixture site back sealed under the key an unconfigured deployment holds.
     *
     * A pass covers every installation of the caller's site. These tests therefore create one
     * run-unique site and keep both scenarios there: durable withdrawn definitions from an earlier
     * idempotency pass cannot be mistaken for unfinished work, while every record created by this
     * class still participates in the same site-wide campaign. The dedicated key material exists only
     * inside this process, so a rotation left behind would strand this class's fixture records.
     *
     * Rolling it back is the same supported operation in the other direction, and is worth proving in
     * its own right: with no active key configured the ring makes `application-secret-v1` active again
     * while `RECORD_ENCRYPTION_PREVIOUS_KEYS` keeps the dedicated key readable, which is exactly the
     * shape an operator needs to abandon a rotation part way through. It runs once the whole class is
     * finished rather than after each test, so the tests keep the state they hand each other.
     *
     * @return  void
     *
     * @throws  RuntimeException  When the rotation port is unavailable, or a bounded campaign does not
     *          finish, which would leave the shared installation sealed under a discarded key.
     *
     * @since   2.0.0
     */
    public static function tearDownAfterClass(): void
    {
        putenv('RECORD_ENCRYPTION_KEY');
        putenv('RECORD_ENCRYPTION_KEY_ID');
        if (self::$rotationSite === null) {
            return;
        }
        // Both halves are fixture literals drawn from `[A-Za-z0-9-]`, so the object needs no escaping.
        putenv(sprintf(
            'RECORD_ENCRYPTION_PREVIOUS_KEYS={"%s":"%s"}',
            self::ROTATED_KEY_ID,
            str_repeat(self::RECORD_SECRET_STEM, 3),
        ));

        try {
            $container = TestKernelFactory::create(Environment::fromGlobals());
            $rotation = $container->get(RecordSecretRotation::class);
            if (!$rotation instanceof RecordSecretRotation) {
                throw new RuntimeException('The record secret rotation port is unavailable.');
            }
            $context = self::administratorContextForSite($container, self::$rotationSite);
            for ($pass = 0; $pass < self::ROLLBACK_PASSES; $pass++) {
                if ($rotation->rotate($context, 500)->complete) {
                    return;
                }
            }

            throw new RuntimeException('The fixture rotation did not roll back within its pass budget.');
        } finally {
            putenv('RECORD_ENCRYPTION_PREVIOUS_KEYS');
        }
    }

    /**
     * Prove envelopes written under the application secret survive the move to dedicated key material.
     *
     * The first container is the deployment as it shipped: one key, derived from `APP_SECRET`, stamped
     * `application-secret-v1`. The second is the same installation after an operator configured dedicated
     * key material and restarted, with no data migration in between. Everything already stored has to keep
     * opening across that restart, and everything written afterwards has to carry the new identifier.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testStoredSecretsMoveOntoTheActiveKeyWithoutBecomingUnreadable(): void
    {
        $legacy = TestKernelFactory::create(Environment::fromGlobals());
        $context = $this->rotationContext($legacy);
        $suffix = $this->suffix();
        $definition = NeutralBusinessFixture::install(
            $legacy,
            $context,
            $this->rotationDocument($suffix),
        );
        $recordKey = $this->createRecord($legacy, $context, $definition, $suffix, 'first');

        self::assertSame(
            'application-secret-v1',
            $this->storedKeyId($legacy, $definition, $recordKey),
            'The unrotated deployment must still seal under the application-secret key.',
        );
        $sealed = $this->storedEnvelope($legacy, $definition, $recordKey);

        $rotated = $this->rotatedContainer();
        $rotatedContext = $this->rotationContext($rotated);
        $provider = $rotated->get(KeyProvider::class);
        self::assertInstanceOf(KeyProvider::class, $provider);
        self::assertSame(self::ROTATED_KEY_ID, $provider->activeKeyId());
        self::assertContains('application-secret-v1', $provider->knownKeyIds());
        self::assertSame(
            'neutral-fixture-secret',
            $this->open($rotated, $sealed, $definition, $recordKey),
            'A pre-rotation envelope must still open once dedicated key material is active.',
        );

        $rotation = $rotated->get(RecordSecretRotation::class);
        self::assertInstanceOf(RecordSecretRotation::class, $rotation);
        $report = $rotation->rotate($rotatedContext, 500);

        self::assertSame(self::ROTATED_KEY_ID, $report->activeKeyId);
        self::assertGreaterThanOrEqual(1, $report->resealed);
        self::assertSame(0, $report->superseded);
        self::assertTrue($report->complete, 'A pass with budget to spare must report itself complete.');

        $resealed = $this->storedEnvelope($rotated, $definition, $recordKey);
        self::assertSame(self::ROTATED_KEY_ID, $resealed->keyId);
        self::assertNotSame($sealed->ciphertext, $resealed->ciphertext);
        self::assertNotSame($sealed->nonce, $resealed->nonce);
        self::assertSame(
            'neutral-fixture-secret',
            $this->open($rotated, $resealed, $definition, $recordKey),
            'A re-sealed envelope must hold exactly the secret it held before.',
        );

        $second = $rotation->rotate($rotatedContext, 500);
        self::assertSame(0, $second->examined, 'A finished installation must give a later pass nothing to do.');
        self::assertSame(0, $second->resealed);
        self::assertTrue($second->complete);

        $audited = $this->database($rotated)->fetchOne(sprintf(
            'SELECT COUNT(*) FROM %s WHERE action = ?',
            $this->tables($rotated)->quoted('audit_events'),
        ), ['business.record.secret.rekeyed']);
        self::assertGreaterThanOrEqual(1, (int) $audited, 'Every re-keying chunk must be recorded.');
    }

    /**
     * Prove a pass stopped short leaves a consistent state that the next pass simply continues.
     *
     * The budget is set to one row, which is the strongest form of the interruption case: every pass
     * commits exactly one record and stops. Both the rows already moved and the rows still waiting have to
     * be readable at every point in between, because in production that is what a killed worker leaves.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnInterruptedRotationResumesWithoutLosingOrDoublingWork(): void
    {
        $legacy = TestKernelFactory::create(Environment::fromGlobals());
        $context = $this->rotationContext($legacy);
        $suffix = $this->suffix();
        $definition = NeutralBusinessFixture::install(
            $legacy,
            $context,
            $this->rotationDocument($suffix),
        );
        $keys = [];
        foreach (['alpha', 'beta', 'gamma'] as $name) {
            $keys[] = $this->createRecord($legacy, $context, $definition, $suffix, $name);
        }

        $rotated = $this->rotatedContainer();
        $rotatedContext = $this->rotationContext($rotated);
        $rotation = $rotated->get(RecordSecretRotation::class);
        self::assertInstanceOf(RecordSecretRotation::class, $rotation);

        $moved = 0;
        for ($pass = 0; $pass < 3; $pass++) {
            $report = $rotation->rotate($rotatedContext, 1);
            $moved += $report->resealed;
            self::assertLessThanOrEqual(1, $report->examined, 'A pass must never exceed the budget it was given.');
            foreach ($keys as $key) {
                self::assertSame(
                    'neutral-fixture-secret',
                    $this->open($rotated, $this->storedEnvelope($rotated, $definition, $key), $definition, $key),
                    'Every record must stay readable while a rotation is only partly done.',
                );
            }
            if ($pass < 2) {
                self::assertFalse($report->complete, 'A pass that spent its whole budget cannot be complete.');
            }
        }

        self::assertSame(3, $moved, 'Three bounded passes must move exactly the three stored secrets.');
        foreach ($keys as $key) {
            self::assertSame(self::ROTATED_KEY_ID, $this->storedKeyId($rotated, $definition, $key));
        }
        self::assertTrue($rotation->rotate($rotatedContext, 10)->complete);
    }

    /**
     * Boot a second container for the same installation with dedicated record key material configured.
     *
     * @return  Container  Container whose record key ring has the dedicated key active and the
     *          application-secret key retired.
     *
     * @since   2.0.0
     */
    private function rotatedContainer(): Container
    {
        putenv('RECORD_ENCRYPTION_KEY=' . str_repeat(self::RECORD_SECRET_STEM, 3));
        putenv('RECORD_ENCRYPTION_KEY_ID=' . self::ROTATED_KEY_ID);

        return TestKernelFactory::create(Environment::fromGlobals());
    }

    /**
     * Resolve an administrator context for this process's isolated rotation site, creating the site once.
     *
     * The row is deliberately retained with the rest of the shared integration fixture history. Its
     * collision-resistant identifier prevents a later PHPUnit process from inheriting its definition
     * catalogue, which is the isolation property this test needs to remain repeatable.
     *
     * @param   Container  $container  Booted kernel container sharing the integration database.
     *
     * @return  ExecutionContext  Production-authenticated administrator scoped to the rotation site.
     *
     * @since   2.0.0
     */
    private function rotationContext(Container $container): ExecutionContext
    {
        if (self::$rotationSite === null) {
            $site = SiteContext::fromString('record-secret-rotation-' . $this->suffix());
            $this->database($container)->insert($this->tables($container)->raw('sites'), [
                'identifier' => $site->identifier(),
                'name' => 'Record secret rotation integration site',
                'created_at' => new DateTimeImmutable(),
            ], ['created_at' => Types::DATETIME_IMMUTABLE]);
            self::$rotationSite = $site;
        }

        return self::administratorContextForSite($container, self::$rotationSite);
    }

    /**
     * Re-scope the production-authenticated integration administrator without manufacturing authority.
     *
     * @param   Container    $container  Booted kernel container whose identity gateway authenticates the actor.
     * @param   SiteContext  $site       Existing site the rotation campaign owns.
     *
     * @return  ExecutionContext  Administrator context carrying the gateway-issued principal and grants.
     *
     * @throws  RuntimeException  When production authentication did not yield a human principal.
     *
     * @since   2.0.0
     */
    private static function administratorContextForSite(Container $container, SiteContext $site): ExecutionContext
    {
        $administrator = TestKernelFactory::administratorContext($container);
        $principal = $administrator->principal();
        if ($principal === null) {
            throw new RuntimeException('The integration administrator principal is unavailable.');
        }

        return $principal->context(
            $site,
            $administrator->authenticationStrength(),
            'record-secret-rotation-' . bin2hex(random_bytes(16)),
        );
    }

    /**
     * Project the neutral secret-bearing definition into the isolated site's ownership namespace.
     *
     * @param   string  $suffix  Run-unique suffix keeping the definition and its physical table distinct.
     *
     * @return  array<string, mixed>  Neutral fixture document owned by the rotation site.
     *
     * @throws  RuntimeException  When the site context has not been established first.
     *
     * @since   2.0.0
     */
    private function rotationDocument(string $suffix): array
    {
        if (self::$rotationSite === null) {
            throw new RuntimeException('The record secret rotation site is unavailable.');
        }
        $site = self::$rotationSite->identifier();
        $document = NeutralBusinessFixture::document($suffix, Uuid::uuid7()->toString());
        $document['owner'] = ['type' => 'site', 'identifier' => $site];
        $document['site'] = $site;
        $document['handle'] = sprintf('site.%s.neutral_business_record_%s', $site, $suffix);

        return $document;
    }

    /**
     * Create one fixture record carrying a secret field and hand back its storage key.
     *
     * @param   Container             $container   Container whose record service performs the write.
     * @param   ExecutionContext      $context     Authorized actor performing it.
     * @param   EntityTypeDefinition  $definition  Installed fixture definition.
     * @param   string                $suffix      Run-unique suffix keeping idempotency keys apart.
     * @param   string                $name        Record name, also part of the idempotency key.
     *
     * @return  string  Storage key of the created record.
     *
     * @since   2.0.0
     */
    private function createRecord(
        Container $container,
        ExecutionContext $context,
        EntityTypeDefinition $definition,
        string $suffix,
        string $name,
    ): string {
        $records = $container->get(BusinessRecordService::class);
        if (!$records instanceof BusinessRecordService) {
            throw new RuntimeException('The business record service is unavailable.');
        }

        return $records->create(new CreateRecordCommand(
            $context,
            $definition->handle,
            NeutralBusinessFixture::recordValues('Rotation ' . $name),
            NeutralBusinessFixture::idempotencyKey('rotation-' . $name . '-' . $suffix),
            recordId: Uuid::uuid7()->toString(),
        ))->recordKey;
    }

    /**
     * Read one record's stored secret envelope straight out of its generated columns.
     *
     * @param   Container             $container   Container supplying the connection and the blueprint.
     * @param   EntityTypeDefinition  $definition  Definition whose installation names the columns.
     * @param   string                $recordKey   Storage key of the row to read.
     *
     * @return  EncryptedEnvelope  The envelope exactly as stored.
     *
     * @since   2.0.0
     */
    private function storedEnvelope(
        Container $container,
        EntityTypeDefinition $definition,
        string $recordKey,
    ): EncryptedEnvelope {
        $row = $this->storedRow($container, $definition, $recordKey);

        return new EncryptedEnvelope($row['ciphertext'], $row['nonce'], $row['key_id'], $row['algorithm']);
    }

    /**
     * Read only the key identifier a stored secret carries.
     *
     * @param   Container             $container   Container supplying the connection and the blueprint.
     * @param   EntityTypeDefinition  $definition  Definition whose installation names the columns.
     * @param   string                $recordKey   Storage key of the row to read.
     *
     * @return  string  Identifier stamped into the stored envelope.
     *
     * @since   2.0.0
     */
    private function storedKeyId(
        Container $container,
        EntityTypeDefinition $definition,
        string $recordKey,
    ): string {
        return $this->storedRow($container, $definition, $recordKey)['key_id'];
    }

    /**
     * Fetch the four sealed components of the fixture's `credential` field for one row.
     *
     * @param   Container             $container   Container supplying the connection and the blueprint.
     * @param   EntityTypeDefinition  $definition  Definition whose installation names the columns.
     * @param   string                $recordKey   Storage key of the row to read.
     *
     * @return  array{ciphertext: string, nonce: string, key_id: string, algorithm: string}  Raw components.
     *
     * @since   2.0.0
     */
    private function storedRow(
        Container $container,
        EntityTypeDefinition $definition,
        string $recordKey,
    ): array {
        $installations = $container->get(BusinessSchemaInstallationRepository::class);
        if (!$installations instanceof BusinessSchemaInstallationRepository) {
            throw new RuntimeException('The schema installation repository is unavailable.');
        }
        $installation = $installations->find($definition->id);
        $table = $installation?->blueprint->table('record');
        if ($table === null) {
            throw new RuntimeException('The fixture record table is unavailable.');
        }
        $columns = [];
        foreach (['ciphertext', 'nonce', 'key_id', 'algorithm'] as $component) {
            $column = $table->column('credential.' . $component);
            if ($column === null) {
                throw new RuntimeException('The fixture secret column is unavailable.');
            }
            $columns[$component] = $column->physicalName;
        }
        $database = $this->database($container);
        $row = $database->fetchAssociative(sprintf(
            'SELECT %s, %s, %s, %s FROM %s WHERE %s = ?',
            $database->quoteSingleIdentifier($columns['ciphertext']),
            $database->quoteSingleIdentifier($columns['nonce']),
            $database->quoteSingleIdentifier($columns['key_id']),
            $database->quoteSingleIdentifier($columns['algorithm']),
            $database->quoteSingleIdentifier($table->physicalName),
            $database->quoteSingleIdentifier($table->primaryKey[0]),
        ), [$recordKey]);
        if ($row === false) {
            throw new RuntimeException('The fixture record row is unavailable.');
        }

        return [
            'ciphertext' => $this->bytes($row[$columns['ciphertext']] ?? null),
            'nonce' => $this->bytes($row[$columns['nonce']] ?? null),
            'key_id' => $this->bytes($row[$columns['key_id']] ?? null),
            'algorithm' => $this->bytes($row[$columns['algorithm']] ?? null),
        ];
    }

    /**
     * Open one stored envelope through the container's own cipher and binding.
     *
     * @param   Container             $container   Container supplying the key ring cipher.
     * @param   EncryptedEnvelope     $envelope    Envelope as stored.
     * @param   EntityTypeDefinition  $definition  Definition the record belongs to.
     * @param   string                $recordKey   Storage key bound into the associated data.
     *
     * @return  string  The plaintext the envelope protects.
     *
     * @since   2.0.0
     */
    private function open(
        Container $container,
        EncryptedEnvelope $envelope,
        EntityTypeDefinition $definition,
        string $recordKey,
    ): string {
        $cipher = $container->get(EnvelopeCipher::class);
        if (!$cipher instanceof EnvelopeCipher) {
            throw new RuntimeException('The record secret cipher is unavailable.');
        }

        return $cipher->decrypt($envelope, SecretAssociatedData::for(
            $definition->siteIdentifier,
            $definition->id,
            $recordKey,
            'credential',
        ));
    }

    /**
     * Read one raw column value as bytes, draining a stream when the driver hands one back.
     *
     * @param   mixed  $value  Raw driver value.
     *
     * @return  string  The column's bytes.
     *
     * @since   2.0.0
     */
    private function bytes(mixed $value): string
    {
        if (is_resource($value)) {
            $contents = stream_get_contents($value);
            if (!is_string($contents)) {
                throw new RuntimeException('A stored secret column could not be read.');
            }

            return $contents;
        }
        if (!is_string($value)) {
            throw new RuntimeException('A stored secret column holds an unexpected value.');
        }

        return $value;
    }

    /**
     * Resolve the connection the fixture rows live on.
     *
     * @param   Container  $container  Container supplying it.
     *
     * @return  Connection  The DBAL connection.
     *
     * @since   2.0.0
     */
    private function database(Container $container): Connection
    {
        $database = $container->get(Connection::class);
        if (!$database instanceof Connection) {
            throw new RuntimeException('The integration connection is unavailable.');
        }

        return $database;
    }

    /**
     * Resolve the prefixed table-name map.
     *
     * @param   Container  $container  Container supplying it.
     *
     * @return  TableNames  The prefix resolver.
     *
     * @since   2.0.0
     */
    private function tables(Container $container): TableNames
    {
        $tables = $container->get(TableNames::class);
        if (!$tables instanceof TableNames) {
            throw new RuntimeException('The integration table map is unavailable.');
        }

        return $tables;
    }

    /**
     * Build a run-unique suffix so repeated runs never collide on a fixture handle.
     *
     * @return  string  Twelve lowercase hexadecimal characters.
     *
     * @since   2.0.0
     */
    private function suffix(): string
    {
        return strtolower(substr(str_replace('-', '', Uuid::uuid7()->toString()), -12));
    }
}
