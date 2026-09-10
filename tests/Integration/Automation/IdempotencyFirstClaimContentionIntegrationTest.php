<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\Automation;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Types;
use Kumwe\App\Kernel\Container;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\App\Delivery\Http\Api\Idempotency\IdempotencyKey;
use Kumwe\App\Delivery\Http\Api\Idempotency\PersistentIdempotencyMiddleware;
use Kumwe\App\Delivery\Http\Api\Idempotency\RequireIdempotencyKeyMiddleware;
use Kumwe\App\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Tests\Support\TestKernelFactory;
use Laminas\Diactoros\Response;
use Laminas\Diactoros\ServerRequestFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use Kumwe\App\Application\Authorization\ExecutionContextAttribute;

/**
 * Proves the losing half of a concurrent first claim, across two connections on the configured database.
 *
 * The unit suite drives this middleware over a fake ledger, and the recovery suite covers stale
 * ownership, exception release and the purge race — but nothing made two connections present one key.
 * Here the winner's reservation is committed on a connection of its own and the loser's request is then
 * issued through the kernel, so the collision the loser hits is a real cross-connection violation of the
 * ledger's unique index rather than a fake. The one thing not reproduced is simultaneity, which does not
 * change the code path: the index decides the winner whichever order the two inserts arrive in, and it is
 * the loser's branch — replay or refuse, never run the handler — that this asserts.
 *
 * @since  2.0.0
 */
#[CoversClass(PersistentIdempotencyMiddleware::class)]
final class IdempotencyFirstClaimContentionIntegrationTest extends TestCase
{
    private const OPERATION = 'POST /api/v1/content';

    public function testTheLoserOfAFirstClaimIsRefusedInFlightAndNeverReachesTheHandler(): void
    {
        $environment = Environment::fromGlobals();
        $loser = TestKernelFactory::create($environment);
        $context = TestKernelFactory::administratorContext($loser);
        $winner = DriverManager::getConnection($this->connection($loser)->getParams());
        $key = 'firstclaim-' . substr(str_replace('-', '', Uuid::uuid7()->toString()), 0, 20);
        $request = $this->request($key, $context);
        $this->reserve($loser, $winner, $context, $request, $key, 'in_progress');

        $handler = $this->countingHandler();
        $response = $this->middleware($loser)->process($request, $handler);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame(
            0,
            $handler->calls,
            'The loser of the first claim must not perform the mutation a second time.',
        );
        self::assertStringContainsString('application/problem+json', $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString(
            'idempotency',
            strtolower((string) $response->getBody()),
            'The refusal names the ledger rather than leaking what the winner is doing.',
        );
    }

    public function testTheLoserOfAFirstClaimReplaysTheWinnersStoredResultOnceItCompletes(): void
    {
        $environment = Environment::fromGlobals();
        $loser = TestKernelFactory::create($environment);
        $context = TestKernelFactory::administratorContext($loser);
        $winner = DriverManager::getConnection($this->connection($loser)->getParams());
        $key = 'firstclaim-' . substr(str_replace('-', '', Uuid::uuid7()->toString()), 0, 20);
        $request = $this->request($key, $context);
        $this->reserve($loser, $winner, $context, $request, $key, 'completed');

        $handler = $this->countingHandler();
        $response = $this->middleware($loser)->process($request, $handler);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('true', $response->getHeaderLine('Idempotency-Replayed'));
        self::assertSame('{"claimed":"by-the-winner"}', (string) $response->getBody());
        self::assertSame(0, $handler->calls, 'A completed key is replayed, never re-run.');
    }

    /**
     * Commit the winner's ledger row on its own connection, in the state the scenario needs.
     */
    private function reserve(
        Container $container,
        Connection $winner,
        ExecutionContext $context,
        ServerRequestInterface $request,
        string $key,
        string $state,
    ): void {
        $now = new DateTimeImmutable('now');
        $token = Uuid::uuid7()->toString();
        $completed = $state === 'completed';
        $body = '{"claimed":"by-the-winner"}';
        $winner->insert($this->tables($container)->raw('idempotency'), [
            'id' => Uuid::uuid7()->toString(),
            'idempotency_key' => $key,
            'subject' => $context->actorId(),
            'operation' => self::OPERATION,
            'request_digest' => $this->digest($request),
            'authorization_fingerprint' => $context->authorizationFingerprint(),
            'state' => $state,
            'owner_token' => $completed ? null : $token,
            'locked_until' => $completed ? null : $now->modify('+10 minutes'),
            'lease_owner' => $completed ? null : $token,
            'lease_expires_at' => $completed ? null : $now->modify('+10 minutes'),
            'result_status' => $completed ? 201 : null,
            'result_body' => $completed ? $body : null,
            'result_headers' => $completed ? ['Content-Type' => 'application/json'] : null,
            'result_body_digest' => $completed ? hash('sha256', $body) : null,
            'created_at' => $now,
            'completed_at' => $completed ? $now : null,
            'expires_at' => $now->modify('+1 day'),
        ], [
            'locked_until' => Types::DATETIME_IMMUTABLE,
            'lease_expires_at' => Types::DATETIME_IMMUTABLE,
            'result_headers' => Types::JSON,
            'created_at' => Types::DATETIME_IMMUTABLE,
            'completed_at' => Types::DATETIME_IMMUTABLE,
            'expires_at' => Types::DATETIME_IMMUTABLE,
        ]);
    }

    /**
     * A handler that records whether the middleware ever let the mutation through.
     */
    private function countingHandler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            /**
             * How many times the middleware admitted the mutation.
             *
             * @var    int
             * @since  2.0.0
             */
            public int $calls = 0;

            /**
             * Record the call and answer with a created response.
             *
             * @param   ServerRequestInterface  $request  Mutation the middleware admitted.
             *
             * @return  ResponseInterface  A created response with a JSON body.
             *
             * @since   2.0.0
             */
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->calls++;
                $response = new Response(status: 201);
                $response->getBody()->write('{"claimed":"by-the-loser"}');

                return $response->withHeader('Content-Type', 'application/json');
            }
        };
    }

    private function request(string $key, ExecutionContext $context): ServerRequestInterface
    {
        $principal = $context->principal();
        if (!$principal instanceof AuthenticatedPrincipal) {
            throw new RuntimeException('The idempotency contention request requires a human principal.');
        }

        return (new ServerRequestFactory())
            ->createServerRequest('POST', 'https://kumwe.test/api/v1/content')
            ->withAttribute(RequireIdempotencyKeyMiddleware::ATTRIBUTE, IdempotencyKey::fromHeader($key))
            ->withAttribute(AuthenticatedPrincipal::REQUEST_ATTRIBUTE, $principal)
            ->withAttribute(ExecutionContextAttribute::NAME, $context);
    }

    private function digest(ServerRequestInterface $request): string
    {
        return hash('sha256', implode("\n", [
            strtoupper($request->getMethod()),
            $request->getUri()->getPath(),
            $request->getUri()->getQuery(),
            $request->getHeaderLine('Content-Type'),
            $request->getHeaderLine('If-Match'),
            (string) $request->getBody(),
        ]));
    }

    private function middleware(Container $container): PersistentIdempotencyMiddleware
    {
        $middleware = $container->get(PersistentIdempotencyMiddleware::class);
        if (!$middleware instanceof PersistentIdempotencyMiddleware) {
            throw new RuntimeException('The idempotency middleware is unavailable.');
        }

        return $middleware;
    }

    private function tables(Container $container): TableNames
    {
        $tables = $container->get(TableNames::class);
        if (!$tables instanceof TableNames) {
            throw new RuntimeException('The integration table map is unavailable.');
        }

        return $tables;
    }

    private function connection(Container $container): Connection
    {
        $connection = $container->get(Connection::class);
        if (!$connection instanceof Connection) {
            throw new RuntimeException('The integration connection is unavailable.');
        }

        return $connection;
    }
}
