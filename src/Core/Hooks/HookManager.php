<?php

namespace Whity\Core\Hooks;

use Psr\Log\LoggerInterface;
use Whity\Core\Audit\AuditContext;
use Whity\Core\Events\DomainEventStore;
use Whity\Core\Tenant\TenantContext;
use Whity\Http\WorkerRuntime;
use Whity\Sdk\PluginNamespace;

/**
 * HookManager implements a Mediator/Observer pattern for event handling
 *
 * Provides both synchronous (filters via dispatch) and asynchronous (actions via dispatchAsync)
 * hook execution. Listeners are executed in priority order, allowing plugins to hook into
 * system events and modify data or perform side effects.
 *
 * Context (tenant_id, timestamp) is automatically injected into all hook executions.
 */
class HookManager
{
    /**
     * Registered event listeners organized by event name and priority
     *
     * Structure: ['event_name' => [priority => [callback1, callback2, ...]]]
     */
    protected array $listeners = [];

    /**
     * Durable event spine (WC-154). When set (the request path, wired in
     * index.php), dispatchAsync PERSISTS each event to domain_events + the
     * relay outbox instead of the retired log-only Queue stub. Left null in
     * contexts that only register listeners and never dispatch domain events
     * (plugin loading, CLI), where dispatchAsync is a safe no-op.
     */
    private ?DomainEventStore $eventStore;

    private ?LoggerInterface $logger;

    /**
     * Namespace slug => plugin name, for every plugin registered with a host
     * that wired this hook manager (#843).
     *
     * Read by exactly one thing: the unmatched-dispatch diagnostic in
     * {@see dispatch()}. It is a MAP rather than a set because the warning has
     * to name the plugin the way its author would recognise it
     * (`Acme\Widgets\Plugin`), not the slug they got wrong.
     *
     * Entries are never removed. A plugin that is disabled loses its listeners
     * but keeps its namespace here, which costs at most a development-only
     * warning that correctly names a real plugin — whereas forgetting the
     * namespace would silence the diagnostic for the plugin most likely to be
     * mid-edit.
     *
     * @var array<string, string>
     */
    private array $pluginNamespaces = [];

    public function __construct(?DomainEventStore $eventStore = null, ?LoggerInterface $logger = null)
    {
        $this->eventStore = $eventStore;
        $this->logger = $logger;
    }

    /**
     * Register a listener for an event
     *
     * @param string $eventName The event to listen for
     * @param callable $callback The callback to execute
     * @param int $priority Priority for execution order (lower = earlier)
     * @return void
     */
    public function listen(string $eventName, callable $callback, int $priority = 10): void
    {
        if (!isset($this->listeners[$eventName])) {
            $this->listeners[$eventName] = [];
        }

        if (!isset($this->listeners[$eventName][$priority])) {
            $this->listeners[$eventName][$priority] = [];
        }

        $this->listeners[$eventName][$priority][] = $callback;
    }

    /**
     * Record that a plugin owns a namespace in the flat event space (#843).
     *
     * Called by the plugin loader as each plugin registers its capabilities —
     * the one place a plugin's NAME and this hook manager meet. Nothing about
     * dispatching depends on it: an unrecorded namespace only means the
     * diagnostic in {@see dispatch()} stays quiet, exactly as it did before.
     *
     * Registered for EVERY plugin, not only those declaring audited events. Any
     * plugin may dispatch its own namespaced events, and a diagnostic is only as
     * good as its idea of which prefixes are real.
     *
     * A name yielding no usable slug is ignored rather than refused: such a
     * plugin cannot namespace anything at all
     * ({@see PluginNamespace::qualify()} throws for it), so there is no
     * namespace to record and nothing a diagnostic could say about one.
     *
     * @param string $pluginName The plugin name ({@see \Whity\Sdk\PluginInterface::getName()}).
     */
    public function registerPluginNamespace(string $pluginName): void
    {
        $slug = PluginNamespace::slug($pluginName);
        if ($slug === null) {
            return;
        }

        $this->pluginNamespaces[$slug] = $pluginName;
    }

    /**
     * Dispatch a synchronous event and return modified data
     *
     * Executes all listeners for the event in priority order, passing data and context
     * to each listener. Each listener can modify and return the data.
     *
     * @param string $eventName The event to dispatch
     * @param array $data The initial data
     * @return array The final modified data
     */
    public function dispatch(string $eventName, array $data): array
    {
        // Build context with tenant_id and timestamp
        $context = [
            'tenant_id' => TenantContext::getTenantId(),
            'timestamp' => time(),
        ];

        // Return early if no listeners for this event. Most events legitimately
        // have none, so this stays a plain return — but a NAMESPACED name that
        // reached nobody is worth a word in development (#843), see below.
        if (!isset($this->listeners[$eventName])) {
            $this->reportUnmatchedPluginEvent($eventName);
            return $data;
        }

        // Sort listeners by priority (lower number = earlier execution)
        $priorityLevels = $this->listeners[$eventName];
        ksort($priorityLevels);

        // Execute listeners in priority order
        foreach ($priorityLevels as $callbacks) {
            foreach ($callbacks as $callback) {
                $result = $callback($data, $context);
                if (is_array($result)) {
                    $data = $result;
                }
            }
        }

        return $data;
    }

    /**
     * Warn — in development only — about a namespaced event that reached nobody
     * (#843).
     *
     * An unlistened event is normally unremarkable: core dispatches filter hooks
     * no plugin has subscribed to on every request, so silence is the right
     * default. This narrows to the one shape that is almost certainly a mistake:
     * a name carrying {@see PluginNamespace::SEPARATOR} whose prefix belongs to
     * a REGISTERED plugin, and which nothing is bound to. A bare name says
     * nothing; a namespace no plugin claims says nothing either.
     *
     * Why the case deserves code at all. Audited plugin events (SDK 1.29) are
     * bound to the NAMESPACED name, and the prefix is a slug of the plugin name
     * rather than the name itself — so a hand-spelled
     * `Acme\Widgets\Plugin:task.completed`, or a one-character typo in
     * `acme:task.completed`, writes no audit row, logs nothing, and leaves the
     * plugin behaving normally in every other respect. The incompleteness is
     * discovered when someone goes looking for who did something and the record
     * is not there: both the moment the trail exists for and the worst moment to
     * learn it was never wired up.
     *
     * A WARNING, never an exception. The action this observes has already
     * happened, and a diagnostic that could break what it observes would be a
     * worse defect than the one it reports — the same reason the audit write
     * itself is fail-soft. Development-gated ({@see WorkerRuntime::isDebug()} —
     * the repo's existing APP_ENV/DEBUG answer, not a tunable of its own)
     * because a plugin looping over a mis-spelled event would otherwise flood a
     * production log with a message only its author can act on.
     */
    private function reportUnmatchedPluginEvent(string $eventName): void
    {
        // Cheapest rejection first: every core event name is dotted and carries
        // no separator, so this returns on all but a plugin-namespaced dispatch
        // before any env or map lookup happens.
        $separator = strpos($eventName, PluginNamespace::SEPARATOR);
        if ($separator === false || $this->pluginNamespaces === [] || $this->logger === null) {
            return;
        }

        if (!WorkerRuntime::isDebug($_ENV)) {
            return;
        }

        $prefix = substr($eventName, 0, $separator);
        $bareEvent = substr($eventName, $separator + 1);

        // The prefix as dispatched, then the SLUG of it. The second lookup is
        // what catches a hand-spelled prefix, where an author wrote the plugin
        // name where its slug belonged: `Acme\Widgets\Plugin` slugs to the
        // `plugin` the host actually listens under, `Acme` to `acme`.
        $slug = isset($this->pluginNamespaces[$prefix])
            ? $prefix
            : PluginNamespace::slug($prefix);

        if ($slug === null || !isset($this->pluginNamespaces[$slug])) {
            return;
        }

        // Computable only for a wrong PREFIX. A typo in the bare half is not
        // recoverable, so that author gets the names actually bound under their
        // namespace instead — which is the string they were reaching for.
        $expected = $slug === $prefix ? null : $slug . PluginNamespace::SEPARATOR . $bareEvent;

        $bound = [];
        foreach (array_keys($this->listeners) as $listened) {
            if (str_starts_with((string) $listened, $slug . PluginNamespace::SEPARATOR)) {
                $bound[] = (string) $listened;
            }
        }

        $this->logger->warning(
            "Plugin '{$this->pluginNamespaces[$slug]}' dispatched '{$eventName}', which no listener "
            . 'is bound to'
            . ($expected !== null ? ", but '{$expected}' is the namespaced form of it" : '')
            . '. Namespaced event names belong to Whity\\Sdk\\Hooks\\Events::forPlugin(): the prefix '
            . 'is a SLUG of the plugin name, so a hand-spelled or mis-typed one matches nothing at '
            . 'all — and a declared audited event then writes no audit row.',
            [
                'event' => $eventName,
                'plugin' => $this->pluginNamespaces[$slug],
                'expected_event' => $expected,
                'bound_events' => $bound,
            ]
        );
    }

    /**
     * Dispatch an asynchronous event by PERSISTING it to the durable event
     * spine (WC-154/#162) — the immutable domain_events log plus a pending
     * event_outbox row a relay worker later drains. This replaces the retired
     * log-only Queue stub, which dropped every event.
     *
     * The tenant (from TenantContext) and actor (from AuditContext) are promoted
     * to first-class columns; the aggregate is derived from the conventional
     * dotted event name ('user.created.async' → 'user') and the payload's own id.
     * Non-critical by contract: a failure to persist is logged server-side and
     * never propagated — the caller's primary action (already committed, for the
     * core callers) must not break because a side-effect event could not be
     * recorded. With no store wired (plugin-loader / CLI) it is a safe no-op.
     *
     * DELIVERY REQUIRES A RELAY PROCESS, and that is not optional decoration.
     * This method PERSISTS an event; it runs no listeners itself. They are run
     * by `whity-cli events:relay` ({@see \Whity\Core\Events\EventRelay}), which
     * reserves each row, restores its tenant, dispatches, and retries or
     * dead-letters on failure.
     *
     * Until that command existed, this persisted events and nothing read them
     * (#1063): a listener bound to an async name would have been written,
     * tested, merged, and silently done nothing in production, while
     * `event_outbox` grew without bound. If you are relying on an async listener
     * firing, confirm the relay is running in that deployment — an outbox row is
     * a record of INTENT, not evidence of delivery.
     *
     * @param string $eventName The event to dispatch
     * @param array<string, mixed> $payload The event payload
     * @return void
     */
    public function dispatchAsync(string $eventName, array $payload): void
    {
        if ($this->eventStore === null) {
            return;
        }

        $tenantId = (int) (TenantContext::getTenantId() ?? 0);
        $aggregateType = explode('.', $eventName, 2)[0];

        try {
            $this->eventStore->append($tenantId, $eventName, $payload, [
                'aggregate_type' => $aggregateType !== '' ? $aggregateType : null,
                'aggregate_id'   => isset($payload['id']) ? (string) $payload['id'] : null,
                'actor_user_id'  => AuditContext::getActorUserId(),
            ]);
        } catch (\Throwable $e) {
            $this->logger?->error('dispatchAsync failed to persist domain event', [
                'event'     => $eventName,
                'tenant_id' => $tenantId,
                'error'     => $e->getMessage(),
            ]);
        }
    }

    /**
     * Remove a previously registered listener for an event
     *
     * Compares callbacks by identity. Used by the plugin hot-reload mechanism to
     * unsubscribe hooks belonging to a plugin that has been removed or is about
     * to be re-registered with updated code. Empty priority buckets and events
     * are pruned so that getListeners() reflects the removal.
     *
     * @param string $eventName The event the listener was registered for
     * @param callable $callback The exact callback to remove
     * @return bool True if a listener was removed, false otherwise
     */
    public function removeListener(string $eventName, callable $callback): bool
    {
        if (!isset($this->listeners[$eventName])) {
            return false;
        }

        $removed = false;

        foreach ($this->listeners[$eventName] as $priority => $callbacks) {
            foreach ($callbacks as $index => $registered) {
                if ($registered === $callback) {
                    unset($this->listeners[$eventName][$priority][$index]);
                    $removed = true;
                }
            }

            if (empty($this->listeners[$eventName][$priority])) {
                unset($this->listeners[$eventName][$priority]);
            } else {
                $this->listeners[$eventName][$priority] = array_values(
                    $this->listeners[$eventName][$priority]
                );
            }
        }

        if (empty($this->listeners[$eventName])) {
            unset($this->listeners[$eventName]);
        }

        return $removed;
    }

    /**
     * Get listeners for an event or all events
     *
     * @param string|null $eventName The event name, or null for all events
     * @return array The listeners array
     */
    public function getListeners(?string $eventName = null): array
    {
        if ($eventName === null) {
            return $this->listeners;
        }

        return $this->listeners[$eventName] ?? [];
    }
}
