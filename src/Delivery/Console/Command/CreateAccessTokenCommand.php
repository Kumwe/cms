<?php

declare(strict_types=1);

namespace Kumwe\App\Delivery\Console\Command;

use DateTimeImmutable;
use InvalidArgumentException;
use Kumwe\App\BusinessSecurity\Application\MembershipDirectory;
use Kumwe\App\Delivery\Console\Command;
use Kumwe\App\Delivery\Console\Output;
use Kumwe\App\Identity\Application\Administration\AdministratorIdentityGateway;
use Kumwe\App\Identity\Application\Authorization\InsufficientCapability;
use Kumwe\Context\Value\AuthenticationStrength;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Context\Value\SiteContext;
use Kumwe\Extension\Spi\Identity\Domain\Capability;
use Throwable;

/**
 * Console entry point that issues a scoped API or MCP access token and prints it once.
 *
 * Only the token's hash is stored, so the line this command prints is the operator's single opportunity
 * to capture the value — losing it means issuing another token, not recovering this one. It authorizes
 * two ways, which is what lets it bootstrap itself: an existing `--token-file` authorizes it like any
 * other management command, while an operator holding only a password file authenticates directly, the
 * route taken on a fresh installation where no token exists yet. Either way the actor must hold
 * `users.manage`.
 *
 * @since  2.0.0
 */
final readonly class CreateAccessTokenCommand implements Command
{
    /**
     * Wire the command to the identity gateway and to the token-file authorization route.
     *
     * @param  AdministratorIdentityGateway  $identities     Gateway that authenticates the operator and mints
     *         the token record.
     * @param  ConsoleAuthorizer             $authorization  Authorization route used when `--token-file` is
     *         supplied instead of a password file.
     * @param  MembershipDirectory           $memberships    Resolves an exact live organization/workspace
     *         selection for password-authenticated issuance.
     *
     * @since  2.0.0
     */
    public function __construct(
        private AdministratorIdentityGateway $identities,
        private ConsoleAuthorizer $authorization,
        private MembershipDirectory $memberships,
    ) {
    }

    /**
     * Name the operator types to issue a token.
     *
     * @return  string  Always `token:create`.
     *
     * @since   2.0.0
     */
    public function name(): string
    {
        return 'token:create';
    }

    /**
     * Describe the command for the console's command listing.
     *
     * @return  string  One-line summary warning that the token is shown only once.
     *
     * @since   2.0.0
     */
    public function description(): string
    {
        return 'core.console.token_create.description';
    }

    /**
     * Issue the token the options describe, then print it and its identifier.
     *
     * `--capabilities` is a comma-separated list from which blank entries are dropped, and `--expires-at`
     * is any string `DateTimeImmutable` parses — omitting it takes the gateway's default lifetime rather
     * than issuing a token that never expires. `--audience` and `--purpose` default to a general HTTP API
     * token, so a token meant to authorize later console runs has to ask for the console pair explicitly.
     * Failures are caught and reduced to a message and exit status 1, so the token value and the
     * operator's password never reach a stack trace.
     *
     * @param   list<string>  $arguments  Only `--name=value` options: `--email`, `--name` and
     *          `--capabilities`, one of `--token-file` (with `--site`) or `--password-file`, and
     *          optionally `--expires-at`, `--audience`, `--purpose`, and a password-authenticated
     *          `--organization` with optional `--workspace`.
     * @param   Output        $output     Sink for the token, its identifier, or the failure message.
     *
     * @return  int  0 when the token was issued, 1 when any step failed.
     *
     * @since   2.0.0
     */
    public function execute(array $arguments, Output $output): int
    {
        try {
            $options = $this->options($arguments);
            $capabilities = array_values(array_filter(array_map(
                'trim',
                explode(',', $this->required($options, 'capabilities')),
            ), static fn (string $value): bool => $value !== ''));
            $expiresAt = isset($options['expires-at']) ? new DateTimeImmutable($options['expires-at']) : null;
            $context = $this->authorizationContext($options);
            $created = $this->identities->issueAccessToken(
                $context,
                $this->required($options, 'email'),
                $this->required($options, 'name'),
                $capabilities,
                $expiresAt,
                $options['audience'] ?? 'kumwe-http',
                $options['purpose'] ?? 'api',
            );
            $output->message('core.console.token_create.store_this_token_now_kumwe_will');
            $output->line($created['token']);
            $output->message('core.console.token_create.token_id', ['created' => $created['token_id']]);

            return 0;
        } catch (Throwable $exception) {
            $output->error($exception->getMessage());

            return 1;
        }
    }

    /**
     * Parse this command's argument list into an option map keyed by option name.
     *
     * Deliberately stricter than `CommandInput::options()`: every option here names an identity, a
     * capability set or a file path, none of which is meaningful when blank, so a bare `--email=` is
     * rejected at parse time rather than accepted as an empty string and reported later.
     *
     * @param   list<string>  $arguments  Arguments the console passed after the command name.
     *
     * @return  array<string, string>  Values keyed by option name without the leading `--`.
     *
     * @throws  InvalidArgumentException  When an argument is not a `--name=value` pair carrying a value.
     *
     * @since   2.0.0
     */
    private function options(array $arguments): array
    {
        $options = [];

        foreach ($arguments as $argument) {
            if (preg_match('/^--([a-z][a-z-]*)=(.+)$/D', $argument, $matches) !== 1) {
                throw new InvalidArgumentException('Options must use --name=value syntax.');
            }

            $options[$matches[1]] = $matches[2];
        }

        return $options;
    }

    /**
     * Read an option the command cannot issue a token without.
     *
     * @param   array<string, string>  $options  Parsed option map to read from.
     * @param   string                 $name     Option name without the leading `--`.
     *
     * @return  string  The value with surrounding whitespace removed, never empty.
     *
     * @throws  InvalidArgumentException  When the option is absent or trims to an empty string.
     *
     * @since   2.0.0
     */
    private function required(array $options, string $name): string
    {
        $value = trim($options[$name] ?? '');

        if ($value === '') {
            throw new InvalidArgumentException(sprintf('The --%s option is required.', $name));
        }

        return $value;
    }

    /**
     * Resolve the context the token is issued under, from either a token file or a password file.
     *
     * The presence of `--token-file` selects the route. With it, `ConsoleAuthorizer` verifies the token
     * and scopes the context to `--site`, exactly as for the other management commands; organization flags
     * cannot replace that credential's scope. Without a token, the operator's password authenticates them
     * against the default site. An optional organization and workspace are then resolved as the subject's
     * exact live membership rather than trusted as authority. Both routes end at the same requirement:
     * `users.manage`, held by the principal the credential resolves to.
     *
     * @param   array<string, string>  $options  Parsed command options; must carry `--token-file`, or
     *          `--email` together with `--password-file`.
     *
     * @return  ExecutionContext  Authorized context for the operator the credential named.
     *
     * @throws  InsufficientCapability  When the credential resolves to no principal, or to one that does
     *          not hold `users.manage`.
     * @throws  InvalidArgumentException  When a required option is missing, the password file fails its
     *          location and permission checks, or the requested membership is unavailable.
     *
     * @since   2.0.0
     */
    private function authorizationContext(array $options): ExecutionContext
    {
        if (isset($options['token-file'])) {
            if (isset($options['organization']) || isset($options['workspace'])) {
                throw new InvalidArgumentException(
                    'Organization and workspace selection is derived from the verified token.',
                );
            }

            return $this->authorization->require($options, 'users.manage');
        }

        if (isset($options['workspace']) && !isset($options['organization'])) {
            throw new InvalidArgumentException('The --workspace option requires --organization.');
        }

        $principal = $this->identities->authenticate(
            $this->required($options, 'email'),
            CommandInput::secretFile($this->required($options, 'password-file')),
            'cli-token-create',
        );
        if ($principal === null || !$principal->hasCapability(Capability::fromString('users.manage'))) {
            throw new InsufficientCapability('users.manage');
        }

        $site = SiteContext::default();
        $membership = null;
        if (isset($options['organization'])) {
            $membership = $this->memberships->resolve(
                $principal->subject(),
                $site,
                $options['organization'],
                $options['workspace'] ?? null,
            );
            if ($membership === null) {
                throw new InvalidArgumentException(
                    'The requested live organization membership is unavailable.',
                );
            }
        }

        return $principal->context(
            $site,
            AuthenticationStrength::Password,
            'cli-' . bin2hex(random_bytes(16)),
            membership: $membership,
        );
    }
}
