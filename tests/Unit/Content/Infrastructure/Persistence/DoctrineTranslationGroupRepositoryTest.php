<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Content\Infrastructure\Persistence;

use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Kumwe\App\Content\Domain\InvalidTranslationGroup;
use Kumwe\App\Content\Infrastructure\Persistence\DoctrineTranslationGroupRepository;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\Localization\Domain\LocaleTag;
use Kumwe\Context\Value\SiteContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Pins how the group reader treats the rows a driver actually hands it, sound and unsound alike.
 *
 * The repository is the seam between stored rows and a `TranslationGroup` that refuses to be malformed,
 * so its interesting behaviour is what it does with input the domain would reject: a member whose locale
 * cannot be read, a declared fallback pointing at a locale nobody carries any more, a publication bound
 * arriving as a date object on one driver and as a raw string on the next. Each of those is asserted here
 * by handing the repository the row and reading the group that comes back, because the recovery is the
 * contract — one unreadable row must cost the item that row and nothing else, while a genuinely
 * unreadable *store* must still fail loudly rather than serve a half-built page.
 *
 * @since  2.0.0
 */
#[CoversClass(DoctrineTranslationGroupRepository::class)]
final class DoctrineTranslationGroupRepositoryTest extends TestCase
{
    /**
     * Identifier of the logical item every group in these tests describes.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string GROUP = '018f22e2-7c8b-7ab0-8f3a-88e8026bc001';

    /**
     * Identifier of the entry a caller asks the group of.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string CONTENT = '018f22e2-7c8b-7ab0-8f3a-88e8026bd001';

    /**
     * Text that satisfies no part of the language-tag grammar, used where a locale must be unreadable.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string UNREADABLE_TAG = 'not-a-language-tag-at-all';

    /**
     * An entry that declares no group belongs to none, rather than to an empty one.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnEntryThatDeclaresNoGroupHasNoTranslationGroup(): void
    {
        $repository = $this->repository($this->database(null, []));

        self::assertNull($repository->forContent(SiteContext::default(), self::CONTENT));
    }

    /**
     * A group whose only member is unreadable is no group at all, rather than a group of nobody.
     *
     * `TranslationGroup` refuses an empty member list outright, so the reader has to answer null here.
     * A page whose single entry lost its locale therefore renders as untranslated content instead of
     * turning into a fault.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAGroupWhoseOnlyRowDeclaresNoLocaleReadsAsNoGroup(): void
    {
        $repository = $this->repository($this->database(self::GROUP, [
            $this->row('e0001', null),
        ]));

        self::assertNull($repository->forContent(SiteContext::default(), self::CONTENT));
    }

    /**
     * One row with an unreadable locale is dropped without taking the other languages down with it.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnUnreadableLocaleCostsThatRowAndNoOther(): void
    {
        $repository = $this->repository($this->database(self::GROUP, [
            $this->row('e0001', 'en-GB'),
            $this->row('e0002', self::UNREADABLE_TAG),
        ]));

        $group = $repository->forContent(SiteContext::default(), self::CONTENT);

        self::assertNotNull($group);
        self::assertCount(1, $group->members());
        self::assertSame('en-GB', $group->members()[0]->locale->toString());
        self::assertFalse($group->isTranslated());
    }

    /**
     * A fallback the item no longer carries degrades to the first locale it does carry.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAFallbackWithoutAMemberDegradesToTheFirstLocaleTheItemCarries(): void
    {
        $repository = $this->repository($this->database(self::GROUP, [
            $this->row('e0001', 'de'),
            $this->row('e0002', 'en-GB'),
        ], 'af'));

        $group = $repository->forContent(SiteContext::default(), self::CONTENT);

        self::assertNotNull($group);
        self::assertSame('de', $group->fallbackLocale->toString());
    }

    /**
     * A stored fallback that is not a language tag degrades the same way an absent member does.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnUnreadableStoredFallbackDegradesToTheFirstLocaleTheItemCarries(): void
    {
        $repository = $this->repository($this->database(self::GROUP, [
            $this->row('e0001', 'de'),
            $this->row('e0002', 'en-GB'),
        ], self::UNREADABLE_TAG));

        $group = $repository->forContent(SiteContext::default(), self::CONTENT);

        self::assertNotNull($group);
        self::assertSame('de', $group->fallbackLocale->toString());
    }

    /**
     * A driver that decodes a JSON column for the caller is read the same as one that returns its text.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAPublicStateListIsReadWhetherTheDriverDecodedItOrNot(): void
    {
        $repository = $this->repository($this->database(self::GROUP, [
            $this->row('e0001', 'en-GB', ['definition_public_states' => ['published']]),
            $this->row('e0002', 'de', ['definition_public_states' => '["published"]']),
            $this->row('e0003', 'fr', ['definition_public_states' => ['review']]),
        ]));

        $group = $repository->forContent(SiteContext::default(), self::CONTENT);

        self::assertNotNull($group);
        self::assertTrue($group->member(LocaleTag::fromString('en-GB'))?->publicState);
        self::assertTrue($group->member(LocaleTag::fromString('de'))?->publicState);
        self::assertFalse($group->member(LocaleTag::fromString('fr'))?->publicState);
    }

    /**
     * A public-state list that cannot be decoded is refused rather than read as "nothing is public".
     *
     * Treating an unreadable list as empty would quietly unpublish every locale of the item, so the
     * reader fails instead, with the decoder's own failure kept as the cause.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnUndecodablePublicStateListIsRefused(): void
    {
        $repository = $this->repository($this->database(self::GROUP, [
            $this->row('e0001', 'en-GB', ['definition_public_states' => '["published"']),
        ]));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Stored workflow public states are invalid.');
        $repository->forContent(SiteContext::default(), self::CONTENT);
    }

    /**
     * A member row missing a field the delivery path depends on is refused by name.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAMemberRowWithoutASlugIsRefused(): void
    {
        $repository = $this->repository($this->database(self::GROUP, [
            $this->row('e0001', 'en-GB', ['slug' => '']),
        ]));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Stored translation group field slug is invalid.');
        $repository->forContent(SiteContext::default(), self::CONTENT);
    }

    /**
     * Every shape a driver returns a timestamp column in reads as the same instant.
     *
     * Drivers disagree here: one hands back a `DateTimeImmutable`, another a mutable `DateTime`, another
     * the raw string, and an unset column arrives as SQL null on one and as the empty string on the next.
     * All four are accepted, and a bare string is read as UTC — the zone every content timestamp is
     * written in — so a scheduled locale goes live at the same moment on every supported engine.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAPublicationBoundIsReadFromEveryShapeADriverReturnsIt(): void
    {
        $zone = new DateTimeZone('UTC');
        $immutable = new DateTimeImmutable('2026-01-02 03:04:05', $zone);
        $repository = $this->repository($this->database(self::GROUP, [
            $this->row('e0001', 'en-GB', ['publish_at' => $immutable]),
            $this->row('e0002', 'de', ['publish_at' => new DateTime('2026-01-03 03:04:05', $zone)]),
            $this->row('e0003', 'fr', ['publish_at' => '2026-01-04 03:04:05', 'unpublish_at' => '']),
            $this->row('e0004', 'af'),
        ]));

        $group = $repository->forContent(SiteContext::default(), self::CONTENT);

        self::assertNotNull($group);
        $english = $group->member(LocaleTag::fromString('en-GB'))?->publicationWindow;
        self::assertSame($immutable, $english?->startsAt());
        self::assertNull($english?->endsAt());
        self::assertSame(
            '2026-01-03T03:04:05+00:00',
            $group->member(LocaleTag::fromString('de'))?->publicationWindow->startsAt()
                ?->format(DateTimeInterface::ATOM),
        );
        $french = $group->member(LocaleTag::fromString('fr'))?->publicationWindow;
        self::assertSame('2026-01-04T03:04:05+00:00', $french?->startsAt()?->format(DateTimeInterface::ATOM));
        self::assertNull($french?->endsAt());
        self::assertNull($group->member(LocaleTag::fromString('af'))?->publicationWindow->startsAt());
    }

    /**
     * A publication bound that is neither a date nor readable text is refused rather than coerced.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAPublicationBoundThatIsNeitherDateNorTextIsRefused(): void
    {
        $repository = $this->repository($this->database(self::GROUP, [
            $this->row('e0001', 'en-GB', ['publish_at' => 20260102]),
        ]));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('A stored translation group publication bound is invalid.');
        $repository->forContent(SiteContext::default(), self::CONTENT);
    }

    /**
     * An existing group refuses a caller that tries to redeclare its fallback.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnExistingGroupRefusesAContradictoryFallback(): void
    {
        $database = $this->createMock(Connection::class);
        $database->method('quoteSingleIdentifier')->willReturnArgument(0);
        $database->expects(self::once())->method('fetchAssociative')->willReturn([
            'id' => self::GROUP,
            'site_identifier' => SiteContext::DEFAULT,
            'fallback_locale' => 'en-GB',
        ]);
        $database->expects(self::never())->method('insert');

        $this->expectException(InvalidTranslationGroup::class);
        $this->expectExceptionMessage('cannot change its declared fallback locale');
        $this->repository($database)->declareGroup(
            SiteContext::default(),
            self::GROUP,
            LocaleTag::fromString('de'),
            LocaleTag::fromString('de'),
        );
    }

    /**
     * An existing group cannot be claimed by a second site even before an entry is attached.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnExistingGroupRefusesASecondSite(): void
    {
        $database = $this->createMock(Connection::class);
        $database->method('quoteSingleIdentifier')->willReturnArgument(0);
        $database->expects(self::once())->method('fetchAssociative')->willReturn([
            'id' => self::GROUP,
            'site_identifier' => SiteContext::DEFAULT,
            'fallback_locale' => 'en-GB',
        ]);
        $database->expects(self::never())->method('insert');

        $this->expectException(InvalidTranslationGroup::class);
        $this->expectExceptionMessage('cannot be shared between sites');
        $this->repository($database)->declareGroup(
            SiteContext::fromString('secondary'),
            self::GROUP,
            LocaleTag::fromString('de'),
        );
    }

    /**
     * The sixty-fifth live member is refused while the group row remains locked.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheSixtyFifthMemberIsRefusedAtWriteTime(): void
    {
        $database = $this->createMock(Connection::class);
        $database->method('quoteSingleIdentifier')->willReturnArgument(0);
        $database->expects(self::once())->method('fetchAssociative')->with(
            self::callback(static fn (string $query): bool => str_contains($query, 'FOR UPDATE')),
            [self::GROUP],
        )->willReturn(['site_identifier' => SiteContext::DEFAULT]);
        $database->expects(self::once())->method('fetchOne')->willReturn('64');

        $this->expectException(InvalidTranslationGroup::class);
        $this->expectExceptionMessage('at most 64 locales');
        $this->repository($database)->guardAttachment(
            SiteContext::default(),
            self::GROUP,
            self::CONTENT,
        );
    }

    /**
     * Build one joined entry-and-workflow-version row as the reader expects to receive it.
     *
     * @param   string                $tail       Final five characters of this row's entry identifier.
     * @param   ?string               $locale     Value of the `locale` column, null when the row declares none.
     * @param   array<string, mixed>  $overrides  Columns replacing the sound defaults, by column name.
     *
     * @return  array<string, mixed>  The row, sound unless an override says otherwise.
     *
     * @since   2.0.0
     */
    private function row(string $tail, ?string $locale, array $overrides = []): array
    {
        return array_merge([
            'id' => '018f22e2-7c8b-7ab0-8f3a-88e8026b' . $tail,
            'locale' => $locale,
            'slug' => 'page-' . $tail,
            'workflow_state_key' => 'published',
            'definition_public_states' => '["published"]',
            'publish_at' => null,
            'unpublish_at' => null,
        ], $overrides);
    }

    /**
     * Build a connection that answers the reader's three statements with the rows a test is about.
     *
     * @param   ?string                     $groupId   Group the entry lookup reports, null when it reports none.
     * @param   list<array<string, mixed>>  $rows      Joined member rows the group read returns, in locale order.
     * @param   string                      $fallback  Fallback locale stored against the group.
     *
     * @return  Connection&Stub  Connection standing in for the installation database.
     *
     * @since   2.0.0
     */
    private function database(?string $groupId, array $rows, string $fallback = 'en-GB'): Connection&Stub
    {
        $database = $this->createStub(Connection::class);
        $database->method('quoteSingleIdentifier')->willReturnCallback(
            static fn (string $identifier): string => $identifier,
        );
        $database->method('fetchOne')->willReturnCallback(
            static fn (string $query): string|false => str_contains($query, 'fallback_locale')
                ? $fallback
                : ($groupId ?? false),
        );
        $database->method('fetchAllAssociative')->willReturn($rows);

        return $database;
    }

    /**
     * Build the reader over one connection, under the prefix every other unit test uses.
     *
     * @param   Connection  $database  Connection the reader issues its statements on.
     *
     * @return  DoctrineTranslationGroupRepository  Reader under test.
     *
     * @since   2.0.0
     */
    private function repository(Connection $database): DoctrineTranslationGroupRepository
    {
        return new DoctrineTranslationGroupRepository($database, new TableNames($database, 'kumwe_'));
    }
}
