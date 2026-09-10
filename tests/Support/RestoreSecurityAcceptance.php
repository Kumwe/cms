<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Support;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Kumwe\App\Kernel\Container;
use Kumwe\Context\Value\AuthenticatedSurface;
use Kumwe\Context\Value\AuthenticationStrength;
use Kumwe\App\Application\Authorization\AuthorizationDenied;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Context\Value\SiteContext;
use Kumwe\App\BusinessRecord\Application\BusinessRecordService;
use Kumwe\App\BusinessRecord\Application\Command\UpdateRecordCommand;
use Kumwe\App\BusinessRecord\Application\Query\ReadRecordQuery;
use Kumwe\App\Identity\Application\Administration\AccessControlService;
use Kumwe\App\Identity\Application\Administration\AdministratorIdentityGateway;
use Kumwe\App\Identity\Application\Administration\AdministratorSession;
use Kumwe\App\Identity\Application\Administration\AdministratorSessionStore;
use Kumwe\App\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\App\Identity\Application\StepUp\AdministratorStepUpProvider;
use Kumwe\App\Identity\Application\StepUp\StepUpCredentialStore;
use Kumwe\App\Identity\Application\StepUp\StepUpRejected;
use Kumwe\App\Identity\Application\StepUp\StepUpSecretCipher;
use Kumwe\App\Identity\Domain\StepUp\StepUpIntent;
use Kumwe\App\Identity\Domain\StepUp\TotpCredential;
use Kumwe\App\Identity\Domain\UserStatus;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use RuntimeException;

/**
 * Identity and second-factor acceptance for a restored Kumwe database.
 *
 * The business-runtime drill proved that rows came back. This proves the security surface those rows
 * describe still works, which is a different claim: an installation restored under the wrong
 * `APP_SECRET` has an intact `step_up_credentials` table whose every row is permanently unopenable,
 * and a digest comparison cannot tell the two apart. So nothing here compares a hash. The limited
 * operator logs in with the password its restored hash was made from, is allowed exactly one
 * operation and denied another, its restored TOTP secret is decrypted and used to pass a live
 * challenge, and every single-use credential is presented twice so the second presentation is
 * refused.
 *
 * Nothing that crosses the seed/verify process boundary is a credential. The passwords and labels
 * here are patterned repository constants, the manifest carries identifiers and counts only, and the
 * TOTP secret is read out of the restored row rather than carried forward from enrollment.
 *
 * @since  2.0.0
 */
final class RestoreSecurityAcceptance
{
    /**
     * Login of the deliberately narrow operator the drill restores and re-authenticates.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string LIMITED_EMAIL = 'backup-drill-reader@example.test';

    /**
     * Password of that operator, written as a readable phrase so it carries no entropy to leak.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string LIMITED_PASSWORD = 'backup drill limited reader passphrase';

    /**
     * Role code carrying the single capability the limited operator is allowed to exercise.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string LIMITED_ROLE = 'backup-drill-limited-reader';

    /**
     * Capabilities granted to that role: read a business record, and nothing that changes one.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private const array LIMITED_CAPABILITIES = ['administrator.access', 'business.record.read'];

    /**
     * User-agent string of the session that must still be live after restore.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string LIVE_AGENT = 'kumwe-backup-drill-live/2.0';

    /**
     * User-agent string of the session that is aged into the past before the backup is taken.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string STALE_AGENT = 'kumwe-backup-drill-stale/2.0';

    /**
     * Instant the stale session is aged to; fixed so the restored classification never drifts.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string STALE_EXPIRY = '2026-01-01T00:00:00+00:00';

    /**
     * Cutoff separating the aged session from the live one when the manifest classifies rows.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string STALE_CUTOFF = '2026-06-01T00:00:00+00:00';

    /**
     * Protected-operation token the drill's step-up proofs are bound to.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string STEP_UP_PURPOSE = 'business.record.backup_acceptance';

    /**
     * Source label recorded against every step-up attempt the drill makes.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string SOURCE = 'backup-restore-drill';

    /**
     * Versioned binding `TotpStepUpProvider` seals a credential secret under.
     *
     * Reproduced rather than imported because it is private to the provider. That duplication is
     * deliberate: if the binding ever changes, this drill stops opening restored credentials, which
     * is exactly the signal an operator needs before a release strands every enrolled authenticator.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string STEP_UP_BINDING = "kumwe-step-up-v1\0";

    /**
     * Recovery codes a confirmed enrollment issues, and the count the manifest asserts survives.
     *
     * @var    int
     * @since  2.0.0
     */
    private const int RECOVERY_CODES = 10;

    /**
     * Create the restorable identity, session and second-factor state the drill later re-exercises.
     *
     * Seeding runs entirely through production services, so what the backup captures is what a real
     * installation holds: a hashed password, a role with two grants, one live and one aged session,
     * an active TOTP credential sealed under this deployment's `APP_SECRET`, and one recovery code
     * already spent.
     *
     * @param   Container         $container      Booted kernel for the source installation.
     * @param   ExecutionContext  $administrator  Owner context able to administer access control.
     *
     * @return  void
     *
     * @throws  RuntimeException  When a required service is unavailable, the seeded operator cannot
     *          authenticate, or the second factor does not enroll and spend exactly once.
     *
     * @since   2.0.0
     */
    public static function seed(Container $container, ExecutionContext $administrator): void
    {
        $access = self::accessControl($container);
        $sessions = self::sessions($container);
        $stepUp = self::stepUp($container);
        if (self::existingUserId($container) !== null) {
            return;
        }

        $userId = $access->createUser(
            $administrator,
            self::LIMITED_EMAIL,
            'Backup drill limited reader',
            self::LIMITED_PASSWORD,
            UserStatus::Active,
        );
        $roleId = $access->createRole($administrator, self::LIMITED_ROLE, 'Backup drill limited reader');
        foreach (self::LIMITED_CAPABILITIES as $capability) {
            $access->grant($administrator, $roleId, $capability);
        }
        $access->assignRole($administrator, $userId, $roleId);

        $principal = self::authenticate($container);
        $stale = $sessions->create(self::sessionContext($principal), self::STALE_AGENT);
        self::ageSession($container, $stale->session->id);
        $live = $sessions->create(self::sessionContext($principal), self::LIVE_AGENT);

        $setup = $stepUp->beginEnrollment($principal->subject(), 'Kumwe', self::LIMITED_EMAIL);
        $completion = $stepUp->confirmEnrollment(
            self::intent($principal, $live->session->id),
            $setup->enrollmentId,
            TotpCodes::fromBase32($setup->secret, intdiv(time(), 30)),
            self::SOURCE,
        );
        if (count($completion->recoveryCodes) !== self::RECOVERY_CODES) {
            throw new RuntimeException('The drill enrollment did not issue the expected recovery codes.');
        }
        $stepUp->recover(
            self::intent($principal, $completion->verification->rotatedSession->sessionId),
            $completion->recoveryCodes[0],
            self::SOURCE,
        );
    }

    /**
     * Describe the seeded security state in terms that survive a byte-exact restore unchanged.
     *
     * Only identifiers, capability names and counts appear. A digest of the credential or session
     * rows would add nothing this drill does not prove by using them, and would put ciphertext in a
     * file the workflow keeps.
     *
     * @param   Container  $container  Booted kernel, source or restored.
     *
     * @return  array<string, mixed>  Canonical, secret-free security state.
     *
     * @throws  RuntimeException  When the seeded operator or its credential is absent.
     *
     * @since   2.0.0
     */
    public static function manifest(Container $container): array
    {
        $database = self::database($container);
        $tables = self::tables($container);
        $userId = self::existingUserId($container)
            ?? throw new RuntimeException('The restored drill operator is unavailable.');
        $credential = self::credentials($container)->active($userId);
        if (!$credential instanceof TotpCredential) {
            throw new RuntimeException('The restored drill second factor is unavailable.');
        }

        $capabilities = $database->fetchFirstColumn(sprintf(
            'SELECT g.capability_code FROM %s g INNER JOIN %s ur ON ur.role_id = g.role_id '
            . 'WHERE ur.user_id = ? ORDER BY g.capability_code ASC',
            $tables->quoted('role_capability_grants'),
            $tables->quoted('user_roles'),
        ), [$userId]);
        $cutoff = new DateTimeImmutable(self::STALE_CUTOFF);
        $expired = 0;
        $live = 0;
        foreach (self::sessionExpiries($container, $userId) as $expiresAt) {
            if ($expiresAt <= $cutoff) {
                $expired++;
                continue;
            }
            $live++;
        }

        return [
            'granted_capabilities' => array_values(array_map(
                static fn (mixed $value): string => is_string($value) ? $value : '',
                $capabilities,
            )),
            'sessions' => ['expired' => $expired, 'live' => $live],
            'step_up' => [
                'credential_active' => $credential->active,
                'recovery_codes_spent' => self::recoveryCodeCount($container, $credential->id, true),
                'recovery_codes_unspent' => self::recoveryCodeCount($container, $credential->id, false),
            ],
            'user_id' => $userId,
        ];
    }

    /**
     * Re-exercise the restored security surface and insist every single-use credential stays spent.
     *
     * Run only after the manifest comparison, because it deliberately changes restored state: it
     * authenticates, elevates, spends codes and ages a session. Each step either performs a real
     * operation or is refused; nothing is asserted by digest.
     *
     * @param   Container  $container  Booted kernel bound to the restored database.
     *
     * @return  array<string, bool|int>  Named proofs, all of which must be present for the drill to
     *          count as passed.
     *
     * @throws  RuntimeException  When a login, authorization, decryption, challenge or replay fence
     *          behaves differently than the restored data promises.
     *
     * @since   2.0.0
     */
    public static function accept(Container $container): array
    {
        $principal = self::authenticate($container);
        $sessions = self::sessions($container);
        $created = $sessions->create(self::sessionContext($principal), self::LIVE_AGENT);

        if (!$sessions->find($created->token, self::LIVE_AGENT) instanceof AdministratorSession) {
            throw new RuntimeException('A restored installation refused a session it had just issued.');
        }
        if ($sessions->find($created->token, 'kumwe-backup-drill-other/2.0') !== null) {
            throw new RuntimeException('A restored installation accepted a session bound to another agent.');
        }

        $limited = $principal->context(
            SiteContext::default(),
            AuthenticationStrength::Password,
            'backup-drill-limited-' . bin2hex(random_bytes(8)),
            surface: AuthenticatedSurface::Administrator,
            sessionId: $created->session->id,
        );
        self::assertLimitedAuthority($container, $limited);

        // Age this session before elevating, because a step-up verification rotates the session it
        // was issued for and the rotated row is a different identity.
        self::ageSession($container, $created->session->id);
        if ($sessions->find($created->token, self::LIVE_AGENT) !== null) {
            throw new RuntimeException('A restored installation accepted a session that had expired.');
        }

        $elevated = $sessions->create(self::sessionContext($principal), self::LIVE_AGENT);
        $totpProofs = self::acceptSecondFactor($container, $principal, $elevated->session->id);

        return [
            'limited_login' => true,
            'limited_operation_denied' => true,
            'stale_session_rejected' => true,
            'step_up_replays_refused' => $totpProofs,
        ];
    }

    /**
     * Confirm the restored expired sessions are gone and the restored live one is not.
     *
     * Called after the restored scheduler and worker have run `system.sessions.purge`, so this reads
     * the effect of work the restored installation actually performed rather than re-running it.
     *
     * @param   Container  $container  Booted kernel bound to the restored database.
     *
     * @return  int  Sessions still held for the drill operator; every one of them is unexpired.
     *
     * @throws  RuntimeException  When an expired session survived the purge or the live one did not.
     *
     * @since   2.0.0
     */
    public static function assertExpiredSessionsPurged(Container $container): int
    {
        $userId = self::existingUserId($container)
            ?? throw new RuntimeException('The restored drill operator is unavailable.');
        $cutoff = new DateTimeImmutable(self::STALE_CUTOFF);
        $remaining = self::sessionExpiries($container, $userId);
        foreach ($remaining as $expiresAt) {
            if ($expiresAt <= $cutoff) {
                throw new RuntimeException('An expired session survived the restored purge job.');
            }
        }
        if ($remaining === []) {
            throw new RuntimeException('The restored purge job removed sessions that had not expired.');
        }

        return count($remaining);
    }

    /**
     * Open the restored second factor, pass a live challenge, and refuse both replays.
     *
     * Decryption here is the point. The credential's secret is sealed under a key derived from
     * `APP_SECRET`, so a restore booted with the wrong secret fails at the first line of this method
     * instead of at an operator's first sign-in weeks later.
     *
     * @param   Container              $container  Booted kernel bound to the restored database.
     * @param   AuthenticatedPrincipal $principal  Freshly authenticated drill operator.
     * @param   string                 $sessionId  Session the first proof elevates.
     *
     * @return  int  Number of replays refused; exactly two are attempted.
     *
     * @throws  RuntimeException  When the restored secret does not open, a live code is rejected, or
     *          a spent code is accepted a second time.
     *
     * @since   2.0.0
     */
    private static function acceptSecondFactor(
        Container $container,
        AuthenticatedPrincipal $principal,
        string $sessionId,
    ): int {
        $stepUp = self::stepUp($container);
        $credential = self::credentials($container)->active($principal->subject());
        if (!$credential instanceof TotpCredential) {
            throw new RuntimeException('The restored second factor is unavailable.');
        }
        try {
            $secret = self::secretCipher($container)->decrypt(
                $credential->encryptedSecret,
                self::STEP_UP_BINDING . strtolower($credential->id) . "\0" . strtolower($credential->subjectId),
            );
        } catch (StepUpRejected $rejected) {
            // The step-up key is derived from the application secret, so this is the diagnosis the
            // operator needs and the generic rejection would not give them.
            throw new RuntimeException(
                'The restored second factor did not decrypt: the application secret does not match the '
                . 'one this installation was backed up under, so every enrolled authenticator is dead.',
                0,
                $rejected,
            );
        }
        $counter = max(intdiv(time(), 30), ($credential->lastAcceptedTimeStep ?? 0) + 1);
        $code = TotpCodes::fromRawSecret($secret, $counter);
        sodium_memzero($secret);

        $verification = $stepUp->challenge(self::intent($principal, $sessionId), $code, self::SOURCE);
        $refused = 0;
        try {
            $stepUp->challenge(
                self::intent($principal, $verification->rotatedSession->sessionId),
                $code,
                self::SOURCE,
            );
        } catch (StepUpRejected) {
            $refused++;
        }
        if ($refused !== 1) {
            throw new RuntimeException('A restored installation accepted a replayed TOTP code.');
        }

        $reissued = $stepUp->reissueRecoveryCodes($principal->subject());
        if (count($reissued) !== self::RECOVERY_CODES) {
            throw new RuntimeException('The restored second factor did not reissue its recovery codes.');
        }
        $verification = $stepUp->recover(
            self::intent($principal, $verification->rotatedSession->sessionId),
            $reissued[0],
            self::SOURCE,
        );
        try {
            $stepUp->recover(
                self::intent($principal, $verification->rotatedSession->sessionId),
                $reissued[0],
                self::SOURCE,
            );
        } catch (StepUpRejected) {
            $refused++;
        }
        if ($refused !== 2) {
            throw new RuntimeException('A restored installation accepted a spent recovery code.');
        }

        return $refused;
    }

    /**
     * Prove the restored grants still bound the limited operator to exactly one operation.
     *
     * @param   Container         $container  Booted kernel bound to the restored database.
     * @param   ExecutionContext  $limited    Context issued from the limited operator's session.
     *
     * @return  void
     *
     * @throws  RuntimeException  When the read is denied or the update is allowed.
     *
     * @since   2.0.0
     */
    private static function assertLimitedAuthority(Container $container, ExecutionContext $limited): void
    {
        $records = $container->get(BusinessRecordService::class);
        if (!$records instanceof BusinessRecordService) {
            throw new RuntimeException('The restored business-record service is unavailable.');
        }
        $view = $records->read(new ReadRecordQuery(
            $limited,
            NeutralBusinessFixture::HANDLE,
            NeutralBusinessFixture::RECORD_ID,
        ));
        if ($view->recordId !== NeutralBusinessFixture::RECORD_ID) {
            throw new RuntimeException('The limited operator read the wrong restored record.');
        }
        try {
            $records->update(new UpdateRecordCommand(
                $limited,
                NeutralBusinessFixture::HANDLE,
                NeutralBusinessFixture::RECORD_ID,
                $view->version,
                ['name' => 'Backup drill limited write'],
                NeutralBusinessFixture::idempotencyKey('backup-limited-write'),
            ));
        } catch (AuthorizationDenied) {
            return;
        }

        throw new RuntimeException('A restored installation let a read-only operator write a record.');
    }

    /**
     * Authenticate the drill operator exactly as the administrator sign-in path does.
     *
     * @param   Container  $container  Booted kernel.
     *
     * @return  AuthenticatedPrincipal  Principal carrying the restored security epoch.
     *
     * @throws  RuntimeException  When the restored password hash no longer authenticates.
     *
     * @since   2.0.0
     */
    private static function authenticate(Container $container): AuthenticatedPrincipal
    {
        $identities = $container->get(AdministratorIdentityGateway::class);
        if (!$identities instanceof AdministratorIdentityGateway) {
            throw new RuntimeException('The administrator identity gateway is unavailable.');
        }
        $principal = $identities->authenticate(self::LIMITED_EMAIL, self::LIMITED_PASSWORD, self::SOURCE);
        if (!$principal instanceof AuthenticatedPrincipal) {
            throw new RuntimeException('The restored drill operator could not be authenticated.');
        }

        return $principal;
    }

    /**
     * Build the password-strength context a session is created from.
     *
     * @param   AuthenticatedPrincipal  $principal  Authenticated drill operator.
     *
     * @return  ExecutionContext  Administrator-surface context for session creation.
     *
     * @since   2.0.0
     */
    private static function sessionContext(AuthenticatedPrincipal $principal): ExecutionContext
    {
        return $principal->context(
            SiteContext::default(),
            AuthenticationStrength::Password,
            'backup-drill-session-' . bin2hex(random_bytes(8)),
            surface: AuthenticatedSurface::Administrator,
        );
    }

    /**
     * Build the server-resolved intent one step-up attempt is bound to.
     *
     * @param   AuthenticatedPrincipal  $principal  Authenticated drill operator.
     * @param   string                  $sessionId  Session being elevated or rotated.
     *
     * @return  StepUpIntent  Intent naming the drill purpose and the operator's current epoch.
     *
     * @since   2.0.0
     */
    private static function intent(AuthenticatedPrincipal $principal, string $sessionId): StepUpIntent
    {
        return new StepUpIntent(
            $principal->subject(),
            $sessionId,
            SiteContext::DEFAULT,
            null,
            null,
            self::STEP_UP_PURPOSE,
            $principal->securityEpoch(),
        );
    }

    /**
     * Move one session's expiry into the past, standing in for time the drill cannot wait out.
     *
     * This is the one place the drill writes a column directly. Expiry is a clock fact rather than a
     * decision, and no production service offers to make a session old; every rule that reads the
     * column is still exercised through the store.
     *
     * @param   Container  $container  Booted kernel.
     * @param   string     $sessionId  Session to age.
     *
     * @return  void
     *
     * @throws  RuntimeException  When the session is absent.
     *
     * @since   2.0.0
     */
    private static function ageSession(Container $container, string $sessionId): void
    {
        $database = self::database($container);
        $updated = $database->update(
            self::tables($container)->raw('administrator_sessions'),
            ['expires_at' => new DateTimeImmutable(self::STALE_EXPIRY)],
            ['id' => $sessionId],
            ['expires_at' => \Doctrine\DBAL\Types\Types::DATETIME_IMMUTABLE],
        );
        if ($updated !== 1) {
            throw new RuntimeException('The drill session could not be aged.');
        }
    }

    /**
     * Read every stored expiry the drill operator still holds a session for.
     *
     * @param   Container  $container  Booted kernel.
     * @param   string     $userId     Drill operator identity.
     *
     * @return  list<DateTimeImmutable>  Expiries, in stored order.
     *
     * @throws  RuntimeException  When a stored expiry is unreadable.
     *
     * @since   2.0.0
     */
    private static function sessionExpiries(Container $container, string $userId): array
    {
        $rows = self::database($container)->fetchFirstColumn(sprintf(
            'SELECT expires_at FROM %s WHERE user_id = ?',
            self::tables($container)->quoted('administrator_sessions'),
        ), [$userId]);
        $expiries = [];
        foreach ($rows as $value) {
            if ($value instanceof DateTimeImmutable) {
                $expiries[] = $value;
                continue;
            }
            if (!is_string($value)) {
                throw new RuntimeException('A restored session expiry is unreadable.');
            }
            $expiries[] = new DateTimeImmutable($value);
        }

        return $expiries;
    }

    /**
     * Count the recovery codes of one credential in either spent or unspent state.
     *
     * @param   Container  $container     Booted kernel.
     * @param   string     $credentialId  Active credential the codes belong to.
     * @param   bool       $spent         True to count consumed codes, false to count usable ones.
     *
     * @return  int  Matching row count.
     *
     * @throws  RuntimeException  When the count is not an integer.
     *
     * @since   2.0.0
     */
    private static function recoveryCodeCount(Container $container, string $credentialId, bool $spent): int
    {
        $count = self::database($container)->fetchOne(sprintf(
            'SELECT COUNT(*) FROM %s WHERE credential_id = ? AND consumed_at IS %s NULL',
            self::tables($container)->quoted('step_up_recovery_codes'),
            $spent ? 'NOT' : '',
        ), [$credentialId]);

        return is_numeric($count) ? (int) $count : throw new RuntimeException('A recovery-code count is unreadable.');
    }

    /**
     * Find the drill operator by its stable login, without creating one.
     *
     * @param   Container  $container  Booted kernel.
     *
     * @return  ?string  Operator identity, or null when this installation has not seeded one.
     *
     * @since   2.0.0
     */
    private static function existingUserId(Container $container): ?string
    {
        $id = self::database($container)->fetchOne(sprintf(
            'SELECT id FROM %s WHERE email_normalized = ?',
            self::tables($container)->quoted('users'),
        ), [self::LIMITED_EMAIL]);

        return is_string($id) ? $id : null;
    }

    /**
     * Resolve the access-control service.
     *
     * @param   Container  $container  Booted kernel.
     *
     * @return  AccessControlService  Production access-control service.
     *
     * @throws  RuntimeException  When it is unavailable.
     *
     * @since   2.0.0
     */
    private static function accessControl(Container $container): AccessControlService
    {
        $service = $container->get(AccessControlService::class);

        return $service instanceof AccessControlService
            ? $service
            : throw new RuntimeException('The access-control service is unavailable.');
    }

    /**
     * Resolve the administrator session store.
     *
     * @param   Container  $container  Booted kernel.
     *
     * @return  AdministratorSessionStore  Production session store.
     *
     * @throws  RuntimeException  When it is unavailable.
     *
     * @since   2.0.0
     */
    private static function sessions(Container $container): AdministratorSessionStore
    {
        $store = $container->get(AdministratorSessionStore::class);

        return $store instanceof AdministratorSessionStore
            ? $store
            : throw new RuntimeException('The administrator session store is unavailable.');
    }

    /**
     * Resolve the administrator step-up provider.
     *
     * @param   Container  $container  Booted kernel.
     *
     * @return  AdministratorStepUpProvider  Production step-up provider.
     *
     * @throws  RuntimeException  When it is unavailable.
     *
     * @since   2.0.0
     */
    private static function stepUp(Container $container): AdministratorStepUpProvider
    {
        $provider = $container->get(AdministratorStepUpProvider::class);

        return $provider instanceof AdministratorStepUpProvider
            ? $provider
            : throw new RuntimeException('The step-up provider is unavailable.');
    }

    /**
     * Resolve the step-up credential store.
     *
     * @param   Container  $container  Booted kernel.
     *
     * @return  StepUpCredentialStore  Production credential store.
     *
     * @throws  RuntimeException  When it is unavailable.
     *
     * @since   2.0.0
     */
    private static function credentials(Container $container): StepUpCredentialStore
    {
        $store = $container->get(StepUpCredentialStore::class);

        return $store instanceof StepUpCredentialStore
            ? $store
            : throw new RuntimeException('The step-up credential store is unavailable.');
    }

    /**
     * Resolve the cipher that seals step-up secrets under the application secret.
     *
     * @param   Container  $container  Booted kernel.
     *
     * @return  StepUpSecretCipher  Production step-up cipher.
     *
     * @throws  RuntimeException  When it is unavailable.
     *
     * @since   2.0.0
     */
    private static function secretCipher(Container $container): StepUpSecretCipher
    {
        $cipher = $container->get(StepUpSecretCipher::class);

        return $cipher instanceof StepUpSecretCipher
            ? $cipher
            : throw new RuntimeException('The step-up secret cipher is unavailable.');
    }

    /**
     * Resolve the installation connection.
     *
     * @param   Container  $container  Booted kernel.
     *
     * @return  Connection  Installation connection.
     *
     * @throws  RuntimeException  When it is unavailable.
     *
     * @since   2.0.0
     */
    private static function database(Container $container): Connection
    {
        $database = $container->get(Connection::class);

        return $database instanceof Connection
            ? $database
            : throw new RuntimeException('The installation connection is unavailable.');
    }

    /**
     * Resolve the table-name compiler.
     *
     * @param   Container  $container  Booted kernel.
     *
     * @return  TableNames  Portable table-name compiler.
     *
     * @throws  RuntimeException  When it is unavailable.
     *
     * @since   2.0.0
     */
    private static function tables(Container $container): TableNames
    {
        $tables = $container->get(TableNames::class);

        return $tables instanceof TableNames
            ? $tables
            : throw new RuntimeException('The table-name compiler is unavailable.');
    }
}
