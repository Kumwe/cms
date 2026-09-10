<?php

declare(strict_types=1);

use Kumwe\Context\Value\SiteContext;
use Kumwe\App\Content\Application\ContentService;
use Kumwe\App\Demo\Application\DemoProfileLedger;
use Kumwe\App\Demo\Infrastructure\DemoContentProfileInstaller;
use Kumwe\App\Kernel\Configuration\ApplicationConfiguration;
use Kumwe\App\Kernel\ContainerFactory;
use Kumwe\App\Navigation\Application\MenuItemRecord;
use Kumwe\App\Navigation\Application\MenuRecord;
use Kumwe\App\Navigation\Application\NavigationService;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Tests\Support\TestKernelFactory;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$mode = $argv[1] ?? null;
if (!in_array($mode, ['apply', 'verify'], true)) {
    throw new RuntimeException('Usage: php tests/Support/customize-demo-profile.php apply|verify');
}

$container = (new ContainerFactory())->create(Environment::fromGlobals());
$configuration = $container->get(ApplicationConfiguration::class);
if (!$configuration instanceof ApplicationConfiguration || $configuration->publicSite !== SiteContext::DEFAULT) {
    throw new RuntimeException('The customization smoke requires the default public site.');
}
$context = TestKernelFactory::administratorContext($container);
$content = $container->get(ContentService::class);
$navigation = $container->get(NavigationService::class);
$ledger = $container->get(DemoProfileLedger::class);
if (
    !$content instanceof ContentService
    || !$navigation instanceof NavigationService
    || !$ledger instanceof DemoProfileLedger
) {
    throw new RuntimeException('The canonical demo-customization services are unavailable.');
}

$page = $content->publishedBySlug('getting-started', $context->site());
if ($page === null) {
    throw new RuntimeException('The documentation page selected for customization is unavailable.');
}

$menu = null;
foreach ($navigation->menus($context) as $candidate) {
    if ($candidate->handle === 'main') {
        $menu = $candidate;
        break;
    }
}
if (!$menu instanceof MenuRecord) {
    throw new RuntimeException('The documentation primary menu is unavailable.');
}

$parent = null;
$child = null;
foreach ($navigation->items($context, $menu->id) as $candidate) {
    if ($candidate->path === '/start-here') {
        $parent = $candidate;
    } elseif ($candidate->path === '/start-here/getting-started') {
        $child = $candidate;
    }
}
if (!$parent instanceof MenuItemRecord || !$child instanceof MenuItemRecord) {
    throw new RuntimeException('The documentation customization fixtures are unavailable.');
}

$pageTitle = 'Operator-customized getting started';
$parentTitle = 'Operator-customized start here';
if ($mode === 'apply') {
    if ($page->entry->title() !== $pageTitle) {
        $page = $content->update(
            $context,
            $page->entry->id(),
            $page->entry->version(),
            $pageTitle,
            $page->entry->slug(),
            $page->entry->data(),
        );
    }
    if ($parent->title !== $parentTitle) {
        $parent = $navigation->updateItem(
            $context,
            $parent->id,
            $parent->version,
            $parent->parentId,
            $parentTitle,
            $parent->slug,
            $parent->position,
            $parent->targetType,
            $parent->contentId,
            $parent->targetUrl,
        );
    }
    $ledger->failed($context->site()->identifier(), DemoContentProfileInstaller::DATASET);
    fwrite(STDOUT, "Applied operator demo-profile customizations.\n");
    exit(0);
}

if ($page->entry->title() !== $pageTitle || $parent->title !== $parentTitle) {
    throw new RuntimeException('Demo reconciliation overwrote an operator customization.');
}
if ($child->parentId !== $parent->id || $child->contentId !== $page->entry->id()) {
    throw new RuntimeException('A preserved demo fixture no longer resolves its dependent menu links.');
}

fwrite(STDOUT, "Verified operator demo-profile customizations and dependencies.\n");
