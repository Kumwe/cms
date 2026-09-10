<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Infrastructure\Security;

use Doctrine\DBAL\Connection;
use Kumwe\App\Application\Authorization\SystemIdentity;
use Kumwe\App\Application\Security\HighImpactAuthenticationRequired;
use Kumwe\App\Identity\Application\Administration\AuthenticationRateLimiter;
use Kumwe\App\Identity\Application\Security\PasswordHasher;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Infrastructure\Security\DoctrineHighImpactCredentialGuard;
use Kumwe\App\Tests\Support\AuthorizationContext;
use Kumwe\Context\Value\SiteContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DoctrineHighImpactCredentialGuard::class)]
#[CoversClass(HighImpactAuthenticationRequired::class)]
final class DoctrineHighImpactCredentialGuardTest extends TestCase
{
    public function testAcceptsOnlyTheCurrentPasswordAndRecordsTheAttempt(): void
    {
        $database = $this->createStub(Connection::class);
        $database->method('fetchOne')->willReturn('stored-password-hash');
        $passwords = $this->createMock(PasswordHasher::class);
        $passwords->expects(self::once())->method('verify')
            ->with('current password', 'stored-password-hash')
            ->willReturn(true);
        $limiter = $this->createMock(AuthenticationRateLimiter::class);
        $limiter->expects(self::once())->method('assertAllowed');
        $limiter->expects(self::once())->method('record')->with(
            self::isString(),
            self::isString(),
            true,
        );
        $guard = new DoctrineHighImpactCredentialGuard(
            $database,
            new TableNames($database, 'kumwe_'),
            $passwords,
            $limiter,
        );

        $guard->assertCurrentPassword(
            AuthorizationContext::human(['business.schema.approve']),
            'business.schema.approve',
            'current password',
        );

        self::addToAssertionCount(1);
    }

    public function testRejectsAnInvalidPasswordWithoutDisclosingIt(): void
    {
        $database = $this->createStub(Connection::class);
        $database->method('fetchOne')->willReturn('stored-password-hash');
        $passwords = $this->createStub(PasswordHasher::class);
        $passwords->method('verify')->willReturn(false);
        $guard = new DoctrineHighImpactCredentialGuard(
            $database,
            new TableNames($database, 'kumwe_'),
            $passwords,
            $this->createStub(AuthenticationRateLimiter::class),
        );

        $this->expectException(HighImpactAuthenticationRequired::class);
        $this->expectExceptionMessage('current-password authentication');

        $guard->assertCurrentPassword(
            AuthorizationContext::human(['business.schema.approve']),
            'business.schema.approve',
            'wrong password',
        );
    }

    public function testRejectsSystemExecutionWithoutQueryingCredentials(): void
    {
        $database = $this->createMock(Connection::class);
        $database->expects(self::never())->method('fetchOne');
        $guard = new DoctrineHighImpactCredentialGuard(
            $database,
            new TableNames($database, 'kumwe_'),
            $this->createStub(PasswordHasher::class),
            $this->createStub(AuthenticationRateLimiter::class),
        );

        $this->expectException(HighImpactAuthenticationRequired::class);

        $guard->assertCurrentPassword(
            AuthorizationContext::system(SystemIdentity::Worker)->context(SiteContext::default(), 'test-request'),
            'business.schema.approve',
            null,
        );
    }
}
