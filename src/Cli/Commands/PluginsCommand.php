<?php

declare(strict_types=1);

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
            'uninstall' => $this->uninstall($argv),
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
     * Uninstall a plugin (disable + rollback migrations + remove directory).
     *
     * Flags:
     *   --dry-run  Print what would happen without mutating anything.
     *   --force    Remove directory even if migration rollback had errors.
     *
     * @param array<int, string> $argv
     */
    private function uninstall(array $argv): int
    {
        $id = null;
        $dryRun = false;
        $force = false;

        foreach ($argv as $arg) {
            if ($arg === '--dry-run') {
                $dryRun = true;
            } elseif ($arg === '--force') {
                $force = true;
            } elseif ($id === null && !str_starts_with($arg, '--')) {
                $id = $arg;
            }
        }

        if ($id === null || $id === '') {
            echo "Error: Missing plugin ID.\n";
            $this->showHelp();
            return 1;
        }

        $body = ['dry_run' => $dryRun, 'force' => $force];
        $response = $this->callApi('POST', "/api/plugins/{$id}/uninstall", $body);
        $statusCode = $response->getStatusCode();
        $payload = json_decode($response->getBody(), true);
        $data = is_array($payload) && isset($payload['data']) && is_array($payload['data'])
            ? $payload['data']
            : [];

        if ($dryRun) {
            echo "Dry-run plan for uninstalling plugin '{$id}':\n\n";

            $migrations = (array) ($data['migrations_to_roll_back'] ?? []);
            $migrationsLabel = empty($migrations) ? '(none)' : implode(', ', $migrations);
            $willRemove = ($data['will_remove_directory'] ?? false) ? 'yes' : 'no';

            $this->renderTable(
                ['Field', 'Value'],
                [
                    ['Plugin', (string) ($data['plugin'] ?? $id)],
                    ['Status', (string) ($data['status'] ?? '-')],
                    ['Migrations to roll back', $migrationsLabel],
                    ['Directory', (string) ($data['directory'] ?? '(unknown)')],
                    ['Will remove directory', $willRemove],
                ]
            );

            return 0;
        }

        if ($statusCode !== 200) {
            $errors = (array) ($data['errors'] ?? []);
            echo "Error uninstalling plugin '{$id}':\n";
            foreach ($errors as $err) {
                echo "  - {$err}\n";
            }
            if ($errors === []) {
                echo $response->getBody() . "\n";
            }
            return 1;
        }

        $rolled = count((array) ($data['migrations_rolled_back'] ?? []));
        $removed = ($data['directory_removed'] ?? false) ? 'yes' : 'no';

        echo "Plugin '{$id}' uninstalled successfully.\n";
        echo "  Migrations rolled back : {$rolled}\n";
        echo "  Directory removed      : {$removed}\n";

        return 0;
    }

    /**
     * Show help for plugins command
     */
    private function showHelp(): int
    {
        echo "Usage: whity-cli plugin <action> [arguments]\n\n";
        echo "Actions:\n";
        echo "  list                                  List all plugins\n";
        echo "  enable <id>                           Enable a plugin\n";
        echo "  disable <id>                          Disable a plugin\n";
        echo "  reload                                Reload discovered plugins\n";
        echo "  uninstall <id> [--dry-run] [--force]  Uninstall a plugin\n";
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
