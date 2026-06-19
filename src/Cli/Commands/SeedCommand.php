<?php

namespace Whity\Cli\Commands;

use Whity\Database\Database;
use Whity\Database\Seeder;

/**
 * Seed CLI Command
 *
 * Initializes the database with default data (tenants, roles, users).
 * This command is idempotent - running it multiple times won't create duplicates.
 *
 * Usage:
 *   php public/index.php seed - Seed the database with default data
 */
class SeedCommand
{
    public function execute(array $argv): int
    {
        try {
            $db = Database::connect();

            echo "\n\033[1;33mSeeding database...\033[0m\n";

            Seeder::seed($db);

            echo "\033[0;32m✓ Database successfully seeded\033[0m\n";
            echo "  - Default Tenant created\n";
            echo "  - Admin user: admin@example.com\n";
            echo "  - Regular user: user@example.com\n";
            echo "  - Superuser (system tenant): superuser@example.com\n";
            echo "  Passwords are taken from INITIAL_ADMIN_PASSWORD / INITIAL_USER_PASSWORD /\n";
            echo "  INITIAL_SUPERUSER_PASSWORD; if unset, a random password was generated and\n";
            echo "  printed above.\n\n";

            return 0;
        } catch (\Exception $e) {
            echo "\033[0;31m✗ Seeding failed: " . $e->getMessage() . "\033[0m\n";
            return 1;
        }
    }
}
