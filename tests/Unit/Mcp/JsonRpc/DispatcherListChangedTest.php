<?php

declare(strict_types=1);

namespace Tests\Unit\Mcp\JsonRpc;

use PHPUnit\Framework\TestCase;
use Whity\Auth\TokenValidator;
use Whity\Core\Router;
use Whity\Core\Store\ArraySharedStore;
use Whity\Core\Tenant\TenantContext;
use Whity\Mcp\Auth\McpPrincipal;
use Whity\Mcp\JsonRpc\Dispatcher;
use Whity\Mcp\Lifecycle\InitializeHandler;
use Whity\Mcp\Lifecycle\PingHandler;
use Whity\Mcp\Notifications\CatalogSignature;
use Whity\Mcp\Notifications\ListChangedNotifier;
use Whity\Mcp\Prompts\PromptRegistry;
use Whity\Mcp\Resources\ResourceDeriver;
use Whity\Mcp\Tools\ToolDeriver;
use Whity\Sdk\Http\Response as SdkResponse;

/**
 * Dispatcher side of the `list_changed` signal (#952).
 *
 * The dispatcher's only job here is to say WHICH client made the call, because
 * that is the one thing the notifier cannot work out for itself. The awkward
 * part is that the dispatcher is a FrankenPHP worker singleton and the transport
 * reads the answer after handle() has returned, so the client identity outlives
 * the call that produced it — these tests pin that it never outlives the NEXT
 * one.
 */
final class DispatcherListChangedTest extends TestCase
{
    private Router $router;
    private PromptRegistry $prompts;
    private ArraySharedStore $store;
    private CatalogSignature $signature;

    protected function setUp(): void
    {
        ToolDeriver::clearCache();
        $this->router    = new Router('');
        $this->prompts   = new PromptRegistry();
        $this->store     = new ArraySharedStore();
        $this->signature = new CatalogSignature(
            new ToolDeriver([], [], $this->router),
            new ResourceDeriver([], $this->router),
            $this->prompts,
        );
    }

    protected function tearDown(): void
    {
        ToolDeriver::clearCache();
        TenantContext::reset();
    }

    public function testAuthenticatedCallIsOwedTheCurrentCatalogue(): void
    {
        $dispatcher = $this->dispatcher('jti-one');

        $dispatcher->handle('{"jsonrpc":"2.0","method":"ping","id":1}', 'a-token');

        self::assertNotSame([], $dispatcher->drainNotifications());
    }

    public function testAnUnrelatedCallEmitsNothingWhenTheCatalogueHasNotMoved(): void
    {
        $dispatcher = $this->dispatcher('jti-one');

        $dispatcher->handle('{"jsonrpc":"2.0","method":"initialize","id":1}', 'a-token');
        $dispatcher->drainNotifications();

        $dispatcher->handle('{"jsonrpc":"2.0","method":"ping","id":2}', 'a-token');

        self::assertSame([], $dispatcher->drainNotifications());
    }

    public function testInitializeMarksTheClientCurrentInsteadOfAnnouncing(): void
    {
        $dispatcher = $this->dispatcher('jti-one');

        $dispatcher->handle('{"jsonrpc":"2.0","method":"initialize","id":1}', 'a-token');

        self::assertSame(
            [],
            $dispatcher->drainNotifications(),
            'a client mid-handshake is about to fetch every list anyway',
        );
    }

    public function testCatalogueChangeIsAnnouncedOnTheNextCall(): void
    {
        $dispatcher = $this->dispatcher('jti-one');
        $dispatcher->handle('{"jsonrpc":"2.0","method":"initialize","id":1}', 'a-token');
        $dispatcher->drainNotifications();

        $this->addRoute();

        $dispatcher->handle('{"jsonrpc":"2.0","method":"ping","id":2}', 'a-token');
        $frames = $dispatcher->drainNotifications();

        self::assertNotSame([], $frames);
        self::assertContains(
            'notifications/tools/list_changed',
            array_map(
                static function (string $frame): mixed {
                    $decoded = json_decode($frame, true);

                    return is_array($decoded) ? ($decoded['method'] ?? null) : null;
                },
                $frames,
            ),
        );
    }

    public function testUnauthenticatedCallIsOwedNothing(): void
    {
        $validator = $this->createMock(TokenValidator::class);
        $validator->method('validateBearerForMcp')->willReturn(null);

        $dispatcher = new Dispatcher(
            ['ping' => new PingHandler()],
            $validator,
            null,
            null,
            $this->notifier(),
        );

        $dispatcher->handle('{"jsonrpc":"2.0","method":"ping","id":1}', 'bad-token');

        self::assertSame(
            [],
            $dispatcher->drainNotifications(),
            'an unauthenticated caller must not make the server write notification bookkeeping',
        );
    }

    public function testDrainIsOneShot(): void
    {
        $dispatcher = $this->dispatcher('jti-one');
        $dispatcher->handle('{"jsonrpc":"2.0","method":"ping","id":1}', 'a-token');

        self::assertNotSame([], $dispatcher->drainNotifications());
        self::assertSame([], $dispatcher->drainNotifications());
    }

    /**
     * The worker-singleton hazard: without clearing the recorded client at the
     * top of handle(), a request the transport never drained would hand its
     * client identity to whoever called next.
     */
    public function testAnUndrainedClientIdentityDoesNotLeakIntoTheNextRequest(): void
    {
        $principals = [
            'token-a' => $this->principal('jti-a'),
            'token-b' => $this->principal('jti-b'),
        ];
        $validator = $this->createMock(TokenValidator::class);
        $validator->method('validateBearerForMcp')
            ->willReturnCallback(static fn (string $t): ?McpPrincipal => $principals[$t] ?? null);

        $dispatcher = new Dispatcher(
            ['ping' => new PingHandler(), 'initialize' => new InitializeHandler(listChanged: true)],
            $validator,
            null,
            null,
            $this->notifier(),
        );

        // Client A is handled and never drained (a client that does not accept
        // an event stream), then client B calls.
        $dispatcher->handle('{"jsonrpc":"2.0","method":"initialize","id":1}', 'token-a');
        $dispatcher->handle('{"jsonrpc":"2.0","method":"initialize","id":1}', 'token-b');
        $dispatcher->drainNotifications();

        // B's initialize marked B current; A was never marked, so A is still owed
        // the catalogue. If A's identity had leaked into B's request, B's drain
        // would have consumed A's marker instead.
        $dispatcher->handle('{"jsonrpc":"2.0","method":"ping","id":2}', 'token-a');
        self::assertNotSame([], $dispatcher->drainNotifications());
    }

    public function testDispatcherWithoutANotifierIsOwedNothing(): void
    {
        $validator = $this->createMock(TokenValidator::class);
        $validator->method('validateBearerForMcp')->willReturn($this->principal('jti-one'));

        $dispatcher = new Dispatcher(['ping' => new PingHandler()], $validator);
        $dispatcher->handle('{"jsonrpc":"2.0","method":"ping","id":1}', 'a-token');

        self::assertSame([], $dispatcher->drainNotifications());
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function dispatcher(string $jti): Dispatcher
    {
        $validator = $this->createMock(TokenValidator::class);
        $validator->method('validateBearerForMcp')->willReturn($this->principal($jti));

        return new Dispatcher(
            [
                'ping'       => new PingHandler(),
                'initialize' => new InitializeHandler(listChanged: true),
            ],
            $validator,
            null,
            null,
            $this->notifier(),
        );
    }

    private function notifier(): ListChangedNotifier
    {
        return new ListChangedNotifier(
            $this->signature,
            $this->store,
            ListChangedNotifier::SEEN_TTL_SECONDS,
            static function (string $m): void {},
        );
    }

    private function principal(string $jti): McpPrincipal
    {
        return new McpPrincipal(
            profileId: 7,
            userId: 7,
            tenantId: 3,
            principalKind: 'user',
            scope: [],
            jti: $jti,
        );
    }

    private function addRoute(): void
    {
        $this->router->registerUnversioned(
            'POST',
            '/api/plugin/things',
            static fn (): SdkResponse => new SdkResponse(200, '', []),
            null,
            null,
            null,
            ['operationId' => 'plugin_create_thing', 'summary' => 'Create thing'],
        );
        // What the host's registry-change listener does after a reload.
        ToolDeriver::clearCache();
        $this->signature->invalidate();
    }
}
