<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Application\Authorization;

use Doctrine\DBAL\Connection;
use Kumwe\App\Application\Authorization\AuthorizationResource;
use Kumwe\App\Application\Authorization\AuthorizationResourceOwnershipUnknown;
use Kumwe\App\Application\Authorization\OwnershipScope;
use Kumwe\App\Application\Authorization\OwnershipScopeLevel;
use Kumwe\App\Application\Authorization\ResourceOwnership;
use Kumwe\App\Application\Authorization\ResourceOwnershipScopePolicy;
use Kumwe\App\Application\Authorization\ResourceSiteOwnershipConflict;
use Kumwe\App\Application\Authorization\SiteGroup;
use Kumwe\App\Infrastructure\Authorization\DoctrineResourceSiteOwnershipWriter;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\Context\Value\SiteContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DoctrineResourceSiteOwnershipWriter::class)]
#[CoversClass(ResourceSiteOwnershipConflict::class)]
final class DoctrineResourceSiteOwnershipWriterTest extends TestCase
{
    public function testRecordsAuthoritativeOwnership(): void
    {
        $database = $this->createMock(Connection::class);
        $database->expects(self::once())->method('insert')->with(
            'kumwe_resource_site_ownership',
            [
                'resource_type' => 'content',
                'resource_id' => '018f22e2-7c8b-7ab0-8f3a-88e8026bb402',
                'site_identifier' => 'corporate',
                'scope_level' => 'site',
                'group_identifier' => null,
            ],
        );

        (new DoctrineResourceSiteOwnershipWriter(
            $database,
            new TableNames($database, 'kumwe_'),
        ))->record(
            AuthorizationResource::item('content', '018f22e2-7c8b-7ab0-8f3a-88e8026bb402'),
            SiteContext::fromString('corporate'),
        );
    }

    public function testRemovesOnlyOwnershipForTheExpectedSite(): void
    {
        $database = $this->database();
        $database->expects(self::once())->method('executeStatement')->with(
            'DELETE FROM kumwe_resource_site_ownership '
                . 'WHERE resource_type = ? AND resource_id = ? AND scope_level = ? AND site_identifier = ?',
            ['content', '018f22e2-7c8b-7ab0-8f3a-88e8026bb402', 'site', 'corporate'],
        )->willReturn(1);
        $database->expects(self::never())->method('fetchAssociative');

        (new DoctrineResourceSiteOwnershipWriter(
            $database,
            new TableNames($database, 'kumwe_'),
        ))->remove(
            AuthorizationResource::item('content', '018f22e2-7c8b-7ab0-8f3a-88e8026bb402'),
            SiteContext::fromString('corporate'),
        );
    }

    public function testCrossSiteOwnershipCannotBeRemoved(): void
    {
        $database = $this->database();
        $database->expects(self::once())->method('executeStatement')->willReturn(0);
        $database->expects(self::once())->method('fetchAssociative')->willReturn([
            'scope_level' => OwnershipScopeLevel::Site->value,
            'site_identifier' => 'subsidiary',
            'group_identifier' => null,
        ]);

        $this->expectException(ResourceSiteOwnershipConflict::class);
        $this->expectExceptionMessage('held by site:subsidiary on behalf of site:corporate');
        (new DoctrineResourceSiteOwnershipWriter(
            $database,
            new TableNames($database, 'kumwe_'),
        ))->remove(
            AuthorizationResource::item('content', '018f22e2-7c8b-7ab0-8f3a-88e8026bb402'),
            SiteContext::fromString('corporate'),
        );
    }

    public function testMissingOwnershipCannotBeSilentlyRemoved(): void
    {
        $database = $this->database();
        $database->expects(self::once())->method('executeStatement')->willReturn(0);
        $database->expects(self::once())->method('fetchAssociative')->willReturn(false);

        $this->expectException(AuthorizationResourceOwnershipUnknown::class);
        (new DoctrineResourceSiteOwnershipWriter(
            $database,
            new TableNames($database, 'kumwe_'),
        ))->remove(
            AuthorizationResource::item('content', '018f22e2-7c8b-7ab0-8f3a-88e8026bb402'),
            SiteContext::fromString('corporate'),
        );
    }

    public function testReassignMatchesTheOwnerTheCallerExpects(): void
    {
        $database = $this->database();
        $database->expects(self::once())->method('executeStatement')->with(
            'UPDATE kumwe_resource_site_ownership SET scope_level = ?, site_identifier = ?, '
                . 'group_identifier = ? WHERE resource_type = ? AND resource_id = ? AND scope_level = ? '
                . 'AND COALESCE(site_identifier, group_identifier, ?) = ?',
            [
                'group',
                null,
                'kumwe-group',
                'person',
                '018f22e2-7c8b-7ab0-8f3a-88e8026bb501',
                'site',
                'manufacturing',
                'manufacturing',
            ],
        )->willReturn(1);

        (new DoctrineResourceSiteOwnershipWriter(
            $database,
            new TableNames($database, 'kumwe_'),
        ))->reassign(
            ResourceOwnership::of(
                AuthorizationResource::item('person', '018f22e2-7c8b-7ab0-8f3a-88e8026bb501'),
                OwnershipScope::group(new SiteGroup('kumwe-group', 'Kumwe group', [
                    'manufacturing',
                    'retail',
                ])),
                new ResourceOwnershipScopePolicy(),
            ),
            OwnershipScope::site(SiteContext::fromString('manufacturing')),
        );
    }

    public function testReassignLosesToAConcurrentChangeInsteadOfOverwritingIt(): void
    {
        $database = $this->database();
        $database->expects(self::once())->method('executeStatement')->willReturn(0);
        $database->expects(self::once())->method('fetchAssociative')->willReturn([
            'scope_level' => OwnershipScopeLevel::Group->value,
            'site_identifier' => null,
            'group_identifier' => 'other-group',
        ]);

        $this->expectException(ResourceSiteOwnershipConflict::class);
        (new DoctrineResourceSiteOwnershipWriter(
            $database,
            new TableNames($database, 'kumwe_'),
        ))->reassign(
            ResourceOwnership::of(
                AuthorizationResource::item('person', '018f22e2-7c8b-7ab0-8f3a-88e8026bb501'),
                OwnershipScope::group(new SiteGroup('kumwe-group', 'Kumwe group', ['manufacturing'])),
                new ResourceOwnershipScopePolicy(),
            ),
            OwnershipScope::site(SiteContext::fromString('manufacturing')),
        );
    }

    private function database(): Connection
    {
        $database = $this->createMock(Connection::class);
        $database->method('quoteSingleIdentifier')->willReturnCallback(
            static fn (string $identifier): string => $identifier,
        );

        return $database;
    }
}
