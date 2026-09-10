<?php

declare(strict_types=1);

namespace Kumwe\App\Application\Security;

use Kumwe\Context\Value\ExecutionContext;

/**
 * Port that demands a fresh proof of the acting operator's password before an irreversible operation.
 *
 * A capability decision answers whether an actor is allowed to do something; it cannot tell whether the
 * person at the keyboard is still the one the session or API token was issued to. The business-schema
 * stages that can destroy installed data — approving a high-risk plan, planning a purge, filing
 * clean-target recovery evidence — call this port on top of the ordinary capability decision, so that
 * holding a live credential is not on its own enough to trigger them. An implementation owes three
 * behaviours: it refuses any context without a human principal, since a machine identity has no password
 * to re-enter and that escalation is the main thing this check exists to stop; it fails identically
 * whether the account has no stored password or supplied the wrong one, so nothing is revealed about the
 * account; and it raises `HighImpactAuthenticationRequired` rather than an authorization denial, which is
 * what lets a client know to re-prompt for the password instead of reporting the operation as forbidden.
 * `DoctrineHighImpactCredentialGuard` is the implementation the container wires.
 *
 * @since  2.0.0
 */
interface HighImpactCredentialGuard
{
    /**
     * Assert that the acting operator has just re-entered the password their account currently holds.
     *
     * Callers run it after their own argument and confirmation checks and go ahead on a normal return;
     * there is no result to inspect. An implementation is expected to put every attempt through a
     * throttle scoped to the actor and the purpose, so a run of wrong passwords locks the actor out of
     * further tries at this operation without spending any other operation's budget.
     *
     * @param   ExecutionContext  $context     Actor to re-authenticate; a context carrying no human
     *          principal is refused rather than waved through.
     * @param   string            $purpose     Operation the proof is demanded for, such as
     *          `business.schema.approve`; scopes the throttle counters to that one operation.
     * @param   ?string           $credential  The actor's current password as just re-entered, or null
     *          when the caller collected none. Marked `#[\SensitiveParameter]` so it stays out of stack
     *          traces.
     *
     * @return  void
     *
     * @throws  HighImpactAuthenticationRequired  When the context carries no human principal, or the
     *          password does not verify against the actor's active credential.
     *
     * @since   2.0.0
     */
    public function assertCurrentPassword(
        ExecutionContext $context,
        string $purpose,
        #[\SensitiveParameter] ?string $credential,
    ): void;
}
