<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Studio\Application\Projection;

use DateTimeImmutable;
use Kumwe\App\Audit\Application\AuditRecorder;
use Kumwe\Context\Value\SiteContext;
use Kumwe\App\Content\Application\ContentBrowseQuery;
use Kumwe\App\Content\Application\ContentPage;
use Kumwe\App\Content\Application\ContentRecord;
use Kumwe\App\Content\Application\ContentRepository;
use Kumwe\App\Content\Application\ContentSearchRepository;
use Kumwe\App\Content\Application\ContentService;
use Kumwe\App\Content\Domain\ContentEntry;
use Kumwe\App\Content\Domain\ContentStatus;
use Kumwe\App\Studio\Application\Host\StudioResourceSearchItem;
use Kumwe\App\Studio\Application\Host\StudioResourceSearchPage;
use Kumwe\App\Studio\Application\Projection\ContentStudioResourceSearchProvider;
use Kumwe\App\Tests\Support\AuthorizationContext;
use Kumwe\Transaction\Testing\ImmediateTransactionManager;
use Kumwe\App\Workflow\Domain\Workflow;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

/**
 * Proves the Content resource projection stays behind the authorized application-service boundary.
 *
 * @since  2.0.0
 */
#[CoversClass(ContentStudioResourceSearchProvider::class)]
#[UsesClass(ContentBrowseQuery::class)]
#[UsesClass(ContentEntry::class)]
#[UsesClass(ContentPage::class)]
#[UsesClass(ContentRecord::class)]
#[UsesClass(ContentService::class)]
#[UsesClass(StudioResourceSearchItem::class)]
#[UsesClass(StudioResourceSearchPage::class)]
final class ContentStudioResourceSearchProviderTest extends TestCase
{
    /**
     * Search translates an arbitrary Studio offset into deterministic authorized Content pages.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testSearchProjectsStableAuthorizedContentReferencesAcrossPageBoundaries(): void
    {
        $records = self::records(55);
        /** @var list<ContentBrowseQuery> $queries */
        $queries = [];
        /** @var ContentRepository&ContentSearchRepository&Stub $repository */
        $repository = self::createStubForIntersectionOfInterfaces([
            ContentRepository::class,
            ContentSearchRepository::class,
        ]);
        $repository
            ->method('searchForSite')
            ->willReturnCallback(
                static function (
                    SiteContext $site,
                    ContentBrowseQuery $query,
                    int $limit,
                    int $offset,
                ) use (
                    $records,
                    &$queries
                ): array {
                    unset($site);
                    $queries[] = $query;

                    return array_slice($records, $offset, $limit);
                },
            );
        $provider = new ContentStudioResourceSearchProvider($this->content($repository));
        $context = AuthorizationContext::human(['content.read']);

        $page = $provider->search($context, 'Entry', 49, 3);

        self::assertSame(ContentStudioResourceSearchProvider::RESOURCE_TYPE, $provider->resourceType());
        self::assertSame([
            'content-entry:018f22e2-7c8b-7ab0-8f3a-000000000050',
            'content-entry:018f22e2-7c8b-7ab0-8f3a-000000000051',
            'content-entry:018f22e2-7c8b-7ab0-8f3a-000000000052',
        ], array_map(
            static fn (StudioResourceSearchItem $item): string => $item->id,
            $page->items,
        ));
        self::assertSame(['Entry 050', 'Entry 051', 'Entry 052'], array_map(
            static fn (StudioResourceSearchItem $item): string => $item->label,
            $page->items,
        ));
        self::assertTrue($page->hasNext);
        self::assertNotEmpty($queries);
        foreach ($queries as $query) {
            self::assertSame('Entry', $query->search);
            self::assertSame('title_asc', $query->sort);
            self::assertSame(50, $query->perPage);
        }

        $last = $provider->search($context, 'Entry', 53, 10);

        self::assertSame([
            'content-entry:018f22e2-7c8b-7ab0-8f3a-000000000054',
            'content-entry:018f22e2-7c8b-7ab0-8f3a-000000000055',
        ], array_map(
            static fn (StudioResourceSearchItem $item): string => $item->id,
            $last->items,
        ));
        self::assertFalse($last->hasNext);
    }

    /**
     * Compose ContentService with its real policy gateway and a search-capable repository double.
     *
     * @param   ContentRepository&ContentSearchRepository  $repository  Search-capable Content store.
     *
     * @return  ContentService  Policy-aware Content application service.
     *
     * @since   2.0.0
     */
    private function content(ContentRepository&ContentSearchRepository $repository): ContentService
    {
        $clock = self::createStub(ClockInterface::class);
        $clock->method('now')->willReturn(new DateTimeImmutable('2026-08-25T12:00:00+00:00'));

        return new ContentService(
            $repository,
            self::createStub(AuditRecorder::class),
            new ImmediateTransactionManager(),
            $clock,
            new Workflow(),
            AuthorizationContext::gateway(),
            AuthorizationContext::ownershipWriter(),
        );
    }

    /**
     * Build deterministic active Content records in the same order requested by the provider.
     *
     * @param   int  $count  Number of records to create.
     *
     * @return  list<ContentRecord>  Ordered Content records.
     *
     * @since   2.0.0
     */
    private static function records(int $count): array
    {
        $at = new DateTimeImmutable('2026-08-25T12:00:00+00:00');
        $records = [];
        for ($number = 1; $number <= $count; $number++) {
            $suffix = str_pad((string) $number, 12, '0', STR_PAD_LEFT);
            $title = 'Entry ' . str_pad((string) $number, 3, '0', STR_PAD_LEFT);
            $records[] = new ContentRecord(
                ContentEntry::create(
                    '018f22e2-7c8b-7ab0-8f3a-' . $suffix,
                    $title,
                    'entry-' . $number,
                    ['body' => $title],
                    ContentStatus::Draft,
                ),
                ContentService::CORE_PAGE_TYPE_ID,
                ContentService::CORE_WORKFLOW_ID,
                $at,
                $at,
            );
        }

        return $records;
    }
}
