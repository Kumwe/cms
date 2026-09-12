<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Delivery\Http\Api\Business;

use Kumwe\Context\Value\AuthenticationStrength;
use Kumwe\Context\Value\AuthenticatedSurface;
use Kumwe\App\Application\Authorization\AuthorizationDecision;
use Kumwe\App\Application\Authorization\AuthorizationGateway;
use Kumwe\App\Application\Authorization\AuthorizationResource;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Context\Value\SiteContext;
use Kumwe\Transaction\Contract\TransactionManager;
use Kumwe\App\BusinessDefinition\Application\FieldTypeDefinitionResolver;
use Kumwe\App\BusinessRecord\Application\BusinessRecordDefinitionResolver;
use Kumwe\App\BusinessRecord\Application\BusinessRecordService;
use Kumwe\App\BusinessSecurity\Application\BusinessRecordAccessController;
use Kumwe\App\BusinessSurface\Application\BusinessRecordQueryFactory;
use Kumwe\App\BusinessSurface\Application\BusinessRecordProjector;
use Kumwe\App\BusinessSurface\Application\BusinessSurface;
use Kumwe\App\BusinessSurface\Application\BusinessSurfaceCatalog;
use Kumwe\App\BusinessSurface\Application\BusinessSurfaceUseCases;
use Kumwe\App\Delivery\Http\Api\Concurrency\IfMatch;
use Kumwe\App\Delivery\Http\Api\Concurrency\RequireIfMatchMiddleware;
use Kumwe\App\Delivery\Http\Api\Business\BusinessRecordApiHandler;
use Kumwe\App\Delivery\Http\Api\Business\BusinessRecordApiPresenter;
use Kumwe\App\Delivery\Http\Api\Business\BusinessRecordApiResponder;
use Kumwe\App\Delivery\Http\Api\Idempotency\IdempotencyKey;
use Kumwe\App\Delivery\Http\Api\Idempotency\RequireIdempotencyKeyMiddleware;
use Kumwe\App\Delivery\Http\Api\ProblemDetailsResponseFactory;
use Kumwe\App\Extension\Runtime\RuntimeMaterializationState;
use Kumwe\App\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\Extension\Spi\Identity\Domain\Capability;
use Kumwe\App\Tests\Support\AuthorizationContext;
use Laminas\Diactoros\ServerRequestFactory;
use Laminas\Diactoros\StreamFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use ReflectionClass;
use Kumwe\App\Application\Authorization\ExecutionContextAttribute;

#[CoversClass(BusinessRecordApiHandler::class)]
/**
 * Verifies the generic REST adapter's strict transport and shared-dispatch behavior.
 *
 * @since  2.0.0
 */
final class BusinessRecordApiHandlerTest extends TestCase
{
    /**
     * Refuse operation tokens outside the handler's closed dispatch table.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRejectsAnUnknownExplicitRouteOperation(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', 'https://kumwe.test/not-a-business-route')
            ->withAttribute(BusinessRecordApiHandler::OPERATION_ATTRIBUTE, 'records.unsupported');

        $response = $this->handler()->handle($request);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->getHeaderLine('Content-Type'));
    }

    /**
     * Refuse organization scope selected by an untrusted request body.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testCreateRefusesClientSelectedOrganizationBeforeCallingTheService(): void
    {
        [$principal, $context] = $this->identity();
        $request = $this->request('{"values":{"name":"Invoice"},"organization":"untrusted"}')
            ->withAttribute(BusinessRecordApiHandler::OPERATION_ATTRIBUTE, BusinessRecordApiHandler::CREATE)
            ->withAttribute(BusinessRecordApiHandler::DEFINITION_ATTRIBUTE, 'core.invoice')
            ->withAttribute(AuthenticatedPrincipal::REQUEST_ATTRIBUTE, $principal)
            ->withAttribute(ExecutionContextAttribute::NAME, $context)
            ->withAttribute(
                RequireIdempotencyKeyMiddleware::ATTRIBUTE,
                IdempotencyKey::fromHeader('record-create-0001'),
            );

        $response = $this->handler()->handle($request);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringNotContainsString('untrusted', (string) $response->getBody());
    }

    /**
     * Require the parsed idempotency middleware attribute instead of reparsing the header.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testMutationDoesNotReparseARawIdempotencyHeader(): void
    {
        [$principal, $context] = $this->identity();
        $request = $this->request('{"values":{"name":"Invoice"}}')
            ->withHeader('Idempotency-Key', 'record-create-0001')
            ->withAttribute(BusinessRecordApiHandler::OPERATION_ATTRIBUTE, BusinessRecordApiHandler::CREATE)
            ->withAttribute(BusinessRecordApiHandler::DEFINITION_ATTRIBUTE, 'core.invoice')
            ->withAttribute(AuthenticatedPrincipal::REQUEST_ATTRIBUTE, $principal)
            ->withAttribute(ExecutionContextAttribute::NAME, $context);

        $response = $this->handler()->handle($request);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('business-record request is invalid', (string) $response->getBody());
        self::assertStringNotContainsString('record-create-0001', (string) $response->getBody());
    }

    /**
     * Dispatch REST actions through the shared facade and project custom result data safely.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testActionDispatchesThroughSharedSurfaceUseCasesAndProjectsCustomResult(): void
    {
        [$principal, $context] = $this->identity();
        $surfaces = $this->createMock(BusinessSurfaceUseCases::class);
        $surfaces->expects(self::once())
            ->method('action')
            ->with(
                $context,
                BusinessSurface::Api,
                'core.invoice',
                'invoice-7',
                4,
                'send',
                'record-action-0001',
                ['channel' => 'email'],
                null,
            )
            ->willReturn([
                'record_id' => 'invoice-7',
                'definition_version' => 3,
                'version' => 5,
                'workflow_state' => 'sent',
                'operation' => 'send',
                'deleted' => false,
                'replayed' => true,
                'result' => [
                    'delivery' => 'queued',
                    'receipt' => 'queued-1',
                ],
            ]);
        $request = $this->request('{"input":{"channel":"email"}}')
            ->withUri((new ServerRequestFactory())->createServerRequest(
                'POST',
                'https://kumwe.test/api/v1/business/records/core.invoice/invoice-7/actions/send',
            )->getUri())
            ->withHeader('If-Match', '"v4"')
            ->withAttribute(BusinessRecordApiHandler::OPERATION_ATTRIBUTE, BusinessRecordApiHandler::ACTION)
            ->withAttribute(BusinessRecordApiHandler::DEFINITION_ATTRIBUTE, 'core.invoice')
            ->withAttribute(BusinessRecordApiHandler::RECORD_ATTRIBUTE, 'invoice-7')
            ->withAttribute(BusinessRecordApiHandler::ACTION_ATTRIBUTE, 'send')
            ->withAttribute(AuthenticatedPrincipal::REQUEST_ATTRIBUTE, $principal)
            ->withAttribute(ExecutionContextAttribute::NAME, $context)
            ->withAttribute(
                RequireIdempotencyKeyMiddleware::ATTRIBUTE,
                IdempotencyKey::fromHeader('record-action-0001'),
            )
            ->withAttribute(RequireIfMatchMiddleware::ATTRIBUTE, IfMatch::fromHeader('"v4"'));

        $response = $this->handler($surfaces)->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('"v5"', $response->getHeaderLine('ETag'));
        self::assertSame('true', $response->getHeaderLine('Idempotency-Replayed'));
        $json = (string) $response->getBody();
        self::assertStringContainsString('"delivery":"queued"', $json);
        self::assertStringContainsString('"receipt":"queued-1"', $json);
    }

    /**
     * Dispatch typed custom approval input through the same shared surface facade as execution.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testApprovalDispatchesTypedInputThroughSharedSurfaceUseCases(): void
    {
        [$principal, $context] = $this->identity();
        $approvalId = '018f22e2-7c8b-7ab0-8f3a-88e8026bb604';
        $surfaces = $this->createMock(BusinessSurfaceUseCases::class);
        $surfaces->expects(self::once())
            ->method('requestActionApproval')
            ->with(
                $context,
                BusinessSurface::Api,
                'core.invoice',
                'invoice-7',
                4,
                'send',
                'record-approval-0001',
                ['channel' => 'email'],
            )
            ->willReturn(['approval_request_id' => $approvalId]);
        $request = $this->request('{"input":{"channel":"email"}}')
            ->withHeader('If-Match', '"v4"')
            ->withAttribute(BusinessRecordApiHandler::OPERATION_ATTRIBUTE, BusinessRecordApiHandler::APPROVAL)
            ->withAttribute(BusinessRecordApiHandler::DEFINITION_ATTRIBUTE, 'core.invoice')
            ->withAttribute(BusinessRecordApiHandler::RECORD_ATTRIBUTE, 'invoice-7')
            ->withAttribute(BusinessRecordApiHandler::ACTION_ATTRIBUTE, 'send')
            ->withAttribute(AuthenticatedPrincipal::REQUEST_ATTRIBUTE, $principal)
            ->withAttribute(ExecutionContextAttribute::NAME, $context)
            ->withAttribute(
                RequireIdempotencyKeyMiddleware::ATTRIBUTE,
                IdempotencyKey::fromHeader('record-approval-0001'),
            )
            ->withAttribute(RequireIfMatchMiddleware::ATTRIBUTE, IfMatch::fromHeader('"v4"'));

        $response = $this->handler($surfaces)->handle($request);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('/api/v1/business/approvals/' . $approvalId, $response->getHeaderLine('Location'));
        self::assertStringContainsString($approvalId, (string) $response->getBody());
    }

    /**
     * Execute collection custom views without mutation middleware and omit internal metadata.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testCollectionCustomViewDispatchesWithoutMutationHeaders(): void
    {
        [$principal, $context] = $this->identity();
        $surfaces = $this->createMock(BusinessSurfaceUseCases::class);
        $surfaces->expects(self::once())
            ->method('customView')
            ->with(
                $context,
                BusinessSurface::Api,
                'core.invoice',
                'overdue',
                ['page_size' => 10],
                ['currency' => 'NAD'],
                null,
            )
            ->willReturn([
                'definition' => ['handle' => 'core.invoice'],
                'available_operations' => ['browse'],
                'view' => [
                    'handle' => 'overdue',
                    'label' => 'Overdue',
                    'kind' => 'list',
                    'fields' => ['number'],
                    'filters' => [],
                    'sorts' => ['number'],
                    'custom_contract' => ['schema_reference' => 'private'],
                ],
                'data' => ['count' => 2, 'segment' => 'overdue'],
            ]);
        $request = $this->request('{"query":{"page_size":10},"parameters":{"currency":"NAD"}}')
            ->withUri((new ServerRequestFactory())->createServerRequest(
                'POST',
                'https://kumwe.test/api/v1/business/views/core.invoice/overdue',
            )->getUri())
            ->withAttribute(BusinessRecordApiHandler::DEFINITION_ATTRIBUTE, 'core.invoice')
            ->withAttribute(BusinessRecordApiHandler::VIEW_ATTRIBUTE, 'overdue')
            ->withAttribute(AuthenticatedPrincipal::REQUEST_ATTRIBUTE, $principal)
            ->withAttribute(ExecutionContextAttribute::NAME, $context);

        $response = $this->handler($surfaces)->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        $json = (string) $response->getBody();
        self::assertStringContainsString('"handle":"overdue"', $json);
        self::assertStringContainsString('"count":2', $json);
        self::assertStringContainsString('"segment":"overdue"', $json);
        self::assertStringNotContainsString('custom_contract', $json);
    }

    /**
     * Infer a record custom view from the collision-free runtime route without a private operation attribute.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRecordCustomViewInfersThePublishedRuntimeRoute(): void
    {
        [$principal, $context] = $this->identity();
        $surfaces = $this->createMock(BusinessSurfaceUseCases::class);
        $surfaces->expects(self::once())
            ->method('customView')
            ->with(
                $context,
                BusinessSurface::Api,
                'core.invoice',
                'timeline',
                [],
                [],
                'invoice-7',
            )
            ->willReturn([
                'definition' => ['handle' => 'core.invoice'],
                'available_operations' => ['read'],
                'view' => [
                    'handle' => 'timeline',
                    'label' => 'Timeline',
                    'kind' => 'detail',
                    'fields' => ['number'],
                    'filters' => [],
                    'sorts' => [],
                ],
                'data' => ['events' => []],
            ]);
        $request = $this->request('{}')
            ->withUri((new ServerRequestFactory())->createServerRequest(
                'POST',
                'https://kumwe.test/api/v1/business/views/core.invoice/invoice-7/timeline',
            )->getUri())
            ->withAttribute(BusinessRecordApiHandler::DEFINITION_ATTRIBUTE, 'core.invoice')
            ->withAttribute(BusinessRecordApiHandler::RECORD_ATTRIBUTE, 'invoice-7')
            ->withAttribute(BusinessRecordApiHandler::VIEW_ATTRIBUTE, 'timeline')
            ->withAttribute(AuthenticatedPrincipal::REQUEST_ATTRIBUTE, $principal)
            ->withAttribute(ExecutionContextAttribute::NAME, $context);

        $response = $this->handler($surfaces)->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('"events":[]', (string) $response->getBody());
    }

    /**
     * Gate relationship reads with read authority rather than the separate relation-mutation capability.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRelationshipReadUsesReadMetadataAuthority(): void
    {
        $principal = AuthorizationContext::principal(['business.record.read']);
        $context = $principal->context(
            SiteContext::default(),
            AuthenticationStrength::BearerToken,
            'business-relation-read-test-0001',
            surface: AuthenticatedSurface::Api,
        );
        $authorization = $this->createMock(AuthorizationGateway::class);
        $authorization->expects(self::once())->method('decide')->with(
            $context,
            self::callback(
                static fn (Capability $capability): bool => $capability->value() === 'business.record.read',
            ),
            self::callback(
                static fn (AuthorizationResource $resource): bool => $resource->type() === 'business_record',
            ),
        )->willReturn(new AuthorizationDecision(false, 'test', 'denied'));
        $catalog = new BusinessSurfaceCatalog(
            $this->createStub(BusinessRecordDefinitionResolver::class),
            $this->createStub(BusinessRecordAccessController::class),
            $this->createStub(FieldTypeDefinitionResolver::class),
            $authorization,
            $this->createStub(TransactionManager::class),
            RuntimeMaterializationState::unavailable('relation-read-test'),
        );
        $request = (new ServerRequestFactory())
            ->createServerRequest(
                'GET',
                'https://kumwe.test/api/v1/business/records/core.invoice/invoice-7/relations/lines',
            )
            ->withAttribute(BusinessRecordApiHandler::OPERATION_ATTRIBUTE, BusinessRecordApiHandler::RELATION)
            ->withAttribute(BusinessRecordApiHandler::DEFINITION_ATTRIBUTE, 'core.invoice')
            ->withAttribute(BusinessRecordApiHandler::RECORD_ATTRIBUTE, 'invoice-7')
            ->withAttribute(BusinessRecordApiHandler::RELATIONSHIP_ATTRIBUTE, 'lines')
            ->withAttribute(AuthenticatedPrincipal::REQUEST_ATTRIBUTE, $principal)
            ->withAttribute(ExecutionContextAttribute::NAME, $context);

        $response = $this->handler(catalog: $catalog)->handle($request);

        self::assertSame(404, $response->getStatusCode());
        self::assertStringContainsString('business-record-not-found', (string) $response->getBody());
    }

    /**
     * Create a bearer principal and matching authenticated execution context.
     *
     * @return  array{AuthenticatedPrincipal, ExecutionContext}  Principal and context for one request.
     *
     * @since   2.0.0
     */
    private function identity(): array
    {
        $principal = AuthorizationContext::principal(['business.record.create']);

        return [$principal, $principal->context(
            SiteContext::default(),
            AuthenticationStrength::BearerToken,
            'business-record-api-test-0001',
        )];
    }

    /**
     * Create a JSON request using the generic collection route as its base URI.
     *
     * @param   string  $json  Exact request body bytes.
     *
     * @return  ServerRequestInterface  Request carrying a readable body stream.
     *
     * @since   2.0.0
     */
    private function request(string $json): ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('POST', 'https://kumwe.test/api/v1/business/records/core.invoice')
            ->withBody((new StreamFactory())->createStream($json));
    }

    /**
     * Build the handler with intentionally uninitialized collaborators that a transport rejection must not call.
     *
     * @param   ?BusinessSurfaceUseCases  $surfaces  Optional recording shared custom/action facade.
     * @param   ?BusinessSurfaceCatalog   $catalog   Optional policy-filtered metadata source.
     *
     * @return  BusinessRecordApiHandler  Handler under test.
     *
     * @since   2.0.0
     */
    private function handler(
        ?BusinessSurfaceUseCases $surfaces = null,
        ?BusinessSurfaceCatalog $catalog = null,
    ): BusinessRecordApiHandler {
        /** @var BusinessRecordService $records */
        $records = (new ReflectionClass(BusinessRecordService::class))->newInstanceWithoutConstructor();
        if ($catalog === null) {
            /** @var BusinessSurfaceCatalog $catalog */
            $catalog = (new ReflectionClass(BusinessSurfaceCatalog::class))->newInstanceWithoutConstructor();
        }

        return new BusinessRecordApiHandler(
            $records,
            new BusinessRecordQueryFactory(),
            new BusinessRecordApiResponder(
                new BusinessRecordApiPresenter(new BusinessRecordProjector()),
                new ProblemDetailsResponseFactory(),
            ),
            $catalog,
            $surfaces ?? $this->createStub(BusinessSurfaceUseCases::class),
        );
    }
}
