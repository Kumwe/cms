<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Content\Application;

use DateTimeImmutable;
use InvalidArgumentException;
use Kumwe\App\Application\Authorization\SiteContext;
use Kumwe\App\Audit\Application\AuditRecorder;
use Kumwe\App\Content\Application\ContentModelNotFound;
use Kumwe\App\Content\Application\ContentModelRepository;
use Kumwe\App\Content\Application\ContentRecord;
use Kumwe\App\Content\Application\ContentRepository;
use Kumwe\App\Content\Application\ContentService;
use Kumwe\App\Content\Domain\ContentEntry;
use Kumwe\App\Content\Domain\ContentStatus;
use Kumwe\App\Content\Domain\ContentTypeDefinition;
use Kumwe\App\Content\Domain\JsonSchemaValidator;
use Kumwe\App\Content\Domain\PublicationWindow;
use Kumwe\App\Tests\Support\AuthorizationContext;
use Kumwe\App\Tests\Support\ImmediateTransactionManager;
use Kumwe\App\Workflow\Domain\Workflow;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

/**
 * Proves that adopting a content type version re-pins a stored entry only to a published version that follows
 * the entry's workflow, and leaves the entry itself, including its optimistic version, untouched.
 *
 * @since  2.0.0
 */
#[CoversClass(ContentService::class)]
final class ContentServiceAdoptContentTypeTest extends TestCase
{
    /**
     * Stored entry under adoption.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string ENTRY = '018f22e2-7c8b-7ab0-8f3a-88e8026bb401';

    /**
     * Content type the entry follows.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string TYPE = '018f22e2-7c8b-7ab0-8f3a-88e8026bb402';

    /**
     * Workflow the stored entry and its adoptable version share.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string WORKFLOW = '018f22e2-7c8b-7ab0-8f3a-88e8026bb403';

    /**
     * Workflow a version the entry must not adopt follows.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string OTHER_WORKFLOW = '018f22e2-7c8b-7ab0-8f3a-88e8026bb404';

    /**
     * An unpublished version and a version on another workflow are refused before anything is written; the
     * version that matches is adopted in one transaction with its audit event, and the entry keeps its version.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAdoptionRefusesUnpublishedAndForeignWorkflowVersionsAndRePinsTheEntry(): void
    {
        $createdAt = new DateTimeImmutable('2026-08-12T00:00:00+00:00');
        $stored = new ContentRecord(
            ContentEntry::reconstitute(
                self::ENTRY,
                'Stored page',
                'stored-page',
                ['body' => 'The stored body.'],
                ContentStatus::Draft,
                PublicationWindow::unbounded(),
                3,
            ),
            self::TYPE,
            self::WORKFLOW,
            $createdAt,
            $createdAt,
        );
        $repository = $this->createMock(ContentRepository::class);
        $repository->method('find')->willReturn($stored);
        $updated = null;
        $repository->expects(self::never())->method('update');
        $repository->expects(self::once())->method('adopt')->willReturnCallback(
            static function (ContentRecord $record, int $expectedVersion) use (&$updated): void {
                self::assertSame(3, $expectedVersion);
                $updated = $record;
            },
        );
        $models = $this->createStub(ContentModelRepository::class);
        $models->method('contentType')->willReturnCallback(
            fn (SiteContext $site, string $identifier, ?int $version = null): ?ContentTypeDefinition
                => match ($version) {
                    2 => $this->definition(2, self::OTHER_WORKFLOW),
                    3 => $this->definition(3, self::WORKFLOW),
                    default => null,
                },
        );
        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn(new DateTimeImmutable('2026-09-09T12:00:00+00:00'));
        $audit = $this->createMock(AuditRecorder::class);
        $audit->expects(self::once())->method('record');
        $service = new ContentService(
            $repository,
            $audit,
            new ImmediateTransactionManager(),
            $clock,
            new Workflow(),
            AuthorizationContext::gateway(),
            AuthorizationContext::ownershipWriter(),
            $models,
            new JsonSchemaValidator(),
        );
        $context = AuthorizationContext::human(['content.read', 'content.update']);

        try {
            $service->adoptContentType($context, self::ENTRY, 3, self::TYPE, 9);
            self::fail('A version that is not published here must be refused.');
        } catch (ContentModelNotFound $missing) {
            self::assertStringContainsString(self::TYPE, $missing->getMessage());
        }
        try {
            $service->adoptContentType($context, self::ENTRY, 3, self::TYPE, 2);
            self::fail('A version that follows another workflow must be refused.');
        } catch (InvalidArgumentException $refused) {
            self::assertSame('The adopted content type version follows a different workflow.', $refused->getMessage());
        }
        self::assertNull($updated);

        $adopted = $service->adoptContentType($context, self::ENTRY, 3, self::TYPE, 3);

        self::assertSame($updated, $adopted);
        self::assertSame(self::TYPE, $adopted->contentTypeId);
        self::assertSame(3, $adopted->contentTypeVersion);
        self::assertSame(self::WORKFLOW, $adopted->workflowId);
        self::assertSame(1, $adopted->workflowVersion);
        self::assertSame($stored->entry, $adopted->entry);
        self::assertSame(3, $adopted->entry->version());
        self::assertSame($createdAt, $adopted->createdAt);
        self::assertSame('2026-09-09T12:00:00+00:00', $adopted->updatedAt->format(DATE_ATOM));
        self::assertNull($adopted->deletedAt);
    }

    /**
     * One published version of the content type on the given workflow.
     *
     * @param   int     $version     Published content type version.
     * @param   string  $workflowId  Workflow that version follows.
     *
     * @return  ContentTypeDefinition  Definition whose schema the stored body satisfies.
     *
     * @since   2.0.0
     */
    private function definition(int $version, string $workflowId): ContentTypeDefinition
    {
        $now = new DateTimeImmutable('2026-08-12T00:00:00+00:00');

        return new ContentTypeDefinition(
            self::TYPE,
            SiteContext::default(),
            'page',
            'Page',
            $workflowId,
            1,
            ['type' => 'object', 'properties' => ['body' => ['type' => 'string']]],
            $version,
            $now,
            $now,
        );
    }
}
