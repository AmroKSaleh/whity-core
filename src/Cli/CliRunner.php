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
     * @param array<string, string>|null $commands Overrides the built-in map.
     *
     * Injectable so the argument handling can be tested against a probe rather
     * than against `seed`. Proving that `--help` does not execute must not
     * require executing anything — a test that seeded a database to check that
     * it does not seed would be its own punchline, and running the real
     * destructive commands to prove they are not run is not available at all.
     */
    public function __construct(?array $commands = null)
    {
        if ($commands !== null) {
            $this->commands = $commands;
        }
    }

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
            // Deliberately NOT typed as BaseCommand: only three of the twelve
            // commands extend it, so any method assumed here is a fatal on the
            // other nine. Capability is asked for by interface instead.
            $command = new $commandClass();

            // ASKING A COMMAND ABOUT ITSELF MUST NEVER RUN IT.
            //
            // `whity-cli seed --help` used to SEED. Unrecognised options were
            // ignored rather than rejected and nothing handled `--help`, so the
            // documented way to ask a command what its flags are was the way to
            // make it run. That punishes exactly the reflex an operator should
            // have — checking before writing to a live database.
            //
            // Intercepted HERE, before the command is constructed into any
            // action, and matched ANYWHERE in the arguments rather than in the
            // first position. The commands that already handled `--help` did so
            // as their ACTION, which meant `migrate --help` printed help while
            // `migrate run --help` ran the migrations. `tenant delete --help` is
            // that same shape with consequences that do not undo.
            if (in_array('--help', $argv, true) || in_array('-h', $argv, true)) {
                $printed = $command instanceof \Whity\Cli\Commands\CommandHelp
                    && $command->printHelp($commandName);

                if (!$printed) {
                    echo "No detailed help is written for '{$commandName}'.\n";
                    echo "Usage: whity-cli {$commandName} [options] [arguments]\n";
                }

                return 0;
            }

            $known = $command instanceof \Whity\Cli\Commands\CommandHelp
                ? $command->knownFlags()
                : null;

            $rejected = self::unknownFlag($argv, $known);
            if ($rejected !== null) {
                // Refused rather than ignored: `seed --with-fixture` (singular)
                // otherwise seeds WITHOUT fixtures and reports success, and the
                // operator learns it from what is missing later.
                echo "Unknown option for '{$commandName}': {$rejected}\n";
                echo "Use 'whity-cli {$commandName} --help' to see the options it accepts.\n";

                return 1;
            }

            // A class that serves several command names is told which one was
            // typed; everything else keeps the one-class-one-command shape.
            if ($command instanceof \Whity\Cli\Commands\NamedSubcommand) {
                return $command->execute($argv, $commandName);
            }

            if ($command instanceof \Whity\Cli\Commands\CliCommand) {
                return $command->execute($argv);
            }

            // Refused with a sentence rather than a fatal on an undefined
            // method. This branch is a wiring mistake — a class registered in
            // the map above that implements neither contract — and the person
            // who has to fix it is whoever just added it.
            echo "Command '{$commandName}' is registered to {$commandClass}, which implements "
                . "neither CliCommand nor NamedSubcommand.\n";

            return 1;
        } catch (\Throwable $e) {
            echo "Error: " . $e->getMessage() . "\n";
            return 1;
        }
    }

    /**
     * The first option in `$argv` the command did not declare, or null.
     *
     * Returns null unconditionally when `$known` is null — a command that has
     * not declared its options keeps behaving exactly as it does today. The
     * alternative, treating "undeclared" as "accepts nothing", would break every
     * command that has not been audited yet, which is a worse bug than the one
     * being fixed.
     *
     * Only tokens beginning `-` are considered. Positional arguments are a
     * command's own business: `tenant delete 5` and `migrate rollback` are
     * actions and ids, not options, and this has no way to judge them.
     *
     * A bare `--` ends option parsing by long convention, so everything after it
     * is left alone.
     *
     * @param list<string>      $argv
     * @param list<string>|null $known
     */
    private static function unknownFlag(array $argv, ?array $known): ?string
    {
        if ($known === null) {
            return null;
        }

        foreach ($argv as $arg) {
            if ($arg === '--') {
                return null;
            }
            if ($arg === '' || $arg[0] !== '-') {
                continue;
            }

            // `--flag=value` is the same option as `--flag`.
            $name = str_contains($arg, '=') ? substr($arg, 0, (int) strpos($arg, '=')) : $arg;

            if (!in_array($name, $known, true)) {
                return $name;
            }
        }

        return null;
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
