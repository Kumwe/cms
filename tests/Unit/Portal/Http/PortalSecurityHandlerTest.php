<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Portal\Http;

use Kumwe\App\Identity\Application\StepUp\StepUpRejected;
use Kumwe\App\Identity\Application\Administration\AuthenticationThrottled;
use Kumwe\App\Tests\Support\InterfaceTranslation;
use DateTimeImmutable;
use Kumwe\App\Application\Authorization\AuthorizationPolicyRegistry;
use Kumwe\Context\Value\SiteContext;
use Kumwe\App\Extension\Contribution\CapabilityDefinitionRegistry;
use Kumwe\App\Http\Middleware\TrustedProxyMiddleware;
use Kumwe\App\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\App\Identity\Application\StepUp\StepUpProvider;
use Kumwe\App\Identity\Domain\StepUp\RotatedStepUpSession;
use Kumwe\App\Identity\Domain\StepUp\StepUpEnrollmentCompletion;
use Kumwe\App\Identity\Domain\StepUp\StepUpEnrollmentSetup;
use Kumwe\App\Identity\Domain\StepUp\StepUpIntent;
use Kumwe\App\Identity\Domain\StepUp\StepUpMethod;
use Kumwe\App\Identity\Domain\StepUp\StepUpVerification;
use Kumwe\App\Portal\Application\PortalSession;
use Kumwe\App\Portal\Application\PortalSessionIdentity;
use Kumwe\App\Portal\Contribution\PortalNavigationRegistry;
use Kumwe\App\Portal\Contribution\PortalTemplateRegistry;
use Kumwe\App\Portal\Contribution\PortalWorkspaceRegistry;
use Kumwe\App\Portal\Application\PortalContext;
use Kumwe\App\Portal\Http\Handler\PortalSecurityHandler;
use Kumwe\App\Portal\Presentation\PortalNavigationVisibility;
use Kumwe\App\Portal\Presentation\PortalRenderer;
use Laminas\Diactoros\ServerRequest;
use Laminas\Diactoros\Uri;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

#[CoversClass(PortalSecurityHandler::class)]
final class PortalSecurityHandlerTest extends TestCase
{
    public function testConfirmationUsesOnlyResolvedSessionContextAndPublishesRotatedCookieAndCodes(): void
    {
        $provider = new CapturingStepUpProvider();
        $handler = new PortalSecurityHandler(
            $provider,
            $this->renderer(),
            InterfaceTranslation::translator(),
            true,
            3600,
        );
        $request = (new ServerRequest(
            [],
            [],
            new Uri('https://example.test/portal/security/totp/confirm'),
            'POST',
        ))
            ->withAttribute(PortalSession::REQUEST_ATTRIBUTE, $this->session())
            ->withAttribute(TrustedProxyMiddleware::ATTRIBUTE_CLIENT_ADDRESS, '192.0.2.5')
            ->withParsedBody([
                'enrollment_id' => '018f0000-0000-7000-8000-000000000010',
                'code' => '123456',
                'purpose' => 'business.approval.approve',
                'site' => 'attacker-site',
            ]);

        $response = $handler->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('kumwe_portal=rotated_cookie_token_', $response->getHeaderLine('Set-Cookie'));
        self::assertStringContainsString('Path=/portal', $response->getHeaderLine('Set-Cookie'));
        self::assertStringContainsString('; Secure', $response->getHeaderLine('Set-Cookie'));
        self::assertStringContainsString('0000-0000-0000-0000-0000-0000-0000-0000', (string) $response->getBody());
        self::assertSame('portal.step_up.enroll', $provider->lastIntent?->purpose);
        self::assertSame('default', $provider->lastIntent?->siteIdentifier);
        self::assertSame('018f0000-0000-7000-8000-000000000002', $provider->lastIntent?->sessionId);
        self::assertSame('192.0.2.5', $provider->source);
    }

    public function testChallengePurposeCannotBeSelectedByTheSubmittedForm(): void
    {
        $provider = new CapturingStepUpProvider();
        $handler = new PortalSecurityHandler(
            $provider,
            $this->renderer(),
            InterfaceTranslation::translator(),
            false,
            3600,
        );
        $request = (new ServerRequest(
            [],
            [],
            new Uri('https://example.test/portal/security/challenge'),
            'POST',
        ))
            ->withAttribute(PortalSession::REQUEST_ATTRIBUTE, $this->session())
            ->withParsedBody(['code' => '123456', 'purpose' => 'business.approval.revoke']);

        $response = $handler->handle($request);

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/portal/security?verified=1', $response->getHeaderLine('Location'));
        self::assertSame('portal.step_up.challenge', $provider->lastIntent?->purpose);
    }


    /**
     * The landing page renders the verification confirmation from the catalogue after a step-up.
     *
     * The redirect after a successful verification carries only a flag, so the sentence a member
     * reads on arrival has to come from the catalogue rather than from the query string.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheLandingPageConfirmsVerificationInCatalogueWording(): void
    {
        $handler = new PortalSecurityHandler(
            new CapturingStepUpProvider(),
            $this->renderer(),
            InterfaceTranslation::translator(),
            true,
            3600,
        );
        $request = (new ServerRequest([], [], new Uri('https://example.test/portal/security?verified=1'), 'GET'))
            ->withAttribute(PortalSession::REQUEST_ATTRIBUTE, $this->session())
            ->withQueryParams(['verified' => '1']);

        $response = $handler->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Identity verification succeeded.', (string) $response->getBody());
    }

    /**
     * The landing page shows no notice when the member simply navigated to it.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheLandingPageShowsNoNoticeWithoutAVerification(): void
    {
        $handler = new PortalSecurityHandler(
            new CapturingStepUpProvider(),
            $this->renderer(),
            InterfaceTranslation::translator(),
            true,
            3600,
        );
        $request = (new ServerRequest([], [], new Uri('https://example.test/portal/security'), 'GET'))
            ->withAttribute(PortalSession::REQUEST_ATTRIBUTE, $this->session());

        $response = $handler->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringNotContainsString('Identity verification succeeded.', (string) $response->getBody());
    }

    /**
     * A security path naming no supported operation answers 404 with catalogue wording.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnUnsupportedSecurityPathIsAnsweredWithCatalogueWording(): void
    {
        $handler = new PortalSecurityHandler(
            new CapturingStepUpProvider(),
            $this->renderer(),
            InterfaceTranslation::translator(),
            true,
            3600,
        );
        $request = (new ServerRequest([], [], new Uri('https://example.test/portal/security/unknown'), 'POST'))
            ->withAttribute(PortalSession::REQUEST_ATTRIBUTE, $this->session())
            ->withParsedBody([]);

        $response = $handler->handle($request);

        self::assertSame(404, $response->getStatusCode());
        self::assertStringContainsString(
            'The requested security operation is unavailable.',
            (string) $response->getBody(),
        );
    }

    /**
     * A refused verification code answers 403 with the shared step-up wording.
     *
     * The same sentence is used wherever a code is refused, so a member meets one explanation rather
     * than a different phrasing per surface.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testARefusedCodeIsReportedWithTheSharedStepUpWording(): void
    {
        $handler = new PortalSecurityHandler(
            new RejectingStepUpProvider(),
            $this->renderer(),
            InterfaceTranslation::translator(),
            true,
            3600,
        );
        $request = (new ServerRequest([], [], new Uri('https://example.test/portal/security/challenge'), 'POST'))
            ->withAttribute(PortalSession::REQUEST_ATTRIBUTE, $this->session())
            ->withAttribute(TrustedProxyMiddleware::ATTRIBUTE_CLIENT_ADDRESS, '192.0.2.5')
            ->withParsedBody(['code' => '000000']);

        $response = $handler->handle($request);

        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString(
            'The verification code is invalid, expired, or already used.',
            (string) $response->getBody(),
        );
    }

    /**
     * A throttled address is told to try again later, at 429 and with a Retry-After.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAThrottledAddressIsToldToTryAgainLater(): void
    {
        $handler = new PortalSecurityHandler(
            new ThrottlingStepUpProvider(),
            $this->renderer(),
            InterfaceTranslation::translator(),
            true,
            3600,
        );
        $request = (new ServerRequest([], [], new Uri('https://example.test/portal/security/recovery'), 'POST'))
            ->withAttribute(PortalSession::REQUEST_ATTRIBUTE, $this->session())
            ->withAttribute(TrustedProxyMiddleware::ATTRIBUTE_CLIENT_ADDRESS, '192.0.2.5')
            ->withParsedBody(['recovery_code' => 'aaaa-bbbb']);

        $response = $handler->handle($request);

        self::assertSame(429, $response->getStatusCode());
        self::assertSame('900', $response->getHeaderLine('Retry-After'));
        self::assertStringContainsString(
            'Too many unsuccessful authentication attempts.',
            (string) $response->getBody(),
        );
    }

    private function renderer(): PortalRenderer
    {
        $workspaces = new PortalWorkspaceRegistry();
        $capabilities = new CapabilityDefinitionRegistry();
        return new PortalRenderer(
            new Environment(new ArrayLoader([
                'portal/security.twig' => '{{ portal_session.id }} {{ notice }} {{ error }} '
                    . '{% for code in recovery_codes %}{{ code }} {% endfor %}',
            ]), ['strict_variables' => true]),
            new PortalNavigationRegistry($workspaces, $capabilities, new AuthorizationPolicyRegistry()),
            new PortalTemplateRegistry(),
            $this->createStub(PortalNavigationVisibility::class),
        );
    }

    private function session(): PortalSession
    {
        $now = new DateTimeImmutable('2026-08-09T10:00:00+00:00');
        $principal = AuthenticatedPrincipal::issueFromStrings(
            new \stdClass(),
            '018f0000-0000-7000-8000-000000000001',
            ['portal.access'],
        );
        return new PortalSession(
            '018f0000-0000-7000-8000-000000000002',
            new PortalSessionIdentity($principal, new PortalContext(SiteContext::default(), null), 1),
            str_repeat('c', 43),
            $now,
            null,
            $now->modify('+1 hour'),
        );
    }
}

final class CapturingStepUpProvider implements StepUpProvider
{
    public ?StepUpIntent $lastIntent = null;

    public string $source = '';

    public function beginEnrollment(string $subjectId, string $issuer, string $accountLabel): StepUpEnrollmentSetup
    {
        return new StepUpEnrollmentSetup(
            '018f0000-0000-7000-8000-000000000010',
            'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567',
            'otpauth://totp/Kumwe?secret=ABCDEFGHIJKLMNOPQRSTUVWXYZ234567&issuer=Kumwe',
            new DateTimeImmutable('2026-08-09T10:10:00+00:00'),
        );
    }

    public function confirmEnrollment(
        StepUpIntent $intent,
        string $enrollmentId,
        string $code,
        string $source,
    ): StepUpEnrollmentCompletion {
        $this->lastIntent = $intent;
        $this->source = $source;
        return new StepUpEnrollmentCompletion(
            $this->verification($intent),
            array_map(
                static fn (int $value): string => implode('-', array_fill(0, 8, sprintf('%04x', $value))),
                range(0, 9),
            ),
        );
    }

    public function challenge(StepUpIntent $intent, string $code, string $source): StepUpVerification
    {
        $this->lastIntent = $intent;
        $this->source = $source;
        return $this->verification($intent);
    }

    public function recover(StepUpIntent $intent, string $recoveryCode, string $source): StepUpVerification
    {
        $this->lastIntent = $intent;
        $this->source = $source;
        return $this->verification($intent, StepUpMethod::RecoveryCode);
    }

    private function verification(
        StepUpIntent $intent,
        StepUpMethod $method = StepUpMethod::Totp,
    ): StepUpVerification {
        $now = new DateTimeImmutable('2026-08-09T10:01:00+00:00');
        return new StepUpVerification(
            $intent,
            '018f0000-0000-7000-8000-000000000011',
            $method,
            $now,
            $now->modify('+5 minutes'),
            str_repeat('n', 43),
            new RotatedStepUpSession(
                '018f0000-0000-7000-8000-000000000012',
                'rotated_cookie_token_' . str_repeat('x', 32),
                str_repeat('z', 43),
                $now->modify('+59 minutes'),
            ),
        );
    }
}

/** Refuses every code, so the shared step-up refusal wording can be pinned. */
final class RejectingStepUpProvider implements StepUpProvider
{
    /**
     * Never reached; enrollment is not exercised through this double.
     *
     * @param   string  $subjectId     Subject enrolling.
     * @param   string  $issuer        Issuer shown in the authenticator.
     * @param   string  $accountLabel  Account label shown in the authenticator.
     *
     * @return  StepUpEnrollmentSetup  Never returned.
     *
     * @since   2.0.0
     */
    public function beginEnrollment(string $subjectId, string $issuer, string $accountLabel): StepUpEnrollmentSetup
    {
        throw new StepUpRejected();
    }

    /**
     * Refuse the confirmation code.
     *
     * @param   StepUpIntent  $intent        Bound intent.
     * @param   string        $enrollmentId  Enrollment being confirmed.
     * @param   string        $code          Submitted code.
     * @param   string        $source        Trusted source address.
     *
     * @return  StepUpEnrollmentCompletion  Never returned.
     *
     * @since   2.0.0
     */
    public function confirmEnrollment(
        StepUpIntent $intent,
        string $enrollmentId,
        string $code,
        string $source,
    ): StepUpEnrollmentCompletion {
        throw new StepUpRejected();
    }

    /**
     * Refuse the challenge code.
     *
     * @param   StepUpIntent  $intent  Bound intent.
     * @param   string        $code    Submitted code.
     * @param   string        $source  Trusted source address.
     *
     * @return  StepUpVerification  Never returned.
     *
     * @since   2.0.0
     */
    public function challenge(StepUpIntent $intent, string $code, string $source): StepUpVerification
    {
        throw new StepUpRejected();
    }

    /**
     * Refuse the recovery code.
     *
     * @param   StepUpIntent  $intent        Bound intent.
     * @param   string        $recoveryCode  Submitted recovery code.
     * @param   string        $source        Trusted source address.
     *
     * @return  StepUpVerification  Never returned.
     *
     * @since   2.0.0
     */
    public function recover(StepUpIntent $intent, string $recoveryCode, string $source): StepUpVerification
    {
        throw new StepUpRejected();
    }
}

/** Throttles every attempt, so the shared authentication-throttle wording can be pinned. */
final class ThrottlingStepUpProvider implements StepUpProvider
{
    /**
     * Never reached; enrollment is not exercised through this double.
     *
     * @param   string  $subjectId     Subject enrolling.
     * @param   string  $issuer        Issuer shown in the authenticator.
     * @param   string  $accountLabel  Account label shown in the authenticator.
     *
     * @return  StepUpEnrollmentSetup  Never returned.
     *
     * @since   2.0.0
     */
    public function beginEnrollment(string $subjectId, string $issuer, string $accountLabel): StepUpEnrollmentSetup
    {
        throw new AuthenticationThrottled();
    }

    /**
     * Throttle the confirmation.
     *
     * @param   StepUpIntent  $intent        Bound intent.
     * @param   string        $enrollmentId  Enrollment being confirmed.
     * @param   string        $code          Submitted code.
     * @param   string        $source        Trusted source address.
     *
     * @return  StepUpEnrollmentCompletion  Never returned.
     *
     * @since   2.0.0
     */
    public function confirmEnrollment(
        StepUpIntent $intent,
        string $enrollmentId,
        string $code,
        string $source,
    ): StepUpEnrollmentCompletion {
        throw new AuthenticationThrottled();
    }

    /**
     * Throttle the challenge.
     *
     * @param   StepUpIntent  $intent  Bound intent.
     * @param   string        $code    Submitted code.
     * @param   string        $source  Trusted source address.
     *
     * @return  StepUpVerification  Never returned.
     *
     * @since   2.0.0
     */
    public function challenge(StepUpIntent $intent, string $code, string $source): StepUpVerification
    {
        throw new AuthenticationThrottled();
    }

    /**
     * Throttle the recovery attempt.
     *
     * @param   StepUpIntent  $intent        Bound intent.
     * @param   string        $recoveryCode  Submitted recovery code.
     * @param   string        $source        Trusted source address.
     *
     * @return  StepUpVerification  Never returned.
     *
     * @since   2.0.0
     */
    public function recover(StepUpIntent $intent, string $recoveryCode, string $source): StepUpVerification
    {
        throw new AuthenticationThrottled();
    }
}
