<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Content\Infrastructure\Persistence;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Result;
use Kumwe\App\Content\Application\ContentRecord;
use Kumwe\App\Content\Domain\ContentEntry;
use Kumwe\App\Content\Domain\ContentStatus;
use Kumwe\App\Content\Domain\ExpectedVersion;
use Kumwe\App\Content\Infrastructure\Persistence\DoctrineContentRepository;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\Localization\Domain\LocaleTag;
use Kumwe\Context\Value\SiteContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Pins how the content adapter carries an entry's language dimension into the row and back out of it.
 *
 * The locale, translation group and database-enforced group-owner mirror are columns like any other,
 * and that is the whole claim being
 * checked here: they are written by the insert, rewritten by the versioned update that a translation
 * is, projected by every read, and re-checked on the way back so a legacy row without them reads as
 * an untranslated entry rather than a malformed one. The statements are observed at the connection
 * rather than against an engine, because what is at stake is which values this adapter binds and in
 * what order — the engine's own behaviour is pinned by the integration suite instead.
 *
 * @since  2.0.0
 */
#[CoversClass(DoctrineContentRepository::class)]
final class DoctrineContentRepositoryTest extends TestCase
{
    /**
     * Identifier of the entry every case here stores or reads.
     *
     * @var    string
     * @since  2.0.0
     */
    private const ENTRY = '018f22e2-7c8b-7ab0-8f3a-88e8026bb701';

    /**
     * Identifier of the logical item the entry is one locale of.
     *
     * @var    string
     * @since  2.0.0
     */
    private const GROUP = '018f22e2-7c8b-7ab0-8f3a-88e8026bb901';

    /**
     * Identifier of the content type every stored entry here is pinned to.
     *
     * @var    string
     * @since  2.0.0
     */
    private const TYPE = '018f22e2-7c8b-7ab0-8f3a-88e8026bb801';

    /**
     * Identifier of the workflow every stored entry here is pinned to.
     *
     * @var    string
     * @since  2.0.0
     */
    private const WORKFLOW = '018f22e2-7c8b-7ab0-8f3a-88e8026bb802';

    /**
     * Prove a new entry's declared language and group are written by the insert, and null when absent.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnInsertedRowCarriesTheDeclaredLanguageAndGroupOrNullForNeither(): void
    {
        $written = [];
        $database = $this->connection();
        $database->expects(self::exactly(2))->method('insert')->willReturnCallback(
            static function (string $table, array $values) use (&$written): int {
                self::assertSame('kumwe_content_entries', $table);
                $written[] = $values;

                return 1;
            },
        );
        $repository = new DoctrineContentRepository($database, new TableNames($database, 'kumwe_'));

        $repository->insert($this->record($this->translated()));
        $repository->insert($this->record($this->entry()));

        self::assertSame('pt-BR', $written[0]['locale']);
        self::assertSame(self::GROUP, $written[0]['translation_group_id']);
        self::assertSame(SiteContext::DEFAULT, $written[0]['translation_group_site_identifier']);
        self::assertNull($written[1]['locale']);
        self::assertNull($written[1]['translation_group_id']);
        self::assertNull($written[1]['translation_group_site_identifier']);
    }

    /**
     * Prove declaring an entry's language reaches the row through the same versioned update as a revision.
     *
     * Translating is a versioned change, so it has to be written by the statement that matches on the
     * version the caller read; a locale that only reached the row on a later edit would leave the
     * group's uniqueness constraints deciding against a row that never carried the value.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTranslatingAnEntryIsWrittenByTheVersionedUpdateItself(): void
    {
        $statement = null;
        $bound = [];
        $database = $this->connection();
        $database->expects(self::once())->method('executeStatement')->willReturnCallback(
            static function (string $sql, array $parameters) use (&$statement, &$bound): int {
                $statement = $sql;
                $bound = $parameters;

                return 1;
            },
        );
        $repository = new DoctrineContentRepository($database, new TableNames($database, 'kumwe_'));

        $repository->update($this->record($this->translated()), 1);

        self::assertIsString($statement);
        self::assertStringContainsString(
            'locale = ?, translation_group_id = ?, translation_group_site_identifier = ?',
            $statement,
        );
        self::assertStringContainsString('WHERE id = ? AND version = ? AND deleted_at IS NULL', $statement);
        self::assertSame(
            ['pt-BR', self::GROUP, SiteContext::DEFAULT, self::ENTRY, 1],
            array_slice($bound, -5),
        );
    }

    /**
     * Prove every read projects the language columns and rebuilds the entry from them.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAReadProjectsTheLanguageColumnsAndRebuildsTheEntryFromThem(): void
    {
        $statement = null;
        $database = $this->reader($this->row(), $statement);
        $repository = new DoctrineContentRepository($database, new TableNames($database, 'kumwe_'));

        $record = $repository->findForSite(SiteContext::default(), self::ENTRY);

        self::assertIsString($statement);
        self::assertStringContainsString('e.locale', $statement);
        self::assertStringContainsString('e.translation_group_id', $statement);
        self::assertNotNull($record);
        self::assertSame('pt-BR', $record->entry->locale()?->toString());
        self::assertSame(self::GROUP, $record->entry->translationGroupId());
        self::assertSame('Guide', $record->entry->title());
        self::assertSame(4, $record->entry->version());
    }

    /**
     * Prove a row written before content carried a language reads back as an untranslated entry.
     *
     * The columns are absent on every row written before the dimension existed, and a driver reading a
     * legacy MySQL row can hand back an empty string where PostgreSQL hands back null. Both mean the
     * same thing, so both have to arrive at the entry as no declaration at all.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testALegacyRowWithoutTheLanguageColumnsReadsBackAsUntranslated(): void
    {
        $row = $this->row();
        $row['locale'] = '';
        unset($row['translation_group_id']);
        $statement = null;
        $database = $this->reader($row, $statement);
        $repository = new DoctrineContentRepository($database, new TableNames($database, 'kumwe_'));

        $record = $repository->findForSite(SiteContext::default(), self::ENTRY);

        self::assertNotNull($record);
        self::assertNull($record->entry->locale());
        self::assertNull($record->entry->translationGroupId());
    }

    /**
     * Prove a stored language that is not text is refused at the read that touched it.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAStoredLanguageThatIsNotTextIsRefusedAtTheReadThatTouchedIt(): void
    {
        $row = $this->row();
        $row['locale'] = 42;
        $statement = null;
        $database = $this->reader($row, $statement);
        $repository = new DoctrineContentRepository($database, new TableNames($database, 'kumwe_'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Stored content field locale is invalid.');
        $repository->findForSite(SiteContext::default(), self::ENTRY);
    }

    /**
     * A connection that quotes and builds queries for real, but issues no statement of its own.
     *
     * @return  Connection  Connection under observation; the caller sets the statement expectations.
     *
     * @since   2.0.0
     */
    private function connection(): Connection
    {
        $database = $this->createMock(Connection::class);
        $database->method('quoteSingleIdentifier')->willReturnCallback(
            static fn (string $identifier): string => $identifier,
        );
        $database->method('getDatabasePlatform')->willReturn(new MariaDBPlatform());
        $database->method('createQueryBuilder')->willReturnCallback(
            static fn (): QueryBuilder => new QueryBuilder($database),
        );

        return $database;
    }

    /**
     * A connection whose single select answers with one fabricated driver row.
     *
     * @param   array<string, mixed>  $row        Row the driver is made to return.
     * @param   ?string               $statement  Receives the SQL the repository issued.
     *
     * @return  Connection  Connection answering every select with that row.
     *
     * @since   2.0.0
     */
    private function reader(array $row, ?string &$statement): Connection
    {
        $result = $this->createStub(Result::class);
        $result->method('fetchAssociative')->willReturn($row);
        $result->method('fetchAllAssociative')->willReturn([$row]);
        $database = $this->createStub(Connection::class);
        $database->method('quoteSingleIdentifier')->willReturnCallback(
            static fn (string $identifier): string => $identifier,
        );
        $database->method('getDatabasePlatform')->willReturn(new MariaDBPlatform());
        $database->method('createQueryBuilder')->willReturnCallback(
            static fn (): QueryBuilder => new QueryBuilder($database),
        );
        $database->method('executeQuery')->willReturnCallback(
            static function (string $sql) use (&$statement, $result): Result {
                $statement = $sql;

                return $result;
            },
        );

        return $database;
    }

    /**
     * The stored row every read case starts from, in one published locale of a translated item.
     *
     * @return  array<string, mixed>  Associative row as a driver would hand it back.
     *
     * @since   2.0.0
     */
    private function row(): array
    {
        return [
            'id' => self::ENTRY,
            'site_identifier' => SiteContext::DEFAULT,
            'content_type_id' => self::TYPE,
            'workflow_id' => self::WORKFLOW,
            'content_type_version' => 1,
            'workflow_version' => 1,
            'workflow_state_key' => 'published',
            'title' => 'Guide',
            'slug' => 'guide',
            'data' => '{"body":"Guide body."}',
            'publish_at' => null,
            'unpublish_at' => null,
            'version' => 4,
            'created_at' => '2026-08-07 10:00:00',
            'updated_at' => '2026-08-07 10:00:00',
            'deleted_at' => null,
            'locale' => 'pt-BR',
            'translation_group_id' => self::GROUP,
        ];
    }

    /**
     * An entry that declares no language, as one authored before the dimension existed would.
     *
     * @return  ContentEntry  Untranslated entry at version one.
     *
     * @since   2.0.0
     */
    private function entry(): ContentEntry
    {
        return ContentEntry::create(
            self::ENTRY,
            'Guide',
            'guide',
            ['body' => 'Guide body.'],
            ContentStatus::Published,
        );
    }

    /**
     * The same entry after being declared one locale of a logical item.
     *
     * @return  ContentEntry  Successor at version two, carrying its locale and its group.
     *
     * @since   2.0.0
     */
    private function translated(): ContentEntry
    {
        return $this->entry()->translate(
            new ExpectedVersion(1),
            LocaleTag::fromString('pt-BR'),
            self::GROUP,
        );
    }

    /**
     * Wrap one entry in the stored record the repository writes.
     *
     * @param   ContentEntry  $entry  Entry to store.
     *
     * @return  ContentRecord  Record pinned to the fixed content type and workflow versions.
     *
     * @since   2.0.0
     */
    private function record(ContentEntry $entry): ContentRecord
    {
        $at = new DateTimeImmutable('2026-08-07T10:00:00+00:00');

        return new ContentRecord($entry, self::TYPE, self::WORKFLOW, $at, $at);
    }
}
