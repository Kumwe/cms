<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessIntegration\Infrastructure;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Kumwe\App\Automation\Domain\CronExpression;
use Kumwe\App\Application\Automation\JobExecutionClass;
use Kumwe\App\Application\Automation\QueueRuntimePolicyCatalog;
use Kumwe\Transaction\Contract\TransactionManager;
use Kumwe\App\BusinessDefinition\Domain\CanonicalDefinitionJson;
use Kumwe\App\BusinessIntegration\Application\PayloadSchemaValidator;
use Kumwe\App\BusinessIntegration\Application\ScheduleRuntimeSynchronizer;
use Kumwe\Extension\Spi\BusinessIntegration\Domain\JobContributionDefinition;
use Kumwe\App\BusinessIntegration\Domain\ScheduleContributionDefinition;
use Kumwe\App\Extension\Contribution\ExtensionContributionRegistrySet;
use Kumwe\App\Extension\Runtime\RuntimeMaterializationState;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use RuntimeException;

/**
 * Reconciles package-owned schedule declarations into preserved core scheduler rows.
 *
 * Disabled or distrusted contributions are marked inactive and disappear from execution and operator
 * listings while their recurrence history remains. Reactivation reuses the deterministic row and its
 * last/next occurrence state; a definition change updates only signed configuration fields.
 *
 * @since  2.0.0
 */
final readonly class ContributedScheduleSynchronizer implements ScheduleRuntimeSynchronizer
{
    /**
     * Create the contributed schedule synchronizer.
     *
     * @param  Connection                        $database       Database connection used for durable state changes.
     * @param  TableNames                        $tables         Configured database table-name mapping.
     * @param  TransactionManager                $transactions   Transaction runner protecting the durable transition.
     * @param  ClockInterface                    $clock          Authoritative clock for schedule and lease timestamps.
     * @param  ExtensionContributionRegistrySet  $contributions  Active owner-bound runtime contribution registries.
     * @param  RuntimeMaterializationState       $runtime        Trusted active extension runtime.
     * @param  PayloadSchemaValidator            $payloads       Bounded payload-schema validator for contributed data.
     * @param  ?QueueRuntimePolicyCatalog        $queuePolicies  Active trusted queue and job attempt limits.
     *
     * @since  2.0.0
     */
    public function __construct(
        private Connection $database,
        private TableNames $tables,
        private TransactionManager $transactions,
        private ClockInterface $clock,
        private ExtensionContributionRegistrySet $contributions,
        private RuntimeMaterializationState $runtime,
        private PayloadSchemaValidator $payloads = new PayloadSchemaValidator(),
        private ?QueueRuntimePolicyCatalog $queuePolicies = null,
    ) {
    }

    /**
     * Synchronize only after the integration migration exists; pre-migration CLI boot is a safe no-op.
     *
     * @return  bool  True when the persistence schema was available and reconciled.
     *
     * @since   2.0.0
     */
    public function synchronize(): bool
    {
        $manager = $this->database->createSchemaManager();
        if (
            !$manager->tablesExist([
            $this->tables->raw('schedules'),
            $this->tables->raw('resource_site_ownership'),
            ])
        ) {
            return false;
        }
        $scheduleTable = $manager->introspectTableByUnquotedName($this->tables->raw('schedules'));
        if (!$scheduleTable->hasColumn('contribution_id')) {
            return false;
        }

        $jobs = [];
        foreach ($this->contributions->jobs()->definitions() as $definition) {
            if (!$definition instanceof JobContributionDefinition) {
                throw new RuntimeException('The trusted job registry contains an invalid definition.');
            }
            $jobs[$definition->identifier()] = $definition;
        }
        $schedules = [];
        foreach ($this->contributions->schedules()->definitions() as $definition) {
            if (!$definition instanceof ScheduleContributionDefinition) {
                throw new RuntimeException('The trusted schedule registry contains an invalid definition.');
            }
            $schedules[] = $definition;
        }

        $this->transactions->transactional(function () use ($jobs, $schedules): void {
            $this->database->executeStatement(sprintf(
                'UPDATE %s SET contribution_active = ?, updated_at = ? WHERE contribution_id IS NOT NULL',
                $this->tables->quoted('schedules'),
            ), [false, $this->clock->now()], [Types::BOOLEAN, Types::DATETIME_IMMUTABLE]);

            foreach ($schedules as $schedule) {
                $job = $jobs[$schedule->jobType()] ?? null;
                if (!$job instanceof JobContributionDefinition) {
                    throw new RuntimeException('A contributed schedule references an inactive job.');
                }
                $this->payloads->assertPayload($job->payloadSchema(), $schedule->payload());
                $site = $schedule->siteIdentifier();
                if ($job->installationWide() === ($site !== null)) {
                    throw new RuntimeException(
                        'A contributed schedule site must agree with its job execution scope.',
                    );
                }
                $this->upsert($schedule, $job, $this->runtime, $site);
            }
        });

        return true;
    }

    /**
     * Insert or update a contributed schedule without replacing its durable identity.
     *
     * @param   ScheduleContributionDefinition  $schedule  Signed contributed schedule being reconciled.
     * @param   JobContributionDefinition       $job       Signed job contract referenced by the contributed schedule.
     * @param   RuntimeMaterializationState     $runtime   Trusted active extension runtime.
     * @param   ?string                         $site      Site scope that owns the durable contribution row.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function upsert(
        ScheduleContributionDefinition $schedule,
        JobContributionDefinition $job,
        RuntimeMaterializationState $runtime,
        ?string $site,
    ): void {
        $now = $this->clock->now();
        $checksum = CanonicalDefinitionJson::checksum($schedule->toArray());
        $generation = $runtime->trusted ? (string) $runtime->generation : 'untrusted';
        $id = Uuid::uuid5(Uuid::NAMESPACE_URL, 'kumwe:schedule:' . $schedule->identifier())->toString();
        $row = $this->database->fetchAssociative(sprintf(
            'SELECT id, contribution_checksum, maximum_attempts FROM %s WHERE contribution_id = ?',
            $this->tables->quoted('schedules'),
        ), [$schedule->identifier()]);
        $scope = $job->installationWide() ? JobExecutionClass::Installation : JobExecutionClass::Site;
        $maximumAttempts = $this->queuePolicies?->maximumAttempts(
            $schedule->queue(),
            $job->identifier(),
            $job->maximumAttempts(),
        ) ?? $job->maximumAttempts();

        if ($row === false) {
            $this->database->insert($this->tables->raw('schedules'), [
                'id' => $id,
                'name' => $this->name($schedule->identifier()),
                'cron_expression' => $schedule->cronExpression(),
                'timezone' => $schedule->timezone(),
                'queue' => $schedule->queue(),
                'job_type' => $job->identifier(),
                'job_schema_version' => $job->schemaVersion(),
                'payload' => $schedule->payload(),
                'priority' => 0,
                'maximum_attempts' => $maximumAttempts,
                'enabled' => $schedule->enabled(),
                'next_run_at' => (new CronExpression($schedule->cronExpression()))->next(
                    $now,
                    $schedule->timezone(),
                ),
                'last_run_at' => null,
                'version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
                'execution_scope' => $scope->value,
                'contribution_id' => $schedule->identifier(),
                'contribution_checksum' => $checksum,
                'contribution_generation' => $generation,
                'contribution_active' => true,
            ], [
                'payload' => Types::JSON,
                'enabled' => Types::BOOLEAN,
                'next_run_at' => Types::DATETIME_IMMUTABLE,
                'created_at' => Types::DATETIME_IMMUTABLE,
                'updated_at' => Types::DATETIME_IMMUTABLE,
                'contribution_active' => Types::BOOLEAN,
            ]);
            $this->assertOwnership($id, $site);
            return;
        }
        if (($row['id'] ?? null) !== $id) {
            throw new RuntimeException('A contributed schedule identity is inconsistent.');
        }
        $changed = ($row['contribution_checksum'] ?? null) !== $checksum;
        $storedMaximum = $row['maximum_attempts'] ?? null;
        $policyChanged = (!is_int($storedMaximum) && !is_string($storedMaximum))
            || (int) $storedMaximum !== $maximumAttempts;
        $values = [
            'contribution_active' => true,
            'contribution_generation' => $generation,
            'maximum_attempts' => $maximumAttempts,
            'updated_at' => $now,
        ];
        $types = [
            'contribution_active' => Types::BOOLEAN,
            'updated_at' => Types::DATETIME_IMMUTABLE,
        ];
        if ($changed) {
            $values += [
                'cron_expression' => $schedule->cronExpression(),
                'timezone' => $schedule->timezone(),
                'queue' => $schedule->queue(),
                'job_type' => $job->identifier(),
                'job_schema_version' => $job->schemaVersion(),
                'payload' => $schedule->payload(),
                'enabled' => $schedule->enabled(),
                'execution_scope' => $scope->value,
                'contribution_checksum' => $checksum,
            ];
            $types['payload'] = Types::JSON;
            $types['enabled'] = Types::BOOLEAN;
        }
        $this->database->update($this->tables->raw('schedules'), $values, ['id' => $id], $types);
        if ($changed || $policyChanged) {
            $this->database->executeStatement(sprintf(
                'UPDATE %s SET version = version + 1 WHERE id = ?',
                $this->tables->quoted('schedules'),
            ), [$id], [Types::GUID]);
        }
        $this->assertOwnership($id, $site);
    }

    /**
     * Require the durable row to remain owned by its signed contribution.
     *
     * @param   string   $id    Stable identifier of the durable record being addressed.
     * @param   ?string  $site  Site scope that owns the durable contribution row.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function assertOwnership(string $id, ?string $site): void
    {
        $stored = $this->database->fetchOne(sprintf(
            'SELECT site_identifier FROM %s WHERE resource_type = ? AND resource_id = ?',
            $this->tables->quoted('resource_site_ownership'),
        ), ['schedule', $id]);
        if ($site === null) {
            if ($stored !== false) {
                throw new RuntimeException('An installation-wide contributed schedule has site ownership.');
            }
            return;
        }
        if ($stored === false) {
            $this->database->insert($this->tables->raw('resource_site_ownership'), [
                'resource_type' => 'schedule',
                'resource_id' => $id,
                'site_identifier' => $site,
            ]);
            return;
        }
        if ($stored !== $site) {
            throw new RuntimeException('A contributed schedule cannot move between sites.');
        }
    }

    /**
     * Derive a bounded scheduler row name from the contribution identifier.
     *
     * @param   string  $identifier  Stable namespaced identifier to render or persist.
     *
     * @return  string  Bounded scheduler row name with a collision-resistant suffix.
     *
     * @since   2.0.0
     */
    private function name(string $identifier): string
    {
        return substr('Extension: ' . $identifier, 0, 143) . ' [' . substr(hash('sha256', $identifier), 0, 12) . ']';
    }
}
