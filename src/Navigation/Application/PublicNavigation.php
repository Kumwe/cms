<?php

declare(strict_types=1);

namespace Kumwe\App\Navigation\Application;

use Kumwe\App\Application\Authorization\AuthorizationResource;
use Kumwe\App\Application\Authorization\AuthorizationResourceOwnershipUnknown;
use Kumwe\App\Application\Authorization\ResourceSiteOwnership;
use Kumwe\Context\Value\SiteContext;

/**
 * Read-only view of a site's navigation, shaped for anonymous visitors.
 *
 * `NavigationService` is the management face of the same tables and demands a capability on every
 * call; this class is the rendering face and has no actor at all, so the only access rule it can
 * apply is site ownership — a menu or item owned by another site is skipped as though it did not
 * exist, and a resource whose owner cannot be established is skipped too rather than assumed public.
 * On top of that filter it does the work a template should not: it nests items under their parents,
 * sorts siblings deterministically, defends against a stored parent cycle, and resolves each item to
 * the `href` a visitor should follow, collapsing the homepage to `/` so it is never reachable twice.
 *
 * @since  2.0.0
 */
final readonly class PublicNavigation
{
    /**
     * Bind the navigation view to one site.
     *
     * @param  NavigationRepository    $repository       Store the menus and items are read from.
     * @param  ?ResourceSiteOwnership  $ownership        Resolver of which site owns a resource; null
     *         disables filtering, as single-site setups want.
     * @param  ?SiteContext            $site             Site whose navigation this is; null falls back
     *         to the default site.
     * @param  string                  $preferredHandle  Handle of the menu to render when a caller
     *         names none.
     *
     * @since  2.0.0
     */
    public function __construct(
        private NavigationRepository $repository,
        private ?ResourceSiteOwnership $ownership = null,
        private ?SiteContext $site = null,
        private string $preferredHandle = 'main',
    ) {
    }

    /**
     * Build the nested navigation tree a template renders.
     *
     * Siblings are ordered by position and then title, so two items sharing a position still come out
     * in the same order on every request. Each entry carries a ready-to-emit `href`: content targets
     * resolve through the menu's own paths, anchors are appended to the path of the content they
     * belong to, and `url` targets are passed through as stored.
     *
     * @param   ?string  $homepageContentId  Content mounted at `/`, so items pointing at it link to the
     *          site root instead of their own path; null when none is set.
     * @param   ?string  $preferredHandle    Menu to render, overriding the configured default.
     *
     * @return  list<array<string, mixed>>  Root items, each nesting the same shape under `children`;
     *          empty when the site has no readable menu.
     *
     * @since   2.0.0
     */
    public function items(?string $homepageContentId = null, ?string $preferredHandle = null): array
    {
        $menu = $this->publicMenu($preferredHandle);
        if (!$menu instanceof MenuRecord) {
            return [];
        }

        $byParent = [];
        $pathByContent = [];
        foreach ($this->repository->items($menu->id) as $item) {
            if (!$this->belongsToPublicSite('menu_item', $item->id)) {
                continue;
            }
            $byParent[$item->parentId ?? ''][] = $item;
            if ($item->targetType === 'content' && $item->contentId !== null) {
                $pathByContent[$item->contentId] ??= $item->path;
            }
        }
        foreach ($byParent as &$siblings) {
            usort($siblings, static fn (MenuItemRecord $left, MenuItemRecord $right): int => [
                $left->position,
                $left->title,
            ] <=> [
                $right->position,
                $right->title,
            ]);
        }
        unset($siblings);

        return $this->branch($byParent, null, [], $pathByContent, $homepageContentId);
    }

    /**
     * Resolve an incoming request path to the content mounted at it.
     *
     * This is the routing direction of the navigation tree: it is what lets an operator publish a page
     * at an arbitrary path by placing a menu item there, instead of the path being dictated by the
     * content's slug. Only `content` targets match; anchors and external links address nothing on this
     * site and are ignored.
     *
     * @param   string   $path             Request path to match; surrounding slashes are normalised away.
     * @param   ?string  $preferredHandle  Menu to search, overriding the configured default.
     *
     * @return  ?string  Identifier of the content at that path, or null when no readable item claims it.
     *
     * @since   2.0.0
     */
    public function contentIdForPath(string $path, ?string $preferredHandle = null): ?string
    {
        $menu = $this->publicMenu($preferredHandle);
        if (!$menu instanceof MenuRecord) {
            return null;
        }
        $path = '/' . trim($path, '/');
        foreach ($this->repository->items($menu->id) as $item) {
            if (
                $item->path === $path
                && $item->targetType === 'content'
                && $item->contentId !== null
                && $this->belongsToPublicSite('menu_item', $item->id)
            ) {
                return $item->contentId;
            }
        }

        return null;
    }

    /**
     * Read the presentation binding of the menu item that publishes a piece of content.
     *
     * A menu item may pin a site template and a colour scheme for the page it links; this returns
     * both, from the first item in stored order that targets the content — the same item every other
     * public resolution rule elects — or nulls when the content is unmounted or carries no override.
     *
     * @param   string   $contentId        Identifier of the content whose binding is wanted.
     * @param   ?string  $preferredHandle  Menu to search, overriding the configured default.
     *
     * @return  array{template: ?string, color_scheme: ?string}  The item's overrides, null when unset.
     *
     * @since   2.0.0
     */
    public function bindingForContent(string $contentId, ?string $preferredHandle = null): array
    {
        $menu = $this->publicMenu($preferredHandle);
        if ($menu instanceof MenuRecord) {
            foreach ($this->repository->items($menu->id) as $item) {
                if (
                    $item->targetType === 'content'
                    && $item->contentId === $contentId
                    && $this->belongsToPublicSite('menu_item', $item->id)
                ) {
                    return ['template' => $item->template, 'color_scheme' => $item->colorScheme];
                }
            }
        }

        return ['template' => null, 'color_scheme' => null];
    }

    /**
     * Resolve a piece of content to the path the navigation publishes it at.
     *
     * The inverse of `contentIdForPath()`, used when building a link to content rather than answering a
     * request for it, so an internal link and the URL a visitor arrived on agree. When the same content
     * is mounted more than once the first item in the menu's stored order wins.
     *
     * @param   string   $contentId        Identifier of the content to locate.
     * @param   ?string  $preferredHandle  Menu to search, overriding the configured default.
     *
     * @return  ?string  The item's absolute path, or null when the menu does not publish that content.
     *
     * @since   2.0.0
     */
    public function pathForContent(string $contentId, ?string $preferredHandle = null): ?string
    {
        $menu = $this->publicMenu($preferredHandle);
        if (!$menu instanceof MenuRecord) {
            return null;
        }
        foreach ($this->repository->items($menu->id) as $item) {
            if (
                $item->targetType === 'content'
                && $item->contentId === $contentId
                && $this->belongsToPublicSite('menu_item', $item->id)
            ) {
                return $item->path;
            }
        }

        return null;
    }

    /**
     * Emit one level of the tree and recurse into its children.
     *
     * The `$ancestors` guard makes a stored parent cycle survivable: an item already seen on the way
     * down is dropped rather than followed, so a corrupt tree costs a missing branch instead of an
     * exhausted stack while a visitor is waiting on a page.
     *
     * @param   array<string, list<MenuItemRecord>>  $byParent           Sorted siblings keyed by parent.
     * @param   ?string                              $parentId           Parent to emit; null for roots.
     * @param   array<string, true>                  $ancestors          Ids already on this branch.
     * @param   array<string, string>                $pathByContent      Menu path per content id.
     * @param   ?string                              $homepageContentId  Content at `/`, or null.
     *
     * @return  list<array<string, mixed>>  Entries for this level, each nesting its children the same way.
     *
     * @since   2.0.0
     */
    private function branch(
        array $byParent,
        ?string $parentId,
        array $ancestors,
        array $pathByContent,
        ?string $homepageContentId,
    ): array {
        $branch = [];
        foreach ($byParent[$parentId ?? ''] ?? [] as $item) {
            if (isset($ancestors[$item->id])) {
                continue;
            }
            $nextAncestors = $ancestors;
            $nextAncestors[$item->id] = true;
            $branch[] = [
                'id' => $item->id,
                'title' => $item->title,
                'target_type' => $item->targetType,
                'content_id' => $item->contentId,
                'target_url' => $item->targetUrl,
                'href' => $this->href($item, $pathByContent, $homepageContentId),
                'path' => $item->path,
                'children' => $this->branch(
                    $byParent,
                    $item->id,
                    $nextAncestors,
                    $pathByContent,
                    $homepageContentId,
                ),
            ];
        }
        return $branch;
    }

    /**
     * Work out the link one item should render as.
     *
     * An anchor is hung off the path of the content it names, so a fragment stays correct when the
     * visitor is on some other page. Anything that cannot be resolved falls back to the item's own
     * stored path, which keeps the link renderable even when its target has gone missing.
     *
     * @param   MenuItemRecord         $item               Item whose link is wanted.
     * @param   array<string, string>  $pathByContent      Menu path per content id, for content targets.
     * @param   ?string                $homepageContentId  Content mounted at `/`, or null when none is.
     *
     * @return  string  Absolute path, fragment-qualified path, or the stored external URL.
     *
     * @since   2.0.0
     */
    private function href(
        MenuItemRecord $item,
        array $pathByContent,
        ?string $homepageContentId,
    ): string {
        if ($item->targetType === 'url') {
            return $item->targetUrl ?? $item->path;
        }
        if ($item->targetType === 'anchor') {
            $fragment = $item->targetUrl ?? '';
            if ($item->contentId === null) {
                return $fragment === '' ? $item->path : $fragment;
            }
            $path = $item->contentId === $homepageContentId
                ? '/'
                : ($pathByContent[$item->contentId] ?? '');
            if ($path === '') {
                return $fragment;
            }

            return $path === '/' ? '/' . $fragment : rtrim($path, '/') . $fragment;
        }

        return $item->contentId !== null && $item->contentId === $homepageContentId
            ? '/'
            : ($item->contentId === null ? $item->path : ($pathByContent[$item->contentId] ?? $item->path));
    }

    /**
     * Pick the menu to render for this site.
     *
     * The preferred handle is a preference, not a requirement: when no menu carries it the first
     * readable menu is used instead, so a site whose operator has not yet named a primary menu still
     * renders navigation rather than nothing.
     *
     * @param   ?string  $preferredHandle  Handle to prefer, or null to use the configured default.
     *
     * @return  ?MenuRecord  The preferred menu, else the first menu this site owns, else null.
     *
     * @since   2.0.0
     */
    private function publicMenu(?string $preferredHandle = null): ?MenuRecord
    {
        $preferredHandle ??= $this->preferredHandle;
        $fallback = null;
        foreach ($this->repository->menus() as $candidate) {
            if (!$this->belongsToPublicSite('menu', $candidate->id)) {
                continue;
            }
            $fallback ??= $candidate;
            if ($candidate->handle === $preferredHandle) {
                return $candidate;
            }
        }

        return $fallback;
    }

    /**
     * Decide whether a navigation resource may be shown to this site's visitors.
     *
     * Unresolvable ownership is treated as "not ours", so a record left without an ownership row never
     * leaks across a site boundary by default. When no ownership resolver is wired at all the check is
     * skipped entirely, which is the single-site configuration.
     *
     * @param   string  $type  Resource type, either `menu` or `menu_item`.
     * @param   string  $id    UUID of the menu or item being considered.
     *
     * @return  bool  True when the resource belongs to this site, or when no resolver is configured.
     *
     * @since   2.0.0
     */
    private function belongsToPublicSite(string $type, string $id): bool
    {
        if ($this->ownership === null) {
            return true;
        }
        try {
            return $this->ownership->scopeFor(AuthorizationResource::item($type, $id))
                ->contains($this->site ?? SiteContext::default());
        } catch (AuthorizationResourceOwnershipUnknown) {
            return false;
        }
    }
}
