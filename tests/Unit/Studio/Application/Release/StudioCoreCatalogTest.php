<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Studio\Application\Release;

use Kumwe\App\Studio\Application\Release\StudioCoreCatalog;
use Kumwe\Producer\Render\BlockCoordinate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Proves the materialized first-party catalog is read exactly and refused when it drifts.
 *
 * @since  2.0.0
 */
#[CoversClass(StudioCoreCatalog::class)]
final class StudioCoreCatalogTest extends TestCase
{
    /**
     * The committed record decodes for the pinned release and exposes exact Producer coordinates.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheCommittedRecordDescribesThePinnedRelease(): void
    {
        $root = dirname(__DIR__, 5);
        $record = (string) file_get_contents($root . '/resources/studio-contract/studio-release.json');
        $release = json_decode($record, true);
        self::assertIsArray($release);
        self::assertIsString($release['release']);

        $catalog = StudioCoreCatalog::fromFile(
            $root . '/resources/studio-contract/core-catalog.json',
            $release['release'],
        );

        self::assertSame($release['release'], $catalog->release);
        self::assertGreaterThanOrEqual(40, count($catalog->blocks));
        self::assertTrue($catalog->hasBlock('studio.core/heading'));
        self::assertTrue($catalog->hasBlock('studio.core/section'));
        self::assertFalse($catalog->hasBlock('core/field-text'));
        self::assertTrue($catalog->hasPattern('studio.pattern/hero'));
        self::assertFalse($catalog->hasPattern('core/pattern-empty-section'));
        $coordinates = $catalog->blockCoordinates();
        self::assertCount(count($catalog->blocks), $coordinates);
        self::assertContainsOnlyInstancesOf(BlockCoordinate::class, $coordinates);
        $section = array_values(array_filter(
            $coordinates,
            static fn (BlockCoordinate $coordinate): bool => $coordinate->type === 'studio.core/section',
        ));
        self::assertCount(1, $section);
        self::assertSame('1.0.0', $section[0]->version);
        self::assertSame('layout-section-r1', $section[0]->revision);
    }

    /**
     * A record that is malformed, unordered, repeated or for another release is refused.
     *
     * @param   string  $json  Candidate record bytes.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    #[DataProvider('refusedRecords')]
    public function testDriftedRecordsAreRefused(string $json): void
    {
        $this->expectException(RuntimeException::class);

        StudioCoreCatalog::fromJson($json, '0.1.0-beta.3');
    }

    /**
     * Supply one refused record per closed rule.
     *
     * @return  iterable<string, array{string}>  Named refusals.
     *
     * @since   2.0.0
     */
    public static function refusedRecords(): iterable
    {
        $block = static fn (string $type, string $revision = 'production-x-r1'): string => json_encode([
            'type' => $type,
            'version' => '1.0.0',
            'revision' => $revision,
        ], JSON_THROW_ON_ERROR);
        $record = static fn (
            string $blocks,
            string $release = '0.1.0-beta.3',
            string $kind = 'studio-core-catalog',
        ): string => sprintf('{"kind":"%s","release":"%s","blocks":[%s],"patterns":[]}', $kind, $release, $blocks);

        yield 'not JSON' => ['{"kind":'];
        yield 'another kind' => [$record($block('studio.core/a'), kind: 'studio-release')];
        yield 'another release' => [$record($block('studio.core/a'), release: '0.1.0-beta.4')];
        yield 'no blocks' => [$record('')];
        yield 'unordered blocks' => [$record($block('studio.core/b') . ',' . $block('studio.core/a'))];
        yield 'repeated block' => [$record($block('studio.core/a') . ',' . $block('studio.core/a'))];
        yield 'unqualified type' => [$record($block('heading'))];
        yield 'empty revision' => [$record($block('studio.core/a', ''))];
        yield 'extra member' => [$record('{"type":"studio.core/a","version":"1.0.0","revision":"r1","owner":"x"}')];
        yield 'range version' => [$record('{"type":"studio.core/a","version":"^1.0.0","revision":"r1"}')];
    }

    /**
     * An absent record file is refused rather than treated as an empty catalog.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAMissingRecordIsRefused(): void
    {
        $this->expectException(RuntimeException::class);

        $missing = sys_get_temp_dir() . '/kumwe-missing-' . bin2hex(random_bytes(6)) . '.json';
        StudioCoreCatalog::fromFile($missing, '0.1.0-beta.3');
    }
}
