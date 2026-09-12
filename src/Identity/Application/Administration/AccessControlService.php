<?php

declare(strict_types=1);

namespace Kumwe\App\Identity\Application\Administration;

use InvalidArgumentException;
use Kumwe\App\Application\Authorization\AuthorizationGateway;
use Kumwe\App\Application\Authorization\AuthorizationResource;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\App\Application\Authorization\ResourceSiteOwnershipWriter;
use Kumwe\Context\Value\SiteContext;
use Kumwe\Transaction\Contract\TransactionManager;
use Kumwe\App\Application\Security\HighImpactCredentialGuard;
use Kumwe\App\Audit\Application\AuditRecorder;
use Kumwe\App\Audit\Domain\AuditEvent;
use Kumwe\App\Identity\Application\Security\PasswordHasher;
use Kumwe\App\Identity\Application\StepUp\StepUpCredentialStore;
use Kumwe\Extension\Spi\Identity\Domain\Capability;
use Kumwe\App\Identity\Domain\EmailAddress;
use Kumwe\App\Identity\Domain\GrantScope;
use Kumwe\App\Identity\Domain\UserStatus;
use Kumwe\App\Shared\Domain\CanonicalJson;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use RuntimeException;

/**
 * The one place users, roles, capability grants and token revocation are changed.
 *
 * The administrator screens, the identity REST surface, the `access` console command and the MCP
 * tools all funnel through this service instead of touching the store, which is what keeps them at
 * parity: what one refuses, the others refuse too. Four rules hold across every entry point here.
 * `users.manage` is asserted against the exact record being touched, never merely against the
 * installation. Listings are filtered row by row, so a partially scoped administrator gets a short
 * list rather than a refusal. Each mutation runs in one transaction that writes its audit entry
 * beside the change, taking a row lock first wherever the decision depends on stored state. And an
 * actor may neither delegate authority it does not hold nor take away its own administrator access.
 *
 * @since  2.0.0
 */
final readonly class AccessControlService
{
    /**
     * Wire the store and the collaborators every mutation here depends on.
     *
     * @param  AccessControlRepository      $repository     Store the users, roles, grants and tokens live in.
     * @param  PasswordHasher               $passwords      Hashes a new or replacement password before it is
     *         stored, and is the only place a plaintext one is ever compared.
     * @param  TransactionManager           $transactions   Wraps each mutation so its lock, write and audit
     *         entry commit together or not at all.
     * @param  AuditRecorder                $audit          Records who changed what, on every mutation.
     * @param  ClockInterface               $clock          Supplies the instants written onto the records.
     * @param  AuthorizationGateway         $authorization  Answers both the access and the delegation question.
     * @param  ResourceSiteOwnershipWriter  $ownership      Registers each new record against the site that owns
     *         it, so later authorization can resolve its scope.
     * @param  HighImpactCredentialGuard    $credentials    Re-proves the actor's current password, under the
     *         same throttle sign-in uses, before they may replace it.
     * @param  StepUpCredentialStore        $stepUp         Store the enrolled second factors live in, so an
     *         administrator can retire one the holder can no longer present.
     * @param  AdministratorSessionStore    $sessions       Ends the subject's browser sessions beside the
     *         epoch advance that already invalidates their tokens and proofs.
     *
     * @since  2.0.0
     */
    public function __construct(
        private AccessControlRepository $repository,
        private PasswordHasher $passwords,
        private TransactionManager $transactions,
        private AuditRecorder $audit,
        private ClockInterface $clock,
        private AuthorizationGateway $authorization,
        private ResourceSiteOwnershipWriter $ownership,
        private HighImpactCredentialGuard $credentials,
        private StepUpCredentialStore $stepUp,
        private AdministratorSessionStore $sessions,
    ) {
    }

    /**
     * List the users this actor may manage.
     *
     * The collection is authorized first and then every row again, so an administrator scoped to part
     * of the installation sees a shorter list instead of being refused outright.
     *
     * @param   ExecutionContext  $context  Actor, site and request identifiers the listing runs under.
     *
     * @return  list<array<string, mixed>>  One row per visible user, each carrying its assigned roles.
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor may not manage users
     *          at all.
     *
     * @since   2.0.0
     */
    public function users(ExecutionContext $context): array
    {
        $this->authorize($context, AuthorizationResource::collection('user'));
        return $this->filterPaged($context, 'user', $this->repository->users(...));
    }

    /**
     * List the roles this actor may manage, each with the capabilities it confers.
     *
     * @param   ExecutionContext  $context  Actor, site and request identifiers the listing runs under.
     *
     * @return  list<array<string, mixed>>  One row per visible role, each carrying its capability grants.
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor may not manage roles
     *          at all.
     *
     * @since   2.0.0
     */
    public function roles(ExecutionContext $context): array
    {
        $this->authorize($context, AuthorizationResource::collection('role'));
        $roles = $this->filterPaged($context, 'role', $this->repository->roles(...));
        foreach ($roles as &$role) {
            $grants = $role['grants'] ?? null;
            if (!is_array($grants) || !array_is_list($grants)) {
                throw new RuntimeException('A role grant inventory is invalid.');
            }
            /** @var list<array<string, mixed>> $grants */
            $role['grant_snapshot'] = $this->grantSnapshot($grants);
        }
        unset($role);

        return $roles;
    }

    /**
     * List the capability vocabulary a grant may name.
     *
     * This is the choice list the administrator and the API offer when someone writes a grant; it says
     * what exists, not what any role holds.
     *
     * @param   ExecutionContext  $context  Actor, site and request identifiers the listing runs under.
     *
     * @return  list<array{code: string, description: string}>  Capability codes with their operator-facing text.
     *
     * @throws  RuntimeException  When a stored capability row is missing its code or its description.
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor may not manage
     *          capabilities.
     *
     * @since   2.0.0
     */
    public function capabilities(ExecutionContext $context): array
    {
        $this->authorize($context, AuthorizationResource::collection('capability'));
        $rows = $this->filterPaged(
            $context,
            'capability',
            $this->repository->capabilities(...),
            'code',
        );

        return array_map(static function (array $row): array {
            $code = $row['code'] ?? null;
            $description = $row['description'] ?? null;
            if (!is_string($code) || !is_string($description)) {
                throw new RuntimeException('A capability record is invalid.');
            }

            return ['code' => $code, 'description' => $description];
        }, $rows);
    }

    /**
     * List the API tokens issued for the context's site.
     *
     * Only metadata comes back: a token's secret exists solely in the response that minted it.
     *
     * @param   ExecutionContext  $context  Actor and site; only that site's tokens are considered.
     *
     * @return  list<array<string, mixed>>  Newest first, each row carrying the token's subject, scope and life.
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor may not manage the
     *          site's tokens.
     *
     * @since   2.0.0
     */
    public function tokens(ExecutionContext $context): array
    {
        $this->authorize($context, AuthorizationResource::item('site', $context->site()->identifier()));
        $siteIdentifier = $context->site()->identifier();
        return $this->filterPaged(
            $context,
            'api_token',
            fn (int $limit, int $offset): array => $this->repository->tokens(
                $siteIdentifier,
                $limit,
                $offset,
            ),
        );
    }

    /**
     * List recent installation-wide identity and credential security events.
     *
     * `users.manage` is installation-global, so the collection authorization occurs before reading any
     * audit row. The repository exposes a closed identity-only projection and omits event metadata, which
     * prevents this focused timeline from becoming an unbounded audit or secret-disclosure surface.
     *
     * @param   ExecutionContext  $context  Authenticated installation identity administrator.
     *
     * @return  list<array<string, mixed>>  Newest accountable identity events, capped at 100 rows.
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor lacks installation
     *          identity-management authority.
     *
     * @since   2.0.0
     */
    public function securityEvents(ExecutionContext $context): array
    {
        $this->authorize($context, AuthorizationResource::collection('user'));

        return $this->repository->securityEvents();
    }

    /**
     * Create a user with a hashed password and register it against the default site.
     *
     * The address, name and password are normalised, checked and hashed before anything is written; the
     * insert, the ownership record and the audit entry then share one transaction.
     *
     * @param   ExecutionContext  $context      Actor, site and request identifiers the creation runs under.
     * @param   string            $email        Address the new user will sign in with.
     * @param   string            $displayName  Human-readable name, 1 to 191 characters once trimmed.
     * @param   string            $password     Plaintext password; only its hash reaches the store.
     * @param   UserStatus        $status       Lifecycle status to start at, active unless stated otherwise.
     *
     * @return  string  UUID of the created user.
     *
     * @throws  InvalidArgumentException  When the address is not an email or the display name is unusable.
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor may not create users.
     *
     * @since   2.0.0
     */
    public function createUser(
        ExecutionContext $context,
        string $email,
        string $displayName,
        string $password,
        UserStatus $status = UserStatus::Active,
    ): string {
        $this->authorize($context, AuthorizationResource::collection('user'));
        $id = Uuid::uuid7()->toString();
        $email = EmailAddress::fromString($email)->value();
        $displayName = $this->displayName($displayName);
        $hash = $this->passwords->hash($password);
        $at = $this->clock->now();

        return $this->transactions->transactional(function () use (
            $id,
            $email,
            $displayName,
            $status,
            $hash,
            $context,
            $at,
        ): string {
            $this->repository->insertUser(
                $id,
                $email,
                $displayName,
                $status->value,
                $hash,
                $at,
            );
            $this->ownership->record(AuthorizationResource::item('user', $id), SiteContext::default());
            $this->audit($context->actorId(), 'user.create', 'user', $id, ['status' => $status->value]);

            return $id;
        });
    }

    /**
     * Apply an edited user record under the store's optimistic-concurrency rule.
     *
     * Two guards stand in front of the write: an actor may not move its own account to a status that
     * cannot sign in, and the requested status must be a legal move from the one currently stored, which
     * is read under a lock taken inside the transaction. The store advances the user's security epoch as
     * the write lands, so tokens issued before the edit stop verifying.
     *
     * @param   ExecutionContext  $context          Actor, site and request identifiers the edit runs under.
     * @param   string            $id               UUID of the user being edited.
     * @param   string            $email            Replacement address.
     * @param   string            $displayName      Replacement human-readable name.
     * @param   UserStatus        $status           Lifecycle status the user is asked to move to.
     * @param   int               $expectedVersion  Version the caller read; a stale one aborts the write.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the actor would lock itself out, the input is unusable, the
     *          transition is illegal, or the record moved on under the caller.
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor may not manage this
     *          user.
     *
     * @since   2.0.0
     */
    public function updateUser(
        ExecutionContext $context,
        string $id,
        string $email,
        string $displayName,
        UserStatus $status,
        int $expectedVersion,
    ): void {
        $this->authorize($context, AuthorizationResource::item('user', $id));
        if ($id === $context->actorId() && !$status->canAuthenticate()) {
            throw new InvalidArgumentException('You cannot disable or suspend your own administrator account.');
        }

        $email = EmailAddress::fromString($email)->value();
        $displayName = $this->displayName($displayName);
        $at = $this->clock->now();
        $this->transactions->transactional(function () use (
            $context,
            $id,
            $email,
            $displayName,
            $status,
            $expectedVersion,
            $at,
        ): void {
            $this->repository->lockUser($id);
            $this->assertLifecycleTransition($id, $status);
            $this->repository->updateUser(
                $id,
                $email,
                $displayName,
                $status->value,
                $expectedVersion,
                $at,
            );
            $this->audit($context->actorId(), 'user.update', 'user', $id, ['status' => $status->value]);
        });
    }

    /**
     * Replace the acting operator's own password, after they have re-proved the current one.
     *
     * The one path in the installation that a person may use on themselves, and the only one that
     * accepts a plaintext current password: `HighImpactCredentialGuard` compares it against the stored
     * hash joined to an active user row, through the same hasher sign-in uses and behind the same
     * throttle, so a run of wrong guesses spends an attempt budget rather than buying an oracle. The
     * new password is measured against the same rule an administrative reset applies, and refused when
     * it equals the one being replaced — a change that changes nothing would advance the epoch and sign
     * the operator out for no gain.
     *
     * What the change invalidates is deliberately everything the platform already knows how to
     * invalidate, through one instrument rather than a new one. The repository raises the subject's
     * security epoch in the same statement sequence as the credential write, and that single number is
     * what the API-token verifier, the portal session store, the administrator session store and every
     * outstanding step-up proof each compare themselves against. The administrator sessions are then
     * also swept from the table, so nothing is left behind that could only fail later.
     *
     * @param   ExecutionContext  $context          Authenticated actor replacing their own credential.
     * @param   string            $currentPassword  Password being replaced, re-entered for this act.
     * @param   string            $newPassword      Replacement password; only its hash is stored.
     *
     * @return  int  How many of the actor's administrator sessions were ended by the change.
     *
     * @throws  InvalidArgumentException  When the replacement fails the password rule or repeats the
     *          current one.
     * @throws  \Kumwe\App\Application\Security\HighImpactAuthenticationRequired  When the context carries
     *          no human principal, or the current password does not match the actor's credential.
     * @throws  \Kumwe\App\Identity\Application\Administration\AuthenticationThrottled  When the actor has
     *          already spent the attempt budget for credential changes.
     *
     * @since   2.0.0
     */
    public function changeOwnPassword(
        ExecutionContext $context,
        #[\SensitiveParameter] string $currentPassword,
        #[\SensitiveParameter] string $newPassword,
    ): int {
        $this->credentials->assertCurrentPassword($context, 'identity.password.change', $currentPassword);
        $this->assertPasswordPolicy($newPassword);
        if (hash_equals($currentPassword, $newPassword)) {
            throw new InvalidArgumentException('The replacement password must differ from the current one.');
        }
        $actorId = $context->actorId();
        $hash = $this->passwords->hash($newPassword);
        $at = $this->clock->now();

        return $this->transactions->transactional(function () use ($context, $actorId, $hash, $at): int {
            $this->repository->lockUser($actorId);
            $this->repository->changePassword($actorId, $hash, $at);
            $ended = $this->sessions->deleteAllForUser($context, $actorId);
            $this->audit($actorId, 'user.password.change', 'user', $actorId, [
                'self_service' => true,
                'sessions_terminated' => $ended,
            ]);

            return $ended;
        });
    }

    /**
     * Replace another account's password as an administrator, on the record and with a reason.
     *
     * The deliberate asymmetry with `changeOwnPassword()`: no current password is demanded, because the
     * whole point is that the account holder cannot supply one. Everything that replaces that proof is
     * therefore about accountability rather than possession. `users.manage` is asserted against the
     * exact record, the reason is mandatory and stored on the audit event, and the event records an
     * actor who is not the subject — which is what makes a quiet takeover impossible to perform without
     * leaving the takeover in the trail an operator reads on the security-events screen.
     *
     * An actor may not reset their own password here. Allowing it would make this a way to replace a
     * credential without proving the current one, which is exactly the check `changeOwnPassword()`
     * exists to apply; the refusal keeps the two paths from collapsing into one.
     *
     * The invalidation is identical to the self-service path — one epoch advance retiring the subject's
     * tokens, portal sessions, administrator sessions and step-up proofs, plus the session sweep — so
     * whoever currently holds the account is put out of it by the reset rather than at their leisure.
     *
     * @param   ExecutionContext  $context      Actor and site the reset is authorized and audited against.
     * @param   string            $userId       UUID of the account whose password is replaced.
     * @param   string            $newPassword  Replacement password; only its hash is stored.
     * @param   string            $reason       Operator justification, 1 to 500 characters once trimmed.
     *
     * @return  int  How many of the subject's administrator sessions the reset ended.
     *
     * @throws  InvalidArgumentException  When the actor names their own account, the reason is empty or
     *          too long, the replacement fails the password rule, or the subject has no credential.
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor may not manage
     *          this user.
     *
     * @since   2.0.0
     */
    public function resetUserPassword(
        ExecutionContext $context,
        string $userId,
        #[\SensitiveParameter] string $newPassword,
        string $reason,
    ): int {
        $this->authorize($context, AuthorizationResource::item('user', $userId));
        if ($userId === $context->actorId()) {
            throw new InvalidArgumentException(
                'Replace your own password through the self-service change, which proves the current one.',
            );
        }
        $reason = $this->reason($reason);
        $this->assertPasswordPolicy($newPassword);
        $actorId = $context->actorId();
        $hash = $this->passwords->hash($newPassword);
        $at = $this->clock->now();

        return $this->transactions->transactional(function () use (
            $context,
            $actorId,
            $userId,
            $hash,
            $reason,
            $at,
        ): int {
            $this->repository->lockUser($userId);
            $this->repository->changePassword($userId, $hash, $at);
            $ended = $this->sessions->deleteAllForUser($context, $userId);
            $this->audit($actorId, 'user.password.reset', 'user', $userId, [
                'self_service' => false,
                'reason' => $reason,
                'sessions_terminated' => $ended,
            ]);

            return $ended;
        });
    }

    /**
     * Retire every second factor a subject holds, so a lost authenticator is recoverable.
     *
     * Without this a lost authenticator with spent recovery codes is terminal: every step-up-gated
     * mutation refuses the holder forever, and `beginEnrollment()` refuses to enroll a replacement
     * while an active credential exists, so the account cannot even be repaired by its owner. Retiring
     * the credential clears both at once — the refusal to re-enroll lifts because nothing is active any
     * more, and the retired row keeps the reason beside it.
     *
     * This is itself a high-impact act, so it carries the same accountability as an emergency
     * revocation: `users.manage` on the exact record, a mandatory reason, an audit event under the
     * `identity.step_up.` prefix the security-events screen already surfaces, and a security-epoch
     * advance that retires the subject's outstanding proofs, tokens and sessions along with the
     * credential. Callers on the administrator surface reach it only behind a payload-bound step-up
     * challenge of the actor's own, which is what keeps a stolen session from resetting somebody's
     * second factor.
     *
     * @param   ExecutionContext  $context  Actor and site the retirement is authorized and audited against.
     * @param   string            $userId   UUID of the subject whose second factors are retired.
     * @param   string            $reason   Operator justification, 1 to 500 characters once trimmed.
     *
     * @return  int  How many credentials were retired, zero when the subject had none enrolled.
     *
     * @throws  InvalidArgumentException  When the reason is empty or too long, or the subject does not
     *          exist and so could not be locked.
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor may not manage
     *          this user.
     *
     * @since   2.0.0
     */
    public function revokeStepUpCredentials(ExecutionContext $context, string $userId, string $reason): int
    {
        $this->authorize($context, AuthorizationResource::item('user', $userId));
        $reason = $this->reason($reason);
        $actorId = $context->actorId();
        $at = $this->clock->now();

        return $this->transactions->transactional(function () use (
            $context,
            $actorId,
            $userId,
            $reason,
            $at,
        ): int {
            $this->repository->lockUser($userId);
            $revoked = $this->stepUp->revokeForSubject($userId, $at, $reason);
            $this->repository->advanceSecurityEpoch($userId);
            $ended = $this->sessions->deleteAllForUser($context, $userId);
            $this->audit($actorId, 'identity.step_up.credential.revoke', 'user', $userId, [
                'revoked_credentials' => $revoked,
                'sessions_terminated' => $ended,
                'reason' => $reason,
                'self_service' => $actorId === $userId,
            ]);

            return $revoked;
        });
    }

    /**
     * End every session a subject holds and retire everything else issued to them.
     *
     * The operation the break-glass revocation was missing. Revoking tokens killed API credentials and,
     * through the epoch, portal sessions and step-up proofs, but a live administrator cookie kept
     * working until it expired; suspending the account was the only lever that reached it, and
     * suspension is a much larger act than signing somebody out. This raises the epoch and sweeps the
     * session table, which ends every browser the subject is signed in on without touching their
     * account's lifecycle state, their roles or their password.
     *
     * @param   ExecutionContext  $context  Actor and site the termination is authorized and audited against.
     * @param   string            $userId   UUID of the subject whose sessions are ended.
     * @param   string            $reason   Operator justification, 1 to 500 characters once trimmed.
     *
     * @return  int  How many administrator sessions were ended, zero when the subject held none.
     *
     * @throws  InvalidArgumentException  When the reason is empty or too long, or the subject does not
     *          exist and so could not be locked.
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor may not manage
     *          this user.
     *
     * @since   2.0.0
     */
    public function terminateUserSessions(ExecutionContext $context, string $userId, string $reason): int
    {
        $this->authorize($context, AuthorizationResource::item('user', $userId));
        $reason = $this->reason($reason);
        $actorId = $context->actorId();

        return $this->transactions->transactional(function () use ($context, $actorId, $userId, $reason): int {
            $this->repository->lockUser($userId);
            $this->repository->advanceSecurityEpoch($userId);
            $ended = $this->sessions->deleteAllForUser($context, $userId);
            $this->audit($actorId, 'user.sessions.terminate', 'user', $userId, [
                'sessions_terminated' => $ended,
                'reason' => $reason,
                'self_service' => $actorId === $userId,
            ]);

            return $ended;
        });
    }

    /**
     * Create a role for capability grants to hang from.
     *
     * The role starts empty and confers nothing until `grant()` attaches a capability to it, so creating
     * one delegates no authority. The code is lowercased and both arguments trimmed before validation.
     *
     * @param   ExecutionContext  $context  Actor, site and request identifiers the creation runs under.
     * @param   string            $code     Stable identifier policy refers to the role by; lowercased, 2 to
     *          64 characters of letters, digits, dot, dash or underscore.
     * @param   string            $name     Human-readable name, 1 to 191 characters once trimmed.
     *
     * @return  string  UUID of the created role.
     *
     * @throws  InvalidArgumentException  When the code is not a stable identifier or the name is unusable.
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor may not create roles.
     *
     * @since   2.0.0
     */
    public function createRole(ExecutionContext $context, string $code, string $name): string
    {
        $this->authorize($context, AuthorizationResource::collection('role'));
        $code = strtolower(trim($code));
        $name = trim($name);
        if (preg_match('/^[a-z][a-z0-9._-]{1,63}$/D', $code) !== 1) {
            throw new InvalidArgumentException('A role code must be a stable lowercase identifier.');
        }
        if ($name === '' || mb_strlen($name) > 191) {
            throw new InvalidArgumentException('A role name must contain 1 to 191 characters.');
        }

        $id = Uuid::uuid7()->toString();
        $at = $this->clock->now();
        return $this->transactions->transactional(function () use ($context, $id, $code, $name, $at): string {
            $this->repository->insertRole($id, $code, $name, $at);
            $this->ownership->record(AuthorizationResource::item('role', $id), SiteContext::default());
            $this->audit($context->actorId(), 'role.create', 'role', $id, ['code' => $code]);

            return $id;
        });
    }

    /**
     * Give a user a role, but only one the actor could have granted itself.
     *
     * Assignment is where authority spreads fastest, so the role is locked and every capability it
     * confers is re-checked for delegation inside the transaction. That closes the obvious hole: an
     * administrator cannot use a role to hand on more than they hold themselves.
     *
     * @param   ExecutionContext  $context  Actor, site and request identifiers the assignment runs under.
     * @param   string            $userId   UUID of the user gaining the role.
     * @param   string            $roleId   UUID of the role being assigned.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the role does not exist, so it could not be locked.
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor may not manage the
     *          user or the role, or may not delegate one of the role's grants.
     *
     * @since   2.0.0
     */
    public function assignRole(ExecutionContext $context, string $userId, string $roleId): void
    {
        $this->authorize($context, AuthorizationResource::item('user', $userId));
        $this->authorize($context, AuthorizationResource::item('role', $roleId));
        $actorId = $context->actorId();
        $at = $this->clock->now();
        $this->transactions->transactional(function () use ($context, $actorId, $userId, $roleId, $at): void {
            $this->repository->lockRole($roleId);
            $this->assertCanDelegateRole($context, $roleId);
            $this->repository->assignRole($userId, $roleId, $actorId, $at);
            $this->audit($actorId, 'role.assign', 'user', $userId, ['role_id' => $roleId]);
        });
    }

    /**
     * Take a role away from a user.
     *
     * An actor may not remove its own `administrator` role, which is what stops a single mistake leaving
     * an installation with nobody able to administer it.
     *
     * @param   ExecutionContext  $context  Actor, site and request identifiers the revocation runs under.
     * @param   string            $userId   UUID of the user losing the role.
     * @param   string            $roleId   UUID of the role being taken away.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the actor is removing its own administrator role, or the user
     *          did not hold the role at all.
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor may not manage the
     *          user or the role.
     *
     * @since   2.0.0
     */
    public function revokeRole(ExecutionContext $context, string $userId, string $roleId): void
    {
        $this->authorize($context, AuthorizationResource::item('user', $userId));
        $this->authorize($context, AuthorizationResource::item('role', $roleId));
        $actorId = $context->actorId();
        if ($actorId === $userId && $this->repository->roleCode($roleId) === 'administrator') {
            throw new InvalidArgumentException('You cannot remove your own administrator role.');
        }
        $this->transactions->transactional(function () use ($actorId, $userId, $roleId): void {
            $this->repository->revokeRole($userId, $roleId);
            $this->audit($actorId, 'role.revoke', 'user', $userId, ['role_id' => $roleId]);
        });
    }

    /**
     * Attach a capability to a role, globally or within one named scope.
     *
     * Delegation is asserted twice: once before the transaction, to refuse the clear case cheaply, and
     * once inside it with the role locked, so a concurrent change to the actor's own authority cannot be
     * raced. Scope pairing is exclusive — a global grant carries no identifier, and a scoped one demands
     * exactly one.
     *
     * @param   ExecutionContext  $context          Actor, site and request identifiers the grant runs under.
     * @param   string            $roleId           UUID of the role receiving the capability.
     * @param   string            $capability       Capability code being granted.
     * @param   string            $scopeType        Kind of scope it applies in; `global` unless stated.
     * @param   ?string           $scopeIdentifier  Which instance of that kind, or null for a global grant.
     *
     * @return  string  UUID of the created grant.
     *
     * @throws  InvalidArgumentException  When the capability, the scope type, or the pairing of scope type
     *          and identifier is invalid.
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor may not manage the
     *          role or may not delegate that capability at that scope.
     *
     * @since   2.0.0
     */
    public function grant(
        ExecutionContext $context,
        string $roleId,
        string $capability,
        string $scopeType = 'global',
        ?string $scopeIdentifier = null,
    ): string {
        $this->authorize($context, AuthorizationResource::item('role', $roleId));
        $actorId = $context->actorId();
        $capability = Capability::fromString($capability)->value();
        $scopeType = strtolower(trim($scopeType));
        if (preg_match('/^[a-z][a-z0-9._-]{0,62}$/D', $scopeType) !== 1) {
            throw new InvalidArgumentException('The grant scope type is invalid.');
        }
        $scopeIdentifier = $scopeIdentifier === null ? null : trim($scopeIdentifier);
        if (($scopeType === 'global') !== ($scopeIdentifier === null)) {
            throw new InvalidArgumentException(
                'Global grants cannot have a scope identifier; scoped grants require one.',
            );
        }
        $scope = $this->scope($scopeType, $scopeIdentifier);
        $this->authorization->assertCanDelegate($context, Capability::fromString($capability), $scope);

        $id = Uuid::uuid7()->toString();
        $at = $this->clock->now();
        return $this->transactions->transactional(function () use (
            $id,
            $roleId,
            $capability,
            $scopeType,
            $scopeIdentifier,
            $actorId,
            $at,
            $context,
            $scope,
        ): string {
            $this->repository->lockRole($roleId);
            $this->authorization->assertCanDelegate(
                $context,
                Capability::fromString($capability),
                $scope,
            );
            $this->repository->grant(
                $id,
                $roleId,
                $capability,
                $scopeType,
                $scopeIdentifier,
                $actorId,
                $at,
            );
            $this->ownership->record(AuthorizationResource::item('grant', $id), SiteContext::default());
            $this->audit($actorId, 'capability.grant', 'role', $roleId, [
                'capability' => $capability,
                'scope_type' => $scopeType,
                'scope_identifier' => $scopeIdentifier,
            ]);

            return $id;
        });
    }

    /**
     * Apply one role-scoped global-capability change set atomically.
     *
     * The submitted snapshot is compared only after the role is locked, so another administrator's
     * intervening grant edit fails closed. Every requested addition is checked against the live capability
     * vocabulary and the actor's delegation ceiling before the first write. Scoped grants are deliberately
     * preserved; the batch editor changes only the global checkboxes it displays.
     *
     * @param   ExecutionContext  $context           Actor and exact authority context for the change set.
     * @param   string            $roleId            UUID of the single role being edited.
     * @param   list<string>      $selected          Global capability codes selected after the edit.
     * @param   string            $expectedSnapshot  SHA-256 snapshot rendered with the form.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the snapshot is stale, a capability is unknown, or the
     *          submitted set is malformed or unreasonably large.
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor may not manage the
     *          role or one removed grant, or may not delegate one requested addition.
     *
     * @since   2.0.0
     */
    public function synchronizeGlobalRoleGrants(
        ExecutionContext $context,
        string $roleId,
        array $selected,
        string $expectedSnapshot,
    ): void {
        $this->authorize($context, AuthorizationResource::item('role', $roleId));
        if (preg_match('/^[a-f0-9]{64}$/D', $expectedSnapshot) !== 1) {
            throw new InvalidArgumentException('The role grant snapshot is invalid.');
        }
        if (count($selected) > 200) {
            throw new InvalidArgumentException('A role change set may contain at most 200 capabilities.');
        }
        $normalized = [];
        foreach ($selected as $candidate) {
            $capability = Capability::fromString($candidate)->value();
            $normalized[$capability] = true;
        }
        ksort($normalized, SORT_STRING);

        $known = [];
        $offset = 0;
        do {
            $page = $this->repository->capabilities(500, $offset);
            foreach ($page as $capability) {
                $known[$capability['code']] = true;
            }
            $offset += count($page);
        } while (count($page) === 500);
        foreach (array_keys($normalized) as $capability) {
            if (!isset($known[$capability])) {
                throw new InvalidArgumentException('The role change set contains an unknown capability.');
            }
        }

        $this->transactions->transactional(function () use (
            $context,
            $roleId,
            $normalized,
            $expectedSnapshot,
        ): void {
            $this->repository->lockRole($roleId);
            $this->authorize($context, AuthorizationResource::item('role', $roleId));
            $current = $this->repository->roleGrantRecords($roleId);
            if (!hash_equals($expectedSnapshot, $this->grantSnapshot($current))) {
                throw new InvalidArgumentException(
                    'The role capabilities changed; reload the role before applying this change set.',
                );
            }

            $kept = [];
            $remove = [];
            foreach ($current as $grant) {
                if ($grant['scope_type'] !== 'global') {
                    continue;
                }
                $capability = $grant['capability'];
                if (isset($normalized[$capability]) && !isset($kept[$capability])) {
                    $kept[$capability] = true;
                    continue;
                }
                $this->authorize($context, AuthorizationResource::item('grant', $grant['id']));
                $remove[] = $grant;
            }
            $add = array_values(array_diff(array_keys($normalized), array_keys($kept)));
            foreach ($add as $capability) {
                $this->authorization->assertCanDelegate(
                    $context,
                    Capability::fromString($capability),
                    GrantScope::global(),
                );
            }

            $actorId = $context->actorId();
            $at = $this->clock->now();
            foreach ($remove as $grant) {
                $this->repository->revokeGrant($grant['id']);
                $this->ownership->remove(
                    AuthorizationResource::item('grant', $grant['id']),
                    SiteContext::default(),
                );
                $this->audit($actorId, 'capability.revoke', 'grant', $grant['id'], [
                    'change_set_role_id' => $roleId,
                ]);
            }
            foreach ($add as $capability) {
                $grantId = Uuid::uuid7()->toString();
                $this->repository->grant(
                    $grantId,
                    $roleId,
                    $capability,
                    'global',
                    null,
                    $actorId,
                    $at,
                );
                $this->ownership->record(AuthorizationResource::item('grant', $grantId), SiteContext::default());
                $this->audit($actorId, 'capability.grant', 'role', $roleId, [
                    'capability' => $capability,
                    'scope_type' => 'global',
                    'scope_identifier' => null,
                    'change_set_role_id' => $roleId,
                ]);
            }
            $this->audit($actorId, 'capability.change_set', 'role', $roleId, [
                'added' => count($add),
                'removed' => count($remove),
                'snapshot_before' => $expectedSnapshot,
                'snapshot_after' => $this->grantSnapshot($this->repository->roleGrantRecords($roleId)),
            ]);
        });
    }

    /**
     * Remove a capability grant, authorized against both the grant and the role behind it.
     *
     * The role is read from the stored grant rather than taken from the caller, so naming a grant is not
     * a way to edit a role the actor does not manage. The role is locked before deletion so a concurrent
     * direct or membership assignment cannot appear between the repository's member snapshot and its
     * security-epoch invalidation.
     *
     * @param   ExecutionContext  $context  Actor, site and request identifiers the revocation runs under.
     * @param   string            $grantId  UUID of the grant to remove.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When no grant carries that identifier.
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor may not manage the
     *          grant or the role that holds it.
     *
     * @since   2.0.0
     */
    public function revokeGrant(ExecutionContext $context, string $grantId): void
    {
        $this->authorize($context, AuthorizationResource::item('grant', $grantId));
        $grant = $this->repository->grantRecord($grantId)
            ?? throw new InvalidArgumentException('The capability grant does not exist.');
        $this->authorize($context, AuthorizationResource::item('role', $grant['role_id']));
        $actorId = $context->actorId();
        $this->transactions->transactional(function () use ($actorId, $grantId, $grant): void {
            $this->repository->lockRole($grant['role_id']);
            $this->repository->revokeGrant($grantId);
            $this->ownership->remove(
                AuthorizationResource::item('grant', $grantId),
                SiteContext::default(),
            );
            $this->audit($actorId, 'capability.revoke', 'grant', $grantId);
        });
    }

    /**
     * Revoke one API token belonging to the context's site.
     *
     * The token's stored site must equal the context's, so a credential can never be killed from a site
     * that does not own it.
     *
     * @param   ExecutionContext  $context  Actor and site the revocation runs under.
     * @param   string            $tokenId  UUID of the token to revoke.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the token belongs to another site, is unknown, or has already
     *          been revoked.
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor may not manage the
     *          token.
     *
     * @since   2.0.0
     */
    public function revokeToken(ExecutionContext $context, string $tokenId): void
    {
        $this->authorize($context, AuthorizationResource::item('api_token', $tokenId));
        if ($this->repository->tokenSite($tokenId) !== $context->site()->identifier()) {
            throw new InvalidArgumentException('A token cannot be revoked outside its site context.');
        }
        $actorId = $context->actorId();
        $at = $this->clock->now();
        $this->transactions->transactional(function () use ($actorId, $tokenId, $at): void {
            $this->repository->revokeToken($tokenId, $at);
            $this->audit($actorId, 'token.revoke', 'api_token', $tokenId);
        });
    }

    /**
     * Revoke every token a subject holds for the context's site.
     *
     * The contained revocation: whatever the subject holds in other sites keeps working. The reason is
     * mandatory because it is stored on each revoked token as well as in the audit entry, which is what
     * makes an incident reconstructable afterwards.
     *
     * @param   ExecutionContext  $context  Actor and site; only that site's tokens are revoked.
     * @param   string            $userId   UUID of the subject whose tokens are revoked.
     * @param   string            $reason   Operator justification, 1 to 500 characters once trimmed.
     *
     * @return  int  How many live tokens were revoked, zero when the subject held none for the site.
     *
     * @throws  InvalidArgumentException  When the reason is empty or too long, or the subject does not exist
     *          and so could not be locked.
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor may not manage the
     *          site's tokens.
     *
     * @since   2.0.0
     */
    public function revokeSubjectTokens(ExecutionContext $context, string $userId, string $reason): int
    {
        $this->authorize($context, AuthorizationResource::item('site', $context->site()->identifier()));
        $actorId = $context->actorId();
        $reason = $this->reason($reason);
        $at = $this->clock->now();

        return $this->transactions->transactional(function () use (
            $context,
            $actorId,
            $userId,
            $reason,
            $at,
        ): int {
            $this->repository->lockUser($userId);
            $count = $this->repository->revokeSubjectTokensForSite(
                $userId,
                $context->site()->identifier(),
                $at,
                $reason,
            );
            $this->audit($actorId, 'token.revoke_subject_site', 'user', $userId, [
                'revoked_tokens' => $count,
                'reason' => $reason,
                'site_identifier' => $context->site()->identifier(),
            ]);

            return $count;
        });
    }

    /**
     * Revoke every token a subject holds, in every site, as a break-glass measure.
     *
     * The unbounded counterpart of `revokeSubjectTokens()`, so it is authorized against the user rather
     * than against one site. The store also advances the subject's security epoch, which means even a
     * credential the sweep somehow missed stops verifying.
     *
     * @param   ExecutionContext  $context  Actor, site and request identifiers the revocation runs under.
     * @param   string            $userId   UUID of the subject whose tokens are all destroyed.
     * @param   string            $reason   Operator justification, 1 to 500 characters once trimmed.
     *
     * @return  int  How many live tokens were revoked, zero when the subject held none.
     *
     * @throws  InvalidArgumentException  When the reason is empty or too long, or the subject does not exist
     *          and so could not be locked.
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor may not manage this
     *          user.
     *
     * @since   2.0.0
     */
    public function emergencyRevokeAllSubjectTokens(
        ExecutionContext $context,
        string $userId,
        string $reason,
    ): int {
        $this->authorize($context, AuthorizationResource::item('user', $userId));
        $reason = $this->reason($reason);
        $at = $this->clock->now();
        return $this->transactions->transactional(function () use ($context, $userId, $reason, $at): int {
            $this->repository->lockUser($userId);
            $count = $this->repository->revokeSubjectTokens($userId, $at, $reason);
            $ended = $this->sessions->deleteAllForUser($context, $userId);
            $this->audit($context->actorId(), 'token.emergency_revoke_all', 'user', $userId, [
                'revoked_tokens' => $count,
                'sessions_terminated' => $ended,
                'reason' => $reason,
            ]);
            return $count;
        });
    }

    /**
     * Trim a submitted display name and refuse one that could not be shown.
     *
     * @param   string  $name  Display name exactly as submitted.
     *
     * @return  string  The trimmed name.
     *
     * @throws  InvalidArgumentException  When the name is empty or longer than 191 characters.
     *
     * @since   2.0.0
     */
    private function displayName(string $name): string
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 191) {
            throw new InvalidArgumentException('A user display name must contain 1 to 191 characters.');
        }

        return $name;
    }

    /**
     * Trim an operator's justification and refuse one that explains nothing or runs away.
     *
     * Every credential-retiring act here demands one, because the count each returns is meaningless to
     * an auditor without the sentence that says why it was run.
     *
     * @param   string  $reason  Justification exactly as submitted.
     *
     * @return  string  The trimmed justification.
     *
     * @throws  InvalidArgumentException  When the reason is empty or longer than 500 characters.
     *
     * @since   2.0.0
     */
    private function reason(string $reason): string
    {
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 500) {
            throw new InvalidArgumentException('An emergency revocation reason of 1 to 500 characters is required.');
        }

        return $reason;
    }

    /**
     * Apply the installation's rule for a password that replaces an existing one.
     *
     * Twelve characters is the floor the administrator's own form has always declared, restated here so
     * the console and any other caller cannot undercut it. The upper bound belongs to `PasswordHasher`,
     * which refuses rather than truncates, so this only has to stop the short end. Deliberately no
     * composition rule: a length floor with no character-class demand is what current guidance asks
     * for, and a rule that pushes people toward predictable substitutions buys nothing.
     *
     * The rule is applied to replacements only. Imposing it on `createUser()` retroactively would
     * change the meaning of an existing call for every caller in the installation, which is a
     * separate decision from giving credentials a rotation path.
     *
     * @param   string  $password  Replacement password exactly as submitted.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the password is shorter than twelve characters.
     *
     * @since   2.0.0
     */
    private function assertPasswordPolicy(#[\SensitiveParameter] string $password): void
    {
        if (mb_strlen($password) < 12) {
            throw new InvalidArgumentException('A replacement password must contain at least 12 characters.');
        }
    }

    /**
     * Applies the User aggregate's lifecycle rule to a status change made through this service.
     *
     * Administrator forms and the management API submit a target status rather than a named
     * transition, so the invariant is asserted here against the locked stored status.
     *
     * @param   string      $id      UUID of the user whose stored status the target is judged against.
     * @param   UserStatus  $target  Status the caller is asking the user to move to.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the user is gone, its stored status is not one this version
     *          knows, or the move is illegal from it.
     *
     * @since   2.0.0
     */
    private function assertLifecycleTransition(string $id, UserStatus $target): void
    {
        $stored = $this->repository->userStatus($id);
        if ($stored === null) {
            throw new InvalidArgumentException('The user does not exist.');
        }
        $current = UserStatus::tryFrom($stored)
            ?? throw new InvalidArgumentException('The stored user lifecycle status is invalid.');
        if (!$current->canTransitionTo($target)) {
            throw new InvalidArgumentException(sprintf(
                'A %s user cannot become %s.',
                $current->value,
                $target->value,
            ));
        }
    }

    /**
     * Demand `users.manage` on one resource, the single gate every entry point here passes through.
     *
     * Every read and write in this service demands that one capability; only the resource it is demanded
     * on differs, which is what keeps the gate identical across all four delivery surfaces.
     *
     * @param   ExecutionContext       $context   Actor, site and request identifiers the check runs under.
     * @param   AuthorizationResource  $resource  Collection or item the capability is demanded on.
     *
     * @return  void
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor does not hold
     *          `users.manage` for that resource.
     *
     * @since   2.0.0
     */
    private function authorize(ExecutionContext $context, AuthorizationResource $resource): void
    {
        $this->authorization->assertAllowed(
            $context,
            Capability::fromString('users.manage'),
            $resource,
        );
    }

    /**
     * Walk a paged reader and keep only the rows the actor may manage.
     *
     * Filtering after reading is what lets a partially scoped administrator list records instead of
     * being refused. Rows arrive a page at a time and the walk ends as soon as the caller's limit is
     * filled or a short page shows the store is exhausted, so the answer stays bounded however few rows
     * turn out to be visible. A row whose identifier field is missing or is not a string is skipped.
     *
     * @param   ExecutionContext                                $context          Actor the rows are judged
     *          against.
     * @param   string                                          $resourceType     Type each row is
     *          authorized as.
     * @param   callable(int, int): list<array<string, mixed>>  $page             Reader taking a page size
     *          and an offset.
     * @param   string                                          $identifierField  Row key holding the
     *          record's identifier.
     * @param   int                                             $limit            Most rows to return once
     *          filtering is done.
     *
     * @return  list<array<string, mixed>>  The visible rows, in the reader's own order.
     *
     * @since   2.0.0
     */
    private function filterPaged(
        ExecutionContext $context,
        string $resourceType,
        callable $page,
        string $identifierField = 'id',
        int $limit = 100,
    ): array {
        $result = [];
        $offset = 0;
        $pageSize = 100;
        do {
            /** @var list<array<string, mixed>> $rows */
            $rows = $page($pageSize, $offset);
            foreach ($rows as $row) {
                $identifier = $row[$identifierField] ?? null;
                if (
                    is_string($identifier) && $this->authorization->decide(
                        $context,
                        Capability::fromString('users.manage'),
                        AuthorizationResource::item($resourceType, $identifier),
                    )->allowed
                ) {
                    $result[] = $row;
                    if (count($result) === $limit) {
                        return $result;
                    }
                }
            }
            $offset += count($rows);
        } while (count($rows) === $pageSize);

        return $result;
    }

    /**
     * Turn a stored scope pair into the value object the delegation check speaks in.
     *
     * @param   string   $type        Scope kind as stored, where `global` is the unscoped case.
     * @param   ?string  $identifier  Instance of that kind; null is passed on as an empty identifier.
     *
     * @return  GrantScope  The global scope for `global`, otherwise a named scope of that kind.
     *
     * @since   2.0.0
     */
    private function scope(string $type, ?string $identifier): GrantScope
    {
        return $type === 'global'
            ? GrantScope::global()
            : GrantScope::named($type, $identifier ?? '');
    }

    /**
     * Fingerprint an exact role grant inventory independently of row order.
     *
     * @param   list<array<string, mixed>>  $grants  Grant rows rendered or read under the role lock.
     *
     * @return  string  Canonical SHA-256 resource-version snapshot.
     *
     * @throws  RuntimeException  When a grant row does not contain the required typed fields.
     *
     * @since   2.0.0
     */
    private function grantSnapshot(array $grants): string
    {
        $snapshot = [];
        foreach ($grants as $grant) {
            $id = $grant['id'] ?? null;
            $capability = $grant['capability'] ?? null;
            $scopeType = $grant['scope_type'] ?? null;
            $scopeIdentifier = $grant['scope_identifier'] ?? null;
            if (
                !is_string($id)
                || !is_string($capability)
                || !is_string($scopeType)
                || ($scopeIdentifier !== null && !is_string($scopeIdentifier))
            ) {
                throw new RuntimeException('A role grant snapshot is invalid.');
            }
            $snapshot[] = [$id, $capability, $scopeType, $scopeIdentifier];
        }
        usort($snapshot, static fn (array $left, array $right): int => $left <=> $right);

        return CanonicalJson::digest($snapshot);
    }

    /**
     * Require that the actor could have written every grant the role already carries.
     *
     * Holding `users.manage` is not enough to hand someone a role: each of its grants is measured
     * against the actor's own ceiling, at the scope the grant was written in.
     *
     * @param   ExecutionContext  $context  Actor whose delegation ceiling the grants are measured against.
     * @param   string            $roleId   UUID of the role being handed on.
     *
     * @return  void
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When one of the role's grants
     *          exceeds what the actor may delegate.
     *
     * @since   2.0.0
     */
    private function assertCanDelegateRole(ExecutionContext $context, string $roleId): void
    {
        foreach ($this->repository->roleGrants($roleId) as $grant) {
            $this->authorization->assertCanDelegate(
                $context,
                Capability::fromString($grant['capability']),
                $this->scope($grant['scope_type'], $grant['scope_identifier']),
            );
        }
    }

    /**
     * Record one successful access-control change in the audit trail.
     *
     * Every call sits inside the mutation's transaction, so the trail commits with the change it
     * describes and a rolled-back attempt leaves no misleading entry.
     *
     * @param   string                $actorId   UUID of the administrator that made the change.
     * @param   string                $action    Dotted action name, such as `role.assign`.
     * @param   string                $type      Resource type the change landed on.
     * @param   string                $id        Identifier of the changed resource.
     * @param   array<string, mixed>  $metadata  Detail worth keeping beside the event, such as the status
     *          written or the number of tokens revoked.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function audit(string $actorId, string $action, string $type, string $id, array $metadata = []): void
    {
        $this->audit->record(new AuditEvent(
            Uuid::uuid7()->toString(),
            $this->clock->now(),
            $actorId,
            $action,
            $type,
            $id,
            'success',
            $metadata,
        ));
    }
}
