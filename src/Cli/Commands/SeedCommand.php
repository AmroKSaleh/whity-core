<?php

namespace Whity\Cli\Commands;

use PDO;
use Whity\Core\Document\Demo\DemoOrganisationSeeder;
use Whity\Core\Document\Demo\DocumentDemoSeeder;
use Whity\Core\Document\DocumentArtifactRepository;
use Whity\Core\Document\DocumentArtifactStore;
use Whity\Core\Document\DocumentBlockRepository;
use Whity\Core\Document\DocumentCollectionRepository;
use Whity\Core\Document\DocumentIssuer;
use Whity\Core\Document\DocumentRepository;
use Whity\Core\Document\DocumentStarterSeeder;
use Whity\Core\Document\DocumentTemplateRepository;
use Whity\Core\Document\Routing\DocumentRouter;
use Whity\Core\Document\Routing\RoleBelowActorRuleResolver;
use Whity\Core\Document\Routing\RoleRuleResolver;
use Whity\Core\Document\Routing\RouteEventRepository;
use Whity\Core\Document\Routing\RouteRecipientRepository;
use Whity\Core\Document\Routing\RouteRepository;
use Whity\Core\Document\Routing\RouteStepRepository;
use Whity\Core\Document\Routing\RoutingRuleRegistry;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;
use Whity\Database\BootstrapIdentity;
use Whity\Database\Database;
use Whity\Database\Seeder;
use Whity\Storage\StorageDriverFactory;

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
 * THE DOCUMENT DEMO RIDES THE SAME GATE
 * ------------------------------------
 * {@see DocumentDemoSeeder} seeds an invented faculty, seven demo logins and
 * five routed documents so the document system's screens can be looked at
 * instead of guessed at. It runs under exactly the condition the
 * `*@example.com` accounts do and for exactly their reason: a production tenant
 * that quietly acquired a "Demo Faculty of Engineering", seven accounts sharing
 * one password and five fake documents in people's inboxes would be a worse
 * accident than the three known-address logins WC-779 was written to prevent.
 *
 * WHY THE SERVICES ARE WIRED HERE AND NOT INSIDE {@see Seeder::seed()}
 * -------------------------------------------------------------------
 * Two reasons, and the second is the load-bearing one.
 *
 *  1. `Seeder::seed()` takes a {@see Database} and touches nothing else.
 *     The document demo needs a storage driver, a settings service and eight
 *     repositories — that is composition, and composition of this platform's
 *     services happens at an entry point (`public/index.php` for requests, here
 *     for the CLI), never inside a domain class.
 *
 *  2. `Seeder::seed()` runs inside the unit suite
 *     ({@see \Whity\Tests\Database\SeederRealEngineTest}) with APP_ENV forced to
 *     `development`, so seeding the demo from there would make a unit test write
 *     PDF bytes to the filesystem through a real storage driver as a side effect
 *     of asserting something about a superuser's role. The demo seeder is tested
 *     directly instead, against a throwaway storage root it cleans up.
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

                foreach ($this->seedDocumentDemo($db) as $line) {
                    echo "  - " . $line . "\n";
                }
            } else {
                echo "  - Demo accounts (admin@/user@/superuser@example.com) SKIPPED: they are\n";
                echo "    seeded only under APP_ENV=development. Pass --with-fixtures to seed them\n";
                echo "    here deliberately.\n";
                echo "  - Document demo data SKIPPED for the same reason.\n";
            }
            echo "\n";

            return 0;
        } catch (\Exception $e) {
            echo "\033[0;31m✗ Seeding failed: " . $e->getMessage() . "\033[0m\n";
            return 1;
        }
    }

    /**
     * Build the document subsystem's real services and seed the demo dataset
     * into the default tenant.
     *
     * @return list<string> Report lines for the caller to print.
     */
    private function seedDocumentDemo(Database $db): array
    {
        $pdo = $db->getPdo();

        $tenant = $this->defaultTenant($db);
        if ($tenant === null) {
            // Cannot happen after Seeder::seed() unless the tenant was renamed
            // out from under it. Reported rather than thrown: the account
            // fixtures above ARE seeded and useful, and losing them to a failed
            // demo would be the wrong trade.
            return ['Document demo SKIPPED: the "' . Seeder::DEFAULT_TENANT_NAME . '" tenant could not be found.'];
        }

        $settings = new SettingsService(
            new GlobalSettingsRepository($pdo),
            new TenantSettingsRepository($pdo)
        );

        // The platform's configured default driver — the same
        // local-unless-storage.driver-says-s3 decision every other consumer
        // makes. The per-tenant routing wrapper (TenantRoutingStorageDriver) is
        // deliberately NOT built here: it resolves a tenant's own bucket only
        // when that tenant holds the `storage.custom_backend` entitlement, which
        // a freshly seeded default tenant does not, so it would resolve to
        // exactly this driver at the cost of an entitlement service and a secret
        // store in the CLI seed path.
        $storageRoot = getenv('STORAGE_ROOT') ?: (dirname(__DIR__, 3) . '/storage');
        $storage = StorageDriverFactory::fromSettings($settings, $_ENV, $storageRoot);

        $templates = new DocumentTemplateRepository($pdo);
        $blocks = new DocumentBlockRepository($pdo);
        $documents = new DocumentRepository($pdo);

        $rules = new RoutingRuleRegistry();
        $rules->registerCoreRoutingRules(
            new RoleRuleResolver($pdo),
            new RoleBelowActorRuleResolver($pdo)
        );

        $seeder = new DocumentDemoSeeder(
            $pdo,
            new DemoOrganisationSeeder($pdo),
            new DocumentStarterSeeder($templates, $blocks),
            $templates,
            $blocks,
            $documents,
            new DocumentIssuer(
                $pdo,
                $documents,
                new DocumentArtifactRepository($pdo),
                new DocumentArtifactStore($storage)
            ),
            new DocumentRouter(
                $pdo,
                new RouteRepository($pdo),
                new RouteStepRepository($pdo),
                new RouteEventRepository($pdo),
                new RouteRecipientRepository($pdo),
                $rules,
                $settings,
                // No HookManager, deliberately. The router's only use for one is
                // to broadcast each appended trail event onto the durable spine
                // through dispatchAsync — which enqueues the outbox relay and
                // runs any registered plugin listener. A seed run must not send
                // seven people a notification about a circular that does not
                // exist, and there is no plugin bootstrap in the CLI seed path
                // for those listeners to have come from anyway. The trail itself
                // is the system of record and is written either way.
                null
            ),
            new DocumentCollectionRepository($pdo)
        );

        return $seeder->seedForTenant((int) $tenant['id'], (string) $tenant['name']);
    }

    /**
     * The tenant {@see Seeder::seed()} just created or found.
     *
     * @return array{id: int, name: string}|null
     */
    private function defaultTenant(Database $db): ?array
    {
        // @tenant-guard-ignore: `tenants` is the tenant registry itself, not a tenant-owned table
        $row = $db->query(
            'SELECT id, name FROM tenants WHERE name = :name',
            [':name' => Seeder::DEFAULT_TENANT_NAME]
        )->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return null;
        }

        return ['id' => (int) $row['id'], 'name' => (string) $row['name']];
    }
}
