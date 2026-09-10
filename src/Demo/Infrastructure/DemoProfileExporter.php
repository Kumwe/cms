<?php

declare(strict_types=1);

namespace Kumwe\App\Demo\Infrastructure;

use InvalidArgumentException;
use JsonException;
use Kumwe\App\BusinessDefinition\Domain\CanonicalDefinitionJson;
use Kumwe\App\Content\Application\ContentRecord;
use Kumwe\App\Content\Application\ContentService;
use Kumwe\App\Demo\Application\DemoProfileLedger;
use Kumwe\App\Navigation\Application\MenuItemRecord;
use Kumwe\App\Navigation\Application\NavigationService;
use Kumwe\App\Site\Application\SiteSettings;
use Kumwe\Context\Value\ExecutionContext;
use Psr\Clock\ClockInterface;
use RuntimeException;

/**
 * Projects the running system back into the immutable JSON manifests a Kumwe release ships.
 *
 * Installation and export are the two directions of the same contract: `resources/demo` manifests go in
 * through the profile installers, and this service walks the live services — never the tables — to write
 * the same shape back out. Fixture keys are reused from the provenance ledger whenever the resource was
 * itself installed from a profile, so an export of an installed-then-customized site stays diffable
 * against the manifest it came from; resources the operators created by hand receive freshly minted,
 * collision-free keys. Exports carry no credential material of any kind: the manifest formats have no
 * place for secrets, and this exporter reads through the same authorized application services an
 * administrator uses, so nothing password-shaped is ever in reach.
 *
 * @since  2.0.0
 */
final readonly class DemoProfileExporter
{
    /**
     * Grammar an exported profile name must satisfy, mirroring the catalog's selection rule.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string PROFILE_NAME_PATTERN = '/^[a-z][a-z0-9-]{0,62}$/D';

    /**
     * Wire the exporter to the authorized read surfaces of every exported dataset.
     *
     * @param  ContentService     $content     Authorized reader for the site's content entries.
     * @param  NavigationService  $navigation  Authorized reader for menus and their items.
     * @param  SiteSettings       $settings    Authorized reader for the managed settings document.
     * @param  DemoProfileLedger  $ledger      Provenance ledger holding installed fixture identities.
     * @param  ClockInterface     $clock       Trusted timestamp source for the integrity index.
     *
     * @since  2.0.0
     */
    public function __construct(
        private ContentService $content,
        private NavigationService $navigation,
        private SiteSettings $settings,
        private DemoProfileLedger $ledger,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Project the site's content, menus, and settings into one installable site-content manifest.
     *
     * The result satisfies `kumwe.demo-content/v1` exactly as `FilesystemDemoManifestCatalog` validates
     * it: live UUIDs become the manifest `resource_id` values, fixture keys come from the ledger or are
     * minted from slugs and handles, and menu items keep their per-item template and colour-scheme
     * bindings so a round trip through install loses nothing.
     *
     * @param   ExecutionContext  $context  Authenticated administrator the reads run as.
     * @param   string            $profile  Profile name the manifest will be published under.
     *
     * @return  array<string, mixed>  Complete manifest document ready for `writePackage()`.
     *
     * @throws  InvalidArgumentException  When the profile name violates the selection grammar.
     *
     * @since   2.0.0
     */
    public function contentManifest(ExecutionContext $context, string $profile): array
    {
        $this->assertProfileName($profile);
        $site = $context->site()->identifier();
        $settings = $this->settings->managed($context);
        $installed = $this->installedFixtures($site, 'site-content');
        $pages = $this->exportPages($context, $installed['content'] ?? []);
        $pageFixtures = [];
        foreach ($pages as $page) {
            $pageFixtures[$this->stringOf($page, 'resource_id')] = $this->stringOf($page, 'fixture_key');
        }
        $homepageId = $this->nullableStringValue($settings['homepage_content_id'] ?? null);

        return [
            'format' => 'kumwe.demo-content/v1',
            'profile' => $profile,
            'version' => 1,
            'site' => [
                'fixture_key' => 'site.' . $site,
                'resource_id' => $site,
                'identifier' => $site,
                'name' => $this->nullableStringValue($settings['site_name'] ?? null) ?? $site,
                'enabled' => true,
            ],
            'settings' => [
                'fixture_key' => 'settings.' . $site,
                'resource_id' => $site,
                'site_fixture_key' => 'site.' . $site,
                'site_id' => $site,
                'site_name' => $this->nullableStringValue($settings['site_name'] ?? null) ?? $site,
                'homepage_content_fixture_key' => $homepageId === null
                    ? null
                    : ($pageFixtures[$homepageId] ?? null),
                'homepage_content_id' => $homepageId,
                'homepage_slug' => $this->nullableStringValue($settings['homepage_slug'] ?? null),
                'default_locale' => $this->nullableStringValue($settings['default_locale'] ?? null),
                'timezone' => $this->nullableStringValue($settings['timezone'] ?? null),
                'search_indexing_enabled' => ($settings['search_indexing_enabled'] ?? null) === true,
                'presentation' => $this->mapOf($settings, 'presentation'),
            ],
            'content' => $pages,
            'menus' => $this->exportMenus(
                $context,
                $installed['menu'] ?? [],
                $installed['menu_item'] ?? [],
                $pageFixtures,
                $homepageId,
            ),
        ];
    }

    /**
     * Write one exported package to disk and return its canonical integrity index.
     *
     * The package mirrors the repository layout below `resources/demo`, so pointing a
     * `FilesystemDemoManifestCatalog` at the package root validates the export exactly as a release is
     * validated, and copying the `resources` tree over an installation makes the export selectable. The
     * integrity index is written beside the tree as `export.json` and repeats each document's canonical
     * checksum so a recipient can verify the package without trusting its transport.
     *
     * @param   string                               $directory  Absolute package root that must not exist yet.
     * @param   string                               $profile    Profile name the documents were exported under.
     * @param   array<string, array<string, mixed>>  $documents  Documents keyed by path relative to `resources/demo`.
     *
     * @return  array<string, string>  Canonical checksum keyed by relative document path.
     *
     * @throws  InvalidArgumentException  When the directory is relative, exists, or cannot be created.
     * @throws  RuntimeException  When a document cannot be encoded or written.
     *
     * @since   2.0.0
     */
    public function writePackage(string $directory, string $profile, array $documents): array
    {
        if (!str_starts_with($directory, '/')) {
            throw new InvalidArgumentException('The export directory must be an absolute path.');
        }
        if (file_exists($directory)) {
            throw new InvalidArgumentException(
                'The export directory already exists; move it away before exporting again.',
            );
        }
        if (!mkdir($directory, 0o700, true)) {
            throw new InvalidArgumentException('The export directory could not be created.');
        }
        $checksums = [];
        foreach ($documents as $relative => $document) {
            if (preg_match('#^[a-z0-9][a-z0-9/._-]*\.json$#D', $relative) !== 1 || str_contains($relative, '..')) {
                throw new RuntimeException(sprintf('Export document path %s is invalid.', $relative));
            }
            $path = $directory . '/resources/demo/' . $relative;
            $parent = dirname($path);
            if (!is_dir($parent) && !mkdir($parent, 0o700, true)) {
                throw new RuntimeException(sprintf('Export directory %s could not be created.', $parent));
            }
            $this->writeDocument($path, $document);
            $checksums[$relative] = CanonicalDefinitionJson::checksum($document);
        }
        $this->writeDocument($directory . '/export.json', [
            'format' => 'kumwe.demo-export/v1',
            'profile' => $profile,
            'generated_at' => $this->clock->now()->format(DATE_ATOM),
            'checksums' => $checksums,
        ]);

        return $checksums;
    }

    /**
     * Project every live content entry into its manifest declaration.
     *
     * @param   ExecutionContext       $context    Authenticated administrator the read runs as.
     * @param   array<string, string>  $installed  Installed fixture keys by content UUID.
     *
     * @return  list<array<string, mixed>>  Manifest content declarations in listing order.
     *
     * @since   2.0.0
     */
    private function exportPages(ExecutionContext $context, array $installed): array
    {
        $declarations = [];
        $minted = [];
        $records = $this->content->list($context, 500);
        if (count($records) === 500) {
            throw new RuntimeException(
                'The site holds 500 or more content entries, which exceeds the demo-profile envelope.',
            );
        }
        foreach ($records as $record) {
            if (!$record instanceof ContentRecord || $record->deletedAt !== null) {
                continue;
            }
            $entry = $record->entry;
            $fixture = $installed[$entry->id()]
                ?? $this->mint('page.' . $entry->slug(), $minted);
            $minted[$fixture] = true;
            $window = $entry->publicationWindow();
            $declarations[] = [
                'fixture_key' => $fixture,
                'resource_id' => $entry->id(),
                'site_fixture_key' => 'site.' . $record->siteIdentifier,
                'site_id' => $record->siteIdentifier,
                'content_type_id' => $record->contentTypeId,
                'content_type_version' => $record->contentTypeVersion,
                'workflow_id' => $record->workflowId,
                'workflow_version' => $record->workflowVersion,
                'workflow_state_key' => $entry->statusKey(),
                'title' => $entry->title(),
                'slug' => $entry->slug(),
                'canonical_path' => '/' . $entry->slug(),
                'data' => $entry->data(),
                'publish_at' => $window->startsAt()?->format(DATE_ATOM),
                'unpublish_at' => $window->endsAt()?->format(DATE_ATOM),
                'version' => $entry->version(),
            ];
        }

        return $declarations;
    }

    /**
     * Project every live menu and its items into manifest declarations.
     *
     * @param   ExecutionContext       $context         Authenticated administrator the reads run as.
     * @param   array<string, string>  $installedMenus  Installed fixture keys by menu UUID.
     * @param   array<string, string>  $installedItems  Installed fixture keys by menu-item UUID.
     * @param   array<string, string>  $pageFixtures    Exported content fixture keys by content UUID.
     * @param   ?string                $homepageId      Content UUID the homepage resolves to, when set.
     *
     * @return  list<array<string, mixed>>  Manifest menu declarations in listing order.
     *
     * @since   2.0.0
     */
    private function exportMenus(
        ExecutionContext $context,
        array $installedMenus,
        array $installedItems,
        array $pageFixtures,
        ?string $homepageId,
    ): array {
        $menus = [];
        $mintedMenus = [];
        foreach ($this->navigation->menus($context) as $menu) {
            $menuFixture = $installedMenus[$menu->id] ?? $this->mint('menu.' . $menu->handle, $mintedMenus);
            $mintedMenus[$menuFixture] = true;
            $items = [];
            $itemFixtures = [];
            $minted = [];
            foreach ($this->navigation->items($context, $menu->id) as $item) {
                $fixture = $installedItems[$item->id]
                    ?? $this->mint($menuFixture . '.' . $item->slug, $minted);
                $minted[$fixture] = true;
                $itemFixtures[$item->id] = $fixture;
                $items[] = $this->exportItem($item, $fixture, $itemFixtures, $pageFixtures, $homepageId);
            }
            $menus[] = [
                'fixture_key' => $menuFixture,
                'resource_id' => $menu->id,
                'site_fixture_key' => 'site.' . $context->site()->identifier(),
                'site_id' => $context->site()->identifier(),
                'handle' => $menu->handle,
                'title' => $menu->title,
                'version' => $menu->version,
                'items' => $items,
            ];
        }

        return $menus;
    }

    /**
     * Project one stored menu item into its manifest declaration.
     *
     * Presentation bindings are declared only when the item carries them, matching the fingerprint rule
     * the installer applies, and the canonical path is derived from the item's target the same way the
     * public site resolves it: the homepage collapses to `/`, anchors append their fragment, and custom
     * URLs speak for themselves.
     *
     * @param   MenuItemRecord         $item          Stored item to declare.
     * @param   string                 $fixture       Fixture key the item exports under.
     * @param   array<string, string>  $itemFixtures  Fixture keys of already-declared items by UUID.
     * @param   array<string, string>  $pageFixtures  Exported content fixture keys by content UUID.
     * @param   ?string                $homepageId    Content UUID the homepage resolves to, when set.
     *
     * @return  array<string, mixed>  Manifest menu-item declaration.
     *
     * @since   2.0.0
     */
    private function exportItem(
        MenuItemRecord $item,
        string $fixture,
        array $itemFixtures,
        array $pageFixtures,
        ?string $homepageId,
    ): array {
        $contentFixture = $item->contentId === null ? null : ($pageFixtures[$item->contentId] ?? null);
        $isHomepage = $item->contentId !== null && $item->contentId === $homepageId;
        $canonical = match ($item->targetType) {
            'content' => $isHomepage ? '/' : $item->path,
            'anchor' => ($isHomepage ? '' : $item->path) . ($item->targetUrl ?? ''),
            default => $item->targetUrl ?? $item->path,
        };
        $declaration = [
            'fixture_key' => $fixture,
            'resource_id' => $item->id,
            'parent_fixture_key' => $item->parentId === null ? null : ($itemFixtures[$item->parentId] ?? null),
            'parent_id' => $item->parentId,
            'title' => $item->title,
            'slug' => $item->slug,
            'path' => $item->path,
            'canonical_path' => $canonical,
            'position' => $item->position,
            'version' => $item->version,
            'target_type' => $item->targetType,
            'content_fixture_key' => $contentFixture,
            'content_id' => $contentFixture === null ? null : $item->contentId,
            'target_url' => $item->targetUrl,
        ];
        if ($item->template !== null) {
            $declaration['template'] = $item->template;
        }
        if ($item->colorScheme !== null) {
            $declaration['color_scheme'] = $item->colorScheme;
        }

        return $declaration;
    }

    /**
     * Build the installed fixture-key index for one dataset from the provenance ledger.
     *
     * @param   string  $site     Site identifier the export runs against.
     * @param   string  $dataset  Provenance dataset key, for example `site-content`.
     *
     * @return  array<string, array<string, string>>  Fixture keys by resource UUID, grouped by resource type.
     *
     * @since   2.0.0
     */
    private function installedFixtures(string $site, string $dataset): array
    {
        $index = [];
        foreach ($this->ledger->assets($site, $dataset) as $asset) {
            $type = $asset['resource_type'] ?? null;
            $fixture = $asset['fixture_key'] ?? null;
            $resource = $asset['resource_id'] ?? null;
            if (is_string($type) && is_string($fixture) && is_string($resource)) {
                $index[$type][$resource] = $fixture;
            }
        }

        return $index;
    }

    /**
     * Mint one collision-free fixture key from a naturally named candidate.
     *
     * @param   string               $candidate  Naturally derived key, for example `page.pricing`.
     * @param   array<string, bool>  $taken      Keys already minted for the same collection.
     *
     * @return  string  The candidate itself, or the first numbered variant that is free.
     *
     * @since   2.0.0
     */
    private function mint(string $candidate, array $taken): string
    {
        $key = strtolower(preg_replace('/[^a-z0-9._-]+/', '-', strtolower($candidate)) ?? $candidate);
        if (!isset($taken[$key])) {
            return $key;
        }
        $suffix = 2;
        while (isset($taken[$key . '-' . $suffix])) {
            ++$suffix;
        }

        return $key . '-' . $suffix;
    }

    /**
     * Refuse an export under a name the manifest catalog would never select.
     *
     * @param   string  $profile  Candidate profile name.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the name violates the selection grammar.
     *
     * @since   2.0.0
     */
    private function assertProfileName(string $profile): void
    {
        if (preg_match(self::PROFILE_NAME_PATTERN, $profile) !== 1) {
            throw new InvalidArgumentException(
                'The export profile name must be lowercase letters, digits, and dashes.',
            );
        }
    }

    /**
     * Encode one document as stable, human-reviewable JSON and write it exclusively.
     *
     * @param   string                $path      Absolute file path that must not exist yet.
     * @param   array<string, mixed>  $document  Document to persist.
     *
     * @return  void
     *
     * @throws  RuntimeException  When encoding fails or the file cannot be created.
     *
     * @since   2.0.0
     */
    private function writeDocument(string $path, array $document): void
    {
        try {
            $encoded = json_encode(
                $document,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            throw new RuntimeException(
                sprintf('Export document %s could not be encoded.', basename($path)),
                0,
                $exception,
            );
        }
        $handle = @fopen($path, 'x');
        if ($handle === false) {
            throw new RuntimeException(sprintf('Export document %s could not be created.', basename($path)));
        }
        fwrite($handle, $encoded . "\n");
        fclose($handle);
    }

    /**
     * Read one required string field out of an exported declaration.
     *
     * @param   array<string, mixed>  $document  Declaration to read from.
     * @param   string                $key       Required field name.
     *
     * @return  string  The field's string value.
     *
     * @throws  RuntimeException  When the field is absent or not a string.
     *
     * @since   2.0.0
     */
    private function stringOf(array $document, string $key): string
    {
        $value = $document[$key] ?? null;
        if (!is_string($value)) {
            throw new RuntimeException(sprintf('The exported declaration lacks the %s field.', $key));
        }

        return $value;
    }

    /**
     * Read one required object-shaped field out of an exported document.
     *
     * @param   array<string, mixed>  $document  Document to read from.
     * @param   string                $key       Required field name.
     *
     * @return  array<string, mixed>  The field's object value.
     *
     * @throws  RuntimeException  When the field is absent or not an object.
     *
     * @since   2.0.0
     */
    private function mapOf(array $document, string $key): array
    {
        $value = $document[$key] ?? null;
        if (!is_array($value) || array_is_list($value)) {
            throw new RuntimeException(sprintf('The exported document lacks the %s object.', $key));
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * Normalize one possibly absent settings value to a string or null.
     *
     * @param   mixed  $value  Candidate settings value.
     *
     * @return  ?string  The string itself, or null for anything that is not a string.
     *
     * @since   2.0.0
     */
    private function nullableStringValue(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
