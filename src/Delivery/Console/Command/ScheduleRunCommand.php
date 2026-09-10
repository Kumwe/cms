<?php

declare(strict_types=1);

namespace Kumwe\App\Delivery\Console\Command;

use InvalidArgumentException;
use Kumwe\App\Application\Automation\Scheduler;
use Kumwe\Context\Value\SiteContext;
use Kumwe\App\Application\Authorization\SystemPrincipal;
use Kumwe\App\Delivery\Console\Command;
use Kumwe\App\Delivery\Console\Output;
use Kumwe\App\Extension\Runtime\ExtensionRuntimeMapCompiler;
use Kumwe\App\Extension\Runtime\RuntimeMaterializationState;
use Throwable;

/**
 * Console command that turns due schedules into queued jobs, once or in a supervised loop.
 *
 * This is the scheduler process. It only decides that an occurrence has come due and enqueues it; the
 * work itself is performed later by `queue:work`, and suppressing a duplicate dispatch across
 * competing schedulers is the `Scheduler` implementation's guarantee rather than the loop's. The two
 * shapes exist for two callers: `--loop` dispatches every fifteen seconds and prints nothing, which
 * suits a long-lived service under a supervisor, while a bare invocation runs one pass and reports its
 * count, which suits a deployment smoke test. Where the container supplies the extension runtime, each
 * pass first re-checks the generation this process loaded, so a scheduler stops rather than keeps
 * dispatching from a revoked extension set.
 *
 * @since  2.0.0
 */
final readonly class ScheduleRunCommand implements Command
{
    /**
     * Wire the scheduler port, its authority, and the optional runtime-generation guard.
     *
     * @param  Scheduler                     $scheduler      Enqueues a job for every schedule that is due.
     * @param  SystemPrincipal               $system         Mints the scheduler context passes authorize with.
     * @param  ?ExtensionRuntimeMapCompiler  $runtime        Rejects a superseded generation before each pass;
     *         null leaves the loop unguarded.
     * @param  ?RuntimeMaterializationState  $loadedRuntime  Generation this process loaded; null also leaves
     *         the loop unguarded.
     *
     * @since  2.0.0
     */
    public function __construct(
        private Scheduler $scheduler,
        private SystemPrincipal $system,
        private ?ExtensionRuntimeMapCompiler $runtime = null,
        private ?RuntimeMaterializationState $loadedRuntime = null,
    ) {
    }

    /**
     * Name this command is invoked under on the console.
     *
     * @return  string  Always `schedule:run`.
     *
     * @since   2.0.0
     */
    public function name(): string
    {
        return 'schedule:run';
    }

    /**
     * Summary line `bin/kumwe list` prints beside the command name.
     *
     * @return  string  One-sentence statement of the two shapes the command runs in.
     *
     * @since   2.0.0
     */
    public function description(): string
    {
        return 'core.console.schedule_run.description';
    }

    /**
     * Dispatch one batch of due schedules, or keep dispatching every fifteen seconds under `--loop`.
     *
     * One context is minted for the whole run, so every pass a looping scheduler makes correlates back
     * to the process that started it. The count is printed only for a single pass; a looping
     * scheduler would otherwise emit a line every fifteen seconds whether or not anything was due. A
     * stale runtime generation leaves through the same error path as any other failure, which is how a
     * supervisor is told to restart the process onto the current extension set.
     *
     * @param   list<string>  $arguments  Empty for a single pass, or `--loop` to keep dispatching; any
     *          other argument is refused.
     * @param   Output        $output     Sink for the dispatched-count line, or the failure on stderr.
     *
     * @return  int  `0` once a single pass has completed, `1` when an argument was rejected or a pass
     *          failed; a looping run only ever returns `1`.
     *
     * @since   2.0.0
     */
    public function execute(array $arguments, Output $output): int
    {
        try {
            $loop = in_array('--loop', $arguments, true);

            foreach ($arguments as $argument) {
                if ($argument !== '--loop') {
                    throw new InvalidArgumentException('schedule:run accepts only the optional --loop flag.');
                }
            }

            $context = $this->system->context(
                SiteContext::default(),
                'scheduler-' . bin2hex(random_bytes(16)),
            );

            do {
                if ($this->runtime !== null && $this->loadedRuntime !== null) {
                    $this->runtime->assertLoadedGenerationCurrent($this->loadedRuntime);
                }
                $dispatched = $this->scheduler->dispatchDue($context);

                if (!$loop) {
                    $output->message('core.console.schedule_run.dispatched_due_schedule_s', [
                        'dispatched' => $dispatched,
                    ]);
                } else {
                    sleep(15);
                }
            } while ($loop);

            return 0;
        } catch (Throwable $exception) {
            $output->error($exception->getMessage());

            return 1;
        }
    }
}
