<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Studio\Application\Composition;

use FilesystemIterator;
use Kumwe\Context\Value\SiteContext;
use Kumwe\Extension\Spi\Application\ExtensionServiceProvider;
use Kumwe\App\Extension\Contribution\ExtensionContributionRegistrySet;
use Kumwe\App\Extension\Runtime\ActiveExtensionSet;
use Kumwe\Extension\Manifest\ExtensionIdentifier;
use Kumwe\Extension\Manifest\ManifestContributions;
use Kumwe\Extension\Spi\Runtime\ExtensionContainer;
use Kumwe\App\Extension\Runtime\RestrictedExtensionContainer;
use Kumwe\App\Extension\Runtime\RuntimeArtifactDigester;
use Kumwe\App\Presentation\Application\SitePresentation;
use Kumwe\App\Site\Application\SiteSettings;
use Kumwe\App\Studio\Application\Composition\StudioBuiltInThemeRelease;
use Kumwe\App\Studio\Application\Composition\StudioPublishedTheme;
use Kumwe\App\Studio\Application\Composition\StudioPublishedThemeReference;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * Verifies public-theme locks are exact to trusted runtime bytes and validated presentation.
 *
 * @since  2.0.0
 */
#[CoversClass(StudioBuiltInThemeRelease::class)]
#[CoversClass(StudioPublishedTheme::class)]
#[CoversClass(StudioPublishedThemeReference::class)]
final class StudioPublishedThemeTest extends TestCase
{
    /**
     * Temporary deployment fixture root.
     *
     * @var    string
     * @since  2.0.0
     */
    private string $root;

    /**
     * Build a minimal deterministic public-theme deployment.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/kumwe-studio-theme-' . bin2hex(random_bytes(8));
        $directories = [
            'templates/site',
            'public/assets/build/.vite',
            'public/assets/build/css',
            'public/assets/build/js',
            'public/assets/build/media',
            'public/assets',
        ];
        foreach ($directories as $directory) {
            $path = $this->root . '/' . $directory;
            self::assertTrue(is_dir($path) || mkdir($path, 0o700, true));
        }
        file_put_contents($this->root . '/templates/site/page.twig', '<main>{{ body_html }}</main>');
        file_put_contents($this->root . '/public/assets/build/css/site.css', 'body{color:#123456}');
        file_put_contents($this->root . '/public/assets/build/js/site.js', 'export {};');
        file_put_contents($this->root . '/public/assets/build/js/shared.js', 'export const shared=true;');
        file_put_contents($this->root . '/public/assets/build/js/lazy.js', 'export const lazy=true;');
        file_put_contents($this->root . '/public/assets/build/js/admin.js', 'export const admin=true;');
        file_put_contents($this->root . '/public/assets/build/media/site.svg', '<svg></svg>');
        file_put_contents($this->root . '/public/assets/site.css', 'body{color:#654321}');
        file_put_contents($this->root . '/public/assets/build/.vite/manifest.json', json_encode([
            '_shared.js' => ['file' => 'js/shared.js'],
            'assets/site/lazy.ts' => ['file' => 'js/lazy.js', 'assets' => ['media/site.svg']],
            'assets/administrator/main.ts' => ['file' => 'js/admin.js'],
            'assets/site/main.ts' => [
                'file' => 'js/site.js',
                'css' => ['css/site.css'],
                'imports' => ['_shared.js'],
                'dynamicImports' => ['assets/site/lazy.ts'],
            ],
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * Remove the isolated deployment fixture.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    protected function tearDown(): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            if (!$entry instanceof SplFileInfo) {
                continue;
            }
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->root);
        parent::tearDown();
    }

    /**
     * Reachable public assets and templates affect the coordinate; unrelated administrator assets do not.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testBuiltInCoordinateTracksOnlyExactPublicThemeRuntime(): void
    {
        $digester = new RuntimeArtifactDigester();
        $this->assertRuntimeFailure(
            static fn (): StudioBuiltInThemeRelease => new StudioBuiltInThemeRelease('not-a-digest'),
            'The built-in public theme revision is invalid.',
        );
        $first = StudioBuiltInThemeRelease::fromDeployment($this->root, '2.0.0', $digester);
        file_put_contents($this->root . '/public/assets/build/js/admin.js', 'export const admin=false;');
        $administratorChanged = StudioBuiltInThemeRelease::fromDeployment($this->root, '2.0.0', $digester);
        self::assertSame($first->revision, $administratorChanged->revision);

        file_put_contents($this->root . '/public/assets/build/js/lazy.js', 'export const lazy=false;');
        $dynamicChanged = StudioBuiltInThemeRelease::fromDeployment($this->root, '2.0.0', $digester);
        self::assertNotSame($first->revision, $dynamicChanged->revision);

        file_put_contents($this->root . '/public/assets/build/media/site.svg', '<svg><title>Changed</title></svg>');
        $assetChanged = StudioBuiltInThemeRelease::fromDeployment($this->root, '2.0.0', $digester);
        self::assertNotSame($dynamicChanged->revision, $assetChanged->revision);

        file_put_contents($this->root . '/public/assets/build/css/site.css', 'body{color:#abcdef}');
        $siteChanged = StudioBuiltInThemeRelease::fromDeployment($this->root, '2.0.0', $digester);
        self::assertNotSame($assetChanged->revision, $siteChanged->revision);

        file_put_contents($this->root . '/templates/site/page.twig', '<main class="changed">{{ body_html }}</main>');
        $templateChanged = StudioBuiltInThemeRelease::fromDeployment($this->root, '2.0.0', $digester);
        self::assertNotSame($siteChanged->revision, $templateChanged->revision);

        $manifestPath = $this->root . '/public/assets/build/.vite/manifest.json';
        $invalidManifests = [
            [[['file' => 'js/site.js']], 'The Vite manifest is invalid.'],
            [['_shared.js' => ['file' => 'js/shared.js']], 'The Vite site entry is unavailable.'],
            [[
                'assets/site/main.ts' => ['file' => false],
            ], 'A Vite site asset coordinate is invalid.'],
            [[
                'assets/site/main.ts' => [
                    'file' => 'js/site.js',
                    'css' => (object) ['site' => 'css/site.css'],
                ],
            ], 'A Vite site asset list is invalid.'],
            [[
                'assets/site/main.ts' => ['file' => 'js/site.js', 'css' => [false]],
            ], 'A Vite site asset coordinate is invalid.'],
            [[
                'assets/site/main.ts' => [
                    'file' => 'js/site.js',
                    'imports' => (object) ['shared' => '_shared.js'],
                ],
            ], 'A Vite site import list is invalid.'],
            [[
                'assets/site/main.ts' => ['file' => 'js/site.js', 'imports' => [false]],
            ], 'A Vite site import coordinate is invalid.'],
            [[
                'assets/site/main.ts' => ['file' => '../site.js'],
            ], 'A Vite site asset path is unsafe.'],
            [[
                'assets/site/main.ts' => ['file' => 'js/missing.js'],
            ], 'A built-in public theme asset is missing or unsafe.'],
        ];
        foreach ($invalidManifests as [$manifest, $message]) {
            file_put_contents($manifestPath, json_encode($manifest, JSON_THROW_ON_ERROR));
            $this->assertRuntimeFailure(
                fn (): StudioBuiltInThemeRelease => StudioBuiltInThemeRelease::fromDeployment(
                    $this->root,
                    '2.0.0',
                    $digester,
                ),
                $message,
            );
        }
    }

    /**
     * Signed extension release identity and validated public presentation both participate in the lock.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testExtensionThemeAndPresentationProduceOneExactReference(): void
    {
        $settingsDocument = ['presentation' => SitePresentation::defaults()];
        $settings = $this->createStub(SiteSettings::class);
        $settings->method('current')->willReturnCallback(
            static function () use (&$settingsDocument): array {
                return $settingsDocument;
            },
        );
        $registries = new ExtensionContributionRegistrySet(withCore: false);
        $extensions = new ActiveExtensionSet($registries);
        $extensions->add(
            'acme/public-theme',
            new StudioThemeProviderProbe(),
            new RestrictedExtensionContainer('acme/public-theme', []),
            ManifestContributions::fromSchemaOne(ExtensionIdentifier::fromString('acme/public-theme')),
            '3.2.1',
            str_repeat('b', 64),
        );
        $extensions->setSiteThemePath('default', '/runtime/acme/public-theme/templates/site', 'acme/public-theme');
        $projection = new StudioPublishedTheme(
            $settings,
            $extensions,
            new StudioBuiltInThemeRelease(str_repeat('a', 64)),
        );

        $first = $projection->reference(SiteContext::fromString('default'));
        self::assertSame('acme/public-theme', $first->id);
        self::assertSame('3.2.1', $first->version);
        self::assertTrue($first->matches($first->document()));

        $settingsDocument['presentation']['active_scheme'] = 'ocean';
        $changed = $projection->reference(SiteContext::fromString('default'));
        self::assertNotSame($first->revision, $changed->revision);
        self::assertFalse($changed->matches($first->document()));
    }

    /**
     * The Studio projection fails closed if a SiteSettings implementation violates its validated contract.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testMissingValidatedPresentationIsRefused(): void
    {
        $settings = $this->createStub(SiteSettings::class);
        $settings->method('current')->willReturn([]);
        $projection = new StudioPublishedTheme(
            $settings,
            new ActiveExtensionSet(new ExtensionContributionRegistrySet(withCore: false)),
            new StudioBuiltInThemeRelease(str_repeat('a', 64)),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('validated public presentation');
        $projection->reference(SiteContext::fromString('default'));
    }

    /**
     * Require one malformed deployment coordinate to stop with its exact stable diagnostic.
     *
     * @param   callable(): mixed  $operation  Theme operation that must fail closed.
     * @param   string             $message    Exact expected deployment diagnostic.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function assertRuntimeFailure(callable $operation, string $message): void
    {
        try {
            $operation();
            self::fail('The malformed built-in public theme deployment was accepted.');
        } catch (RuntimeException $failure) {
            self::assertSame($message, $failure->getMessage());
        }
    }
}

/**
 * Empty provider retained only to attach a signed release to the active-set fixture.
 *
 * @since  2.0.0
 */
final readonly class StudioThemeProviderProbe implements ExtensionServiceProvider
{
    /**
     * Register no services.
     *
     * @param   ExtensionContainer  $container  Restricted container intentionally unchanged.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function register(ExtensionContainer $container): void
    {
    }
}
