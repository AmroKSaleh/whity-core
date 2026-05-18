<?php

namespace Whity\Cli\Commands;

/**
 * Plugins management command
 */
class PluginsCommand extends BaseCommand
{
    /**
     * Execute the command
     *
     * @param array $argv Command arguments
     * @return int Exit code
     */
    public function execute(array $argv): int
    {
        $action = array_shift($argv) ?: 'list';

        return match ($action) {
            'list' => $this->list(),
            'enable' => $this->enable($argv),
            'disable' => $this->disable($argv),
            'reload' => $this->reload(),
            '--help', '-h', 'help' => $this->showHelp(),
            default => $this->unknownAction($action),
        };
    }

    /**
     * List plugins
     */
    private function list(): int
    {
        $response = $this->callApi('GET', '/api/plugins');

        if ($response->getStatusCode() !== 200) {
            // Fallback: If endpoint doesn't exist yet, we might want to implement it or use local logic
            // But let's try to follow the "wrapper around endpoints" instruction.
            echo "Error: " . $response->getBody() . "\n";
            return 1;
        }

        $data = json_decode($response->getBody(), true);
        $plugins = $data['data'] ?? [];

        $headers = ['ID', 'Name', 'Status', 'Route', 'Method'];
        $rows = array_map(function ($plugin) {
            return [
                $plugin['id'],
                $plugin['name'],
                $plugin['enabled'] ? 'Enabled' : 'Disabled',
                $plugin['route'] ?? '-',
                $plugin['method'] ?? '-',
            ];
        }, $plugins);

        $this->renderTable($headers, $rows);
        return 0;
    }

    /**
     * Enable a plugin
     */
    private function enable(array $argv): int
    {
        if (empty($argv)) {
            echo "Error: Missing plugin ID.\n";
            $this->showHelp();
            return 1;
        }

        $id = array_shift($argv);

        $response = $this->callApi('POST', "/api/plugins/{$id}/enable");

        if ($response->getStatusCode() === 200) {
            echo "Plugin '{$id}' enabled successfully.\n";
            return 0;
        }

        echo "Error: " . $response->getBody() . "\n";
        return 1;
    }

    /**
     * Disable a plugin
     */
    private function disable(array $argv): int
    {
        if (empty($argv)) {
            echo "Error: Missing plugin ID.\n";
            $this->showHelp();
            return 1;
        }

        $id = array_shift($argv);

        $response = $this->callApi('POST', "/api/plugins/{$id}/disable");

        if ($response->getStatusCode() === 200) {
            echo "Plugin '{$id}' disabled successfully.\n";
            return 0;
        }

        echo "Error: " . $response->getBody() . "\n";
        return 1;
    }

    /**
     * Reload plugins
     */
    private function reload(): int
    {
        // This might be a special operation, or just list again
        echo "Reloading plugins...\n";
        $response = $this->callApi('POST', '/api/plugins/reload');

        if ($response->getStatusCode() === 200) {
            echo "Plugins reloaded successfully.\n";
            return 0;
        }

        echo "Error: " . $response->getBody() . "\n";
        return 1;
    }

    /**
     * Show help for plugins command
     */
    private function showHelp(): int
    {
        echo "Usage: whity-cli plugin <action> [arguments]\n\n";
        echo "Actions:\n";
        echo "  list               List all plugins\n";
        echo "  enable <id>        Enable a plugin\n";
        echo "  disable <id>       Disable a plugin\n";
        echo "  reload             Reload discovered plugins\n";
        return 0;
    }

    /**
     * Handle unknown action
     */
    private function unknownAction(string $action): int
    {
        echo "Unknown plugin action: {$action}\n";
        $this->showHelp();
        return 1;
    }
}
