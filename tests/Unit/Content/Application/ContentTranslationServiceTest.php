<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Content\Application;

use DateTimeImmutable;
use Kumwe\App\Application\Authorization\AuthorizationDenied;
use Kumwe\App\Audit\Application\AuditRecorder;
use Kumwe\App\Audit\Domain\AuditEvent;
use Kumwe\App\Content\Application\ContentRecord;
use Kumwe\App\Content\Application\ContentRepository;
use Kumwe\App\Content\Application\ContentService;
use Kumwe\App\Content\Application\TranslationGroupRepository;
use Kumwe\App\Content\Domain\ContentEntry;
use Kumwe\App\Content\Domain\ContentRevision;
use Kumwe\App\Content\Domain\ContentStatus;
use Kumwe\App\Content\Domain\VersionConflict;
use Kumwe\App\Localization\Domain\LocaleTag;
use Kumwe\App\Tests\Support\AuthorizationContext;
use Kumwe\App\Tests\Support\ImmediateTransactionManager;
use Kumwe\App\Workflow\Domain\Workflow;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Context\Value\SiteContext;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

#[CoversClass(ContentService::class)]
#[UsesClass(ContentEntry::class)]
#[UsesClass(ContentRecord::class)]
#[UsesClass(ContentRevision::class)]
#[UsesClass(AuditEvent::class)]
#[UsesClass(LocaleTag::class)]
#[UsesClass(Workflow::class)]
/**
 * Pins the use case that brings a translation into being: declaring what language an entry is in.
 *
 * Decision D12 puts the group and the entry on the same commit, because a stored entry naming a group
 * no store knows about is a page delivery cannot resolve. These are the assertions that the declaration
 * and the row travel together, that nothing editorial moves with them, and that the two refusals a
 * caller can actually meet — no group store wired, and a version somebody else already moved past —
 * leave the store untouched.
 *
 * @since  2.0.0
 */
final class ContentTranslationServiceTest extends TestCase
{
    /**
     * Identity of the stored entry every case in this class places in a group.
     *
     * @var    string
     * @since  2.0.0
     */
    private const ENTRY = '018f22e2-7c8b-7ab0-8f3a-88e8026bb960';

    /**
     * Identity of the logical item that entry becomes one locale of.
     *
     * @var    string
     * @since  2.0.0
     */
    private const GROUP = '018f22e2-7c8b-7ab0-8f3a-88e8026bb961';

    /**
     * Prove the group declaration, the row, its revision and its audit event are one commit.
     *
     * The fallback is not stated here, so the entry's own locale becomes it: that is what the first
     * locale of an item declares, and every later locale naming the same group leaves it alone.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTranslatingDeclaresTheGroupAndCommitsTheEntryWithIt(): void
    {
        $repository = $this->repository();
        $repository->expects(self::once())->method('update')->with(
            self::callback(static fn (ContentRecord $record): bool => $record->entry->version() === 2),
            1,
        );
        $repository->expects(self::once())->method('appendRevision')->with(
            self::callback(static fn (ContentRevision $revision): bool => $revision->revisionNumber() === 4),
        );
        $groups = $this->createMock(TranslationGroupRepository::class);
        $groups->expects(self::once())->method('declareGroup')->with(
            self::callback(static fn (SiteContext $site): bool => $site->identifier() === SiteContext::DEFAULT),
            self::GROUP,
            self::callback(static fn (LocaleTag $member): bool => $member->toString() === 'af'),
            null,
        );
        $groups->expects(self::once())->method('guardAttachment')->with(
            self::callback(static fn (SiteContext $site): bool => $site->identifier() === SiteContext::DEFAULT),
            self::GROUP,
            self::ENTRY,
        );
        $events = [];

        $updated = $this->service($repository, $groups, $events)->translate(
            $this->context(),
            self::ENTRY,
            1,
            LocaleTag::fromString('af'),
            self::GROUP,
        );

        self::assertSame('af', $updated->entry->locale()?->toString());
        self::assertSame(self::GROUP, $updated->entry->translationGroupId());
        self::assertSame(2, $updated->entry->version());
        self::assertSame('welcome', $updated->entry->slug());
        self::assertSame(ContentStatus::Published->value, $updated->entry->statusKey());
        self::assertSame(['content.translate'], array_map(
            static fn (AuditEvent $event): string => $event->action(),
            $events,
        ));
        self::assertSame(['version' => 2], $events[0]->metadata());
    }

    /**
     * Prove a stated fallback is the one recorded with the group, rather than the entry's own locale.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAStatedFallbackIsTheOneRecordedWithTheGroup(): void
    {
        $repository = $this->repository();
        $repository->expects(self::once())->method('update');
        $groups = $this->createMock(TranslationGroupRepository::class);
        $groups->expects(self::once())->method('declareGroup')->with(
            self::anything(),
            self::GROUP,
            self::callback(static fn (LocaleTag $member): bool => $member->toString() === 'af'),
            self::callback(static fn (LocaleTag $fallback): bool => $fallback->toString() === 'en-GB'),
        );
        $groups->expects(self::once())->method('guardAttachment')->with(self::anything(), self::GROUP, self::ENTRY);
        $events = [];

        $updated = $this->service($repository, $groups, $events)->translate(
            $this->context(),
            self::ENTRY,
            1,
            LocaleTag::fromString('af'),
            self::GROUP,
            LocaleTag::fromString('en-GB'),
        );

        self::assertSame('af', $updated->entry->locale()?->toString());
    }

    /**
     * Prove an installation with no group store refuses the call instead of writing half of it.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTranslationIsUnavailableWithoutAGroupStore(): void
    {
        $repository = $this->repository();
        $repository->expects(self::never())->method('update');
        $events = [];

        $this->expectExceptionMessage('Content translation requires a translation group store.');

        try {
            $this->service($repository, null, $events)->translate(
                $this->context(),
                self::ENTRY,
                1,
                LocaleTag::fromString('af'),
                self::GROUP,
            );
        } finally {
            self::assertSame([], $events);
        }
    }

    /**
     * Prove an editor who lost the version race declares no group and writes no row.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAStaleEditorDeclaresNoGroupAndWritesNothing(): void
    {
        $repository = $this->repository();
        $repository->expects(self::never())->method('update');
        $repository->expects(self::never())->method('appendRevision');
        $groups = $this->createMock(TranslationGroupRepository::class);
        $groups->expects(self::never())->method('declareGroup');
        $groups->expects(self::never())->method('guardAttachment');
        $events = [];

        $this->expectException(VersionConflict::class);

        try {
            $this->service($repository, $groups, $events)->translate(
                $this->context(),
                self::ENTRY,
                2,
                LocaleTag::fromString('af'),
                self::GROUP,
            );
        } finally {
            self::assertSame([], $events);
        }
    }

    /**
     * Prove an actor who may not change the entry never reaches the group store at all.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnActorWithoutTheUpdateCapabilityNeverReachesTheGroupStore(): void
    {
        $repository = $this->repository();
        $repository->expects(self::never())->method('find');
        $groups = $this->createMock(TranslationGroupRepository::class);
        $groups->expects(self::never())->method('declareGroup');
        $groups->expects(self::never())->method('guardAttachment');
        $events = [];

        $this->expectException(AuthorizationDenied::class);

        $this->service($repository, $groups, $events)->translate(
            AuthorizationContext::human(['content.read']),
            self::ENTRY,
            1,
            LocaleTag::fromString('af'),
            self::GROUP,
        );
    }

    /**
     * Compose the service over the store doubles under test.
     *
     * @param   ContentRepository            $repository  Store the entry is read from and written back to.
     * @param   ?TranslationGroupRepository  $groups      Group store, or null for an installation without one.
     * @param   list<AuditEvent>             $events      Trail the recorder appends each written event to.
     *
     * @return  ContentService  Service wired with a fixed clock and the built-in workflow.
     *
     * @since   2.0.0
     */
    private function service(
        ContentRepository $repository,
        ?TranslationGroupRepository $groups,
        array &$events,
    ): ContentService {
        $audit = $this->createStub(AuditRecorder::class);
        $audit->method('record')->willReturnCallback(
            static function (AuditEvent $event) use (&$events): void {
                $events[] = $event;
            },
        );

        return new ContentService(
            $repository,
            $audit,
            new ImmediateTransactionManager(),
            $this->clock(),
            new Workflow(),
            AuthorizationContext::gateway(),
            AuthorizationContext::ownershipWriter(),
            null,
            null,
            $groups,
        );
    }

    /**
     * Build the store double holding the one published entry these cases translate.
     *
     * @return  ContentRepository&MockObject  Store answering with that entry.
     *
     * @since   2.0.0
     */
    private function repository(): ContentRepository&MockObject
    {
        $at = new DateTimeImmutable('2026-08-17T09:00:00+00:00');
        $repository = $this->createMock(ContentRepository::class);
        $repository->method('find')->willReturn(new ContentRecord(
            ContentEntry::create(self::ENTRY, 'Welcome', 'welcome', ['body' => 'Hello'], ContentStatus::Published),
            ContentService::CORE_PAGE_TYPE_ID,
            ContentService::CORE_WORKFLOW_ID,
            $at,
            $at,
        ));
        $repository->method('nextRevisionNumber')->willReturn(4);

        return $repository;
    }

    /**
     * The context an editor of this site translates under.
     *
     * @return  ExecutionContext  Actor holding both capabilities the call demands.
     *
     * @since   2.0.0
     */
    private function context(): ExecutionContext
    {
        return AuthorizationContext::human(['content.read', 'content.update']);
    }

    /**
     * The one instant every write in this class is stamped with.
     *
     * @return  ClockInterface  Clock fixed to a moment after the entry was stored.
     *
     * @since   2.0.0
     */
    private function clock(): ClockInterface
    {
        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn(new DateTimeImmutable('2026-08-17T10:00:00+00:00'));

        return $clock;
    }
}
