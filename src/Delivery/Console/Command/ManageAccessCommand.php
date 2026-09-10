<?php

declare(strict_types=1);

namespace Kumwe\App\Delivery\Console\Command;

use DateTimeImmutable;
use Kumwe\App\Delivery\Console\Command;
use Kumwe\App\Delivery\Console\Output;
use Kumwe\App\Identity\Application\Administration\AccessControlService;
use Kumwe\App\Identity\Application\Administration\AdministratorIdentityGateway;
use Kumwe\App\Identity\Domain\UserStatus;
use Kumwe\Context\Value\ExecutionContext;
use Throwable;

/**
 * Console command that administers users, roles, capability grants and API tokens from a shell.
 *
 * `access` is the console face of `AccessControlService`, and it exists so identity work never
 * depends on the administrator UI being reachable: the same create, assign, grant and revoke
 * operations are available during recovery, from a deployment script, or on a host where no browser
 * can reach the site. The verified token must carry `users.manage` for every action, and the service
 * still applies its own per-record and delegation rules underneath, so console access is not a way
 * around authorization. Secrets travel by protected file rather than argument, so no password or
 * bearer token is ever visible in the process table.
 *
 * @since  2.0.0
 */
final readonly class ManageAccessCommand implements Command
{
    /**
     * Wire the access-control service, the token gateway, and the console authorization gate.
     *
     * @param  AccessControlService          $access         Owns users, roles, grants and revocation.
     * @param  AdministratorIdentityGateway  $identities     Issues the replacement credential the
     *         `rotate-token` action returns.
     * @param  ConsoleAuthorizer             $authorization  Turns `--site` and `--token-file` into an
     *         execution context carrying `users.manage`.
     *
     * @since  2.0.0
     */
    public function __construct(
        private AccessControlService $access,
        private AdministratorIdentityGateway $identities,
        private ConsoleAuthorizer $authorization,
    ) {
    }

    /**
     * Name the console dispatcher registers this command under.
     *
     * @return  string  Always `access`.
     *
     * @since   2.0.0
     */
    public function name(): string
    {
        return 'access';
    }

    /**
     * Summary line `bin/kumwe list` prints beside the command name.
     *
     * @return  string  One-sentence statement of what the command administers.
     *
     * @since   2.0.0
     */
    public function description(): string
    {
        return 'core.console.access.description';
    }

    /**
     * Dispatch one access action and print its result as JSON.
     *
     * The first argument names the action and defaults to `users`; everything after it is a
     * `--name=value` option. The token is verified and checked for `users.manage` first, and only then
     * is the action matched, so an unrecognised action is refused after authentication rather than
     * before it. The printed shape follows the action: listings return an `items` array, creating
     * actions return the new `id`, plain mutations return an `updated` flag, and the token actions
     * return their own shape — which is what a script piping this into `jq` has to branch on.
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
            $action = array_shift($arguments) ?? 'users';
            $options = CommandInput::options($arguments);
            $context = $this->authorization->require($options, 'users.manage');
            $result = match ($action) {
                'users' => ['items' => $this->access->users($context)],
                'roles' => [
                    'items' => $this->access->roles($context),
                    'capabilities' => $this->access->capabilities($context),
                ],
                'tokens' => ['items' => $this->access->tokens($context)],
                'create-user' => ['id' => $this->access->createUser(
                    $context,
                    CommandInput::required($options, 'email'),
                    CommandInput::required($options, 'display-name'),
                    CommandInput::secretFile(CommandInput::required($options, 'password-file')),
                    UserStatus::from($options['status'] ?? 'active'),
                )],
                'update-user' => $this->updateUser($options, $context),
                'create-role' => ['id' => $this->access->createRole(
                    $context,
                    CommandInput::required($options, 'code'),
                    CommandInput::required($options, 'name'),
                )],
                'assign-role' => $this->assignRole($options, $context, false),
                'revoke-role' => $this->assignRole($options, $context, true),
                'grant' => ['id' => $this->access->grant(
                    $context,
                    CommandInput::required($options, 'role'),
                    CommandInput::required($options, 'capability'),
                    $options['scope-type'] ?? 'global',
                    $this->optional($options, 'scope'),
                )],
                'revoke-grant' => $this->revokeGrant($options, $context),
                'revoke-token' => $this->revokeToken($options, $context),
                'rotate-token' => $this->rotateToken($options, $context),
                'revoke-user-tokens' => $this->revokeUserTokens($options, $context),
                'emergency-revoke-user-tokens' => $this->emergencyRevokeUserTokens($options, $context),
                'reset-password' => $this->resetPassword($options, $context),
                'revoke-step-up' => $this->revokeStepUp($options, $context),
                'terminate-sessions' => $this->terminateSessions($options, $context),
                default => throw new \InvalidArgumentException('Unsupported access action.'),
            };
            $output->line(CommandInput::render($result));
            return 0;
        } catch (Throwable $exception) {
            $output->error($exception->getMessage());
            return 1;
        }
    }

    /**
     * Replace a user's profile and lifecycle state under optimistic concurrency.
     *
     * The service takes a complete replacement rather than a patch, so every field is required even
     * when only one is changing, and `--version` must be the version the operator last read, which
     * stops two administrators editing the same account from silently overwriting each other. Two
     * refusals are worth knowing about before scripting this: the lifecycle rule on `UserStatus`
     * rejects transitions such as reviving a disabled account, and an actor cannot suspend or
     * disable their own account.
     *
     * @param   array<string, string>  $options  Console options; `id`, `email`, `display-name`,
     *          `status` and `version` are all required.
     * @param   ExecutionContext       $context  Authorized actor and site the change is audited under.
     *
     * @return  array{updated: bool}  Always `['updated' => true]`; a refusal arrives as an exception.
     *
     * @throws  \InvalidArgumentException  When a required option is missing, `version` is not a
     *          positive integer, the address is not a valid email, or the lifecycle transition is
     *          refused.
     * @throws  \ValueError  When `status` names no `UserStatus` case.
     *
     * @since   2.0.0
     */
    private function updateUser(array $options, ExecutionContext $context): array
    {
        $this->access->updateUser(
            $context,
            CommandInput::required($options, 'id'),
            CommandInput::required($options, 'email'),
            CommandInput::required($options, 'display-name'),
            UserStatus::from(CommandInput::required($options, 'status')),
            CommandInput::positiveInteger($options, 'version'),
        );
        return ['updated' => true];
    }

    /**
     * Attach a role to a user, or detach it.
     *
     * Both directions take the same two identifiers and the same authorization, so they share one
     * call site and `$revoke` picks the service method. Assignment additionally checks that the actor
     * may delegate the role at all, so an operator cannot hand out capabilities beyond their own.
     *
     * @param   array<string, string>  $options  Console options; `user` and `role` are both required.
     * @param   ExecutionContext       $context  Authorized actor and site the change is audited under.
     * @param   bool                   $revoke   True to remove the assignment, false to create it.
     *
     * @return  array{updated: bool}  Always `['updated' => true]` once the change committed.
     *
     * @throws  \InvalidArgumentException  When `user` or `role` is missing, or when an actor tries to
     *          take the administrator role off their own account.
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor may not
     *          delegate one of the capabilities the role grants.
     *
     * @since   2.0.0
     */
    private function assignRole(array $options, ExecutionContext $context, bool $revoke): array
    {
        $arguments = [
            $context, CommandInput::required($options, 'user'),
            CommandInput::required($options, 'role'),
        ];
        if ($revoke) {
            $this->access->revokeRole(...$arguments);
        } else {
            $this->access->assignRole(...$arguments);
        }
        return ['updated' => true];
    }

    /**
     * Withdraw one capability grant from the role that holds it.
     *
     * The grant is named by its own identifier rather than by role and capability, because the same
     * capability can be granted to one role several times at different scopes and only the operator
     * knows which of those they inspected.
     *
     * @param   array<string, string>  $options  Console options; `grant` identifies the grant to drop.
     * @param   ExecutionContext       $context  Authorized actor and site the change is audited under.
     *
     * @return  array{updated: bool}  Always `['updated' => true]` once the grant is gone.
     *
     * @throws  \InvalidArgumentException  When `grant` is missing, or names no existing grant.
     *
     * @since   2.0.0
     */
    private function revokeGrant(array $options, ExecutionContext $context): array
    {
        $this->access->revokeGrant($context, CommandInput::required($options, 'grant'));
        return ['updated' => true];
    }

    /**
     * Revoke one API or MCP token so it stops authenticating immediately.
     *
     * The token is named by the identifier the `tokens` listing prints, never by the plaintext
     * credential, so revoking a leaked token does not require handling it again. Revocation is
     * confined to the site in the execution context: a token issued for another site must be revoked
     * from that site's context.
     *
     * @param   array<string, string>  $options  Console options; `token` is the token identifier.
     * @param   ExecutionContext       $context  Authorized actor and site the token must belong to.
     *
     * @return  array{updated: bool}  Always `['updated' => true]` once the token is revoked.
     *
     * @throws  \InvalidArgumentException  When `token` is missing, or the token belongs to a site
     *          other than the one in the context.
     *
     * @since   2.0.0
     */
    private function revokeToken(array $options, ExecutionContext $context): array
    {
        $this->access->revokeToken($context, CommandInput::required($options, 'token'));
        return ['updated' => true];
    }

    /**
     * Issue a replacement for an existing token and revoke the one it replaces.
     *
     * The replacement inherits the subject, capabilities, audience and purpose of the old token, so
     * rotation is a credential change rather than a permission change; only the name and the expiry
     * are restated here. Both halves happen in one transaction, which is what makes this safe to run
     * against a credential that is currently in use. The plaintext token comes back exactly once, in
     * the JSON this command prints — route it into a secret manager, because nothing can recover it
     * afterwards. Omitting `expires-at` issues a replacement with no expiry.
     *
     * @param   array<string, string>  $options  Console options; `token` and `name` are required and
     *          `expires-at` is an optional date-time string.
     * @param   ExecutionContext       $context  Authorized actor and site the rotation is audited under.
     *
     * @return  array{token: string, token_id: string}  The plaintext replacement and its identifier.
     *
     * @throws  \InvalidArgumentException  When `token` or `name` is missing.
     * @throws  \DateMalformedStringException  When `expires-at` is not a parseable date-time string.
     *
     * @since   2.0.0
     */
    private function rotateToken(array $options, ExecutionContext $context): array
    {
        $expiresAt = $this->optional($options, 'expires-at');
        return $this->identities->rotateAccessToken(
            $context,
            CommandInput::required($options, 'token'),
            CommandInput::required($options, 'name'),
            $expiresAt === null ? null : new DateTimeImmutable($expiresAt),
        );
    }

    /**
     * Revoke every token a user holds for the current site.
     *
     * This is the proportionate response to a credential that leaked from one site: tokens the same
     * user holds for other sites keep working, so an incident on one property does not lock the
     * person out of the installation. Reach for `emergency-revoke-user-tokens` when the account
     * itself is suspect. The reason is mandatory and is stored on each revoked row and on the audit
     * event, so the count this returns can be explained later.
     *
     * @param   array<string, string>  $options  Console options; `user` and `reason` are required.
     * @param   ExecutionContext       $context  Authorized actor and the site whose tokens are dropped.
     *
     * @return  array{revoked_tokens: int}  How many tokens this call actually revoked.
     *
     * @throws  \InvalidArgumentException  When `user` or `reason` is missing, or the reason is longer
     *          than 500 characters.
     *
     * @since   2.0.0
     */
    private function revokeUserTokens(array $options, ExecutionContext $context): array
    {
        return ['revoked_tokens' => $this->access->revokeSubjectTokens(
            $context,
            CommandInput::required($options, 'user'),
            CommandInput::required($options, 'reason'),
        )];
    }

    /**
     * Revoke every token a user holds, across every site.
     *
     * This is the break-glass form, for when the account rather than one credential is believed
     * compromised: it deliberately ignores the site scope `revoke-user-tokens` respects, so a
     * responder does not have to enumerate the sites the person had tokens for. The mandatory reason
     * is what an auditor reads afterwards to justify the blast radius.
     *
     * @param   array<string, string>  $options  Console options; `user` and `reason` are required.
     * @param   ExecutionContext       $context  Authorized actor the revocation is audited under.
     *
     * @return  array{revoked_tokens: int}  How many tokens this call actually revoked.
     *
     * @throws  \InvalidArgumentException  When `user` or `reason` is missing, or the reason is longer
     *          than 500 characters.
     *
     * @since   2.0.0
     */
    private function emergencyRevokeUserTokens(array $options, ExecutionContext $context): array
    {
        return ['revoked_tokens' => $this->access->emergencyRevokeAllSubjectTokens(
            $context,
            CommandInput::required($options, 'user'),
            CommandInput::required($options, 'reason'),
        )];
    }

    /**
     * Replace another account's password from a protected file, on the record.
     *
     * The console face of the administrator reset, for the ordinary case where an operator is
     * authorized and the site is reachable but the browser is not the tool at hand — a runbook step, a
     * provisioning script, a support rotation. It carries exactly the same rules the screen does: the
     * actor must hold `users.manage` over the account, the reason is mandatory and lands on the audit
     * event beside an actor who is not the subject, and an operator may not reset their own password
     * this way because that would skip proving the current one.
     *
     * The replacement arrives in a file the operator has locked down rather than as an argument, the
     * same way `user:create-admin` takes one, so it never reaches shell history or the process table.
     *
     * @param   array<string, string>  $options  Console options; `user`, `password-file` and `reason`
     *          are all required.
     * @param   ExecutionContext       $context  Authorized actor the reset is audited under.
     *
     * @return  array{updated: bool, sessions_terminated: int}  Confirmation and how many of the
     *          subject's administrator sessions the reset ended.
     *
     * @throws  \InvalidArgumentException  When an option is missing, the file is unreadable or empty,
     *          the reason is too long, the replacement is shorter than twelve characters, or the actor
     *          named their own account.
     *
     * @since   2.0.0
     */
    private function resetPassword(array $options, ExecutionContext $context): array
    {
        return [
            'updated' => true,
            'sessions_terminated' => $this->access->resetUserPassword(
                $context,
                CommandInput::required($options, 'user'),
                CommandInput::secretFile(CommandInput::required($options, 'password-file')),
                CommandInput::required($options, 'reason'),
            ),
        ];
    }

    /**
     * Retire every second factor an account holds so a replacement can be enrolled.
     *
     * For the operator who lost the authenticator and spent the recovery codes: nothing they can
     * present will satisfy a step-up challenge again, and enrollment refuses to issue a replacement
     * while the dead credential is still active, so without this the account is locked out of every
     * step-up-gated mutation permanently. Retiring the credential lifts both at once. It also advances
     * the subject's security epoch, which retires their tokens, sessions and outstanding proofs, so the
     * reason is mandatory and is what explains that blast radius afterwards.
     *
     * @param   array<string, string>  $options  Console options; `user` and `reason` are required.
     * @param   ExecutionContext       $context  Authorized actor the retirement is audited under.
     *
     * @return  array{revoked_credentials: int}  How many credentials this call retired.
     *
     * @throws  \InvalidArgumentException  When `user` or `reason` is missing, the reason is longer than
     *          500 characters, or the account does not exist.
     *
     * @since   2.0.0
     */
    private function revokeStepUp(array $options, ExecutionContext $context): array
    {
        return ['revoked_credentials' => $this->access->revokeStepUpCredentials(
            $context,
            CommandInput::required($options, 'user'),
            CommandInput::required($options, 'reason'),
        )];
    }

    /**
     * Sign an account out of every browser it is signed in on, without touching anything else.
     *
     * The narrow response to a session that should not still be open — a shared workstation, a departed
     * contractor's laptop, a report of a stolen phone — where suspending the account would be too
     * large and revoking tokens would not reach the browser at all. The security epoch advances, so
     * portal sessions and outstanding step-up proofs go with the administrator ones; roles, password
     * and lifecycle status are left exactly as they were.
     *
     * @param   array<string, string>  $options  Console options; `user` and `reason` are required.
     * @param   ExecutionContext       $context  Authorized actor the termination is audited under.
     *
     * @return  array{sessions_terminated: int}  How many administrator sessions this call ended.
     *
     * @throws  \InvalidArgumentException  When `user` or `reason` is missing, the reason is longer than
     *          500 characters, or the account does not exist.
     *
     * @since   2.0.0
     */
    private function terminateSessions(array $options, ExecutionContext $context): array
    {
        return ['sessions_terminated' => $this->access->terminateUserSessions(
            $context,
            CommandInput::required($options, 'user'),
            CommandInput::required($options, 'reason'),
        )];
    }

    /**
     * Read an option that may legitimately be absent.
     *
     * Trimming first means `--scope=` and a whitespace-only value are treated as absent rather than
     * being forwarded as an empty scope or an empty expiry, which the services would reject on
     * different grounds and with a less helpful message.
     *
     * @param   array<string, string>  $options  Parsed console options.
     * @param   string                 $name     Option name to read, without the leading dashes.
     *
     * @return  ?string  The trimmed value, or null when the option is absent or blank.
     *
     * @since   2.0.0
     */
    private function optional(array $options, string $name): ?string
    {
        $value = trim($options[$name] ?? '');
        return $value === '' ? null : $value;
    }
}
