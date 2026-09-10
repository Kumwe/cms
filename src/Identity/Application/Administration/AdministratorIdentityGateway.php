<?php

declare(strict_types=1);

namespace Kumwe\App\Identity\Application\Administration;

use Kumwe\App\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\Context\Value\ExecutionContext;

/**
 * The operations that produce a credential rather than consume one: sign in, bootstrap, mint, rotate.
 *
 * Everything else about users and roles goes through `AccessControlService`; what is gathered here is
 * the work whose output is itself a credential. Sign-in turns an email and password into the principal
 * the administrator session is built on, bootstrap creates a fully granted administrator on the
 * authority of the host rather than of a logged-in actor, and issuance and rotation mint the bearer
 * secrets the API and MCP surfaces authenticate with. An implementation owes the secrecy guarantees
 * these imply: a plaintext token is returned exactly once and is never re-readable, password
 * verification is throttled, and every capability a token carries is one the subject already holds and
 * the actor is entitled to delegate.
 *
 * @since  2.0.0
 */
interface AdministratorIdentityGateway
{
    /**
     * Verify an email and password pair and describe who they belong to.
     *
     * A wrong password and an unknown, suspended or disabled account are all reported the same way, so
     * a caller cannot use the answer to enumerate accounts. The attempt is counted against the rate
     * limiter whichever way it goes.
     *
     * @param   string  $email     Address the sign-in was attempted with, in any casing.
     * @param   string  $password  Plaintext password as submitted, verified against the stored hash.
     * @param   string  $source    Origin the attempt came from, used only to key the throttle; pass
     *          `unknown` when the pipeline could not establish one.
     *
     * @return  ?AuthenticatedPrincipal  The actor with their grants and security epoch, or null when the
     *          credential does not identify an account that may sign in.
     *
     * @throws  AuthenticationThrottled  When too many attempts have already failed for this pair.
     * @throws  \InvalidArgumentException  When the address is not syntactically an address at all, which an
     *          implementation refuses before it counts or looks anything up.
     *
     * @since   2.0.0
     */
    public function authenticate(string $email, string $password, string $source): ?AuthenticatedPrincipal;

    /**
     * Provisions a distinct administrator through the trusted host bootstrap identity.
     *
     * The method name is retained for compatibility, but implementations must support provisioning
     * additional administrators after the first account exists.
     *
     * @param   ExecutionContext  $context      Bootstrap authority, which must carry
     *          `administrator.bootstrap` rather than an ordinary administrator's grants.
     * @param   string            $email        Address the new administrator will sign in with; it must
     *          not already belong to a user.
     * @param   string            $displayName  Human-readable name shown wherever the account is listed.
     * @param   string            $password     Plaintext password to store as a hash for the account.
     *
     * @return  string  UUID of the created user, already holding the full administrator role.
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the context does not carry
     *          the bootstrap authority.
     * @throws  \InvalidArgumentException  When the display name is unusable or the email is already taken.
     *
     * @since   2.0.0
     */
    public function createInitialAdministrator(
        ExecutionContext $context,
        string $email,
        string $displayName,
        string $password,
    ): string;

    /**
     * Mint a bearer token that acts for a subject with a fixed set of capabilities.
     *
     * The plaintext token comes back once and is never recoverable afterwards, so a caller that loses
     * it must issue another. Two rules bound what may be minted: every requested capability must be one
     * the subject already holds, and the actor must be entitled to delegate it, which is re-checked
     * inside the write transaction so a concurrent revocation cannot slip a token through.
     *
     * @param   ExecutionContext     $context       Actor and site the token is issued under and confined to.
     * @param   string               $email         Address of the subject the token will act as.
     * @param   string               $name          Operator-facing label the token is listed under.
     * @param   list<string>         $capabilities  Capabilities the token may exercise; at least one is
     *          required, and each is checked against the subject's own grants.
     * @param   ?\DateTimeImmutable  $expiresAt     Expiry to set, or null for the default lifetime; it must
     *          lie in the future and within the maximum the implementation allows.
     * @param   string               $audience      Consumer the token is accepted by, such as `kumwe-http`.
     * @param   string               $purpose       Why the token exists, such as `api`; it partitions the
     *          per-subject quota alongside the audience.
     * @param   ?string              $rotatedFrom   UUID of the token being replaced, so a rotation is not
     *          charged twice against the quota, or null for a fresh issue.
     *
     * @return  array{token: string, token_id: string}  The plaintext secret, shown only here, under
     *          `token`, and the stored record's UUID under `token_id`.
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor may not act for
     *          the subject or may not delegate one of the capabilities.
     * @throws  \InvalidArgumentException  When the name, expiry, capability set or quota forbids the token.
     *
     * @since   2.0.0
     */
    public function issueAccessToken(
        ExecutionContext $context,
        string $email,
        string $name,
        array $capabilities,
        ?\DateTimeImmutable $expiresAt = null,
        string $audience = 'kumwe-http',
        string $purpose = 'api',
        ?string $rotatedFrom = null,
    ): array;

    /**
     * Replace a live token with a fresh secret carrying the same authority.
     *
     * Subject, capabilities, audience and purpose are copied from the token being replaced rather than
     * supplied again, so a rotation can never quietly widen what the credential may do. The old token is
     * revoked in the same transaction as the new one is written, which means a caller that loses the
     * response has lost both and must issue a token afresh.
     *
     * @param   ExecutionContext     $context    Actor and site the rotation runs under; the token must
     *          belong to that site.
     * @param   string               $tokenId    UUID of the live token being replaced.
     * @param   string               $name       Operator-facing label for the replacement.
     * @param   ?\DateTimeImmutable  $expiresAt  Expiry for the replacement, or null for the default
     *          lifetime; the old token's remaining life is not carried over.
     *
     * @return  array{token: string, token_id: string}  The replacement's plaintext secret under `token`
     *          and its new UUID under `token_id`.
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor may not manage the
     *          token or may not delegate the capabilities it carries.
     * @throws  \InvalidArgumentException  When the token is absent, already dead, outside the site, or the
     *          replacement's name or expiry is unusable.
     *
     * @since   2.0.0
     */
    public function rotateAccessToken(
        ExecutionContext $context,
        string $tokenId,
        string $name,
        ?\DateTimeImmutable $expiresAt,
    ): array;
}
