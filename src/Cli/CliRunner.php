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
        // Registered here as well as in public/index.php (WC-779): `migrate` was
        // reachable through this tool and `seed` was not, so the only bootstrap
        // an operator following the CLI could produce was the migration-seeded
        // administrator — the one account nothing let them configure.
        'seed'       => 'Whity\Cli\Commands\SeedCommand',
        'plugin'     => 'Whity\Cli\Commands\PluginsCommand',
        'tenant'     => 'Whity\Cli\Commands\TenantsCommand',
        'totp'       => 'Whity\Cli\Commands\TotpCommand',
        'queue:work' => 'Whity\Cli\Commands\QueueWorkCommand',
        'schedule:run' => 'Whity\Cli\Commands\ScheduleRunCommand',
        'scale:seed' => 'Whity\Cli\Commands\ScaleSeedCommand',
        'health:watch' => 'Whity\Cli\Commands\HealthWatchCommand',
        'i18n:extract' => 'Whity\Cli\Commands\I18nCommand',
        'i18n:sync' => 'Whity\Cli\Commands\I18nCommand',
        'i18n:coverage' => 'Whity\Cli\Commands\I18nCommand',
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

            // A class that serves several command names is told which one was
            // typed; everything else keeps the one-class-one-command shape.
            if ($command instanceof \Whity\Cli\Commands\NamedSubcommand) {
                return $command->execute($argv, $commandName);
            }

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
        echo "  seed       Seed default tenant/roles/accounts (--with-fixtures for the demo logins)\n";
        echo "  plugin     Manage plugins (list, enable, disable, reload)\n";
        echo "  tenant     Manage tenants (list, create, update, delete)\n";
        echo "  totp       TOTP secret maintenance (reencrypt legacy secrets)\n";
        echo "  queue:work Run the durable async job worker loop\n";
        echo "  schedule:run Run the cron-tick scheduler (exactly-once per minute across workers)\n";
        echo "  scale:seed Bulk-insert a parameterized, deterministic large-scale multi-tenant dataset\n";
        echo "  health:watch Sample service health for the public /status page (runs outside the app)\n";
        echo "  i18n:extract Rebuild the English translation catalogue from the t() calls in the source\n";
        echo "  i18n:sync    Seed catalogue keys missing from the translations table (never overwrites)\n";
        echo "  i18n:coverage Per-domain translated/missing counts for every committed language\n\n";
        echo "Use 'whity-cli <command> --help' for more information on a specific command.\n";
    }
}
