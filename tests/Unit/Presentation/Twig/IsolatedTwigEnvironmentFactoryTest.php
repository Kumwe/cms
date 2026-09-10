<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Presentation\Twig;

use Kumwe\Context\Value\SiteContext;
use Kumwe\App\Administrator\Presentation\AdministratorRenderer;
use Kumwe\App\Administrator\Presentation\RecoveryAdministratorRenderer;
use Kumwe\App\Extension\Runtime\ActiveExtensionSet;
use Kumwe\App\Extension\Contribution\ExtensionContributionRegistrySet;
use Kumwe\App\Extension\Domain\ThemeSurface;
use Kumwe\App\Presentation\Twig\ContractRestrictedLoader;
use Kumwe\App\Presentation\Twig\IsolatedTwigEnvironmentFactory;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Twig\Error\LoaderError;

#[CoversClass(IsolatedTwigEnvironmentFactory::class)]
#[CoversClass(ContractRestrictedLoader::class)]
#[CoversClass(ActiveExtensionSet::class)]
#[CoversClass(AdministratorRenderer::class)]
#[CoversClass(RecoveryAdministratorRenderer::class)]
#[UsesClass(ThemeSurface::class)]
final class IsolatedTwigEnvironmentFactoryTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/kumwe-theme-test-' . bin2hex(random_bytes(8));
        foreach (
            [
            '/core/site', '/core/administrator', '/core/interface-standard', '/site-theme',
            '/regional-theme', '/admin-theme',
            '/extension/site', '/extension/administrator', '/extension/collision-a',
            '/extension/collision-b', '/cache',
            ] as $directory
        ) {
            self::assertTrue(mkdir($this->root . $directory, 0700, true));
        }

        $this->template('/core/site/page.twig', 'core site');
        $this->template('/core/interface-standard/example.twig', 'KIS component');
        $this->template('/core/administrator/page.twig', 'core administrator');
        $this->template('/core/administrator/layout.twig', 'core:{% block content %}{% endblock %}');
        $this->template(
            '/core/administrator/shell.twig',
            '{% extends "layout.twig" %}{% block content %}page{% endblock %}',
        );
        $this->template('/site-theme/page.twig', 'site theme');
        $this->template('/regional-theme/page.twig', 'regional theme');
        $this->template('/admin-theme/page.twig', 'administrator theme');
        $this->template('/admin-theme/layout.twig', 'theme:{% block content %}{% endblock %}');
        $this->template('/extension/site/widget.twig', 'site extension');
        $this->template('/extension/administrator/widget.twig', 'administrator extension');
        $this->template('/extension/collision-a/widget.twig', 'collision A');
        $this->template('/extension/collision-b/widget.twig', 'collision B');
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testSurfacesHaveIndependentOverridesCoreNamespacesAndExtensionViews(): void
    {
        $active = $this->activeExtensions();
        $factory = $this->factory($active);
        $site = $factory->site();
        $administrator = $factory->administrator();

        self::assertSame('site theme', $site->render('page.twig'));
        self::assertSame('core site', $site->render('@core-site/page.twig'));
        self::assertSame('site extension', $site->render('@extension-61636d652f746f6f6c73/widget.twig'));
        self::assertSame('KIS component', $site->render('@kis/example.twig'));
        self::assertSame('core administrator', $administrator->render('page.twig'));
        self::assertSame('core administrator', $administrator->render('@core-admin/page.twig'));
        self::assertSame(
            'administrator extension',
            $administrator->render('@extension-61636d652f746f6f6c73/widget.twig'),
        );
        self::assertSame('KIS component', $administrator->render('@kis/example.twig'));
        self::assertSame('theme:page', $administrator->render('shell.twig'));
    }

    public function testAdministratorThemeCannotOverrideControllerSpecificTemplates(): void
    {
        $administrator = $this->factory($this->activeExtensions())->administrator();

        self::assertSame('core administrator', $administrator->render('page.twig'));
        $this->expectException(LoaderError::class);
        $administrator->render('@admin-theme/page.twig');
    }

    public function testSiteThemeCannotResolveOrOverrideAdministratorNamespaces(): void
    {
        $site = $this->factory($this->activeExtensions())->site();

        $this->expectException(LoaderError::class);
        $site->render('@core-admin/page.twig');
    }

    public function testRecoveryRendererIgnoresBrokenAdministratorTheme(): void
    {
        $this->template('/admin-theme/layout.twig', '{% deliberately_invalid %}');
        $factory = $this->factory($this->activeExtensions());
        $renderer = new AdministratorRenderer(
            $factory->administrator(),
            new RecoveryAdministratorRenderer($factory->recoveryAdministrator()),
        );

        self::assertSame('core:page', $renderer->render('shell'));
        self::assertSame('KIS component', $factory->recoveryAdministrator()->render('@kis/example.twig'));
    }

    public function testOnlyOneThemeCanBeLoadedPerSurface(): void
    {
        $active = new ActiveExtensionSet(new ExtensionContributionRegistrySet(withCore: false));
        $active->setSiteThemePath('default', $this->root . '/site-theme');
        $this->expectException(LogicException::class);

        $active->setSiteThemePath('default', $this->root . '/admin-theme');
    }

    public function testPublicSiteSelectsItsOwnThemeAssignment(): void
    {
        $active = $this->activeExtensions();
        $active->setSiteThemePath('regional', $this->root . '/regional-theme');
        $factory = $this->factory($active);

        self::assertSame('site theme', $factory->site(SiteContext::default())->render('page.twig'));
        self::assertSame(
            'regional theme',
            $factory->site(SiteContext::fromString('regional'))->render('page.twig'),
        );
    }

    public function testExtensionNamespacesAreInjectiveForPreviouslyCollidingIdentifiers(): void
    {
        $first = IsolatedTwigEnvironmentFactory::extensionNamespace('ac-me/x');
        $second = IsolatedTwigEnvironmentFactory::extensionNamespace('ac/me-x');
        $active = new ActiveExtensionSet(new ExtensionContributionRegistrySet(withCore: false));
        $active->addExtensionViewPath(ThemeSurface::Site, 'ac-me/x', $this->root . '/extension/collision-a');
        $active->addExtensionViewPath(ThemeSurface::Site, 'ac/me-x', $this->root . '/extension/collision-b');
        $twig = $this->factory($active)->site();

        self::assertNotSame($first, $second);
        self::assertSame('collision A', $twig->render('@' . $first . '/widget.twig'));
        self::assertSame('collision B', $twig->render('@' . $second . '/widget.twig'));
    }

    private function activeExtensions(): ActiveExtensionSet
    {
        $active = new ActiveExtensionSet(new ExtensionContributionRegistrySet(withCore: false));
        $active->setSiteThemePath('default', $this->root . '/site-theme');
        $active->setThemePath(ThemeSurface::Administrator, $this->root . '/admin-theme');
        $active->addExtensionViewPath(ThemeSurface::Site, 'acme/tools', $this->root . '/extension/site');
        $active->addExtensionViewPath(
            ThemeSurface::Administrator,
            'acme/tools',
            $this->root . '/extension/administrator',
        );

        return $active;
    }

    private function factory(ActiveExtensionSet $active): IsolatedTwigEnvironmentFactory
    {
        return new IsolatedTwigEnvironmentFactory(
            $active,
            $this->root . '/core',
            $this->root . '/cache',
            false,
        );
    }

    private function template(string $relativePath, string $contents): void
    {
        self::assertNotFalse(file_put_contents($this->root . $relativePath, $contents));
    }

    private function removeTree(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }
        rmdir($directory);
    }
}
