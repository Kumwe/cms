<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Studio\Domain\Projection;

use InvalidArgumentException;
use Kumwe\App\Studio\Domain\Projection\ContentBlueprintBinding;
use Kumwe\Context\Value\SiteContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Proves a Content-to-Blueprint binding admits only exact coordinates from the pinned Studio grammar.
 *
 * @since  2.0.0
 */
#[CoversClass(ContentBlueprintBinding::class)]
final class ContentBlueprintBindingTest extends TestCase
{
    /**
     * A complete immutable coordinate preserves every Content, Blueprint and optimistic revision component.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testExactBindingCoordinateIsPreserved(): void
    {
        $binding = new ContentBlueprintBinding(
            SiteContext::fromString('Publisher-Namibia'),
            '018f22e2-7c8b-7ab0-8f3a-88e8026be100',
            12,
            'kumwe.blueprints/article:wide',
            '2.4.1-rc.2+build.19',
            'artifact-revision-88',
            7,
        );

        self::assertSame('publisher-namibia', $binding->site->identifier());
        self::assertSame('018f22e2-7c8b-7ab0-8f3a-88e8026be100', $binding->contentTypeId);
        self::assertSame(12, $binding->contentTypeVersion);
        self::assertSame('kumwe.blueprints/article:wide', $binding->blueprintId);
        self::assertSame('2.4.1-rc.2+build.19', $binding->blueprintVersion);
        self::assertSame('artifact-revision-88', $binding->blueprintRevision);
        self::assertSame(7, $binding->revision);
    }

    /**
     * Every malformed coordinate is refused at construction instead of entering persistence.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testMalformedBindingCoordinatesAreRefused(): void
    {
        $cases = [
            'content UUID' => ['not-a-uuid', 1, 'blueprint/article', '1.0.0', null, 1],
            'content version' => ['018f22e2-7c8b-7ab0-8f3a-88e8026be100', 0, 'blueprint/article', '1.0.0', null, 1],
            'binding revision' => ['018f22e2-7c8b-7ab0-8f3a-88e8026be100', 1, 'blueprint/article', '1.0.0', null, 0],
            'Blueprint ID' => ['018f22e2-7c8b-7ab0-8f3a-88e8026be100', 1, 'bad id', '1.0.0', null, 1],
            'semantic version' => [
                '018f22e2-7c8b-7ab0-8f3a-88e8026be100',
                1,
                'blueprint/article',
                '01.0.0',
                null,
                1,
            ],
            'empty Blueprint revision' => [
                '018f22e2-7c8b-7ab0-8f3a-88e8026be100',
                1,
                'blueprint/article',
                '1.0.0',
                '',
                1,
            ],
            'long Blueprint revision' => [
                '018f22e2-7c8b-7ab0-8f3a-88e8026be100',
                1,
                'blueprint/article',
                '1.0.0',
                str_repeat('r', 201),
                1,
            ],
        ];

        foreach ($cases as $label => [$typeId, $typeVersion, $blueprintId, $version, $artifactRevision, $revision]) {
            try {
                new ContentBlueprintBinding(
                    SiteContext::default(),
                    $typeId,
                    $typeVersion,
                    $blueprintId,
                    $version,
                    $artifactRevision,
                    $revision,
                );
                self::fail(sprintf('The malformed %s was accepted.', $label));
            } catch (InvalidArgumentException $failure) {
                self::assertNotSame('', $failure->getMessage(), $label);
            }
        }
    }

    /**
     * Prototype-pollution member names remain forbidden even though they match the lexical ID pattern.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testReservedStudioStableIdentifiersAreRefused(): void
    {
        foreach (['__proto__', 'prototype', 'constructor'] as $identifier) {
            try {
                new ContentBlueprintBinding(
                    SiteContext::default(),
                    '018f22e2-7c8b-7ab0-8f3a-88e8026be100',
                    1,
                    $identifier,
                    '1.0.0',
                    null,
                    1,
                );
                self::fail(sprintf('The reserved Studio identifier %s was accepted.', $identifier));
            } catch (InvalidArgumentException $failure) {
                self::assertStringContainsString('Blueprint ID', $failure->getMessage());
            }
        }
    }
}
