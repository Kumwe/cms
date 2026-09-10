<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Navigation\Application;

use DateTimeImmutable;
use InvalidArgumentException;
use Kumwe\App\Application\Authorization\AuthorizationResource;
use Kumwe\App\Application\Authorization\ResourceSiteOwnershipWriter;
use Kumwe\App\Application\Persistence\TransactionManager;
use Kumwe\App\Audit\Application\AuditRecorder;
use Kumwe\App\Audit\Domain\AuditEvent;
use Kumwe\App\Navigation\Application\MenuItemRecord;
use Kumwe\App\Navigation\Application\MenuRecord;
use Kumwe\App\Navigation\Application\NavigationRepository;
use Kumwe\App\Navigation\Application\NavigationService;
use Kumwe\App\Navigation\Application\NavigationVersionConflict;
use Kumwe\App\Tests\Support\AuthorizationContext;
use Kumwe\Context\Value\SiteContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

#[CoversClass(NavigationService::class)]
#[UsesClass(AuditEvent::class)]
#[UsesClass(MenuItemRecord::class)]
#[UsesClass(MenuRecord::class)]
final class NavigationServiceTest extends TestCase
{
    private const ACTOR = '018f22e2-7c8b-7ab0-8f3a-88e8026bb301';
    private const MENU = '018f22e2-7c8b-7ab0-8f3a-88e8026bb401';
    private const ITEM = '018f22e2-7c8b-7ab0-8f3a-88e8026bb402';
    private const PARENT = '018f22e2-7c8b-7ab0-8f3a-88e8026bb403';

    public function testCreatesNormalizedMenuInsideAuditedTransaction(): void
    {
        $repository = $this->createMock(NavigationRepository::class);
        $repository->expects(self::once())->method('insertMenu')->with(self::callback(
            static fn (MenuRecord $menu): bool => $menu->handle === 'main_menu'
                && $menu->title === 'Main menu'
                && $menu->version === 1,
        ));
        $audit = $this->createMock(AuditRecorder::class);
        $audit->expects(self::once())->method('record')->with(self::callback(
            static fn (AuditEvent $event): bool => $event->actorId() === self::ACTOR
                && $event->action() === 'navigation.menu.create'
                && $event->subjectType() === 'menu',
        ));

        $menu = $this->service($repository, $audit)->createMenu($this->context(), ' Main_Menu ', ' Main menu ');

        self::assertSame('main_menu', $menu->handle);
        self::assertSame('Main menu', $menu->title);
        self::assertSame(1, $menu->version);
    }

    public function testMovesDescendantPathsWhenParentOrSlugChanges(): void
    {
        $now = new DateTimeImmutable('2026-08-04T10:00:00+00:00');
        $stored = new MenuItemRecord(
            self::ITEM,
            self::MENU,
            null,
            'Guides',
            'guides',
            '/guides',
            0,
            4,
            $now->modify('-1 day'),
            $now->modify('-1 hour'),
            'url',
            null,
            'https://example.com/guides',
        );
        $repository = $this->createMock(NavigationRepository::class);
        $repository->expects(self::once())->method('item')->with(self::ITEM)->willReturn($stored);
        $repository->expects(self::once())->method('assertMoveIsAcyclic')->with(
            self::ITEM,
            self::MENU,
            self::PARENT,
        );
        $repository->expects(self::once())->method('pathForParent')->with(
            self::MENU,
            self::PARENT,
            'documentation',
        )->willReturn('/resources/documentation');
        $repository->expects(self::once())->method('updateItem')->with(self::callback(
            static fn (MenuItemRecord $item): bool => $item->path === '/resources/documentation'
                && $item->version === 5
                && $item->targetType === 'url'
                && $item->targetUrl === 'https://example.com/guides',
        ), 4);
        $repository->expects(self::once())->method('moveDescendantPaths')->with(
            self::ITEM,
            '/guides',
            '/resources/documentation',
            $now,
        );
        $audit = $this->createMock(AuditRecorder::class);
        $audit->expects(self::once())->method('record')->with(self::callback(
            static fn (AuditEvent $event): bool => $event->action() === 'navigation.item.update'
                && $event->metadata()['version'] === 5,
        ));

        $updated = $this->service($repository, $audit, $now)->updateItem(
            $this->context(),
            self::ITEM,
            4,
            self::PARENT,
            'Documentation',
            'documentation',
            2,
        );

        self::assertSame('/resources/documentation', $updated->path);
        self::assertSame(5, $updated->version);
    }

    public function testCreatesAValidatedAnchorTarget(): void
    {
        $now = new DateTimeImmutable('2026-08-04T10:00:00+00:00');
        $repository = $this->createMock(NavigationRepository::class);
        $repository->expects(self::once())->method('menu')->with(self::MENU)->willReturn(new MenuRecord(
            self::MENU,
            'main',
            'Main',
            1,
            $now,
            $now,
        ));
        $repository->expects(self::once())->method('pathForParent')->with(
            self::MENU,
            null,
            'platform',
        )->willReturn('/platform');
        $repository->expects(self::once())->method('insertItem')->with(self::callback(
            static fn (MenuItemRecord $item): bool => $item->targetType === 'anchor'
                && $item->contentId === null
                && $item->targetUrl === '#platform',
        ));

        $item = $this->service(
            $repository,
            $this->createStub(AuditRecorder::class),
            $now,
        )->createItem(
            $this->context(),
            self::MENU,
            null,
            'Platform',
            'platform',
            0,
            'anchor',
            null,
            '#platform',
        );

        self::assertSame('anchor', $item->targetType);
        self::assertSame('#platform', $item->targetUrl);
    }

    public function testRejectsAnUnsafeUrlTargetBeforeWriting(): void
    {
        $now = new DateTimeImmutable('2026-08-04T10:00:00+00:00');
        $repository = $this->createMock(NavigationRepository::class);
        $repository->expects(self::once())->method('menu')->with(self::MENU)->willReturn(new MenuRecord(
            self::MENU,
            'main',
            'Main',
            1,
            $now,
            $now,
        ));
        $repository->expects(self::never())->method('pathForParent');
        $repository->expects(self::never())->method('insertItem');

        $this->expectException(InvalidArgumentException::class);

        $this->service(
            $repository,
            $this->createStub(AuditRecorder::class),
            $now,
        )->createItem(
            $this->context(),
            self::MENU,
            null,
            'Unsafe',
            'unsafe',
            0,
            'url',
            null,
            'javascript:alert(1)',
        );
    }

    public function testRejectsReservedPublicRoutePrefixes(): void
    {
        $now = new DateTimeImmutable('2026-08-04T10:00:00+00:00');
        $repository = $this->createMock(NavigationRepository::class);
        $repository->expects(self::once())->method('menu')->with(self::MENU)->willReturn(new MenuRecord(
            self::MENU,
            'main',
            'Main',
            1,
            $now,
            $now,
        ));
        $repository->method('pathForParent')->willReturn('/administrator');
        $repository->expects(self::never())->method('insertItem');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('reserved system prefix');

        $this->service($repository, $this->createStub(AuditRecorder::class), $now)->createItem(
            $this->context(),
            self::MENU,
            null,
            'Administrator page',
            'administrator',
            0,
        );
    }

    public function testRejectsStaleVersionBeforeMoveOrWrite(): void
    {
        $now = new DateTimeImmutable('2026-08-04T10:00:00+00:00');
        $repository = $this->createMock(NavigationRepository::class);
        $repository->method('item')->willReturn(new MenuItemRecord(
            self::ITEM,
            self::MENU,
            null,
            'Guides',
            'guides',
            '/guides',
            0,
            4,
            $now,
            $now,
        ));
        $repository->expects(self::never())->method('assertMoveIsAcyclic');
        $repository->expects(self::never())->method('updateItem');

        $this->expectException(NavigationVersionConflict::class);

        $this->service($repository, $this->createStub(AuditRecorder::class), $now)->updateItem(
            $this->context(),
            self::ITEM,
            3,
            null,
            'Guides',
            'guides',
            0,
        );
    }

    public function testMenuDeletionRemovesCascadedItemOwnershipInsideTheTransaction(): void
    {
        $now = new DateTimeImmutable('2026-08-04T10:00:00+00:00');
        $repository = $this->createMock(NavigationRepository::class);
        $repository->expects(self::once())->method('menu')->with(self::MENU)->willReturn(new MenuRecord(
            self::MENU,
            'main',
            'Main',
            3,
            $now,
            $now,
        ));
        $repository->expects(self::once())->method('itemIdsForMenuDeletion')->with(self::MENU, 3)->willReturn([
            self::ITEM,
            self::PARENT,
        ]);
        $repository->expects(self::once())->method('deleteMenu')->with(self::MENU, 3);
        $removed = [];
        $ownership = $this->createMock(ResourceSiteOwnershipWriter::class);
        $ownership->expects(self::exactly(3))->method('remove')->with(
            self::callback(static function (AuthorizationResource $resource) use (&$removed): bool {
                $removed[] = $resource->type() . ':' . $resource->identifier();

                return true;
            }),
            self::callback(static fn (SiteContext $site): bool => $site->identifier() === SiteContext::DEFAULT),
        );

        $this->service(
            $repository,
            $this->createStub(AuditRecorder::class),
            $now,
            $ownership,
        )->deleteMenu($this->context(), self::MENU, 3);

        self::assertSame([
            'menu_item:' . self::ITEM,
            'menu_item:' . self::PARENT,
            'menu:' . self::MENU,
        ], $removed);
    }

    public function testItemDeletionRemovesExactOwnership(): void
    {
        $now = new DateTimeImmutable('2026-08-04T10:00:00+00:00');
        $repository = $this->createMock(NavigationRepository::class);
        $repository->expects(self::once())->method('item')->with(self::ITEM)->willReturn(new MenuItemRecord(
            self::ITEM,
            self::MENU,
            null,
            'One',
            'one',
            '/one',
            0,
            2,
            $now,
            $now,
        ));
        $repository->expects(self::once())->method('deleteItem')->with(self::ITEM, 2);
        $ownership = $this->createMock(ResourceSiteOwnershipWriter::class);
        $ownership->expects(self::once())->method('remove')->with(
            self::callback(static fn (AuthorizationResource $resource): bool => $resource->type() === 'menu_item'
                && $resource->identifier() === self::ITEM),
            self::callback(static fn (SiteContext $site): bool => $site->identifier() === SiteContext::DEFAULT),
        );

        $this->service(
            $repository,
            $this->createStub(AuditRecorder::class),
            $now,
            $ownership,
        )->deleteItem($this->context(), self::ITEM, 2);
    }

    private function service(
        NavigationRepository $repository,
        AuditRecorder $audit,
        ?DateTimeImmutable $now = null,
        ?ResourceSiteOwnershipWriter $ownership = null,
    ): NavigationService {
        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn($now ?? new DateTimeImmutable('2026-08-04T10:00:00+00:00'));
        $transactions = $this->createStub(TransactionManager::class);
        $transactions->method('transactional')->willReturnCallback(
            static fn (callable $operation): mixed => $operation(),
        );

        return new NavigationService(
            $repository,
            $audit,
            $transactions,
            $clock,
            AuthorizationContext::gateway(),
            $ownership ?? AuthorizationContext::ownershipWriter(),
        );
    }

    private function context(): \Kumwe\Context\Value\ExecutionContext
    {
        return AuthorizationContext::human(['navigation.manage'], self::ACTOR);
    }
}
