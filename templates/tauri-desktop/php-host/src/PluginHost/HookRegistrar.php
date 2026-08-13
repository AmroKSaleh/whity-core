<?php

declare(strict_types=1);

namespace Whity\PluginHost;

use Whity\Core\Hooks\HookManager;
use Whity\Sdk\Hooks\HookVetoException;
use Whity\Sdk\PluginInterface;

/**
 * Registers a plugin's getHooks() declarations onto a HookManager, wrapping
 * every callback in the same per-plugin error boundary production's
 * PluginLoader::wrapHookCallback() uses: a generic Throwable is logged and
 * swallowed (the payload passes through unchanged, so one broken listener
 * never corrupts the dispatch chain), while HookVetoException is logged at
 * info level and re-thrown — the one exception the SDK sanctions for a
 * listener to abort the operation that dispatched it.
 *
 * A plugin whose getHooks() itself throws loses only its hooks (logged,
 * registration continues for the next plugin) — routes/permissions/migrations
 * are unaffected, since hook declaration is evaluated independently of them.
 */
final class HookRegistrar
{
    public static function registerAll(HookManager $hookManager, string $pluginName, PluginInterface $plugin): void
    {
        try {
            $hooks = $plugin->getHooks();
        } catch (\Throwable $e) {
            error_log("[php-host] plugin '{$pluginName}' getHooks() threw " . get_class($e) . ': ' . $e->getMessage());

            return;
        }

        foreach ($hooks as $eventName => $hookData) {
            if (!is_string($eventName)) {
                continue;
            }

            foreach (self::normalize($hookData) as [$callback, $priority]) {
                $hookManager->listen($eventName, self::wrap($pluginName, $callback), $priority);
            }
        }
    }

    /**
     * Normalize a getHooks() entry into a flat list of [callable, priority] pairs.
     *
     * @return list<array{0: callable, 1: int}>
     */
    private static function normalize(mixed $hookData): array
    {
        if (is_callable($hookData)) {
            return [[$hookData, 10]];
        }

        if (!is_array($hookData)) {
            return [];
        }

        // A single structured subscription: ['callback' => ..., 'priority' => ...].
        if (isset($hookData['callback']) && is_callable($hookData['callback'])) {
            $priority = is_int($hookData['priority'] ?? null) ? $hookData['priority'] : 10;

            return [[$hookData['callback'], $priority]];
        }

        // A list of callables or structured subscriptions.
        $entries = [];
        foreach ($hookData as $item) {
            if (is_callable($item)) {
                $entries[] = [$item, 10];
            } elseif (is_array($item) && isset($item['callback']) && is_callable($item['callback'])) {
                $priority = is_int($item['priority'] ?? null) ? $item['priority'] : 10;
                $entries[] = [$item['callback'], $priority];
            }
        }

        return $entries;
    }

    private static function wrap(string $pluginName, callable $callback): callable
    {
        return function (array $data, array $context = []) use ($pluginName, $callback): array {
            try {
                $result = $callback($data, $context);

                return is_array($result) ? $result : $data;
            } catch (HookVetoException $e) {
                error_log("[php-host] plugin '{$pluginName}' vetoed \"{$e->eventName()}\": {$e->reason()}");
                throw $e;
            } catch (\Throwable $e) {
                error_log("[php-host] plugin '{$pluginName}' hook callback threw " . get_class($e) . ': ' . $e->getMessage());

                return $data;
            }
        };
    }
}
