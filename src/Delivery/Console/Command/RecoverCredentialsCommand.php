<?php

declare(strict_types=1);

namespace Kumwe\App\Delivery\Console\Command;

use InvalidArgumentException;
use Kumwe\App\Application\Authorization\SystemPrincipal;
use Kumwe\App\Delivery\Console\Command;
use Kumwe\App\Delivery\Console\Output;
use Kumwe\App\Identity\Application\Administration\AccessControlRepository;
use Kumwe\App\Identity\Application\Administration\AccessControlService;
use Kumwe\App\Identity\Domain\EmailAddress;
use Kumwe\Context\Value\SiteContext;
use Throwable;

/**
 * Break-glass console recovery for an account nobody can sign into or step up as any more.
 *
 * Every other credential path in the installation assumes somebody is still able to authenticate: the
 * administrator screens need a live session, the console `access` actions need a token carrying
 * `users.manage`, and the second-factor reset on both needs an operator who can pass a step-up
 * challenge of their own. There is one situation those assumptions do not cover, and it is not
 * hypothetical — an installation whose administrators have between them lost every authenticator and
 * spent every recovery code cannot perform a single step-up-gated mutation, which includes the reset
 * that would repair them. That is a genuine total lockout, and the only honest answer is an authority
 * that does not come from a credential at all.
 *
 * So this acts under the composition root's `SystemPrincipal`, as `user:create-admin` does, and
 * reaching the console is the authorization: whoever can run it already owns the host, the database
 * and the application secret, and could rewrite the credential tables by hand regardless. What this
 * adds over that hand surgery is that the repair goes through the same service the administrator
 * screens use, so it takes the same locks, advances the same security epoch, ends the same sessions
 * and writes the same audit events — a recovery that is reconstructable afterwards rather than an
 * unexplained change to a credential row.
 *
 * The identity it acts as is `SystemIdentity::CredentialRecovery`, not the bootstrap one. Bootstrap
 * holds `administrator.bootstrap` alone precisely so that creating the first account can never become
 * a way to edit identity records later; this repair genuinely needs `users.manage`, so it gets its own
 * identity instead of widening that one. The audit trail therefore names `system:credential-recovery`
 * as the actor, which is the one exact string to search for when reviewing whether host authority was
 * ever used to reach an account.
 *
 * Because reaching the console is the authorization, the console must be worth that trust: host access
 * should be held by fewer people than administrator access. Passwords arrive in protected files rather
 * than as arguments, so no secret reaches the shell history or the process table.
 *
 * @since  2.0.0
 */
final readonly class RecoverCredentialsCommand implements Command
{
    /**
     * Wire the recovery actions to the access-control service and the bootstrap authority.
     *
     * @param  AccessControlService     $access      Owns the password, second-factor and session
     *         retirements, with their locks, epoch advance and audit events.
     * @param  AccessControlRepository  $repository  Resolves the named address to the account it
     *         belongs to, so an operator recovers by email rather than by identifier.
     * @param  SystemPrincipal          $system      Trusted in-process authority the recovery runs
     *         under, since by assumption nobody can sign in.
     *
     * @since  2.0.0
     */
    public function __construct(
        private AccessControlService $access,
        private AccessControlRepository $repository,
        private SystemPrincipal $system,
    ) {
    }

    /**
     * Name the console dispatcher registers this command under.
     *
     * @return  string  Always `user:recover-credentials`.
     *
     * @since   2.0.0
     */
    public function name(): string
    {
        return 'user:recover-credentials';
    }

    /**
     * Summary line `bin/kumwe list` prints beside the command name.
     *
     * @return  string  One-sentence statement of what the command repairs and under what authority.
     *
     * @since   2.0.0
     */
    public function description(): string
    {
        return 'core.console.user_recover_credentials.description';
    }

    /**
     * Run one recovery action against the account the `--email` option names.
     *
     * The action is the first argument and has no default: a command that repairs credentials should
     * never do anything at all because an argument was forgotten. Everything after it is a
     * `--name=value` option. Failures are reduced to a message and exit status 1, which keeps a
     * password out of any stack trace the console would otherwise print.
     *
     * @param   list<string>  $arguments  Action name first, then `--name=value` options.
     * @param   Output        $output     Sink for the JSON result, or for the failure message.
     *
     * @return  int  `0` when the action completed, `1` with its message on stderr when it did not.
     *
     * @since   2.0.0
     */
    public function execute(array $arguments, Output $output): int
    {
        try {
            $action = array_shift($arguments)
                ?? throw new InvalidArgumentException('Name the recovery action to perform.');
            $options = CommandInput::options($arguments);
            $context = $this->system->context(
                SiteContext::default(),
                'break-glass-' . bin2hex(random_bytes(16)),
            );
            $userId = $this->account($options);
            $reason = $this->reason($options, $action);
            $result = match ($action) {
                'reset-password' => [
                    'updated' => true,
                    'sessions_terminated' => $this->access->resetUserPassword(
                        $context,
                        $userId,
                        CommandInput::secretFile(CommandInput::required($options, 'password-file')),
                        $reason,
                    ),
                ],
                'revoke-step-up' => [
                    'revoked_credentials' => $this->access->revokeStepUpCredentials($context, $userId, $reason),
                ],
                'terminate-sessions' => [
                    'sessions_terminated' => $this->access->terminateUserSessions($context, $userId, $reason),
                ],
                default => throw new InvalidArgumentException('Unsupported credential recovery action.'),
            };
            $output->line(CommandInput::render($result));

            return 0;
        } catch (Throwable $exception) {
            $output->error($exception->getMessage());

            return 1;
        }
    }

    /**
     * Resolve the account the recovery acts on from the address the operator typed.
     *
     * An identifier would be the safer input, but the operator running a break-glass recovery is
     * looking at a support ticket rather than at a database, so the address is what they have. It is
     * normalised through the same value object sign-in uses, so the lookup matches the stored key
     * however the address was capitalised.
     *
     * @param   array<string, string>  $options  Parsed console options; `email` is required.
     *
     * @return  string  UUID of the account to recover.
     *
     * @throws  InvalidArgumentException  When `email` is absent, is not an address, or names no account.
     *
     * @since   2.0.0
     */
    private function account(array $options): string
    {
        $email = EmailAddress::fromString(CommandInput::required($options, 'email'))->value();

        return $this->repository->userIdByEmail($email)
            ?? throw new InvalidArgumentException('No account carries that address.');
    }

    /**
     * Read the operator's justification, defaulting to one that names the action as break-glass.
     *
     * The service demands a reason on every one of these acts, and a default is offered rather than a
     * refusal because an operator repairing a locked-out installation at speed should not have the
     * recovery refused over prose. The default still says what happened and that it came from the
     * host, which is the fact an auditor most needs; a supplied reason is always better.
     *
     * @param   array<string, string>  $options  Parsed console options; `reason` is optional.
     * @param   string                 $action   Recovery action being run, named in the default text.
     *
     * @return  string  The trimmed justification, or the generated break-glass sentence.
     *
     * @since   2.0.0
     */
    private function reason(array $options, string $action): string
    {
        $reason = trim($options['reason'] ?? '');

        return $reason === ''
            ? sprintf('Break-glass console recovery (%s) run from the installation host.', $action)
            : $reason;
    }
}
