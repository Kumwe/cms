<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessDefinition\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Types\Types;
use JsonException;
use Kumwe\App\BusinessDefinition\Application\BusinessDefinitionRepository;
use Kumwe\App\BusinessDefinition\Application\BusinessDefinitionRevisionConflict;
use Kumwe\App\BusinessDefinition\Application\DefinitionCatalogEntry;
use Kumwe\App\BusinessDefinition\Application\DefinitionDraft;
use Kumwe\App\BusinessDefinition\Application\DefinitionVersionRecord;
use Kumwe\App\BusinessDefinition\Domain\CompatibilityChange;
use Kumwe\App\BusinessDefinition\Domain\CompatibilityClassification;
use Kumwe\App\BusinessDefinition\Domain\CompatibilityPlan;
use Kumwe\App\BusinessDefinition\Domain\DefinitionOwner;
use Kumwe\App\BusinessDefinition\Domain\DefinitionOwnerType;
use Kumwe\App\BusinessDefinition\Domain\DefinitionStatus;
use Kumwe\App\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\App\BusinessDefinition\Domain\InvalidBusinessDefinition;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\Context\Value\SiteContext;
use LogicException;
use Ramsey\Uuid\Uuid;
use RuntimeException;

/**
 * Catalog, draft and published-version store over the prefixed `business_definition*` tables.
 *
 * This is the `BusinessDefinitionRepository` the container wires in production, and it spreads one handle
 * across four tables: `business_definitions` carries the catalog head, `business_definition_drafts` at most
 * one row of work in progress, `business_definition_versions` the published bytes beside the compatibility
 * plan that produced them, and `business_definition_dependencies` the flattened rows through which schema
 * planning finds what a published version reads. Every read is scoped to a site and takes the definition's
 * UUID or its handle in the same argument, branching the SQL identity clause on `Uuid::isValid()`, so a
 * caller never has to resolve one spelling into the other first.
 *
 * Individual definition writes are optimistic, and each one refuses to start unless the caller already opened
 * a transaction. Publication additionally exposes a site-wide namespace lock on the stable site row so two
 * differently spelled handles cannot concurrently claim one normalized public component. `saveDraft()` and
 * `publish()` put the revision they were composed against into the WHERE clause and turn an affected-row count
 * other than one into `BusinessDefinitionRevisionConflict`, re-reading the head so the conflict reports the
 * revision the catalog actually holds. Identity and ownership are checked against the stored head on every
 * save, so a definition cannot be moved to another catalog site, handle or owner.
 *
 * Nothing coming out of storage is trusted. Every column is read through a typed accessor that raises
 * `RuntimeException` rather than coercing, JSON columns have to decode to the object or list shape their
 * reader expects, enum-backed columns have to name a case, and the value objects rebuilt from a row re-verify
 * their own checksums — so a hand-edited or corrupted row is refused at the read that touched it instead of
 * reaching the schema compiler as though it were canonical.
 *
 * @since  2.0.0
 */
final readonly class DoctrineBusinessDefinitionRepository implements BusinessDefinitionRepository
{
    /**
     * Bind the store to the connection its statements run on and the resolver that names its tables.
     *
     * @param  Connection  $database  DBAL connection carrying the transaction every writer here requires.
     * @param  TableNames  $tables    Resolver applying the configured prefix to every table this store reads
     *         or writes, from `business_definitions` through to `business_field_types`.
     *
     * @since  2.0.0
     */
    public function __construct(private Connection $database, private TableNames $tables)
    {
    }

    /**
     * List every catalog head the site holds, whoever owns it.
     *
     * @param   SiteContext  $site  Site whose `business_definitions` rows are read.
     *
     * @return  list<DefinitionCatalogEntry>  At most 4096 heads ordered by handle; empty when the site has
     *          no definitions at all.
     *
     * @throws  RuntimeException  When a stored head is missing a column, holds a wrongly typed value, or its
     *          owner type or publication state names no case, or the site exceeds the catalog bound.
     *
     * @since   2.0.0
     */
    public function catalog(SiteContext $site): array
    {
        $rows = $this->database->fetchAllAssociative(sprintf(
            'SELECT * FROM %s WHERE site_identifier = ? ORDER BY handle LIMIT 4097',
            $this->tables->quoted('business_definitions'),
        ), [$site->identifier()]);
        if (count($rows) > 4096) {
            throw new RuntimeException('The business-definition catalog exceeds its supported bound.');
        }

        return array_map($this->mapEntry(...), $rows);
    }

    /**
     * Lock the site's stable authority row for exclusive contract-name admission.
     *
     * @param   SiteContext  $site  Site whose normalized component namespace is being changed.
     *
     * @return  void
     *
     * @throws  LogicException  When the caller has no transaction open.
     * @throws  RuntimeException  When the site row is unavailable or malformed.
     *
     * @since   2.0.0
     */
    public function lockContractNamespace(SiteContext $site): void
    {
        $this->assertTransaction('Business-definition contract namespace locking');
        $identifier = $this->database->fetchOne(sprintf(
            'SELECT identifier FROM %s WHERE identifier = ? FOR UPDATE',
            $this->tables->quoted('sites'),
        ), [$site->identifier()], [Types::STRING]);
        if (!is_string($identifier) || $identifier !== $site->identifier()) {
            throw new RuntimeException('The business-definition contract namespace site is unavailable.');
        }
    }

    /**
     * Resolve one catalog head, touching neither the draft table nor the version table.
     *
     * @param   SiteContext  $site        Site the definition must belong to.
     * @param   string       $identifier  The definition's UUID or its handle; a canonical UUID is matched
     *          against either column, anything else against the handle alone.
     *
     * @return  ?DefinitionCatalogEntry  Where the handle stands, or null when this site holds no such
     *          definition.
     *
     * @throws  RuntimeException  When the stored head is missing a column, holds a wrongly typed value, or
     *          its owner type or publication state names no case.
     *
     * @since   2.0.0
     */
    public function entry(SiteContext $site, string $identifier): ?DefinitionCatalogEntry
    {
        $row = $this->entryRow($site, $identifier);

        return $row === null ? null : $this->mapEntry($row);
    }

    /**
     * Load the handle's work in progress by joining its draft row to the catalog head.
     *
     * The join is what confines the lookup to one site, since the draft table carries only a definition id.
     * A null answer does not distinguish "no such definition" from "published with nothing in progress",
     * because publication deletes the draft row.
     *
     * @param   SiteContext  $site        Site the definition must belong to.
     * @param   string       $identifier  The definition's UUID or its handle; a canonical UUID is matched
     *          against either column, anything else against the handle alone.
     *
     * @return  ?DefinitionDraft  The stored draft with the revision a further write must quote, or null when
     *          the site holds no such definition or its draft was consumed by a publication.
     *
     * @throws  RuntimeException  When the draft row is missing a column, holds a wrongly typed value, or its
     *          canonical payload does not decode to a JSON object.
     * @throws  InvalidBusinessDefinition  When the stored payload is not a valid definition, or the stored
     *          checksum disagrees with the bytes it sits beside.
     *
     * @since   2.0.0
     */
    public function draft(SiteContext $site, string $identifier): ?DefinitionDraft
    {
        $identity = Uuid::isValid($identifier) ? '(h.id = ? OR h.handle = ?)' : 'h.handle = ?';
        $row = $this->database->fetchAssociative(sprintf(
            'SELECT d.* FROM %s d INNER JOIN %s h ON h.id = d.definition_id '
            . 'WHERE h.site_identifier = ? AND %s',
            $this->tables->quoted('business_definition_drafts'),
            $this->tables->quoted('business_definitions'),
            $identity,
        ), Uuid::isValid($identifier)
            ? [$site->identifier(), $identifier, $identifier]
            : [$site->identifier(), $identifier]);
        if ($row === false) {
            return null;
        }
        $definition = EntityTypeDefinition::fromArray($this->jsonObject($row, 'canonical_payload'));

        return new DefinitionDraft(
            $definition,
            $this->integer($row, 'revision'),
            $this->string($row, 'checksum'),
            $this->string($row, 'updated_by'),
            $this->date($row['updated_at'] ?? null),
        );
    }

    /**
     * Load one published version, defaulting to whichever version the catalog head currently serves.
     *
     * With no version named the statement compares against `h.published_version`, so a handle that has never
     * been published matches nothing and answers null rather than falling back to its newest version.
     *
     * @param   SiteContext  $site        Site the definition must belong to.
     * @param   string       $identifier  The definition's UUID or its handle; a canonical UUID is matched
     *          against either column, anything else against the handle alone.
     * @param   ?int         $version     Version to load, or null for the one the head publishes.
     *
     * @return  ?DefinitionVersionRecord  The version paired with the plan that produced it, or null when the
     *          site holds no such definition, never published that version, or has published nothing yet.
     *
     * @throws  RuntimeException  When the version row is missing a column, holds a wrongly typed value, or a
     *          stored status, classification or plan document is malformed.
     * @throws  InvalidBusinessDefinition  When the stored payload is not a valid definition, or bytes, plan
     *          and status do not describe one consistent publication.
     *
     * @since   2.0.0
     */
    public function published(SiteContext $site, string $identifier, ?int $version = null): ?DefinitionVersionRecord
    {
        $identity = Uuid::isValid($identifier) ? '(h.id = ? OR h.handle = ?)' : 'h.handle = ?';
        $sql = sprintf(
            'SELECT v.* FROM %s v INNER JOIN %s h ON h.id = v.definition_id '
            . 'WHERE h.site_identifier = ? AND %s AND v.version = %s',
            $this->tables->quoted('business_definition_versions'),
            $this->tables->quoted('business_definitions'),
            $identity,
            $version === null ? 'h.published_version' : '?',
        );
        $parameters = Uuid::isValid($identifier)
            ? [$site->identifier(), $identifier, $identifier]
            : [$site->identifier(), $identifier];
        if ($version !== null) {
            $parameters[] = $version;
        }
        $row = $this->database->fetchAssociative($sql, $parameters);

        return $row === false ? null : $this->mapVersion($row);
    }

    /**
     * Load exact published versions through driver-safe bounded batches.
     *
     * Each statement carries at most two hundred UUID/version pairs, keeping parameter counts below the
     * supported drivers' conservative limits while replacing catalog discovery's former query-per-entry
     * behavior. Site ownership remains part of every statement through the catalog-head join.
     *
     * @param   SiteContext         $site      Site every requested definition must belong to.
     * @param   array<string, int>  $versions  Definition UUID to exact positive version.
     *
     * @return  array<string, DefinitionVersionRecord>  Valid stored versions keyed by definition UUID.
     *
     * @throws  InvalidBusinessDefinition  When the request is malformed or a stored version is inconsistent.
     * @throws  RuntimeException  When a stored row is malformed or a duplicate identity is returned.
     *
     * @since   2.0.0
     */
    public function publishedBatch(SiteContext $site, array $versions): array
    {
        if (count($versions) > 4096) {
            throw new InvalidBusinessDefinition('A published-definition batch exceeds its supported bound.');
        }
        foreach ($versions as $definitionId => $version) {
            if (!is_string($definitionId) || !Uuid::isValid($definitionId) || !is_int($version) || $version < 1) {
                throw new InvalidBusinessDefinition('A published-definition batch request is invalid.');
            }
        }
        $records = [];
        foreach (array_chunk($versions, 200, true) as $chunk) {
            $clauses = [];
            $parameters = [$site->identifier()];
            $types = [Types::STRING];
            foreach ($chunk as $definitionId => $version) {
                $clauses[] = '(v.definition_id = ? AND v.version = ?)';
                $parameters[] = $definitionId;
                $parameters[] = $version;
                $types[] = Types::GUID;
                $types[] = Types::INTEGER;
            }
            $rows = $this->database->fetchAllAssociative(sprintf(
                'SELECT v.* FROM %s v INNER JOIN %s h ON h.id = v.definition_id '
                . 'WHERE h.site_identifier = ? AND (%s)',
                $this->tables->quoted('business_definition_versions'),
                $this->tables->quoted('business_definitions'),
                implode(' OR ', $clauses),
            ), $parameters, $types);
            foreach ($rows as $row) {
                $record = $this->mapVersion($row);
                $definitionId = $record->definition->id;
                if (isset($records[$definitionId])) {
                    throw new RuntimeException('A published-definition batch returned a duplicate identity.');
                }
                $records[$definitionId] = $record;
            }
        }

        return $records;
    }

    /**
     * List every version of one definition that was ever published, newest first.
     *
     * @param   SiteContext  $site        Site the definition must belong to.
     * @param   string       $identifier  The definition's UUID or its handle; a canonical UUID is matched
     *          against either column, anything else against the handle alone.
     *
     * @return  list<DefinitionVersionRecord>  Ordered by version descending; empty when the site holds no
     *          such definition or it has never been published.
     *
     * @throws  RuntimeException  When a version row is missing a column, holds a wrongly typed value, or a
     *          stored status, classification or plan document is malformed.
     * @throws  InvalidBusinessDefinition  When a stored payload is not a valid definition, or bytes, plan and
     *          status do not describe one consistent publication.
     *
     * @since   2.0.0
     */
    public function history(SiteContext $site, string $identifier): array
    {
        $identity = Uuid::isValid($identifier) ? '(h.id = ? OR h.handle = ?)' : 'h.handle = ?';
        $rows = $this->database->fetchAllAssociative(sprintf(
            'SELECT v.* FROM %s v INNER JOIN %s h ON h.id = v.definition_id '
            . 'WHERE h.site_identifier = ? AND %s ORDER BY v.version DESC',
            $this->tables->quoted('business_definition_versions'),
            $this->tables->quoted('business_definitions'),
            $identity,
        ), Uuid::isValid($identifier)
            ? [$site->identifier(), $identifier, $identifier]
            : [$site->identifier(), $identifier]);

        return array_map($this->mapVersion(...), $rows);
    }

    /**
     * Write the draft for a handle, creating its catalog head when this is the first save.
     *
     * The head is located by the definition's own site and handle, so the expected revision is the caller's
     * proof it composed the change against what is stored: with no head yet only null or zero is accepted and
     * the head is created at revision one; with a head present the value has to equal the stored draft
     * revision exactly, which means null is refused once the handle exists. The bump runs as an UPDATE
     * filtered on that revision and the draft row is then upserted — updated in place, inserted only when the
     * update matched nothing — so the head revision and the draft row always advance together.
     *
     * @param   EntityTypeDefinition  $definition        Draft to store, carrying its own id, site, handle and
     *          owner.
     * @param   string                $actorId           Actor recorded as having last saved the draft.
     * @param   DateTimeImmutable     $now               Instant recorded on the head and the draft row.
     * @param   ?int                  $expectedRevision  Draft revision the change was composed against, or
     *          null when the caller expects to be creating the definition.
     *
     * @return  DefinitionDraft  The stored draft at its new revision, which the next write must quote.
     *
     * @throws  LogicException  When the caller has no transaction open.
     * @throws  BusinessDefinitionRevisionConflict  When the stored head is not at the expected revision, or
     *          another writer created or advanced the same handle or definition identity first.
     * @throws  InvalidBusinessDefinition  When the stored head names a different definition id, catalog site,
     *          handle or owner, or the definition cannot be canonically encoded to a checksum.
     * @throws  RuntimeException  When the stored head is missing a column or holds a wrongly typed value.
     *
     * @since   2.0.0
     */
    public function saveDraft(
        EntityTypeDefinition $definition,
        string $actorId,
        DateTimeImmutable $now,
        ?int $expectedRevision,
    ): DefinitionDraft {
        $this->assertTransaction('Business-definition draft persistence');
        $row = $this->entryRow(SiteContext::fromString($definition->siteIdentifier), $definition->handle);
        if ($row === null) {
            if ($expectedRevision !== null && $expectedRevision !== 0) {
                throw new BusinessDefinitionRevisionConflict($expectedRevision, 0);
            }
            $this->assertIdentityUnclaimed($definition);
            try {
                $this->database->insert($this->tables->raw('business_definitions'), [
                    'id' => $definition->id,
                    'site_identifier' => $definition->siteIdentifier,
                    'handle' => $definition->handle,
                    'owner_type' => $definition->owner->type->value,
                    'owner_identifier' => $definition->owner->identifier,
                    'owner_active' => true,
                    'draft_revision' => 1,
                    'published_version' => null,
                    'publication_state' => DefinitionStatus::Draft->value,
                    'created_by' => $actorId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], [
                    'owner_active' => Types::BOOLEAN,
                    'draft_revision' => Types::INTEGER,
                    'published_version' => Types::INTEGER,
                    'created_at' => Types::DATETIME_IMMUTABLE,
                    'updated_at' => Types::DATETIME_IMMUTABLE,
                ]);
            } catch (UniqueConstraintViolationException $exception) {
                throw new BusinessDefinitionRevisionConflict($expectedRevision ?? 0, 1, $exception);
            }
            $revision = 1;
        } else {
            $this->assertSameCatalogIdentity($definition, $row);
            $actual = $this->integer($row, 'draft_revision');
            if ($expectedRevision === null || $expectedRevision !== $actual) {
                throw new BusinessDefinitionRevisionConflict($expectedRevision ?? 0, $actual);
            }
            $revision = $actual + 1;
            $affected = $this->database->executeStatement(sprintf(
                'UPDATE %s SET draft_revision = ?, publication_state = ?, updated_at = ? '
                . 'WHERE id = ? AND draft_revision = ?',
                $this->tables->quoted('business_definitions'),
            ), [
                $revision,
                DefinitionStatus::Draft->value,
                $now,
                $this->string($row, 'id'),
                $actual,
            ], [Types::INTEGER, Types::STRING, Types::DATETIME_IMMUTABLE, Types::GUID, Types::INTEGER]);
            if ($affected !== 1) {
                throw new BusinessDefinitionRevisionConflict($actual, $this->headDraftRevision($definition->id));
            }
        }
        $payload = $definition->toArray();
        $values = [
            'revision' => $revision,
            'checksum' => $definition->checksum(),
            'canonical_payload' => $payload,
            'dependency_graph' => $definition->dependencyGraph(),
            'updated_by' => $actorId,
            'updated_at' => $now,
        ];
        $types = [
            'revision' => Types::INTEGER,
            'canonical_payload' => Types::JSON,
            'dependency_graph' => Types::JSON,
            'updated_at' => Types::DATETIME_IMMUTABLE,
        ];
        $affected = $this->database->update(
            $this->tables->raw('business_definition_drafts'),
            $values,
            ['definition_id' => $definition->id],
            $types,
        );
        if ($affected === 0) {
            $this->database->insert($this->tables->raw('business_definition_drafts'), [
                'definition_id' => $definition->id,
                ...$values,
            ], ['definition_id' => Types::GUID, ...$types]);
        }

        return new DefinitionDraft($definition, $revision, $definition->checksum(), $actorId, $now);
    }

    /**
     * Promote the stored draft to a published version and retire the version it replaces.
     *
     * The plan is checked against the bytes before anything is written: its target checksum has to match the
     * definition's own and its target version has to be the definition's version, so a plan analysed against
     * different bytes can never be stored beside them. The head then moves to `draft_revision = 0` under a
     * revision-and-identity-guarded UPDATE, any still-published predecessor is marked superseded without its
     * bytes being touched, the new version row is inserted with its plan, its dependency rows are rewritten,
     * and the draft row is deleted — leaving the handle published with no work in progress. Re-reading and
     * guarding the frozen catalog coordinate here matters even though the normal service publishes the stored
     * draft: the repository is a public seam and must reject caller-supplied bytes that move the same UUID.
     *
     * @param   EntityTypeDefinition  $definition             Definition already advanced to the version the
     *          plan targets, whose checksum the plan names.
     * @param   CompatibilityPlan     $plan                   Plan analysed for exactly these bytes; stored
     *          verbatim beside the version.
     * @param   string                $actorId                Actor recorded as the publisher.
     * @param   DateTimeImmutable     $now                    Instant recorded as the publication time.
     * @param   int                   $expectedDraftRevision  Draft revision being published, as the caller
     *          last read it.
     *
     * @return  DefinitionVersionRecord  The stored version, published and paired with its plan.
     *
     * @throws  LogicException  When the caller has no transaction open.
     * @throws  InvalidBusinessDefinition  When the definition's checksum or version does not match the plan,
     *          the definition cannot be canonically encoded, it does not itself carry published status, or
     *          its catalog site, handle or owner differs from the stored head.
     * @throws  BusinessDefinitionRevisionConflict  When the head is no longer at the expected draft revision,
     *          so another writer changed it after the plan was analysed.
     *
     * @since   2.0.0
     */
    public function publish(
        EntityTypeDefinition $definition,
        CompatibilityPlan $plan,
        string $actorId,
        DateTimeImmutable $now,
        int $expectedDraftRevision,
    ): DefinitionVersionRecord {
        $this->assertTransaction('Business-definition publication');
        if (
            !hash_equals($definition->checksum(), $plan->toChecksum)
            || $definition->definitionVersion !== $plan->toVersion
        ) {
            throw new InvalidBusinessDefinition('A publication does not match its compatibility plan.');
        }
        $head = $this->identityRow($definition->id);
        if ($head === null) {
            throw new BusinessDefinitionRevisionConflict($expectedDraftRevision, 0);
        }
        $this->assertSameCatalogIdentity($definition, $head);
        $affected = $this->database->executeStatement(sprintf(
            'UPDATE %s SET draft_revision = 0, published_version = ?, publication_state = ?, updated_at = ? '
            . 'WHERE id = ? AND site_identifier = ? AND handle = ? AND owner_type = ? AND owner_identifier = ? '
            . 'AND draft_revision = ?',
            $this->tables->quoted('business_definitions'),
        ), [
            $definition->definitionVersion,
            DefinitionStatus::Published->value,
            $now,
            $definition->id,
            $definition->siteIdentifier,
            $definition->handle,
            $definition->owner->type->value,
            $definition->owner->identifier,
            $expectedDraftRevision,
        ], [
            Types::INTEGER,
            Types::STRING,
            Types::DATETIME_IMMUTABLE,
            Types::GUID,
            Types::STRING,
            Types::STRING,
            Types::STRING,
            Types::STRING,
            Types::INTEGER,
        ]);
        if ($affected !== 1) {
            throw new BusinessDefinitionRevisionConflict(
                $expectedDraftRevision,
                $this->headDraftRevision($definition->id),
            );
        }
        $previous = $definition->definitionVersion - 1;
        if ($previous > 0) {
            $this->database->update($this->tables->raw('business_definition_versions'), [
                'status' => DefinitionStatus::Superseded->value,
            ], [
                'definition_id' => $definition->id,
                'version' => $previous,
                'status' => DefinitionStatus::Published->value,
            ]);
        }
        $this->database->insert($this->tables->raw('business_definition_versions'), [
            'definition_id' => $definition->id,
            'version' => $definition->definitionVersion,
            'status' => $definition->status->value,
            'checksum' => $definition->checksum(),
            'canonical_payload' => $definition->toArray(),
            'dependency_graph' => $definition->dependencyGraph(),
            'compatibility_plan' => $plan->toArray(),
            'published_by' => $actorId,
            'published_at' => $now,
        ], [
            'version' => Types::INTEGER,
            'canonical_payload' => Types::JSON,
            'dependency_graph' => Types::JSON,
            'compatibility_plan' => Types::JSON,
            'published_at' => Types::DATETIME_IMMUTABLE,
        ]);
        $this->replaceDependencies($definition);
        $this->database->delete($this->tables->raw('business_definition_drafts'), [
            'definition_id' => $definition->id,
        ]);

        return new DefinitionVersionRecord($definition, $plan, DefinitionStatus::Published, $actorId, $now);
    }

    /**
     * Move one published version to a later lifecycle state, leaving its bytes untouched.
     *
     * Only a retirement is accepted here — superseded, deprecated or rejected — because publishing is what
     * moves a version into service and this path never rewrites canonical payloads. The catalog head follows
     * the version only when the version being moved is the one the head serves.
     *
     * @param   SiteContext        $site        Site the definition must belong to.
     * @param   string             $identifier  The definition's UUID or its handle.
     * @param   int                $version     Published version whose lifecycle state is changing.
     * @param   DefinitionStatus   $status      State to move it to; only `Superseded`, `Deprecated` and
     *          `Rejected` are accepted.
     * @param   DateTimeImmutable  $now         Instant recorded against the catalog head, when it follows.
     *
     * @return  DefinitionVersionRecord  The version re-read from storage in its new state.
     *
     * @throws  LogicException  When the caller has no transaction open.
     * @throws  InvalidBusinessDefinition  When the target state is not a retirement, the site holds no such
     *          definition, the update matched no version row, or the version re-read afterwards is not a
     *          consistent publication.
     * @throws  RuntimeException  When the changed version cannot be read back, or a stored row is missing a
     *          column or holds a wrongly typed value.
     *
     * @since   2.0.0
     */
    public function changeStatus(
        SiteContext $site,
        string $identifier,
        int $version,
        DefinitionStatus $status,
        DateTimeImmutable $now,
    ): DefinitionVersionRecord {
        $this->assertTransaction('Business-definition status mutation');
        if (
            !in_array(
                $status,
                [DefinitionStatus::Superseded, DefinitionStatus::Deprecated, DefinitionStatus::Rejected],
                true,
            )
        ) {
            throw new InvalidBusinessDefinition('The requested definition status transition is unsupported.');
        }
        $entry = $this->entryRow($site, $identifier)
            ?? throw new InvalidBusinessDefinition('The business definition does not exist.');
        $affected = $this->database->update($this->tables->raw('business_definition_versions'), [
            'status' => $status->value,
        ], ['definition_id' => $this->string($entry, 'id'), 'version' => $version]);
        if ($affected !== 1) {
            throw new InvalidBusinessDefinition('The requested business definition version does not exist.');
        }
        if ($this->integerOrNull($entry, 'published_version') === $version) {
            $this->database->update($this->tables->raw('business_definitions'), [
                'publication_state' => $status->value,
                'updated_at' => $now,
            ], ['id' => $this->string($entry, 'id')], ['updated_at' => Types::DATETIME_IMMUTABLE]);
        }

        return $this->published($site, $identifier, $version)
            ?? throw new RuntimeException('The changed business definition could not be reloaded.');
    }

    /**
     * Flip the availability of everything one extension owns, without republishing any of it.
     *
     * Two tables move together because a definition cannot be read without the field types its owner
     * contributed: the extension's catalog heads and its rows in `business_field_types`. Versions keep their
     * bytes, their numbering and their history, so this is a switch rather than a publication. The update is
     * keyed on owner alone and therefore crosses every site, matching the fact that an extension is installed
     * once per installation.
     *
     * @param   string             $ownerIdentifier  Owning extension, as `vendor/name`.
     * @param   bool               $active           Whether its definitions and field types become available
     *          again.
     * @param   DateTimeImmutable  $now              Instant recorded against every affected row.
     *
     * @return  void
     *
     * @throws  LogicException  When the caller has no transaction open.
     *
     * @since   2.0.0
     */
    public function setOwnerActive(string $ownerIdentifier, bool $active, DateTimeImmutable $now): void
    {
        $this->assertTransaction('Business-definition owner lifecycle synchronization');
        $this->database->update($this->tables->raw('business_definitions'), [
            'owner_active' => $active,
            'updated_at' => $now,
        ], ['owner_type' => DefinitionOwnerType::Extension->value, 'owner_identifier' => $ownerIdentifier], [
            'owner_active' => Types::BOOLEAN,
            'updated_at' => Types::DATETIME_IMMUTABLE,
        ]);
        $this->database->update($this->tables->raw('business_field_types'), [
            'active' => $active,
            'updated_at' => $now,
        ], ['owner_type' => DefinitionOwnerType::Extension->value, 'owner_identifier' => $ownerIdentifier], [
            'active' => Types::BOOLEAN,
            'updated_at' => Types::DATETIME_IMMUTABLE,
        ]);
    }

    /**
     * Rebuild a catalog head from one `business_definitions` row.
     *
     * @param   array<string, mixed>  $row  Head row as fetched, keyed by unqualified column name.
     *
     * @return  DefinitionCatalogEntry  Where the handle stands, with owner and publication state resolved
     *          back to their enum cases.
     *
     * @throws  RuntimeException  When a column is absent or wrongly typed, or the stored owner type or
     *          publication state names no case.
     *
     * @since   2.0.0
     */
    private function mapEntry(array $row): DefinitionCatalogEntry
    {
        $ownerType = DefinitionOwnerType::tryFrom($this->string($row, 'owner_type'))
            ?? throw new RuntimeException('Stored business-definition owner type is invalid.');
        $status = DefinitionStatus::tryFrom($this->string($row, 'publication_state'))
            ?? throw new RuntimeException('Stored business-definition publication state is invalid.');

        return new DefinitionCatalogEntry(
            $this->string($row, 'id'),
            $this->string($row, 'site_identifier'),
            $this->string($row, 'handle'),
            new DefinitionOwner($ownerType, $this->string($row, 'owner_identifier')),
            $this->boolean($row, 'owner_active'),
            $this->integer($row, 'draft_revision'),
            $this->integerOrNull($row, 'published_version'),
            $status,
            $this->date($row['updated_at'] ?? null),
        );
    }

    /**
     * Rebuild a published version and the compatibility plan stored beside it from one version row.
     *
     * The plan is reassembled from its own stored document rather than recomputed, so a historical version
     * keeps the account of what publishing it cost even after the definitions it was compared against have
     * moved on. Each change document has to be a JSON object naming a real classification; a list or a
     * scalar in that position is treated as corruption rather than skipped.
     *
     * @param   array<string, mixed>  $row  Version row as fetched, keyed by unqualified column name.
     *
     * @return  DefinitionVersionRecord  The published bytes paired with the plan that produced them.
     *
     * @throws  RuntimeException  When a column is absent or wrongly typed, a JSON column does not hold the
     *          object or list it should, or a stored classification or version status names no case.
     * @throws  InvalidBusinessDefinition  When the stored payload is not a valid definition, a change's
     *          pointer or message is invalid, the plan's bounds or checksums are invalid, or bytes, plan and
     *          status do not describe one consistent publication.
     *
     * @since   2.0.0
     */
    private function mapVersion(array $row): DefinitionVersionRecord
    {
        $definition = EntityTypeDefinition::fromArray($this->jsonObject($row, 'canonical_payload'));
        $plan = $this->jsonObject($row, 'compatibility_plan');
        $changes = [];
        foreach ($this->arrayList($plan, 'changes') as $change) {
            if (!is_array($change) || array_is_list($change)) {
                throw new RuntimeException('Stored business compatibility change is invalid.');
            }
            /** @var non-empty-array<string, mixed> $change */
            $classification = CompatibilityClassification::tryFrom($this->string($change, 'classification'))
                ?? throw new RuntimeException('Stored compatibility classification is invalid.');
            $changes[] = new CompatibilityChange(
                $this->string($change, 'path'),
                $classification,
                $this->string($change, 'message'),
            );
        }
        $compatibility = new CompatibilityPlan(
            $this->integerOrNull($plan, 'from_version'),
            $this->integer($plan, 'to_version'),
            $this->nullableString($plan, 'from_checksum'),
            $this->string($plan, 'to_checksum'),
            $changes,
        );

        return new DefinitionVersionRecord(
            $definition,
            $compatibility,
            DefinitionStatus::tryFrom($this->string($row, 'status'))
                ?? throw new RuntimeException('Stored definition-version status is invalid.'),
            $this->string($row, 'published_by'),
            $this->date($row['published_at'] ?? null),
        );
    }

    /**
     * Rewrite the dependency rows for one published version from the definition's own dependency graph.
     *
     * The rows exist so schema planning can ask what a version reads without decoding its payload. They are
     * deleted and re-inserted for this exact `(definition_id, version)` pair rather than merged, which keeps
     * a republication of the same version from leaving stale edges behind. Field dependencies are flattened
     * one row per edge, spelled `source>target`.
     *
     * @param   EntityTypeDefinition  $definition  Version whose dependency rows are being replaced.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function replaceDependencies(EntityTypeDefinition $definition): void
    {
        $this->database->delete($this->tables->raw('business_definition_dependencies'), [
            'definition_id' => $definition->id,
            'version' => $definition->definitionVersion,
        ]);
        $graph = $definition->dependencyGraph();
        foreach ($graph['fields'] as $source => $targets) {
            foreach ($targets as $target) {
                $this->insertDependency($definition, 'field', $source . '>' . $target);
            }
        }
        foreach (['entity' => 'entities', 'field_type' => 'field_types'] as $kind => $collection) {
            foreach ($graph[$collection] as $handle) {
                $this->insertDependency($definition, $kind, $handle);
            }
        }
    }

    /**
     * Insert one dependency edge for a published version, attributed to the definition's owner.
     *
     * @param   EntityTypeDefinition  $definition  Version the edge belongs to, supplying id, version number
     *          and owner.
     * @param   string                $kind        Edge category stored in `dependency_kind`: `field`,
     *          `entity` or `field_type`.
     * @param   string                $handle      What the edge points at: a dependent entity or field-type
     *          handle, or `source>target` for a field-level edge.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function insertDependency(EntityTypeDefinition $definition, string $kind, string $handle): void
    {
        $this->database->insert($this->tables->raw('business_definition_dependencies'), [
            'definition_id' => $definition->id,
            'version' => $definition->definitionVersion,
            'dependency_kind' => $kind,
            'dependency_handle' => $handle,
            'owner_identifier' => $definition->owner->identifier,
        ], ['version' => Types::INTEGER]);
    }

    /**
     * Fetch the raw catalog head row for a site-scoped UUID or handle.
     *
     * The operations that need the head itself — `entry()`, `saveDraft()` and `changeStatus()` — all come
     * through here, so the identity branch is written once: a canonical UUID is matched against `id` or
     * `handle`, anything else against `handle` alone.
     *
     * @param   SiteContext  $site        Site the row must belong to.
     * @param   string       $identifier  The definition's UUID or its handle.
     *
     * @return  array<string, mixed>|null  The head row exactly as the driver returned it, or null when
     *          nothing matched.
     *
     * @since   2.0.0
     */
    private function entryRow(SiteContext $site, string $identifier): ?array
    {
        $identity = Uuid::isValid($identifier) ? '(id = ? OR handle = ?)' : 'handle = ?';
        $row = $this->database->fetchAssociative(sprintf(
            'SELECT * FROM %s WHERE site_identifier = ? AND %s',
            $this->tables->quoted('business_definitions'),
            $identity,
        ), Uuid::isValid($identifier)
            ? [$site->identifier(), $identifier, $identifier]
            : [$site->identifier(), $identifier]);

        return $row === false ? null : $row;
    }

    /**
     * Fetch the raw catalog head for one globally unique definition identity.
     *
     * Publication receives a complete definition rather than a site context, so it resolves the authoritative
     * head by UUID and then compares every frozen catalog coordinate before it writes. This lookup is kept
     * distinct from `entryRow()`, whose site-and-handle semantics are the public catalog lookup contract.
     *
     * @param   string  $id  Canonical definition UUID.
     *
     * @return  array<string, mixed>|null  The head row exactly as returned by the driver, or null when absent.
     *
     * @since   2.0.0
     */
    private function identityRow(string $id): ?array
    {
        $row = $this->database->fetchAssociative(sprintf(
            'SELECT * FROM %s WHERE id = ?',
            $this->tables->quoted('business_definitions'),
        ), [$id], [Types::GUID]);

        return $row === false ? null : $row;
    }

    /**
     * Refuse a save that would change an existing catalog coordinate or owner.
     *
     * A handle is looked up by site and name, so without this check a second definition claiming the same
     * handle could silently take over the stored head. Identity, catalog site, handle and ownership are
     * therefore settled when the entry is first created and no later save can move them.
     *
     * @param   EntityTypeDefinition  $definition  Definition being saved or published.
     * @param   array<string, mixed>  $row         Authoritative stored catalog head.
     *
     * @return  void
     *
     * @throws  InvalidBusinessDefinition  When the stored id, site, handle, owner type or owner identifier
     *          differs from the definition being saved.
     * @throws  RuntimeException  When one of those columns is absent or is not a non-empty string.
     *
     * @since   2.0.0
     */
    private function assertSameCatalogIdentity(EntityTypeDefinition $definition, array $row): void
    {
        if (
            $this->string($row, 'id') !== $definition->id
            || $this->string($row, 'site_identifier') !== $definition->siteIdentifier
            || $this->string($row, 'handle') !== $definition->handle
            || $this->string($row, 'owner_type') !== $definition->owner->type->value
            || $this->string($row, 'owner_identifier') !== $definition->owner->identifier
        ) {
            throw new InvalidBusinessDefinition(
                'A business-definition identity, catalog site, handle or owner cannot be changed.',
            );
        }
    }

    /**
     * Refuse to introduce a globally claimed identity under a different site or handle.
     *
     * The ordinary lookup is deliberately site-and-handle scoped. A caller presenting an existing UUID
     * under a different coordinate would otherwise reach the insert and be reported as a generic optimistic
     * conflict by the primary key. Naming the invariant here keeps the catalog site — and therefore the
     * owning-site coordinate used by non-site-scoped number sequences — immovable by contract. This read is
     * diagnostic rather than a substitute for the UUID primary key: two concurrent first saves can both see
     * no claim, and the database-authoritative loser is intentionally reported as an optimistic conflict.
     *
     * @param   EntityTypeDefinition  $definition  New catalog entry being proposed.
     *
     * @return  void
     *
     * @throws  InvalidBusinessDefinition  When the definition UUID already has a catalog head.
     *
     * @since   2.0.0
     */
    private function assertIdentityUnclaimed(EntityTypeDefinition $definition): void
    {
        $claimed = $this->database->fetchOne(sprintf(
            'SELECT id FROM %s WHERE id = ?',
            $this->tables->quoted('business_definitions'),
        ), [$definition->id], [Types::GUID]);
        if ($claimed !== false) {
            throw new InvalidBusinessDefinition(
                'A business-definition identity, catalog site, handle or owner cannot be changed.',
            );
        }
    }

    /**
     * Re-read a head's draft revision so a refused write can report what the catalog now holds.
     *
     * This runs on the failure path only, after a revision-guarded UPDATE matched no row, and it deliberately
     * degrades instead of throwing: reporting a conflict must not itself fail.
     *
     * @param   string  $id  Definition UUID of the head to re-read.
     *
     * @return  int  The stored draft revision, or zero when the row is gone or the driver returned something
     *          that is not a number.
     *
     * @since   2.0.0
     */
    private function headDraftRevision(string $id): int
    {
        $value = $this->database->fetchOne(sprintf(
            'SELECT draft_revision FROM %s WHERE id = ?',
            $this->tables->quoted('business_definitions'),
        ), [$id]);

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Refuse to start a multi-statement write outside a transaction the caller already opened.
     *
     * Each write here touches several tables, so a partial failure has to roll back as one unit. The store
     * never opens the transaction itself, because the caller usually spans this write and an audit entry.
     *
     * @param   string  $operation  Name of the operation, used to open the failure message.
     *
     * @return  void
     *
     * @throws  LogicException  When no transaction is active on the connection.
     *
     * @since   2.0.0
     */
    private function assertTransaction(string $operation): void
    {
        if (!$this->database->isTransactionActive()) {
            throw new LogicException($operation . ' requires an active transaction.');
        }
    }

    /**
     * Read a column that must hold a non-empty string.
     *
     * @param   array<string, mixed>  $row  Row as fetched, keyed by unqualified column name.
     * @param   string                $key  Column to read.
     *
     * @return  string  The stored value, never the empty string.
     *
     * @throws  RuntimeException  When the column is absent, not a string, or empty.
     *
     * @since   2.0.0
     */
    private function string(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new RuntimeException('Stored business-definition property ' . $key . ' is invalid.');
        }
        return $value;
    }

    /**
     * Read a column that may be absent but must otherwise hold a string.
     *
     * Unlike `string()` this accepts the empty string, because a nullable text column carries the absent
     * case in null rather than in emptiness.
     *
     * @param   array<string, mixed>  $row  Row as fetched, keyed by unqualified column name.
     * @param   string                $key  Column to read.
     *
     * @return  ?string  The stored value, or null when the column is absent or SQL NULL.
     *
     * @throws  RuntimeException  When the column holds something that is neither null nor a string.
     *
     * @since   2.0.0
     */
    private function nullableString(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;
        if ($value !== null && !is_string($value)) {
            throw new RuntimeException('Stored business-definition property ' . $key . ' is invalid.');
        }
        return $value;
    }

    /**
     * Read a column that must hold a whole number.
     *
     * Digit strings are accepted because several drivers hydrate integer columns as text, but nothing else
     * is coerced: a float, a boolean or a partly numeric string is corruption, not a value to round.
     *
     * @param   array<string, mixed>  $row  Row as fetched, keyed by unqualified column name.
     * @param   string                $key  Column to read.
     *
     * @return  int  The stored number, converted from the driver's text form when needed.
     *
     * @throws  RuntimeException  When the column is absent, or holds neither an integer nor an optionally
     *          signed run of digits.
     *
     * @since   2.0.0
     */
    private function integer(array $row, string $key): int
    {
        $value = $row[$key] ?? null;
        if (!is_int($value) && (!is_string($value) || preg_match('/^-?[0-9]+$/D', $value) !== 1)) {
            throw new RuntimeException('Stored business-definition property ' . $key . ' is invalid.');
        }
        return (int) $value;
    }

    /**
     * Read a nullable whole-number column, such as a head's published version.
     *
     * @param   array<string, mixed>  $row  Row as fetched, keyed by unqualified column name.
     * @param   string                $key  Column to read.
     *
     * @return  ?int  The stored number, or null when the column is absent or SQL NULL.
     *
     * @throws  RuntimeException  When a present value is neither an integer nor a run of digits.
     *
     * @since   2.0.0
     */
    private function integerOrNull(array $row, string $key): ?int
    {
        return ($row[$key] ?? null) === null ? null : $this->integer($row, $key);
    }

    /**
     * Read a flag column across drivers that spell booleans differently.
     *
     * A native boolean is taken as is, and the integer or string forms of zero and one are accepted because
     * that is how the supported platforms hydrate a boolean column. Nothing else is treated as truthy.
     *
     * @param   array<string, mixed>  $row  Row as fetched, keyed by unqualified column name.
     * @param   string                $key  Column to read.
     *
     * @return  bool  The stored flag.
     *
     * @throws  RuntimeException  When the column is absent or holds anything other than a boolean, 0 or 1.
     *
     * @since   2.0.0
     */
    private function boolean(array $row, string $key): bool
    {
        $value = $row[$key] ?? null;
        if (is_bool($value)) {
            return $value;
        }
        if (in_array($value, [0, 1, '0', '1'], true)) {
            return (bool) $value;
        }
        throw new RuntimeException('Stored business-definition property ' . $key . ' is invalid.');
    }

    /**
     * Read a JSON column that must hold an object, decoding it when the driver returned raw text.
     *
     * Drivers differ over whether a JSON column arrives decoded, so both forms are accepted. A JSON list is
     * refused rather than accepted as an array, because the callers key into what comes back; the empty
     * array is the one ambiguous value allowed through, since `[]` encodes both an empty object and an empty
     * list. Decoding is bounded at 64 levels and a decode failure is converted here rather than propagated.
     *
     * @param   array<string, mixed>  $row  Row as fetched, keyed by unqualified column name.
     * @param   string                $key  JSON column to read.
     *
     * @return  array<string, mixed>  The decoded document, keyed by its own property names.
     *
     * @throws  RuntimeException  When the stored text is not valid JSON, or the value is not an object.
     *
     * @since   2.0.0
     */
    private function jsonObject(array $row, string $key): array
    {
        $value = $row[$key] ?? null;
        if (is_string($value)) {
            try {
                $value = json_decode($value, true, 64, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new RuntimeException('Stored business-definition JSON is invalid.', 0, $exception);
            }
        }
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new RuntimeException('Stored business-definition property ' . $key . ' must be an object.');
        }
        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * Read a member of an already-decoded JSON document that must hold a list.
     *
     * Unlike `jsonObject()` this never decodes: it reads inside a document that has already been decoded,
     * such as the `changes` member of a stored compatibility plan.
     *
     * @param   array<string, mixed>  $row  Decoded document to read the member from.
     * @param   string                $key  Member expected to hold a list.
     *
     * @return  list<mixed>  The stored list, with its elements still unvalidated.
     *
     * @throws  RuntimeException  When the member is absent, is not an array, or is keyed rather than a list.
     *
     * @since   2.0.0
     */
    private function arrayList(array $row, string $key): array
    {
        $value = $row[$key] ?? null;
        if (!is_array($value) || !array_is_list($value)) {
            throw new RuntimeException('Stored business-definition property ' . $key . ' must be a list.');
        }
        return $value;
    }

    /**
     * Normalise whatever the driver returned for a timestamp column into an immutable date.
     *
     * Some platforms hydrate a date object and others hand back the raw string, so both are accepted rather
     * than pinning the mapper to one driver. A bare string is read as UTC, which is the zone every
     * definition timestamp is written in.
     *
     * @param   mixed  $value  Raw timestamp column value from a catalog, draft or version row.
     *
     * @return  DateTimeImmutable  The instant, converted when the driver returned another date type.
     *
     * @throws  RuntimeException  When the value is neither a date object nor a string.
     * @throws  \DateMalformedStringException  When the string cannot be read as a date.
     *
     * @since   2.0.0
     */
    private function date(mixed $value): DateTimeImmutable
    {
        if ($value instanceof DateTimeImmutable) {
            return $value;
        }
        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value);
        }
        if (is_string($value)) {
            return new DateTimeImmutable($value, new DateTimeZone('UTC'));
        }
        throw new RuntimeException('Stored business-definition timestamp is invalid.');
    }
}
