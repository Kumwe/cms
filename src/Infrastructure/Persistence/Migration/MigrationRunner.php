<?php

declare(strict_types=1);

namespace Kumwe\App\Infrastructure\Persistence\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Kumwe\App\Application\Authorization\AuthorizationGateway;
use Kumwe\App\Application\Authorization\AuthorizationResource;
use Kumwe\App\Application\Persistence\TransactionManager;
use Kumwe\App\Infrastructure\Persistence\SchemaCollationConvergence;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Extension\Spi\Identity\Domain\Capability;

/**
 * Applies the schema migrations this binary ships and records each one in the ledger.
 *
 * This is the only writer of the migration ledger. Both entry points authorize `system.migrate` before
 * they read anything, and `migrate()` holds `MigrationLock` for the whole pass, so replicas booting
 * together cannot apply the same DDL or race each other into the ledger. Compatibility is asserted
 * before the first migration runs, which is what stops an older binary from migrating a database a
 * newer one has already advanced. How each migration is wrapped depends on the platform: PostgreSQL
 * commits the migration and its ledger row together, MySQL cannot because DDL commits implicitly and
 * instead journals the attempt through `NonTransactionalMigrationRecovery`, and any other platform
 * runs the pair unwrapped.
 *
 * @since  2.0.0
 */
final readonly class MigrationRunner
{
    /**
     * Wire the runner to the database it migrates and the guards a pass runs behind.
     *
     * @param  Connection                         $database                  Database being migrated; its
     *         platform selects how each migration is wrapped.
     * @param  MigrationRepository                $repository                Ledger read to find pending work
     *         and appended to per applied migration.
     * @param  MigrationLock                      $lock                      Cluster-wide exclusion held for
     *         the whole of a `migrate()` pass.
     * @param  TransactionManager                 $transactions              Commits a migration and its
     *         ledger row as one unit on PostgreSQL.
     * @param  MigrationPlan                      $plan                      Ordered migrations this binary
     *         ships, and the ledger-compatibility gate.
     * @param  AuthorizationGateway               $authorization             Decides whether the caller may
     *         exercise `system.migrate`.
     * @param  NonTransactionalMigrationRecovery  $nonTransactionalRecovery  Journals and resumes attempts
     *         where DDL commits implicitly.
     * @param  ?SchemaCollationConvergence        $collation                 Converges every application
     *         table on the database default collation once the plan has run, still under the lock; null
     *         leaves the schema exactly as the migrations wrote it.
     *
     * @since  2.0.0
     */
    public function __construct(
        private Connection $database,
        private MigrationRepository $repository,
        private MigrationLock $lock,
        private TransactionManager $transactions,
        private MigrationPlan $plan,
        private AuthorizationGateway $authorization,
        private NonTransactionalMigrationRecovery $nonTransactionalRecovery,
        private ?SchemaCollationConvergence $collation = null,
    ) {
    }

    /**
     * Apply every migration the ledger does not already record, in plan order.
     *
     * The caller is authorized first, then the whole pass runs under the migration lock: the ledger
     * table is created when absent, the recovery journal is rejected if it holds an attempt this binary
     * does not ship, and the ledger is proven an exact prefix of the plan before any DDL runs. On MySQL
     * a migration already in the ledger is still reconciled, so a stale attempt left by a crash between
     * the ledger write and the journal cleanup is retired instead of replayed. Once the plan has run,
     * and before the lock is released, every application table is converged on the database default
     * collation, so a server whose character-set default differs from the database default cannot leave
     * the schema split between two collations; a consistent schema is read and left untouched.
     *
     * @param   ExecutionContext  $context  Actor, site and provenance the run is authorized and audited
     *          under.
     *
     * @return  MigrationResult  The IDs this pass recorded, empty when the ledger was already current,
     *          together with the tables converged on the database default collation.
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor may not exercise
     *          `system.migrate` over the database schema.
     * @throws  \RuntimeException  When the lock is held elsewhere, the ledger does not match the plan, a
     *          recorded checksum has drifted, or an interrupted migration has no proven way to resume.
     *
     * @since   2.0.0
     */
    public function migrate(ExecutionContext $context): MigrationResult
    {
        $this->authorize($context);
        return $this->lock->synchronized(function (): MigrationResult {
            $this->repository->ensureLedger();
            $applied = $this->repository->applied();
            $this->nonTransactionalRecovery->assertKnownAttempts($this->plan->ids());
            $this->plan->assertCompatible($applied);
            $completed = [];

            foreach ($this->plan->all() as $migration) {
                $id = $migration->id();
                $checksum = $migration->checksum();

                if (isset($applied[$id])) {
                    if ($this->database->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
                        $this->nonTransactionalRecovery->reconcileApplied($migration);
                    }

                    continue;
                }

                $started = hrtime(true);
                $operation = function () use ($migration, $id, $checksum, $started): void {
                    $migration->up($this->database);
                    $elapsed = max(0, (int) round((hrtime(true) - $started) / 1_000_000));
                    $this->repository->record($id, $checksum, $elapsed);
                };

                $platform = $this->database->getDatabasePlatform();
                if ($platform instanceof PostgreSQLPlatform) {
                    $this->transactions->transactional($operation);
                } elseif ($platform instanceof AbstractMySQLPlatform) {
                    $action = $this->nonTransactionalRecovery->prepare($migration);
                    if ($action === NonTransactionalMigrationAction::Execute) {
                        $migration->up($this->database);
                    }
                    $elapsed = max(0, (int) round((hrtime(true) - $started) / 1_000_000));
                    $this->repository->record($id, $checksum, $elapsed);
                    $this->nonTransactionalRecovery->complete($migration);
                } else {
                    $operation();
                }
                $completed[] = $id;
            }

            $converged = $this->collation?->converge() ?? [];

            return new MigrationResult($completed, $converged, $converged === [] ? null : $this->collation?->target());
        });
    }

    /**
     * List the migrations this database has still to run, without applying any of them.
     *
     * `MigrationStatusCommand` reports from this, so it performs the same authorization, journal and
     * ledger-compatibility checks as a real run and fails on a drifted schema before anyone reaches the
     * command that would write. No lock is taken, but the ledger table is created when it is missing —
     * the one side effect of an otherwise read-only call.
     *
     * @param   ExecutionContext  $context  Actor, site and provenance the check is authorized and audited
     *          under.
     *
     * @return  list<Migration>  The tail of the plan the ledger does not cover, in apply order; empty
     *          when the schema is current.
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor may not exercise
     *          `system.migrate` over the database schema.
     * @throws  \RuntimeException  When the recovery journal holds an unknown attempt, the ledger is not an
     *          exact prefix of the plan, or a recorded checksum has drifted.
     *
     * @since   2.0.0
     */
    public function pending(ExecutionContext $context): array
    {
        $this->authorize($context);
        $this->repository->ensureLedger();
        $this->nonTransactionalRecovery->assertKnownAttempts($this->plan->ids());

        return $this->plan->pending($this->repository->applied());
    }

    /**
     * Require `system.migrate` over the schema collection before a call reads or writes anything.
     *
     * @param   ExecutionContext  $context  Actor, site and provenance the capability is evaluated for.
     *
     * @return  void
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When policy refuses the actor
     *          this capability.
     *
     * @since   2.0.0
     */
    private function authorize(ExecutionContext $context): void
    {
        $this->authorization->assertAllowed(
            $context,
            Capability::fromString('system.migrate'),
            AuthorizationResource::collection('database_schema'),
        );
    }
}
