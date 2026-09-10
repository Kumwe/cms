<?php

declare(strict_types=1);

namespace Kumwe\App\Delivery\Console\Command;

use InvalidArgumentException;
use Kumwe\App\Application\Automation\QueueRuntimePolicyCatalog;
use Kumwe\App\Application\Automation\Worker;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Context\Value\SiteContext;
use Kumwe\App\Application\Authorization\SystemPrincipal;
use Kumwe\App\Delivery\Console\Command;
use Kumwe\App\Delivery\Console\Output;
use Kumwe\App\Extension\Runtime\ExtensionRuntimeMapCompiler;
use Kumwe\App\Extension\Runtime\RuntimeMaterializationState;
use Throwable;

/**
 * Console command that runs the durable job worker as a supervised loop or a single pass.
 *
 * Claiming, fencing, executing and settling a job all belong to `Worker`; what lives here is the
 * process around it. That means option validation, the poll interval, the signal handlers that turn a
 * stop request into a drain after the job in flight has settled rather than a killed job, and the
 * `--max-jobs`/`--max-runtime` budgets that let a supervisor recycle the process on its own schedule.
 * When the container supplies the extension runtime, the loop also re-checks before every claim that
 * the generation this process started on is still current, so a worker cannot keep executing revoked
 * extension code for the rest of its lifetime. Nothing escapes as an exception: a failure is reported
 * on stderr and becomes exit status `1`, and a worker that reached the loop always retires its
 * heartbeat on the way out.
 *
 * @since  2.0.0
 */
final readonly class QueueWorkCommand implements Command
{
    /**
     * Wire the worker, its authority, and the optional runtime-generation guard.
     *
     * @param  Worker                        $worker         Executes at most one claimed job per iteration.
     * @param  SystemPrincipal               $system         Mints the worker context claims authorize with.
     * @param  ?ExtensionRuntimeMapCompiler  $runtime        Rejects a superseded generation before each claim;
     *         the check runs only when both this and `$loadedRuntime` are given.
     * @param  ?RuntimeMaterializationState  $loadedRuntime  Generation this process loaded, and the source of
     *         its worker identity; null falls back to a random one.
     * @param  ?QueueRuntimePolicyCatalog    $policies       Active contributed queue limits; null preserves
     *         the core worker defaults for isolated command instances.
     *
     * @since  2.0.0
     */
    public function __construct(
        private Worker $worker,
        private SystemPrincipal $system,
        private ?ExtensionRuntimeMapCompiler $runtime = null,
        private ?RuntimeMaterializationState $loadedRuntime = null,
        private ?QueueRuntimePolicyCatalog $policies = null,
    ) {
    }

    /**
     * Name this command is invoked under on the console.
     *
     * @return  string  Always `queue:work`.
     *
     * @since   2.0.0
     */
    public function name(): string
    {
        return 'queue:work';
    }

    /**
     * Summary line `bin/kumwe list` prints beside the command name.
     *
     * @return  string  One-sentence statement of what the command runs.
     *
     * @since   2.0.0
     */
    public function description(): string
    {
        return 'core.console.queue_work.description';
    }

    /**
     * Consume one queue until the process is asked to drain, or for a single pass with `--once`.
     *
     * Accepts `--queue=NAME`, `--once`, `--sleep-ms=N` (50-60000), `--lease-seconds=N` (5-3600),
     * `--max-jobs=N` and `--max-runtime=N`. A contributed queue defaults to its signed lease and refuses
     * a wider override. A `SIGTERM`, `SIGINT`, `SIGHUP` or `SIGQUIT`, an exhausted
     * job budget, or an exhausted runtime budget each end the loop after the current job has settled;
     * the sleep is skipped whenever a job was handled, so a busy queue drains without pausing. The
     * heartbeat is retired from a `finally` block, but only once a worker identity and context exist,
     * so an option rejected during validation leaves nothing to clean up. A cleanup failure is
     * reported without changing the status the loop already decided on.
     *
     * @param   list<string>  $arguments  Raw `--name=value` options plus the valueless `--once` flag.
     * @param   Output        $output     Sink for the startup and drain lines, and for failures on stderr.
     *
     * @return  int  `0` after a clean drain, `1` when an option was rejected or the loop failed.
     *
     * @since   2.0.0
     */
    public function execute(array $arguments, Output $output): int
    {
        $workerId = null;
        $context = null;
        $queue = null;

        try {
            $options = $this->options($arguments);
            $queue = $options['queue'] ?? 'default';
            $once = isset($options['once']);

            if (!is_string($queue)) {
                throw new InvalidArgumentException('The worker queue must be a string.');
            }
            $policy = $this->policies?->policy($queue);

            $sleepOption = $options['sleep-ms'] ?? null;

            if ($sleepOption !== null && (!is_string($sleepOption) || preg_match('/^[0-9]+$/D', $sleepOption) !== 1)) {
                throw new InvalidArgumentException('Worker sleep must be an integer.');
            }

            $sleepMilliseconds = $sleepOption === null ? 1_000 : (int) $sleepOption;

            if ($sleepMilliseconds < 50 || $sleepMilliseconds > 60_000) {
                throw new InvalidArgumentException('Worker sleep must be between 50 and 60000 milliseconds.');
            }

            $leaseSeconds = $this->integerOption(
                $options,
                'lease-seconds',
                $policy->leaseSeconds ?? 60,
                5,
                3_600,
            );
            if ($policy !== null && $leaseSeconds > $policy->leaseSeconds) {
                throw new InvalidArgumentException('A contributed queue lease cannot exceed its signed policy.');
            }
            $maximumJobs = $this->integerOption($options, 'max-jobs', 0, 0, 1_000_000);
            $maximumRuntime = $this->integerOption($options, 'max-runtime', 0, 0, 604_800);
            $workerId = $this->loadedRuntime === null
                ? 'worker:' . bin2hex(random_bytes(16))
                : 'runtime:' . $this->loadedRuntime->replicaId;
            $output->message('core.console.queue_work.kumwe_worker_is_consuming_queue', [
                'workerId' => $workerId,
                'queue' => $queue,
            ]);
            if ($policy !== null) {
                $output->message('core.console.queue_work.queue_policy_generation_lease_s_attempts', [
                    'runtimeGeneration' => $policy->runtimeGeneration,
                    'leaseSeconds' => $policy->leaseSeconds,
                    'maximumAttempts' => $policy->maximumAttempts,
                    'maximumInFlight' => $policy->maximumInFlight,
                    'retentionDays' => $policy->retentionDays,
                ]);
            }
            $context = $this->system->context(
                SiteContext::default(),
                'worker-' . bin2hex(random_bytes(16)),
            );

            $draining = false;
            if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
                pcntl_async_signals(true);
                $drain = static function () use (&$draining): void {
                    $draining = true;
                };
                pcntl_signal(SIGTERM, $drain);
                pcntl_signal(SIGINT, $drain);
                pcntl_signal(SIGHUP, $drain);
                pcntl_signal(SIGQUIT, $drain);
            }

            $startedAt = hrtime(true);
            $handledJobs = 0;

            do {
                if ($this->runtime !== null && $this->loadedRuntime !== null) {
                    $this->runtime->assertLoadedGenerationCurrent($this->loadedRuntime);
                }
                $handled = $this->worker->runOnce($context, $queue, $workerId, $leaseSeconds);
                $handledJobs += $handled ? 1 : 0;

                $runtimeSeconds = (int) ((hrtime(true) - $startedAt) / 1_000_000_000);
                $draining = $draining
                    || ($maximumJobs > 0 && $handledJobs >= $maximumJobs)
                    || ($maximumRuntime > 0 && $runtimeSeconds >= $maximumRuntime);

                if (!$handled && !$once && !$draining) {
                    usleep($sleepMilliseconds * 1_000);
                }
            } while (!$once && !$draining);

            $output->message('core.console.queue_work.kumwe_worker_drained_after_job_s', [
                'workerId' => $workerId,
                'handledJobs' => $handledJobs,
            ]);

            return 0;
        } catch (Throwable $exception) {
            $output->error($exception->getMessage());

            return 1;
        } finally {
            if (is_string($workerId) && $context instanceof ExecutionContext && is_string($queue)) {
                try {
                    $this->worker->disconnect($context, $workerId, $queue);
                } catch (Throwable $exception) {
                    $output->failure('core.console.queue_work.worker_heartbeat_cleanup_failed', [
                        'reason' => $exception->getMessage(),
                    ]);
                }
            }
        }
    }

    /**
     * Read one bounded integer option, falling back to its default when the flag was not supplied.
     *
     * Only a run of decimal digits is accepted, so a value such as `30s` or `1_000` is refused rather
     * than quietly truncated by the cast that follows.
     *
     * @param   array<string, string|true>  $options  Parsed options, keyed by name without the `--`.
     * @param   string                      $name     Option to read, spelled as it appears after `--`.
     * @param   int                         $default  Value used when the option is absent.
     * @param   int                         $minimum  Lowest accepted value, inclusive.
     * @param   int                         $maximum  Highest accepted value, inclusive.
     *
     * @return  int  The supplied value, or the default when the option was not given.
     *
     * @throws  InvalidArgumentException  When the value is not a run of digits, or falls outside the
     *          accepted range.
     *
     * @since   2.0.0
     */
    private function integerOption(
        array $options,
        string $name,
        int $default,
        int $minimum,
        int $maximum,
    ): int {
        $value = $options[$name] ?? null;
        if ($value === null) {
            return $default;
        }
        if (!is_string($value) || preg_match('/^[0-9]+$/D', $value) !== 1) {
            throw new InvalidArgumentException(sprintf('Worker %s must be an integer.', $name));
        }
        $integer = (int) $value;
        if ($integer < $minimum || $integer > $maximum) {
            throw new InvalidArgumentException(sprintf(
                'Worker %s must be between %d and %d.',
                $name,
                $minimum,
                $maximum,
            ));
        }

        return $integer;
    }

    /**
     * Parse the argument vector into the option map the rest of the command reads.
     *
     * `--once` is the only valueless flag and is recorded as `true`; every other argument must be
     * spelled `--name=value`, so a mistyped option stops the worker instead of being dropped and
     * leaving it running with a default nobody asked for.
     *
     * @param   list<string>  $arguments  Argument vector as handed over by the console application.
     *
     * @return  array<string, string|true>  Values keyed by option name without the leading `--`, with
     *          `--once` mapped to `true`.
     *
     * @throws  InvalidArgumentException  When an argument is neither `--once` nor `--name=value`.
     *
     * @since   2.0.0
     */
    private function options(array $arguments): array
    {
        $options = [];

        foreach ($arguments as $argument) {
            if ($argument === '--once') {
                $options['once'] = true;
                continue;
            }

            if (preg_match('/^--([a-z][a-z-]*)=(.+)$/D', $argument, $matches) !== 1) {
                throw new InvalidArgumentException('Worker options must use --name=value syntax.');
            }

            $options[$matches[1]] = $matches[2];
        }

        return $options;
    }
}
