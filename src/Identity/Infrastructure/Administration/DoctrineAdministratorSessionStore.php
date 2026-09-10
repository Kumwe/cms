<?php

declare(strict_types=1);

namespace Kumwe\App\Identity\Infrastructure\Administration;

use DateInterval;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Types\Types;
use InvalidArgumentException;
use Kumwe\App\Application\Authorization\AuthorizationGateway;
use Kumwe\App\Application\Authorization\AuthorizationResource;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Context\Value\MembershipContext;
use Kumwe\App\Application\Authorization\ResourceSiteOwnershipWriter;
use Kumwe\Context\Value\SiteContext;
use Kumwe\App\Application\Persistence\TransactionManager;
use Kumwe\App\BusinessSecurity\Application\MembershipDirectory;
use Kumwe\App\Identity\Application\Administration\AdministratorSession;
use Kumwe\App\Identity\Application\Administration\AdministratorSessionStore;
use Kumwe\App\Identity\Application\Administration\CreatedAdministratorSession;
use Kumwe\App\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\App\Identity\Application\StepUp\StepUpRejected;
use Kumwe\App\Identity\Application\StepUp\StepUpSessionRotator;
use Kumwe\App\Identity\Domain\StepUp\RotatedStepUpSession;
use Kumwe\App\Identity\Domain\StepUp\StepUpIntent;
use Kumwe\Extension\Spi\Identity\Domain\Capability;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use RuntimeException;

/**
 * Doctrine implementation of the administrator session store, backed by the `administrator_sessions` table.
 *
 * The row keeps only the SHA-256 digest of the cookie token and a keyed digest of the client
 * `User-Agent`, so neither the credential nor the header can be read back out of the database and a
 * cookie replayed from a different client does not resolve. Every resolution rebuilds the principal
 * from the user's current role grants and the security epoch on the user row rather than from anything
 * cached in the session, which is what makes a revocation bite on the next request instead of at the
 * session's expiry. Creation, deletion and purging each run inside one transaction together with the
 * matching `resource_site_ownership` write, so a session and the record of which site owns it appear
 * and disappear as a unit.
 *
 * @since  2.0.0
 */
final readonly class DoctrineAdministratorSessionStore implements AdministratorSessionStore, StepUpSessionRotator
{
    /**
     * Wire the store to its connection and authorization collaborators, and fix the session lifetime.
     *
     * @param   Connection                   $database           DBAL connection the session rows are read
     *          and written through.
     * @param   TableNames                   $tables             Resolver applying the configured prefix to
     *          the session, user and ownership tables.
     * @param   ClockInterface               $clock              Source of the creation, last-seen and
     *          expiry timestamps.
     * @param   string                       $applicationSecret  Installation secret keying the
     *          `User-Agent` fingerprint, so digests are useless in another deployment.
     * @param   AuthorizationGateway         $authorization      Judge of whether the actor may open, end
     *          or purge administrator sessions.
     * @param   TransactionManager           $transactions       Scope each write runs inside, alongside
     *          its ownership row.
     * @param   ResourceSiteOwnershipWriter  $ownership          Writer recording and withdrawing which
     *          site owns each session.
     * @param   object                       $provenance         Composition-root authority every principal
     *          resolved here is stamped with; anything else is untrusted.
     * @param   int                          $lifetimeSeconds    How long a newly opened session stays
     *          valid; eight hours unless configured, and never outside five minutes to seven days.
     * @param   ?MembershipDirectory         $memberships        Resolves trusted organization selections;
     *          optional solely for schema-upgrade compatibility in isolated tests.
     *
     * @throws  InvalidArgumentException  When the configured lifetime is below 300 or above 604800 seconds.
     *
     * @since   2.0.0
     */
    public function __construct(
        private Connection $database,
        private TableNames $tables,
        private ClockInterface $clock,
        private string $applicationSecret,
        private AuthorizationGateway $authorization,
        private TransactionManager $transactions,
        private ResourceSiteOwnershipWriter $ownership,
        private object $provenance,
        private int $lifetimeSeconds = 28_800,
        private ?MembershipDirectory $memberships = null,
    ) {
        if ($lifetimeSeconds < 300 || $lifetimeSeconds > 604_800) {
            throw new InvalidArgumentException('Administrator sessions must last between five minutes and seven days.');
        }
    }

    /**
     * Open a session for the context's principal and hand back the only copy of its cookie token.
     *
     * The token is 48 random bytes and the CSRF secret 32, both rendered as base64url. Only the token's
     * digest is stored, while the CSRF secret is kept in the clear because `AdministratorCsrfMiddleware`
     * compares it against what the browser posts back. The insert and its ownership row commit together,
     * so a rejected write cannot leave a session the authorization gateway would later refuse as unowned.
     *
     * @param   ExecutionContext  $context    Actor, site and provenance the sign-in happened under; its
     *          principal becomes the session's subject.
     * @param   string            $userAgent  Client `User-Agent` header; only its keyed digest is stored,
     *          and the session resolves later only for a client presenting the same one.
     *
     * @return  CreatedAdministratorSession  The stored session paired with the plaintext cookie token.
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor may not hold an
     *          administrator session.
     * @throws  InvalidArgumentException  When the context carries no human principal to sign in as.
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects the insert.
     *
     * @since   2.0.0
     */
    public function create(ExecutionContext $context, string $userAgent): CreatedAdministratorSession
    {
        $this->authorization->assertAllowed(
            $context,
            Capability::fromString('administrator.access'),
            AuthorizationResource::collection('administrator_session'),
        );
        $principal = AuthenticatedPrincipal::of($context)
            ?? throw new InvalidArgumentException('Administrator sessions require a human principal.');
        $id = Uuid::uuid7()->toString();
        $token = $this->base64Url(random_bytes(48));
        $csrf = $this->base64Url(random_bytes(32));
        $now = $this->clock->now();
        $expiresAt = $now->add(new DateInterval(sprintf('PT%dS', $this->lifetimeSeconds)));
        $this->transactions->transactional(function () use (
            $context,
            $principal,
            $id,
            $token,
            $csrf,
            $userAgent,
            $now,
            $expiresAt,
        ): void {
            $this->database->insert($this->tables->raw('administrator_sessions'), [
                'id' => $id,
                'user_id' => $principal->subject(),
                'token_digest' => hash('sha256', $token),
                'csrf_token' => $csrf,
                'ip_digest' => null,
                'user_agent_digest' => $this->fingerprint($userAgent),
                'created_at' => $now,
                'last_seen_at' => $now,
                'expires_at' => $expiresAt,
                'site_identifier' => $context->site()->identifier(),
                'organization_identifier' => $context->organization()?->identifier(),
                'workspace_identifier' => $context->workspace()?->identifier(),
                'membership_id' => $context->membership()?->membershipId(),
                'membership_version' => $context->membership()?->membershipVersion(),
                'policy_generation' => $context->membership()?->policyGeneration(),
                'rotation' => 1,
                'step_up_at' => null,
                'security_epoch' => $principal->securityEpoch(),
            ], [
                'created_at' => Types::DATETIME_IMMUTABLE,
                'last_seen_at' => Types::DATETIME_IMMUTABLE,
                'expires_at' => Types::DATETIME_IMMUTABLE,
            ]);
            $this->ownership->record(
                AuthorizationResource::item('administrator_session', $id),
                $context->site(),
            );
        });

        return new CreatedAdministratorSession(
            $token,
            new AdministratorSession($id, $principal, $csrf, $expiresAt, $context->site(), $context->membership()),
        );
    }

    /**
     * Resolve a cookie token back into the live session it names, or answer null.
     *
     * Everything an untrusted caller could learn from is collapsed into null: a token of the wrong shape
     * never reaches the database, and an unknown digest, a passed expiry, a non-active user, a
     * superseded security epoch and a mismatched `User-Agent` are indistinguishable in the answer. The
     * fingerprint comparison runs in constant time. A resolution that succeeds rebuilds the principal
     * from the user's current role grants and epoch and stamps `last_seen_at`, so the row tracks the
     * last request that used it.
     *
     * The epoch predicate is what makes a revocation reach a browser. The session records the epoch it
     * was issued under, the user row carries the epoch in force now, and the two must still be equal —
     * so a password change, a second-factor reset, a role edit or a break-glass token revocation ends
     * every live administrator cookie on the subject's very next request, the same way it already ended
     * their API tokens, their portal sessions and their outstanding step-up proofs.
     *
     * @param   string  $token      Opaque token from the administrator cookie; anything outside 43 to 128
     *          base64url characters is refused before a query is issued.
     * @param   string  $userAgent  Client `User-Agent` header, which must fingerprint to the value stored
     *          when the session was opened.
     *
     * @return  ?AdministratorSession  The session carrying a freshly rebuilt principal, or null whenever
     *          the token does not currently resolve to a live session of an active user.
     *
     * @throws  InvalidArgumentException  When the stored security epoch is unusable, or the stored grants
     *          do not assemble into a valid principal.
     * @throws  \DateMalformedStringException  When the stored expiry is a string no date reader accepts.
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects the lookup or the last-seen update.
     *
     * @since   2.0.0
     */
    public function find(string $token, string $userAgent): ?AdministratorSession
    {
        if (strlen($token) < 43 || strlen($token) > 128 || preg_match('/^[A-Za-z0-9_-]+$/D', $token) !== 1) {
            return null;
        }

        $now = $this->clock->now();
        $row = $this->database->fetchAssociative(sprintf(
            'SELECT s.id, s.user_id, s.csrf_token, s.expires_at, s.user_agent_digest, u.security_epoch, '
            . 's.site_identifier, s.organization_identifier, s.workspace_identifier, s.membership_id, '
            . 's.membership_version, s.policy_generation '
            . 'FROM %s s INNER JOIN %s u ON u.id = s.user_id '
            . "WHERE s.token_digest = ? AND s.expires_at > ? AND u.status = 'active' "
            . 'AND s.security_epoch = u.security_epoch',
            $this->tables->quoted('administrator_sessions'),
            $this->tables->quoted('users'),
        ), [hash('sha256', $token), $now], [Types::STRING, Types::DATETIME_IMMUTABLE]);

        if (
            $row === false
            || !is_string($row['user_agent_digest'] ?? null)
            || !hash_equals($row['user_agent_digest'], $this->fingerprint($userAgent))
            || !is_string($row['id'] ?? null)
            || !is_string($row['user_id'] ?? null)
            || !is_string($row['csrf_token'] ?? null)
        ) {
            return null;
        }

        $storedExpiry = $row['expires_at'] ?? null;
        if (!$storedExpiry instanceof DateTimeImmutable && !is_string($storedExpiry)) {
            return null;
        }
        $expiresAt = $storedExpiry instanceof DateTimeImmutable
            ? $storedExpiry
            : new DateTimeImmutable($storedExpiry);
        $this->database->update(
            $this->tables->raw('administrator_sessions'),
            ['last_seen_at' => $now],
            ['id' => $row['id']],
            ['last_seen_at' => Types::DATETIME_IMMUTABLE],
        );

        try {
            $site = SiteContext::fromString(is_string($row['site_identifier'] ?? null)
                ? $row['site_identifier']
                : SiteContext::DEFAULT);
            $organization = $row['organization_identifier'] ?? null;
            $workspace = $row['workspace_identifier'] ?? null;
            $storedMembershipId = $row['membership_id'] ?? null;
            $storedMembershipVersion = $row['membership_version'] ?? null;
            $storedPolicyGeneration = $row['policy_generation'] ?? null;
            if (
                ($organization !== null && !is_string($organization))
                || ($workspace !== null && !is_string($workspace))
            ) {
                return null;
            }
            if (
                $organization === null && (
                $workspace !== null
                || $storedMembershipId !== null
                || $storedMembershipVersion !== null
                || $storedPolicyGeneration !== null
                )
            ) {
                return null;
            }
            $membership = $organization === null || $this->memberships === null
                ? null
                : $this->memberships->resolve($row['user_id'], $site, $organization, $workspace);
            if (
                $organization !== null && (
                $membership === null
                || $membership->membershipId() !== $storedMembershipId
                || $membership->membershipVersion() !== $this->positiveInteger($storedMembershipVersion)
                || $membership->policyGeneration() !== $this->positiveInteger($storedPolicyGeneration)
                )
            ) {
                return null;
            }
        } catch (InvalidArgumentException) {
            return null;
        }

        return new AdministratorSession(
            $row['id'],
            AuthenticatedPrincipal::issueFromGrantRows(
                $this->provenance,
                $row['user_id'],
                $this->grantsFor($row['user_id'], $site, $membership),
                'administrator-session:' . $row['id'],
                $this->positiveInteger($row['security_epoch'] ?? null),
            ),
            $row['csrf_token'],
            $expiresAt,
            $site,
            $membership,
        );
    }

    /**
     * Rotate an administrator session onto one exact live membership scope.
     *
     * @param   ExecutionContext  $context                 Current authenticated administrator session.
     * @param   string            $organizationIdentifier  Exact organization to select.
     * @param   ?string           $workspaceIdentifier     Optional exact workspace to select.
     * @param   string            $userAgent               Current user-agent value bound to the replacement session.
     *
     * @return  CreatedAdministratorSession  Opaque replacement token and selected membership session.
     *
     * @since   2.0.0
     */
    public function selectMembership(
        ExecutionContext $context,
        string $organizationIdentifier,
        ?string $workspaceIdentifier,
        string $userAgent,
    ): CreatedAdministratorSession {
        $sessionId = $context->sessionId()
            ?? throw new InvalidArgumentException('An administrator membership selection requires a live session.');
        $principal = AuthenticatedPrincipal::of($context)
            ?? throw new InvalidArgumentException('An administrator membership selection requires a human actor.');
        $directory = $this->memberships
            ?? throw new InvalidArgumentException('Organization membership resolution is unavailable.');
        $this->authorization->assertAllowed(
            $context,
            Capability::fromString('administrator.access'),
            AuthorizationResource::item('administrator_session', $sessionId),
        );
        $membership = $directory->resolve(
            $context->actorId(),
            $context->site(),
            $organizationIdentifier,
            $workspaceIdentifier,
            true,
        ) ?? throw new InvalidArgumentException('The requested organization selection is not available.');
        $replacementId = Uuid::uuid7()->toString();
        $token = $this->base64Url(random_bytes(48));
        $csrf = $this->base64Url(random_bytes(32));
        $now = $this->clock->now();
        $expiresAt = $now->add(new DateInterval(sprintf('PT%dS', $this->lifetimeSeconds)));

        $this->transactions->transactional(function () use (
            $context,
            $sessionId,
            $replacementId,
            $principal,
            $token,
            $csrf,
            $userAgent,
            $membership,
            $now,
            $expiresAt,
        ): void {
            if (!$this->memberships?->current($context->actorId(), $context->site(), $membership, true)) {
                throw new InvalidArgumentException('The requested organization selection is no longer current.');
            }
            $affected = $this->database->delete(
                $this->tables->raw('administrator_sessions'),
                ['id' => $sessionId, 'user_id' => $context->actorId()],
                ['id' => Types::GUID, 'user_id' => Types::GUID],
            );
            if ($affected !== 1) {
                throw new InvalidArgumentException('The administrator session changed during rotation.');
            }
            $this->ownership->remove(
                AuthorizationResource::item('administrator_session', $sessionId),
                $context->site(),
            );
            $this->database->insert($this->tables->raw('administrator_sessions'), [
                'id' => $replacementId,
                'user_id' => $principal->subject(),
                'token_digest' => hash('sha256', $token),
                'csrf_token' => $csrf,
                'ip_digest' => null,
                'user_agent_digest' => $this->fingerprint($userAgent),
                'created_at' => $now,
                'last_seen_at' => $now,
                'expires_at' => $expiresAt,
                'site_identifier' => $context->site()->identifier(),
                'organization_identifier' => $membership->organization()->identifier(),
                'workspace_identifier' => $membership->workspace()?->identifier(),
                'membership_id' => $membership->membershipId(),
                'membership_version' => $membership->membershipVersion(),
                'policy_generation' => $membership->policyGeneration(),
                'rotation' => 1,
                'step_up_at' => null,
                'security_epoch' => $principal->securityEpoch(),
            ], [
                'created_at' => Types::DATETIME_IMMUTABLE,
                'last_seen_at' => Types::DATETIME_IMMUTABLE,
                'expires_at' => Types::DATETIME_IMMUTABLE,
            ]);
            $this->ownership->record(
                AuthorizationResource::item('administrator_session', $replacementId),
                $context->site(),
            );
        });

        return new CreatedAdministratorSession(
            $token,
            new AdministratorSession(
                $replacementId,
                $principal,
                $csrf,
                $expiresAt,
                $context->site(),
                $membership,
            ),
        );
    }

    /**
     * Rotate the exact live administrator session after a successful second-factor challenge.
     *
     * The old row is locked and every server-resolved coordinate from the intent is compared before a
     * replacement cookie, CSRF token, and ownership row are installed atomically. The stored browser
     * binding is copied rather than accepting a header from the challenge form. Both epochs are
     * compared against the intent — the user's current one and the one the session being elevated was
     * issued under — so a revocation that landed between the challenge starting and its verification
     * arriving refuses the rotation instead of minting a session under a retired authority.
     *
     * @param   StepUpIntent       $intent      Exact old session and authority context being elevated.
     * @param   DateTimeImmutable  $verifiedAt  Trusted successful verification instant.
     *
     * @return  RotatedStepUpSession  Replacement session id, one-time cookie, CSRF, and expiry.
     *
     * @throws  StepUpRejected  When the session, user, scope, epoch, membership, or expiry changed.
     *
     * @since   2.0.0
     */
    public function rotate(StepUpIntent $intent, DateTimeImmutable $verifiedAt): RotatedStepUpSession
    {
        return $this->transactions->transactional(function () use ($intent, $verifiedAt): RotatedStepUpSession {
            $row = $this->database->fetchAssociative(sprintf(
                'SELECT s.*, u.security_epoch AS user_security_epoch FROM %s s '
                . 'INNER JOIN %s u ON u.id = s.user_id '
                . "WHERE s.id = ? AND s.user_id = ? AND u.status = 'active'%s",
                $this->tables->quoted('administrator_sessions'),
                $this->tables->quoted('users'),
                $this->lockClause(),
            ), [$intent->sessionId, $intent->subjectId]);
            if (!is_array($row)) {
                throw new StepUpRejected();
            }
            $expiresAt = $row['expires_at'] instanceof DateTimeImmutable
                ? $row['expires_at']
                : (is_string($row['expires_at'] ?? null) ? new DateTimeImmutable($row['expires_at']) : null);
            $organization = is_string($row['organization_identifier'] ?? null)
                ? $row['organization_identifier']
                : null;
            $workspace = is_string($row['workspace_identifier'] ?? null) ? $row['workspace_identifier'] : null;
            if (
                !$expiresAt instanceof DateTimeImmutable
                || $expiresAt <= $verifiedAt
                || ($row['site_identifier'] ?? null) !== $intent->siteIdentifier
                || $organization !== $intent->organizationIdentifier
                || $workspace !== $intent->workspaceIdentifier
                || $this->positiveInteger($row['user_security_epoch'] ?? null) !== $intent->securityEpoch
                || $this->positiveInteger($row['security_epoch'] ?? null) !== $intent->securityEpoch
            ) {
                throw new StepUpRejected();
            }
            if ($organization !== null) {
                $membership = $this->memberships?->resolve(
                    $intent->subjectId,
                    SiteContext::fromString($intent->siteIdentifier),
                    $organization,
                    $workspace,
                    true,
                );
                if (
                    $membership === null
                    || $membership->membershipId() !== ($row['membership_id'] ?? null)
                    || $membership->membershipVersion()
                        !== $this->positiveInteger($row['membership_version'] ?? null)
                    || $membership->policyGeneration()
                        !== $this->positiveInteger($row['policy_generation'] ?? null)
                ) {
                    throw new StepUpRejected();
                }
            }

            $replacementId = Uuid::uuid7()->toString();
            $token = $this->base64Url(random_bytes(48));
            $csrf = $this->base64Url(random_bytes(32));
            $this->database->insert($this->tables->raw('administrator_sessions'), [
                'id' => $replacementId,
                'user_id' => $intent->subjectId,
                'token_digest' => hash('sha256', $token),
                'csrf_token' => $csrf,
                'ip_digest' => $row['ip_digest'] ?? null,
                'user_agent_digest' => $row['user_agent_digest'],
                'created_at' => $verifiedAt,
                'last_seen_at' => $verifiedAt,
                'expires_at' => $expiresAt,
                'site_identifier' => $intent->siteIdentifier,
                'organization_identifier' => $organization,
                'workspace_identifier' => $workspace,
                'membership_id' => $row['membership_id'] ?? null,
                'membership_version' => $row['membership_version'] ?? null,
                'policy_generation' => $row['policy_generation'] ?? null,
                'rotation' => $this->positiveInteger($row['rotation'] ?? 1) + 1,
                'step_up_at' => $verifiedAt,
                'security_epoch' => $intent->securityEpoch,
            ], [
                'created_at' => Types::DATETIME_IMMUTABLE,
                'last_seen_at' => Types::DATETIME_IMMUTABLE,
                'expires_at' => Types::DATETIME_IMMUTABLE,
                'step_up_at' => Types::DATETIME_IMMUTABLE,
            ]);
            if (
                $this->database->delete(
                    $this->tables->raw('administrator_sessions'),
                    ['id' => $intent->sessionId, 'user_id' => $intent->subjectId],
                ) !== 1
            ) {
                throw new StepUpRejected();
            }
            $site = SiteContext::fromString($intent->siteIdentifier);
            $this->ownership->remove(
                AuthorizationResource::item('administrator_session', $intent->sessionId),
                $site,
            );
            $this->ownership->record(
                AuthorizationResource::item('administrator_session', $replacementId),
                $site,
            );

            return new RotatedStepUpSession($replacementId, $token, $csrf, $expiresAt);
        });
    }

    /**
     * Read a stored security epoch that reaches PHP as either a native integer or a decimal string.
     *
     * Drivers disagree on whether an integer column comes back typed, so both spellings are accepted: an
     * `int` is taken as it stands, a string only when it is a positive decimal without a leading zero.
     * Anything else is a corrupt row rather than a value to coerce, because the epoch is what a
     * revocation raises to invalidate every credential already issued to the user.
     *
     * @param   mixed  $value  Raw `security_epoch` column value exactly as the driver returned it.
     *
     * @return  int  The epoch, cast from the string form when that is what the driver produced.
     *
     * @throws  InvalidArgumentException  When the value is neither an integer nor a string of digits
     *          beginning with 1 through 9.
     *
     * @since   2.0.0
     */
    private function positiveInteger(mixed $value): int
    {
        if (!is_int($value) && (!is_string($value) || preg_match('/^[1-9][0-9]*$/D', $value) !== 1)) {
            throw new InvalidArgumentException('Stored user security epoch is invalid.');
        }

        return (int) $value;
    }

    /**
     * End one session and withdraw its ownership row in the same transaction.
     *
     * The delete is matched on the identifier alone and must affect exactly one row, so ending a session
     * another request has already ended is reported rather than quietly accepted. Ownership is withdrawn
     * for the context's own site, so an actor acting for the wrong site aborts the transaction instead of
     * removing a session that belongs elsewhere.
     *
     * @param   ExecutionContext  $context    Actor, site and provenance the sign-out runs under.
     * @param   string            $sessionId  UUID of the session to end.
     *
     * @return  void
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor may not end this
     *          session.
     * @throws  InvalidArgumentException  When no row carries that identifier, so nothing was ended.
     * @throws  \Kumwe\App\Application\Authorization\ResourceSiteOwnershipConflict  When the session's
     *          ownership row names a site other than the context's.
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationResourceOwnershipUnknown  When the
     *          session carries no ownership row to withdraw.
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects the delete.
     *
     * @since   2.0.0
     */
    public function delete(ExecutionContext $context, string $sessionId): void
    {
        $this->authorization->assertAllowed(
            $context,
            Capability::fromString('administrator.access'),
            AuthorizationResource::item('administrator_session', $sessionId),
        );
        $this->transactions->transactional(function () use ($context, $sessionId): void {
            $affected = $this->database->delete(
                $this->tables->raw('administrator_sessions'),
                ['id' => $sessionId],
            );
            if ((string) $affected !== '1') {
                throw new InvalidArgumentException('The administrator session does not exist.');
            }
            $this->ownership->remove(
                AuthorizationResource::item('administrator_session', $sessionId),
                $context->site(),
            );
        });
    }

    /**
     * End every administrator session one user holds, in every site, inside one transaction.
     *
     * Authorization follows who is being signed out. Ending somebody else's sessions is an
     * identity-management act, asserted as `users.manage` against that exact user, because demanding
     * `administrator.access` on rows the actor does not own would refuse precisely the responder this
     * exists for. Ending your own is the sign-out-everywhere a password change performs on itself, so
     * it asks only for the `administrator.access` the actor must already hold to have a session at all.
     * Candidates are locked in identifier order so two concurrent terminations queue rather than
     * deadlock, and each session is removed together with the ownership row for the site that session
     * itself records — never the actor's own site, which would be the wrong site for a session opened
     * elsewhere. A row that vanished between the select and its delete aborts the whole termination
     * rather than being skipped, so the count returned is exactly what was ended.
     *
     * The security epoch remains the primary instrument; this is the direct sweep that accompanies it,
     * so the session table does not keep rows that can no longer resolve.
     *
     * @param   ExecutionContext  $context  Actor, site and request identifiers the termination runs under.
     * @param   string            $userId   UUID of the user whose sessions are all ended.
     *
     * @return  int  How many live sessions were ended, zero when the user held none.
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor may not manage
     *          this user.
     * @throws  RuntimeException  When a selected identifier is not usable, a locked row no longer
     *          existed when its delete ran, or a session records no site to withdraw ownership for.
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects the candidate read or one of the deletes.
     *
     * @since   2.0.0
     */
    public function deleteAllForUser(ExecutionContext $context, string $userId): int
    {
        $self = $context->actorId() === $userId;
        $this->authorization->assertAllowed(
            $context,
            Capability::fromString($self ? 'administrator.access' : 'users.manage'),
            $self
                ? AuthorizationResource::collection('administrator_session')
                : AuthorizationResource::item('user', $userId),
        );

        return $this->transactions->transactional(function () use ($userId): int {
            $rows = $this->database->fetchAllAssociative(sprintf(
                'SELECT id, site_identifier FROM %s WHERE user_id = ? ORDER BY id%s',
                $this->tables->quoted('administrator_sessions'),
                $this->lockClause(),
            ), [$userId], [Types::GUID]);
            foreach ($rows as $row) {
                $sessionId = $row['id'] ?? null;
                $siteIdentifier = $row['site_identifier'] ?? null;
                if (!is_string($sessionId) || $sessionId === '' || !is_string($siteIdentifier)) {
                    throw new RuntimeException('An administrator session row is unusable for termination.');
                }
                $affected = $this->database->delete(
                    $this->tables->raw('administrator_sessions'),
                    ['id' => $sessionId],
                );
                if ((string) $affected !== '1') {
                    throw new RuntimeException('An administrator session changed during termination.');
                }
                $this->ownership->remove(
                    AuthorizationResource::item('administrator_session', $sessionId),
                    SiteContext::fromString($siteIdentifier),
                );
            }

            return count($rows);
        });
    }

    /**
     * Delete every expired session the context's site owns, inside one locked transaction.
     *
     * Candidates are chosen by joining the ownership table, so a purge run for one site never reaches
     * another's rows — on PostgreSQL the session identifier is cast to text to meet the ownership
     * column's type. Candidates are locked `FOR UPDATE` in identifier order, so two concurrent purges
     * take them in the same order and queue rather than deadlock, and each session is deleted together
     * with its ownership row. A row that vanished between the select and its delete aborts the whole
     * purge instead of being skipped.
     *
     * @param   ExecutionContext  $context  Actor, site and provenance the purge runs under; the site
     *          decides which sessions are in scope.
     *
     * @return  int  How many expired sessions were removed, zero when there was nothing to clear.
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor may not run
     *          administrator housekeeping.
     * @throws  RuntimeException  When a selected identifier is not a usable string, or a locked row no
     *          longer existed when its delete ran.
     * @throws  \Kumwe\App\Application\Authorization\ResourceSiteOwnershipConflict  When a session's
     *          ownership row names a site other than the context's.
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationResourceOwnershipUnknown  When a session
     *          carries no ownership row to withdraw.
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects the candidate read or one of the deletes.
     *
     * @since   2.0.0
     */
    public function purgeExpired(ExecutionContext $context): int
    {
        $this->authorization->assertAllowed(
            $context,
            Capability::fromString('automation.manage'),
            AuthorizationResource::collection('administrator_session'),
        );
        return $this->transactions->transactional(function () use ($context): int {
            $now = $this->clock->now();
            $sessionOwnershipId = $this->database->getDatabasePlatform() instanceof PostgreSQLPlatform
                ? 'CAST(s.id AS VARCHAR)'
                : 's.id';
            $sessionIds = $this->database->fetchFirstColumn(sprintf(
                'SELECT s.id FROM %s s INNER JOIN %s o ON o.resource_type = ? AND o.resource_id = %s '
                . 'AND o.site_identifier = ? WHERE s.expires_at <= ? ORDER BY s.id%s',
                $this->tables->quoted('administrator_sessions'),
                $this->tables->quoted('resource_site_ownership'),
                $sessionOwnershipId,
                $this->lockClause(),
            ), ['administrator_session', $context->site()->identifier(), $now], [
                Types::STRING,
                Types::STRING,
                Types::DATETIME_IMMUTABLE,
            ]);

            foreach ($sessionIds as $sessionId) {
                if (!is_string($sessionId) || $sessionId === '') {
                    throw new RuntimeException('An expired administrator session identifier is invalid.');
                }
                $affected = $this->database->delete(
                    $this->tables->raw('administrator_sessions'),
                    ['id' => $sessionId],
                );
                if ((string) $affected !== '1') {
                    throw new RuntimeException('An expired administrator session changed during deletion.');
                }
                $this->ownership->remove(
                    AuthorizationResource::item('administrator_session', $sessionId),
                    $context->site(),
                );
            }

            return count($sessionIds);
        });
    }

    /**
     * Return the row-lock suffix supported by the active database platform.
     *
     * @return  string  Empty for SQLite and `FOR UPDATE` elsewhere.
     *
     * @since   2.0.0
     */
    private function lockClause(): string
    {
        return $this->database->getDatabasePlatform() instanceof SQLitePlatform ? '' : ' FOR UPDATE';
    }

    /**
     * Read every role grant the user holds, keeping the reach each one was recorded at.
     *
     * This is what `AuthenticatedPrincipal::issueFromGrantRows()` consumes on each resolution, so a
     * capability granted over a single resource stays confined to it instead of being promoted to a
     * global grant when the session is rebuilt.
     *
     * @param   string              $userId      UUID of the user whose role grants are read.
     * @param   SiteContext         $site        Exact administrator session site.
     * @param   ?MembershipContext  $membership  Live selected membership, or null for global-only context.
     *
     * @return  list<array{capability: string, scope_type: string, scope_identifier: ?string}>
     *          One row per distinct grant, ordered by capability, scope type and scope identifier; empty
     *          when the user's roles grant nothing.
     *
     * @throws  InvalidArgumentException  When a stored grant row is not the expected scalar shape.
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects the read.
     *
     * @since   2.0.0
     */
    private function grantsFor(
        string $userId,
        SiteContext $site,
        ?MembershipContext $membership,
    ): array {
        if ($membership !== null) {
            $workspace = $membership->workspace()?->identifier();
            $parameters = [
                $userId,
                $membership->membershipId(),
                $userId,
                $membership->membershipVersion(),
                $site->identifier(),
                $membership->organization()->identifier(),
                $membership->policyGeneration(),
            ];

            $sql = sprintf(
                'SELECT g.capability_code AS capability, g.scope_type, g.scope_identifier '
                . 'FROM %s ur INNER JOIN %s g ON g.role_id = ur.role_id WHERE ur.user_id = ? '
                . 'UNION SELECT g.capability_code AS capability, g.scope_type, g.scope_identifier '
                . 'FROM %s mr INNER JOIN %s g ON g.role_id = mr.role_id '
                . 'INNER JOIN %s m ON m.id = mr.membership_id '
                . 'INNER JOIN %s o ON o.id = m.organization_id '
                . 'WHERE mr.membership_id = ? AND m.user_id = ? AND m.version = ? '
                . "AND m.status = 'active' AND m.valid_from <= CURRENT_TIMESTAMP "
                . 'AND (m.valid_until IS NULL OR m.valid_until > CURRENT_TIMESTAMP) '
                . "AND o.site_identifier = ? AND o.identifier = ? AND o.status = 'active' "
                . 'AND o.policy_generation = ? ',
                $this->tables->quoted('user_roles'),
                $this->tables->quoted('role_capability_grants'),
                $this->tables->quoted('membership_roles'),
                $this->tables->quoted('role_capability_grants'),
                $this->tables->quoted('organization_memberships'),
                $this->tables->quoted('organizations'),
            );
            if ($workspace !== null) {
                $sql .= sprintf(
                    'AND EXISTS (SELECT 1 FROM %s mw INNER JOIN %s w ON w.id = mw.workspace_id '
                    . 'WHERE mw.membership_id = m.id AND w.organization_id = o.id '
                    . "AND w.identifier = ? AND w.status = 'active') ",
                    $this->tables->quoted('membership_workspaces'),
                    $this->tables->quoted('workspaces'),
                );
                $parameters[] = $workspace;
            }
            $sql .= 'ORDER BY capability, scope_type, scope_identifier';
        } else {
            $parameters = [$userId];
            $sql = sprintf(
                'SELECT DISTINCT g.capability_code AS capability, g.scope_type, g.scope_identifier '
                . 'FROM %s ur INNER JOIN %s g ON g.role_id = ur.role_id WHERE ur.user_id = ? '
                . 'ORDER BY g.capability_code, g.scope_type, g.scope_identifier',
                $this->tables->quoted('user_roles'),
                $this->tables->quoted('role_capability_grants'),
            );
        }

        $grants = [];
        foreach ($this->database->fetchAllAssociative($sql, $parameters) as $row) {
            $capability = $row['capability'] ?? null;
            $scopeType = $row['scope_type'] ?? null;
            $scopeIdentifier = $row['scope_identifier'] ?? null;
            if (!is_string($capability) || !is_string($scopeType)) {
                throw new InvalidArgumentException('A stored administrator principal grant is invalid.');
            }
            if ($scopeIdentifier !== null && !is_string($scopeIdentifier)) {
                throw new InvalidArgumentException('A stored administrator principal grant scope is invalid.');
            }
            $grants[] = [
                'capability' => $capability,
                'scope_type' => $scopeType,
                'scope_identifier' => $scopeIdentifier,
            ];
        }

        return $grants;
    }

    /**
     * Reduce a client `User-Agent` header to the keyed digest stored beside the session.
     *
     * Only the first 512 bytes are folded in, so an unusually long header still fingerprints to a fixed
     * width, and the installation secret keys the HMAC, so a digest lifted from one deployment's table
     * cannot be recognised in another. The raw header itself never reaches storage.
     *
     * @param   string  $userAgent  Header exactly as the client sent it, of any length.
     *
     * @return  string  Hex SHA-256 HMAC to store or compare, identical for the same header and secret.
     *
     * @since   2.0.0
     */
    private function fingerprint(string $userAgent): string
    {
        return hash_hmac('sha256', substr($userAgent, 0, 512), $this->applicationSecret);
    }

    /**
     * Render random bytes as unpadded base64url, the only alphabet `find()` will accept back.
     *
     * `+` and `/` are swapped for `-` and `_` and the `=` padding is dropped, so the value survives a
     * `Set-Cookie` header and a URL without escaping and still matches the character class the token
     * shape check applies before any query is issued.
     *
     * @param   string  $bytes  Raw random bytes to encode.
     *
     * @return  string  URL-safe, unpadded base64 text.
     *
     * @since   2.0.0
     */
    private function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
