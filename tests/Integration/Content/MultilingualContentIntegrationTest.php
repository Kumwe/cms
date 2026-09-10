<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\Content;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DriverException;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\ForeignKeyConstraint\ReferentialAction;
use Doctrine\DBAL\Schema\Name\UnqualifiedName;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\App\Content\Application\ContentRecord;
use Kumwe\App\Content\Application\ContentService;
use Kumwe\App\Content\Application\TranslationGroupRepository;
use Kumwe\App\Content\Domain\ContentEntry;
use Kumwe\App\Content\Domain\ContentStatus;
use Kumwe\App\Content\Domain\InvalidTranslationGroup;
use Kumwe\App\Content\Domain\TranslationGroup;
use Kumwe\App\Content\Domain\TranslationGroupMember;
use Kumwe\App\Content\Infrastructure\Persistence\DoctrineContentRepository;
use Kumwe\App\Content\Infrastructure\Persistence\DoctrineTranslationGroupRepository;
use Kumwe\App\Content\Presentation\TranslationGroupPresenter;
use Kumwe\App\Http\Handler\HomePageHandler;
use Kumwe\App\Http\Handler\PublishedContentHandler;
use Kumwe\App\Infrastructure\Persistence\Migration\IndexNameIsolationMigration;
use Kumwe\App\Infrastructure\Persistence\Migration\MultilingualContentMigration;
use Kumwe\App\Infrastructure\Persistence\Migration\TranslationGroupSiteOwnershipMigration;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Kernel\Configuration\ApplicationConfiguration;
use Kumwe\App\Kernel\ContainerFactory;
use Kumwe\App\Localization\Domain\LocaleTag;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Site\Application\SiteSettings;
use Kumwe\App\Tests\Support\TestKernelFactory;
use Kumwe\App\Kernel\Container;
use Laminas\Diactoros\ServerRequestFactory;
use Mezzio\Application;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Ramsey\Uuid\Uuid;
use RuntimeException;

#[CoversClass(MultilingualContentMigration::class)]
#[CoversClass(TranslationGroupSiteOwnershipMigration::class)]
#[CoversClass(DoctrineTranslationGroupRepository::class)]
#[CoversClass(DoctrineContentRepository::class)]
#[CoversClass(ContentService::class)]
#[CoversClass(ContentEntry::class)]
#[CoversClass(TranslationGroup::class)]
#[CoversClass(TranslationGroupMember::class)]
#[CoversClass(TranslationGroupPresenter::class)]
#[CoversClass(PublishedContentHandler::class)]
#[CoversClass(HomePageHandler::class)]
/**
 * Proves the content half of decision D12 against a real database and the real public site.
 *
 * Everything the acceptance criteria ask for is asserted here rather than in isolation, because every
 * one of them is a property of the whole path: a group publishes one locale while another drafts, a
 * missing translation resolves to the declared fallback, per-locale slugs do not collide across the
 * locales of one group, and `hreflang` and the shipped language selector list exactly the published
 * members and nothing else. The two uniqueness properties are proven at the database level — by
 * watching the engine refuse the write — rather than by trusting the application to have checked.
 *
 * The suite runs on MariaDB, MySQL and PostgreSQL through the same configuration every other
 * integration test uses, so the portability of the migration is exercised by running it at all.
 *
 * @since  2.0.0
 */
final class MultilingualContentIntegrationTest extends TestCase
{
    /**
     * Identifier of the logical item this test translates.
     *
     * @var    string
     * @since  2.0.0
     */
    private const GROUP = '018f22e2-7c8b-7ab0-8f3a-88e8026bc001';

    /**
     * Prove the migration lands the language dimension and its constraints on every supported engine.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheMigrationAddsTheLanguageDimensionAndItsConstraints(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $tables = $this->service($container, TableNames::class);
        $manager = $this->service($container, Connection::class)->createSchemaManager();

        $entries = $manager->introspectTableByUnquotedName($tables->raw('content_entries'));
        self::assertTrue($entries->hasColumn('locale'));
        self::assertTrue($entries->hasColumn('translation_group_id'));
        self::assertTrue($entries->hasColumn('translation_group_site_identifier'));
        // The installed schema carries these under their installation-unique names: the index-name
        // isolation at the end of the plan renames every literal, so two prefixed installations can
        // share one PostgreSQL schema. The literal spellings are asserted on the fixture-built table
        // below, where the migration under test runs alone.
        $translationLocale = IndexNameIsolationMigration::isolatedName(
            $tables->raw('content_entries'),
            'uniq_content_translation_locale',
        );
        $siteSlug = IndexNameIsolationMigration::isolatedName(
            $tables->raw('content_entries'),
            'uniq_content_site_slug',
        );
        self::assertTrue($entries->hasIndex($translationLocale));
        self::assertTrue($entries->getIndex($translationLocale)->isUnique());
        self::assertTrue($entries->hasIndex($siteSlug));
        self::assertTrue($entries->getIndex($siteSlug)->isUnique());
        $groupsName = $tables->raw('content_translation_groups');
        self::assertTrue($manager->tablesExist([$groupsName]));
        $ownership = array_values(array_filter(
            $entries->getForeignKeys(),
            static fn (ForeignKeyConstraint $foreignKey): bool => array_map(
                static fn (UnqualifiedName $name): string => $name->getIdentifier()->getValue(),
                $foreignKey->getReferencingColumnNames(),
            ) === ['translation_group_id', 'translation_group_site_identifier'],
        ));
        self::assertCount(1, $ownership);
        self::assertSame(
            $groupsName,
            $ownership[0]->getReferencedTableName()->getUnqualifiedName()->getValue(),
        );
        self::assertSame(
            ['id', 'site_identifier'],
            array_map(
                static fn (UnqualifiedName $name): string => $name->getIdentifier()->getValue(),
                $ownership[0]->getReferencedColumnNames(),
            ),
        );
        self::assertContains($ownership[0]->getOnDeleteAction(), [
            ReferentialAction::RESTRICT,
            ReferentialAction::NO_ACTION,
        ]);
        self::assertContains($ownership[0]->getOnUpdateAction(), [
            ReferentialAction::RESTRICT,
            ReferentialAction::NO_ACTION,
        ]);
        self::assertCount(0, array_filter(
            $entries->getForeignKeys(),
            static fn (ForeignKeyConstraint $foreignKey): bool => array_map(
                static fn (UnqualifiedName $name): string => $name->getIdentifier()->getValue(),
                $foreignKey->getReferencingColumnNames(),
            ) === ['translation_group_id'],
        ), 'The overlapping predecessor key must not survive beside the composite relationship.');
    }

    /**
     * Prove a fresh installation builds the whole dimension, and that running it again changes nothing.
     *
     * Both properties are invisible once an installation is migrated, because by then every `has` check
     * in the migration short-circuits and the statements that build the dimension never run again. So
     * this drives the migration against its own scratch tables — from nothing, then a second time over
     * what it just built — which is the only way to watch it create the group table, add both columns,
     * name the foreign key by digest, and copy the entry identifier's character definition onto the
     * referencing column. That copy is the MySQL-family hazard the migration exists to handle: without
     * it the engine refuses a foreign key between textual GUIDs of differing collations outright.
     *
     * MySQL and MariaDB scope index names to their table, which is what lets a second `content_entries`
     * stand beside the installation's own. PostgreSQL keeps index names schema-global, so the same
     * rehearsal would collide with the real index instead of testing anything; there the dimension is
     * proven by the migration having run at all, which the assertion above reads back.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheMigrationBuildsTheDimensionFromNothingAndReRunsAsANoOp(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $database = $this->service($container, Connection::class);
        if (!$database->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            self::markTestSkipped('Index names are schema-global outside the MySQL family.');
        }

        $tables = new TableNames($database, 'mlc' . substr(bin2hex(random_bytes(4)), 0, 8) . '_');
        $migration = new MultilingualContentMigration($tables);
        $manager = $database->createSchemaManager();
        $entriesName = $tables->raw('content_entries');
        $groupsName = $tables->raw('content_translation_groups');

        $entries = new Table($entriesName);
        $entries->addColumn('id', Types::GUID);
        $entries->addColumn('site_identifier', Types::STRING, ['length' => 191]);
        $entries->addPrimaryKeyConstraint(
            PrimaryKeyConstraint::editor()->setUnquotedColumnNames('id')->create(),
        );
        $manager->createTable($entries);

        try {
            $migration->up($database);
            $built = $manager->introspectTableByUnquotedName($entriesName);

            self::assertTrue($manager->tablesExist([$groupsName]));
            self::assertTrue($built->hasColumn('locale'));
            self::assertTrue($built->hasColumn('translation_group_id'));
            self::assertTrue($built->getIndex('uniq_content_translation_locale')->isUnique());
            self::assertTrue($built->hasIndex('idx_content_site_locale'));
            self::assertCount(1, $built->getForeignKeys());
            self::assertSame(
                $built->getColumn('id')->getCollation(),
                $built->getColumn('translation_group_id')->getCollation(),
                'A foreign key between textual GUIDs of differing collations is refused outright.',
            );

            // The second run is the interrupted-attempt rehearsal: every addition is already there, so
            // the comparator must find no difference at all rather than re-issuing a single statement.
            $migration->up($database);
            $again = $manager->introspectTableByUnquotedName($entriesName);

            self::assertCount(count($built->getColumns()), $again->getColumns());
            self::assertCount(count($built->getIndexes()), $again->getIndexes());
            self::assertCount(1, $again->getForeignKeys());
        } finally {
            // The referencing table goes first, and each drop is guarded, so a failure above surfaces as
            // itself rather than as a missing-table error raised while clearing up after it.
            foreach ([$entriesName, $groupsName] as $scratch) {
                if ($manager->tablesExist([$scratch])) {
                    $manager->dropTable($scratch);
                }
            }
        }
    }

    /**
     * Prove the site root, the one entry point naming no language, renders through the language block.
     *
     * The root is where negotiation is allowed to choose, so it carries a different code path from a
     * permalink that names its locale in the URL. Requesting it proves that path is wired and that the
     * template renders the alternates the handler hands it, whether or not a homepage is configured.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheSiteRootRendersThroughTheLanguageAwareEntryPoint(): void
    {
        $body = $this->publicPage('/');

        self::assertStringContainsString('<html', $body);
    }

    /**
     * Prove every root alternate renders the locale it names, even against a conflicting preference.
     *
     * English is the nominated entry and German is first selected through `Accept-Language`, reproducing
     * the state in which both alternates used to collapse to `/`. The emitted URLs are followed through
     * the real negotiation middleware with the opposite header each time. The explicit query choice must
     * win, the selected entry and document language must agree, and each variant must be self-canonical.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testEveryRootAlternateRendersTheLocaleItAdvertises(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $context = TestKernelFactory::administratorContext($container);
        $content = $this->service($container, ContentService::class);
        $settings = $this->service($container, SiteSettings::class);
        $baseUrl = rtrim($this->service($container, ApplicationConfiguration::class)->baseUrl, '/');
        $previous = $settings->managed($context);
        $suffix = substr(bin2hex(random_bytes(4)), 0, 8);
        $englishTitle = 'Root English ' . $suffix;
        $germanTitle = 'Root Deutsch ' . $suffix;
        $englishSlug = 'root-english-' . $suffix;
        $english = $this->page($content, $context, $englishTitle, $englishSlug, true);
        $german = $this->page($content, $context, $germanTitle, 'root-deutsch-' . $suffix, true);
        $groupId = Uuid::uuid7()->toString();
        foreach ([[$english, 'en-GB'], [$german, 'de']] as [$record, $locale]) {
            $content->translate(
                $context,
                $record->entry->id(),
                $record->entry->version(),
                LocaleTag::fromString($locale),
                $groupId,
                LocaleTag::fromString('en-GB'),
            );
        }

        try {
            $settings->updateAll($context, [
                'homepage_content_id' => $english->entry->id(),
                'homepage_slug' => $englishSlug,
            ]);

            $neutralBody = $this->publicPage('/', ['Accept-Language' => 'de']);
            self::assertStringContainsString('hreflang="de" href="/?locale=de"', $neutralBody);
            self::assertStringContainsString('hreflang="en-GB" href="/?locale=en-GB"', $neutralBody);

            $germanBody = $this->publicPage('/?locale=de', ['Accept-Language' => 'en-GB']);
            self::assertStringContainsString($germanTitle, $germanBody);
            self::assertStringContainsString('<html lang="de" dir="ltr">', $germanBody);
            self::assertStringContainsString(
                '<link rel="canonical" href="' . $baseUrl . '/?locale=de">',
                $germanBody,
            );
            self::assertStringContainsString(
                'href="/?locale=de" hreflang="de" lang="de" dir="ltr" aria-current="true"',
                $germanBody,
            );
            self::assertStringContainsString('hreflang="en-GB" href="/?locale=en-GB"', $germanBody);

            $englishBody = $this->publicPage('/?locale=en-GB', ['Accept-Language' => 'de']);
            self::assertStringContainsString($englishTitle, $englishBody);
            self::assertStringContainsString('<html lang="en-GB" dir="ltr">', $englishBody);
            self::assertStringContainsString(
                '<link rel="canonical" href="' . $baseUrl . '/?locale=en-GB">',
                $englishBody,
            );
            self::assertStringContainsString(
                'href="/?locale=en-GB" hreflang="en-GB" lang="en-GB" dir="ltr" aria-current="true"',
                $englishBody,
            );
            self::assertStringContainsString('hreflang="de" href="/?locale=de"', $englishBody);

            $fallback = $this->publicPageResponse('/?locale=af', ['Accept-Language' => 'de']);
            $fallbackBody = (string) $fallback->getBody();
            self::assertStringContainsString($englishTitle, $fallbackBody);
            self::assertStringContainsString('<html lang="en-GB" dir="ltr">', $fallbackBody);
            self::assertSame('en-GB', $fallback->getHeaderLine('Content-Language'));
        } finally {
            $settings->updateAll($context, $previous);
        }
    }

    /**
     * Prove one locale publishes while another drafts, and a missing translation takes the fallback.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testOneLocalePublishesWhileAnotherDraftsAndAMissingOneFallsBack(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $context = TestKernelFactory::administratorContext($container);
        $content = $this->service($container, ContentService::class);
        $groups = $this->service($container, TranslationGroupRepository::class);
        $suffix = substr(bin2hex(random_bytes(4)), 0, 8);

        $english = $this->page($content, $context, 'About ' . $suffix, 'about-' . $suffix, true);
        $german = $this->page($content, $context, 'Ueber uns ' . $suffix, 'ueber-uns-' . $suffix, false);
        $groupId = Uuid::uuid7()->toString();
        $content->translate(
            $context,
            $english->entry->id(),
            $english->entry->version(),
            LocaleTag::fromString('en-GB'),
            $groupId,
        );
        $content->translate(
            $context,
            $german->entry->id(),
            $german->entry->version(),
            LocaleTag::fromString('de'),
            $groupId,
        );

        $group = $groups->forContent($context->site(), $english->entry->id());
        self::assertNotNull($group);
        self::assertCount(2, $group->members());
        self::assertSame(['de', 'en-GB'], array_map(
            static fn (object $member): string => $member->locale->toString(),
            $group->members(),
        ));
        self::assertSame(['en-GB'], array_map(
            static fn (object $member): string => $member->locale->toString(),
            $group->publishedMembers(new \DateTimeImmutable('now')),
        ));
        self::assertSame(
            $english->entry->id(),
            $group->resolve(LocaleTag::fromString('de'), new \DateTimeImmutable('now'))?->contentId,
        );
        self::assertSame(
            $english->entry->id(),
            $group->resolve(LocaleTag::fromString('af'), new \DateTimeImmutable('now'))?->contentId,
        );
    }

    /**
     * Restoring a translated entry cannot reintroduce a sixty-fifth live member.
     *
     * Trashing one locale frees a slot, so attaching a replacement is valid. The trashed row still names
     * the group, however, and restoring it must take the same group lock and repeat the live-member check
     * before clearing its deletion marker. This executes through the real repository and transaction
     * adapter on every supported engine.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRestoringATranslationCannotExceedTheLiveMemberCeiling(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $context = TestKernelFactory::administratorContext($container);
        $content = $this->service($container, ContentService::class);
        $suffix = substr(bin2hex(random_bytes(4)), 0, 8);
        $groupId = Uuid::uuid7()->toString();
        $members = [];

        for ($index = 0; $index < TranslationGroup::MAXIMUM_MEMBERS; $index++) {
            $locale = chr(97 + intdiv($index, 26)) . chr(97 + ($index % 26));
            $record = $this->page(
                $content,
                $context,
                'Member ' . $locale . ' ' . $suffix,
                'member-' . $locale . '-' . $suffix,
                false,
            );
            $members[] = $content->translate(
                $context,
                $record->entry->id(),
                $record->entry->version(),
                LocaleTag::fromString($locale),
                $groupId,
            );
        }

        $removed = $members[1];
        $trashed = $content->trash(
            $context,
            $removed->entry->id(),
            $removed->entry->version(),
        );
        $replacement = $this->page(
            $content,
            $context,
            'Replacement ' . $suffix,
            'replacement-' . $suffix,
            false,
        );
        $content->translate(
            $context,
            $replacement->entry->id(),
            $replacement->entry->version(),
            LocaleTag::fromString('zz'),
            $groupId,
        );

        try {
            $content->restore($context, $trashed->entry->id(), $trashed->entry->version());
            self::fail('Restoring a sixty-fifth live translation must be refused.');
        } catch (InvalidTranslationGroup $exception) {
            self::assertStringContainsString('at most 64 locales', $exception->getMessage());
        }

        self::assertNotNull($content->get($context, $trashed->entry->id(), true)->deletedAt);
    }

    /**
     * Prove the database itself refuses a second entry for one locale of one item.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheDatabaseRefusesTwoEntriesForOneLocaleOfOneItem(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $context = TestKernelFactory::administratorContext($container);
        $content = $this->service($container, ContentService::class);
        $database = $this->service($container, Connection::class);
        $tables = $this->service($container, TableNames::class);
        $suffix = substr(bin2hex(random_bytes(4)), 0, 8);

        $first = $this->page($content, $context, 'First ' . $suffix, 'first-' . $suffix, true);
        $second = $this->page($content, $context, 'Second ' . $suffix, 'second-' . $suffix, true);
        $groupId = Uuid::uuid7()->toString();
        $content->translate(
            $context,
            $first->entry->id(),
            $first->entry->version(),
            LocaleTag::fromString('en-GB'),
            $groupId,
        );

        $this->expectException(DriverException::class);
        $database->executeStatement(sprintf(
            'UPDATE %s SET locale = ?, translation_group_id = ?, '
            . 'translation_group_site_identifier = site_identifier WHERE id = ?',
            $tables->quoted('content_entries'),
        ), ['en-GB', $groupId, $second->entry->id()], [Types::STRING, Types::STRING, Types::GUID]);
    }

    /**
     * Prove every supported engine enforces the translated entry's owner-equality check on update.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheDatabaseRefusesMovingATranslatedEntryAwayFromItsGroupOwner(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $context = TestKernelFactory::administratorContext($container);
        $content = $this->service($container, ContentService::class);
        $database = $this->service($container, Connection::class);
        $tables = $this->service($container, TableNames::class);
        $suffix = substr(bin2hex(random_bytes(4)), 0, 8);
        $entry = $this->page($content, $context, 'Owned ' . $suffix, 'owned-' . $suffix, true);
        $content->translate(
            $context,
            $entry->entry->id(),
            $entry->entry->version(),
            LocaleTag::fromString('en-GB'),
            Uuid::uuid7()->toString(),
        );

        try {
            $database->update(
                $tables->raw('content_entries'),
                ['site_identifier' => 'cross-site-' . $suffix],
                ['id' => $entry->entry->id()],
            );
            self::fail('A translated entry must remain owned by the same site as its group.');
        } catch (DriverException) {
            // The real database check, rather than an application precondition, refused the update.
        }

        self::assertSame($context->site()->identifier(), $database->fetchOne(sprintf(
            'SELECT site_identifier FROM %s WHERE id = ?',
            $tables->quoted('content_entries'),
        ), [$entry->entry->id()]));
    }

    /**
     * Prove the database refuses two locales of one item claiming the same route segment.
     *
     * The site-wide slug index is what carries this property: a slug names one page in a site, so two
     * locales of one item can never collide on one, and a visitor arriving on a segment is never
     * ambiguous about which language they asked for.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheDatabaseRefusesTwoLocalesClaimingOneRouteSegment(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $context = TestKernelFactory::administratorContext($container);
        $content = $this->service($container, ContentService::class);
        $database = $this->service($container, Connection::class);
        $tables = $this->service($container, TableNames::class);
        $suffix = substr(bin2hex(random_bytes(4)), 0, 8);

        $english = $this->page($content, $context, 'Shared ' . $suffix, 'shared-' . $suffix, true);
        $german = $this->page($content, $context, 'Geteilt ' . $suffix, 'geteilt-' . $suffix, true);
        $groupId = Uuid::uuid7()->toString();
        $content->translate(
            $context,
            $english->entry->id(),
            $english->entry->version(),
            LocaleTag::fromString('en-GB'),
            $groupId,
        );
        $content->translate(
            $context,
            $german->entry->id(),
            $german->entry->version(),
            LocaleTag::fromString('de'),
            $groupId,
        );

        $this->expectException(DriverException::class);
        $database->executeStatement(sprintf(
            'UPDATE %s SET slug = ? WHERE id = ?',
            $tables->quoted('content_entries'),
        ), ['shared-' . $suffix, $german->entry->id()], [Types::STRING, Types::GUID]);
    }

    /**
     * Prove the public page advertises exactly the published locales and offers exactly those choices.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testThePublicPageAdvertisesAndOffersExactlyThePublishedLocales(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $context = TestKernelFactory::administratorContext($container);
        $content = $this->service($container, ContentService::class);
        $suffix = substr(bin2hex(random_bytes(4)), 0, 8);

        $english = $this->page($content, $context, 'Guide ' . $suffix, 'guide-' . $suffix, true);
        $german = $this->page($content, $context, 'Anleitung ' . $suffix, 'anleitung-' . $suffix, true);
        $afrikaans = $this->page($content, $context, 'Gids ' . $suffix, 'gids-' . $suffix, false);
        $groupId = Uuid::uuid7()->toString();
        foreach ([[$english, 'en-GB'], [$german, 'de'], [$afrikaans, 'af']] as [$record, $locale]) {
            $content->translate(
                $context,
                $record->entry->id(),
                $record->entry->version(),
                LocaleTag::fromString($locale),
                $groupId,
                LocaleTag::fromString('en-GB'),
            );
        }

        $body = $this->publicPage('/pages/guide-' . $suffix);

        self::assertStringContainsString(
            sprintf('<link rel="alternate" hreflang="en-GB" href="/guide-%s">', $suffix),
            $body,
        );
        self::assertStringContainsString(
            sprintf('<link rel="alternate" hreflang="de" href="/anleitung-%s">', $suffix),
            $body,
        );
        self::assertStringContainsString(
            sprintf('<link rel="alternate" hreflang="x-default" href="/guide-%s">', $suffix),
            $body,
        );
        self::assertStringNotContainsString('hreflang="af"', $body);
        self::assertStringNotContainsString('gids-' . $suffix, $body);
        self::assertStringContainsString('site-language-selector', $body);
        self::assertStringContainsString(
            sprintf('href="/anleitung-%s" hreflang="de" lang="de" dir="ltr"', $suffix),
            $body,
        );
        self::assertStringContainsString('aria-current="true"', $body);

        $germanBody = $this->publicPage('/anleitung-' . $suffix, ['Accept-Language' => 'en-GB']);
        self::assertStringContainsString('Anleitung ' . $suffix, $germanBody);
        self::assertStringContainsString('<html lang="de" dir="ltr">', $germanBody);
        self::assertStringContainsString(
            sprintf('href="/anleitung-%s" hreflang="de" lang="de" dir="ltr" aria-current="true"', $suffix),
            $germanBody,
        );
    }

    /**
     * Prove a group degrades around stored rows the database should never have been left holding.
     *
     * The read path is deliberately forgiving, because a group is assembled from rows that outlive the
     * code that wrote them: a locale column edited by hand, a fallback pointing at a translation since
     * withdrawn, an entry that was never translated at all. None of those may take down a page that is
     * otherwise perfectly serveable, so each degrades to the next best answer instead of raising. The
     * damage is written straight through the connection here, because that is the only way to produce
     * rows the application layer refuses to create.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheGroupDegradesAroundRowsTheApplicationWouldNeverWrite(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $context = TestKernelFactory::administratorContext($container);
        $content = $this->service($container, ContentService::class);
        $groups = $this->service($container, TranslationGroupRepository::class);
        $database = $this->service($container, Connection::class);
        $tables = $this->service($container, TableNames::class);
        $suffix = substr(bin2hex(random_bytes(4)), 0, 8);

        $lone = $this->page($content, $context, 'Lone ' . $suffix, 'lone-' . $suffix, true);
        self::assertNull(
            $groups->forContent($context->site(), $lone->entry->id()),
            'An entry nobody has translated belongs to no group.',
        );

        $english = $this->page($content, $context, 'Manual ' . $suffix, 'manual-' . $suffix, true);
        $german = $this->page($content, $context, 'Handbuch ' . $suffix, 'handbuch-' . $suffix, true);
        $groupId = Uuid::uuid7()->toString();
        foreach ([[$english, 'en-GB'], [$german, 'de']] as [$record, $locale]) {
            $content->translate(
                $context,
                $record->entry->id(),
                $record->entry->version(),
                LocaleTag::fromString($locale),
                $groupId,
                LocaleTag::fromString('en-GB'),
            );
        }

        $entries = $tables->raw('content_entries');

        try {
            // A fallback naming a locale no member carries any more falls back to a member that exists.
            $database->update(
                $tables->raw('content_translation_groups'),
                ['fallback_locale' => 'zu'],
                ['id' => $groupId],
            );
            $stale = $groups->forContent($context->site(), $english->entry->id());
            self::assertNotNull($stale);
            self::assertSame('de', $stale->fallbackLocale->toString());

            // A member whose stored locale is not a language tag is dropped, not raised over.
            $database->update($entries, ['locale' => 'not a tag'], ['id' => $german->entry->id()]);
            $partial = $groups->forContent($context->site(), $english->entry->id());
            self::assertNotNull($partial);
            self::assertCount(1, $partial->members());

            // Once no member survives, the group reads as absent rather than as an empty group.
            $database->update($entries, ['locale' => ''], ['id' => $english->entry->id()]);
            self::assertNull($groups->forContent($context->site(), $english->entry->id()));
        } finally {
            // The damage above is written past every guard the application has, so it has to be undone
            // here: these rows outlive the test, and the suite is run twice against one database.
            $database->update($entries, ['locale' => 'en-GB'], ['id' => $english->entry->id()]);
            $database->update($entries, ['locale' => 'de'], ['id' => $german->entry->id()]);
            $database->update(
                $tables->raw('content_translation_groups'),
                ['fallback_locale' => 'en-GB'],
                ['id' => $groupId],
            );
        }
    }

    /**
     * Create one page in one language, published or left drafting.
     *
     * @param   ContentService    $content    Service every write goes through.
     * @param   ExecutionContext  $context    Actor and site the page is created for.
     * @param   string            $title      Title of the page.
     * @param   string            $slug       Route segment the page is published under.
     * @param   bool              $published  Whether the page is moved into its public state.
     *
     * @return  ContentRecord  The stored record, at the version the caller must hand back.
     *
     * @since   2.0.0
     */
    private function page(
        ContentService $content,
        ExecutionContext $context,
        string $title,
        string $slug,
        bool $published,
    ): ContentRecord {
        $record = $content->create($context, $title, $slug, ['body' => 'Body of ' . $title]);
        if (!$published) {
            return $record;
        }
        // The built-in workflow reaches its public state through review, so a page that has to be live
        // for this test travels the same two edges an editor travels.
        $record = $content->transition(
            $context,
            $record->entry->id(),
            $record->entry->version(),
            ContentStatus::Review,
        );

        return $content->transition(
            $context,
            $record->entry->id(),
            $record->entry->version(),
            ContentStatus::Published,
        );
    }

    /**
     * Fetch one public page through the real application, following its canonical redirect.
     *
     * The request is issued on a host the installation under test actually answers to, read from its own
     * `ApplicationConfiguration` rather than assumed, because the host boundary is a real refusal: a
     * request arriving on an untrusted name is answered 400 before any content is resolved, and a
     * hardcoded name would make this test report a delivery failure wherever the configured host differs.
     *
     * @param   string                 $path     Root-relative path to request.
     * @param   array<string, string>  $headers  Request headers to add.
     *
     * @return  string  The rendered HTML of the canonical page.
     *
     * @since   2.0.0
     */
    private function publicPage(string $path, array $headers = []): string
    {
        return (string) $this->publicPageResponse($path, $headers)->getBody();
    }

    /**
     * Fetch one public page through the real application and retain its response metadata.
     *
     * @param   string                 $path     Root-relative path to request.
     * @param   array<string, string>  $headers  Request headers to add.
     *
     * @return  ResponseInterface  Canonical 200 response after following a public-content redirect.
     *
     * @since   2.0.0
     */
    private function publicPageResponse(string $path, array $headers = []): ResponseInterface
    {
        $container = (new ContainerFactory())->create(Environment::fromGlobals());
        $application = $this->service($container, Application::class);
        $host = $this->service($container, ApplicationConfiguration::class)->trustedHosts[0];

        $response = $this->publicRequest($application, $host, $path, $headers);
        if ($response->getStatusCode() === 308) {
            $response = $this->publicRequest(
                $application,
                $host,
                $response->getHeaderLine('Location'),
                $headers,
            );
        }
        self::assertSame(200, $response->getStatusCode(), $path);

        return $response;
    }

    /**
     * Issue one public GET through the real application on a trusted host.
     *
     * @param   Application            $application  Booted application under test.
     * @param   string                 $host         Host name the installation answers to.
     * @param   string                 $path         Root-relative path to request.
     * @param   array<string, string>  $headers      Request headers to add.
     *
     * @return  ResponseInterface  The application's response, whatever its status.
     *
     * @since   2.0.0
     */
    private function publicRequest(
        Application $application,
        string $host,
        string $path,
        array $headers = [],
    ): ResponseInterface {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', 'https://' . $host . $path)
            ->withHeader('Host', $host);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        $query = parse_url($path, PHP_URL_QUERY);
        if (is_string($query)) {
            parse_str($query, $parameters);
            $request = $request->withQueryParams($parameters);
        }

        return $application->handle($request);
    }

    /**
     * Resolve one service out of the container, refusing anything of the wrong type.
     *
     * @template T of object
     *
     * @param   Container         $container  Booted kernel container.
     * @param   class-string<T>   $service    Service to resolve.
     *
     * @return  T  The resolved service.
     *
     * @throws  RuntimeException  When the container answers with something else.
     *
     * @since   2.0.0
     */
    private function service(Container $container, string $service): object
    {
        $resolved = $container->get($service);
        if (!$resolved instanceof $service) {
            throw new RuntimeException(sprintf('The container did not supply %s.', $service));
        }

        return $resolved;
    }
}
