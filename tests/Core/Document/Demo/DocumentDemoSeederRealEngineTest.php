<?php

declare(strict_types=1);

namespace Tests\Core\Document\Demo;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Auth\RoleChecker;
use Whity\Core\Audience\ExplicitRuleResolver;
use Whity\Core\Document\Demo\DemoOrganisationSeeder;
use Whity\Core\Document\Demo\DemoPdf;
use Whity\Core\Document\Demo\DocumentDemoSeeder;
use Whity\Core\Document\DocumentAccessPolicy;
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
use Whity\Core\Document\Qr\QrRevocationReason;
use Whity\Core\Document\RouteTemplate\RouteTemplateGraph;
use Whity\Core\Document\RouteTemplate\RouteTemplateRepository;
use Whity\Core\Document\Routing\DocumentRouter;
use Whity\Core\Document\Routing\RoleBelowActorRuleResolver;
use Whity\Core\Document\Routing\RoleRuleResolver;
use Whity\Core\Document\Routing\RouteAction;
use Whity\Core\Document\Routing\RouteEdgeRepository;
use Whity\Core\Document\Routing\RouteEventRepository;
use Whity\Core\Document\Routing\RouteQuorum;
use Whity\Core\Document\Routing\RouteRecipientRepository;
use Whity\Core\Document\Routing\RouteRepository;
use Whity\Core\Document\Routing\RouteSatisfaction;
use Whity\Core\Document\Routing\RouteStepRepository;
use Whity\Core\Document\Routing\RouteVerdict;
use Whity\Core\Document\Routing\RoutingRuleRegistry;
use Whity\Core\Group\GroupResolver;
use Whity\Core\Group\GroupRuleResolver;
use Whity\Core\Group\UserGroupRepository;
use Whity\Core\Ou\OuReachResolver;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\RBAC\ResourceRoleAssignmentRepository;
use Whity\Core\RBAC\ResourceTypeRegistry;
use Whity\Core\RBAC\ScopedPermissionSet;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;
use Whity\Database\Database;

/**
 * Real-engine tests for {@see DocumentDemoSeeder}.
 *
 * WHAT IS WORTH ASSERTING ABOUT A SEEDER
 * --------------------------------------
 * Not that it inserted rows — it plainly does, and a count is not a claim. What
 * matters is that the STATES IT EXISTS TO MAKE VISIBLE are actually distinct in
 * what it produced, because a demo dataset whose cases have quietly collapsed
 * into each other is worse than no dataset: every screen still renders, and the
 * distinction the reader came to see is simply not there.
 *
 * So each test below names a pair of things that must DIFFER, and would pass
 * trivially if the fixture drifted into producing only one of them:
 *
 *   - the two secretaries' template sets (identical role, identical permission);
 *   - the technician's template set against the secretary's IN THE SAME UNIT —
 *     the one comparison the fixture used to fail, where reach is held constant
 *     and only the capability differs;
 *   - an awaiting document against an acted-on one;
 *   - within ONE document, an acted recipient beside an unacted one at the same
 *     step — the state #1000's per-step counts exist for;
 *   - a document with two artifacts against the ones with one;
 *   - "raised by my unit", "below my unit" and "passed through my unit" as three
 *     different answers at the same anchor.
 *
 * Plus idempotency, which is the property `seed` is run against dev databases
 * on the strength of, and two guards on the fixture's LEGIBILITY: that every
 * demo role can name a unit, a person and a role rather than printing their ids,
 * and that no designer row is gated on a permission every demo role holds —
 * which is what stops the next well-meant grant flattening the distinctions
 * above without failing anything.
 *
 * ENGINE. {@see SchemaFromMigrations::make()} returns real PostgreSQL when
 * PHPUNIT_PG_DSN is set and SQLite otherwise, and both are worth having here:
 * the routing states this fixture builds depend on migration 112's partial
 * unique index, which only a real engine enforces, while the SQLite path is what
 * the unit job actually runs.
 *
 * STORAGE. A real {@see \Whity\Storage\LocalStorageDriver} over a throwaway
 * directory, not a fake. The artifacts are the part of the fixture that leaves
 * the database, and a stub would let a seeder that writes no bytes pass.
 */
final class DocumentDemoSeederRealEngineTest extends TestCase
{
    private const TENANT = 1;

    /** Deterministic, >= 32 characters (project secret policy). */
    private const DEMO_PASSWORD = 'demo-seeder-fixture-password-0123456789';

    /**
     * A non-empty public origin, so {@see DocumentQrService::isConfigured()} is
     * true. With an empty one the service refuses to mint, every verification
     * assertion below would be asserting nothing, and the suite would go green
     * over a fixture that had not been seeded.
     */
    private const PUBLIC_URL = 'https://demo.example.test';

    private PDO $pdo;
    private Database $db;
    private string $storageRoot;
    private DocumentDemoSeeder $seeder;
    private DocumentTemplateRepository $templates;
    private DocumentBlockRepository $blocks;
    private DocumentRepository $documents;
    private RouteTemplateRepository $routeTemplates;
    private DocumentQrTokenRepository $qrTokens;
    private DocumentRouter $router;

    protected function setUp(): void
    {
        // Process-level permission caches are per FrankenPHP worker in
        // production and per test here; a grant this fixture writes must not be
        // read through a cache another test warmed.
        RoleChecker::clearCache();

        $_ENV[DemoOrganisationSeeder::PASSWORD_ENV_VAR] = self::DEMO_PASSWORD;
        putenv(DemoOrganisationSeeder::PASSWORD_ENV_VAR . '=' . self::DEMO_PASSWORD);

        $this->pdo = SchemaFromMigrations::make();
        $this->pdo->exec("INSERT INTO tenants (id, name) VALUES (1, 'Demo Tenant')");
        // The tenant is seeded at an EXPLICIT id, which moves SQLite's counter
        // and does NOT move PostgreSQL's SERIAL sequence — so every id-less
        // insert after it would collide on the real engine. Unconditional; a
        // no-op on SQLite.
        SchemaFromMigrations::syncSequences($this->pdo);

        $this->db = Database::withFactory(fn (): PDO => $this->pdo, 86400, 86400);
        $this->db->forceConnect();

        $this->storageRoot = sys_get_temp_dir() . '/whity-doc-demo-' . bin2hex(random_bytes(6));

        $this->templates = new DocumentTemplateRepository($this->pdo);
        $this->blocks = new DocumentBlockRepository($this->pdo);
        $this->documents = new DocumentRepository($this->pdo);
        $this->routeTemplates = new RouteTemplateRepository($this->pdo);
        $this->qrTokens = new DocumentQrTokenRepository($this->pdo);

        $settings = new SettingsService(
            new GlobalSettingsRepository($this->pdo),
            new TenantSettingsRepository($this->pdo)
        );

        // All four core kinds, wired the way SeedCommand wires them (#999) — the
        // seeder under test must be handed the registry the CLI hands it, or a
        // demo that only the test's narrower registry can produce passes here
        // and fails on `php public/index.php seed`.
        $rules = new RoutingRuleRegistry();
        $groupRepository = new UserGroupRepository($this->pdo);
        $groupResolver = new GroupResolver(
            $this->pdo,
            $groupRepository,
            static fn (): RoutingRuleRegistry => $rules
        );
        $rules->registerCoreRoutingRules(
            new RoleRuleResolver($this->pdo),
            new RoleBelowActorRuleResolver($this->pdo),
            new ExplicitRuleResolver(),
            new GroupRuleResolver($groupResolver)
        );

        $this->seeder = new DocumentDemoSeeder(
            $this->pdo,
            new DemoOrganisationSeeder($this->pdo, new \Whity\Core\Identity\ProfileProvisioner($this->pdo)),
            new DocumentStarterSeeder($this->templates, $this->blocks),
            $this->templates,
            $this->blocks,
            $this->documents,
            new DocumentIssuer(
                $this->pdo,
                $this->documents,
                new DocumentArtifactRepository($this->pdo),
                new DocumentArtifactStore(new \Whity\Storage\LocalStorageDriver($this->storageRoot))
            ),
            new DocumentRouter(
                $this->pdo,
                new RouteRepository($this->pdo),
                new RouteStepRepository($this->pdo),
                new RouteEventRepository($this->pdo),
                new RouteRecipientRepository($this->pdo),
                new RouteEdgeRepository($this->pdo),
                $rules,
                $settings,
                null
            ),
            new DocumentCollectionRepository($this->pdo),
            $this->routeTemplates,
            new RouteTemplateGraph($rules),
            $settings,
            // A REAL public base URL, so `isConfigured()` is true and the
            // verification fixture is actually seeded. Empty here would make
            // every QR assertion below pass vacuously against a seeder that
            // minted nothing — the shape of test the coordinator's note on
            // positive controls warns about.
            new DocumentQrService($this->pdo, $this->qrTokens, new DocumentQrScanRepository($this->pdo), self::PUBLIC_URL),
            $this->qrTokens
        );

        // Held for the tests below, which read what the seeder wrote through the
        // same repositories the product reads it through.
        $this->router = new DocumentRouter(
            $this->pdo,
            new RouteRepository($this->pdo),
            new RouteStepRepository($this->pdo),
            new RouteEventRepository($this->pdo),
            new RouteRecipientRepository($this->pdo),
            new RouteEdgeRepository($this->pdo),
            $rules,
            $settings,
            null
        );
    }

    protected function tearDown(): void
    {
        RoleChecker::clearCache();
        unset($_ENV[DemoOrganisationSeeder::PASSWORD_ENV_VAR]);
        putenv(DemoOrganisationSeeder::PASSWORD_ENV_VAR);
        self::removeTree($this->storageRoot);
    }

    // ── 1. #1004: same role, same permission, different place ────────────────

    /**
     * The headline. Both secretaries hold `demo-secretary` and therefore
     * `documents:write`; the faculty one stands at the faculty and the
     * department one at a department, and the sets they see are not the same.
     *
     * Asserted as a STRICT superset rather than as two fixed lists, so the test
     * says what migration 117 promises ("her set strictly contains theirs")
     * rather than pinning the number of demo templates.
     */
    public function testTheTwoSecretariesSeeDifferentTemplateSets(): void
    {
        $this->seeder->seedForTenant(self::TENANT, 'Demo Tenant');

        $faculty = $this->visibleTemplateNames(DemoOrganisationSeeder::FACULTY_SECRETARY);
        $department = $this->visibleTemplateNames(DemoOrganisationSeeder::CIVIL_SECRETARY);

        self::assertSame(
            $this->roleOf(DemoOrganisationSeeder::FACULTY_SECRETARY),
            $this->roleOf(DemoOrganisationSeeder::CIVIL_SECRETARY),
            'The demonstration is only worth anything if the two secretaries hold the SAME role.'
        );

        self::assertNotEquals(
            $faculty,
            $department,
            'Two holders of one role in different units must not see the same template set — '
            . 'that identity is exactly what migration 117 was written to break.'
        );
        self::assertSame(
            [],
            array_diff($department, $faculty),
            "The department secretary's set must be contained in the faculty secretary's: reach is downward."
        );
        self::assertNotSame(
            [],
            array_diff($faculty, $department),
            'The faculty secretary must reach templates the department secretary does not.'
        );
    }

    /**
     * The OTHER predicate, with placement held constant.
     *
     * The publish-tagged template is filed at the civil department, so BOTH
     * secretaries pass its placement check and NEITHER may see it, while the
     * dean — who holds `documents:publish` — may. Without this case a reader
     * could conclude that placement is the whole rule.
     */
    public function testAPermissionTaggedTemplateIsWithheldFromBothSecretariesAndShownToTheDean(): void
    {
        $this->seeder->seedForTenant(self::TENANT, 'Demo Tenant');

        $tagged = $this->taggedTemplateName(CorePermissions::DOCUMENTS_PUBLISH);

        self::assertNotContains($tagged, $this->visibleTemplateNames(DemoOrganisationSeeder::CIVIL_SECRETARY));
        self::assertNotContains($tagged, $this->visibleTemplateNames(DemoOrganisationSeeder::FACULTY_SECRETARY));
        self::assertContains($tagged, $this->visibleTemplateNames(DemoOrganisationSeeder::DEAN));
    }

    /**
     * The comparison a customer actually makes: the technician and the secretary
     * WHO SIT IN THE SAME OFFICE.
     *
     * This is the case the fixture used to get wrong, and it was measured rather
     * than assumed: before the `documents:write`-tagged template existed,
     * `civil-technician@` and `civil-secretary@` saw byte-identical template and
     * block sets. Reach cannot separate them — they stand in one unit — and the
     * only tag in the set was `documents:publish`, which neither holds.
     * "Technicians don't see contract templates" was therefore true only against
     * the DEAN, two levels up and four permissions richer, where a difference
     * demonstrates nothing in particular.
     *
     * Asserted as a strict superset, like the two secretaries above, so it states
     * the property rather than pinning a count.
     */
    public function testTheTechnicianSeesLessThanTheSecretaryStandingBesideHer(): void
    {
        $this->seeder->seedForTenant(self::TENANT, 'Demo Tenant');

        $secretary = $this->visibleTemplateNames(DemoOrganisationSeeder::CIVIL_SECRETARY);
        $technician = $this->visibleTemplateNames(DemoOrganisationSeeder::CIVIL_TECHNICIAN);

        self::assertSame(
            $this->ouOf(DemoOrganisationSeeder::CIVIL_SECRETARY),
            $this->ouOf(DemoOrganisationSeeder::CIVIL_TECHNICIAN),
            'The demonstration is only worth anything if the two stand in the SAME unit — '
            . 'otherwise reach could be doing the work and the tag would prove nothing.'
        );

        self::assertSame(
            [],
            array_diff($technician, $secretary),
            "The technician's set must be contained in the secretary's: she holds every "
            . 'capability he does.'
        );
        self::assertNotSame(
            [],
            array_diff($secretary, $technician),
            'The secretary must see at least one template the technician beside her does not, '
            . 'or the permission gate is invisible to anybody comparing two people in one office.'
        );

        // And it is the write-tagged row specifically, not some accident of
        // placement: naming it is what stops this test passing for the wrong
        // reason if the tag is ever dropped from that template.
        $tagged = $this->taggedTemplateName(CorePermissions::DOCUMENTS_WRITE);
        self::assertContains($tagged, $secretary);
        self::assertNotContains($tagged, $technician);
    }

    // ── 1b. the demo is LEGIBLE: names, not ids ──────────────────────────────

    /**
     * Every demo role can name a unit, a person and a role.
     *
     * Not a document permission and therefore easy to forget, which is what
     * happened: the organizer's "raised from" column, the record page's trail and
     * the routing screen's recipient list all resolve foreign keys through
     * `GET /api/v1/ous`, `/users` and `/roles`, and a role holding only
     * `documents:*` gets a 403 from each — so every persona read `Unit #2`,
     * `Account #10`, `Role #5` under a banner naming the permission it lacked.
     * A dataset whose whole subject is an OU hierarchy shaping what people see
     * cannot present that hierarchy as three integers.
     *
     * ALL FIVE, deliberately — see {@see DemoOrganisationSeeder}'s
     * DIRECTORY_PERMISSIONS for why withholding them from the technician (the
     * tempting exception) would wreck the comparison the test above makes.
     *
     * Resolved BY NAME. #992 removed eight slugs and left holes at ids 2, 3, 6,
     * 7, 10, 11, 14 and 15, so an id is not stable across installs, never mind
     * meaningful in a test.
     */
    public function testEveryDemoRoleCanNameUnitsPeopleAndRoles(): void
    {
        $this->seeder->seedForTenant(self::TENANT, 'Demo Tenant');

        $needed = [CorePermissions::OUS_READ, CorePermissions::USERS_READ, CorePermissions::ROLES_READ];

        foreach ($this->demoRoleIds() as $roleName => $roleId) {
            $held = $this->permissionsOfRole($roleId);
            foreach ($needed as $permission) {
                self::assertContains(
                    $permission,
                    $held,
                    "The demo role '{$roleName}' cannot list {$permission}, so every screen it "
                    . 'lands on prints ids where names belong.'
                );
            }
        }
    }

    /**
     * The directory grants above cannot have flattened what the fixture
     * discriminates on — checked, rather than argued.
     *
     * The whole value of this dataset is that two people see different things.
     * A permission granted to EVERY demo role is, by construction, incapable of
     * telling any two of them apart — so if a designer row were ever gated on
     * one, that row would silently stop discriminating and every screen would
     * still render perfectly. This is the guard against the next well-meant
     * grant, not against the ones just added.
     */
    public function testNoDesignerRowIsGatedOnAPermissionEveryDemoRoleHolds(): void
    {
        $this->seeder->seedForTenant(self::TENANT, 'Demo Tenant');

        $roleIds = $this->demoRoleIds();
        $holders = [];
        foreach ($roleIds as $roleId) {
            foreach ($this->permissionsOfRole($roleId) as $permission) {
                $holders[$permission] = ($holders[$permission] ?? 0) + 1;
            }
        }

        $tags = $this->taggedPermissions();
        self::assertNotSame([], $tags, 'The fixture must gate at least one designer row on a permission.');

        foreach ($tags as $permission) {
            self::assertLessThan(
                count($roleIds),
                $holders[$permission] ?? 0,
                "Every demo role holds '{$permission}', so gating a designer row on it hides that "
                . 'row from nobody — the fixture would look like it discriminates and would not.'
            );
            self::assertGreaterThan(
                0,
                $holders[$permission] ?? 0,
                "No demo role holds '{$permission}', so the row it gates is visible to nobody and "
                . 'the demo shows an empty gate rather than a working one.'
            );
        }
    }

    /** Blocks are placed too, because {@see DocumentAccessPolicy} governs both tables. */
    public function testBlocksArePlacedAsWellAsTemplates(): void
    {
        $this->seeder->seedForTenant(self::TENANT, 'Demo Tenant');

        self::assertNotEquals(
            $this->visibleBlockNames(DemoOrganisationSeeder::FACULTY_SECRETARY),
            $this->visibleBlockNames(DemoOrganisationSeeder::CIVIL_SECRETARY),
            'A demo that placed only templates would leave half of #1004 unverifiable by eye.'
        );
    }

    // ── 1b. #1024: blocks are POINTERS, and the demo has to contain some ─────

    /**
     * ONE BLOCK, INSTANCED BY TEMPLATES IN MORE THAN ONE UNIT.
     *
     * The regression this pins is that the dataset placed blocks and instanced
     * them NOWHERE — zero `blockInstance` elements across every seeded template.
     * A demo whose whole purpose is to make behaviour visible then showed the
     * block library as though blocks were standalone documents: every usage count
     * zero, the delete guard never engaged, the disclosure never populated. It did
     * not merely omit synced patterns, it taught that blocks are copies.
     *
     * Asserted through the SAME repository query the `/usage` endpoint runs
     * ({@see DocumentTemplateRepository::referencingTemplates()}), not through a
     * re-scan of the JSON here, so a fixture that stopped satisfying the actual
     * scanner could not pass this.
     */
    public function testOneBlockIsInstancedByTemplatesInMoreThanOneUnit(): void
    {
        $this->seeder->seedForTenant(self::TENANT, 'Demo Tenant');

        $referencing = $this->templates->referencingTemplates($this->blockId('demo-faculty-letterhead'), self::TENANT);

        self::assertGreaterThan(
            1,
            count($referencing),
            'Propagation needs somewhere to propagate to: a block instanced once demonstrates nothing.'
        );

        $units = array_unique(array_map(static fn (array $row): ?int => $row['owner_ou_id'], $referencing));
        self::assertGreaterThan(
            1,
            count($units),
            'The referencing templates must sit in different units, or the reach half of the story is missing.'
        );
    }

    /**
     * THE CIVIL SECRETARY IS TOLD "2 templates, 1 you cannot see".
     *
     * The case worth seeding permanently, because it is the one that stops
     * somebody editing a block believing they can see everything it affects. She
     * can see the site-safety block — it is filed in her department — and exactly
     * one of the two templates that instance it; the other is at Mechanical
     * Engineering, out of her reach entirely.
     *
     * Computed the way {@see \Whity\Api\DocumentBlocksApiHandler::usage()}
     * computes it — unfiltered total, filtered list, hidden as the difference —
     * rather than by asserting on a literal the fixture could drift away from.
     */
    public function testTheCivilSecretaryIsToldOneOfTheTwoUsersIsHiddenFromHer(): void
    {
        $this->seeder->seedForTenant(self::TENANT, 'Demo Tenant');
        $blockId = $this->blockId('demo-civil-safety-notice');

        self::assertSame(
            ['total' => 2, 'hidden' => 1],
            $this->usageFor(DemoOrganisationSeeder::CIVIL_SECRETARY, $blockId),
            'Her row must read "2 templates, 1 you cannot see" — a real number, not a zero.'
        );

        // And the disclosure is a DIFFERENCE, not a constant: the two people who
        // reach the whole faculty are told the same total with nothing hidden, so
        // the number above is about her reach rather than about the endpoint.
        foreach ([DemoOrganisationSeeder::DEAN, DemoOrganisationSeeder::FACULTY_SECRETARY] as $email) {
            self::assertSame(
                ['total' => 2, 'hidden' => 0],
                $this->usageFor($email, $blockId),
                $email . ' reaches both units and must be told nothing is hidden.'
            );
        }
    }

    /**
     * Every pointer the demo lays down resolves to a block that is actually there.
     *
     * A `blockInstance` carrying an id nothing matches renders as an orphan
     * placeholder in the designer and counts towards nothing — a fixture that
     * looked seeded and demonstrated the broken case instead of the working one.
     * Cheap to get wrong (an id written before the block exists) and invisible
     * without this.
     */
    public function testEveryBlockInstanceInTheDemoPointsAtABlockThatExists(): void
    {
        $this->seeder->seedForTenant(self::TENANT, 'Demo Tenant');

        $blockIds = [];
        foreach ($this->blocks->listForTenant(self::TENANT) as $block) {
            $blockIds[(string) $block['id']] = true;
        }

        $found = 0;
        foreach ($this->templates->listForTenant(self::TENANT) as $template) {
            foreach (self::collectBlockIds($template['data']) as $referenced) {
                $found++;
                self::assertArrayHasKey(
                    $referenced,
                    $blockIds,
                    'Template "' . $template['name'] . '" points at block ' . $referenced . ', which does not exist.'
                );
            }
        }

        self::assertGreaterThan(0, $found, 'The whole point of #1024 is that there ARE pointers.');
    }

    /**
     * And at least one template instances nothing.
     *
     * The used-by-nothing state is what makes a delete safe, and a fixture in
     * which every template references something renders it nowhere.
     */
    public function testAtLeastOneSeededTemplateInstancesNoBlockAtAll(): void
    {
        $this->seeder->seedForTenant(self::TENANT, 'Demo Tenant');

        $withoutPointers = array_filter(
            $this->templates->listForTenant(self::TENANT),
            static fn (array $row): bool => self::collectBlockIds($row['data']) === [],
        );

        self::assertNotSame([], $withoutPointers);
    }

    // ── 2. routing states that differ from each other ────────────────────────

    /**
     * At least one document is waiting on somebody and at least one is finished.
     *
     * Both halves matter: with only the first, "acted on by me" is empty and
     * unreadable; with only the second, so is the inbox.
     */
    public function testOneDocumentIsAwaitingWhileAnotherIsFullyActedOn(): void
    {
        $this->seeder->seedForTenant(self::TENANT, 'Demo Tenant');

        $awaiting = [];
        $settled = [];
        foreach ($this->documentTitles() as $id => $title) {
            $open = $this->countRecipients($id, open: true);
            $closed = $this->countRecipients($id, open: false);
            if ($open > 0) {
                $awaiting[$title] = $open;
            }
            if ($open === 0 && $closed > 0) {
                $settled[$title] = $closed;
            }
        }

        self::assertNotSame([], $awaiting, 'No document is awaiting anybody: the inbox folder cannot be seen.');
        self::assertNotSame(
            [],
            $settled,
            'Every document is still open, so "acted on by me" and the trail cannot be seen.'
        );
    }

    /**
     * The state a single global progress bar would misreport, and the reason
     * #1000 renders per-step counts: ONE document, ONE step, one recipient who
     * acted and one who did not.
     *
     * This is the case a linear document cannot exhibit at all, which is why it
     * is asserted per (document, step) rather than per document.
     */
    public function testAFannedOutStepHasBothAnActedAndAnUnactedRecipient(): void
    {
        $this->seeder->seedForTenant(self::TENANT, 'Demo Tenant');

        $stmt = $this->pdo->prepare(
            'SELECT r.document_id, r.step_id,
                    SUM(CASE WHEN r.closed_by_event_id IS NULL THEN 1 ELSE 0 END) AS still_open,
                    SUM(CASE WHEN r.closed_by_event_id IS NULL THEN 0 ELSE 1 END) AS acted
               FROM document_route_recipients r
              WHERE r.tenant_id = :tenant_id
              GROUP BY r.document_id, r.step_id'
        );
        $stmt->execute([':tenant_id' => self::TENANT]);

        $mixed = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ((int) $row['still_open'] > 0 && (int) $row['acted'] > 0) {
                $mixed++;
            }
        }

        self::assertGreaterThan(
            0,
            $mixed,
            'No step has both an acted and an unacted recipient, so the demo cannot show why a single '
            . 'progress bar over a routed document is the wrong rendering.'
        );
    }

    /**
     * Every recipient row and every trail event came out of the engine, which is
     * what makes the fixture trustworthy — so the invariants the engine
     * maintains must hold over the seeded data.
     *
     * A closed row names the event that closed it, that event is on the same
     * route, and its actor is the person whose row it is. A hand-written fixture
     * could satisfy the schema and fail every one of these.
     *
     * NARROWED TO `act` STAGES BY #1054, AND THE NARROWING IS THE POINT.
     * ------------------------------------------------------------------
     * This assertion predates delivery stages, and it encoded an assumption they
     * invalidate: that the only way a recipient row closes is that ITS OWN HOLDER
     * acted. At a stage {@see RouteSatisfaction::DELIVERY} the row is closed by
     * the very event that opened it — somebody ELSE's forward, one step up —
     * because those people were TOLD rather than asked, and one act performs both
     * closes. {@see RouteSatisfaction} says so in as many words.
     *
     * So the invariant is not weakened, it is SPLIT: `act` rows are still closed
     * only by their own holder's act, and `delivery` rows are checked below by
     * the rule that actually governs them. Dropping the `act` half instead would
     * have retired the check that makes a hand-written recipient row detectable,
     * which is the one this fixture most needs.
     */
    public function testEveryClosedRecipientRowWasClosedByThatPersonsOwnActOnThatRoute(): void
    {
        $this->seeder->seedForTenant(self::TENANT, 'Demo Tenant');

        $stmt = $this->pdo->prepare(
            'SELECT count(*) FROM document_route_recipients r
               JOIN document_route_events e ON e.id = r.closed_by_event_id
               JOIN document_route_steps s ON s.id = r.step_id
              WHERE r.tenant_id = :tenant_id
                AND s.satisfied_by = :act
                AND (e.route_id <> r.route_id
                     OR e.actor_profile_id <> r.profile_id
                     OR e.tenant_id <> r.tenant_id)'
        );
        $stmt->execute([':tenant_id' => self::TENANT, ':act' => RouteSatisfaction::ACT]);

        self::assertSame(0, (int) $stmt->fetchColumn());
    }

    /**
     * The other half of the invariant above: a row at a DELIVERY stage is closed
     * by the event that created it, and by no other (#1054).
     *
     * Asserted over the whole tenant rather than over one document, so a future
     * delivery stage added anywhere in this fixture is covered by construction.
     * The POSITIVE CONTROL is the count: the fixture must actually contain
     * delivery rows, or an all-clear here means only that there were none.
     */
    public function testEveryDeliveryRowWasClosedByTheEventThatOpenedIt(): void
    {
        $this->seeder->seedForTenant(self::TENANT, 'Demo Tenant');

        $stmt = $this->pdo->prepare(
            'SELECT count(*) AS total,
                    sum(CASE WHEN r.closed_by_event_id = r.created_by_event_id THEN 1 ELSE 0 END) AS matched
               FROM document_route_recipients r
               JOIN document_route_steps s ON s.id = r.step_id
              WHERE r.tenant_id = :tenant_id AND s.satisfied_by = :delivery'
        );
        $stmt->execute([':tenant_id' => self::TENANT, ':delivery' => RouteSatisfaction::DELIVERY]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        self::assertIsArray($row);
        self::assertGreaterThan(
            0,
            (int) $row['total'],
            'the fixture contains no delivery rows at all, so this test would pass over nothing'
        );
        self::assertSame(
            (int) $row['total'],
            (int) $row['matched'],
            'every row at a delivery stage is closed by the event that opened it — nothing there is '
            . 'ever awaited, and nobody there ever acted'
        );
    }

    /**
     * A recipient's `ou_id` is the unit the RULE reached them in, so it must be
     * a unit they actually hold a membership at — the property a hand-picked
     * value would not have.
     */
    public function testEveryRecipientWasReachedInAUnitTheyBelongTo(): void
    {
        $this->seeder->seedForTenant(self::TENANT, 'Demo Tenant');

        $stmt = $this->pdo->prepare(
            'SELECT count(*) FROM document_route_recipients r
              WHERE r.tenant_id = :tenant_id
                AND r.ou_id IS NOT NULL
                AND NOT EXISTS (
                    SELECT 1 FROM memberships m
                     WHERE m.tenant_id = r.tenant_id
                       AND m.profile_id = r.profile_id
                       AND m.ou_id = r.ou_id
                )'
        );
        $stmt->execute([':tenant_id' => self::TENANT]);

        self::assertSame(0, (int) $stmt->fetchColumn());
    }

    // ── 3. the three unit folders are three answers ──────────────────────────

    /**
     * At the top of the tree, "raised by my unit", "everything below my unit"
     * and "passed through my unit" must return three different counts.
     *
     * On a flat tenant all three coincide, which is the whole reason an
     * organisation is seeded — and the third differs from the second only
     * because one document is raised by somebody in no unit at all.
     */
    public function testTheThreeUnitFoldersDisagreeAtTheTopOfTheTree(): void
    {
        $this->seeder->seedForTenant(self::TENANT, 'Demo Tenant');

        $faculty = $this->rootUnitId();
        $subtree = \Whity\Core\Ou\OuSubtree::descendantIds($this->pdo, self::TENANT, [$faculty]);

        $raised = $this->documents->countForCriteria(
            self::TENANT,
            new \Whity\Core\Document\Organizer\DocumentCriteria(originOuIds: [$faculty])
        );
        $below = $this->documents->countForCriteria(
            self::TENANT,
            new \Whity\Core\Document\Organizer\DocumentCriteria(originOuIds: $subtree)
        );
        $through = $this->documents->countForCriteria(
            self::TENANT,
            new \Whity\Core\Document\Organizer\DocumentCriteria(routedThroughOuIds: $subtree)
        );

        self::assertSame(
            3,
            count(array_unique([$raised, $below, $through])),
            "The faculty's three unit folders answered {$raised}/{$below}/{$through}; two of them agreeing "
            . 'means the demo cannot show that they are different questions.'
        );
    }

    /** The document that makes the above possible: raised from no unit at all. */
    public function testOneDocumentHasNoOriginUnit(): void
    {
        $this->seeder->seedForTenant(self::TENANT, 'Demo Tenant');

        $stmt = $this->pdo->prepare(
            'SELECT count(*) FROM documents WHERE tenant_id = :tenant_id AND origin_ou_id IS NULL'
        );
        $stmt->execute([':tenant_id' => self::TENANT]);

        self::assertSame(1, (int) $stmt->fetchColumn());
    }

    // ── 4. artifacts ─────────────────────────────────────────────────────────

    /**
     * One document must carry more than one artifact, or #986's "version N of M"
     * and its superseded-version warning are unreachable.
     */
    public function testExactlyOneDocumentCarriesASecondArtifactVersion(): void
    {
        $this->seeder->seedForTenant(self::TENANT, 'Demo Tenant');

        $stmt = $this->pdo->prepare(
            'SELECT document_id, count(*) AS versions FROM document_artifacts
              WHERE tenant_id = :tenant_id GROUP BY document_id'
        );
        $stmt->execute([':tenant_id' => self::TENANT]);

        $multi = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ((int) $row['versions'] > 1) {
                $multi++;
            }
        }

        self::assertSame(1, $multi);
    }

    /**
     * The bytes are on disk, they are what the checksum says, and they are a
     * PDF — the viewer streams them as `application/pdf` and a browser handed
     * something else shows a broken document, which reads as the viewer being
     * broken.
     */
    public function testEveryStoredArtifactIsAReadablePdfMatchingItsChecksum(): void
    {
        $this->seeder->seedForTenant(self::TENANT, 'Demo Tenant');

        $storage = new \Whity\Storage\LocalStorageDriver($this->storageRoot);

        $stmt = $this->pdo->prepare(
            'SELECT storage_key, content_type, byte_size, checksum_sha256 FROM document_artifacts
              WHERE tenant_id = :tenant_id'
        );
        $stmt->execute([':tenant_id' => self::TENANT]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        self::assertNotSame([], $rows);
        foreach ($rows as $row) {
            $bytes = $storage->get((string) $row['storage_key']);
            self::assertSame('application/pdf', (string) $row['content_type']);
            self::assertSame((int) $row['byte_size'], strlen($bytes));
            self::assertSame((string) $row['checksum_sha256'], hash('sha256', $bytes));
            self::assertStringStartsWith('%PDF-', $bytes);
            self::assertStringEndsWith("%%EOF\n", $bytes);
        }
    }

    /**
     * {@see DemoPdf} computes its cross-reference offsets, so they must point at
     * the objects they claim to. Most viewers tolerate a wrong xref, which is
     * precisely why nothing else would catch a broken one.
     */
    public function testTheGeneratedPdfsCrossReferenceTablePointsAtItsObjects(): void
    {
        $pdf = DemoPdf::page('Heading (with parens)', ['first', 'second']);

        // The captured groups are read through `??` rather than asserted into
        // existence: PHPStan cannot prove a group is present from preg_match's
        // return value, and a missing one should fail as "no startxref" rather
        // than as an array-offset error.
        preg_match('/startxref\s+(\d+)/', $pdf, $m);
        $xrefOffset = (int) ($m[1] ?? -1);
        self::assertGreaterThan(0, $xrefOffset, 'The PDF must carry a startxref offset.');
        self::assertSame('xref', substr($pdf, $xrefOffset, 4), 'startxref must point at the xref table.');

        // Entry 1 (the first object) must land exactly on "1 0 obj".
        preg_match('/xref\s+0 6\s+0000000000 65535 f \n(\d{10}) 00000 n /', $pdf, $offsets);
        $firstObject = $offsets[1] ?? null;
        self::assertNotNull(
            $firstObject,
            'The xref table must carry a free head entry followed by one 20-byte entry per object.'
        );
        self::assertSame('1 0 obj', substr($pdf, (int) $firstObject, 7));

        // The parenthesised heading survived as an escaped literal rather than
        // as unbalanced PDF syntax.
        self::assertStringContainsString(chr(92) . '(with parens' . chr(92) . ')', $pdf);
    }

    // ── 5. collections ───────────────────────────────────────────────────────

    /**
     * Starring is a collection carrying a well-known key, and a custom
     * collection is one without it. Both must be present, or the distinction
     * migration 114 argues for is invisible.
     */
    public function testAStarredCollectionAndACustomOneBothExist(): void
    {
        $this->seeder->seedForTenant(self::TENANT, 'Demo Tenant');

        $stmt = $this->pdo->prepare(
            'SELECT system_key, count(*) AS n FROM document_collections
              WHERE tenant_id = :tenant_id GROUP BY system_key'
        );
        $stmt->execute([':tenant_id' => self::TENANT]);

        $starred = 0;
        $custom = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ($row['system_key'] === DocumentCollectionRepository::STARRED_KEY) {
                $starred += (int) $row['n'];
            } elseif ($row['system_key'] === null) {
                $custom += (int) $row['n'];
            }
        }

        self::assertGreaterThan(0, $starred);
        self::assertGreaterThan(0, $custom);
    }

    // ── 6. idempotency ───────────────────────────────────────────────────────

    /**
     * `seed` is run repeatedly against dev databases, and a second run must
     * neither duplicate nor fail.
     *
     * Counted over EVERY table the seeder touches rather than over `documents`
     * alone: the duplication that would actually happen is a second route or a
     * second trail event on an existing document, which leaves the document
     * count unchanged and the demo wrong.
     */
    public function testASecondRunChangesNothing(): void
    {
        $this->seeder->seedForTenant(self::TENANT, 'Demo Tenant');
        $first = $this->tableCounts();

        $this->seeder->seedForTenant(self::TENANT, 'Demo Tenant');
        $second = $this->tableCounts();

        self::assertSame($first, $second);
    }

    /**
     * And the storage objects do not accumulate either — a re-run that wrote a
     * fresh artifact each time would leave the counts above unchanged while
     * filling a bucket.
     */
    public function testASecondRunWritesNoFurtherStorageObjects(): void
    {
        $this->seeder->seedForTenant(self::TENANT, 'Demo Tenant');
        $first = $this->countStoredObjects();

        $this->seeder->seedForTenant(self::TENANT, 'Demo Tenant');

        self::assertSame($first, $this->countStoredObjects());
        self::assertGreaterThan(0, $first);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /**
     * A demo block's id from its stable `starter_key`.
     *
     * By key rather than by name because the key is the identity — and because
     * the row carries it again (#1013), which is what let the seeder point a
     * template at a block in the first place.
     */
    // ── 8. the OUTCOMES tranche: what was decided, not only where it is ─────

    /**
     * A quorum stopped PART WAY — two of three approvals in, the step undecided.
     *
     * The state the whole tranche is for. #1041's answer carries `decided`, which
     * is what the STEP concluded and not the verdict the caller gave, and it is
     * null while a quorum is short. Reaching that state used to require three
     * people acting in order, which is precisely why nobody had looked at it.
     *
     * ASSERTED FROM BOTH SIDES, because "the step has not settled" is a claim an
     * empty result satisfies for many reasons — a route that never issued, a rule
     * that resolved to nobody, a quorum that is not what the fixture thinks. So:
     *
     *  BEFORE  the tallies are read off the rows and put through
     *          {@see RouteQuorum::approvalCarried()} — the ENGINE's own
     *          predicate, not a re-implementation of it — which must say the
     *          step has not carried;
     *  AFTER   the third approver is driven through the real {@see DocumentRouter}
     *          and `decided` must come back `approved`.
     *
     * The second half is the positive control: it can only pass if the route
     * issued, the rule resolved to three real people, one of them was genuinely
     * left holding an open item, and the quorum arithmetic is the one the fixture
     * declares.
     */
    public function testAQuorumIsSeededPartWayThroughAndOneMoreApprovalSettlesIt(): void
    {
        $this->seeder->seedForTenant(self::TENANT, 'Demo Tenant');

        $documentId = $this->documentIdByTitle('Demo research grant sign-off');
        $step = $this->onlyStepOf($documentId);

        self::assertTrue((bool) $step['decision'], 'the grant step must be a decision step');
        self::assertSame(
            RouteQuorum::ALL,
            $step['decision_quorum'],
            'the quorum is named ON THE STEP so this fixture behaves the same in a tenant whose '
            . 'default is `any`'
        );

        $rows = $this->recipientsAtStep($documentId, (int) $step['id']);
        $approvals = count(array_filter(
            $rows,
            static fn (array $r): bool => $r['closing_verdict'] === RouteVerdict::APPROVED
        ));
        $open = array_values(array_filter(
            $rows,
            static fn (array $r): bool => $r['closed_by_event_id'] === null
        ));

        self::assertCount(3, $rows, 'three approvers, so the intermediate state is two acts from each end');
        self::assertSame(2, $approvals, 'two approvals are already recorded');
        self::assertCount(1, $open, 'and exactly one person is still holding it');

        self::assertFalse(
            RouteQuorum::approvalCarried(RouteQuorum::ALL, $approvals, count($open), count($rows)),
            'the ENGINE\'s own predicate must say this step has not carried — which is what makes '
            . '`decided` null, and what the two people who have already approved were told'
        );

        // The positive control. Only a fixture that really issued, really
        // resolved and really left somebody holding an open item can be settled
        // by one more act through the real engine.
        $route = $this->routeOf($documentId);
        $settled = $this->router->act(
            self::TENANT,
            (int) $open[0]['profile_id'],
            $route,
            RouteAction::ACKNOWLEDGED,
            null,
            RouteVerdict::APPROVED,
        );

        self::assertSame(
            RouteVerdict::APPROVED,
            $settled['decided'],
            'the THIRD approval settles the step, and only then. Before it, the same call would have '
            . 'answered null — that difference is the whole of what this document exists to show'
        );
    }

    /**
     * One design, two documents, two destinations (#1030).
     *
     * The claim is not "a rejection is recorded"; it is that a rejection goes
     * SOMEWHERE ELSE and never inherits the approval's destination. That cannot
     * be seen from one document, so the fixture applies ONE design twice and
     * answers it differently — which makes the answer the only variable.
     *
     * Each half carries its own positive control: the stage a document did NOT
     * reach must have ZERO rows, and the stage it did reach must have an OPEN
     * one. A route that never issued would fail the second.
     */
    public function testTheApproveEdgeAndTheRejectEdgeOfOneDesignLeadToDifferentPlaces(): void
    {
        $this->seeder->seedForTenant(self::TENANT, 'Demo Tenant');

        $approved = $this->documentIdByTitle('Demo stationery purchase order');
        $rejected = $this->documentIdByTitle('Demo overtime claim');

        self::assertSame(
            $this->routeTemplateIdOf($approved),
            $this->routeTemplateIdOf($rejected),
            'both documents must be applied from the SAME design, or the difference below could be '
            . 'the design rather than the verdict'
        );

        // The approval took the drawn edge and SKIPPED the next ordinal.
        self::assertSame(0, $this->rowsAtPosition($approved, 2), 'an approval never reaches the referral stage');
        self::assertSame(1, $this->openRowsAtPosition($approved, 3), 'it is sitting at the filing stage');

        // The rejection went to the stage an approval never reaches, and stopped
        // there — it did NOT fall through to where an approval would have gone.
        self::assertSame(1, $this->openRowsAtPosition($rejected, 2), 'the refusal is with the registry officer');
        self::assertSame(
            0,
            $this->rowsAtPosition($rejected, 3),
            'and it never reached filing, which is the destination approval has. A rejection that '
            . 'inherited the approve edge would be indistinguishable on every screen'
        );
    }

    /**
     * A rejection at a gate with NO reject edge ends the chain.
     *
     * What this produces is an ABSENCE, so it is asserted beside two presences on
     * the same document: the gate HAS a recipient row and it WAS closed by a
     * `rejected` verdict. Without those, "step 2 has no rows" is equally
     * satisfied by a route that was never issued at all.
     */
    public function testARejectionWithNoRejectEdgeEndsTheChain(): void
    {
        $this->seeder->seedForTenant(self::TENANT, 'Demo Tenant');

        $documentId = $this->documentIdByTitle('Demo conference travel request');
        $steps = $this->stepsOf($documentId);
        self::assertCount(2, $steps, 'the route must HAVE a second step, or there is nothing to not reach');

        $gate = $steps[0];
        self::assertTrue((bool) $gate['decision']);
        self::assertSame(
            0,
            $this->outgoingEdges((int) $gate['id']),
            'the gate must have NO edges at all: the absent reject edge is the configuration'
        );

        $gateRows = $this->recipientsAtStep($documentId, (int) $gate['id']);
        self::assertCount(1, $gateRows, 'the gate was reached');
        self::assertSame(
            RouteVerdict::REJECTED,
            $gateRows[0]['closing_verdict'],
            'and it was answered with a refusal'
        );

        self::assertSame(
            0,
            $this->rowsAtPosition($documentId, 2),
            'step 2 was never opened. A rejection falling through to the ordinal successor is the '
            . 'failure #1014 is written against, and it is invisible on every screen'
        );
        self::assertSame(0, $this->countRecipients($documentId, open: true), 'nothing anywhere is awaited');
    }

    /**
     * The rework loop has been round more than once (#1037).
     *
     * Three rows at the drafting stage is the assertion, and it is only
     * meaningful because they belong to ONE person: three DIFFERENT people would
     * be an ordinary fan-out. Two of the three are closed and one is open, which
     * is what "on its third lap" means.
     */
    public function testTheReworkLoopHasBeenRoundMoreThanOnce(): void
    {
        $this->seeder->seedForTenant(self::TENANT, 'Demo Tenant');

        $documentId = $this->documentIdByTitle('Demo laboratory refurbishment plan');
        $steps = $this->stepsOf($documentId);
        $drafting = $this->recipientsAtStep($documentId, (int) $steps[0]['id']);

        self::assertCount(3, $drafting, 'three arrivals at the drafting stage: lap one, lap two, lap three');
        self::assertCount(
            1,
            array_unique(array_map(static fn (array $r): int => (int) $r['profile_id'], $drafting)),
            'all three are the SAME person — otherwise this is a fan-out, not a loop'
        );

        $open = array_filter($drafting, static fn (array $r): bool => $r['closed_by_event_id'] === null);
        self::assertCount(1, $open, 'exactly one open item on the current lap');

        $rejections = $this->countEvents($documentId, RouteVerdict::REJECTED);
        self::assertSame(
            2,
            $rejections,
            'two refusals behind it. One lap is indistinguishable from a document that simply has an '
            . 'open item; two is the cheapest fixture that shows a loop is a loop'
        );

        // #1037, made portable: the three arrivals are INDISTINGUISHABLE from
        // each other in every column that carries meaning. Same person, same
        // stage, same unit — nothing anywhere says which lap a row belongs to,
        // so a document on its ninth rejection renders exactly like one on its
        // first. Asserted over the rows rather than by probing the schema for a
        // column named `lap`, which only PostgreSQL can answer and which would
        // therefore not run on the engine CI's unit job uses.
        $shape = array_map(
            static fn (array $r): string => $r['profile_id'] . '|' . $r['step_id'] . '|' . ($r['ou_id'] ?? '-'),
            $drafting
        );
        self::assertCount(
            1,
            array_unique($shape),
            'the three laps are indistinguishable except by row id, which is #1037 exactly'
        );
    }

    /**
     * #1058 REPRODUCED IN THE FIXTURE: a merge whose rule is actor-relative
     * settles once per ARRIVING CHAIN, not once.
     *
     * The flow editor draws "Paths merge here — settles once" on this stage. Two
     * chains reach it, resolve to two DIFFERENT people, nothing de-duplicates,
     * and the stage ends up holding two independent cohorts.
     *
     * This test asserts the WRONG behaviour on purpose, exactly as
     * {@see \Tests\Core\Document\RouteTemplate\RouteTemplateInstantiationRealEngineTest}
     * does: it is filed against the LABEL, not the engine, and a fixture that
     * quietly avoided the shape would remove the only place anybody looking at
     * the demo could meet it.
     */
    public function testTheActorRelativeMergeHoldsTwoCohortsAtOneStage(): void
    {
        $this->seeder->seedForTenant(self::TENANT, 'Demo Tenant');

        $documentId = $this->documentIdByTitle('Demo cross-department equipment review');
        $steps = $this->stepsOf($documentId);
        self::assertCount(4, $steps);

        $merge = $steps[3];
        self::assertSame(
            RoutingRuleRegistry::KIND_ROLE_BELOW_ACTOR,
            (string) $merge['rule_kind'],
            'the merge stage must be ACTOR-RELATIVE — that is the whole difference between the case '
            . 'the canvas label describes and the case it does not'
        );

        $rows = $this->recipientsAtStep($documentId, (int) $merge['id']);
        self::assertCount(2, $rows, 'two inbox items at a stage the canvas marked "settles once"');
        self::assertCount(
            2,
            array_unique(array_map(static fn (array $r): int => (int) $r['created_by_event_id'], $rows)),
            'in two SEPARATE cohorts. The cohort a quorum is counted over is the rows one act opened, '
            . 'so two cohorts at one stage settle independently and each opens its own continuation'
        );
        self::assertCount(
            2,
            array_unique(array_map(static fn (array $r): int => (int) $r['profile_id'], $rows)),
            'and they are two different people, which is why nothing de-duplicated'
        );
        self::assertSame(2, $this->openRowsAtPosition($documentId, 4), 'both are still open to be walked up to');
    }

    /**
     * A delivery stage (#1054): every row closed by the event that opened it.
     *
     * THE POSITIVE CONTROL IS ON THE SAME DOCUMENT and is the point of the test.
     * "The technicians are awaiting nothing" is satisfied by a route that never
     * issued, a rule that reached nobody, or a broken predicate. The mechanical
     * head's still-open step-1 item rules all three out: it can only exist if the
     * route issued and its rules really resolved.
     */
    public function testADeliveryStageClosesItsRowsWithTheEventThatOpenedThem(): void
    {
        $this->seeder->seedForTenant(self::TENANT, 'Demo Tenant');

        $documentId = $this->documentIdByTitle('Demo workshop reopening notice');
        $steps = $this->stepsOf($documentId);

        self::assertSame(RouteSatisfaction::ACT, (string) $steps[0]['satisfied_by']);
        self::assertSame(RouteSatisfaction::DELIVERY, (string) $steps[1]['satisfied_by']);

        $told = $this->recipientsAtStep($documentId, (int) $steps[1]['id']);
        self::assertCount(2, $told, 'the delivery rule really reached two people');
        foreach ($told as $row) {
            self::assertNotNull($row['closed_by_event_id'], 'nobody at a delivery stage is ever awaited');
            self::assertSame(
                (int) $row['created_by_event_id'],
                (int) $row['closed_by_event_id'],
                'and the row is closed by the very event that opened it — one act, both effects, which '
                . 'is why the flag is on the STEP and not on the event'
            );
        }

        // THE POSITIVE CONTROL.
        $waiting = array_filter(
            $this->recipientsAtStep($documentId, (int) $steps[0]['id']),
            static fn (array $r): bool => $r['closed_by_event_id'] === null
        );
        self::assertCount(
            1,
            $waiting,
            'somebody on THIS document must still be waiting, or "the technicians are awaiting nothing" '
            . 'is equally true of a document that was never routed'
        );

        // Nobody who was merely told appears among the people who acted.
        $actors = $this->actorProfileIds($documentId);
        foreach ($told as $row) {
            self::assertNotContains(
                (int) $row['profile_id'],
                $actors,
                'a person who was TOLD must not appear in "acted on by me" — they did not act'
            );
        }
    }

    /**
     * Routes applied from a design carry `template_id` and a `template_name`
     * snapshot (#1056), and routes composed by hand do not.
     *
     * The second half is the control: a column that were set on EVERY route would
     * discriminate nothing, and the demo could not show the difference between a
     * route somebody composed and one they applied.
     */
    public function testRoutesAppliedFromADesignCarryTheirProvenanceAndOthersDoNot(): void
    {
        $this->seeder->seedForTenant(self::TENANT, 'Demo Tenant');

        $stmt = $this->pdo->prepare(
            'SELECT id, title, template_id, template_name FROM document_routes WHERE tenant_id = :t'
        );
        $stmt->execute([':t' => self::TENANT]);
        $routes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $applied = array_values(array_filter($routes, static fn (array $r): bool => $r['template_id'] !== null));
        $composed = array_values(array_filter($routes, static fn (array $r): bool => $r['template_id'] === null));

        self::assertNotSame([], $applied, 'no route carries design provenance, so #1056 cannot be seen');
        self::assertNotSame([], $composed, 'every route carries it, so the column discriminates nothing');

        foreach ($applied as $route) {
            $design = $this->routeTemplates->findById((int) $route['template_id'], self::TENANT);
            self::assertNotNull($design, 'a route names a design that does not exist');
            self::assertSame(
                (string) $design['name'],
                (string) $route['template_name'],
                'the snapshot must match the design it was taken from at seed time'
            );
        }
    }

    /**
     * Every seeded design is one the editor itself would have accepted.
     *
     * Re-validated through {@see RouteTemplateGraph}, which is what `PUT /graph`
     * runs. A demo whose purpose is that somebody opens the canvas on these must
     * not contain a canvas the canvas cannot save — and a fixture written
     * straight into the repository could.
     */
    public function testEverySeededRouteDesignIsOneTheEditorCouldHaveSaved(): void
    {
        $this->seeder->seedForTenant(self::TENANT, 'Demo Tenant');

        $designs = $this->routeTemplates->listForTenant(self::TENANT, 50, 0);
        self::assertNotSame([], $designs, 'no route designs were seeded, so the flow editor still opens empty');

        $graph = new RouteTemplateGraph($this->rulesForTest());

        foreach ($designs as $design) {
            $id = (int) $design['id'];
            $steps = $this->routeTemplates->stepsFor($id, self::TENANT);
            $edges = $this->routeTemplates->edgesFor($id, self::TENANT);

            self::assertNotSame([], $steps, "design '{$design['name']}' has no stages to open on");

            $wire = [];
            foreach ($steps as $step) {
                $wire[] = [
                    'position' => (int) $step['position'],
                    'rule_kind' => (string) $step['rule_kind'],
                    'rule_config' => $step['rule_config'] ?? [],
                    'label' => $step['label'],
                    'decision' => (bool) $step['decision'],
                    'decision_quorum' => $step['decision_quorum'],
                    'satisfied_by' => (string) ($step['satisfied_by'] ?? RouteSatisfaction::ACT),
                    'canvas_x' => (int) $step['canvas_x'],
                    'canvas_y' => (int) $step['canvas_y'],
                ];
            }

            // Throws if the design could not be saved through the editor.
            $graph->validate($wire, $edges, 50);
        }
    }

    /**
     * The designs are laid out on the canvas, not stacked at the origin.
     *
     * Not decoration: three designs whose every node sits at (0, 0) open as one
     * illegible pile, which is a worse first-run experience than the empty canvas
     * this fixture exists to replace.
     */
    public function testTheSeededDesignsHaveDistinctCanvasPositions(): void
    {
        $this->seeder->seedForTenant(self::TENANT, 'Demo Tenant');

        foreach ($this->routeTemplates->listForTenant(self::TENANT, 50, 0) as $design) {
            $steps = $this->routeTemplates->stepsFor((int) $design['id'], self::TENANT);
            $points = array_map(
                static fn (array $s): string => $s['canvas_x'] . ',' . $s['canvas_y'],
                $steps
            );

            self::assertSame(
                count($steps),
                count(array_unique($points)),
                "design '{$design['name']}' has two stages at the same canvas point, so the editor "
                . 'would open with nodes on top of each other'
            );
        }
    }

    /**
     * A LIVE verification code and a REVOKED one, on two different documents.
     *
     * One of them alone proves nothing: the public page answers a revoked code
     * and an unknown token the same way by default, so a lone revoked code
     * renders exactly like a typo in the URL. The live one is what the refusal
     * has to be different from.
     */
    public function testOneDocumentCarriesALiveVerificationCodeAndAnotherARevokedOne(): void
    {
        $this->seeder->seedForTenant(self::TENANT, 'Demo Tenant');

        $stmt = $this->pdo->prepare(
            'SELECT document_id, revoked_at, revoked_reason FROM document_qr_tokens WHERE tenant_id = :t'
        );
        $stmt->execute([':t' => self::TENANT]);
        $tokens = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $live = array_values(array_filter($tokens, static fn (array $t): bool => $t['revoked_at'] === null));
        $revoked = array_values(array_filter($tokens, static fn (array $t): bool => $t['revoked_at'] !== null));

        self::assertCount(1, $live, 'exactly one code is in force');
        self::assertCount(1, $revoked, 'and exactly one has been retired');
        self::assertSame(
            QrRevocationReason::WITHDRAWN,
            (string) $revoked[0]['revoked_reason'],
            'withdrawn, not superseded: the fixture is "this paper is not to be trusted", which is a '
            . 'decision somebody made, rather than the side effect of a reprint'
        );
        self::assertNotSame(
            (int) $live[0]['document_id'],
            (int) $revoked[0]['document_id'],
            'on two different documents, so the two states can be opened side by side'
        );

        // The tenant switch, without which neither would render anywhere.
        $settings = new SettingsService(
            new GlobalSettingsRepository($this->pdo),
            new TenantSettingsRepository($this->pdo)
        );
        self::assertSame(
            'true',
            $settings->effective(self::TENANT)[SettingsRegistry::DOCUMENTS_QR_ENABLED] ?? null,
            'documents.qr_enabled defaults to FALSE, so a fixture that did not turn it on would seed '
            . 'two codes that no screen would ever show'
        );
    }

    /**
     * The demo never turns the verification switch back on over an operator's own
     * answer.
     *
     * The whole file's discipline is "insert when absent, never update", and this
     * is that discipline applied to the one thing here that is a SETTING rather
     * than a row — which is exactly where it would be easiest to get wrong,
     * because `setTenant()` is an upsert and would silently win.
     */
    public function testASecondRunDoesNotOverrideAnOperatorsOwnVerificationSetting(): void
    {
        $this->seeder->seedForTenant(self::TENANT, 'Demo Tenant');

        $settings = new SettingsService(
            new GlobalSettingsRepository($this->pdo),
            new TenantSettingsRepository($this->pdo)
        );
        $settings->setTenant(self::TENANT, SettingsRegistry::DOCUMENTS_QR_ENABLED, 'false');

        $this->seeder->seedForTenant(self::TENANT, 'Demo Tenant');

        self::assertSame(
            'false',
            $settings->effective(self::TENANT)[SettingsRegistry::DOCUMENTS_QR_ENABLED] ?? null,
            'an operator who looked at the demo and switched the feature off must keep their answer'
        );
    }

    /**
     * Re-running never mints a second verification code.
     *
     * The trap this pins is specific and would have been invisible: a revoked
     * code leaves the document with NO code in force, so a guard written on
     * "does it have a live one" sees nothing, mints again, revokes again, and
     * adds two rows every run — drift that no count of the DOCUMENTS would catch.
     */
    public function testASecondRunMintsNoFurtherVerificationCodes(): void
    {
        $this->seeder->seedForTenant(self::TENANT, 'Demo Tenant');
        $before = $this->countQrTokens();

        $this->seeder->seedForTenant(self::TENANT, 'Demo Tenant');
        $this->seeder->seedForTenant(self::TENANT, 'Demo Tenant');

        self::assertSame(2, $before, 'one live and one revoked');
        self::assertSame($before, $this->countQrTokens(), 'and still exactly those two after two more runs');
    }

    /**
     * Somebody in the demo can actually OPEN the designs — and not everybody can
     * rewrite them.
     *
     * The gap this pins is one the fixture walked into and nothing would have
     * caught, because with no designs seeded an empty list and a 403 render
     * identically. Migration 120 backfills `route_templates:read` onto every role
     * holding `documents:route`, and a backfill reaches only the roles that exist
     * when it runs; every demo role is created afterwards, by the seeder. So the
     * demo dean — who holds `documents:route` — did not have it, and every
     * persona opened the flow editor on "This route template could not be
     * loaded".
     *
     * THREE ASSERTIONS, NOT ONE, because "the dean can read" alone would pass on
     * a fixture that granted everything to everybody, which is the other way to
     * make a permission demo worthless:
     *
     *   - the dean can READ and WRITE (she draws the flows);
     *   - a head can READ but NOT write (a clerk who may route a document must
     *     not thereby be able to rewrite where every document goes);
     *   - the technician holds neither, so the page is not even in her nav.
     */
    public function testThePeopleWhoRouteDocumentsCanOpenTheSeededDesignsAndOnlyOneCanRedrawThem(): void
    {
        $this->seeder->seedForTenant(self::TENANT, 'Demo Tenant');

        $dean = $this->permissionsOfRole($this->roleOf(DemoOrganisationSeeder::DEAN));
        $head = $this->permissionsOfRole($this->roleOf(DemoOrganisationSeeder::CIVIL_HEAD));
        $technician = $this->permissionsOfRole($this->roleOf(DemoOrganisationSeeder::CIVIL_TECHNICIAN));

        self::assertContains(
            CorePermissions::ROUTE_TEMPLATES_READ,
            $dean,
            'the dean cannot open the designs she authored, so the flow editor 403s for the one '
            . 'persona the fixture seeds them for'
        );
        self::assertContains(CorePermissions::ROUTE_TEMPLATES_WRITE, $dean);

        self::assertContains(
            CorePermissions::ROUTE_TEMPLATES_READ,
            $head,
            'a head routes documents, so migration 120 would have backfilled read onto this role on '
            . 'any tenant whose roles predate it'
        );
        self::assertNotContains(
            CorePermissions::ROUTE_TEMPLATES_WRITE,
            $head,
            'and must NOT be able to redraw every route in the tenant'
        );

        self::assertNotContains(CorePermissions::ROUTE_TEMPLATES_READ, $technician);
        self::assertNotContains(CorePermissions::ROUTE_TEMPLATES_WRITE, $technician);
    }

    // ── helpers for the outcomes tranche ───────────────────────────────

    private function documentIdByTitle(string $title): int
    {
        foreach ($this->documentTitles() as $id => $seeded) {
            if ($seeded === $title) {
                return $id;
            }
        }

        self::fail("The demo document '{$title}' was not seeded.");
    }

    /** @return array<string, mixed> */
    private function routeOf(int $documentId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM document_routes WHERE tenant_id = :t AND document_id = :d ORDER BY id LIMIT 1'
        );
        $stmt->execute([':t' => self::TENANT, ':d' => $documentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row, 'the document has no route');

        return $row;
    }

    private function routeTemplateIdOf(int $documentId): ?int
    {
        $id = $this->routeOf($documentId)['template_id'] ?? null;

        return $id === null ? null : (int) $id;
    }

    /** @return list<array<string, mixed>> */
    private function stepsOf(int $documentId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT s.* FROM document_route_steps s
               JOIN document_routes r ON r.id = s.route_id
              WHERE s.tenant_id = :t AND r.document_id = :d
              ORDER BY s.position'
        );
        $stmt->execute([':t' => self::TENANT, ':d' => $documentId]);

        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $rows;
    }

    /** @return array<string, mixed> */
    private function onlyStepOf(int $documentId): array
    {
        $steps = $this->stepsOf($documentId);
        self::assertCount(1, $steps, 'this fixture expects a single-step route');

        return $steps[0];
    }

    /** @return list<array<string, mixed>> */
    private function recipientsAtStep(int $documentId, int $stepId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT rc.*, e.verdict AS closing_verdict
               FROM document_route_recipients rc
               LEFT JOIN document_route_events e ON e.id = rc.closed_by_event_id
              WHERE rc.tenant_id = :t AND rc.document_id = :d AND rc.step_id = :s
              ORDER BY rc.id'
        );
        $stmt->execute([':t' => self::TENANT, ':d' => $documentId, ':s' => $stepId]);

        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $rows;
    }

    private function rowsAtPosition(int $documentId, int $position): int
    {
        $steps = $this->stepsOf($documentId);
        if (!isset($steps[$position - 1])) {
            self::fail("The route has no step at position {$position}.");
        }

        return count($this->recipientsAtStep($documentId, (int) $steps[$position - 1]['id']));
    }

    private function openRowsAtPosition(int $documentId, int $position): int
    {
        $steps = $this->stepsOf($documentId);
        if (!isset($steps[$position - 1])) {
            self::fail("The route has no step at position {$position}.");
        }

        return count(array_filter(
            $this->recipientsAtStep($documentId, (int) $steps[$position - 1]['id']),
            static fn (array $r): bool => $r['closed_by_event_id'] === null
        ));
    }

    private function outgoingEdges(int $stepId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT count(*) FROM document_route_edges WHERE tenant_id = :t AND from_step_id = :s'
        );
        $stmt->execute([':t' => self::TENANT, ':s' => $stepId]);

        return (int) $stmt->fetchColumn();
    }

    private function countEvents(int $documentId, string $verdict): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT count(*) FROM document_route_events
              WHERE tenant_id = :t AND document_id = :d AND verdict = :v'
        );
        $stmt->execute([':t' => self::TENANT, ':d' => $documentId, ':v' => $verdict]);

        return (int) $stmt->fetchColumn();
    }

    /** @return list<int> */
    private function actorProfileIds(int $documentId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT DISTINCT actor_profile_id FROM document_route_events
              WHERE tenant_id = :t AND document_id = :d AND actor_profile_id IS NOT NULL'
        );
        $stmt->execute([':t' => self::TENANT, ':d' => $documentId]);

        return array_map(intval(...), $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    private function countQrTokens(): int
    {
        $stmt = $this->pdo->prepare('SELECT count(*) FROM document_qr_tokens WHERE tenant_id = :t');
        $stmt->execute([':t' => self::TENANT]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * The same four core rule kinds SeedCommand registers, for the tests that
     * need a registry of their own.
     */
    private function rulesForTest(): RoutingRuleRegistry
    {
        $rules = new RoutingRuleRegistry();
        $groupResolver = new GroupResolver(
            $this->pdo,
            new UserGroupRepository($this->pdo),
            static fn (): RoutingRuleRegistry => $rules
        );
        $rules->registerCoreRoutingRules(
            new RoleRuleResolver($this->pdo),
            new RoleBelowActorRuleResolver($this->pdo),
            new ExplicitRuleResolver(),
            new GroupRuleResolver($groupResolver)
        );

        return $rules;
    }

    private function blockId(string $starterKey): int
    {
        foreach ($this->blocks->listForTenant(self::TENANT) as $row) {
            if (($row['starter_key'] ?? null) === $starterKey) {
                return (int) $row['id'];
            }
        }

        self::fail('The demo must seed a block with starter_key "' . $starterKey . '".');
    }

    /**
     * `total` and `hidden` for one caller, computed exactly as
     * {@see \Whity\Api\DocumentBlocksApiHandler::usage()} computes them: the
     * unfiltered set of referencing templates, and the same set through
     * {@see DocumentAccessPolicy}.
     *
     * @return array{total: int, hidden: int}
     */
    private function usageFor(string $email, int $blockId): array
    {
        $referencing = $this->templates->referencingTemplates($blockId, self::TENANT);
        // ROWS, not names: two templates may legitimately share a name, and a
        // count of distinct names would then quietly understate `hidden` — which
        // is the one number this whole fixture exists to make non-zero.
        $visible = $this->visibleRows($email, $referencing);

        return ['total' => count($referencing), 'hidden' => count($referencing) - count($visible)];
    }

    /**
     * The block ids a template body references, by the same recursive-descent
     * walk both production scanners use (PHP's
     * {@see DocumentTemplateRepository::referencingTemplates()} and the
     * TypeScript `collectBlockIds`).
     *
     * @param mixed $node
     * @return list<string>
     */
    private static function collectBlockIds(mixed $node): array
    {
        if (!is_array($node)) {
            return [];
        }

        $out = [];
        if (($node['type'] ?? null) === 'blockInstance' && array_key_exists('blockId', $node)) {
            $out[] = (string) $node['blockId'];
        }
        foreach ($node as $value) {
            foreach (self::collectBlockIds($value) as $id) {
                $out[] = $id;
            }
        }

        return $out;
    }

    /** @return list<string> Sorted, so set comparisons are order-independent. */
    private function visibleTemplateNames(string $email): array
    {
        return $this->visibleNames($email, $this->templates->listForTenant(self::TENANT));
    }

    /** @return list<string> */
    private function visibleBlockNames(string $email): array
    {
        return $this->visibleNames($email, $this->blocks->listForTenant(self::TENANT));
    }

    /**
     * The rows a caller may see, through the SAME two predicates the API handler
     * builds — {@see ScopedPermissionSet} and {@see OuReachResolver} — rather
     * than through a re-implementation of the rule under test.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<string>
     */
    private function visibleNames(string $email, array $rows): array
    {
        $names = array_map(
            static fn (array $row): string => (string) $row['name'],
            $this->visibleRows($email, $rows),
        );
        sort($names);

        return $names;
    }

    /**
     * The same filter, before the names are taken off it — so a caller that needs
     * to COUNT what a person may see is not counting distinct names.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function visibleRows(string $email, array $rows): array
    {
        $profileId = $this->profileId($email);

        return (new DocumentAccessPolicy())->filterVisible(
            $rows,
            $profileId,
            ScopedPermissionSet::forProfile(
                new RoleChecker($this->db, new PermissionRegistry()),
                $profileId,
                self::TENANT
            ),
            (new OuReachResolver(
                $this->pdo,
                new ResourceRoleAssignmentRepository($this->pdo, new ResourceTypeRegistry())
            ))->reachFor(self::TENANT, $profileId),
        );
    }

    /**
     * The template gated on ONE named permission.
     *
     * Takes the slug rather than returning "the tagged one": there are two
     * tagged templates now, gating two different capabilities, and an
     * unqualified `SELECT … WHERE required_permission IS NOT NULL` would return
     * whichever row the engine happened to hand back first — a test that passes
     * or fails on row order.
     */
    private function taggedTemplateName(string $permission): string
    {
        $stmt = $this->pdo->prepare(
            'SELECT name FROM document_templates
              WHERE tenant_id = :tenant_id AND required_permission = :permission'
        );
        $stmt->execute([':tenant_id' => self::TENANT, ':permission' => $permission]);
        $name = $stmt->fetchColumn();

        self::assertNotFalse($name, "The fixture must place a template gated on '{$permission}'.");

        return (string) $name;
    }

    /**
     * Every permission any designer row in this tenant is gated on.
     *
     * Templates AND blocks, because migration 117 gave both tables the column
     * and {@see DocumentAccessPolicy} is applied to both — a guard that read only
     * templates would go quiet the first time a block was tagged.
     *
     * @return list<string>
     */
    private function taggedPermissions(): array
    {
        $found = [];
        foreach (['document_templates', 'document_blocks'] as $table) {
            $stmt = $this->pdo->prepare(
                "SELECT DISTINCT required_permission FROM {$table}
                  WHERE tenant_id = :tenant_id AND required_permission IS NOT NULL"
            );
            $stmt->execute([':tenant_id' => self::TENANT]);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $permission) {
                $found[(string) $permission] = true;
            }
        }

        return array_keys($found);
    }

    /**
     * The demo roles, by the `demo-` prefix the seeder guarantees.
     *
     * By prefix rather than by listing the five constants, so a sixth demo role
     * is covered by the legibility and discrimination guards on the day it is
     * added rather than on the day somebody remembers to extend a list here.
     *
     * @return array<string, int> role name => id
     */
    private function demoRoleIds(): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id, name FROM roles WHERE tenant_id = :tenant_id AND name LIKE 'demo-%' ORDER BY name"
        );
        $stmt->execute([':tenant_id' => self::TENANT]);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(string) $row['name']] = (int) $row['id'];
        }

        self::assertNotSame([], $out, 'The fixture must create demo roles.');

        return $out;
    }

    /**
     * The permission SLUGS one role holds, joined through the catalogue.
     *
     * Never an id comparison: #992 deleted eight slugs and left holes in the low
     * id range, so `permission_id = 4` is neither stable across installs nor
     * readable in a failure message. Same pattern as
     * {@see \Tests\Api\RolePermissionDeltaRealEngineTest}.
     *
     * @return list<string>
     */
    private function permissionsOfRole(int $roleId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT p.name
               FROM role_permissions rp
               JOIN permissions p ON p.id = rp.permission_id
              WHERE rp.role_id = :role_id'
        );
        $stmt->execute([':role_id' => $roleId]);

        return array_map(static fn ($name): string => (string) $name, $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** The unit a person's single primary membership names. */
    private function ouOf(string $email): ?int
    {
        $stmt = $this->pdo->prepare(
            'SELECT ou_id FROM memberships WHERE tenant_id = :tenant_id AND profile_id = :profile_id'
        );
        $stmt->execute([':tenant_id' => self::TENANT, ':profile_id' => $this->profileId($email)]);
        $ouId = $stmt->fetchColumn();

        return $ouId === false || $ouId === null ? null : (int) $ouId;
    }

    private function profileId(string $email): int
    {
        $stmt = $this->pdo->prepare('SELECT profile_id FROM profile_emails WHERE email = :email');
        $stmt->execute([':email' => $email]);
        $id = $stmt->fetchColumn();

        self::assertNotFalse($id, "The fixture must have created {$email}.");

        return (int) $id;
    }

    private function roleOf(string $email): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT role_id FROM memberships WHERE tenant_id = :tenant_id AND profile_id = :profile_id'
        );
        $stmt->execute([':tenant_id' => self::TENANT, ':profile_id' => $this->profileId($email)]);

        return (int) $stmt->fetchColumn();
    }

    /** @return array<int, string> id => title */
    private function documentTitles(): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, title FROM documents WHERE tenant_id = :tenant_id ORDER BY id'
        );
        $stmt->execute([':tenant_id' => self::TENANT]);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(int) $row['id']] = (string) $row['title'];
        }

        return $out;
    }

    private function countRecipients(int $documentId, bool $open): int
    {
        $sql = 'SELECT count(*) FROM document_route_recipients
                 WHERE tenant_id = :tenant_id AND document_id = :document_id
                   AND closed_by_event_id IS ' . ($open ? 'NULL' : 'NOT NULL');
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':tenant_id' => self::TENANT, ':document_id' => $documentId]);

        return (int) $stmt->fetchColumn();
    }

    private function rootUnitId(): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM organizational_units
              WHERE tenant_id = :tenant_id AND parent_id IS NULL'
        );
        $stmt->execute([':tenant_id' => self::TENANT]);

        return (int) $stmt->fetchColumn();
    }

    /** @return array<string, int> */
    private function tableCounts(): array
    {
        $tables = [
            'organizational_units',
            'roles',
            'memberships',
            'document_templates',
            'document_blocks',
            'documents',
            'document_artifacts',
            'document_routes',
            'document_route_steps',
            'document_route_events',
            'document_route_recipients',
            'document_collections',
            'document_collection_items',
        ];

        $counts = [];
        foreach ($tables as $table) {
            // The table name comes from the literal list above and never from
            // input; every one of these carries `tenant_id`.
            $stmt = $this->pdo->prepare(
                'SELECT count(*) FROM ' . $table . ' WHERE tenant_id = :tenant_id'
            );
            $stmt->execute([':tenant_id' => self::TENANT]);
            $counts[$table] = (int) $stmt->fetchColumn();
        }

        // Profiles have no tenant column (ADR 0005); count the demo addresses.
        $stmt = $this->pdo->prepare(
            "SELECT count(*) FROM profile_emails WHERE email LIKE '%@demo.example.com'"
        );
        $stmt->execute();
        $counts['profiles'] = (int) $stmt->fetchColumn();

        return $counts;
    }

    private function countStoredObjects(): int
    {
        if (!is_dir($this->storageRoot)) {
            return 0;
        }

        $files = 0;
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->storageRoot, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            if ($item->isFile()) {
                $files++;
            }
        }

        return $files;
    }

    private static function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($path);
    }
}
