<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Identity\Application\Authentication;

use InvalidArgumentException;
use Kumwe\Context\Contract\Principal;
use Kumwe\Context\Value\AuthenticationStrength;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Context\Value\SiteContext;
use Kumwe\App\Application\Authorization\SystemIdentity;
use Kumwe\App\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\App\Identity\Application\Authentication\PrincipalGrant;
use Kumwe\Extension\Spi\Identity\Domain\Capability;
use Kumwe\App\Identity\Domain\GrantScope;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Kumwe\App\Tests\Support\AuthorizationContext;

#[CoversClass(AuthenticatedPrincipal::class)]
#[UsesClass(GrantScope::class)]
#[UsesClass(PrincipalGrant::class)]
final class AuthenticatedPrincipalTest extends TestCase
{
    private const SUBJECT = '018f22e2-7c8b-7ab0-8f3a-88e8026bb301';

    public function testStoresValidatedSubjectAndSortedExactCapabilities(): void
    {
        $principal = AuthorizationContext::principal([
            'content.update',
            'content.read',
        ], self::SUBJECT);

        self::assertSame(self::SUBJECT, $principal->subject());
        self::assertSame(
            ['content.read', 'content.update'],
            array_map(
                static fn (Capability $capability): string => $capability->value(),
                $principal->capabilities(),
            ),
        );
        self::assertTrue($principal->hasCapability(Capability::fromString('content.read')));
        self::assertFalse($principal->hasCapability(Capability::fromString('content')));
    }

    public function testRejectsWildcardCapability(): void
    {
        $this->expectException(InvalidArgumentException::class);

        AuthorizationContext::principal(['content.*'], self::SUBJECT);
    }

    public function testKeepsScopedGrantsInsteadOfPromotingThemToGlobal(): void
    {
        $principal = AuthorizationContext::principalFromGrantRows([[
            'capability' => 'content.update',
            'scope_type' => 'content',
            'scope_identifier' => 'page-1',
        ]], self::SUBJECT);
        $capability = Capability::fromString('content.update');

        self::assertTrue($principal->hasCapability($capability));
        self::assertTrue($principal->allows($capability, [GrantScope::named('content', 'page-1')]));
        self::assertFalse($principal->allows($capability, [GrantScope::named('content', 'page-2')]));
    }

    public function testRejectsDuplicateCapability(): void
    {
        $this->expectException(InvalidArgumentException::class);

        AuthorizationContext::principal(['content.read', 'content.read'], self::SUBJECT);
    }

    public function testRejectsNonUuidSubject(): void
    {
        $this->expectException(InvalidArgumentException::class);

        AuthorizationContext::principal(['content.read'], 'user-1');
    }

    public function testRestrictsALiveGrantSupersetToTheCapturedExactRows(): void
    {
        $captured = [[
            'capability' => 'content.read',
            'scope_type' => 'site',
            'scope_identifier' => 'default',
        ]];
        $current = AuthorizationContext::principalFromGrantRows([
            ...$captured,
            [
                'capability' => 'users.manage',
                'scope_type' => 'global',
                'scope_identifier' => null,
            ],
        ], self::SUBJECT);

        $restricted = $current->restrictedToGrantRows($captured);

        self::assertNotNull($restricted);
        self::assertSame(
            AuthorizationContext::principalFromGrantRows($captured, self::SUBJECT)->authorityFingerprint(),
            $restricted->authorityFingerprint(),
        );
        self::assertFalse($restricted->hasCapability(Capability::fromString('users.manage')));
    }

    public function testRefusesACapturedGrantThatTheLivePrincipalNoLongerHoldsExactly(): void
    {
        $current = AuthorizationContext::principalFromGrantRows([[
            'capability' => 'content.read',
            'scope_type' => 'global',
            'scope_identifier' => null,
        ]], self::SUBJECT);

        self::assertNull($current->restrictedToGrantRows([[
            'capability' => 'content.read',
            'scope_type' => 'site',
            'scope_identifier' => 'default',
        ]]));
    }

    public function testRestrictionRejectsNonCanonicalPersistedRows(): void
    {
        $current = AuthorizationContext::principal(['content.read'], self::SUBJECT);

        $this->expectException(InvalidArgumentException::class);

        $current->restrictedToGrantRows([[
            'capability' => 'Content.Read',
            'scope_type' => 'global',
            'scope_identifier' => null,
        ]]);
    }

    public function testRestrictionRejectsGrantRowsOutsideCanonicalOrder(): void
    {
        $current = AuthorizationContext::principal(['content.read', 'users.manage'], self::SUBJECT);

        $this->expectException(InvalidArgumentException::class);

        $current->restrictedToGrantRows([
            [
                'capability' => 'users.manage',
                'scope_type' => 'global',
                'scope_identifier' => null,
            ],
            [
                'capability' => 'content.read',
                'scope_type' => 'global',
                'scope_identifier' => null,
            ],
        ]);
    }

    /**
     * The context a principal issues carries that principal, its site, and the strength it was asked for.
     *
     * Every authorization decision downstream reads the context rather than the principal, so a context
     * that lost the principal it came from would be authority with no one behind it.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testIssuesAHumanExecutionContextCarryingItsOwnAuthority(): void
    {
        $principal = AuthorizationContext::principal(['content.read'], self::SUBJECT);

        $context = $principal->context(
            SiteContext::fromString(SiteContext::DEFAULT),
            AuthenticationStrength::BearerToken,
            'request-0001',
            'correlation-0001',
        );

        self::assertInstanceOf(ExecutionContext::class, $context);
        self::assertSame($principal, $context->principal());
        self::assertSame(self::SUBJECT, $context->actorId());
        self::assertSame(SiteContext::DEFAULT, $context->site()->identifier());
        self::assertSame(AuthenticationStrength::BearerToken, $context->authenticationStrength());
        self::assertSame('request-0001', $context->requestId());
        self::assertSame('correlation-0001', $context->correlationId());
    }

    /**
     * `of()` narrows the package port back to the App principal and to nothing else.
     *
     * A system context carries no principal, and a foreign implementation of the port issued with the same
     * provenance is read as no principal at all, so grant-carrying consumers never trust it.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testOfNarrowsThePortToTheAppPrincipalOnly(): void
    {
        $principal = AuthorizationContext::principal(['content.read'], self::SUBJECT);
        $own = $principal->context(SiteContext::default(), AuthenticationStrength::Password, 'request-0002');
        $system = AuthorizationContext::system(SystemIdentity::Worker)->context(SiteContext::default(), 'job-0002');
        $foreign = new class (AuthorizationContext::provenance()) implements Principal {
            /**
             * Hold the provenance this forged principal claims.
             *
             * @param  object  $provenance  Authority object to claim.
             *
             * @since  2.0.0
             */
            public function __construct(private readonly object $provenance)
            {
            }

            /**
             * Answer a canonical subject.
             *
             * @return  string  Lowercase UUID.
             *
             * @since   2.0.0
             */
            public function subject(): string
            {
                return '018f22e2-7c8b-7ab0-8f3a-88e8026bb999';
            }

            /**
             * Answer a positive epoch.
             *
             * @return  int  Always one.
             *
             * @since   2.0.0
             */
            public function securityEpoch(): int
            {
                return 1;
            }

            /**
             * Claim the installation provenance.
             *
             * @param   object  $provenance  Authority object under test.
             *
             * @return  bool  True for the claimed object.
             *
             * @since   2.0.0
             */
            public function hasProvenance(object $provenance): bool
            {
                return $provenance === $this->provenance;
            }

            /**
             * Answer a fixed digest.
             *
             * @return  string  Lowercase hexadecimal SHA-256.
             *
             * @since   2.0.0
             */
            public function authorizationFingerprint(): string
            {
                return hash('sha256', 'forged-authorization');
            }

            /**
             * Answer a fixed digest.
             *
             * @return  string  Lowercase hexadecimal SHA-256.
             *
             * @since   2.0.0
             */
            public function authorityFingerprint(): string
            {
                return hash('sha256', 'forged-authority');
            }
        };
        $forged = ExecutionContext::issueHuman(
            AuthorizationContext::provenance(),
            $foreign,
            SiteContext::default(),
            AuthenticationStrength::Password,
            'request-0003',
        );

        self::assertSame($principal, AuthenticatedPrincipal::of($own));
        self::assertNull(AuthenticatedPrincipal::of($system));
        self::assertSame($foreign, $forged->principal());
        self::assertNull(AuthenticatedPrincipal::of($forged));
    }
}
