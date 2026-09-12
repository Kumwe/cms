<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Delivery\Console\Command;

use Kumwe\App\Tests\Support\TranslatesConsoleOutput;
use DateTimeImmutable;
use Kumwe\App\Audit\Application\AuditRecorder;
use Kumwe\App\Audit\Domain\AuditEvent;
use Kumwe\App\BusinessRecord\Application\PostingPeriodService;
use Kumwe\App\Delivery\Console\Command\ConsoleAuthorizer;
use Kumwe\App\Delivery\Console\Command\ManagePostingPeriodsCommand;
use Kumwe\App\Delivery\Console\Output;
use Kumwe\App\Identity\Application\Authentication\AccessTokenVerifier;
use Kumwe\App\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\App\Tests\Support\AuthorizationContext;
use Kumwe\Transaction\Testing\ImmediateTransactionManager;
use Kumwe\App\Tests\Support\InMemoryPostingPeriodRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

/**
 * Pins the shell face of posting-period administration: naming, capability split, and the JSON reply.
 *
 * The rules themselves — the capability gate, the audit entry, the range immutability — live in
 * `PostingPeriodService` and are proven there; what this suite pins is that the console adapter routes
 * each action to that one service under the right capability, prints the declaration as JSON, and
 * turns every refusal into a non-zero exit with its message rather than a stack trace.
 *
 * @since  2.0.0
 */
#[CoversClass(ManagePostingPeriodsCommand::class)]
final class ManagePostingPeriodsCommandTest extends TestCase
{
    /**
     * Path of the owner-protected token file minted for the run.
     *
     * @var    ?string
     * @since  2.0.0
     */
    private ?string $tokenFile = null;

    /**
     * Remove the token file a test minted.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    protected function tearDown(): void
    {
        if ($this->tokenFile !== null && is_file($this->tokenFile)) {
            unlink($this->tokenFile);
        }
        $this->tokenFile = null;
    }

    /**
     * Listing takes the read capability; closing and re-opening each take the manage one.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testEveryActionDemandsItsOwnCapability(): void
    {
        $capabilities = (new \ReflectionClass(ManagePostingPeriodsCommand::class))->getConstant('CAPABILITIES');

        self::assertSame([
            'list' => 'business.period.read',
            'close' => 'business.period.manage',
            'reopen' => 'business.period.manage',
        ], $capabilities);
    }

    /**
     * Close, list and re-open round-trip through the one service and print the declaration as JSON.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheLifecycleRoundTripsThroughTheServiceAsJson(): void
    {
        $command = $this->command();
        $options = $this->authorizedOptions();

        $output = new CollectingPostingPeriodOutput();
        self::assertSame(0, $command->execute(
            ['close', ...$options, '--key=2026-08', '--starts=2026-08-01', '--ends=2026-09-01'],
            $output,
        ));
        $closed = json_decode($output->lines[0], true);
        self::assertIsArray($closed);
        self::assertSame('closed', $closed['status']);
        self::assertSame('2026-08-01T00:00:00Z', $closed['starts_at']);

        $output = new CollectingPostingPeriodOutput();
        self::assertSame(0, $command->execute(['list', ...$options], $output));
        $listing = json_decode($output->lines[0], true);
        self::assertIsArray($listing);
        self::assertCount(1, $listing['items']);

        $output = new CollectingPostingPeriodOutput();
        self::assertSame(0, $command->execute(
            ['reopen', ...$options, '--key=2026-08'],
            $output,
        ));
        $reopened = json_decode($output->lines[0], true);
        self::assertIsArray($reopened);
        self::assertSame('open', $reopened['status']);
        self::assertSame('business-periods', $command->name());
        self::assertNotSame('', trim($command->description()));
    }

    /**
     * A full RFC 3339 boundary is accepted beside the bare-date shorthand.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnRfcInstantBoundaryIsAcceptedBesideTheBareDate(): void
    {
        $command = $this->command();
        $output = new CollectingPostingPeriodOutput();

        self::assertSame(0, $command->execute(
            [
                'close',
                ...$this->authorizedOptions(),
                '--key=half-day',
                '--starts=2026-08-01T12:00:00Z',
                '--ends=2026-08-02',
            ],
            $output,
        ));
        $closed = json_decode($output->lines[0], true);
        self::assertIsArray($closed);
        self::assertSame('2026-08-01T12:00:00Z', $closed['starts_at']);
    }

    /**
     * Failures exit non-zero with an operator-readable sentence on stderr, never a stack trace.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testFailuresExitNonZeroWithTheirMessage(): void
    {
        $command = $this->command();
        $options = $this->authorizedOptions();
        $failures = [
            'an unsupported action' => ['destroy', ...$options],
            'a malformed boundary' => ['close', ...$options, '--key=bad', '--starts=today', '--ends=2026-09-01'],
            'an impossible instant' => [
                'close',
                ...$options,
                '--key=bad',
                '--starts=2026-99-99T00:00:00Z',
                '--ends=2026-09-01',
            ],
            'a missing key' => ['close', ...$options, '--starts=2026-08-01', '--ends=2026-09-01'],
        ];

        foreach ($failures as $name => $arguments) {
            $output = new CollectingPostingPeriodOutput();
            self::assertSame(1, $command->execute($arguments, $output), $name);
            self::assertNotSame([], $output->errors, $name);
        }
    }

    /**
     * Build the command over the real authorizer, a stub token verifier, and the in-memory service.
     *
     * @return  ManagePostingPeriodsCommand  Command under test.
     *
     * @since   2.0.0
     */
    private function command(): ManagePostingPeriodsCommand
    {
        $verifier = new class implements AccessTokenVerifier {
            /**
             * Authenticate the unit token for the console audience and management purpose.
             *
             * @param   string  $token           Presented bearer credential.
             * @param   string  $audience        Surface the token must belong to.
             * @param   string  $purpose         Purpose the token must carry.
             * @param   string  $siteIdentifier  Site the token must be scoped to.
             *
             * @return  ?AuthenticatedPrincipal  The test principal, or null off the happy path.
             *
             * @since   2.0.0
             */
            public function verify(
                string $token,
                string $audience = 'kumwe-http',
                string $purpose = 'api',
                string $siteIdentifier = 'default',
            ): ?AuthenticatedPrincipal {
                if ($token !== 'unit-console-token' || $audience !== 'kumwe-cli' || $purpose !== 'management') {
                    return null;
                }

                return AuthorizationContext::principal([
                    PostingPeriodService::MANAGE,
                    PostingPeriodService::READ,
                ]);
            }
        };
        $events = [];
        $recorder = new class ($events) implements AuditRecorder {
            /**
             * Capture events into the test's own list.
             *
             * @param  list<AuditEvent>  $events  Sink held by reference.
             *
             * @since  2.0.0
             */
            public function __construct(private array &$events)
            {
            }

            /**
             * Append one event to the captured list.
             *
             * @param   AuditEvent  $event  Event the service recorded.
             *
             * @return  void
             *
             * @since   2.0.0
             */
            public function record(AuditEvent $event): void
            {
                $this->events[] = $event;
            }
        };
        $clock = new class implements ClockInterface {
            /**
             * Answer a fixed instant so rendered bookkeeping is exact.
             *
             * @return  DateTimeImmutable  Always 2026-09-05T08:00:00Z.
             *
             * @since   2.0.0
             */
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('2026-09-05T08:00:00Z');
            }
        };
        $service = new PostingPeriodService(
            new InMemoryPostingPeriodRepository(),
            AuthorizationContext::gateway(),
            new ImmediateTransactionManager(),
            $recorder,
            $clock,
        );

        return new ManagePostingPeriodsCommand($service, new ConsoleAuthorizer($verifier));
    }

    /**
     * Mint the owner-protected token file and answer the shared authorization options.
     *
     * @return  list<string>  The `--site` and `--token-file` options every action needs.
     *
     * @since   2.0.0
     */
    private function authorizedOptions(): array
    {
        if ($this->tokenFile === null) {
            $path = tempnam(sys_get_temp_dir(), 'kumwe-unit-periods-');
            self::assertIsString($path);
            self::assertNotFalse(file_put_contents($path, 'unit-console-token'));
            self::assertTrue(chmod($path, 0o600));
            $this->tokenFile = $path;
        }

        return ['--site=default', '--token-file=' . $this->tokenFile];
    }
}

/**
 * Output double collecting the command's lines and errors for assertion.
 *
 * @since  2.0.0
 */
final class CollectingPostingPeriodOutput implements Output
{
    use TranslatesConsoleOutput;

    /**
     * Result lines printed so far.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    public array $lines = [];

    /**
     * Error lines printed so far.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    public array $errors = [];

    /**
     * Collect one result line.
     *
     * @param   string  $message  Line the command printed.
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
     * Collect one error line.
     *
     * @param   string  $message  Failure the command reported.
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
