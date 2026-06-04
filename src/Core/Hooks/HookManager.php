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
        Queue::push('whity-core-async-hooks', $payload);
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
