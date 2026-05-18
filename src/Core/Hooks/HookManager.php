<?php

namespace Whity\Core\Hooks;

use Whity\Core\Tenant\TenantContext;
use Whity\Core\Queue\Queue;

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
     * Queue instance for async hook dispatching
     */
    protected Queue $queue;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->queue = new Queue();
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

        // Return early if no listeners for this event
        if (!isset($this->listeners[$eventName])) {
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
     * Dispatch an asynchronous event by queuing the payload
     *
     * Injects context into the payload and queues it for background processing.
     *
     * @param string $eventName The event to dispatch
     * @param array $payload The payload to queue
     * @return void
     */
    public function dispatchAsync(string $eventName, array $payload): void
    {
        // Inject context into payload
        $payload['_context'] = [
            'tenant_id' => TenantContext::getTenantId(),
            'timestamp' => time(),
        ];

        // Queue the payload for async processing
        $this->queue->push('whity-core-async-hooks', $payload);
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
