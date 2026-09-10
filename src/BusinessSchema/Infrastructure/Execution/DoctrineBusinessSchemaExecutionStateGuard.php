<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessSchema\Infrastructure\Execution;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Kumwe\App\BusinessSchema\Application\BusinessSchemaConflict;
use Kumwe\App\BusinessSchema\Application\BusinessSchemaExecutionStateGuard;
use Kumwe\App\BusinessSchema\Domain\SchemaInstallationStatus;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\Context\Value\SiteContext;

/**
 * Doctrine-backed locking reads the executor takes before it finalizes a schema execution.
 *
 * A plan runs for a long time beside ordinary administration, so the facts it started from — who owns
 * the definition, whether that owner is still active, and what state the installation is in — can move
 * underneath it. This adapter re-reads them under `FOR UPDATE` inside the caller's transaction, which
 * holds them still until that transaction commits and turns a concurrent change into a refusal instead
 * of a lost update. It insists on an already-open transaction rather than opening one of its own, so the
 * rows stay locked for the whole finalizing write, and it reports driver failures as
 * `BusinessSchemaConflict` so no Doctrine exception escapes into the application layer.
 *
 * @since  2.0.0
 */
final readonly class DoctrineBusinessSchemaExecutionStateGuard implements BusinessSchemaExecutionStateGuard
{
    /**
     * Bind the guard to the connection whose transaction the locking reads must join.
     *
     * @param  Connection  $database  Connection the finalizing transaction is already open on.
     * @param  TableNames  $tables    Resolver for the definition and installation table names.
     *
     * @since  2.0.0
     */
    public function __construct(private Connection $database, private TableNames $tables)
    {
    }

    /**
     * Lock the definition row and confirm it still belongs to the site and owner execution began under.
     *
     * The returned flag is the reason this is a read rather than an assertion: a plan may legitimately
     * finalize while its owning extension is disabled, and the caller uses the flag to decide which
     * installation states it will then accept.
     *
     * @param   SiteContext  $site             Site the execution is authorized for.
     * @param   string       $definitionId     Definition whose row is locked.
     * @param   string       $ownerIdentifier  Owner the plan was built and approved for.
     * @param   bool         $activeRequired   Whether an inactive owner should be refused outright.
     *
     * @return  bool  Whether the owning extension is currently marked active.
     *
     * @throws  BusinessSchemaConflict  When no transaction is open, the row could not be locked, the
     *          definition has gone, its site or owner changed, or an active owner was required and the
     *          owner is not active.
     *
     * @since   2.0.0
     */
    public function lockOwner(
        SiteContext $site,
        string $definitionId,
        string $ownerIdentifier,
        bool $activeRequired,
    ): bool {
        $this->assertTransaction();
        try {
            $row = $this->database->fetchAssociative(sprintf(
                'SELECT site_identifier, owner_identifier, owner_active FROM %s WHERE id = ? FOR UPDATE',
                $this->tables->quoted('business_definitions'),
            ), [$definitionId]);
        } catch (DbalException $failure) {
            throw new BusinessSchemaConflict('The definition owner could not be locked for finalization.', 0, $failure);
        }
        $active = $row !== false
            && in_array($row['owner_active'] ?? null, [true, 1, '1', 't', 'true'], true);
        if (
            $row === false
            || ($row['site_identifier'] ?? null) !== $site->identifier()
            || ($row['owner_identifier'] ?? null) !== $ownerIdentifier
            || ($activeRequired && !$active)
        ) {
            throw new BusinessSchemaConflict('The definition owner changed during schema execution.');
        }

        return $active;
    }

    /**
     * Lock this definition's installation row and read the status it currently holds.
     *
     * A stored status this build cannot interpret is reported as a conflict rather than folded into the
     * null case, because finalizing over it would quietly discard whatever state it was recording.
     *
     * @param   string  $definitionId  Definition whose installation row is locked.
     *
     * @return  ?SchemaInstallationStatus  The locked status, or null when the definition has no
     *          installation row yet, which is the normal shape of a first install.
     *
     * @throws  BusinessSchemaConflict  When no transaction is open, the row could not be locked, or the
     *          stored status is not a value this build understands.
     *
     * @since   2.0.0
     */
    public function lockInstallationStatus(string $definitionId): ?SchemaInstallationStatus
    {
        $this->assertTransaction();
        try {
            $status = $this->database->fetchOne(sprintf(
                'SELECT status FROM %s WHERE definition_id = ? FOR UPDATE',
                $this->tables->quoted('business_schema_installations'),
            ), [$definitionId]);
        } catch (DbalException $failure) {
            throw new BusinessSchemaConflict(
                'The schema installation could not be locked for finalization.',
                0,
                $failure,
            );
        }
        if ($status === false) {
            return null;
        }
        if (!is_string($status) || SchemaInstallationStatus::tryFrom($status) === null) {
            throw new BusinessSchemaConflict('The locked schema installation status is invalid.');
        }

        return SchemaInstallationStatus::from($status);
    }

    /**
     * Refuse to take a locking read outside a transaction.
     *
     * A `FOR UPDATE` issued in autocommit releases its lock the moment the statement returns, so the
     * read would look correct and guarantee nothing for the write that follows it.
     *
     * @return  void
     *
     * @throws  BusinessSchemaConflict  When the connection has no active transaction.
     *
     * @since   2.0.0
     */
    private function assertTransaction(): void
    {
        if (!$this->database->isTransactionActive()) {
            throw new BusinessSchemaConflict('Schema finalization requires an active database transaction.');
        }
    }
}
