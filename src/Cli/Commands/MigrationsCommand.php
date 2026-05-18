<?php

namespace Whity\Cli\Commands;

/**
 * Migrations management command
 */
class MigrationsCommand extends BaseCommand
{
    /**
     * Execute the command
     *
     * @param array $argv Command arguments
     * @return int Exit code
     */
    public function execute(array $argv): int
    {
        $action = array_shift($argv) ?: 'status';

        return match ($action) {
            'status' => $this->status(),
            'run' => $this->runMigrations(),
            'rollback' => $this->rollback(),
            '--help', '-h', 'help' => $this->showHelp(),
            default => $this->unknownAction($action),
        };
    }

    /**
     * Show migration status
     */
    private function status(): int
    {
        $response = $this->callApi('GET', '/api/migrations');

        if ($response->getStatusCode() !== 200) {
            echo "Error: " . $response->getBody() . "\n";
            return 1;
        }

        $data = json_decode($response->getBody(), true);
        $migrations = $data['data'] ?? [];

        $headers = ['Migration Name', 'Executed At', 'Status'];
        $rows = array_map(function ($migration) {
            return [
                $migration['name'],
                $migration['executed_at'] ?? 'Never',
                $migration['executed'] ? 'Executed' : 'Pending',
            ];
        }, $migrations);

        $this->renderTable($headers, $rows);
        return 0;
    }

    /**
     * Run pending migrations
     */
    private function runMigrations(): int
    {
        echo "Running pending migrations...\n";
        $response = $this->callApi('POST', '/api/migrations/run');

        if ($response->getStatusCode() === 200) {
            $data = json_decode($response->getBody(), true);
            $count = $data['data']['count'] ?? 0;
            echo "Successfully ran {$count} migration(s).\n";
            return 0;
        }

        echo "Error: " . $response->getBody() . "\n";
        return 1;
    }

    /**
     * Rollback the last migration
     */
    private function rollback(): int
    {
        echo "Rolling back last migration...\n";
        $response = $this->callApi('POST', '/api/migrations/rollback');

        if ($response->getStatusCode() === 200) {
            echo "Successfully rolled back.\n";
            return 0;
        }

        echo "Error: " . $response->getBody() . "\n";
        return 1;
    }

    /**
     * Show help for migrations command
     */
    private function showHelp(): int
    {
        echo "Usage: whity-cli migrate <action>\n\n";
        echo "Actions:\n";
        echo "  status       Show status of all migrations\n";
        echo "  run          Run all pending migrations\n";
        echo "  rollback     Rollback the last migration\n";
        return 0;
    }

    /**
     * Handle unknown action
     */
    private function unknownAction(string $action): int
    {
        echo "Unknown migration action: {$action}\n";
        $this->showHelp();
        return 1;
    }
}
