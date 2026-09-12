<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Administrator\Http\Handler;

use DateTimeImmutable;
use Kumwe\App\Administrator\Http\Handler\AdministratorAccessControlHandler;
use Kumwe\App\Administrator\Presentation\AdministratorRenderer;
use Kumwe\App\Administrator\Presentation\RecoveryAdministratorRenderer;
use Kumwe\App\Application\Authorization\AuthorizationGateway;
use Kumwe\App\Application\Authorization\ExecutionContextAttribute;
use Kumwe\App\Application\Authorization\ResourceSiteOwnershipWriter;
use Kumwe\Transaction\Contract\TransactionManager;
use Kumwe\Context\Value\SiteContext;
use Kumwe\App\Application\Security\HighImpactCredentialGuard;
use Kumwe\App\Audit\Application\AuditRecorder;
use Kumwe\App\BusinessSecurity\Application\Approval\StepUpProofConsumer;
use Kumwe\App\BusinessSecurity\Application\MembershipDirectory;
use Kumwe\App\Identity\Application\Administration\AccessControlRepository;
use Kumwe\App\Identity\Application\Administration\AccessControlService;
use Kumwe\App\Identity\Application\Administration\AdministratorIdentityGateway;
use Kumwe\App\Identity\Application\Administration\AdministratorSession;
use Kumwe\App\Identity\Application\Administration\AdministratorSessionStore;
use Kumwe\App\Identity\Application\Administration\CreatedAdministratorSession;
use Kumwe\App\Identity\Application\Security\PasswordHasher;
use Kumwe\App\Identity\Application\StepUp\AdministratorStepUpProvider;
use Kumwe\App\Identity\Application\StepUp\AuthorizationStepUpProofAdapter;
use Kumwe\App\Identity\Application\StepUp\StepUpCredentialStore;
use Kumwe\App\Presentation\Twig\AdministratorTwigEnvironment;
use Kumwe\App\Presentation\Twig\RecoveryAdministratorTwigEnvironment;
use Kumwe\App\Tests\Support\AuthorizationContext;
use Kumwe\Context\Value\AuthenticatedSurface;
use Kumwe\Context\Value\AuthenticationStrength;
use Laminas\Diactoros\ServerRequestFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Twig\Loader\ArrayLoader;

/**
 * Pins focused workspace state across administrator membership-context rotation.
 *
 * @since  2.0.0
 */
#[CoversClass(AdministratorAccessControlHandler::class)]
final class AdministratorAccessControlHandlerTest extends TestCase
{
    /**
     * A context selection returns to the same selected assignment review after rotating the session.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testContextSelectionPreservesFocusedWorkspaceStateInRedirect(): void
    {
        $sessionId = '018f22e2-7c8b-7ab0-8f3a-88e8026bb711';
        $userId = '018f22e2-7c8b-7ab0-8f3a-88e8026bb301';
        $roleId = '018f22e2-7c8b-7ab0-8f3a-88e8026bb302';
        $selectedId = $userId . ':' . $roleId;
        $principal = AuthorizationContext::principal(['administrator.access', 'users.manage']);
        $context = $principal->context(
            SiteContext::default(),
            AuthenticationStrength::Password,
            'test-request-context-select',
            surface: AuthenticatedSurface::Administrator,
            sessionId: $sessionId,
        );
        $session = new AdministratorSession(
            $sessionId,
            $principal,
            'current-csrf-token',
            new DateTimeImmutable('2026-08-12T13:00:00+00:00'),
            SiteContext::default(),
        );
        $replacement = new CreatedAdministratorSession(
            'rotated-cookie-token',
            new AdministratorSession(
                '018f22e2-7c8b-7ab0-8f3a-88e8026bb712',
                $principal,
                'rotated-csrf-token',
                new DateTimeImmutable('2026-08-12T14:00:00+00:00'),
                SiteContext::default(),
            ),
        );
        $sessions = $this->createMock(AdministratorSessionStore::class);
        $sessions->expects(self::once())->method('selectMembership')->with(
            self::identicalTo($context),
            'acme',
            'operations',
            'Kumwe test browser',
        )->willReturn($replacement);
        $handler = $this->handler($sessions);
        $query = [
            'section' => 'assignments',
            'mode' => 'review',
            'id' => $selectedId,
        ];
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', 'https://kumwe.test/administrator/access')
            ->withQueryParams($query)
            ->withHeader('User-Agent', 'Kumwe test browser')
            ->withAttribute(AdministratorSession::REQUEST_ATTRIBUTE, $session)
            ->withAttribute(ExecutionContextAttribute::NAME, $context)
            ->withParsedBody([
                'action' => 'context.select',
                'organization' => 'acme',
                'workspace' => 'operations',
            ]);

        $response = $handler->handle($request);

        self::assertSame(303, $response->getStatusCode());
        self::assertSame(
            '/administrator/access?section=assignments&mode=review&id=' . rawurlencode($selectedId) . '&saved=1',
            $response->getHeaderLine('Location'),
        );
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertStringContainsString(
            'kumwe_administrator=rotated-cookie-token',
            $response->getHeaderLine('Set-Cookie'),
        );
    }

    /**
     * Binds a proof to the exact submitted change set without binding credential or CSRF values.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testStepUpPurposeBindsExactPayloadAndResourceSnapshot(): void
    {
        $handler = $this->handler($this->createStub(AdministratorSessionStore::class));
        $method = new \ReflectionMethod($handler, 'stepUpPurpose');
        $first = $method->invoke($handler, 'grant.synchronize', [
            'action' => 'grant.synchronize',
            'role_id' => 'role-one',
            'grant_snapshot' => str_repeat('a', 64),
            'selected_capabilities' => 'content.update,content.publish',
            '_csrf' => 'csrf-one',
            'step_up_code' => '111111',
        ]);
        $sameChange = $method->invoke($handler, 'grant.synchronize', [
            'action' => 'grant.synchronize',
            'role_id' => 'role-one',
            'grant_snapshot' => str_repeat('a', 64),
            'selected_capabilities' => 'content.update,content.publish',
            '_csrf' => 'csrf-two',
            'step_up_code' => '222222',
        ]);
        $differentChange = $method->invoke($handler, 'grant.synchronize', [
            'action' => 'grant.synchronize',
            'role_id' => 'role-one',
            'grant_snapshot' => str_repeat('a', 64),
            'selected_capabilities' => 'content.update',
            '_csrf' => 'csrf-two',
            'step_up_code' => '222222',
        ]);

        self::assertIsString($first);
        self::assertMatchesRegularExpression(
            '/^identity\.access_control\.grant\.synchronize\.payload\.[a-f0-9]{64}$/D',
            $first,
        );
        self::assertSame($first, $sameChange);
        self::assertNotSame($first, $differentChange);
    }

    /**
     * Proves no submitted password reaches the durable purpose digest a proof and its audit event carry.
     *
     * A purpose digest is stored on the step-up proof row and repeated in the audit metadata, so folding
     * a plaintext password into it would leave an offline-guessable commitment to that password in two
     * tables. Every credential-bearing field must therefore be invisible to the digest.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testStepUpPurposeIsBlindToEverySubmittedCredentialField(): void
    {
        $handler = $this->handler($this->createStub(AdministratorSessionStore::class));
        $method = new \ReflectionMethod($handler, 'stepUpPurpose');
        $base = [
            'action' => 'user.password.reset',
            'user_id' => 'user-one',
            'reason' => 'lost device, ticket 4711',
        ];
        $first = $method->invoke($handler, 'user.password.reset', $base + [
            'new_password' => 'the first replacement passphrase',
            'current_password' => 'the first current passphrase',
            'password' => 'another secret entirely',
            '_csrf' => 'csrf-one',
            'step_up_code' => '111111',
        ]);
        $second = $method->invoke($handler, 'user.password.reset', $base + [
            'new_password' => 'a completely different passphrase',
            'current_password' => 'a different current passphrase',
            'password' => 'yet another secret',
            '_csrf' => 'csrf-two',
            'step_up_code' => '222222',
        ]);
        $differentReason = $method->invoke($handler, 'user.password.reset', [
            'action' => 'user.password.reset',
            'user_id' => 'user-one',
            'reason' => 'a different reason',
        ]);

        self::assertIsString($first);
        self::assertMatchesRegularExpression(
            '/^identity\.access_control\.user\.password\.reset\.payload\.[a-f0-9]{64}$/D',
            $first,
        );
        self::assertSame($first, $second);
        self::assertNotSame($first, $differentReason);
    }

    /**
     * Build the handler with production value objects and inert ports not reached by context selection.
     *
     * @param   AdministratorSessionStore  $sessions  Session port whose selection call is under test.
     *
     * @return  AdministratorAccessControlHandler  Fully typed handler fixture.
     *
     * @since   2.0.0
     */
    private function handler(AdministratorSessionStore $sessions): AdministratorAccessControlHandler
    {
        $access = new AccessControlService(
            $this->createStub(AccessControlRepository::class),
            $this->createStub(PasswordHasher::class),
            $this->createStub(TransactionManager::class),
            $this->createStub(AuditRecorder::class),
            $this->createStub(ClockInterface::class),
            $this->createStub(AuthorizationGateway::class),
            $this->createStub(ResourceSiteOwnershipWriter::class),
            $this->createStub(HighImpactCredentialGuard::class),
            $this->createStub(StepUpCredentialStore::class),
            $sessions,
        );
        $renderer = new AdministratorRenderer(
            new AdministratorTwigEnvironment(new ArrayLoader()),
            new RecoveryAdministratorRenderer(
                new RecoveryAdministratorTwigEnvironment(new ArrayLoader()),
            ),
        );

        return new AdministratorAccessControlHandler(
            $access,
            $this->createStub(AdministratorIdentityGateway::class),
            $renderer,
            $sessions,
            $this->createStub(MembershipDirectory::class),
            $this->createStub(AdministratorStepUpProvider::class),
            new AuthorizationStepUpProofAdapter(),
            $this->createStub(StepUpProofConsumer::class),
            $this->createStub(TransactionManager::class),
            $this->createStub(ClockInterface::class),
            false,
            3600,
        );
    }
}
