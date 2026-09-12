<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Content\Application;

use DateTimeImmutable;
use InvalidArgumentException;
use Kumwe\App\Application\Authorization\AuthorizationDenied;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Context\Value\SiteContext;
use Kumwe\App\Audit\Application\AuditRecorder;
use Kumwe\App\Audit\Domain\AuditEvent;
use Kumwe\App\Content\Application\ContentRecord;
use Kumwe\App\Content\Application\ContentRepository;
use Kumwe\App\Content\Application\ContentService;
use Kumwe\App\Content\Application\TranslationGroupRepository;
use Kumwe\App\Content\Domain\ContentEntry;
use Kumwe\App\Content\Domain\ContentRevision;
use Kumwe\App\Content\Domain\ContentStatus;
use Kumwe\Extension\Spi\Contribution\ContributionOwner;
use Kumwe\App\Extension\Contribution\OwnedRuntimeContributionRegistry;
use Kumwe\App\Extension\Contribution\TranslationGroupDeclaration;
use Kumwe\Extension\Spi\Contribution\TranslationSetItemAssociation;
use Kumwe\Localization\Domain\LocaleTag;
use Kumwe\App\Tests\Support\AuthorizationContext;
use Kumwe\Transaction\Testing\ImmediateTransactionManager;
use Kumwe\App\Workflow\Domain\Workflow;
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
#[UsesClass(Workflow::class)]
#[UsesClass(OwnedRuntimeContributionRegistry::class)]
#[UsesClass(TranslationGroupDeclaration::class)]
/**
 * Pins the runtime half of the extension content-translation contract inside `ContentService`.
 *
 * The signed declaration is admission metadata; these are the assertions that it governs storage. An
 * association resolves against the active registry before anything is written, the group and fallback
 * both come from the resolved declaration rather than from the caller, the refusals the contract
 * promises — an inactive set, an undeclared locale, a missing registry — leave the store untouched,
 * and the audit event names the owner and set so the association survives into the trail.
 *
 * @since  2.0.0
 */
final class ContributedContentTranslationTest extends TestCase
{
    /**
     * Identity of the stored entry every case in this class associates with a declared set.
     *
     * @var    string
     * @since  2.0.0
     */
    private const ENTRY = '018f22e2-7c8b-7ab0-8f3a-88e8026bb970';

    /**
     * Prove the declared set decides the group and the fallback, and the audit trail names both ends.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnAssociatedEntryJoinsTheDeclaredSetUnderTheDeclaredFallback(): void
    {
        $association = new TranslationSetItemAssociation('acme/blog', 'acme.blog.articles');
        $group = $association->groupIdForSite(SiteContext::DEFAULT);
        $repository = $this->repository();
        $repository->expects(self::once())->method('update')->with(
            self::callback(static fn (ContentRecord $record): bool => $record->entry->version() === 2),
            1,
        );
        $groups = $this->createMock(TranslationGroupRepository::class);
        $groups->expects(self::once())->method('declareGroup')->with(
            self::callback(static fn (SiteContext $site): bool => $site->identifier() === SiteContext::DEFAULT),
            $group,
            self::callback(static fn (LocaleTag $member): bool => $member->toString() === 'de'),
            self::callback(static fn (LocaleTag $fallback): bool => $fallback->toString() === 'en-GB'),
        );
        $groups->expects(self::once())->method('guardAttachment')->with(self::anything(), $group, self::ENTRY);
        $events = [];

        $updated = $this->service($repository, $groups, $events, $this->registry())->translateContributed(
            $this->context(),
            self::ENTRY,
            1,
            LocaleTag::fromString('de'),
            $association,
        );

        self::assertSame('de', $updated->entry->locale()?->toString());
        self::assertSame($group, $updated->entry->translationGroupId());
        self::assertSame(['content.translate'], array_map(
            static fn (AuditEvent $event): string => $event->action(),
            $events,
        ));
        self::assertSame(
            ['version' => 2, 'owner' => 'acme/blog', 'translation_set' => 'acme.blog.articles'],
            $events[0]->metadata(),
        );
    }

    /**
     * Prove a locale the declaration does not carry is refused before anything is written.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnUndeclaredLocaleIsRefused(): void
    {
        $repository = $this->repository();
        $repository->expects(self::never())->method('update');
        $groups = $this->createMock(TranslationGroupRepository::class);
        $groups->expects(self::never())->method('declareGroup');
        $events = [];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Translation set acme.blog.articles does not declare locale he.');

        try {
            $this->service($repository, $groups, $events, $this->registry())->translateContributed(
                $this->context(),
                self::ENTRY,
                1,
                LocaleTag::fromString('he'),
                new TranslationSetItemAssociation('acme/blog', 'acme.blog.articles'),
            );
        } finally {
            self::assertSame([], $events);
        }
    }

    /**
     * Prove a set the claimed owner has not actively declared is refused before anything is written.
     *
     * The registry answers by owner and identifier together, so a withdrawn package, a never-declared
     * set and a set held by some other owner all refuse through this same path.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testASetTheOwnerHasNotActivelyDeclaredIsRefused(): void
    {
        $repository = $this->repository();
        $repository->expects(self::never())->method('update');
        $groups = $this->createMock(TranslationGroupRepository::class);
        $groups->expects(self::never())->method('declareGroup');
        $events = [];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Translation set acme.blog.pages is not an active declaration of acme/blog.');

        try {
            $this->service($repository, $groups, $events, $this->registry())->translateContributed(
                $this->context(),
                self::ENTRY,
                1,
                LocaleTag::fromString('de'),
                new TranslationSetItemAssociation('acme/blog', 'acme.blog.pages'),
            );
        } finally {
            self::assertSame([], $events);
        }
    }

    /**
     * Prove an installation with no contribution registry refuses the call instead of guessing.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testContributedTranslationIsUnavailableWithoutTheRegistry(): void
    {
        $repository = $this->repository();
        $repository->expects(self::never())->method('update');
        $events = [];

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Contributed content translation requires the contribution registry.');

        try {
            $this->service($repository, null, $events, null)->translateContributed(
                $this->context(),
                self::ENTRY,
                1,
                LocaleTag::fromString('de'),
                new TranslationSetItemAssociation('acme/blog', 'acme.blog.articles'),
            );
        } finally {
            self::assertSame([], $events);
        }
    }

    /**
     * Prove an actor who may not change the entry learns nothing about the declaration inventory.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnActorWithoutTheUpdateCapabilityNeverReachesTheRegistry(): void
    {
        $repository = $this->repository();
        $repository->expects(self::never())->method('find');
        $events = [];

        $this->expectException(AuthorizationDenied::class);

        $this->service($repository, null, $events, $this->registry())->translateContributed(
            AuthorizationContext::human(['content.read']),
            self::ENTRY,
            1,
            LocaleTag::fromString('de'),
            new TranslationSetItemAssociation('acme/blog', 'acme.blog.articles'),
        );
    }

    /**
     * The active registry every resolving case consults, holding one declared set.
     *
     * @return  OwnedRuntimeContributionRegistry  Registry with `acme.blog.articles` active for `acme/blog`.
     *
     * @since   2.0.0
     */
    private function registry(): OwnedRuntimeContributionRegistry
    {
        $registry = new OwnedRuntimeContributionRegistry('content translation group');
        $registry->register(
            ContributionOwner::extension('acme/blog'),
            new TranslationGroupDeclaration('acme.blog.articles', ['de', 'en-GB'], 'en-GB'),
        );

        return $registry;
    }

    /**
     * Compose the service over the store doubles under test.
     *
     * @param   ContentRepository                  $repository  Store the entry is read from and written to.
     * @param   ?TranslationGroupRepository        $groups      Group store, or null when never reached.
     * @param   list<AuditEvent>                   $events      Trail the recorder appends each event to.
     * @param   ?OwnedRuntimeContributionRegistry  $registry    Active declared sets, or null for none wired.
     *
     * @return  ContentService  Service wired with a fixed clock and the built-in workflow.
     *
     * @since   2.0.0
     */
    private function service(
        ContentRepository $repository,
        ?TranslationGroupRepository $groups,
        array &$events,
        ?OwnedRuntimeContributionRegistry $registry,
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
            $registry,
        );
    }

    /**
     * Build the store double holding the one published entry these cases associate.
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
     * The context an editor of this site associates entries under.
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
