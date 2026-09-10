<?php

declare(strict_types=1);

namespace Kumwe\App\Demo\Application;

use Kumwe\App\BusinessDefinition\Domain\DefinitionStatus;
use Kumwe\Context\Value\SiteContext;
use Ramsey\Uuid\Uuid;
use RuntimeException;

/**
 * Projects an immutable default-site business demo template into one validated site namespace.
 *
 * The released fixture stays byte-addressable and environment-independent. Installation derives a
 * site-owned document in memory, replacing only declared definition handles, globally stored definition
 * and record identities, and explicit site ownership fields. Business values such as field defaults are
 * never interpreted as site identifiers. The template's handle namespace and projection namespace derive
 * from the manifest's own profile name, so any discovered business profile — the released VDM example or
 * a fork's replacement — projects identically. Every projected definition document leaves in the
 * released-draft shape the installer publishes from — status `draft`, version zero — because that is the
 * one shape `EntityTypeDefinition::published()` accepts: a document exported from a running site carries
 * the published status and version of its source, and left as it was it installed once and then made every
 * later reconciliation fail before it could compare the definition to its checkpoint.
 *
 * @since  2.0.0
 */
final readonly class VdmBusinessManifestProjector
{
    /**
     * Derive a site-owned aggregate without mutating the catalog's source manifest.
     *
     * @param   array<string, mixed>  $manifest  Validated aggregate business demo template.
     * @param   SiteContext           $site      Installation site receiving the example.
     *
     * @return  array<string, mixed>  Site-scoped aggregate ready for canonical application services, every
     *          definition document in the released-draft shape (status `draft`, version zero).
     *
     * @throws  RuntimeException  When the source contradicts the template contract or the target site
     *          cannot form a valid business-definition namespace.
     *
     * @since   2.0.0
     */
    public function forSite(array $manifest, SiteContext $site): array
    {
        $siteIdentifier = $site->identifier();
        $profile = $this->profileName($manifest);
        $this->assertTemplate($manifest, $siteIdentifier, $profile);
        $replacements = $this->identityReplacements($manifest, $siteIdentifier, $profile);
        $projected = $this->map(
            $this->projectValues($manifest, $replacements),
            'aggregate manifest',
        );
        $documents = $this->map($projected['definition_documents'] ?? null, 'definition documents');
        foreach ($documents as $fixtureKey => $candidate) {
            $document = $this->map($candidate, sprintf('definition %s', $fixtureKey));
            $owner = $this->map($document['owner'] ?? null, sprintf('definition %s owner', $fixtureKey));
            if (($owner['type'] ?? null) !== 'site') {
                throw new RuntimeException(sprintf('Business demo definition %s is not site owned.', $fixtureKey));
            }
            $owner['identifier'] = $siteIdentifier;
            $document['owner'] = $owner;
            $document['site'] = $siteIdentifier;
            // The installer publishes every template document from a version-zero draft, exactly as
            // `BusinessDefinitionService::importDraft()` normalizes an imported document; a published
            // status or version copied from an export's source is lifecycle state, not template content.
            $document['status'] = DefinitionStatus::Draft->value;
            $document['definition_version'] = 0;
            $documents[$fixtureKey] = $document;
        }
        $records = $this->map($projected['records_document'] ?? null, 'records document');
        $records['site'] = $siteIdentifier;
        $projected['site_template'] = $siteIdentifier;
        $projected['definition_documents'] = $documents;
        $projected['records_document'] = $records;

        return $projected;
    }

    /**
     * Read and validate the profile name that anchors the template's identity namespaces.
     *
     * @param   array<string, mixed>  $manifest  Immutable aggregate business demo template.
     *
     * @return  string  Validated lowercase profile name, for example `vdm`.
     *
     * @throws  RuntimeException  When the manifest declares no well-formed profile name.
     *
     * @since   2.0.0
     */
    private function profileName(array $manifest): string
    {
        $profile = $manifest['profile'] ?? null;
        if (!is_string($profile) || preg_match('/^[a-z][a-z0-9-]{0,62}$/D', $profile) !== 1) {
            throw new RuntimeException('The business demo template declares no valid profile name.');
        }

        return $profile;
    }

    /**
     * Build the default-site handle prefix every template definition must use.
     *
     * @param   string  $profile  Validated template profile name.
     *
     * @return  string  Immutable source handle prefix, for example `site.default.vdm_`.
     *
     * @since   2.0.0
     */
    private function templatePrefix(string $profile): string
    {
        return 'site.' . SiteContext::DEFAULT . '.' . $profile . '_';
    }

    /**
     * Refuse contradictory template ownership and target namespaces before projection hides them.
     *
     * @param   array<string, mixed>  $manifest        Immutable aggregate business demo template.
     * @param   string                $siteIdentifier  Normalized target site identifier.
     * @param   string                $profile         Validated template profile name.
     *
     * @return  void
     *
     * @throws  RuntimeException  When source ownership is contradictory or a projected handle is invalid.
     *
     * @since   2.0.0
     */
    private function assertTemplate(array $manifest, string $siteIdentifier, string $profile): void
    {
        $prefix = $this->templatePrefix($profile);
        if (($manifest['site_template'] ?? null) !== SiteContext::DEFAULT) {
            throw new RuntimeException('The business demo aggregate must declare the default site template.');
        }
        if (preg_match('/^[a-z0-9][a-z0-9._-]{0,190}$/D', $siteIdentifier) !== 1) {
            throw new RuntimeException(sprintf(
                'Site %s cannot own business demo definitions.',
                $siteIdentifier,
            ));
        }

        $documents = $this->map($manifest['definition_documents'] ?? null, 'definition documents');
        foreach ($documents as $fixtureKey => $candidate) {
            $document = $this->map($candidate, sprintf('definition %s', $fixtureKey));
            $owner = $this->map($document['owner'] ?? null, sprintf('definition %s owner', $fixtureKey));
            $handle = $document['handle'] ?? null;
            if (
                ($document['site'] ?? null) !== SiteContext::DEFAULT
                || count($owner) !== 2
                || ($owner['type'] ?? null) !== 'site'
                || ($owner['identifier'] ?? null) !== SiteContext::DEFAULT
                || !is_string($handle)
                || !str_starts_with($handle, $prefix)
                || $handle === $prefix
            ) {
                throw new RuntimeException(sprintf(
                    'Business demo definition %s contradicts the default site template.',
                    $fixtureKey,
                ));
            }

            $projectedHandle = 'site.' . $siteIdentifier . '.' . $profile . '_'
                . substr($handle, strlen($prefix));
            if (
                strlen($projectedHandle) > 191
                || preg_match('/^[a-z][a-z0-9]*(?:[._-][a-z0-9]+)+$/D', $projectedHandle) !== 1
            ) {
                throw new RuntimeException(sprintf(
                    'Business demo definition %s cannot form a portable handle for site %s.',
                    $fixtureKey,
                    $siteIdentifier,
                ));
            }
        }

        $records = $this->map($manifest['records_document'] ?? null, 'records document');
        if (($records['site'] ?? null) !== SiteContext::DEFAULT) {
            throw new RuntimeException('The business demo records document contradicts the default site template.');
        }
    }

    /**
     * Build exact replacements for definition handles and globally stored definition or record UUIDs.
     *
     * @param   array<string, mixed>  $manifest        Validated default-site aggregate.
     * @param   string                $siteIdentifier  Validated target site identifier.
     * @param   string                $profile         Validated template profile name.
     *
     * @return  array<string, string>  Exact source value to site-owned value map.
     *
     * @throws  RuntimeException  When a definition or record identity is absent, malformed, or duplicated.
     *
     * @since   2.0.0
     */
    private function identityReplacements(array $manifest, string $siteIdentifier, string $profile): array
    {
        $prefix = $this->templatePrefix($profile);
        $replacements = [];
        $documents = $this->map($manifest['definition_documents'] ?? null, 'definition documents');
        foreach ($documents as $fixtureKey => $candidate) {
            $document = $this->map($candidate, sprintf('definition %s', $fixtureKey));
            $id = $document['id'] ?? null;
            $handle = $document['handle'] ?? null;
            if (!is_string($id) || !Uuid::isValid($id) || !is_string($handle)) {
                throw new RuntimeException(sprintf(
                    'Business demo definition %s has an invalid identity.',
                    $fixtureKey,
                ));
            }
            $this->addReplacement(
                $replacements,
                $handle,
                'site.' . $siteIdentifier . '.' . $profile . '_' . substr($handle, strlen($prefix)),
            );
            $this->addReplacement($replacements, $id, $this->siteUuid($id, $siteIdentifier, $profile));
        }

        $records = $this->map($manifest['records_document'] ?? null, 'records document');
        $declarations = $records['records'] ?? null;
        if (!is_array($declarations) || !array_is_list($declarations)) {
            throw new RuntimeException('The business demo record declarations are invalid.');
        }
        foreach ($declarations as $offset => $candidate) {
            $record = $this->map($candidate, sprintf('record declaration %d', $offset));
            $id = $record['record_id'] ?? null;
            if (!is_string($id) || !Uuid::isValid($id)) {
                throw new RuntimeException(sprintf(
                    'Business demo record declaration %d has an invalid identity.',
                    $offset,
                ));
            }
            $this->addReplacement($replacements, $id, $this->siteUuid($id, $siteIdentifier, $profile));
        }

        return $replacements;
    }

    /**
     * Add one unambiguous source identity to the projection map.
     *
     * @param   array<string, string>  &$replacements  Projection map under construction.
     * @param   string  $source  Exact source identity.
     * @param   string  $target  Site-owned target identity.
     *
     * @return  void
     *
     * @throws  RuntimeException  When one source identity is declared more than once.
     *
     * @since   2.0.0
     */
    private function addReplacement(array &$replacements, string $source, string $target): void
    {
        if (isset($replacements[$source])) {
            throw new RuntimeException(sprintf('The business demo source identity %s is duplicated.', $source));
        }
        $replacements[$source] = $target;
    }

    /**
     * Derive one stable site-owned UUID while retaining released default-site identities byte for byte.
     *
     * @param   string  $source          Released default-site UUID.
     * @param   string  $siteIdentifier  Validated target site identifier.
     * @param   string  $profile         Validated template profile name anchoring the derivation namespace.
     *
     * @return  string  Original UUID for default or deterministic UUIDv5 for another site.
     *
     * @since   2.0.0
     */
    private function siteUuid(string $source, string $siteIdentifier, string $profile): string
    {
        if ($siteIdentifier === SiteContext::DEFAULT) {
            return $source;
        }

        return Uuid::uuid5(
            Uuid::NAMESPACE_URL,
            sprintf('https://kumwe.dev/demo/%s/sites/%s/fixtures/%s', $profile, $siteIdentifier, $source),
        )->toString();
    }

    /**
     * Replace only declared identity values throughout the aggregate, leaving business values untouched.
     *
     * @param   mixed                  $value         Scalar or nested manifest value.
     * @param   array<string, string>  $replacements  Exact released identities and their target values.
     *
     * @return  mixed  Recursively projected value with list and object keys preserved.
     *
     * @since   2.0.0
     */
    private function projectValues(mixed $value, array $replacements): mixed
    {
        if (is_string($value) && isset($replacements[$value])) {
            return $replacements[$value];
        }
        if (!is_array($value)) {
            return $value;
        }

        $projected = [];
        foreach ($value as $key => $item) {
            $projected[$key] = $this->projectValues($item, $replacements);
        }

        return $projected;
    }

    /**
     * Require one projected value to remain an object with string keys.
     *
     * @param   mixed   $value  Candidate projected value.
     * @param   string  $name   Diagnostic noun identifying the value on failure.
     *
     * @return  array<string, mixed>  Validated object-shaped value.
     *
     * @throws  RuntimeException  When the value is not an object or contains a non-string key.
     *
     * @since   2.0.0
     */
    private function map(mixed $value, string $name): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new RuntimeException(sprintf('The business demo %s is invalid.', $name));
        }

        $result = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new RuntimeException(sprintf('The business demo %s has a non-string object key.', $name));
            }
            $result[$key] = $item;
        }

        return $result;
    }
}
