<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\OpenApi;

use Kumwe\App\BusinessSurface\Application\BusinessSurfaceCatalog;
use Kumwe\App\OpenApi\Application\CompiledOpenApiContract;
use Kumwe\App\OpenApi\Application\OpenApiContractCache;
use Kumwe\App\OpenApi\Application\OpenApiContractCompiler;
use Kumwe\App\OpenApi\Application\OpenApiContractLimits;
use Kumwe\App\OpenApi\Application\OpenApiContractService;
use Kumwe\App\OpenApi\Application\OpenApiContractUnavailable;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Tests\Support\NeutralBusinessFixture;
use Kumwe\App\Tests\Support\TestKernelFactory;
use Kumwe\Context\Value\AuthenticationStrength;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Ramsey\Uuid\Uuid;

/**
 * Proves definition publication invalidates caller-specific OpenAPI generations without stale reuse.
 *
 * @since  2.0.0
 */
#[CoversClass(OpenApiContractService::class)]
#[CoversClass(OpenApiContractCompiler::class)]
final class OpenApiContractGenerationIntegrationTest extends TestCase
{
    /**
     * Compile an exact new generation after installing a newly visible definition.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testDefinitionInstallationProducesAndCachesOnlyNewExactGeneration(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $administrator = TestKernelFactory::administratorContext($container);
        $principal = $administrator->principal();
        self::assertNotNull($principal);
        $api = $principal->context(
            $administrator->site(),
            AuthenticationStrength::BearerToken,
            'openapi-generation-' . bin2hex(random_bytes(8)),
        );
        $contracts = $container->get(OpenApiContractService::class);
        self::assertInstanceOf(OpenApiContractService::class, $contracts);
        $before = $contracts->contract($api);

        $suffix = strtolower(substr(str_replace('-', '', Uuid::uuid7()->toString()), -12));
        $document = NeutralBusinessFixture::document($suffix, Uuid::uuid7()->toString());
        $definition = NeutralBusinessFixture::install($container, $administrator, $document);
        $after = $contracts->contract($api);

        self::assertNotSame($before->generation, $after->generation);
        self::assertNotSame($before->checksum, $after->checksum);
        self::assertStringContainsString(
            'Business_' . str_replace(['.', '-'], '_', $definition->handle) . '_Record',
            $after->json,
        );
        self::assertEquals($after, $contracts->contract($api));
    }

    /**
     * Fail a large current compile before any cache publication can occur.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testOversizedCurrentContractFailsBeforeCachePublication(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $administrator = TestKernelFactory::administratorContext($container);
        $principal = $administrator->principal();
        self::assertNotNull($principal);
        $api = $principal->context(
            $administrator->site(),
            AuthenticationStrength::BearerToken,
            'openapi-large-' . bin2hex(random_bytes(8)),
        );
        $catalog = $container->get(BusinessSurfaceCatalog::class);
        self::assertInstanceOf(BusinessSurfaceCatalog::class, $catalog);
        $path = dirname(__DIR__, 3) . '/api/openapi/kumwe-v1.json';
        $json = file_get_contents($path);
        self::assertIsString($json);
        /** @var array<string, mixed> $core */
        $core = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $core['info']['description'] = str_repeat('x', OpenApiContractLimits::MAX_CONTRACT_BYTES);
        $cache = new class implements OpenApiContractCache {
            /**
             * Whether an oversized compiled value reached publication.
             *
             * @var    bool
             * @since  2.0.0
             */
            public bool $putCalled = false;

            /**
             * Force compilation for every exact generation.
             *
             * @param   string  $generation  Exact requested generation.
             *
             * @return  ?CompiledOpenApiContract  Always null.
             *
             * @since   2.0.0
             */
            public function get(string $generation): ?CompiledOpenApiContract
            {
                return null;
            }

            /**
             * Record an unsafe publication attempt.
             *
             * @param   CompiledOpenApiContract  $contract  Candidate verified value.
             *
             * @return  void
             *
             * @since   2.0.0
             */
            public function put(CompiledOpenApiContract $contract): void
            {
                $this->putCalled = true;
            }
        };
        $service = new OpenApiContractService(
            $core,
            $catalog,
            new OpenApiContractCompiler(),
            $cache,
            new NullLogger(),
        );

        try {
            $service->contract($api);
            self::fail('An oversized current contract was served.');
        } catch (OpenApiContractUnavailable $exception) {
            self::assertStringContainsString('safe byte bound', $exception->getPrevious()?->getMessage() ?? '');
        }
        self::assertFalse($cache->putCalled);
    }
}
