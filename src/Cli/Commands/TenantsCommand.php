<?php

namespace Whity\Cli\Commands;

/**
 * Tenants management command
 */
class TenantsCommand extends BaseCommand implements CliCommand
{
    /**
     * The help this command already wrote, routed through the interface.
     *
     * `showHelp()` used to be reached as an ACTION — `TenantsCommand --help` matched it
     * in the first position. That is why `TenantsCommand <action> --help` ran the
     * action instead: the flag was never in the position that matched. The runner
     * now intercepts it anywhere, so this is how the text still gets printed.
     */
    public function printHelp(string $commandName): bool
    {
        $this->showHelp();

        return true;
    }

    /** @return list<string>|null */
    public function knownFlags(): ?array
    {
        // Not declared yet: this command has not been audited for the full set
        // of options it accepts, and null means "do not validate" rather than
        // "accepts nothing". Declaring an incomplete list here would reject
        // flags that currently work.
        return null;
    }

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
                // The API shapes this row into the PUBLIC contract, which is
                // camelCase (WC-122's toPublicTenant). This read stayed
                // snake_case and emitted an undefined-key warning with a blank
                // column — invisible for as long as #928 made the command
                // unreachable, and visible on the first run after it was fixed.
                $tenant['createdAt'] ?? $tenant['created_at'] ?? 'N/A',
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
