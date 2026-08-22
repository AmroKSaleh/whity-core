<?php

namespace Tests\Unit\Core\Hooks;

use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Tenant\TenantContext;

/**
 * Tests for HookManager class
 */
class HookManagerTest extends TestCase
{
    private HookManager $hookManager;

    /**
     * Backups of the two env vars the unmatched-event diagnostic reads (#843),
     * so a test that switches the dev gate on cannot leak it into the rest of
     * the suite. Presence is recorded separately from the value: "absent"
     * ("production", the fail-quiet default) is itself one of the states under
     * test, and restoring an absent var as an empty string would not be it.
     */
    private bool $hadAppEnv = false;

    private ?string $previousAppEnv = null;

    private bool $hadDebug = false;

    private ?string $previousDebug = null;

    protected function setUp(): void
    {
        $this->hookManager = new HookManager();
        // Reset TenantContext for each test
        TenantContext::reset();

        $this->hadAppEnv = array_key_exists('APP_ENV', $_ENV);
        $this->previousAppEnv = isset($_ENV['APP_ENV']) ? (string) $_ENV['APP_ENV'] : null;
        $this->hadDebug = array_key_exists('DEBUG', $_ENV);
        $this->previousDebug = isset($_ENV['DEBUG']) ? (string) $_ENV['DEBUG'] : null;
    }

    protected function tearDown(): void
    {
        TenantContext::reset();

        if ($this->hadAppEnv) {
            $_ENV['APP_ENV'] = $this->previousAppEnv;
        } else {
            unset($_ENV['APP_ENV']);
        }

        if ($this->hadDebug) {
            $_ENV['DEBUG'] = $this->previousDebug;
        } else {
            unset($_ENV['DEBUG']);
        }
    }

    /**
     * Test listen() stores callback in listeners array
     */
    public function testListenRegistersCallback(): void
    {
        $callback = fn() => null;
        $this->hookManager->listen('test_event', $callback);

        $listeners = $this->hookManager->getListeners('test_event');
        $this->assertNotEmpty($listeners);
    }

    /**
     * Test dispatch() executes listeners in priority order
     */
    public function testDispatchExecutesListenersInPriorityOrder(): void
    {
        $execution = [];

        // Register listeners with different priorities
        $this->hookManager->listen('test_event', function() use (&$execution) {
            $execution[] = 'first';
        }, 5);

        $this->hookManager->listen('test_event', function() use (&$execution) {
            $execution[] = 'second';
        }, 10);

        $this->hookManager->listen('test_event', function() use (&$execution) {
            $execution[] = 'third';
        }, 15);

        $this->hookManager->dispatch('test_event', []);

        $this->assertEquals(['first', 'second', 'third'], $execution);
    }

    /**
     * Test dispatch() returns modified data
     */
    public function testDispatchReturnsModifiedData(): void
    {
        $this->hookManager->listen('test_event', function($data, $context) {
            $data['name'] = 'modified';
            return $data;
        });

        $result = $this->hookManager->dispatch('test_event', ['name' => 'original']);

        $this->assertEquals('modified', $result['name']);
    }

    /**
     * Test dispatch() includes context metadata
     */
    public function testDispatchIncludesContextMetadata(): void
    {
        TenantContext::setTenantId(42);

        $capturedContext = null;
        $this->hookManager->listen('test_event', function($data, $context) use (&$capturedContext) {
            $capturedContext = $context;
            return $data;
        });

        $this->hookManager->dispatch('test_event', []);

        $this->assertIsArray($capturedContext);
        $this->assertArrayHasKey('tenant_id', $capturedContext);
        $this->assertArrayHasKey('timestamp', $capturedContext);
        $this->assertEquals(42, $capturedContext['tenant_id']);
        $this->assertIsInt($capturedContext['timestamp']);
    }

    /**
     * With no durable event store wired (the default here, and the case for
     * plugin-loader / CLI HookManagers that only register listeners), the
     * async dispatch is a safe no-op — it must not throw. Persistence behaviour
     * is covered against a real store in HookIntegrationTest.
     */
    public function testDispatchAsyncWithNoStoreIsSafeNoOp(): void
    {
        TenantContext::setTenantId(1);

        $this->expectNotToPerformAssertions();
        $this->hookManager->dispatchAsync('test_event', ['key' => 'value']);
    }

    /**
     * Test multiple listeners at same priority execute sequentially
     */
    public function testMultipleListenersExecuteSequentially(): void
    {
        $execution = [];

        $this->hookManager->listen('test_event', function($data, $context) use (&$execution) {
            $execution[] = 'listener1';
            $data['step'] = 1;
            return $data;
        }, 10);

        $this->hookManager->listen('test_event', function($data, $context) use (&$execution) {
            $execution[] = 'listener2';
            $data['step'] = 2;
            return $data;
        }, 10);

        $result = $this->hookManager->dispatch('test_event', ['step' => 0]);

        $this->assertEquals(['listener1', 'listener2'], $execution);
        $this->assertEquals(2, $result['step']);
    }

    /**
     * Test getListeners() returns empty array for unregistered event
     */
    public function testGetListenersReturnsEmptyForUnregisteredEvent(): void
    {
        $listeners = $this->hookManager->getListeners('nonexistent_event');

        $this->assertIsArray($listeners);
        $this->assertEmpty($listeners);
    }

    public function testRemoveListenerRemovesMatchingCallback(): void
    {
        $callbackA = static fn(array $data, array $context): array => $data;
        $callbackB = static fn(array $data, array $context): array => $data;

        $this->hookManager->listen('event.x', $callbackA);
        $this->hookManager->listen('event.x', $callbackB);

        $removed = $this->hookManager->removeListener('event.x', $callbackA);
        $this->assertTrue($removed);

        // The event still has callbackB, but the empty priority bucket was cleaned up
        $listeners = $this->hookManager->getListeners('event.x');
        $flattened = [];
        foreach ($listeners as $callbacks) {
            foreach ($callbacks as $callback) {
                $flattened[] = $callback;
            }
        }
        $this->assertCount(1, $flattened);
        $this->assertSame($callbackB, $flattened[0]);
    }

    public function testRemoveListenerPrunesEmptyEvent(): void
    {
        $callback = static fn(array $data, array $context): array => $data;
        $this->hookManager->listen('event.y', $callback, 5);

        $this->assertTrue($this->hookManager->removeListener('event.y', $callback));

        // Removing the only listener leaves no trace of the event
        $this->assertEmpty($this->hookManager->getListeners('event.y'));
        $this->assertArrayNotHasKey('event.y', $this->hookManager->getListeners());
    }

    public function testRemoveListenerReturnsFalseWhenNotFound(): void
    {
        $callback = static fn(array $data, array $context): array => $data;

        $this->assertFalse($this->hookManager->removeListener('event.z', $callback));
    }

    // ── Unmatched namespaced dispatch (#843) ─────────────────────────────────

    /**
     * The typo case. The prefix is right, the bare half is not, so nothing is
     * bound to the name dispatched and a declared audited event writes no row —
     * a gap discovered only when someone later goes looking for the record.
     *
     * The warning has to name the PLUGIN, because an author reading a shared log
     * needs to know whose dispatch this was, and the names actually bound under
     * that namespace, because a typo cannot be corrected by computation and
     * those strings are what the author was reaching for.
     */
    public function testAMisTypedNamespacedEventWarnsWithThePluginAndItsRealEvents(): void
    {
        $_ENV['APP_ENV'] = 'development';
        $logs = new HookManagerSpyLogger();
        $hooks = $this->managerWithPlugins($logs, 'Acme');
        $hooks->listen('acme:task.completed', static fn(array $data, array $context): array => $data, 50);

        $result = $hooks->dispatch('acme:task.completd', ['task_id' => 7]);

        $this->assertCount(1, $logs->records);
        $this->assertSame('warning', $logs->records[0]['level']);
        $this->assertStringContainsString("Plugin 'Acme'", $logs->records[0]['message']);
        $this->assertStringContainsString("'acme:task.completd'", $logs->records[0]['message']);
        $this->assertSame(['acme:task.completed'], $logs->records[0]['context']['bound_events']);
        $this->assertNull(
            $logs->records[0]['context']['expected_event'],
            'a mis-typed bare name is not recoverable, so no correction is claimed'
        );
        // The diagnostic only observes the filter chain — it must never alter it.
        $this->assertSame(['task_id' => 7], $result);
    }

    /**
     * The hand-spelled-prefix case, and the one the issue was raised for: the
     * prefix is a SLUG of the plugin name, not the name, so an author who wrote
     * `Acme\Widgets\Plugin:…` after seeing `acme:task.completed` in a doc
     * dispatches into silence. Here the correct name IS computable, so the
     * warning owes the author that exact string rather than a hint.
     */
    public function testAHandSpelledPrefixIsGivenTheNamespacedFormItShouldHaveUsed(): void
    {
        $_ENV['APP_ENV'] = 'development';
        $logs = new HookManagerSpyLogger();
        $hooks = $this->managerWithPlugins($logs, 'Acme\Widgets\Plugin');
        $hooks->listen('plugin:task.completed', static fn(array $data, array $context): array => $data, 50);

        $result = $hooks->dispatch('Acme\Widgets\Plugin:task.completed', ['task_id' => 7]);

        $this->assertCount(1, $logs->records);
        $this->assertStringContainsString("Plugin 'Acme\Widgets\Plugin'", $logs->records[0]['message']);
        $this->assertSame('plugin:task.completed', $logs->records[0]['context']['expected_event']);
        $this->assertStringContainsString("'plugin:task.completed'", $logs->records[0]['message']);
        $this->assertSame(['task_id' => 7], $result);
    }

    /**
     * A BARE unlistened event stays silent. Core dispatches filter hooks no
     * plugin has subscribed to on every single request; warning about those
     * would bury the one case that is actually a mistake.
     */
    public function testABareUnlistenedEventIsNeverWarnedAbout(): void
    {
        $_ENV['APP_ENV'] = 'development';
        $logs = new HookManagerSpyLogger();
        $hooks = $this->managerWithPlugins($logs, 'Acme');

        $result = $hooks->dispatch('user.creating', ['email' => 'a@example.test']);

        $this->assertSame([], $logs->records);
        $this->assertSame(['email' => 'a@example.test'], $result);
    }

    /**
     * A namespaced event that IS bound is the working case — the diagnostic must
     * not fire on the very thing it is trying to protect, and must not disturb
     * the payload the listener returned.
     */
    public function testANamespacedEventWithAListenerIsNotWarnedAbout(): void
    {
        $_ENV['APP_ENV'] = 'development';
        $logs = new HookManagerSpyLogger();
        $hooks = $this->managerWithPlugins($logs, 'Acme');
        $hooks->listen('acme:task.completed', static function (array $data, array $context): array {
            $data['audited'] = true;
            return $data;
        }, 50);

        $result = $hooks->dispatch('acme:task.completed', ['task_id' => 7]);

        $this->assertSame([], $logs->records);
        $this->assertSame(['task_id' => 7, 'audited' => true], $result);
    }

    /**
     * A namespace no registered plugin claims stays silent. The host cannot tell
     * a third party's convention from a mistake, and guessing would make the
     * diagnostic noise in exactly the deployments that install the most plugins.
     */
    public function testANamespaceNoPluginClaimsIsNotWarnedAbout(): void
    {
        $_ENV['APP_ENV'] = 'development';
        $logs = new HookManagerSpyLogger();
        $hooks = $this->managerWithPlugins($logs, 'Acme');

        $result = $hooks->dispatch('other:task.completed', ['task_id' => 7]);

        $this->assertSame([], $logs->records);
        $this->assertSame(['task_id' => 7], $result);
    }

    /**
     * A plugin name that yields no usable slug registers no namespace, so a
     * dispatch under it is not attributed to it. Such a plugin cannot namespace
     * anything in the first place (PluginNamespace::qualify() throws for it),
     * which leaves nothing for a diagnostic to correct.
     */
    public function testAPluginNameWithNoUsableSlugClaimsNoNamespace(): void
    {
        $_ENV['APP_ENV'] = 'development';
        $logs = new HookManagerSpyLogger();
        $hooks = $this->managerWithPlugins($logs, '!!!');

        $result = $hooks->dispatch('!!!:task.completed', ['task_id' => 7]);

        $this->assertSame([], $logs->records);
        $this->assertSame(['task_id' => 7], $result);
    }

    /**
     * Gate off, nothing said. A plugin looping over a mis-spelled event would
     * otherwise flood a production log with a message only its author can act
     * on, and an unset APP_ENV counts as production — the same fail-quiet
     * default the rest of the codebase's APP_ENV gates use.
     *
     * @dataProvider nonDebugEnvironments
     */
    public function testTheDiagnosticIsSilentWhenTheDevGateIsOff(?string $appEnv): void
    {
        if ($appEnv === null) {
            unset($_ENV['APP_ENV']);
        } else {
            $_ENV['APP_ENV'] = $appEnv;
        }
        unset($_ENV['DEBUG']);

        $logs = new HookManagerSpyLogger();
        $hooks = $this->managerWithPlugins($logs, 'Acme');
        $hooks->listen('acme:task.completed', static fn(array $data, array $context): array => $data, 50);

        $result = $hooks->dispatch('acme:task.completd', ['task_id' => 7]);

        $this->assertSame([], $logs->records);
        $this->assertSame(['task_id' => 7], $result);
    }

    /**
     * @return array<string, array{0: string|null}>
     */
    public static function nonDebugEnvironments(): array
    {
        return [
            'production' => ['production'],
            'staging' => ['staging'],
            'unset' => [null],
        ];
    }

    /**
     * The gate is the repo's existing APP_ENV/DEBUG pair, not a tunable of its
     * own: an operator who set the documented DEBUG flag to chase a problem in a
     * non-development environment gets this diagnostic too, exactly as they get
     * the worker's lifecycle lines.
     */
    public function testTheDebugFlagAloneTurnsTheDiagnosticOn(): void
    {
        $_ENV['APP_ENV'] = 'production';
        $_ENV['DEBUG'] = '1';

        $logs = new HookManagerSpyLogger();
        $hooks = $this->managerWithPlugins($logs, 'Acme');

        $result = $hooks->dispatch('acme:task.completd', ['task_id' => 7]);

        $this->assertCount(1, $logs->records);
        $this->assertSame(['task_id' => 7], $result);
    }

    /**
     * A hook manager wired the way a host wires one: a logger, plus the plugin
     * namespaces the loader would have registered as each plugin loaded. The
     * dev gate is left to the caller, since "gate off" is one of the cases under
     * test.
     */
    private function managerWithPlugins(HookManagerSpyLogger $logs, string ...$pluginNames): HookManager
    {
        $hooks = new HookManager(null, $logs);
        foreach ($pluginNames as $pluginName) {
            $hooks->registerPluginNamespace($pluginName);
        }

        return $hooks;
    }
}

/**
 * In-memory PSR-3 logger double. The whole contract of the unmatched-event
 * diagnostic is "a warning names the plugin and, where possible, the name it
 * meant", so both the message and its context have to be asserted rather than
 * merely allowed to happen somewhere.
 */
final class HookManagerSpyLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    public array $records = [];

    /**
     * @param mixed $level
     * @param array<string, mixed> $context
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = [
            'level' => (string) $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }
}
