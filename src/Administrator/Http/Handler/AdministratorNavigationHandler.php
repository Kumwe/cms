<?php

declare(strict_types=1);

namespace Kumwe\App\Administrator\Http\Handler;

use InvalidArgumentException;
use Kumwe\App\Administrator\Http\AdministratorRequest;
use Kumwe\App\Administrator\Presentation\AdministratorRenderer;
use Kumwe\App\Content\Application\ContentRecord;
use Kumwe\App\Content\Application\ContentService;
use Kumwe\App\Navigation\Application\MenuItemRecord;
use Kumwe\App\Navigation\Application\MenuRecord;
use Kumwe\App\Navigation\Application\NavigationService;
use Kumwe\App\Presentation\Application\SitePresentation;
use Kumwe\App\Presentation\ContentLayoutCatalog;
use Kumwe\App\Site\Application\SiteSettings;
use Kumwe\Context\Value\ExecutionContext;
use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\Response\RedirectResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Serves the administrator navigation screen: every menu with its items, and every write posted back.
 *
 * A single path carries the whole menu editor, so the handler is a dispatcher over an `action` field
 * rather than a set of endpoints. Reordering is the reason it exists in this shape:
 * the screen submits the menu's complete item order as one list, and this handler turns that list
 * into the individual writes the navigation service accepts, refusing a list that does not account
 * for every item so a stale screen cannot drop one another editor just added.
 *
 * @since  2.0.0
 */
final readonly class AdministratorNavigationHandler implements RequestHandlerInterface
{
    /**
     * Wire the menu editor to the navigation service and to the pages it can link to.
     *
     * @param  NavigationService      $navigation  Reads menus and items, and performs every mutation.
     * @param  AdministratorRenderer  $renderer    Renders the `navigation` template.
     * @param  ?ContentService        $content     Supplies the pages offered as link targets; null offers none.
     * @param  ?SiteSettings          $settings    Supplies the colour schemes offered as per-item overrides;
     *         null offers none.
     *
     * @since  2.0.0
     */
    public function __construct(
        private NavigationService $navigation,
        private AdministratorRenderer $renderer,
        private ?ContentService $content = null,
        private ?SiteSettings $settings = null,
    ) {
    }

    /**
     * Render the navigation screen, or apply one posted mutation and redirect back to it.
     *
     * A write answers 303 to `/administrator/navigation?saved=1` rather than rendering, so the
     * browser re-reads the screen and a refresh cannot repost the form. The rendered response is
     * marked `no-store` because it embeds the session's CSRF token, and every menu's items are loaded
     * up front and keyed by menu id, so the template renders each tree without a second lookup.
     *
     * @param   ServerRequestInterface  $request  Administrator request; the method decides render or mutate.
     *
     * @return  ResponseInterface  The rendered screen for a read, or a 303 redirect back to it after a write.
     *
     * @throws  InvalidArgumentException  When a required field is missing, the action is unknown, or a submitted
     *          value is refused.
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor may not manage the menu.
     * @throws  \Kumwe\App\Navigation\Application\NavigationNotFound  When the named menu or item does not exist.
     * @throws  \Kumwe\App\Navigation\Application\NavigationVersionConflict  When another editor moved it on first.
     * @throws  \Kumwe\App\Content\Application\ContentNotFound  When a chosen page target no longer exists.
     *
     * @since   2.0.0
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $session = AdministratorRequest::session($request);
        if (strtoupper($request->getMethod()) === 'POST') {
            $form = AdministratorRequest::form($request);
            $this->mutate(AdministratorRequest::context($request), $form);

            return new RedirectResponse('/administrator/navigation?saved=1', 303);
        }

        $context = AdministratorRequest::context($request);
        $menus = $this->navigation->menus($context);
        $items = [];
        foreach ($menus as $menu) {
            $items[$menu->id] = array_map(
                static fn (MenuItemRecord $item): array => $item->toArray(),
                $this->navigation->items($context, $menu->id),
            );
        }

        return new HtmlResponse($this->renderer->render('navigation', [
            'csrf' => $session->csrfToken,
            'capabilities' => AdministratorRequest::capabilityMap($request),
            'menus' => array_map(static fn (MenuRecord $menu): array => $menu->toArray(), $menus),
            'items' => $items,
            'content_targets' => $this->content === null ? [] : array_map(
                static fn (ContentRecord $record): array => $record->toArray(),
                array_values($this->content->list($context, 500)),
            ),
            'layout_options' => ContentLayoutCatalog::handles(),
            'scheme_options' => $this->schemeOptions(),
            'saved' => ($request->getQueryParams()['saved'] ?? null) === '1',
        ]), 200, ['Cache-Control' => 'no-store']);
    }

    /**
     * List the colour schemes an item may bind, as handle-and-name pairs for the select.
     *
     * The list comes from the same validated presentation document the public site renders with, so
     * only a scheme that can actually take effect is offered. Without wired settings the list is
     * empty and the screen offers only the site default.
     *
     * @return  list<array{handle: string, name: string}>  Selectable schemes in stored order.
     *
     * @since   2.0.0
     */
    private function schemeOptions(): array
    {
        if ($this->settings === null) {
            return [];
        }
        $presentation = SitePresentation::from(
            $this->settings->current()['presentation'] ?? SitePresentation::defaults(),
        );
        $options = [];
        foreach ($presentation->schemeCatalog() as $scheme) {
            $options[] = ['handle' => $scheme['handle'], 'name' => $scheme['name']];
        }

        return $options;
    }

    /**
     * Apply the single navigation write the posted form names in its `action` field.
     *
     * `item.reorder` is handled before the match because it is the only action that rewrites several
     * items from one submission; every other action maps to exactly one navigation service call. An
     * unrecognised action is refused rather than silently ignored, so a stale or hand-edited form
     * reports a failure instead of appearing to have saved.
     *
     * @param   ExecutionContext       $context  Actor and site the write runs as.
     * @param   array<string, string>  $form     Flattened form carrying `action` and the fields it needs.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the action is unknown, a required field is missing, or a submitted
     *          value is refused.
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor may not manage the menu.
     * @throws  \Kumwe\App\Navigation\Application\NavigationNotFound  When the named menu or item does not exist.
     * @throws  \Kumwe\App\Navigation\Application\NavigationVersionConflict  When another editor moved it on first.
     * @throws  \Kumwe\App\Content\Application\ContentNotFound  When a chosen page target no longer exists.
     *
     * @since   2.0.0
     */
    private function mutate(ExecutionContext $context, array $form): void
    {
        $action = AdministratorRequest::required($form, 'action');
        if ($action === 'item.reorder') {
            $this->reorder(
                $context,
                AdministratorRequest::required($form, 'menu_id'),
                AdministratorRequest::required($form, 'order'),
            );

            return;
        }
        match ($action) {
            'menu.create' => $this->navigation->createMenu(
                $context,
                AdministratorRequest::required($form, 'handle'),
                AdministratorRequest::required($form, 'title'),
            ),
            'menu.update' => $this->navigation->updateMenu(
                $context,
                AdministratorRequest::required($form, 'id'),
                AdministratorRequest::positiveInteger($form, 'version'),
                AdministratorRequest::required($form, 'handle'),
                AdministratorRequest::required($form, 'title'),
            ),
            'menu.delete' => $this->navigation->deleteMenu(
                $context,
                AdministratorRequest::required($form, 'id'),
                AdministratorRequest::positiveInteger($form, 'version'),
            ),
            'item.create' => $this->navigation->createItem(
                $context,
                AdministratorRequest::required($form, 'menu_id'),
                $this->nullable($form, 'parent_id'),
                AdministratorRequest::required($form, 'title'),
                AdministratorRequest::required($form, 'slug'),
                $this->nonNegativeInteger($form, 'position'),
                $form['target_type'] ?? 'content',
                $this->nullable($form, 'content_id'),
                $this->nullable($form, 'target_url'),
                $this->nullable($form, 'template'),
                $this->nullable($form, 'color_scheme'),
            ),
            'item.update' => $this->navigation->updateItem(
                $context,
                AdministratorRequest::required($form, 'id'),
                AdministratorRequest::positiveInteger($form, 'version'),
                $this->nullable($form, 'parent_id'),
                AdministratorRequest::required($form, 'title'),
                AdministratorRequest::required($form, 'slug'),
                $this->nonNegativeInteger($form, 'position'),
                $form['target_type'] ?? 'content',
                $this->nullable($form, 'content_id'),
                $this->nullable($form, 'target_url'),
                $this->nullable($form, 'template'),
                $this->nullable($form, 'color_scheme'),
            ),
            'item.delete' => $this->navigation->deleteItem(
                $context,
                AdministratorRequest::required($form, 'id'),
                AdministratorRequest::positiveInteger($form, 'version'),
            ),
            default => throw new InvalidArgumentException('The navigation action is not supported.'),
        };
    }

    /**
     * Rewrite the sort positions of a menu's items from the order the screen submitted.
     *
     * The submitted list is checked against the menu as it is stored right now and must name every
     * item exactly once, so a drag on a screen opened before another editor added an item is refused
     * instead of quietly discarding that item. Items already sitting at their target position are
     * skipped, so only the rows that really moved consume a version.
     *
     * @param   ExecutionContext  $context  Actor and site the reorder runs as.
     * @param   string            $menuId   UUID of the menu whose items are being reordered.
     * @param   string            $order    Comma-separated item identifiers, first to last; blank segments are
     *          ignored.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the list repeats an item, does not cover the menu, or names an item
     *          that is not in it.
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor may not manage the menu.
     * @throws  \Kumwe\App\Navigation\Application\NavigationNotFound  When no menu carries that identifier.
     * @throws  \Kumwe\App\Navigation\Application\NavigationVersionConflict  When an item changes between the read
     *          and its write.
     *
     * @since   2.0.0
     */
    private function reorder(ExecutionContext $context, string $menuId, string $order): void
    {
        $identifiers = array_values(array_filter(
            array_map('trim', explode(',', $order)),
            static fn (string $identifier): bool => $identifier !== '',
        ));
        if (count($identifiers) !== count(array_unique($identifiers))) {
            throw new InvalidArgumentException('The menu item order contains duplicate items.');
        }
        $current = $this->navigation->items($context, $menuId);
        $byId = [];
        foreach ($current as $item) {
            $byId[$item->id] = $item;
        }
        if (count($identifiers) !== count($byId)) {
            throw new InvalidArgumentException('The menu item order is incomplete.');
        }
        foreach ($identifiers as $position => $identifier) {
            $item = $byId[$identifier] ?? null;
            if (!$item instanceof MenuItemRecord) {
                throw new InvalidArgumentException('The menu item order contains an unknown item.');
            }
            if ($item->position === $position) {
                continue;
            }
            $this->navigation->updateItem(
                $context,
                $item->id,
                $item->version,
                $item->parentId,
                $item->title,
                $item->slug,
                $position,
            );
        }
    }

    /**
     * Read an optional field, reporting a blank one as null rather than as an empty string.
     *
     * The menu form always posts its optional controls, so "no parent" and "no link target" arrive as
     * empty strings; the navigation service distinguishes null from an empty string, and this is the
     * translation between the two.
     *
     * @param   array<string, string>  $form   Flattened form as posted by the navigation screen.
     * @param   string                 $field  Name of the optional field.
     *
     * @return  ?string  The trimmed value, or null when the field was absent or blank.
     *
     * @since   2.0.0
     */
    private function nullable(array $form, string $field): ?string
    {
        $value = trim($form[$field] ?? '');

        return $value === '' ? null : $value;
    }

    /**
     * Read a field that must spell a non-negative decimal integer, such as a sort position.
     *
     * The value is pattern-matched rather than cast, so `-1` and `2x` are refused instead of quietly
     * becoming a position. An absent field falls back to the first position, while a field that is
     * present but empty is a malformed submission and is refused.
     *
     * @param   array<string, string>  $form   Flattened form as posted by the navigation screen.
     * @param   string                 $field  Name of the field holding the number.
     *
     * @return  int  The value as an integer, zero or greater.
     *
     * @throws  InvalidArgumentException  When the field holds anything other than decimal digits.
     *
     * @since   2.0.0
     */
    private function nonNegativeInteger(array $form, string $field): int
    {
        $value = $form[$field] ?? '0';
        if (preg_match('/^[0-9]+$/D', $value) !== 1) {
            throw new InvalidArgumentException(sprintf('The %s field must be a non-negative integer.', $field));
        }

        return (int) $value;
    }
}
