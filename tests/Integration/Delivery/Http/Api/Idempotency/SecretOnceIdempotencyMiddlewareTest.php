<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\Delivery\Http\Api\Idempotency;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Kumwe\App\Application\Authorization\ExecutionContextAttribute;
use Kumwe\App\Delivery\Http\Api\Idempotency\IdempotencyKey;
use Kumwe\App\Delivery\Http\Api\Idempotency\RequireIdempotencyKeyMiddleware;
use Kumwe\App\Delivery\Http\Api\Idempotency\SecretOnceIdempotencyMiddleware;
use Kumwe\App\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\App\Infrastructure\Persistence\DoctrineSecretOnceIdempotencyLedger;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Tests\Support\TestKernelFactory;
use Kumwe\Context\Value\ExecutionContext;
use Laminas\Diactoros\Response\JsonResponse;
use Laminas\Diactoros\ServerRequestFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Ramsey\Uuid\Uuid;
use RuntimeException;

#[CoversClass(SecretOnceIdempotencyMiddleware::class)]
#[CoversClass(DoctrineSecretOnceIdempotencyLedger::class)]
final class SecretOnceIdempotencyMiddlewareTest extends TestCase
{
    public function testTokenSecretIsReturnedOnceAndNeverStoredInIdempotencyState(): void
    {
        [$middleware, $database, $tables, $context] = $this->services();
        $marker = Uuid::uuid7()->toString();
        $request = $this->request('token-secret-' . $marker, $context);
        $handler = $this->handler($database, $tables, 'secret-once-' . $marker, false);

        $first = $middleware->process($request, $handler);
        $replay = $middleware->process($request, $handler);
        $firstBody = json_decode((string) $first->getBody(), true, 16, JSON_THROW_ON_ERROR);
        $replayBody = json_decode((string) $replay->getBody(), true, 16, JSON_THROW_ON_ERROR);
        self::assertSame('raw-token-secret', $firstBody['token']);
        self::assertTrue($firstBody['secret_returned']);
        self::assertArrayNotHasKey('token', $replayBody);
        self::assertFalse($replayBody['secret_returned']);
        self::assertSame('true', $replay->getHeaderLine('Idempotency-Replayed'));
        self::assertSame(1, $handler->calls);

        $stored = $database->fetchOne(sprintf(
            'SELECT result_body FROM %s WHERE subject = ? AND operation = ? AND idempotency_key = ?',
            $tables->quoted('idempotency'),
        ), [$context->actorId(), 'POST /api/v1/tokens', 'token-secret-' . $marker]);
        self::assertIsString($stored);
        self::assertStringNotContainsString('raw-token-secret', $stored);
    }

    public function testMutationAndLeaseAreRolledBackTogetherAtFailureKillPoint(): void
    {
        [$middleware, $database, $tables, $context] = $this->services();
        $marker = Uuid::uuid7()->toString();
        $request = $this->request('token-failure-' . $marker, $context);
        $setting = 'secret-failure-' . $marker;

        try {
            $middleware->process($request, $this->handler($database, $tables, $setting, true));
            self::fail('The simulated kill point must escape the middleware.');
        } catch (RuntimeException $exception) {
            self::assertSame('simulated process failure', $exception->getMessage());
        }
        self::assertSame('0', (string) $database->fetchOne(sprintf(
            'SELECT COUNT(*) FROM %s WHERE setting_key = ?',
            $tables->quoted('site_settings'),
        ), [$setting]));
        self::assertSame('0', (string) $database->fetchOne(sprintf(
            'SELECT COUNT(*) FROM %s WHERE subject = ? AND operation = ? AND idempotency_key = ?',
            $tables->quoted('idempotency'),
        ), [$context->actorId(), 'POST /api/v1/tokens', 'token-failure-' . $marker]));
    }

    public function testLegacyCompletedTokenResponseIsRedactedAndScrubbedBeforeReplay(): void
    {
        [$middleware, $database, $tables, $context] = $this->services();
        $marker = Uuid::uuid7()->toString();
        $key = 'legacy-token-' . $marker;
        $request = $this->request($key, $context);
        $legacy = json_encode([
            'token' => 'legacy-plaintext-secret',
            'token_id' => Uuid::uuid7()->toString(),
        ], JSON_THROW_ON_ERROR);
        $now = new DateTimeImmutable();
        $database->insert($tables->raw('idempotency'), [
            'id' => Uuid::uuid7()->toString(),
            'idempotency_key' => $key,
            'subject' => $context->actorId(),
            'operation' => 'POST /api/v1/tokens',
            'request_digest' => $this->digest($request),
            'authorization_fingerprint' => $context->authorizationFingerprint(),
            'state' => 'completed',
            'result_status' => 201,
            'result_body' => $legacy,
            'result_headers' => ['Content-Type' => 'application/json'],
            'result_body_digest' => hash('sha256', $legacy),
            'owner_token' => null,
            'lease_expires_at' => null,
            'attempt' => 0,
            'created_at' => $now,
            'completed_at' => $now,
            'expires_at' => $now->modify('+1 day'),
        ], [
            'result_headers' => Types::JSON,
            'lease_expires_at' => Types::DATETIME_IMMUTABLE,
            'created_at' => Types::DATETIME_IMMUTABLE,
            'completed_at' => Types::DATETIME_IMMUTABLE,
            'expires_at' => Types::DATETIME_IMMUTABLE,
        ]);
        $handler = $this->handler($database, $tables, 'legacy-replay-' . $marker, false);

        $response = $middleware->process($request, $handler);
        $body = json_decode((string) $response->getBody(), true, 16, JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('token', $body);
        self::assertFalse($body['secret_returned']);
        self::assertSame(0, $handler->calls);
        $stored = $database->fetchOne(sprintf(
            'SELECT result_body FROM %s WHERE subject = ? AND operation = ? AND idempotency_key = ?',
            $tables->quoted('idempotency'),
        ), [$context->actorId(), 'POST /api/v1/tokens', $key]);
        self::assertIsString($stored);
        self::assertStringNotContainsString('legacy-plaintext-secret', $stored);
    }

    /**
     * Proves one key cannot be reused for different content, and the first request's effect stands.
     *
     * The key names one request, not one caller's licence to mutate repeatedly. A second body under the same
     * key is refused, and the handler count proves the original mutation ran exactly once and was not
     * replaced by the impostor.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testReusedKeyWithDifferentContentIsRefused(): void
    {
        [$middleware, $database, $tables, $context] = $this->services();
        $marker = Uuid::uuid7()->toString();
        $key = 'token-reuse-' . $marker;
        $first = $this->handler($database, $tables, 'reuse-first-' . $marker, false);
        $second = $this->handler($database, $tables, 'reuse-second-' . $marker, false);

        $middleware->process($this->request($key, $context), $first);
        $refused = $middleware->process(
            $this->request($key, $context)->withHeader('If-Match', '"a-precondition-the-first-lacked"'),
            $second,
        );
        $document = json_decode((string) $refused->getBody(), true, 16, JSON_THROW_ON_ERROR);

        self::assertSame(1, $first->calls);
        self::assertSame(0, $second->calls);
        self::assertSame(422, $refused->getStatusCode());
        self::assertSame('urn:kumwe:problem:idempotency-key-reused', $document['type']);
    }

    /**
     * Proves a key presented under a different credential is refused without reaching the handler.
     *
     * An idempotency key is scoped to the credential that minted it, so a key that leaks cannot be used to
     * replay another subject's mutation or read its recorded response. The refusal is its own named
     * conflict and the handler is never called.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testKeyPresentedUnderADifferentCredentialIsRefused(): void
    {
        [$middleware, $database, $tables, $context] = $this->services();
        $marker = Uuid::uuid7()->toString();
        $key = 'token-credential-' . $marker;
        $request = $this->request($key, $context);
        $this->reservation($database, $tables, $context, $request, [
            'authorization_fingerprint' => hash('sha256', 'another-credential-entirely'),
        ]);
        $handler = $this->handler($database, $tables, 'credential-' . $marker, false);

        $refused = $middleware->process($request, $handler);
        $document = json_decode((string) $refused->getBody(), true, 16, JSON_THROW_ON_ERROR);

        self::assertSame(0, $handler->calls);
        self::assertSame(409, $refused->getStatusCode());
        self::assertSame('urn:kumwe:problem:idempotency-authorization-changed', $document['type']);
    }

    /**
     * Proves a lapsed reservation is taken over so the mutation runs rather than deadlocking on a dead lease.
     *
     * A process that dies mid-mutation leaves a reservation nobody will release. Once its lease has expired
     * the next attempt takes ownership and executes, which is what keeps a crash from making a key
     * permanently unusable.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testLapsedReservationIsTakenOverAndTheMutationRuns(): void
    {
        [$middleware, $database, $tables, $context] = $this->services();
        $marker = Uuid::uuid7()->toString();
        $key = 'token-lapsed-' . $marker;
        $request = $this->request($key, $context);
        $this->reservation($database, $tables, $context, $request, [
            'lease_expires_at' => (new DateTimeImmutable())->modify('-1 minute'),
        ]);
        $handler = $this->handler($database, $tables, 'lapsed-' . $marker, false);

        $response = $middleware->process($request, $handler);

        self::assertSame(1, $handler->calls);
        self::assertSame(201, $response->getStatusCode());
        self::assertSame('2', (string) $database->fetchOne(sprintf(
            'SELECT attempt FROM %s WHERE subject = ? AND operation = ? AND idempotency_key = ?',
            $tables->quoted('idempotency'),
        ), [$context->actorId(), 'POST /api/v1/tokens', $key]));
    }

    /**
     * Proves a live reservation refuses a concurrent attempt instead of running the mutation twice.
     *
     * While one request holds an unexpired lease on the key, a second arrival is refused with a named
     * conflict and the handler is never entered, which is the whole guarantee: at most one execution
     * per key while an attempt is genuinely in flight.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testLiveReservationRefusesAConcurrentAttempt(): void
    {
        [$middleware, $database, $tables, $context] = $this->services();
        $marker = Uuid::uuid7()->toString();
        $key = 'token-live-' . $marker;
        $request = $this->request($key, $context);
        $this->reservation($database, $tables, $context, $request, [
            'lease_expires_at' => (new DateTimeImmutable())->modify('+2 minutes'),
        ]);
        $handler = $this->handler($database, $tables, 'live-' . $marker, false);

        $refused = $middleware->process($request, $handler);
        $document = json_decode((string) $refused->getBody(), true, 16, JSON_THROW_ON_ERROR);

        self::assertSame(0, $handler->calls);
        self::assertSame(409, $refused->getStatusCode());
        self::assertSame('urn:kumwe:problem:idempotency-in-progress', $document['type']);
    }

    /**
     * Insert one in-progress reservation held by another request, overriding any column under test.
     *
     * @param   Connection              $database   Connection the fixture row is written on.
     * @param   TableNames              $tables     Resolves the physical `idempotency` table name.
     * @param   ExecutionContext        $context    Actor whose subject and fingerprint the row carries.
     * @param   ServerRequestInterface  $request    Request whose digest the row stores.
     * @param   array<string, mixed>    $overrides  Columns to replace in the fixture row.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function reservation(
        Connection $database,
        TableNames $tables,
        ExecutionContext $context,
        ServerRequestInterface $request,
        array $overrides,
    ): void {
        $now = new DateTimeImmutable();
        $database->insert($tables->raw('idempotency'), [
            ...[
                'id' => Uuid::uuid7()->toString(),
                'idempotency_key' => (string) $request->getAttribute(RequireIdempotencyKeyMiddleware::ATTRIBUTE),
                'subject' => $context->actorId(),
                'operation' => 'POST /api/v1/tokens',
                'request_digest' => $this->digest($request),
                'authorization_fingerprint' => $context->authorizationFingerprint(),
                'state' => 'in_progress',
                'owner_token' => 'another-request-owns-this',
                'lease_owner' => 'another-request-owns-this',
                'lease_expires_at' => $now->modify('+2 minutes'),
                'attempt' => 1,
                'created_at' => $now,
                'expires_at' => $now->modify('+1 day'),
            ],
            ...$overrides,
        ], [
            'lease_expires_at' => Types::DATETIME_IMMUTABLE,
            'created_at' => Types::DATETIME_IMMUTABLE,
            'expires_at' => Types::DATETIME_IMMUTABLE,
        ]);
    }

    /** @return array{SecretOnceIdempotencyMiddleware, Connection, TableNames, ExecutionContext} */
    private function services(): array
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $middleware = $container->get(SecretOnceIdempotencyMiddleware::class);
        $database = $container->get(Connection::class);
        $tables = $container->get(TableNames::class);
        self::assertInstanceOf(SecretOnceIdempotencyMiddleware::class, $middleware);
        self::assertInstanceOf(Connection::class, $database);
        self::assertInstanceOf(TableNames::class, $tables);
        return [$middleware, $database, $tables, TestKernelFactory::administratorContext($container)];
    }

    private function request(string $key, ExecutionContext $context): ServerRequestInterface
    {
        $principal = $context->principal();
        self::assertInstanceOf(AuthenticatedPrincipal::class, $principal);
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', 'https://kumwe.test/api/v1/tokens')
            ->withHeader('Content-Type', 'application/json')
            ->withAttribute(RequireIdempotencyKeyMiddleware::ATTRIBUTE, IdempotencyKey::fromHeader($key))
            ->withAttribute(AuthenticatedPrincipal::REQUEST_ATTRIBUTE, $principal)
            ->withAttribute(ExecutionContextAttribute::NAME, $context);
        $request->getBody()->write(json_encode([
            'email' => 'integration-administrator@example.test',
            'capabilities' => ['users.manage'],
        ], JSON_THROW_ON_ERROR));
        return $request;
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

    private function handler(
        Connection $database,
        TableNames $tables,
        string $setting,
        bool $fail,
    ): RequestHandlerInterface {
        return new class ($database, $tables, $setting, $fail) implements RequestHandlerInterface {
            public int $calls = 0;

            public function __construct(
                private readonly Connection $database,
                private readonly TableNames $tables,
                private readonly string $setting,
                private readonly bool $fail,
            ) {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                ++$this->calls;
                $this->database->insert($this->tables->raw('site_settings'), [
                    'setting_key' => $this->setting,
                    'setting_value' => ['created' => true],
                    'version' => 1,
                    'updated_by' => null,
                    'updated_at' => new DateTimeImmutable(),
                ], ['setting_value' => Types::JSON, 'updated_at' => Types::DATETIME_IMMUTABLE]);
                if ($this->fail) {
                    throw new RuntimeException('simulated process failure');
                }
                return new JsonResponse([
                    'token' => 'raw-token-secret',
                    'token_id' => Uuid::uuid7()->toString(),
                    'secret_returned' => true,
                ], 201, ['Cache-Control' => 'no-store']);
            }
        };
    }
}
