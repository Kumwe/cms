<?php

declare(strict_types=1);

namespace Kumwe\App\Content\Domain;

use DateTimeImmutable;
use Kumwe\Localization\Domain\InvalidLocaleTag;
use Kumwe\Localization\Domain\LocaleTag;
use Ramsey\Uuid\Uuid;

/**
 * One logical item across every language it exists in, with one entry per locale and a declared fallback.
 *
 * This is the whole of the content half of the multilingual model. A group holds one member per locale;
 * each member is a real content entry with its own slug, its own workflow state and its own publication
 * window, so English going live while German is still drafting is the ordinary case rather than a
 * special one. The declared fallback is the locale a reader is served when the language they negotiated
 * has no member, or has one that is not published yet — and because a fallback that names nothing is
 * worse than no fallback at all, construction refuses a group whose fallback locale has no member.
 *
 * Two invariants beyond that are enforced here rather than left to the store: a locale appears at most
 * once, because "one entry per locale" is what makes `resolve()` deterministic, and two members never
 * share a slug, because a single route segment that resolves to two languages of the same item is a
 * duplicate URL rather than a translation. The database carries both constraints as well; this class is
 * what a reader in memory can rely on.
 *
 * Nothing here decides which locale the request is in. That is `LocaleNegotiator`'s answer, arrived at
 * from the caller's explicit choice, its `Accept-Language` header and the site's `default_locale`, and
 * it is handed to `resolve()` so content and interface never disagree about the current language.
 *
 * @since  2.0.0
 */
final readonly class TranslationGroup
{
    /**
     * Locales one logical item may be carried in, which bounds both storage and the rendered selector.
     *
     * @var    int
     * @since  2.0.0
     */
    public const MAXIMUM_MEMBERS = 64;

    /**
     * Members keyed by the canonical string form of their locale, ordered by that key.
     *
     * Keying by locale is what proves "one entry per locale" at construction, and sorting means two
     * groups assembled from rows read in a different order compare and render identically.
     *
     * @var    array<string, TranslationGroupMember>
     * @since  2.0.0
     */
    private array $members;

    /**
     * Assemble a group and refuse one whose members contradict the delivery rules.
     *
     * @param   string                        $id              UUID identifying the logical item across locales.
     * @param   LocaleTag                     $fallbackLocale  Locale served when the negotiated one is missing
     *          or unpublished; must itself have a member.
     * @param   list<TranslationGroupMember>  $members         One entry per locale, at least one and at most 64.
     *
     * @throws  InvalidTranslationGroup  When the identifier is not a UUID, the member list is empty or past
     *          its ceiling, a locale or a slug is claimed twice, or the fallback locale has no member.
     *
     * @since   2.0.0
     */
    public function __construct(
        public string $id,
        public LocaleTag $fallbackLocale,
        array $members,
    ) {
        if (!Uuid::isValid($id)) {
            throw new InvalidTranslationGroup('A translation group identifier must be a canonical UUID.');
        }
        if ($members === [] || count($members) > self::MAXIMUM_MEMBERS) {
            throw new InvalidTranslationGroup('A translation group carries between one and 64 locales.');
        }
        $indexed = [];
        $slugs = [];
        foreach ($members as $member) {
            $locale = $member->locale->toString();
            if (isset($indexed[$locale])) {
                throw new InvalidTranslationGroup(
                    'A translation group carries at most one entry for locale ' . $locale . '.',
                );
            }
            if (isset($slugs[$member->slug])) {
                throw new InvalidTranslationGroup(
                    'Translation group slug ' . $member->slug . ' is claimed by two locales.',
                );
            }
            $indexed[$locale] = $member;
            $slugs[$member->slug] = true;
        }
        ksort($indexed, SORT_STRING);
        $this->members = $indexed;
        if (!isset($this->members[$fallbackLocale->toString()])) {
            throw new InvalidTranslationGroup(
                'A translation group fallback must name a locale the group carries.',
            );
        }
    }

    /**
     * Build the group a single untranslated entry stands in as.
     *
     * An item that exists in one language is still a group of one, which is what lets delivery treat
     * translated and untranslated content through the same path instead of branching on whether a
     * translation exists yet.
     *
     * @param   string                  $id      UUID identifying the logical item.
     * @param   TranslationGroupMember  $member  The single locale the item is written in.
     *
     * @return  self  A group whose only member is also its declared fallback.
     *
     * @throws  InvalidTranslationGroup  When the identifier is not a canonical UUID.
     *
     * @since   2.0.0
     */
    public static function ofOne(string $id, TranslationGroupMember $member): self
    {
        return new self($id, $member->locale, [$member]);
    }

    /**
     * Every locale of the item, in canonical locale order.
     *
     * @return  list<TranslationGroupMember>  Never empty; construction requires at least one member.
     *
     * @since   2.0.0
     */
    public function members(): array
    {
        return array_values($this->members);
    }

    /**
     * The member written in one exact locale, published or not.
     *
     * @param   LocaleTag  $locale  Locale to look for, compared after normalisation.
     *
     * @return  ?TranslationGroupMember  The member, or null when the item has no entry in that locale.
     *
     * @since   2.0.0
     */
    public function member(LocaleTag $locale): ?TranslationGroupMember
    {
        return $this->members[$locale->toString()] ?? null;
    }

    /**
     * The members a visitor may actually be sent to at a given moment.
     *
     * This is what `hreflang` is built from, which is why it filters rather than lists: advertising a
     * locale that is still drafting invites a search engine to index a page it cannot fetch.
     *
     * @param   DateTimeImmutable  $instant  Moment publication is judged at, usually now.
     *
     * @return  list<TranslationGroupMember>  Published members in canonical locale order; empty when the
     *          whole item is still unpublished.
     *
     * @since   2.0.0
     */
    public function publishedMembers(DateTimeImmutable $instant): array
    {
        return array_values(array_filter(
            $this->members,
            static fn (TranslationGroupMember $member): bool => $member->isVisibleAt($instant),
        ));
    }

    /**
     * Choose the member that best serves a negotiated locale at a given moment.
     *
     * Three steps, in order, and each one is a decision rather than a guess. An exact published member
     * wins. Failing that, a published member whose locale the negotiated one falls back through is used,
     * so a reader who asked for `pt-BR` is served `pt` before being sent to another language entirely.
     * Failing that the declared fallback is used, and only if it too is published — a fallback that is
     * still drafting is not a page anyone may see, so the answer is null and the caller decides whether
     * that is a miss or a redirect.
     *
     * @param   LocaleTag          $locale   Locale the request negotiated.
     * @param   DateTimeImmutable  $instant  Moment publication is judged at, usually now.
     *
     * @return  ?TranslationGroupMember  The member to serve, or null when nothing in the group is
     *          published at that instant.
     *
     * @since   2.0.0
     */
    public function resolve(LocaleTag $locale, DateTimeImmutable $instant): ?TranslationGroupMember
    {
        foreach ($locale->fallbacks() as $candidate) {
            try {
                $member = $this->member(LocaleTag::fromString($candidate));
            } catch (InvalidLocaleTag) {
                continue;
            }
            if ($member !== null && $member->isVisibleAt($instant)) {
                return $member;
            }
        }
        $declared = $this->members[$this->fallbackLocale->toString()] ?? null;

        return $declared !== null && $declared->isVisibleAt($instant) ? $declared : null;
    }

    /**
     * Whether the item carries more than the one language it was first written in.
     *
     * Delivery reads this to decide whether a language selector is worth rendering at all, so a site
     * that has never translated anything shows no selector rather than a selector of one.
     *
     * @return  bool  True when the group holds two or more locales.
     *
     * @since   2.0.0
     */
    public function isTranslated(): bool
    {
        return count($this->members) > 1;
    }
}
