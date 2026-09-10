<?php

declare(strict_types=1);

namespace Kumwe\App\Site\Application;

use Kumwe\App\Content\Application\ContentRecord;
use Kumwe\App\Content\Application\ContentService;
use Kumwe\App\Navigation\Application\PublicNavigation;
use Kumwe\App\Presentation\Application\SitePresentation;
use Kumwe\Context\Value\SiteContext;

/**
 * Two-way map between a public request path and the published content mounted at it.
 *
 * The public site has no fixed URL scheme: an operator decides what sits at `/` through the homepage
 * setting and what sits at every other path by placing menu items, so routing and link building have
 * to consult the same object or an internal link will disagree with the URL a visitor arrived on.
 * This is that object, and the handlers for `/` and the catch-all content route both go through it.
 * A path resolves through the primary menu first, then through the stable `/pages/{slug}` permalink
 * and the bare-slug fallback that predates managed navigation; paths under a reserved first segment
 * such as `/api` or `/administrator` are refused so content cannot shadow an application route, and a
 * path carrying traversal, an encoded slash or anything outside the lowercase slug grammar is rejected
 * before the store is touched. Every lookup runs through `ContentService` in this site's context, so
 * an unpublished record, or one belonging to another site, comes back as null rather than leaking.
 *
 * @since  2.0.0
 */
final readonly class PublicPageLocator
{
    /**
     * Bind the locator to the content store, settings and navigation of one site.
     *
     * @param  ContentService    $content     Publication-aware reader every resolution goes through.
     * @param  SiteSettings      $settings    Source of the homepage nomination and presentation contract.
     * @param  PublicNavigation  $navigation  Read-only menu tree mapping paths to content and back.
     * @param  SiteContext       $site        Site every lookup is scoped to.
     *
     * @since  2.0.0
     */
    public function __construct(
        private ContentService $content,
        private SiteSettings $settings,
        private PublicNavigation $navigation,
        private SiteContext $site,
    ) {
    }

    /**
     * Resolve the record an operator has mounted at the site root.
     *
     * The nominated `homepage_content_id` wins outright; the older `homepage_slug` is consulted only
     * when no id is stored, which is what keeps sites configured before the id setting working.
     *
     * @return  ?ContentRecord  The published homepage, or null when nothing is nominated or the
     *          nominated record is not publicly visible right now.
     *
     * @since   2.0.0
     */
    public function homepage(): ?ContentRecord
    {
        $settings = $this->settings->current();
        $contentId = $settings['homepage_content_id'] ?? null;
        if (is_string($contentId) && trim($contentId) !== '') {
            return $this->content->publishedById(trim($contentId), $this->site);
        }

        $legacySlug = $settings['homepage_slug'] ?? null;

        return is_string($legacySlug) && $legacySlug !== ''
            ? $this->content->publishedBySlug($legacySlug, $this->site)
            : null;
    }

    /**
     * Resolve an incoming request path to the record that should answer it.
     *
     * The order is deliberate: `/` is the homepage, `/pages/{slug}` is the permalink form that always
     * works, a menu item mounted at the path comes next, and only a single-segment path left over
     * falls back to a content slug. A reserved first segment is refused before any of that, and a path
     * the normaliser will not accept resolves to null rather than being cleaned up and retried.
     *
     * @param   string  $path  Request path as it arrived; query string, trailing slash and percent
     *          encoding are normalised away first.
     *
     * @return  ?ContentRecord  The published record for that path, or null when the path is malformed,
     *          reserved, or claims nothing publicly visible.
     *
     * @since   2.0.0
     */
    public function byPath(string $path): ?ContentRecord
    {
        $path = $this->normalizePath($path);
        if ($path === null) {
            return null;
        }
        if ($path === '/') {
            return $this->homepage();
        }

        if (preg_match('#^/pages/([a-z0-9]+(?:-[a-z0-9]+)*)$#D', $path, $matches) === 1) {
            return $this->content->publishedBySlug($matches[1], $this->site);
        }
        if ($this->hasReservedPrefix($path)) {
            return null;
        }

        $contentId = $this->navigation->contentIdForPath($path, $this->primaryMenu());
        if ($contentId !== null) {
            return $this->content->publishedById($contentId, $this->site);
        }

        $item = $this->itemAtPath($this->rawNavigation(), $path);
        if ($item !== null) {
            return $this->contentForItem($item);
        }

        if (preg_match('#^/([a-z0-9]+(?:-[a-z0-9]+)*)$#D', $path, $matches) !== 1) {
            return null;
        }

        return $this->content->publishedBySlug($matches[1], $this->site);
    }

    /**
     * Build the path this site publishes a record at.
     *
     * The link-building direction of `byPath()`. The homepage collapses to `/` so it is never linked
     * twice, a menu mount beats the record's own slug, and a record the navigation does not carry
     * falls back to `/{slug}`. The answer is a best effort, not a promise that a visitor can follow
     * it — `publicPathFor()` is the one that proves reachability.
     *
     * @param   ContentRecord  $record  Record to build a link to.
     *
     * @return  string  Absolute path beginning with `/`, url-encoded where it falls back to the slug.
     *
     * @since   2.0.0
     */
    public function pathFor(ContentRecord $record): string
    {
        $homepage = $this->homepage();
        if ($homepage !== null && $homepage->entry->id() === $record->entry->id()) {
            return '/';
        }

        $navigation = $this->rawNavigation();
        $path = $this->navigation->pathForContent($record->entry->id(), $this->primaryMenu())
            ?? $this->pathForContentId($navigation, $record->entry->id())
            ?? $this->pathForLegacySlug($navigation, $record->entry->slug());

        return $path ?? '/' . rawurlencode($record->entry->slug());
    }

    /**
     * Read the per-menu presentation binding of the item that publishes a record.
     *
     * The menu item is the seam where an operator overrides how one linked page presents: a bound
     * template wins over the content type's layout, and a bound colour scheme wins over the site's
     * active scheme, both only for that page. A record no menu publishes answers nulls, so callers
     * fall back to the type and site defaults without branching.
     *
     * @param   ContentRecord  $record  Record whose publishing item's binding is wanted.
     *
     * @return  array{template: ?string, color_scheme: ?string}  The overrides, null where unset.
     *
     * @since   2.0.0
     */
    public function presentationBindingFor(ContentRecord $record): array
    {
        return $this->navigation->bindingForContent($record->entry->id(), $this->primaryMenu());
    }

    /**
     * Build the path a record is reachable at, and prove that the path round-trips back to it.
     *
     * Administration screens use this to decide whether to offer a "view on site" link: the record has
     * to be published in this site's context, and resolving the path it maps to has to return that
     * same record, so a page shadowed by a menu item or by another record's slug honestly reports no
     * public path instead of offering a link that lands somewhere else.
     *
     * @param   ContentRecord  $record  Record whose public URL is wanted.
     *
     * @return  ?string  Absolute path a visitor can follow, or null when the record is not publicly
     *          reachable under it.
     *
     * @since   2.0.0
     */
    public function publicPathFor(ContentRecord $record): ?string
    {
        $published = $this->content->publishedById($record->entry->id(), $this->site);
        if ($published === null) {
            return null;
        }
        $path = $this->pathFor($published);
        $resolved = $this->byPath($path);

        return $resolved !== null && $resolved->entry->id() === $published->entry->id()
            ? $path
            : null;
    }

    /**
     * Build the navigation tree with every entry resolved to a link this site can actually serve.
     *
     * An item whose content is missing or no longer published is dropped along with the branch it
     * heads, rather than rendered as a dead link, and every surviving content item's `href` is
     * rewritten through `pathFor()` so the menu and the router agree on where a page lives.
     *
     * @return  list<array<string, mixed>>  Root items, each nesting the same shape under `children`.
     *
     * @since   2.0.0
     */
    public function navigation(): array
    {
        return $this->presentNavigation($this->rawNavigation());
    }

    /**
     * Fetch the navigation tree as the navigation component builds it, before any link rewriting.
     *
     * The homepage nomination is passed down so that items pointing at it already carry `/`, which is
     * what stops the front page appearing under two different URLs.
     *
     * @return  list<array<string, mixed>>  Root items of the primary menu, children nested under
     *          `children`; empty when the site has no readable menu.
     *
     * @since   2.0.0
     */
    private function rawNavigation(): array
    {
        $contentId = $this->settings->current()['homepage_content_id'] ?? null;

        return $this->navigation->items(
            is_string($contentId) && $contentId !== '' ? $contentId : null,
            $this->primaryMenu(),
        );
    }

    /**
     * Resolve the handle of the menu this site renders as its primary navigation.
     *
     * @return  string  Menu handle from the site's presentation contract, falling back to the shipped
     *          default when no presentation settings are stored.
     *
     * @since   2.0.0
     */
    private function primaryMenu(): string
    {
        return SitePresentation::from(
            $this->settings->current()['presentation'] ?? SitePresentation::defaults(),
        )->primaryMenu();
    }

    /**
     * Rewrite one level of the navigation tree so every content entry carries a servable link.
     *
     * Children are presented before their parent is judged, so an entry whose own target no longer
     * resolves takes its already-rewritten subtree with it. Entries with a non-content target — an
     * external URL or an anchor — are passed through untouched, keeping the `href` the navigation
     * component built for them.
     *
     * @param   list<array<string, mixed>>  $items  Raw navigation entries for one level of the tree.
     *
     * @return  list<array<string, mixed>>  The surviving entries, `href` and `children` rewritten.
     *
     * @since   2.0.0
     */
    private function presentNavigation(array $items): array
    {
        $presented = [];
        foreach ($items as $item) {
            $item['children'] = $this->presentNavigation($this->children($item));
            $targetType = $this->targetType($item);
            if ($targetType === 'content' || $targetType === null) {
                $record = $this->contentForItem($item);
                if ($record === null) {
                    continue;
                }
                $item['href'] = $this->pathFor($record);
            }
            $presented[] = $item;
        }

        return $presented;
    }

    /**
     * Search the tree depth first for the entry mounted at a path.
     *
     * Each candidate path is normalised before comparison, so an entry stored with a trailing slash or
     * percent encoding still matches the canonical path a request was reduced to.
     *
     * @param   list<array<string, mixed>>  $items  Entries to search, their children included.
     * @param   string                      $path   Already-normalised path to match against.
     *
     * @return  array<string, mixed>|null  The first entry claiming that path, or null when none does.
     *
     * @since   2.0.0
     */
    private function itemAtPath(array $items, string $path): ?array
    {
        foreach ($items as $item) {
            $candidate = $item['path'] ?? null;
            if (is_string($candidate) && $this->normalizePath($candidate) === $path) {
                return $item;
            }
            $children = $this->children($item);
            if ($children !== []) {
                $match = $this->itemAtPath($children, $path);
                if ($match !== null) {
                    return $match;
                }
            }
        }

        return null;
    }

    /**
     * Find the path a navigation entry mounts a given content record at.
     *
     * @param   list<array<string, mixed>>  $items      Entries to search, their children included.
     * @param   string                      $contentId  Identifier of the content to locate.
     *
     * @return  ?string  Normalised path of the first entry pointing at that content, or null when the
     *          tree does not publish it.
     *
     * @since   2.0.0
     */
    private function pathForContentId(array $items, string $contentId): ?string
    {
        foreach ($items as $item) {
            $path = $item['path'] ?? null;
            $normalizedPath = is_string($path) ? $this->normalizePath($path) : null;
            if ($normalizedPath !== null && $this->contentId($item) === $contentId) {
                return $normalizedPath;
            }
            $children = $this->children($item);
            if ($children !== []) {
                $childPath = $this->pathForContentId($children, $contentId);
                if ($childPath !== null) {
                    return $childPath;
                }
            }
        }

        return null;
    }

    /**
     * Find the path a slug-targeted navigation entry publishes a record at.
     *
     * Only entries that declare no target type qualify. Those are the rows written before navigation
     * gained explicit targets, which name a page by slug alone; matching them by slug here is what
     * keeps their links stable across the upgrade.
     *
     * @param   list<array<string, mixed>>  $items  Entries to search, their children included.
     * @param   string                      $slug   Content slug such an entry would carry.
     *
     * @return  ?string  Normalised path of the first matching entry, or null when none matches.
     *
     * @since   2.0.0
     */
    private function pathForLegacySlug(array $items, string $slug): ?string
    {
        foreach ($items as $item) {
            $path = $item['path'] ?? null;
            $normalizedPath = is_string($path) ? $this->normalizePath($path) : null;
            if (
                $normalizedPath !== null
                && $this->targetType($item) === null
                && ($item['slug'] ?? null) === $slug
            ) {
                return $normalizedPath;
            }
            $children = $this->children($item);
            if ($children !== []) {
                $childPath = $this->pathForLegacySlug($children, $slug);
                if ($childPath !== null) {
                    return $childPath;
                }
            }
        }

        return null;
    }

    /**
     * Resolve the published record a navigation entry points at.
     *
     * An entry targeting anything other than content addresses nothing this site can render, so it is
     * refused before the store is consulted. A content target is looked up by identifier, falling back
     * to the entry's slug for rows written before identifiers were stored.
     *
     * @param   array<string, mixed>  $item  Navigation entry to resolve.
     *
     * @return  ?ContentRecord  The published record, or null when the entry targets something else,
     *          names nothing, or names content that is not publicly visible.
     *
     * @since   2.0.0
     */
    private function contentForItem(array $item): ?ContentRecord
    {
        $targetType = $this->targetType($item);
        if ($targetType !== null && $targetType !== 'content') {
            return null;
        }
        $contentId = $this->contentId($item);
        if ($contentId !== null) {
            return $this->content->publishedById($contentId, $this->site);
        }
        $slug = $item['slug'] ?? null;

        return is_string($slug) && $slug !== ''
            ? $this->content->publishedBySlug($slug, $this->site)
            : null;
    }

    /**
     * Read the content identifier out of a navigation entry, whichever shape it uses.
     *
     * Entries reach this class from more than one producer, so `content_id`, the older `target_id`,
     * and the nested `target` map are each tried in turn before the entry is treated as naming no
     * content at all.
     *
     * @param   array<string, mixed>  $item  Navigation entry to inspect.
     *
     * @return  ?string  Trimmed identifier, or null when the entry names no content.
     *
     * @since   2.0.0
     */
    private function contentId(array $item): ?string
    {
        $target = $item['target'] ?? null;
        $value = $item['content_id'] ?? $item['target_id'] ?? null;
        if ($value === null && is_array($target)) {
            $value = $target['content_id'] ?? $target['id'] ?? null;
        }

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * Read what kind of thing a navigation entry points at, whichever shape it uses.
     *
     * @param   array<string, mixed>  $item  Navigation entry to inspect.
     *
     * @return  ?string  Lowercased target kind such as `content`, `anchor` or `url`; null when the
     *          entry declares none, which the callers read as a legacy content target.
     *
     * @since   2.0.0
     */
    private function targetType(array $item): ?string
    {
        $target = $item['target'] ?? null;
        $value = $item['target_type'] ?? null;
        if ($value === null && is_array($target)) {
            $value = $target['type'] ?? null;
        }

        return is_string($value) && $value !== '' ? strtolower($value) : null;
    }

    /**
     * Read the child entries of a navigation entry, discarding anything malformed.
     *
     * Navigation arrives as untyped data, so a `children` value that is not a list, and any child that
     * is not a string-keyed map, is dropped here rather than allowed to reach the tree walkers.
     *
     * @param   array<string, mixed>  $item  Navigation entry whose children are wanted.
     *
     * @return  list<array<string, mixed>>  Well-formed child entries; empty when there are none.
     *
     * @since   2.0.0
     */
    private function children(array $item): array
    {
        $children = $item['children'] ?? null;
        if (!is_array($children) || !array_is_list($children)) {
            return [];
        }
        $normalized = [];
        foreach ($children as $child) {
            if (is_array($child) && !array_is_list($child)) {
                /** @var array<string, mixed> $child */
                $normalized[] = $child;
            }
        }

        return $normalized;
    }

    /**
     * Reduce a path to the canonical form every lookup compares against, or refuse it outright.
     *
     * This is the guard that keeps hostile input away from the content store, and it rejects rather
     * than repairs: a query string and a trailing slash are dropped, but an encoded slash or
     * backslash, a doubled slash, a null byte, or a `.` or `..` segment ends the resolution. What
     * survives is a root-relative path of lowercase, hyphen-joined alphanumeric segments, which is the
     * only shape this site serves — so the same function safely normalises stored menu paths too.
     *
     * @param   string  $path  Raw path, as a request carried it or a navigation entry stored it.
     *
     * @return  ?string  The canonical path, or null when it is not a shape this site will serve.
     *
     * @since   2.0.0
     */
    private function normalizePath(string $path): ?string
    {
        $path = parse_url($path, PHP_URL_PATH);
        if (!is_string($path) || $path === '' || $path[0] !== '/') {
            return null;
        }
        if (preg_match('/%(?:2f|5c)/i', $path) === 1 || str_contains($path, '//')) {
            return null;
        }
        $decoded = rawurldecode($path);
        if (
            str_contains($decoded, "\0")
            || str_contains($decoded, '\\')
            || preg_match('#(?:^|/)(?:\.{1,2})(?:/|$)#D', $decoded) === 1
        ) {
            return null;
        }
        if ($decoded !== '/' && str_ends_with($decoded, '/')) {
            $decoded = rtrim($decoded, '/');
        }

        return preg_match('#^/(?:[a-z0-9]+(?:-[a-z0-9]+)*(?:/[a-z0-9]+(?:-[a-z0-9]+)*)*)?$#D', $decoded) === 1
            ? $decoded
            : null;
    }

    /**
     * Decide whether a path belongs to a route the application owns rather than to content.
     *
     * Content must never be able to shadow the administrator, API, MCP, media, asset, health or
     * permalink routes, so a first segment naming one of those ends resolution before any lookup.
     *
     * @param   string  $path  Normalised path whose first segment is inspected.
     *
     * @return  bool  True when the segment is reserved and the path must not resolve to content.
     *
     * @since   2.0.0
     */
    private function hasReservedPrefix(string $path): bool
    {
        $firstSegment = explode('/', ltrim($path, '/'), 2)[0] ?? '';

        return in_array($firstSegment, [
            'administrator',
            'api',
            'assets',
            'health',
            'mcp',
            'media',
            'pages',
        ], true);
    }
}
