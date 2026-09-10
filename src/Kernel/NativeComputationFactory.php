<?php

declare(strict_types=1);

namespace Kumwe\App\Kernel;

use InvalidArgumentException;
use JsonException;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\CanonicalJson\CanonicalEncoder;
use Kumwe\Computation\CapabilitySet;
use Kumwe\Computation\Compiler;
use Kumwe\Computation\Executor;
use Kumwe\Computation\NativeAdapter;
use Kumwe\Computation\NativeCanonicalEncoder;
use Kumwe\Computation\NativeCompatibility;
use Kumwe\Engine\Runtime;

/**
 * Admit the installed native build against the tuple recorded independently during provisioning.
 *
 * This host boundary reads deployment configuration only. The package owns tuple compatibility and
 * canonical semantics; a missing or incompatible native installation refuses boot without a fallback.
 *
 * @since  2.0.0
 */
final readonly class NativeComputationFactory
{
    /**
     * Create the canonical service from the admitted deployment runtime.
     *
     * @param   Environment  $environment  Allow-listed deployment values.
     *
     * @return  NativeCanonicalEncoder  Package-owned encoder backed by the admitted native extension.
     *
     * @since   2.0.0
     */
    public function create(Environment $environment): NativeCanonicalEncoder
    {
        return new NativeCanonicalEncoder(new Runtime(), $this->compatibility($environment));
    }

    /**
     * Bind the admitted native runtime and shared package services in the host container.
     *
     * @param   Container    $container    Host composition container.
     * @param   Environment  $environment  Allow-listed deployment values.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function register(Container $container, Environment $environment): void
    {
        $compatibility = $this->compatibility($environment);
        $runtime = new Runtime();
        $container->share(NativeCompatibility::class, $compatibility, true);
        $container->share(Runtime::class, $runtime, true);
        $container->share(NativeAdapter::class, new NativeAdapter($runtime, $compatibility), true);
        $container->alias(Compiler::class, NativeAdapter::class);
        $container->alias(Executor::class, NativeAdapter::class);
        $container->share(NativeCanonicalEncoder::class, new NativeCanonicalEncoder($runtime, $compatibility), true);
        $container->alias(CanonicalEncoder::class, NativeCanonicalEncoder::class);
    }

    /**
     * Read the expected tuple generated from pinned source and build metadata, never runtime output.
     *
     * @param   Environment  $environment  Allow-listed deployment values.
     *
     * @return  NativeCompatibility  Exact independently recorded deployment identity.
     *
     * @throws  InvalidArgumentException  When provisioning metadata is absent or malformed.
     * @throws  JsonException  When provisioning metadata is not valid JSON.
     *
     * @since   2.0.0
     */
    public function compatibility(Environment $environment): NativeCompatibility
    {
        $path = $environment->string(
            'KUMWE_NATIVE_EXPECTED_TUPLE',
            '/usr/local/lib/kumwe-native/native-expected-tuple.json',
        );
        if (!is_file($path) || !is_readable($path)) {
            throw new InvalidArgumentException('The independently recorded native runtime tuple is not readable.');
        }
        $bytes = file_get_contents($path);
        if ($bytes === false) {
            throw new InvalidArgumentException('The independently recorded native runtime tuple could not be read.');
        }
        $tuple = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($tuple) || array_is_list($tuple)) {
            throw new InvalidArgumentException('The native runtime tuple must be a JSON object.');
        }
        $capabilities = $tuple['capabilities'] ?? null;
        if (!is_array($capabilities) || array_is_list($capabilities)) {
            throw new InvalidArgumentException('The native runtime capabilities must be a JSON object.');
        }
        $namedCapabilities = [];
        foreach ($capabilities as $key => $value) {
            if (!is_string($key)) {
                throw new InvalidArgumentException('The native runtime capabilities require named fields.');
            }
            $namedCapabilities[$key] = $value;
        }
        foreach (
            [
            'extension_version', 'embedded_engine_commit', 'embedded_source_sha256', 'binding_build_digest',
            ] as $field
        ) {
            if (!is_string($tuple[$field] ?? null)) {
                throw new InvalidArgumentException('The native runtime tuple is missing a required identity field.');
            }
        }

        return new NativeCompatibility(
            CapabilitySet::fromArray($namedCapabilities),
            $tuple['extension_version'],
            $tuple['embedded_engine_commit'],
            $tuple['embedded_source_sha256'],
            $tuple['binding_build_digest'],
        );
    }
}
