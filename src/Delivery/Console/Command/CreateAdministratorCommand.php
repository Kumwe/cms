<?php

declare(strict_types=1);

namespace Kumwe\App\Delivery\Console\Command;

use InvalidArgumentException;
use Kumwe\App\Application\Authorization\SystemPrincipal;
use Kumwe\App\Delivery\Console\Command;
use Kumwe\App\Delivery\Console\Output;
use Kumwe\App\Identity\Application\Administration\AdministratorIdentityGateway;
use Kumwe\Context\Value\SiteContext;
use Throwable;

/**
 * Console entry point that provisions an administrator account from a protected password file.
 *
 * This resolves the chicken-and-egg problem a fresh installation starts in: nobody can sign in yet, so
 * there is no operator to authorize against. It therefore acts under the composition root's
 * `SystemPrincipal` rather than a credential the caller presented, which makes reaching the console the
 * authorization — anyone who can run it already owns the host. `bin/kumwe-install` drives it for the
 * first account, and `bootstrap/console.php` lists it among the recovery commands, so it still works
 * when the full container cannot be built. Despite the gateway method's name it is not limited to the
 * first account; later administrators are provisioned the same way. The password arrives in a file the
 * operator has locked down, never as an argument, so it stays out of shell history and the process table.
 *
 * @since  2.0.0
 */
final readonly class CreateAdministratorCommand implements Command
{
    /**
     * Wire the command to the identity gateway and to the bootstrap authority it acts under.
     *
     * @param  AdministratorIdentityGateway  $identities  Gateway that creates the account and hashes its password.
     * @param  SystemPrincipal               $system      Trusted in-process authority the creation runs under,
     *         since no operator is signed in.
     *
     * @since  2.0.0
     */
    public function __construct(
        private AdministratorIdentityGateway $identities,
        private SystemPrincipal $system,
    ) {
    }

    /**
     * Name the operator types to create an administrator.
     *
     * @return  string  Always `user:create-admin`.
     *
     * @since   2.0.0
     */
    public function name(): string
    {
        return 'user:create-admin';
    }

    /**
     * Describe the command for the console's command listing.
     *
     * @return  string  One-line summary naming the password-file requirement.
     *
     * @since   2.0.0
     */
    public function description(): string
    {
        return 'core.console.user_create_admin.description';
    }

    /**
     * Create the administrator the options describe and print the identifier of the new account.
     *
     * The run acts as the system identity against the default site, under a fresh random request
     * identifier so the audit record ties back to this invocation. Failures are caught and reduced to a
     * message and exit status 1, keeping the password out of any stack trace.
     *
     * @param   list<string>  $arguments  Only `--name=value` options: `--email`, `--name` and `--password-file`.
     * @param   Output        $output     Sink for the confirmation line, or for the failure message.
     *
     * @return  int  0 when the administrator was created, 1 when any step failed.
     *
     * @since   2.0.0
     */
    public function execute(array $arguments, Output $output): int
    {
        try {
            $options = $this->options($arguments);
            $password = $this->passwordFromFile($this->required($options, 'password-file'));
            $id = $this->identities->createInitialAdministrator(
                $this->system->context(
                    SiteContext::default(),
                    'bootstrap-' . bin2hex(random_bytes(16)),
                ),
                $this->required($options, 'email'),
                $this->required($options, 'name'),
                $password,
            );
            $output->message('core.console.user_create_admin.created_administrator', ['id' => $id]);

            return 0;
        } catch (Throwable $exception) {
            $output->error($exception->getMessage());

            return 1;
        }
    }

    /**
     * Parse this command's argument list into an option map keyed by option name.
     *
     * Stricter than `CommandInput::options()`: each option here names an email address, a display name or
     * a file path, so a bare `--email=` is rejected at parse time rather than accepted as an empty string.
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
     * Read an option the command cannot create an account without.
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
     * Read the new administrator's password out of a file the operator has protected.
     *
     * The file must be named by an absolute path, be a readable regular file rather than a symlink, and
     * carry no group or other permission bits — a password other accounts on the host can already read is
     * not worth setting. Only trailing carriage returns and newlines are stripped, so leading and interior
     * whitespace is part of the password, and what survives must be at least 12 characters.
     *
     * @param   string  $path  Absolute filesystem path of the file holding the password.
     *
     * @return  string  The password with trailing carriage returns and newlines removed.
     *
     * @throws  InvalidArgumentException  When the path is relative, is a symlink, names no readable regular
     *          file, is readable or writable by group or others, cannot be read, or holds fewer than 12
     *          characters.
     *
     * @since   2.0.0
     */
    private function passwordFromFile(string $path): string
    {
        if (!str_starts_with($path, '/') || is_link($path) || !is_file($path) || !is_readable($path)) {
            throw new InvalidArgumentException('The password file must be an absolute, readable regular file.');
        }

        $permissions = fileperms($path);

        if (!is_int($permissions) || ($permissions & 0o077) !== 0) {
            throw new InvalidArgumentException(
                'The password file must not be readable or writable by group or others.',
            );
        }

        $password = file_get_contents($path);

        if (!is_string($password)) {
            throw new InvalidArgumentException('The password file could not be read.');
        }

        $password = rtrim($password, "\r\n");

        if (strlen($password) < 12) {
            throw new InvalidArgumentException(
                'The administrator password must contain at least 12 characters.',
            );
        }

        return $password;
    }
}
