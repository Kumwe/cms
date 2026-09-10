<?php

declare(strict_types=1);

namespace Kumwe\App\Delivery\Console\Command;

use InvalidArgumentException;
use Kumwe\App\Delivery\Console\Command;
use Kumwe\App\Delivery\Console\Output;
use Kumwe\App\Demo\Infrastructure\DemoAccessProvisioner;
use Kumwe\App\Demo\Infrastructure\FilesystemDemoManifestCatalog;
use Kumwe\App\Identity\Application\Administration\AdministratorIdentityGateway;
use Kumwe\App\Kernel\Configuration\ApplicationConfiguration;
use Kumwe\Context\Value\AuthenticationStrength;
use Kumwe\Context\Value\SiteContext;
use Psr\Clock\ClockInterface;
use Throwable;

/**
 * Console entry point that provisions the demonstration staff and portal sign-ins.
 *
 * The command is deliberately double-gated: reaching the console already requires host access, and the
 * work itself runs under a real administrator account whose credentials arrive in a protected password
 * file — never as an argument — so every user, role, grant, and membership it creates carries ordinary
 * administrator provenance through the canonical services. Each newly created account receives a
 * generated password that exists exactly twice: once on this command's output and once in the owner-only
 * credentials file it writes, which is how a reviewer signs in to every demonstrated role without any
 * secret ever entering the repository or a manifest.
 *
 * @since  2.0.0
 */
final readonly class DemoAccessCommand implements Command
{
    /**
     * Wire the demo access pipeline to configuration, manifests, authentication, and provisioning.
     *
     * @param  ApplicationConfiguration       $configuration  Validated process profile selectors.
     * @param  FilesystemDemoManifestCatalog  $catalog        Shipped immutable JSON manifests.
     * @param  AdministratorIdentityGateway   $identities     Password verifier for the acting administrator.
     * @param  DemoAccessProvisioner          $provisioner    Idempotent cast provisioning service.
     * @param  ClockInterface                 $clock          Trusted timestamp source for the credentials file.
     *
     * @since  2.0.0
     */
    public function __construct(
        private ApplicationConfiguration $configuration,
        private FilesystemDemoManifestCatalog $catalog,
        private AdministratorIdentityGateway $identities,
        private DemoAccessProvisioner $provisioner,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Name the operators type to provision demonstration access.
     *
     * @return  string  Always `demo:provision-access`.
     *
     * @since   2.0.0
     */
    public function name(): string
    {
        return 'demo:provision-access';
    }

    /**
     * Describe the command for the console's command listing.
     *
     * @return  string  One-line summary naming the credential outputs.
     *
     * @since   2.0.0
     */
    public function description(): string
    {
        return 'core.console.demo_provision_access.description';
    }

    /**
     * Provision the selected business profile's demonstration cast and write the credentials file.
     *
     * @param   list<string>  $arguments  Only `--name=value` options: `--admin-email`,
     *          `--admin-password-file`, and `--credentials-file`.
     * @param   Output        $output     Sink for the provisioning summary or the failure message.
     *
     * @return  int  0 when every declared identity is provisioned or confirmed, 1 when any step failed.
     *
     * @since   2.0.0
     */
    public function execute(array $arguments, Output $output): int
    {
        try {
            $options = $this->options($arguments);
            $profile = $this->configuration->businessProfile;
            if ($profile === 'none') {
                throw new InvalidArgumentException(
                    'No business demonstration dataset is selected; there is no cast to provision.',
                );
            }
            $manifest = $this->catalog->access($profile)['manifest'];
            $credentialsPath = $this->credentialsPath($this->required($options, 'credentials-file'));
            $password = $this->passwordFromFile($this->required($options, 'admin-password-file'));
            $principal = $this->identities->authenticate(
                $this->required($options, 'admin-email'),
                $password,
                'demo-access-provisioning',
            );
            if ($principal === null) {
                throw new InvalidArgumentException('The administrator could not be authenticated.');
            }
            $context = $principal->context(
                SiteContext::fromString($this->configuration->publicSite),
                AuthenticationStrength::Password,
                'demo-access-' . bin2hex(random_bytes(16)),
            );
            $report = $this->provisioner->provision($context, $manifest);
            $this->writeCredentials($credentialsPath, $report['identities']);
            foreach ($report['identities'] as $identity) {
                $output->message('core.console.demo_provision_access.identity_line', [
                    'created' => $identity['created'],
                    'email' => $identity['email'],
                    'role' => $identity['role'],
                    'area' => $identity['area'],
                    'organization' => $identity['organization'] === null ? '' : ', ' . $identity['organization'],
                    'has_password' => $identity['password'] !== null,
                    'password' => $identity['password'] ?? '',
                ]);
            }
            $output->message('core.console.demo_provision_access.wrote_the_demonstration_credentials_file', [
                'credentialsPath' => $credentialsPath,
            ]);

            return 0;
        } catch (Throwable $exception) {
            $output->error($exception->getMessage());

            return 1;
        }
    }

    /**
     * Validate the destination the generated credentials will be written to.
     *
     * @param   string  $path  Requested credentials file path.
     *
     * @return  string  Validated absolute path that does not exist yet.
     *
     * @throws  InvalidArgumentException  When the path is relative, already exists, or has no directory.
     *
     * @since   2.0.0
     */
    private function credentialsPath(string $path): string
    {
        if (!str_starts_with($path, '/')) {
            throw new InvalidArgumentException('The credentials file must be an absolute path.');
        }
        if (file_exists($path)) {
            throw new InvalidArgumentException(
                'The credentials file already exists; move it away before provisioning again.',
            );
        }
        if (!is_dir(dirname($path)) || !is_writable(dirname($path))) {
            throw new InvalidArgumentException('The credentials file directory must exist and be writable.');
        }

        return $path;
    }

    /**
     * Write the owner-only credentials file naming every provisioned or confirmed identity.
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
     * @throws  InvalidArgumentException  When the file cannot be created exclusively or protected.
     *
     * @since   2.0.0
     */
    private function writeCredentials(string $path, array $identities): void
    {
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
     * Read an option the command cannot provision without.
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
