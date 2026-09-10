<?php

declare(strict_types=1);

namespace Kumwe\App\Presentation\Infrastructure;

use Doctrine\DBAL\Connection;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\App\Identity\Application\Administration\AuthenticationRateLimiter;
use Kumwe\App\Identity\Application\Security\PasswordHasher;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Presentation\Application\StepUpAuthenticationRequired;
use Kumwe\App\Presentation\Application\ThemeActivationGuard;
use Kumwe\App\Extension\Domain\ThemeSurface;

/**
 * Demands re-entry of the actor's current password before an administrator theme is activated.
 *
 * Capability checks alone are not enough for the administrator surface: a stolen session or a leaked
 * token would be sufficient to replace the theme that renders the whole back office. This guard adds the
 * step-up factor. It reads the actor's stored password hash directly from the credential table, joined
 * to an active user row, so a disabled or deleted account cannot step up even while its session lives,
 * and it puts every attempt through the same rate limiter that guards administrator sign-in, keyed to
 * the actor. Site theme activation is deliberately unguarded and returns immediately.
 *
 * @since  2.0.0
 */
final readonly class DoctrineThemeActivationGuard implements ThemeActivationGuard
{
    /**
     * Bind the guard to the credential store, hasher, and throttle it verifies through.
     *
     * @param  Connection                 $database     DBAL connection the password credential row is
     *         read from.
     * @param  TableNames                 $tables       Resolver applying the configured prefix to the
     *         credential and user tables.
     * @param  PasswordHasher             $passwords    Hasher performing the constant-time verification.
     * @param  AuthenticationRateLimiter  $rateLimiter  Throttle counting step-up attempts per actor.
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
     * Asserts that this caller may activate a theme on the given surface, stepping up when it matters.
     *
     * Every administrator attempt is recorded against the throttle, successful or not, so repeated wrong
     * passwords lock the actor out of further attempts. A missing credential row and a wrong password are
     * reported identically, which keeps the guard from confirming whether an account has a password set.
     *
     * @param   ThemeSurface      $surface           Surface being activated; only `Administrator` is
     *          guarded, `Site` returns immediately.
     * @param   ExecutionContext  $context           Caller's context; must carry a human principal, so a
     *          system or machine identity can never step up.
     * @param   ?string           $stepUpCredential  The actor's current password, re-entered for this
     *          activation, or null when none was supplied.
     *
     * @return  void
     *
     * @throws  StepUpAuthenticationRequired  When no human principal is present, or the supplied password
     *          does not match the actor's active credential.
     * @throws  \Kumwe\App\Identity\Application\Administration\AuthenticationThrottled  When the actor has
     *          already spent the step-up attempt budget.
     *
     * @since   2.0.0
     */
    public function assertAllowed(
        ThemeSurface $surface,
        ExecutionContext $context,
        #[\SensitiveParameter] ?string $stepUpCredential,
    ): void {
        if ($surface === ThemeSurface::Site) {
            return;
        }

        $actorId = $context->actorId();
        if ($context->principal() === null) {
            throw new StepUpAuthenticationRequired(
                'Administrator theme activation requires a human step-up authentication context.',
            );
        }
        $subject = hash('sha256', 'administrator-theme:' . $actorId);
        $source = hash('sha256', 'administrator-theme-step-up');
        $this->rateLimiter->assertAllowed($subject, $source);
        $hash = $this->database->fetchOne(sprintf(
            'SELECT p.password_hash FROM %s p INNER JOIN %s u ON u.id = p.user_id '
            . "WHERE p.user_id = ? AND u.status = 'active'",
            $this->tables->quoted('password_credentials'),
            $this->tables->quoted('users'),
        ), [$actorId]);

        $verified = is_string($hash)
            && $stepUpCredential !== null
            && $this->passwords->verify($stepUpCredential, $hash);
        $this->rateLimiter->record($subject, $source, $verified);

        if (!$verified) {
            throw new StepUpAuthenticationRequired(
                'Administrator theme activation requires current-password step-up authentication.',
            );
        }
    }
}
