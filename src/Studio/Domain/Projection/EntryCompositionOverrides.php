<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Domain\Projection;

use InvalidArgumentException;
use Kumwe\Context\Value\SiteContext;
use Kumwe\Producer\Canonical\CanonicalEncodingException;
use Kumwe\Producer\Canonical\CanonicalJson;
use stdClass;

/**
 * Canonical per-entry values that override reusable composition decisions without changing Content.
 *
 * The object is copied into Studio's `entry.compositionOverrides` member and is deliberately separate
 * from the Content entry body: Studio may read it, while Content validation, workflow, and translation
 * state remain authoritative in their own bounded context. Canonical bytes are stored privately so a
 * caller cannot mutate an object after construction and change what this value means.
 *
 * @since  2.0.0
 */
final readonly class EntryCompositionOverrides
{
    /**
     * Canonical immutable representation of the override object.
     *
     * @var    string
     * @since  2.0.0
     */
    private string $canonical;

    /**
     * Capture one entry's override object at an optimistic revision.
     *
     * @param   SiteContext  $site      Site whose entry is addressed.
     * @param   string       $entryId   Canonical UUID of the Content entry.
     * @param   stdClass     $values    Override values keyed by Studio stable node or binding identifier.
     * @param   int          $revision  Optimistic override revision, starting at one.
     *
     * @throws  InvalidArgumentException  When the entry, revision, key set, or byte budget is invalid.
     * @throws  CanonicalEncodingException  When a value is not representable as canonical JSON.
     *
     * @since   2.0.0
     */
    public function __construct(
        public SiteContext $site,
        public string $entryId,
        stdClass $values,
        public int $revision,
    ) {
        if (preg_match(self::UUID, $entryId) !== 1) {
            throw new InvalidArgumentException('Studio entry overrides require a canonical Content UUID.');
        }
        if ($revision < 1) {
            throw new InvalidArgumentException('A Studio entry override revision must be positive.');
        }
        $members = get_object_vars($values);
        if (count($members) > 1000) {
            throw new InvalidArgumentException('Studio entry overrides may carry at most 1000 members.');
        }
        foreach (array_keys($members) as $name) {
            if (
                preg_match(self::STABLE_ID, (string) $name) !== 1
                || in_array($name, self::FORBIDDEN_IDENTIFIERS, true)
            ) {
                throw new InvalidArgumentException('A Studio entry override key is not a stable identifier.');
            }
        }
        foreach ($members as $value) {
            self::assertJsonValueShape($value, 1);
        }
        $canonical = CanonicalJson::stringify($values);
        if (strlen($canonical) > 1_048_576) {
            throw new InvalidArgumentException('Studio entry overrides exceed the one-megabyte bound.');
        }
        $this->canonical = $canonical;
    }

    /**
     * Return a fresh decoded copy safe for insertion into a projection document.
     *
     * @return  stdClass  The same canonical object supplied at construction.
     *
     * @throws  \JsonException  If an impossible corruption made the private canonical bytes unreadable.
     *
     * @since   2.0.0
     */
    public function values(): stdClass
    {
        $decoded = json_decode($this->canonical, false, 65, JSON_THROW_ON_ERROR);
        if (!$decoded instanceof stdClass) {
            throw new \LogicException('Canonical Studio entry overrides did not decode to an object.');
        }

        return $decoded;
    }

    /**
     * Return the byte-stable form persistence writes.
     *
     * @return  string  Canonical UTF-8 JSON object bytes.
     *
     * @since   2.0.0
     */
    public function canonical(): string
    {
        return $this->canonical;
    }

    /**
     * Enforce the recursive limits inherited from Studio's canonical JSON value definition.
     *
     * @param   mixed  $value  Candidate nested override value.
     * @param   int    $depth  Number of containers already entered, including the root override object.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When a list, object, member name, or depth exceeds the pinned contract.
     *
     * @since   2.0.0
     */
    private static function assertJsonValueShape(mixed $value, int $depth): void
    {
        if (!is_array($value) && !$value instanceof stdClass) {
            return;
        }
        if ($depth >= CanonicalJson::DEFAULT_MAXIMUM_DEPTH) {
            throw new InvalidArgumentException('Studio entry overrides exceed the canonical depth bound.');
        }
        if (is_array($value)) {
            if (!array_is_list($value)) {
                return;
            }
            if (count($value) > 10_000) {
                throw new InvalidArgumentException('A Studio entry override list may carry at most 10000 items.');
            }
            foreach ($value as $member) {
                self::assertJsonValueShape($member, $depth + 1);
            }

            return;
        }

        $members = get_object_vars($value);
        if (count($members) > 10_000) {
            throw new InvalidArgumentException('A Studio entry override object may carry at most 10000 members.');
        }
        foreach ($members as $name => $member) {
            if (
                mb_strlen($name, 'UTF-8') < 1
                || mb_strlen($name, 'UTF-8') > 200
                || preg_match('/^[^\x00-\x1F\x7F]+$/uD', $name) !== 1
            ) {
                throw new InvalidArgumentException('A nested Studio entry override member name is invalid.');
            }
            self::assertJsonValueShape($member, $depth + 1);
        }
    }

    /**
     * Canonical UUID grammar shared with Content entries.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD';

    /**
     * Stable identifier grammar from Studio's common schema.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string STABLE_ID = '/^[A-Za-z0-9][A-Za-z0-9._:\/-]{0,239}$/D';

    /**
     * Property names forbidden by every Studio identifier vocabulary.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private const array FORBIDDEN_IDENTIFIERS = ['__proto__', 'prototype', 'constructor'];
}
