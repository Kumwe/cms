<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessSchema\Application;

use DateTimeImmutable;
use Kumwe\App\BusinessDefinition\Application\BusinessDefinitionRepository;
use Kumwe\App\BusinessSchema\Domain\SchemaInstallationStatus;
use Kumwe\Context\Value\SiteContext;

/**
 * Applies an extension owner's activation change to every business schema that owner has installed.
 *
 * Keeps preserved physical data unavailable while an owning extension is disabled: deactivation changes
 * nothing but status, withdrawing a live installation from record traffic and holding an in-flight one
 * aside, so the tables and their rows stay exactly where they are. Reactivation is the guarded
 * direction. A withheld installation returns to service only when no unfinished execution is outstanding
 * for its definition, the published definition version it names is still present and still owned by this
 * extension, and both a fresh compilation and the live database checksum to what the installation
 * recorded. Anything else is refused, so a schema that has drifted has to come back through a new
 * approved plan rather than being reopened silently.
 *
 * @since  2.0.0
 */
final readonly class BusinessSchemaLifecycleManager implements BusinessSchemaLifecycleObserver
{
    /**
     * Capture the collaborators the reconciliation sweep reads and writes through.
     *
     * @param  BusinessDefinitionRepository          $definitions     Source of the published definition a
     *         reactivation is re-proved against.
     * @param  DefinitionPhysicalSchemaCompiler      $compiler        Recompiles the blueprint a
     *         reactivation compares checksums against.
     * @param  BusinessSchemaInstallationRepository  $installations   Locked read of an owner's rows, and
     *         the status writes that follow it.
     * @param  BusinessSchemaPlanRepository          $plans           Consulted for an execution left
     *         unfinished on a definition.
     * @param  PhysicalSchemaGateway                 $physicalSchema  Inspects the live tables so drift
     *         blocks a reactivation.
     *
     * @since  2.0.0
     */
    public function __construct(
        private BusinessDefinitionRepository $definitions,
        private DefinitionPhysicalSchemaCompiler $compiler,
        private BusinessSchemaInstallationRepository $installations,
        private BusinessSchemaPlanRepository $plans,
        private PhysicalSchemaGateway $physicalSchema,
    ) {
    }

    /**
     * Bring every installation this owner holds into line with the owner's new activation state.
     *
     * Deactivation is unconditional and lossless: an active installation is disabled, an installing one
     * is preserved, and any other status is left untouched. Reactivation considers only disabled and
     * preserved rows, and re-proves each one before saving it; the first row that fails aborts the whole
     * sweep, so the owner's activation rolls back with the caller's transaction rather than taking effect
     * over a schema nobody re-proved. The rows are read under a write lock, which is why this has to run
     * inside that transaction.
     *
     * @param   string             $ownerIdentifier  `core`, an extension handle, or `vendor/package`.
     * @param   bool               $active           True when the owner just became active, false when disabled.
     * @param   DateTimeImmutable  $at               Instant recorded as the update time on every row changed.
     *
     * @return  void
     *
     * @throws  BusinessSchemaConflict  When a reactivation candidate still has an unfinished execution,
     *          has lost the published version it names, is no longer owned by this extension, or no longer
     *          matches the schema checksum it recorded — including when its tables have drifted or are no
     *          longer there at all.
     *
     * @since   2.0.0
     */
    public function setOwnerActive(string $ownerIdentifier, bool $active, DateTimeImmutable $at): void
    {
        foreach ($this->installations->ownedByForUpdate($ownerIdentifier) as $installation) {
            if (!$active) {
                if ($installation->status === SchemaInstallationStatus::Active) {
                    $this->installations->save($installation->disable($at));
                } elseif ($installation->status === SchemaInstallationStatus::Installing) {
                    $this->installations->save($installation->preserve($at));
                }
                continue;
            }
            if (
                !in_array(
                    $installation->status,
                    [SchemaInstallationStatus::Disabled, SchemaInstallationStatus::Preserved],
                    true,
                )
            ) {
                continue;
            }
            $site = SiteContext::fromString($installation->siteIdentifier);
            if ($this->plans->hasUnfinishedExecution($site, $installation->definitionId)) {
                throw new BusinessSchemaConflict(
                    'An extension schema cannot reactivate while schema recovery is incomplete.',
                );
            }
            $record = $this->definitions->published(
                $site,
                $installation->definitionId,
                $installation->definitionVersion,
            )
                ?? throw new BusinessSchemaConflict(
                    'An extension schema cannot reactivate without its published definition.',
                );
            $target = $this->compiler->compile($record->definition, $site);
            $inspected = $this->physicalSchema->inspect($installation->blueprint);
            if (
                $record->definition->owner->identifier !== $ownerIdentifier
                || !hash_equals($target->checksum(), $installation->schemaChecksum)
                || $inspected === null
                || !hash_equals($inspected->checksum(), $installation->schemaChecksum)
            ) {
                throw new BusinessSchemaConflict(
                    'An extension schema requires an approved synchronization plan before reactivation.',
                );
            }
            $this->installations->save($installation->reactivate($at));
        }
    }
}
