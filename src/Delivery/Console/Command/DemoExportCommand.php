<?php

declare(strict_types=1);

namespace Kumwe\App\Delivery\Console\Command;

use InvalidArgumentException;
use Kumwe\App\Delivery\Console\Command;
use Kumwe\App\Delivery\Console\Output;
use Kumwe\App\Demo\Infrastructure\DemoBusinessProfileExporter;
use Kumwe\App\Demo\Infrastructure\DemoProfileExporter;
use Kumwe\App\Demo\Infrastructure\FilesystemDemoManifestCatalog;
use Kumwe\App\Identity\Application\Administration\AdministratorIdentityGateway;
use Kumwe\App\Kernel\Configuration\ApplicationConfiguration;
use Kumwe\Context\Value\AuthenticationStrength;
use Kumwe\Context\Value\SiteContext;
use RuntimeException;
use Throwable;

/**
 * Console entry point that exports the running system into an installable demo-profile package.
 *
 * Export is deliberately a host-only operation: it exists on the console alone — never on the HTTP API
 * or the MCP surface — and it still demands a real administrator whose password arrives in a protected
 * file, so producing a portable copy of the site requires both host access and administrator standing.
 * The package mirrors the repository's `resources/demo` layout, is validated through the same catalog
 * that guards release manifests before the command reports success, and ships an `export.json` integrity
 * index of canonical checksums so recipients can verify what they received. Profiles never carry
 * credential material; accounts on a target installation are provisioned separately with freshly
 * generated passwords through `demo:provision-access`.
 *
 * @since  2.0.0
 */
final readonly class DemoExportCommand implements Command
{
    /**
     * Wire the export pipeline to configuration, authentication, and the manifest projectors.
     *
     * @param  ApplicationConfiguration      $configuration  Validated process profile selectors.
     * @param  AdministratorIdentityGateway  $identities     Password verifier for the acting administrator.
     * @param  DemoProfileExporter           $exporter       Site-content-to-manifest projector.
     * @param  DemoBusinessProfileExporter   $business       Business-dataset-to-manifest projector.
     *
     * @since  2.0.0
     */
    public function __construct(
        private ApplicationConfiguration $configuration,
        private AdministratorIdentityGateway $identities,
        private DemoProfileExporter $exporter,
        private DemoBusinessProfileExporter $business,
    ) {
    }

    /**
     * Name the operators type to export the running system.
     *
     * @return  string  Always `demo:export-profile`.
     *
     * @since   2.0.0
     */
    public function name(): string
    {
        return 'demo:export-profile';
    }

    /**
     * Describe the command for the console's command listing.
     *
     * @return  string  One-line summary naming the package output.
     *
     * @since   2.0.0
     */
    public function description(): string
    {
        return 'core.console.demo_export_profile.description';
    }

    /**
     * Export the site-content, business, and access datasets into one validated package.
     *
     * @param   list<string>  $arguments  Only `--name=value` options: `--admin-email`,
     *          `--admin-password-file`, `--profile`, and `--output`.
     * @param   Output        $output     Sink for the export summary or the failure message.
     *
     * @return  int  0 when the package was written and re-validated, 1 when any step failed.
     *
     * @since   2.0.0
     */
    public function execute(array $arguments, Output $output): int
    {
        try {
            $options = $this->options($arguments);
            $profile = $this->required($options, 'profile');
            $directory = $this->required($options, 'output');
            $password = $this->passwordFromFile($this->required($options, 'admin-password-file'));
            $principal = $this->identities->authenticate(
                $this->required($options, 'admin-email'),
                $password,
                'demo-profile-export',
            );
            if ($principal === null) {
                throw new InvalidArgumentException('The administrator could not be authenticated.');
            }
            $context = $principal->context(
                SiteContext::fromString($this->configuration->publicSite),
                AuthenticationStrength::Password,
                'demo-export-' . bin2hex(random_bytes(16)),
            );
            $manifest = $this->exporter->contentManifest($context, $profile);
            $business = $this->business->documents($context, $profile);
            $documents = [sprintf('content/%s.json', $profile) => $manifest];
            $exportsBusiness = $business['profile'] !== [];
            if ($exportsBusiness) {
                $documents[sprintf('business/%s/profile.json', $profile)] = $business['profile'];
                foreach ($business['definitions'] as $relative => $document) {
                    $documents[sprintf('business/%s/%s', $profile, $relative)] = $document;
                }
                $documents[sprintf('business/%s/records.json', $profile)] = $business['records'];
                if ($business['access'] !== []) {
                    $documents[sprintf('business/%s/access.json', $profile)] = $business['access'];
                }
            }
            $this->refuseBeyondEnvelope($business);
            $checksums = $this->exporter->writePackage($directory, $profile, $documents);
            $catalog = new FilesystemDemoManifestCatalog($directory);
            $verified = $catalog->content($profile);
            $content = $manifest['content'];
            $menus = $manifest['menus'];
            $output->message('core.console.demo_export_profile.exported_content_entries_and_menus_as', [
                'entries' => is_array($content) ? count($content) : 0,
                'menus' => is_array($menus) ? count($menus) : 0,
                'profile' => $profile,
            ]);
            $this->reportBusiness($output, $catalog, $profile, $business, $exportsBusiness);
            foreach ($checksums as $relative => $checksum) {
                $output->line(sprintf('%s %s', $checksum, $relative));
            }
            $output->message(
                'core.console.demo_export_profile.catalog_re_validation_checksum',
                ['checksum' => $verified['checksum']],
            );
            $output->message('core.console.demo_export_profile.copy_resources_demo_over_an_installation', [
                'directory' => $directory,
            ]);

            return 0;
        } catch (Throwable $exception) {
            $output->error($exception->getMessage());

            return 1;
        }
    }

    /**
     * Refuse an installation the demo-profile envelope cannot carry, before anything is written.
     *
     * The catalogue and the installer both bound what a business profile may declare, and the projector
     * bounds nothing: it answers with every published definition the site owns. An installation past the
     * bound therefore used to be written to disk in full and refused by this command's own re-validation
     * on the next line — the operator got a package, a non-zero status, and `definition order is invalid`
     * to explain it. Asking the question here means nothing is written and the message names the actual
     * count and the actual bound.
     *
     * @param   array{
     *              profile: array<string, mixed>,
     *              definitions: array<string, array<string, mixed>>,
     *              records: array<string, mixed>,
     *              access: array<string, mixed>,
     *              withheld_identities: int
     *          }  $business  Documents the business exporter produced.
     *
     * @return  void
     *
     * @throws  RuntimeException  When the projected documents exceed a declared envelope bound.
     *
     * @since   2.0.0
     */
    private function refuseBeyondEnvelope(array $business): void
    {
        $order = $business['profile']['installation_order'] ?? [];
        if (is_array($order) && count($order) > FilesystemDemoManifestCatalog::MAXIMUM_INSTALLATION_ORDER) {
            throw new RuntimeException(sprintf(
                'The site publishes %d business definitions, which exceeds the demo-profile envelope '
                    . 'of %d; nothing was written.',
                count($order),
                FilesystemDemoManifestCatalog::MAXIMUM_INSTALLATION_ORDER,
            ));
        }
        $roles = $business['access']['roles'] ?? [];
        if (is_array($roles) && count($roles) > FilesystemDemoManifestCatalog::MAXIMUM_ROLES) {
            throw new RuntimeException(sprintf(
                'The exported identities reference %d roles, which exceeds the demo-profile envelope '
                    . 'of %d; nothing was written.',
                count($roles),
                FilesystemDemoManifestCatalog::MAXIMUM_ROLES,
            ));
        }
    }

    /**
     * Report the exported business dataset and access cast, re-validating both through the catalog.
     *
     * @param   Output                         $output           Sink for the export summary lines.
     * @param   FilesystemDemoManifestCatalog  $catalog          Catalog bound to the written package.
     * @param   string                         $profile          Profile name the package was written under.
     * @param   array{
     *              profile: array<string, mixed>,
     *              definitions: array<string, array<string, mixed>>,
     *              records: array<string, mixed>,
     *              access: array<string, mixed>,
     *              withheld_identities: int
     *          }                              $business         Documents the business exporter produced.
     * @param   bool                           $exportsBusiness  Whether business documents were written.
     *
     * @return  void
     *
     * @throws  \RuntimeException  When the written package fails the catalog's re-validation.
     *
     * @since   2.0.0
     */
    private function reportBusiness(
        Output $output,
        FilesystemDemoManifestCatalog $catalog,
        string $profile,
        array $business,
        bool $exportsBusiness,
    ): void {
        if (!$exportsBusiness) {
            $output->message('core.console.demo_export_profile.no_published_site_owned_business_definitions');

            return;
        }
        $verified = $catalog->business($profile);
        $expected = $business['records']['expected'] ?? null;
        $counts = is_array($expected) ? $expected : [];
        $output->message('core.console.demo_export_profile.exported_business_definitions_records_relations', [
            'definitions' => count($business['definitions']),
            'records' => $this->count($counts, 'record_count'),
            'relations' => $this->count($counts, 'relation_count'),
            'actions' => $this->count($counts, 'action_count'),
            'archives' => $this->count($counts, 'archive_count'),
        ]);
        $output->message(
            'core.console.demo_export_profile.business_catalog_re_validation_checksum',
            ['checksum' => $verified['checksum']],
        );
        if ($business['access'] !== []) {
            $access = $catalog->access($profile);
            $output->message('core.console.demo_export_profile.exported_the_demonstration_access_cast_with', [
                'roles' => $this->count($business['access'], 'roles'),
                'staff' => $this->count($business['access'], 'staff'),
                'organizations' => $this->count($business['access'], 'organizations'),
            ]);
            $output->message(
                'core.console.demo_export_profile.access_catalog_re_validation_checksum',
                ['checksum' => $access['checksum']],
            );
        } else {
            $output->message('core.console.demo_export_profile.no_example_identities_qualified_access_json');
        }
        $output->message(
            'core.console.demo_export_profile.withheld_identities_outside_the_reserved_example',
            ['identities' => $business['withheld_identities']],
        );
    }

    /**
     * Read one count out of an exported document member that may be an integer or a list.
     *
     * @param   array<array-key, mixed>  $document  Document or count map to read from.
     * @param   string                   $key       Member holding the count or the counted list.
     *
     * @return  int  The integer itself, the list's size, or zero for anything else.
     *
     * @since   2.0.0
     */
    private function count(array $document, string $key): int
    {
        $value = $document[$key] ?? null;
        if (is_int($value)) {
            return $value;
        }

        return is_array($value) ? count($value) : 0;
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
     * Read an option the command cannot export without.
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
