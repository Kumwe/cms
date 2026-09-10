<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessDefinition\Application;

use Kumwe\App\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\App\BusinessDefinition\Domain\FieldTypeDefinition;
use Kumwe\Context\Value\SiteContext;

/**
 * Applies the business definitions an extension package declares to a site's definition catalog.
 *
 * The extension lifecycle owns installation, but definitions are versioned content: publishing what a
 * package ships has to advance each handle exactly one version, leave already published bytes byte-identical,
 * and deprecate the handles a new release stopped declaring. Stating that work here lets the extension
 * context ask for it without learning the business-definition vocabulary, and keeps the dependency pointing
 * inward. Implementations run inside the caller's lifecycle transaction and reject a package that claims
 * another owner's identifier, edits published bytes, or skips a version, so a refused release rolls back
 * with the catalog untouched.
 *
 * @since  2.0.0
 */
interface PackageDefinitionSynchronizer
{
    /**
     * Bring a site's catalog in line with the field types and definitions one package release declares.
     *
     * @param   string                      $extensionIdentifier  Owning extension, as `vendor/name`.
     * @param   string                      $releaseVersion       Release the contributions were read from.
     * @param   SiteContext                 $site                 Site whose catalog is being updated.
     * @param   list<FieldTypeDefinition>   $fieldTypes           Field types the release declares.
     * @param   list<EntityTypeDefinition>  $definitions          Entity types the release declares.
     * @param   bool                        $active               Whether the package is enabled once applied.
     * @param   string                      $actorId              Actor recorded against the audit entries.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function synchronize(
        string $extensionIdentifier,
        string $releaseVersion,
        SiteContext $site,
        array $fieldTypes,
        array $definitions,
        bool $active,
        string $actorId,
    ): void;

    /**
     * Move a package's already applied definitions between available and withheld.
     *
     * Enabling or disabling an installed extension is not a re-publication: the versions in the catalog keep
     * their bytes, their numbering and their history, and only their availability to the runtime changes.
     *
     * @param   string  $extensionIdentifier  Owning extension, as `vendor/name`.
     * @param   bool    $active               Whether its definitions become available again.
     * @param   string  $actorId              Actor recorded against the audit entry.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function setActive(string $extensionIdentifier, bool $active, string $actorId): void;
}
