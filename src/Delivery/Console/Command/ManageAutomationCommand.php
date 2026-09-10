<?php

declare(strict_types=1);

namespace Kumwe\App\Delivery\Console\Command;

use DateTimeImmutable;
use Kumwe\App\Application\Automation\AutomationManagementService;
use Kumwe\App\Delivery\Console\Command;
use Kumwe\App\Delivery\Console\Output;
use Kumwe\Context\Value\ExecutionContext;
use Throwable;

/**
 * Console command that inspects and steers recurring schedules and queued jobs.
 *
 * The worker and scheduler processes do the automation work; this command is how a person decides
 * what work exists. It is the operator's tool for suspending a nightly schedule during a migration
 * window without losing its definition, for seeing what the queue is holding, and for rescuing an
 * individual job that died or was queued by mistake. Everything goes through
 * `AutomationManagementService`, so the console sees exactly the schedules and jobs the actor's token
 * may manage, and prints JSON so the same call can drive a dashboard or a deployment check.
 *
 * @since  2.0.0
 */
final readonly class ManageAutomationCommand implements Command
{
    /**
     * Wire the automation service and the gate that turns console options into an authorized actor.
     *
     * @param  AutomationManagementService  $automation     Owns schedule definitions, the job listing
     *         and the retry and cancel operations.
     * @param  ConsoleAuthorizer            $authorization  Turns `--site` and `--token-file` into an
     *         execution context carrying
     *         `automation.manage`.
     *
     * @since  2.0.0
     */
    public function __construct(
        private AutomationManagementService $automation,
        private ConsoleAuthorizer $authorization,
    ) {
    }

    /**
     * Name the console dispatcher registers this command under.
     *
     * @return  string  Always `automation`.
     *
     * @since   2.0.0
     */
    public function name(): string
    {
        return 'automation';
    }

    /**
     * Summary line `bin/kumwe list` prints beside the command name.
     *
     * @return  string  One-sentence statement of what the command manages.
     *
     * @since   2.0.0
     */
    public function description(): string
    {
        return 'core.console.automation.description';
    }

    /**
     * Dispatch one automation action and print its result as JSON.
     *
     * The first argument names the action and defaults to `schedules`; the rest are `--name=value`
     * options, and the whole command is gated on `automation.manage`. The `schedules` listing carries
     * the registered job types alongside the schedules, so an operator composing a `create` call can
     * see in one round trip which handlers a new schedule is allowed to name. Every action that
     * changes an existing schedule additionally takes the `--version` the operator last read, so two
     * people editing the same schedule cannot silently overwrite one another.
     *
     * @param   list<string>  $arguments  Action name first, then `--name=value` options.
     * @param   Output        $output     Sink for the JSON result, or for the failure message.
     *
     * @return  int  `0` when the action completed, `1` with its message on stderr when it did not.
     *
     * @since   2.0.0
     */
    public function execute(array $arguments, Output $output): int
    {
        try {
            $action = array_shift($arguments) ?? 'schedules';
            $options = CommandInput::options($arguments);
            $context = $this->authorization->require($options, 'automation.manage');
            $result = match ($action) {
                'schedules' => [
                    'items' => $this->automation->schedules($context),
                    'job_types' => $this->automation->jobTypes($context),
                ],
                'schedule' => $this->automation->schedule($context, CommandInput::required($options, 'id')),
                'jobs' => ['items' => $this->automation->jobs($context, (int) ($options['limit'] ?? 100))],
                'queues' => ['items' => $this->automation->queuePolicies($context)],
                'purge-queue' => ['purged' => $this->automation->purgeQueue(
                    $context,
                    CommandInput::required($options, 'queue'),
                    isset($options['limit']) ? CommandInput::positiveInteger($options, 'limit') : 100,
                )],
                'create' => ['id' => $this->automation->createSchedule(
                    $context,
                    CommandInput::required($options, 'name'),
                    CommandInput::required($options, 'cron'),
                    $options['timezone'] ?? 'UTC',
                    CommandInput::required($options, 'job'),
                    CommandInput::jsonObject($options, 'payload'),
                    $options['queue'] ?? 'default',
                    isset($options['first-run'])
                        ? new DateTimeImmutable($options['first-run'])
                        : new DateTimeImmutable(),
                )],
                'enable' => $this->scheduleState($context, $options, true),
                'disable' => $this->scheduleState($context, $options, false),
                'delete' => $this->deleteSchedule($context, $options),
                'retry' => $this->jobAction($context, $options, true),
                'cancel' => $this->jobAction($context, $options, false),
                default => throw new \InvalidArgumentException('Unsupported automation action.'),
            };
            $output->line(CommandInput::render($result));
            return 0;
        } catch (Throwable $exception) {
            $output->error($exception->getMessage());
            return 1;
        }
    }

    /**
     * Suspend or resume a schedule without discarding its definition.
     *
     * This is the reversible way to stop recurring work: the definition and last-run record survive,
     * the scheduler simply passes the schedule over while it is disabled. Both directions share one
     * call site because they take the same options and differ only in the flag.
     *
     * @param   ExecutionContext       $context  Authorized actor and site the change is audited under.
     * @param   array<string, string>  $options  Console options; `id` and `version` are required.
     * @param   bool                   $enabled  True to resume dispatching, false to suspend it.
     *
     * @return  array{updated: bool}  Always `['updated' => true]` once the change committed.
     *
     * @throws  \InvalidArgumentException  When `id` is missing or is not a canonical lowercase UUID,
     *          `version` is not a positive integer, or the schedule no longer exists at that version.
     *
     * @since   2.0.0
     */
    private function scheduleState(ExecutionContext $context, array $options, bool $enabled): array
    {
        $this->automation->setScheduleEnabled(
            $context,
            CommandInput::required($options, 'id'),
            CommandInput::positiveInteger($options, 'version'),
            $enabled,
        );
        return ['updated' => true];
    }

    /**
     * Remove a schedule permanently so it produces no further occurrences.
     *
     * Deletion takes the definition with it and cannot be undone; `disable` is what an operator wants
     * when the schedule may be needed again. Jobs the schedule has already enqueued are untouched and
     * still run, so deleting a schedule is not a way to stop work that is already in the queue.
     *
     * @param   ExecutionContext       $context  Authorized actor and site the deletion is audited under.
     * @param   array<string, string>  $options  Console options; `id` and `version` are required.
     *
     * @return  array{deleted: bool}  Always `['deleted' => true]` once the schedule is gone.
     *
     * @throws  \InvalidArgumentException  When `id` is missing or is not a canonical lowercase UUID,
     *          `version` is not a positive integer, or the schedule no longer exists at that version.
     *
     * @since   2.0.0
     */
    private function deleteSchedule(ExecutionContext $context, array $options): array
    {
        $this->automation->deleteSchedule(
            $context,
            CommandInput::required($options, 'id'),
            CommandInput::positiveInteger($options, 'version'),
        );
        return ['deleted' => true];
    }

    /**
     * Requeue a job that exhausted its attempts, or withdraw one that has not started.
     *
     * The two actions identify the job the same way and differ only in which service call runs, so
     * they share one call site. Neither reaches a job a worker already holds: retry applies to a dead
     * job and cancel to a pending one, and anything in between is refused rather than interrupted.
     * A retried job starts as if newly enqueued, so whatever made it fail should be fixed first.
     *
     * @param   ExecutionContext       $context  Authorized actor and site the change is audited under.
     * @param   array<string, string>  $options  Console options; `id` names the job to act on.
     * @param   bool                   $retry    True to retry a dead job, false to cancel a pending one.
     *
     * @return  array{updated: bool}  Always `['updated' => true]` once the queue accepted the change.
     *
     * @throws  \InvalidArgumentException  When `id` is missing or is not a canonical lowercase UUID,
     *          or the job does not exist or is not in the state the action requires.
     *
     * @since   2.0.0
     */
    private function jobAction(ExecutionContext $context, array $options, bool $retry): array
    {
        $id = CommandInput::required($options, 'id');
        if ($retry) {
            $this->automation->retryJob($context, $id);
        } else {
            $this->automation->cancelJob($context, $id);
        }
        return ['updated' => true];
    }
}
