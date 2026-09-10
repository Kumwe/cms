<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Studio\Application\Media;

use Kumwe\App\Studio\Application\Host\StudioHostSessionSnapshot;
use Kumwe\App\Studio\Application\Media\StudioMediaCursorCodec;
use Kumwe\App\Studio\Application\Media\StudioMediaHostPort;
use Kumwe\App\Studio\Application\Media\StudioMediaOperations;
use Kumwe\App\Studio\Application\Media\StudioMediaPortRejected;
use Kumwe\App\Studio\Domain\Media\StudioExternalUrlPolicy;
use Kumwe\App\Studio\Domain\Media\StudioMediaPolicyRejected;
use Kumwe\App\Studio\Domain\Media\StudioMediaUploadPolicy;
use Kumwe\App\Studio\Domain\Media\StudioMediaUploadRequest;
use Kumwe\App\Tests\Support\StudioProducerRequest;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Producer\Canonical\CanonicalJson;
use Kumwe\Producer\Error\HostRefusal;
use Kumwe\Producer\Wire\HostResult;
use Kumwe\Producer\Wire\OperationRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use stdClass;

/**
 * Replays Producer's pinned media vectors through the App's direct media port implementation.
 *
 * @since  2.0.0
 */
#[CoversClass(StudioMediaHostPort::class)]
#[CoversClass(StudioMediaCursorCodec::class)]
#[CoversClass(StudioMediaPortRejected::class)]
final class StudioMediaProducerPortTest extends TestCase
{
    /**
     * Discover exactly the ten pinned Producer media vectors.
     *
     * @return iterable<string, array{string}>
     *
     * @since  2.0.0
     */
    public static function vectors(): iterable
    {
        $producerRoot = dirname((string) (new ReflectionClass(OperationRegistry::class))->getFileName(), 3);
        $paths = glob($producerRoot . '/resources/studio-contract/testkit/vectors/host/media.*.json');
        self::assertIsArray($paths);
        self::assertCount(10, $paths);
        sort($paths, SORT_STRING);
        foreach ($paths as $path) {
            $vector = self::decode($path);
            self::assertIsString($vector->id);
            yield $vector->id => [$path];
        }
    }

    /**
     * Apply each language-neutral vector through the canonical Producer method directly.
     *
     * @param   string  $path  Absolute Producer vector path.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    #[DataProvider('vectors')]
    public function testPinnedProducerVector(string $path): void
    {
        $vector = self::decode($path);
        self::assertIsString($vector->id);
        self::assertIsString($vector->operation);
        self::assertInstanceOf(stdClass::class, $vector->argument);
        self::assertInstanceOf(stdClass::class, $vector->context);
        self::assertInstanceOf(stdClass::class, $vector->expect);
        self::assertIsString($vector->context->operationId);
        $arguments = self::wrapper($vector->operation, $vector->argument);
        $request = StudioProducerRequest::authorized($vector->context->operationId, $arguments);
        $port = (new StudioMediaHostPort($this->operations()))->forRequest($request->authority);
        $result = null;
        $error = null;
        try {
            $result = self::invoke($port, $vector->operation, $request->arguments(), $request->context());
        } catch (HostRefusal $refusal) {
            $error = $refusal->error();
        }

        self::assertIsString($vector->expect->outcome);
        if ($vector->expect->outcome === 'result') {
            self::assertInstanceOf(HostResult::class, $result, $vector->id);
            self::assertNull($error, $vector->id);
            if ($vector->expect->value === 'null') {
                self::assertNull($result->value, $vector->id);
            } else {
                self::assertInstanceOf(stdClass::class, $result->value, $vector->id);
            }
            $encoded = CanonicalJson::stringify($result->toDocument());
        } else {
            self::assertNotNull($error, $vector->id);
            self::assertIsString($vector->expect->category);
            self::assertSame($vector->expect->category, $error->category(), $vector->id);
            $encoded = $error->toCanonicalJson();
        }
        $forbiddenValues = $vector->expect->messageMustNotContain ?? [];
        self::assertIsArray($forbiddenValues);
        foreach ($forbiddenValues as $forbidden) {
            self::assertIsString($forbidden);
            self::assertStringNotContainsString($forbidden, $encoded, $vector->id);
        }
    }

    /**
     * Malformed operation wrappers are refused before invoking media custody.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function testMalformedArgumentsAreRefusedAtTheDirectPort(): void
    {
        $cases = [
            'invalid upload descriptor' => [
                'studio.operation/media.authorize-upload',
                'authorize-upload',
                (object) ['request' => (object) ['filename' => 'one.png']],
                'studio.media/upload-failed',
            ],
            'extra wrapper member' => [
                'studio.operation/media.get',
                'get',
                (object) ['assetId' => 'assets/one', 'extra' => true],
                'studio.host/invalid-arguments',
            ],
            'non-object query' => [
                'studio.operation/media.list',
                'list',
                (object) ['query' => 'all'],
                'studio.host/invalid-arguments',
            ],
            'invalid stable identity' => [
                'studio.operation/media.get',
                'get',
                (object) ['assetId' => 'bad identity'],
                'studio.host/invalid-arguments',
            ],
        ];

        foreach ($cases as $case => [$capability, $operation, $arguments, $code]) {
            $request = StudioProducerRequest::authorized($capability, $arguments);
            $port = (new StudioMediaHostPort($this->operations()))->forRequest($request->authority);
            try {
                self::invoke($port, $operation, $request->arguments(), $request->context());
                self::fail('The malformed media request unexpectedly succeeded: ' . $case);
            } catch (HostRefusal $refusal) {
                self::assertSame($code, $refusal->error()->diagnostics()[0]->code(), $case);
            }
        }
    }

    /**
     * Invoke the one direct Producer media method named by a pinned vector.
     *
     * @param   StudioMediaHostPort                  $port       Bound App media port.
     * @param   string                               $operation  Canonical media operation name.
     * @param   mixed                                $arguments  Parsed operation arguments.
     * @param   \Kumwe\Producer\Wire\RequestContext  $context    Parsed Producer request context.
     *
     * @return  HostResult  Canonical Producer result.
     *
     * @since  2.0.0
     */
    private static function invoke(
        StudioMediaHostPort $port,
        string $operation,
        mixed $arguments,
        \Kumwe\Producer\Wire\RequestContext $context,
    ): HostResult {
        return match ($operation) {
            'abort-upload' => $port->abortUpload($arguments, $context),
            'authorize-upload' => $port->authorizeUpload($arguments, $context),
            'complete-upload' => $port->completeUpload($arguments, $context),
            'get' => $port->get($arguments, $context),
            'import-external' => $port->importExternal($arguments, $context),
            'list' => $port->list($arguments, $context),
            'upload-status' => $port->uploadStatus($arguments, $context),
            default => self::fail('The Producer media vector names an unknown operation.'),
        };
    }

    /**
     * Supply deterministic semantic media operations behind the direct port.
     *
     * @return  StudioMediaOperations  Operations needed by the canonical vector corpus.
     *
     * @since  2.0.0
     */
    private function operations(): StudioMediaOperations
    {
        /** @var StudioMediaOperations&Stub $operations */
        $operations = self::createStub(StudioMediaOperations::class);
        $operations->method('authorizeUpload')->willReturnCallback(
            static function (
                ExecutionContext $context,
                StudioHostSessionSnapshot $snapshot,
                StudioMediaUploadRequest $request,
            ): stdClass {
                unset($context, $snapshot);
                $policy = new StudioMediaUploadPolicy(['image/jpeg'], 16_777_216, false);
                try {
                    $plan = $policy->authorize($request);
                } catch (StudioMediaPolicyRejected $failure) {
                    throw new StudioMediaPortRejected(
                        $failure->failureCode === 'studio.media/upload-too-large'
                            ? 'limit-exceeded'
                            : 'validation-failed',
                        $failure->failureCode,
                    );
                }

                return (object) [
                    'expiresAt' => '2030-01-01T00:00:00.000Z',
                    'method' => 'PUT',
                    'plan' => $plan->document(),
                    'uploadId' => 'uploads/vector-grant',
                    'url' => 'https://uploads.example.invalid/vector-grant',
                ];
            },
        );
        $operations->method('abortUpload')->willThrowException(
            new StudioMediaPortRejected('not-found', 'studio.media/upload-not-found'),
        );
        $operations->method('completeUpload')->willThrowException(
            new StudioMediaPortRejected('not-found', 'studio.media/upload-not-found'),
        );
        $operations->method('get')->willReturn(null);
        $operations->method('list')->willReturnCallback(static function (
            ExecutionContext $context,
            stdClass $query,
        ): stdClass {
            unset($context);
            if (!is_int($query->limit ?? null) || $query->limit < 1 || $query->limit > 100) {
                throw new StudioMediaPortRejected('invalid-request', 'studio.media/query-invalid');
            }
            if (property_exists($query, 'cursor')) {
                self::assertIsString($query->cursor);
                (new StudioMediaCursorCodec(str_repeat('k', 32)))->decode(
                    $query->cursor,
                    'default',
                    hash('sha256', '{"mediaTypes":[],"search":""}'),
                );
            }

            return (object) ['assets' => []];
        });
        $operations->method('importExternal')->willReturnCallback(static function (
            ExecutionContext $context,
            string $url,
        ): stdClass {
            unset($context);
            if (!(new StudioExternalUrlPolicy())->validate($url)->acceptedUrl()) {
                throw new StudioMediaPortRejected('validation-failed', 'studio.media/external-url-refused');
            }

            return (object) ['id' => 'assets/vector', 'revision' => 'r1', 'state' => 'ready'];
        });
        $operations->method('uploadStatus')->willThrowException(
            new StudioMediaPortRejected('not-found', 'studio.media/asset-not-found'),
        );

        return $operations;
    }

    /**
     * Project raw vector arguments into canonical Producer media wrappers.
     *
     * @param   string    $operation  Canonical media operation name.
     * @param   stdClass  $argument   Raw language-neutral vector argument.
     *
     * @return  stdClass  Exact Producer arguments.
     *
     * @since  2.0.0
     */
    private static function wrapper(string $operation, stdClass $argument): stdClass
    {
        return match ($operation) {
            'authorize-upload' => (object) ['request' => clone $argument],
            'list' => (object) ['query' => clone $argument],
            default => clone $argument,
        };
    }

    /**
     * Decode one pinned Producer media vector.
     *
     * @param   string  $path  Absolute fixture path.
     *
     * @return  stdClass  Decoded vector.
     *
     * @since  2.0.0
     */
    private static function decode(string $path): stdClass
    {
        $document = json_decode((string) file_get_contents($path), false, 32, JSON_THROW_ON_ERROR);
        self::assertInstanceOf(stdClass::class, $document);

        return $document;
    }
}
