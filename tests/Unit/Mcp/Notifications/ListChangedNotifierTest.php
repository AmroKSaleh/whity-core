<?php

declare(strict_types=1);

namespace Tests\Unit\Mcp\Notifications;

use PHPUnit\Framework\TestCase;
use Whity\Core\Router;
use Whity\Core\Store\ArraySharedStore;
use Whity\Core\Store\SharedStoreInterface;
use Whity\Mcp\Notifications\CatalogSignature;
use Whity\Mcp\Notifications\ListChangedNotifier;
use Whity\Mcp\Prompts\Prompt;
use Whity\Mcp\Prompts\PromptRegistry;
use Whity\Mcp\Resources\ResourceDeriver;
use Whity\Mcp\Tools\ToolDeriver;
use Whity\Sdk\Http\Response as SdkResponse;

/**
 * Who is owed a `list_changed`, and how often (#952).
 *
 * The two failure modes this guards are opposite and equally bad: never telling
 * a client its cached tool list is stale (the corruption in #952) and telling it
 * on every request (a storm that costs a `tools/list` round trip each time).
 * Between them sits the multi-worker case — eight FrankenPHP workers observing
 * the same change must produce ONE notification per client, not eight, which is
 * why the "already told" marker lives in the shared store and not in the worker.
 */
final class ListChangedNotifierTest extends TestCase
{
    private const CLIENT = 'jti-client-one';

    private Router $router;
    private PromptRegistry $prompts;
    private ArraySharedStore $store;

    /**
     * One signature instance for the whole test, because that is what production
     * has: CatalogSignature is a worker singleton that survives every reload the
     * worker sees. Tests that need a SECOND worker build their own via
     * {@see notifierOver()}.
     */
    private CatalogSignature $signature;

    protected function setUp(): void
    {
        ToolDeriver::clearCache();
        $this->router    = new Router('');
        $this->prompts   = new PromptRegistry();
        $this->store     = new ArraySharedStore();
        $this->signature = $this->signatureOver($this->router, $this->prompts);
    }

    protected function tearDown(): void
    {
        ToolDeriver::clearCache();
    }

    // ── The catalogue moved ───────────────────────────────────────────────────

    public function testCatalogueChangeEmitsToolsListChanged(): void
    {
        $notifier = $this->notifier();
        $notifier->markSeen(self::CLIENT);

        $this->addRoute('POST', '/api/plugin/things', 'plugin_create_thing');
        $notifier = $this->notifier();

        self::assertSame(
            ['notifications/tools/list_changed'],
            $this->methodsOf($notifier->drainFor(self::CLIENT)),
        );
    }

    public function testGetRouteChangeEmitsToolsAndResourcesListChanged(): void
    {
        $notifier = $this->notifier();
        $notifier->markSeen(self::CLIENT);

        $this->addRoute('GET', '/api/plugin/things', 'plugin_list_things');
        $notifier = $this->notifier();

        self::assertSame(
            [
                'notifications/tools/list_changed',
                'notifications/resources/list_changed',
            ],
            $this->methodsOf($notifier->drainFor(self::CLIENT)),
        );
    }

    public function testPromptChangeEmitsPromptsListChanged(): void
    {
        $notifier = $this->notifier();
        $notifier->markSeen(self::CLIENT);

        $this->addPrompt('plugin.triage');
        $notifier = $this->notifier();

        self::assertSame(
            ['notifications/prompts/list_changed'],
            $this->methodsOf($notifier->drainFor(self::CLIENT)),
        );
    }

    public function testEmittedFrameIsAValidJsonRpcNotification(): void
    {
        $notifier = $this->notifier();
        $notifier->markSeen(self::CLIENT);

        $this->addRoute('POST', '/api/plugin/things', 'plugin_create_thing');
        $frames = $this->notifier()->drainFor(self::CLIENT);

        $decoded = json_decode($frames[0], true);
        self::assertIsArray($decoded);
        self::assertSame('2.0', $decoded['jsonrpc']);
        self::assertSame('notifications/tools/list_changed', $decoded['method']);
        // A notification carries no id — an id would make it a request the
        // client is expected to answer.
        self::assertArrayNotHasKey('id', $decoded);
    }

    // ── Nothing moved ─────────────────────────────────────────────────────────

    public function testUnchangedCatalogueEmitsNothing(): void
    {
        $notifier = $this->notifier();
        $notifier->markSeen(self::CLIENT);

        self::assertSame([], $notifier->drainFor(self::CLIENT));
        self::assertSame([], $notifier->drainFor(self::CLIENT));
        self::assertSame([], $notifier->drainFor(self::CLIENT));
    }

    public function testClientIsToldOnlyOncePerChange(): void
    {
        $notifier = $this->notifier();
        $notifier->markSeen(self::CLIENT);

        $this->addRoute('POST', '/api/plugin/things', 'plugin_create_thing');
        $notifier = $this->notifier();

        self::assertCount(1, $notifier->drainFor(self::CLIENT));
        self::assertSame([], $notifier->drainFor(self::CLIENT), 'the same change must not be re-announced');
    }

    /**
     * Install then uninstall — A → B → A. The client was told about B, so it is
     * holding B's catalogue; the server is back on A. Announcing only the first
     * move leaves exactly the mismatch #952 is about, and the first cut of this
     * code did precisely that on a live server: adding a plugin announced, then
     * removing it again went silent.
     */
    public function testRevertingToAnEarlierCatalogueAnnouncesAgain(): void
    {
        $notifier = $this->notifier();
        $notifier->markSeen(self::CLIENT);

        // A → B: the plugin lands.
        $this->addPrompt('plugin.triage');
        self::assertCount(1, $this->notifier()->drainFor(self::CLIENT), 'the install is announced');

        // B → A: the plugin is uninstalled and the catalogue is byte-identical
        // to the one this client was marked current on before the install.
        $this->prompts->reset();
        $this->signature->invalidate();

        self::assertSame(
            ['notifications/prompts/list_changed'],
            $this->methodsOf($this->notifier()->drainFor(self::CLIENT)),
            'the uninstall must be announced too — the client is still holding B',
        );
    }

    public function testRevertIsStillAnnouncedOnlyOnce(): void
    {
        $notifier = $this->notifier();
        $notifier->markSeen(self::CLIENT);

        $this->addPrompt('plugin.triage');
        $this->notifier()->drainFor(self::CLIENT);

        $this->prompts->reset();
        $this->signature->invalidate();

        self::assertCount(1, $this->notifier()->drainFor(self::CLIENT));
        self::assertSame([], $this->notifier()->drainFor(self::CLIENT));
    }

    // ── Eight workers, one notification ───────────────────────────────────────

    /**
     * Two notifiers over one shared store stand in for two FrankenPHP workers
     * that have both converged on the new catalogue. Without the shared marker
     * each would announce the same change to the same client.
     */
    public function testTwoWorkersAnnounceTheSameChangeOnlyOnce(): void
    {
        $this->notifier()->markSeen(self::CLIENT);

        $this->addRoute('POST', '/api/plugin/things', 'plugin_create_thing');

        // Two workers that have both converged on the new registry, each with
        // its own signature memo but sharing the store.
        $workerOne = $this->notifierOver($this->router, $this->prompts);
        $workerTwo = $this->notifierOver($this->router, $this->prompts);

        self::assertCount(1, $workerOne->drainFor(self::CLIENT));
        self::assertSame([], $workerTwo->drainFor(self::CLIENT));
    }

    /**
     * The other half of the multi-worker answer: a worker that has NOT picked up
     * the change computes the old catalogue and therefore owes nothing. It must
     * not announce a list it would then fail to serve.
     */
    public function testWorkerThatHasNotReloadedStaysQuiet(): void
    {
        $laggingRouter = new Router('');
        $lagging       = $this->notifierOver($laggingRouter, new PromptRegistry());
        $lagging->markSeen(self::CLIENT);

        // Another worker's registry moved on; this one's did not.
        $this->addRoute('POST', '/api/plugin/things', 'plugin_create_thing');
        ToolDeriver::clearCache();
        $lagging = $this->notifierOver($laggingRouter, new PromptRegistry());

        self::assertSame([], $lagging->drainFor(self::CLIENT));
    }

    public function testEachClientIsToldSeparately(): void
    {
        $notifier = $this->notifier();
        $notifier->markSeen('jti-a');
        $notifier->markSeen('jti-b');

        $this->addRoute('POST', '/api/plugin/things', 'plugin_create_thing');
        $notifier = $this->notifier();

        self::assertCount(1, $notifier->drainFor('jti-a'));
        self::assertCount(1, $notifier->drainFor('jti-b'));
    }

    public function testMarkSeenSuppressesTheAnnouncementForThatCatalogue(): void
    {
        $this->addRoute('POST', '/api/plugin/things', 'plugin_create_thing');
        $notifier = $this->notifier();

        $notifier->markSeen(self::CLIENT);

        self::assertSame([], $notifier->drainFor(self::CLIENT));
    }

    public function testAClientThatNeverInitializedIsToldAboutTheCurrentCatalogue(): void
    {
        // No markSeen: the client skipped the handshake, so it has never been
        // told anything. One redundant refetch is the right way to fail here.
        self::assertCount(3, $this->notifier()->drainFor('jti-never-handshook'));
    }

    // ── Failure isolation ─────────────────────────────────────────────────────

    public function testStoreFailureYieldsNoNotificationsAndDoesNotThrow(): void
    {
        $warnings = [];
        $notifier = new ListChangedNotifier(
            $this->signatureOver($this->router, $this->prompts),
            $this->explodingStore(),
            ListChangedNotifier::SEEN_TTL_SECONDS,
            static function (string $m) use (&$warnings): void { $warnings[] = $m; },
        );

        self::assertSame([], $notifier->drainFor(self::CLIENT));
        self::assertNotSame([], $warnings, 'a store outage must be logged, not silent');
    }

    public function testMarkSeenSwallowsAStoreFailure(): void
    {
        $notifier = new ListChangedNotifier(
            $this->signatureOver($this->router, $this->prompts),
            $this->explodingStore(),
            ListChangedNotifier::SEEN_TTL_SECONDS,
            static function (string $m): void {},
        );

        $notifier->markSeen(self::CLIENT);

        // Reaching here at all is the assertion: markSeen() is called from the
        // response path and may not surface a store failure to the caller.
        $this->addToAssertionCount(1);
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    /** The worker under test, always over the same long-lived signature. */
    private function notifier(): ListChangedNotifier
    {
        return $this->notifierUsing($this->signature);
    }

    /** A separate worker: its own signature memo over the given registry state. */
    private function notifierOver(Router $router, PromptRegistry $prompts): ListChangedNotifier
    {
        return $this->notifierUsing($this->signatureOver($router, $prompts));
    }

    private function notifierUsing(CatalogSignature $signature): ListChangedNotifier
    {
        return new ListChangedNotifier(
            $signature,
            $this->store,
            ListChangedNotifier::SEEN_TTL_SECONDS,
            static function (string $m): void {},
        );
    }

    private function signatureOver(Router $router, PromptRegistry $prompts): CatalogSignature
    {
        return new CatalogSignature(
            new ToolDeriver([], [], $router),
            new ResourceDeriver([], $router),
            $prompts,
        );
    }

    private function addRoute(string $method, string $path, string $operationId): void
    {
        $this->router->registerUnversioned(
            $method,
            $path,
            static fn (): SdkResponse => new SdkResponse(200, '', []),
            null,
            null,
            null,
            ['operationId' => $operationId, 'summary' => 'A plugin route'],
        );
        // Exactly what the host's registry-change listener does after a reload.
        ToolDeriver::clearCache();
        $this->signature->invalidate();
    }

    private function addPrompt(string $name): void
    {
        $this->prompts->register(new Prompt(name: $name, description: 'A plugin prompt'));
        $this->signature->invalidate();
    }

    /**
     * @param list<string> $frames
     * @return list<string>
     */
    private function methodsOf(array $frames): array
    {
        return array_map(
            static function (string $frame): string {
                $decoded = json_decode($frame, true);

                return is_array($decoded) && is_string($decoded['method'] ?? null)
                    ? $decoded['method']
                    : '';
            },
            $frames,
        );
    }

    private function explodingStore(): SharedStoreInterface
    {
        return new class implements SharedStoreInterface {
            public function increment(string $key, int $ttlSeconds): int
            {
                throw new \RuntimeException('shared store unavailable');
            }

            public function decrement(string $key): int
            {
                throw new \RuntimeException('shared store unavailable');
            }

            public function count(string $key): int
            {
                throw new \RuntimeException('shared store unavailable');
            }

            public function ttl(string $key): int
            {
                throw new \RuntimeException('shared store unavailable');
            }

            public function delete(string $key): void
            {
                throw new \RuntimeException('shared store unavailable');
            }

            public function prune(): int
            {
                throw new \RuntimeException('shared store unavailable');
            }
        };
    }
}
