<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessSchema\Application;

use Kumwe\App\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\App\BusinessSchema\Domain\PhysicalSchemaBlueprint;
use Kumwe\Context\Value\SiteContext;

/**
 * Port translating a published business definition into the physical schema that definition installs.
 *
 * This is the single crossing from definition vocabulary — fields, relationships, scopes — into tables,
 * columns, indexes and foreign keys, and every stage downstream treats it as a pure function of the
 * definition and the site. `BusinessSchemaPlanner` diffs its output against what is installed to derive
 * operations, approval binds an operator to that output's checksum, and `BusinessSchemaExecutor`
 * recompiles once more as execution starts and refuses to run when the checksum has moved. One whose
 * naming or ordering varied between two compilations of the same definition would silently invalidate
 * every approval, so determinism matters here as much as correctness.
 *
 * @since  2.0.0
 */
interface DefinitionPhysicalSchemaCompiler
{
    /**
     * Compile the physical tables one published definition version installs.
     *
     * @param   EntityTypeDefinition  $definition  Published definition version to compile; never a draft.
     * @param   SiteContext           $site        Site owning the definition and scoping the generated names.
     *
     * @return  PhysicalSchemaBlueprint  Every table the version needs, checksummed for later comparison.
     *
     * @throws  \Kumwe\App\BusinessSchema\Domain\InvalidBusinessSchema  When the definition belongs to
     *          another site, or carries no published version number.
     *
     * @since   2.0.0
     */
    public function compile(EntityTypeDefinition $definition, SiteContext $site): PhysicalSchemaBlueprint;
}
