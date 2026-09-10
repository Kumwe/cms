<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Delivery\Http\Api\Business;

use DateTimeImmutable;
use Kumwe\Context\Value\AuthenticationStrength;
use Kumwe\Context\Value\SiteContext;
use Kumwe\App\Audit\Application\AuditRecorder;
use Kumwe\App\Audit\Domain\AuditEvent;
use Kumwe\App\BusinessRecord\Application\PostingPeriodService;
use Kumwe\App\Delivery\Http\Api\Business\BusinessApiResponder;
use Kumwe\App\Delivery\Http\Api\Business\PostingPeriodApiHandler;
use Kumwe\App\Delivery\Http\Api\ProblemDetailsResponseFactory;
use Kumwe\App\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\App\Tests\Support\AuthorizationContext;
use Kumwe\App\Tests\Support\ImmediateTransactionManager;
use Kumwe\App\Tests\Support\InMemoryPostingPeriodRepository;
use Laminas\Diactoros\ServerRequestFactory;
use Laminas\Diactoros\StreamFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Kumwe\App\Application\Authorization\ExecutionContextAttribute;

/**
 * Pins the REST face of posting-period administration: routing, JSON shapes, and problem mapping.
 *
 * The rules live in `PostingPeriodService` and are proven there; this suite pins that the adapter
 * dispatches each route to that one service, renders declarations as documents, answers a declaration
 * conflict as its own 409 problem, and defers every other failure to the shared business responder.
 *
 * @since  2.0.0
 */
#[CoversClass(PostingPeriodApiHandler::class)]
final class PostingPeriodApiHandlerTest extends TestCase
{
    /**
     * Close, list and re-open round-trip as JSON documents over one service.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheLifecycleRoundTripsAsJsonDocuments(): void
    {
        $handler = $this->handler();
        $principal = $this->principal();

        $closed = $this->document($handler->handle($this->post($principal, 'close', [
            'key' => '2026-08',
            'starts_at' => '2026-08-01',
            'ends_at' => '2026-09-01T00:00:00Z',
        ])));
        self::assertSame('closed', $closed['status']);
        self::assertSame('2026-08-01T00:00:00Z', $closed['starts_at']);
        self::assertSame('2026-09-01T00:00:00Z', $closed['ends_at']);

        $listing = $this->document($handler->handle($this->get($principal)));
        self::assertCount(1, $listing['items']);
        $filtered = $this->document($handler->handle($this->get($principal, 'acme')));
        self::assertCount(1, $filtered['items']);

        $reopened = $this->document($handler->handle($this->post($principal, 'reopen', [
            'key' => '2026-08',
        ])));
        self::assertSame('open', $reopened['status']);

        $scoped = $this->document($handler->handle($this->post($principal, 'close', [
            'key' => 'acme-2026-08',
            'starts_at' => '2026-08-01',
            'ends_at' => '2026-09-01',
            'organization' => 'acme',
        ])));
        self::assertSame('acme', $scoped['organization']);
    }

    /**
     * A contradicted declaration answers 409 under the posting-period problem type.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testADeclarationConflictAnswersItsOwnProblemDocument(): void
    {
        $handler = $this->handler();
        $principal = $this->principal();
        $body = ['key' => '2026-08', 'starts_at' => '2026-08-01', 'ends_at' => '2026-09-01'];
        $handler->handle($this->post($principal, 'close', $body));

        $response = $handler->handle($this->post($principal, 'close', $body));

        self::assertSame(409, $response->getStatusCode());
        $problem = $this->document($response);
        self::assertSame('urn:kumwe:problem:posting-period-conflict', $problem['type']);
    }

    /**
     * Refused authority, malformed input and unknown routes map onto the shared problem vocabulary.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testFailuresMapOntoTheSharedProblemVocabulary(): void
    {
        $handler = $this->handler();
        $principal = $this->principal();
        $reader = AuthorizationContext::principal([PostingPeriodService::READ]);

        $denied = $handler->handle($this->post($reader, 'close', [
            'key' => '2026-08',
            'starts_at' => '2026-08-01',
            'ends_at' => '2026-09-01',
        ]));
        self::assertSame(403, $denied->getStatusCode());

        $malformed = $handler->handle($this->post($principal, 'close', [
            'key' => '2026-08',
            'starts_at' => 'yesterday',
            'ends_at' => '2026-09-01',
        ]));
        self::assertSame(422, $malformed->getStatusCode());

        $impossible = $handler->handle($this->post($principal, 'close', [
            'key' => '2026-08',
            'starts_at' => '2026-99-99T00:00:00Z',
            'ends_at' => '2026-09-01',
        ]));
        self::assertSame(422, $impossible->getStatusCode());

        $inverted = $handler->handle($this->post($principal, 'close', [
            'key' => '2026-08',
            'starts_at' => '2026-09-01',
            'ends_at' => '2026-08-01',
        ]));
        self::assertSame(422, $inverted->getStatusCode());

        $mistyped = $handler->handle($this->post($principal, 'reopen', [
            'key' => '2026-08',
            'organization' => 7,
        ]));
        self::assertSame(422, $mistyped->getStatusCode());

        $unknown = $handler->handle($this->post($principal, 'purge', ['key' => '2026-08']));
        self::assertSame(422, $unknown->getStatusCode());
    }

    /**
     * Build the handler over the in-memory service and the shared problem renderers.
     *
     * @return  PostingPeriodApiHandler  Handler under test.
     *
     * @since   2.0.0
     */
    private function handler(): PostingPeriodApiHandler
    {
        $events = [];
        $recorder = new class ($events) implements AuditRecorder {
            /**
             * Capture events into the test's own list.
             *
             * @param  list<AuditEvent>  $events  Sink held by reference.
             *
             * @since  2.0.0
             */
            public function __construct(private array &$events)
            {
            }

            /**
             * Append one event to the captured list.
             *
             * @param   AuditEvent  $event  Event the service recorded.
             *
             * @return  void
             *
             * @since   2.0.0
             */
            public function record(AuditEvent $event): void
            {
                $this->events[] = $event;
            }
        };
        $clock = new class implements ClockInterface {
            /**
             * Answer a fixed instant so rendered bookkeeping is exact.
             *
             * @return  DateTimeImmutable  Always 2026-09-05T08:00:00Z.
             *
             * @since   2.0.0
             */
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('2026-09-05T08:00:00Z');
            }
        };
        $problems = new ProblemDetailsResponseFactory();

        return new PostingPeriodApiHandler(
            new PostingPeriodService(
                new InMemoryPostingPeriodRepository(),
                AuthorizationContext::gateway(),
                new ImmediateTransactionManager(),
                $recorder,
                $clock,
            ),
            new BusinessApiResponder($problems),
            $problems,
        );
    }

    /**
     * Mint the managing-and-reading API principal.
     *
     * @return  AuthenticatedPrincipal  Principal holding both posting-period capabilities.
     *
     * @since   2.0.0
     */
    private function principal(): AuthenticatedPrincipal
    {
        return AuthorizationContext::principal([
            PostingPeriodService::MANAGE,
            PostingPeriodService::READ,
        ]);
    }

    /**
     * Build one authenticated GET listing request.
     *
     * @param   AuthenticatedPrincipal  $principal     Authenticated bearer principal.
     * @param   ?string                 $organization  Optional organization filter query.
     *
     * @return  ServerRequestInterface  Prepared request.
     *
     * @since   2.0.0
     */
    private function get(AuthenticatedPrincipal $principal, ?string $organization = null): ServerRequestInterface
    {
        $uri = 'https://kumwe.test/api/v1/business-periods'
            . ($organization === null ? '' : '?organization=' . rawurlencode($organization));
        $request = (new ServerRequestFactory())->createServerRequest('GET', $uri);
        if ($organization !== null) {
            $request = $request->withQueryParams(['organization' => $organization]);
        }

        return $this->authenticated($request, $principal);
    }

    /**
     * Build one authenticated POST action request carrying a JSON body.
     *
     * @param   AuthenticatedPrincipal  $principal  Authenticated bearer principal.
     * @param   string                  $action     Trailing path segment: `close` or `reopen`.
     * @param   array<string, mixed>    $body       JSON body members.
     *
     * @return  ServerRequestInterface  Prepared request.
     *
     * @since   2.0.0
     */
    private function post(AuthenticatedPrincipal $principal, string $action, array $body): ServerRequestInterface
    {
        $encoded = json_encode($body);
        self::assertIsString($encoded);
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', 'https://kumwe.test/api/v1/business-periods/' . $action)
            ->withBody((new StreamFactory())->createStream($encoded));

        return $this->authenticated($request, $principal);
    }

    /**
     * Attach the principal and its matching execution context the way the API middleware does.
     *
     * @param   ServerRequestInterface  $request    Request being prepared.
     * @param   AuthenticatedPrincipal  $principal  Authenticated bearer principal.
     *
     * @return  ServerRequestInterface  Request carrying both authentication attributes.
     *
     * @since   2.0.0
     */
    private function authenticated(
        ServerRequestInterface $request,
        AuthenticatedPrincipal $principal,
    ): ServerRequestInterface {
        $context = $principal->context(
            SiteContext::default(),
            AuthenticationStrength::BearerToken,
            'test-request-0002',
        );

        return $request
            ->withAttribute(AuthenticatedPrincipal::REQUEST_ATTRIBUTE, $principal)
            ->withAttribute(ExecutionContextAttribute::NAME, $context);
    }

    /**
     * Decode one JSON response body into its document.
     *
     * @param   ResponseInterface  $response  Response the handler answered.
     *
     * @return  array<string, mixed>  Decoded document.
     *
     * @since   2.0.0
     */
    private function document(ResponseInterface $response): array
    {
        $decoded = json_decode((string) $response->getBody(), true);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
