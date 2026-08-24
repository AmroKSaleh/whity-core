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
use Whity\Core\Document\Routing\DocumentRouter;
use Whity\Core\Document\Routing\RoleBelowActorRuleResolver;
use Whity\Core\Document\Routing\RoleRuleResolver;
use Whity\Core\Document\Routing\RouteEventRepository;
use Whity\Core\Document\Routing\RouteRecipientRepository;
use Whity\Core\Document\Routing\RouteRepository;
use Whity\Core\Document\Routing\RouteStepRepository;
use Whity\Core\Document\Routing\RoutingRuleRegistry;
use Whity\Core\Group\GroupResolver;
use Whity\Core\Group\GroupRuleResolver;
use Whity\Core\Group\UserGroupRepository;
use Whity\Core\Ou\OuReachResolver;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\RBAC\ResourceRoleAssignmentRepository;
use Whity\Core\RBAC\ResourceTypeRegistry;
use Whity\Core\RBAC\ScopedPermissionSet;
use Whity\Core\Settings\GlobalSettingsRepository;
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
 *   - an awaiting document against an acted-on one;
 *   - within ONE document, an acted recipient beside an unacted one at the same
 *     step — the state #1000's per-step counts exist for;
 *   - a document with two artifacts against the ones with one;
 *   - "raised by my unit", "below my unit" and "passed through my unit" as three
 *     different answers at the same anchor.
 *
 * Plus idempotency, which is the property `seed` is run against dev databases
 * on the strength of.
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

    private PDO $pdo;
    private Database $db;
    private string $storageRoot;
    private DocumentDemoSeeder $seeder;
    private DocumentTemplateRepository $templates;
    private DocumentBlockRepository $blocks;
    private DocumentRepository $documents;

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
                $rules,
                $settings,
                null
            ),
            new DocumentCollectionRepository($this->pdo)
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

        $tagged = $this->taggedTemplateName();

        self::assertNotContains($tagged, $this->visibleTemplateNames(DemoOrganisationSeeder::CIVIL_SECRETARY));
        self::assertNotContains($tagged, $this->visibleTemplateNames(DemoOrganisationSeeder::FACULTY_SECRETARY));
        self::assertContains($tagged, $this->visibleTemplateNames(DemoOrganisationSeeder::DEAN));
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
     */
    public function testEveryClosedRecipientRowWasClosedByThatPersonsOwnActOnThatRoute(): void
    {
        $this->seeder->seedForTenant(self::TENANT, 'Demo Tenant');

        $stmt = $this->pdo->prepare(
            'SELECT count(*) FROM document_route_recipients r
               JOIN document_route_events e ON e.id = r.closed_by_event_id
              WHERE r.tenant_id = :tenant_id
                AND (e.route_id <> r.route_id
                     OR e.actor_profile_id <> r.profile_id
                     OR e.tenant_id <> r.tenant_id)'
        );
        $stmt->execute([':tenant_id' => self::TENANT]);

        self::assertSame(0, (int) $stmt->fetchColumn());
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
        $profileId = $this->profileId($email);
        $visible = (new DocumentAccessPolicy())->filterVisible(
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

        $names = array_map(static fn (array $row): string => (string) $row['name'], $visible);
        sort($names);

        return $names;
    }

    private function taggedTemplateName(): string
    {
        $stmt = $this->pdo->prepare(
            'SELECT name FROM document_templates
              WHERE tenant_id = :tenant_id AND required_permission IS NOT NULL'
        );
        $stmt->execute([':tenant_id' => self::TENANT]);
        $name = $stmt->fetchColumn();

        self::assertNotFalse($name, 'The fixture must place one permission-tagged template.');

        return (string) $name;
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
