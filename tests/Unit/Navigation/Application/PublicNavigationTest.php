<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Navigation\Application;

use DateTimeImmutable;
use Kumwe\App\Application\Authorization\AuthorizationResource;
use Kumwe\App\Application\Authorization\OwnershipScope;
use Kumwe\App\Application\Authorization\ResourceSiteOwnership;
use Kumwe\App\Navigation\Application\MenuItemRecord;
use Kumwe\App\Navigation\Application\MenuRecord;
use Kumwe\App\Navigation\Application\NavigationRepository;
use Kumwe\App\Navigation\Application\PublicNavigation;
use Kumwe\Context\Value\SiteContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PublicNavigation::class)]
#[UsesClass(MenuItemRecord::class)]
#[UsesClass(MenuRecord::class)]
#[UsesClass(AuthorizationResource::class)]
final class PublicNavigationTest extends TestCase
{
    public function testBuildsTheManagedMainMenuRecursivelyInPositionOrder(): void
    {
        $repository = $this->createMock(NavigationRepository::class);
        $time = new DateTimeImmutable('2026-08-06T12:00:00+00:00');
        $menu = new MenuRecord('main-menu', 'main', 'Main menu', 1, $time, $time);
        $repository->expects(self::exactly(3))->method('menus')->willReturn([
            new MenuRecord('foreign', 'main', 'Other site menu', 1, $time, $time),
            $menu,
        ]);
        $repository->expects(self::exactly(3))->method('items')->with('main-menu')->willReturn([
            new MenuItemRecord(
                'about',
                'main-menu',
                null,
                'About',
                'about',
                '/about',
                20,
                1,
                $time,
                $time,
                'content',
                'about-content',
            ),
            new MenuItemRecord(
                'team',
                'main-menu',
                'about',
                'Team',
                'team',
                '/about/team',
                10,
                1,
                $time,
                $time,
                'content',
                'team-content',
            ),
            new MenuItemRecord(
                'home',
                'main-menu',
                null,
                'Home',
                'home',
                '/home',
                10,
                1,
                $time,
                $time,
                'content',
                'home-content',
            ),
            new MenuItemRecord(
                'platform',
                'main-menu',
                null,
                'Platform',
                'platform',
                '/platform',
                30,
                1,
                $time,
                $time,
                'anchor',
                'home-content',
                '#platform',
            ),
            new MenuItemRecord(
                'administrator',
                'main-menu',
                null,
                'Administrator',
                'administrator',
                '/administrator',
                40,
                1,
                $time,
                $time,
                'url',
                null,
                '/administrator',
            ),
        ]);

        $ownership = new class implements ResourceSiteOwnership {
            public function scopeFor(AuthorizationResource $resource): OwnershipScope
            {
                return OwnershipScope::site($resource->identifier() === 'foreign'
                    ? SiteContext::fromString('other-site')
                    : SiteContext::default());
            }
        };
        $navigation = new PublicNavigation($repository, $ownership, SiteContext::default());
        $items = $navigation->items('home-content');

        self::assertSame(['Home', 'About', 'Platform', 'Administrator'], array_column($items, 'title'));
        self::assertSame('/', $items[0]['href']);
        self::assertSame('Team', $items[1]['children'][0]['title']);
        self::assertSame('/about/team', $items[1]['children'][0]['href']);
        self::assertSame('/about/team', $items[1]['children'][0]['path']);
        self::assertSame('content', $items[1]['children'][0]['target_type']);
        self::assertSame('team-content', $items[1]['children'][0]['content_id']);
        self::assertSame('team-content', $navigation->contentIdForPath('/about/team/'));
        self::assertSame('/about/team', $navigation->pathForContent('team-content'));
        self::assertSame('/#platform', $items[2]['href']);
        self::assertSame('/administrator', $items[3]['href']);
    }

    public function testUsesTheDatabaseSelectedMenuHandle(): void
    {
        $repository = $this->createStub(NavigationRepository::class);
        $time = new DateTimeImmutable('2026-08-07T17:00:00+00:00');
        $repository->method('menus')->willReturn([
            new MenuRecord('main-menu', 'main', 'Main', 1, $time, $time),
            new MenuRecord('corporate-menu', 'corporate', 'Corporate', 1, $time, $time),
        ]);
        $repository->method('items')->willReturnMap([
            ['main-menu', []],
            ['corporate-menu', [
                new MenuItemRecord(
                    'corporate-home',
                    'corporate-menu',
                    null,
                    'Corporate home',
                    'home',
                    '/home',
                    0,
                    1,
                    $time,
                    $time,
                    'content',
                    'home-content',
                ),
            ]],
        ]);

        $items = (new PublicNavigation($repository))->items('home-content', 'corporate');

        self::assertSame(['Corporate home'], array_column($items, 'title'));
        self::assertSame('/', $items[0]['href']);
    }
}
