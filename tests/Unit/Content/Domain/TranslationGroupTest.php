<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Content\Domain;

use DateTimeImmutable;
use InvalidArgumentException;
use Kumwe\App\Content\Domain\ContentEntry;
use Kumwe\App\Content\Domain\ContentStatus;
use Kumwe\App\Content\Domain\ExpectedVersion;
use Kumwe\App\Content\Domain\InvalidTranslationGroup;
use Kumwe\App\Content\Domain\PublicationWindow;
use Kumwe\App\Content\Domain\TranslationGroup;
use Kumwe\App\Content\Domain\TranslationGroupMember;
use Kumwe\Localization\Domain\LocaleTag;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TranslationGroup::class)]
#[CoversClass(TranslationGroupMember::class)]
#[CoversClass(InvalidTranslationGroup::class)]
#[CoversClass(ContentEntry::class)]
#[UsesClass(ExpectedVersion::class)]
#[UsesClass(PublicationWindow::class)]
/**
 * Pins the content half of decision D12: one item, one entry per locale, published one locale at a time.
 *
 * @since  2.0.0
 */
final class TranslationGroupTest extends TestCase
{
    /**
     * Identifier of the logical item every case in this class translates.
     *
     * @var    string
     * @since  2.0.0
     */
    private const GROUP = '018f22e2-7c8b-7ab0-8f3a-88e8026bb900';

    /**
     * Prove English may be live while another language is still drafting.
     *
     * This is the property the whole model exists for: publication is per locale, so a drafting
     * translation is neither served nor advertised while the language beside it is public.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testOneLocalePublishesWhileAnotherDrafts(): void
    {
        $group = new TranslationGroup(self::GROUP, LocaleTag::fromString('en-GB'), [
            $this->member('en-GB', 'about', true),
            $this->member('de', 'ueber-uns', false),
        ]);

        $published = $group->publishedMembers($this->instant());

        self::assertCount(1, $published);
        self::assertSame('en-GB', $published[0]->locale->toString());
        self::assertNotNull($group->member(LocaleTag::fromString('de')));
        self::assertFalse($group->member(LocaleTag::fromString('de'))?->isVisibleAt($this->instant()));
    }

    /**
     * Prove a reader whose language is drafting is served the declared fallback rather than a miss.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAMissingOrDraftingTranslationResolvesToTheDeclaredFallback(): void
    {
        $group = new TranslationGroup(self::GROUP, LocaleTag::fromString('en-GB'), [
            $this->member('en-GB', 'about', true),
            $this->member('de', 'ueber-uns', false),
        ]);

        self::assertSame(
            'en-GB',
            $group->resolve(LocaleTag::fromString('de'), $this->instant())?->locale->toString(),
        );
        self::assertSame(
            'en-GB',
            $group->resolve(LocaleTag::fromString('af'), $this->instant())?->locale->toString(),
        );
    }

    /**
     * Prove a locale is served through its own fallback chain before another language is reached for.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testARegionalRequestFallsThroughItsOwnLanguageFirst(): void
    {
        $group = new TranslationGroup(self::GROUP, LocaleTag::fromString('en-GB'), [
            $this->member('en-GB', 'about', true),
            $this->member('pt', 'sobre', true),
        ]);

        self::assertSame(
            'pt',
            $group->resolve(LocaleTag::fromString('pt-BR'), $this->instant())?->locale->toString(),
        );
    }

    /**
     * Prove nothing is served when the whole item is unpublished, fallback included.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnUnpublishedFallbackServesNothingRatherThanADraft(): void
    {
        $group = new TranslationGroup(self::GROUP, LocaleTag::fromString('en-GB'), [
            $this->member('en-GB', 'about', false),
            $this->member('de', 'ueber-uns', false),
        ]);

        self::assertNull($group->resolve(LocaleTag::fromString('de'), $this->instant()));
        self::assertSame([], $group->publishedMembers($this->instant()));
    }

    /**
     * Prove a published locale outside its schedule is treated exactly as a drafting one.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAScheduledLocaleIsInvisibleOutsideItsWindow(): void
    {
        $group = new TranslationGroup(self::GROUP, LocaleTag::fromString('en-GB'), [
            $this->member('en-GB', 'about', true),
            new TranslationGroupMember(
                LocaleTag::fromString('de'),
                '018f22e2-7c8b-7ab0-8f3a-88e8026bb902',
                'ueber-uns',
                ContentStatus::Published->value,
                true,
                new PublicationWindow(new DateTimeImmutable('2026-09-01T00:00:00+00:00')),
            ),
        ]);

        self::assertCount(1, $group->publishedMembers($this->instant()));
        self::assertSame(
            'en-GB',
            $group->resolve(LocaleTag::fromString('de'), $this->instant())?->locale->toString(),
        );
    }

    /**
     * Prove the shapes the delivery rules cannot honour are refused where the group is built.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheGroupRefusesShapesDeliveryCannotHonour(): void
    {
        try {
            new TranslationGroup(self::GROUP, LocaleTag::fromString('en-GB'), [
                $this->member('en-GB', 'about', true),
                $this->member('en-GB', 'about-us', true),
            ]);
            self::fail('A locale was carried twice by one translation group.');
        } catch (InvalidTranslationGroup $exception) {
            self::assertStringContainsString('at most one entry for locale en-GB', $exception->getMessage());
        }

        try {
            new TranslationGroup(self::GROUP, LocaleTag::fromString('en-GB'), [
                $this->member('en-GB', 'about', true),
                $this->member('de', 'about', true),
            ]);
            self::fail('Two locales of one item claimed the same route segment.');
        } catch (InvalidTranslationGroup $exception) {
            self::assertStringContainsString('claimed by two locales', $exception->getMessage());
        }

        try {
            new TranslationGroup(self::GROUP, LocaleTag::fromString('af'), [
                $this->member('en-GB', 'about', true),
            ]);
            self::fail('A fallback named a locale the group does not carry.');
        } catch (InvalidTranslationGroup $exception) {
            self::assertStringContainsString('fallback must name a locale', $exception->getMessage());
        }

        try {
            new TranslationGroup(self::GROUP, LocaleTag::fromString('en-GB'), []);
            self::fail('A group carrying no member at all was accepted.');
        } catch (InvalidTranslationGroup $exception) {
            self::assertStringContainsString('between one and 64 locales', $exception->getMessage());
        }

        $this->expectException(InvalidTranslationGroup::class);
        new TranslationGroup('not-a-uuid', LocaleTag::fromString('en-GB'), [$this->member('en-GB', 'about', true)]);
    }

    /**
     * Prove a group of no locales, and one past the ceiling, are both refused where the group is built.
     *
     * The ceiling is what bounds the rendered selector and the storage behind it, so a group that
     * carries nothing, or more locales than the model admits, is refused at construction rather than
     * discovered when delivery tries to render it.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAGroupOutsideItsSizeBoundsIsRefused(): void
    {
        try {
            new TranslationGroup(self::GROUP, LocaleTag::fromString('en-GB'), []);
            self::fail('A translation group carrying no locale at all was accepted.');
        } catch (InvalidTranslationGroup $exception) {
            self::assertStringContainsString('between one and 64 locales', $exception->getMessage());
        }

        $members = [];
        for ($index = 0; $index <= TranslationGroup::MAXIMUM_MEMBERS; $index++) {
            $locale = chr(97 + intdiv($index, 26)) . chr(97 + $index % 26);
            $members[] = $this->member($locale, 'about-' . $locale, true);
        }

        self::assertCount(TranslationGroup::MAXIMUM_MEMBERS + 1, $members);
        $this->expectExceptionMessage('A translation group carries between one and 64 locales.');

        new TranslationGroup(self::GROUP, LocaleTag::fromString('aa'), $members);
    }

    /**
     * Prove an untranslated item is still a group, and says so.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnItemInOneLanguageIsStillAGroupOfOne(): void
    {
        $group = TranslationGroup::ofOne(self::GROUP, $this->member('en-GB', 'about', true));

        self::assertFalse($group->isTranslated());
        self::assertCount(1, $group->members());
        self::assertSame('en-GB', $group->fallbackLocale->toString());
    }

    /**
     * Prove an entry carries its locale and group across every versioned change it goes through.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnEntryCarriesItsLocaleAndGroupThroughEveryChange(): void
    {
        $entry = ContentEntry::create(
            '018f22e2-7c8b-7ab0-8f3a-88e8026bb901',
            'About',
            'about',
            [],
            ContentStatus::Draft,
            null,
            'en-gb',
            self::GROUP,
        );

        self::assertSame('en-GB', $entry->locale()?->toString());
        self::assertSame(self::GROUP, $entry->translationGroupId());

        $revised = $entry->revise(new ExpectedVersion(1), 'About us', 'about-us', []);
        self::assertSame('en-GB', $revised->locale()?->toString());
        self::assertSame(self::GROUP, $revised->translationGroupId());

        $rescheduled = $revised->reschedule(new ExpectedVersion(2), PublicationWindow::unbounded());
        self::assertSame(self::GROUP, $rescheduled->translationGroupId());
    }

    /**
     * Prove an entry may only join a group under a declared locale.
     *
     * A member that does not know which locale it is cannot take a place in the group, so the pairing
     * is a construction rule rather than something the store is left to notice.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnEntryCannotJoinAGroupWithoutDeclaringItsLocale(): void
    {
        try {
            ContentEntry::create(
                '018f22e2-7c8b-7ab0-8f3a-88e8026bb903',
                'About',
                'about',
                [],
                ContentStatus::Draft,
                null,
                'en-GB',
                'not-a-uuid',
            );
            self::fail('A group identifier that is not a canonical UUID was accepted.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('must be a canonical UUID', $exception->getMessage());
        }

        $this->expectExceptionMessage('An entry in a translation group must declare its locale.');

        ContentEntry::create(
            '018f22e2-7c8b-7ab0-8f3a-88e8026bb903',
            'About',
            'about',
            [],
            ContentStatus::Draft,
            null,
            null,
            self::GROUP,
        );
    }

    /**
     * Prove `translate()` places an entry in a group without touching anything editorial.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTranslatePlacesAnEntryInAGroupAndChangesNothingElse(): void
    {
        $entry = ContentEntry::create(
            '018f22e2-7c8b-7ab0-8f3a-88e8026bb904',
            'About',
            'about',
            ['body' => 'Hello'],
            ContentStatus::Published,
        );

        $placed = $entry->translate(new ExpectedVersion(1), LocaleTag::fromString('en-GB'), self::GROUP);

        self::assertSame(self::GROUP, $placed->translationGroupId());
        self::assertSame('en-GB', $placed->locale()?->toString());
        self::assertSame(['body' => 'Hello'], $placed->data());
        self::assertSame('about', $placed->slug());
        self::assertSame(ContentStatus::Published->value, $placed->statusKey());
        self::assertSame(2, $placed->version());
        self::assertNull($entry->translationGroupId());
    }

    /**
     * Prove an untranslated entry snapshots to exactly the bytes its stored revisions were checksummed over.
     *
     * The snapshot is what `ContentRevision` hashes, so adding a language dimension to content had to
     * leave an entry that does not use it byte-identical, or every stored revision checksum would have
     * been invalidated by the change itself.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnUntranslatedEntrySnapshotsToItsOriginalBytes(): void
    {
        $entry = ContentEntry::create('018f22e2-7c8b-7ab0-8f3a-88e8026bb905', 'About', 'about', ['body' => 'Hi']);

        self::assertSame(
            ['id', 'title', 'slug', 'data', 'status', 'publication_window', 'version'],
            array_keys($entry->snapshot()),
        );

        $translated = $entry->translate(new ExpectedVersion(1), LocaleTag::fromString('en-GB'), self::GROUP);
        self::assertSame(
            ['id', 'title', 'slug', 'data', 'status', 'publication_window', 'version', 'locale', 'translation_group'],
            array_keys($translated->snapshot()),
        );
        self::assertSame('en-GB', $translated->snapshot()['locale']);
        self::assertSame(self::GROUP, $translated->snapshot()['translation_group']);
    }

    /**
     * Build one locale's place in the group with an unbounded schedule.
     *
     * @param   string  $locale     Language tag the member is written in.
     * @param   string  $slug       Route segment the member is published under.
     * @param   bool    $published  Whether the member's workflow state is public in its pinned version.
     *
     * @return  TranslationGroupMember  The member, with an identifier derived from its locale.
     *
     * @since   2.0.0
     */
    private function member(string $locale, string $slug, bool $published): TranslationGroupMember
    {
        return new TranslationGroupMember(
            LocaleTag::fromString($locale),
            '018f22e2-7c8b-7ab0-8f3a-' . substr(hash('sha256', $locale . $slug), 0, 12),
            $slug,
            $published ? ContentStatus::Published->value : ContentStatus::Draft->value,
            $published,
            PublicationWindow::unbounded(),
        );
    }

    /**
     * The one instant every publication question in this class is asked about.
     *
     * @return  DateTimeImmutable  A fixed moment in UTC.
     *
     * @since   2.0.0
     */
    private function instant(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-08-17T10:00:00+00:00');
    }
}
