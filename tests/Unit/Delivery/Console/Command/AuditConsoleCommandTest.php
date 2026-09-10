<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Delivery\Console\Command;

use Kumwe\App\Tests\Support\TranslatesConsoleOutput;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\App\Audit\Application\AuditTrailExport;
use Kumwe\App\Audit\Application\AuditTrailExporter;
use Kumwe\App\Audit\Application\AuditTrailVerifier;
use Kumwe\App\Audit\Domain\AuditEnforcementState;
use Kumwe\App\Audit\Domain\AuditVerificationFinding;
use Kumwe\App\Audit\Domain\AuditVerificationReport;
use Kumwe\App\Audit\Domain\StoredAuditArchive;
use Kumwe\App\Delivery\Console\Command\ConsoleAuthorizer;
use Kumwe\App\Delivery\Console\Command\ExportAuditTrailCommand;
use Kumwe\App\Delivery\Console\Command\VerifyAuditTrailCommand;
use Kumwe\App\Delivery\Console\Output;
use Kumwe\App\Identity\Application\Authentication\AccessTokenVerifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(VerifyAuditTrailCommand::class)]
#[CoversClass(ExportAuditTrailCommand::class)]
final class AuditConsoleCommandTest extends TestCase
{
    /**
     * Protected token files awaiting cleanup.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private array $files = [];

    /**
     * Remove every protected token file a test created.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    /**
     * Prove an intact trail exits zero and reports what it re-checked.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testVerificationOfAnIntactTrailSucceedsAndReportsItsCounts(): void
    {
        $output = new CollectingConsoleOutput();
        $command = new VerifyAuditTrailCommand(
            new StubAuditTrailVerifier(new AuditVerificationReport(12, 3, 12, AuditEnforcementState::Active)),
            $this->authorizer(),
        );

        self::assertSame('audit:verify', $command->name());
        self::assertSame(0, $command->execute($this->options(), $output));
        self::assertStringContainsString('"intact": true', $output->lines[0]);
        self::assertStringContainsString('"events_verified": 12', $output->lines[0]);
        self::assertStringContainsString('"append_only_enforcement": "active"', $output->lines[0]);
        self::assertSame([], $output->errors);
    }

    /**
     * Prove an intact trail on a server with no guards is reported as its own, distinctly worse verdict.
     *
     * The exit status has to separate it from both neighbours: a deployment gate that treated it as `0`
     * would sign off an installation with no prevention at all, and one that treated it as `1` could not
     * tell a missing control from a trail that has actually been tampered with.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testVerificationOfAnUnguardedTrailReportsTheDegradedStateDistinctly(): void
    {
        $output = new CollectingConsoleOutput();
        $command = new VerifyAuditTrailCommand(
            new StubAuditTrailVerifier(new AuditVerificationReport(12, 3, 12, AuditEnforcementState::NotInstalled)),
            $this->authorizer(),
        );

        self::assertSame(2, $command->execute($this->options(), $output));
        self::assertSame([], $output->lines);
        self::assertStringContainsString('"intact": true', $output->errors[0]);
        self::assertStringContainsString('"append_only_enforcement": "not_installed"', $output->errors[0]);
        self::assertStringContainsString('application-level discipline', $output->errors[0]);
        self::assertStringNotContainsString('"divergence"', $output->errors[0]);
    }

    /**
     * Prove a divergence exits non-zero and names the first divergent row on the error stream.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testVerificationOfADivergentTrailFailsAndNamesTheFirstDivergence(): void
    {
        $output = new CollectingConsoleOutput();
        $command = new VerifyAuditTrailCommand(
            new StubAuditTrailVerifier(new AuditVerificationReport(
                4,
                1,
                9,
                AuditEnforcementState::Active,
                new AuditVerificationFinding(
                    'event.digest.mismatch',
                    5,
                    'The stored event digest disagrees with its recomputation from the row.',
                    '8bd4ec65-92f2-4934-afb8-b22a3cf956cd',
                ),
            )),
            $this->authorizer(),
        );

        self::assertSame(1, $command->execute($this->options(), $output));
        self::assertSame([], $output->lines);
        self::assertStringContainsString('"code": "event.digest.mismatch"', $output->errors[0]);
        self::assertStringContainsString('"position": 5', $output->errors[0]);
        self::assertStringContainsString('8bd4ec65-92f2-4934-afb8-b22a3cf956cd', $output->errors[0]);
        self::assertStringContainsString('"append_only_enforcement": "active"', $output->errors[0]);
    }

    /**
     * Prove a divergence outranks a missing guard, so the louder verdict is never masked by the quieter.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testADivergenceOutranksMissingEnforcementInTheExitStatus(): void
    {
        $output = new CollectingConsoleOutput();
        $command = new VerifyAuditTrailCommand(
            new StubAuditTrailVerifier(new AuditVerificationReport(
                4,
                1,
                9,
                AuditEnforcementState::NotInstalled,
                new AuditVerificationFinding('event.link.unresolved', 5, 'Broken witness link.'),
            )),
            $this->authorizer(),
        );

        self::assertSame(1, $command->execute($this->options(), $output));
        self::assertStringContainsString('"append_only_enforcement": "not_installed"', $output->errors[0]);
        self::assertStringContainsString('"code": "event.link.unresolved"', $output->errors[0]);
    }

    /**
     * Prove an unauthenticated invocation fails without reaching the verifier.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testVerificationRefusesToRunWithoutAProtectedTokenFile(): void
    {
        $output = new CollectingConsoleOutput();
        $verifier = new StubAuditTrailVerifier(new AuditVerificationReport(0, 0, 0, AuditEnforcementState::Active));

        self::assertSame(1, (new VerifyAuditTrailCommand($verifier, $this->authorizer()))->execute(
            ['--site=default'],
            $output,
        ));
        self::assertFalse($verifier->called);
        self::assertNotSame([], $output->errors);
    }

    /**
     * Prove the export prints the archive manifest rather than the archived bytes.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testExportPrintsTheArchiveManifestAndNeverTheArchivedBytes(): void
    {
        $output = new CollectingConsoleOutput();
        $exporter = new StubAuditTrailExporter(new AuditTrailExport(
            new StoredAuditArchive('archive-key.ndjson', 2048, str_repeat('a', 64)),
            1,
            40,
            40,
            2,
            7,
        ));
        $command = new ExportAuditTrailCommand($exporter, $this->authorizer());

        self::assertSame('audit:export', $command->name());
        self::assertSame(0, $command->execute([...$this->options(), '--from=1', '--to=40'], $output));
        self::assertSame([1, 40], [$exporter->from, $exporter->to]);
        self::assertStringContainsString('"archive_key": "archive-key.ndjson"', $output->lines[0]);
        self::assertStringContainsString('"archive_sha256": "' . str_repeat('a', 64) . '"', $output->lines[0]);
        self::assertStringContainsString('"event_count": 40', $output->lines[0]);
        self::assertStringContainsString('"redacted_values": 2', $output->lines[0]);
        self::assertStringContainsString('"anchor_sequence": 7', $output->lines[0]);
    }

    /**
     * Build the console options both commands are invoked with.
     *
     * @return  list<string>
     *
     * @since   2.0.0
     */
    private function options(): array
    {
        return ['--site=default', '--token-file=' . $this->tokenFile()];
    }

    /**
     * Write an owner-only token file the console authorizer will accept.
     *
     * @return  string  Absolute path of the protected file.
     *
     * @since   2.0.0
     */
    private function tokenFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'kumwe-audit-token-');
        self::assertIsString($path);
        file_put_contents($path, 'console-token');
        chmod($path, 0o600);
        $this->files[] = $path;

        return $path;
    }

    /**
     * Build an authorizer whose verifier resolves every token to a fully capable principal.
     *
     * @return  ConsoleAuthorizer  Authorizer bound to the permissive test verifier.
     *
     * @since   2.0.0
     */
    private function authorizer(): ConsoleAuthorizer
    {
        return new ConsoleAuthorizer(new class implements AccessTokenVerifier {
            public function verify(
                string $token,
                string $audience = 'kumwe-http',
                string $purpose = 'api',
                ?string $site = null,
            ): ?\Kumwe\App\Identity\Application\Authentication\AuthenticatedPrincipal {
                return \Kumwe\App\Tests\Support\AuthorizationContext::principal(
                    ['audit.manage', 'audit.export'],
                );
            }
        });
    }
}

/**
 * Output double that keeps result and failure lines apart, as the console streams do.
 *
 * @since  2.0.0
 */
final class CollectingConsoleOutput implements Output
{
    use TranslatesConsoleOutput;

    /**
     * Result lines the command wrote.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    public array $lines = [];

    /**
     * Failure lines the command wrote.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    public array $errors = [];

    /**
     * Capture one result line.
     *
     * @param   string  $message  Text the command produced.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function line(string $message): void
    {
        $this->lines[] = $message;
    }

    /**
     * Capture one failure line.
     *
     * @param   string  $message  Text the command produced.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function error(string $message): void
    {
        $this->errors[] = $message;
    }
}

/**
 * Verifier double returning a fixed report, so the command's own behaviour is what is under test.
 *
 * @since  2.0.0
 */
final class StubAuditTrailVerifier implements AuditTrailVerifier
{
    /**
     * Whether the command reached the verifier at all.
     *
     * @var    bool
     * @since  2.0.0
     */
    public bool $called = false;

    /**
     * Bind the fixed report this double answers with.
     *
     * @param  AuditVerificationReport  $report  Report handed back to every call.
     *
     * @since  2.0.0
     */
    public function __construct(private AuditVerificationReport $report)
    {
    }

    /**
     * Answer with the fixed report.
     *
     * @param   ExecutionContext  $context    Ignored authorized context.
     * @param   int               $batchSize  Ignored walk batch size.
     *
     * @return  AuditVerificationReport  The report this double was built with.
     *
     * @since   2.0.0
     */
    public function verify(ExecutionContext $context, int $batchSize = 1000): AuditVerificationReport
    {
        $this->called = true;

        return $this->report;
    }
}

/**
 * Exporter double capturing the requested range and returning a fixed manifest.
 *
 * @since  2.0.0
 */
final class StubAuditTrailExporter implements AuditTrailExporter
{
    /**
     * First position the command asked for.
     *
     * @var    ?int
     * @since  2.0.0
     */
    public ?int $from = null;

    /**
     * Last position the command asked for.
     *
     * @var    ?int
     * @since  2.0.0
     */
    public ?int $to = null;

    /**
     * Bind the fixed manifest this double answers with.
     *
     * @param  AuditTrailExport  $export  Manifest handed back to every call.
     *
     * @since  2.0.0
     */
    public function __construct(private AuditTrailExport $export)
    {
    }

    /**
     * Record the requested range and answer with the fixed manifest.
     *
     * @param   ExecutionContext  $context       Ignored authorized context.
     * @param   ?int              $fromPosition  First position the command requested.
     * @param   ?int              $toPosition    Last position the command requested.
     *
     * @return  AuditTrailExport  The manifest this double was built with.
     *
     * @since   2.0.0
     */
    public function export(
        ExecutionContext $context,
        ?int $fromPosition = null,
        ?int $toPosition = null,
    ): AuditTrailExport {
        $this->from = $fromPosition;
        $this->to = $toPosition;

        return $this->export;
    }
}
