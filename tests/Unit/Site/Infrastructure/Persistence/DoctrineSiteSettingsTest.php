<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Site\Infrastructure\Persistence;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Kumwe\App\Application\Authorization\AuthorizationGateway;
use Kumwe\App\Application\Authorization\SystemIdentity;
use Kumwe\App\Application\Persistence\TransactionManager;
use Kumwe\App\Audit\Application\AuditRecorder;
use Kumwe\App\Audit\Domain\AuditEvent;
use Kumwe\App\Content\Application\ContentRecord;
use Kumwe\App\Content\Application\ContentService;
use Kumwe\App\Content\Application\SiteScopedContentRepository;
use Kumwe\App\Content\Domain\ContentEntry;
use Kumwe\App\Content\Domain\ContentStatus;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Site\Infrastructure\Persistence\DoctrineSiteSettings;
use Kumwe\App\Tests\Support\AuthorizationContext;
use Kumwe\App\Tests\Support\ImmediateTransactionManager;
use Kumwe\App\Workflow\Domain\Workflow;
use Kumwe\Context\Value\SiteContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

/**
 * Proves settings persistence separates nullable human attribution from opaque audit actors.
 *
 * @since  2.0.0
 */
#[CoversClass(DoctrineSiteSettings::class)]
#[UsesClass(ContentRecord::class)]
#[UsesClass(ContentService::class)]
final class DoctrineSiteSettingsTest extends TestCase
{
    /**
     * Stable human subject accepted by the nullable GUID settings column.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string HUMAN_ID = '018f22e2-7c8b-7ab0-8f3a-88e8026bb301';

    /**
     * Managed navigation menu returned by the referential-integrity lookup.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string MENU_ID = '018f22e2-7c8b-7ab0-8f3a-88e8026bb401';

    /**
     * System writes must bind SQL NULL as a GUID while retaining the system token in the audit event.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testSystemWriteStoresNullGuidAndRetainsSystemAuditAttribution(): void
    {
        $database = $this->database();
        $database->method('fetchOne')->willReturnCallback(
            static function (string $query): string {
                if (
                    str_contains($query, 'navigation_menus')
                    || str_contains($query, 'resource_site_ownership')
                ) {
                    return self::MENU_ID;
                }

                return 'stored-setting';
            },
        );
        $database->expects(self::never())->method('insert');
        $storedKeys = [];
        /** @var list<string> $storedTypes */
        $storedTypes = [];
        $database->expects(self::exactly(7))->method('executeStatement')->with(
            self::callback(static fn (string $query): bool => $query
                === 'UPDATE kumwe_site_settings SET setting_value = ?, updated_by = ?, updated_at = ?, '
                    . 'version = version + 1 WHERE setting_key = ?'),
            self::callback(static function (array $parameters) use (&$storedKeys): bool {
                self::assertCount(4, $parameters);
                if ($parameters[3] === 'site.homepage_content_id') {
                    self::assertSame('null', $parameters[0]);
                }
                self::assertNull($parameters[1]);
                self::assertInstanceOf(DateTimeImmutable::class, $parameters[2]);
                self::assertIsString($parameters[3]);
                $storedKeys[] = $parameters[3];

                return true;
            }),
            self::callback(static function (array $types) use (&$storedTypes): bool {
                $valueType = $types[0] ?? null;
                self::assertIsString($valueType);
                self::assertContains($valueType, [Types::JSON, Types::STRING]);
                self::assertSame(
                    [Types::GUID, Types::DATETIME_IMMUTABLE, Types::STRING],
                    array_slice($types, 1),
                );
                $storedTypes[] = $valueType;

                return true;
            }),
        )->willReturn(1);
        $audit = $this->audit(SystemIdentity::ProfileInstaller->value);

        $this->settings($database, $audit)->updateAll(
            AuthorizationContext::system(SystemIdentity::ProfileInstaller)->context(
                SiteContext::default(),
                'profile-install-settings',
            ),
            [],
        );

        self::assertSame($this->settingKeys(), $storedKeys);
        self::assertSame(
            [Types::JSON, Types::STRING, Types::JSON, Types::JSON, Types::JSON, Types::JSON, Types::JSON],
            $storedTypes,
        );
    }

    /**
     * Human writes must bind the authenticated subject as a GUID in newly inserted settings rows.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testHumanWriteStoresSubjectGuidAndUsesItForAuditAttribution(): void
    {
        $database = $this->database();
        $database->method('fetchOne')->willReturnCallback(
            static function (string $query): string|false {
                if (
                    str_contains($query, 'navigation_menus')
                    || str_contains($query, 'resource_site_ownership')
                ) {
                    return self::MENU_ID;
                }

                return false;
            },
        );
        $database->expects(self::never())->method('executeStatement');
        $storedKeys = [];
        /** @var list<string> $storedTypes */
        $storedTypes = [];
        $database->expects(self::exactly(7))->method('insert')->with(
            'kumwe_site_settings',
            self::callback(static function (array $values) use (&$storedKeys): bool {
                self::assertSame(self::HUMAN_ID, $values['updated_by'] ?? null);
                self::assertSame(1, $values['version'] ?? null);
                self::assertInstanceOf(DateTimeImmutable::class, $values['updated_at'] ?? null);
                self::assertIsString($values['setting_key'] ?? null);
                if ($values['setting_key'] === 'site.homepage_content_id') {
                    self::assertSame('null', $values['setting_value'] ?? null);
                }
                $storedKeys[] = $values['setting_key'];

                return true;
            }),
            self::callback(static function (array $types) use (&$storedTypes): bool {
                $valueType = $types['setting_value'] ?? null;
                self::assertIsString($valueType);
                self::assertContains($valueType, [Types::JSON, Types::STRING]);
                self::assertSame(Types::GUID, $types['updated_by'] ?? null);
                self::assertSame(Types::DATETIME_IMMUTABLE, $types['updated_at'] ?? null);
                $storedTypes[] = $valueType;

                return true;
            }),
        )->willReturn(1);
        $audit = $this->audit(self::HUMAN_ID);

        $this->settings($database, $audit)->updateAll(
            AuthorizationContext::human(['settings.manage'], self::HUMAN_ID),
            [],
        );

        self::assertSame($this->settingKeys(), $storedKeys);
        self::assertSame(
            [Types::JSON, Types::STRING, Types::JSON, Types::JSON, Types::JSON, Types::JSON, Types::JSON],
            $storedTypes,
        );
    }

    /**
     * Build a connection seam that returns an empty current document and unquoted test identifiers.
     *
     * @return  Connection&MockObject  Observable database seam.
     *
     * @since   2.0.0
     */
    private function database(): Connection&MockObject
    {
        $database = $this->createMock(Connection::class);
        $database->method('quoteSingleIdentifier')->willReturnCallback(
            static fn (string $identifier): string => $identifier,
        );
        $database->method('fetchAllAssociative')->willReturn([]);

        return $database;
    }

    /**
     * Expect one successful settings audit event attributed to the supplied actor.
     *
     * @param   string  $actorId  Human subject UUID or opaque system actor token.
     *
     * @return  AuditRecorder&MockObject  Recorder with the attribution expectation installed.
     *
     * @since   2.0.0
     */
    private function audit(string $actorId): AuditRecorder&MockObject
    {
        $audit = $this->createMock(AuditRecorder::class);
        $audit->expects(self::once())->method('record')->with(self::callback(
            static fn (AuditEvent $event): bool => $event->actorId() === $actorId
                && $event->action() === 'site.settings.update'
                && $event->outcome() === 'success',
        ));

        return $audit;
    }

    /**
     * Construct the authoritative store with deterministic infrastructure collaborators.
     *
     * @param   Connection       $database  Observable database seam.
     * @param   AuditRecorder    $audit     Recorder carrying this scenario's attribution expectation.
     * @param   ?ContentService  $content   Optional homepage reader for referential-integrity scenarios.
     *
     * @return  DoctrineSiteSettings  Settings adapter under test.
     *
     * @since   2.0.0
     */
    private function settings(
        Connection $database,
        AuditRecorder $audit,
        ?ContentService $content = null,
    ): DoctrineSiteSettings {
        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn(new DateTimeImmutable('2026-08-11T12:00:00+00:00'));
        $authorization = $this->createMock(AuthorizationGateway::class);
        $authorization->expects(self::once())->method('assertAllowed');

        return new DoctrineSiteSettings(
            $database,
            new TableNames($database, 'kumwe_'),
            new ImmediateTransactionManager(),
            $audit,
            $clock,
            $authorization,
            $content,
        );
    }

    /**
     * A homepage may be any published content entry, so a document-driven layout can lead the site.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testHomepageAcceptsAnyPublishedContentEntryRegardlessOfLayout(): void
    {
        $database = $this->database();
        $database->method('fetchOne')->willReturn(self::MENU_ID);
        $database->method('executeStatement')->willReturn(1);
        $content = $this->content($this->landingRecord());

        $this->settings($database, $this->audit(self::HUMAN_ID), $content)->updateAll(
            AuthorizationContext::human(['settings.manage'], self::HUMAN_ID),
            ['homepage_content_id' => '018f22e2-7c8b-7ab0-8f3a-88e8026bb701'],
        );

        self::addToAssertionCount(1);
    }

    /**
     * A homepage nominating an unpublished or unknown entry must still be refused.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testHomepageStillRefusesAnUnpublishedContentEntry(): void
    {
        $database = $this->database();
        $database->method('fetchOne')->willReturn(self::MENU_ID);
        $content = $this->content(null);
        $audit = $this->createMock(AuditRecorder::class);
        $audit->expects(self::never())->method('record');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The homepage must be a published content entry');

        $this->settings($database, $audit, $content)->updateAll(
            AuthorizationContext::human(['settings.manage'], self::HUMAN_ID),
            ['homepage_content_id' => '018f22e2-7c8b-7ab0-8f3a-88e8026bb702'],
        );
    }

    /**
     * Build a real content service whose site-scoped published lookup answers the supplied record.
     *
     * @param   ?ContentRecord  $record  Record the homepage lookup resolves, or null for none.
     *
     * @return  ContentService  Service backed by deterministic collaborators.
     *
     * @since   2.0.0
     */
    private function content(?ContentRecord $record): ContentService
    {
        $repository = $this->createStub(SiteScopedContentRepository::class);
        $repository->method('findPublishedByIdForSite')->willReturn($record);
        $transactions = $this->createStub(TransactionManager::class);
        $transactions->method('transactional')->willReturnCallback(
            static fn (callable $operation): mixed => $operation(),
        );
        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn(new DateTimeImmutable('2026-08-12T00:00:00+00:00'));

        return new ContentService(
            $repository,
            $this->createStub(AuditRecorder::class),
            $transactions,
            $clock,
            new Workflow(),
            AuthorizationContext::gateway(),
            AuthorizationContext::ownershipWriter(),
        );
    }

    /**
     * Build one published record pinned to a non-core content type, as a landing homepage would be.
     *
     * @return  ContentRecord  Published landing-layout record.
     *
     * @since   2.0.0
     */
    private function landingRecord(): ContentRecord
    {
        $now = new DateTimeImmutable('2026-08-12T00:00:00+00:00');

        return new ContentRecord(
            ContentEntry::create(
                '018f22e2-7c8b-7ab0-8f3a-88e8026bb701',
                'Welcome',
                'welcome',
                ['heading' => 'Welcome', 'features' => [['heading' => 'One', 'body' => 'Body']]],
                ContentStatus::Published,
            ),
            '018f22e2-7c8b-7ab0-8f3a-88e8026bb414',
            '018f22e2-7c8b-7ab0-8f3a-88e8026bb401',
            $now,
            $now,
        );
    }

    /**
     * Return the stable storage-key order in which the settings adapter rewrites its document.
     *
     * @return  list<string>  All seven storage keys.
     *
     * @since   2.0.0
     */
    private function settingKeys(): array
    {
        return [
            'site.name',
            'site.homepage_content_id',
            'site.homepage_slug',
            'site.default_locale',
            'site.timezone',
            'search.indexing_enabled',
            'site.presentation',
        ];
    }
}
