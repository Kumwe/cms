<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessRecord\Application;

use Kumwe\Context\Value\ExecutionContext;

/**
 * Port that pairs a published business definition with the physical schema installed for it.
 *
 * Every record operation needs two facts that live in separate tables: the immutable published version
 * that describes the shape, and the installation row that says which version of that shape actually
 * exists on disk. This port resolves both together and refuses to return a pair whose version or
 * checksum disagree, so no caller reads or writes a table through a definition the installer never
 * applied. The four entry points differ only in how strict they are about the installation's lifecycle
 * status and about which version may be pinned, which is what separates a live write from a read of
 * preserved history. Resolution is a point-in-time answer and not a concurrency guarantee — callers
 * hold a `BusinessRecordMutationFence` generation across the resolve and assert the pair against it.
 *
 * @since  2.0.0
 */
interface BusinessRecordDefinitionResolver
{
    /**
     * Resolve every definition on the caller's site whose schema is installed and active.
     *
     * Callers scan this set when an operation has to reason about definitions it was not handed by
     * name — deleting a record, for instance, has to find every other definition that may hold an
     * inbound reference to it.
     *
     * @param   ExecutionContext  $context  Actor and site whose definition catalog is scanned.
     *
     * @return  list<ResolvedBusinessDefinition>  One pair per active installation owned by an active
     *          owner; empty when the site has none.
     *
     * @throws  \Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordSchemaUnavailable  When an
     *          active installation disagrees with the catalog version whose checksum it records.
     *
     * @since   2.0.0
     */
    public function activeInstalled(ExecutionContext $context): array;

    /**
     * Resolve the version a new or updated record must be written against.
     *
     * This is the strictest entry point: it accepts only an active installation under an active owner,
     * pinned to the installed version, so a caller cannot write rows shaped by a definition the tables
     * do not match.
     *
     * @param   ExecutionContext  $context     Actor and site the operation runs as.
     * @param   string            $identifier  Definition UUID or handle naming the record type.
     *
     * @return  ResolvedBusinessDefinition  The installed version paired with its installation row.
     *
     * @throws  \Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordDefinitionUnavailable  When
     *          no definition matches, its owner is disabled, or the installed version is unpublished or
     *          rejected.
     * @throws  \Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordSchemaUnavailable  When the
     *          schema is not installed and active, or its checksum disagrees with the published version.
     *
     * @since   2.0.0
     */
    public function forCreate(ExecutionContext $context, string $identifier): ResolvedBusinessDefinition;

    /**
     * Resolve an older published version of a live definition, as pinned by a stored row.
     *
     * Rows carry the definition version they were written under, so reading one back requires the
     * shape it was written with rather than the newest installed shape. The installation must still be
     * active, and the pinned version may not run ahead of it.
     *
     * @param   ExecutionContext  $context            Actor and site the operation runs as.
     * @param   string            $identifier         Definition UUID or handle naming the record type.
     * @param   int               $definitionVersion  Published version the stored row was written under.
     *
     * @return  ResolvedBusinessDefinition  The pinned version paired with the live installation row.
     *
     * @throws  \Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordDefinitionUnavailable  When
     *          no definition matches, its owner is disabled, or that version is not published.
     * @throws  \Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordSchemaUnavailable  When the
     *          schema is not installed and active, or the pinned version is newer than the installed one.
     *
     * @since   2.0.0
     */
    public function pinned(
        ExecutionContext $context,
        string $identifier,
        int $definitionVersion,
    ): ResolvedBusinessDefinition;

    /**
     * Resolve preserved metadata for authorized history without enabling executable record behavior.
     *
     * History has to stay readable after a record type is withdrawn, so this entry point also accepts
     * an installing, disabled, or preserved installation and tolerates a deactivated owner. Callers
     * must treat the result as descriptive only: it may name a schema that is not accepting traffic.
     * Omitting the version resolves the installed one.
     *
     * @param   ExecutionContext  $context            Actor and site the history read runs as.
     * @param   string            $identifier         Definition UUID or handle naming the record type.
     * @param   int|null          $definitionVersion  Version a revision was written under, or null for
     *          the installed version.
     *
     * @return  ResolvedBusinessDefinition  The requested version paired with its installation row,
     *          whatever lifecycle status that installation currently carries.
     *
     * @throws  \Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordDefinitionUnavailable  When
     *          no definition matches, or the requested version is not published.
     * @throws  \Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordSchemaUnavailable  When no
     *          retained installation exists, or the requested version is newer than the installed one.
     *
     * @since   2.0.0
     */
    public function forHistory(
        ExecutionContext $context,
        string $identifier,
        ?int $definitionVersion = null,
    ): ResolvedBusinessDefinition;
}
