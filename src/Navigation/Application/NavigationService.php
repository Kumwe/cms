<?php

declare(strict_types=1);

namespace Kumwe\App\Navigation\Application;

use DateTimeImmutable;
use InvalidArgumentException;
use Kumwe\App\Application\Authorization\AuthorizationGateway;
use Kumwe\App\Application\Authorization\AuthorizationResource;
use Kumwe\App\Application\Authorization\ResourceSiteOwnershipWriter;
use Kumwe\App\Application\Persistence\TransactionManager;
use Kumwe\App\Audit\Application\AuditRecorder;
use Kumwe\App\Audit\Domain\AuditEvent;
use Kumwe\App\Content\Application\ContentService;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Extension\Spi\Identity\Domain\Capability;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;

/**
 * Management face of the navigation tree: the only supported way to change menus and their items.
 *
 * Every delivery surface that edits navigation — the HTTP API, the administrator screens, the console
 * command and the MCP tools — goes through this one service, so the rules live here once. It refuses
 * a caller who lacks `navigation.manage` on the resource, normalises and validates handles, slugs,
 * titles, positions and link targets, keeps `path` consistent by recomputing it and dragging the
 * moved subtree with it, and enforces optimistic locking against the version the caller read. Each
 * mutation runs in one transaction that also records site ownership for the new resource and writes
 * the audit event, so an authorization store can never end up describing a row that was rolled back.
 *
 * Reads are filtered rather than refused: listing returns only what the actor may see, while fetching
 * a named record the actor may not see is a denial, not an empty result.
 *
 * @since  2.0.0
 */
final readonly class NavigationService
{
    /**
     * Wire the service to its store, policy and bookkeeping collaborators.
     *
     * @param  NavigationRepository         $repository     Store menus and items are read from and written to.
     * @param  AuditRecorder                $audit          Sink every navigation mutation is recorded to.
     * @param  TransactionManager           $transactions   Scope that makes a write, its ownership row and
     *         its audit event succeed or fail together.
     * @param  ClockInterface               $clock          Source of the timestamps stored and audited.
     * @param  AuthorizationGateway         $authorization  Policy deciding who may manage which menu.
     * @param  ResourceSiteOwnershipWriter  $ownership      Registrar telling the authorization layer which
     *         site a newly created resource belongs to.
     * @param  ?ContentService              $content        Used to prove a content target exists and is
     *         readable; null skips that check.
     *
     * @since  2.0.0
     */
    public function __construct(
        private NavigationRepository $repository,
        private AuditRecorder $audit,
        private TransactionManager $transactions,
        private ClockInterface $clock,
        private AuthorizationGateway $authorization,
        private ResourceSiteOwnershipWriter $ownership,
        private ?ContentService $content = null,
    ) {
    }

    /**
     * List the menus this actor is allowed to manage.
     *
     * Menus the actor cannot reach are filtered out rather than refused, so an administrator listing
     * screen shows a short list instead of an error when their grants cover only part of the site.
     *
     * @param   ExecutionContext  $context  Actor and site the listing runs as.
     *
     * @return  list<MenuRecord>  The visible menus, empty when the actor may manage none.
     *
     * @since   2.0.0
     */
    public function menus(ExecutionContext $context): array
    {
        return array_values(array_filter(
            $this->repository->menus(),
            fn (MenuRecord $menu): bool => $this->authorization->decide(
                $context,
                Capability::fromString('navigation.manage'),
                AuthorizationResource::item('menu', $menu->id),
            )->allowed,
        ));
    }

    /**
     * Load one menu the actor is allowed to manage.
     *
     * @param   ExecutionContext  $context  Actor and site the read runs as.
     * @param   string            $id       UUID of the menu to load.
     *
     * @return  MenuRecord  The stored menu.
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor may not manage it.
     * @throws  NavigationNotFound  When no menu carries that identifier.
     *
     * @since   2.0.0
     */
    public function menu(ExecutionContext $context, string $id): MenuRecord
    {
        $this->authorize($context, AuthorizationResource::item('menu', $id));
        return $this->repository->menu($id) ?? throw new NavigationNotFound('The menu does not exist.');
    }

    /**
     * List the items of one menu that this actor is allowed to manage.
     *
     * Access to the menu is proved first, so an actor with no reach into the menu is refused rather
     * than handed an empty list; individual items they cannot manage are then filtered out silently.
     *
     * @param   ExecutionContext  $context  Actor and site the listing runs as.
     * @param   string            $menuId   UUID of the menu whose items are wanted.
     *
     * @return  list<MenuItemRecord>  The visible items, ordered by path so parents precede children.
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the menu is out of reach.
     * @throws  NavigationNotFound  When no menu carries that identifier.
     *
     * @since   2.0.0
     */
    public function items(ExecutionContext $context, string $menuId): array
    {
        $this->menu($context, $menuId);

        return array_values(array_filter(
            $this->repository->items($menuId),
            fn (MenuItemRecord $item): bool => $this->authorization->decide(
                $context,
                Capability::fromString('navigation.manage'),
                AuthorizationResource::item('menu_item', $item->id),
            )->allowed,
        ));
    }

    /**
     * Load one menu item the actor is allowed to manage.
     *
     * @param   ExecutionContext  $context  Actor and site the read runs as.
     * @param   string            $id       UUID of the menu item to load.
     *
     * @return  MenuItemRecord  The stored item, including the version a later write must quote back.
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor may not manage it.
     * @throws  NavigationNotFound  When no item carries that identifier.
     *
     * @since   2.0.0
     */
    public function item(ExecutionContext $context, string $id): MenuItemRecord
    {
        $this->authorize($context, AuthorizationResource::item('menu_item', $id));
        return $this->repository->item($id) ?? throw new NavigationNotFound('The menu item does not exist.');
    }

    /**
     * Create an empty menu owned by the acting site.
     *
     * Insert, site-ownership registration and audit entry share one transaction, so a menu never
     * becomes visible to the authorization layer without the row it describes actually existing.
     *
     * @param   ExecutionContext  $context  Actor and site the new menu is created under.
     * @param   string            $handle   Name a theme or setting will refer to the menu by.
     * @param   string            $title    Human-readable label shown to operators.
     *
     * @return  MenuRecord  The stored menu, at version 1.
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor may not create menus.
     * @throws  InvalidArgumentException  When the handle or title is not in an acceptable shape.
     *
     * @since   2.0.0
     */
    public function createMenu(ExecutionContext $context, string $handle, string $title): MenuRecord
    {
        $this->authorize($context, AuthorizationResource::collection('menu'));
        $handle = $this->handle($handle);
        $title = $this->title($title);
        $now = $this->clock->now();
        $menu = new MenuRecord(Uuid::uuid7()->toString(), $handle, $title, 1, $now, $now);

        return $this->transactions->transactional(function () use ($context, $menu, $now): MenuRecord {
            $this->repository->insertMenu($menu);
            $this->ownership->record(AuthorizationResource::item('menu', $menu->id), $context->site());
            $this->audit($context->actorId(), 'navigation.menu.create', 'menu', $menu->id, $now, ['version' => 1]);

            return $menu;
        });
    }

    /**
     * Rename a menu, provided the caller is working from the current version.
     *
     * Both fields are replaced, so a caller must send the values it wants kept as well as the ones it
     * is changing. Changing the handle re-points every site setting that names the menu by handle.
     *
     * @param   ExecutionContext  $context          Actor and site the write runs as.
     * @param   string            $id               UUID of the menu to update.
     * @param   int               $expectedVersion  Version the caller read; the stored menu must match.
     * @param   string            $handle           Replacement handle.
     * @param   string            $title            Replacement label.
     *
     * @return  MenuRecord  The stored menu, with its version incremented.
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor may not manage it.
     * @throws  NavigationNotFound  When no menu carries that identifier.
     * @throws  NavigationVersionConflict  When the stored menu has moved past the expected version.
     * @throws  InvalidArgumentException  When the handle or title is not in an acceptable shape.
     *
     * @since   2.0.0
     */
    public function updateMenu(
        ExecutionContext $context,
        string $id,
        int $expectedVersion,
        string $handle,
        string $title,
    ): MenuRecord {
        $this->authorize($context, AuthorizationResource::item('menu', $id));
        $stored = $this->menu($context, $id);
        $this->assertVersion($stored->version, $expectedVersion);
        $now = $this->clock->now();
        $updated = new MenuRecord(
            $stored->id,
            $this->handle($handle),
            $this->title($title),
            $stored->version + 1,
            $stored->createdAt,
            $now,
        );

        return $this->transactions->transactional(function () use (
            $context,
            $updated,
            $expectedVersion,
            $now,
        ): MenuRecord {
            $this->repository->updateMenu($updated, $expectedVersion);
            $this->audit($context->actorId(), 'navigation.menu.update', 'menu', $updated->id, $now, [
                'version' => $updated->version,
            ]);

            return $updated;
        });
    }

    /**
     * Delete a menu together with every item beneath it.
     *
     * The item identifiers are collected before the delete, because the stored cascade removes the rows
     * and their ownership records would otherwise be orphaned in the authorization store and could be
     * matched by a later resource reusing the identifier.
     *
     * @param   ExecutionContext  $context          Actor and site the delete runs as.
     * @param   string            $id               UUID of the menu to delete.
     * @param   int               $expectedVersion  Version the caller read; the stored menu must match.
     *
     * @return  void
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor may not manage it.
     * @throws  NavigationNotFound  When no menu carries that identifier.
     * @throws  NavigationVersionConflict  When the stored menu has moved past the expected version.
     *
     * @since   2.0.0
     */
    public function deleteMenu(ExecutionContext $context, string $id, int $expectedVersion): void
    {
        $this->authorize($context, AuthorizationResource::item('menu', $id));
        $stored = $this->menu($context, $id);
        $this->assertVersion($stored->version, $expectedVersion);
        $now = $this->clock->now();
        $this->transactions->transactional(function () use (
            $context,
            $id,
            $expectedVersion,
            $now,
        ): void {
            $itemIds = $this->repository->itemIdsForMenuDeletion($id, $expectedVersion);
            $this->repository->deleteMenu($id, $expectedVersion);
            foreach ($itemIds as $itemId) {
                $this->ownership->remove(
                    AuthorizationResource::item('menu_item', $itemId),
                    $context->site(),
                );
            }
            $this->ownership->remove(AuthorizationResource::item('menu', $id), $context->site());
            $this->audit($context->actorId(), 'navigation.menu.delete', 'menu', $id, $now);
        });
    }

    /**
     * Add an item to a menu, at a path derived from its parent and slug.
     *
     * The resolved path is checked against the reserved first segments the platform routes itself, so
     * an operator cannot shadow `/administrator` or `/api` with a menu entry. Omitting `$targetType`
     * entirely selects the legacy content item that carries no explicit target and is resolved by slug
     * at render time; passing `content` without a `$contentId` is rejected.
     *
     * @param   ExecutionContext  $context      Actor and site the new item is created under.
     * @param   string            $menuId       UUID of the menu to add the item to.
     * @param   ?string           $parentId     Parent item, or null to place the item at the menu root.
     * @param   string            $title        Label the navigation renders.
     * @param   string            $slug         URL segment the item contributes to its path.
     * @param   int               $position     Sort order among siblings; lower values render first.
     * @param   ?string           $targetType   `content`, `anchor` or `url`; null for a legacy content item.
     * @param   ?string           $contentId    Content to link to, required for an explicit content target.
     * @param   ?string           $targetUrl    Fragment for an anchor, or the link for a `url` target.
     * @param   ?string           $template     Site template overriding the linked page's type-declared
     *          layout; null or blank keeps the content type's decision.
     * @param   ?string           $colorScheme  Colour-scheme handle overriding the site's active scheme
     *          while the linked page renders; null or blank keeps the site's decision.
     *
     * @return  MenuItemRecord  The stored item, at version 1, with its path resolved.
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the menu is out of reach.
     * @throws  NavigationNotFound  When no menu carries that identifier.
     * @throws  InvalidArgumentException  When a field, the parent, the target or the resolved path is rejected.
     * @throws  \Kumwe\App\Content\Application\ContentNotFound  When the content target does not exist.
     *
     * @since   2.0.0
     */
    public function createItem(
        ExecutionContext $context,
        string $menuId,
        ?string $parentId,
        string $title,
        string $slug,
        int $position,
        ?string $targetType = null,
        ?string $contentId = null,
        ?string $targetUrl = null,
        ?string $template = null,
        ?string $colorScheme = null,
    ): MenuItemRecord {
        $this->authorize($context, AuthorizationResource::item('menu', $menuId));
        $this->menu($context, $menuId);
        $template = $this->presentationHandle($template, 'template');
        $colorScheme = $this->presentationHandle($colorScheme, 'color scheme');
        $slug = $this->slug($slug);
        $position = $this->position($position);
        $legacyUntargetedContent = $targetType === null;
        [$targetType, $contentId, $targetUrl] = $this->target(
            $context,
            $targetType ?? 'content',
            $contentId,
            $targetUrl,
            $legacyUntargetedContent,
        );
        $path = $this->repository->pathForParent($menuId, $parentId, $slug);
        $this->assertPublicPath($path);
        $now = $this->clock->now();
        $item = new MenuItemRecord(
            Uuid::uuid7()->toString(),
            $menuId,
            $parentId,
            $this->title($title),
            $slug,
            $path,
            $position,
            1,
            $now,
            $now,
            $targetType,
            $contentId,
            $targetUrl,
            $template,
            $colorScheme,
        );

        return $this->transactions->transactional(function () use ($context, $item, $now): MenuItemRecord {
            $this->repository->insertItem($item);
            $this->ownership->record(AuthorizationResource::item('menu_item', $item->id), $context->site());
            $this->audit(
                $context->actorId(),
                'navigation.item.create',
                'menu_item',
                $item->id,
                $now,
                ['path' => $item->path],
            );

            return $item;
        });
    }

    /**
     * Rewrite a menu item, moving its subtree when the change moves the item.
     *
     * A move is rejected before anything is written if it would make the item its own ancestor, and
     * every descendant path the move would produce is checked against the reserved system prefixes, so
     * a branch cannot be dragged onto a routed path by moving its root. When the path really does
     * change, the descendants are rewritten in the same transaction and their versions bumped, which
     * invalidates any stale copy of a child an editor is holding.
     *
     * Passing null for `$targetType` keeps the item's stored target untouched rather than clearing it.
     *
     * @param   ExecutionContext  $context          Actor and site the write runs as.
     * @param   string            $id               UUID of the item to update.
     * @param   int               $expectedVersion  Version the caller read; the stored item must match.
     * @param   ?string           $parentId         New parent, or null to move the item to the root.
     * @param   string            $title            Replacement label.
     * @param   string            $slug             Replacement URL segment.
     * @param   int               $position         Replacement sort order among siblings.
     * @param   ?string           $targetType       `content`, `anchor` or `url`; null keeps the stored target.
     * @param   ?string           $contentId        Replacement content target, ignored when type is null.
     * @param   ?string           $targetUrl        Replacement fragment or link, ignored when type is null.
     * @param   ?string           $template         Replacement layout override; null or blank clears it so
     *          the content type decides again.
     * @param   ?string           $colorScheme      Replacement colour-scheme override; null or blank clears
     *          it so the site's active scheme applies.
     *
     * @return  MenuItemRecord  The stored item, with its version incremented and its path resolved.
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor may not manage it.
     * @throws  NavigationNotFound  When no item carries that identifier.
     * @throws  NavigationVersionConflict  When the stored item has moved past the expected version.
     * @throws  InvalidArgumentException  When a field, the move, the target or a resulting path is rejected.
     * @throws  \Kumwe\App\Content\Application\ContentNotFound  When the content target does not exist.
     *
     * @since   2.0.0
     */
    public function updateItem(
        ExecutionContext $context,
        string $id,
        int $expectedVersion,
        ?string $parentId,
        string $title,
        string $slug,
        int $position,
        ?string $targetType = null,
        ?string $contentId = null,
        ?string $targetUrl = null,
        ?string $template = null,
        ?string $colorScheme = null,
    ): MenuItemRecord {
        $this->authorize($context, AuthorizationResource::item('menu_item', $id));
        $stored = $this->item($context, $id);
        $this->assertVersion($stored->version, $expectedVersion);
        $slug = $this->slug($slug);
        $template = $this->presentationHandle($template, 'template');
        $colorScheme = $this->presentationHandle($colorScheme, 'color scheme');
        [$targetType, $contentId, $targetUrl] = $this->target(
            $context,
            $targetType ?? $stored->targetType,
            $targetType === null ? $stored->contentId : $contentId,
            $targetType === null ? $stored->targetUrl : $targetUrl,
            $targetType === null,
        );
        $this->repository->assertMoveIsAcyclic($id, $stored->menuId, $parentId);
        $path = $this->repository->pathForParent($stored->menuId, $parentId, $slug);
        $this->assertPublicPath($path);
        if ($stored->path !== $path) {
            foreach ($this->repository->items($stored->menuId) as $candidate) {
                if (str_starts_with($candidate->path, $stored->path . '/')) {
                    $this->assertPublicPath($path . substr($candidate->path, strlen($stored->path)));
                }
            }
        }
        $now = $this->clock->now();
        $updated = new MenuItemRecord(
            $stored->id,
            $stored->menuId,
            $parentId,
            $this->title($title),
            $slug,
            $path,
            $this->position($position),
            $stored->version + 1,
            $stored->createdAt,
            $now,
            $targetType,
            $contentId,
            $targetUrl,
            $template,
            $colorScheme,
        );

        return $this->transactions->transactional(function () use (
            $context,
            $updated,
            $expectedVersion,
            $stored,
            $now,
        ): MenuItemRecord {
            $this->repository->updateItem($updated, $expectedVersion);
            if ($stored->path !== $updated->path) {
                $this->repository->moveDescendantPaths($updated->id, $stored->path, $updated->path, $now);
            }
            $this->audit($context->actorId(), 'navigation.item.update', 'menu_item', $updated->id, $now, [
                'path' => $updated->path,
                'version' => $updated->version,
            ]);

            return $updated;
        });
    }

    /**
     * Delete a menu item together with the branch beneath it.
     *
     * Descendants go with the item through the stored cascade, so a caller wanting to keep them must
     * reparent them first.
     *
     * @param   ExecutionContext  $context          Actor and site the delete runs as.
     * @param   string            $id               UUID of the item to delete.
     * @param   int               $expectedVersion  Version the caller read; the stored item must match.
     *
     * @return  void
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor may not manage it.
     * @throws  NavigationNotFound  When no item carries that identifier.
     * @throws  NavigationVersionConflict  When the stored item has moved past the expected version.
     *
     * @since   2.0.0
     */
    public function deleteItem(ExecutionContext $context, string $id, int $expectedVersion): void
    {
        $this->authorize($context, AuthorizationResource::item('menu_item', $id));
        $stored = $this->item($context, $id);
        $this->assertVersion($stored->version, $expectedVersion);
        $now = $this->clock->now();
        $this->transactions->transactional(function () use ($context, $id, $expectedVersion, $now): void {
            $this->repository->deleteItem($id, $expectedVersion);
            $this->ownership->remove(AuthorizationResource::item('menu_item', $id), $context->site());
            $this->audit($context->actorId(), 'navigation.item.delete', 'menu_item', $id, $now);
        });
    }

    /**
     * Normalise and validate a menu handle.
     *
     * @param   string  $handle  Operator-supplied handle, trimmed and lowercased before checking.
     *
     * @return  string  The normalised handle, safe to store and to name in a presentation contract.
     *
     * @throws  InvalidArgumentException  When the handle is empty, over-long, or uses other characters.
     *
     * @since   2.0.0
     */
    private function handle(string $handle): string
    {
        $handle = strtolower(trim($handle));
        if (preg_match('/^[a-z][a-z0-9_]{0,99}$/D', $handle) !== 1) {
            throw new InvalidArgumentException('A menu handle must use lowercase letters, digits and underscores.');
        }

        return $handle;
    }

    /**
     * Demand `navigation.manage` on one resource before the caller goes any further.
     *
     * Every public entry point begins here, which is what keeps the capability name in a single place
     * and makes an unauthorized call fail before it can read or write anything.
     *
     * @param   ExecutionContext       $context   Actor and site the operation runs as.
     * @param   AuthorizationResource  $resource  Menu, item, or the menu collection being acted on.
     *
     * @return  void
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When policy refuses the actor.
     *
     * @since   2.0.0
     */
    private function authorize(ExecutionContext $context, AuthorizationResource $resource): void
    {
        $this->authorization->assertAllowed(
            $context,
            Capability::fromString('navigation.manage'),
            $resource,
        );
    }

        /**
         * Normalise an optional presentation-binding handle to the lowercase grammar or to null.
         *
         * The grammar matches template names and colour-scheme handles alike, and a blank submission
         * clears the binding rather than storing an empty string, so "no override" has exactly one
         * stored representation. Existence is deliberately not checked here: a handle naming a template
         * or scheme that later disappears must degrade to the type or site default at render time, not
         * strand the menu item as unwritable.
         *
         * @param   ?string  $handle  Operator-supplied handle, or null when no override is offered.
         * @param   string   $label   Field name used in the rejection message.
         *
         * @return  ?string  The normalised handle, or null when the binding is absent or cleared.
         *
         * @throws  InvalidArgumentException  When a non-blank handle falls outside the grammar.
         *
         * @since   2.0.0
         */
    private function presentationHandle(?string $handle, string $label): ?string
    {
        if ($handle === null) {
            return null;
        }
        $handle = strtolower(trim($handle));
        if ($handle === '') {
            return null;
        }
        if (preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $handle) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'The menu item %s must be a lowercase handle such as landing or corporate.',
                $label,
            ));
        }

        return $handle;
    }

    /**
     * Normalise and validate the URL segment an item contributes to its path.
     *
     * The accepted shape is deliberately narrow — lowercase alphanumeric groups joined by single
     * hyphens — so that a stored path needs no encoding and cannot smuggle a separator, a traversal
     * step or a query into the route the public site matches on.
     *
     * @param   string  $slug  Operator-supplied segment, trimmed and lowercased before checking.
     *
     * @return  string  The normalised segment, safe to concatenate into a path.
     *
     * @throws  InvalidArgumentException  When the segment is malformed or longer than 160 bytes.
     *
     * @since   2.0.0
     */
    private function slug(string $slug): string
    {
        $slug = strtolower(trim($slug));
        if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug) !== 1 || strlen($slug) > 160) {
            throw new InvalidArgumentException('A menu item slug must be a safe lowercase URL segment.');
        }

        return $slug;
    }

    /**
     * Normalise and validate a menu or item label.
     *
     * The length ceiling counts characters rather than bytes, so a title in a non-Latin script gets the
     * same allowance as an ASCII one.
     *
     * @param   string  $title  Operator-supplied label, trimmed before checking.
     *
     * @return  string  The trimmed label.
     *
     * @throws  InvalidArgumentException  When the label is blank or exceeds 255 characters.
     *
     * @since   2.0.0
     */
    private function title(string $title): string
    {
        $title = trim($title);
        if ($title === '' || mb_strlen($title) > 255) {
            throw new InvalidArgumentException('A navigation title must contain 1 to 255 characters.');
        }

        return $title;
    }

    /**
     * Validate the sort order an item claims among its siblings.
     *
     * Duplicates are allowed; the public renderer breaks a tie on title so the order stays stable.
     *
     * @param   int  $position  Operator-supplied sort order.
     *
     * @return  int  The accepted position, unchanged.
     *
     * @throws  InvalidArgumentException  When the position is negative.
     *
     * @since   2.0.0
     */
    private function position(int $position): int
    {
        if ($position < 0) {
            throw new InvalidArgumentException('A menu item position cannot be negative.');
        }

        return $position;
    }

    /**
     * Normalise a link target and reject any combination that does not make sense.
     *
     * The three stored columns are one discriminated value, so this is where that invariant is
     * enforced: a content target names content and no URL, an anchor names a safe fragment, and a URL
     * target names a link but no content. A content reference is additionally proved to exist and to be
     * readable by this actor, which stops a menu from advertising a page the operator cannot see —
     * unless no `ContentService` was wired, in which case only the identifier's shape is checked.
     *
     * @param   ExecutionContext  $context                 Actor the content reference is resolved as.
     * @param   string            $targetType              Requested type, trimmed and lowercased.
     * @param   ?string           $contentId               Content reference, or null when there is none.
     * @param   ?string           $targetUrl               Fragment or link, or null when there is none.
     * @param   bool              $allowUntargetedContent  Whether a legacy content target may omit it.
     *
     * @return  array{string, ?string, ?string}  Normalised type, content reference and link, in the
     *          order `MenuItemRecord` takes them.
     *
     * @throws  InvalidArgumentException  When the type is unknown, the combination is contradictory, or
     *          the fragment, URL or content identifier is unsafe.
     * @throws  \Kumwe\App\Content\Application\ContentNotFound  When the referenced content does not exist.
     *
     * @since   2.0.0
     */
    private function target(
        ExecutionContext $context,
        string $targetType,
        ?string $contentId,
        ?string $targetUrl,
        bool $allowUntargetedContent = false,
    ): array {
        $targetType = strtolower(trim($targetType));
        $contentId = $this->nullable($contentId);
        $targetUrl = $this->nullable($targetUrl);

        if (!in_array($targetType, ['content', 'anchor', 'url'], true)) {
            throw new InvalidArgumentException('A navigation target type must be content, anchor or url.');
        }
        if ($targetType === 'content' && $targetUrl !== null) {
            throw new InvalidArgumentException('A content navigation target cannot contain a target URL.');
        }
        if ($targetType === 'content' && $contentId === null && !$allowUntargetedContent) {
            throw new InvalidArgumentException('A content navigation target must reference content.');
        }
        if ($targetType === 'anchor') {
            if (
                $targetUrl === null
                || preg_match('/^#[A-Za-z][A-Za-z0-9._:-]{0,190}$/D', $targetUrl) !== 1
            ) {
                throw new InvalidArgumentException('An anchor navigation target must contain a safe fragment.');
            }
        }
        if ($targetType === 'url') {
            if ($contentId !== null) {
                throw new InvalidArgumentException('A URL navigation target cannot reference content.');
            }
            if ($targetUrl === null || !$this->safeUrl($targetUrl)) {
                throw new InvalidArgumentException('A URL navigation target must contain a safe URL.');
            }
        }
        if ($contentId !== null) {
            if (
                preg_match(
                    '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD',
                    $contentId,
                ) !== 1
            ) {
                throw new InvalidArgumentException('A navigation content target must be a canonical UUID.');
            }
            $this->content?->get($context, $contentId);
        }

        return [$targetType, $contentId, $targetUrl];
    }

    /**
     * Collapse a blank optional field to null.
     *
     * Delivery surfaces send an empty string where a form field was left alone, and storing that would
     * make "cleared" and "never set" different values in the target columns.
     *
     * @param   ?string  $value  Raw optional field as it arrived.
     *
     * @return  ?string  The trimmed value, or null when it was absent or whitespace only.
     *
     * @since   2.0.0
     */
    private function nullable(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * Decide whether a stored link is safe to emit as an `href`.
     *
     * The allowed set is narrow on purpose: a root-relative path, an absolute `http`/`https` URL with a
     * host, or a `mailto:` with a valid address. Everything else is refused, which is what keeps a
     * `javascript:` or `data:` link out of a page that renders navigation without escaping the scheme.
     * Protocol-relative `//host` forms are rejected as well, since they inherit the visitor's scheme.
     *
     * @param   string  $url  Candidate link exactly as the operator supplied it.
     *
     * @return  bool  True when the link may be stored and rendered as-is.
     *
     * @since   2.0.0
     */
    private function safeUrl(string $url): bool
    {
        if (preg_match('/[\x00-\x20\x7f]/', $url) === 1 || str_contains($url, '\\')) {
            return false;
        }
        if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
            return true;
        }
        $scheme = parse_url($url, PHP_URL_SCHEME);
        if (!is_string($scheme)) {
            return false;
        }
        $scheme = strtolower($scheme);
        if ($scheme === 'mailto') {
            return filter_var(substr($url, 7), FILTER_VALIDATE_EMAIL) !== false;
        }
        if (!in_array($scheme, ['http', 'https'], true)) {
            return false;
        }

        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && is_string(parse_url($url, PHP_URL_HOST));
    }

    /**
     * Refuse a path that would collide with a route the platform owns.
     *
     * The public site falls back to navigation lookup for paths it does not otherwise recognise, so a
     * menu item mounted under a reserved first segment could shadow the administrator console, the API
     * or the media endpoints. Checking the first segment here keeps that decision in one place, and it
     * is applied to every descendant path a move would produce, not only the item being edited.
     *
     * @param   string  $path  Absolute path an item would occupy.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the path exceeds 512 bytes or begins with a reserved segment.
     *
     * @since   2.0.0
     */
    private function assertPublicPath(string $path): void
    {
        if (strlen($path) > 512) {
            throw new InvalidArgumentException('A navigation path cannot exceed 512 bytes.');
        }
        $firstSegment = explode('/', ltrim($path, '/'), 2)[0] ?? '';
        if (
            in_array($firstSegment, [
                'administrator',
                'api',
                'assets',
                'health',
                'mcp',
                'media',
                'pages',
            ], true)
        ) {
            throw new InvalidArgumentException('A navigation path cannot use a reserved system prefix.');
        }
    }

    /**
     * Refuse a write whose caller is not working from the record's current version.
     *
     * This is the early check made against the record just read; the repository repeats it inside the
     * write statement, which is what actually closes the race. An expected version below 1 is treated
     * as a conflict rather than a validation error, so a caller that omitted the field is told to
     * reload instead of being given a way to bypass the check.
     *
     * @param   int  $actual    Version the stored record currently carries.
     * @param   int  $expected  Version the caller quoted back.
     *
     * @return  void
     *
     * @throws  NavigationVersionConflict  When the versions differ or the expected version is not positive.
     *
     * @since   2.0.0
     */
    private function assertVersion(int $actual, int $expected): void
    {
        if ($expected < 1 || $actual !== $expected) {
            throw new NavigationVersionConflict('The navigation record changed; reload it and retry.');
        }
    }

    /**
     * Record one navigation mutation in the audit log.
     *
     * Always called from inside the mutation's transaction, so the trail and the change it describes
     * commit together. Only successful outcomes reach here — a refused or conflicting write throws
     * before this point and is recorded by the layer that refused it.
     *
     * @param   string                $actorId      Accountable actor taken from the execution context.
     * @param   string                $action       Dotted event name, such as `navigation.item.update`.
     * @param   string                $subjectType  Kind of record changed: `menu` or `menu_item`.
     * @param   string                $subjectId    UUID of the record changed.
     * @param   DateTimeImmutable     $at           Timestamp shared with the change itself.
     * @param   array<string, mixed>  $metadata     Extra detail worth keeping, such as the resulting
     *          version or the item's new path.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function audit(
        string $actorId,
        string $action,
        string $subjectType,
        string $subjectId,
        DateTimeImmutable $at,
        array $metadata = [],
    ): void {
        $this->audit->record(new AuditEvent(
            Uuid::uuid7()->toString(),
            $at,
            $actorId,
            $action,
            $subjectType,
            $subjectId,
            'success',
            $metadata,
        ));
    }
}
