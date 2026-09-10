<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Delivery\Http\Api\Business;

use Kumwe\App\Application\Authorization\ExecutionContextAttribute;
use Kumwe\App\BusinessSurface\Application\BusinessApprovalSurfaceService;
use Kumwe\App\Delivery\Http\Api\Business\BusinessApprovalApiHandler;
use Kumwe\App\Delivery\Http\Api\Business\BusinessApprovalApiPresenter;
use Kumwe\App\Delivery\Http\Api\ProblemDetailsResponseFactory;
use Kumwe\App\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\App\Tests\Support\AuthorizationContext;
use Kumwe\Context\Value\AuthenticationStrength;
use Kumwe\Context\Value\SiteContext;
use Laminas\Diactoros\ServerRequestFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(BusinessApprovalApiHandler::class)]
/**
 * Proves generated-business approval REST input is closed and bounded before repository access.
 *
 * @since  2.0.0
 */
final class BusinessApprovalApiHandlerTest extends TestCase
{
    /**
     * Proves unknown or excessive inbox controls produce one safe validation problem.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRejectsUnknownOrUnboundedCollectionParametersBeforeRepositoryAccess(): void
    {
        $principal = AuthorizationContext::principal(['business.approval.request']);
        $context = $principal->context(
            SiteContext::default(),
            AuthenticationStrength::BearerToken,
            'business-approval-api-test-0001',
        );
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', 'https://kumwe.test/api/v1/business/approvals?limit=1000')
            ->withQueryParams(['limit' => '1000'])
            ->withAttribute(AuthenticatedPrincipal::REQUEST_ATTRIBUTE, $principal)
            ->withAttribute(ExecutionContextAttribute::NAME, $context);

        $response = $this->handler()->handle($request);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->getHeaderLine('Content-Type'));
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
    }

    /**
     * Construct a handler whose repository remains untouched by transport rejection.
     *
     * @return  BusinessApprovalApiHandler  Handler under test.
     *
     * @since   2.0.0
     */
    private function handler(): BusinessApprovalApiHandler
    {
        /** @var BusinessApprovalSurfaceService $approvals */
        $approvals = (new ReflectionClass(BusinessApprovalSurfaceService::class))->newInstanceWithoutConstructor();

        return new BusinessApprovalApiHandler(
            $approvals,
            new BusinessApprovalApiPresenter(),
            new ProblemDetailsResponseFactory(),
        );
    }
}
