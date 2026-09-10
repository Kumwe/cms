<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\BusinessRecord\Domain;

use InvalidArgumentException;
use Kumwe\App\BusinessDefinition\Domain\ScopeMode;
use Kumwe\App\BusinessRecord\Domain\RecordScope;
use Kumwe\Context\Value\SiteContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RecordScope::class)]
final class RecordScopeTest extends TestCase
{
    public function testSiteOrganizationScopeRequiresAndBindsBothDimensions(): void
    {
        $scope = RecordScope::forDefinition(
            ScopeMode::SiteOrganization,
            SiteContext::fromString('default'),
            'organization-1',
        );

        self::assertSame([
            'mode' => 'site_organization',
            'site' => 'default',
            'organization' => 'organization-1',
        ], $scope->toArray());
        $scope->assertRequest(SiteContext::fromString('default'), 'organization-1');

        try {
            $scope->assertRequest(SiteContext::fromString('default'), 'organization-2');
            self::fail('A record must not escape its organization scope.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('does not match', $exception->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        RecordScope::forDefinition(ScopeMode::SiteOrganization, SiteContext::fromString('default'), null);
    }

    public function testInstallationAndSiteScopesRejectUnexpectedOrganizationInput(): void
    {
        self::assertSame(
            ['mode' => 'installation', 'site' => null, 'organization' => null],
            RecordScope::forDefinition(
                ScopeMode::Installation,
                SiteContext::fromString('default'),
                null,
            )->toArray(),
        );
        self::assertSame(
            ['mode' => 'site', 'site' => 'default', 'organization' => null],
            RecordScope::forDefinition(ScopeMode::Site, SiteContext::fromString('default'), null)->toArray(),
        );

        $this->expectException(InvalidArgumentException::class);
        RecordScope::forDefinition(ScopeMode::Site, SiteContext::fromString('default'), 'not-allowed');
    }
}
