<?php

declare(strict_types=1);

namespace Kumwe\App\Infrastructure\Persistence\Migration;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use JsonException;
use Kumwe\Context\Value\SiteContext;
use Kumwe\App\BusinessSchema\Domain\PhysicalSchemaBlueprint;
use Kumwe\App\Extension\Contribution\CanonicalManifestInterpreter;
use Kumwe\App\Extension\Contribution\ContributionDefinitionChecksum;
use Kumwe\App\Extension\Contribution\CoreExtensionContributions;
use Kumwe\App\Extension\Runtime\RuntimeCanonicalJson;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\Extension\Manifest\ExtensionManifest;
use Kumwe\Extension\Spi\Contribution\ContributionOwner;
use Ramsey\Uuid\Uuid;
use RuntimeException;

/**
 * Installs organization policy, approval, step-up, portal-session and delegated-token storage.
 *
 * The tables deliberately keep site, organization and workspace in separate columns. Policy documents
 * are bounded canonical ASTs, while identities, memberships, approval bindings, epochs and lifecycle
 * state remain relational and indexable on every supported database.
 *
 * @since  2.0.0
 */
final readonly class BusinessSecurityPortalMigration implements Migration
{
    /** @var string Stable ordered migration identity. @since 2.0.0 */
    public const string ID = '20260809010000_business_security_portal';

    /**
     * Bind the migration to the installation's table-name compiler.
     *
     * @param  TableNames  $tables  Portable physical table names.
     *
     * @since  2.0.0
     */
    public function __construct(private TableNames $tables)
    {
    }

    /** @return string Stable ordered migration identity. @since 2.0.0 */
    public function id(): string
    {
        return self::ID;
    }

    /**
     * Bind ledger compatibility to the exact published source.
     *
     * @return  string  SHA-256 migration checksum.
     *
     * @throws  RuntimeException  When the source cannot be read.
     *
     * @since   2.0.0
     */
    public function checksum(): string
    {
        $digest = hash_file('sha256', __FILE__);
        if (!is_string($digest)) {
            throw new RuntimeException('The business security portal migration checksum could not be calculated.');
        }

        return hash('sha256', self::ID . ':' . $digest);
    }

    /**
     * Create the control-plane tables and extend canonical capability and token catalogs.
     *
     * @param   Connection  $database  Installation database.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function up(Connection $database): void
    {
        $this->extendSites($database);
        $manager = $database->createSchemaManager();
        $siteIdentifier = $manager->introspectTableByUnquotedName(
            $this->tables->raw('sites'),
        )->getColumn('identifier');
        foreach ($this->tables($this->siteIdentifierOptions($siteIdentifier)) as $table) {
            $name = $table->getObjectName()->getUnqualifiedName()->getValue();
            if (!$manager->tablesExist([$name])) {
                $manager->createTable($table);
            }
        }

        $this->backfillBusinessRecordOwnership($database);
        $this->extendCapabilities($database);
        $this->synchronizeCoreCapabilities($database);
        $this->backfillExtensionAuthorizationContributions($database);
        $this->extendTokens($database);
        $this->extendAdministratorSessions($database);
    }

    /**
     * Build tables in foreign-key dependency order.
     *
     * @param   array<string, mixed>  $siteIdentifierOptions  Canonical site identifier column options.
     *
     * @return  list<Table>  Portable Doctrine schema definitions.
     *
     * @since   2.0.0
     */
    private function tables(array $siteIdentifierOptions): array
    {
        return [
            $this->organizations($siteIdentifierOptions),
            $this->workspaces(),
            $this->memberships(),
            $this->membershipWorkspaces(),
            $this->membershipRoles(),
            $this->extensionContributionResourcePolicies(),
            $this->resourcePolicies(),
            $this->separationOfDutyRules($siteIdentifierOptions),
            $this->approvalRequests($siteIdentifierOptions),
            $this->approvalVotes(),
            $this->stepUpCredentials(),
            $this->stepUpRecoveryCodes(),
            $this->stepUpProofs($siteIdentifierOptions),
            $this->portalSessions($siteIdentifierOptions),
        ];
    }

    /**
     * Define organizations using the canonical site identifier's exact character semantics.
     *
     * @param   array<string, mixed>  $siteIdentifierOptions  Length, fixedness, charset and collation copied
     *          from `sites.identifier` for the foreign-key column.
     *
     * @return  Table  Organizations remain distinct from sites.
     *
     * @since   2.0.0
     */
    private function organizations(array $siteIdentifierOptions): Table
    {
        $table = new Table($this->tables->raw('organizations'));
        $table->addColumn('id', Types::GUID);
        $table->addColumn('site_identifier', Types::STRING, $siteIdentifierOptions);
        $table->addColumn('identifier', Types::STRING, ['length' => 191]);
        $table->addColumn('name', Types::STRING, ['length' => 191]);
        $table->addColumn('status', Types::STRING, ['length' => 24]);
        $table->addColumn('policy_generation', Types::BIGINT, ['default' => 1]);
        $table->addColumn('version', Types::INTEGER, ['default' => 1]);
        $table->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('updated_at', Types::DATETIME_IMMUTABLE);
        $this->primary($table, 'id');
        $table->addUniqueIndex(['site_identifier', 'identifier'], 'uniq_org_site_identifier');
        $table->addIndex(['site_identifier', 'status'], 'idx_org_site_status');
        $table->addForeignKeyConstraint(
            $this->tables->raw('sites'),
            ['site_identifier'],
            ['identifier'],
            ['onDelete' => 'RESTRICT'],
            'fk_org_site',
        );

        return $table;
    }

    /** @return Table Workspaces nested in one organization. @since 2.0.0 */
    private function workspaces(): Table
    {
        $table = new Table($this->tables->raw('workspaces'));
        $table->addColumn('id', Types::GUID);
        $table->addColumn('organization_id', Types::GUID);
        $table->addColumn('identifier', Types::STRING, ['length' => 191]);
        $table->addColumn('name', Types::STRING, ['length' => 191]);
        $table->addColumn('status', Types::STRING, ['length' => 24]);
        $table->addColumn('version', Types::INTEGER, ['default' => 1]);
        $table->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('updated_at', Types::DATETIME_IMMUTABLE);
        $this->primary($table, 'id');
        $table->addUniqueIndex(['organization_id', 'identifier'], 'uniq_workspace_org_identifier');
        $table->addIndex(['organization_id', 'status'], 'idx_workspace_org_status');
        $table->addForeignKeyConstraint(
            $this->tables->raw('organizations'),
            ['organization_id'],
            ['id'],
            ['onDelete' => 'CASCADE'],
            'fk_workspace_org',
        );

        return $table;
    }

    /** @return Table Versioned organization membership rows. @since 2.0.0 */
    private function memberships(): Table
    {
        $table = new Table($this->tables->raw('organization_memberships'));
        $table->addColumn('id', Types::GUID);
        $table->addColumn('organization_id', Types::GUID);
        $table->addColumn('user_id', Types::GUID);
        $table->addColumn('status', Types::STRING, ['length' => 24]);
        $table->addColumn('version', Types::INTEGER, ['default' => 1]);
        $table->addColumn('valid_from', Types::DATETIME_IMMUTABLE);
        $table->addColumn('valid_until', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('created_by', Types::STRING, ['length' => 191]);
        $table->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('updated_at', Types::DATETIME_IMMUTABLE);
        $this->primary($table, 'id');
        $table->addUniqueIndex(['organization_id', 'user_id'], 'uniq_membership_org_user');
        $table->addIndex(['user_id', 'status', 'valid_until'], 'idx_membership_user_active');
        $table->addForeignKeyConstraint(
            $this->tables->raw('organizations'),
            ['organization_id'],
            ['id'],
            ['onDelete' => 'CASCADE'],
            'fk_membership_org',
        );
        $table->addForeignKeyConstraint(
            $this->tables->raw('users'),
            ['user_id'],
            ['id'],
            ['onDelete' => 'CASCADE'],
            'fk_membership_user',
        );

        return $table;
    }

    /** @return Table Explicit workspace assignments for memberships. @since 2.0.0 */
    private function membershipWorkspaces(): Table
    {
        $table = new Table($this->tables->raw('membership_workspaces'));
        $table->addColumn('membership_id', Types::GUID);
        $table->addColumn('workspace_id', Types::GUID);
        $table->addColumn('assigned_by', Types::STRING, ['length' => 191]);
        $table->addColumn('assigned_at', Types::DATETIME_IMMUTABLE);
        $this->primary($table, 'membership_id', 'workspace_id');
        $table->addIndex(['workspace_id'], 'idx_membership_workspace');
        $table->addForeignKeyConstraint(
            $this->tables->raw('organization_memberships'),
            ['membership_id'],
            ['id'],
            ['onDelete' => 'CASCADE'],
            'fk_mworkspace_membership',
        );
        $table->addForeignKeyConstraint(
            $this->tables->raw('workspaces'),
            ['workspace_id'],
            ['id'],
            ['onDelete' => 'CASCADE'],
            'fk_mworkspace_workspace',
        );

        return $table;
    }

    /** @return Table Organization-specific role assignments. @since 2.0.0 */
    private function membershipRoles(): Table
    {
        $table = new Table($this->tables->raw('membership_roles'));
        $table->addColumn('membership_id', Types::GUID);
        $table->addColumn('role_id', Types::GUID);
        $table->addColumn('assigned_by', Types::STRING, ['length' => 191]);
        $table->addColumn('assigned_at', Types::DATETIME_IMMUTABLE);
        $this->primary($table, 'membership_id', 'role_id');
        $table->addIndex(['role_id'], 'idx_membership_role');
        $table->addForeignKeyConstraint(
            $this->tables->raw('organization_memberships'),
            ['membership_id'],
            ['id'],
            ['onDelete' => 'CASCADE'],
            'fk_mrole_membership',
        );
        $table->addForeignKeyConstraint(
            $this->tables->raw('roles'),
            ['role_id'],
            ['id'],
            ['onDelete' => 'RESTRICT'],
            'fk_mrole_role',
        );

        return $table;
    }

    /** @return Table Durable signed declarations for extension-owned base resource policies. @since 2.0.0 */
    private function extensionContributionResourcePolicies(): Table
    {
        $table = new Table($this->tables->raw('extension_contribution_resource_policies'));
        $table->addColumn('extension_id', Types::GUID);
        $table->addColumn('policy_code', Types::STRING, ['length' => 191]);
        $table->addColumn('capability_code', Types::STRING, ['length' => 191]);
        $table->addColumn('definition', Types::JSON);
        $table->addColumn('installation_global', Types::BOOLEAN, ['default' => false]);
        $table->addColumn('lifecycle_state', Types::STRING, ['length' => 24]);
        $table->addColumn('definition_version', Types::INTEGER);
        $table->addColumn('definition_checksum', Types::STRING, ['length' => 64, 'fixed' => true]);
        $table->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('updated_at', Types::DATETIME_IMMUTABLE);
        $this->primary($table, 'policy_code');
        $table->addIndex(['extension_id', 'lifecycle_state'], 'idx_ext_resource_policy_owner');
        $table->addIndex(['capability_code'], 'idx_ext_resource_policy_capability');
        $table->addForeignKeyConstraint(
            $this->tables->raw('extensions'),
            ['extension_id'],
            ['id'],
            ['onDelete' => 'CASCADE'],
            'fk_ext_resource_policy_owner',
        );
        $table->addForeignKeyConstraint(
            $this->tables->raw('capabilities'),
            ['capability_code'],
            ['code'],
            ['onDelete' => 'CASCADE'],
            'fk_ext_resource_policy_capability',
        );

        return $table;
    }

    /** @return Table Owner-aware typed resource policy documents. @since 2.0.0 */
    private function resourcePolicies(): Table
    {
        $table = new Table($this->tables->raw('resource_policies'));
        $table->addColumn('id', Types::GUID);
        $table->addColumn('policy_code', Types::STRING, ['length' => 191]);
        $table->addColumn('owner_kind', Types::STRING, ['length' => 24]);
        $table->addColumn('owner_identifier', Types::STRING, ['length' => 191]);
        $table->addColumn('capability_code', Types::STRING, ['length' => 191]);
        $table->addColumn('resource_type', Types::STRING, ['length' => 63]);
        $table->addColumn('action', Types::STRING, ['length' => 127]);
        $table->addColumn('effect', Types::STRING, ['length' => 8]);
        $table->addColumn('scope_type', Types::STRING, ['length' => 32]);
        $table->addColumn('organization_id', Types::GUID, ['notnull' => false]);
        $table->addColumn('entity_definition_id', Types::GUID, ['notnull' => false]);
        $table->addColumn('canonical_ast', Types::JSON);
        $table->addColumn('field_rules', Types::JSON);
        $table->addColumn('ast_checksum', Types::STRING, ['length' => 64, 'fixed' => true]);
        $table->addColumn('policy_version', Types::INTEGER);
        $table->addColumn('priority', Types::INTEGER, ['default' => 0]);
        $table->addColumn('status', Types::STRING, ['length' => 24]);
        $table->addColumn('created_by', Types::STRING, ['length' => 191]);
        $table->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('updated_at', Types::DATETIME_IMMUTABLE);
        $this->primary($table, 'id');
        $table->addUniqueIndex(['policy_code'], 'uniq_resource_policy_code');
        $table->addIndex(
            ['resource_type', 'action', 'organization_id', 'status', 'priority'],
            'idx_resource_policy_match',
        );
        $table->addIndex(['owner_identifier', 'status'], 'idx_resource_policy_owner');
        $table->addForeignKeyConstraint(
            $this->tables->raw('capabilities'),
            ['capability_code'],
            ['code'],
            ['onDelete' => 'RESTRICT'],
            'fk_resource_policy_capability',
        );
        $table->addForeignKeyConstraint(
            $this->tables->raw('organizations'),
            ['organization_id'],
            ['id'],
            ['onDelete' => 'CASCADE'],
            'fk_resource_policy_org',
        );
        $table->addForeignKeyConstraint(
            $this->tables->raw('business_definitions'),
            ['entity_definition_id'],
            ['id'],
            ['onDelete' => 'RESTRICT'],
            'fk_resource_policy_definition',
        );

        return $table;
    }

    /**
     * Define generic incompatible-role/action and maker-checker rules.
     *
     * @param   array<string, mixed>  $siteIdentifierOptions  Canonical site identifier column options.
     *
     * @return  Table  Generic incompatible-role/action and maker-checker rules.
     *
     * @since   2.0.0
     */
    private function separationOfDutyRules(array $siteIdentifierOptions): Table
    {
        $table = new Table($this->tables->raw('separation_duty_rules'));
        $table->addColumn('id', Types::GUID);
        $table->addColumn('site_identifier', Types::STRING, $siteIdentifierOptions);
        $table->addColumn('organization_id', Types::GUID, ['notnull' => false]);
        $table->addColumn('scope_key', Types::STRING, ['length' => 64]);
        $table->addColumn('rule_code', Types::STRING, ['length' => 191]);
        $table->addColumn('resource_type', Types::STRING, ['length' => 63]);
        $table->addColumn('request_action', Types::STRING, ['length' => 191]);
        $table->addColumn('approval_action', Types::STRING, ['length' => 191]);
        $table->addColumn('requester_role_id', Types::GUID, ['notnull' => false]);
        $table->addColumn('approver_role_id', Types::GUID, ['notnull' => false]);
        $table->addColumn('quorum', Types::SMALLINT);
        $table->addColumn('distinct_actors', Types::BOOLEAN, ['default' => true]);
        $table->addColumn('status', Types::STRING, ['length' => 24]);
        $table->addColumn('version', Types::INTEGER, ['default' => 1]);
        $table->addColumn('created_by', Types::STRING, ['length' => 191]);
        $table->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('updated_at', Types::DATETIME_IMMUTABLE);
        $this->primary($table, 'id');
        $table->addUniqueIndex(['site_identifier', 'scope_key', 'rule_code'], 'uniq_sod_scope_code');
        $table->addUniqueIndex(
            ['site_identifier', 'scope_key', 'resource_type', 'request_action'],
            'uniq_sod_scope_action',
        );
        $table->addIndex(['site_identifier', 'resource_type', 'request_action', 'status'], 'idx_sod_match');
        $table->addForeignKeyConstraint(
            $this->tables->raw('sites'),
            ['site_identifier'],
            ['identifier'],
            ['onDelete' => 'RESTRICT'],
            'fk_sod_site',
        );
        $table->addForeignKeyConstraint(
            $this->tables->raw('organizations'),
            ['organization_id'],
            ['id'],
            ['onDelete' => 'CASCADE'],
            'fk_sod_org',
        );
        $table->addForeignKeyConstraint(
            $this->tables->raw('roles'),
            ['requester_role_id'],
            ['id'],
            ['onDelete' => 'RESTRICT'],
            'fk_sod_requester_role',
        );
        $table->addForeignKeyConstraint(
            $this->tables->raw('roles'),
            ['approver_role_id'],
            ['id'],
            ['onDelete' => 'RESTRICT'],
            'fk_sod_approver_role',
        );

        return $table;
    }

    /**
     * Define immutable high-impact action requests and bindings.
     *
     * @param   array<string, mixed>  $siteIdentifierOptions  Canonical site identifier column options.
     *
     * @return  Table  Immutable high-impact action requests and bindings.
     *
     * @since   2.0.0
     */
    private function approvalRequests(array $siteIdentifierOptions): Table
    {
        $table = new Table($this->tables->raw('approval_requests'));
        $table->addColumn('id', Types::GUID);
        $table->addColumn('rule_id', Types::GUID);
        $table->addColumn('rule_version', Types::INTEGER);
        $table->addColumn('approval_action', Types::STRING, ['length' => 191]);
        $table->addColumn('approver_role_id', Types::GUID, ['notnull' => false]);
        $table->addColumn('distinct_actors', Types::BOOLEAN);
        $table->addColumn('site_identifier', Types::STRING, $siteIdentifierOptions);
        $table->addColumn('organization_id', Types::GUID, ['notnull' => false]);
        $table->addColumn('workspace_id', Types::GUID, ['notnull' => false]);
        $table->addColumn('requester_id', Types::STRING, ['length' => 191]);
        $table->addColumn('action', Types::STRING, ['length' => 127]);
        $table->addColumn('resource_type', Types::STRING, ['length' => 63]);
        $table->addColumn('resource_id', Types::STRING, ['length' => 191]);
        $table->addColumn('resource_version', Types::BIGINT);
        $table->addColumn('context_fingerprint', Types::STRING, ['length' => 64, 'fixed' => true]);
        $table->addColumn('payload_digest', Types::STRING, ['length' => 64, 'fixed' => true]);
        $table->addColumn('required_quorum', Types::SMALLINT);
        $table->addColumn('status', Types::STRING, ['length' => 24]);
        $table->addColumn('expires_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('resolved_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('consumed_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('revoked_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('version', Types::INTEGER, ['default' => 1]);
        $this->primary($table, 'id');
        $table->addIndex(['requester_id', 'status', 'expires_at'], 'idx_approval_requester');
        $table->addIndex(
            ['resource_type', 'resource_id', 'resource_version', 'status'],
            'idx_approval_resource',
        );
        $table->addForeignKeyConstraint(
            $this->tables->raw('separation_duty_rules'),
            ['rule_id'],
            ['id'],
            ['onDelete' => 'RESTRICT'],
            'fk_approval_rule',
        );
        $table->addForeignKeyConstraint(
            $this->tables->raw('sites'),
            ['site_identifier'],
            ['identifier'],
            ['onDelete' => 'RESTRICT'],
            'fk_approval_site',
        );
        $table->addForeignKeyConstraint(
            $this->tables->raw('organizations'),
            ['organization_id'],
            ['id'],
            ['onDelete' => 'RESTRICT'],
            'fk_approval_org',
        );
        $table->addForeignKeyConstraint(
            $this->tables->raw('workspaces'),
            ['workspace_id'],
            ['id'],
            ['onDelete' => 'RESTRICT'],
            'fk_approval_workspace',
        );
        $table->addForeignKeyConstraint(
            $this->tables->raw('roles'),
            ['approver_role_id'],
            ['id'],
            ['onDelete' => 'RESTRICT'],
            'fk_approval_approver_role',
        );

        return $table;
    }

    /** @return Table One immutable decision per approver and request. @since 2.0.0 */
    private function approvalVotes(): Table
    {
        $table = new Table($this->tables->raw('approval_votes'));
        $table->addColumn('id', Types::GUID);
        $table->addColumn('request_id', Types::GUID);
        $table->addColumn('approver_id', Types::STRING, ['length' => 191]);
        $table->addColumn('decision', Types::STRING, ['length' => 16]);
        $table->addColumn('reason', Types::STRING, ['length' => 500, 'notnull' => false]);
        $table->addColumn('context_fingerprint', Types::STRING, ['length' => 64, 'fixed' => true]);
        $table->addColumn('step_up_proof_id', Types::GUID, ['notnull' => false]);
        $table->addColumn('decided_at', Types::DATETIME_IMMUTABLE);
        $this->primary($table, 'id');
        $table->addUniqueIndex(['request_id', 'approver_id'], 'uniq_approval_vote_actor');
        $table->addIndex(['request_id', 'decision'], 'idx_approval_vote_decision');
        $table->addForeignKeyConstraint(
            $this->tables->raw('approval_requests'),
            ['request_id'],
            ['id'],
            ['onDelete' => 'CASCADE'],
            'fk_approval_vote_request',
        );

        return $table;
    }

    /** @return Table Encrypted production TOTP credentials and replay fence. @since 2.0.0 */
    private function stepUpCredentials(): Table
    {
        $table = new Table($this->tables->raw('step_up_credentials'));
        $table->addColumn('id', Types::GUID);
        $table->addColumn('subject_id', Types::GUID);
        $table->addColumn('encrypted_secret', Types::TEXT);
        $table->addColumn('status', Types::STRING, ['length' => 24]);
        $table->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('enrollment_expires_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('confirmed_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('last_accepted_time_step', Types::BIGINT, ['notnull' => false]);
        $table->addColumn('last_verified_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('disabled_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('version', Types::INTEGER, ['default' => 1]);
        $this->primary($table, 'id');
        $table->addIndex(['subject_id', 'status'], 'idx_stepup_subject_status');
        $table->addForeignKeyConstraint(
            $this->tables->raw('users'),
            ['subject_id'],
            ['id'],
            ['onDelete' => 'CASCADE'],
            'fk_stepup_subject',
        );

        return $table;
    }

    /** @return Table Hashed, single-use step-up recovery codes. @since 2.0.0 */
    private function stepUpRecoveryCodes(): Table
    {
        $table = new Table($this->tables->raw('step_up_recovery_codes'));
        $table->addColumn('credential_id', Types::GUID);
        $table->addColumn('code_digest', Types::STRING, ['length' => 64, 'fixed' => true]);
        $table->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('consumed_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $this->primary($table, 'credential_id', 'code_digest');
        $table->addIndex(['credential_id', 'consumed_at'], 'idx_stepup_recovery_live');
        $table->addForeignKeyConstraint(
            $this->tables->raw('step_up_credentials'),
            ['credential_id'],
            ['id'],
            ['onDelete' => 'CASCADE'],
            'fk_stepup_recovery_credential',
        );

        return $table;
    }

    /**
     * Define short-lived, session-bound, single-use step-up proofs.
     *
     * @param   array<string, mixed>  $siteIdentifierOptions  Canonical site identifier column options.
     *
     * @return  Table  Short-lived, session-bound, single-use step-up proofs.
     *
     * @since   2.0.0
     */
    private function stepUpProofs(array $siteIdentifierOptions): Table
    {
        $table = new Table($this->tables->raw('step_up_proofs'));
        $table->addColumn('id', Types::GUID);
        $table->addColumn('nonce_digest', Types::STRING, ['length' => 64, 'fixed' => true]);
        $table->addColumn('user_id', Types::GUID);
        $table->addColumn('session_id', Types::GUID);
        $table->addColumn('site_identifier', Types::STRING, $siteIdentifierOptions);
        $table->addColumn('organization_identifier', Types::STRING, ['length' => 191, 'notnull' => false]);
        $table->addColumn('workspace_identifier', Types::STRING, ['length' => 191, 'notnull' => false]);
        $table->addColumn('purpose', Types::STRING, ['length' => 127]);
        $table->addColumn('security_epoch', Types::BIGINT);
        $table->addColumn('method', Types::STRING, ['length' => 32]);
        $table->addColumn('verified_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('expires_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('consumed_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('revoked_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $this->primary($table, 'id');
        $table->addUniqueIndex(['nonce_digest'], 'uniq_stepup_proof_nonce');
        $table->addIndex(
            ['user_id', 'session_id', 'purpose', 'security_epoch', 'expires_at'],
            'idx_stepup_proof_session',
        );

        return $table;
    }

    /**
     * Define cookie-digest portal sessions isolated from administrator sessions.
     *
     * @param   array<string, mixed>  $siteIdentifierOptions  Canonical site identifier column options.
     *
     * @return  Table  Cookie-digest portal sessions isolated from administrator sessions.
     *
     * @since   2.0.0
     */
    private function portalSessions(array $siteIdentifierOptions): Table
    {
        $table = new Table($this->tables->raw('portal_sessions'));
        $table->addColumn('id', Types::GUID);
        $table->addColumn('token_digest', Types::STRING, ['length' => 64, 'fixed' => true]);
        $table->addColumn('user_id', Types::GUID);
        $table->addColumn('site_identifier', Types::STRING, $siteIdentifierOptions);
        $table->addColumn('organization_identifier', Types::STRING, ['length' => 191, 'notnull' => false]);
        $table->addColumn('workspace_identifier', Types::STRING, ['length' => 191, 'notnull' => false]);
        $table->addColumn('membership_id', Types::GUID, ['notnull' => false]);
        $table->addColumn('membership_version', Types::INTEGER, ['notnull' => false]);
        $table->addColumn('policy_generation', Types::BIGINT, ['notnull' => false]);
        $table->addColumn('security_epoch', Types::BIGINT);
        $table->addColumn('csrf_token', Types::STRING, ['length' => 128]);
        $table->addColumn('user_agent_digest', Types::STRING, ['length' => 64, 'fixed' => true]);
        $table->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('authenticated_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('last_seen_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('step_up_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('expires_at', Types::DATETIME_IMMUTABLE);
        $this->primary($table, 'id');
        $table->addUniqueIndex(['token_digest'], 'uniq_portal_session_digest');
        $table->addIndex(['user_id', 'expires_at'], 'idx_portal_session_user');
        $table->addIndex(
            ['site_identifier', 'organization_identifier', 'workspace_identifier'],
            'idx_portal_session_scope',
        );
        $table->addForeignKeyConstraint(
            $this->tables->raw('users'),
            ['user_id'],
            ['id'],
            ['onDelete' => 'CASCADE'],
            'fk_portal_session_user',
        );

        return $table;
    }

    /**
     * Add ownership and high-impact metadata to the canonical capability catalog.
     *
     * @param   Connection  $database  Installation database.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function extendCapabilities(Connection $database): void
    {
        $table = $database->createSchemaManager()->introspectTableByUnquotedName(
            $this->tables->raw('capabilities'),
        );
        $columns = [
            'owner_kind' => "VARCHAR(24) NOT NULL DEFAULT 'core'",
            'owner_identifier' => "VARCHAR(191) NOT NULL DEFAULT 'core'",
            'allowed_scopes' => "VARCHAR(8192) NOT NULL DEFAULT '[\"global\"]'",
            'delegable' => 'BOOLEAN NOT NULL DEFAULT TRUE',
            'high_impact' => 'BOOLEAN NOT NULL DEFAULT FALSE',
            'definition_version' => 'INTEGER NOT NULL DEFAULT 1',
            'definition_checksum' => "VARCHAR(64) NOT NULL DEFAULT 'legacy'",
            'lifecycle_state' => "VARCHAR(24) NOT NULL DEFAULT 'active'",
        ];
        foreach ($columns as $name => $declaration) {
            if (!$table->hasColumn($name)) {
                $database->executeStatement(sprintf(
                    'ALTER TABLE %s ADD %s %s',
                    $this->tables->quoted('capabilities'),
                    $database->quoteSingleIdentifier($name),
                    $declaration,
                ));
            }
        }
        $database->executeStatement(sprintf(
            "UPDATE %s SET definition_checksum = ? WHERE definition_checksum = 'legacy'",
            $this->tables->quoted('capabilities'),
        ), [hash('sha256', 'kumwe-core-capability-catalog-v1')]);
    }

    /**
     * Reconcile persisted core metadata and administrator grants from the typed contribution catalog.
     *
     * The contribution definitions are the same objects the live authorization registry consumes.
     * Migrations therefore seed new capability rows and repair metadata without maintaining another
     * capability list. Existing administrator roles receive every enforceable human core capability,
     * and affected principals have their security epoch raised once after the new grants are durable.
     *
     * @param   Connection  $database  Installation database.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function synchronizeCoreCapabilities(Connection $database): void
    {
        $owner = ContributionOwner::core();
        $definitions = CoreExtensionContributions::capabilityDefinitions();
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $administratorCapabilities = [];

        foreach ($definitions as $definition) {
            $values = [
                'description' => $definition->description,
                'owner_kind' => 'core',
                'owner_identifier' => $owner->identifier(),
                'allowed_scopes' => RuntimeCanonicalJson::encode($definition->allowedScopes),
                'delegable' => $definition->delegatable,
                'high_impact' => $definition->highImpact,
                'definition_version' => $definition->version,
                'definition_checksum' => ContributionDefinitionChecksum::calculate($owner, $definition),
                'lifecycle_state' => $definition->lifecycle->value,
            ];
            $exists = $database->fetchOne(sprintf(
                'SELECT code FROM %s WHERE code = ?',
                $this->tables->quoted('capabilities'),
            ), [$definition->id]);
            if ($exists === false) {
                $database->insert($this->tables->raw('capabilities'), [
                    'code' => $definition->id,
                    ...$values,
                ], [
                    'delegable' => Types::BOOLEAN,
                    'high_impact' => Types::BOOLEAN,
                ]);
            } else {
                $database->update(
                    $this->tables->raw('capabilities'),
                    $values,
                    ['code' => $definition->id],
                    [
                        'delegable' => Types::BOOLEAN,
                        'high_impact' => Types::BOOLEAN,
                    ],
                );
            }
            $this->ensureDefaultOwnership($database, 'capability', $definition->id);
            if ($definition->lifecycle->enforceable() && $definition->allowedScopes !== []) {
                $administratorCapabilities[] = $definition->id;
            }
        }

        $changedRoles = [];
        $roles = $database->fetchFirstColumn(sprintf(
            'SELECT id FROM %s WHERE code = ? ORDER BY id',
            $this->tables->quoted('roles'),
        ), ['administrator']);
        foreach ($roles as $roleId) {
            if (!is_string($roleId) || $roleId === '') {
                throw new RuntimeException('The stored administrator role identity is invalid.');
            }
            foreach ($administratorCapabilities as $capability) {
                $grant = $database->fetchOne(sprintf(
                    'SELECT id FROM %s WHERE role_id = ? AND capability_code = ? '
                    . "AND scope_type = 'global' AND scope_identifier IS NULL",
                    $this->tables->quoted('role_capability_grants'),
                ), [$roleId, $capability]);
                if ($grant !== false) {
                    continue;
                }
                $grantId = Uuid::uuid5(
                    Uuid::NAMESPACE_URL,
                    'kumwe:administrator:' . $roleId . ':' . $capability,
                )->toString();
                $database->insert($this->tables->raw('role_capability_grants'), [
                    'id' => $grantId,
                    'role_id' => $roleId,
                    'capability_code' => $capability,
                    'scope_type' => 'global',
                    'scope_identifier' => null,
                    'granted_at' => $now,
                    'granted_by' => null,
                ], ['granted_at' => Types::DATETIME_IMMUTABLE]);
                $this->ensureDefaultOwnership($database, 'grant', $grantId);
                $changedRoles[$roleId] = true;
            }
        }

        if ($changedRoles !== []) {
            $placeholders = implode(', ', array_fill(0, count($changedRoles), '?'));
            $database->executeStatement(sprintf(
                'UPDATE %s SET security_epoch = security_epoch + 1 WHERE id IN ('
                . 'SELECT DISTINCT user_id FROM %s WHERE role_id IN (%s))',
                $this->tables->quoted('users'),
                $this->tables->quoted('user_roles'),
                $placeholders,
            ), array_keys($changedRoles));
        }
    }

    /**
     * Backfill owner-aware metadata and base-policy declarations for already installed extensions.
     *
     * Current releases were signature-validated when installed, so their stored manifest is the
     * authoritative declaration to replay. A catalog collision stops migration instead of allowing a
     * package to take ownership of a core or foreign capability. The ABAC `resource_policies` table is
     * deliberately not touched; base action/resource bindings have their own declaration table.
     *
     * @param   Connection  $database  Installation database.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function backfillExtensionAuthorizationContributions(Connection $database): void
    {
        $rows = $database->fetchAllAssociative(sprintf(
            'SELECT e.id, e.identifier, r.manifest FROM %s e INNER JOIN %s r '
            . 'ON r.extension_id = e.id AND r.version = e.installed_version ORDER BY e.identifier',
            $this->tables->quoted('extensions'),
            $this->tables->quoted('extension_releases'),
        ));
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        foreach ($rows as $row) {
            $extensionId = $row['id'] ?? null;
            $identifier = $row['identifier'] ?? null;
            $manifestValue = $row['manifest'] ?? null;
            if (
                !is_string($extensionId)
                || !is_string($identifier)
                || (!is_string($manifestValue) && !is_array($manifestValue))
            ) {
                throw new RuntimeException('An installed extension release has invalid authorization metadata.');
            }
            $manifest = ExtensionManifest::fromJson(is_string($manifestValue)
                ? $manifestValue
                : json_encode($manifestValue, JSON_THROW_ON_ERROR));
            if ($manifest->identifier()->value() !== $identifier) {
                throw new RuntimeException('An installed extension release has inconsistent ownership.');
            }
            $owner = ContributionOwner::extension($identifier);

            $contributions = CanonicalManifestInterpreter::fromManifest($manifest);
            foreach ($contributions->capabilities() as $definition) {
                $recordedOwner = $database->fetchOne(sprintf(
                    'SELECT extension_id FROM %s WHERE capability_code = ?',
                    $this->tables->quoted('extension_contribution_capabilities'),
                ), [$definition->id]);
                if ($recordedOwner !== $extensionId) {
                    throw new RuntimeException(sprintf(
                        'Installed capability %s is not owned by its declaring extension.',
                        $definition->id,
                    ));
                }
                $database->update($this->tables->raw('capabilities'), [
                    'description' => $definition->description,
                    'owner_kind' => 'extension',
                    'owner_identifier' => $owner->identifier(),
                    'allowed_scopes' => RuntimeCanonicalJson::encode($definition->allowedScopes),
                    'delegable' => $definition->delegatable,
                    'high_impact' => $definition->highImpact,
                    'definition_version' => $definition->version,
                    'definition_checksum' => ContributionDefinitionChecksum::calculate($owner, $definition),
                    'lifecycle_state' => $definition->lifecycle->value,
                ], ['code' => $definition->id], [
                    'delegable' => Types::BOOLEAN,
                    'high_impact' => Types::BOOLEAN,
                ]);
            }

            foreach ($contributions->resourcePolicies() as $definition) {
                $values = [
                    'capability_code' => $definition->capability,
                    'definition' => $definition->toArray(),
                    'installation_global' => $definition->installationGlobal,
                    'lifecycle_state' => $definition->lifecycle->value,
                    'definition_version' => $definition->version,
                    'definition_checksum' => ContributionDefinitionChecksum::calculate($owner, $definition),
                    'updated_at' => $now,
                ];
                $types = [
                    'definition' => Types::JSON,
                    'installation_global' => Types::BOOLEAN,
                    'updated_at' => Types::DATETIME_IMMUTABLE,
                ];
                $recordedOwner = $database->fetchOne(sprintf(
                    'SELECT extension_id FROM %s WHERE policy_code = ?',
                    $this->tables->quoted('extension_contribution_resource_policies'),
                ), [$definition->id]);
                if ($recordedOwner !== false && $recordedOwner !== $extensionId) {
                    throw new RuntimeException(sprintf(
                        'Installed resource policy %s is owned by another extension.',
                        $definition->id,
                    ));
                }
                if ($recordedOwner === false) {
                    $database->insert($this->tables->raw('extension_contribution_resource_policies'), [
                        'extension_id' => $extensionId,
                        'policy_code' => $definition->id,
                        ...$values,
                        'created_at' => $now,
                    ], $types + ['created_at' => Types::DATETIME_IMMUTABLE]);
                    continue;
                }
                $database->update(
                    $this->tables->raw('extension_contribution_resource_policies'),
                    $values,
                    ['policy_code' => $definition->id],
                    $types,
                );
            }
        }
    }

    /**
     * Ensure one migration-created authorization resource belongs to the default site.
     *
     * @param   Connection  $database      Installation database.
     * @param   string      $resourceType  Stable authorization resource type.
     * @param   string      $resourceId    Capability code or grant UUID being recorded.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function ensureDefaultOwnership(
        Connection $database,
        string $resourceType,
        string $resourceId,
    ): void {
        $exists = $database->fetchOne(sprintf(
            'SELECT resource_id FROM %s WHERE resource_type = ? AND resource_id = ?',
            $this->tables->quoted('resource_site_ownership'),
        ), [$resourceType, $resourceId]);
        if ($exists !== false) {
            return;
        }

        $database->insert($this->tables->raw('resource_site_ownership'), [
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'site_identifier' => SiteContext::DEFAULT,
        ]);
    }

    /**
     * Backfill site ownership for records created before the business-security registry existed.
     *
     * The persisted physical blueprint is the only trusted source of generated identifiers. Ownership
     * uses definition UUID plus internal record UUID, because two definitions may legitimately carry the
     * same caller-selected UUID and the generic ownership table must still distinguish them.
     *
     * @param   Connection  $database  Installation database.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function backfillBusinessRecordOwnership(Connection $database): void
    {
        $installations = $database->fetchAllAssociative(sprintf(
            'SELECT site_identifier, blueprint FROM %s ORDER BY definition_id',
            $this->tables->quoted('business_schema_installations'),
        ));
        foreach ($installations as $installation) {
            $site = $installation['site_identifier'] ?? null;
            if (!is_string($site) || $site === '') {
                throw new RuntimeException('A business schema installation has an invalid site.');
            }
            $blueprint = $this->physicalBlueprint($installation['blueprint'] ?? null);
            $record = $blueprint->table('record')
                ?? throw new RuntimeException('A business schema installation has no record table.');
            $key = $record->column('record_id')
                ?? throw new RuntimeException('A business record table has no internal identity column.');
            $last = null;
            do {
                $sql = sprintf(
                    'SELECT %s FROM %s%s ORDER BY %s LIMIT 500',
                    $database->quoteSingleIdentifier($key->physicalName),
                    $database->quoteSingleIdentifier($record->physicalName),
                    $last === null
                        ? ''
                        : ' WHERE ' . $database->quoteSingleIdentifier($key->physicalName) . ' > ?',
                    $database->quoteSingleIdentifier($key->physicalName),
                );
                $recordKeys = $last === null
                    ? $database->fetchFirstColumn($sql)
                    : $database->fetchFirstColumn($sql, [$last], [Types::GUID]);
                $resourceIds = [];
                foreach ($recordKeys as $recordKey) {
                    if (!is_string($recordKey) || $recordKey === '') {
                        throw new RuntimeException('A business record has an invalid internal identity.');
                    }
                    $resourceIds[] = $blueprint->definitionId . ':' . $recordKey;
                }
                if ($resourceIds !== []) {
                    $owned = $database->fetchAllKeyValue(sprintf(
                        'SELECT resource_id, site_identifier FROM %s '
                        . 'WHERE resource_type = ? AND resource_id IN (?)',
                        $this->tables->quoted('resource_site_ownership'),
                    ), ['business_record', $resourceIds], [Types::STRING, ArrayParameterType::STRING]);
                    foreach ($resourceIds as $resourceId) {
                        $existingSite = $owned[$resourceId] ?? null;
                        if ($existingSite !== null && $existingSite !== $site) {
                            throw new RuntimeException('A business record ownership identity collides across sites.');
                        }
                        if ($existingSite === $site) {
                            continue;
                        }
                        $database->insert($this->tables->raw('resource_site_ownership'), [
                            'resource_type' => 'business_record',
                            'resource_id' => $resourceId,
                            'site_identifier' => $site,
                        ]);
                    }
                }
                $last = $recordKeys === [] ? null : end($recordKeys);
            } while (count($recordKeys) === 500 && is_string($last));
        }
    }

    /**
     * Reconstitute one persisted physical blueprint for an ownership backfill.
     *
     * @param   mixed  $value  DBAL-decoded JSON object or its encoded representation.
     *
     * @return  PhysicalSchemaBlueprint  Fully revalidated installed blueprint.
     *
     * @throws  RuntimeException  When the stored value is malformed JSON or not an object.
     *
     * @since   2.0.0
     */
    private function physicalBlueprint(mixed $value): PhysicalSchemaBlueprint
    {
        if (is_string($value)) {
            try {
                $value = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $failure) {
                throw new RuntimeException('A business schema installation blueprint is invalid.', 0, $failure);
            }
        }
        if (!is_array($value) || array_is_list($value)) {
            throw new RuntimeException('A business schema installation blueprint must be an object.');
        }

        $document = [];
        foreach ($value as $key => $entry) {
            if (!is_string($key)) {
                throw new RuntimeException('A business schema installation blueprint must use string keys.');
            }
            $document[$key] = $entry;
        }

        return PhysicalSchemaBlueprint::fromArray($document);
    }

    /**
     * Add exact organization, membership, family and parent bindings to delegated credentials.
     *
     * @param   Connection  $database  Installation database.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function extendTokens(Connection $database): void
    {
        $table = $database->createSchemaManager()->introspectTableByUnquotedName(
            $this->tables->raw('api_tokens'),
        );
        $columns = [
            'organization_identifier' => 'VARCHAR(191) DEFAULT NULL',
            'workspace_identifier' => 'VARCHAR(191) DEFAULT NULL',
            'membership_id' => 'VARCHAR(36) DEFAULT NULL',
            'membership_version' => 'INTEGER DEFAULT NULL',
            'policy_generation' => 'BIGINT DEFAULT NULL',
            'family_id' => 'VARCHAR(36) DEFAULT NULL',
            'parent_token_id' => 'VARCHAR(36) DEFAULT NULL',
            'delegation_depth' => 'SMALLINT NOT NULL DEFAULT 0',
            'owner_identifier' => "VARCHAR(191) NOT NULL DEFAULT 'core'",
        ];
        foreach ($columns as $name => $declaration) {
            if (!$table->hasColumn($name)) {
                $database->executeStatement(sprintf(
                    'ALTER TABLE %s ADD %s %s',
                    $this->tables->quoted('api_tokens'),
                    $database->quoteSingleIdentifier($name),
                    $declaration,
                ));
            }
        }
        $database->executeStatement(sprintf(
            'UPDATE %s SET family_id = id WHERE family_id IS NULL',
            $this->tables->quoted('api_tokens'),
        ));
    }

    /**
     * Add an authenticated, versioned membership selection to administrator sessions.
     *
     * @param   Connection  $database  Installation database.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function extendAdministratorSessions(Connection $database): void
    {
        $table = $database->createSchemaManager()->introspectTableByUnquotedName(
            $this->tables->raw('administrator_sessions'),
        );
        $columns = [
            'site_identifier' => "VARCHAR(191) NOT NULL DEFAULT 'default'",
            'organization_identifier' => 'VARCHAR(191) DEFAULT NULL',
            'workspace_identifier' => 'VARCHAR(191) DEFAULT NULL',
            'membership_id' => 'VARCHAR(36) DEFAULT NULL',
            'membership_version' => 'INTEGER DEFAULT NULL',
            'policy_generation' => 'BIGINT DEFAULT NULL',
            'rotation' => 'INTEGER NOT NULL DEFAULT 1',
            'step_up_at' => $database->getDatabasePlatform() instanceof PostgreSQLPlatform
                ? 'TIMESTAMP(6) WITHOUT TIME ZONE DEFAULT NULL'
                : 'DATETIME(6) DEFAULT NULL',
        ];
        foreach ($columns as $name => $declaration) {
            if (!$table->hasColumn($name)) {
                $database->executeStatement(sprintf(
                    'ALTER TABLE %s ADD %s %s',
                    $this->tables->quoted('administrator_sessions'),
                    $database->quoteSingleIdentifier($name),
                    $declaration,
                ));
            }
        }
    }

    /**
     * Reproduce the canonical site identifier's physical character definition on new referencing columns.
     *
     * MariaDB requires both sides of a textual foreign key to have identical charset and collation. Doctrine's
     * schema-diff and standalone-table creation paths can resolve different physical defaults, so relying on
     * equal logical DBAL types is insufficient.
     *
     * @param   Column  $column  Introspected canonical `sites.identifier` column.
     *
     * @return  array{length: int, fixed: bool, platformOptions: array<string, string>}  Options for every
     *          new site identifier column, including character metadata when the platform exposes it.
     *
     * @throws  RuntimeException  When the canonical site identifier is not the expected bounded string.
     * @since   2.0.0
     */
    private function siteIdentifierOptions(Column $column): array
    {
        $length = $column->getLength();
        if (Type::lookupName($column->getType()) !== Types::STRING || !is_int($length) || $length < 1) {
            throw new RuntimeException('The canonical site identifier column is incompatible.');
        }

        $platformOptions = [];
        $charset = $column->getCharset();
        if ($charset !== null) {
            $platformOptions['charset'] = $charset;
        }
        $collation = $column->getCollation();
        if ($collation !== null) {
            $platformOptions['collation'] = $collation;
        }

        return [
            'length' => $length,
            'fixed' => $column->getFixed(),
            'platformOptions' => $platformOptions,
        ];
    }

    /**
     * Add the site-wide generation used to serialize conditional-policy plans and writes.
     *
     * @param   Connection  $database  Installation database.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function extendSites(Connection $database): void
    {
        $table = $database->createSchemaManager()->introspectTableByUnquotedName(
            $this->tables->raw('sites'),
        );
        if ($table->hasColumn('policy_generation')) {
            return;
        }
        $database->executeStatement(sprintf(
            'ALTER TABLE %s ADD %s BIGINT NOT NULL DEFAULT 1',
            $this->tables->quoted('sites'),
            $database->quoteSingleIdentifier('policy_generation'),
        ));
    }

    /**
     * Add a portable primary key without deprecated schema APIs.
     *
     * @param   Table   $table    Table being defined.
     * @param   string  ...$names Ordered primary-key columns.
     *
     * @return  void
     *
     * @throws  RuntimeException  When the migration declares no primary-key column or an empty column name.
     *
     * @since   2.0.0
     */
    private function primary(Table $table, string ...$names): void
    {
        if ($names === [] || array_any($names, static fn (string $name): bool => $name === '')) {
            throw new RuntimeException('A migration primary key requires non-empty column names.');
        }
        /** @var non-empty-list<non-empty-string> $names */
        $table->addPrimaryKeyConstraint(
            PrimaryKeyConstraint::editor()->setUnquotedColumnNames(...$names)->create(),
        );
    }
}
