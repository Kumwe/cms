<?php

declare(strict_types=1);

namespace Kumwe\App\Demo\Infrastructure;

use Kumwe\Transaction\Contract\TransactionManager;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\App\BusinessDefinition\Domain\CanonicalDefinitionJson;
use Kumwe\App\Content\Application\ContentNotFound;
use Kumwe\App\Content\Application\ContentRecord;
use Kumwe\App\Content\Application\ContentService;
use Kumwe\App\Demo\Application\DemoProfileLedger;
use Kumwe\App\Navigation\Application\MenuItemRecord;
use Kumwe\App\Navigation\Application\MenuRecord;
use Kumwe\App\Navigation\Application\NavigationService;
use Kumwe\App\Site\Application\SiteSettings;
use RuntimeException;

/**
 * Reconciles the selected managed-site example through Kumwe's canonical application services.
 *
 * Fixture identifiers are hints for adopting the released legacy placeholder; new records keep the UUIDs
 * minted by their application services and are mapped in the provenance ledger. A release updates an asset
 * only while its current canonical state still matches what the previous release applied. Once an operator
 * edits a page, menu item, or settings document, reconciliation leaves it alone and reports the divergence.
 *
 * @since  2.0.0
 */
final readonly class DemoContentProfileInstaller
{
    /**
     * Dataset key persisted independently from the VDM business example.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string DATASET = 'site-content';

    /**
     * Bind the content reconciler to the public application services and its restart ledger.
     *
     * @param  ContentService      $content       Canonical page mutation service.
     * @param  NavigationService   $navigation    Canonical menu-tree mutation service.
     * @param  SiteSettings        $settings      Canonical settings document service.
     * @param  DemoProfileLedger   $ledger        Stable fixture mapping and divergence baseline.
     * @param  TransactionManager  $transactions  Atomic service-mutation and provenance boundary.
     *
     * @since  2.0.0
     */
    public function __construct(
        private ContentService $content,
        private NavigationService $navigation,
        private SiteSettings $settings,
        private DemoProfileLedger $ledger,
        private TransactionManager $transactions,
    ) {
    }

    /**
     * Apply one validated content manifest and return concise operator diagnostics.
     *
     * @param   ExecutionContext      $context      Purpose-bound profile-installer context.
     * @param   array<string, mixed>  $manifest     Selected content manifest.
     * @param   array<string, mixed>  $placeholder  Legacy placeholder manifest used only as an adoption sentinel.
     *
     * @return  list<string>  Applied, removed, and deliberately preserved resource summaries.
     *
     * @since   2.0.0
     */
    public function install(ExecutionContext $context, array $manifest, array $placeholder): array
    {
        $messages = [];
        $pages = $this->pageIndex($manifest);
        $baselinePages = $this->pageIndex($placeholder);
        $pageIds = [];
        foreach ($pages as $fixtureKey => $page) {
            $result = $this->reconcilePage($context, $fixtureKey, $page, $baselinePages[$fixtureKey] ?? null);
            $pageIds[$fixtureKey] = $result['record']->entry->id();
            if ($result['preserved']) {
                $messages[] = sprintf('Preserved customized demo page %s.', $fixtureKey);
            }
        }
        $this->retirePages($context, $pages, $baselinePages, $messages);

        $menus = $this->menus($manifest);
        $baselineMenus = $this->menus($placeholder);
        foreach ($menus as $menu) {
            $this->reconcileMenu(
                $context,
                $menu,
                $this->menuByFixture($baselineMenus, $this->fixtureKey($menu)),
                $pageIds,
                $messages,
            );
        }
        if ($menus === []) {
            throw new RuntimeException('A site-content profile must retain the managed primary menu.');
        }
        $this->reconcileSettings($context, $manifest, $placeholder, $pageIds, $messages);

        return $messages;
    }

    /**
     * Reconcile one page, creating it through the service or updating only an untouched prior fixture.
     *
     * @param   ExecutionContext       $context     Profile installer context.
     * @param   string                 $fixtureKey  Stable fixture key.
     * @param   array<string, mixed>   $page        Desired page declaration.
     * @param   ?array<string, mixed>  $baseline    Legacy adoption sentinel for this fixture.
     *
     * @return  array{record: ContentRecord, preserved: bool}  Stored record and whether it was preserved.
     *
     * @since   2.0.0
     */
    private function reconcilePage(
        ExecutionContext $context,
        string $fixtureKey,
        array $page,
        ?array $baseline,
    ): array {
        return $this->transactions->transactional(function () use (
            $context,
            $fixtureKey,
            $page,
            $baseline,
        ): array {
            $desired = $this->pageState($page);
            $desiredChecksum = CanonicalDefinitionJson::checksum($desired);
            $asset = $this->ledger->asset($context->site()->identifier(), self::DATASET, $fixtureKey);
            $preferredId = $this->requiredString($page, 'resource_id');
            $record = is_string($asset['resource_id'] ?? null)
                ? $this->findPage($context, $asset['resource_id'], true)
                : $this->content->publishedById($preferredId, $context->site());
            if ($record === null) {
                $record = $this->content->publishedBySlug(
                    $this->requiredString($page, 'slug'),
                    $context->site(),
                );
            }
            if ($record === null) {
                $record = $this->publishNewPage($context, $page);
            } else {
                if ($record->deletedAt !== null) {
                    $record = $this->content->restore($context, $record->entry->id(), $record->entry->version());
                }
                $current = $this->pageRecordState($record);
                $currentChecksum = CanonicalDefinitionJson::checksum($current);
                $baselineChecksum = $baseline === null
                    ? null
                    : CanonicalDefinitionJson::checksum($this->pageState($baseline));
                $lastApplied = is_string($asset['last_applied_checksum'] ?? null)
                    ? $asset['last_applied_checksum']
                    : null;
                if (
                    $currentChecksum !== $desiredChecksum
                    && $currentChecksum !== $lastApplied
                    && $currentChecksum !== $baselineChecksum
                ) {
                    return ['record' => $record, 'preserved' => true];
                }
                if ($currentChecksum !== $desiredChecksum) {
                    $record = $this->content->update(
                        $context,
                        $record->entry->id(),
                        $record->entry->version(),
                        $this->requiredString($page, 'title'),
                        $this->requiredString($page, 'slug'),
                        $this->requiredMap($page, 'data'),
                    );
                }
            }
            $this->ledger->recordAsset(
                $context->site()->identifier(),
                self::DATASET,
                $fixtureKey,
                'content',
                $record->entry->id(),
                $desiredChecksum,
                $record->entry->version(),
                $desired,
            );

            return ['record' => $record, 'preserved' => false];
        });
    }

    /**
     * Create and publish one new Page through its persisted editorial workflow.
     *
     * @param   ExecutionContext      $context  Profile installer context.
     * @param   array<string, mixed>  $page     Desired page declaration.
     *
     * @return  ContentRecord  Published page after draft and review revisions were captured.
     *
     * @since   2.0.0
     */
    private function publishNewPage(ExecutionContext $context, array $page): ContentRecord
    {
        $record = $this->content->create(
            $context,
            $this->requiredString($page, 'title'),
            $this->requiredString($page, 'slug'),
            $this->requiredMap($page, 'data'),
            contentTypeIdentifier: $this->requiredString($page, 'content_type_id'),
        );
        if ($record->entry->statusKey() !== 'published') {
            $record = $this->content->transition(
                $context,
                $record->entry->id(),
                $record->entry->version(),
                'review',
            );
            $record = $this->content->transition(
                $context,
                $record->entry->id(),
                $record->entry->version(),
                'published',
            );
        }

        return $record;
    }

    /**
     * Trash untouched pages removed by the selected profile while preserving every customized page.
     *
     * @param   ExecutionContext                     $context   Profile installer context.
     * @param   array<string, array<string, mixed>>  $target    Desired page index.
     * @param   array<string, array<string, mixed>>  $baseline  Legacy sentinel page index.
     * @param   list<string>                            &$messages      Operator diagnostics to append.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function retirePages(
        ExecutionContext $context,
        array $target,
        array $baseline,
        array &$messages,
    ): void {
        $candidates = $baseline;
        foreach ($this->ledger->assets($context->site()->identifier(), self::DATASET) as $asset) {
            if (($asset['resource_type'] ?? null) !== 'content') {
                continue;
            }
            $fixtureKey = $asset['fixture_key'] ?? null;
            if (is_string($fixtureKey) && !isset($candidates[$fixtureKey])) {
                $state = $this->map($asset['last_applied_state'] ?? null, 'stored page checkpoint');
                $candidates[$fixtureKey] = [
                    'resource_id' => $this->requiredString($asset, 'resource_id'),
                    ...$state,
                ];
            }
        }
        foreach (array_diff_key($candidates, $target) as $fixtureKey => $page) {
            $asset = $this->ledger->asset($context->site()->identifier(), self::DATASET, $fixtureKey);
            $resourceId = is_string($asset['resource_id'] ?? null)
                ? $asset['resource_id']
                : $this->requiredString($page, 'resource_id');
            $record = $this->findPage($context, $resourceId, true);
            if ($record === null || $record->deletedAt !== null) {
                continue;
            }
            $current = $this->pageRecordState($record);
            $currentChecksum = CanonicalDefinitionJson::checksum($current);
            $allowed = [CanonicalDefinitionJson::checksum($this->candidatePageState($page))];
            if (is_string($asset['last_applied_checksum'] ?? null)) {
                $allowed[] = $asset['last_applied_checksum'];
            }
            if (!in_array($currentChecksum, $allowed, true)) {
                $messages[] = sprintf('Preserved customized demo page %s.', $fixtureKey);
                continue;
            }
            $this->transactions->transactional(function () use ($context, $fixtureKey, $record): void {
                $record = $this->content->trash(
                    $context,
                    $record->entry->id(),
                    $record->entry->version(),
                );
                $removed = ['removed' => true];
                $this->ledger->recordAsset(
                    $context->site()->identifier(),
                    self::DATASET,
                    $fixtureKey,
                    'content',
                    $record->entry->id(),
                    CanonicalDefinitionJson::checksum($removed),
                    $record->entry->version(),
                    $removed,
                );
            });
            $messages[] = sprintf('Removed untouched demo page %s.', $fixtureKey);
        }
    }

    /**
     * Reconcile one primary menu and all its items in parent-before-child manifest order.
     *
     * @param   ExecutionContext       $context   Profile installer context.
     * @param   array<string, mixed>   $menu      Desired menu declaration.
     * @param   ?array<string, mixed>  $baseline  Legacy menu sentinel.
     * @param   array<string, string>  $pageIds   Actual content IDs keyed by fixture key.
     * @param   list<string>           &$messages     Operator diagnostics to append.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function reconcileMenu(
        ExecutionContext $context,
        array $menu,
        ?array $baseline,
        array $pageIds,
        array &$messages,
    ): void {
        $fixtureKey = $this->fixtureKey($menu);
        $stored = $this->findMenu($context, $this->requiredString($menu, 'handle'));
        if ($stored === null) {
            $stored = $this->navigation->createMenu(
                $context,
                $this->requiredString($menu, 'handle'),
                $this->requiredString($menu, 'title'),
            );
        }
        $desiredMenu = $this->menuState($menu);
        $currentMenu = $this->menuRecordState($stored);
        $asset = $this->ledger->asset($context->site()->identifier(), self::DATASET, $fixtureKey);
        $menuDiverged = false;
        if (
            $currentMenu !== $desiredMenu
            && !$this->safeToChange($currentMenu, $desiredMenu, $asset, $baseline === null
                ? null
                : $this->menuState($baseline))
        ) {
            $menuDiverged = true;
            $messages[] = sprintf('Preserved customized demo menu %s.', $fixtureKey);
        } elseif ($currentMenu !== $desiredMenu) {
            $stored = $this->navigation->updateMenu(
                $context,
                $stored->id,
                $stored->version,
                $this->requiredString($menu, 'handle'),
                $this->requiredString($menu, 'title'),
            );
        }
        if (!$menuDiverged) {
            $this->ledger->recordAsset(
                $context->site()->identifier(),
                self::DATASET,
                $fixtureKey,
                'menu',
                $stored->id,
                CanonicalDefinitionJson::checksum($desiredMenu),
                $stored->version,
                $desiredMenu,
            );
        }

        $currentItems = [];
        foreach ($this->navigation->items($context, $stored->id) as $item) {
            $currentItems[$item->id] = $item;
        }
        $baselineItems = $baseline === null ? [] : $this->itemIndex($baseline);
        $targetItems = $this->itemIndex($menu);
        $itemIds = [];
        foreach ($targetItems as $itemFixture => $item) {
            $result = $this->reconcileItem(
                $context,
                $stored,
                $itemFixture,
                $item,
                $baselineItems[$itemFixture] ?? null,
                $pageIds,
                $itemIds,
                $currentItems,
            );
            $itemIds[$itemFixture] = $result['record']->id;
            if ($result['preserved']) {
                $messages[] = sprintf('Preserved customized demo menu item %s.', $itemFixture);
            }
        }
        $this->retireMenuItems(
            $context,
            $targetItems,
            $baselineItems,
            $currentItems,
            $messages,
        );
    }

    /**
     * Create or safely update one menu item after its parent and content target have been resolved.
     *
     * @param   ExecutionContext               $context       Profile installer context.
     * @param   MenuRecord                     $menu          Parent menu.
     * @param   string                         $fixtureKey    Stable item fixture key.
     * @param   array<string, mixed>           $item          Desired item declaration.
     * @param   ?array<string, mixed>          $baseline      Legacy item sentinel.
     * @param   array<string, string>          $pageIds       Actual content IDs by fixture.
     * @param   array<string, string>          $itemIds       Actual parent IDs by fixture.
     * @param   array<string, MenuItemRecord>  $currentItems  Current menu items by UUID.
     *
     * @return  array{record: MenuItemRecord, preserved: bool}  Stored item and preservation outcome.
     *
     * @since   2.0.0
     */
    private function reconcileItem(
        ExecutionContext $context,
        MenuRecord $menu,
        string $fixtureKey,
        array $item,
        ?array $baseline,
        array $pageIds,
        array $itemIds,
        array $currentItems,
    ): array {
        return $this->transactions->transactional(function () use (
            $context,
            $menu,
            $fixtureKey,
            $item,
            $baseline,
            $pageIds,
            $itemIds,
            $currentItems,
        ): array {
            $parentFixture = $item['parent_fixture_key'] ?? null;
            $parentId = is_string($parentFixture) ? ($itemIds[$parentFixture] ?? null) : null;
            if (is_string($parentFixture) && $parentId === null) {
                throw new RuntimeException(sprintf('Demo menu item %s has an unresolved parent.', $fixtureKey));
            }
            $contentFixture = $item['content_fixture_key'] ?? null;
            $contentId = is_string($contentFixture) ? ($pageIds[$contentFixture] ?? null) : null;
            if (is_string($contentFixture) && $contentId === null) {
                throw new RuntimeException(sprintf('Demo menu item %s has an unresolved content target.', $fixtureKey));
            }
            $desired = $this->itemState($item, $parentId, $contentId);
            $asset = $this->ledger->asset($context->site()->identifier(), self::DATASET, $fixtureKey);
            $resourceId = is_string($asset['resource_id'] ?? null)
                ? $asset['resource_id']
                : $this->requiredString($item, 'resource_id');
            $stored = $currentItems[$resourceId]
                ?? $this->menuItemByPath($currentItems, $this->requiredString($item, 'path'));
            if ($stored === null) {
                $stored = $this->navigation->createItem(
                    $context,
                    $menu->id,
                    $parentId,
                    $this->requiredString($item, 'title'),
                    $this->requiredString($item, 'slug'),
                    $this->requiredInteger($item, 'position', 0),
                    $this->requiredString($item, 'target_type'),
                    $contentId,
                    $this->nullableString($item, 'target_url'),
                    $this->nullableString($item, 'template'),
                    $this->nullableString($item, 'color_scheme'),
                );
            } else {
                $current = $this->itemRecordState($stored);
                $baselineState = $baseline === null
                    ? null
                    : $this->itemState(
                        $baseline,
                        $this->nullableString($baseline, 'parent_id'),
                        $this->nullableString($baseline, 'content_id'),
                    );
                if (!$this->safeToChange($current, $desired, $asset, $baselineState)) {
                    return ['record' => $stored, 'preserved' => true];
                }
                if ($current !== $desired) {
                    $stored = $this->navigation->updateItem(
                        $context,
                        $stored->id,
                        $stored->version,
                        $parentId,
                        $this->requiredString($item, 'title'),
                        $this->requiredString($item, 'slug'),
                        $this->requiredInteger($item, 'position', 0),
                        $this->requiredString($item, 'target_type'),
                        $contentId,
                        $this->nullableString($item, 'target_url'),
                        $this->nullableString($item, 'template'),
                        $this->nullableString($item, 'color_scheme'),
                    );
                }
            }
            $this->ledger->recordAsset(
                $context->site()->identifier(),
                self::DATASET,
                $fixtureKey,
                'menu_item',
                $stored->id,
                CanonicalDefinitionJson::checksum($desired),
                $stored->version,
                $desired,
            );

            return ['record' => $stored, 'preserved' => false];
        });
    }

    /**
     * Delete untouched items absent from the target, deepest descendants first.
     *
     * @param   ExecutionContext                     $context       Profile installer context.
     * @param   array<string, array<string, mixed>>  $target        Desired item index.
     * @param   array<string, array<string, mixed>>  $baseline      Legacy sentinel item index.
     * @param   array<string, MenuItemRecord>        $currentItems  Current items by UUID.
     * @param   list<string>                           &$messages     Operator diagnostics to append.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function retireMenuItems(
        ExecutionContext $context,
        array $target,
        array $baseline,
        array $currentItems,
        array &$messages,
    ): void {
        $candidates = $baseline;
        foreach ($this->ledger->assets($context->site()->identifier(), self::DATASET) as $asset) {
            if (($asset['resource_type'] ?? null) !== 'menu_item') {
                continue;
            }
            $fixtureKey = $asset['fixture_key'] ?? null;
            if (is_string($fixtureKey) && !isset($candidates[$fixtureKey])) {
                $state = $this->map($asset['last_applied_state'] ?? null, 'stored menu-item checkpoint');
                $candidates[$fixtureKey] = [
                    'resource_id' => $this->requiredString($asset, 'resource_id'),
                    ...$state,
                ];
            }
        }
        $obsolete = array_diff_key($candidates, $target);
        uasort($obsolete, static function (array $left, array $right): int {
            $leftPath = $left['path'] ?? null;
            $rightPath = $right['path'] ?? null;

            return strlen(is_string($rightPath) ? $rightPath : '')
                <=> strlen(is_string($leftPath) ? $leftPath : '');
        });
        foreach ($obsolete as $fixtureKey => $item) {
            $asset = $this->ledger->asset($context->site()->identifier(), self::DATASET, $fixtureKey);
            $resourceId = is_string($asset['resource_id'] ?? null)
                ? $asset['resource_id']
                : $this->requiredString($item, 'resource_id');
            $stored = $currentItems[$resourceId] ?? null;
            if ($stored === null) {
                continue;
            }
            $current = $this->itemRecordState($stored);
            $baselineState = $this->itemState(
                $item,
                $this->nullableString($item, 'parent_id'),
                $this->nullableString($item, 'content_id'),
            );
            if (!$this->safeToChange($current, $baselineState, $asset, $baselineState)) {
                $messages[] = sprintf('Preserved customized demo menu item %s.', $fixtureKey);
                continue;
            }
            $this->transactions->transactional(function () use ($context, $fixtureKey, $stored): void {
                $this->navigation->deleteItem($context, $stored->id, $stored->version);
                $removed = ['removed' => true];
                $this->ledger->recordAsset(
                    $context->site()->identifier(),
                    self::DATASET,
                    $fixtureKey,
                    'menu_item',
                    $stored->id,
                    CanonicalDefinitionJson::checksum($removed),
                    $stored->version,
                    $removed,
                );
            });
            $messages[] = sprintf('Removed untouched demo menu item %s.', $fixtureKey);
        }
    }

    /**
     * Reconcile the one settings document after page and menu targets are available.
     *
     * @param   ExecutionContext       $context      Profile installer context.
     * @param   array<string, mixed>   $manifest     Desired content manifest.
     * @param   array<string, mixed>   $placeholder  Legacy adoption manifest.
     * @param   array<string, string>  $pageIds      Actual page IDs by fixture key.
     * @param   list<string>           &$messages    Operator diagnostics to append.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function reconcileSettings(
        ExecutionContext $context,
        array $manifest,
        array $placeholder,
        array $pageIds,
        array &$messages,
    ): void {
        $settings = $this->requiredMap($manifest, 'settings');
        $placeholderSettings = $this->requiredMap($placeholder, 'settings');
        $desired = $this->settingsState($settings, $pageIds);
        $baseline = $this->settingsState($placeholderSettings, [
            'page.home' => $this->requiredString($placeholderSettings, 'homepage_content_id'),
        ]);
        $current = $this->settings->managed($context);
        $asset = $this->ledger->asset($context->site()->identifier(), self::DATASET, 'settings.default');
        if (!$this->safeToChange($current, $desired, $asset, $baseline)) {
            $messages[] = 'Preserved customized site settings.';
            return;
        }
        $this->transactions->transactional(function () use ($context, $current, $desired): void {
            if ($current !== $desired) {
                $this->settings->updateAll($context, $desired);
            }
            $this->ledger->recordAsset(
                $context->site()->identifier(),
                self::DATASET,
                'settings.default',
                'site_settings',
                $context->site()->identifier(),
                CanonicalDefinitionJson::checksum($desired),
                1,
                $desired,
            );
        });
    }

    /**
     * Decide whether current state is the target, the prior applied state, or the legacy sentinel.
     *
     * @param   array<string, mixed>   $current   Current canonical state.
     * @param   array<string, mixed>   $desired   Desired canonical state.
     * @param   ?array<string, mixed>  $asset     Prior ledger checkpoint.
     * @param   ?array<string, mixed>  $baseline  Legacy adoption sentinel.
     *
     * @return  bool  Whether reconciliation may replace the resource.
     *
     * @since   2.0.0
     */
    private function safeToChange(
        array $current,
        array $desired,
        ?array $asset,
        ?array $baseline,
    ): bool {
        $checksum = CanonicalDefinitionJson::checksum($current);
        $allowed = [CanonicalDefinitionJson::checksum($desired)];
        if (is_string($asset['last_applied_checksum'] ?? null)) {
            $allowed[] = $asset['last_applied_checksum'];
        }
        if ($baseline !== null) {
            $allowed[] = CanonicalDefinitionJson::checksum($baseline);
        }

        return in_array($checksum, $allowed, true);
    }

    /**
     * Find one content record without treating absence as a failed installation.
     *
     * @param   ExecutionContext  $context         Profile installer context.
     * @param   string            $id              Candidate content UUID.
     * @param   bool              $includeDeleted  Whether a trashed profile page counts as present.
     *
     * @return  ?ContentRecord  Stored page or null.
     *
     * @since   2.0.0
     */
    private function findPage(ExecutionContext $context, string $id, bool $includeDeleted = false): ?ContentRecord
    {
        try {
            return $this->content->get($context, $id, $includeDeleted);
        } catch (ContentNotFound) {
            return null;
        }
    }

    /**
     * Find one menu by its stable handle.
     *
     * @param   ExecutionContext  $context  Profile installer context.
     * @param   string            $handle   Menu handle.
     *
     * @return  ?MenuRecord  Matching menu or null.
     *
     * @since   2.0.0
     */
    private function findMenu(ExecutionContext $context, string $handle): ?MenuRecord
    {
        foreach ($this->navigation->menus($context) as $menu) {
            if ($menu->handle === $handle) {
                return $menu;
            }
        }

        return null;
    }

    /**
     * Recover an item written before provenance by its menu-unique canonical path.
     *
     * This closes the restart window left by older installers that committed the navigation mutation
     * before recording its service-minted UUID in the demo ledger.
     *
     * @param   array<string, MenuItemRecord>  $items  Current items keyed by UUID.
     * @param   string                         $path   Exact canonical path declared by the fixture.
     *
     * @return  ?MenuItemRecord  Matching item or null when the fixture has not been written.
     *
     * @since   2.0.0
     */
    private function menuItemByPath(array $items, string $path): ?MenuItemRecord
    {
        foreach ($items as $item) {
            if ($item->path === $path) {
                return $item;
            }
        }

        return null;
    }

    /**
     * Index the bounded page declarations by their stable fixture keys.
     *
     * @param   array<string, mixed>  $manifest  Content manifest carrying the page list.
     *
     * @return  array<string, array<string, mixed>>  Validated page declarations by fixture key.
     *
     * @since   2.0.0
     */
    private function pageIndex(array $manifest): array
    {
        $pages = $manifest['content'] ?? null;
        if (!is_array($pages) || !array_is_list($pages) || count($pages) > 256) {
            throw new RuntimeException('A demo content manifest has an invalid page list.');
        }
        $result = [];
        foreach ($pages as $page) {
            $page = $this->map($page, 'page declaration');
            $fixtureKey = $this->fixtureKey($page);
            if (isset($result[$fixtureKey])) {
                throw new RuntimeException(sprintf('Demo page fixture %s is duplicated.', $fixtureKey));
            }
            $result[$fixtureKey] = $page;
        }

        return $result;
    }

    /**
     * Validate and return the bounded menu declarations in authored order.
     *
     * @param   array<string, mixed>  $manifest  Content manifest carrying the menu list.
     *
     * @return  list<array<string, mixed>>  Validated menu declarations.
     *
     * @since   2.0.0
     */
    private function menus(array $manifest): array
    {
        $menus = $manifest['menus'] ?? null;
        if (!is_array($menus) || !array_is_list($menus) || count($menus) > 16) {
            throw new RuntimeException('A demo content manifest has an invalid menu list.');
        }
        $result = [];
        foreach ($menus as $menu) {
            $result[] = $this->map($menu, 'menu declaration');
        }

        return $result;
    }

    /**
     * Index one menu's bounded item declarations by their stable fixture keys.
     *
     * @param   array<string, mixed>  $menu  Menu declaration carrying the item list.
     *
     * @return  array<string, array<string, mixed>>  Validated menu items by fixture key.
     *
     * @since   2.0.0
     */
    private function itemIndex(array $menu): array
    {
        $items = $menu['items'] ?? null;
        if (!is_array($items) || !array_is_list($items) || count($items) > 256) {
            throw new RuntimeException('A demo menu has an invalid item list.');
        }
        $result = [];
        foreach ($items as $item) {
            $item = $this->map($item, 'menu-item declaration');
            $fixtureKey = $this->fixtureKey($item);
            if (isset($result[$fixtureKey])) {
                throw new RuntimeException(sprintf('Demo menu item fixture %s is duplicated.', $fixtureKey));
            }
            $result[$fixtureKey] = $item;
        }

        return $result;
    }

    /**
     * Find one manifest menu by its stable fixture key.
     *
     * @param   list<array<string, mixed>>  $menus       Validated menu declarations to search.
     * @param   string                      $fixtureKey  Stable fixture identity to match.
     *
     * @return  ?array<string, mixed>  Matching menu declaration, or null when absent.
     *
     * @since   2.0.0
     */
    private function menuByFixture(array $menus, string $fixtureKey): ?array
    {
        foreach ($menus as $menu) {
            if ($this->fixtureKey($menu) === $fixtureKey) {
                return $menu;
            }
        }

        return null;
    }

    /**
     * Reduce a manifest page declaration to the authored state used for divergence checks.
     *
     * @param   array<string, mixed>  $page  Validated manifest page declaration.
     *
     * @return  array<string, mixed>  Canonical authored page state.
     *
     * @since   2.0.0
     */
    private function pageState(array $page): array
    {
        return [
            'title' => $this->requiredString($page, 'title'),
            'slug' => $this->requiredString($page, 'slug'),
            'data' => $this->requiredMap($page, 'data'),
            'status' => $this->requiredString($page, 'workflow_state_key'),
        ];
    }

    /**
     * Project a stored content record into the same state used by the manifest.
     *
     * @param   ContentRecord  $record  Stored page and its current workflow state.
     *
     * @return  array<string, mixed>  Canonical authored state of the stored page.
     *
     * @since   2.0.0
     */
    private function pageRecordState(ContentRecord $record): array
    {
        return [
            'title' => $record->entry->title(),
            'slug' => $record->entry->slug(),
            'data' => $record->entry->data(),
            'status' => $record->entry->statusKey(),
        ];
    }

    /**
     * Normalize either a manifest page declaration or a prior ledger page state.
     *
     * @param   array<string, mixed>  $page  Manifest declaration or stored canonical state.
     *
     * @return  array<string, mixed>  Canonical authored page state.
     *
     * @since   2.0.0
     */
    private function candidatePageState(array $page): array
    {
        if (isset($page['workflow_state_key'])) {
            return $this->pageState($page);
        }

        return [
            'title' => $this->requiredString($page, 'title'),
            'slug' => $this->requiredString($page, 'slug'),
            'data' => $this->requiredMap($page, 'data'),
            'status' => $this->requiredString($page, 'status'),
        ];
    }

    /**
     * Reduce a manifest menu declaration to its user-editable state.
     *
     * @param   array<string, mixed>  $menu  Validated manifest menu declaration.
     *
     * @return  array<string, mixed>  Canonical authored menu state.
     *
     * @since   2.0.0
     */
    private function menuState(array $menu): array
    {
        return [
            'handle' => $this->requiredString($menu, 'handle'),
            'title' => $this->requiredString($menu, 'title'),
        ];
    }

    /**
     * Project a stored menu into the same state used by the manifest.
     *
     * @param   MenuRecord  $menu  Stored menu with its current title and handle.
     *
     * @return  array<string, mixed>  Canonical authored state of the stored menu.
     *
     * @since   2.0.0
     */
    private function menuRecordState(MenuRecord $menu): array
    {
        return ['handle' => $menu->handle, 'title' => $menu->title];
    }

    /**
     * Resolve a manifest menu item to the identifiers and path persisted by navigation.
     *
     * @param   array<string, mixed>  $item       Validated menu-item declaration.
     * @param   ?string               $parentId   Resolved parent item identity, or null for a root item.
     * @param   ?string               $contentId  Resolved target content identity, or null for a URL item.
     *
     * @return  array<string, mixed>  Canonical resolved menu-item state.
     *
     * @since   2.0.0
     */
    private function itemState(array $item, ?string $parentId, ?string $contentId): array
    {
        $state = [
            'parent_id' => $parentId,
            'title' => $this->requiredString($item, 'title'),
            'slug' => $this->requiredString($item, 'slug'),
            'path' => $this->requiredString($item, 'path'),
            'position' => $this->requiredInteger($item, 'position', 0),
            'target_type' => $this->requiredString($item, 'target_type'),
            'content_id' => $contentId,
            'target_url' => $this->nullableString($item, 'target_url'),
        ];
        // Presentation bindings join the fingerprint only when bound, so every checkpoint recorded
        // before the binding columns existed keeps its checksum and is still recognised as untouched.
        $template = $this->nullableString($item, 'template');
        if ($template !== null) {
            $state['template'] = $template;
        }
        $scheme = $this->nullableString($item, 'color_scheme');
        if ($scheme !== null) {
            $state['color_scheme'] = $scheme;
        }

        return $state;
    }

    /**
     * Project a stored menu item into the resolved state used by the manifest.
     *
     * @param   MenuItemRecord  $item  Stored navigation item and resolved targets.
     *
     * @return  array<string, mixed>  Canonical resolved state of the stored item.
     *
     * @since   2.0.0
     */
    private function itemRecordState(MenuItemRecord $item): array
    {
        $state = [
            'parent_id' => $item->parentId,
            'title' => $item->title,
            'slug' => $item->slug,
            'path' => $item->path,
            'position' => $item->position,
            'target_type' => $item->targetType,
            'content_id' => $item->contentId,
            'target_url' => $item->targetUrl,
        ];
        // Mirrors itemState(): bindings are fingerprinted only when bound, keeping legacy checksums stable.
        if ($item->template !== null) {
            $state['template'] = $item->template;
        }
        if ($item->colorScheme !== null) {
            $state['color_scheme'] = $item->colorScheme;
        }

        return $state;
    }

    /**
     * Resolve fixture references in the manifest's public-site settings.
     *
     * @param   array<string, mixed>   $settings  Authored settings declaration.
     * @param   array<string, string>  $pageIds   Installed content identities by fixture key.
     *
     * @return  array<string, mixed>  Canonical public settings with actual content identities.
     *
     * @since   2.0.0
     */
    private function settingsState(array $settings, array $pageIds): array
    {
        $homepageFixture = $settings['homepage_content_fixture_key'] ?? null;
        $homepageId = is_string($homepageFixture) ? ($pageIds[$homepageFixture] ?? null) : null;
        if (is_string($homepageFixture) && $homepageId === null) {
            throw new RuntimeException('The demo homepage fixture did not resolve to an installed page.');
        }

        return [
            'site_name' => $this->requiredString($settings, 'site_name'),
            'homepage_content_id' => $homepageId,
            'homepage_slug' => $this->requiredString($settings, 'homepage_slug'),
            'default_locale' => $this->requiredString($settings, 'default_locale'),
            'timezone' => $this->requiredString($settings, 'timezone'),
            'search_indexing_enabled' => $this->requiredBoolean($settings, 'search_indexing_enabled'),
            'presentation' => $this->requiredMap($settings, 'presentation'),
        ];
    }

    /**
     * Read and validate a stable fixture key from a resource declaration.
     *
     * @param   array<string, mixed>  $resource  Manifest resource carrying its fixture identity.
     *
     * @return  string  Stable bounded fixture key.
     *
     * @since   2.0.0
     */
    private function fixtureKey(array $resource): string
    {
        $value = $this->requiredString($resource, 'fixture_key');
        if (preg_match('/^[a-z][a-z0-9.-]{0,190}$/D', $value) !== 1) {
            throw new RuntimeException('A demo fixture key is invalid.');
        }

        return $value;
    }

    /**
     * Read one required non-empty string from a manifest object.
     *
     * @param   array<string, mixed>  $document  Manifest object carrying the field.
     * @param   string                $key       Required field name.
     *
     * @return  string  Validated non-empty field value.
     *
     * @since   2.0.0
     */
    private function requiredString(array $document, string $key): string
    {
        $value = $document[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new RuntimeException(sprintf('Demo manifest field %s is invalid.', $key));
        }

        return $value;
    }

    /**
     * Read one optional nullable string from a manifest object.
     *
     * @param   array<string, mixed>  $document  Manifest object carrying the field.
     * @param   string                $key       Optional field name.
     *
     * @return  ?string  Validated string, or null when the field is absent or explicitly null.
     *
     * @since   2.0.0
     */
    private function nullableString(array $document, string $key): ?string
    {
        $value = $document[$key] ?? null;
        if ($value !== null && !is_string($value)) {
            throw new RuntimeException(sprintf('Demo manifest field %s is invalid.', $key));
        }

        return $value;
    }

    /**
     * Read one required object-shaped value from a manifest object.
     *
     * @param   array<string, mixed>  $document  Manifest object carrying the nested object.
     * @param   string                $key       Required field name.
     *
     * @return  array<string, mixed>  Validated object-shaped value.
     *
     * @since   2.0.0
     */
    private function requiredMap(array $document, string $key): array
    {
        return $this->map($document[$key] ?? null, sprintf('field %s', $key));
    }

    /**
     * Require one decoded manifest value to be an object with string keys.
     *
     * @param   mixed   $value  Candidate decoded value.
     * @param   string  $name   Diagnostic noun identifying the value on failure.
     *
     * @return  array<string, mixed>  Validated object-shaped value.
     *
     * @since   2.0.0
     */
    private function map(mixed $value, string $name): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new RuntimeException(sprintf('The demo manifest %s is invalid.', $name));
        }

        $result = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new RuntimeException(sprintf('The demo manifest %s has a non-string object key.', $name));
            }
            $result[$key] = $item;
        }

        return $result;
    }

    /**
     * Read one bounded integer from a manifest object.
     *
     * @param   array<string, mixed>  $document  Manifest object carrying the field.
     * @param   string                $key       Required field name.
     * @param   int                   $minimum   Smallest accepted value.
     *
     * @return  int  Validated integer at or above the supplied minimum.
     *
     * @since   2.0.0
     */
    private function requiredInteger(array $document, string $key, int $minimum = 1): int
    {
        $value = $document[$key] ?? null;
        if (!is_int($value) || $value < $minimum) {
            throw new RuntimeException(sprintf('Demo manifest field %s is invalid.', $key));
        }

        return $value;
    }

    /**
     * Read one required boolean from a manifest object.
     *
     * @param   array<string, mixed>  $document  Manifest object carrying the field.
     * @param   string                $key       Required field name.
     *
     * @return  bool  Validated boolean field value.
     *
     * @since   2.0.0
     */
    private function requiredBoolean(array $document, string $key): bool
    {
        $value = $document[$key] ?? null;
        if (!is_bool($value)) {
            throw new RuntimeException(sprintf('Demo manifest field %s is invalid.', $key));
        }

        return $value;
    }
}
