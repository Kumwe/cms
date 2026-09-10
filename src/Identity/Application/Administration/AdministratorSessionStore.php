<?php

declare(strict_types=1);

namespace Kumwe\App\Identity\Application\Administration;

use Kumwe\Context\Value\ExecutionContext;

/**
 * Store that mints, resolves, and ends the browser sessions behind the administrator.
 *
 * The administrator has no other way in: the login handler asks for a session after a password check,
 * the session middleware resolves the cookie on every subsequent request, the logout handler ends it,
 * and a scheduled job clears what has expired. Keeping all four in one port is what lets the session
 * secret stay out of the rest of the application — callers only ever hold the opaque token returned
 * once at creation, and an implementation is expected to persist a digest of it rather than the token
 * itself, to bind the session to the browser that created it, and to expire it on its own clock.
 *
 * @since  2.0.0
 */
interface AdministratorSessionStore
{
    /**
     * Open a session for an actor who has just proved their password.
     *
     * The returned token is the only copy the caller will ever see; it is handed to the browser as a
     * cookie and cannot be recovered from the store afterwards.
     *
     * @param   ExecutionContext  $context    Actor, site and request identifiers the sign-in happened under.
     * @param   string            $userAgent  Client `User-Agent` header the session is bound to.
     *
     * @return  CreatedAdministratorSession  The session paired with the one-time cookie token.
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor may not hold an
     *          administrator session.
     * @throws  \InvalidArgumentException  When the context carries no human principal to sign in as.
     *
     * @since   2.0.0
     */
    public function create(ExecutionContext $context, string $userAgent): CreatedAdministratorSession;

    /**
     * Resolve a cookie token back into the session it names.
     *
     * This runs before authorization on every administrator request, so it answers with null for every
     * failure it can meet — unknown, malformed, expired, or presented by a different client than the
     * one it was issued to — rather than distinguishing them for a caller who is not yet trusted.
     *
     * @param   string  $token      Opaque token the browser presented in the administrator cookie.
     * @param   string  $userAgent  Client `User-Agent` header the presented session must still match.
     *
     * @return  ?AdministratorSession  Null whenever the token does not currently resolve to a live session.
     *
     * @since   2.0.0
     */
    public function find(string $token, string $userAgent): ?AdministratorSession;

    /**
     * Rotate a live session onto one exact organization/workspace membership selection.
     *
     * The supplied identifiers only select among the actor's current memberships. Implementations
     * resolve them from storage, rotate both session and CSRF credentials, and return the new token once;
     * an absent, stale or foreign selection fails without changing the existing session.
     *
     * @param   ExecutionContext  $context                 Current administrator context and session identity.
     * @param   string            $organizationIdentifier  Organization the actor asks to enter.
     * @param   ?string           $workspaceIdentifier     Optional workspace inside that organization.
     * @param   string            $userAgent               Client binding for the replacement session.
     *
     * @return  CreatedAdministratorSession  Replacement session and one-time opaque cookie token.
     *
     * @throws  \InvalidArgumentException  When the context has no current session or the selection is not live.
     *
     * @since   2.0.0
     */
    public function selectMembership(
        ExecutionContext $context,
        string $organizationIdentifier,
        ?string $workspaceIdentifier,
        string $userAgent,
    ): CreatedAdministratorSession;

    /**
     * End one session immediately, as a sign-out does.
     *
     * @param   ExecutionContext  $context    Actor, site and request identifiers the sign-out runs under.
     * @param   string            $sessionId  UUID of the session to end.
     *
     * @return  void
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor may not end this
     *          session.
     * @throws  \InvalidArgumentException  When no session carries that identifier, so nothing was ended.
     *
     * @since   2.0.0
     */
    public function delete(ExecutionContext $context, string $sessionId): void;

    /**
     * End every administrator session one user holds, everywhere, at once.
     *
     * The counterpart of `delete()` for the case where the subject rather than the session is the
     * problem. A break-glass revocation, a password change and a second-factor reset all need the
     * person signed out of every browser they are signed in on, and none of them knows which sessions
     * those are. Implementations authorize by who is being signed out: `users.manage` against the exact
     * user when the actor is an identity administrator acting on somebody else's account, and the
     * ordinary `administrator.access` when the actor is ending their own sessions, which is the
     * sign-out-everywhere a self-service password change performs on itself. Sessions in every site are
     * ended, deliberately: the epoch this normally accompanies is installation-wide too.
     *
     * @param   ExecutionContext  $context  Actor, site and request identifiers the termination runs under.
     * @param   string            $userId   UUID of the user whose sessions are all ended.
     *
     * @return  int  How many live sessions were ended, zero when the user held none.
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor may not manage
     *          this user, or may not hold an administrator session at all.
     *
     * @since   2.0.0
     */
    public function deleteAllForUser(ExecutionContext $context, string $userId): int;

    /**
     * Delete every session of the context's site whose expiry has passed.
     *
     * Expired sessions already fail to resolve, so this is housekeeping rather than a security control;
     * the automation job calls it on a schedule to stop the table growing without bound.
     *
     * @param   ExecutionContext  $context  Actor, site and request identifiers the purge runs under.
     *
     * @return  int  How many expired sessions were removed, zero when there was nothing to clear.
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor may not run
     *          administrator housekeeping.
     *
     * @since   2.0.0
     */
    public function purgeExpired(ExecutionContext $context): int;
}
