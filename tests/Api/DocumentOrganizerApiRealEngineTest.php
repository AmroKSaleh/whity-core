<?php

declare(strict_types=1);

namespace Tests\Api;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\DocumentCollectionsApiHandler;
use Whity\Api\DocumentsApiHandler;
use Whity\Auth\RoleChecker;
use Whity\Core\Audience\ExplicitRuleResolver;
use Whity\Core\Document\DocumentAccessPolicy;
use Whity\Core\Document\DocumentArtifactRepository;
use Whity\Core\Document\DocumentArtifactStore;
use Whity\Core\Document\DocumentCollectionRepository;
use Whity\Core\Document\DocumentIssuer;
use Whity\Core\Document\DocumentBlockRepository;
use Whity\Core\Document\DocumentRepository;
use Whity\Core\Document\DocumentTemplateRepository;
use Whity\Core\Document\DocumentVisibilityPolicy;
use Whity\Core\Document\Routing\DocumentRouter;
use Whity\Core\Document\Routing\RoleBelowActorRuleResolver;
use Whity\Core\Document\Routing\RoleRuleResolver;
use Whity\Core\Document\Routing\RouteAction;
use Whity\Core\Document\Routing\RouteEdgeRepository;
use Whity\Core\Document\Routing\RouteEventRepository;
use Whity\Core\Document\Routing\RouteRecipientRepository;
use Whity\Core\Document\Routing\RouteRepository;
use Whity\Core\Document\Routing\RouteStepRepository;
use Whity\Core\Document\Routing\RoutingRuleRegistry;
use Whity\Core\Document\Organizer\CoreDocumentSubstrates;
use Whity\Core\Document\Organizer\CoreDocumentViews;
use Whity\Core\Document\Organizer\DocumentSubstrateRegistry;
use Whity\Core\Document\Organizer\DocumentViewRegistry;
use Whity\Core\Document\Organizer\PdoSchemaPresence;
use Whity\Core\Document\Render\DocumentRenderer;
use Whity\Core\Group\GroupResolver;
use Whity\Core\Group\GroupRuleResolver;
use Whity\Core\Group\UserGroupRepository;
use Whity\Core\Ou\OuReachResolver;
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
 *  1. NO FOLDER IS RENDERED THAT CANNOT BE COMPUTED. The routing-derived folders
 *     are offered here, because this schema is built from every migration — and
 *     {@see testTheRoutingFoldersVanishWhenTheirTablesDo()} DROPS migration 112's
 *     tables from the live database and asserts that all three leave the rail and
 *     404 on request. An empty "Awaiting me" would state "nothing awaits you",
 *     which is false, so the folder is absent rather than empty.
 *  2. THE THREE-WAY DISTINCTION. Absent (404), unanchored-for-this-caller (422
 *     with a reason), and genuinely empty (200 with no rows) are three different
 *     answers to three different questions.
 *  2b. THE INBOX ONLY LISTS OPEN ITEMS. A recipient row closed by an act leaves
 *     "awaiting me" and stays in "acted on by me". An inbox that keeps what you
 *     have already done never empties, and is wrong in the direction that looks
 *     like work — so the transition is driven through the real
 *     {@see DocumentRouter} rather than by writing the rows this test wants.
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

    /** Roles the routing fixtures address. `admin` is seeded by migration 060; `clerk` below. */
    private const ROLE_ADMIN = 1;
    private const ROLE_CLERK = 101;

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
    private DocumentRouter $router;
    private string $storageRoot;

    /**
     * Builds a handler whose registries measured the schema AS IT IS NOW.
     *
     * Held as a factory rather than built once because one case drops migration
     * 112's tables mid-test: {@see PdoSchemaPresence} caches per instance,
     * exactly as production does, so the handler that must not see routing has to
     * be a different instance rather than the same one asked again.
     *
     * @var \Closure(): DocumentsApiHandler
     */
    private \Closure $makeDocuments;

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
        $recipientRepo = new RouteRecipientRepository($this->pdo);
        $visibility = new DocumentVisibilityPolicy(
            $recipientRepo,
            new ResourceRoleAssignmentRepository($this->pdo, new ResourceTypeRegistry())
        );
        $templatePolicy = new DocumentAccessPolicy();

        // The real routing engine, so the routing folders are asked about rows
        // the engine actually wrote. The alternative — INSERTing recipient and
        // trail rows this test chose — would let "awaiting me" pass while
        // disagreeing with what routing produces, which is the one thing an
        // inbox must not do. In particular, closing a recipient row is
        // DocumentRouter::act()'s business, and that transition is the assertion
        // that matters most here.
        //
        // All FOUR core kinds are registered, not just the two this file's
        // routes exercise (#999). This test's standing rule is that the engine
        // is wired the way public/index.php wires it — a registry missing two
        // kinds would let a folder pass here against an engine production does
        // not have. The `group` kind's construction order is forced by a real
        // cycle (the resolver needs the registry that resolves whatever kind the
        // group is defined as), broken with the same closure index.php uses.
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
        $this->router = new DocumentRouter(
            $this->pdo,
            new RouteRepository($this->pdo),
            new RouteStepRepository($this->pdo),
            new RouteEventRepository($this->pdo),
            $recipientRepo,
            new RouteEdgeRepository($this->pdo),
            $rules,
            $settings,
            null
        );

        // Wired the way public/index.php wires it: registries built over the
        // LIVE schema, so an unavailable folder here is unavailable in
        // production and vice versa.
        $this->makeDocuments = function () use (
            $documentRepo,
            $artifactRepo,
            $store,
            $visibility,
            $templatePolicy,
            $renderer,
            $issuer,
            $roleChecker,
            $settings
        ): DocumentsApiHandler {
            $substrates = new DocumentSubstrateRegistry(new PdoSchemaPresence($this->pdo));
            CoreDocumentSubstrates::registerInto($substrates);
            $views = new DocumentViewRegistry($substrates);
            CoreDocumentViews::registerInto($views);

            return new DocumentsApiHandler(
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
                new OuReachResolver($this->pdo, new ResourceRoleAssignmentRepository($this->pdo, new ResourceTypeRegistry())),
            );
        };
        $this->documents = ($this->makeDocuments)();

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
            $issuer,
            new OuReachResolver($this->pdo, new ResourceRoleAssignmentRepository($this->pdo, new ResourceTypeRegistry())),
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
     * compute — which, on a schema built from every migration, is all of them.
     */
    public function testTheRailOffersEveryComputableFolderAndNamesWhatIsMissing(): void
    {
        $body = self::decode($this->getViews(self::AUDITOR));

        $keys = array_map(static fn (array $v): string => (string) $v['key'], $body['data']);
        self::assertSame(
            [
                CoreDocumentViews::ALL,
                CoreDocumentViews::CREATED_BY_ME,
                CoreDocumentViews::RAISED_BY_MY_UNIT,
                CoreDocumentViews::BELOW_MY_UNIT,
                CoreDocumentViews::AWAITING_ME,
                CoreDocumentViews::ACTED_ON_BY_ME,
                CoreDocumentViews::APPROVED_BY_ME,
                CoreDocumentViews::REJECTED_BY_ME,
                CoreDocumentViews::PASSED_THROUGH_MY_UNIT,
                CoreDocumentViews::STARRED,
                CoreDocumentViews::COLLECTION,
            ],
            $keys
        );

        // Each routing folder tells the client which fact source it reads, which
        // is what lets a rail explain its own absence somewhere else.
        $byKey = self::byKey(self::rows($this->getViews(self::AUDITOR)));
        self::assertSame(['routing.recipients'], $byKey[CoreDocumentViews::AWAITING_ME]['requires']);
        self::assertSame(['routing.trail'], $byKey[CoreDocumentViews::ACTED_ON_BY_ME]['requires']);
        self::assertSame(
            ['routing.trail', 'ou.tree'],
            $byKey[CoreDocumentViews::PASSED_THROUGH_MY_UNIT]['requires']
        );

        // Nothing is reported missing on a fully migrated installation, which is
        // simply true here. The field still exists and is still the place an
        // absent fact source is explained (#951) — the case below takes the
        // routing tables away and reads it.
        self::assertSame([], $body['unavailable_substrates']);
    }

    /**
     * The property the whole registry exists for, asserted against a real
     * database rather than by inspection: DROP migration 112's tables and the
     * three routing folders leave the rail entirely and 404 on request, while the
     * six that read `documents` are untouched.
     *
     * This is what an installation that has not run migration 112 looks like from
     * outside, and it is the state #978 built the seam for. The folders are not
     * rendered-and-empty, not listed-and-disabled, but ABSENT: there is nothing
     * truthful to say about an inbox on an installation that records no
     * recipients, and an empty one would say "nothing awaits you".
     */
    public function testTheRoutingFoldersVanishWhenTheirTablesDo(): void
    {
        $this->issue(self::AUDITOR, 'Invoice');

        // Recipients first: their rows point INTO the trail, so the other order
        // is refused by the foreign key on PostgreSQL. CASCADE covers the
        // dependency either way and SQLite has no such clause, which is why the
        // order is written out rather than relied upon.
        $this->pdo->exec('DROP TABLE document_route_recipients');
        $this->pdo->exec('DROP TABLE document_route_events');

        // A NEW handler, because the schema probe caches per instance exactly as
        // it does in production. Asking the old one again would answer from a map
        // read before the drop, which is the staleness PdoSchemaPresence is
        // instance-scoped to bound rather than to eliminate.
        $documents = ($this->makeDocuments)();

        $body = self::decode($this->documentsCallOn($documents, 'views', self::AUDITOR));
        $keys = array_map(static fn (array $v): string => (string) $v['key'], self::asRows($body['data']));

        self::assertSame(
            [
                CoreDocumentViews::ALL,
                CoreDocumentViews::CREATED_BY_ME,
                CoreDocumentViews::RAISED_BY_MY_UNIT,
                CoreDocumentViews::BELOW_MY_UNIT,
                CoreDocumentViews::STARRED,
                CoreDocumentViews::COLLECTION,
            ],
            $keys,
            'without migration 112 the rail is exactly what #978 shipped'
        );

        // Not hidden in silence: the two missing fact sources are named, with
        // what would supply them, so an operator asking "why is there no inbox
        // here" gets an answer instead of a shorter list (#951).
        $missing = self::asRows($body['unavailable_substrates']);
        self::assertEqualsCanonicalizing(
            ['routing.recipients', 'routing.trail', 'routing.verdict'],
            array_map(static fn (array $s): string => (string) $s['key'], $missing)
        );
        // Each names the migration that supplies it, and they do not all name
        // the same one — #1014's verdict arrived in 118, and an operator sent to
        // run 112 for it would run a migration they already have.
        foreach ($missing as $substrate) {
            self::assertMatchesRegularExpression('/migration 11[29]/', (string) $substrate['provenance']);
        }

        // And requesting one is a 404: from outside, it does not exist.
        foreach (
            [
                CoreDocumentViews::AWAITING_ME,
                CoreDocumentViews::ACTED_ON_BY_ME,
                CoreDocumentViews::APPROVED_BY_ME,
                CoreDocumentViews::REJECTED_BY_ME,
                CoreDocumentViews::PASSED_THROUGH_MY_UNIT,
            ] as $key
        ) {
            $res = $this->documentsCallOn($documents, 'list', self::AUDITOR, query: ['view' => $key]);
            self::assertSame(404, $res->getStatusCode(), "view={$key} must 404, never return an empty page");
        }
    }

    /** A key nobody registered is the same 404, and for the same reason. */
    public function testRequestingAViewThatWasNeverRegisteredIsNotFound(): void
    {
        $this->issue(self::AUDITOR, 'Invoice');

        $res = $this->list(self::AUDITOR, ['view' => 'nonsense']);
        self::assertSame(404, $res->getStatusCode());
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

    // ── the routing folders ────────────────────────────────────────────────

    /**
     * The assertion that matters most in this file.
     *
     * "Awaiting me" lists OPEN recipient rows. A row closed by an act leaves the
     * folder, and the transition is driven through the real
     * {@see DocumentRouter} — issue puts the row there, `act` closes it — rather
     * than by writing the rows this test would like to see.
     *
     * Get this wrong and the inbox never empties: every document that ever
     * reached you stays listed, the count only ever rises, and within a week
     * nobody reads it. That is worse than not shipping the folder, because the
     * screen still looks like it is working.
     */
    public function testAwaitingMeListsOpenRowsAndDropsThemTheMomentTheyAreActedOn(): void
    {
        $route = $this->routeToClerkBelowRegistry('Circular');

        // Before: the clerk has an open row, and can see the document BECAUSE a
        // route reached them — they hold no `documents:read:all`.
        $before = self::decode($this->list(self::CLERK, ['view' => CoreDocumentViews::AWAITING_ME]));
        self::assertSame(['Circular'], self::titles($before));
        self::assertSame(1, $before['pagination']['total'], 'the total describes the same set as the page');

        $this->act(self::CLERK, $route, RouteAction::ACKNOWLEDGED);

        // After: the row is closed, so it is out of the inbox — and the row
        // itself still exists, which is the point. The folder is a predicate over
        // `closed_by_event_id`, not a delete.
        $after = self::decode($this->list(self::CLERK, ['view' => CoreDocumentViews::AWAITING_ME]));
        self::assertSame([], $after['data'], 'an acted-on item must leave the inbox');
        self::assertSame(0, $after['pagination']['total']);
        self::assertSame(
            1,
            $this->countRows('SELECT COUNT(*) FROM document_route_recipients WHERE tenant_id = ?', [self::TENANT]),
            'the recipient row is closed, never removed — its history is the inbox\'s whole basis'
        );
        self::assertSame(
            1,
            $this->countRows(
                'SELECT COUNT(*) FROM document_route_recipients
                  WHERE tenant_id = ? AND closed_by_event_id IS NOT NULL',
                [self::TENANT]
            ),
            'and it is closed by pointing at the trail row for the act, which is what "open" is the '
                . 'absence of'
        );
    }

    /**
     * The complement, and why the two folders are separate predicates rather than
     * one slot: a document you have finished with LEAVES your inbox and STAYS in
     * "acted on by me". Somebody who forwarded something last week must still be
     * able to find what they forwarded.
     */
    public function testActedOnByMeKeepsWhatHasLeftYourInbox(): void
    {
        $route = $this->routeToClerkBelowRegistry('Circular');

        // Nothing yet: being a recipient is not having acted.
        self::assertSame(
            [],
            self::decode($this->list(self::CLERK, ['view' => CoreDocumentViews::ACTED_ON_BY_ME]))['data'],
            'an item sitting in your inbox is not one you have acted on'
        );

        $this->act(self::CLERK, $route, RouteAction::ACKNOWLEDGED);

        self::assertSame(
            ['Circular'],
            self::titles(self::decode($this->list(self::CLERK, ['view' => CoreDocumentViews::ACTED_ON_BY_ME])))
        );

        // The issuer is an actor too — the `issued` event names them — so this is
        // not "things in my inbox I closed", it is the trail read by actor.
        self::assertSame(
            ['Circular'],
            self::titles(self::decode($this->list(self::AUDITOR, ['view' => CoreDocumentViews::ACTED_ON_BY_ME])))
        );

        // And a person who has been sent nothing and done nothing gets an honest
        // empty page rather than a refusal: this folder anchors on the caller,
        // who always exists.
        $res = $this->list(self::UNAFFILIATED, ['view' => CoreDocumentViews::ACTED_ON_BY_ME]);
        self::assertSame(200, $res->getStatusCode());
        self::assertSame([], self::decode($res)['data']);
    }

    /**
     * "Passed through my unit" reads BOTH ends of a transition, over the anchor's
     * SUBTREE.
     *
     * The fixture is built so each end is provable on its own. The `issued` event
     * records `from_ou_id` = Registry (the issuer's unit) and `to_ou_id` = Records
     * Office (the single unit its recipients landed in), and nobody in Records has
     * acted yet — so a folder anchored at Records can only be matching on the
     * arriving end. A `from`-only predicate would answer nothing there while
     * looking correct everywhere else.
     */
    public function testPassedThroughMyUnitCoversBothEndsOfATransitionAcrossTheSubtree(): void
    {
        $this->routeToClerkBelowRegistry('Circular');

        // Registry is the FROM end, and its subtree contains the TO end as well.
        self::assertSame(
            ['Circular'],
            self::titles(self::decode($this->list(self::AUDITOR, [
                'view' => CoreDocumentViews::PASSED_THROUGH_MY_UNIT,
            ]))),
            'the issuing unit is on the trail'
        );

        // Records Office is the TO end ONLY: no event has left it.
        self::assertSame(
            0,
            $this->countRows(
                'SELECT COUNT(*) FROM document_route_events WHERE tenant_id = ? AND from_ou_id = ?',
                [self::TENANT, self::OU_RECORDS]
            ),
            'fixture: nothing has been done FROM Records Office yet, so the next assertion can only '
                . 'be matching on to_ou_id'
        );
        self::assertSame(
            ['Circular'],
            self::titles(self::decode($this->list(self::AUDITOR, [
                'view' => CoreDocumentViews::PASSED_THROUGH_MY_UNIT,
                'ou_id' => (string) self::OU_RECORDS,
            ]))),
            'a unit the routing arrived at has had the document pass through it'
        );

        // Archive is a sibling of Records under Registry and is on neither end,
        // so it is an honestly empty page — the predicate is a filter, not a
        // "documents that were routed at all" list.
        $archive = self::decode($this->list(self::AUDITOR, [
            'view' => CoreDocumentViews::PASSED_THROUGH_MY_UNIT,
            'ou_id' => (string) self::OU_ARCHIVE,
        ]));
        self::assertSame([], $archive['data']);
        self::assertSame(0, $archive['pagination']['total']);

        // And a document that was never routed is in no unit's folder, however
        // it was raised.
        $this->issue(self::AUDITOR, 'Never routed');
        self::assertSame(
            ['Circular'],
            self::titles(self::decode($this->list(self::AUDITOR, [
                'view' => CoreDocumentViews::PASSED_THROUGH_MY_UNIT,
            ]))),
            'being raised from a unit is a different fact from having passed through it'
        );
    }

    /**
     * The #951 case for the third unit folder. A caller in no unit cannot anchor
     * it, so it is LISTED and disabled with the reason, and opening it is a 422 —
     * never a 200 with no rows, which would read as "nothing passed through my
     * unit" to somebody who has no unit.
     */
    public function testPassedThroughMyUnitIsUnanchoredRatherThanEmptyForACallerInNoUnit(): void
    {
        $this->routeToClerkBelowRegistry('Circular');

        $byKey = self::byKey(self::rows($this->getViews(self::UNAFFILIATED)));
        self::assertArrayHasKey(CoreDocumentViews::PASSED_THROUGH_MY_UNIT, $byKey);
        self::assertFalse($byKey[CoreDocumentViews::PASSED_THROUGH_MY_UNIT]['available']);
        self::assertStringContainsString(
            'unit',
            (string) $byKey[CoreDocumentViews::PASSED_THROUGH_MY_UNIT]['unavailable_reason']
        );

        // The two caller-anchored routing folders are unaffected: having no unit
        // does not stop you having an inbox.
        self::assertTrue($byKey[CoreDocumentViews::AWAITING_ME]['available']);
        self::assertTrue($byKey[CoreDocumentViews::ACTED_ON_BY_ME]['available']);

        $res = $this->list(self::UNAFFILIATED, ['view' => CoreDocumentViews::PASSED_THROUGH_MY_UNIT]);
        self::assertSame(422, $res->getStatusCode(), '422 is the folder existing and this caller not fitting it');
        self::assertStringContainsString('unit', self::decode($res)['error'] ?? '');
    }

    /**
     * A routing folder narrows like every other one: it cannot show a caller a
     * document they may not see, even though the recipient row proves routing
     * reached somebody.
     */
    public function testARoutingFolderCannotWidenWhatTheCallerMaySee(): void
    {
        $this->routeToClerkBelowRegistry('Circular');
        // A second document whose routing touches the SAME units and never
        // reaches the clerk: circulated among admins, all of whom sit in
        // Registry. Its trail is inside the subtree the clerk anchors on below,
        // so only row visibility can be what keeps it out.
        $this->routeTo('Admins only', 'role', self::ROLE_ADMIN);

        // The clerk sits below Registry, so anchoring at Campus spans everything
        // the trail touched — and they still see only what reached them.
        $body = self::decode($this->list(self::CLERK, [
            'view' => CoreDocumentViews::PASSED_THROUGH_MY_UNIT,
            'ou_id' => (string) self::OU_CAMPUS,
        ]));

        self::assertSame(['Circular'], self::titles($body));
        self::assertSame(1, $body['pagination']['total']);
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
     * Issue a document and route it, through the real engine.
     *
     * `role_below_actor` from the auditor (Registry) with the clerk's role
     * resolves to exactly one person in exactly one unit — the clerk, in Records
     * Office — which is what makes the `issued` event carry a single `to_ou_id`
     * and lets the "both ends of a transition" assertions separate the two ends.
     * A tenant-wide `role` fan-out spanning two units would leave `to_ou_id`
     * null, correctly, and prove nothing about the arriving end.
     *
     * @return array<string, mixed> The `document_routes` row, for {@see act()}.
     */
    private function routeToClerkBelowRegistry(string $title): array
    {
        return $this->routeTo($title, 'role_below_actor', self::ROLE_CLERK);
    }

    /** @return array<string, mixed> The `document_routes` row. */
    private function routeTo(string $title, string $ruleKind, int $roleId, int $actorId = self::AUDITOR): array
    {
        $documentId = $this->issue($actorId, $title);

        $stmt = $this->pdo->prepare('SELECT * FROM documents WHERE id = ? AND tenant_id = ?');
        $stmt->execute([$documentId, self::TENANT]);
        $document = $stmt->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($document, 'fixture setup: the issued document must be readable');

        $outcome = $this->router->issue(self::TENANT, $actorId, $document, $title . ' route', [
            ['rule_kind' => $ruleKind, 'rule_config' => ['role_id' => $roleId]],
        ]);
        self::assertGreaterThan(
            0,
            $outcome['delivered'],
            'fixture setup: a route that reached nobody would make every assertion below vacuous'
        );

        return $outcome['route'];
    }

    /** @param array<string, mixed> $route */
    private function act(int $actorId, array $route, string $action): void
    {
        $this->router->act(self::TENANT, $actorId, $route, $action, null);
    }

    /**
     * A COUNT read straight off the tables, for the two assertions that are about
     * ROWS rather than about a response: "the recipient row was CLOSED, not
     * deleted", and "nothing has yet been done FROM this unit" — the fixture
     * precondition that makes the `to_ou_id` assertion beside it mean anything.
     * Neither is visible through the API, which is the point of reading the
     * database for them.
     *
     * @param list<int|string> $bindings
     */
    private function countRows(string $sql, array $bindings = []): int
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($bindings);

        return (int) $stmt->fetchColumn();
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
        return $this->documentsCallOn($this->documents, $method, $actorId, $params, $tenantId, $query);
    }

    /**
     * The same call against an EXPLICIT handler, for the one case that needs a
     * handler whose schema probe ran after a table was dropped.
     *
     * @param array<string, string> $params
     * @param array<string, string> $query
     */
    private function documentsCallOn(
        DocumentsApiHandler $handler,
        string $method,
        int $actorId,
        array $params = [],
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
            'views' => $handler->views($req),
            'list' => $handler->list($req),
            'show' => $handler->show($req, $params),
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
        $templateId = $this->createTemplate($actorId, $title . ' Template');
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
     * A decoded JSON array as a list PHPStan can index into — the same job
     * {@see rows()} does for a response, for a field that is not `data`.
     *
     * @return list<array<string, mixed>>
     */
    private static function asRows(mixed $value): array
    {
        self::assertIsArray($value);

        /** @var list<array<string, mixed>> $value */
        return $value;
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
