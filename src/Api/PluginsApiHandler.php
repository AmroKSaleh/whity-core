<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\PluginLoader;

/**
 * Plugins API Handler
 *
 * Handles plugin discovery, enabling, and disabling at the filesystem level, and
 * - when a PluginLoader is wired in - exposes per-plugin lifecycle state plus a
 * manual re-enable action for plugins that have been auto-disabled after
 * repeated failures.
 */
class PluginsApiHandler
{
    private string $pluginDir;

    /**
     * Optional in-memory plugin loader used to read/mutate lifecycle state.
     *
     * Filesystem operations (enable/disable by renaming files) work without it,
     * but lifecycle reporting and re-enable require the live loader instance.
     */
    private ?PluginLoader $pluginLoader;

    /**
     * @param string $pluginDir Directory containing plugin files.
     * @param PluginLoader|null $pluginLoader Optional live loader for lifecycle state.
     */
    public function __construct(string $pluginDir, ?PluginLoader $pluginLoader = null)
    {
        $this->pluginDir = $pluginDir;
        $this->pluginLoader = $pluginLoader;
    }

    /**
     * GET /api/plugins - List all plugins
     *
     * When a loader is available, each entry is enriched with its lifecycle
     * state (discovered/loaded/active/failed/disabled), the consecutive-error
     * count, and the most recent error details.
     *
     * @param Request $request The incoming request.
     * @return Response
     */
    public function list(Request $request): Response
    {
        try {
            $lifecycleByName = $this->lifecycleStatusByName();

            $plugins = [];
            $files = scandir($this->pluginDir);

            if ($files === false) {
                $files = [];
            }

            foreach ($files as $file) {
                if ($file === '.' || $file === '..' || $file === '.gitkeep') {
                    continue;
                }

                $path = $this->pluginDir . '/' . $file;
                $extension = pathinfo($file, PATHINFO_EXTENSION);

                if (is_dir($path) || $extension === 'php' || (strpos($file, '.php.disabled') !== false)) {
                    $id = str_replace(['.php.disabled', '.php'], '', $file);
                    $enabled = !is_dir($path) ? $extension === 'php' : true;

                    $entry = [
                        'id' => $id,
                        'name' => $id,
                        'enabled' => $enabled,
                        'file' => $file,
                    ];

                    $entry += $this->matchLifecycle($id, $lifecycleByName);
                    $plugins[] = $entry;
                }
            }

            // Surface any loaded plugins that have no on-disk file entry matched
            // above (e.g. nested plugins whose folder name differs from the id).
            foreach ($this->loaderStatuses() as $status) {
                if (!$this->statusAlreadyListed($status, $plugins)) {
                    $plugins[] = [
                        'id' => $status['id'],
                        'name' => $status['name'],
                        'enabled' => true,
                        'file' => null,
                        'state' => $status['state'],
                        'consecutive_errors' => $status['consecutive_errors'],
                        'last_error' => $status['last_error'],
                    ];
                }
            }

            return Response::json(['data' => $plugins], 200);
        } catch (\Throwable $e) {
            return Response::error('Failed to list plugins: ' . $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/plugins/{id}/enable - Enable a plugin (filesystem rename)
     *
     * @param Request $request The incoming request.
     * @param array<string, string> $params Route parameters.
     * @return Response
     */
    public function enable(Request $request, array $params): Response
    {
        $id = $params['id'] ?? null;
        if ($id === null || $id === '') {
            return Response::error('Plugin ID required', 400);
        }

        $disabledPath = $this->pluginDir . '/' . $id . '.php.disabled';
        $enabledPath = $this->pluginDir . '/' . $id . '.php';

        if (file_exists($enabledPath)) {
            return Response::json(['data' => ['message' => 'Plugin already enabled']], 200);
        }

        if (!file_exists($disabledPath)) {
            return Response::error('Plugin not found', 404);
        }

        if (rename($disabledPath, $enabledPath)) {
            return Response::json(['data' => ['message' => "Plugin {$id} enabled"]], 200);
        }

        return Response::error('Failed to enable plugin', 500);
    }

    /**
     * POST /api/plugins/{id}/disable - Disable a plugin (filesystem rename)
     *
     * @param Request $request The incoming request.
     * @param array<string, string> $params Route parameters.
     * @return Response
     */
    public function disable(Request $request, array $params): Response
    {
        $id = $params['id'] ?? null;
        if ($id === null || $id === '') {
            return Response::error('Plugin ID required', 400);
        }

        $enabledPath = $this->pluginDir . '/' . $id . '.php';
        $disabledPath = $this->pluginDir . '/' . $id . '.php.disabled';

        if (file_exists($disabledPath)) {
            return Response::json(['data' => ['message' => 'Plugin already disabled']], 200);
        }

        if (!file_exists($enabledPath)) {
            return Response::error('Plugin not found', 404);
        }

        if (rename($enabledPath, $disabledPath)) {
            return Response::json(['data' => ['message' => "Plugin {$id} disabled"]], 200);
        }

        return Response::error('Failed to disable plugin', 500);
    }

    /**
     * POST /api/plugins/{id}/re-enable - Clear the failed state of a plugin
     *
     * Returns an auto-disabled (failed) or administratively disabled plugin to
     * the active state with a clean error counter, so it may serve requests
     * again. Identity is the plugin's runtime key (its original FQCN).
     *
     * @param Request $request The incoming request.
     * @param array<string, string> $params Route parameters (expects 'id').
     * @return Response
     */
    public function reEnable(Request $request, array $params): Response
    {
        $id = $params['id'] ?? null;
        if ($id === null || $id === '') {
            return Response::error('Plugin ID required', 400);
        }

        if ($this->pluginLoader === null) {
            return Response::error('Plugin lifecycle management is unavailable', 503);
        }

        if (!$this->pluginLoader->reEnablePlugin($id)) {
            return Response::error('Plugin not found', 404);
        }

        $lifecycle = $this->pluginLoader->getLifecycle($id);

        return Response::json([
            'data' => [
                'message' => "Plugin {$id} re-enabled",
                'state' => $lifecycle?->getState()->value,
            ],
        ], 200);
    }

    /**
     * POST /api/plugins/reload - Reload plugins (just a success message for now as they hotload)
     *
     * @param Request $request The incoming request.
     * @return Response
     */
    public function reload(Request $request): Response
    {
        return Response::json(['data' => ['message' => 'Plugins reloaded']], 200);
    }

    /**
     * Get lifecycle status snapshots from the loader, if available.
     *
     * @return array<int, array{id: string, name: string, state: string, consecutive_errors: int, last_error: array{message: string, type: string, trace: string, at: int}|null}>
     */
    private function loaderStatuses(): array
    {
        if ($this->pluginLoader === null) {
            return [];
        }

        return $this->pluginLoader->getPluginStatuses();
    }

    /**
     * Index loader statuses by plugin name for matching against on-disk files.
     *
     * @return array<string, array{id: string, name: string, state: string, consecutive_errors: int, last_error: array{message: string, type: string, trace: string, at: int}|null}>
     */
    private function lifecycleStatusByName(): array
    {
        $byName = [];
        foreach ($this->loaderStatuses() as $status) {
            $byName[$status['name']] = $status;
        }

        return $byName;
    }

    /**
     * Resolve the lifecycle fields for a filesystem-listed plugin id.
     *
     * @param string $id The filesystem-derived plugin id.
     * @param array<string, array{id: string, name: string, state: string, consecutive_errors: int, last_error: array{message: string, type: string, trace: string, at: int}|null}> $byName
     * @return array{state?: string, consecutive_errors?: int, last_error?: array{message: string, type: string, trace: string, at: int}|null, lifecycle_id?: string}
     */
    private function matchLifecycle(string $id, array $byName): array
    {
        $status = $byName[$id] ?? null;
        if ($status === null) {
            return [];
        }

        return [
            'state' => $status['state'],
            'consecutive_errors' => $status['consecutive_errors'],
            'last_error' => $status['last_error'],
            'lifecycle_id' => $status['id'],
        ];
    }

    /**
     * Determine whether a loader status was already represented in the listing.
     *
     * @param array{id: string, name: string, state: string, consecutive_errors: int, last_error: array{message: string, type: string, trace: string, at: int}|null} $status
     * @param array<int, array<string, mixed>> $plugins
     * @return bool
     */
    private function statusAlreadyListed(array $status, array $plugins): bool
    {
        foreach ($plugins as $plugin) {
            if (($plugin['lifecycle_id'] ?? null) === $status['id']) {
                return true;
            }
            if (($plugin['name'] ?? null) === $status['name']) {
                return true;
            }
        }

        return false;
    }
}
