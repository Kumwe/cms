<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessReporting\Infrastructure;

use JsonException;
use Kumwe\Transaction\Contract\TransactionManager;
use Kumwe\App\BusinessDefinition\Domain\CanonicalDefinitionJson;
use Kumwe\App\BusinessReporting\Application\ExportArtifactRepository;
use Kumwe\App\BusinessReporting\Application\ExportVersionConflict;
use Kumwe\App\BusinessReporting\Domain\ExportArtifact;
use RuntimeException;

/**
 * Private append-only filesystem repository for export metadata versions.
 *
 * @since  2.0.0
 */
final readonly class FilesystemExportArtifactRepository implements ExportArtifactRepository
{
    /**
     * Prepare a private absolute metadata directory.
     *
     * @param   string              $directory     Absolute non-public storage directory.
     * @param   TransactionManager  $transactions  Database transaction whose rollback removes a new version.
     *
     * @throws  RuntimeException  When the directory is unsafe or cannot be made private.
     *
     * @since   2.0.0
     */
    public function __construct(
        private string $directory,
        private TransactionManager $transactions,
    ) {
        if (!str_starts_with($directory, DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('The export metadata directory must be absolute.');
        }
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('The export metadata directory cannot be created.');
        }
        if (is_link($directory) || chmod($directory, 0700) === false) {
            throw new RuntimeException('The export metadata directory is unsafe.');
        }
    }

    /**
     * Append metadata version one without overwriting an existing request.
     *
     * @param   ExportArtifact  $artifact  New queued artifact.
     *
     * @return  void
     *
     * @throws  ExportVersionConflict  When the id or version already exists.
     *
     * @since   2.0.0
     */
    public function add(ExportArtifact $artifact): void
    {
        if ($artifact->version !== 1) {
            throw new ExportVersionConflict('A new export artifact must start at version one.');
        }
        $this->locked($artifact->id, function () use ($artifact): void {
            if ($this->findUnlocked($artifact->id) !== null) {
                throw new ExportVersionConflict('The export artifact already exists.');
            }
            $this->append($artifact);
        });
    }

    /**
     * Read the highest immutable metadata version.
     *
     * @param   string  $id  Artifact UUID.
     *
     * @return  ?ExportArtifact  Current state or null.
     *
     * @since   2.0.0
     */
    public function find(string $id): ?ExportArtifact
    {
        return $this->locked($id, fn (): ?ExportArtifact => $this->findUnlocked($id), LOCK_SH);
    }

    /**
     * Append the next metadata version after an optimistic check.
     *
     * @param   ExportArtifact  $artifact         New immutable state.
     * @param   int             $expectedVersion  Previously read version.
     *
     * @return  void
     *
     * @throws  ExportVersionConflict  When current state or next version differs.
     *
     * @since   2.0.0
     */
    public function save(ExportArtifact $artifact, int $expectedVersion): void
    {
        $this->locked($artifact->id, function () use ($artifact, $expectedVersion): void {
            $current = $this->findUnlocked($artifact->id);
            if (
                $current === null || $current->version !== $expectedVersion
                || $artifact->version !== $expectedVersion + 1
            ) {
                throw new ExportVersionConflict('The export artifact changed concurrently.');
            }
            $this->append($artifact);
        });
    }

    /**
     * Load export metadata without acquiring a repository lock.
     *
     * @param   string  $id  Stable identifier of the durable record being addressed.
     *
     * @return  ?ExportArtifact
     *
     * @since   2.0.0
     */
    private function findUnlocked(string $id): ?ExportArtifact
    {
        $this->assertId($id);
        for ($version = 16; $version >= 1; --$version) {
            $path = $this->path($id, $version);
            if (!file_exists($path)) {
                continue;
            }
            if (is_link($path) || !is_file($path) || filesize($path) === false || filesize($path) > 1_048_576) {
                throw new RuntimeException('Export metadata storage integrity failed.');
            }
            $json = file_get_contents($path);
            if ($json === false) {
                throw new RuntimeException('Export metadata cannot be read.');
            }
            try {
                $document = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new RuntimeException('Export metadata is not valid JSON.', 0, $exception);
            }
            if (!is_array($document) || array_is_list($document)) {
                throw new RuntimeException('Export metadata is not an object.');
            }
            /** @var array<string, mixed> $document */
            $artifact = ExportArtifact::fromArray($document);
            if ($artifact->id !== $id || $artifact->version !== $version) {
                throw new RuntimeException('Export metadata identity does not match its immutable file.');
            }

            return $artifact;
        }

        return null;
    }

    /**
     * Append the supplied item to durable storage.
     *
     * @param   ExportArtifact  $artifact  Immutable export artifact being transitioned.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function append(ExportArtifact $artifact): void
    {
        $path = $this->path($artifact->id, $artifact->version);
        if (file_exists($path) || is_link($path)) {
            throw new ExportVersionConflict('The export metadata version already exists.');
        }
        $temporary = $path . '.' . bin2hex(random_bytes(8)) . '.tmp';
        $stream = fopen($temporary, 'x+b');
        if ($stream === false) {
            throw new RuntimeException('A temporary export metadata file cannot be created.');
        }
        try {
            chmod($temporary, 0600);
            $json = CanonicalDefinitionJson::encode($artifact->toArray()) . "\n";
            $this->write($stream, $json);
            fflush($stream);
            if (function_exists('fsync')) {
                fsync($stream);
            }
        } finally {
            fclose($stream);
        }
        if (!link($temporary, $path)) {
            @unlink($temporary);
            throw new ExportVersionConflict('The export metadata version could not be published.');
        }
        @unlink($temporary);
        chmod($path, 0600);
        $this->transactions->afterRollback(static function () use ($path): void {
            if (!file_exists($path) && !is_link($path)) {
                return;
            }
            if (is_link($path) || !is_file($path) || !unlink($path)) {
                throw new RuntimeException('A rolled-back export metadata version could not be removed safely.');
            }
        });
    }

    /**
     * Execute the callback while holding the artifact lock.
     *
     * @template T
     *
     * @param   string           $id         Stable identifier of the durable record being addressed.
     * @param   callable(): T    $operation  Repository operation executed while the artifact lock is held.
     * @param   LOCK_SH|LOCK_EX  $mode       Filesystem lock mode required by the repository operation.
     *
     * @return  T
     *
     * @since   2.0.0
     */
    private function locked(string $id, callable $operation, int $mode = LOCK_EX): mixed
    {
        $this->assertId($id);
        $lockPath = $this->directory . DIRECTORY_SEPARATOR . strtolower($id) . '.lock';
        if (is_link($lockPath)) {
            throw new RuntimeException('An export metadata lock path is unsafe.');
        }
        $lock = fopen($lockPath, 'c+b');
        if ($lock === false || !flock($lock, $mode)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            throw new RuntimeException('Export metadata cannot be locked.');
        }
        chmod($lockPath, 0600);
        try {
            return $operation();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Resolve the confined filesystem path for the supplied identifier.
     *
     * @param   string  $id       Stable identifier of the durable record being addressed.
     * @param   int     $version  Exact schema or optimistic-lock version to test.
     *
     * @return  string  Confined absolute path for the requested export artifact.
     *
     * @since   2.0.0
     */
    private function path(string $id, int $version): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . strtolower($id) . '.v' . $version . '.json';
    }

    /**
     * Validate an export artifact identifier before path resolution.
     *
     * @param   string  $id  Stable identifier of the durable record being addressed.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function assertId(string $id): void
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $id) !== 1) {
            throw new RuntimeException('An export metadata id is invalid.');
        }
    }

    /**
     * Write bytes completely to an already opened artifact stream.
     *
     * @param   resource  $stream  Opened artifact stream that receives all bytes.
     * @param   string    $bytes   Complete artifact bytes to write.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function write(mixed $stream, string $bytes): void
    {
        while ($bytes !== '') {
            $written = fwrite($stream, $bytes);
            if ($written === false || $written === 0) {
                throw new RuntimeException('Export metadata could not be written.');
            }
            $bytes = substr($bytes, $written);
        }
    }
}
