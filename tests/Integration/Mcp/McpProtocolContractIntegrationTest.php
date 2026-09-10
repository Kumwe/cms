<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\Mcp;

use Kumwe\App\Application\Authorization\ExecutionContextAttribute;
use Kumwe\App\Delivery\Http\Mcp\McpHttpHandler;
use Kumwe\App\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\App\Infrastructure\Mcp\KumweMcpServerFactory;
use Kumwe\App\Infrastructure\Mcp\McpCapabilityCatalog;
use Kumwe\App\Infrastructure\Mcp\McpMachineContract;
use Kumwe\App\Infrastructure\Mcp\McpToolErrorEnvelope;
use Kumwe\App\Infrastructure\Mcp\McpToolErrorMapper;
use Kumwe\App\Infrastructure\Mcp\McpToolReferenceHandler;
use Kumwe\App\Tests\Support\AuthorizationContext;
use Kumwe\App\Tests\Support\McpHandlersFixture;
use Kumwe\Context\Value\ExecutionContext;
use Laminas\Diactoros\ResponseFactory;
use Laminas\Diactoros\ServerRequest;
use Laminas\Diactoros\StreamFactory;
use Laminas\Diactoros\Uri;
use Mcp\Server\Session\InMemorySessionStore;
use Mcp\Server\Transport\StreamableHttpTransport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Stringable;

/**
 * Exercises the retained MCP surface through actual JSON-RPC and Streamable HTTP SDK boundaries.
 *
 * @since  2.0.0
 */
#[CoversClass(KumweMcpServerFactory::class)]
#[CoversClass(McpMachineContract::class)]
#[CoversClass(McpToolErrorEnvelope::class)]
#[CoversClass(McpToolErrorMapper::class)]
#[CoversClass(McpToolReferenceHandler::class)]
final class McpProtocolContractIntegrationTest extends TestCase
{
    /**
     * Prove negotiation, pagination, registration, resource/prompt access and both error paths end to end.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRetainedGenerationIsTheLiveProtocolSurfaceAndErrorsStaySeparated(): void
    {
        $catalog = new McpCapabilityCatalog();
        $logger = new RecordingMcpLogger();
        $streams = new StreamFactory();
        $handler = new McpHttpHandler(
            new KumweMcpServerFactory(
                $catalog,
                sessions: new InMemorySessionStore(),
                logger: $logger,
            ),
            McpHandlersFixture::create($catalog),
            new ResponseFactory(),
            $streams,
            $logger,
            1_048_576,
            ['kumwe.test'],
        );
        $context = AuthorizationContext::human(['content.read']);

        [$initialize, $session] = $this->initialize($handler, $streams, $context);
        self::assertSame('2025-11-25', $initialize['result']['protocolVersion']);
        self::assertSame('Kumwe App', $initialize['result']['serverInfo']['name']);
        self::assertSame([
            'prompts' => [],
            'resources' => [],
            'tools' => [],
        ], $initialize['result']['capabilities']);

        $initialized = $this->exchange(
            $handler,
            $streams,
            $context,
            'notifications/initialized',
            [],
            null,
            $session,
        );
        self::assertSame(202, $initialized['status']);

        $firstToolsExchange = $this->exchange(
            $handler,
            $streams,
            $context,
            'tools/list',
            [],
            2,
            $session,
        );
        $firstTools = $this->successfulResult($firstToolsExchange);
        self::assertCount(50, $firstTools['tools']);
        self::assertIsString($firstTools['nextCursor']);
        $secondToolsExchange = $this->exchange(
            $handler,
            $streams,
            $context,
            'tools/list',
            ['cursor' => $firstTools['nextCursor']],
            3,
            $session,
        );
        $secondTools = $this->successfulResult($secondToolsExchange);
        self::assertCount(25, $secondTools['tools']);
        self::assertArrayNotHasKey('nextCursor', $secondTools);

        $fixtureBytes = (string) file_get_contents(
            dirname(__DIR__, 3) . '/docs/machine-contract/mcp-v1.json',
        );
        $fixture = json_decode(
            $fixtureBytes,
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $listedTools = [...$firstTools['tools'], ...$secondTools['tools']];
        self::assertSame(array_column($fixture['surface']['tools'], 'name'), array_column($listedTools, 'name'));
        foreach ($fixture['surface']['tools'] as $offset => $expected) {
            unset($expected['handler'], $expected['capability'], $expected['risk'], $expected['alternative']);
            self::assertEquals($expected, $listedTools[$offset], $expected['name']);
        }
        $fixtureWire = json_decode($fixtureBytes, false, 512, JSON_THROW_ON_ERROR);
        $firstToolsWire = json_decode($firstToolsExchange['body'], false, 512, JSON_THROW_ON_ERROR);
        $secondToolsWire = json_decode($secondToolsExchange['body'], false, 512, JSON_THROW_ON_ERROR);
        self::assertIsObject($fixtureWire);
        self::assertIsObject($firstToolsWire);
        self::assertIsObject($secondToolsWire);
        $expectedWireTools = $fixtureWire->surface->tools;
        foreach ($expectedWireTools as $expectedWireTool) {
            unset(
                $expectedWireTool->handler,
                $expectedWireTool->capability,
                $expectedWireTool->risk,
                $expectedWireTool->alternative,
            );
        }
        self::assertEquals(
            $expectedWireTools,
            [...$firstToolsWire->result->tools, ...$secondToolsWire->result->tools],
        );

        $resources = $this->successfulResult($this->exchange(
            $handler,
            $streams,
            $context,
            'resources/list',
            [],
            4,
            $session,
        ));
        $expectedResource = $fixture['surface']['resources'][0];
        unset($expectedResource['handler']);
        self::assertEquals([$expectedResource], $resources['resources']);

        $prompts = $this->successfulResult($this->exchange(
            $handler,
            $streams,
            $context,
            'prompts/list',
            [],
            5,
            $session,
        ));
        $expectedPrompt = $fixture['surface']['prompts'][0];
        unset($expectedPrompt['handler']);
        self::assertEquals([$expectedPrompt], $prompts['prompts']);

        $resource = $this->successfulResult($this->exchange(
            $handler,
            $streams,
            $context,
            'resources/read',
            ['uri' => 'kumwe://capabilities'],
            6,
            $session,
        ));
        $summary = json_decode($resource['contents'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(array_column($fixture['surface']['tools'], 'name'), $summary['tools']);

        $prompt = $this->successfulResult($this->exchange(
            $handler,
            $streams,
            $context,
            'prompts/get',
            ['name' => 'kumwe_site_review', 'arguments' => ['focus' => 'seo']],
            7,
            $session,
        ));
        self::assertSame('user', $prompt['messages'][0]['role']);
        self::assertStringContainsString('seo focus', $prompt['messages'][0]['content']['text']);

        $discovery = $this->successfulResult($this->exchange(
            $handler,
            $streams,
            $context,
            'tools/call',
            ['name' => 'kumwe_discover', 'arguments' => []],
            8,
            $session,
        ));
        self::assertFalse($discovery['isError']);
        self::assertSame(75, count($discovery['structuredContent']['tools']));

        $refusal = $this->successfulResult($this->exchange(
            $handler,
            $streams,
            $context,
            'tools/call',
            ['name' => 'kumwe_settings_get', 'arguments' => []],
            9,
            $session,
        ));
        self::assertTrue($refusal['isError']);
        self::assertSame([
            'schema' => 'kumwe.mcp.tool-error.v1',
            'code' => 'authorization.denied',
            'message' => 'The requested operation is not permitted.',
            'retryable' => false,
        ], json_decode($refusal['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR));

        $defect = $this->exchange(
            $handler,
            $streams,
            $context,
            'tools/call',
            ['name' => 'kumwe_content_list', 'arguments' => []],
            10,
            $session,
        );
        self::assertSame(-32603, $defect['json']['error']['code']);
        self::assertSame('Error while executing tool', $defect['json']['error']['message']);
        self::assertTrue($logger->hasMessage('Unhandled error during tool execution'));
    }

    /**
     * Open one authenticated MCP session and return its negotiated response and UUID.
     *
     * @param   McpHttpHandler    $handler  Application MCP endpoint.
     * @param   StreamFactory     $streams  PSR-17 body factory.
     * @param   ExecutionContext  $context  Authenticated request context.
     *
     * @return  array{array<string, mixed>, string}  Initialize response and issued session identifier.
     *
     * @since   2.0.0
     */
    private function initialize(
        McpHttpHandler $handler,
        StreamFactory $streams,
        ExecutionContext $context,
    ): array {
        $response = $this->exchange(
            $handler,
            $streams,
            $context,
            'initialize',
            [
                'protocolVersion' => '2025-11-25',
                'capabilities' => [],
                'clientInfo' => ['name' => 'Kumwe contract test', 'version' => '1.0.0'],
            ],
            1,
            null,
        );
        self::assertSame(200, $response['status']);
        self::assertIsString($response['session']);
        self::assertNotSame('', $response['session']);

        return [$response['json'], $response['session']];
    }

    /**
     * Send one protocol message through the real HTTP handler and decode its JSON response.
     *
     * @param   McpHttpHandler       $handler  Application MCP endpoint.
     * @param   StreamFactory        $streams  PSR-17 body factory.
     * @param   ExecutionContext     $context  Authenticated actor and site.
     * @param   string               $method   JSON-RPC method.
     * @param   array<string, mixed>  $params  JSON-RPC parameters.
     * @param   ?int                 $id      Request identity, null for a notification.
     * @param   ?string              $session  Existing MCP session, null for initialize.
     *
     * @return  array{status: int, session: string, body: string, json: array<string, mixed>}
     *          HTTP and protocol response.
     *
     * @since   2.0.0
     */
    private function exchange(
        McpHttpHandler $handler,
        StreamFactory $streams,
        ExecutionContext $context,
        string $method,
        array $params,
        ?int $id,
        ?string $session,
    ): array {
        $message = ['jsonrpc' => '2.0'];
        if ($id !== null) {
            $message['id'] = $id;
        }
        $message['method'] = $method;
        if ($params !== []) {
            $message['params'] = $params;
        }
        $request = (new ServerRequest())
            ->withMethod('POST')
            ->withUri(new Uri('https://kumwe.test/mcp'))
            ->withHeader('Host', 'kumwe.test')
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Accept', 'application/json, text/event-stream')
            ->withBody($streams->createStream(json_encode($message, JSON_THROW_ON_ERROR)))
            ->withAttribute(AuthenticatedPrincipal::REQUEST_ATTRIBUTE, $context->principal())
            ->withAttribute(ExecutionContextAttribute::NAME, $context);
        if ($session !== null) {
            $request = $request
                ->withHeader(StreamableHttpTransport::SESSION_HEADER, $session)
                ->withHeader(StreamableHttpTransport::PROTOCOL_VERSION_HEADER, '2025-11-25');
        }

        $response = $handler->handle($request);
        $body = (string) $response->getBody();

        return [
            'status' => $response->getStatusCode(),
            'session' => $response->getHeaderLine(StreamableHttpTransport::SESSION_HEADER),
            'body' => $body,
            'json' => $body === '' ? [] : json_decode($body, true, 512, JSON_THROW_ON_ERROR),
        ];
    }

    /**
     * Extract a successful JSON-RPC result while keeping assertion failures close to the request.
     *
     * @param   array{status: int, session: string, body: string, json: array<string, mixed>}  $response
     *          Exchange response.
     *
     * @return  array<string, mixed>  JSON-RPC result document.
     *
     * @since   2.0.0
     */
    private function successfulResult(array $response): array
    {
        self::assertSame(200, $response['status']);
        self::assertArrayHasKey(
            'result',
            $response['json'],
            json_encode($response['json'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );

        return $response['json']['result'];
    }
}

/**
 * Minimal in-memory PSR-3 sink used to prove unexpected SDK failures are recorded.
 *
 * @since  2.0.0
 */
final class RecordingMcpLogger extends AbstractLogger
{
    /**
     * Messages recorded by the server and transport.
     *
     * @var    list<string>
     *
     * @since  2.0.0
     */
    private array $messages = [];

    /**
     * Retain only the bounded message template; exception context is asserted at the SDK boundary itself.
     *
     * @param   mixed                 $level    PSR-3 level.
     * @param   string|Stringable     $message  Stable log message.
     * @param   array<string, mixed>  $context  Structured log context.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->messages[] = (string) $message;
    }

    /**
     * Report whether an exact SDK message was recorded.
     *
     * @param   string  $message  Message template to find.
     *
     * @return  bool  True when the sink observed it.
     *
     * @since   2.0.0
     */
    public function hasMessage(string $message): bool
    {
        return in_array($message, $this->messages, true);
    }
}
