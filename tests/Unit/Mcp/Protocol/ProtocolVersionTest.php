<?php

declare(strict_types=1);

namespace Tests\Unit\Mcp\Protocol;

use PHPUnit\Framework\TestCase;
use Whity\Core\CoreVersion;
use Whity\Core\Request;
use Whity\Mcp\JsonRpc\Dispatcher;
use Whity\Mcp\JsonRpc\ErrorCode;
use Whity\Mcp\JsonRpc\McpException;
use Whity\Mcp\JsonRpc\MethodHandler;
use Whity\Mcp\Lifecycle\DiscoverHandler;
use Whity\Mcp\Lifecycle\InitializeHandler;
use Whity\Mcp\Lifecycle\PingHandler;
use Whity\Mcp\Transport\McpTransportHandler;

/**
 * MCP protocol versions 2025-03-26 through 2026-07-28 on one endpoint (WHIT-708).
 * Expected shapes are written from the spec pages, not from McpProtocol.
 */
final class ProtocolVersionTest extends TestCase
{
    private const MODERN = '2026-07-28';

    // ── Legacy: the initialize handshake ───────────────────────────────────────

    public function testInitializeAnswersWithTheClientsVersionWhenSpoken(): void
    {
        foreach (['2025-03-26', '2025-06-18', '2025-11-25'] as $version) {
            $r = $this->call('initialize', ['protocolVersion' => $version]);
            self::assertSame($version, $r['result']['protocolVersion']);
        }
    }

    public function testInitializeOffersTheNewestLegacyVersionOtherwise(): void
    {
        self::assertSame('2025-11-25', $this->call('initialize', ['protocolVersion' => '2024-11-05'])['result']['protocolVersion']);
        self::assertSame('2025-11-25', $this->call('initialize', [])['result']['protocolVersion']);
    }

    public function testServerInfoReportsTheRealVersion(): void
    {
        self::assertSame(
            ['name' => 'whity-core', 'version' => CoreVersion::VERSION],
            $this->call('initialize', ['protocolVersion' => '2025-06-18'])['result']['serverInfo'],
        );
    }

    // ── Legacy: what each version changes ──────────────────────────────────────

    public function testToolsGetATopLevelTitleFrom20250618(): void
    {
        $old = $this->call('tools/list', [], ['mcp-protocol-version' => '2025-03-26'])['result']['tools'][0];
        $new = $this->call('tools/list', [], ['mcp-protocol-version' => '2025-06-18'])['result']['tools'][0];

        self::assertArrayNotHasKey('title', $old);
        self::assertSame('List widgets', $new['title']);
        self::assertSame('List widgets', $new['annotations']['title'], 'the annotation title stays for older readers');
    }

    public function testJsonObjectResultsBecomeStructuredContentFrom20250618(): void
    {
        $old = $this->call('tools/call', ['name' => 'object'], ['mcp-protocol-version' => '2025-03-26'])['result'];
        $new = $this->call('tools/call', ['name' => 'object'], ['mcp-protocol-version' => '2025-06-18'])['result'];

        self::assertArrayNotHasKey('structuredContent', $old);
        self::assertSame(['items' => [1, 2]], $new['structuredContent']);
        self::assertSame('{"items":[1,2]}', $new['content'][0]['text'], 'the text block stays, as the spec asks');
    }

    public function testOnlyObjectsAreStructuredBefore2026AndErrorsNever(): void
    {
        $list  = $this->call('tools/call', ['name' => 'list'], ['mcp-protocol-version' => '2025-11-25'])['result'];
        $error = $this->call('tools/call', ['name' => 'failing'], ['mcp-protocol-version' => '2025-11-25'])['result'];
        $text  = $this->call('tools/call', ['name' => 'text'], ['mcp-protocol-version' => '2025-11-25'])['result'];

        self::assertArrayNotHasKey('structuredContent', $list);
        self::assertArrayNotHasKey('structuredContent', $error);
        self::assertArrayNotHasKey('structuredContent', $text);
    }

    public function testAnUnknownVersionHeaderIsRefusedWithTheSupportedList(): void
    {
        [$r, $status] = $this->post('tools/list', [], ['MCP-Protocol-Version' => '1999-01-01']);

        self::assertSame(400, $status);
        self::assertSame(ErrorCode::UNSUPPORTED_PROTOCOL_VERSION, $r['error']['code']);
        self::assertSame(['2026-07-28', '2025-11-25', '2025-06-18', '2025-03-26'], $r['error']['data']['supported']);
        self::assertSame('1999-01-01', $r['error']['data']['requested']);
    }

    public function testBatchesWorkFor20250326ButNotLater(): void
    {
        $batch = '[{"jsonrpc":"2.0","id":1,"method":"ping"},{"jsonrpc":"2.0","id":2,"method":"ping"}]';

        self::assertCount(2, json_decode($this->dispatcher()->handle($batch, null), true));

        $dispatcher = $this->dispatcher();
        $r = json_decode($dispatcher->handle($batch, null, ['mcp-protocol-version' => '2025-06-18']), true);
        self::assertSame(ErrorCode::INVALID_REQUEST, $r['error']['code']);
        self::assertSame(400, $dispatcher->httpStatus());
    }

    // ── Modern: stateless, versioned per request ───────────────────────────────

    public function testAModernRequestNeedsNoHandshake(): void
    {
        [$r, $status] = $this->post('tools/list', $this->modernParams(), $this->modernHeaders('tools/list'));

        self::assertSame(200, $status);
        self::assertSame('complete', $r['result']['resultType']);
        self::assertSame(['name' => 'whity-core', 'version' => CoreVersion::VERSION], $r['result']['_meta']['io.modelcontextprotocol/serverInfo']);
        self::assertSame(60000, $r['result']['ttlMs']);
        self::assertSame('private', $r['result']['cacheScope'], 'the list depends on who asks');
        self::assertSame('List widgets', $r['result']['tools'][0]['title']);
    }

    public function testModernResultsMayStructureAnyJson(): void
    {
        $params = $this->modernParams(['name' => 'list']);
        [$r] = $this->post('tools/call', $params, $this->modernHeaders('tools/call', 'list'));

        self::assertSame([1, 2, 3], $r['result']['structuredContent']);
        self::assertSame('complete', $r['result']['resultType']);
    }

    public function testRemovedMethodsAreUnknownToAModernRequest(): void
    {
        foreach (['initialize', 'ping'] as $method) {
            [$r, $status] = $this->post($method, $this->modernParams(), $this->modernHeaders($method));
            self::assertSame(404, $status, $method);
            self::assertSame(ErrorCode::METHOD_NOT_FOUND, $r['error']['code'], $method);
        }
        [, $status] = $this->post('no/such', $this->modernParams(), $this->modernHeaders('no/such'));
        self::assertSame(404, $status);
    }

    public function testServerDiscoverListsVersionsCapabilitiesAndIdentity(): void
    {
        [$r] = $this->post('server/discover', $this->modernParams(), $this->modernHeaders('server/discover'));

        self::assertSame(['2026-07-28', '2025-11-25', '2025-06-18', '2025-03-26'], $r['result']['supportedVersions']);
        self::assertEqualsCanonicalizing(['tools', 'resources', 'prompts'], array_keys($r['result']['capabilities']));
        self::assertArrayNotHasKey('listChanged', $r['result']['capabilities']['tools'], 'no subscriptions/listen, so no change announcements');
        self::assertSame('public', $r['result']['cacheScope']);
        self::assertSame('complete', $r['result']['resultType']);
    }

    /** @return array<string, array{array<string, string>, string}> */
    public static function badHeaders(): array
    {
        return [
            'no version header'       => [['Mcp-Method' => 'tools/call', 'Mcp-Name' => 'object'], 'MCP-Protocol-Version'],
            'version header differs'  => [['MCP-Protocol-Version' => '2025-11-25', 'Mcp-Method' => 'tools/call', 'Mcp-Name' => 'object'], 'MCP-Protocol-Version'],
            'no method header'        => [['MCP-Protocol-Version' => self::MODERN, 'Mcp-Name' => 'object'], 'Mcp-Method'],
            'method header differs'   => [['MCP-Protocol-Version' => self::MODERN, 'Mcp-Method' => 'tools/list', 'Mcp-Name' => 'object'], 'Mcp-Method'],
            'no name header'          => [['MCP-Protocol-Version' => self::MODERN, 'Mcp-Method' => 'tools/call'], 'Mcp-Name'],
            'name header differs'     => [['MCP-Protocol-Version' => self::MODERN, 'Mcp-Method' => 'tools/call', 'Mcp-Name' => 'list'], 'Mcp-Name'],
        ];
    }

    /** @param array<string, string> $headers */
    #[\PHPUnit\Framework\Attributes\DataProvider('badHeaders')]
    public function testHeadersMustAgreeWithTheBody(array $headers, string $named): void
    {
        [$r, $status] = $this->post('tools/call', $this->modernParams(['name' => 'object']), $headers);

        self::assertSame(400, $status);
        self::assertSame(ErrorCode::HEADER_MISMATCH, $r['error']['code']);
        self::assertStringContainsString($named, $r['error']['message']);
    }

    public function testABase64EncodedNameHeaderIsDecodedBeforeComparing(): void
    {
        $headers = $this->modernHeaders('tools/call', '=?base64?' . base64_encode('object') . '?=');
        [$r, $status] = $this->post('tools/call', $this->modernParams(['name' => 'object']), $headers);

        self::assertSame(200, $status);
        self::assertSame(['items' => [1, 2]], $r['result']['structuredContent']);
    }

    public function testAModernVersionHeaderWithoutMetaIsAMismatch(): void
    {
        [$r, $status] = $this->post('tools/list', [], ['MCP-Protocol-Version' => self::MODERN, 'Mcp-Method' => 'tools/list']);

        self::assertSame(400, $status);
        self::assertSame(ErrorCode::HEADER_MISMATCH, $r['error']['code']);
    }

    public function testALegacyVersionCannotBeDeclaredPerRequest(): void
    {
        $params = ['_meta' => ['io.modelcontextprotocol/protocolVersion' => '2025-06-18']];
        [$r, $status] = $this->post('tools/list', $params, ['MCP-Protocol-Version' => '2025-06-18', 'Mcp-Method' => 'tools/list']);

        self::assertSame(400, $status);
        self::assertSame(ErrorCode::UNSUPPORTED_PROTOCOL_VERSION, $r['error']['code']);
    }

    public function testResourceNotFoundBecomesInvalidParamsFor2026Only(): void
    {
        $legacy = $this->call('resources/read', ['uri' => 'x://missing'], ['mcp-protocol-version' => '2025-11-25']);
        [$modern] = $this->post('resources/read', $this->modernParams(['uri' => 'x://missing']), $this->modernHeaders('resources/read', 'x://missing'));

        self::assertSame(ErrorCode::RESOURCE_NOT_FOUND, $legacy['error']['code']);
        self::assertSame(ErrorCode::INVALID_PARAMS, $modern['error']['code']);
    }

    public function testANotificationIsAccepted(): void
    {
        $transport = new McpTransportHandler($this->dispatcher());
        $response = $transport->handlePost(new Request('POST', '/mcp', ['Content-Type' => 'application/json'], '{"jsonrpc":"2.0","method":"ping"}'));

        self::assertSame(202, $response->getStatusCode());
        self::assertSame('', $response->getBody());
    }

    public function testTheVersionIsNeverCarriedBetweenRequests(): void
    {
        $dispatcher = $this->dispatcher();
        $dispatcher->handle($this->body('tools/list', $this->modernParams()), null, array_change_key_case($this->modernHeaders('tools/list')));
        $next = json_decode($dispatcher->handle($this->body('tools/list', []), null), true);

        self::assertArrayNotHasKey('resultType', $next['result'], 'the next request, with no version, is 2025-03-26 again');
        self::assertSame(200, $dispatcher->httpStatus());
    }

    // ── Fixtures ───────────────────────────────────────────────────────────────

    private function dispatcher(): Dispatcher
    {
        $tools = new class implements MethodHandler {
            public function __invoke(?array $params, ?string $bearerToken): mixed
            {
                return ['tools' => [[
                    'name'        => 'get_api_widgets',
                    'description' => 'List widgets',
                    'inputSchema' => ['type' => 'object'],
                    'annotations' => ['title' => 'List widgets', 'readOnlyHint' => true],
                ]]];
            }
        };
        $call = new class implements MethodHandler {
            public function __invoke(?array $params, ?string $bearerToken): mixed
            {
                return match ($params['name'] ?? null) {
                    'object'  => ['content' => [['type' => 'text', 'text' => '{"items":[1,2]}']]],
                    'list'    => ['content' => [['type' => 'text', 'text' => '[1,2,3]']]],
                    'failing' => ['isError' => true, 'content' => [['type' => 'text', 'text' => '{"error":"no"}']]],
                    default   => ['content' => [['type' => 'text', 'text' => 'plain words']]],
                };
            }
        };
        $read = new class implements MethodHandler {
            public function __invoke(?array $params, ?string $bearerToken): mixed
            {
                throw new McpException(ErrorCode::RESOURCE_NOT_FOUND, 'Resource not found');
            }
        };

        return new Dispatcher([
            'initialize'      => new InitializeHandler(),
            'server/discover' => new DiscoverHandler(),
            'ping'            => new PingHandler(),
            'tools/list'      => $tools,
            'tools/call'      => $call,
            'resources/read'  => $read,
        ]);
    }

    /**
     * @param array<string, mixed>  $params
     * @param array<string, string> $headers lower-cased
     * @return array<string, mixed>
     */
    private function call(string $method, array $params, array $headers = []): array
    {
        $decoded = json_decode($this->dispatcher()->handle($this->body($method, $params), null, $headers), true);
        self::assertIsArray($decoded);
        return $decoded;
    }

    /**
     * Through the HTTP transport, so the status is the one a client sees.
     *
     * @param array<string, mixed>  $params
     * @param array<string, string> $headers
     * @return array{0: array<string, mixed>, 1: int}
     */
    private function post(string $method, array $params, array $headers): array
    {
        $transport = new McpTransportHandler($this->dispatcher());
        $response  = $transport->handlePost(new Request('POST', '/mcp', ['Content-Type' => 'application/json'] + $headers, $this->body($method, $params)));
        $decoded   = json_decode($response->getBody(), true);
        self::assertIsArray($decoded, $response->getBody());
        return [$decoded, $response->getStatusCode()];
    }

    /** @param array<string, mixed> $params */
    private function body(string $method, array $params): string
    {
        return (string) json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => (object) $params]);
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function modernParams(array $extra = []): array
    {
        return $extra + ['_meta' => [
            'io.modelcontextprotocol/protocolVersion'    => self::MODERN,
            'io.modelcontextprotocol/clientInfo'         => ['name' => 'test', 'version' => '1'],
            'io.modelcontextprotocol/clientCapabilities' => (object) [],
        ]];
    }

    /** @return array<string, string> */
    private function modernHeaders(string $method, ?string $name = null): array
    {
        return ['MCP-Protocol-Version' => self::MODERN, 'Mcp-Method' => $method] + ($name !== null ? ['Mcp-Name' => $name] : []);
    }
}
