<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Presentation;

use DateTimeImmutable;
use Kumwe\App\Content\Application\ContentModelRepository;
use Kumwe\App\Content\Application\ContentRecord;
use Kumwe\App\Content\Domain\ContentEntry;
use Kumwe\App\Content\Domain\ContentStatus;
use Kumwe\App\Content\Domain\ContentTypeDefinition;
use Kumwe\App\Presentation\ContentLayoutCatalog;
use Kumwe\Context\Value\SiteContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Proves layout selection follows the record's content type and never breaks unknown types.
 *
 * @since  2.0.0
 */
#[CoversClass(ContentLayoutCatalog::class)]
final class ContentLayoutCatalogTest extends TestCase
{
    /**
     * Resolve each core layout handle to its template and default everything else to the page layout.
     *
     * @param   ?string  $handle    Content-type handle the repository reports, or null for a missing type.
     * @param   string   $template  Template name the catalog must answer.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    #[DataProvider('layouts')]
    public function testResolvesTheLayoutDeclaredByTheContentType(?string $handle, string $template): void
    {
        $models = $this->createStub(ContentModelRepository::class);
        $models->method('contentType')->willReturn($handle === null ? null : $this->definition($handle));

        $catalog = new ContentLayoutCatalog($models, 'default');

        self::assertSame($template, $catalog->templateFor($this->record()));
    }

    /**
     * Name every core layout mapping plus the unknown-type and missing-type fallbacks.
     *
     * @return  array<string, array{?string, string}>  Handle under test and the expected template.
     *
     * @since   2.0.0
     */
    public static function layouts(): array
    {
        return [
            'article layout' => ['article', 'article'],
            'document layout' => ['document', 'document'],
            'faq layout' => ['faq', 'faq'],
            'guide layout' => ['guide', 'guide'],
            'landing layout' => ['landing', 'landing'],
            'reference layout' => ['reference', 'reference'],
            'page keeps the general layout' => ['page', 'page'],
            'unknown handles keep the general layout' => ['newsletter', 'page'],
            'missing types keep the general layout' => [null, 'page'],
        ];
    }

    /**
     * A menu-bound override wins when it names a known layout and is ignored when it does not.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testMenuOverridesWinOnlyWhenTheyNameAKnownLayout(): void
    {
        $models = $this->createStub(ContentModelRepository::class);
        $models->method('contentType')->willReturn($this->definition('document'));
        $catalog = new ContentLayoutCatalog($models, 'default');

        self::assertSame('article', $catalog->templateFor($this->record(), 'article'));
        self::assertSame('page', $catalog->templateFor($this->record(), 'page'));
        self::assertSame('document', $catalog->templateFor($this->record(), 'newsletter'));
        self::assertSame('document', $catalog->templateFor($this->record(), null));
    }

    /**
     * The selectable handle list names every core layout and ends with the general page layout.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testHandlesListEveryCoreLayoutEndingWithPage(): void
    {
        self::assertSame(
            ['article', 'document', 'faq', 'guide', 'landing', 'reference', 'page'],
            ContentLayoutCatalog::handles(),
        );
    }

    /**
     * Build one published record pinned to an arbitrary content type identity.
     *
     * @return  ContentRecord  Minimal published record for layout resolution.
     *
     * @since   2.0.0
     */
    private function record(): ContentRecord
    {
        $now = new DateTimeImmutable('2026-08-12T00:00:00+00:00');

        return new ContentRecord(
            ContentEntry::create(
                '018f22e2-7c8b-7ab0-8f3a-88e8026bb4aa',
                'Layout probe',
                'layout-probe',
                ['body' => 'Probe body'],
                ContentStatus::Published,
            ),
            '018f22e2-7c8b-7ab0-8f3a-88e8026bb410',
            '018f22e2-7c8b-7ab0-8f3a-88e8026bb401',
            $now,
            $now,
        );
    }

    /**
     * Build one published content-type definition carrying the handle under test.
     *
     * @param   string  $handle  Content-type handle the repository reports.
     *
     * @return  ContentTypeDefinition  Minimal valid definition.
     *
     * @since   2.0.0
     */
    private function definition(string $handle): ContentTypeDefinition
    {
        $now = new DateTimeImmutable('2026-08-12T00:00:00+00:00');

        return new ContentTypeDefinition(
            '018f22e2-7c8b-7ab0-8f3a-88e8026bb410',
            SiteContext::default(),
            $handle,
            'Layout probe type',
            '018f22e2-7c8b-7ab0-8f3a-88e8026bb401',
            1,
            ['type' => 'object', 'properties' => ['body' => ['type' => 'string']]],
            1,
            $now,
            $now,
        );
    }
}
