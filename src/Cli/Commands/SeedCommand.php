<?php

namespace Whity\Cli\Commands;

use PDO;
use Whity\Core\Audience\ExplicitRuleResolver;
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
use Whity\Core\Document\Qr\DocumentQrScanRepository;
use Whity\Core\Document\Qr\DocumentQrService;
use Whity\Core\Document\Qr\DocumentQrTokenRepository;
use Whity\Core\Document\RouteTemplate\RouteTemplateGraph;
use Whity\Core\Document\RouteTemplate\RouteTemplateRepository;
use Whity\Core\Document\Routing\DocumentRouter;
use Whity\Core\Document\Routing\RoleBelowActorRuleResolver;
use Whity\Core\Document\Routing\RoleRuleResolver;
use Whity\Core\Document\Routing\RouteEdgeRepository;
use Whity\Core\Document\Routing\RouteEventRepository;
use Whity\Core\Document\Routing\RouteRecipientRepository;
use Whity\Core\Document\Routing\RouteRepository;
use Whity\Core\Document\Routing\RouteStepRepository;
use Whity\Core\Document\Routing\RoutingRuleRegistry;
use Whity\Core\Group\GroupResolver;
use Whity\Core\Group\GroupRuleResolver;
use Whity\Core\Group\UserGroupRepository;
use Whity\Core\Identity\ProfileProvisioner;
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
 * THE DOCUMENT DEMO HAS A GATE OF ITS OWN: --with-document-demo
 * ------------------------------------------------------------
 * {@see DocumentDemoSeeder} seeds an invented faculty, eight demo logins, a set
 * of route designs and a folder of routed documents — one per state the screens
 * distinguish between — so the document system can be looked at instead of
 * guessed at. It is OFF by default in EVERY environment, development included;
 * only `--with-document-demo` turns it on.
 *
 * It first rode `--with-fixtures`, and the way that was wrong is the argument for
 * the split. The two flags answer different questions:
 *
 *   --with-fixtures       demo ACCOUNTS — infrastructure other things need. The
 *                         E2E suite runs migrate + seed in a development
 *                         environment precisely because it must log in as
 *                         admin@example.com.
 *   --with-document-demo  demo CONTENT — illustration for a person who wants
 *                         something to click through. Nothing depends on it.
 *
 * Sharing one flag meant the E2E baseline silently acquired the demo dataset, and
 * it broke specs that had nothing to do with documents: eight demo memberships
 * pushed admin@example.com off the first page of a users table that paginates at
 * ten and orders newest-membership-first, so two specs asserting on that one cell
 * failed. That is the shape of the problem rather than an accident of it — a
 * shared gate leaves every future change to this seeder able to break an
 * unrelated spec, and pushes each spec towards carrying knowledge of whatever
 * the seeder happens to create.
 *
 * The gate covers the demo ORGANISATION as well as its documents, and the failure
 * above is exactly why: the rows that broke E2E were PEOPLE, not documents, so a
 * flag over only the documents would have fixed nothing.
 *
 * Off even in development, deliberately. "Development" is not one audience — it
 * is a person clicking through a UI, and it is also a CI job booting a stack to
 * run Playwright against. Only the first wants demo content, and a human asking
 * for it by name is the only signal that tells them apart.
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
 *   php public/index.php seed                       - Seed the database
 *   php public/index.php seed --with-fixtures       - …including the demo accounts
 *   php public/index.php seed --with-document-demo  - …including the document demo dataset
 *   php bin/whity-cli seed                          - Same, via the CLI tool
 */
class SeedCommand
{
    /**
     * The flag that turns the document demo dataset on.
     *
     * A constant because three places name it: the parser below, the usage block
     * above, and the test that pins the gate.
     */
    public const DOCUMENT_DEMO_FLAG = '--with-document-demo';

    public function execute(array $argv): int
    {
        try {
            $withFixtures = in_array('--with-fixtures', $argv, true) ? true : null;
            $withDocumentDemo = self::wantsDocumentDemo($argv);

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

            if ($withDocumentDemo) {
                foreach ($this->seedDocumentDemo($db) as $line) {
                    echo "  - " . $line . "\n";
                }
            } else {
                echo "  - Document demo data SKIPPED: pass " . self::DOCUMENT_DEMO_FLAG . " for an\n";
                echo "    invented faculty, its people, three route designs and a routed document\n";
                echo "    per state the screens distinguish between. Off by default in\n";
                echo "    EVERY environment, development included — nothing depends on it, so it is\n";
                echo "    seeded only because somebody wants something to click through.\n";
            }
            echo "\n";

            return 0;
        } catch (\Exception $e) {
            echo "\033[0;31m✗ Seeding failed: " . $e->getMessage() . "\033[0m\n";
            return 1;
        }
    }

    /**
     * Whether this invocation asked for the document demo dataset.
     *
     * A named predicate rather than an inline `in_array`, and public rather than
     * private, so the GATE ITSELF is testable without a database — see
     * {@see \Whity\Tests\Cli\SeedCommandTest}. The regression it guards is the
     * one that already happened: demo content riding a flag that exists for
     * something else, which nothing failed on until an unrelated E2E spec did.
     *
     * No environment fallback, deliberately. Unlike the `*@example.com`
     * accounts, this is never implied by `APP_ENV=development` — see the class
     * docblock for why "development" is not a single audience.
     *
     * @param list<string> $argv
     */
    public static function wantsDocumentDemo(array $argv): bool
    {
        return in_array(self::DOCUMENT_DEMO_FLAG, $argv, true);
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

        // All four core kinds, the same set and the same construction order
        // BaseCommand uses (#999) — the `group` resolver needs the group
        // repository, and the group resolver needs THIS registry to resolve
        // whatever kind a group is defined as, so the cycle is broken with a
        // closure. A seeder registering a narrower registry than the app runs
        // would produce demo data the app cannot re-derive.
        $rules = new RoutingRuleRegistry();
        $groupRepository = new UserGroupRepository($pdo);
        $groupResolver = new GroupResolver(
            $pdo,
            $groupRepository,
            static fn (): RoutingRuleRegistry => $rules
        );
        $rules->registerCoreRoutingRules(
            new RoleRuleResolver($pdo),
            new RoleBelowActorRuleResolver($pdo),
            new ExplicitRuleResolver(),
            new GroupRuleResolver($groupResolver)
        );

        // ONE token repository, handed to both the service that writes through it
        // and the seeder that makes one read the service does not expose. Two
        // instances would work identically and would be two things to keep in
        // step for no reason.
        $qrTokens = new DocumentQrTokenRepository($pdo);

        $seeder = new DocumentDemoSeeder(
            $pdo,
            // The identity seam, not an INSERT of our own: see
            // DemoOrganisationSeeder for why a hand-rolled profile row is the
            // same mistake as a hand-rolled recipient row.
            new DemoOrganisationSeeder($pdo, new ProfileProvisioner($pdo)),
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
                new RouteEdgeRepository($pdo),
                $rules,
                $settings,
                // No HookManager, deliberately. The router's only use for one is
                // to broadcast each appended trail event onto the durable spine
                // through dispatchAsync — which enqueues the outbox relay and
                // runs any registered plugin listener. A seed run must not send
                // eight people a notification about a circular that does not
                // exist, and there is no plugin bootstrap in the CLI seed path
                // for those listeners to have come from anyway. The trail itself
                // is the system of record and is written either way.
                null
            ),
            new DocumentCollectionRepository($pdo),
            // #1056: route DESIGNS, and the validator the editor itself runs.
            // The graph goes in through `RouteTemplateGraph` rather than
            // straight into the repository so a seeded design is by construction
            // one `PUT /graph` would have accepted — a demo whose whole point is
            // that somebody opens the canvas on it must not contain a canvas the
            // canvas cannot save.
            new RouteTemplateRepository($pdo),
            new RouteTemplateGraph($rules),
            // Already built above for the router's quorum ladder; passed on
            // rather than rebuilt, so the seeder cannot read a different
            // settings chain from the engine it is driving.
            $settings,
            // #1036: the verification code. The public base URL is APP_URL, the
            // same value `public/index.php` hands this service — read the same
            // way, and EMPTY is a real state that the service answers by
            // refusing to mint rather than by encoding a relative URL into a
            // code nothing can follow.
            new DocumentQrService(
                $pdo,
                $qrTokens,
                new DocumentQrScanRepository($pdo),
                rtrim((string) ($_ENV['APP_URL'] ?? getenv('APP_URL') ?: ''), '/')
            ),
            $qrTokens
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
