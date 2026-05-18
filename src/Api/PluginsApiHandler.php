<?php

namespace Whity\Api;

use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\PluginLoader;
use Whity\Core\Router;

/**
 * Plugins API Handler
 *
 * Handles plugin discovery, enabling, and disabling.
 */
class PluginsApiHandler
{
    private string $pluginDir;

    public function __construct(string $pluginDir)
    {
        $this->pluginDir = $pluginDir;
    }

    /**
     * GET /api/plugins - List all plugins
     */
    public function list(Request $request): Response
    {
        try {
            $plugins = [];
            $files = scandir($this->pluginDir);

            foreach ($files as $file) {
                if ($file === '.' || $file === '..' || $file === '.gitkeep') {
                    continue;
                }

                $path = $this->pluginDir . '/' . $file;
                $extension = pathinfo($file, PATHINFO_EXTENSION);

                if ($extension === 'php' || (strpos($file, '.php.disabled') !== false)) {
                    $id = str_replace(['.php.disabled', '.php'], '', $file);
                    $enabled = $extension === 'php';

                    $plugins[] = [
                        'id' => $id,
                        'name' => $id, // Use ID as name for now
                        'enabled' => $enabled,
                        'file' => $file
                    ];
                }
            }

            return Response::json(['data' => $plugins], 200);
        } catch (\Exception $e) {
            return Response::error('Failed to list plugins: ' . $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/plugins/{id}/enable - Enable a plugin
     */
    public function enable(Request $request, array $params): Response
    {
        $id = $params['id'] ?? null;
        if (!$id) return Response::error('Plugin ID required', 400);

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
     * POST /api/plugins/{id}/disable - Disable a plugin
     */
    public function disable(Request $request, array $params): Response
    {
        $id = $params['id'] ?? null;
        if (!$id) return Response::error('Plugin ID required', 400);

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
     * POST /api/plugins/reload - Reload plugins (just a success message for now as they hotload)
     */
    public function reload(Request $request): Response
    {
        return Response::json(['data' => ['message' => 'Plugins reloaded']], 200);
    }
}
