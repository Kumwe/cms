<?php

declare(strict_types=1);

namespace Kumwe\App\Infrastructure\Security;

use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use Kumwe\App\Application\Security\HighImpactAuthenticationRequired;
use Kumwe\App\Application\Security\HighImpactCredentialGuard;
use Kumwe\App\Identity\Application\Administration\AuthenticationRateLimiter;
use Kumwe\App\Identity\Application\Security\PasswordHasher;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\Context\Value\ExecutionContext;

/**
 * Re-proves the acting operator's password against the credential table before a high-impact operation.
 *
 * The `HighImpactCredentialGuard` the container wires for the business-schema operations that stay
 * dangerous however the session was established — approving a schema plan, planning a purge, filing
 * recovery evidence. It reads the stored hash straight from the password credential table joined to an
 * active user row, so a suspended or deleted account cannot re-authenticate while its session is still
 * alive, and it puts every attempt through the same rate limiter that guards administrator sign-in,
 * keyed to the actor and the operation. A missing credential row and a wrong password fail identically,
 * so the guard never reveals whether an account has a password set at all. A context with no human
 * principal is refused before the lookup: a machine identity has no password to re-enter, which is
 * precisely the escalation this check exists to stop.
 *
 * @since  2.0.0
 */
final readonly class DoctrineHighImpactCredentialGuard implements HighImpactCredentialGuard
{
    /**
     * Bind the guard to the credential store, hasher, and throttle it verifies through.
     *
     * @param  Connection                 $database     DBAL connection the password credential row is
     *         read from.
     * @param  TableNames                 $tables       Resolver applying the configured prefix to the
     *         credential and user tables.
     * @param  PasswordHasher             $passwords    Hasher performing the constant-time comparison.
     * @param  AuthenticationRateLimiter  $rateLimiter  Throttle counting re-authentication attempts per
     *         actor and purpose.
     *
     * @since  2.0.0
     */
    public function __construct(
        private Connection $database,
        private TableNames $tables,
        private PasswordHasher $passwords,
        private AuthenticationRateLimiter $rateLimiter,
    ) {
    }

    /**
     * Asserts that the acting operator's current password has just been re-entered for this operation.
     *
     * Every attempt is reported to the throttle, successful or not, so a run of wrong passwords locks
     * the actor out of further tries. Both throttle keys are digests that fold the purpose in, which
     * keeps one operation's failures from spending another's budget and keeps the actor's identifier
     * out of the throttle store.
     *
     * @param   ExecutionContext  $context     Actor being re-authenticated; must carry a human
     *          principal.
     * @param   string            $purpose     Operation demanding the proof, as a 3 to 64 character
     *          lowercase identifier starting with a letter; scopes the throttle counters.
     * @param   ?string           $credential  The actor's current password, re-entered for this
     *          operation, or null when none was supplied.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the purpose is not a lowercase identifier of the accepted
     *          shape and length.
     * @throws  HighImpactAuthenticationRequired  When the context carries no human principal, or the
     *          supplied password does not match the actor's active credential.
     * @throws  \Kumwe\App\Identity\Application\Administration\AuthenticationThrottled  When the actor
     *          has already spent the attempt budget for this purpose.
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects the credential lookup.
     *
     * @since   2.0.0
     */
    public function assertCurrentPassword(
        ExecutionContext $context,
        string $purpose,
        #[\SensitiveParameter] ?string $credential,
    ): void {
        if (preg_match('/^[a-z][a-z0-9._-]{2,63}$/D', $purpose) !== 1) {
            throw new InvalidArgumentException('A high-impact authentication purpose is invalid.');
        }
        if ($context->principal() === null) {
            throw new HighImpactAuthenticationRequired(
                'This high-impact operation requires a human authentication context.',
            );
        }

        $actorId = $context->actorId();
        $subject = hash('sha256', 'high-impact:' . $purpose . ':' . $actorId);
        $source = hash('sha256', 'high-impact-current-password:' . $purpose);
        $this->rateLimiter->assertAllowed($subject, $source);
        $hash = $this->database->fetchOne(sprintf(
            'SELECT p.password_hash FROM %s p INNER JOIN %s u ON u.id = p.user_id '
            . "WHERE p.user_id = ? AND u.status = 'active'",
            $this->tables->quoted('password_credentials'),
            $this->tables->quoted('users'),
        ), [$actorId]);

        $verified = is_string($hash)
            && $credential !== null
            && $this->passwords->verify($credential, $hash);
        $this->rateLimiter->record($subject, $source, $verified);
        if (!$verified) {
            throw new HighImpactAuthenticationRequired(
                'This high-impact operation requires current-password authentication.',
            );
        }
    }
}
