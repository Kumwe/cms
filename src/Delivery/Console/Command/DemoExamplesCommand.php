<?php

declare(strict_types=1);

namespace Kumwe\App\Delivery\Console\Command;

use InvalidArgumentException;
use Kumwe\App\Delivery\Console\Command;
use Kumwe\App\Delivery\Console\Output;
use Kumwe\App\Demo\Infrastructure\DemoExampleExtensionInstaller;
use Kumwe\App\Identity\Application\Administration\AdministratorIdentityGateway;
use Kumwe\App\Kernel\Configuration\ApplicationConfiguration;
use Kumwe\Context\Value\AuthenticationStrength;
use Kumwe\Context\Value\SiteContext;
use Throwable;

/**
 * Console entry point that installs the shipped example extensions into a demonstration.
 *
 * The gating mirrors `demo:provision-access`: reaching the console already requires host access, and
 * the installs run under a real administrator whose password arrives in a protected file — never as an
 * argument — so every trust key, install, and activation carries ordinary administrator provenance
 * through the canonical extension pipeline. The default selection installs every demonstration-worthy
 * example, and `--extensions` narrows it to any subset, which is how a deployment opts out of
 * individual examples without losing the rest.
 *
 * @since  2.0.0
 */
final readonly class DemoExamplesCommand implements Command
{
    /**
     * Examples installed when the operator does not narrow the selection.
     *
     * The two minimal template scaffolds are deliberately absent: they exist to be copied by
     * extension authors, not to run in a demonstration. The Horizon theme is included but only
     * installed — switching the public site onto it stays an operator decision.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private const array DEFAULT_EXAMPLES = ['announcements', 'asset-inspection', 'audit-listener', 'horizon-theme'];

    /**
     * Wire the command to configuration, authentication, and the example installer.
     *
     * @param  ApplicationConfiguration       $configuration  Validated process profile selectors.
     * @param  AdministratorIdentityGateway   $identities     Password verifier for the acting administrator.
     * @param  DemoExampleExtensionInstaller  $installer      Idempotent packaging and activation service.
     *
     * @since  2.0.0
     */
    public function __construct(
        private ApplicationConfiguration $configuration,
        private AdministratorIdentityGateway $identities,
        private DemoExampleExtensionInstaller $installer,
    ) {
    }

    /**
     * Name the operators type to install the demonstration examples.
     *
     * @return  string  Always `demo:install-examples`.
     *
     * @since   2.0.0
     */
    public function name(): string
    {
        return 'demo:install-examples';
    }

    /**
     * Describe the command for the console's command listing.
     *
     * @return  string  One-line summary naming the default example set.
     *
     * @since   2.0.0
     */
    public function description(): string
    {
        return 'core.console.demo_install_examples.description';
    }

    /**
     * Install the selected examples under an authenticated administrator.
     *
     * @param   list<string>  $arguments  Only `--name=value` options: `--admin-email`,
     *          `--admin-password-file`, and optionally `--extensions` as a comma-separated subset.
     * @param   Output        $output     Sink for the per-example outcomes or the failure message.
     *
     * @return  int  0 when every selected example is active, 1 when any step failed.
     *
     * @since   2.0.0
     */
    public function execute(array $arguments, Output $output): int
    {
        try {
            $options = $this->options($arguments);
            $selection = $this->selection($options['extensions'] ?? null);
            $password = $this->passwordFromFile($this->required($options, 'admin-password-file'));
            $principal = $this->identities->authenticate(
                $this->required($options, 'admin-email'),
                $password,
                'demo-example-installation',
            );
            if ($principal === null) {
                throw new InvalidArgumentException('The administrator could not be authenticated.');
            }
            $context = $principal->context(
                SiteContext::fromString($this->configuration->publicSite),
                AuthenticationStrength::Password,
                'demo-examples-' . bin2hex(random_bytes(16)),
            );
            foreach ($selection as $example) {
                $result = $this->installer->install($context, $example);
                $output->message('core.console.demo_install_examples.example_outcome', [
                    'installed' => $result['installed'],
                    'activated' => $result['activated'],
                    'selectable' => $result['installed'] && !$result['activated'],
                    'identifier' => $result['identifier'],
                    'example' => $example,
                ]);
                foreach ($result['contributions'] as $line) {
                    $output->line('  ' . $line);
                }
            }

            return 0;
        } catch (Throwable $failure) {
            $output->line($failure->getMessage());

            return 1;
        }
    }

    /**
     * Resolve the requested example subset against what this release ships.
     *
     * @param   ?string  $requested  Comma-separated example names, or null for the default set.
     *
     * @return  list<string>  Validated selection in request order, never empty.
     *
     * @throws  InvalidArgumentException  When the selection is empty or names an unshipped example.
     *
     * @since   2.0.0
     */
    private function selection(?string $requested): array
    {
        if ($requested === null) {
            return self::DEFAULT_EXAMPLES;
        }
        $available = $this->installer->available();
        $selection = [];
        foreach (explode(',', $requested) as $candidate) {
            $candidate = trim($candidate);
            if ($candidate === '') {
                continue;
            }
            if (!in_array($candidate, $available, true)) {
                throw new InvalidArgumentException(sprintf(
                    'The %s example is not shipped; this release offers %s.',
                    $candidate,
                    implode(', ', $available),
                ));
            }
            $selection[] = $candidate;
        }
        if ($selection === []) {
            throw new InvalidArgumentException('The --extensions option names no examples.');
        }

        return $selection;
    }

    /**
     * Parse `--name=value` options into a map.
     *
     * @param   list<string>  $arguments  Raw console arguments after the command name.
     *
     * @return  array<string, string>  Option values keyed by option name.
     *
     * @throws  InvalidArgumentException  When an argument does not use the option syntax.
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
     * Read an option the command cannot run without.
     *
     * @param   array<string, string>  $options  Parsed option map to read from.
     * @param   string                 $name     Option name without the leading `--`.
     *
     * @return  string  The value with surrounding whitespace removed, never empty.
     *
     * @throws  InvalidArgumentException  When the option is missing or blank.
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
     * Read the acting administrator's password out of a file the operator has protected.
     *
     * The same protections as `demo:provision-access` apply: the file must be named by an absolute
     * path, be a readable regular file rather than a symlink, and carry no group or other permission
     * bits.
     *
     * @param   string  $path  Absolute filesystem path of the file holding the password.
     *
     * @return  string  The password with trailing carriage returns and newlines removed.
     *
     * @throws  InvalidArgumentException  When the path is relative, is a symlink, names no readable
     *          regular file, is readable or writable by group or others, or cannot be read.
     *
     * @since   2.0.0
     */
    private function passwordFromFile(string $path): string
    {
        if (!str_starts_with($path, '/') || is_link($path) || !is_file($path) || !is_readable($path)) {
            throw new InvalidArgumentException('The password file must be an absolute, readable regular file.');
        }

        $permissions = fileperms($path);
        if ($permissions === false || ($permissions & 0o077) !== 0) {
            throw new InvalidArgumentException('The password file must not be readable by group or others.');
        }

        $contents = file_get_contents($path);
        if (!is_string($contents)) {
            throw new InvalidArgumentException('The password file could not be read.');
        }

        return rtrim($contents, "\r\n");
    }
}
