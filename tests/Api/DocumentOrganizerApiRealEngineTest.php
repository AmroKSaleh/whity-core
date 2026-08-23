<?php

declare(strict_types=1);

namespace Tests\Api;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\DocumentCollectionsApiHandler;
use Whity\Api\DocumentsApiHandler;
use Whity\Auth\RoleChecker;
use Whity\Core\Document\DocumentAccessPolicy;
use Whity\Core\Document\DocumentArtifactRepository;
use Whity\Core\Document\DocumentArtifactStore;
use Whity\Core\Document\DocumentCollectionRepository;
use Whity\Core\Document\DocumentIssuer;
use Whity\Core\Document\DocumentBlockRepository;
use Whity\Core\Document\DocumentRepository;
use Whity\Core\Document\DocumentTemplateRepository;
use Whity\Core\Document\DocumentVisibilityPolicy;
use Whity\Core\Document\Routing\RouteRecipientRepository;
use Whity\Core\Document\Organizer\CoreDocumentSubstrates;
use Whity\Core\Document\Organizer\CoreDocumentViews;
use Whity\Core\Document\Organizer\DocumentSubstrateRegistry;
use Whity\Core\Document\Organizer\DocumentViewRegistry;
use Whity\Core\Document\Organizer\PdoSchemaPresence;
use Whity\Core\Document\Render\DocumentRenderer;
use Whity\Core\RBAC\ResourceRoleAssignmentRepository;
use Whity\Core\RBAC\ResourceTypeRegistry;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\Request;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;
use Whity\Core\Tenant\TenantContext;
use Whity\Database\Database;
use Whity\Sdk\Http\Response;
use Whity\Storage\LocalStorageDriver;

/**
 * Real-engine tests for the document organizer (#978, implementing #947 item 5)
 * — the derived folders, the per-user collections, and the star.
 *
 * The things worth failing a build over, in order:
 *
 *  1. NO FOLDER IS RENDERED THAT CANNOT BE COMPUTED. The routing-derived
 *     folders are absent from `GET /api/documents/views` and 404 on request.
 *     An empty "Awaiting me" would state "nothing awaits you", which is false.
 *  2. THE THREE-WAY DISTINCTION. Absent (404), unanchored-for-this-caller (422
 *     with a reason), and genuinely empty (200 with no rows) are three different
 *     answers to three different questions.
 *  3. VISIBILITY IS RE-APPLIED THROUGH EVERY FOLDER, INCLUDING A COLLECTION. A
 *     document filed in March and hidden in April must stop appearing. A stored
 *     pointer is never a grant.
 *  4. TENANT AND PROFILE ISOLATION. Another tenant sees nothing; another
 *     PERSON's collection id is not found rather than forbidden.
 *  5. PAGINATION TOTALS DESCRIBE THE SAME SET AS THE PAGE, per folder — a
 *     post-filtered page reports a total the caller cannot reach.
 *
 * The render service is faked (as in {@see DocumentsApiHandlerRealEngineTest});
 * storage is a real {@see LocalStorageDriver} over a throwaway directory, and
 * the registries are wired exactly as public/index.php wires them, including the
 * live-schema probe — a stubbed probe would let a folder pass here that is
 * unavailable in production, which is the one thing this suite is for.
 */
final class DocumentOrganizerApiRealEngineTest extends TestCase
{
    private const TENANT = 1;
    private const OTHER_TENANT = 2;

    /** admin in tenant 1: documents:* including read:all. In unit 3 (Registry). */
    private const AUDITOR = 10;
    /** documents:read only, no read:all. In unit 4 (Records Office, a child of Registry). */
    private const CLERK = 11;
    /** documents:read only. In NO unit — the #951 "cannot anchor" case. */
    private const UNAFFILIATED = 12;
    private const OTHER_TENANT_ADMIN = 20;

    // OU tree seeded below: 2 (Campus) → 3 (Registry) → 4 (Records Office), 3 → 5 (Archive).
    private const OU_CAMPUS = 2;
    private const OU_REGISTRY = 3;
    private const OU_RECORDS = 4;
    private const OU_ARCHIVE = 5;

    private PDO $pdo;
    private \Tests\Support\FakeRenderServiceClient $fakeRender;
    private DocumentTemplateRepository $templateRepo;
    private DocumentCollectionRepository $collectionRepo;
    private DocumentsApiHandler $documents;
    private DocumentCollectionsApiHandler $collections;
    private \Whity\Api\DocumentRenderApiHandler $renderHandler;
    private string $storageRoot;

    protected function setUp(): void
    {
        RoleChecker::clearCache();
        $this->pdo = $this->makeSchema();
        $db = $this->wrapSqlite($this->pdo);

        $this->storageRoot = sys_get_temp_dir() . '/whity-organizer-' . bin2hex(random_bytes(6));
        $this->templateRepo = new DocumentTemplateRepository($this->pdo);
        $documentRepo = new DocumentRepository($this->pdo);
        $artifactRepo = new DocumentArtifactRepository($this->pdo);
        $this->collectionRepo = new DocumentCollectionRepository($this->pdo);
        $this->fakeRender = new \Tests\Support\FakeRenderServiceClient();

        $settings = new SettingsService(
            new GlobalSettingsRepository($this->pdo),
            new TenantSettingsRepository($this->pdo)
        );
        $store = new DocumentArtifactStore(new LocalStorageDriver($this->storageRoot));
        $renderer = new DocumentRenderer(
            new DocumentBlockRepository($this->pdo),
            $settings,
            $this->fakeRender
        );
        $issuer = new DocumentIssuer($this->pdo, $documentRepo, $artifactRepo, $store);
        $roleChecker = new RoleChecker($db, new PermissionRegistry());
        // #947 item 3 widened the policy with two disjuncts: a route reached you,
        // or a role was granted to you on the document. Wired for real rather
        // than stubbed, because this file asserts that a COLLECTION re-applies
        // visibility, and a stub would let the collection path pass while the
        // production wiring diverged.
        $visibility = new DocumentVisibilityPolicy(
            new RouteRecipientRepository($this->pdo),
            new ResourceRoleAssignmentRepository($this->pdo, new ResourceTypeRegistry())
        );
        $templatePolicy = new DocumentAccessPolicy();

        // Wired the way public/index.php wires it: registries built over the
        // LIVE schema, so an unavailable folder here is unavailable in
        // production and vice versa.
        $substrates = new DocumentSubstrateRegistry(new PdoSchemaPresence($this->pdo));
        CoreDocumentSubstrates::registerInto($substrates);
        $views = new DocumentViewRegistry($substrates);
        CoreDocumentViews::registerInto($views);

        $this->documents = new DocumentsApiHandler(
            $documentRepo,
            $artifactRepo,
            $store,
            $visibility,
            $this->templateRepo,
            $templatePolicy,
            $renderer,
            $issuer,
            $roleChecker,
            $settings,
            $views,
            $substrates,
            $this->collectionRepo,
            $this->pdo,
        );

        $this->collections = new DocumentCollectionsApiHandler(
            $this->collectionRepo,
            $documentRepo,
            $visibility,
            $roleChecker,
        );

        $this->renderHandler = new \Whity\Api\DocumentRenderApiHandler(
            $this->templateRepo,
            $templatePolicy,
            $roleChecker,
            $settings,
            $renderer,
            $issuer
        );

        $settings->setGlobal(SettingsRegistry::DOCUMENTS_RENDER_ENABLED, 'true');
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
        RoleChecker::clearCache();
        $_GET = [];
        self::removeTree($this->storageRoot);
    }

    // ── 1. nothing is rendered that cannot be computed ──────────────────────

    /**
     * The keystone. The rail offers the folders this installation can actually
     * compute, and the three routing-derived ones from #947 item 5 are NOT
     * among them.
     */
    public function testTheRailOffersOnlyComputableFoldersAndNamesWhatIsMissing(): void
    {
        $body = self::decode($this->getViews(self::AUDITOR));

        $keys = array_map(static fn (array $v): string => (string) $v['key'], $body['data']);
        self::assertSame(
            [
                CoreDocumentViews::ALL,
                CoreDocumentViews::CREATED_BY_ME,
                CoreDocumentViews::RAISED_BY_MY_UNIT,
                CoreDocumentViews::BELOW_MY_UNIT,
                CoreDocumentViews::STARRED,
                CoreDocumentViews::COLLECTION,
            ],
            $keys
        );

        // #947 item 3 HAS landed, so its tables exist and both routing substrates
        // resolve — and the three folders derived from them are STILL not
        // offered, because each needs a predicate and a registration of its own.
        // A resolvable fact source is not a folder. Had they been registered
        // behind the substrate to "appear automatically", they would be here
        // now, empty, saying "nothing awaits you".
        foreach (['awaiting-me', 'acted-on-by-me', 'passed-through-my-unit'] as $absent) {
            self::assertNotContains($absent, $keys, "'{$absent}' is not built and must not be offered");
        }

        // Nothing is reported missing on a fully migrated installation, which is
        // simply true here. The field still exists and is still the place an
        // absent fact source is explained (#951) — DocumentViewRegistryTest
        // exercises that with a substrate whose table genuinely is not there.
        self::assertSame([], $body['unavailable_substrates']);
    }

    /** And requesting one is a 404: from outside, it does not exist. */
    public function testRequestingARoutingDerivedFolderIsNotFound(): void
    {
        $this->issue(self::AUDITOR, 'Invoice');

        foreach (['awaiting-me', 'acted-on-by-me', 'passed-through-my-unit', 'nonsense'] as $key) {
            $res = $this->list(self::AUDITOR, ['view' => $key]);
            self::assertSame(404, $res->getStatusCode(), "view={$key} must 404, never return an empty page");
        }
    }

    // ── 2. absent vs unanchored vs empty ───────────────────────────────────

    /**
     * The #951 case, end to end. The caller belongs to no unit, so the two unit
     * folders are LISTED (they exist and are computable) and marked unavailable
     * WITH A REASON — never hidden, which would make "I have no unit" look
     * identical to "the feature is gone".
     */
    public function testAUnitFolderIsListedButDisabledWithAReasonForACallerInNoUnit(): void
    {
        $byKey = self::byKey(self::decode($this->getViews(self::UNAFFILIATED))['data']);

        foreach ([CoreDocumentViews::RAISED_BY_MY_UNIT, CoreDocumentViews::BELOW_MY_UNIT] as $key) {
            self::assertArrayHasKey($key, $byKey, "'{$key}' must still be listed — hiding it hides the cause");
            self::assertFalse($byKey[$key]['available']);
            self::assertNotNull($byKey[$key]['unavailable_reason']);
            self::assertStringContainsString('unit', (string) $byKey[$key]['unavailable_reason']);
        }

        // Everything that does not need a unit is unaffected.
        self::assertTrue($byKey[CoreDocumentViews::CREATED_BY_ME]['available']);
        self::assertTrue($byKey[CoreDocumentViews::ALL]['available']);
    }

    /**
     * Opening it is a 422 carrying the reason — NOT a 404 (which is what an
     * unbuilt folder returns, and the two must stay distinguishable) and NOT a
     * 200 with no rows (which would read as "my unit has raised nothing").
     */
    public function testOpeningAnUnanchorableFolderIs422WithTheReasonRatherThanAnEmptyPage(): void
    {
        $this->issue(self::AUDITOR, 'Invoice');

        $res = $this->list(self::UNAFFILIATED, ['view' => CoreDocumentViews::RAISED_BY_MY_UNIT]);

        self::assertSame(422, $res->getStatusCode());
        self::assertStringContainsString('unit', self::decode($res)['error'] ?? '');
    }

    /** A caller in a unit that has raised nothing gets an honest empty page. */
    public function testAUnitThatHasRaisedNothingIsAnEmptyPageNotAnError(): void
    {
        // The auditor raises from Registry; the clerk's own unit is Records Office.
        $this->issue(self::AUDITOR, 'Registry memo');

        $body = self::decode($this->list(self::CLERK, ['view' => CoreDocumentViews::RAISED_BY_MY_UNIT]));

        self::assertSame([], $body['data']);
        self::assertSame(0, $body['pagination']['total']);
        self::assertSame(self::OU_RECORDS, $body['view']['ou_id'], 'the anchor it actually used is echoed back');
    }

    // ── the derived folders themselves ─────────────────────────────────────

    public function testCreatedByMeReturnsOnlyTheCallersOwnDocuments(): void
    {
        $this->issue(self::AUDITOR, 'Theirs');
        $this->issue(self::CLERK, 'Mine');

        $body = self::decode($this->list(self::AUDITOR, ['view' => CoreDocumentViews::CREATED_BY_ME]));

        self::assertSame(['Theirs'], self::titles($body));
        self::assertSame(1, $body['pagination']['total'], 'the total describes the same set as the page');
    }

    /**
     * "Raised by my unit" is the unit alone. The auditor is in Registry, so a
     * document raised from Records Office (its child) is NOT in it — that is
     * what the second folder is for.
     */
    public function testRaisedByMyUnitIsTheAnchorUnitAloneAndNotItsChildren(): void
    {
        $this->issue(self::AUDITOR, 'From Registry');
        $this->issue(self::CLERK, 'From Records');

        $body = self::decode($this->list(self::AUDITOR, ['view' => CoreDocumentViews::RAISED_BY_MY_UNIT]));

        self::assertSame(['From Registry'], self::titles($body));
        self::assertSame(self::OU_REGISTRY, $body['view']['ou_id']);
    }

    public function testBelowMyUnitIncludesTheAnchorAndItsDescendants(): void
    {
        $this->issue(self::AUDITOR, 'From Registry');
        $this->issue(self::CLERK, 'From Records');

        $body = self::decode($this->list(self::AUDITOR, ['view' => CoreDocumentViews::BELOW_MY_UNIT]));

        self::assertEqualsCanonicalizing(['From Registry', 'From Records'], self::titles($body));
        self::assertSame(2, $body['pagination']['total']);
    }

    /**
     * An explicit anchor narrows or moves the walk. Anchoring at Records Office
     * excludes Registry's own document even though the caller sits in Registry.
     */
    public function testAnExplicitAnchorReplacesTheCallersOwnUnit(): void
    {
        $this->issue(self::AUDITOR, 'From Registry');
        $this->issue(self::CLERK, 'From Records');

        $body = self::decode($this->list(self::AUDITOR, [
            'view' => CoreDocumentViews::BELOW_MY_UNIT,
            'ou_id' => (string) self::OU_RECORDS,
        ]));

        self::assertSame(['From Records'], self::titles($body));
        self::assertSame(self::OU_RECORDS, $body['view']['ou_id']);

        // A leaf unit that has raised nothing is an honestly empty page, not an
        // error: the folder computed fine and the answer is zero.
        $archive = self::decode($this->list(self::AUDITOR, [
            'view' => CoreDocumentViews::BELOW_MY_UNIT,
            'ou_id' => (string) self::OU_ARCHIVE,
        ]));
        self::assertSame([], $archive['data']);
        self::assertSame(0, $archive['pagination']['total']);
    }

    /**
     * An anchor from ANOTHER TENANT is a 400, not a silent fallback to the
     * caller's own unit: a folder quietly answering about a different unit than
     * the one on screen is worse than an error.
     */
    public function testAnAnchorThatIsNotAUnitInThisTenantIsRejected(): void
    {
        $this->issue(self::AUDITOR, 'From Registry');

        foreach ([9, 4242] as $foreignOu) {
            $res = $this->list(self::AUDITOR, [
                'view' => CoreDocumentViews::BELOW_MY_UNIT,
                'ou_id' => (string) $foreignOu,
            ]);
            self::assertSame(400, $res->getStatusCode(), "ou_id={$foreignOu} must be refused, not ignored");
        }
    }

    /** Search is a title substring, and an empty `q` is a cleared box rather than a term. */
    public function testTitleSearchFiltersAndAnEmptyTermIsNotAFilter(): void
    {
        $this->issue(self::AUDITOR, 'Quarterly Report');
        $this->issue(self::AUDITOR, 'Invoice 42');

        self::assertSame(
            ['Quarterly Report'],
            self::titles(self::decode($this->list(self::AUDITOR, ['q' => 'quarter'])))
        );
        self::assertCount(2, self::decode($this->list(self::AUDITOR, ['q' => '']))['data']);
        // A wildcard in the term is matched LITERALLY — a caller typing `%`
        // must not get every row back.
        self::assertSame([], self::titles(self::decode($this->list(self::AUDITOR, ['q' => '%']))));
    }

    // ── 2b. sorting is the SERVER's, and it is echoed back ─────────────────

    /**
     * The three sortable columns actually order the whole result set.
     *
     * Asserted through the API rather than the repository because the ordering
     * only means anything in combination with the pagination the handler
     * applies: a client-side sort of a server-paginated page sorts one page and
     * calls it a sorted list, which is the failure this endpoint exists to make
     * impossible.
     *
     * The titles are chosen so alphabetical order and issue order DISAGREE — a
     * fixture where they coincide passes with no ORDER BY at all.
     */
    public function testEachSortableColumnOrdersTheWholeList(): void
    {
        // Issued newest-last, so the recorded order is Zebra, apple, Mango.
        $this->issue(self::AUDITOR, 'Zebra file');
        $this->issue(self::AUDITOR, 'apple file');
        $this->issue(self::AUDITOR, 'Mango file');

        // Default: the order they were recorded in, newest first.
        self::assertSame(
            ['Mango file', 'apple file', 'Zebra file'],
            self::titles(self::decode($this->list(self::AUDITOR, [])))
        );

        // Title ascends by default, and CASE-INSENSITIVELY: without LOWER() the
        // lowercase 'apple' sorts after both capitals on PostgreSQL's C locale,
        // and an "alphabetical" list is not one.
        self::assertSame(
            ['apple file', 'Mango file', 'Zebra file'],
            self::titles(self::decode($this->list(self::AUDITOR, ['sort' => 'title'])))
        );
        self::assertSame(
            ['Zebra file', 'Mango file', 'apple file'],
            self::titles(self::decode($this->list(self::AUDITOR, ['sort' => 'title', 'direction' => 'desc'])))
        );

        // The template name is the SNAPSHOT on the row; `issue()` names each
        // template after its document, so this orders by a second column that
        // is not the title and is not the id.
        self::assertSame(
            ['apple file', 'Mango file', 'Zebra file'],
            self::titles(self::decode($this->list(self::AUDITOR, ['sort' => 'template_name'])))
        );

        // created_at is set EXPLICITLY here, and the first draft of this test is
        // why. All three documents were issued inside the same second, so they
        // shared a timestamp to the resolution the column stores — asc and desc
        // then returned the same list, decided entirely by the `id` tie-breaker.
        // That is the tie-breaker working, and it also means a test that did not
        // separate the timestamps would assert nothing about created_at at all.
        $this->setCreatedAt('Zebra file', '2026-03-01 09:00:00');
        $this->setCreatedAt('apple file', '2026-01-01 09:00:00');
        $this->setCreatedAt('Mango file', '2026-02-01 09:00:00');

        // Descends by default — the recent thing is the one being looked for.
        self::assertSame(
            ['Zebra file', 'Mango file', 'apple file'],
            self::titles(self::decode($this->list(self::AUDITOR, ['sort' => 'created_at'])))
        );
        self::assertSame(
            ['apple file', 'Mango file', 'Zebra file'],
            self::titles(self::decode($this->list(self::AUDITOR, ['sort' => 'created_at', 'direction' => 'asc'])))
        );
    }

    /**
     * The response reports the order APPLIED, not the one asked for.
     *
     * `direction` defaults PER FIELD, so a client that assumed a single default
     * would draw its arrow the wrong way round on one of the three columns. The
     * echo is what makes that rule discoverable instead of folklore — the same
     * reason `view.ou_id` is echoed.
     */
    public function testTheAppliedSortIsEchoedIncludingItsPerFieldDirectionDefault(): void
    {
        $this->issue(self::AUDITOR, 'Invoice');

        self::assertSame(
            ['field' => 'title', 'direction' => 'asc'],
            self::decode($this->list(self::AUDITOR, ['sort' => 'title']))['sort']
        );
        self::assertSame(
            ['field' => 'created_at', 'direction' => 'desc'],
            self::decode($this->list(self::AUDITOR, ['sort' => 'created_at']))['sort']
        );
        self::assertSame(
            ['field' => 'template_name', 'direction' => 'desc'],
            self::decode($this->list(self::AUDITOR, ['sort' => 'template_name', 'direction' => 'desc']))['sort']
        );
        // No sort named: the order documents were RECORDED in, which is not
        // created_at under another name — see DocumentRepository::orderSql().
        self::assertSame(
            ['field' => null, 'direction' => 'desc'],
            self::decode($this->list(self::AUDITOR, []))['sort']
        );
    }

    /**
     * A sort this list does not offer is REFUSED, never ignored.
     *
     * Ignoring it returns 200 with rows ordered by something else, and the
     * client draws an indicator on a column the rows are not sorted by — the
     * reader then trusts a claim the response never made. `origin_ou_id` is in
     * the list because it is a visible column that is deliberately NOT sortable
     * (it displays a name resolved from a separately-permissioned request), so
     * it is exactly the plausible guess a client would make.
     */
    public function testAnUnknownOrUnbackedSortIsRefusedRatherThanIgnored(): void
    {
        $this->issue(self::AUDITOR, 'Invoice');

        foreach (['id', 'origin_ou_id', 'created_by', 'title; DROP TABLE documents'] as $field) {
            $res = $this->list(self::AUDITOR, ['sort' => $field]);
            self::assertSame(400, $res->getStatusCode(), "sort={$field} must be refused, not ignored");
            // The error names the vocabulary: it is the only way a caller can
            // discover it without fetching a schema.
            self::assertStringContainsString('template_name', $res->getBody());
        }

        // The table is still there — the injection attempt never reached SQL,
        // because the request names an enum case or nothing at all.
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM documents WHERE tenant_id = :tenant_id');
        $count->execute([':tenant_id' => self::TENANT]);
        self::assertSame(1, (int) $count->fetchColumn());

        // A direction that is neither, and a direction with nothing to order by.
        self::assertSame(
            400,
            $this->list(self::AUDITOR, ['sort' => 'title', 'direction' => 'sideways'])->getStatusCode()
        );
        self::assertSame(400, $this->list(self::AUDITOR, ['direction' => 'asc'])->getStatusCode());
    }

    /**
     * A sorted list still pages without losing or repeating a row.
     *
     * The tie-breaker is the point: three documents sharing a template name have
     * no defined order among themselves, so the engine may return them
     * differently for page 1 and page 2, and a row that crosses the boundary is
     * shown twice or never. Nothing errors and the total still adds up — the
     * symptom is a document the reader concludes was never issued.
     */
    public function testASortedListPagesWithoutLosingOrRepeatingARow(): void
    {
        $templateId = $this->createTemplate(self::AUDITOR, 'Shared');
        foreach (['One', 'Two', 'Three', 'Four'] as $title) {
            $this->issueFromTemplate(self::AUDITOR, $templateId, $title);
        }

        $seen = [];
        for ($page = 1; $page <= 4; $page++) {
            $body = self::decode($this->list(self::AUDITOR, [
                'sort' => 'template_name',
                'page' => (string) $page,
                'per_page' => '1',
            ]));
            $seen = [...$seen, ...self::titles($body)];
        }

        self::assertCount(4, $seen);
        self::assertCount(4, array_unique($seen), 'a tie in the sort key must not repeat a row across pages');
    }

    // ── 3. visibility is re-applied through every folder ───────────────────

    /**
     * The most important assertion about collections. A pointer stored while the
     * caller could see a document does NOT keep serving it once they cannot: the
     * collection join is a filter, never a grant.
     *
     * Modelled by starring as the auditor (who holds documents:read:all) and
     * then reading the same collection back after the grant is removed.
     */
    public function testACollectionStopsServingADocumentOnceVisibilityIsWithdrawn(): void
    {
        $documentId = $this->issue(self::CLERK, 'Clerk memo');

        // The auditor may see it (read:all) and stars it.
        $star = $this->call('star', self::AUDITOR, ['id' => (string) $documentId]);
        self::assertSame(200, $star->getStatusCode());
        self::assertTrue(self::decode($star)['starred']);
        self::assertSame(
            ['Clerk memo'],
            self::titles(self::decode($this->list(self::AUDITOR, ['view' => CoreDocumentViews::STARRED])))
        );

        // The tenant-wide grant is revoked. The row in the collection is
        // untouched — and must stop being served.
        $this->revoke(1, CorePermissions::DOCUMENTS_READ_ALL);
        RoleChecker::clearCache();

        $body = self::decode($this->list(self::AUDITOR, ['view' => CoreDocumentViews::STARRED]));
        self::assertSame([], $body['data'], 'a stored pointer must never outlive the permission behind it');
        self::assertSame(0, $body['pagination']['total']);

        // The row is still there, so this is a live filter rather than a delete.
        $starred = $this->collectionRepo->findBySystemKey(
            DocumentCollectionRepository::STARRED_KEY,
            self::TENANT,
            self::AUDITOR
        );
        self::assertNotNull($starred);
        self::assertTrue($this->collectionRepo->contains(self::TENANT, (int) $starred['id'], $documentId));
    }

    /**
     * Filing requires visibility, so the endpoint cannot be used to discover
     * which document ids exist.
     */
    public function testADocumentTheCallerCannotSeeCannotBeFiledAndIsReportedMissing(): void
    {
        $documentId = $this->issue(self::AUDITOR, 'Not yours');

        $collectionId = $this->createCollection(self::CLERK, 'Q3 audit');

        $res = $this->call('addDocument', self::CLERK, [
            'id' => (string) $collectionId,
            'documentId' => (string) $documentId,
        ]);
        self::assertSame(404, $res->getStatusCode(), 'never a 403 — that would confirm the id exists');

        $star = $this->call('star', self::CLERK, ['id' => (string) $documentId]);
        self::assertSame(404, $star->getStatusCode());
    }

    /**
     * Un-filing does NOT re-check visibility: it is exactly the case a person
     * needs, and refusing it would leave a row they own, cannot read and cannot
     * get rid of.
     */
    public function testUnfilingADocumentTheCallerCanNoLongerSeeIsAllowed(): void
    {
        $documentId = $this->issue(self::CLERK, 'Clerk memo');
        $this->call('star', self::AUDITOR, ['id' => (string) $documentId]);

        $this->revoke(1, CorePermissions::DOCUMENTS_READ_ALL);
        RoleChecker::clearCache();

        $res = $this->call('unstar', self::AUDITOR, ['id' => (string) $documentId]);
        self::assertSame(200, $res->getStatusCode());
        self::assertFalse(self::decode($res)['starred']);

        $starred = $this->collectionRepo->findBySystemKey(
            DocumentCollectionRepository::STARRED_KEY,
            self::TENANT,
            self::AUDITOR
        );
        self::assertNotNull($starred);
        self::assertFalse($this->collectionRepo->contains(self::TENANT, (int) $starred['id'], $documentId));
    }

    // ── 4. tenant and profile isolation ────────────────────────────────────

    public function testAnotherTenantSeesNoneOfTheseFoldersContents(): void
    {
        $this->issue(self::AUDITOR, 'Invoice');

        foreach ([CoreDocumentViews::ALL, CoreDocumentViews::CREATED_BY_ME, CoreDocumentViews::BELOW_MY_UNIT] as $view) {
            $res = $this->listAs(self::OTHER_TENANT_ADMIN, self::OTHER_TENANT, ['view' => $view]);
            // The other tenant's admin is in no unit, so the unit folder is a
            // 422 there; everything else is an empty page. Neither may leak a row.
            if ($res->getStatusCode() === 200) {
                self::assertSame([], self::decode($res)['data'], "view={$view} leaked across tenants");
            } else {
                self::assertSame(422, $res->getStatusCode());
            }
        }
    }

    /**
     * A collection id belonging to another PERSON is not found rather than
     * forbidden — collection ids are enumerable, and a 403 would let a
     * colleague's filing be mapped by walking them.
     */
    public function testAnotherPersonsCollectionIsNotFoundRatherThanForbidden(): void
    {
        $documentId = $this->issue(self::CLERK, 'Clerk memo');
        $mine = $this->createCollection(self::CLERK, 'Q3 audit');
        $this->call('addDocument', self::CLERK, [
            'id' => (string) $mine,
            'documentId' => (string) $documentId,
        ]);

        foreach (['update', 'delete'] as $method) {
            $res = $this->call($method, self::AUDITOR, ['id' => (string) $mine], ['name' => 'Hijacked']);
            self::assertSame(404, $res->getStatusCode(), "{$method} on another person's collection must 404");
        }

        // And it is unreadable through the LIST endpoint, which is the subtler
        // half: the auditor holds documents:read:all, so row visibility alone
        // would have served the clerk's document back and thereby revealed that
        // the clerk had filed it. Who filed what is private in its own right.
        $res = $this->list(self::AUDITOR, [
            'view' => CoreDocumentViews::COLLECTION,
            'collection_id' => (string) $mine,
        ]);
        self::assertSame(404, $res->getStatusCode(), "another person's collection must not resolve at all");

        // Sanity: the auditor CAN see that document through a folder of their
        // own, so the 404 above is about the collection rather than the row.
        self::assertContains(
            'Clerk memo',
            self::titles(self::decode($this->list(self::AUDITOR, [])))
        );
    }

    public function testACollectionFromAnotherTenantIsNotFound(): void
    {
        $mine = $this->createCollection(self::AUDITOR, 'Q3 audit');

        $res = $this->callAs('delete', self::OTHER_TENANT_ADMIN, self::OTHER_TENANT, ['id' => (string) $mine]);
        self::assertSame(404, $res->getStatusCode());
    }

    // ── collections and the star ───────────────────────────────────────────

    public function testACollectionRoundTripsThroughItsOwnFolder(): void
    {
        $kept = $this->issue(self::AUDITOR, 'Kept');
        $this->issue(self::AUDITOR, 'Ignored');
        $collectionId = $this->createCollection(self::AUDITOR, 'Q3 audit');

        $added = $this->call('addDocument', self::AUDITOR, [
            'id' => (string) $collectionId,
            'documentId' => (string) $kept,
        ]);
        self::assertSame(200, $added->getStatusCode());
        self::assertTrue(self::decode($added)['data']['in_collection']);

        $body = self::decode($this->list(self::AUDITOR, [
            'view' => CoreDocumentViews::COLLECTION,
            'collection_id' => (string) $collectionId,
        ]));
        self::assertSame(['Kept'], self::titles($body));
        self::assertSame($collectionId, $body['view']['collection_id']);

        // The row carries the caller's own filing, so a client renders the badge
        // without a second request per row.
        self::assertSame([$collectionId], $body['data'][0]['collection_ids']);
        self::assertFalse($body['data'][0]['starred']);
    }

    /** Filing is idempotent in both directions: two clicks must not differ from one. */
    public function testFilingAndUnfilingAreIdempotent(): void
    {
        $documentId = $this->issue(self::AUDITOR, 'Invoice');
        $collectionId = $this->createCollection(self::AUDITOR, 'Q3 audit');
        $params = ['id' => (string) $collectionId, 'documentId' => (string) $documentId];

        foreach ([1, 2] as $_) {
            $res = $this->call('addDocument', self::AUDITOR, $params);
            self::assertSame(200, $res->getStatusCode());
            self::assertTrue(self::decode($res)['data']['in_collection']);
        }

        foreach ([1, 2] as $_) {
            $res = $this->call('removeDocument', self::AUDITOR, $params);
            self::assertSame(200, $res->getStatusCode());
            self::assertFalse(self::decode($res)['data']['in_collection']);
        }
    }

    /**
     * Starring is a collection with a well-known key, not a second concept —
     * and the proof is that a star shows up in the ordinary collections list.
     */
    public function testStarringIsAnOrdinaryCollectionUnderAWellKnownKey(): void
    {
        $documentId = $this->issue(self::AUDITOR, 'Invoice');

        // Nothing exists until the first star: no row is written to record
        // something nobody has done.
        //
        // assertCount(0, …) rather than assertSame([], …) on purpose. PHPStan
        // remembers a narrowed type per EXPRESSION, and the identical
        // `self::rows($this->call('list', …))` two statements below would
        // inherit `array{}` from an assertSame and fail analysis on `[0]`.
        self::assertCount(0, self::rows($this->call('list', self::AUDITOR, [])));

        $this->call('star', self::AUDITOR, ['id' => (string) $documentId]);

        $collections = self::rows($this->call('list', self::AUDITOR, []));
        self::assertCount(1, $collections);
        self::assertSame(DocumentCollectionRepository::STARRED_KEY, $collections[0]['system_key']);
        self::assertSame(1, $collections[0]['item_count']);

        // And it is reachable both ways: through the `starred` folder and
        // through the generic `collection` folder addressed by its id.
        $viaStarred = self::decode($this->list(self::AUDITOR, ['view' => CoreDocumentViews::STARRED]));
        $viaCollection = self::decode($this->list(self::AUDITOR, [
            'view' => CoreDocumentViews::COLLECTION,
            'collection_id' => (string) $collections[0]['id'],
        ]));
        self::assertSame(['Invoice'], self::titles($viaStarred));
        self::assertSame(['Invoice'], self::titles($viaCollection));
        self::assertTrue(self::rows($this->list(self::AUDITOR, ['view' => CoreDocumentViews::STARRED]))[0]['starred']);
    }

    /** Un-starring having never starred is a 200: the requested state is already true. */
    public function testUnstarringWithNoStarredCollectionIsSuccessAndWritesNothing(): void
    {
        $documentId = $this->issue(self::AUDITOR, 'Invoice');

        $res = $this->call('unstar', self::AUDITOR, ['id' => (string) $documentId]);

        self::assertSame(200, $res->getStatusCode());
        self::assertNull(self::decode($res)['data']);
        self::assertFalse(self::decode($res)['starred']);
        self::assertNull($this->collectionRepo->findBySystemKey(
            DocumentCollectionRepository::STARRED_KEY,
            self::TENANT,
            self::AUDITOR
        ));
    }

    /**
     * A keyed collection is not renameable or deletable: the star control
     * addresses it BY KEY, so deleting it would have the next star silently
     * create a different row.
     */
    public function testTheStarredCollectionCannotBeRenamedOrDeleted(): void
    {
        $documentId = $this->issue(self::AUDITOR, 'Invoice');
        $this->call('star', self::AUDITOR, ['id' => (string) $documentId]);
        $starred = $this->collectionRepo->findBySystemKey(
            DocumentCollectionRepository::STARRED_KEY,
            self::TENANT,
            self::AUDITOR
        );
        self::assertNotNull($starred);

        $renamed = $this->call('update', self::AUDITOR, ['id' => (string) $starred['id']], ['name' => 'Favourites']);
        self::assertSame(409, $renamed->getStatusCode());

        $deleted = $this->call('delete', self::AUDITOR, ['id' => (string) $starred['id']]);
        self::assertSame(409, $deleted->getStatusCode());
    }

    public function testDuplicateCollectionNamesAreRefusedPerPersonNotPerTenant(): void
    {
        $this->createCollection(self::AUDITOR, 'Q3 audit');

        $again = $this->call('create', self::AUDITOR, [], ['name' => 'Q3 audit']);
        self::assertSame(409, $again->getStatusCode());

        // Somebody else's identically-named pile is a different pile.
        $other = $this->call('create', self::CLERK, [], ['name' => 'Q3 audit']);
        self::assertSame(201, $other->getStatusCode());
    }

    public function testCollectionNamesAreValidated(): void
    {
        foreach ([[], ['name' => '   '], ['name' => str_repeat('x', 161)], ['name' => 42]] as $body) {
            /** @var array<string, mixed> $body */
            $res = $this->call('create', self::AUDITOR, [], $body);
            self::assertSame(422, $res->getStatusCode());
        }

        // 160 exactly is the column width and must be accepted, so the
        // validator and the schema agree rather than nearly agree.
        $ok = $this->call('create', self::AUDITOR, [], ['name' => str_repeat('x', 160)]);
        self::assertSame(201, $ok->getStatusCode());
    }

    /**
     * Deleting a collection destroys an opinion, not documents. That is the
     * whole difference between a collection and a folder, and it is the reason
     * no folder tree is stored at all.
     */
    public function testDeletingACollectionLeavesItsDocumentsAlone(): void
    {
        $documentId = $this->issue(self::AUDITOR, 'Invoice');
        $collectionId = $this->createCollection(self::AUDITOR, 'Q3 audit');
        $this->call('addDocument', self::AUDITOR, [
            'id' => (string) $collectionId,
            'documentId' => (string) $documentId,
        ]);

        self::assertSame(200, $this->call('delete', self::AUDITOR, ['id' => (string) $collectionId])->getStatusCode());

        $body = self::decode($this->list(self::AUDITOR, []));
        self::assertSame(['Invoice'], self::titles($body));
        self::assertSame([], $body['data'][0]['collection_ids']);
    }

    // ── 5. the plain list is unchanged, and totals match pages ─────────────

    /**
     * The item-1 contract survives: a caller naming no view gets the same plain
     * newest-first page it always got, and the response says which view ran.
     */
    public function testNamingNoViewIsStillThePlainTenantWideList(): void
    {
        $this->issue(self::AUDITOR, 'First');
        $this->issue(self::CLERK, 'Second');

        $body = self::decode($this->list(self::AUDITOR, []));

        self::assertSame(['Second', 'First'], self::titles($body), 'newest first');
        self::assertSame(2, $body['pagination']['total']);
        self::assertSame(CoreDocumentViews::ALL, $body['view']['key']);
    }

    /** Per folder, the total describes the same set the page does. */
    public function testEachFoldersTotalDescribesTheSameSetAsItsPage(): void
    {
        $this->issue(self::AUDITOR, 'A');
        $this->issue(self::AUDITOR, 'B');
        $this->issue(self::CLERK, 'C');

        foreach (
            [
                CoreDocumentViews::ALL => 3,
                CoreDocumentViews::CREATED_BY_ME => 2,
                CoreDocumentViews::RAISED_BY_MY_UNIT => 2,
                CoreDocumentViews::BELOW_MY_UNIT => 3,
            ] as $view => $expected
        ) {
            $body = self::decode($this->list(self::AUDITOR, ['view' => $view, 'per_page' => '2']));
            self::assertSame($expected, $body['pagination']['total'], "view={$view} total");
            self::assertCount(min(2, $expected), $body['data'], "view={$view} page");
        }
    }

    /** The `collection` folder refuses to open without its required parameter. */
    public function testTheCollectionFolderRequiresItsParameter(): void
    {
        $res = $this->list(self::AUDITOR, ['view' => CoreDocumentViews::COLLECTION]);

        self::assertSame(400, $res->getStatusCode(), 'a missing parameter is a client error, not an absent folder');
        self::assertStringContainsString('collection_id', self::decode($res)['error'] ?? '');
    }

    /**
     * A caller without `documents:read:all` sees only their own documents
     * through EVERY folder — the view narrows, it never widens.
     */
    public function testAViewCannotWidenWhatTheCallerMaySee(): void
    {
        $this->issue(self::AUDITOR, 'Registry memo');
        $this->issue(self::CLERK, 'Clerk memo');

        // The clerk sits below Registry in the tree, so "below my unit" anchored
        // at Campus spans both documents — and must still show only their own.
        $body = self::decode($this->list(self::CLERK, [
            'view' => CoreDocumentViews::BELOW_MY_UNIT,
            'ou_id' => (string) self::OU_CAMPUS,
        ]));

        self::assertSame(['Clerk memo'], self::titles($body));
        self::assertSame(1, $body['pagination']['total']);
    }

    /** The render route does not compute filing, so it says nothing about it. */
    public function testTheRenderRouteOmitsTheFilingFieldsRatherThanGuessingThem(): void
    {
        TenantContext::reset();
        TenantContext::setTenantId(self::TENANT);
        $templateId = $this->createTemplate(self::AUDITOR, 'Invoice Template');
        $req = new Request(
            'POST',
            "/api/document-templates/{$templateId}/render",
            [],
            (string) json_encode(['persist' => true, 'title' => 'Invoice'])
        );
        $req->user = (object) ['profile_id' => self::AUDITOR, 'active_tenant_id' => self::TENANT];

        $rendered = self::decode($this->renderHandler->render($req, ['id' => (string) $templateId]))['data'];

        self::assertArrayNotHasKey('starred', $rendered, 'absent means "not computed", false would be a claim');
        self::assertArrayNotHasKey('collection_ids', $rendered);

        // The route that DOES ask reports it.
        $shown = self::data($this->documentsCall('show', self::AUDITOR, ['id' => (string) $rendered['id']]));
        self::assertArrayHasKey('starred', $shown);
        self::assertFalse($shown['starred']);
    }

    // ── helpers ────────────────────────────────────────────────────────────

    /** @param array<string, string> $query */
    private function list(int $actorId, array $query): Response
    {
        return $this->listAs($actorId, self::TENANT, $query);
    }

    /** @param array<string, string> $query */
    private function listAs(int $actorId, int $tenantId, array $query): Response
    {
        return $this->documentsCall('list', $actorId, [], $tenantId, $query);
    }

    private function getViews(int $actorId): Response
    {
        return $this->documentsCall('views', $actorId, []);
    }

    /**
     * @param array<string, string> $params
     * @param array<string, string> $query
     */
    private function documentsCall(
        string $method,
        int $actorId,
        array $params,
        int $tenantId = self::TENANT,
        array $query = []
    ): Response {
        TenantContext::reset();
        TenantContext::setTenantId($tenantId);
        // The query string goes in the PATH rather than into $_GET: the handler
        // reads both (mirroring PaginationParams), and a test that only ever set
        // the superglobal would leak state between cases.
        $path = '/api/documents' . ($query === [] ? '' : '?' . http_build_query($query));
        $req = new Request('GET', $path, [], '');
        $req->user = (object) ['profile_id' => $actorId, 'active_tenant_id' => $tenantId];

        return match ($method) {
            'views' => $this->documents->views($req),
            'list' => $this->documents->list($req),
            'show' => $this->documents->show($req, $params),
            default => throw new \InvalidArgumentException("Unknown documents route: {$method}"),
        };
    }

    /**
     * @param array<string, string> $params
     * @param array<string, mixed>  $body
     */
    private function call(string $method, int $actorId, array $params, array $body = []): Response
    {
        return $this->callAs($method, $actorId, self::TENANT, $params, $body);
    }

    /**
     * @param array<string, string> $params
     * @param array<string, mixed>  $body
     */
    private function callAs(string $method, int $actorId, int $tenantId, array $params, array $body = []): Response
    {
        TenantContext::reset();
        TenantContext::setTenantId($tenantId);
        $req = new Request('POST', '/api/document-collections', [], (string) json_encode($body));
        $req->user = (object) ['profile_id' => $actorId, 'active_tenant_id' => $tenantId];

        return match ($method) {
            'list' => $this->collections->list($req),
            'create' => $this->collections->create($req),
            'update' => $this->collections->update($req, $params),
            'delete' => $this->collections->delete($req, $params),
            'addDocument' => $this->collections->addDocument($req, $params),
            'removeDocument' => $this->collections->removeDocument($req, $params),
            'star' => $this->collections->star($req, $params),
            'unstar' => $this->collections->unstar($req, $params),
            default => throw new \InvalidArgumentException("Unknown collections route: {$method}"),
        };
    }

    private function createCollection(int $actorId, string $name): int
    {
        $res = $this->call('create', $actorId, [], ['name' => $name]);
        self::assertSame(201, $res->getStatusCode(), 'fixture setup: creating a collection must succeed');

        return (int) self::data($res)['id'];
    }

    /** Issue a document the way production does: a persisted render. */
    private function issue(int $actorId, string $title): int
    {
        TenantContext::reset();
        TenantContext::setTenantId(self::TENANT);

        return $this->issueFromTemplate(
            $actorId,
            $this->createTemplate($actorId, $title . ' Template'),
            $title
        );
    }

    /**
     * Issue from an EXISTING template, so several documents can share one
     * `template_name` snapshot — which is what makes a tie in that sort key
     * reachable.
     */
    private function issueFromTemplate(int $actorId, int $templateId, string $title): int
    {
        TenantContext::reset();
        TenantContext::setTenantId(self::TENANT);
        $req = new Request(
            'POST',
            "/api/document-templates/{$templateId}/render",
            [],
            (string) json_encode(['persist' => true, 'title' => $title])
        );
        $req->user = (object) ['profile_id' => $actorId, 'active_tenant_id' => self::TENANT];

        $res = $this->renderHandler->render($req, ['id' => (string) $templateId]);
        self::assertSame(201, $res->getStatusCode(), 'fixture setup: the persisted render must succeed');

        return (int) self::data($res)['id'];
    }

    /**
     * Fixture surgery: give a document a distinct `created_at`.
     *
     * The render path stamps `NOW()`, so documents issued in one test share a
     * timestamp to the second and a date sort has nothing to order by. Written
     * directly rather than by sleeping between renders — a test that takes three
     * seconds to establish a fixture is a test people stop running.
     */
    private function setCreatedAt(string $title, string $timestamp): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE documents SET created_at = :created_at WHERE tenant_id = :tenant_id AND title = :title'
        );
        $stmt->execute([':created_at' => $timestamp, ':tenant_id' => self::TENANT, ':title' => $title]);
        self::assertSame(1, $stmt->rowCount(), "fixture setup: no document titled '{$title}'");
    }

    private function createTemplate(int $actorId, string $name): int
    {
        return $this->templateRepo->create(self::TENANT, [
            'name' => $name,
            'data' => [
                'version' => 2,
                'page' => ['widthMm' => 210, 'heightMm' => 297, 'marginMm' => 10, 'background' => '#fff'],
                'placeholders' => [],
                'pages' => [['id' => 'p1', 'elements' => []]],
            ],
            'scope' => 'tenant',
            'created_by' => $actorId,
        ]);
    }

    /**
     * The `data` array of a list response, as a list PHPStan can index into.
     *
     * Reading `decode($r)['data'][0]` directly narrows to `array{}` after an
     * earlier `assertSame([], …)` in the same method and fails analysis — this
     * asserts the shape once instead of scattering annotations.
     *
     * @return list<array<string, mixed>>
     */
    private static function rows(Response $response): array
    {
        $data = self::decode($response)['data'] ?? null;
        self::assertIsArray($data);

        /** @var list<array<string, mixed>> $data */
        return $data;
    }

    /**
     * @param list<array<string, mixed>> $views
     * @return array<string, array<string, mixed>>
     */
    private static function byKey(array $views): array
    {
        $byKey = [];
        foreach ($views as $view) {
            $byKey[(string) $view['key']] = $view;
        }

        return $byKey;
    }

    /**
     * @param array<string, mixed> $body
     * @return list<string>
     */
    private static function titles(array $body): array
    {
        /** @var list<array<string, mixed>> $data */
        $data = $body['data'];

        return array_map(static fn (array $row): string => (string) $row['title'], $data);
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(Response $response): array
    {
        $decoded = json_decode($response->getBody(), true);
        self::assertIsArray($decoded, 'the response body must be a JSON object');

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @return array<string, mixed>
     */
    private static function data(Response $response): array
    {
        $data = self::decode($response)['data'] ?? null;
        self::assertIsArray($data);

        /** @var array<string, mixed> $data */
        return $data;
    }

    private function makeSchema(): PDO
    {
        $pdo = SchemaFromMigrations::make(true);
        $pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'a', 'a')");
        $pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (2, 'b', 'b')");

        // 2 (Campus) → 3 (Registry) → 4 (Records Office); 3 → 5 (Archive).
        // 9 belongs to the OTHER tenant, so the subtree walk and the anchor
        // validation both have something they must refuse to reach.
        $pdo->exec("INSERT INTO organizational_units (id, tenant_id, parent_id, name, slug, created_at) VALUES
            (2, 1, NULL, 'Campus',        'campus',  datetime('now')),
            (3, 1, 2,    'Registry',      'registry', datetime('now')),
            (4, 1, 3,    'Records Office','records',  datetime('now')),
            (5, 1, 3,    'Archive',       'archive',  datetime('now')),
            (9, 2, NULL, 'Other Campus',  'other',    datetime('now'))");

        $pdo->exec("INSERT OR IGNORE INTO roles (id, name, description, tenant_id, created_at)
                    VALUES (1, 'admin', '', NULL, datetime('now'))");
        $pdo->exec("INSERT INTO roles (id, name, description, tenant_id, created_at) VALUES
            (101, 'clerk', '', 1, datetime('now'))");

        // The admin role already holds documents:* through migrations 060/109.
        foreach ([CorePermissions::DOCUMENTS_READ, CorePermissions::DOCUMENTS_RENDER] as $permission) {
            $this->grant($pdo, 101, $permission);
        }

        $pdo->exec("
            INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled,
                                  two_factor_backup_codes_version, token_epoch, created_at, updated_at) VALUES
                (10, 'auditor',      'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
                (11, 'clerk',        'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
                (12, 'unaffiliated', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
                (20, 'other-admin',  'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ");
        // The unaffiliated member holds a membership with NO unit — the case the
        // #951 assertions turn on. The other tenant's admin likewise.
        $pdo->exec("
            INSERT INTO memberships (id, profile_id, tenant_id, role_id, ou_id, is_primary, status, created_at) VALUES
                (1000, 10, 1, 1,   3,    true, 'active', datetime('now')),
                (1001, 11, 1, 101, 4,    true, 'active', datetime('now')),
                (1002, 12, 1, 101, NULL, true, 'active', datetime('now')),
                (1003, 20, 2, 1,   NULL, true, 'active', datetime('now'))
        ");

        return $pdo;
    }

    private function grant(PDO $pdo, int $roleId, string $permission): void
    {
        $pdo->prepare('INSERT OR IGNORE INTO permissions (name, description, created_at) VALUES (?, ?, NOW())')
            ->execute([$permission, '']);
        $sel = $pdo->prepare('SELECT id FROM permissions WHERE name = ?');
        $sel->execute([$permission]);
        $pdo->prepare('INSERT OR IGNORE INTO role_permissions (role_id, permission_id, created_at) VALUES (?, ?, NOW())')
            ->execute([$roleId, (int) $sel->fetchColumn()]);
    }

    private function revoke(int $roleId, string $permission): void
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM role_permissions WHERE role_id = ?
              AND permission_id = (SELECT id FROM permissions WHERE name = ?)'
        );
        $stmt->execute([$roleId, $permission]);
    }

    private function wrapSqlite(PDO $pdo): Database
    {
        $db = Database::withFactory(static fn (): PDO => $pdo);
        $db->setMaxLifetimeSeconds(86400);
        $db->setPingIntervalSeconds(86400);
        $db->forceConnect();

        return $db;
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
