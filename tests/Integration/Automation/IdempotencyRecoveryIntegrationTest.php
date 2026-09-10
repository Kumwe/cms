<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\Automation;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Kumwe\App\Application\Authorization\ExecutionContextAttribute;
use Kumwe\App\Delivery\Http\Api\Idempotency\IdempotencyKey;
use Kumwe\App\Delivery\Http\Api\Idempotency\PersistentIdempotencyMiddleware;
use Kumwe\App\Delivery\Http\Api\Idempotency\RequireIdempotencyKeyMiddleware;
use Kumwe\App\Delivery\Http\Api\Idempotency\ServerFailureResponse;
use Kumwe\App\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\App\Infrastructure\Automation\DoctrineIdempotencyPurger;
use Kumwe\App\Infrastructure\Persistence\DoctrineIdempotencyLedger;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Tests\Support\TestKernelFactory;
use Kumwe\Context\Value\ExecutionContext;
use Laminas\Diactoros\Response;
use Laminas\Diactoros\ServerRequestFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Ramsey\Uuid\Uuid;
use RuntimeException;

#[CoversClass(PersistentIdempotencyMiddleware::class)]
#[CoversClass(DoctrineIdempotencyPurger::class)]
#[CoversClass(DoctrineIdempotencyLedger::class)]
#[CoversClass(ServerFailureResponse::class)]
final class IdempotencyRecoveryIntegrationTest extends TestCase
{
    private const OPERATION = 'POST /api/v1/content';

    public function testStaleOwnershipIsRecoveredAndTheCompletedResultIsReplayed(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $database = $container->get(Connection::class);
        $tables = $container->get(TableNames::class);
        $middleware = $container->get(PersistentIdempotencyMiddleware::class);
        self::assertInstanceOf(Connection::class, $database);
        self::assertInstanceOf(TableNames::class, $tables);
        self::assertInstanceOf(PersistentIdempotencyMiddleware::class, $middleware);
        $key = 'recovery-' . substr(Uuid::uuid7()->toString(), 0, 24);
        $context = TestKernelFactory::administratorContext($container);
        $request = $this->request($key, $context);
        $operation = self::OPERATION;
        $now = new DateTimeImmutable('now');
        $ownerToken = Uuid::uuid7()->toString();
        $database->insert($tables->raw('idempotency'), [
            'id' => Uuid::uuid7()->toString(),
            'idempotency_key' => $key,
            'subject' => $context->actorId(),
            'operation' => $operation,
            'request_digest' => $this->digest($request),
            'authorization_fingerprint' => $context->authorizationFingerprint(),
            'state' => 'in_progress',
            'owner_token' => $ownerToken,
            'locked_until' => $now->modify('-1 minute'),
            'lease_owner' => $ownerToken,
            'lease_expires_at' => $now->modify('-1 minute'),
            'result_status' => null,
            'result_body' => null,
            'result_headers' => null,
            'result_body_digest' => null,
            'created_at' => $now->modify('-20 minutes'),
            'completed_at' => null,
            'expires_at' => $now->modify('+1 day'),
        ], [
            'locked_until' => Types::DATETIME_IMMUTABLE,
            'lease_expires_at' => Types::DATETIME_IMMUTABLE,
            'created_at' => Types::DATETIME_IMMUTABLE,
            'expires_at' => Types::DATETIME_IMMUTABLE,
        ]);

        $calls = new \stdClass();
        $calls->count = 0;
        $response = $middleware->process($request, new class ($calls) implements RequestHandlerInterface {
            public function __construct(private \stdClass $calls)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->calls->count++;
                $response = new Response(status: 201);
                $response->getBody()->write('{"recovered":true}');
                return $response->withHeader('Content-Type', 'application/json');
            }
        });
        self::assertSame(201, $response->getStatusCode());
        self::assertSame(1, $calls->count);

        $replayed = $middleware->process($request, new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw new RuntimeException('A completed operation must be replayed.');
            }
        });
        self::assertSame(201, $replayed->getStatusCode());
        self::assertSame('true', $replayed->getHeaderLine('Idempotency-Replayed'));
        self::assertSame('{"recovered":true}', (string) $replayed->getBody());
    }

    /**
     * Proves an expired record is taken over rather than replayed, and the mutation runs afresh.
     *
     * A retention window that has passed means the recorded result is no longer a promise anyone may be
     * given. The retry therefore executes the handler again instead of returning stale content, and the
     * record it leaves behind is the new attempt's, not a resurrection of the old one.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testExpiredRecordIsTakenOverAndTheMutationRunsAfresh(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $database = $container->get(Connection::class);
        $tables = $container->get(TableNames::class);
        $middleware = $container->get(PersistentIdempotencyMiddleware::class);
        self::assertInstanceOf(Connection::class, $database);
        self::assertInstanceOf(TableNames::class, $tables);
        self::assertInstanceOf(PersistentIdempotencyMiddleware::class, $middleware);
        $key = 'expired-' . substr(Uuid::uuid7()->toString(), 0, 24);
        $context = TestKernelFactory::administratorContext($container);
        $request = $this->request($key, $context);
        $now = new DateTimeImmutable('now');
        $body = '{"stale":true}';
        $database->insert($tables->raw('idempotency'), [
            'id' => Uuid::uuid7()->toString(),
            'idempotency_key' => $key,
            'subject' => $context->actorId(),
            'operation' => self::OPERATION,
            'request_digest' => hash('sha256', 'a-request-this-one-no-longer-speaks-for'),
            'authorization_fingerprint' => hash('sha256', 'an-expired-authorization'),
            'state' => 'completed',
            'owner_token' => null,
            'locked_until' => null,
            'lease_owner' => null,
            'lease_expires_at' => null,
            'result_status' => 200,
            'result_body' => $body,
            'result_headers' => ['Content-Type' => 'application/json'],
            'result_body_digest' => hash('sha256', $body),
            'created_at' => $now->modify('-2 days'),
            'completed_at' => $now->modify('-2 days'),
            'expires_at' => $now->modify('-1 hour'),
        ], [
            'result_headers' => Types::JSON,
            'created_at' => Types::DATETIME_IMMUTABLE,
            'completed_at' => Types::DATETIME_IMMUTABLE,
            'expires_at' => Types::DATETIME_IMMUTABLE,
        ]);

        $response = $middleware->process($request, new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $response = new Response(status: 201);
                $response->getBody()->write('{"reclaimed":true}');
                return $response->withHeader('Content-Type', 'application/json');
            }
        });

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('', $response->getHeaderLine('Idempotency-Replayed'));
        self::assertSame('{"reclaimed":true}', (string) $response->getBody());
        self::assertSame($this->digest($request), $database->fetchOne(sprintf(
            'SELECT request_digest FROM %s WHERE subject = ? AND operation = ? AND idempotency_key = ?',
            $tables->quoted('idempotency'),
        ), [$context->actorId(), self::OPERATION, $key]));
    }

    /**
     * Proves a failed record is reclaimed by a retry of the same request rather than blocking it forever.
     *
     * A failure leaves ownership behind that nothing else will clear. The identical request must be able to
     * take that ownership back and run, because the alternative is a key that is permanently unusable
     * after one server-side fault.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testFailedRecordIsReclaimedByARetryOfTheSameRequest(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $database = $container->get(Connection::class);
        $tables = $container->get(TableNames::class);
        $middleware = $container->get(PersistentIdempotencyMiddleware::class);
        self::assertInstanceOf(Connection::class, $database);
        self::assertInstanceOf(TableNames::class, $tables);
        self::assertInstanceOf(PersistentIdempotencyMiddleware::class, $middleware);
        $key = 'failed-retry-' . substr(Uuid::uuid7()->toString(), 0, 20);
        $context = TestKernelFactory::administratorContext($container);
        $request = $this->request($key, $context);
        $now = new DateTimeImmutable('now');
        $database->insert($tables->raw('idempotency'), [
            'id' => Uuid::uuid7()->toString(),
            'idempotency_key' => $key,
            'subject' => $context->actorId(),
            'operation' => self::OPERATION,
            'request_digest' => $this->digest($request),
            'authorization_fingerprint' => $context->authorizationFingerprint(),
            'state' => 'failed',
            'owner_token' => null,
            'locked_until' => null,
            'lease_owner' => null,
            'lease_expires_at' => null,
            'result_status' => null,
            'result_body' => null,
            'result_headers' => null,
            'result_body_digest' => null,
            'created_at' => $now->modify('-5 minutes'),
            'completed_at' => null,
            'expires_at' => $now->modify('+1 day'),
        ], [
            'created_at' => Types::DATETIME_IMMUTABLE,
            'expires_at' => Types::DATETIME_IMMUTABLE,
        ]);

        $calls = new \stdClass();
        $calls->count = 0;
        $response = $middleware->process($request, new class ($calls) implements RequestHandlerInterface {
            public function __construct(private \stdClass $calls)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->calls->count++;
                $response = new Response(status: 201);
                $response->getBody()->write('{"retried":true}');
                return $response->withHeader('Content-Type', 'application/json');
            }
        });

        self::assertSame(201, $response->getStatusCode());
        self::assertSame(1, $calls->count);
        self::assertSame('completed', $database->fetchOne(sprintf(
            'SELECT state FROM %s WHERE subject = ? AND operation = ? AND idempotency_key = ?',
            $tables->quoted('idempotency'),
        ), [$context->actorId(), self::OPERATION, $key]));
    }

    public function testExceptionRemovesInProgressOwnershipForImmediateRetry(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $database = $container->get(Connection::class);
        $tables = $container->get(TableNames::class);
        $middleware = $container->get(PersistentIdempotencyMiddleware::class);
        self::assertInstanceOf(Connection::class, $database);
        self::assertInstanceOf(TableNames::class, $tables);
        self::assertInstanceOf(PersistentIdempotencyMiddleware::class, $middleware);
        $key = 'exception-' . substr(Uuid::uuid7()->toString(), 0, 24);
        $context = TestKernelFactory::administratorContext($container);
        $request = $this->request($key, $context);

        try {
            $middleware->process($request, new class implements RequestHandlerInterface {
                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    throw new RuntimeException('Expected application exception.');
                }
            });
            self::fail('The application exception was swallowed.');
        } catch (RuntimeException $exception) {
            self::assertSame('Expected application exception.', $exception->getMessage());
        }

        self::assertSame(0, (int) $database->fetchOne(sprintf(
            'SELECT COUNT(*) FROM %s WHERE subject = ? AND operation = ? AND idempotency_key = ?',
            $tables->quoted('idempotency'),
        ), [$context->actorId(), self::OPERATION, $key]));
    }

    public function testServerFailureRollsBackMutationAndReleasesReservation(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $database = $container->get(Connection::class);
        $tables = $container->get(TableNames::class);
        $middleware = $container->get(PersistentIdempotencyMiddleware::class);
        self::assertInstanceOf(Connection::class, $database);
        self::assertInstanceOf(TableNames::class, $tables);
        self::assertInstanceOf(PersistentIdempotencyMiddleware::class, $middleware);
        $key = 'server-failure-' . substr(Uuid::uuid7()->toString(), 0, 20);
        $auditId = Uuid::uuid7()->toString();
        $context = TestKernelFactory::administratorContext($container);
        $request = $this->request($key, $context);
        $response = $middleware->process(
            $request,
            new class ($database, $tables, $auditId) implements RequestHandlerInterface {
                public function __construct(
                    private Connection $database,
                    private TableNames $tables,
                    private string $auditId,
                ) {
                }

                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    $this->database->insert($this->tables->raw('audit_events'), [
                        'id' => $this->auditId,
                        'occurred_at' => new DateTimeImmutable('now'),
                        'actor_id' => 'test:idempotency',
                        'action' => 'test.idempotency.rollback',
                        'subject_type' => 'test',
                        'subject_id' => $this->auditId,
                        'outcome' => 'failure',
                        'metadata' => [],
                    ], [
                        'occurred_at' => Types::DATETIME_IMMUTABLE,
                        'metadata' => Types::JSON,
                    ]);
                    return new Response(status: 503);
                }
            },
        );

        self::assertSame(503, $response->getStatusCode());
        self::assertFalse($database->fetchOne(sprintf(
            'SELECT id FROM %s WHERE id = ?',
            $tables->quoted('audit_events'),
        ), [$auditId]));
        self::assertSame(0, (int) $database->fetchOne(sprintf(
            'SELECT COUNT(*) FROM %s WHERE subject = ? AND operation = ? AND idempotency_key = ?',
            $tables->quoted('idempotency'),
        ), [$context->actorId(), self::OPERATION, $key]));
    }

    public function testPurgeCannotDeleteRecordReacquiredAfterCandidateSelection(): void
    {
        $environment = Environment::fromGlobals();
        $primaryContainer = TestKernelFactory::create($environment);
        $secondaryContainer = TestKernelFactory::create($environment);
        $primary = $primaryContainer->get(Connection::class);
        $secondary = $secondaryContainer->get(Connection::class);
        $tables = $primaryContainer->get(TableNames::class);
        $clock = $primaryContainer->get(ClockInterface::class);
        self::assertInstanceOf(Connection::class, $primary);
        self::assertInstanceOf(Connection::class, $secondary);
        self::assertNotSame($primary, $secondary);
        self::assertInstanceOf(TableNames::class, $tables);
        self::assertInstanceOf(ClockInterface::class, $clock);
        $purger = new DoctrineIdempotencyPurger($primary, $tables, $clock);
        $id = Uuid::uuid7()->toString();
        $now = new DateTimeImmutable('now');
        $body = '{"expired":true}';
        $primary->insert($tables->raw('idempotency'), [
            'id' => $id,
            'idempotency_key' => 'purge-race-' . substr($id, 0, 20),
            'subject' => TestKernelFactory::administratorContext($primaryContainer)->actorId(),
            'operation' => self::OPERATION,
            'request_digest' => hash('sha256', 'expired-request'),
            'authorization_fingerprint' => hash('sha256', 'expired-authorization'),
            'state' => 'completed',
            'owner_token' => null,
            'locked_until' => null,
            'lease_owner' => null,
            'lease_expires_at' => null,
            'result_status' => 200,
            'result_body' => $body,
            'result_headers' => ['Content-Type' => 'application/json'],
            'result_body_digest' => hash('sha256', $body),
            'created_at' => $now->modify('-2 days'),
            'completed_at' => $now->modify('-2 days'),
            'expires_at' => $now->modify('-1 day'),
        ], [
            'result_headers' => Types::JSON,
            'created_at' => Types::DATETIME_IMMUTABLE,
            'completed_at' => Types::DATETIME_IMMUTABLE,
            'expires_at' => Types::DATETIME_IMMUTABLE,
        ]);

        $cutoff = $clock->now();
        self::assertContains($id, $purger->expiredCandidates($cutoff, 100));

        $newOwner = Uuid::uuid7()->toString();
        $secondary->update($tables->raw('idempotency'), [
            'state' => 'in_progress',
            'owner_token' => $newOwner,
            'locked_until' => $now->modify('+15 minutes'),
            'lease_owner' => $newOwner,
            'lease_expires_at' => $now->modify('+15 minutes'),
            'expires_at' => $now->modify('+1 day'),
            'result_status' => null,
            'result_body' => null,
            'result_headers' => null,
            'result_body_digest' => null,
            'completed_at' => null,
        ], ['id' => $id], [
            'locked_until' => Types::DATETIME_IMMUTABLE,
            'lease_expires_at' => Types::DATETIME_IMMUTABLE,
            'expires_at' => Types::DATETIME_IMMUTABLE,
            'id' => Types::GUID,
        ]);

        self::assertSame(0, $purger->deleteExpiredCandidates([$id], $cutoff));
        self::assertSame($newOwner, $secondary->fetchOne(sprintf(
            'SELECT owner_token FROM %s WHERE id = ?',
            $tables->quoted('idempotency'),
        ), [$id]));
    }

    private function request(string $key, ExecutionContext $context): ServerRequestInterface
    {
        $principal = $context->principal();
        if (!$principal instanceof AuthenticatedPrincipal) {
            throw new RuntimeException('The idempotency integration request requires a human principal.');
        }
        return (new ServerRequestFactory())
            ->createServerRequest('POST', 'https://kumwe.test/api/v1/content')
            ->withAttribute(
                RequireIdempotencyKeyMiddleware::ATTRIBUTE,
                IdempotencyKey::fromHeader($key),
            )
            ->withAttribute(
                AuthenticatedPrincipal::REQUEST_ATTRIBUTE,
                $principal,
            )
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
}
