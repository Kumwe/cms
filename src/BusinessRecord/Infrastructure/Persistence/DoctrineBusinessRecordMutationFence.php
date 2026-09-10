<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessRecord\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Kumwe\App\BusinessRecord\Application\BusinessRecordMutationFence;
use Kumwe\App\BusinessRecord\Application\BusinessRecordMutationGeneration;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordDefinitionUnavailable;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordSchemaUnavailable;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordTemporarilyUnavailable;
use Kumwe\App\BusinessSchema\Domain\SchemaInstallationStatus;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Context\Value\SiteContext;
use Ramsey\Uuid\Uuid;

/**
 * Mutation fence taken as a locking read across the definition and schema-installation tables.
 *
 * Holds the installation row lock until the caller's record transaction commits or rolls back. One
 * `SELECT` joins a site's business definition to its schema installation and carries the lock clause,
 * so the two rows are observed together and pinned for the rest of that transaction — an installer
 * cannot publish a version, disable an owner, or alter the physical tables in the window between a
 * caller resolving a definition and touching its rows. The exclusive fence uses `FOR UPDATE`; the
 * shared one is platform-specific, so MySQL and PostgreSQL are named outright and any other platform is
 * refused rather than quietly served by an unlocked read. Where the join finds nothing a second locking
 * read separates a definition that is absent, or whose owner is disabled, from one whose schema is
 * simply not installed, so the caller learns which of the two it hit. Every DBAL failure is translated,
 * so no driver exception leaves the adapter.
 *
 * @since  2.0.0
 */
final readonly class DoctrineBusinessRecordMutationFence implements BusinessRecordMutationFence
{
    /**
     * Wire the fence to its connection and physical table naming.
     *
     * @param  Connection  $database  DBAL connection whose active transaction holds the row locks.
     * @param  TableNames  $tables    Resolver for the prefixed `business_definitions` and
     *         `business_schema_installations` table names.
     *
     * @since  2.0.0
     */
    public function __construct(
        private Connection $database,
        private TableNames $tables,
    ) {
    }

    /**
     * Take the exclusive fence a record mutation runs behind, using `FOR UPDATE`.
     *
     * Only the site is read off the execution context; the actor plays no part in choosing the row, as
     * authorization has already been settled by the time a fence is taken.
     *
     * @param   ExecutionContext  $context               Actor and site the mutation runs as.
     * @param   string            $definitionIdentifier  Definition UUID or handle to fence.
     *
     * @return  BusinessRecordMutationGeneration  Installation identity, version, checksums and status as
     *          observed under the exclusive lock.
     *
     * @throws  BusinessRecordDefinitionUnavailable  When no definition on this site matches the
     *          identifier, or its owner is disabled.
     * @throws  BusinessRecordSchemaUnavailable  When the definition has no active installation registered
     *          to this site and to the definition's own owner.
     * @throws  BusinessRecordTemporarilyUnavailable  When no transaction is open to hold the lock, or the
     *          locking read fails.
     *
     * @since   2.0.0
     */
    public function lock(
        ExecutionContext $context,
        string $definitionIdentifier,
    ): BusinessRecordMutationGeneration {
        return $this->acquire($context->site(), $definitionIdentifier, 'FOR UPDATE', false);
    }

    /**
     * Take the shared fence a record read runs behind, in the platform's own share-lock syntax.
     *
     * MySQL gets `LOCK IN SHARE MODE` and PostgreSQL `FOR SHARE`; any other platform is reported as
     * temporarily unavailable rather than served by an unlocked read. Passing $historyOnly widens what
     * the fence accepts to a disabled or preserved installation, and forgives a disabled owner on a
     * definition that does have an installation, which is what lets an authorized history read describe
     * records whose type no longer accepts mutations.
     *
     * @param   SiteContext  $site                  Site whose installation of the definition is fenced.
     * @param   string       $definitionIdentifier  Definition UUID or handle to fence.
     * @param   bool         $historyOnly           True to also accept a withdrawn installation and a
     *          disabled owner, for reads of preserved history.
     *
     * @return  BusinessRecordMutationGeneration  Installation identity, version, checksums and status as
     *          observed under the shared lock.
     *
     * @throws  BusinessRecordDefinitionUnavailable  When no definition on this site matches the
     *          identifier, or its owner is disabled and either $historyOnly is false or the definition
     *          has no installation at all.
     * @throws  BusinessRecordSchemaUnavailable  When no installation of the definition carries a status
     *          this call admits, or one is registered to another site or owner.
     * @throws  BusinessRecordTemporarilyUnavailable  When no transaction is open to hold the lock, the
     *          platform has no share-lock syntax this adapter knows, or the locking read fails.
     *
     * @since   2.0.0
     */
    public function shared(
        SiteContext $site,
        string $definitionIdentifier,
        bool $historyOnly = false,
    ): BusinessRecordMutationGeneration {
        $platform = $this->database->getDatabasePlatform();
        $clause = match (true) {
            $platform instanceof AbstractMySQLPlatform => 'LOCK IN SHARE MODE',
            $platform instanceof PostgreSQLPlatform => 'FOR SHARE',
            default => throw new BusinessRecordTemporarilyUnavailable(),
        };

        return $this->acquire($site, $definitionIdentifier, $clause, $historyOnly);
    }

    /**
     * Run the locking read behind both fences and turn the row it pins into a generation.
     *
     * A UUID-shaped identifier is matched against either the definition ID or its handle, anything else
     * against the handle alone; the identifier is always bound, and the only text interpolated into the
     * statement is the table names, that identity predicate chosen between two constants, and the lock
     * clause the public methods pick from a fixed set. Where the join yields nothing, a second locking
     * read over the definitions table alone tells a definition that is absent or whose owner is disabled
     * apart from one whose schema is not installed. On the joined row the installation's own site and
     * owner are re-checked against the definition, so a row registered elsewhere is refused rather than
     * mutated, and its status must be one this call admits.
     *
     * @param   SiteContext  $site                  Site whose definition and installation rows are read.
     * @param   string       $definitionIdentifier  Definition UUID or handle to fence.
     * @param   string       $lockClause            Trailing SQL lock clause chosen by the caller; never
     *          derived from caller input.
     * @param   bool         $historyOnly           True to admit disabled and preserved installations
     *          alongside active ones, and to overlook a disabled owner on the joined row.
     *
     * @return  BusinessRecordMutationGeneration  Installation identity, version, checksums and status as
     *          observed under the lock.
     *
     * @throws  BusinessRecordDefinitionUnavailable  When no definition on this site matches the
     *          identifier, or its owner is disabled and this call does not overlook that.
     * @throws  BusinessRecordSchemaUnavailable  When no installation joined the definition, one is
     *          registered to another site or owner, its status is unknown or not admitted here, or a
     *          column of the locked row is missing or malformed.
     * @throws  BusinessRecordTemporarilyUnavailable  When no transaction is open to hold the lock, or a
     *          locking read fails at the driver.
     *
     * @since   2.0.0
     */
    private function acquire(
        SiteContext $site,
        string $definitionIdentifier,
        string $lockClause,
        bool $historyOnly,
    ): BusinessRecordMutationGeneration {
        if (!$this->database->isTransactionActive()) {
            throw new BusinessRecordTemporarilyUnavailable();
        }
        $uuid = Uuid::isValid($definitionIdentifier);
        $identity = $uuid ? '(h.id = ? OR h.handle = ?)' : 'h.handle = ?';
        $parameters = $uuid
            ? [$site->identifier(), $definitionIdentifier, $definitionIdentifier]
            : [$site->identifier(), $definitionIdentifier];
        try {
            $row = $this->database->fetchAssociative(sprintf(
                'SELECT h.id AS definition_id, h.owner_identifier AS definition_owner, h.owner_active, '
                    . 'i.site_identifier, i.owner_identifier AS installation_owner, i.definition_version, '
                    . 'i.definition_checksum, i.schema_checksum, i.status FROM %s h INNER JOIN %s i '
                    . 'ON i.definition_id = h.id WHERE h.site_identifier = ? AND %s %s',
                $this->tables->quoted('business_definitions'),
                $this->tables->quoted('business_schema_installations'),
                $identity,
                $lockClause,
            ), $parameters);
        } catch (DbalException $failure) {
            throw new BusinessRecordTemporarilyUnavailable($failure);
        }
        if ($row === false) {
            try {
                $definition = $this->database->fetchAssociative(sprintf(
                    'SELECT h.owner_active FROM %s h WHERE h.site_identifier = ? AND %s %s',
                    $this->tables->quoted('business_definitions'),
                    $identity,
                    $lockClause,
                ), $parameters);
            } catch (DbalException $failure) {
                throw new BusinessRecordTemporarilyUnavailable($failure);
            }
            if ($definition === false) {
                throw new BusinessRecordDefinitionUnavailable();
            }
            if (!in_array($definition['owner_active'] ?? null, [true, 1, '1'], true)) {
                throw new BusinessRecordDefinitionUnavailable('The business-definition owner is disabled.');
            }
            throw new BusinessRecordSchemaUnavailable();
        }
        $ownerActive = $row['owner_active'] ?? null;
        if (!$historyOnly && !in_array($ownerActive, [true, 1, '1'], true)) {
            throw new BusinessRecordDefinitionUnavailable('The business-definition owner is disabled.');
        }
        $owner = $this->string($row, 'definition_owner');
        $status = SchemaInstallationStatus::tryFrom($this->string($row, 'status'));
        $allowed = $historyOnly
            ? [
                SchemaInstallationStatus::Active,
                SchemaInstallationStatus::Disabled,
                SchemaInstallationStatus::Preserved,
            ]
            : [SchemaInstallationStatus::Active];
        if (
            $this->string($row, 'site_identifier') !== $site->identifier()
            || $this->string($row, 'installation_owner') !== $owner
            || $status === null
            || !in_array($status, $allowed, true)
        ) {
            throw new BusinessRecordSchemaUnavailable();
        }

        return new BusinessRecordMutationGeneration(
            $this->string($row, 'definition_id'),
            $this->string($row, 'site_identifier'),
            $owner,
            $this->integer($row, 'definition_version'),
            $this->string($row, 'definition_checksum'),
            $this->string($row, 'schema_checksum'),
            $status,
        );
    }

    /**
     * Read a column the locked row must carry as a non-empty string.
     *
     * @param   array<string, mixed>  $row  Associative row from the locking read.
     * @param   string                $key  Column alias to read.
     *
     * @return  string  The stored value, guaranteed non-empty.
     *
     * @throws  BusinessRecordSchemaUnavailable  When the column is absent, is not a string, or is empty.
     *
     * @since   2.0.0
     */
    private function string(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new BusinessRecordSchemaUnavailable();
        }

        return $value;
    }

    /**
     * Read a column the locked row must carry as an integer.
     *
     * An integer is taken as it stands. Some drivers return the column as a string, so that form is
     * accepted too, but only when it spells a positive decimal with no leading zero — anything looser
     * would let a malformed installation version through as a silently coerced number.
     *
     * @param   array<string, mixed>  $row  Associative row from the locking read.
     * @param   string                $key  Column alias to read.
     *
     * @return  int  The stored value.
     *
     * @throws  BusinessRecordSchemaUnavailable  When the column is absent, or is neither an integer nor a
     *          string spelling a positive decimal without a leading zero.
     *
     * @since   2.0.0
     */
    private function integer(array $row, string $key): int
    {
        $value = $row[$key] ?? null;
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value) === 1) {
            return (int) $value;
        }

        throw new BusinessRecordSchemaUnavailable();
    }
}
