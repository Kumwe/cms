<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Release;

use JsonException;
use Kumwe\Producer\Render\BlockCoordinate;
use RuntimeException;
use stdClass;

/**
 * The exact first-party block and pattern coordinates the pinned Studio browser module compiles in.
 *
 * Studio's hosted runtime admits a built-in block only when the resolved session locks its exact
 * version and revision, and Producer's published rendering executes only exactly registered
 * coordinates. Neither authority publishes those revisions to PHP, so the App materializes them
 * from the installed exact `@kumwe/studio-core` package into
 * `resources/studio-contract/core-catalog.json` and proves the record against that package in its
 * release gate. This class reads that record fail-closed: a malformed, unpinned or drifted record
 * is refused at boot rather than locking an unknown catalog into an authoring session.
 *
 * @since  2.0.0
 */
final readonly class StudioCoreCatalog
{
    /**
     * Document kind the materialized record declares.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string KIND = 'studio-core-catalog';

    /**
     * Hold the validated coordinates.
     *
     * @param  string          $release   Exact coordinated Studio release the coordinates belong to.
     * @param  list<stdClass>  $blocks    `{type, version, revision}` locks in type order.
     * @param  list<stdClass>  $patterns  `{id, version, revision}` references in identifier order.
     *
     * @since  2.0.0
     */
    private function __construct(
        public string $release,
        public array $blocks,
        public array $patterns,
    ) {
    }

    /**
     * Decode and prove one materialized catalog record.
     *
     * @param   string  $json     Exact record bytes.
     * @param   string  $release  Exact release the record must belong to.
     *
     * @return  self  Validated catalog.
     *
     * @throws  RuntimeException  When the record is malformed, names another release, or repeats a coordinate.
     *
     * @since   2.0.0
     */
    public static function fromJson(string $json, string $release): self
    {
        try {
            $decoded = json_decode($json, false, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException('The Studio core catalog record is not valid JSON.', 0, $error);
        }
        if (
            !$decoded instanceof stdClass
            || ($decoded->kind ?? null) !== self::KIND
            || ($decoded->release ?? null) !== $release
            || !is_array($decoded->blocks ?? null)
            || !array_is_list($decoded->blocks)
            || $decoded->blocks === []
            || !is_array($decoded->patterns ?? null)
            || !array_is_list($decoded->patterns)
        ) {
            throw new RuntimeException('The Studio core catalog record does not describe the pinned release.');
        }

        return new self(
            $release,
            self::coordinates($decoded->blocks, 'type'),
            self::coordinates($decoded->patterns, 'id'),
        );
    }

    /**
     * Read and prove the record one deployment ships.
     *
     * @param   string  $path     Absolute path of `core-catalog.json`.
     * @param   string  $release  Exact release the record must belong to.
     *
     * @return  self  Validated catalog.
     *
     * @throws  RuntimeException  When the record is unreadable or invalid.
     *
     * @since   2.0.0
     */
    public static function fromFile(string $path, string $release): self
    {
        $json = is_file($path) ? file_get_contents($path) : false;
        if (!is_string($json)) {
            throw new RuntimeException('The Studio core catalog record is unavailable.');
        }

        return self::fromJson($json, $release);
    }

    /**
     * The exact Producer coordinates of every first-party block.
     *
     * @return  list<BlockCoordinate>  Coordinates in type order.
     *
     * @since   2.0.0
     */
    public function blockCoordinates(): array
    {
        $coordinates = [];
        foreach ($this->blocks as $block) {
            $type = $block->type;
            $version = $block->version;
            $revision = $block->revision;
            if (is_string($type) && is_string($version) && is_string($revision)) {
                $coordinates[] = new BlockCoordinate($type, $version, $revision);
            }
        }

        return $coordinates;
    }

    /**
     * Whether the catalog compiles in one exact block type.
     *
     * @param   string  $type  Qualified block type.
     *
     * @return  bool  True when the type is first-party.
     *
     * @since   2.0.0
     */
    public function hasBlock(string $type): bool
    {
        foreach ($this->blocks as $block) {
            if ($block->type === $type) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the catalog compiles in one exact pattern identifier.
     *
     * @param   string  $id  Qualified pattern identifier.
     *
     * @return  bool  True when the pattern is first-party.
     *
     * @since   2.0.0
     */
    public function hasPattern(string $id): bool
    {
        foreach ($this->patterns as $pattern) {
            if ($pattern->id === $id) {
                return true;
            }
        }

        return false;
    }

    /**
     * Admit one ordered list of exact coordinates keyed by an identifying member.
     *
     * @param   list<mixed>  $entries  Decoded list.
     * @param   string       $key      `type` for blocks, `id` for patterns.
     *
     * @return  list<stdClass>  Coordinates carrying exactly the key, `version` and `revision`.
     *
     * @throws  RuntimeException  When an entry is malformed, unordered or repeated.
     *
     * @since   2.0.0
     */
    private static function coordinates(array $entries, string $key): array
    {
        $coordinates = [];
        $previous = null;
        foreach ($entries as $entry) {
            $members = $entry instanceof stdClass ? array_keys(get_object_vars($entry)) : [];
            sort($members, SORT_STRING);
            $expected = [$key, 'revision', 'version'];
            sort($expected, SORT_STRING);
            $identity = $entry instanceof stdClass ? ($entry->{$key} ?? null) : null;
            $version = $entry instanceof stdClass ? ($entry->version ?? null) : null;
            $revision = $entry instanceof stdClass ? ($entry->revision ?? null) : null;
            if (
                !$entry instanceof stdClass
                || $members !== $expected
                || !is_string($identity)
                || preg_match('#^[a-z][a-z0-9]*(?:[.-][a-z0-9]+)*/[a-z][a-z0-9]*(?:[.-][a-z0-9]+)*$#D', $identity) !== 1
                || !is_string($version)
                || preg_match('/^(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)$/D', $version) !== 1
                || !is_string($revision)
                || $revision === ''
                || ($previous !== null && strcmp($previous, $identity) >= 0)
            ) {
                throw new RuntimeException('The Studio core catalog record carries a malformed coordinate.');
            }
            $previous = $identity;
            $coordinates[] = (object) [$key => $identity, 'version' => $version, 'revision' => $revision];
        }

        return $coordinates;
    }
}
