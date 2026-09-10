<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\BusinessRecord;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Kumwe\App\Kernel\Container;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\App\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\App\BusinessRecord\Application\BusinessRecordService;
use Kumwe\App\BusinessRecord\Application\Command\DocumentLineInput;
use Kumwe\App\BusinessRecord\Application\Command\DocumentWriteIntent;
use Kumwe\App\BusinessRecord\Application\Command\WriteDocumentCommand;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordVersionConflict;
use Kumwe\App\BusinessRecord\Application\Query\ReadRecordQuery;
use Kumwe\App\BusinessSchema\Application\BusinessSchemaInstallationRepository;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Tests\Support\NeutralBusinessFixture;
use Kumwe\App\Tests\Support\TestKernelFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * Races two whole-document amendments against one document and proves only one of them lands.
 *
 * The interleaving this rules out is the one that would matter: two callers each read a document at
 * version five, each prepare a consistent replacement of it, and each commit — leaving a document neither
 * of them wrote, with one caller's header over the other caller's lines. The race cannot be built inside
 * one PHP process, because the loser only exists while the winner is still inside its transaction, so the
 * second caller is a separate operating-system process running the same kernel.
 *
 * @since  2.0.0
 */
#[CoversClass(BusinessRecordService::class)]
final class AggregateDocumentConcurrencyIntegrationTest extends TestCase
{
    /**
     * Proves two concurrent amendments of one document produce one winner and one refused conflict.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTwoConcurrentAmendmentsCannotInterleaveIntoOneDocument(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $database = $this->service($container, Connection::class);
        if ($database->getDatabasePlatform() instanceof SQLitePlatform) {
            self::markTestSkipped(
                'SQLite admits one writer at a time, so two sessions cannot contend for one document.',
            );
        }
        $context = TestKernelFactory::administratorContext($container);
        $records = $this->service($container, BusinessRecordService::class);
        $suffix = strtolower(substr(str_replace('-', '', Uuid::uuid7()->toString()), -10));
        $header = $this->install($container, $context);
        $documentId = Uuid::uuid7()->toString();

        $created = $records->writeDocument(new WriteDocumentCommand(
            $context,
            $header->handle,
            'lines',
            ['title' => 'Raced document', 'total' => '1.00'],
            [new DocumentLineInput([
                'code' => 'race-' . $suffix . '-original',
                'description' => 'Original line',
                'amount' => '1.00',
            ])],
            NeutralBusinessFixture::idempotencyKey('doc-race-create-' . $suffix),
            recordId: $documentId,
        ));

        $directory = $this->handshakeDirectory();
        $partner = $this->spawnPartner(
            $directory,
            $header->handle,
            $documentId,
            $created->version,
            'race-' . $suffix . '-partner',
        );

        $ours = null;
        try {
            self::assertTrue(
                $this->await($directory . '/partner-ready', 30.0),
                'The partner process never booted; the race could not be built.',
            );
            file_put_contents($directory . '/test-ready', 'ready');
            try {
                $records->writeDocument(new WriteDocumentCommand(
                    $context,
                    $header->handle,
                    'lines',
                    ['total' => '3.00'],
                    [new DocumentLineInput([
                        'code' => 'race-' . $suffix . '-ours',
                        'description' => 'Our line',
                        'amount' => '3.00',
                    ])],
                    NeutralBusinessFixture::idempotencyKey('doc-race-ours-' . $suffix),
                    DocumentWriteIntent::Amend,
                    $created->version,
                    $documentId,
                ));
                $ours = 'committed';
            } catch (Throwable $exception) {
                $ours = $exception::class;
            }
            $theirs = $this->awaitOutcome($directory, $partner);
        } finally {
            $this->cleanUp($directory);
        }

        $committed = array_filter([$ours, $theirs], static fn (string $outcome): bool => $outcome === 'committed');
        self::assertCount(
            1,
            $committed,
            sprintf(
                'Exactly one of two concurrent amendments must land; this run saw %s and %s.',
                $ours ?? 'nothing',
                $theirs,
            ),
        );
        $loser = $ours === 'committed' ? $theirs : $ours;
        self::assertSame(
            BusinessRecordVersionConflict::class,
            $loser,
            'The amendment that lost the race is refused as a stale document, not applied to a moved one.',
        );

        $view = $records->read(new ReadRecordQuery(
            $context,
            $header->handle,
            $documentId,
            includes: ['lines'],
        ));
        self::assertSame($created->version + 1, $view->version, 'One document moved exactly one version.');
        $lines = $view->includes['lines'] ?? [];
        self::assertCount(1, $lines, 'The surviving document holds one caller\'s collection, not a mixture.');
        $total = $view->values['total'] ?? null;
        self::assertNotNull($total);
        self::assertSame(
            $ours === 'committed' ? '3.00' : '7.00',
            (string) $total,
            'The header that survived belongs to the same caller as the lines that survived.',
        );
    }

    /**
     * Install, or reuse, the neutral document header and line definitions this race is run over.
     *
     * The same stable pair the rest of the document suite uses: what keeps one run apart from another is
     * the document identity it contends over, not a definition of its own.
     *
     * @param   Container         $container  Live integration container.
     * @param   ExecutionContext  $context    Trusted test actor.
     *
     * @return  EntityTypeDefinition  The installed header definition.
     *
     * @since   2.0.0
     */
    private function install(Container $container, ExecutionContext $context): EntityTypeDefinition
    {
        $line = NeutralBusinessFixture::install(
            $container,
            $context,
            NeutralBusinessFixture::documentLineDocument(
                NeutralBusinessFixture::DOCUMENT_SUFFIX,
                Uuid::uuid7()->toString(),
            ),
        );

        return NeutralBusinessFixture::install(
            $container,
            $context,
            NeutralBusinessFixture::documentHeaderDocument(
                NeutralBusinessFixture::DOCUMENT_SUFFIX,
                Uuid::uuid7()->toString(),
                $line->handle,
            ),
        );
    }

    /**
     * Start the second caller, which boots its own kernel and waits for the handshake.
     *
     * @param   string  $directory  Handshake directory both processes coordinate through.
     * @param   string  $handle     Definition handle of the document type.
     * @param   string  $recordId   Identity of the contended document.
     * @param   int     $version    Aggregate version both callers read.
     * @param   string  $code       Unique line code the partner writes.
     *
     * @return  resource  The running process handle.
     *
     * @since   2.0.0
     */
    private function spawnPartner(string $directory, string $handle, string $recordId, int $version, string $code)
    {
        $process = proc_open([
            PHP_BINARY,
            dirname(__DIR__, 3) . '/tests/Support/document-amend-partner.php',
            $directory,
            $handle,
            $recordId,
            (string) $version,
            $code,
        ], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            self::fail('The document amendment partner process could not be started.');
        }
        foreach ($pipes as $pipe) {
            stream_set_blocking($pipe, false);
        }

        return $process;
    }

    /**
     * Wait for the partner to record what happened to it, then reap the process.
     *
     * @param   string    $directory  Handshake directory.
     * @param   resource  $partner    Handle returned by `spawnPartner()`.
     *
     * @return  string  The partner's own outcome: `committed` or the class of what refused it.
     *
     * @since   2.0.0
     */
    private function awaitOutcome(string $directory, $partner): string
    {
        $this->await($directory . '/partner-outcome', 60.0);
        $outcome = is_file($directory . '/partner-outcome')
            ? trim((string) file_get_contents($directory . '/partner-outcome'))
            : 'partner-never-reported';
        proc_close($partner);

        return $outcome;
    }

    /**
     * Block until a handshake file appears, or the deadline passes.
     *
     * @param   string  $path     File to wait for.
     * @param   float   $seconds  How long to wait before giving up.
     *
     * @return  bool  True when the file appeared in time.
     *
     * @since   2.0.0
     */
    private function await(string $path, float $seconds): bool
    {
        $deadline = microtime(true) + $seconds;
        while (microtime(true) < $deadline) {
            clearstatcache(true, $path);
            if (is_file($path)) {
                return true;
            }
            usleep(10_000);
        }

        return false;
    }

    /**
     * Create the private directory the two processes hand signals through.
     *
     * @return  string  Absolute path of the new directory.
     *
     * @since   2.0.0
     */
    private function handshakeDirectory(): string
    {
        $directory = sys_get_temp_dir() . '/kumwe-document-race-' . bin2hex(random_bytes(8));
        if (!mkdir($directory, 0o700, true) && !is_dir($directory)) {
            self::fail('The document race handshake directory could not be created.');
        }

        return $directory;
    }

    /**
     * Remove the handshake directory and everything the two processes left in it.
     *
     * @param   string  $directory  Directory created by `handshakeDirectory()`.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function cleanUp(string $directory): void
    {
        foreach (glob($directory . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($directory);
    }

    /**
     * Resolve one wired service, failing the test rather than returning something unusable.
     *
     * @template T of object
     *
     * @param   Container        $container  Live integration container.
     * @param   class-string<T>  $service    Service identifier to resolve.
     *
     * @return  T  The wired instance.
     *
     * @since   2.0.0
     */
    private function service(Container $container, string $service): object
    {
        $resolved = $container->get($service);
        self::assertInstanceOf($service, $resolved);

        return $resolved;
    }
}
