<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\Infrastructure;

use DateTimeImmutable;
use Kumwe\App\BusinessReporting\Infrastructure\FilesystemExportArtifactStorage;
use Kumwe\App\Media\Infrastructure\FilesystemMediaStorage;
use Kumwe\Context\Value\SiteContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

/**
 * Writes into storage that cannot accept writes, and checks what is left behind when it refuses.
 *
 * The atomic-write code has always thrown on a failed copy, chmod or rename, and production containers
 * run read-only with tmpfs — but nothing had ever made a storage root refuse a write, so the branch that
 * matters most on the worst day was the one branch never executed. Three different refusals are used
 * here because they are genuinely different failures and no single one of them can be produced in every
 * environment: a volume that is not there at all, a root the process may not write to, and a filesystem
 * mounted read-only. Each is produced by the operating system rather than by a stub, and each is skipped
 * with its reason stated where this machine cannot produce it, rather than quietly passing.
 *
 * What is asserted in every case is the same contract: the failure is typed and carries an operator
 * message, nothing partial is published, and no temporary file is left behind for somebody to find.
 *
 * @since  2.0.0
 */
#[CoversClass(FilesystemMediaStorage::class)]
#[CoversClass(FilesystemExportArtifactStorage::class)]
final class UnwritableStorageIntegrationTest extends TestCase
{
    public function testAStorageRootThatIsNotThereFailsClosedWithoutDebris(): void
    {
        $room = $this->room();

        try {
            // A path whose parent is a regular file: every write below it fails with ENOTDIR, for every
            // user including root, which is what a missing or broken mount looks like from inside PHP.
            $blocker = $room . '/volume';
            self::assertNotFalse(file_put_contents($blocker, 'not a directory'));
            $root = $blocker . '/media';

            $media = new FilesystemMediaStorage($root);
            $upload = $this->upload($room);
            try {
                $media->store(SiteContext::default(), $upload, 'drill.pdf', 1_048_576, new DateTimeImmutable());
                self::fail('A storage root that is not there must not report a stored asset.');
            } catch (RuntimeException $failure) {
                self::assertSame('The media directory could not be created.', $failure->getMessage());
            }
            self::assertSame([], $this->debris($room), 'A refused upload must leave nothing behind.');

            try {
                new FilesystemExportArtifactStorage($blocker . '/exports');
                self::fail('An export store must refuse a directory it cannot create.');
            } catch (RuntimeException $failure) {
                self::assertSame('The export artifact directory cannot be created.', $failure->getMessage());
            }
        } finally {
            $this->cleanUp($room);
        }
    }

    public function testAMediaRootTheProcessMayNotWriteToFailsClosedWithoutDebris(): void
    {
        if (posix_geteuid() === 0) {
            self::markTestSkipped(
                'Permission bits do not restrain a process running as root, so this drill would pass '
                . 'without proving anything; the continuous-integration run is unprivileged and executes it.',
            );
        }
        $room = $this->room();
        $root = $room . '/media';
        self::assertTrue(mkdir($root . '/' . SiteContext::DEFAULT, 0o700, true));

        try {
            self::assertTrue(chmod($root . '/' . SiteContext::DEFAULT, 0o500));
            $media = new FilesystemMediaStorage($root);
            $upload = $this->upload($room);

            try {
                $media->store(SiteContext::default(), $upload, 'drill.pdf', 1_048_576, new DateTimeImmutable());
                self::fail('A read-only media directory must not report a stored asset.');
            } catch (RuntimeException $failure) {
                self::assertSame('The media file could not be stored.', $failure->getMessage());
            }

            self::assertSame(
                [],
                $this->debris($root . '/' . SiteContext::DEFAULT),
                'A refused upload must leave no temporary payload in the directory it failed to write.',
            );
        } finally {
            @chmod($root . '/' . SiteContext::DEFAULT, 0o700);
            $this->cleanUp($room);
        }
    }

    public function testAReadOnlyFilesystemFailsClosedWithoutDebris(): void
    {
        $room = $this->room();
        $mount = $room . '/volume';
        self::assertTrue(mkdir($mount, 0o700, true));
        if (!$this->mountReadOnly($mount)) {
            $this->cleanUp($room);
            self::markTestSkipped(
                'This machine cannot mount a read-only filesystem without privileges the test runner '
                . 'does not hold; the unwritable-root drill above covers the same refusal where it can.',
            );
        }

        try {
            $media = new FilesystemMediaStorage($mount . '/media');
            $upload = $this->upload(sys_get_temp_dir());

            try {
                $media->store(SiteContext::default(), $upload, 'drill.pdf', 1_048_576, new DateTimeImmutable());
                self::fail('A read-only filesystem must not report a stored asset.');
            } catch (RuntimeException $failure) {
                self::assertSame('The media directory could not be created.', $failure->getMessage());
            }
            @unlink($upload);

            try {
                new FilesystemExportArtifactStorage($mount . '/exports');
                self::fail('An export store must refuse a read-only filesystem.');
            } catch (RuntimeException $failure) {
                self::assertSame('The export artifact directory cannot be created.', $failure->getMessage());
            }

            self::assertSame([], $this->debris($mount), 'A read-only volume must be left exactly as it was.');
        } finally {
            $this->unmount($mount);
            $this->cleanUp($room);
        }
    }

    /**
     * Try to put a read-only filesystem at the given path, and report whether it worked.
     */
    private function mountReadOnly(string $path): bool
    {
        if (posix_geteuid() !== 0) {
            return false;
        }
        $output = [];
        $status = 0;
        exec(sprintf('mount -t tmpfs -o ro,size=1m tmpfs %s 2>/dev/null', escapeshellarg($path)), $output, $status);

        return $status === 0;
    }

    private function unmount(string $path): void
    {
        $output = [];
        $status = 0;
        exec(sprintf('umount %s 2>/dev/null', escapeshellarg($path)), $output, $status);
    }

    /**
     * Write a real, type-detectable upload the media store will accept on a healthy volume.
     *
     * The bytes are a small readable document rather than an encoded blob, so nothing in this fixture
     * looks like key material to a scanner reading the repository.
     */
    private function upload(string $room): string
    {
        $path = $room . '/upload-' . bin2hex(random_bytes(6)) . '.pdf';
        $document = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";
        self::assertNotFalse(file_put_contents($path, $document));

        return $path;
    }

    /**
     * List anything in a directory that is not the drill's own upload, so debris cannot hide.
     *
     * @return  list<string>
     */
    private function debris(string $directory): array
    {
        $found = [];
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || str_starts_with($entry, 'upload-')) {
                continue;
            }
            if ($entry === 'media' || $entry === 'volume') {
                continue;
            }
            $found[] = $entry;
        }

        return $found;
    }

    private function room(): string
    {
        $room = sys_get_temp_dir() . '/kumwe-unwritable-storage-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($room, 0o700, true));

        return $room;
    }

    private function cleanUp(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory . '/' . $entry;
            try {
                if (is_dir($path)) {
                    $this->cleanUp($path);
                    continue;
                }
                @unlink($path);
            } catch (Throwable) {
                // A drill that cannot tidy up must not fail the run it was proving something about.
            }
        }
        @rmdir($directory);
    }
}
