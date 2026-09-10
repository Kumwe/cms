<?php

declare(strict_types=1);

namespace Kumwe\App\Infrastructure\Persistence\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\ForeignKeyConstraint\ReferentialAction;
use Doctrine\DBAL\Schema\Index\IndexType;
use Doctrine\DBAL\Schema\Name\UnqualifiedName;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\DateTimeImmutableType;
use Doctrine\DBAL\Types\DateTimeType;
use Doctrine\DBAL\Types\IntegerType;
use Doctrine\DBAL\Types\StringType;
use Doctrine\DBAL\Types\Types;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\Context\Value\SiteContext;
use Ramsey\Uuid\Uuid;
use RuntimeException;

/**
 * Completes the immutable application-authorization migration after implicit-DDL interruption.
 *
 * The original migration remains byte-for-byte unchanged. This recovery path reconstructs its
 * deterministic postcondition from state captured before the first attempt, then verifies every
 * security-sensitive schema and ownership invariant before the runner records the original hash.
 *
 * `DoctrineNonTransactionalMigrationRecovery` owns the pairing: it calls `capture()` into the migration
 * journal before the first attempt, and `recover()` when it finds a journalled attempt on resume. Once
 * `recover()` returns, the runner records the migration as applied without ever executing `up()` again,
 * which matters because `ApplicationAuthorizationMigration` seeds rows and increments epochs and so is
 * not safe to replay.
 *
 * @since  2.0.0
 */
final readonly class ApplicationAuthorizationMigrationRecovery
{
    /**
     * Strategy tag stamped into the captured state, mirroring the recovery journal's own constant.
     *
     * The journal stores this alongside the attempt row and both sides re-check it on resume, so a
     * state written by a build that reconstructed the postcondition differently is rejected rather
     * than replayed through this one.
     *
     * @var    string
     * @since  2.0.0
     */
    private const STRATEGY = 'application_authorization_v1';

    /**
     * Resource types the migration backfills into `resource_site_ownership`, and where their ids live.
     *
     * Copied from `ApplicationAuthorizationMigration` and required to stay identical to it: this list
     * defines both what `reconcileOwnership()` inserts and what `verifyOwnership()` will accept as a
     * complete postcondition. Keyed by ownership `resource_type`; each entry names the logical table to
     * read and the column holding that resource's identifier.
     *
     * @var    array<string, array{table: string, column: string}>
     * @since  2.0.0
     */
    private const OWNED_RESOURCES = [
        'administrator_session' => ['table' => 'administrator_sessions', 'column' => 'id'],
        'api_token' => ['table' => 'api_tokens', 'column' => 'id'],
        'capability' => ['table' => 'capabilities', 'column' => 'code'],
        'content' => ['table' => 'content_entries', 'column' => 'id'],
        'extension' => ['table' => 'extensions', 'column' => 'identifier'],
        'grant' => ['table' => 'role_capability_grants', 'column' => 'id'],
        'job' => ['table' => 'jobs', 'column' => 'id'],
        'menu' => ['table' => 'navigation_menus', 'column' => 'id'],
        'menu_item' => ['table' => 'navigation_items', 'column' => 'id'],
        'role' => ['table' => 'roles', 'column' => 'id'],
        'schedule' => ['table' => 'schedules', 'column' => 'id'],
        'user' => ['table' => 'users', 'column' => 'id'],
    ];

    /**
     * Capabilities the migration seeds, keyed by capability code and mapped to their description.
     *
     * These are the rows `capture()` insists are still absent before it accepts a baseline, and the
     * rows `reconcileCapabilitiesAndGrants()` inserts or verifies. The descriptions must match
     * `ApplicationAuthorizationMigration` exactly, because a stored row that differs aborts recovery.
     *
     * @var    array<string, string>
     * @since  2.0.0
     */
    private const CAPABILITIES = [
        'themes.administrator.manage' => 'Manage administrator presentation themes.',
        'themes.site.manage' => 'Manage public site presentation themes.',
    ];

    /**
     * Bind the reconstruction to the connection and name compiler the migration runner is using.
     *
     * @param  Connection  $database  Connection the interrupted migration ran on; every read, DDL
     *         statement and reconciling write goes through it, outside any transaction.
     * @param  TableNames  $tables    Compiler turning logical table names into this installation's
     *         prefixed physical identifiers.
     *
     * @since  2.0.0
     */
    public function __construct(private Connection $database, private TableNames $tables)
    {
    }

    /**
     * Snapshot the untouched baseline that the later reconstruction will be measured against.
     *
     * Called once, before the first attempt, so the journal holds a durable record of what the
     * migration is about to change. It accepts only a clean parent schema: every table the migration
     * depends on must exist, none of the schema or seed rows it adds may be present yet, and
     * each administrator must resolve to the epoch the migration would raise them to — the starting
     * epoch of 1 plus one increment per administrator role that user holds.
     *
     * @return  array{
     *              strategy: string,
     *              administrator_roles: list<string>,
     *              administrator_security_epochs: list<array{user_id: string, epoch: int}>
     *          }  Roles ordered by id and epoch entries ordered by user id, so the journalled state
     *          round-trips canonically; the epoch list is empty when no user holds an administrator
     *          role.
     *
     * @throws  RuntimeException  When a parent table is missing, when the schema or seed data is
     *          already partly migrated, or when an administrator assignment row is unusable.
     *
     * @since   2.0.0
     */
    public function capture(): array
    {
        $manager = $this->database->createSchemaManager();
        $required = [
            'users',
            'roles',
            'user_roles',
            'capabilities',
            'role_capability_grants',
            'idempotency',
        ];
        foreach ($required as $table) {
            if (!$manager->tablesExist([$this->tables->raw($table)])) {
                throw new RuntimeException(sprintf(
                    'Application-authorization recovery cannot capture a parent schema without "%s".',
                    $table,
                ));
            }
        }

        $users = $manager->introspectTableByUnquotedName($this->tables->raw('users'));
        $idempotency = $manager->introspectTableByUnquotedName($this->tables->raw('idempotency'));
        if (
            $users->hasColumn('security_epoch')
            || $manager->tablesExist([$this->tables->raw('sites')])
            || $manager->tablesExist([$this->tables->raw('resource_site_ownership')])
            || $idempotency->hasColumn('authorization_fingerprint')
            || $idempotency->hasColumn('lease_owner')
            || $idempotency->hasColumn('lease_expires_at')
        ) {
            throw new RuntimeException(
                'The application-authorization parent schema is already partial; recovery has no durable baseline.',
            );
        }

        foreach (array_keys(self::CAPABILITIES) as $capability) {
            if (
                $this->database->fetchOne(sprintf(
                    'SELECT code FROM %s WHERE code = ?',
                    $this->tables->quoted('capabilities'),
                ), [$capability]) !== false
            ) {
                throw new RuntimeException(
                    'The application-authorization parent data already contains migration-owned capabilities.',
                );
            }
        }

        $roles = $this->administratorRoleIds();
        $epochs = [];
        $rows = $this->database->fetchAllAssociative(sprintf(
            'SELECT ur.user_id, COUNT(*) AS role_count FROM %s ur INNER JOIN %s r ON r.id = ur.role_id '
            . 'WHERE r.code = ? GROUP BY ur.user_id ORDER BY ur.user_id',
            $this->tables->quoted('user_roles'),
            $this->tables->quoted('roles'),
        ), ['administrator']);
        foreach ($rows as $row) {
            $userId = $row['user_id'] ?? null;
            $count = $this->nonNegativeInteger($row['role_count'] ?? null, 'administrator role count');
            if (!is_string($userId) || $userId === '' || $count < 1) {
                throw new RuntimeException('The administrator security-epoch baseline is invalid.');
            }
            $epochs[] = ['user_id' => $userId, 'epoch' => 1 + $count];
        }

        return [
            'strategy' => self::STRATEGY,
            'administrator_roles' => $roles,
            'administrator_security_epochs' => $epochs,
        ];
    }

    /**
     * Rebuild the interrupted migration's postcondition and prove it before the runner records it.
     *
     * Runs against a database that may hold any prefix of the migration's work, so every step is
     * written to be resumable: schema objects are created only when absent and seed rows inserted only
     * when missing, while anything already present that differs from what the migration would have
     * written aborts recovery instead of being overwritten. The idempotency table is emptied before
     * the schema work, because a replay recorded before the authorization-bound lease existed can no
     * longer be honoured.
     *
     * Returning normally means both the schema and the ownership postconditions have been verified.
     *
     * @param   array<string, mixed>  $state  The snapshot `capture()` produced, as decoded from the
     *          recovery journal.
     *
     * @return  void
     *
     * @throws  RuntimeException  When the state is not canonical, when the administrator roles or
     *          assignments moved since capture, when an already-written object diverges from what the
     *          migration would have written, or when a postcondition check fails.
     *
     * @since   2.0.0
     */
    public function recover(array $state): void
    {
        [$roles, $epochs] = $this->state($state);
        if ($roles !== $this->administratorRoleIds()) {
            throw new RuntimeException('Administrator roles changed during application-authorization recovery.');
        }

        $this->assertAdministratorUsers($epochs);
        $this->database->executeStatement(sprintf('DELETE FROM %s', $this->tables->quoted('idempotency')));
        $this->ensureSchema();
        $this->reconcileSite();
        $this->reconcileCapabilitiesAndGrants($roles);
        $this->reconcileSecurityEpochs($epochs);
        $this->reconcileOwnership();
        $this->verifySchema();
        $this->verifyOwnership();
    }

    /**
     * Validate a journalled snapshot and split it into the two collections recovery works from.
     *
     * The snapshot has been through JSON, so nothing about its shape survives on trust. Beyond types,
     * the canonical ordering `capture()` produced is re-checked — roles sorted and unique, epoch
     * entries carrying exactly `user_id` and `epoch` and keyed by user id in sorted order — so a
     * reordered or hand-edited journal row is rejected rather than quietly changing the target state.
     *
     * @param   array<string, mixed>  $state  Decoded journal payload, expected to carry this class's
     *          strategy tag.
     *
     * @return  array{0: list<string>, 1: array<string, int>}  Administrator role ids in captured
     *          order, and the target security epoch for each administrator user id.
     *
     * @throws  RuntimeException  When the strategy tag, a role, or an epoch entry is missing,
     *          malformed, duplicated, or out of canonical order.
     *
     * @since   2.0.0
     */
    private function state(array $state): array
    {
        if (
            ($state['strategy'] ?? null) !== self::STRATEGY
            || !is_array($state['administrator_roles'] ?? null)
            || !array_is_list($state['administrator_roles'])
            || !is_array($state['administrator_security_epochs'] ?? null)
            || !array_is_list($state['administrator_security_epochs'])
        ) {
            throw new RuntimeException('The application-authorization recovery state is invalid.');
        }

        $roles = [];
        foreach ($state['administrator_roles'] as $role) {
            if (!is_string($role) || $role === '') {
                throw new RuntimeException('The application-authorization recovery role is invalid.');
            }
            $roles[] = $role;
        }
        $sortedRoles = $roles;
        sort($sortedRoles, SORT_STRING);
        if ($roles !== $sortedRoles || count($roles) !== count(array_unique($roles))) {
            throw new RuntimeException('The application-authorization recovery roles are not canonical.');
        }

        $epochs = [];
        foreach ($state['administrator_security_epochs'] as $entry) {
            if (!is_array($entry) || array_is_list($entry)) {
                throw new RuntimeException('The application-authorization recovery epoch is invalid.');
            }
            $userId = $entry['user_id'] ?? null;
            $epoch = $entry['epoch'] ?? null;
            $keys = array_keys($entry);
            sort($keys, SORT_STRING);
            if (
                !is_string($userId) || $userId === '' || !is_int($epoch) || $epoch < 2
                || $keys !== ['epoch', 'user_id'] || isset($epochs[$userId])
            ) {
                throw new RuntimeException('The application-authorization recovery epoch is invalid.');
            }
            $epochs[$userId] = $epoch;
        }
        $ordered = array_keys($epochs);
        $sorted = $ordered;
        sort($sorted, SORT_STRING);
        if ($ordered !== $sorted) {
            throw new RuntimeException('The application-authorization recovery epochs are not canonical.');
        }

        return [$roles, $epochs];
    }

    /**
     * Read the ids of every role carrying the `administrator` code.
     *
     * @return  list<string>  Role ids in ascending id order, the same order `capture()` stored, so the
     *          captured and current lists can be compared directly. Empty when the installation has no
     *          administrator role.
     *
     * @throws  RuntimeException  When a stored role identifier is not a non-empty string.
     *
     * @since   2.0.0
     */
    private function administratorRoleIds(): array
    {
        $values = $this->database->fetchFirstColumn(sprintf(
            'SELECT id FROM %s WHERE code = ? ORDER BY id',
            $this->tables->quoted('roles'),
        ), ['administrator']);
        $roles = [];
        foreach ($values as $value) {
            if (!is_string($value) || $value === '') {
                throw new RuntimeException('An administrator role identifier is invalid.');
            }
            $roles[] = $value;
        }

        return $roles;
    }

    /**
     * Confirm the current administrator assignments still imply exactly the captured epochs.
     *
     * Recomputes the target epoch for every current administrator and compares the whole map at once.
     * A user granted or stripped of an administrator role between the interrupted attempt and the
     * resume would make the captured epochs wrong, so recovery stops rather than writing a value that
     * no longer reflects the user's privileges.
     *
     * @param   array<string, int>  $epochs  Target security epoch per user id, as captured.
     *
     * @return  void
     *
     * @throws  RuntimeException  When an assignment row is unusable, or the recomputed map differs
     *          from the captured one.
     *
     * @since   2.0.0
     */
    private function assertAdministratorUsers(array $epochs): void
    {
        $rows = $this->database->fetchAllAssociative(sprintf(
            'SELECT ur.user_id, COUNT(*) AS role_count FROM %s ur INNER JOIN %s r ON r.id = ur.role_id '
            . 'WHERE r.code = ? GROUP BY ur.user_id ORDER BY ur.user_id',
            $this->tables->quoted('user_roles'),
            $this->tables->quoted('roles'),
        ), ['administrator']);
        $current = [];
        foreach ($rows as $row) {
            $userId = $row['user_id'] ?? null;
            $count = $this->nonNegativeInteger($row['role_count'] ?? null, 'administrator role count');
            if (!is_string($userId) || $userId === '' || $count < 1) {
                throw new RuntimeException('The current administrator assignment is invalid.');
            }
            $current[$userId] = 1 + $count;
        }
        if ($current !== $epochs) {
            throw new RuntimeException('Administrator assignments changed during application-authorization recovery.');
        }
    }

    /**
     * Add whatever part of the migration's schema change is still missing, and nothing else.
     *
     * The introspected schema is checked for compatibility first, so an object the interrupted attempt
     * already created is only accepted when it matches the migration's definition exactly. A clone is
     * then extended with the missing pieces — the `security_epoch` column, the `sites` and
     * `resource_site_ownership` tables with their lookup index and cascading foreign key, and the three
     * idempotency lease columns — and the comparator's ALTER statements are executed one at a time.
     *
     * @return  void
     *
     * @throws  RuntimeException  When an object the interrupted attempt already created diverges from
     *          the migration's definition.
     *
     * @since   2.0.0
     */
    private function ensureSchema(): void
    {
        $manager = $this->database->createSchemaManager();
        $before = $manager->introspectSchema();
        $this->assertCompatiblePartialSchema($before);
        $after = clone $before;

        $users = $after->getTable($this->tables->raw('users'));
        if (!$users->hasColumn('security_epoch')) {
            $users->addColumn('security_epoch', Types::INTEGER, ['default' => 1]);
        }

        $sitesName = $this->tables->raw('sites');
        if (!$after->hasTable($sitesName)) {
            $sites = $after->createTable($sitesName);
            $sites->addColumn('identifier', Types::STRING, ['length' => 191]);
            $sites->addColumn('name', Types::STRING, ['length' => 191]);
            $sites->addColumn('created_at', Types::DATETIME_IMMUTABLE);
            $sites->addPrimaryKeyConstraint(
                PrimaryKeyConstraint::editor()->setUnquotedColumnNames('identifier')->create(),
            );
        }

        $ownershipName = $this->tables->raw('resource_site_ownership');
        if (!$after->hasTable($ownershipName)) {
            $ownership = $after->createTable($ownershipName);
            $ownership->addColumn('resource_type', Types::STRING, ['length' => 63]);
            $ownership->addColumn('resource_id', Types::STRING, ['length' => 191]);
            $ownership->addColumn('site_identifier', Types::STRING, ['length' => 191]);
            $ownership->addPrimaryKeyConstraint(
                PrimaryKeyConstraint::editor()
                    ->setUnquotedColumnNames('resource_type', 'resource_id')
                    ->create(),
            );
        }
        $ownership = $after->getTable($ownershipName);
        if (!$ownership->hasIndex('idx_resource_site')) {
            $ownership->addIndex(['site_identifier', 'resource_type'], 'idx_resource_site');
        }
        if ($this->ownershipForeignKey($ownership) === null) {
            $ownership->addForeignKeyConstraint(
                $sitesName,
                ['site_identifier'],
                ['identifier'],
                ['onDelete' => 'CASCADE'],
                'fk_resource_site_' . substr(hash('sha256', $ownershipName), 0, 16),
            );
        }

        $idempotency = $after->getTable($this->tables->raw('idempotency'));
        if (!$idempotency->hasColumn('authorization_fingerprint')) {
            $idempotency->addColumn(
                'authorization_fingerprint',
                Types::STRING,
                ['length' => 64, 'fixed' => true],
            );
        }
        if (!$idempotency->hasColumn('lease_owner')) {
            $idempotency->addColumn('lease_owner', Types::STRING, ['length' => 64]);
        }
        if (!$idempotency->hasColumn('lease_expires_at')) {
            $idempotency->addColumn('lease_expires_at', Types::DATETIME_IMMUTABLE);
        }

        $difference = $manager->createComparator()->compareSchemas($before, $after);
        foreach ($this->database->getDatabasePlatform()->getAlterSchemaSQL($difference) as $statement) {
            $this->database->executeStatement($statement);
        }
    }

    /**
     * Check every object the migration creates, where it already exists, against its definition.
     *
     * Absent objects are ignored by design — the schema is partial by construction — but anything
     * present must match the migration exactly: column type, length, fixedness, nullability and
     * default, the full column set of each new table, its primary key, and the ownership index and
     * cascading foreign key where those have been created. It is used twice: before the repair, to
     * decide the schema is safe to extend, and again inside `verifySchema()` as the postcondition.
     *
     * @param   Schema  $schema  Introspected live schema, which must already carry the parent tables.
     *
     * @return  void
     *
     * @throws  RuntimeException  When an existing object diverges from the migration's definition.
     *
     * @since   2.0.0
     */
    private function assertCompatiblePartialSchema(Schema $schema): void
    {
        $users = $schema->getTable($this->tables->raw('users'));
        if ($users->hasColumn('security_epoch')) {
            $this->assertIntegerColumn($users, 'security_epoch', true, '1');
        }
        $idempotency = $schema->getTable($this->tables->raw('idempotency'));
        if ($idempotency->hasColumn('authorization_fingerprint')) {
            $this->assertStringColumn($idempotency, 'authorization_fingerprint', 64, true, true);
        }
        if ($idempotency->hasColumn('lease_owner')) {
            $this->assertStringColumn($idempotency, 'lease_owner', 64, true, false);
        }
        if ($idempotency->hasColumn('lease_expires_at')) {
            $this->assertDateTimeColumn($idempotency, 'lease_expires_at', true);
        }

        $sitesName = $this->tables->raw('sites');
        if ($schema->hasTable($sitesName)) {
            $sites = $schema->getTable($sitesName);
            $this->assertExactColumns($sites, ['created_at', 'identifier', 'name']);
            $this->assertStringColumn($sites, 'identifier', 191, true, false);
            $this->assertStringColumn($sites, 'name', 191, true, false);
            $this->assertDateTimeColumn($sites, 'created_at', true);
            $this->assertPrimaryKey($sites, ['identifier']);
        }

        $ownershipName = $this->tables->raw('resource_site_ownership');
        if ($schema->hasTable($ownershipName)) {
            $ownership = $schema->getTable($ownershipName);
            $this->assertExactColumns($ownership, ['resource_id', 'resource_type', 'site_identifier']);
            $this->assertStringColumn($ownership, 'resource_type', 63, true, false);
            $this->assertStringColumn($ownership, 'resource_id', 191, true, false);
            $this->assertStringColumn($ownership, 'site_identifier', 191, true, false);
            $this->assertPrimaryKey($ownership, ['resource_type', 'resource_id']);
            if ($ownership->hasIndex('idx_resource_site')) {
                $this->assertIndex($ownership, 'idx_resource_site', ['site_identifier', 'resource_type']);
            }
            if ($this->ownershipForeignKey($ownership) !== null) {
                $this->assertOwnershipForeignKey($ownership);
            }
        }
    }

    /**
     * Prove the live schema now carries the migration's full postcondition, not merely a valid part.
     *
     * Re-runs the compatibility checks against a freshly introspected schema, then insists nothing is
     * still absent: the `security_epoch` column, both new tables, the three idempotency lease columns,
     * the ownership lookup index and its cascading foreign key must all be present.
     *
     * @return  void
     *
     * @throws  RuntimeException  When any part of the postcondition is missing or divergent.
     *
     * @since   2.0.0
     */
    private function verifySchema(): void
    {
        $schema = $this->database->createSchemaManager()->introspectSchema();
        $this->assertCompatiblePartialSchema($schema);
        $users = $schema->getTable($this->tables->raw('users'));
        $idempotency = $schema->getTable($this->tables->raw('idempotency'));
        if (
            !$users->hasColumn('security_epoch')
            || !$schema->hasTable($this->tables->raw('sites'))
            || !$schema->hasTable($this->tables->raw('resource_site_ownership'))
            || !$idempotency->hasColumn('authorization_fingerprint')
            || !$idempotency->hasColumn('lease_owner')
            || !$idempotency->hasColumn('lease_expires_at')
        ) {
            throw new RuntimeException('Application-authorization recovery did not reach its schema postcondition.');
        }
        $ownership = $schema->getTable($this->tables->raw('resource_site_ownership'));
        $this->assertIndex($ownership, 'idx_resource_site', ['site_identifier', 'resource_type']);
        $this->assertOwnershipForeignKey($ownership);
    }

    /**
     * Seed the default site row, or accept the one the interrupted attempt already inserted.
     *
     * Exactly one row is expected. An empty table is seeded; a table holding anything other than the
     * migration's own `default` / `Default site` pair is refused, because a second site would mean
     * ownership rows could point at a site this reconstruction never accounted for.
     *
     * @return  void
     *
     * @throws  RuntimeException  When the table holds more than one row, or a row the migration would
     *          not have written.
     *
     * @since   2.0.0
     */
    private function reconcileSite(): void
    {
        $rows = $this->database->fetchAllAssociative(sprintf(
            'SELECT identifier, name FROM %s ORDER BY identifier',
            $this->tables->quoted('sites'),
        ));
        if ($rows === []) {
            $this->database->insert($this->tables->raw('sites'), [
                'identifier' => SiteContext::DEFAULT,
                'name' => 'Default site',
                'created_at' => $this->now(),
            ], ['created_at' => Types::DATETIME_IMMUTABLE]);

            return;
        }
        if (
            count($rows) !== 1
            || ($rows[0]['identifier'] ?? null) !== SiteContext::DEFAULT
            || ($rows[0]['name'] ?? null) !== 'Default site'
        ) {
            throw new RuntimeException('The interrupted site seed is divergent.');
        }
    }

    /**
     * Seed each migration-owned capability and its global grant for every administrator role.
     *
     * A capability row that already exists must carry the migration's own description, and a grant
     * that already exists must be the single global grant with no scope identifier. After the per-role
     * pass the full set of roles holding each capability is compared against the captured list, so a
     * grant belonging to a role the migration never touched is caught as well.
     *
     * @param   list<string>  $roles  Administrator role ids in the captured ascending id order, which
     *          is also the order the grant lookup returns.
     *
     * @return  void
     *
     * @throws  RuntimeException  When an existing capability description, an existing grant, or the
     *          resulting set of granted roles diverges from what the migration would have written.
     *
     * @since   2.0.0
     */
    private function reconcileCapabilitiesAndGrants(array $roles): void
    {
        foreach (self::CAPABILITIES as $code => $description) {
            $row = $this->database->fetchAssociative(sprintf(
                'SELECT code, description FROM %s WHERE code = ?',
                $this->tables->quoted('capabilities'),
            ), [$code]);
            if ($row === false) {
                $this->database->insert($this->tables->raw('capabilities'), [
                    'code' => $code,
                    'description' => $description,
                ]);
            } elseif (($row['description'] ?? null) !== $description) {
                throw new RuntimeException(sprintf('The interrupted capability "%s" is divergent.', $code));
            }

            foreach ($roles as $roleId) {
                $grants = $this->database->fetchAllAssociative(sprintf(
                    'SELECT id, scope_type, scope_identifier FROM %s '
                    . 'WHERE role_id = ? AND capability_code = ? ORDER BY id',
                    $this->tables->quoted('role_capability_grants'),
                ), [$roleId, $code]);
                if ($grants === []) {
                    $this->database->insert($this->tables->raw('role_capability_grants'), [
                        'id' => Uuid::uuid7()->toString(),
                        'role_id' => $roleId,
                        'capability_code' => $code,
                        'scope_type' => 'global',
                        'scope_identifier' => null,
                        'granted_at' => $this->now(),
                        'granted_by' => null,
                    ], ['granted_at' => Types::DATETIME_IMMUTABLE]);
                } elseif (
                    count($grants) !== 1
                    || ($grants[0]['scope_type'] ?? null) !== 'global'
                    || ($grants[0]['scope_identifier'] ?? null) !== null
                ) {
                    throw new RuntimeException(sprintf('The interrupted grant for "%s" is divergent.', $code));
                }
            }

            $grantedRoles = $this->database->fetchFirstColumn(sprintf(
                'SELECT role_id FROM %s WHERE capability_code = ? ORDER BY role_id',
                $this->tables->quoted('role_capability_grants'),
            ), [$code]);
            if ($grantedRoles !== $roles) {
                throw new RuntimeException(sprintf('The interrupted grants for "%s" are divergent.', $code));
            }
        }
    }

    /**
     * Bring every user's security epoch up to the target the capture recorded for them.
     *
     * A user absent from the capture is expected to still sit at the starting epoch of 1. An epoch
     * below 1 or already past its target means something other than this migration moved it, which
     * aborts recovery; anything in between is the interrupted attempt's partial progress and is
     * written forward with a direct assignment rather than an increment. That is what stops a resumed
     * run pushing an epoch past its target and invalidating tokens and sessions the migration would
     * have left valid.
     *
     * @param   array<string, int>  $epochs  Target security epoch per administrator user id.
     *
     * @return  void
     *
     * @throws  RuntimeException  When a stored user identifier or epoch is unusable, or an epoch has
     *          already moved past its captured target.
     *
     * @since   2.0.0
     */
    private function reconcileSecurityEpochs(array $epochs): void
    {
        $rows = $this->database->fetchAllAssociative(sprintf(
            'SELECT id, security_epoch FROM %s ORDER BY id',
            $this->tables->quoted('users'),
        ));
        foreach ($rows as $row) {
            $userId = $row['id'] ?? null;
            if (!is_string($userId) || $userId === '') {
                throw new RuntimeException('An interrupted user identifier is invalid.');
            }
            $current = $this->nonNegativeInteger($row['security_epoch'] ?? null, 'security epoch');
            $expected = $epochs[$userId] ?? 1;
            if ($current < 1 || $current > $expected) {
                throw new RuntimeException(sprintf('The security epoch for user "%s" is divergent.', $userId));
            }
            if ($current !== $expected) {
                $this->database->update(
                    $this->tables->raw('users'),
                    ['security_epoch' => $expected],
                    ['id' => $userId],
                    ['security_epoch' => Types::INTEGER],
                );
            }
        }
    }

    /**
     * Give every ownable resource a `resource_site_ownership` row pointing at the default site.
     *
     * Walks the resource types in `OWNED_RESOURCES` and inserts the rows the interrupted attempt never
     * reached. A row that already names some other site stops recovery, because silently rewriting it
     * would move an existing resource between sites.
     *
     * @return  void
     *
     * @throws  RuntimeException  When an existing ownership row names a site other than the default,
     *          or a resource identifier is unusable.
     *
     * @since   2.0.0
     */
    private function reconcileOwnership(): void
    {
        foreach ($this->resourceIdentifiers() as $resourceType => $identifiers) {
            foreach ($identifiers as $identifier) {
                $site = $this->database->fetchOne(sprintf(
                    'SELECT site_identifier FROM %s WHERE resource_type = ? AND resource_id = ?',
                    $this->tables->quoted('resource_site_ownership'),
                ), [$resourceType, $identifier]);
                if ($site === false) {
                    $this->database->insert($this->tables->raw('resource_site_ownership'), [
                        'resource_type' => $resourceType,
                        'resource_id' => $identifier,
                        'site_identifier' => SiteContext::DEFAULT,
                    ]);
                } elseif ($site !== SiteContext::DEFAULT) {
                    throw new RuntimeException(sprintf('The %s ownership seed is divergent.', $resourceType));
                }
            }
        }
    }

    /**
     * Prove the ownership table holds exactly one default-site row per ownable resource, and no more.
     *
     * Reads the whole table and matches it in both directions: every stored row must name a known
     * resource type and the default site, and every expected identifier must be present in the same
     * order. An unexpected row fails as hard as a missing one, because ownership decides which site's
     * requests may reach a resource.
     *
     * @return  void
     *
     * @throws  RuntimeException  When a stored row is unknown or points at another site, or an
     *          expected resource has no row.
     *
     * @since   2.0.0
     */
    private function verifyOwnership(): void
    {
        $expected = $this->resourceIdentifiers();
        $rows = $this->database->fetchAllAssociative(sprintf(
            'SELECT resource_type, resource_id, site_identifier FROM %s ORDER BY resource_type, resource_id',
            $this->tables->quoted('resource_site_ownership'),
        ));
        $actual = [];
        foreach ($rows as $row) {
            $type = $row['resource_type'] ?? null;
            $id = $row['resource_id'] ?? null;
            if (
                !is_string($type) || !is_string($id) || !array_key_exists($type, $expected)
                || ($row['site_identifier'] ?? null) !== SiteContext::DEFAULT
            ) {
                throw new RuntimeException('The application-authorization ownership postcondition is divergent.');
            }
            $actual[$type][] = $id;
        }
        foreach ($expected as $type => $identifiers) {
            if (($actual[$type] ?? []) !== $identifiers) {
                throw new RuntimeException(sprintf('The %s ownership postcondition is incomplete.', $type));
            }
        }
    }

    /**
     * Read the current identifier of every ownable resource, grouped by ownership resource type.
     *
     * @return  array<string, list<string>>  Keyed by the `OWNED_RESOURCES` resource type, each list in
     *          ascending identifier order and empty where that table holds no rows. Every configured
     *          resource type is present as a key.
     *
     * @throws  RuntimeException  When a stored resource identifier is not a non-empty string.
     *
     * @since   2.0.0
     */
    private function resourceIdentifiers(): array
    {
        $resources = [];
        foreach (self::OWNED_RESOURCES as $resourceType => $mapping) {
            $column = $this->database->quoteSingleIdentifier($mapping['column']);
            $values = $this->database->fetchFirstColumn(sprintf(
                'SELECT %s FROM %s ORDER BY %s',
                $column,
                $this->tables->quoted($mapping['table']),
                $column,
            ));
            $identifiers = [];
            foreach ($values as $value) {
                if (!is_string($value) || $value === '') {
                    throw new RuntimeException(sprintf('A %s ownership identifier is invalid.', $resourceType));
                }
                $identifiers[] = $value;
            }
            $resources[$resourceType] = $identifiers;
        }

        return $resources;
    }

    /**
     * Insist a table the migration created carries the expected columns and no others.
     *
     * @param   Table         $table     Introspected table from the live schema.
     * @param   list<string>  $expected  Every column name the migration defines for that table.
     *
     * @return  void
     *
     * @throws  RuntimeException  When the column count differs, or an expected column is absent.
     *
     * @since   2.0.0
     */
    private function assertExactColumns(Table $table, array $expected): void
    {
        if (
            count($table->getColumns()) !== count($expected)
            || array_any($expected, static fn (string $column): bool => !$table->hasColumn($column))
        ) {
            throw new RuntimeException(sprintf(
                'The interrupted table "%s" has divergent columns.',
                $table->getObjectName()->toString(),
            ));
        }
    }

    /**
     * Insist a column is the string column the migration declared.
     *
     * @param   Table   $table    Introspected table owning the column, which must already have it.
     * @param   string  $name     Column to check.
     * @param   int     $length   Character length the migration declared.
     * @param   bool    $notNull  Whether the migration declared the column NOT NULL.
     * @param   bool    $fixed    Whether the column is fixed width (CHAR) rather than VARCHAR.
     *
     * @return  void
     *
     * @throws  RuntimeException  When the type, length, nullability or fixedness differs.
     *
     * @since   2.0.0
     */
    private function assertStringColumn(
        Table $table,
        string $name,
        int $length,
        bool $notNull,
        bool $fixed,
    ): void {
        $column = $table->getColumn($name);
        if (
            !$column->getType() instanceof StringType
            || $column->getLength() !== $length
            || $column->getNotnull() !== $notNull
            || $column->getFixed() !== $fixed
        ) {
            throw new RuntimeException(sprintf(
                'The interrupted column "%s.%s" is divergent.',
                $table->getObjectName()->toString(),
                $name,
            ));
        }
    }

    /**
     * Insist a column is the integer column the migration declared, default included.
     *
     * The default is compared as text because platforms report it as either an int or a string.
     *
     * @param   Table   $table    Introspected table owning the column, which must already have it.
     * @param   string  $name     Column to check.
     * @param   bool    $notNull  Whether the migration declared the column NOT NULL.
     * @param   string  $default  Default the migration declared, spelled as decimal digits.
     *
     * @return  void
     *
     * @throws  RuntimeException  When the type, nullability or default differs.
     *
     * @since   2.0.0
     */
    private function assertIntegerColumn(Table $table, string $name, bool $notNull, string $default): void
    {
        $column = $table->getColumn($name);
        $actualDefault = $column->getDefault();
        if (
            !$column->getType() instanceof IntegerType
            || $column->getNotnull() !== $notNull
            || (!is_int($actualDefault) && !is_string($actualDefault))
            || (string) $actualDefault !== $default
        ) {
            throw new RuntimeException(sprintf(
                'The interrupted column "%s.%s" is divergent.',
                $table->getObjectName()->toString(),
                $name,
            ));
        }
    }

    /**
     * Insist a column is a datetime column with the declared nullability.
     *
     * Both DBAL datetime mappings are accepted, because introspection cannot tell a column declared as
     * `datetime_immutable` apart from one declared as `datetime`.
     *
     * @param   Table   $table    Introspected table owning the column, which must already have it.
     * @param   string  $name     Column to check.
     * @param   bool    $notNull  Whether the migration declared the column NOT NULL.
     *
     * @return  void
     *
     * @throws  RuntimeException  When the column is not a datetime column, or its nullability differs.
     *
     * @since   2.0.0
     */
    private function assertDateTimeColumn(Table $table, string $name, bool $notNull): void
    {
        $column = $table->getColumn($name);
        if (
            (!$column->getType() instanceof DateTimeImmutableType && !$column->getType() instanceof DateTimeType)
            || $column->getNotnull() !== $notNull
        ) {
            throw new RuntimeException(sprintf(
                'The interrupted column "%s.%s" is divergent.',
                $table->getObjectName()->toString(),
                $name,
            ));
        }
    }

    /**
     * Insist a table's primary key covers exactly the given columns, in the given order.
     *
     * @param   Table         $table    Introspected table from the live schema.
     * @param   list<string>  $columns  Primary key columns as the migration declares them, in order.
     *
     * @return  void
     *
     * @throws  RuntimeException  When the table has no primary key, or a different one.
     *
     * @since   2.0.0
     */
    private function assertPrimaryKey(Table $table, array $columns): void
    {
        $primary = $table->getPrimaryKeyConstraint();
        if ($primary === null || $this->names($primary->getColumnNames()) !== $columns) {
            throw new RuntimeException(sprintf(
                'The interrupted table "%s" has a divergent primary key.',
                $table->getObjectName()->toString(),
            ));
        }
    }

    /**
     * Insist a named index exists over exactly the given columns, as a plain non-unique index.
     *
     * @param   Table         $table    Introspected table from the live schema.
     * @param   string        $name     Index name as the migration declares it.
     * @param   list<string>  $columns  Indexed columns, in index order.
     *
     * @return  void
     *
     * @throws  RuntimeException  When no index carries the name, or the one that does covers other
     *          columns or is not a regular index.
     *
     * @since   2.0.0
     */
    private function assertIndex(Table $table, string $name, array $columns): void
    {
        foreach ($table->getIndexes() as $index) {
            if ($index->getObjectName()?->getIdentifier()->getValue() !== $name) {
                continue;
            }
            $actual = array_map(
                static fn (\Doctrine\DBAL\Schema\Index\IndexedColumn $column): string =>
                    $column->getColumnName()->getIdentifier()->getValue(),
                $index->getIndexedColumns(),
            );
            if ($actual !== $columns || $index->getType() !== IndexType::REGULAR) {
                throw new RuntimeException(sprintf('The interrupted index "%s" is divergent.', $name));
            }

            return;
        }
        throw new RuntimeException(sprintf('The interrupted index "%s" is missing.', $name));
    }

    /**
     * Insist the ownership table's foreign key into `sites` exists and cascades on delete.
     *
     * The cascade is what keeps ownership rows from outliving their site and leaving a resource owned
     * by an identifier that no longer resolves.
     *
     * @param   Table  $table  Introspected `resource_site_ownership` table.
     *
     * @return  void
     *
     * @throws  RuntimeException  When the key is absent, references a column other than `identifier`,
     *          or does not cascade deletes.
     *
     * @since   2.0.0
     */
    private function assertOwnershipForeignKey(Table $table): void
    {
        $foreignKey = $this->ownershipForeignKey($table);
        if ($foreignKey === null) {
            throw new RuntimeException('The interrupted ownership foreign key is missing.');
        }
        if (
            $this->names($foreignKey->getReferencedColumnNames()) !== ['identifier']
            || $foreignKey->getOnDeleteAction() !== ReferentialAction::CASCADE
        ) {
            throw new RuntimeException('The interrupted ownership foreign key is divergent.');
        }
    }

    /**
     * Find the ownership table's foreign key into `sites` by its columns rather than by its name.
     *
     * The published migration and `ensureSchema()` name that constraint differently, so matching on
     * the referencing column and the referenced table is what lets recovery recognise a key either of
     * them created — and therefore avoid adding a second one alongside it.
     *
     * @param   Table  $table  Introspected `resource_site_ownership` table.
     *
     * @return  ?ForeignKeyConstraint  The matching constraint, or null when the table carries none.
     *
     * @since   2.0.0
     */
    private function ownershipForeignKey(Table $table): ?ForeignKeyConstraint
    {
        foreach ($table->getForeignKeys() as $foreignKey) {
            if (
                $this->names($foreignKey->getReferencingColumnNames()) === ['site_identifier']
                && $foreignKey->getReferencedTableName()->getUnqualifiedName()->getValue()
                    === $this->tables->raw('sites')
            ) {
                return $foreignKey;
            }
        }

        return null;
    }

    /**
     * Flatten DBAL name objects to the plain identifier strings the schema assertions compare.
     *
     * @param   non-empty-list<UnqualifiedName>  $names  Column names as introspection returns them.
     *
     * @return  non-empty-list<string>  The identifiers, unquoted, in the order given.
     *
     * @since   2.0.0
     */
    private function names(array $names): array
    {
        return array_map(
            static fn (UnqualifiedName $name): string => $name->getIdentifier()->getValue(),
            $names,
        );
    }

    /**
     * Read a count or epoch that a driver may hand back as either an int or a numeric string.
     *
     * A string is accepted only when it is entirely decimal digits; an int is taken as it stands, so
     * callers that need a lower bound still range-check the value they get back.
     *
     * @param   mixed   $value  Raw column value from a `COUNT(*)` or security-epoch read.
     * @param   string  $label  Name of the value, used to phrase the failure message for an operator.
     *
     * @return  int  The value as an integer.
     *
     * @throws  RuntimeException  When the value is neither an int nor a string of decimal digits.
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

    /**
     * Timestamp the rows this reconstruction inserts, in UTC.
     *
     * @return  \DateTimeImmutable  The current instant in UTC. Rows the interrupted attempt already
     *          wrote keep their original timestamp, so seeded times may differ across a resumed run.
     *
     * @since   2.0.0
     */
    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
