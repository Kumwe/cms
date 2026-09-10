<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\Identity;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use InvalidArgumentException;
use Kumwe\App\Kernel\Container;
use Kumwe\Context\Value\SiteContext;
use Kumwe\App\Identity\Application\Administration\AccessControlService;
use Kumwe\App\Identity\Application\Administration\AdministratorIdentityGateway;
use Kumwe\App\Identity\Application\Administration\AdministratorSessionStore;
use Kumwe\App\Identity\Application\Authentication\AccessTokenVerifier;
use Kumwe\App\Identity\Application\StepUp\StepUpCredentialStore;
use Kumwe\App\Identity\Domain\StepUp\TotpCredential;
use Kumwe\App\Identity\Infrastructure\Administration\DoctrineAccessControlRepository;
use Kumwe\App\Identity\Infrastructure\Administration\DoctrineAdministratorSessionStore;
use Kumwe\App\Identity\Infrastructure\StepUp\DoctrineStepUpCredentialStore;
use Kumwe\App\Infrastructure\Persistence\Migration\CredentialLifecycleMigration;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Kernel\Configuration\ApplicationConfiguration;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Tests\Support\TestKernelFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

/**
 * Proves on every supported engine that a credential change actually retires what it claims to.
 *
 * These are the assertions the wave exists for, and they are database tests rather than unit tests
 * because the claim is about what the stored predicates do: an epoch written by one statement has to
 * be the epoch another statement's `WHERE` clause rejects, in the collation, type and driver of the
 * server the installation actually runs on.
 *
 * @since  2.0.0
 */
#[CoversClass(AccessControlService::class)]
#[CoversClass(DoctrineAccessControlRepository::class)]
#[CoversClass(DoctrineAdministratorSessionStore::class)]
#[CoversClass(DoctrineStepUpCredentialStore::class)]
#[CoversClass(CredentialLifecycleMigration::class)]
final class CredentialLifecycleIntegrationTest extends TestCase
{
    /**
     * Proves an administrative reset replaces the credential and retires token, session and proof.
     *
     * One act, four consequences, all of them read back from the database: the old password stops
     * authenticating, the new one starts, an already-issued API token stops verifying, and the
     * subject's administrator session row is gone.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAdministrativeResetRetiresPasswordTokenAndSessionTogether(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $access = $container->get(AccessControlService::class);
        $identities = $container->get(AdministratorIdentityGateway::class);
        $tokens = $container->get(AccessTokenVerifier::class);
        $database = $container->get(Connection::class);
        $tables = $container->get(TableNames::class);
        self::assertInstanceOf(AccessControlService::class, $access);
        self::assertInstanceOf(AdministratorIdentityGateway::class, $identities);
        self::assertInstanceOf(AccessTokenVerifier::class, $tokens);
        self::assertInstanceOf(Connection::class, $database);
        self::assertInstanceOf(TableNames::class, $tables);
        $context = TestKernelFactory::administratorContext($container);
        $marker = Uuid::uuid7()->toString();
        $email = sprintf('password-reset-%s@example.test', $marker);
        $userId = $access->createUser($context, $email, 'Reset subject', 'the original passphrase');
        $roleId = $access->createRole($context, 'password-reset-' . $marker, 'Password reset test');
        $access->grant($context, $roleId, 'content.read');
        $access->assignRole($context, $userId, $roleId);
        $issued = $identities->issueAccessToken($context, $email, 'Reset proof', ['content.read']);
        self::assertNotNull($tokens->verify($issued['token']));
        self::assertNotNull($identities->authenticate($email, 'the original passphrase', 'integration'));
        $this->insertSession($container, $database, $tables, $userId, $marker);

        $ended = $access->resetUserPassword($context, $userId, 'a replacement passphrase', 'ticket 4711');

        self::assertSame(1, $ended);
        self::assertNull($identities->authenticate($email, 'the original passphrase', 'integration'));
        self::assertNotNull($identities->authenticate($email, 'a replacement passphrase', 'integration'));
        self::assertNull($tokens->verify($issued['token']));
        self::assertSame(0, $this->sessionCount($database, $tables, $userId));
    }

    /**
     * Proves the reset is on the record, naming an actor who is not the subject.
     *
     * The property that makes an administrative reset something other than a silent account takeover.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAdministrativeResetLeavesTheTakeoverVisibleInTheSecurityTimeline(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $access = $container->get(AccessControlService::class);
        self::assertInstanceOf(AccessControlService::class, $access);
        $context = TestKernelFactory::administratorContext($container);
        $marker = Uuid::uuid7()->toString();
        $userId = $access->createUser(
            $context,
            sprintf('reset-audit-%s@example.test', $marker),
            'Reset audit subject',
            'the original passphrase',
        );

        $access->resetUserPassword($context, $userId, 'a replacement passphrase', 'audited reset ' . $marker);

        $matching = array_values(array_filter(
            $access->securityEvents($context),
            static fn (array $event): bool => ($event['action'] ?? null) === 'user.password.reset'
                && ($event['subject_id'] ?? null) === $userId,
        ));
        self::assertCount(1, $matching);
        self::assertSame($context->actorId(), $matching[0]['actor_id'] ?? null);
        self::assertNotSame($userId, $matching[0]['actor_id'] ?? null);
        self::assertSame('success', $matching[0]['outcome'] ?? null);
    }

    /**
     * Proves an administrator may not reset their own password and skip proving the current one.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAdministrativeResetRefusesTheActorsOwnAccount(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $access = $container->get(AccessControlService::class);
        self::assertInstanceOf(AccessControlService::class, $access);
        $context = TestKernelFactory::administratorContext($container);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('self-service change');

        $access->resetUserPassword($context, $context->actorId(), 'a replacement passphrase', 'trying it on');
    }

    /**
     * Proves a live administrator cookie stops resolving the moment the security epoch advances.
     *
     * The gap this closes: before the session row carried an epoch, a break-glass revocation killed
     * tokens, portal sessions and step-up proofs while leaving the browser signed in until expiry.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAdministratorSessionStopsResolvingAfterTheEpochAdvances(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $access = $container->get(AccessControlService::class);
        $sessions = $container->get(AdministratorSessionStore::class);
        $database = $container->get(Connection::class);
        $tables = $container->get(TableNames::class);
        self::assertInstanceOf(AccessControlService::class, $access);
        self::assertInstanceOf(AdministratorSessionStore::class, $sessions);
        self::assertInstanceOf(Connection::class, $database);
        self::assertInstanceOf(TableNames::class, $tables);
        $context = TestKernelFactory::administratorContext($container);
        $marker = Uuid::uuid7()->toString();
        $userId = $access->createUser(
            $context,
            sprintf('session-epoch-%s@example.test', $marker),
            'Session epoch subject',
            'the original passphrase',
        );
        $token = $this->insertSession($container, $database, $tables, $userId, $marker);
        self::assertNotNull($sessions->find($token, 'Kumwe integration browser'));

        $access->emergencyRevokeAllSubjectTokens($context, $userId, 'break-glass ' . $marker);

        self::assertNull($sessions->find($token, 'Kumwe integration browser'));
    }

    /**
     * Proves the terminate-all operation ends sessions without disturbing anything else.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testSessionTerminationEndsEverySessionAndLeavesThePasswordAlone(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $access = $container->get(AccessControlService::class);
        $identities = $container->get(AdministratorIdentityGateway::class);
        $database = $container->get(Connection::class);
        $tables = $container->get(TableNames::class);
        self::assertInstanceOf(AccessControlService::class, $access);
        self::assertInstanceOf(AdministratorIdentityGateway::class, $identities);
        self::assertInstanceOf(Connection::class, $database);
        self::assertInstanceOf(TableNames::class, $tables);
        $context = TestKernelFactory::administratorContext($container);
        $marker = Uuid::uuid7()->toString();
        $email = sprintf('session-terminate-%s@example.test', $marker);
        $userId = $access->createUser($context, $email, 'Termination subject', 'the original passphrase');
        $this->insertSession($container, $database, $tables, $userId, $marker . '-a');
        $this->insertSession($container, $database, $tables, $userId, $marker . '-b');

        $ended = $access->terminateUserSessions($context, $userId, 'shared workstation ' . $marker);

        self::assertSame(2, $ended);
        self::assertSame(0, $this->sessionCount($database, $tables, $userId));
        self::assertNotNull($identities->authenticate($email, 'the original passphrase', 'integration'));
    }

    /**
     * Proves an administrative second-factor retirement clears the way for a fresh enrollment.
     *
     * Enrollment refuses while an active credential exists, so this is what makes a lost authenticator
     * recoverable rather than terminal.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testStepUpRetirementClearsTheBlockOnFreshEnrollment(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $access = $container->get(AccessControlService::class);
        $credentials = $container->get(StepUpCredentialStore::class);
        $database = $container->get(Connection::class);
        $tables = $container->get(TableNames::class);
        self::assertInstanceOf(AccessControlService::class, $access);
        self::assertInstanceOf(StepUpCredentialStore::class, $credentials);
        self::assertInstanceOf(Connection::class, $database);
        self::assertInstanceOf(TableNames::class, $tables);
        $context = TestKernelFactory::administratorContext($container);
        $marker = Uuid::uuid7()->toString();
        $userId = $access->createUser(
            $context,
            sprintf('step-up-reset-%s@example.test', $marker),
            'Step-up reset subject',
            'the original passphrase',
        );
        $credentialId = $this->enrollActiveCredential($credentials, $database, $tables, $userId);
        self::assertNotNull($credentials->active($userId));

        $retired = $access->revokeStepUpCredentials($context, $userId, 'authenticator lost ' . $marker);

        self::assertSame(1, $retired);
        self::assertNull($credentials->active($userId));
        self::assertSame(0, $this->liveRecoveryCodeCount($database, $tables, $credentialId));
        $credentials->replacePending(new TotpCredential(
            Uuid::uuid7()->toString(),
            $userId,
            'v1.replacement-enrollment',
            false,
            new DateTimeImmutable('now', new DateTimeZone('UTC')),
            new DateTimeImmutable('+10 minutes', new DateTimeZone('UTC')),
            null,
            null,
            1,
        ));
        self::assertSame('revoked', $database->fetchOne(sprintf(
            'SELECT status FROM %s WHERE id = ?',
            $tables->quoted('step_up_credentials'),
        ), [$credentialId]));
    }

    /**
     * Insert one administrator session row directly, at the owning user's current epoch.
     *
     * The store's own `create()` needs a full authenticated context for the subject, which is exactly
     * what these subjects do not have; the row is what the assertions are about, so it is written here
     * the way the store writes it.
     *
     * @param   Container   $container  Booted integration container supplying the fingerprint secret.
     * @param   Connection  $database   Installation connection.
     * @param   TableNames  $tables     Prefixed table resolver.
     * @param   string      $userId     Subject the session belongs to.
     * @param   string      $marker     Unique marker distinguishing this row's credentials.
     *
     * @return  string  The plaintext session token the store will be asked to resolve.
     *
     * @since   2.0.0
     */
    private function insertSession(
        Container $container,
        Connection $database,
        TableNames $tables,
        string $userId,
        string $marker,
    ): string {
        $token = rtrim(strtr(base64_encode(hash('sha256', 'session-' . $marker, true)), '+/', '-_'), '=');
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $epoch = $database->fetchOne(sprintf(
            'SELECT security_epoch FROM %s WHERE id = ?',
            $tables->quoted('users'),
        ), [$userId]);
        self::assertTrue(is_int($epoch) || is_string($epoch));
        $database->insert($tables->raw('administrator_sessions'), [
            'id' => Uuid::uuid7()->toString(),
            'user_id' => $userId,
            'token_digest' => hash('sha256', $token),
            'csrf_token' => str_repeat('c', 43),
            'ip_digest' => null,
            'user_agent_digest' => hash_hmac(
                'sha256',
                'Kumwe integration browser',
                $this->applicationSecret($container),
            ),
            'created_at' => $now,
            'last_seen_at' => $now,
            'expires_at' => $now->modify('+1 hour'),
            'site_identifier' => SiteContext::DEFAULT,
            'organization_identifier' => null,
            'workspace_identifier' => null,
            'membership_id' => null,
            'membership_version' => null,
            'policy_generation' => null,
            'rotation' => 1,
            'step_up_at' => null,
            'security_epoch' => (int) $epoch,
        ], [
            'created_at' => Types::DATETIME_IMMUTABLE,
            'last_seen_at' => Types::DATETIME_IMMUTABLE,
            'expires_at' => Types::DATETIME_IMMUTABLE,
        ]);
        $database->insert($tables->raw('resource_site_ownership'), [
            'resource_type' => 'administrator_session',
            'resource_id' => (string) $database->fetchOne(sprintf(
                'SELECT id FROM %s WHERE token_digest = ?',
                $tables->quoted('administrator_sessions'),
            ), [hash('sha256', $token)]),
            'site_identifier' => SiteContext::DEFAULT,
        ]);

        return $token;
    }

    /**
     * Count the administrator session rows one user still holds.
     *
     * @param   Connection  $database  Installation connection.
     * @param   TableNames  $tables    Prefixed table resolver.
     * @param   string      $userId    Subject whose sessions are counted.
     *
     * @return  int  Live row count.
     *
     * @since   2.0.0
     */
    private function sessionCount(Connection $database, TableNames $tables, string $userId): int
    {
        return (int) $database->fetchOne(sprintf(
            'SELECT COUNT(*) FROM %s WHERE user_id = ?',
            $tables->quoted('administrator_sessions'),
        ), [$userId]);
    }

    /**
     * Count the unspent recovery digests still attached to a credential.
     *
     * @param   Connection  $database      Installation connection.
     * @param   TableNames  $tables        Prefixed table resolver.
     * @param   string      $credentialId  Credential whose digests are counted.
     *
     * @return  int  Unspent digest count.
     *
     * @since   2.0.0
     */
    private function liveRecoveryCodeCount(Connection $database, TableNames $tables, string $credentialId): int
    {
        return (int) $database->fetchOne(sprintf(
            'SELECT COUNT(*) FROM %s WHERE credential_id = ? AND consumed_at IS NULL',
            $tables->quoted('step_up_recovery_codes'),
        ), [$credentialId]);
    }

    /**
     * Enroll and activate one credential with recovery digests, through the production store.
     *
     * @param   StepUpCredentialStore  $credentials  Store under test.
     * @param   Connection             $database     Installation connection.
     * @param   TableNames             $tables       Prefixed table resolver.
     * @param   string                 $userId       Subject the credential belongs to.
     *
     * @return  string  The active credential's identifier.
     *
     * @since   2.0.0
     */
    private function enrollActiveCredential(
        StepUpCredentialStore $credentials,
        Connection $database,
        TableNames $tables,
        string $userId,
    ): string {
        $credentialId = Uuid::uuid7()->toString();
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $credentials->replacePending(new TotpCredential(
            $credentialId,
            $userId,
            'v1.integration-enrollment',
            false,
            $now,
            $now->modify('+10 minutes'),
            null,
            null,
            1,
        ));
        $digests = [];
        for ($index = 0; $index < 10; ++$index) {
            $digests[] = hash('sha256', $credentialId . ':' . $index);
        }
        self::assertTrue($credentials->activate($credentialId, $userId, 1, 100, $digests, $now));
        self::assertSame(10, $this->liveRecoveryCodeCount($database, $tables, $credentialId));

        return $credentialId;
    }

    /**
     * Read the installation secret the session store keys its browser fingerprint with.
     *
     * It comes from the booted container's own configuration rather than from raw `getenv()`, because
     * configuration that arrives through `.env` never reaches the process environment: the raw read
     * answered false there, and a fingerprint keyed on anything but the container's secret would make
     * the inserted row one the store under test could never resolve.
     *
     * @param   Container  $container  Booted integration container.
     *
     * @return  string  Configured application secret.
     *
     * @since   2.0.0
     */
    private function applicationSecret(Container $container): string
    {
        $configuration = $container->get(ApplicationConfiguration::class);
        self::assertInstanceOf(ApplicationConfiguration::class, $configuration);

        return $configuration->secret;
    }
}
