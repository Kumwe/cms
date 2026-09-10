<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Governance;

use Kumwe\App\Tools\Governance\ComposerLock;
use Kumwe\App\Tools\Governance\GovernanceViolation;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Holds `tools/Governance/ComposerLock.php` to the immutable coordinates a capability-index entry needs.
 *
 * @since  2.0.0
 */
#[CoversNothing]
final class ComposerLockTest extends TestCase
{
    /**
     * Load the governance classes once.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 3) . '/tools/Governance/bootstrap.php';
    }

    /**
     * The repository lock exposes the admitted Kumwe packages, sorted, with full references and PSR-4 roots.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheRepositoryLockExposesTheKumwePackages(): void
    {
        $path = GovernanceFixture::repositoryRoot() . '/composer.lock';
        $lock = ComposerLock::read($path);

        self::assertSame(hash_file('sha256', $path), $lock->sha256());
        self::assertSame($path, $lock->path());
        self::assertSame(
            [
                'kumwe/access-context',
                'kumwe/canonical-json',
                'kumwe/computation',
                'kumwe/conversion',
                'kumwe/extension-sdk',
                'kumwe/producer',
                'kumwe/sequence',
            ],
            array_keys($lock->packages()),
        );
        $conversion = $lock->package('kumwe/conversion');
        self::assertNotNull($conversion);
        self::assertSame('v0.1.2', $conversion['version']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{40}$/', $conversion['source']['reference']);
        self::assertSame($conversion['source']['reference'], $conversion['dist']['reference']);
        self::assertSame(['Kumwe\\Conversion\\' => ['src/']], $conversion['psr4']);
        self::assertSame(['Apache-2.0'], $conversion['license']);
        self::assertNull($lock->package('kumwe/absent'));
    }

    /**
     * The fixture lock is read the same way, and a development-section Kumwe package is included.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testDevelopmentPackagesAreLockedPackagesToo(): void
    {
        $root = GovernanceFixture::copy();
        try {
            $bytes = GovernanceFixture::read($root, 'composer.lock');
            /** @var array{packages: list<array<string, mixed>>, "packages-dev": list<array<string, mixed>>} $lock */
            $lock = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
            $moved = array_pop($lock['packages']);
            $lock['packages-dev'][] = $moved;
            GovernanceFixture::write($root, 'composer.lock', json_encode($lock, JSON_THROW_ON_ERROR));

            $read = ComposerLock::read($root . '/composer.lock');

            self::assertSame(['kumwe/example-legacy', 'kumwe/example-v2'], array_keys($read->packages()));
        } finally {
            GovernanceFixture::remove($root);
        }
    }

    /**
     * A missing lock, a short reference and a package without a PSR-4 root are refused.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testIncompleteCoordinatesAreRefused(): void
    {
        try {
            ComposerLock::read(sys_get_temp_dir() . '/kumwe-absent/composer.lock');
            self::fail('A missing lock must be refused.');
        } catch (GovernanceViolation $violation) {
            self::assertStringContainsString('composer.lock is missing', $violation->getMessage());
        }

        $root = GovernanceFixture::copy();
        try {
            $bytes = GovernanceFixture::read($root, 'composer.lock');
            /** @var array{packages: list<array<string, mixed>>} $lock */
            $lock = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
            $lock['packages'][0]['source']['reference'] = 'abc123';
            GovernanceFixture::write($root, 'composer.lock', json_encode($lock, JSON_THROW_ON_ERROR));
            try {
                ComposerLock::read($root . '/composer.lock');
                self::fail('A short reference must be refused.');
            } catch (GovernanceViolation $violation) {
                self::assertStringContainsString('not a full commit SHA', $violation->getMessage());
            }

            /** @var array{packages: list<array<string, mixed>>} $lock */
            $lock = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
            unset($lock['packages'][0]['autoload']);
            GovernanceFixture::write($root, 'composer.lock', json_encode($lock, JSON_THROW_ON_ERROR));
            try {
                ComposerLock::read($root . '/composer.lock');
                self::fail('A package without a PSR-4 root must be refused.');
            } catch (GovernanceViolation $violation) {
                self::assertStringContainsString('no PSR-4 autoload root', $violation->getMessage());
            }
        } finally {
            GovernanceFixture::remove($root);
        }
    }
}
