<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessRecord\Application;

use Kumwe\App\BusinessDefinition\Application\BusinessDefinitionRepository;
use Kumwe\App\BusinessDefinition\Domain\DefinitionStatus;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordDefinitionUnavailable;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordSchemaUnavailable;
use Kumwe\App\BusinessSchema\Application\BusinessSchemaInstallationRepository;
use Kumwe\App\BusinessSchema\Domain\SchemaInstallation;
use Kumwe\App\BusinessSchema\Domain\SchemaInstallationStatus;
use Kumwe\Context\Value\ExecutionContext;

/**
 * Resolver that reconciles the published definition catalog against the installed schema rows.
 *
 * This is the implementation every record operation runs against on a live site. It reads shapes from
 * `BusinessDefinitionRepository` and installed state from `BusinessSchemaInstallationRepository`, and
 * returns a pair only when the installation belongs to the caller's site, is owned by the same owner as
 * the catalog entry, and names a published version that is not rejected. Where the two stores should
 * describe the same shape it compares checksums with `hash_equals`, so a tampered or half-applied
 * installation row fails loudly instead of quietly shaping reads and writes wrongly.
 *
 * Nothing is cached: each call re-reads both stores, so the answer is only as current as the moment it
 * was asked for. Callers that need it to hold across several statements take a
 * `BusinessRecordMutationFence` generation and assert the resolved pair against it.
 *
 * @since  2.0.0
 */
final readonly class InstalledBusinessRecordDefinitionResolver implements BusinessRecordDefinitionResolver
{
    /**
     * Wire the resolver to the two stores it reconciles.
     *
     * @param  BusinessDefinitionRepository          $definitions    Catalog of definitions and their
     *         immutable published versions.
     * @param  BusinessSchemaInstallationRepository  $installations  Rows recording which definition
     *         version each site physically has installed.
     *
     * @since  2.0.0
     */
    public function __construct(
        private BusinessDefinitionRepository $definitions,
        private BusinessSchemaInstallationRepository $installations,
    ) {
    }

    /**
     * Resolve every definition on the caller's site whose schema installation is active.
     *
     * A catalog entry whose owner is disabled is skipped, as is one whose installation is missing, not
     * active, or recorded against another site or owner: at this entry point a mismatch is a filter
     * rather than a failure, because the catalog legitimately holds more than this site can use. Once an
     * installation has passed those filters, a version that is missing, rejected, or whose checksum does
     * not match is fatal — it means the tables and the published shape have diverged. The result is
     * ordered by definition handle so repeated scans agree with each other. Installations and exact versions
     * are batch-loaded after the bounded catalog read, keeping generated discovery from becoming an N+1 query.
     *
     * @param   ExecutionContext  $context  Actor and site whose definition catalog is scanned.
     *
     * @return  list<ResolvedBusinessDefinition>  One pair per usable active installation, ordered by
     *          handle; empty when the site has none.
     *
     * @throws  BusinessRecordSchemaUnavailable  When an active installation names a catalog version that
     *          is missing or rejected, or whose checksum differs from the one the installation records.
     *
     * @since   2.0.0
     */
    public function activeInstalled(ExecutionContext $context): array
    {
        $entries = [];
        foreach ($this->definitions->catalog($context->site()) as $entry) {
            if ($entry->ownerActive) {
                $entries[] = $entry;
            }
        }
        if (count($entries) > 4096) {
            throw new BusinessRecordSchemaUnavailable('The active definition catalog exceeds its safe bound.');
        }
        $definitionIds = [];
        foreach ($entries as $entry) {
            $definitionIds[] = $entry->id;
        }
        $installations = $this->installations->findBatch($definitionIds);
        $eligible = [];
        $requestedVersions = [];
        foreach ($entries as $entry) {
            $installation = $installations[$entry->id] ?? null;
            if (
                $installation === null
                || $installation->status !== SchemaInstallationStatus::Active
                || $installation->siteIdentifier !== $context->site()->identifier()
                || $installation->ownerIdentifier !== $entry->owner->identifier
            ) {
                continue;
            }
            $eligible[] = [$entry, $installation];
            $requestedVersions[$entry->id] = $installation->definitionVersion;
        }
        $versions = $this->definitions->publishedBatch($context->site(), $requestedVersions);
        $resolved = [];
        foreach ($eligible as [$entry, $installation]) {
            $version = $versions[$entry->id] ?? null;
            if (
                $version === null || $version->status === DefinitionStatus::Rejected
                || !hash_equals($version->definition->checksum(), $installation->definitionChecksum)
            ) {
                throw new BusinessRecordSchemaUnavailable(
                    'An active installed definition disagrees with its immutable catalog version.',
                );
            }
            $resolved[] = new ResolvedBusinessDefinition($version->definition, $installation);
        }
        usort(
            $resolved,
            static fn (ResolvedBusinessDefinition $left, ResolvedBusinessDefinition $right): int =>
                $left->definition->handle <=> $right->definition->handle,
        );

        return $resolved;
    }

    /**
     * Resolve the definition version a live read or write must be performed against.
     *
     * This is the strictest entry point: the owner must be active, the installation must be active, and
     * the version resolved is the one that installation records. Its checksum is compared against the
     * installation before the pair is returned, so no caller can shape rows with a definition the tables
     * do not match.
     *
     * @param   ExecutionContext  $context     Actor and site the operation runs as.
     * @param   string            $identifier  Definition UUID or handle naming the record type.
     *
     * @return  ResolvedBusinessDefinition  The installed version paired with its installation row.
     *
     * @throws  BusinessRecordDefinitionUnavailable  When no catalog entry matches the identifier, its
     *          owner is disabled, or the installed version is unpublished or rejected.
     * @throws  BusinessRecordSchemaUnavailable  When no active installation exists for the caller's site
     *          and owner, or its checksum disagrees with the published version.
     *
     * @since   2.0.0
     */
    public function forCreate(ExecutionContext $context, string $identifier): ResolvedBusinessDefinition
    {
        [$definitionId, $installation] = $this->installation($context, $identifier);
        $version = $this->definitions->published(
            $context->site(),
            $definitionId,
            $installation->definitionVersion,
        );
        if ($version === null || $version->status === DefinitionStatus::Rejected) {
            throw new BusinessRecordDefinitionUnavailable();
        }
        if (!hash_equals($version->definition->checksum(), $installation->definitionChecksum)) {
            throw new BusinessRecordSchemaUnavailable('The installed schema and published definition disagree.');
        }

        return new ResolvedBusinessDefinition($version->definition, $installation);
    }

    /**
     * Resolve an older published version of a still-live definition, as pinned by a stored row.
     *
     * Rows record the version they were written under, so reading one back needs that shape rather than
     * the newest installed one. The owner and installation must still be active and the requested
     * version may not run ahead of the installed one; no checksum comparison applies, because an older
     * version is expected to differ from the installed shape.
     *
     * @param   ExecutionContext  $context            Actor and site the operation runs as.
     * @param   string            $identifier         Definition UUID or handle naming the record type.
     * @param   int               $definitionVersion  Published version the stored row was written under.
     *
     * @return  ResolvedBusinessDefinition  The pinned version paired with the live installation row.
     *
     * @throws  BusinessRecordDefinitionUnavailable  When no catalog entry matches the identifier, its
     *          owner is disabled, or that version is not published.
     * @throws  BusinessRecordSchemaUnavailable  When no active installation exists for the caller's site
     *          and owner, or the requested version is newer than the installed one.
     *
     * @since   2.0.0
     */
    public function pinned(
        ExecutionContext $context,
        string $identifier,
        int $definitionVersion,
    ): ResolvedBusinessDefinition {
        [$definitionId, $installation] = $this->installation($context, $identifier);
        if ($definitionVersion > $installation->definitionVersion) {
            throw new BusinessRecordSchemaUnavailable('The record definition version is newer than installed schema.');
        }
        $version = $this->definitions->published($context->site(), $definitionId, $definitionVersion);
        if ($version === null || $version->status === DefinitionStatus::Rejected) {
            throw new BusinessRecordDefinitionUnavailable('The record\'s pinned definition version is unavailable.');
        }

        return new ResolvedBusinessDefinition($version->definition, $installation);
    }

    /**
     * Resolve preserved metadata for a history read, tolerating a record type that is out of service.
     *
     * Unlike the live entry points this also accepts an installing, disabled or preserved installation
     * and ignores whether the definition's owner is still active, so revisions stay readable after the
     * record type stops accepting traffic. Omitting the version resolves the installed one. Treat the
     * result as descriptive only: the installation it names may not be serving requests at all.
     *
     * @param   ExecutionContext  $context            Actor and site the history read runs as.
     * @param   string            $identifier         Definition UUID or handle naming the record type.
     * @param   ?int              $definitionVersion  Version the revision was written under, or null to
     *          fall back to the installed version.
     *
     * @return  ResolvedBusinessDefinition  The requested version paired with its installation row,
     *          whatever lifecycle status that installation currently carries.
     *
     * @throws  BusinessRecordDefinitionUnavailable  When no catalog entry matches the identifier, or the
     *          requested version is not published.
     * @throws  BusinessRecordSchemaUnavailable  When no retained installation exists for the caller's
     *          site and owner, or the requested version is newer than the installed one.
     *
     * @since   2.0.0
     */
    public function forHistory(
        ExecutionContext $context,
        string $identifier,
        ?int $definitionVersion = null,
    ): ResolvedBusinessDefinition {
        [$definitionId, $installation] = $this->installation($context, $identifier, true);
        $definitionVersion ??= $installation->definitionVersion;
        if ($definitionVersion > $installation->definitionVersion) {
            throw new BusinessRecordSchemaUnavailable('The historical definition is newer than installed schema.');
        }
        $version = $this->definitions->published($context->site(), $definitionId, $definitionVersion);
        if ($version === null || $version->status === DefinitionStatus::Rejected) {
            throw new BusinessRecordDefinitionUnavailable('A historical pinned definition is unavailable.');
        }

        return new ResolvedBusinessDefinition($version->definition, $installation);
    }

    /**
     * Look up one definition's catalog entry and installation row, enforcing the site and owner match.
     *
     * This is where the three single-definition entry points differ: a live lookup demands an active
     * owner and an active installation, while a history lookup tolerates a disabled owner and also
     * accepts an installing, disabled or preserved installation. Both reject an installation recorded
     * against another site or another owner, so a definition handle cannot be used to reach across a
     * tenancy boundary.
     *
     * @param   ExecutionContext  $context      Actor and site the lookup runs as.
     * @param   string            $identifier   Definition UUID or handle naming the record type.
     * @param   bool              $historyOnly  True to accept a retained installation and a disabled
     *          owner, as a read of preserved history requires.
     *
     * @return  array{string, SchemaInstallation}  The catalog entry's definition ID and the installation
     *          row that passed the checks.
     *
     * @throws  BusinessRecordDefinitionUnavailable  When no catalog entry matches the identifier, or its
     *          owner is disabled on a live lookup.
     * @throws  BusinessRecordSchemaUnavailable  When no installation exists, its status is not one this
     *          lookup accepts, or it belongs to another site or owner.
     *
     * @since   2.0.0
     */
    private function installation(
        ExecutionContext $context,
        string $identifier,
        bool $historyOnly = false,
    ): array {
        $entry = $this->definitions->entry($context->site(), $identifier);
        if ($entry === null) {
            throw new BusinessRecordDefinitionUnavailable();
        }
        if (!$historyOnly && !$entry->ownerActive) {
            throw new BusinessRecordDefinitionUnavailable('The business-definition owner is disabled.');
        }
        $installation = $this->installations->find($entry->id);
        if (
            $installation === null
            || (!$historyOnly && $installation->status !== SchemaInstallationStatus::Active)
            || ($historyOnly && !in_array($installation->status, [
                SchemaInstallationStatus::Active,
                SchemaInstallationStatus::Installing,
                SchemaInstallationStatus::Disabled,
                SchemaInstallationStatus::Preserved,
            ], true))
            || $installation->siteIdentifier !== $context->site()->identifier()
            || $installation->ownerIdentifier !== $entry->owner->identifier
        ) {
            throw new BusinessRecordSchemaUnavailable();
        }

        return [$entry->id, $installation];
    }
}
