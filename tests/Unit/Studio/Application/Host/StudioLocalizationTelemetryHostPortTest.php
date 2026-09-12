<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Studio\Application\Host;

use Kumwe\Localization\Application\ActiveLocale;
use Kumwe\Localization\Application\SupportedLocales;
use Kumwe\Localization\Application\TranslationScope;
use Kumwe\Localization\Domain\LocaleTag;
use Kumwe\App\Localization\Infrastructure\ArrayMessageOverrideRepository;
use Kumwe\App\Localization\Infrastructure\CompiledMessageCatalogueRepository;
use Kumwe\App\Studio\Application\Host\StudioLocalizationHostPort;
use Kumwe\App\Studio\Application\Host\StudioTelemetryHostPort;
use Kumwe\App\Tests\Support\StudioProducerRequest;
use Kumwe\Producer\Error\HostRefusal;
use Kumwe\Producer\Wire\HostResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use stdClass;

/**
 * Replays the exact AP-7 localization and telemetry host vectors.
 *
 * Covered IDs: `vector.host-vector.localization.messages.unknown-locale`,
 * `vector.host-vector.telemetry.emit.accepted`, and
 * `vector.host-vector.telemetry.emit.non-primitive`.
 *
 * @since  2.0.0
 */
#[CoversClass(StudioLocalizationHostPort::class)]
#[CoversClass(StudioTelemetryHostPort::class)]
final class StudioLocalizationTelemetryHostPortTest extends TestCase
{
    /**
     * The exact corpus resolves through the effective chain and every malformed request is refused.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function testLocalizationReturnsExactCorpusAndUnknownLocaleIsNotFound(): void
    {
        $supported = new SupportedLocales();
        $active = new ActiveLocale($supported);
        $active->begin(LocaleTag::fromString('en-GB'), new TranslationScope('default'));
        $port = new StudioLocalizationHostPort(
            new CompiledMessageCatalogueRepository(dirname(__DIR__, 5) . '/resources/localization/compiled'),
            new ArrayMessageOverrideRepository(site: [
                'default' => ['en-GB' => ['core.studio.shell.undo' => 'Undo composition']],
            ]),
            $active,
            $supported,
        );

        $result = self::localization(
            $port,
            (object) ['locale' => 'en-GB', 'namespaces' => ['studio.shell']],
        );

        self::assertCount(160, get_object_vars($result->value));
        self::assertSame('Undo composition', $result->value->{'studio.shell/undo'});
        self::assertPortRefused(
            static fn () => self::localization(
                $port,
                (object) ['locale' => 'en-GB', 'namespaces' => ['studio.shell']],
                expectedRevision: 'revision/not-allowed',
            ),
            'invalid-request',
            'studio.host/invalid-context',
        );
        self::assertPortRefused(
            static fn () => self::localization(
                $port,
                null,
            ),
            'invalid-request',
            'studio.host/invalid-arguments',
        );
        self::assertPortRefused(
            static fn () => self::localization(
                $port,
                (object) ['locale' => 'en-GB', 'namespaces' => []],
            ),
            'invalid-request',
            'studio.host/invalid-arguments',
        );
        self::assertPortRefused(
            static fn () => self::localization(
                $port,
                (object) ['locale' => 'en-GB', 'namespaces' => ['studio/shell']],
            ),
            'invalid-request',
            'studio.host/invalid-arguments',
        );
        self::assertPortRefused(
            static fn () => self::localization(
                $port,
                (object) ['locale' => 'not_locale!', 'namespaces' => ['studio.shell']],
            ),
            'not-found',
            'studio.localization/locale-not-found',
        );
        self::assertPortRefused(
            static fn () => self::localization(
                $port,
                (object) ['locale' => 'zz', 'namespaces' => ['studio.shell']],
            ),
            'not-found',
            'studio.localization/locale-not-found',
        );
    }

    /**
     * Primitive attributes are accepted while every unbounded or malformed event is refused.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function testTelemetryAcceptsPrimitiveVectorAndRejectsNestedVector(): void
    {
        $logger = new StudioTelemetryTestLogger();
        $port = new StudioTelemetryHostPort($logger);
        $result = self::telemetry(
            $port,
            (object) ['event' => (object) [
                'attributes' => (object) ['surface' => 'canvas'],
                'name' => 'studio.telemetry/vector',
            ]],
            'idempotency/telemetry-vector',
        );

        self::assertNull($result->value);
        self::assertSame(['surface'], $logger->records[0]['context']['attribute_names']);
        self::assertArrayNotHasKey('attributes', $logger->records[0]['context']);
        self::assertNotContains('canvas', $logger->records[0]['context']);
        self::assertPortRefused(
            static fn () => self::telemetry(
                $port,
                null,
            ),
            'invalid-request',
            'studio.host/invalid-arguments',
        );
        self::assertPortRefused(
            static fn () => self::telemetry(
                $port,
                (object) ['event' => 'not-an-object'],
            ),
            'invalid-request',
            'studio.host/invalid-arguments',
        );
        self::assertPortRefused(
            static fn () => self::telemetry(
                $port,
                (object) ['event' => (object) [
                    'extra' => true,
                    'name' => 'studio.telemetry/vector',
                ]],
            ),
            'invalid-request',
            'studio.host/invalid-arguments',
        );
        self::assertPortRefused(
            static fn () => self::telemetry(
                $port,
                (object) ['event' => (object) ['name' => 'invalid-event-name']],
            ),
            'invalid-request',
            'studio.telemetry/invalid-event',
        );
        self::assertPortRefused(
            static fn () => self::telemetry(
                $port,
                (object) ['event' => (object) [
                    'attributes' => [],
                    'name' => 'studio.telemetry/vector',
                ]],
            ),
            'invalid-request',
            'studio.telemetry/invalid-attributes',
        );
        self::assertPortRefused(
            static fn () => self::telemetry(
                $port,
                (object) ['event' => (object) [
                    'attributes' => (object) ['surface' => (object) ['nested' => true]],
                    'name' => 'studio.telemetry/vector',
                ]],
            ),
            'invalid-request',
            'studio.telemetry/invalid-attributes',
        );
        self::assertPortRefused(
            static fn () => self::telemetry(
                $port,
                (object) ['event' => (object) [
                    'attributes' => (object) ['surface' => str_repeat('x', 201)],
                    'name' => 'studio.telemetry/vector',
                ]],
            ),
            'invalid-request',
            'studio.telemetry/invalid-attributes',
        );
        $largeAttributes = new stdClass();
        for ($index = 0; $index < 32; $index++) {
            $largeAttributes->{sprintf('attribute_%02d', $index)} = str_repeat('x', 200);
        }
        self::assertPortRefused(
            static fn () => self::telemetry(
                $port,
                (object) ['event' => (object) [
                    'attributes' => $largeAttributes,
                    'name' => 'studio.telemetry/vector',
                ]],
            ),
            'limit-exceeded',
            'studio.telemetry/event-too-large',
        );
    }

    /**
     * Execute one localization call through a real authorized Producer request scope.
     *
     * @param   StudioLocalizationHostPort  $port              App-owned direct Producer port.
     * @param   mixed                       $arguments         Candidate operation arguments.
     * @param   string|null                 $expectedRevision Optional invalid revision under test.
     *
     * @return  HostResult  Canonical Producer result.
     *
     * @since  2.0.0
     */
    private static function localization(
        StudioLocalizationHostPort $port,
        mixed $arguments,
        ?string $expectedRevision = null,
    ): HostResult {
        $request = StudioProducerRequest::authorized(
            'studio.operation/localization.messages',
            $arguments,
            $expectedRevision,
        );

        return $port->forRequest($request->authority)->messages($request->arguments(), $request->context());
    }

    /**
     * Execute one telemetry call through a real authorized Producer request scope.
     *
     * @param   StudioTelemetryHostPort  $port            App-owned direct Producer port.
     * @param   mixed                    $arguments       Candidate operation arguments.
     * @param   string|null              $idempotencyKey Optional accepted mutation replay coordinate.
     *
     * @return  HostResult  Canonical Producer result.
     *
     * @since  2.0.0
     */
    private static function telemetry(
        StudioTelemetryHostPort $port,
        mixed $arguments,
        ?string $idempotencyKey = null,
    ): HostResult {
        $request = StudioProducerRequest::authorized(
            'studio.operation/telemetry.emit',
            $arguments,
            idempotencyKey: $idempotencyKey,
        );

        return $port->forRequest($request->authority)->emit($request->arguments(), $request->context());
    }

    /**
     * Assert one host-port refusal without allowing the next rejection vector to be skipped.
     *
     * @param   callable(): mixed  $operation  Host operation expected to fail closed.
     * @param   string             $category   Expected canonical refusal category.
     * @param   string             $code       Expected canonical diagnostic code.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    private static function assertPortRefused(callable $operation, string $category, string $code): void
    {
        try {
            $operation();
            self::fail('The malformed host-port request unexpectedly succeeded.');
        } catch (HostRefusal $refused) {
            self::assertSame($category, $refused->error()->category());
            self::assertSame($code, $refused->error()->diagnostics()[0]->code());
        }
    }
}

/**
 * Captures telemetry records without a production transport.
 *
 * @since  2.0.0
 */
final class StudioTelemetryTestLogger extends AbstractLogger
{
    /**
     * Captured structured log records.
     *
     * @var    list<array{message: string, context: array<string, mixed>}>
     * @since  2.0.0
     */
    public array $records = [];

    /**
     * Capture one structured log record for assertion.
     *
     * @param   mixed                 $level    PSR log level.
     * @param   string|\Stringable    $message  Structured log message.
     * @param   array<string, mixed>  $context  Safe structured context.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        unset($level);
        $this->records[] = ['message' => (string) $message, 'context' => $context];
    }
}
