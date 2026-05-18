<?php

namespace Whity\Cli\Commands;

/**
 * Tenants management command
 */
class TenantsCommand extends BaseCommand
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
            'create' => $this->create($argv),
            'update' => $this->update($argv),
            'delete' => $this->delete($argv),
            '--help', '-h', 'help' => $this->showHelp(),
            default => $this->unknownAction($action),
        };
    }

    /**
     * List tenants
     */
    private function list(): int
    {
        $response = $this->callApi('GET', '/api/tenants');

        if ($response->getStatusCode() !== 200) {
            echo "Error: " . $response->getBody() . "\n";
            return 1;
        }

        $data = json_decode($response->getBody(), true);
        $tenants = $data['data'] ?? [];

        $headers = ['ID', 'Name', 'Slug', 'Users', 'Created At'];
        $rows = array_map(function ($tenant) {
            return [
                $tenant['id'],
                $tenant['name'],
                $tenant['slug'],
                $tenant['userCount'] ?? 0,
                $tenant['created_at'],
            ];
        }, $tenants);

        $this->renderTable($headers, $rows);
        return 0;
    }

    /**
     * Create a tenant
     */
    private function create(array $argv): int
    {
        if (empty($argv)) {
            echo "Error: Missing tenant name.\n";
            $this->showHelp();
            return 1;
        }

        $name = array_shift($argv);
        $options = $this->parseOptions($argv);
        $slug = $options['slug'] ?? null;

        $response = $this->callApi('POST', '/api/tenants', [
            'name' => $name,
            'slug' => $slug
        ]);

        if ($response->getStatusCode() === 201) {
            $data = json_decode($response->getBody(), true);
            echo "Tenant created successfully: " . $data['data']['name'] . " (ID: " . $data['data']['id'] . ")\n";
            return 0;
        }

        echo "Error: " . $response->getBody() . "\n";
        return 1;
    }

    /**
     * Update a tenant
     */
    private function update(array $argv): int
    {
        if (empty($argv)) {
            echo "Error: Missing tenant ID.\n";
            $this->showHelp();
            return 1;
        }

        $id = array_shift($argv);
        $options = $this->parseOptions($argv);

        if (empty($options)) {
            echo "Error: No updates provided. Use --name or --slug.\n";
            return 1;
        }

        $response = $this->callApi('PATCH', "/api/tenants/{$id}", $options);

        if ($response->getStatusCode() === 200) {
            echo "Tenant updated successfully.\n";
            return 0;
        }

        echo "Error: " . $response->getBody() . "\n";
        return 1;
    }

    /**
     * Delete a tenant
     */
    private function delete(array $argv): int
    {
        if (empty($argv)) {
            echo "Error: Missing tenant ID.\n";
            $this->showHelp();
            return 1;
        }

        $id = array_shift($argv);

        $response = $this->callApi('DELETE', "/api/tenants/{$id}");

        if ($response->getStatusCode() === 200) {
            echo "Tenant deleted successfully.\n";
            return 0;
        }

        echo "Error: " . $response->getBody() . "\n";
        return 1;
    }

    /**
     * Parse command line options (--key=value)
     */
    private function parseOptions(array $argv): array
    {
        $options = [];
        foreach ($argv as $arg) {
            if (strpos($arg, '--') === 0) {
                $parts = explode('=', substr($arg, 2), 2);
                $key = $parts[0];
                $value = $parts[1] ?? true;
                $options[$key] = $value;
            }
        }
        return $options;
    }

    /**
     * Show help for tenants command
     */
    private function showHelp(): int
    {
        echo "Usage: whity-cli tenant <action> [arguments]\n\n";
        echo "Actions:\n";
        echo "  list                        List all tenants\n";
        echo "  create <name> [--slug=s]    Create a new tenant\n";
        echo "  update <id> [--name=n] ...  Update a tenant\n";
        echo "  delete <id>                 Delete a tenant\n";
        return 0;
    }

    /**
     * Handle unknown action
     */
    private function unknownAction(string $action): int
    {
        echo "Unknown tenant action: {$action}\n";
        $this->showHelp();
        return 1;
    }
}
