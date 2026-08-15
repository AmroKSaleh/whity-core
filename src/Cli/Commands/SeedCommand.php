<?php

namespace Whity\Cli\Commands;

use Whity\Database\BootstrapIdentity;
use Whity\Database\Database;
use Whity\Database\Seeder;

/**
 * Seed CLI Command
 *
 * Initializes the database with default data (tenants, roles, accounts).
 * This command is idempotent - running it multiple times won't create
 * duplicates, and it never rewrites an existing account's password.
 *
 * What gets seeded depends on the environment (WC-779): the bootstrap
 * administrator always, the `*@example.com` demo accounts only under
 * APP_ENV=development. `--with-fixtures` forces the demo accounts on anyway,
 * which is the deliberate escape hatch for a staging box that wants them —
 * deliberate being the point, since the alternative was every environment
 * getting them by default.
 *
 * Usage:
 *   php public/index.php seed                  - Seed the database
 *   php public/index.php seed --with-fixtures  - …including the demo accounts
 *   php bin/whity-cli seed                     - Same, via the CLI tool
 */
class SeedCommand
{
    public function execute(array $argv): int
    {
        try {
            $withFixtures = in_array('--with-fixtures', $argv, true) ? true : null;

            $db = Database::connect();

            echo "\n\033[1;33mSeeding database...\033[0m\n";

            // The address the seeder ACTUALLY landed on, which is not always the
            // one INITIAL_SYSTEM_ADMIN_EMAIL names — a rename onto an address
            // another account already holds is refused, and this summary must
            // not contradict the warning that refusal just printed.
            $bootstrapEmail = Seeder::seed($db, $withFixtures);

            $seededFixtures = $withFixtures === true || ($_ENV['APP_ENV'] ?? getenv('APP_ENV')) === 'development';

            echo "\033[0;32m✓ Database successfully seeded\033[0m\n";
            echo "  - Default Tenant created\n";
            echo "  - Bootstrap administrator (system tenant): " . $bootstrapEmail . "\n";
            echo "    Password from INITIAL_SYSTEM_ADMIN_PASSWORD; address from "
                . BootstrapIdentity::EMAIL_ENV_VAR . " (default " . BootstrapIdentity::DEFAULT_EMAIL . ").\n";

            if ($seededFixtures) {
                echo "  - Admin user: admin@example.com\n";
                echo "  - Regular user: user@example.com\n";
                echo "  - Superuser (system tenant): superuser@example.com\n";
                echo "  Passwords are taken from INITIAL_ADMIN_PASSWORD / INITIAL_USER_PASSWORD /\n";
                echo "  INITIAL_SUPERUSER_PASSWORD; if unset, a random password was generated and\n";
                echo "  printed above.\n";
            } else {
                echo "  - Demo accounts (admin@/user@/superuser@example.com) SKIPPED: they are\n";
                echo "    seeded only under APP_ENV=development. Pass --with-fixtures to seed them\n";
                echo "    here deliberately.\n";
            }
            echo "\n";

            return 0;
        } catch (\Exception $e) {
            echo "\033[0;31m✗ Seeding failed: " . $e->getMessage() . "\033[0m\n";
            return 1;
        }
    }
}
