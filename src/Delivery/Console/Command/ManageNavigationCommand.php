<?php

declare(strict_types=1);

namespace Kumwe\App\Delivery\Console\Command;

use Kumwe\App\Delivery\Console\Command;
use Kumwe\App\Delivery\Console\Output;
use Kumwe\App\Navigation\Application\MenuItemRecord;
use Kumwe\App\Navigation\Application\MenuRecord;
use Kumwe\App\Navigation\Application\NavigationService;
use Kumwe\Context\Value\ExecutionContext;
use Throwable;

/**
 * Console entry point for editing menus and menu items as `kumwe navigation`.
 *
 * Menus and their items share one command because they share one service and one capability: every
 * action here proves `navigation.manage` and then hands the work to `NavigationService`, which owns
 * slug and path validation, the reserved-prefix check that stops a menu entry shadowing a routed path,
 * subtree moves and optimistic locking. The command's only real contribution is option handling — it
 * distinguishes an option left out from one passed empty, so `update-item` can leave a stored parent or
 * link target alone instead of clearing it.
 *
 * @since  2.0.0
 */
final readonly class ManageNavigationCommand implements Command
{
    /**
     * Wire the command to the navigation use cases and to the console's token authorization route.
     *
     * @param  NavigationService  $navigation     Service every action delegates its read or mutation to.
     * @param  ConsoleAuthorizer  $authorization  Resolves `--site` and `--token-file` into an authorized context.
     *
     * @since  2.0.0
     */
    public function __construct(private NavigationService $navigation, private ConsoleAuthorizer $authorization)
    {
    }

    /**
     * Name the operator types to reach the navigation actions.
     *
     * @return  string  Always `navigation`.
     *
     * @since   2.0.0
     */
    public function name(): string
    {
        return 'navigation';
    }

    /**
     * Describe the command for the console's command listing.
     *
     * @return  string  One-line summary covering both menus and the items inside them.
     *
     * @since   2.0.0
     */
    public function description(): string
    {
        return 'core.console.navigation.description';
    }

    /**
     * Run one navigation action and print the affected menu, item, or list as JSON.
     *
     * The first argument is the action and defaults to `list`; everything after it is a `--name=value`
     * option. Unlike the other management commands the capability does not vary by action — reading a
     * menu and deleting one both require `navigation.manage` — so a token that can list can also write.
     * Deletes answer with `{"deleted": true}` rather than the removed row. Every failure is written as
     * one message and reported as exit status 1.
     *
     * @param   list<string>  $arguments  Action name first, then `--name=value` options: `--site` and
     *          `--token-file` always, plus whatever the chosen action requires.
     * @param   Output        $output     Sink for the JSON result, or for the failure message.
     *
     * @return  int  0 when the action completed, 1 when any step failed.
     *
     * @since   2.0.0
     */
    public function execute(array $arguments, Output $output): int
    {
        try {
            $action = array_shift($arguments) ?? 'list';
            $options = CommandInput::options($arguments);
            $context = $this->authorization->require($options, 'navigation.manage');
            $result = match ($action) {
                'list' => ['items' => array_map(
                    static fn (MenuRecord $menu): array => $menu->toArray(),
                    $this->navigation->menus($context),
                )],
                'items' => ['items' => array_map(
                    static fn (MenuItemRecord $item): array => $item->toArray(),
                    $this->navigation->items($context, CommandInput::required($options, 'menu')),
                )],
                'get-item' => $this->navigation->item(
                    $context,
                    CommandInput::required($options, 'id'),
                )->toArray(),
                'create-menu' => $this->navigation->createMenu(
                    $context,
                    CommandInput::required($options, 'handle'),
                    CommandInput::required($options, 'title'),
                )->toArray(),
                'update-menu' => $this->navigation->updateMenu(
                    $context,
                    CommandInput::required($options, 'id'),
                    CommandInput::positiveInteger($options, 'version'),
                    CommandInput::required($options, 'handle'),
                    CommandInput::required($options, 'title'),
                )->toArray(),
                'delete-menu' => $this->deleteMenu($options, $context),
                'create-item' => $this->createItem($options, $context),
                'update-item' => $this->updateItem($options, $context),
                'delete-item' => $this->deleteItem($options, $context),
                default => throw new \InvalidArgumentException('Unsupported navigation action.'),
            };
            $output->line(CommandInput::render($result));
            return 0;
        } catch (Throwable $exception) {
            $output->error($exception->getMessage());
            return 1;
        }
    }

    /**
     * Delete a menu and the whole tree of items beneath it on behalf of the `delete-menu` action.
     *
     * The service returns nothing, so the acknowledgement printed to the operator is synthesised here.
     *
     * @param   array<string, string>  $options  Parsed options; `--id` and `--version` are required.
     * @param   ExecutionContext       $context  Authorized actor and site the delete is performed and audited for.
     *
     * @return  array{deleted: bool}  Always `['deleted' => true]`; failure arrives as an exception instead.
     *
     * @throws  \InvalidArgumentException  When `--id` or a positive `--version` is missing.
     * @throws  \Kumwe\App\Navigation\Application\NavigationNotFound  When no menu carries that identifier.
     * @throws  \Kumwe\App\Navigation\Application\NavigationVersionConflict  When the stored menu has moved on.
     *
     * @since   2.0.0
     */
    private function deleteMenu(array $options, ExecutionContext $context): array
    {
        $this->navigation->deleteMenu(
            $context,
            CommandInput::required($options, 'id'),
            CommandInput::positiveInteger($options, 'version'),
        );
        return ['deleted' => true];
    }

    /**
     * Delete a menu item and the branch beneath it on behalf of the `delete-item` action.
     *
     * Descendants go with the item, so an operator wanting to keep them reparents them first.
     *
     * @param   array<string, string>  $options  Parsed options; `--id` and `--version` are required.
     * @param   ExecutionContext       $context  Authorized actor and site the delete is performed and audited for.
     *
     * @return  array{deleted: bool}  Always `['deleted' => true]`; failure arrives as an exception instead.
     *
     * @throws  \InvalidArgumentException  When `--id` or a positive `--version` is missing.
     * @throws  \Kumwe\App\Navigation\Application\NavigationNotFound  When no item carries that identifier.
     * @throws  \Kumwe\App\Navigation\Application\NavigationVersionConflict  When the stored item has moved on.
     *
     * @since   2.0.0
     */
    private function deleteItem(array $options, ExecutionContext $context): array
    {
        $this->navigation->deleteItem(
            $context,
            CommandInput::required($options, 'id'),
            CommandInput::positiveInteger($options, 'version'),
        );
        return ['deleted' => true];
    }

    /**
     * Add an item to a menu on behalf of the `create-item` action.
     *
     * `--parent`, `--target-type`, `--content` and `--target-url` are all treated as absent when passed
     * empty, so omitting `--target-type` selects the legacy content item resolved by slug at render
     * time. `--position` defaults to 0 and is read as an integer, which places the item first among its
     * siblings unless the operator numbers it deliberately.
     *
     * @param   array<string, string>  $options  Parsed options; `--menu`, `--title` and `--slug` are required.
     * @param   ExecutionContext       $context  Authorized actor and site the new item is created under.
     *
     * @return  array<string, mixed>  The stored item at version 1, with its resolved path, in JSON shape.
     *
     * @throws  \InvalidArgumentException  When a required option is missing, or a field, parent, target or
     *          resolved path is rejected.
     * @throws  \Kumwe\App\Navigation\Application\NavigationNotFound  When no menu carries that identifier.
     * @throws  \Kumwe\App\Content\Application\ContentNotFound  When the named content target does not exist.
     *
     * @since   2.0.0
     */
    private function createItem(array $options, ExecutionContext $context): array
    {
        return $this->navigation->createItem(
            $context,
            CommandInput::required($options, 'menu'),
            $this->optional($options, 'parent'),
            CommandInput::required($options, 'title'),
            CommandInput::required($options, 'slug'),
            (int) ($options['position'] ?? 0),
            $this->optional($options, 'target-type'),
            $this->optional($options, 'content'),
            $this->optional($options, 'target-url'),
        )->toArray();
    }

    /**
     * Rewrite a menu item on behalf of the `update-item` action, preserving what the operator left out.
     *
     * The service replaces every field it is given, so the stored item is read first and used to fill
     * in what the operator did not pass: the parent, the position and the three target fields all
     * survive an update that never mentions them. Passing `--parent=` empty is not the same as omitting
     * it — that moves the item to the menu root. Naming any of `--target-type`, `--content` or
     * `--target-url` re-sends the whole target, which is why the stored target type is carried over when
     * only the content or the URL is being changed.
     *
     * @param   array<string, string>  $options  Parsed options; `--id`, `--version`, `--title` and `--slug`
     *          are required, the rest default to the stored item.
     * @param   ExecutionContext       $context  Authorized actor and site the write is performed and audited for.
     *
     * @return  array<string, mixed>  The stored item one version higher, with its resolved path, in JSON shape.
     *
     * @throws  \InvalidArgumentException  When a required option is missing, or a field, the move, the target
     *          or a resulting path is rejected.
     * @throws  \Kumwe\App\Navigation\Application\NavigationNotFound  When no item carries that identifier.
     * @throws  \Kumwe\App\Navigation\Application\NavigationVersionConflict  When the stored item has moved on.
     * @throws  \Kumwe\App\Content\Application\ContentNotFound  When the named content target does not exist.
     *
     * @since   2.0.0
     */
    private function updateItem(array $options, ExecutionContext $context): array
    {
        $item = $this->navigation->item($context, CommandInput::required($options, 'id'));
        $targetChanged = array_key_exists('target-type', $options)
            || array_key_exists('content', $options)
            || array_key_exists('target-url', $options);
        $targetType = $targetChanged
            ? ($this->optional($options, 'target-type') ?? $item->targetType)
            : null;

        return $this->navigation->updateItem(
            $context,
            $item->id,
            CommandInput::positiveInteger($options, 'version'),
            array_key_exists('parent', $options) ? $this->optional($options, 'parent') : $item->parentId,
            CommandInput::required($options, 'title'),
            CommandInput::required($options, 'slug'),
            (int) ($options['position'] ?? $item->position),
            $targetType,
            array_key_exists('content', $options) ? $this->optional($options, 'content') : $item->contentId,
            array_key_exists('target-url', $options)
                ? $this->optional($options, 'target-url')
                : $item->targetUrl,
        )->toArray();
    }

    /**
     * Read an option the caller may legitimately leave unset.
     *
     * A blank value and an absent option deliberately give the same answer, so `--parent=` reads as "no
     * parent" instead of as an empty identifier the service would reject.
     *
     * @param   array<string, string>  $options  Parsed option map to read from.
     * @param   string                 $name     Option name without the leading `--`.
     *
     * @return  ?string  The trimmed value, or null when the option is absent or trims to nothing.
     *
     * @since   2.0.0
     */
    private function optional(array $options, string $name): ?string
    {
        $value = trim($options[$name] ?? '');
        return $value === '' ? null : $value;
    }
}
