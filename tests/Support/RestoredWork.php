<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Support;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Kumwe\App\Kernel\Container;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\App\Application\Automation\AutomationManagementService;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use RuntimeException;

/**
 * Proof that a restored installation performs work rather than merely holding the rows that describe it.
 *
 * Comparing digests answers "did the bytes come back". It does not answer "does the system run",
 * and those come apart in exactly the places a restore is most likely to go wrong: a schedule whose
 * next occurrence never arrives, a queue no worker will claim from, a handler the restored runtime
 * generation refuses to load. So the drill seeds one schedule before the backup and, after the
 * restore, makes the restored installation dispatch it and drain the job through the real console
 * commands — the same two the operator runbook asks for by hand.
 *
 * The job is `system.sessions.purge`, chosen because its effect is observable in restored data: the
 * expired sessions the backup carried are gone afterwards and the live one is not.
 *
 * @since  2.0.0
 */
final class RestoredWork
{
    /**
     * Cron expression the drill schedule uses, so an occurrence is due whenever the drill runs.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string CRON = '* * * * *';

    /**
     * Job type each occurrence enqueues.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string JOB_TYPE = 'system.sessions.purge';

    /**
     * Create the schedule whose occurrence the restored installation has to dispatch and run.
     *
     * The first run is placed in the past, which the scheduler pulls up to now, so the occurrence is
     * already owed by the time the backup is taken and is still owed when the restore boots.
     *
     * @param   Container         $container  Booted kernel for the source installation.
     * @param   ExecutionContext  $context    Owner context able to manage automation.
     * @param   string            $name       Operator-facing schedule name the drill looks itself up by.
     * @param   string            $queue      Queue the occurrence is enqueued onto.
     *
     * @return  void
     *
     * @throws  RuntimeException  When automation management is unavailable.
     *
     * @since   2.0.0
     */
    public static function seedSchedule(
        Container $container,
        ExecutionContext $context,
        string $name,
        string $queue,
    ): void {
        if (self::findSchedule($container, $name) !== null) {
            return;
        }
        $automation = $container->get(AutomationManagementService::class);
        if (!$automation instanceof AutomationManagementService) {
            throw new RuntimeException('The automation management service is unavailable.');
        }
        $automation->createSchedule(
            $context,
            $name,
            self::CRON,
            'UTC',
            self::JOB_TYPE,
            [],
            $queue,
            new DateTimeImmutable('2026-08-08T00:00:00+00:00'),
        );
    }

    /**
     * Dispatch the due occurrence and drain it, through the console commands an operator would use.
     *
     * @param   Container  $container  Booted kernel bound to the restored database.
     * @param   string     $name       Schedule name seeded before the backup.
     * @param   string     $queue      Queue the occurrence lands on.
     *
     * @return  array{schedules_dispatched: int, jobs_completed: int}  What the restored installation did.
     *
     * @throws  RuntimeException  When either console command is unavailable or fails, nothing was
     *          dispatched, the schedule did not advance, or no job reached a completed state.
     *
     * @since   2.0.0
     */
    public static function execute(Container $container, string $name, string $queue): array
    {
        if (self::findSchedule($container, $name) === null) {
            throw new RuntimeException('The restored drill schedule is unavailable.');
        }

        // The console binary is used rather than the command objects, because the runtime-generation
        // guard the worker and scheduler enforce is a property of a freshly started process: a
        // long-lived process that loaded an older generation is exactly what it exists to stop. That
        // also makes this the operator's own sequence — materialize, dispatch, drain — rather than an
        // in-process imitation of it.
        $materialized = self::run(['extension:runtime:materialize']);
        if ($materialized['status'] !== 0) {
            $materialized = self::run(['extension:runtime:materialize', '--repair']);
        }
        if ($materialized['status'] !== 0) {
            throw new RuntimeException(
                'The restored extension runtime did not materialize: ' . $materialized['output'],
            );
        }

        $scheduled = self::run(['schedule:run']);
        if ($scheduled['status'] !== 0) {
            throw new RuntimeException('The restored scheduler failed: ' . $scheduled['output']);
        }
        $dispatched = self::dispatchedCount($scheduled['output']);
        if ($dispatched < 1) {
            throw new RuntimeException('The restored scheduler dispatched no due occurrence.');
        }

        $drained = self::run(['queue:work', '--once', '--queue=' . $queue]);
        if ($drained['status'] !== 0) {
            throw new RuntimeException('The restored worker failed: ' . $drained['output']);
        }
        if (preg_match('/ drained after 1 job\(s\)\./', $drained['output']) !== 1) {
            throw new RuntimeException('The restored worker claimed no job from the drill queue.');
        }

        $advanced = self::findSchedule($container, $name);
        if ($advanced === null || $advanced['last_run_at'] === null) {
            throw new RuntimeException('The restored schedule did not record a run.');
        }
        $completed = self::completedJobs($container, $queue);
        if ($completed < 1) {
            throw new RuntimeException('The restored queue holds no completed job.');
        }

        return ['schedules_dispatched' => $dispatched, 'jobs_completed' => $completed];
    }

    /**
     * Run one `bin/kumwe` command in its own process and collect what it said.
     *
     * @param   list<string>  $arguments  Console command and its flags.
     *
     * @return  array{status: int, output: string}  Exit status and merged output.
     *
     * @throws  RuntimeException  When the console binary is missing or cannot be started.
     *
     * @since   2.0.0
     */
    private static function run(array $arguments): array
    {
        $binary = dirname(__DIR__, 2) . '/bin/kumwe';
        if (!is_file($binary)) {
            throw new RuntimeException('The Kumwe console binary is unavailable.');
        }
        $process = proc_open(
            [PHP_BINARY, $binary, ...$arguments],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__, 2),
        );
        if (!is_resource($process)) {
            throw new RuntimeException('A restored console command could not be started.');
        }
        $output = '';
        foreach ($pipes as $pipe) {
            $contents = stream_get_contents($pipe);
            $output .= is_string($contents) ? $contents : '';
            fclose($pipe);
        }

        return ['status' => proc_close($process), 'output' => $output];
    }

    /**
     * Read the count out of the scheduler's own report rather than re-querying for it.
     *
     * @param   string  $output  Everything the scheduler wrote.
     *
     * @return  int  Occurrences dispatched, or zero when the report is absent.
     *
     * @since   2.0.0
     */
    private static function dispatchedCount(string $output): int
    {
        return preg_match('/Dispatched (\d+) due schedule\(s\)\./', $output, $matches) === 1
            ? (int) $matches[1]
            : 0;
    }

    /**
     * Find the drill schedule by its operator-facing name.
     *
     * @param   Container  $container  Booted kernel.
     * @param   string     $name       Schedule name.
     *
     * @return  ?array{id: string, last_run_at: mixed}  The row, or null when it is absent.
     *
     * @throws  RuntimeException  When the connection or table compiler is unavailable.
     *
     * @since   2.0.0
     */
    private static function findSchedule(Container $container, string $name): ?array
    {
        $row = self::database($container)->fetchAssociative(sprintf(
            'SELECT id, last_run_at FROM %s WHERE name = ?',
            self::tables($container)->quoted('schedules'),
        ), [$name]);
        if ($row === false || !is_string($row['id'] ?? null)) {
            return null;
        }

        return ['id' => $row['id'], 'last_run_at' => $row['last_run_at'] ?? null];
    }

    /**
     * Count the completed jobs the drill queue holds.
     *
     * @param   Container  $container  Booted kernel.
     * @param   string     $queue      Drill queue.
     *
     * @return  int  Completed jobs.
     *
     * @throws  RuntimeException  When the count is unreadable.
     *
     * @since   2.0.0
     */
    private static function completedJobs(Container $container, string $queue): int
    {
        $count = self::database($container)->fetchOne(sprintf(
            'SELECT COUNT(*) FROM %s WHERE queue = ? AND status = ?',
            self::tables($container)->quoted('jobs'),
        ), [$queue, 'completed']);

        return is_numeric($count) ? (int) $count : throw new RuntimeException('A job count is unreadable.');
    }

    /**
     * Resolve the installation connection.
     *
     * @param   Container  $container  Booted kernel.
     *
     * @return  Connection  Installation connection.
     *
     * @throws  RuntimeException  When it is unavailable.
     *
     * @since   2.0.0
     */
    private static function database(Container $container): Connection
    {
        $database = $container->get(Connection::class);

        return $database instanceof Connection
            ? $database
            : throw new RuntimeException('The installation connection is unavailable.');
    }

    /**
     * Resolve the table-name compiler.
     *
     * @param   Container  $container  Booted kernel.
     *
     * @return  TableNames  Portable table-name compiler.
     *
     * @throws  RuntimeException  When it is unavailable.
     *
     * @since   2.0.0
     */
    private static function tables(Container $container): TableNames
    {
        $tables = $container->get(TableNames::class);

        return $tables instanceof TableNames
            ? $tables
            : throw new RuntimeException('The table-name compiler is unavailable.');
    }
}
