<?php

declare(strict_types=1);

namespace Kumwe\App\Infrastructure\Persistence\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\JsonType;
use Doctrine\DBAL\Types\StringType;
use Doctrine\DBAL\Types\Types;
use JsonException;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use RuntimeException;

/**
 * Journals implicit-DDL migrations before they start and resumes only proven strategies.
 *
 * An interrupted immutable Core migration is replayed from its clean prefix-scoped baseline.
 * Application authorization is reconciled by a dedicated postcondition verifier because its
 * published migration is not repeatable. Current idempotent migrations and migrations explicitly
 * implementing RepeatableMigration are replayed in place. Every other interrupted migration fails
 * closed rather than guessing whether partially committed DDL is safe.
 *
 * This is the Doctrine implementation `MigrationRunner` reaches for on MySQL. It keeps at most one
 * open attempt at a time in a prefixed `migration_attempts` table, holding the migration's checksum,
 * the strategy it was classified under and whatever baseline that strategy needs to undo or verify an
 * interrupted run. Every read proves the journal's own schema first, so a table that has drifted from
 * the shape written here is reported rather than misread.
 *
 * @since  2.0.0
 */
final readonly class DoctrineNonTransactionalMigrationRecovery implements NonTransactionalMigrationRecovery
{
    /**
     * Logical name of the attempt journal, resolved through `TableNames` to this installation's prefix.
     *
     * @var    string
     * @since  2.0.0
     */
    private const TABLE = 'migration_attempts';
    /**
     * Strategy tag for the immutable Core migration: undo back to the journaled baseline, then replay.
     *
     * The `_v1` suffix is part of the contract, because the tag is stored in the attempt row and
     * re-checked on resume: a later build that reconstructs differently registers a new tag rather
     * than resuming from state this one wrote.
     *
     * @var    string
     * @since  2.0.0
     */
    private const STRATEGY_CORE = 'fresh_core_v1';
    /**
     * Strategy tag for the immutable application-authorization migration: rebuild, verify, never replay.
     *
     * Deliberately identical to `ApplicationAuthorizationMigrationRecovery::STRATEGY`, which stamps the
     * same value into the state it captures and refuses a snapshot carrying anything else.
     *
     * @var    string
     * @since  2.0.0
     */
    private const STRATEGY_APPLICATION_AUTHORIZATION = 'application_authorization_v1';
    /**
     * Strategy tag for a migration whose whole `up()` may simply be executed again.
     *
     * @var    string
     * @since  2.0.0
     */
    private const STRATEGY_REPEAT = 'repeatable_v1';
    /**
     * Strategy tag for everything else: an interrupted attempt stops the run and waits for an operator.
     *
     * @var    string
     * @since  2.0.0
     */
    private const STRATEGY_FAIL_CLOSED = 'fail_closed_v1';

    /**
     * SHA-256 of `CoreSchemaMigration` as released, which the baseline-and-replay strategy is pinned to.
     *
     * Core recovery drops tables, so it is granted only to the exact migration bytes this build was
     * released against; a Core migration whose checksum differs is refused instead of recovered.
     *
     * @var    string
     * @since  2.0.0
     */
    private const PUBLISHED_CORE_CHECKSUM = '40bf9c3fa708f153453cfbd6caf93c9cef806052eabb6a1bb8ad7a4b71e7dddf';
    /**
     * SHA-256 of `ApplicationAuthorizationMigration` as released, pinning the skip-`up()` strategy to it.
     *
     * That strategy records the migration as applied without ever running it again, so it is granted
     * only to the published bytes whose postcondition the paired verifier knows how to reconstruct. The
     * pin follows the published source exactly: when `KUMWE-MIG-2026-004` moved `SiteContext` to
     * `kumwe/access-context`, the migration changed only its import and this pin moved with it, while
     * the pre-move checksum stays accepted for databases migrated earlier.
     *
     * @var    string
     * @since  2.0.0
     */
    private const PUBLISHED_APPLICATION_AUTHORIZATION_CHECKSUM =
        'ec925040815c2e4f1069355be969982db4f515186e7734bdbbfdf3f9cb045be1';

    /**
     * IDs of already-released migrations whose `up()` is idempotent but which cannot say so in code.
     *
     * `RepeatableMigration` is the route open to anything written from now on. These three shipped
     * before that interface existed, and editing them to implement it would change the checksums
     * installed sites have already recorded, so recovery classifies them by ID instead.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private const CURRENT_REPEATABLE_MIGRATIONS = [
        JobRecoveryMigration::ID,
        AuthorizationRecoveryIntegrationMigration::ID,
        TokenAndTrustLifecycleMigration::ID,
    ];

    /**
     * Every logical table `CoreSchemaMigration` creates, and the only tables Core recovery may drop.
     *
     * `recoverFreshCore()` matches each prefixed table that appeared since the baseline against this
     * list and aborts without removing anything when one is not on it, so an undo cannot destroy a
     * table some other writer created inside the same prefix.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private const CORE_TABLES = [
        'administrator_sessions',
        'api_tokens',
        'audit_events',
        'capabilities',
        'content_entries',
        'content_revisions',
        'content_types',
        'extension_dependencies',
        'extension_migrations',
        'extension_releases',
        'extension_runtime_generation',
        'extension_trust_keys',
        'extensions',
        'failed_jobs',
        'idempotency',
        'jobs',
        'navigation_items',
        'navigation_menus',
        'password_credentials',
        'role_capability_grants',
        'roles',
        'schedules',
        'site_settings',
        'user_roles',
        'users',
        'worker_heartbeats',
        'workflow_states',
        'workflow_transitions',
        'workflows',
    ];

    /**
     * Bind recovery to the database it journals in and the verifier it delegates authorization repair to.
     *
     * @param  Connection                                 $database                  Connection every
     *         journal read, DDL statement and recovery write goes through, outside any transaction.
     * @param  TableNames                                 $tables                    Compiler turning
     *         logical names into this installation's prefixed identifiers; that prefix also bounds
     *         which tables Core recovery is allowed to see and drop.
     * @param  ApplicationAuthorizationMigrationRecovery  $applicationAuthorization  Verifier that
     *         captures and rebuilds the immutable application-authorization migration's postcondition.
     *
     * @since  2.0.0
     */
    public function __construct(
        private Connection $database,
        private TableNames $tables,
        private ApplicationAuthorizationMigrationRecovery $applicationAuthorization,
    ) {
    }

    /**
     * Refuse to continue when the journal holds an attempt for a migration this binary does not ship.
     *
     * Returns quietly when no journal table exists, which is the state of every database that has
     * never been interrupted. Otherwise the journal's own schema is proven before a single attempt is
     * read, so a rollback onto an older build stops here rather than stepping over a recovery it
     * cannot reason about.
     *
     * @param   list<string>  $knownMigrationIds  IDs of every migration in the deployed plan.
     *
     * @return  void
     *
     * @throws  RuntimeException  When the journal schema has diverged, or a journaled attempt names a
     *          migration outside the deployed plan.
     *
     * @since   2.0.0
     */
    public function assertKnownAttempts(array $knownMigrationIds): void
    {
        if (!$this->journalExists()) {
            return;
        }
        $this->assertJournalSchema();
        $attempts = $this->database->fetchFirstColumn(sprintf(
            'SELECT version FROM %s ORDER BY version',
            $this->tables->quoted(self::TABLE),
        ));
        foreach ($attempts as $attempt) {
            if (!is_string($attempt) || !in_array($attempt, $knownMigrationIds, true)) {
                throw new RuntimeException(sprintf(
                    'Migration recovery contains the unknown attempt "%s".',
                    is_scalar($attempt) ? (string) $attempt : 'invalid',
                ));
            }
        }
    }

    /**
     * Report whether any journaled attempt is still open.
     *
     * A database with no journal table has nothing unresolved, so its absence answers false rather than
     * failing. `ReadinessProbe` calls this on every platform, while the journal itself is only ever
     * created on one whose DDL commits implicitly, so the missing table is the ordinary case.
     *
     * @return  bool  True while at least one attempt row is present and has not been retired.
     *
     * @throws  RuntimeException  When the journal schema has diverged, or the driver returns a count
     *          that is neither an integer nor a string of decimal digits.
     *
     * @since   2.0.0
     */
    public function hasUnresolvedAttempts(): bool
    {
        if (!$this->journalExists()) {
            return false;
        }
        $this->assertJournalSchema();

        return $this->nonNegativeInteger($this->database->fetchOne(sprintf(
            'SELECT COUNT(*) FROM %s',
            $this->tables->quoted(self::TABLE),
        )), 'migration recovery attempt count') > 0;
    }

    /**
     * Journal the attempt about to start, and decide how an interrupted earlier one resumes.
     *
     * A first attempt stores the migration's checksum, its strategy and whatever baseline that strategy
     * will need — the current prefix-scoped table list for Core, the captured snapshot for application
     * authorization, nothing for the rest — and asks the runner to execute. A repeat attempt must match
     * the journaled checksum and strategy exactly before anything else happens; Core is then undone
     * back to its baseline and replayed, application authorization is rebuilt and verified so that
     * `up()` is skipped entirely, a repeatable migration is replayed in place, and anything else stops
     * the run for an operator.
     *
     * Only one attempt may be open across the whole installation, so an unresolved attempt for a
     * different migration blocks this one rather than being stepped over.
     *
     * @param   Migration  $migration  Migration whose attempt is being opened or resumed.
     *
     * @return  NonTransactionalMigrationAction  `Execute` when the runner must call `up()`,
     *          `RecordRecovered` when recovery has already restored the postcondition itself.
     *
     * @throws  RuntimeException  When another attempt is unresolved, an immutable migration's checksum
     *          is not the published one, the journaled checksum or strategy has drifted, the journaled
     *          baseline or state is unusable, or the interrupted migration is not repeatable.
     *
     * @since   2.0.0
     */
    public function prepare(Migration $migration): NonTransactionalMigrationAction
    {
        $this->ensureJournal();
        $this->assertOnlyAttempt($migration->id());
        $strategy = $this->strategy($migration);
        $attempt = $this->attempt($migration->id());

        if ($attempt === null) {
            $state = $strategy === self::STRATEGY_APPLICATION_AUTHORIZATION
                ? $this->applicationAuthorization->capture()
                : ['strategy' => $strategy];
            $now = $this->now();
            $this->database->insert($this->tables->raw(self::TABLE), [
                'version' => $migration->id(),
                'checksum' => $migration->checksum(),
                'baseline_tables' => $this->encode(
                    $strategy === self::STRATEGY_CORE ? $this->prefixedTables() : [],
                ),
                'recovery_state' => $this->encode($state),
                'started_at' => $now,
                'updated_at' => $now,
            ], [
                'version' => Types::STRING,
                'checksum' => Types::STRING,
                'baseline_tables' => Types::TEXT,
                'recovery_state' => Types::TEXT,
                'started_at' => Types::DATETIME_IMMUTABLE,
                'updated_at' => Types::DATETIME_IMMUTABLE,
            ]);

            return NonTransactionalMigrationAction::Execute;
        }

        $this->assertChecksum($migration, $attempt);
        $state = $this->state($attempt);
        if (($state['strategy'] ?? null) !== $strategy) {
            throw new RuntimeException(sprintf(
                'Interrupted migration recovery strategy drift detected for "%s".',
                $migration->id(),
            ));
        }

        if ($strategy === self::STRATEGY_CORE) {
            $this->recoverFreshCore($this->baseline($attempt));
        } elseif ($strategy === self::STRATEGY_APPLICATION_AUTHORIZATION) {
            $this->applicationAuthorization->recover($state);
            $this->touch($migration->id());

            return NonTransactionalMigrationAction::RecordRecovered;
        } elseif ($strategy === self::STRATEGY_FAIL_CLOSED) {
            throw new RuntimeException(sprintf(
                'Interrupted migration "%s" is not declared repeatable and requires operator recovery.',
                $migration->id(),
            ));
        }

        $this->touch($migration->id());

        return NonTransactionalMigrationAction::Execute;
    }

    /**
     * Retire the attempt now that the migration's ledger row has been written.
     *
     * Deliberately strict, because the journal is the only account of what an interrupted run left
     * behind: a missing attempt row, or a delete that removes anything other than exactly one row,
     * stops the pass rather than leaving behind a row that would block every later migration.
     *
     * @param   Migration  $migration  Migration whose attempt is being closed.
     *
     * @return  void
     *
     * @throws  RuntimeException  When no attempt is journaled for the migration, its journaled checksum
     *          has drifted, or the row could not be removed.
     *
     * @since   2.0.0
     */
    public function complete(Migration $migration): void
    {
        $attempt = $this->attempt($migration->id());
        if ($attempt === null) {
            throw new RuntimeException(sprintf(
                'The non-transactional migration attempt for "%s" is missing.',
                $migration->id(),
            ));
        }
        $this->assertChecksum($migration, $attempt);
        $deleted = $this->database->delete($this->tables->raw(self::TABLE), [
            'version' => $migration->id(),
        ]);
        if ($deleted !== 1) {
            throw new RuntimeException(sprintf(
                'The completed migration attempt for "%s" could not be retired.',
                $migration->id(),
            ));
        }
    }

    /**
     * Clear a leftover attempt for a migration the ledger already records as applied.
     *
     * Covers the window between the ledger write and `complete()`: the migration did finish, so the
     * stale row is deleted instead of driving a replay, and readiness stops reporting an unresolved
     * attempt. Doing nothing when no attempt is journaled is the usual case, since the runner calls
     * this for every already-applied migration on every MySQL pass.
     *
     * @param   Migration  $migration  Applied migration whose stale attempt is being cleared.
     *
     * @return  void
     *
     * @throws  RuntimeException  When the journal schema has diverged, the journaled checksum does not
     *          match the migration, or the row could not be removed.
     *
     * @since   2.0.0
     */
    public function reconcileApplied(Migration $migration): void
    {
        $this->ensureJournal();
        $attempt = $this->attempt($migration->id());
        if ($attempt === null) {
            return;
        }
        $this->assertChecksum($migration, $attempt);
        $deleted = $this->database->delete($this->tables->raw(self::TABLE), [
            'version' => $migration->id(),
        ]);
        if ($deleted !== 1) {
            throw new RuntimeException(sprintf(
                'The applied migration attempt for "%s" could not be reconciled.',
                $migration->id(),
            ));
        }
    }

    /**
     * Classify how an interrupted attempt at this migration would be allowed to resume.
     *
     * The two immutable published migrations are recognized by ID and then pinned to the checksum this
     * build shipped against, so a migration whose bytes no longer match cannot claim a strategy that
     * drops tables or skips `up()`. Everything else earns a replay only by implementing
     * `RepeatableMigration` or by appearing in `CURRENT_REPEATABLE_MIGRATIONS`; the remainder falls
     * through to failing closed.
     *
     * @param   Migration  $migration  Migration being journaled for the first time, or resumed.
     *
     * @return  string  One of the `STRATEGY_*` tags, stored in the attempt row and compared again on
     *          every resume.
     *
     * @throws  RuntimeException  When an immutable migration's checksum is not the published one.
     *
     * @since   2.0.0
     */
    private function strategy(Migration $migration): string
    {
        if ($migration->id() === CoreSchemaMigration::ID) {
            if (!hash_equals(self::PUBLISHED_CORE_CHECKSUM, $migration->checksum())) {
                throw new RuntimeException('The immutable Core migration checksum is not recognized.');
            }

            return self::STRATEGY_CORE;
        }
        if ($migration->id() === ApplicationAuthorizationMigration::ID) {
            if (!hash_equals(self::PUBLISHED_APPLICATION_AUTHORIZATION_CHECKSUM, $migration->checksum())) {
                throw new RuntimeException('The immutable application-authorization checksum is not recognized.');
            }

            return self::STRATEGY_APPLICATION_AUTHORIZATION;
        }
        if (
            $migration instanceof RepeatableMigration
            || in_array($migration->id(), self::CURRENT_REPEATABLE_MIGRATIONS, true)
        ) {
            return self::STRATEGY_REPEAT;
        }

        return self::STRATEGY_FAIL_CLOSED;
    }

    /**
     * Create the attempt journal when this installation has none, and prove its schema when it has one.
     *
     * The created table keys attempts by `version` and carries the checksum, the JSON baseline and
     * recovery-state payloads, and the two timestamps — the exact shape `assertJournalSchema()` insists
     * on afterwards.
     *
     * @return  void
     *
     * @throws  RuntimeException  When an existing journal table diverges from the shape written here.
     *
     * @since   2.0.0
     */
    private function ensureJournal(): void
    {
        $schema = $this->database->createSchemaManager();
        $name = $this->tables->raw(self::TABLE);
        if (!$schema->tablesExist([$name])) {
            $table = new Table($name);
            $table->addColumn('version', Types::STRING, ['length' => 191]);
            $table->addColumn('checksum', Types::STRING, ['length' => 64, 'fixed' => true]);
            $table->addColumn('baseline_tables', Types::JSON);
            $table->addColumn('recovery_state', Types::JSON);
            $table->addColumn('started_at', Types::DATETIME_IMMUTABLE);
            $table->addColumn('updated_at', Types::DATETIME_IMMUTABLE);
            $table->addPrimaryKeyConstraint(
                PrimaryKeyConstraint::editor()->setUnquotedColumnNames('version')->create(),
            );
            $schema->createTable($table);

            return;
        }

        $this->assertJournalSchema();
    }

    /**
     * Check whether this installation's prefix already carries the attempt journal.
     *
     * @return  bool  True when the table exists; false on a database that has never opened an attempt,
     *          which the read-only entry points treat as "nothing to recover" rather than an error.
     *
     * @since   2.0.0
     */
    private function journalExists(): bool
    {
        return $this->database->createSchemaManager()->tablesExist([$this->tables->raw(self::TABLE)]);
    }

    /**
     * Prove an existing journal is the table recovery writes, before any attempt is read out of it.
     *
     * Checks the exact column set, the `version` and `checksum` string columns with their declared
     * lengths and fixedness, both JSON payload columns, and a primary key over `version` alone. A
     * journal failing any of these could hold rows this code would misread into a decision to drop
     * tables, so it is refused rather than trusted.
     *
     * @return  void
     *
     * @throws  RuntimeException  When the column set, a column definition, or the primary key differs
     *          from what `ensureJournal()` creates.
     *
     * @since   2.0.0
     */
    private function assertJournalSchema(): void
    {
        $schema = $this->database->createSchemaManager();
        $name = $this->tables->raw(self::TABLE);

        $table = $schema->introspectTableByUnquotedName($name);
        $expected = ['baseline_tables', 'checksum', 'recovery_state', 'started_at', 'updated_at', 'version'];
        if (
            count($table->getColumns()) !== count($expected)
            || array_any($expected, static fn (string $column): bool => !$table->hasColumn($column))
        ) {
            throw new RuntimeException('The non-transactional migration journal schema is divergent.');
        }
        if (
            !$table->getColumn('version')->getType() instanceof StringType
            || $table->getColumn('version')->getLength() !== 191
            || !$table->getColumn('checksum')->getType() instanceof StringType
            || $table->getColumn('checksum')->getLength() !== 64
            || !$table->getColumn('checksum')->getFixed()
            || !$table->getColumn('baseline_tables')->getType() instanceof JsonType
            || !$table->getColumn('recovery_state')->getType() instanceof JsonType
        ) {
            throw new RuntimeException('The non-transactional migration journal columns are divergent.');
        }
        $primary = $table->getPrimaryKeyConstraint();
        if (
            $primary !== null
            && array_map(
                static fn (\Doctrine\DBAL\Schema\Name\UnqualifiedName $name): string =>
                    $name->getIdentifier()->getValue(),
                $primary->getColumnNames(),
            ) === ['version']
        ) {
            return;
        }
        throw new RuntimeException('The non-transactional migration journal primary key is divergent.');
    }

    /**
     * Refuse to open an attempt while some other migration's attempt is still unresolved.
     *
     * Two open attempts would mean two half-applied migrations with no ordering between their undo
     * paths, so the second one never starts and the operator deals with the first.
     *
     * @param   string  $id  Migration ID about to be journaled, the one attempt allowed to be open.
     *
     * @return  void
     *
     * @throws  RuntimeException  When the journal holds an attempt for any other migration.
     *
     * @since   2.0.0
     */
    private function assertOnlyAttempt(string $id): void
    {
        $other = $this->database->fetchOne(sprintf(
            'SELECT version FROM %s WHERE version <> ? ORDER BY version',
            $this->tables->quoted(self::TABLE),
        ), [$id]);
        if ($other !== false) {
            throw new RuntimeException(sprintf(
                'Migration recovery is blocked by the unresolved attempt "%s".',
                is_scalar($other) ? (string) $other : 'invalid',
            ));
        }
    }

    /**
     * Read the journaled attempt row for a migration, if one is open.
     *
     * @param   string  $id  Migration ID the attempt is keyed by.
     *
     * @return  array<string, mixed>|null  The `version`, `checksum`, `baseline_tables` and
     *          `recovery_state` columns as the driver hands them back, or null when no attempt is open.
     *
     * @since   2.0.0
     */
    private function attempt(string $id): ?array
    {
        $attempt = $this->database->fetchAssociative(sprintf(
            'SELECT version, checksum, baseline_tables, recovery_state FROM %s WHERE version = ?',
            $this->tables->quoted(self::TABLE),
        ), [$id]);

        return $attempt === false ? null : $attempt;
    }

    /**
     * Insist the journaled attempt was opened by the same migration code that is now resuming it.
     *
     * A deployment that changed a migration's body between the interruption and the resume would
     * otherwise recover a schema change nobody can name, so the two checksums are compared before any
     * undo, replay or retirement is allowed to proceed. The one compatibility exception is the exact
     * published constraint-name checksum: its immutable source remains present, while its same-ID wrapper
     * deliberately resumes the attempt through the corrected, shape-validating implementation.
     *
     * @param   Migration             $migration  Migration whose checksum the journal must agree with.
     * @param   array<string, mixed>  $attempt    Journaled attempt row, as `attempt()` returned it.
     *
     * @return  void
     *
     * @throws  RuntimeException  When the row carries no usable checksum, or one that differs from the
     *          migration's own.
     *
     * @since   2.0.0
     */
    private function assertChecksum(Migration $migration, array $attempt): void
    {
        $checksum = $attempt['checksum'] ?? null;
        if (is_string($checksum) && hash_equals($migration->checksum(), $checksum)) {
            return;
        }
        if (
            is_string($checksum)
            && $migration instanceof ConstraintNameIsolationCompatibilityMigration
            && hash_equals(ConstraintNameIsolationCompatibilityMigration::PUBLISHED_CHECKSUM, $checksum)
        ) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Interrupted migration checksum drift detected for "%s".',
            $migration->id(),
        ));
    }

    /**
     * Read back the prefix-scoped table list captured before the interrupted Core attempt started.
     *
     * Every entry is re-proved to sit inside the configured prefix. This list is what
     * `recoverFreshCore()` subtracts from the live schema to decide which tables to drop, so a payload
     * naming anything outside the installation is not one this code wrote and is refused outright
     * rather than allowed to shape that decision.
     *
     * @param   array<string, mixed>  $attempt  Journaled attempt row carrying `baseline_tables`.
     *
     * @return  list<string>  Physical table names that already existed when the attempt was opened;
     *          empty when the prefix held no tables at all, which is the fresh-install case.
     *
     * @throws  RuntimeException  When the payload is unusable, is not a list, or names a table outside
     *          the installation's prefix.
     *
     * @since   2.0.0
     */
    private function baseline(array $attempt): array
    {
        $baseline = $this->decode($attempt['baseline_tables'] ?? null, 'Core migration baseline');
        if (!array_is_list($baseline)) {
            throw new RuntimeException('The interrupted Core migration baseline is invalid.');
        }
        foreach ($baseline as $table) {
            if (!is_string($table) || !$this->hasPrefix($table)) {
                throw new RuntimeException('The interrupted Core migration baseline escapes its table prefix.');
            }
        }

        /** @var list<string> $baseline */
        return $baseline;
    }

    /**
     * Read back the recovery state the strategy journaled when the attempt was opened.
     *
     * The payload has been through JSON, so a list is rejected outright and every key is proven to be
     * a string before the map is handed to a strategy that will read named entries out of it.
     *
     * @param   array<string, mixed>  $attempt  Journaled attempt row carrying `recovery_state`.
     *
     * @return  array<string, mixed>  The decoded state: the strategy tag, plus whatever that strategy
     *          captured — for application authorization, the snapshot its verifier rebuilds from.
     *
     * @throws  RuntimeException  When the payload is unusable, or decodes to anything other than a
     *          string-keyed map.
     *
     * @since   2.0.0
     */
    private function state(array $attempt): array
    {
        $state = $this->decode($attempt['recovery_state'] ?? null, 'migration recovery state');
        if (array_is_list($state)) {
            throw new RuntimeException('The interrupted migration recovery state is invalid.');
        }

        foreach (array_keys($state) as $key) {
            if (!is_string($key)) {
                throw new RuntimeException('The interrupted migration recovery state is invalid.');
            }
        }

        /** @var array<string, mixed> $state */
        return $state;
    }

    /**
     * Undo an interrupted Core attempt back to its baseline so the migration can be replayed cleanly.
     *
     * Only tables that appeared inside this installation's prefix since the baseline are candidates,
     * and every one of them must be a table `CoreSchemaMigration` itself creates; a single unrecognized
     * name aborts the whole recovery before anything is removed. Foreign keys are then dropped across
     * all the candidates first, which is what makes the table drops that follow safe whatever order the
     * names happen to sort in. An interruption that created nothing new leaves this a no-op.
     *
     * @param   list<string>  $baseline  Prefixed table names present when the attempt was opened.
     *
     * @return  void
     *
     * @throws  RuntimeException  When a table created since the baseline is not one Core creates, in
     *          which case nothing is dropped, or when such a table carries an unnamed foreign key.
     *
     * @since   2.0.0
     */
    private function recoverFreshCore(array $baseline): void
    {
        $created = array_values(array_diff($this->prefixedTables(), $baseline));
        if ($created === []) {
            return;
        }

        $allowed = array_map(
            fn (string $table): string => $this->tables->raw($table),
            self::CORE_TABLES,
        );
        foreach ($created as $tableName) {
            if (!in_array($tableName, $allowed, true)) {
                throw new RuntimeException(sprintf(
                    'Interrupted Core recovery found the unexpected table "%s"; no data was removed.',
                    $tableName,
                ));
            }
        }

        $schema = $this->database->createSchemaManager();
        foreach ($created as $tableName) {
            $table = $schema->introspectTableByUnquotedName($tableName);
            foreach ($table->getForeignKeys() as $foreignKey) {
                $schema->dropForeignKey($this->constraintName($foreignKey), $tableName);
            }
        }
        foreach (array_reverse($created) as $tableName) {
            if ($schema->tablesExist([$tableName])) {
                $schema->dropTable($tableName);
            }
        }
    }

    /**
     * List the physical tables belonging to this installation, as introspection currently sees them.
     *
     * @return  list<string>  Unqualified table names carrying the configured prefix, sorted as strings
     *          so a baseline captured on one run compares directly against a later introspection.
     *
     * @since   2.0.0
     */
    private function prefixedTables(): array
    {
        $tables = array_values(array_filter(array_map(
            static fn (\Doctrine\DBAL\Schema\Name\OptionallyQualifiedName $table): string =>
                $table->getUnqualifiedName()->getValue(),
            $this->database->createSchemaManager()->introspectTableNames(),
        ), fn (string $table): bool => $this->hasPrefix($table)));
        sort($tables, SORT_STRING);

        return $tables;
    }

    /**
     * Decide whether a physical table name belongs to this installation's table prefix.
     *
     * The prefix is recovered from a name `TableNames` compiles rather than held as a second copy here,
     * so the two can never disagree. The empty-prefix guard is defensive: a blank prefix would match
     * every table in the database, and matching nothing is the safe answer for a check that decides
     * what Core recovery may drop.
     *
     * @param   string  $table  Unqualified physical table name, as introspection reports it.
     *
     * @return  bool  True when the name starts with the installation's non-empty prefix.
     *
     * @since   2.0.0
     */
    private function hasPrefix(string $table): bool
    {
        $ledger = $this->tables->raw('schema_migrations');
        $prefix = substr($ledger, 0, -strlen('schema_migrations'));

        return $prefix !== '' && str_starts_with($table, $prefix);
    }

    /**
     * Stamp a resumed attempt as still being worked on, and confirm its row survived the resume.
     *
     * An update that reports no rows changed is not by itself a failure — a driver reports that when
     * the timestamp it would write equals the one already stored — so the row is re-read and only a
     * genuinely vanished attempt stops the run.
     *
     * @param   string  $id  Migration ID of the attempt being kept open.
     *
     * @return  void
     *
     * @throws  RuntimeException  When the update matched nothing and the attempt row is gone.
     *
     * @since   2.0.0
     */
    private function touch(string $id): void
    {
        $updated = $this->database->update(
            $this->tables->raw(self::TABLE),
            ['updated_at' => $this->now()],
            ['version' => $id],
            ['updated_at' => Types::DATETIME_IMMUTABLE],
        );
        if ($updated !== 1 && $this->attempt($id) === null) {
            throw new RuntimeException(sprintf('The migration attempt "%s" lost its journal row.', $id));
        }
    }

    /**
     * Read the name a foreign key has to be dropped by while undoing an interrupted Core attempt.
     *
     * @param   ForeignKeyConstraint  $constraint  Constraint introspected from a table Core recovery is
     *          about to remove.
     *
     * @return  string  The unquoted constraint identifier.
     *
     * @throws  RuntimeException  When the constraint is anonymous and so cannot be dropped by name.
     *
     * @since   2.0.0
     */
    private function constraintName(ForeignKeyConstraint $constraint): string
    {
        $name = $constraint->getObjectName();
        if ($name === null) {
            throw new RuntimeException('Interrupted Core recovery found an unnamed foreign key.');
        }

        return $name->getIdentifier()->getValue();
    }

    /**
     * Serialize a journal payload for one of the attempt row's JSON columns.
     *
     * A JSON failure is translated into this class's operator-facing wording, with the original kept
     * as the previous exception, so callers only ever have to handle the recovery vocabulary.
     *
     * @param   array<mixed>  $value  Baseline table list, or recovery state map, about to be journaled.
     *
     * @return  string  JSON with slashes left unescaped, as stored in the attempt row.
     *
     * @throws  RuntimeException  When the value cannot be represented as JSON.
     *
     * @since   2.0.0
     */
    private function encode(array $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new RuntimeException('Migration recovery state could not be encoded.', 0, $exception);
        }
    }

    /**
     * Read a journal payload back out of an attempt row's JSON column.
     *
     * Decoding is capped at 32 levels, and the caller's label is what reaches the operator, so a
     * failure names the payload that is unusable rather than the column it happened to sit in.
     *
     * @param   mixed   $encoded  Raw column value, which has to be the JSON string the journal stored.
     * @param   string  $label    Name of the payload, used to phrase the failure for an operator.
     *
     * @return  array<mixed>  The decoded payload; `baseline()` and `state()` narrow it from here.
     *
     * @throws  RuntimeException  When the column is not a string, is not JSON within the depth limit,
     *          or decodes to something other than an array.
     *
     * @since   2.0.0
     */
    private function decode(mixed $encoded, string $label): array
    {
        if (!is_string($encoded)) {
            throw new RuntimeException(sprintf('The interrupted %s is invalid.', $label));
        }
        try {
            $decoded = json_decode($encoded, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException(sprintf('The interrupted %s is invalid.', $label), 0, $exception);
        }
        if (!is_array($decoded)) {
            throw new RuntimeException(sprintf('The interrupted %s is invalid.', $label));
        }

        return $decoded;
    }

    /**
     * Timestamp the attempt rows this journal opens and touches, in UTC.
     *
     * @return  \DateTimeImmutable  The current instant in UTC, so `started_at` and `updated_at` stay
     *          comparable across replicas whose local zones differ.
     *
     * @since   2.0.0
     */
    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    /**
     * Read a count a driver may hand back as either an integer or a numeric string.
     *
     * @param   mixed   $value  Raw column value from the journal's attempt-count query.
     * @param   string  $label  Name of the value, used to phrase the failure for an operator.
     *
     * @return  int  The value as an integer.
     *
     * @throws  RuntimeException  When the value is neither an integer nor a string of decimal digits.
     *
     * @since   2.0.0
     */
    private function nonNegativeInteger(mixed $value, string $label): int
    {
        if (!is_int($value) && (!is_string($value) || preg_match('/^[0-9]+$/D', $value) !== 1)) {
            throw new RuntimeException(sprintf('The %s is invalid.', $label));
        }

        return (int) $value;
    }
}
