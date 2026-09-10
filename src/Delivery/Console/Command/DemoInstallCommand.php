<?php

declare(strict_types=1);

namespace Kumwe\App\Delivery\Console\Command;

use InvalidArgumentException;
use Kumwe\App\Delivery\Console\Command;
use Kumwe\App\Delivery\Console\Output;
use Kumwe\App\Demo\Infrastructure\DemoAccessProvisioner;
use Kumwe\App\Demo\Infrastructure\DemoExampleExtensionInstaller;
use Kumwe\App\Demo\Infrastructure\FilesystemDemoManifestCatalog;
use Kumwe\App\Identity\Application\Administration\AdministratorIdentityGateway;
use Kumwe\App\Kernel\Configuration\ApplicationConfiguration;
use Kumwe\Context\Value\AuthenticationStrength;
use Kumwe\Context\Value\SiteContext;
use Psr\Clock\ClockInterface;
use Throwable;

/**
 * Console entry point that completes a demonstration in one authenticated step.
 *
 * `database:migrate` already installs the selected site content and business dataset; what it
 * deliberately omits — the demonstration sign-ins and the example extensions — previously took two
 * separate commands. This command runs both behind one authentication: it provisions the selected
 * business profile's cast through `DemoAccessProvisioner` and installs the shipped examples through
 * `DemoExampleExtensionInstaller`, under a single administrator context, exactly as
 * `demo:provision-access` and `demo:install-examples` would. The gating is identical: host access
 * plus a real administrator whose password arrives in a protected file, never as an argument.
 *
 * Re-running is safe. Existing accounts are confirmed without a password change and installed
 * examples are confirmed or reactivated. The `--credentials-file` option is always required, but the
 * file is only created when this run actually generated at least one new password; a run that merely
 * confirms the cast reports that existing sign-ins remain valid and touches nothing, which is what
 * lets an operator re-run the command with the same options. When new credentials must be written,
 * the destination keeps the `demo:provision-access` contract: it must not exist yet, and it is
 * created exclusively with owner-only permissions. The file's path is reported the moment it is
 * written, before any example installs, so a later refusal cannot leave passwords on disk that the
 * operator was never told about; and an example that fails is named in the failure line. When the
 * business profile is `none` the cast is skipped with an explanation instead of failing, and the
 * examples still install.
 *
 * @since  2.0.0
 */
final readonly class DemoInstallCommand implements Command
{
    /**
     * Examples installed when the operator does not narrow the selection.
     *
     * The same default as `demo:install-examples`: every demonstration-worthy example, with the two
     * minimal template scaffolds absent because they exist to be copied, not demonstrated. The
     * Horizon theme installs as selectable only — activating it stays an operator decision.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private const array DEFAULT_EXAMPLES = ['announcements', 'asset-inspection', 'audit-listener', 'horizon-theme'];

    /**
     * Wire the one-step demonstration pipeline to configuration, manifests, and both demo services.
     *
     * @param  ApplicationConfiguration       $configuration  Validated process profile selectors.
     * @param  FilesystemDemoManifestCatalog  $catalog        Shipped immutable JSON manifests.
     * @param  AdministratorIdentityGateway   $identities     Password verifier for the acting administrator.
     * @param  DemoAccessProvisioner          $provisioner    Idempotent cast provisioning service.
     * @param  DemoExampleExtensionInstaller  $installer      Idempotent packaging and activation service.
     * @param  ClockInterface                 $clock          Trusted timestamp source for the credentials file.
     *
     * @since  2.0.0
     */
    public function __construct(
        private ApplicationConfiguration $configuration,
        private FilesystemDemoManifestCatalog $catalog,
        private AdministratorIdentityGateway $identities,
        private DemoAccessProvisioner $provisioner,
        private DemoExampleExtensionInstaller $installer,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Name the operators type to complete the demonstration.
     *
     * @return  string  Always `demo:install`.
     *
     * @since   2.0.0
     */
    public function name(): string
    {
        return 'demo:install';
    }

    /**
     * Describe the command for the console's command listing.
     *
     * @return  string  One-line summary naming both halves of the work.
     *
     * @since   2.0.0
     */
    public function description(): string
    {
        return 'core.console.demo_install.description';
    }

    /**
     * Provision the demonstration cast and install the selected examples under one administrator.
     *
     * @param   list<string>  $arguments  Only `--name=value` options: `--admin-email`,
     *          `--admin-password-file`, `--credentials-file`, and optionally `--extensions` as a
     *          comma-separated subset of the shipped examples.
     * @param   Output        $output     Sink for the sequential outcomes or the failure message.
     *
     * @return  int  0 when the cast and every selected example are in place, 1 when any step failed;
     *          an example step's failure line names the example it stopped on.
     *
     * @since   2.0.0
     */
    public function execute(array $arguments, Output $output): int
    {
        try {
            $options = $this->options($arguments);
            $credentialsPath = $this->credentialsPath($this->required($options, 'credentials-file'));
            $selection = $this->selection($options['extensions'] ?? null);
            $password = $this->passwordFromFile($this->required($options, 'admin-password-file'));
            $principal = $this->identities->authenticate(
                $this->required($options, 'admin-email'),
                $password,
                'demo-installation',
            );
            if ($principal === null) {
                throw new InvalidArgumentException('The administrator could not be authenticated.');
            }
            $context = $principal->context(
                SiteContext::fromString($this->configuration->publicSite),
                AuthenticationStrength::Password,
                'demo-install-' . bin2hex(random_bytes(16)),
            );

            $credentialsWritten = false;
            $profile = $this->configuration->businessProfile;
            if ($profile === 'none') {
                $output->message('core.console.demo_install.no_business_demonstration_dataset');
            } else {
                $manifest = $this->catalog->access($profile)['manifest'];
                $report = $this->provisioner->provision($context, $manifest);
                foreach ($report['identities'] as $identity) {
                    $output->message('core.console.demo_install.identity_line', [
                        'created' => $identity['created'],
                        'email' => $identity['email'],
                        'role' => $identity['role'],
                        'area' => $identity['area'],
                        'organization' => $identity['organization'] === null ? '' : ', ' . $identity['organization'],
                        'has_password' => $identity['password'] !== null,
                        'password' => $identity['password'] ?? '',
                    ]);
                }
                foreach ($report['identities'] as $identity) {
                    if ($identity['password'] !== null) {
                        $this->writeCredentials($credentialsPath, $report['identities']);
                        $credentialsWritten = true;
                        break;
                    }
                }
                if ($credentialsWritten) {
                    $output->message('core.console.demo_install.wrote_the_demonstration_credentials_file', [
                        'credentialsPath' => $credentialsPath,
                    ]);
                } else {
                    $output->message('core.console.demo_install.no_new_credentials_were_generated_existing');
                }
            }

            foreach ($selection as $example) {
                try {
                    $result = $this->installer->install($context, $example);
                } catch (Throwable $failure) {
                    $output->failure('core.console.demo_install.example_failed', [
                        'example' => $example,
                        'reason' => $failure->getMessage(),
                    ]);

                    return 1;
                }
                $output->message('core.console.demo_install.example_outcome', [
                    'installed' => $result['installed'],
                    'activated' => $result['activated'],
                    'selectable' => $result['installed'] && !$result['activated'],
                    'identifier' => $result['identifier'],
                    'example' => $example,
                ]);
            }

            $output->message('core.console.demo_install.staff_sign_in_at_administrator_portal');
            $output->message('core.console.demo_install.dataset_installed_by_migrate');

            return 0;
        } catch (Throwable $exception) {
            $output->error($exception->getMessage());

            return 1;
        }
    }

    /**
     * Validate the destination any generated credentials would be written to.
     *
     * Unlike `demo:provision-access`, existence is not refused here: an idempotent re-run passes the
     * same path even though the file may already exist from the first run. The must-not-exist rule
     * still holds at write time, which is the only moment a new password exists to protect.
     *
     * @param   string  $path  Requested credentials file path.
     *
     * @return  string  Validated absolute path below an existing writable directory.
     *
     * @throws  InvalidArgumentException  When the path is relative or has no writable directory.
     *
     * @since   2.0.0
     */
    private function credentialsPath(string $path): string
    {
        if (!str_starts_with($path, '/')) {
            throw new InvalidArgumentException('The credentials file must be an absolute path.');
        }
        if (!is_dir(dirname($path)) || !is_writable(dirname($path))) {
            throw new InvalidArgumentException('The credentials file directory must exist and be writable.');
        }

        return $path;
    }

    /**
     * Write the owner-only credentials file naming every provisioned or confirmed identity.
     *
     * The write keeps the `demo:provision-access` contract: the file must not exist yet, is created
     * exclusively, and is protected to owner-only permissions before the secrets land in it.
     *
     * @param   string  $path  Validated absolute destination path.
     * @param   list<array{
     *              email: string,
     *              display_name: string,
     *              role: string,
     *              area: string,
     *              organization: ?string,
     *              created: bool,
     *              password: ?string
     *          }>  $identities  Provisioning outcomes to persist.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the file already exists, or cannot be created
     *          exclusively or protected.
     *
     * @since   2.0.0
     */
    private function writeCredentials(string $path, array $identities): void
    {
        if (file_exists($path)) {
            throw new InvalidArgumentException(
                'The credentials file already exists; move it away before provisioning again.',
            );
        }
        $handle = @fopen($path, 'x');
        if ($handle === false) {
            throw new InvalidArgumentException('The credentials file could not be created exclusively.');
        }
        if (!chmod($path, 0o600)) {
            fclose($handle);
            throw new InvalidArgumentException('The credentials file could not be protected.');
        }
        $document = [
            'format' => 'kumwe.demo-access-credentials/v1',
            'generated_at' => $this->clock->now()->format(DATE_ATOM),
            'site' => $this->configuration->publicSite,
            'identities' => $identities,
        ];
        $encoded = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        fwrite($handle, $encoded . "\n");
        fclose($handle);
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
     * Parse this command's argument list into an option map keyed by option name.
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
     * Read an option the command cannot run without.
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
     * Read the acting administrator's password out of a file the operator has protected.
     *
     * The same protections as `user:create-admin` apply: the file must be named by an absolute path, be
     * a readable regular file rather than a symlink, and carry no group or other permission bits.
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

        if (!is_int($permissions) || ($permissions & 0o077) !== 0) {
            throw new InvalidArgumentException(
                'The password file must not be readable or writable by group or others.',
            );
        }

        $password = file_get_contents($path);

        if (!is_string($password)) {
            throw new InvalidArgumentException('The password file could not be read.');
        }

        return rtrim($password, "\r\n");
    }
}
