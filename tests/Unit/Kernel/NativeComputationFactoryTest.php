<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Kernel;

use InvalidArgumentException;
use JsonException;
use Kumwe\App\Kernel\NativeComputationFactory;
use Kumwe\App\Kernel\Container;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\CanonicalJson\CanonicalEncoder;
use Kumwe\Computation\ExecutionRefused;
use Kumwe\Computation\Compiler;
use Kumwe\Computation\Executor;
use Kumwe\Computation\NativeAdapter;
use Kumwe\Computation\NativeCanonicalEncoder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(NativeComputationFactory::class)]
/**
 * Pin the host's native provisioning boundary without duplicating the package's canonical corpus.
 *
 * @since  2.0.0
 */
final class NativeComputationFactoryTest extends TestCase
{
    /**
     * The provisioned tuple admits the real extension through the public canonical contract.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testProvisionedRuntimeImplementsTheCanonicalContract(): void
    {
        $encoder = (new NativeComputationFactory())->create(Environment::fromGlobals());

        self::assertInstanceOf(CanonicalEncoder::class, $encoder);
        self::assertSame('{"a":1,"b":2}', $encoder->encode(['b' => 2, 'a' => 1]));
    }

    /**
     * Production composition shares one compiled-plan owner and exposes the package canonical contract.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testProductionBindingsShareNativeServicesBehindTheirPublicContracts(): void
    {
        $container = new Container();
        (new NativeComputationFactory())->register($container, Environment::fromGlobals());

        self::assertInstanceOf(NativeAdapter::class, $container->get(Compiler::class));
        self::assertSame($container->get(Compiler::class), $container->get(Executor::class));
        self::assertSame($container->get(Compiler::class), $container->get(NativeAdapter::class));
        self::assertSame($container->get(CanonicalEncoder::class), $container->get(NativeCanonicalEncoder::class));
        self::assertSame($container->get(CanonicalEncoder::class), $container->get(CanonicalEncoder::class));
    }

    /**
     * A host with no independently recorded build identity refuses admission.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testMissingProvisioningRecordIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new NativeComputationFactory())->create(new Environment([
            'KUMWE_NATIVE_EXPECTED_TUPLE' => __DIR__ . '/absent-native-tuple.json',
        ]));
    }

    /**
     * Malformed deployment metadata is refused before any compatibility decision.
     *
     * @param   string                   $bytes      Invalid provisioning document.
     * @param   class-string<\Throwable>  $exception  Expected host configuration refusal.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    #[DataProvider('malformedRecords')]
    public function testMalformedProvisioningRecordIsRefused(string $bytes, string $exception): void
    {
        $path = tempnam(sys_get_temp_dir(), 'kumwe-native-');
        self::assertIsString($path);
        file_put_contents($path, $bytes);
        try {
            $this->expectException($exception);
            (new NativeComputationFactory())->create(new Environment(['KUMWE_NATIVE_EXPECTED_TUPLE' => $path]));
        } finally {
            unlink($path);
        }
    }

    /**
     * Invalid host metadata shapes exercise the configuration boundary, not package semantics.
     *
     * @return  iterable<string, array{string, class-string<\Throwable>}>
     *
     * @since   2.0.0
     */
    public static function malformedRecords(): iterable
    {
        yield 'invalid JSON' => ['{', JsonException::class];
        yield 'scalar root' => ['null', InvalidArgumentException::class];
        yield 'list root' => ['[]', InvalidArgumentException::class];
        yield 'missing capabilities' => ['{"extension_version":"1.0.1"}', InvalidArgumentException::class];
        yield 'list capabilities' => ['{"capabilities":[1]}', InvalidArgumentException::class];
        yield 'numeric capability field' => ['{"capabilities":{"0":1,"named":2}}', InvalidArgumentException::class];
        yield 'missing identity' => ['{"capabilities":{"named":2}}', InvalidArgumentException::class];
    }

    /**
     * An expected build identity cannot be replaced by whatever the loaded extension advertises.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testMismatchedIndependentBuildIdentityIsRefused(): void
    {
        $source = Environment::fromGlobals()->string(
            'KUMWE_NATIVE_EXPECTED_TUPLE',
            '/usr/local/lib/kumwe-native/native-expected-tuple.json',
        );
        $bytes = file_get_contents($source);
        self::assertIsString($bytes);
        $tuple = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
        $tuple['binding_build_digest'] = str_repeat('0', 64);
        $path = tempnam(sys_get_temp_dir(), 'kumwe-native-');
        self::assertIsString($path);
        file_put_contents($path, json_encode($tuple, JSON_THROW_ON_ERROR));
        try {
            $this->expectException(ExecutionRefused::class);
            (new NativeComputationFactory())->create(new Environment(['KUMWE_NATIVE_EXPECTED_TUPLE' => $path]));
        } finally {
            unlink($path);
        }
    }
}
