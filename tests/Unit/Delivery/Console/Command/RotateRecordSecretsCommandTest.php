<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Delivery\Console\Command;

use Kumwe\App\BusinessRecord\Application\RecordSecretRotation;
use Kumwe\App\BusinessRecord\Application\RecordSecretRotationReport;
use Kumwe\App\Delivery\Console\Command\ConsoleAuthorizer;
use Kumwe\App\Delivery\Console\Command\RotateRecordSecretsCommand;
use Kumwe\App\Identity\Application\Authentication\AccessTokenVerifier;
use Kumwe\App\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\App\Tests\Support\AuthorizationContext;
use Kumwe\Context\Value\ExecutionContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(RotateRecordSecretsCommand::class)]
#[CoversClass(RecordSecretRotationReport::class)]
/**
 * Proves the re-keying command reports progress in counts and encodes completion in its exit status.
 *
 * @since  2.0.0
 */
final class RotateRecordSecretsCommandTest extends TestCase
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
     * Prove a finished rotation exits zero and states which key everything now carries.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAFinishedRotationExitsZeroAndNamesTheActiveKey(): void
    {
        $output = new CollectingConsoleOutput();
        $rotation = new StubRecordSecretRotation(
            new RecordSecretRotationReport('record-encryption-v2', 12, 12, 0, 3, [], true),
        );
        $command = new RotateRecordSecretsCommand($rotation, $this->authorizer());

        self::assertSame('business-record-rekey', $command->name());
        self::assertSame(0, $command->execute($this->options(), $output));
        self::assertSame(200, $rotation->batchSize);
        self::assertStringContainsString('"active_key_id": "record-encryption-v2"', $output->lines[0]);
        self::assertStringContainsString('"rows_resealed": 12', $output->lines[0]);
        self::assertStringContainsString('"complete": true', $output->lines[0]);
        self::assertSame([], $output->errors);
    }

    /**
     * Prove an unfinished pass exits two, so a driving loop knows to call again.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnUnfinishedPassExitsTwoSoALoopCallsAgain(): void
    {
        $output = new CollectingConsoleOutput();
        $rotation = new StubRecordSecretRotation(new RecordSecretRotationReport(
            'record-encryption-v2',
            500,
            497,
            3,
            2,
            [['definition_id' => '018f22e2-7c8b-7ab0-8f3a-88e8026bb701', 'status' => 'disabled']],
            false,
        ));
        $command = new RotateRecordSecretsCommand($rotation, $this->authorizer());

        self::assertSame(2, $command->execute([...$this->options(), '--batch-size=500'], $output));
        self::assertSame(500, $rotation->batchSize);
        self::assertStringContainsString('"complete": false', $output->lines[0]);
        self::assertStringContainsString('"rows_superseded": 3', $output->lines[0]);
        self::assertStringContainsString('"status": "disabled"', $output->lines[0]);
    }

    /**
     * Prove a failing pass exits one and prints nothing but its message.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAFailedPassExitsOneAndPrintsOnlyItsMessage(): void
    {
        $output = new CollectingConsoleOutput();
        $command = new RotateRecordSecretsCommand(
            new FailingRecordSecretRotation(),
            $this->authorizer(),
        );

        self::assertSame(1, $command->execute($this->options(), $output));
        self::assertSame([], $output->lines);
        self::assertSame(
            ['The business-record secret encryption key "record-v1" is unavailable.'],
            $output->errors,
        );
    }

    /**
     * Build the option list every test invokes the command with.
     *
     * @return  list<string>  Site and token-file options.
     *
     * @since   2.0.0
     */
    private function options(): array
    {
        return ['--site=default', '--token-file=' . $this->tokenFile()];
    }

    /**
     * Write a token file with the permissions the console authorizer insists on.
     *
     * @return  string  Absolute path of the protected file.
     *
     * @since   2.0.0
     */
    private function tokenFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'kumwe-rekey-token-');
        self::assertIsString($path);
        file_put_contents($path, 'console-token');
        chmod($path, 0o600);
        $this->files[] = $path;

        return $path;
    }

    /**
     * Build an authorizer whose verifier resolves every token to a principal holding the capability.
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
            ): ?AuthenticatedPrincipal {
                return AuthorizationContext::principal(['business.record.rekey']);
            }
        });
    }
}

/**
 * Rotation double returning a fixed report, so the command's own behaviour is what is under test.
 *
 * @since  2.0.0
 */
final class StubRecordSecretRotation implements RecordSecretRotation
{
    /**
     * Batch size the command asked for, so the default and the option can both be proven.
     *
     * @var    int
     * @since  2.0.0
     */
    public int $batchSize = 0;

    /**
     * Bind the fixed report this double answers with.
     *
     * @param  RecordSecretRotationReport  $report  Report handed back to every call.
     *
     * @since  2.0.0
     */
    public function __construct(private RecordSecretRotationReport $report)
    {
    }

    /**
     * Answer with the fixed report, recording the batch size it was called with.
     *
     * @param   ExecutionContext  $context    Ignored authorized context.
     * @param   int               $batchSize  Recorded for assertion.
     *
     * @return  RecordSecretRotationReport  The report this double was built with.
     *
     * @since   2.0.0
     */
    public function rotate(ExecutionContext $context, int $batchSize): RecordSecretRotationReport
    {
        $this->batchSize = $batchSize;

        return $this->report;
    }
}

/**
 * Rotation double that fails the way a missing key fails, message included.
 *
 * @since  2.0.0
 */
final class FailingRecordSecretRotation implements RecordSecretRotation
{
    /**
     * Fail exactly as an unavailable key does.
     *
     * @param   ExecutionContext  $context    Ignored authorized context.
     * @param   int               $batchSize  Ignored batch size.
     *
     * @return  RecordSecretRotationReport  Never returned.
     *
     * @throws  RuntimeException  Always.
     *
     * @since   2.0.0
     */
    public function rotate(ExecutionContext $context, int $batchSize): RecordSecretRotationReport
    {
        throw new RuntimeException('The business-record secret encryption key "record-v1" is unavailable.');
    }
}
