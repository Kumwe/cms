<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Authoring;

use Kumwe\App\Content\Application\ContentRecord;
use Kumwe\App\Content\Domain\ContentTypeDefinition;
use stdClass;

/**
 * The coordinated artifact state of one contextual authoring session as PHP projects it.
 *
 * Every member is an exact projection of live App state (or, for a not-yet-created item or a blank
 * canvas, of deterministic provisional identities), so the coordinates the browser echoes back can
 * be compared byte for byte against a fresh projection before any durable effect.
 *
 * @since  2.0.0
 */
final readonly class ContentStudioAuthoringState
{
    /**
     * Capture one coordinated projection.
     *
     * @param  stdClass                $coordinates  Canonical `artifactCoordinates`.
     * @param  ?stdClass               $type         Reusable-content-type definition, or null on a blank canvas.
     * @param  stdClass                $model        Canonical `content-model` document.
     * @param  stdClass                $blueprint    Canonical `blueprint` document.
     * @param  stdClass                $entry        Canonical `entry` document.
     * @param  ?ContentTypeDefinition  $definition   Exact App definition behind the type, when one exists.
     * @param  ?ContentRecord          $record       Exact App record behind the entry, when it is persisted.
     *
     * @since  2.0.0
     */
    public function __construct(
        public stdClass $coordinates,
        public ?stdClass $type,
        public stdClass $model,
        public stdClass $blueprint,
        public stdClass $entry,
        public ?ContentTypeDefinition $definition,
        public ?ContentRecord $record,
    ) {
    }

    /**
     * The persisted entry revision Studio must expect, or null while the item is provisional.
     *
     * @return  ?string  Canonical entry revision of the live record.
     *
     * @since   2.0.0
     */
    public function entryRevision(): ?string
    {
        $entry = $this->coordinates->entry ?? null;
        $revision = $entry instanceof stdClass ? ($entry->revision ?? null) : null;

        return $this->record !== null && is_string($revision) ? $revision : null;
    }
}
