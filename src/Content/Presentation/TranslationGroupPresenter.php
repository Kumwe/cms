<?php

declare(strict_types=1);

namespace Kumwe\App\Content\Presentation;

use Kumwe\App\Content\Application\ContentRecord;
use Kumwe\App\Content\Application\ContentService;
use Kumwe\App\Content\Application\TranslationGroupRepository;
use Kumwe\App\Content\Domain\TranslationGroup;
use Kumwe\App\Content\Domain\TranslationGroupMember;
use Kumwe\App\Localization\Application\ActiveLocale;
use Kumwe\App\Site\Application\PublicPageLocator;
use Kumwe\Context\Value\SiteContext;
use Locale;
use Psr\Clock\ClockInterface;

/**
 * Turns the translation group behind a page into its `hreflang` links and its language selector.
 *
 * Both of the things D12 asks to ship by default are one calculation, which is why they are one class:
 * the alternates a page advertises to a crawler and the choices it offers a reader are the same list —
 * exactly the locales the group publishes right now — differing only in how they are rendered. A locale
 * that is still drafting appears in neither, because advertising a page nobody may read is worse than
 * advertising nothing.
 *
 * The locale a reader is currently in is not recomputed here. It is read from `ActiveLocale`, which the
 * locale-negotiation middleware opened for this request from the caller's explicit choice, its
 * `Accept-Language` header and the site's `default_locale` setting, so the language the interface is
 * rendered in and the language the content is chosen in can never drift apart.
 *
 * `negotiate()` is deliberately narrow. A URL that names a locale is honoured as written — a reader who
 * followed a link to the German page gets the German page, whatever their browser prefers — so language
 * negotiation only decides what a *language-neutral* entry point serves, which on the public site means
 * the site root. That is also where the declared fallback earns its keep: a reader whose language the
 * item has not been translated into, or has been translated into but not yet published, is served the
 * fallback rather than a miss.
 *
 * Cost is bounded by the number of published locales, since each sibling is resolved through
 * `ContentService`. A locale-bearing page is located through `PublicPageLocator` so its link points at the
 * canonical path rather than at a permalink that redirects. The language-neutral root instead carries
 * each locale as the explicit `?locale=` choice the negotiation middleware already honours; otherwise the
 * nominated homepage member and the negotiated member can both collapse to `/`, making one language
 * impossible to choose. A page whose entry declares no group costs one query and renders nothing extra,
 * which is what an untranslated site pays.
 *
 * @since  2.0.0
 */
final readonly class TranslationGroupPresenter
{
    /**
     * Bind the presenter to the group store, the publication reader, link building and the request locale.
     *
     * @param  TranslationGroupRepository  $groups   Store the logical item's locales are read from.
     * @param  ContentService              $content  Publication-aware reader each sibling is loaded through.
     * @param  PublicPageLocator           $pages    Two-way path map used to build each locale's link.
     * @param  ActiveLocale                $active   Holder carrying the locale this request negotiated.
     * @param  ClockInterface              $clock    Source of the instant publication is judged at.
     * @param  SiteContext                 $site     Site every lookup is scoped to.
     *
     * @since  2.0.0
     */
    public function __construct(
        private TranslationGroupRepository $groups,
        private ContentService $content,
        private PublicPageLocator $pages,
        private ActiveLocale $active,
        private ClockInterface $clock,
        private SiteContext $site,
    ) {
    }

    /**
     * Choose which locale of a language-neutral entry point the reader is served.
     *
     * The negotiated locale wins when the item publishes it, the locales it falls back through are tried
     * next, and the group's declared fallback answers when none of those is published. An item with no
     * group, or one whose resolved locale is the record already in hand, is returned untouched, so a
     * caller can apply this unconditionally.
     *
     * @param   ContentRecord  $record  Record the entry point nominates, in whatever locale it was stored.
     *
     * @return  ContentRecord  The locale of the same logical item to render; the argument itself when the
     *          item is untranslated or no other locale is published.
     *
     * @since   2.0.0
     */
    public function negotiate(ContentRecord $record): ContentRecord
    {
        $group = $this->groups->forContent($this->site, $record->entry->id());
        if ($group === null) {
            return $record;
        }
        $member = $group->resolve($this->active->locale(), $this->clock->now());
        if ($member === null || $member->contentId === $record->entry->id()) {
            return $record;
        }

        return $this->content->publishedById($member->contentId, $this->site) ?? $record;
    }

    /**
     * Build the alternate-language view model a site layout renders in its head and its selector.
     *
     * `default_href` carries the group's declared fallback, which is what a page emits as
     * `hreflang="x-default"`: the standard's way of saying "serve this to a reader whose language is not
     * listed", and precisely what the declared fallback means. It is null when the fallback locale is not
     * itself published, because an unpublished page is not a safe default for anyone.
     *
     * @param   ContentRecord  $record         Record being rendered, whose group supplies the alternates.
     * @param   string         $canonicalPath  Path this record is already being served on, reused rather
     *          than rebuilt so the current entry never disagrees with the page's canonical URL. The
     *          language-neutral root gains an explicit locale query on each alternate.
     *
     * @return  array{
     *              alternates: list<array{
     *                  locale: string,
     *                  label: string,
     *                  href: string,
     *                  direction: string,
     *                  current: bool
     *              }>,
     *              default_href: ?string
     *          }  Published locales in canonical locale order, empty when the item is untranslated.
     *
     * @since   2.0.0
     */
    public function alternates(ContentRecord $record, string $canonicalPath): array
    {
        $group = $this->groups->forContent($this->site, $record->entry->id());
        if ($group === null || !$group->isTranslated()) {
            return ['alternates' => [], 'default_href' => null];
        }

        $alternates = [];
        $paths = [];
        foreach ($group->publishedMembers($this->clock->now()) as $member) {
            $href = $this->hrefFor($member, $record, $canonicalPath);
            if ($href === null) {
                continue;
            }
            $paths[$member->locale->toString()] = $href;
            $alternates[] = [
                'locale' => $member->locale->toString(),
                'label' => $this->label($member),
                'href' => $href,
                'direction' => $member->locale->direction()->value,
                'current' => $member->contentId === $record->entry->id(),
            ];
        }
        if (count($alternates) < 2) {
            return ['alternates' => [], 'default_href' => null];
        }

        return [
            'alternates' => $alternates,
            'default_href' => $paths[$group->fallbackLocale->toString()] ?? null,
        ];
    }

    /**
     * Build the public path one locale of the item is reachable at.
     *
     * @param   TranslationGroupMember  $member         Locale whose link is wanted.
     * @param   ContentRecord           $record         Record already being rendered.
     * @param   string                  $canonicalPath  Path that record is served on.
     *
     * @return  ?string  The canonical path for that locale, or an explicit locale choice at the
     *          language-neutral root; null when it is no longer reachable, which is how a locale that has
     *          just been unpublished drops out rather than being advertised as a dead link.
     *
     * @since   2.0.0
     */
    private function hrefFor(
        TranslationGroupMember $member,
        ContentRecord $record,
        string $canonicalPath,
    ): ?string {
        if ($member->contentId === $record->entry->id()) {
            return $canonicalPath === '/' ? $this->rootHrefFor($member) : $canonicalPath;
        }
        $sibling = $this->content->publishedById($member->contentId, $this->site);
        if ($sibling === null) {
            return null;
        }

        return $canonicalPath === '/'
            ? $this->rootHrefFor($member)
            : $this->pages->pathFor($sibling);
    }

    /**
     * Make one root alternate state the locale negotiation must honour.
     *
     * `/` deliberately names no language and is the only public path where negotiation may choose a
     * different member. Carrying the locale in the documented explicit-choice parameter makes every
     * alternate bookmarkable and prevents the reader's previous header preference from choosing again.
     *
     * @param   TranslationGroupMember  $member  Locale the root link must select explicitly.
     *
     * @return  string  Root path with the member's canonical locale tag as its explicit choice.
     *
     * @since   2.0.0
     */
    private function rootHrefFor(TranslationGroupMember $member): string
    {
        return '/?locale=' . rawurlencode($member->locale->toString());
    }

    /**
     * Name a language in that language, which is the only wording a selector may safely show.
     *
     * A language selector is the one control on a page that must not be translated into the language the
     * reader is currently in: a reader who cannot read the current language is exactly the reader
     * reaching for it, so each choice is written in its own language. That makes the label a proper name
     * rather than interface text, which is why it comes from ICU's locale data instead of from the
     * message catalogue.
     *
     * @param   TranslationGroupMember  $member  Locale whose own name is wanted.
     *
     * @return  string  The locale's endonym, or its canonical tag when ICU carries no name for it.
     *
     * @since   2.0.0
     */
    private function label(TranslationGroupMember $member): string
    {
        $tag = $member->locale->toString();
        $name = Locale::getDisplayName($tag, $tag);

        return $name === false || trim($name) === '' ? $tag : $name;
    }
}
