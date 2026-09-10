<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Media\Infrastructure;

use DateTimeImmutable;
use InvalidArgumentException;
use Kumwe\App\Media\Application\MediaAsset;
use Kumwe\App\Media\Infrastructure\FilesystemMediaStorage;
use Kumwe\Context\Value\SiteContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FilesystemMediaStorage::class)]
#[UsesClass(MediaAsset::class)]
final class FilesystemMediaStorageTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/kumwe-media-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->directory, 0700, true));
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->directory)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo) {
                continue;
            }
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->directory);
    }

    public function testStoresSiteScopedMediaWithImmutablePublicMetadata(): void
    {
        $source = $this->directory . '/source.png';
        $pixel = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
            true,
        );
        self::assertIsString($pixel);
        self::assertNotFalse(file_put_contents($source, $pixel));
        $storage = new FilesystemMediaStorage($this->directory . '/library');
        $asset = $storage->store(
            SiteContext::default(),
            $source,
            'Editorial hero.png',
            1024,
            new DateTimeImmutable('2026-08-06T12:00:00+00:00'),
        );

        self::assertSame('image/png', $asset->mimeType);
        self::assertSame('Editorial hero.png', $asset->name);
        self::assertSame($asset->id, $storage->all(SiteContext::default())[0]->id);
        self::assertSame(
            $asset->id,
            $storage->choices(SiteContext::default(), 'editorial', 50, 4096)[0]->id,
        );
        self::assertSame([], $storage->choices(SiteContext::default(), 'missing', 50, 4096));
        self::assertNull($storage->find(SiteContext::fromString('another-site'), $asset->id));
        self::assertStringContainsString(rawurlencode($asset->name), $asset->toArray()['url']);

        $storage->delete(SiteContext::default(), $asset->id);
        self::assertNull($storage->find(SiteContext::default(), $asset->id));
    }

    public function testRejectsExecutableOrUnknownUploads(): void
    {
        $source = $this->directory . '/payload.php';
        self::assertNotFalse(file_put_contents($source, '<?php echo "unsafe";'));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Only JPEG, PNG, GIF, WebP, AVIF and PDF');

        (new FilesystemMediaStorage($this->directory . '/library'))->store(
            SiteContext::default(),
            $source,
            'payload.php',
            1024,
            new DateTimeImmutable(),
        );
    }

    /**
     * Proves media choice search rejects an excessive limit before walking storage.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testChoiceSearchRejectsUnboundedWorkBeforeDirectoryIteration(): void
    {
        $storage = new FilesystemMediaStorage($this->directory . '/library');

        $this->expectException(InvalidArgumentException::class);
        $storage->choices(SiteContext::default(), '', 51, 4096);
    }

    public function testExposesBundledSvgAsReadOnlyMediaWithoutAllowingSvgUploads(): void
    {
        $id = '018f22e2-7c8b-7ab0-8f3a-88e8026bb710';
        $bundled = $this->directory . '/bundled/default';
        self::assertTrue(mkdir($bundled, 0700, true));
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1 1"><path d="M0 0h1v1H0z"/></svg>';
        self::assertNotFalse(file_put_contents($bundled . '/' . $id . '.svg', $svg));
        self::assertNotFalse(file_put_contents($bundled . '/' . $id . '.json', json_encode([
            'name' => 'brand.svg',
            'mime_type' => 'image/svg+xml',
            'extension' => 'svg',
            'size' => strlen($svg),
            'sha256' => hash('sha256', $svg),
            'created_at' => '2026-08-07T00:00:00+00:00',
        ], JSON_THROW_ON_ERROR)));
        $storage = new FilesystemMediaStorage($this->directory . '/library', $this->directory . '/bundled');

        $asset = $storage->find(SiteContext::default(), $id);

        self::assertNotNull($asset);
        self::assertSame('image/svg+xml', $asset->mimeType);
        self::assertFalse($asset->deletable);
        $storage->delete(SiteContext::default(), $id);
        self::assertNotNull($storage->find(SiteContext::default(), $id));

        $this->expectException(InvalidArgumentException::class);
        $storage->store(SiteContext::default(), $asset->path, 'brand.svg', 1024, new DateTimeImmutable());
    }
}
