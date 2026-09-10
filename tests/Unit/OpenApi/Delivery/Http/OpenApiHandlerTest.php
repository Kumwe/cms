<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\OpenApi\Delivery\Http;

use Kumwe\App\Application\Authorization\ExecutionContextAttribute;
use Kumwe\App\Delivery\Http\Api\ProblemDetailsResponseFactory;
use Kumwe\App\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\App\OpenApi\Application\CompiledOpenApiContract;
use Kumwe\App\OpenApi\Application\OpenApiContractProvider;
use Kumwe\App\OpenApi\Application\OpenApiContractUnavailable;
use Kumwe\App\OpenApi\Delivery\Http\OpenApiHandler;
use Kumwe\App\Tests\Support\AuthorizationContext;
use Kumwe\Context\Value\AuthenticationStrength;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Context\Value\SiteContext;
use Laminas\Diactoros\ServerRequestFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(OpenApiHandler::class)]
/**
 * Proves the public contract endpoint serves exact bytes and fails closed without stale output.
 *
 * @since  2.0.0
 */
final class OpenApiHandlerTest extends TestCase
{
    /**
     * Serve the exact verified contract and preserve its generation identity on conditional reads.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testServesOnlyExactVerifiedGenerationWithStrongConditionalIdentity(): void
    {
        $generation = str_repeat('a', 64);
        $json = sprintf(
            "{\"openapi\":\"3.1.0\",\"x-kumwe-business-generation\":\"%s\"}\n",
            $generation,
        );
        $contract = new CompiledOpenApiContract(
            $generation,
            hash('sha256', $json),
            $json,
        );
        $provider = new class ($contract) implements OpenApiContractProvider {
            /**
             * Retain the exact verified test value.
             *
             * @param  CompiledOpenApiContract  $contract  Contract every request receives.
             *
             * @since  2.0.0
             */
            public function __construct(private CompiledOpenApiContract $contract)
            {
            }

            /**
             * Return the exact configured current generation.
             *
             * @param   ExecutionContext  $context  Authenticated request context.
             *
             * @return  CompiledOpenApiContract  Configured contract.
             *
             * @since   2.0.0
             */
            public function contract(ExecutionContext $context): CompiledOpenApiContract
            {
                return $this->contract;
            }
        };
        $handler = new OpenApiHandler($provider, new ProblemDetailsResponseFactory());

        $response = $handler->handle($this->request());
        self::assertSame(200, $response->getStatusCode());
        self::assertSame($json, (string) $response->getBody());
        self::assertSame($contract->generation, $response->getHeaderLine('X-Kumwe-Contract-Generation'));
        self::assertSame('"' . $contract->checksum . '"', $response->getHeaderLine('ETag'));

        $conditional = $handler->handle($this->request()->withHeader('If-None-Match', '"' . $contract->checksum . '"'));
        self::assertSame(304, $conditional->getStatusCode());
        self::assertSame('', (string) $conditional->getBody());
        self::assertSame($contract->generation, $conditional->getHeaderLine('X-Kumwe-Contract-Generation'));
    }

    /**
     * Map an unexpected current-generation invariant failure to a stable no-store problem.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testUnavailableCurrentGenerationReturnsNoStore503WithoutContractBytes(): void
    {
        $provider = new class implements OpenApiContractProvider {
            /**
             * Refuse every request as though runtime compilation failed.
             *
             * @param   ExecutionContext  $context  Authenticated request context.
             *
             * @return  CompiledOpenApiContract  Never returned.
             *
             * @throws  OpenApiContractUnavailable  Always.
             *
             * @since   2.0.0
             */
            public function contract(ExecutionContext $context): CompiledOpenApiContract
            {
                throw new OpenApiContractUnavailable();
            }
        };

        $response = (new OpenApiHandler($provider, new ProblemDetailsResponseFactory()))->handle($this->request());
        /** @var array<string, mixed> $problem */
        $problem = json_decode((string) $response->getBody(), true, 8, JSON_THROW_ON_ERROR);

        self::assertSame(503, $response->getStatusCode());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame('30', $response->getHeaderLine('Retry-After'));
        self::assertSame('application/problem+json', $response->getHeaderLine('Content-Type'));
        self::assertSame('urn:kumwe:problem:openapi-contract-unavailable', $problem['type']);
        self::assertArrayNotHasKey('openapi', $problem);
        self::assertFalse($response->hasHeader('ETag'));
        self::assertFalse($response->hasHeader('X-Kumwe-Contract-Generation'));
    }

    /**
     * Build an API request carrying matching trusted context and principal attributes.
     *
     * @return  \Psr\Http\Message\ServerRequestInterface  Authenticated OpenAPI request.
     *
     * @since   2.0.0
     */
    private function request(): \Psr\Http\Message\ServerRequestInterface
    {
        $principal = AuthorizationContext::principal([]);
        $context = $principal->context(
            SiteContext::default(),
            AuthenticationStrength::BearerToken,
            'openapi-handler-test',
        );

        return (new ServerRequestFactory())
            ->createServerRequest('GET', 'https://kumwe.test/api/v1/openapi.json')
            ->withAttribute(AuthenticatedPrincipal::REQUEST_ATTRIBUTE, $principal)
            ->withAttribute(ExecutionContextAttribute::NAME, $context);
    }
}
