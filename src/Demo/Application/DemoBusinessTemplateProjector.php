<?php

declare(strict_types=1);

namespace Kumwe\App\Demo\Application;

use Kumwe\App\BusinessDefinition\Domain\DefinitionOwnerType;
use Kumwe\App\BusinessDefinition\Domain\DefinitionStatus;
use Kumwe\App\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\Context\Value\SiteContext;
use RuntimeException;

/**
 * Projects a running site's published definitions back into the default-site template a profile is released as.
 *
 * This is the inverse of `VdmBusinessManifestProjector`. That projector derives a site-owned graph from a
 * released template whose handles carry the profile namespace `site.default.<profile>_<name>`; this one takes
 * the published definitions of a running site — whatever namespace they carry — and produces what the released
 * contract accepts: every handle re-namespaced under the export profile, every reference to it (relationship
 * targets, entity-reference and ordered-lines field targets, record declarations) rewritten through the same
 * exact-value map, ownership returned to the default site template, and the released-draft shape — status
 * `draft`, version zero — the installer publishes from. Installation order is settled over the complete
 * reference graph, relationships and reference fields alike, which is the same target set
 * `EntityTypeDefinition::dependencyGraph()` persists and `BusinessDefinitionValidator::validateGraph()` closes
 * over, so no definition is declared before one it references and a cycle is refused by name rather than
 * written into a package that can never install.
 *
 * @since  2.0.0
 */
final readonly class DemoBusinessTemplateProjector
{
    /**
     * Grammar a projected template handle must satisfy, mirroring the definition domain's own handle rule.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string HANDLE_PATTERN = '/^[a-z][a-z0-9]*(?:[._-][a-z0-9]+)+$/D';

    /**
     * Longest handle the definition domain stores, so a projected handle is refused before it is written.
     *
     * @var    int
     * @since  2.0.0
     */
    private const int MAXIMUM_HANDLE_LENGTH = 191;

    /**
     * Order definitions so every definition they reference — by relationship or by field — precedes them.
     *
     * The sort is a repeated stable scan: each pass places, in the given order, every definition whose
     * exported references are already placed, so ties keep their catalog order. A pass that places nothing
     * means the remaining definitions reference one another in a cycle; the members of that cycle are named
     * and the export is refused, because the installer imports and publishes definitions one at a time and
     * can never satisfy the first member's references.
     *
     * @param   list<EntityTypeDefinition>  $definitions  Published definitions in catalog order.
     *
     * @return  list<EntityTypeDefinition>  The same definitions in installation order.
     *
     * @throws  RuntimeException  When the exported definitions reference one another in a cycle.
     *
     * @since   2.0.0
     */
    public function orderByDependency(array $definitions): array
    {
        $exported = [];
        foreach ($definitions as $definition) {
            $exported[$definition->handle] = true;
        }
        $placed = [];
        $ordered = [];
        $remaining = $definitions;
        while ($remaining !== []) {
            $deferred = [];
            foreach ($remaining as $definition) {
                $ready = true;
                foreach ($this->references($definition, $exported) as $target) {
                    if (!isset($placed[$target])) {
                        $ready = false;
                        break;
                    }
                }
                if ($ready) {
                    $placed[$definition->handle] = true;
                    $ordered[] = $definition;
                } else {
                    $deferred[] = $definition;
                }
            }
            if (count($deferred) === count($remaining)) {
                throw new RuntimeException(sprintf(
                    'Business definitions %s reference one another in a cycle; a demo profile installs '
                        . 'definitions one at a time and cannot carry it.',
                    implode(', ', $this->cycleMembers($deferred, $exported)),
                ));
            }
            $remaining = $deferred;
        }

        return $ordered;
    }

    /**
     * Name the exported definitions one definition references, as fixture keys in installation order.
     *
     * @param   EntityTypeDefinition        $definition  Definition whose `depends_on` entries are wanted.
     * @param   list<EntityTypeDefinition>  $ordered     Every exported definition in installation order.
     * @param   array<string, string>       $fixtures    Fixture key by live definition handle.
     *
     * @return  list<string>  Fixture keys of the referenced definitions, in installation order.
     *
     * @since   2.0.0
     */
    public function dependencies(EntityTypeDefinition $definition, array $ordered, array $fixtures): array
    {
        $exported = [];
        foreach (array_keys($fixtures) as $handle) {
            $exported[$handle] = true;
        }
        $targets = [];
        foreach ($this->references($definition, $exported) as $target) {
            $targets[$target] = true;
        }
        $dependencies = [];
        foreach ($ordered as $candidate) {
            if (isset($targets[$candidate->handle])) {
                $dependencies[] = $fixtures[$candidate->handle];
            }
        }

        return $dependencies;
    }

    /**
     * Map every live definition handle to the template handle it is released under.
     *
     * The template handle is `site.default.<profile>_<tail>`, where the tail is the definition's fixture key
     * without its `definition.` prefix — the same derivation the released VDM profile follows, so a fixture
     * `definition.client_account` exported under `fork` becomes `site.default.fork_client_account`. Runs of
     * underscores are collapsed and trailing ones dropped so the tail satisfies the handle grammar, and a
     * tail that would collide with one already claimed receives a numeric suffix.
     *
     * @param   string                      $profile      Validated profile name the package is exported under.
     * @param   list<EntityTypeDefinition>  $definitions  Exported definitions in installation order.
     * @param   array<string, string>       $fixtures     Fixture key by live definition handle.
     *
     * @return  array<string, string>  Template handle by live definition handle.
     *
     * @throws  RuntimeException  When a definition has no fixture key or its template handle is not portable.
     *
     * @since   2.0.0
     */
    public function templateHandles(string $profile, array $definitions, array $fixtures): array
    {
        $prefix = 'site.' . SiteContext::DEFAULT . '.' . $profile . '_';
        $handles = [];
        $claimed = [];
        foreach ($definitions as $definition) {
            $fixture = $fixtures[$definition->handle] ?? null;
            if (!is_string($fixture) || !str_starts_with($fixture, 'definition.')) {
                throw new RuntimeException(sprintf(
                    'Business definition %s has no definition fixture key to derive a template handle from.',
                    $definition->handle,
                ));
            }
            $tail = preg_replace('/_+/', '_', substr($fixture, strlen('definition.'))) ?? '';
            $handle = $prefix . trim($tail, '_');
            if (isset($claimed[$handle])) {
                $suffix = 2;
                while (isset($claimed[$handle . '_' . $suffix])) {
                    ++$suffix;
                }
                $handle .= '_' . $suffix;
            }
            if (
                strlen($handle) > self::MAXIMUM_HANDLE_LENGTH
                || preg_match(self::HANDLE_PATTERN, $handle) !== 1
            ) {
                throw new RuntimeException(sprintf(
                    'Business definition %s cannot form a portable template handle under profile %s.',
                    $definition->handle,
                    $profile,
                ));
            }
            $claimed[$handle] = true;
            $handles[$definition->handle] = $handle;
        }

        return $handles;
    }

    /**
     * Project one published, site-owned definition into the released-draft document a profile ships.
     *
     * The document is the definition's canonical form returned to the default site template: owned by
     * `default`, declared in `default`, carrying the template handle, and in the shape the installer
     * publishes from — status `draft`, version zero, `record_invariants` present even when empty. Every
     * exact occurrence of a live handle anywhere in the document is rewritten through the handle map, so
     * relationship targets and reference-field targets follow their definitions into the new namespace.
     *
     * @param   EntityTypeDefinition   $definition  Live published definition.
     * @param   array<string, string>  $handles     Template handle by live definition handle.
     *
     * @return  array<string, mixed>  Released-draft definition document.
     *
     * @throws  RuntimeException  When the definition is not site owned or has no template handle.
     *
     * @since   2.0.0
     */
    public function templateDocument(EntityTypeDefinition $definition, array $handles): array
    {
        if ($definition->owner->type !== DefinitionOwnerType::Site) {
            throw new RuntimeException(sprintf(
                'Business definition %s is not site owned and cannot be exported as a template.',
                $definition->handle,
            ));
        }
        if (!isset($handles[$definition->handle])) {
            throw new RuntimeException(sprintf(
                'Business definition %s has no template handle.',
                $definition->handle,
            ));
        }
        $document = $definition->toArray();
        $document['site'] = SiteContext::DEFAULT;
        $document['owner'] = ['type' => DefinitionOwnerType::Site->value, 'identifier' => SiteContext::DEFAULT];
        $document['status'] = DefinitionStatus::Draft->value;
        $document['definition_version'] = 0;
        if (!array_key_exists('record_invariants', $document)) {
            $document['record_invariants'] = [];
        }

        return $this->replaceValues($document, $handles);
    }

    /**
     * Rewrite every definition reference of a records document into the template namespace.
     *
     * Record, relation, action, and archive declarations name their definition by handle; each is mapped
     * through the handle map, and the document's site becomes the default site template, which is what
     * `VdmBusinessManifestProjector` requires before projecting the records onto an installation site.
     *
     * @param   array<string, mixed>   $records  Records document built from live reads.
     * @param   array<string, string>  $handles  Template handle by live definition handle.
     *
     * @return  array<string, mixed>  Records document in the template namespace.
     *
     * @throws  RuntimeException  When a declaration names a definition the export does not carry.
     *
     * @since   2.0.0
     */
    public function templateRecords(array $records, array $handles): array
    {
        $records['site'] = SiteContext::DEFAULT;
        foreach (['records', 'relations', 'actions', 'archives'] as $member) {
            $declarations = $records[$member] ?? null;
            if (!is_array($declarations)) {
                continue;
            }
            foreach ($declarations as $offset => $declaration) {
                if (!is_array($declaration)) {
                    continue;
                }
                $handle = $declaration['definition'] ?? null;
                if (!is_string($handle) || !isset($handles[$handle])) {
                    throw new RuntimeException(sprintf(
                        'A business %s declaration references a definition the export does not carry.',
                        rtrim($member, 's'),
                    ));
                }
                $declaration['definition'] = $handles[$handle];
                $declarations[$offset] = $declaration;
            }
            $records[$member] = $declarations;
        }

        return $records;
    }

    /**
     * Name the exported definitions one definition reaches by relationship or by reference field.
     *
     * @param   EntityTypeDefinition  $definition  Definition whose references are read.
     * @param   array<string, true>   $exported    Handles of every exported definition.
     *
     * @return  list<string>  Exported handles the definition references, itself excluded.
     *
     * @since   2.0.0
     */
    private function references(EntityTypeDefinition $definition, array $exported): array
    {
        $targets = [];
        foreach ($definition->dependencyGraph()['entities'] as $target) {
            if ($target !== $definition->handle && isset($exported[$target])) {
                $targets[] = $target;
            }
        }

        return $targets;
    }

    /**
     * Name the definitions among a stalled set that can reach themselves through exported references.
     *
     * @param   list<EntityTypeDefinition>  $stalled   Definitions a placement pass could not order.
     * @param   array<string, true>         $exported  Handles of every exported definition.
     *
     * @return  list<string>  Handles of the cycle members, or every stalled handle if none is found.
     *
     * @since   2.0.0
     */
    private function cycleMembers(array $stalled, array $exported): array
    {
        $byHandle = [];
        foreach ($stalled as $definition) {
            $byHandle[$definition->handle] = $definition;
        }
        $members = [];
        foreach ($stalled as $definition) {
            $visited = [];
            if ($this->reaches($definition, $definition->handle, $byHandle, $exported, $visited)) {
                $members[] = $definition->handle;
            }
        }

        return $members === [] ? array_keys($byHandle) : $members;
    }

    /**
     * Decide whether one definition reaches a goal handle through the stalled set's references.
     *
     * @param   EntityTypeDefinition                 $from      Definition the walk starts at.
     * @param   string                               $goal      Handle whose reachability is tested.
     * @param   array<string, EntityTypeDefinition>  $stalled   Stalled definitions by handle.
     * @param   array<string, true>                  $exported  Handles of every exported definition.
     * @param   array<string, true>                  &$visited  Handles already walked in this search.
     *
     * @return  bool  True when a chain of references leads from the definition to the goal.
     *
     * @since   2.0.0
     */
    private function reaches(
        EntityTypeDefinition $from,
        string $goal,
        array $stalled,
        array $exported,
        array &$visited,
    ): bool {
        foreach ($this->references($from, $exported) as $target) {
            if ($target === $goal) {
                return true;
            }
            if (isset($visited[$target]) || !isset($stalled[$target])) {
                continue;
            }
            $visited[$target] = true;
            if ($this->reaches($stalled[$target], $goal, $stalled, $exported, $visited)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Replace every exact occurrence of a live handle in one document, preserving keys and list order.
     *
     * @param   array<string, mixed>   $document      Object-shaped document to project.
     * @param   array<string, string>  $replacements  Template handle by live definition handle.
     *
     * @return  array<string, mixed>  Projected document.
     *
     * @since   2.0.0
     */
    private function replaceValues(array $document, array $replacements): array
    {
        $projected = [];
        foreach ($document as $key => $item) {
            $projected[$key] = $this->replaceValue($item, $replacements);
        }

        return $projected;
    }

    /**
     * Replace one value when it is exactly a live handle, descending into arrays otherwise.
     *
     * @param   mixed                  $value         Scalar or nested document value.
     * @param   array<string, string>  $replacements  Template handle by live definition handle.
     *
     * @return  mixed  Projected value with array keys preserved.
     *
     * @since   2.0.0
     */
    private function replaceValue(mixed $value, array $replacements): mixed
    {
        if (is_string($value)) {
            return $replacements[$value] ?? $value;
        }
        if (!is_array($value)) {
            return $value;
        }
        $projected = [];
        foreach ($value as $key => $item) {
            $projected[$key] = $this->replaceValue($item, $replacements);
        }

        return $projected;
    }
}
