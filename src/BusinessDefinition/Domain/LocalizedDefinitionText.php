<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessDefinition\Domain;

use Kumwe\Localization\Domain\InvalidLocaleTag;
use Kumwe\Localization\Domain\LocaleTag;

/**
 * The locale dimension on a business definition's operator-facing wording, and the rules it obeys.
 *
 * `EntityTypeDefinition`'s singular and plural labels and `FieldDefinition`'s label, description and
 * help text are the only strings in a definition an operator reads, and they sit inside the document a
 * published version is checksummed over. A published version is immutable, so the dimension could not
 * be added later without migrating live documents — which is why it exists before the first extension
 * publishes, and why it is shaped so that a definition that does not use it is byte-identical to the
 * one it was before. That is the whole reason translations are held in a member of their own, written
 * into the document only when non-empty, rather than by widening each label into an object.
 *
 * The declared text stays exactly where it was. A translation never replaces it; it stands beside it,
 * and the declared text is the last fallback, so a definition always has wording to show in every
 * locale. Resolution walks the requested locale's own fallback chain first — `pt-BR`, then `pt` — which
 * is the same chain the message catalogues are resolved through, so a definition label and the
 * interface around it agree about what "close enough" means.
 *
 * @since  2.0.0
 */
final class LocalizedDefinitionText
{
    /**
     * Locales one member of one definition may be translated into.
     *
     * The ceiling is the same order as the rest of a definition's collection bounds, and it exists for
     * the same reason: the document travels into a checksum and a stored row, so nothing in it may be
     * unbounded.
     *
     * @var    int
     * @since  2.0.0
     */
    public const MAXIMUM_LOCALES = 64;

    /**
     * Validate and canonicalize a member-to-locale-to-text map declared on a definition.
     *
     * Locale keys are normalised through `LocaleTag`, so `pt_br` and `PT-BR` cannot both appear as
     * separate translations of the same thing, and both member names and locale tags are sorted, so two
     * authors declaring the same translations in a different order produce one document and one
     * checksum. A member whose map is empty is dropped entirely rather than written as an empty object,
     * which is what keeps an untranslated definition's bytes unchanged.
     *
     * @param   array<string, mixed>  $translations  Declared map, keyed by member name then locale tag.
     * @param   array<string, int>    $members       Translatable member names and the byte bound each one
     *          shares with the declared text it stands beside.
     *
     * @return  array<string, array<string, string>>  The map with locales normalised, members and locales
     *          sorted, and empty members removed; empty when nothing is translated.
     *
     * @throws  InvalidBusinessDefinition  When a member is not translatable, a map is not an object, a
     *          locale tag is malformed or duplicates another tag after normalization, a translation is
     *          blank or over its member's bound, or a member declares more than 64 locales.
     *
     * @since   2.0.0
     */
    public static function normalize(array $translations, array $members): array
    {
        $normalized = [];
        foreach ($translations as $member => $locales) {
            if (!isset($members[$member])) {
                throw new InvalidBusinessDefinition(
                    'Business definition member ' . $member . ' does not carry translated text.',
                );
            }
            if (!is_array($locales) || ($locales !== [] && array_is_list($locales))) {
                throw new InvalidBusinessDefinition('Business definition translations must be an object.');
            }
            if (count($locales) > self::MAXIMUM_LOCALES) {
                throw new InvalidBusinessDefinition('A business definition member exceeds 64 translations.');
            }
            $texts = [];
            foreach ($locales as $tag => $text) {
                $locale = self::locale($tag);
                if (array_key_exists($locale, $texts)) {
                    throw new InvalidBusinessDefinition(sprintf(
                        'Business definition member %s declares locale %s more than once after normalization.',
                        $member,
                        $locale,
                    ));
                }
                $texts[$locale] = self::text($text, $members[$member]);
            }
            if ($texts === []) {
                continue;
            }
            ksort($texts, SORT_STRING);
            $normalized[$member] = $texts;
        }
        ksort($normalized, SORT_STRING);

        return $normalized;
    }

    /**
     * Read the wording one member should be shown in, falling back to the declared text.
     *
     * @param   array<string, array<string, string>>  $translations  Normalised translations of the whole
     *          definition member set.
     * @param   string                                $member        Member being rendered, such as `label`.
     * @param   string                                $declared      Text the definition declares, used when
     *          the locale and its fallbacks carry no translation.
     * @param   LocaleTag|string                      $locale        Locale the operator is reading in.
     *
     * @return  string  The translation for the closest locale the member carries, otherwise the declared
     *          text — never an empty string and never the member's name.
     *
     * @throws  InvalidLocaleTag  When the locale is given as a malformed tag.
     *
     * @since   2.0.0
     */
    public static function resolve(
        array $translations,
        string $member,
        string $declared,
        LocaleTag|string $locale,
    ): string {
        $texts = $translations[$member] ?? [];
        if ($texts === []) {
            return $declared;
        }
        $tag = is_string($locale) ? LocaleTag::fromString($locale) : $locale;
        foreach ($tag->fallbacks() as $candidate) {
            if (isset($texts[$candidate])) {
                return $texts[$candidate];
            }
        }

        return $declared;
    }

    /**
     * Reduce one declared locale key to its canonical tag.
     *
     * @param   mixed  $tag  Locale key exactly as the definition declared it.
     *
     * @return  string  The normalised tag, such as `pt-BR`.
     *
     * @throws  InvalidBusinessDefinition  When the key is not a well-formed language tag.
     *
     * @since   2.0.0
     */
    private static function locale(mixed $tag): string
    {
        if (!is_string($tag)) {
            throw new InvalidBusinessDefinition('A business definition translation locale must be a string.');
        }
        try {
            return LocaleTag::fromString($tag)->toString();
        } catch (InvalidLocaleTag $exception) {
            throw new InvalidBusinessDefinition(
                'Business definition translation locale ' . $tag . ' is not a language tag.',
                0,
                $exception,
            );
        }
    }

    /**
     * Check one translated string against the bound its declared counterpart already obeys.
     *
     * @param   mixed  $text     Translation exactly as the definition declared it.
     * @param   int    $maximum  Byte bound the member's declared text is held to.
     *
     * @return  string  The translation, trimmed.
     *
     * @throws  InvalidBusinessDefinition  When the value is not a string, is blank, or is over the bound.
     *
     * @since   2.0.0
     */
    private static function text(mixed $text, int $maximum): string
    {
        if (!is_string($text)) {
            throw new InvalidBusinessDefinition('A business definition translation must be a string.');
        }
        $text = trim($text);
        if ($text === '' || strlen($text) > $maximum) {
            throw new InvalidBusinessDefinition('A business definition translation is empty or over its bound.');
        }

        return $text;
    }

    /**
     * Block instantiation; every member of this helper is static.
     *
     * @since  2.0.0
     */
    private function __construct()
    {
    }
}
