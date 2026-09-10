<?php

declare(strict_types=1);

namespace Kumwe\App\Infrastructure\Automation;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\Types;
use InvalidArgumentException;
use JsonException;
use Kumwe\App\Application\Authorization\AuthorizationGateway;
use Kumwe\App\Application\Authorization\AuthorizationResource;
use Kumwe\App\Application\Authorization\AuthorizationResourceOwnershipUnknown;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\App\Application\Authorization\ResourceSiteOwnership;
use Kumwe\App\Application\Authorization\ResourceSiteOwnershipWriter;
use Kumwe\App\Application\Authorization\SystemPrincipal;
use Kumwe\App\Automation\Domain\CronExpression;
use Kumwe\App\Application\Automation\JobExecutionClass;
use Kumwe\App\Application\Automation\JobExecutionScope;
use Kumwe\App\Application\Automation\QueueRuntimePolicyCatalog;
use Kumwe\App\Application\Automation\ScheduleOccurrenceKey;
use Kumwe\App\Application\Automation\Scheduler;
use Kumwe\App\Application\Automation\Job\ScheduleRepository;
use Kumwe\App\Application\Persistence\TransactionManager;
use Kumwe\App\BusinessIntegration\Application\ScheduleRuntimeSynchronizer;
use Kumwe\Extension\Spi\Identity\Domain\Capability;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use RuntimeException;

/**
 * Doctrine-backed schedule store that also turns due schedules into queued jobs.
 *
 * One class answers both `Scheduler` and `ScheduleRepository` because dispatching a schedule and
 * managing one read the same row and obey the same ownership rule: an installation-wide job type is
 * authorized against the type, a site-local one against the schedule and the site that owns it.
 * Dispatch claims due rows with `FOR UPDATE SKIP LOCKED` and stamps every queued job with a key
 * derived from the schedule and the occurrence instant, so any number of scheduler processes may run
 * concurrently without a single occurrence being enqueued twice. Site-local schedules are only ever
 * seen through the ownership table joined to an enabled site, so a retired or disabled site quietly
 * stops producing work instead of filling the queue with jobs that could never be authorized.
 *
 * @since  2.0.0
 */
final readonly class DoctrineScheduler implements Scheduler, ScheduleRepository
{
    /**
     * Savepoint name the occurrence insert is attempted inside, so a duplicate undoes only itself.
     *
     * One name is reused across every schedule in a pass: each `dispatch()` opens the savepoint and
     * either releases or rolls back to it before the next one begins, and re-declaring a savepoint name
     * replaces the earlier mark on every supported engine.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string OCCURRENCE_SAVEPOINT = 'kumwe_schedule_occurrence';

    /**
     * Wire the scheduler to its connection, clock and authorization collaborators.
     *
     * @param  Connection                    $database              Connection the schedule, job and site tables live
     *         on.
     * @param  TableNames                    $tables                Resolver for prefixed physical table names.
     * @param  TransactionManager            $transactions          Runs a dispatch pass or a mutation as one unit.
     * @param  ClockInterface                $clock                 Supplies the instant a schedule is judged due
     *         against.
     * @param  AuthorizationGateway          $authorization         Decides whether a caller may manage or dispatch.
     * @param  ResourceSiteOwnership         $ownership             Resolves the site a site-local schedule belongs to.
     * @param  ResourceSiteOwnershipWriter   $ownershipWriter       Writes and removes ownership beside the row itself.
     * @param  SystemPrincipal               $system                Issues the per-site context a dispatch runs under.
     * @param  JobExecutionScope             $jobScope              Classifies a job type as installation-wide or
     *         site-local.
     * @param  ?ScheduleRuntimeSynchronizer  $contributedSchedules  Optional reconciler for signed extension schedules.
     * @param  ?QueueRuntimePolicyCatalog    $queuePolicies         Active contributed queue and job limits; null
     *         preserves the established core scheduler behavior.
     *
     * @since  2.0.0
     */
    public function __construct(
        private Connection $database,
        private TableNames $tables,
        private TransactionManager $transactions,
        private ClockInterface $clock,
        private AuthorizationGateway $authorization,
        private ResourceSiteOwnership $ownership,
        private ResourceSiteOwnershipWriter $ownershipWriter,
        private SystemPrincipal $system,
        private JobExecutionScope $jobScope,
        private ?ScheduleRuntimeSynchronizer $contributedSchedules = null,
        private ?QueueRuntimePolicyCatalog $queuePolicies = null,
    ) {
    }

    /**
     * Enqueue a job for every enabled schedule whose next run has arrived.
     *
     * The claim, the enqueue and the advance of each row happen in one transaction, and the rows are
     * taken with `FOR UPDATE SKIP LOCKED` so a second scheduler picks up different work instead of
     * waiting. A site-local schedule is authorized a second time under a system context for its own
     * site, and is skipped when that site is unknown or disabled — the pass continues with the rest
     * rather than failing. Schedules are taken in due order, so the oldest overdue work goes first.
     *
     * @param   ExecutionContext  $context  Caller the dispatch capability is checked against.
     * @param   int               $limit    Most schedules to claim in this pass, from 1 to 1000.
     *
     * @return  int  How many schedules were enqueued; zero when nothing was due or all were skipped.
     *
     * @throws  InvalidArgumentException  When the limit is outside 1 to 1000.
     * @throws  RuntimeException  When a claimed row is malformed or its recurrence has no next occurrence.
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the caller may not dispatch
     *          schedules, or may not dispatch one that a site owns.
     *
     * @since   2.0.0
     */
    public function dispatchDue(ExecutionContext $context, int $limit = 100): int
    {
        $this->contributedSchedules?->synchronize();
        $this->authorization->assertAllowed(
            $context,
            Capability::fromString('system.scheduler.dispatch'),
            AuthorizationResource::collection('schedule'),
        );
        if ($limit < 1 || $limit > 1_000) {
            throw new InvalidArgumentException('The scheduler dispatch limit must be between 1 and 1000.');
        }

        return $this->transactions->transactional(function () use ($context, $limit): int {
            $scheduleOwnershipId = $this->database->getDatabasePlatform() instanceof PostgreSQLPlatform
                ? 'CAST(s.id AS VARCHAR)'
                : 's.id';
            $rows = $this->database->fetchAllAssociative(sprintf(
                'SELECT s.* FROM %s s WHERE (s.contribution_id IS NULL OR s.contribution_active = ?) '
                . 'AND (s.execution_scope = ? OR (s.execution_scope = ? '
                . 'AND EXISTS (SELECT 1 FROM %s o INNER JOIN %s site ON site.identifier = o.site_identifier '
                . 'WHERE o.resource_type = ? AND o.resource_id = %s AND site.enabled = ?))) '
                . 'AND s.enabled = ? AND s.next_run_at <= ? '
                . 'ORDER BY s.next_run_at, s.id LIMIT %d FOR UPDATE SKIP LOCKED',
                $this->tables->quoted('schedules'),
                $this->tables->quoted('resource_site_ownership'),
                $this->tables->quoted('sites'),
                $scheduleOwnershipId,
                $limit,
            ), [
                true,
                JobExecutionClass::Installation->value,
                JobExecutionClass::Site->value,
                'schedule',
                true,
                true,
                $this->clock->now(),
            ], [
                Types::BOOLEAN,
                Types::STRING,
                Types::STRING,
                Types::STRING,
                Types::BOOLEAN,
                Types::BOOLEAN,
                Types::DATETIME_IMMUTABLE,
            ]);

            $dispatched = 0;
            foreach ($rows as $row) {
                $scheduleId = $this->requiredString($row, 'id');
                $jobType = $this->requiredString($row, 'job_type');
                $executionClass = $this->jobScope->assertStoredClass(
                    $jobType,
                    $this->requiredString($row, 'execution_scope'),
                );
                $site = null;
                if ($executionClass === JobExecutionClass::Site) {
                    try {
                        $site = $this->ownership
                            ->scopeFor(AuthorizationResource::item('schedule', $scheduleId))
                            ->requireSite();
                    } catch (AuthorizationResourceOwnershipUnknown) {
                        continue;
                    }
                    if (!$this->lockEnabledSite($site)) {
                        continue;
                    }
                    $scheduleContext = $this->system->context(
                        $site,
                        'scheduler-schedule-' . $scheduleId,
                        $context->correlationId(),
                    );
                    $this->authorization->assertAllowed(
                        $scheduleContext,
                        Capability::fromString('system.scheduler.dispatch'),
                        AuthorizationResource::item('schedule', $scheduleId),
                    );
                }
                $this->dispatch($row, $site, $executionClass);
                $dispatched++;
            }

            return $dispatched;
        });
    }

    /**
     * Store a new schedule and return the identifier it is addressed by from then on.
     *
     * Everything the scheduler will later depend on is checked before the row exists — the cron
     * expression is parsed, the timezone must be a known identifier, and the job type and queue name
     * must match their allowed shapes — because an unparsable schedule would otherwise fail on every
     * dispatch pass. Creating a schedule for an installation-wide job type needs a second grant on
     * that type. The row and, for a site-local type, its site ownership are written together, so a
     * schedule can never exist without the site that owns it.
     *
     * @param   ExecutionContext      $context         Actor and site the schedule is created for.
     * @param   string                $name            Operator-facing label; trimmed, 1 to 160 characters.
     * @param   string                $cronExpression  Five-field cron expression fixing the recurrence.
     * @param   string                $timezone        IANA identifier the recurrence is evaluated in.
     * @param   string                $jobType         Registered handler type every occurrence dispatches.
     * @param   array<string, mixed>  $payload         Job payload copied onto each dispatched occurrence.
     * @param   string                $queue           Queue name the occurrences are enqueued on.
     * @param   DateTimeImmutable     $firstRun        Earliest first occurrence; a past instant runs now.
     *
     * @return  string  Identifier of the stored schedule, a UUID version 7.
     *
     * @throws  InvalidArgumentException  When the name, cron expression, timezone, job type or queue
     *          name is not acceptable.
     * @throws  UniqueConstraintViolationException  When another schedule already carries the same name.
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the caller may not manage
     *          automation, or may not schedule this installation-wide job type.
     *
     * @since   2.0.0
     */
    public function create(
        ExecutionContext $context,
        string $name,
        string $cronExpression,
        string $timezone,
        string $jobType,
        array $payload,
        string $queue,
        DateTimeImmutable $firstRun,
    ): string {
        $this->authorize($context, AuthorizationResource::collection('schedule'));
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 160) {
            throw new InvalidArgumentException('A schedule name must contain 1 to 160 characters.');
        }
        new CronExpression($cronExpression);
        if (!in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            throw new InvalidArgumentException('The schedule timezone is invalid.');
        }
        $this->assertJobType($jobType);
        $this->authorizeJobType($context, $jobType);
        $executionClass = $this->jobScope->executionClass($jobType);
        $this->assertQueue($queue);
        $id = Uuid::uuid7()->toString();
        $now = $this->clock->now();
        $maximumAttempts = $this->queuePolicies?->maximumAttempts($queue, $jobType, 5) ?? 5;
        $this->transactions->transactional(function () use (
            $context,
            $id,
            $name,
            $cronExpression,
            $timezone,
            $queue,
            $jobType,
            $executionClass,
            $payload,
            $firstRun,
            $now,
            $maximumAttempts,
        ): void {
            $this->database->insert($this->tables->raw('schedules'), [
                'id' => $id,
                'name' => $name,
                'cron_expression' => $cronExpression,
                'timezone' => $timezone,
                'queue' => $queue,
                'job_type' => $jobType,
                'execution_scope' => $executionClass->value,
                'job_schema_version' => 1,
                'payload' => $payload,
                'priority' => 0,
                'maximum_attempts' => $maximumAttempts,
                'enabled' => true,
                'next_run_at' => $firstRun < $now ? $now : $firstRun,
                'last_run_at' => null,
                'version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ], [
                'payload' => Types::JSON,
                'enabled' => Types::BOOLEAN,
                'next_run_at' => Types::DATETIME_IMMUTABLE,
                'created_at' => Types::DATETIME_IMMUTABLE,
                'updated_at' => Types::DATETIME_IMMUTABLE,
            ]);
            if ($executionClass === JobExecutionClass::Site) {
                $this->ownershipWriter->record(AuthorizationResource::item('schedule', $id), $context->site());
            }
        });

        return $id;
    }

    /**
     * List the schedules this context is entitled to manage, ordered by name.
     *
     * The query itself is unscoped: every row is read and then put to the authorization gateway, and
     * rows the context may not manage are dropped silently rather than refused. A row carrying no
     * string identifier is dropped before it is judged, since it names no resource to decide about.
     *
     * @param   ExecutionContext  $context  Actor and site the listing is filtered for.
     *
     * @return  list<array<string, mixed>>  Normalized schedule rows; empty when none may be managed.
     *
     * @throws  RuntimeException  When a stored row carries an unusable payload, version or job type.
     *
     * @since   2.0.0
     */
    public function all(ExecutionContext $context): array
    {
        $this->contributedSchedules?->synchronize();
        $rows = array_map($this->normalize(...), $this->database->fetchAllAssociative(sprintf(
            'SELECT * FROM %s WHERE contribution_id IS NULL OR contribution_active = ? ORDER BY name',
            $this->tables->quoted('schedules'),
        ), [true], [Types::BOOLEAN]));

        return array_values(array_filter(
            $rows,
            fn (array $row): bool => is_string($row['id'] ?? null)
                && $this->canManageRow($context, $row),
        ));
    }

    /**
     * Read one schedule by identifier, with its payload and version normalized.
     *
     * Null is returned only for an identifier no row carries. A schedule that exists but belongs to
     * another site, or to the installation the caller may not manage, is refused instead — so a
     * caller can distinguish an unknown identifier from a forbidden one.
     *
     * @param   ExecutionContext  $context  Actor and site the schedule is authorized against.
     * @param   string            $id       Identifier returned by `create()`.
     *
     * @return  array<string, mixed>|null  The normalized row, or null when no such schedule exists.
     *
     * @throws  RuntimeException  When the stored row carries an unusable payload, version or job type.
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the caller may not manage
     *          this schedule.
     *
     * @since   2.0.0
     */
    public function find(ExecutionContext $context, string $id): ?array
    {
        $this->contributedSchedules?->synchronize();
        $row = $this->database->fetchAssociative(sprintf(
            'SELECT * FROM %s WHERE id = ? AND (contribution_id IS NULL OR contribution_active = ?)',
            $this->tables->quoted('schedules'),
        ), [$id, true], [Types::GUID, Types::BOOLEAN]);

        if ($row === false) {
            return null;
        }
        $this->authorizeRow($context, $row);

        return $this->normalize($row);
    }

    /**
     * Enable or disable a schedule, refusing the change when the row has moved on.
     *
     * The update matches on the version the caller read and bumps it, so two operators editing the
     * same schedule cannot silently overwrite each other. Disabling keeps the row, its recurrence and
     * its history; it only removes the schedule from what a dispatch pass considers due.
     *
     * @param   ExecutionContext  $context          Actor and site the schedule is authorized against.
     * @param   string            $id               Identifier of the schedule to switch.
     * @param   int               $expectedVersion  Version the caller read; a newer stored row is refused.
     * @param   bool              $enabled          True to let dispatch resume, false to hold it.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the schedule does not exist, the expected version is not
     *          positive, or the stored version has already moved on.
     * @throws  RuntimeException  When the stored row carries an unusable job type or execution scope.
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the caller may not manage
     *          this schedule.
     *
     * @since   2.0.0
     */
    public function setEnabled(
        ExecutionContext $context,
        string $id,
        int $expectedVersion,
        bool $enabled,
    ): void {
        $row = $this->scheduleRow($id);
        $this->authorizeRow($context, $row);
        if ($expectedVersion < 1) {
            throw new InvalidArgumentException('The expected schedule version must be positive.');
        }

        $affected = $this->database->executeStatement(sprintf(
            'UPDATE %s SET enabled = ?, version = version + 1, updated_at = ? WHERE id = ? AND version = ?',
            $this->tables->quoted('schedules'),
        ), [$enabled, $this->clock->now(), $id, $expectedVersion], [
            Types::BOOLEAN,
            Types::DATETIME_IMMUTABLE,
            Types::GUID,
            Types::INTEGER,
        ]);

        if ($affected !== 1) {
            throw new InvalidArgumentException('The schedule does not exist or its version changed.');
        }
    }

    /**
     * Remove a schedule and, for a site-local one, the ownership row that binds it to its site.
     *
     * Deletion and the ownership removal share a transaction, so no orphaned ownership row can
     * survive a failure. Occurrences already enqueued are untouched: they were handed to the queue
     * when they were dispatched and run to completion on their own.
     *
     * @param   ExecutionContext  $context          Actor and site the schedule is authorized against.
     * @param   string            $id               Identifier of the schedule to remove.
     * @param   int               $expectedVersion  Version the caller read; a newer stored row is refused.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the schedule does not exist, the expected version is not
     *          positive, or the stored version has already moved on.
     * @throws  RuntimeException  When the stored row carries an unusable job type or execution scope.
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the caller may not manage
     *          this schedule.
     *
     * @since   2.0.0
     */
    public function delete(ExecutionContext $context, string $id, int $expectedVersion): void
    {
        $row = $this->scheduleRow($id);
        if (is_string($row['contribution_id'] ?? null)) {
            throw new InvalidArgumentException('A package-owned schedule is removed by disabling its extension.');
        }
        $executionClass = $this->authorizeRow($context, $row);
        if ($expectedVersion < 1) {
            throw new InvalidArgumentException('The expected schedule version must be positive.');
        }

        $this->transactions->transactional(function () use (
            $context,
            $executionClass,
            $id,
            $expectedVersion,
        ): void {
            $affected = $this->database->executeStatement(sprintf(
                'DELETE FROM %s WHERE id = ? AND version = ?',
                $this->tables->quoted('schedules'),
            ), [$id, $expectedVersion], [Types::GUID, Types::INTEGER]);

            if ((string) $affected !== '1') {
                throw new InvalidArgumentException('The schedule does not exist or its version changed.');
            }
            if ($executionClass === JobExecutionClass::Site) {
                $this->ownershipWriter->remove(AuthorizationResource::item('schedule', $id), $context->site());
            }
        });
    }

    /**
     * Enqueue one occurrence of a claimed schedule and advance the row to its next instant.
     *
     * The queued job carries an occurrence key derived from the schedule and the instant it was due,
     * and the queue holds a unique index over that key. A duplicate insert is therefore swallowed: it
     * means another scheduler already emitted this occurrence, and the row still has to move forward.
     * The next instant is computed from the instant that was due rather than from now, so a late pass
     * does not drift the recurrence off its grid.
     *
     * The insert runs inside a savepoint because swallowing is not enough on its own. PostgreSQL marks
     * the whole transaction as aborted when a statement violates a constraint, so a caught duplicate
     * would leave the schedule advance — and every remaining schedule in this pass — unable to run at
     * all. Rolling back to the savepoint undoes only the refused insert, which is what makes the swallow
     * mean the same thing on all four supported engines.
     *
     * @param   array<string, mixed>               $row             Claimed schedule row.
     * @param   ?\Kumwe\Context\Value\SiteContext  $site            Owner, null if global.
     * @param   JobExecutionClass                  $executionClass  Scope the job inherits.
     *
     * @return  void
     *
     * @throws  RuntimeException  When the row is malformed, a site-owned occurrence has no site, or the
     *          recurrence yields no occurrence within five years.
     * @throws  InvalidArgumentException  When the stored cron expression no longer parses.
     *
     * @since   2.0.0
     */
    private function dispatch(
        array $row,
        ?\Kumwe\Context\Value\SiteContext $site,
        JobExecutionClass $executionClass,
    ): void {
        $id = $this->requiredString($row, 'id');
        $scheduledFor = $this->dateTime($row['next_run_at'] ?? null);
        $now = $this->clock->now();
        $jobId = Uuid::uuid7()->toString();
        $queue = $this->requiredString($row, 'queue');
        $jobType = $this->requiredString($row, 'job_type');
        $maximumAttempts = $this->integer($row, 'maximum_attempts');
        $maximumAttempts = $this->queuePolicies?->maximumAttempts($queue, $jobType, $maximumAttempts)
            ?? $maximumAttempts;

        $this->database->createSavepoint(self::OCCURRENCE_SAVEPOINT);

        try {
            $this->database->insert($this->tables->raw('jobs'), [
                'id' => $jobId,
                'queue' => $queue,
                'job_type' => $jobType,
                'execution_scope' => $executionClass->value,
                'schema_version' => $this->integer($row, 'job_schema_version'),
                'payload' => $this->payload($row['payload'] ?? null),
                'priority' => $this->integer($row, 'priority'),
                'status' => 'pending',
                'available_at' => $now,
                'attempts' => 0,
                'maximum_attempts' => $maximumAttempts,
                'schedule_id' => $id,
                'scheduled_for' => $scheduledFor,
                'occurrence_key' => (string) ScheduleOccurrenceKey::for($id, $scheduledFor),
                'created_at' => $now,
                'updated_at' => $now,
            ], [
                'payload' => Types::JSON,
                'available_at' => Types::DATETIME_IMMUTABLE,
                'scheduled_for' => Types::DATETIME_IMMUTABLE,
                'created_at' => Types::DATETIME_IMMUTABLE,
                'updated_at' => Types::DATETIME_IMMUTABLE,
            ]);
            if ($executionClass === JobExecutionClass::Site) {
                if ($site === null) {
                    throw new RuntimeException('A site-owned scheduled job has no durable site.');
                }
                $this->ownershipWriter->record(AuthorizationResource::item('job', $jobId), $site);
            }
            $this->database->releaseSavepoint(self::OCCURRENCE_SAVEPOINT);
        } catch (UniqueConstraintViolationException) {
            // A concurrent scheduler already emitted this occurrence; undo only the refused insert so
            // the advance below still runs on an engine that aborts a transaction on a failed statement.
            $this->database->rollbackSavepoint(self::OCCURRENCE_SAVEPOINT);
        }

        $next = (new CronExpression($this->requiredString($row, 'cron_expression')))->next(
            $scheduledFor,
            $this->requiredString($row, 'timezone'),
        );
        $this->database->executeStatement(sprintf(
            'UPDATE %s SET last_run_at = ?, next_run_at = ?, version = version + 1, updated_at = ? WHERE id = ?',
            $this->tables->quoted('schedules'),
        ), [$scheduledFor, $next, $now, $id], [
            Types::DATETIME_IMMUTABLE, Types::DATETIME_IMMUTABLE, Types::DATETIME_IMMUTABLE, Types::GUID,
        ]);
    }

    /**
     * Read a stored payload column back into the associative array a job handler expects.
     *
     * Drivers differ over whether a JSON column arrives decoded, so a string is decoded here and
     * anything else is taken as already decoded. A JSON array is rejected because a payload is
     * addressed by key; the empty array is the one list-shaped value allowed through.
     *
     * @param   mixed  $stored  Payload column as the driver returned it.
     *
     * @return  array<string, mixed>  The decoded payload, empty when the schedule stores no keys.
     *
     * @throws  RuntimeException  When the column is not valid JSON, or decodes to something other
     *          than an object.
     *
     * @since   2.0.0
     */
    private function payload(mixed $stored): array
    {
        try {
            $payload = is_string($stored) ? json_decode($stored, true, 64, JSON_THROW_ON_ERROR) : $stored;
        } catch (JsonException $exception) {
            throw new RuntimeException('A schedule payload contains invalid JSON.', 0, $exception);
        }
        if (!is_array($payload) || ($payload !== [] && array_is_list($payload))) {
            throw new RuntimeException('A schedule payload must be a JSON object.');
        }
        /** @var array<string, mixed> $payload */
        return $payload;
    }

    /**
     * Read a schedule column that must be present and non-empty.
     *
     * @param   array<string, mixed>  $row    Schedule row as the driver returned it.
     * @param   string                $field  Column name, also named in the failure message.
     *
     * @return  string  The column value, never the empty string.
     *
     * @throws  RuntimeException  When the column is absent, not a string, or empty.
     *
     * @since   2.0.0
     */
    private function requiredString(array $row, string $field): string
    {
        $value = $row[$field] ?? null;
        if (!is_string($value) || $value === '') {
            throw new RuntimeException(sprintf('Schedule field %s is invalid.', $field));
        }
        return $value;
    }

    /**
     * Read a schedule column that must hold a whole number.
     *
     * Drivers return integer columns as either an int or its decimal string, so both are accepted and
     * anything else — including a numeric string with a fractional part — is rejected.
     *
     * @param   array<string, mixed>  $row    Schedule row as the driver returned it.
     * @param   string                $field  Column name, also named in the failure message.
     *
     * @return  int  The column value as an integer.
     *
     * @throws  RuntimeException  When the column is neither an integer nor a decimal integer string.
     *
     * @since   2.0.0
     */
    private function integer(array $row, string $field): int
    {
        $value = $row[$field] ?? null;
        if (!is_int($value) && (!is_string($value) || preg_match('/^-?[0-9]+$/D', $value) !== 1)) {
            throw new RuntimeException(sprintf('Schedule field %s is not an integer.', $field));
        }
        return (int) $value;
    }

    /**
     * Read a stored timestamp back as a date object, whichever form the driver returned.
     *
     * @param   mixed  $value  Timestamp column, already converted or still a string.
     *
     * @return  DateTimeImmutable  The instant the column represents.
     *
     * @throws  RuntimeException  When the column is neither a date object nor a string.
     * @throws  \DateMalformedStringException  When the string is not a parsable date.
     *
     * @since   2.0.0
     */
    private function dateTime(mixed $value): DateTimeImmutable
    {
        if ($value instanceof DateTimeImmutable) {
            return $value;
        }
        if (!is_string($value)) {
            throw new RuntimeException('A schedule timestamp is invalid.');
        }
        return new DateTimeImmutable($value);
    }

    /**
     * Convert a raw schedule row into the shape the automation API and views read.
     *
     * Only the three columns whose driver representation varies are touched: the payload is decoded,
     * the enabled flag is coerced from whatever boolean form the driver uses, and the version is made
     * an integer. Every other column is passed through untouched.
     *
     * @param   array<string, mixed>  $row  Schedule row as the driver returned it.
     *
     * @return  array<string, mixed>  The same row with `payload`, `enabled` and `version` normalized.
     *
     * @throws  RuntimeException  When the payload or the version cannot be read.
     *
     * @since   2.0.0
     */
    private function normalize(array $row): array
    {
        $row['payload'] = $this->payload($row['payload'] ?? null);
        $row['enabled'] = filter_var($row['enabled'] ?? false, FILTER_VALIDATE_BOOL);
        $row['version'] = $this->integer($row, 'version');

        return $row;
    }

    /**
     * Reject a queue name a worker could not be pointed at.
     *
     * @param   string  $queue  Queue name a new schedule would enqueue onto.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the name is empty, longer than 64 characters, does not
     *          start with a letter or digit, or uses a character outside dot, colon, dash and
     *          underscore.
     *
     * @since   2.0.0
     */
    private function assertQueue(string $queue): void
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,63}$/D', $queue) !== 1) {
            throw new InvalidArgumentException('The schedule queue is invalid.');
        }
    }

    /**
     * Reject a job type no handler could ever be registered under.
     *
     * This is a shape check only. Whether a handler is actually registered for the type is settled by
     * `AutomationManagementService` before it reaches the store.
     *
     * @param   string  $type  Job type a new schedule would dispatch.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the type does not start with a letter, or is outside 3
     *          to 128 characters of the allowed set.
     *
     * @since   2.0.0
     */
    private function assertJobType(string $type): void
    {
        if (preg_match('/^[A-Za-z][A-Za-z0-9._:-]{2,127}$/D', $type) !== 1) {
            throw new InvalidArgumentException('The scheduled job type is invalid.');
        }
    }

    /**
     * Demand the automation management capability over one resource.
     *
     * @param   ExecutionContext       $context   Actor and site the decision is made for.
     * @param   AuthorizationResource  $resource  Schedule, job-type or collection being managed.
     *
     * @return  void
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the context does not hold
     *          `automation.manage` over the resource.
     *
     * @since   2.0.0
     */
    private function authorize(ExecutionContext $context, AuthorizationResource $resource): void
    {
        $this->authorization->assertAllowed(
            $context,
            Capability::fromString('automation.manage'),
            $resource,
        );
    }

    /**
     * Demand the extra grant a job type needs when its effect reaches the whole installation.
     *
     * A site-local type returns immediately: the site-level check the caller already passed is the
     * whole requirement. An installation-wide type is checked again against the type itself, so a
     * single site's administrator cannot schedule work that runs for every site.
     *
     * @param   ExecutionContext  $context  Actor and site the decision is made for.
     * @param   string            $jobType  Job type the new schedule would dispatch.
     *
     * @return  void
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the context may not manage
     *          this installation-wide job type.
     *
     * @since   2.0.0
     */
    private function authorizeJobType(ExecutionContext $context, string $jobType): void
    {
        if (!$this->jobScope->isInstallationGlobal($jobType)) {
            return;
        }

        $this->authorization->assertAllowed(
            $context,
            Capability::fromString('automation.manage'),
            AuthorizationResource::item('automation_installation', $jobType),
        );
    }

    /**
     * Decide, without refusing, whether a listed schedule belongs in the caller's view.
     *
     * The resource put to the gateway follows the row's own execution scope: an installation-wide row
     * is judged by its job type, a site-local one by the schedule itself. This is the listing
     * counterpart of `authorizeRow()` — it answers rather than throws, so a row the caller may not see
     * is simply left out.
     *
     * @param   ExecutionContext      $context  Actor and site the decision is made for.
     * @param   array<string, mixed>  $row      Schedule row being considered for the listing.
     *
     * @return  bool  True when the context may manage the schedule the row describes.
     *
     * @throws  RuntimeException  When the row has no usable job type, execution scope or identifier.
     *
     * @since   2.0.0
     */
    private function canManageRow(ExecutionContext $context, array $row): bool
    {
        $jobType = $this->requiredString($row, 'job_type');
        $executionClass = $this->jobScope->assertStoredClass(
            $jobType,
            $this->requiredString($row, 'execution_scope'),
        );
        $resource = $executionClass === JobExecutionClass::Installation
            ? AuthorizationResource::item('automation_installation', $jobType)
            : AuthorizationResource::item('schedule', $this->requiredString($row, 'id'));

        return $this->authorization->decide(
            $context,
            Capability::fromString('automation.manage'),
            $resource,
        )->allowed;
    }

    /**
     * Demand management rights over a stored schedule and report the scope it runs in.
     *
     * The stored execution scope is checked against the one its job type declares before it is used,
     * so a row whose scope was tampered with cannot downgrade an installation-wide schedule into a
     * site-level check. Callers reuse the returned scope instead of classifying the row again.
     *
     * @param   ExecutionContext      $context  Actor and site the decision is made for.
     * @param   array<string, mixed>  $row      Schedule row the caller is about to act on.
     *
     * @return  JobExecutionClass  Whether the schedule is installation-wide or site-local.
     *
     * @throws  RuntimeException  When the row has no usable job type, execution scope or identifier.
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the context may not manage
     *          this schedule.
     *
     * @since   2.0.0
     */
    private function authorizeRow(ExecutionContext $context, array $row): JobExecutionClass
    {
        $jobType = $this->requiredString($row, 'job_type');
        $executionClass = $this->jobScope->assertStoredClass(
            $jobType,
            $this->requiredString($row, 'execution_scope'),
        );
        $this->authorize(
            $context,
            $executionClass === JobExecutionClass::Installation
                ? AuthorizationResource::item('automation_installation', $jobType)
                : AuthorizationResource::item('schedule', $this->requiredString($row, 'id')),
        );

        return $executionClass;
    }

    /**
     * Load the three columns an authorization decision about a schedule needs.
     *
     * Deliberately narrower than `find()`: a mutation only has to know that the schedule exists and
     * how it is scoped, so neither its payload nor its recurrence is read or normalized.
     *
     * @param   string  $id  Identifier of the schedule about to be changed.
     *
     * @return  array<string, mixed>  The `id`, `job_type` and `execution_scope` columns.
     *
     * @throws  InvalidArgumentException  When no schedule carries that identifier.
     *
     * @since   2.0.0
     */
    private function scheduleRow(string $id): array
    {
        $this->contributedSchedules?->synchronize();
        $row = $this->database->fetchAssociative(sprintf(
            'SELECT id, job_type, execution_scope, contribution_id FROM %s WHERE id = ? '
            . 'AND (contribution_id IS NULL OR contribution_active = ?)',
            $this->tables->quoted('schedules'),
        ), [$id, true], [Types::GUID, Types::BOOLEAN]);
        if ($row === false) {
            throw new InvalidArgumentException('The schedule does not exist.');
        }

        return $row;
    }

    /**
     * Hold the site row for the rest of the dispatch transaction, and report whether it is enabled.
     *
     * A matching row is taken `FOR UPDATE`, which is what makes the answer durable: the site cannot
     * then be disabled or retired between this check and the job insert, so no occurrence is enqueued
     * for a site already on its way out.
     *
     * @param   \Kumwe\Context\Value\SiteContext  $site  Site the schedule belongs to.
     *
     * @return  bool  True when the site exists and is enabled, false when it is missing or disabled.
     *
     * @since   2.0.0
     */
    private function lockEnabledSite(\Kumwe\Context\Value\SiteContext $site): bool
    {
        return $this->database->fetchOne(sprintf(
            'SELECT identifier FROM %s WHERE identifier = ? AND enabled = ? FOR UPDATE',
            $this->tables->quoted('sites'),
        ), [$site->identifier(), true], [Types::STRING, Types::BOOLEAN]) !== false;
    }
}
