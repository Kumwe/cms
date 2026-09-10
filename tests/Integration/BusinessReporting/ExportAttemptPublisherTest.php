<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\BusinessReporting;

use Closure;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Kumwe\App\BusinessReporting\Application\ExportArtifactStorage;
use Kumwe\App\BusinessReporting\Application\ExportAttemptPublisher;
use Kumwe\App\BusinessReporting\Application\StoredExportArtifact;
use Kumwe\App\BusinessReporting\Domain\ExportArtifact;
use Kumwe\App\BusinessReporting\Domain\ExportArtifactStatus;
use Kumwe\App\BusinessReporting\Infrastructure\DoctrineExportArtifactRepository;
use Kumwe\App\BusinessReporting\Infrastructure\FilesystemExportArtifactStorage;
use Kumwe\App\Infrastructure\Persistence\DoctrineTransactionManager;
use Kumwe\App\Infrastructure\Persistence\Migration\BusinessIntegrationSdkMigration;
use Kumwe\App\Infrastructure\Persistence\Migration\CoreSchemaMigration;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\Context\Value\AuthenticatedSurface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use RuntimeException;

#[CoversClass(ExportAttemptPublisher::class)]
#[CoversClass(FilesystemExportArtifactStorage::class)]
/**
 * Proves concurrent and failed export generation attempts cannot damage published private bytes.
 *
 * @since  2.0.0
 */
final class ExportAttemptPublisherTest extends TestCase
{
    /**
     * Isolated private object directory for one test.
     *
     * @var    string
     * @since  2.0.0
     */
    private string $directory;

    /**
     * In-memory relational metadata store.
     *
     * @var    Connection
     * @since  2.0.0
     */
    private Connection $database;

    /**
     * Shared metadata and audit transaction owner.
     *
     * @var    DoctrineTransactionManager
     * @since  2.0.0
     */
    private DoctrineTransactionManager $transactions;

    /**
     * Append-only compare-and-set export ledger.
     *
     * @var    DoctrineExportArtifactRepository
     * @since  2.0.0
     */
    private DoctrineExportArtifactRepository $artifacts;

    /**
     * Deterministically interleaved private object storage.
     *
     * @var    InterleavingExportArtifactStorage
     * @since  2.0.0
     */
    private InterleavingExportArtifactStorage $storage;

    /**
     * Attempt-fenced publisher under test.
     *
     * @var    ExportAttemptPublisher
     * @since  2.0.0
     */
    private ExportAttemptPublisher $publisher;

    /**
     * Prepare a migrated metadata ledger and isolated immutable object store.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/kumwe-export-race-' . bin2hex(random_bytes(8));
        $this->database = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $tables = new TableNames($this->database, 'kumwe_');
        $this->transactions = new DoctrineTransactionManager($this->database);
        $this->artifacts = new DoctrineExportArtifactRepository($this->database, $tables, $this->transactions);
        (new CoreSchemaMigration($tables))->up($this->database);
        (new BusinessIntegrationSdkMigration($tables))->up($this->database);
        $this->storage = new InterleavingExportArtifactStorage(
            new FilesystemExportArtifactStorage($this->directory, 1024),
        );
        $this->publisher = new ExportAttemptPublisher(
            $this->artifacts,
            $this->storage,
            $this->transactions,
        );
    }

    /**
     * Close persistence and remove each isolated private object.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    protected function tearDown(): void
    {
        $this->database->close();
        if (!is_dir($this->directory)) {
            return;
        }
        foreach (scandir($this->directory) ?: [] as $name) {
            if ($name !== '.' && $name !== '..') {
                unlink($this->directory . DIRECTORY_SEPARATOR . $name);
            }
        }
        rmdir($this->directory);
    }

    /**
     * Prove worker A's losing cleanup cannot delete worker B's completed object.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testConcurrentWinnerCannotBeDeletedByTheLosingAttempt(): void
    {
        $running = $this->runningArtifact();
        $winner = null;
        $completedAudits = [];
        $this->storage->interleaveOnce(function () use ($running, &$winner, &$completedAudits): void {
            $winner = $this->publisher->publish(
                $running,
                ["worker-b\n"],
                new DateTimeImmutable('2026-08-10T10:02:00+00:00'),
                1,
                str_repeat('e', 64),
                static function (ExportArtifact $completed) use (&$completedAudits): void {
                    $completedAudits[] = $completed->storageKey;
                },
            );
        });

        $observed = $this->publisher->publish(
            $running,
            ["worker-a\n"],
            new DateTimeImmutable('2026-08-10T10:03:00+00:00'),
            1,
            str_repeat('f', 64),
            static function (ExportArtifact $completed) use (&$completedAudits): void {
                $completedAudits[] = $completed->storageKey;
            },
        );

        self::assertInstanceOf(ExportArtifact::class, $winner);
        self::assertSame($winner->storageKey, $observed->storageKey);
        self::assertSame([$winner->storageKey], $completedAudits);
        $this->assertCompletedBytes($running->id, "worker-b\n", str_repeat('e', 64));
    }

    /**
     * Prove a rolled-back worker B deletes only its bytes before worker A completes.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testFailedOverlappingAttemptDeletesOnlyItsBytesAndFirstWorkerCanPublish(): void
    {
        $running = $this->runningArtifact();
        $failure = null;
        $this->storage->interleaveOnce(function () use ($running, &$failure): void {
            try {
                $this->publisher->publish(
                    $running,
                    ["worker-b-fails\n"],
                    new DateTimeImmutable('2026-08-10T10:02:00+00:00'),
                    1,
                    str_repeat('e', 64),
                    static function (ExportArtifact $completed): void {
                        throw new RuntimeException('audit transaction failed');
                    },
                );
            } catch (RuntimeException $exception) {
                $failure = $exception;
            }
        });

        $completed = $this->publisher->publish(
            $running,
            ["worker-a-wins\n"],
            new DateTimeImmutable('2026-08-10T10:03:00+00:00'),
            1,
            str_repeat('f', 64),
            static function (ExportArtifact $completed): void {
            },
        );

        self::assertInstanceOf(RuntimeException::class, $failure);
        self::assertSame('audit transaction failed', $failure->getMessage());
        self::assertSame($completed->storageKey, $this->artifacts->find($running->id)?->storageKey);
        $this->assertCompletedBytes($running->id, "worker-a-wins\n", str_repeat('f', 64));
    }

    /**
     * Persist the shared running metadata version read by both simulated workers.
     *
     * @return  ExportArtifact  Running export metadata at optimistic version two.
     *
     * @since   2.0.0
     */
    private function runningArtifact(): ExportArtifact
    {
        $createdAt = new DateTimeImmutable('2026-08-10T10:00:00+00:00');
        $queued = new ExportArtifact(
            Uuid::uuid7()->toString(),
            'core.asset_register',
            1,
            str_repeat('a', 64),
            'operator-1',
            'default',
            null,
            null,
            AuthenticatedSurface::Api,
            str_repeat('b', 64),
            str_repeat('c', 64),
            [],
            str_repeat('d', 64),
            ExportArtifactStatus::Queued,
            $createdAt,
            $createdAt->modify('+1 hour'),
            null,
            null,
            'core_asset_register-20260810-100000.csv',
            null,
            null,
            null,
            null,
            null,
            null,
            1,
        );
        $this->artifacts->add($queued);
        $running = $queued->start($createdAt->modify('+1 minute'));
        $this->artifacts->save($running, $queued->version);

        return $running;
    }

    /**
     * Verify the completed metadata head and its sole immutable private object together.
     *
     * @param   string  $artifactId   Canonical metadata identifier.
     * @param   string  $expected     Exact winning CSV bytes.
     * @param   string  $queryDigest  Expected winning policy-filtered query digest.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function assertCompletedBytes(string $artifactId, string $expected, string $queryDigest): void
    {
        $head = $this->artifacts->find($artifactId);
        self::assertNotNull($head);
        self::assertSame(ExportArtifactStatus::Completed, $head->status);
        self::assertSame(3, $head->version);
        self::assertSame($queryDigest, $head->queryDigest);
        self::assertSame(hash('sha256', $expected), $head->checksum);
        self::assertNotNull($head->storageKey);
        self::assertNotNull($head->size);
        self::assertNotNull($head->checksum);
        $stream = $this->storage->open(new StoredExportArtifact(
            $head->storageKey,
            $head->size,
            $head->checksum,
        ));
        self::assertIsResource($stream);
        self::assertSame($expected, stream_get_contents($stream));
        fclose($stream);

        $files = array_values(array_filter(
            scandir($this->directory) ?: [],
            static fn (string $name): bool => $name !== '.' && $name !== '..',
        ));
        self::assertSame([$head->storageKey], $files);
    }
}

/**
 * Runs one nested publisher immediately after its outer worker has stored private bytes.
 *
 * @since  2.0.0
 */
final class InterleavingExportArtifactStorage implements ExportArtifactStorage
{
    /**
     * One-shot deterministic overlap callback.
     *
     * @var    ?Closure
     * @since  2.0.0
     */
    private ?Closure $interleave = null;

    /**
     * Wrap the real immutable store without changing its storage semantics.
     *
     * @param  ExportArtifactStorage  $storage  Concrete private object store.
     *
     * @since  2.0.0
     */
    public function __construct(private readonly ExportArtifactStorage $storage)
    {
    }

    /**
     * Schedule one competing publisher at the next completed store boundary.
     *
     * @param   Closure(): void  $operation  Competing worker publication.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function interleaveOnce(Closure $operation): void
    {
        $this->interleave = $operation;
    }

    /**
     * Store bytes, then yield once to the scheduled competing worker.
     *
     * @param   string            $artifactId  Canonical artifact UUID.
     * @param   iterable<string>  $chunks      Ordered artifact chunks.
     *
     * @return  StoredExportArtifact  Attempt-owned stored-byte evidence.
     *
     * @since   2.0.0
     */
    public function store(string $artifactId, iterable $chunks): StoredExportArtifact
    {
        $stored = $this->storage->store($artifactId, $chunks);
        $interleave = $this->interleave;
        $this->interleave = null;
        if ($interleave !== null) {
            $interleave();
        }

        return $stored;
    }

    /**
     * Open verified private bytes through the real store.
     *
     * @param   StoredExportArtifact  $artifact  Expected stored-byte evidence.
     *
     * @return  resource  Verified read stream.
     *
     * @since   2.0.0
     */
    public function open(StoredExportArtifact $artifact): mixed
    {
        return $this->storage->open($artifact);
    }

    /**
     * Delete exactly one attempt-owned object through the real store.
     *
     * @param   string  $key  Opaque attempt-owned storage key.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function delete(string $key): void
    {
        $this->storage->delete($key);
    }
}
