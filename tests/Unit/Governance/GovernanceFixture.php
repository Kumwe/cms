<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Governance;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * Copies the clean governance fixture into a scratch root and mutates it for the negative cases.
 *
 * The committed fixture under `tests/Fixtures/Governance/clean` always passes both governance gates. A test that
 * needs a broken tree copies the fixture into `sys_get_temp_dir()`, changes exactly one thing, runs the tool
 * against the copy, and removes it again in `finally`; no broken fixture is ever committed.
 *
 * @since  2.0.0
 */
final readonly class GovernanceFixture
{
    /**
     * The factories block of the fixture service map, as written in the file, for tests that empty it.
     *
     * @var    string
     * @since  2.0.0
     */
    public const FACTORY_BLOCK = "\"factories\": [\n    {\n      \"service\": \"Kumwe\\\\Example\\\\ExampleService\",\n"
        . "      \"factory\": \"Kumwe\\\\Example\\\\Container\\\\ExampleServiceFactory\",\n"
        . "      \"lifetime\": \"shared\"\n    }\n  ]";

    /**
     * The repository root.
     *
     * @return  string  Absolute path.
     *
     * @since   2.0.0
     */
    public static function repositoryRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    /**
     * The committed clean fixture root.
     *
     * @return  string  Absolute path.
     *
     * @since   2.0.0
     */
    public static function cleanRoot(): string
    {
        return self::repositoryRoot() . '/tests/Fixtures/Governance/clean';
    }

    /**
     * The governance schema directory of the repository.
     *
     * @return  string  Absolute path.
     *
     * @since   2.0.0
     */
    public static function schemaDirectory(): string
    {
        return self::repositoryRoot() . '/docs/architecture/governance/schemas';
    }

    /**
     * Copy the clean fixture into a fresh scratch root.
     *
     * @return  string  Absolute path of the copy.
     *
     * @throws  RuntimeException  When the copy cannot be created.
     *
     * @since   2.0.0
     */
    public static function copy(): string
    {
        $target = sys_get_temp_dir() . '/kumwe-governance-' . bin2hex(random_bytes(8));
        self::copyTree(self::cleanRoot(), $target);

        return $target;
    }

    /**
     * Copy one directory tree.
     *
     * @param   string  $source  Existing directory.
     * @param   string  $target  Directory to create.
     *
     * @return  void
     *
     * @throws  RuntimeException  When a directory or file cannot be created.
     *
     * @since   2.0.0
     */
    public static function copyTree(string $source, string $target): void
    {
        if (!mkdir($target, 0o700, true) && !is_dir($target)) {
            throw new RuntimeException('The scratch fixture directory could not be created.');
        }
        /** @var iterable<string, SplFileInfo> $entries */
        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($entries as $entry) {
            $relative = substr($entry->getPathname(), strlen($source) + 1);
            $destination = $target . '/' . $relative;
            if ($entry->isDir()) {
                if (!mkdir($destination, 0o700, true) && !is_dir($destination)) {
                    throw new RuntimeException('The scratch fixture directory could not be created.');
                }
                continue;
            }
            if (!copy($entry->getPathname(), $destination)) {
                throw new RuntimeException('The scratch fixture file could not be copied.');
            }
        }
    }

    /**
     * Remove a scratch root.
     *
     * @param   string  $directory  Absolute path created by `copy()`.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public static function remove(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        /** @var iterable<string, SplFileInfo> $entries */
        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($entries as $entry) {
            $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($directory);
    }

    /**
     * Run `tools/generate-capability-index.php` with the given arguments.
     *
     * @param   list<string>  $arguments  Arguments such as `--check` and `--root=PATH`.
     *
     * @return  array{status: int, output: string}  Exit status and combined output.
     *
     * @since   2.0.0
     */
    public static function run(array $arguments): array
    {
        $command = sprintf(
            '%s %s',
            escapeshellarg(PHP_BINARY),
            escapeshellarg(self::repositoryRoot() . '/tools/generate-capability-index.php'),
        );
        foreach ($arguments as $argument) {
            $command .= ' ' . escapeshellarg($argument);
        }
        $lines = [];
        $status = 0;
        exec($command . ' 2>&1', $lines, $status);

        return ['status' => $status, 'output' => implode("\n", $lines)];
    }

    /**
     * Read one file of a scratch root.
     *
     * @param   string  $root      Scratch root.
     * @param   string  $relative  Root-relative path.
     *
     * @return  string  File bytes.
     *
     * @throws  RuntimeException  When the file cannot be read.
     *
     * @since   2.0.0
     */
    public static function read(string $root, string $relative): string
    {
        $bytes = file_get_contents($root . '/' . $relative);
        if (!is_string($bytes)) {
            throw new RuntimeException(sprintf('The fixture file %s cannot be read.', $relative));
        }

        return $bytes;
    }

    /**
     * Write one file of a scratch root, creating directories as needed.
     *
     * @param   string  $root      Scratch root.
     * @param   string  $relative  Root-relative path.
     * @param   string  $contents  Complete file bytes.
     *
     * @return  void
     *
     * @throws  RuntimeException  When the file cannot be written.
     *
     * @since   2.0.0
     */
    public static function write(string $root, string $relative, string $contents): void
    {
        $path = $root . '/' . $relative;
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0o700, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('The fixture directory for %s cannot be created.', $relative));
        }
        if (file_put_contents($path, $contents) === false) {
            throw new RuntimeException(sprintf('The fixture file %s cannot be written.', $relative));
        }
    }

    /**
     * Replace one occurrence of a string inside a scratch-root file.
     *
     * @param   string  $root      Scratch root.
     * @param   string  $relative  Root-relative path.
     * @param   string  $search    Text that must occur exactly once.
     * @param   string  $replace   Replacement.
     *
     * @return  void
     *
     * @throws  RuntimeException  When the text does not occur exactly once.
     *
     * @since   2.0.0
     */
    public static function replace(string $root, string $relative, string $search, string $replace): void
    {
        $bytes = self::read($root, $relative);
        if (substr_count($bytes, $search) !== 1) {
            throw new RuntimeException(sprintf('"%s" must occur exactly once in %s.', $search, $relative));
        }
        self::write($root, $relative, str_replace($search, $replace, $bytes));
    }

    /**
     * Delete a file or directory tree inside a scratch root.
     *
     * @param   string  $root      Scratch root.
     * @param   string  $relative  Root-relative path.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public static function delete(string $root, string $relative): void
    {
        $path = $root . '/' . $relative;
        if (is_dir($path)) {
            self::remove($path);

            return;
        }
        if (is_file($path)) {
            unlink($path);
        }
    }

    /**
     * Digest the bytes of a scratch-root file.
     *
     * @param   string  $root      Scratch root.
     * @param   string  $relative  Root-relative path.
     *
     * @return  string  Lowercase hexadecimal SHA-256.
     *
     * @since   2.0.0
     */
    public static function digest(string $root, string $relative): string
    {
        return hash('sha256', self::read($root, $relative));
    }

    /**
     * Re-record the manifest digests in a Version 2 package's handoff and the handoff digest in its ledger record.
     *
     * A test that changes a manifest or the handoff itself uses this so the change reaches the rule under test
     * instead of tripping the digest guards every handoff carries.
     *
     * @param   string  $root         Scratch root.
     * @param   string  $short        Package short name, such as `example-v2`.
     * @param   string  $migrationId  Identifier of the ledger record that adopted the package.
     *
     * @return  void
     *
     * @throws  RuntimeException  When a file cannot be read or written.
     *
     * @since   2.0.0
     */
    public static function reseal(string $root, string $short, string $migrationId): void
    {
        $package = 'vendor/kumwe/' . $short;
        $handoff = self::read($root, $package . '/MIGRATION-HANDOFF.md');
        $manifests = [
            'resources/public-api/v1.json',
            'resources/capabilities/v1.json',
            'resources/service-map/v1.json',
        ];
        foreach ($manifests as $manifest) {
            $handoff = (string) preg_replace(
                '/(- path: ' . preg_quote($manifest, '/') . '\n\s+sha256: ")[a-f0-9]{64}(")/',
                '${1}' . self::digest($root, $package . '/' . $manifest) . '${2}',
                $handoff,
            );
        }
        self::write($root, $package . '/MIGRATION-HANDOFF.md', $handoff);
        $ledgerPath = 'docs/architecture/migrations/' . $migrationId . '.yaml';
        $ledger = (string) preg_replace(
            '/handoff_sha256: "[a-f0-9]{64}"/',
            'handoff_sha256: "' . self::digest($root, $package . '/MIGRATION-HANDOFF.md') . '"',
            self::read($root, $ledgerPath),
        );
        self::write($root, $ledgerPath, $ledger);
    }

    /**
     * Replace the fixture package's legacy handoff with a production record and update its adoption digest.
     *
     * @param   string  $root  Scratch root.
     * @param   bool    $json  Whether to encode the contract as JSON-compatible YAML.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public static function useProductionRecord(string $root, bool $json = false): void
    {
        $package = 'vendor/kumwe/example-v2/';
        $path = $package . 'docs/release-record.md';
        $bytes = self::read(self::repositoryRoot(), 'tests/Fixtures/Governance/package-release-record.v1.example.md');
        if ($json) {
            $parsed = \Kumwe\App\Tools\Governance\StrictYaml::parseFrontMatter($bytes);
            $bytes = "---\n" . json_encode($parsed['front_matter'], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)
                . "\n---\n" . $parsed['body'];
        }
        self::write($root, $path, $bytes);
        self::delete($root, $package . 'MIGRATION-HANDOFF.md');
        $ledger = 'docs/architecture/migrations/KUMWE-MIG-2026-001.yaml';
        self::replace($root, $ledger, $package . 'MIGRATION-HANDOFF.md', $path);
        $record = (string) preg_replace(
            '/handoff_sha256: "[a-f0-9]{64}"/',
            'handoff_sha256: "' . self::digest($root, $path) . '"',
            self::read($root, $ledger),
        );
        self::write($root, $ledger, $record);
    }

    /**
     * Add a second Version 2 package, `kumwe/example-v3`, cloned from `kumwe/example-v2` with its own namespace.
     *
     * The clone keeps the same capability id and configuration key as the original so a test can prove the
     * duplicate-owner rules; it carries its own ledger record, change set and attestation so it is otherwise clean.
     *
     * @param   string  $root  Scratch root.
     *
     * @return  void
     *
     * @throws  RuntimeException  When a file cannot be read or written.
     *
     * @since   2.0.0
     */
    public static function cloneVersion2Package(string $root): void
    {
        $replacements = [
            'kumwe/example-v2' => 'kumwe/example-v3',
            'Kumwe\\\\Example\\\\' => 'Kumwe\\\\ExampleThree\\\\',
            'Kumwe\\Example\\' => 'Kumwe\\ExampleThree\\',
            'Kumwe\\Example' => 'Kumwe\\ExampleThree',
            'KUMWE-MIG-2026-001' => 'KUMWE-MIG-2026-002',
            'KUMWE-CS-2026-001' => 'KUMWE-CS-2026-002',
        ];
        $rewrite = static fn (string $bytes): string => str_replace(
            array_keys($replacements),
            array_values($replacements),
            $bytes,
        );

        self::copyTree($root . '/vendor/kumwe/example-v2', $root . '/vendor/kumwe/example-v3');
        /** @var iterable<string, SplFileInfo> $entries */
        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root . '/vendor/kumwe/example-v3', FilesystemIterator::SKIP_DOTS),
        );
        foreach ($entries as $entry) {
            if ($entry->isFile()) {
                $relative = substr($entry->getPathname(), strlen($root) + 1);
                self::write($root, $relative, $rewrite(self::read($root, $relative)));
            }
        }
        self::write(
            $root,
            'docs/architecture/migrations/KUMWE-MIG-2026-002.yaml',
            $rewrite(self::read($root, 'docs/architecture/migrations/KUMWE-MIG-2026-001.yaml')),
        );
        self::reseal($root, 'example-v3', 'KUMWE-MIG-2026-002');
        self::write(
            $root,
            'docs/architecture/migrations/change-sets/KUMWE-CS-2026-002.yaml',
            $rewrite(self::read($root, 'docs/architecture/migrations/change-sets/KUMWE-CS-2026-001.yaml')),
        );
        self::write(
            $root,
            'docs/architecture/migrations/evidence/KUMWE-MIG-2026-002/RELEASE-ATTESTATION.yaml',
            $rewrite(self::read(
                $root,
                'docs/architecture/migrations/evidence/KUMWE-MIG-2026-001/RELEASE-ATTESTATION.yaml',
            )),
        );

        /** @var array{packages: list<array<string, mixed>>} $lock */
        $lock = json_decode(self::read($root, 'composer.lock'), true, 512, JSON_THROW_ON_ERROR);
        foreach ($lock['packages'] as $entry) {
            if ($entry['name'] === 'kumwe/example-v2') {
                /** @var array<string, mixed> $clone */
                $clone = json_decode(
                    $rewrite(json_encode($entry, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
                    true,
                    512,
                    JSON_THROW_ON_ERROR,
                );
                $lock['packages'][] = $clone;
            }
        }
        self::write(
            $root,
            'composer.lock',
            json_encode($lock, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
        );
    }
}
