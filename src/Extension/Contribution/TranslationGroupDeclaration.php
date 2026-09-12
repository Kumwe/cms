<?php

declare(strict_types=1);

namespace Kumwe\App\Extension\Contribution;

use Kumwe\Extension\Spi\Contribution\ContributionDefinition;
use InvalidArgumentException;
use Kumwe\App\Content\Domain\TranslationGroup;
use Kumwe\Localization\Domain\InvalidLocaleTag;
use Kumwe\Localization\Domain\LocaleTag;

/**
 * What a package declares before any of its content is allowed to carry locale variants.
 *
 * Content contributed by an extension is content, and it needs the same translation group core content
 * has. The reason that has to be settled in the contribution contract rather than later is not
 * convenience: a manifest is signed, and a package admitted against a contract with no locale dimension
 * would have to be migrated to gain one. So the dimension is declared here, up front, in the shape
 * every other contribution uses — an owner-namespaced identifier naming the content set, the locales
 * the package is prepared to publish it in, and the locale a reader falls back to when the one they
 * asked for is missing or still drafting.
 *
 * It sits beside the registrar that consumes it rather than in `Content\Domain`, because it is not a
 * content concept at all: nothing in the content model reads it, `TranslationGroup` never sees one, and
 * what it describes is a promise a *package* makes at admission time. Putting it here is also what
 * keeps the dependency direction honest — a declaration that has to implement an application-owned
 * contribution contract belongs in the application layer, alongside `CapabilityDefinition` and
 * `PortalRouteDefinition`, rather than reaching outward from the domain to find its interface.
 *
 * The locale list is a closed claim rather than a hint, exactly as a rate provider's currency list is.
 * An operator can read which languages a package promises before installing it, and the declared
 * fallback has to be one of them, because a fallback naming a language the package never publishes is
 * not a fallback.
 *
 * @since  2.0.0
 */
final readonly class TranslationGroupDeclaration implements ContributionDefinition
{
    /**
     * Declared locale tags, normalised, deduplicated and sorted so two orderings declare the same thing.
     *
     * @var    non-empty-list<string>
     * @since  2.0.0
     */
    public array $locales;

    /**
     * Locale served when the reader's language is missing or unpublished, in canonical form.
     *
     * @var    string
     * @since  2.0.0
     */
    public string $fallbackLocale;

    /**
     * Declare one content set, the languages it publishes in, and the language it falls back to.
     *
     * @param   string        $groupId   Namespaced identifier inside the declaring package's namespace.
     * @param   list<string>  $locales   Language tags this package is prepared to publish the set in.
     * @param   string        $fallback  Language served when the negotiated one has nothing published;
     *          must appear in the declared locales.
     *
     * @throws  InvalidArgumentException  When the identifier is not namespaced, the locale list is empty
     *          or past `TranslationGroup::MAXIMUM_MEMBERS`, a tag is malformed, or the fallback is not
     *          one of the declared locales.
     *
     * @since   2.0.0
     */
    public function __construct(
        private string $groupId,
        array $locales,
        string $fallback,
    ) {
        if (preg_match('/^[a-z][a-z0-9]*(?:[._-][a-z0-9]+){1,15}$/D', $groupId) !== 1) {
            throw new InvalidArgumentException('A content translation group identifier must be namespaced.');
        }
        if ($locales === [] || count($locales) > TranslationGroup::MAXIMUM_MEMBERS) {
            throw new InvalidArgumentException(
                'A content translation group must declare between one and 64 locales.',
            );
        }
        $normalized = [];
        foreach ($locales as $locale) {
            $normalized[] = $this->tag($locale);
        }
        $normalized = array_values(array_unique($normalized));
        sort($normalized, SORT_STRING);
        /** @var non-empty-list<string> $normalized */
        $this->locales = $normalized;
        $this->fallbackLocale = $this->tag($fallback);
        if (!in_array($this->fallbackLocale, $this->locales, true)) {
            throw new InvalidArgumentException(
                'A content translation group fallback must be one of its declared locales.',
            );
        }
    }

    /**
     * The identifier this content set is registered and reconciled under.
     *
     * @return  string  Namespaced content-set identity, inside the declaring package's namespace.
     *
     * @since   2.0.0
     */
    public function identifier(): string
    {
        return $this->groupId;
    }

    /**
     * Whether this package promised to publish the set in a given language.
     *
     * @param   LocaleTag  $locale  Locale a caller is looking for.
     *
     * @return  bool  True only when the exact tag was declared.
     *
     * @since   2.0.0
     */
    public function publishes(LocaleTag $locale): bool
    {
        return in_array($locale->toString(), $this->locales, true);
    }

    /**
     * Serialize the declaration for the signed manifest, the runtime publication, and inventory.
     *
     * @return  array{group_id: string, locales: non-empty-list<string>, fallback_locale: string}
     *          Canonical declaration.
     *
     * @since   2.0.0
     */
    public function toArray(): array
    {
        return [
            'group_id' => $this->groupId,
            'locales' => $this->locales,
            'fallback_locale' => $this->fallbackLocale,
        ];
    }

    /**
     * Reconstitute the declaration from validated manifest data.
     *
     * @param   array<string, mixed>  $data  Declaration as `toArray()` produced it.
     *
     * @return  self  Validated translation-group declaration.
     *
     * @throws  InvalidArgumentException  When a member is missing, extra, or mistyped.
     *
     * @since   2.0.0
     */
    public static function fromArray(array $data): self
    {
        $expected = ['group_id', 'locales', 'fallback_locale'];
        if (array_diff($expected, array_keys($data)) !== [] || array_diff(array_keys($data), $expected) !== []) {
            throw new InvalidArgumentException(
                'A content translation group declaration must carry exactly its members.',
            );
        }
        $groupId = $data['group_id'];
        $locales = $data['locales'];
        $fallback = $data['fallback_locale'];
        if (!is_string($groupId) || !is_array($locales) || !array_is_list($locales) || !is_string($fallback)) {
            throw new InvalidArgumentException(
                'A content translation group declaration member has the wrong type.',
            );
        }
        $tags = [];
        foreach ($locales as $locale) {
            if (!is_string($locale)) {
                throw new InvalidArgumentException('A content translation group locale must be a string.');
            }
            $tags[] = $locale;
        }

        return new self($groupId, $tags, $fallback);
    }

    /**
     * Reduce one declared language tag to its canonical form.
     *
     * @param   string  $locale  Tag exactly as the manifest declared it.
     *
     * @return  string  The normalised tag, such as `pt-BR`.
     *
     * @throws  InvalidArgumentException  When the value is not a well-formed language tag.
     *
     * @since   2.0.0
     */
    private function tag(string $locale): string
    {
        try {
            return LocaleTag::fromString($locale)->toString();
        } catch (InvalidLocaleTag $exception) {
            throw new InvalidArgumentException(
                'A content translation group locale is not a language tag.',
                0,
                $exception,
            );
        }
    }
}
