<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Functional\Http;

use Kumwe\App\Kernel\Container;
use Kumwe\App\Extension\Runtime\RuntimeMaterializationState;
use Kumwe\App\Http\Handler\ApiIndexHandler;
use Kumwe\App\Http\Handler\HomePageHandler;
use Kumwe\App\Http\Handler\LivenessHandler;
use Kumwe\App\Http\Middleware\ExtensionRuntimeGenerationMiddleware;
use Kumwe\App\Kernel\ContainerFactory;
use Kumwe\App\Presentation\Twig\AdministratorTwigEnvironment;
use Kumwe\App\Presentation\Twig\RecoveryAdministratorTwigEnvironment;
use Kumwe\App\Presentation\Twig\SiteTwigEnvironment;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Laminas\Diactoros\ServerRequestFactory;
use Laminas\Stratigility\MiddlewarePipeInterface;
use Mezzio\Application;
use Mezzio\Middleware\LazyLoadingMiddleware;
use Mezzio\Router\RouterInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Server\MiddlewareInterface;
use ReflectionMethod;
use Traversable;

#[CoversClass(ContainerFactory::class)]
#[CoversClass(HomePageHandler::class)]
#[CoversClass(LivenessHandler::class)]
#[CoversClass(ApiIndexHandler::class)]
final class KernelTest extends TestCase
{
    /**
     * Configuration as the application resolves it, read once and reused across this case.
     *
     * @var    Environment|null
     * @since  2.0.0
     */
    private ?Environment $configured = null;

    public function testContainerReturnsOneConfiguredApplication(): void
    {
        $container = (new ContainerFactory())->create(Environment::fromGlobals());

        self::assertSame($container->get(Application::class), $container->get(Application::class));
        self::assertNotSame(
            $container->get(SiteTwigEnvironment::class),
            $container->get(AdministratorTwigEnvironment::class),
        );
        self::assertNotSame(
            $container->get(AdministratorTwigEnvironment::class),
            $container->get(RecoveryAdministratorTwigEnvironment::class),
        );
    }

    public function testPublicHealthAndProtectedBoundaries(): void
    {
        $container = (new ContainerFactory())->create(Environment::fromGlobals());
        $application = $container->get(Application::class);
        $factory = new ServerRequestFactory();

        $live = $application->handle(
            $factory->createServerRequest('GET', 'https://kumwe.test/health/live')->withHeader('Host', 'kumwe.test'),
        );
        $admin = $application->handle(
            $factory->createServerRequest('GET', 'https://kumwe.test/administrator')->withHeader('Host', 'kumwe.test'),
        );
        $unsafeApi = $application->handle(
            $factory->createServerRequest('POST', 'https://kumwe.test/api/v1')->withHeader('Host', 'kumwe.test'),
        );

        self::assertSame(200, $live->getStatusCode());
        self::assertSame(303, $admin->getStatusCode());
        self::assertSame('/administrator/login', $admin->getHeaderLine('Location'));
        self::assertSame(405, $unsafeApi->getStatusCode());
    }

    /**
     * The API root answers its discovery document to an anonymous GET.
     *
     * A client holding nothing but the base URL starts here, so this is also the cheapest proof that the
     * routing table and the middleware in front of the API are intact: the answer is a constant, and it
     * arrives without a credential or a single row being read.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testApiRootAnswersDiscoveryWithoutStorageOrCredentials(): void
    {
        $container = (new ContainerFactory())->create(Environment::fromGlobals());
        $application = $container->get(Application::class);
        $factory = new ServerRequestFactory();

        $response = $application->handle(
            $factory->createServerRequest('GET', 'https://kumwe.test/api/v1')->withHeader('Host', 'kumwe.test'),
        );

        self::assertSame(200, $response->getStatusCode());
        $document = json_decode((string) $response->getBody(), true);
        self::assertIsArray($document);
        self::assertSame(
            ['product' => 'Kumwe App', 'api_version' => 'v1', 'status' => 'available'],
            $document,
        );
    }

    public function testTrustedProxyNormalizationPrecedesHostAndTransportSecurity(): void
    {
        $values = $this->productionValues();
        $values['APP_TRUSTED_PROXIES'] = '10.20.0.0/16';
        $application = (new ContainerFactory())->create(new Environment($values))->get(Application::class);
        $request = (new ServerRequestFactory())->createServerRequest(
            'GET',
            'http://proxy.internal/health/live',
            ['REMOTE_ADDR' => '10.20.0.10'],
        )->withHeader('Forwarded', 'for=203.0.113.50;proto=https;host=kumwe.test');

        $response = $application->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertNotSame('', $response->getHeaderLine('Strict-Transport-Security'));
    }

    public function testUntrustedPeerCannotUseForwardedHostToPassTheHostBoundary(): void
    {
        $values = $this->productionValues();
        $values['APP_TRUSTED_PROXIES'] = '10.20.0.0/16';
        $application = (new ContainerFactory())->create(new Environment($values))->get(Application::class);
        $request = (new ServerRequestFactory())->createServerRequest(
            'GET',
            'http://attacker.test/health/live',
            ['REMOTE_ADDR' => '198.51.100.12'],
        )->withHeader('Forwarded', 'for=203.0.113.50;proto=https;host=kumwe.test');

        $response = $application->handle($request);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('', $response->getHeaderLine('Strict-Transport-Security'));
    }

    public function testRecoveryBootCannotPoisonTheFullProductionRouteCache(): void
    {
        $environment = new Environment($this->productionValues());
        $factory = new ContainerFactory();
        $recovery = $factory->createRecovery($environment);
        $runtime = $factory->create($environment);
        $root = dirname(__DIR__, 3);
        $recoveryCache = $this->routeCacheFile($recovery);
        $runtimeCache = $this->routeCacheFile($runtime);

        self::assertStringStartsWith($root . '/storage/cache/routes-recovery-', $recoveryCache);
        self::assertStringStartsWith($root . '/storage/cache/routes-runtime-', $runtimeCache);
        self::assertNotSame($recoveryCache, $runtimeCache);
        foreach ([$recoveryCache, $runtimeCache] as $cache) {
            if (is_file($cache)) {
                unlink($cache);
            }
        }

        try {
            self::assertInstanceOf(Application::class, $recovery->get(Application::class));
            $requestFactory = new ServerRequestFactory();
            $recoveryRouter = $recovery->get(RouterInterface::class);
            self::assertInstanceOf(RouterInterface::class, $recoveryRouter);
            $health = $recoveryRouter->match(
                $requestFactory->createServerRequest('GET', 'https://kumwe.test/health/live'),
            );
            self::assertTrue($health->isSuccess());
            self::assertFileExists($recoveryCache);
            self::assertFileDoesNotExist($runtimeCache);

            self::assertInstanceOf(Application::class, $runtime->get(Application::class));
            $runtimeRouter = $runtime->get(RouterInterface::class);
            self::assertInstanceOf(RouterInterface::class, $runtimeRouter);
            $portalLogin = $runtimeRouter->match(
                $requestFactory->createServerRequest('GET', 'https://kumwe.test/portal/login'),
            );
            self::assertTrue($portalLogin->isSuccess());
            $route = $portalLogin->getMatchedRoute();
            self::assertNotFalse($route);
            self::assertSame('portal.login', $route->getName());
            self::assertFileExists($runtimeCache);
        } finally {
            foreach ([$recoveryCache, $runtimeCache] as $cache) {
                if (is_file($cache)) {
                    unlink($cache);
                }
            }
        }
    }

    public function testRuntimeRouteCacheChangesWithReleaseAndMaterializedGeneration(): void
    {
        $method = new ReflectionMethod(ContainerFactory::class, 'routeCacheFile');
        $first = new RuntimeMaterializationState('replica-one', 17, str_repeat('a', 64), 'proof', true);
        $next = new RuntimeMaterializationState('replica-one', 18, str_repeat('b', 64), 'proof', true);

        $firstCache = $method->invoke(null, '/app', '2.0.0', true, $first);
        $nextCache = $method->invoke(null, '/app', '2.0.0', true, $next);
        $nextReleaseCache = $method->invoke(null, '/app', '2.0.1', true, $next);
        $firstRecoveryCache = $method->invoke(null, '/app', '2.0.0', false, $first);
        $nextRecoveryCache = $method->invoke(null, '/app', '2.0.0', false, $next);

        self::assertIsString($firstCache);
        self::assertIsString($nextCache);
        self::assertIsString($nextReleaseCache);
        self::assertIsString($firstRecoveryCache);
        self::assertIsString($nextRecoveryCache);
        self::assertNotSame($firstCache, $nextCache);
        self::assertNotSame($nextCache, $nextReleaseCache);
        self::assertSame($firstRecoveryCache, $nextRecoveryCache);
    }

    /**
     * The extension-generation fence is installed only after the full runtime is materialized.
     *
     * Recovery composition must remain able to repair an absent or stale runtime publication, while
     * normal requests must cross the live-generation authority before resident extension code can run.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testExtensionGenerationFenceIsInstalledOnlyInTheFullRuntime(): void
    {
        $factory = new ContainerFactory();
        $environment = new Environment($this->productionValues());

        self::assertNotContains(
            ExtensionRuntimeGenerationMiddleware::class,
            $this->pipelineNames($factory->createRecovery($environment)),
        );
        self::assertContains(
            ExtensionRuntimeGenerationMiddleware::class,
            $this->pipelineNames($factory->create($environment)),
        );
    }

    /**
     * @return array<string, string>
     */
    private function productionValues(): array
    {
        return [
            'APP_ENV' => 'production',
            'APP_DEBUG' => 'false',
            'APP_BASE_URL' => 'https://kumwe.test',
            'APP_TRUSTED_HOSTS' => 'kumwe.test',
            'APP_SECRET' => $this->environment('APP_SECRET', str_repeat('a', 32)),
            'EXTENSION_RUNTIME_SIGNING_KEY' => str_repeat('r', 32),
            'KUMWE_DEPLOYMENT_ID' => 'test-deployment',
            'KUMWE_REPLICA_ID' => 'test-replica',
            'KUMWE_PROCESS_ID' => 'test-process',
            'KUMWE_INSTANCE_ID' => 'test-instance',
            'KUMWE_NATIVE_EXPECTED_TUPLE' => $this->environment(
                'KUMWE_NATIVE_EXPECTED_TUPLE',
                '/usr/local/lib/kumwe-native/native-expected-tuple.json',
            ),
            'DB_DRIVER' => $this->environment('DB_DRIVER', 'pgsql'),
            'DB_HOST' => $this->environment('DB_HOST', '127.0.0.1'),
            'DB_PORT' => $this->environment('DB_PORT', '5432'),
            'DB_NAME' => $this->environment('DB_NAME', 'kumwe_test'),
            'DB_USER' => $this->environment('DB_USER', 'kumwe'),
            'DB_PASSWORD' => $this->environment('DB_PASSWORD', 'kumwe_test'),
            'DB_TABLE_PREFIX' => $this->environment('DB_TABLE_PREFIX', 'kumwe_'),
            'DB_SERVER_VERSION' => $this->environment('DB_SERVER_VERSION', '17'),
            'DB_SSLMODE' => $this->environment('DB_SSLMODE', 'disable'),
            'REDIS_HOST' => $this->environment('REDIS_HOST', '127.0.0.1'),
            'REDIS_PORT' => $this->environment('REDIS_PORT', '6379'),
        ];
    }

    /**
     * Read one configuration value the way the container reads it.
     *
     * A raw `getenv()` sees only the process environment, so an installation that configures through
     * `.env` answered nothing here and these values fell back to a PostgreSQL that need not exist —
     * three of this case's tests then errored on any host without one. `Environment` sees the dotenv
     * file as well, and is the one class the standard permits to read configuration at all.
     *
     * @param   string  $name      Configuration key to read.
     * @param   string  $fallback  Value to use where the installation configures none.
     *
     * @return  string  The configured value, or the fallback.
     *
     * @since   2.0.0
     */
    private function environment(string $name, string $fallback): string
    {
        $this->configured ??= Environment::fromGlobals();

        return $this->configured->optionalString($name, $fallback) ?? $fallback;
    }

    private function routeCacheFile(Container $container): string
    {
        $config = $container->get('config');
        self::assertIsArray($config);
        $router = $config['router'] ?? null;
        self::assertIsArray($router);
        $fastRoute = $router['fastroute'] ?? null;
        self::assertIsArray($fastRoute);
        $cache = $fastRoute['cache_file'] ?? null;
        self::assertIsString($cache);

        return $cache;
    }

    /**
     * Flatten the configured application pipeline into concrete and lazy service names.
     *
     * @param   Container  $container  Prepared full or recovery runtime container.
     *
     * @return  list<string>  Middleware names in execution order.
     *
     * @since   2.0.0
     */
    private function pipelineNames(Container $container): array
    {
        self::assertInstanceOf(Application::class, $container->get(Application::class));
        $pipeline = $container->get(MiddlewarePipeInterface::class);
        self::assertInstanceOf(MiddlewareInterface::class, $pipeline);

        return $this->middlewareNames($pipeline);
    }

    /**
     * Flatten one middleware pipeline into the service names it will resolve.
     *
     * @param   MiddlewareInterface  $middleware  Prepared middleware or nested pipeline.
     *
     * @return  list<string>  Concrete class and lazy service names in execution order.
     *
     * @since   2.0.0
     */
    private function middlewareNames(MiddlewareInterface $middleware): array
    {
        if ($middleware instanceof LazyLoadingMiddleware) {
            return [$middleware->middlewareName];
        }
        if ($middleware instanceof Traversable) {
            $names = [];
            foreach ($middleware as $nested) {
                self::assertInstanceOf(MiddlewareInterface::class, $nested);
                array_push($names, ...$this->middlewareNames($nested));
            }

            return $names;
        }

        return [$middleware::class];
    }
}
