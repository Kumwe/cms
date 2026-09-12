<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Content\Domain;

use DateTimeImmutable;
use InvalidArgumentException;
use Kumwe\App\Content\Domain\ContentEntry;
use Kumwe\App\Content\Domain\ContentStatus;
use Kumwe\App\Content\Domain\ExpectedVersion;
use Kumwe\App\Content\Domain\PublicationWindow;
use Kumwe\App\Content\Domain\VersionConflict;
use Kumwe\Localization\Domain\LocaleTag;
use Kumwe\App\Workflow\Domain\InvalidWorkflowTransition;
use Kumwe\App\Workflow\Domain\Workflow;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ContentEntry::class)]
#[UsesClass(ContentStatus::class)]
#[UsesClass(ExpectedVersion::class)]
#[UsesClass(PublicationWindow::class)]
#[UsesClass(VersionConflict::class)]
#[UsesClass(Workflow::class)]
#[UsesClass(InvalidWorkflowTransition::class)]
final class ContentEntryTest extends TestCase
{
    private const ID = '018f22e2-7c8b-7ab0-8f3a-88e8026bb151';

    private const GROUP = '018f22e2-7c8b-7ab0-8f3a-88e8026bb152';

    public function testCreatesValidatedVersionOneAggregate(): void
    {
        $entry = ContentEntry::create(self::ID, '  Welcome  ', 'welcome', ['body' => 'Hello']);

        self::assertSame(self::ID, $entry->id());
        self::assertSame('Welcome', $entry->title());
        self::assertSame('welcome', $entry->slug());
        self::assertSame(['body' => 'Hello'], $entry->data());
        self::assertSame(ContentStatus::Draft, $entry->status());
        self::assertSame(1, $entry->version());
    }

    public function testRevisionReturnsNewAggregateAndIncrementsVersion(): void
    {
        $original = ContentEntry::create(self::ID, 'Welcome', 'welcome');
        $revised = $original->revise(new ExpectedVersion(1), 'About', 'about', ['body' => 'About us']);

        self::assertSame('Welcome', $original->title());
        self::assertSame(1, $original->version());
        self::assertSame('About', $revised->title());
        self::assertSame(2, $revised->version());
    }

    public function testEveryMutationEnforcesExpectedVersion(): void
    {
        $entry = ContentEntry::create(self::ID, 'Welcome', 'welcome');

        $this->expectException(VersionConflict::class);

        $entry->reschedule(new ExpectedVersion(2), PublicationWindow::unbounded());
    }

    public function testVisibilityRequiresPublishedStateAndActiveWindow(): void
    {
        $entry = ContentEntry::create(
            self::ID,
            'Welcome',
            'welcome',
            publicationWindow: new PublicationWindow(
                new DateTimeImmutable('2026-08-04T10:00:00Z'),
                new DateTimeImmutable('2026-08-04T11:00:00Z'),
            ),
        );

        self::assertFalse($entry->isVisibleAt(new DateTimeImmutable('2026-08-04T10:30:00Z')));

        $review = $entry->transition(new ExpectedVersion(1), new Workflow(), ContentStatus::Review);
        $published = $review->transition(new ExpectedVersion(2), new Workflow(), ContentStatus::Published);

        self::assertTrue($published->isVisibleAt(new DateTimeImmutable('2026-08-04T10:30:00Z')));
        self::assertFalse($published->isVisibleAt(new DateTimeImmutable('2026-08-04T11:00:00Z')));
    }

    public function testRejectsInvalidSlug(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ContentEntry::create(self::ID, 'Welcome', 'Welcome');
    }

    public function testRejectsNonJsonContentData(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ContentEntry::create(self::ID, 'Welcome', 'welcome', ['invalid' => new \stdClass()]);
    }

    public function testRejectsNonFiniteContentNumber(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ContentEntry::create(self::ID, 'Welcome', 'welcome', ['invalid' => INF]);
    }

    public function testTransitionCannotBypassWorkflow(): void
    {
        $entry = ContentEntry::create(self::ID, 'Welcome', 'welcome');

        $this->expectException(InvalidWorkflowTransition::class);

        $entry->transition(new ExpectedVersion(1), new Workflow(), ContentStatus::Published);
    }

    public function testRejectsATranslationGroupThatIsNotACanonicalUuid(): void
    {
        $this->expectExceptionMessage('A content translation group ID must be a canonical UUID.');

        ContentEntry::create(self::ID, 'Welcome', 'welcome', locale: 'en-GB', translationGroupId: 'welcome-group');
    }

    public function testRejectsAStoredRowThatJoinsAGroupWithoutDeclaringItsLanguage(): void
    {
        $this->expectExceptionMessage('An entry in a translation group must declare its locale.');

        ContentEntry::reconstitute(
            self::ID,
            'Welcome',
            'welcome',
            [],
            ContentStatus::Draft,
            PublicationWindow::unbounded(),
            4,
            null,
            self::GROUP,
        );
    }

    public function testReconstitutesAStoredRowWithItsLanguageAndGroupNormalised(): void
    {
        $entry = ContentEntry::reconstitute(
            strtoupper(self::ID),
            '  Welcome  ',
            'welcome',
            ['body' => 'Hello'],
            ContentStatus::Published,
            PublicationWindow::unbounded(),
            7,
            'PT_br',
            strtoupper(self::GROUP),
        );

        self::assertSame(self::ID, $entry->id());
        self::assertSame('Welcome', $entry->title());
        self::assertSame(7, $entry->version());
        self::assertSame('pt-BR', $entry->locale()?->toString());
        self::assertSame(self::GROUP, $entry->translationGroupId());

        $undeclared = ContentEntry::create(self::ID, 'Welcome', 'welcome');

        self::assertNull($undeclared->locale());
        self::assertNull($undeclared->translationGroupId());
    }

    public function testReschedulingMovesTheWindowAndCarriesLanguageAndGroupForward(): void
    {
        $entry = ContentEntry::create(
            self::ID,
            'Welcome',
            'welcome',
            ['body' => 'Hello'],
            ContentStatus::Draft,
            null,
            'en-GB',
            self::GROUP,
        );

        $rescheduled = $entry->reschedule(
            new ExpectedVersion(1),
            new PublicationWindow(new DateTimeImmutable('2026-09-01T08:00:00+00:00'), null),
        );

        self::assertSame(2, $rescheduled->version());
        self::assertSame('en-GB', $rescheduled->locale()?->toString());
        self::assertSame(self::GROUP, $rescheduled->translationGroupId());
        self::assertSame(['body' => 'Hello'], $rescheduled->data());
        self::assertNull($entry->publicationWindow()->startsAt());
    }

    public function testTranslationDeclaresLanguageAndGroupWithoutTouchingAnythingEditorial(): void
    {
        $entry = ContentEntry::create(self::ID, 'Welcome', 'welcome', ['body' => 'Hello'], ContentStatus::Published);

        $placed = $entry->translate(new ExpectedVersion(1), LocaleTag::fromString('af'), strtoupper(self::GROUP));

        self::assertSame('af', $placed->locale()?->toString());
        self::assertSame(self::GROUP, $placed->translationGroupId());
        self::assertSame('welcome', $placed->slug());
        self::assertSame(['body' => 'Hello'], $placed->data());
        self::assertSame(ContentStatus::Published->value, $placed->statusKey());
        self::assertSame(2, $placed->version());
        self::assertNull($entry->locale());

        $this->expectException(VersionConflict::class);

        $entry->translate(new ExpectedVersion(2), LocaleTag::fromString('de'), self::GROUP);
    }

    public function testSnapshotCarriesLanguageAndGroupOnlyWhenTheEntryDeclaresThem(): void
    {
        $scheduled = ContentEntry::create(
            self::ID,
            'Welcome',
            'welcome',
            ['body' => 'Hello'],
            ContentStatus::Published,
            new PublicationWindow(new DateTimeImmutable('2026-09-01T08:00:00+00:00'), null),
            'de',
            self::GROUP,
        );

        $snapshot = $scheduled->snapshot();

        self::assertSame(
            ['id', 'title', 'slug', 'data', 'status', 'publication_window', 'version', 'locale', 'translation_group'],
            array_keys($snapshot),
        );
        self::assertSame(
            ['starts_at' => '2026-09-01T08:00:00+00:00', 'ends_at' => null],
            $snapshot['publication_window'],
        );
        self::assertSame('de', $snapshot['locale']);
        self::assertSame(self::GROUP, $snapshot['translation_group']);
        self::assertSame(
            ['id', 'title', 'slug', 'data', 'status', 'publication_window', 'version'],
            array_keys(ContentEntry::create(self::ID, 'Welcome', 'welcome')->snapshot()),
        );
    }
}
