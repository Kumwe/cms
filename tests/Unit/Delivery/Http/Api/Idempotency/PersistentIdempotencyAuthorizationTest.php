<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Delivery\Http\Api\Idempotency;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Kumwe\App\Application\Authorization\AuthorizationGateway;
use Kumwe\App\Application\Authorization\AuthorizationDenied;
use Kumwe\App\Application\Authorization\AuthorizationResource;
use Kumwe\App\Application\Persistence\TransactionManager;
use Kumwe\App\Content\Application\ContentService;
use Kumwe\App\Delivery\Http\Api\Idempotency\HttpMutationPreauthorizer;
use Kumwe\App\Delivery\Http\Api\Idempotency\IdempotencyKey;
use Kumwe\App\Delivery\Http\Api\Idempotency\PersistentIdempotencyMiddleware;
use Kumwe\App\Delivery\Http\Api\Idempotency\RequireIdempotencyKeyMiddleware;
use Kumwe\App\Delivery\Http\Api\ProblemDetailsResponseFactory;
use Kumwe\App\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\App\Identity\Application\Administration\AccessControlRepository;
use Kumwe\App\Identity\Application\Administration\TokenDelegationPreauthorizer;
use Kumwe\App\Identity\Application\Administration\TokenRotationPreauthorizer;
use Kumwe\Extension\Spi\Identity\Domain\Capability;
use Kumwe\App\Infrastructure\Persistence\DoctrineIdempotencyLedger;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Tests\Support\AuthorizationContext;
use Laminas\Diactoros\ServerRequestFactory;
use Laminas\Diactoros\StreamFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ReflectionClass;
use RuntimeException;
use Kumwe\App\Application\Authorization\ExecutionContextAttribute;

#[CoversClass(PersistentIdempotencyMiddleware::class)]
#[CoversClass(DoctrineIdempotencyLedger::class)]
#[CoversClass(HttpMutationPreauthorizer::class)]
final class PersistentIdempotencyAuthorizationTest extends TestCase
{
    public function testReportExportPreauthorizationDecodesAndTargetsTheExactReport(): void
    {
        $context = AuthorizationContext::human(['business.record.export']);
        $authorization = $this->createMock(AuthorizationGateway::class);
        $authorization->expects(self::once())->method('assertAllowed')->with(
            $context,
            self::callback(
                static fn (Capability $capability): bool => $capability->value() === 'business.record.export',
            ),
            self::callback(
                static fn (AuthorizationResource $resource): bool => $resource->type() === 'business_report'
                    && $resource->identifier() === 'acme.open_items',
            ),
        );

        $this->preauthorizer($authorization)->authorize(
            (new ServerRequestFactory())->createServerRequest(
                'POST',
                '/api/v1/business/reports/acme%2Eopen_items/exports',
            ),
            $context,
        );
    }

    public function testMalformedReportExportIdentifierIsRejectedBeforeAuthorization(): void
    {
        $principal = AuthorizationContext::principal(['business.record.export']);
        $context = $principal->context(
            \Kumwe\Context\Value\SiteContext::default(),
            \Kumwe\Context\Value\AuthenticationStrength::BearerToken,
            'idempotency-malformed-report-export-test',
        );
        $authorization = $this->createMock(AuthorizationGateway::class);
        $authorization->expects(self::never())->method('assertAllowed');
        $database = $this->createMock(Connection::class);
        $database->expects(self::never())->method('insert');
        $database->expects(self::never())->method('fetchAssociative');
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/v1/business/reports/acme%2Fopen_items/exports')
            ->withAttribute(RequireIdempotencyKeyMiddleware::ATTRIBUTE, IdempotencyKey::fromHeader('stable-key-0005'))
            ->withAttribute(AuthenticatedPrincipal::REQUEST_ATTRIBUTE, $principal)
            ->withAttribute(ExecutionContextAttribute::NAME, $context);
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::never())->method('handle');

        $response = (new PersistentIdempotencyMiddleware(
            new DoctrineIdempotencyLedger($database, new TableNames($database, 'kumwe_'), $this->clock()),
            $this->clock(),
            new ProblemDetailsResponseFactory(),
            $this->transactions(),
            $this->preauthorizer($authorization),
        ))->process($request, $handler);
        /** @var array<string, mixed> $document */
        $document = json_decode((string) $response->getBody(), true, 32, JSON_THROW_ON_ERROR);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('urn:kumwe:problem:invalid-business-report-export', $document['type']);
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
    }

    public function testReportExportForAnotherReportCannotProbeOrReserveAnExistingKey(): void
    {
        $principal = AuthorizationContext::principalFromGrantRows([[
            'capability' => 'business.record.export',
            'scope_type' => 'business_report',
            'scope_identifier' => 'acme.allowed_report',
        ]]);
        $context = $principal->context(
            \Kumwe\Context\Value\SiteContext::default(),
            \Kumwe\Context\Value\AuthenticationStrength::BearerToken,
            'idempotency-report-export-test',
        );
        $database = $this->createMock(Connection::class);
        $database->expects(self::never())->method('insert');
        $database->expects(self::never())->method('fetchAssociative');
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/v1/business/reports/acme.denied_report/exports')
            ->withAttribute(RequireIdempotencyKeyMiddleware::ATTRIBUTE, IdempotencyKey::fromHeader('stable-key-0004'))
            ->withAttribute(AuthenticatedPrincipal::REQUEST_ATTRIBUTE, $principal)
            ->withAttribute(ExecutionContextAttribute::NAME, $context);
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::never())->method('handle');

        $this->expectException(AuthorizationDenied::class);
        (new PersistentIdempotencyMiddleware(
            new DoctrineIdempotencyLedger($database, new TableNames($database, 'kumwe_'), $this->clock()),
            $this->clock(),
            new ProblemDetailsResponseFactory(),
            $this->transactions(),
            $this->preauthorizer(AuthorizationContext::gateway()),
        ))->process($request, $handler);
    }

    public function testWrongResourceCannotProbeOrReplayAnExistingKey(): void
    {
        $allowed = '018f22e2-7c8b-7ab0-8f3a-88e8026bb411';
        $denied = '018f22e2-7c8b-7ab0-8f3a-88e8026bb412';
        $principal = AuthorizationContext::principalFromGrantRows([[
            'capability' => 'content.update',
            'scope_type' => 'content',
            'scope_identifier' => $allowed,
        ]]);
        $context = $principal->context(
            \Kumwe\Context\Value\SiteContext::default(),
            \Kumwe\Context\Value\AuthenticationStrength::BearerToken,
            'idempotency-auth-test',
        );
        $database = $this->createMock(Connection::class);
        $database->expects(self::never())->method('insert');
        $database->expects(self::never())->method('fetchAssociative');
        $request = (new ServerRequestFactory())
            ->createServerRequest('PATCH', '/api/v1/content/' . $denied)
            ->withAttribute(RequireIdempotencyKeyMiddleware::ATTRIBUTE, IdempotencyKey::fromHeader('stable-key-0001'))
            ->withAttribute(AuthenticatedPrincipal::REQUEST_ATTRIBUTE, $principal)
            ->withAttribute(ExecutionContextAttribute::NAME, $context);
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::never())->method('handle');

        $this->expectException(AuthorizationDenied::class);
        (new PersistentIdempotencyMiddleware(
            new DoctrineIdempotencyLedger($database, new TableNames($database, 'kumwe_'), $this->clock()),
            $this->clock(),
            new ProblemDetailsResponseFactory(),
            $this->transactions(),
            new HttpMutationPreauthorizer(
                AuthorizationContext::gateway(),
                (new ReflectionClass(ContentService::class))->newInstanceWithoutConstructor(),
                $this->createStub(AccessControlRepository::class),
                new TokenDelegationPreauthorizer(
                    $this->createStub(AccessControlRepository::class),
                    AuthorizationContext::gateway(),
                ),
                $this->rotation($this->createStub(AccessControlRepository::class)),
            ),
        ))->process($request, $handler);
    }

    public function testThrownMutationReleasesItsLeaseAsFailed(): void
    {
        $principal = AuthorizationContext::principal(['content.create']);
        $context = $principal->context(
            \Kumwe\Context\Value\SiteContext::default(),
            \Kumwe\Context\Value\AuthenticationStrength::BearerToken,
            'idempotency-failure-test',
        );
        $database = $this->createMock(Connection::class);
        $database->expects(self::once())->method('insert');
        $database->expects(self::once())->method('executeStatement')->with(
            self::stringContains('DELETE FROM'),
            self::isArray(),
        )->willReturn(1);
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/v1/content')
            ->withAttribute(RequireIdempotencyKeyMiddleware::ATTRIBUTE, IdempotencyKey::fromHeader('stable-key-0002'))
            ->withAttribute(AuthenticatedPrincipal::REQUEST_ATTRIBUTE, $principal)
            ->withAttribute(ExecutionContextAttribute::NAME, $context);
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw new RuntimeException('mutation failed');
            }
        };

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('mutation failed');
        (new PersistentIdempotencyMiddleware(
            new DoctrineIdempotencyLedger($database, new TableNames($database, 'kumwe_'), $this->clock()),
            $this->clock(),
            new ProblemDetailsResponseFactory(),
            $this->transactions(),
            new HttpMutationPreauthorizer(
                AuthorizationContext::gateway(),
                (new ReflectionClass(ContentService::class))->newInstanceWithoutConstructor(),
                $this->createStub(AccessControlRepository::class),
                new TokenDelegationPreauthorizer(
                    $this->createStub(AccessControlRepository::class),
                    AuthorizationContext::gateway(),
                ),
                $this->rotation($this->createStub(AccessControlRepository::class)),
            ),
        ))->process($request, $handler);
    }

    public function testUndelegableTokenRequestCannotReserveIdempotencyRow(): void
    {
        $subjectId = '018f22e2-7c8b-7ab0-8f3a-88e8026bb421';
        $principal = AuthorizationContext::principal(['users.manage']);
        $context = $principal->context(
            \Kumwe\Context\Value\SiteContext::default(),
            \Kumwe\Context\Value\AuthenticationStrength::BearerToken,
            'idempotency-token-delegation-test',
        );
        $repository = $this->createStub(AccessControlRepository::class);
        $repository->method('userIdByEmail')->willReturn($subjectId);
        $repository->method('userGrants')->willReturn([[
            'capability' => 'content.publish',
            'scope_type' => 'global',
            'scope_identifier' => null,
        ]]);
        $database = $this->createMock(Connection::class);
        $database->expects(self::never())->method('insert');
        $database->expects(self::never())->method('fetchAssociative');
        $body = json_encode([
            'email' => 'target@example.test',
            'name' => 'deployment',
            'capabilities' => ['content.publish'],
        ], JSON_THROW_ON_ERROR);
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/v1/tokens')
            ->withBody((new StreamFactory())->createStream($body))
            ->withAttribute(RequireIdempotencyKeyMiddleware::ATTRIBUTE, IdempotencyKey::fromHeader('stable-key-0003'))
            ->withAttribute(AuthenticatedPrincipal::REQUEST_ATTRIBUTE, $principal)
            ->withAttribute(ExecutionContextAttribute::NAME, $context);
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::never())->method('handle');

        $this->expectException(AuthorizationDenied::class);
        (new PersistentIdempotencyMiddleware(
            new DoctrineIdempotencyLedger($database, new TableNames($database, 'kumwe_'), $this->clock()),
            $this->clock(),
            new ProblemDetailsResponseFactory(),
            $this->transactions(),
            new HttpMutationPreauthorizer(
                AuthorizationContext::gateway(),
                (new ReflectionClass(ContentService::class))->newInstanceWithoutConstructor(),
                $repository,
                new TokenDelegationPreauthorizer($repository, AuthorizationContext::gateway()),
                $this->rotation($repository),
            ),
        ))->process($request, $handler);
    }

    private function clock(): ClockInterface
    {
        return new class implements ClockInterface {
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('2026-08-05T12:00:00+00:00');
            }
        };
    }

    private function preauthorizer(AuthorizationGateway $authorization): HttpMutationPreauthorizer
    {
        $repository = $this->createStub(AccessControlRepository::class);
        return new HttpMutationPreauthorizer(
            $authorization,
            (new ReflectionClass(ContentService::class))->newInstanceWithoutConstructor(),
            $repository,
            new TokenDelegationPreauthorizer($repository, $authorization),
            new TokenRotationPreauthorizer(
                $repository,
                $authorization,
                new TokenDelegationPreauthorizer($repository, $authorization),
            ),
        );
    }

    private function rotation(AccessControlRepository $repository): TokenRotationPreauthorizer
    {
        $gateway = AuthorizationContext::gateway();
        return new TokenRotationPreauthorizer(
            $repository,
            $gateway,
            new TokenDelegationPreauthorizer($repository, $gateway),
        );
    }

    private function transactions(): TransactionManager
    {
        return new class implements TransactionManager {
            public function transactional(callable $operation): mixed
            {
                return $operation();
            }

            public function afterCommit(callable $operation): void
            {
                $operation();
            }

            public function afterRollback(callable $operation): void
            {
            }
        };
    }
}
