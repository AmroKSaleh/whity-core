<?php

declare(strict_types=1);

namespace Whity\Core\Hooks;

use Whity\Core\Tenant\TenantContext;

/**
 * Sized-down port of production's Whity\Core\Hooks\HookManager: a
 * synchronous, priority-ordered Observer — listen()/dispatch() — with the
 * same auto-injected context and "non-array return ignored" semantics.
 * dispatchAsync() is a pure no-op here (not log-and-drop): there is no relay
 * worker offline to eventually drain a persisted event, so pretending one
 * might run would be less honest than doing nothing.
 */
class HookManager
{
    /** @var array<string, array<int, list<callable>>> */
    protected array $listeners = [];

    public function listen(string $eventName, callable $callback, int $priority = 10): void
    {
        $this->listeners[$eventName][$priority][] = $callback;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function dispatch(string $eventName, array $data): array
    {
        if (!isset($this->listeners[$eventName])) {
            return $data;
        }

        $context = [
            'tenant_id' => TenantContext::getTenantId(),
            'timestamp' => time(),
        ];

        $priorityLevels = $this->listeners[$eventName];
        ksort($priorityLevels);

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
     * @param array<string, mixed> $payload
     */
    public function dispatchAsync(string $eventName, array $payload): void
    {
        // No relay worker offline — intentionally inert, see class docblock.
    }

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

            if ($this->listeners[$eventName][$priority] === []) {
                unset($this->listeners[$eventName][$priority]);
            } else {
                $this->listeners[$eventName][$priority] = array_values($this->listeners[$eventName][$priority]);
            }
        }

        if (($this->listeners[$eventName] ?? []) === []) {
            unset($this->listeners[$eventName]);
        }

        return $removed;
    }

    /**
     * @return array<int, list<callable>>|array<string, array<int, list<callable>>>
     */
    public function getListeners(?string $eventName = null): array
    {
        if ($eventName === null) {
            return $this->listeners;
        }

        return $this->listeners[$eventName] ?? [];
    }
}
