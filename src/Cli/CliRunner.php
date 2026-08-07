<?php

namespace Whity\Cli;

/**
 * CLI Runner for whity-core
 *
 * Parses command line arguments and dispatches to appropriate command handlers.
 */
class CliRunner
{
    /**
     * @var array<string, string> Map of command names to class names
     */
    private array $commands = [
        'migrate'    => 'Whity\Cli\Commands\MigrationsCommand',
        'plugin'     => 'Whity\Cli\Commands\PluginsCommand',
        'tenant'     => 'Whity\Cli\Commands\TenantsCommand',
        'totp'       => 'Whity\Cli\Commands\TotpCommand',
        'queue:work' => 'Whity\Cli\Commands\QueueWorkCommand',
        'schedule:run' => 'Whity\Cli\Commands\ScheduleRunCommand',
        'scale:seed' => 'Whity\Cli\Commands\ScaleSeedCommand',
    ];

    /**
     * Run the CLI application
     *
     * @param array $argv Command line arguments
     * @return int Exit code
     */
    public function run(array $argv): int
    {
        // Remove the script name
        array_shift($argv);

        if (empty($argv)) {
            $this->showHelp();
            return 0;
        }

        $commandName = array_shift($argv);

        if ($commandName === '--help' || $commandName === '-h' || $commandName === 'help') {
            $this->showHelp();
            return 0;
        }

        if (!isset($this->commands[$commandName])) {
            echo "Unknown command: {$commandName}\n";
            $this->showHelp();
            return 1;
        }

        $commandClass = $this->commands[$commandName];

        try {
            /** @var \Whity\Cli\Commands\BaseCommand $command */
            $command = new $commandClass();
            return $command->execute($argv);
        } catch (\Throwable $e) {
            echo "Error: " . $e->getMessage() . "\n";
            return 1;
        }
    }

    /**
     * Show general help text
     */
    private function showHelp(): void
    {
        echo "Whity Core CLI Tool\n\n";
        echo "Usage:\n";
        echo "  whity-cli <command> [options] [arguments]\n\n";
        echo "Available Commands:\n";
        echo "  migrate    Manage database migrations (status, run, rollback)\n";
        echo "  plugin     Manage plugins (list, enable, disable, reload)\n";
        echo "  tenant     Manage tenants (list, create, update, delete)\n";
        echo "  totp       TOTP secret maintenance (reencrypt legacy secrets)\n";
        echo "  queue:work Run the durable async job worker loop\n";
        echo "  schedule:run Run the cron-tick scheduler (exactly-once per minute across workers)\n";
        echo "  scale:seed Bulk-insert a parameterized, deterministic large-scale multi-tenant dataset\n\n";
        echo "Use 'whity-cli <command> --help' for more information on a specific command.\n";
    }
}
