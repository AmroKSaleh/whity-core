<?php

declare(strict_types=1);

namespace Tests\Api;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\EditorTemplateFixture;
use Tests\Support\FakeRenderServiceClient;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\DocumentsApiHandler;
use Whity\Auth\RoleChecker;
use Whity\Core\Document\DocumentArtifactRepository;
use Whity\Core\Document\DocumentArtifactStore;
use Whity\Core\Document\DocumentCollectionRepository;
use Whity\Core\Document\DocumentIssuer;
use Whity\Core\Document\DocumentRepository;
use Whity\Core\Document\DocumentVisibilityPolicy;
use Whity\Core\Document\Organizer\CoreDocumentSubstrates;
use Whity\Core\Document\Organizer\CoreDocumentViews;
use Whity\Core\Document\Organizer\DocumentSubstrateRegistry;
use Whity\Core\Document\Organizer\DocumentViewRegistry;
use Whity\Core\Document\Organizer\PdoSchemaPresence;
use Whity\Core\Document\Qr\DocumentQrScanRepository;
use Whity\Core\Document\Qr\DocumentQrService;
use Whity\Core\Document\Qr\DocumentQrTokenRepository;
use Whity\Core\Document\Routing\RouteRecipientRepository;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\RBAC\ResourceRoleAssignmentRepository;
use Whity\Core\RBAC\ResourceTypeRegistry;
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
 * Real-engine tests for {@see DocumentsApiHandler} (#947 item 1) — issued
 * documents, and the guarantees the whole item exists to make.
 *
 * The four things worth failing a build over:
 *
 *  1. IMMUTABILITY. A re-render APPENDS. The superseded artifact keeps its own
 *     id, its own URL and its own bytes, and both are fetchable afterwards —
 *     asserted by comparing the bytes actually returned, not by counting rows.
 *  2. TENANT ISOLATION. A document raised in tenant 1 is invisible, unreadable
 *     and un-re-renderable from tenant 2, at every route including the two that
 *     stream bytes.
 *  3. VISIBILITY. A colleague holding `documents:read` sees nothing of a
 *     document they did not raise; a holder of `documents:read:all` sees it.
 *     The list is filtered in SQL, so its PAGINATION TOTAL is filtered too — a
 *     post-filtered page would report a total the caller cannot reach.
 *  4. THE STORAGE KEY NEVER LEAVES THE SERVER, on any route.
 *
 * And, since editor M4c, what a correction IS: the same published version of
 * the same editor template, filled with the values the document was raised
 * with, carrying the same verification code — and a refusal (409) for a
 * document that cannot be corrected that way rather than a guess.
 *
 * The render service is faked ({@see FakeRenderServiceClient});
 * storage is a REAL {@see LocalStorageDriver} over a throwaway directory,
 * because the assertions here are about what is actually on disk and a fake
 * store would let a write that never happened pass.
 */
final class DocumentsApiHandlerRealEngineTest extends TestCase
{
    private const TENANT = 1;
    private const OTHER_TENANT = 2;

    private const OWNER = 10;   // admin in tenant 1 -> documents:* incl. read:all (migrations 060/109)
    private const VIEWER = 11;  // documents:read + documents:render, NOT read:all
    private const AUDITOR = 12; // documents:read + documents:read:all
    private const OTHER_TENANT_ADMIN = 20;

    private PDO $pdo;
    private FakeRenderServiceClient $fakeRender;
    private SettingsService $settingsService;
    private EditorTemplateFixture $editor;
    private DocumentRepository $documentRepo;
    private DocumentArtifactRepository $artifactRepo;
    private DocumentQrService $qr;
    private DocumentsApiHandler $handler;
    private string $storageRoot;

    protected function setUp(): void
    {
        RoleChecker::clearCache();
        $this->pdo = $this->makeSchema();
        $db = $this->wrapSqlite($this->pdo);

        $this->storageRoot = sys_get_temp_dir() . '/whity-documents-' . bin2hex(random_bytes(6));
        $this->documentRepo = new DocumentRepository($this->pdo);
        $this->artifactRepo = new DocumentArtifactRepository($this->pdo);
        $this->fakeRender = new FakeRenderServiceClient();
        $this->settingsService = new SettingsService(
            new GlobalSettingsRepository($this->pdo),
            new TenantSettingsRepository($this->pdo)
        );

        $store = new DocumentArtifactStore(new LocalStorageDriver($this->storageRoot));
        $issuer = new DocumentIssuer($this->pdo, $this->documentRepo, $this->artifactRepo, $store);
        $roleChecker = new RoleChecker($db, new PermissionRegistry());
        // #947 item 3 widened the policy with two disjuncts (a route reached
        // you, or a role was granted to you on the document), so it now takes
        // the two repositories that answer them. Required rather than
        // nullable: an unwired policy would silently fall back to the interim
        // rule and hide documents from the people a route was built to reach.
        $visibility = new DocumentVisibilityPolicy(
            new RouteRecipientRepository($this->pdo),
            new ResourceRoleAssignmentRepository($this->pdo, new ResourceTypeRegistry())
        );
        $this->qr = new DocumentQrService(
            $this->pdo,
            new DocumentQrTokenRepository($this->pdo),
            new DocumentQrScanRepository($this->pdo),
            'https://docs.example.test'
        );
        $this->editor = new EditorTemplateFixture(
            $this->pdo,
            $this->settingsService,
            $roleChecker,
            $this->fakeRender,
            $issuer,
            $this->documentRepo,
            $visibility,
            $this->qr,
        );

        // #978 added the organizer's collaborators. They are wired here the way
        // public/index.php wires them rather than stubbed, because this file's
        // assertions include the LIST route and a stub would let the shared
        // wiring drift without any test noticing.
        $substrates = new DocumentSubstrateRegistry(new PdoSchemaPresence($this->pdo));
        CoreDocumentSubstrates::registerInto($substrates);
        $views = new DocumentViewRegistry($substrates);
        CoreDocumentViews::registerInto($views);

        $this->handler = new DocumentsApiHandler(
            $this->documentRepo,
            $this->artifactRepo,
            $store,
            $visibility,
            $this->editor->sources,
            $this->editor->fields,
            $this->editor->issuing,
            $roleChecker,
            $this->settingsService,
            $views,
            $substrates,
            new DocumentCollectionRepository($this->pdo),
            $this->pdo,
            qr: $this->qr,
        );

        // Documents are created the way production creates them — through
        // `POST /api/documents` from a published editor template, with
        // `render: true` — rather than by inserting rows, so these tests
        // exercise the real issue path end to end.
        $this->settingsService->setGlobal(SettingsRegistry::DOCUMENTS_RENDER_ENABLED, 'true');
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
        RoleChecker::clearCache();
        self::removeTree($this->storageRoot);
    }

    // ── immutability ─────────────────────────────────────────────────────────

    /**
     * The keystone assertion of #947 item 1.
     *
     * A correction appends. The first artifact keeps its id, its URL and — the
     * part that actually matters — ITS BYTES, which are compared here against
     * the second artifact's rather than merely counted. A store that overwrote
     * in place would still report two rows; only reading both back catches it.
     */
    public function testReRenderAppendsAnArtifactAndTheSupersededOneStaysFetchable(): void
    {
        $this->fakeRender->pdfBytes = "%PDF-1.4\nfirst issue\n%%EOF";
        $documentId = $this->issue(self::OWNER, 'Invoice');

        $first = $this->artifactRepo->findLatestForDocument($documentId, self::TENANT);
        self::assertNotNull($first);

        $this->fakeRender->pdfBytes = "%PDF-1.4\ncorrected\n%%EOF";
        $res = $this->call('rerender', self::OWNER, ['id' => (string) $documentId]);
        self::assertSame(201, $res->getStatusCode());

        $doc = self::data($res);
        self::assertIsList($doc['artifacts']);
        self::assertCount(2, $doc['artifacts'], 'a re-render must append, never replace');
        // Newest first.
        self::assertSame($first['id'], $doc['artifacts'][1]['id']);
        $secondId = $doc['artifacts'][0]['id'];
        self::assertNotSame($first['id'], $secondId);

        // The superseded artifact still serves the bytes it was issued with.
        $old = $this->call('artifactContent', self::OWNER, ['id' => (string) $documentId, 'artifactId' => (string) $first['id']]);
        self::assertSame(200, $old->getStatusCode());
        self::assertSame("%PDF-1.4\nfirst issue\n%%EOF", $old->getBody());

        // And the new one serves the correction.
        $new = $this->call('artifactContent', self::OWNER, ['id' => (string) $documentId, 'artifactId' => (string) $secondId]);
        self::assertSame("%PDF-1.4\ncorrected\n%%EOF", $new->getBody());

        // `/content` follows the head of the history.
        $current = $this->call('content', self::OWNER, ['id' => (string) $documentId]);
        self::assertSame("%PDF-1.4\ncorrected\n%%EOF", $current->getBody());
    }

    /**
     * The two artifacts occupy two distinct objects on the backend. If a key
     * were derived from the document id alone, the second write would land on
     * the first's address and this would find one file.
     */
    public function testEachArtifactOccupiesItsOwnStorageObject(): void
    {
        $documentId = $this->issue(self::OWNER, 'Invoice');
        $this->fakeRender->pdfBytes = "%PDF-1.4\nsecond\n%%EOF";
        $this->call('rerender', self::OWNER, ['id' => (string) $documentId]);

        $stmt = $this->pdo->prepare('SELECT storage_key FROM document_artifacts');
        $stmt->execute();
        $keys = $stmt->fetchAll(PDO::FETCH_COLUMN);
        self::assertCount(2, $keys);
        self::assertCount(2, array_unique($keys), 'two artifacts must never share a storage key');

        $dir = $this->storageRoot . '/tenants/' . self::TENANT . '/documents/' . $documentId;
        $files = array_values(array_diff(scandir($dir) ?: [], ['.', '..']));
        self::assertCount(2, $files, 'the earlier object must still be on the backend');
    }

    /** The checksum recorded at issue time matches the bytes served later. */
    public function testStoredChecksumMatchesTheBytesServed(): void
    {
        $this->fakeRender->pdfBytes = "%PDF-1.4\nchecksum me\n%%EOF";
        $documentId = $this->issue(self::OWNER, 'Invoice');

        $artifact = $this->artifactRepo->findLatestForDocument($documentId, self::TENANT);
        self::assertNotNull($artifact);

        $res = $this->call('content', self::OWNER, ['id' => (string) $documentId]);
        self::assertSame($artifact['checksum_sha256'], hash('sha256', $res->getBody()));
        self::assertSame('"' . $artifact['checksum_sha256'] . '"', $res->getHeaders()['etag'] ?? null);
    }

    // ── tenant isolation ─────────────────────────────────────────────────────

    public function testAnotherTenantCanNeitherSeeReadNorReRenderTheDocument(): void
    {
        $documentId = $this->issue(self::OWNER, 'Invoice');
        $artifact = $this->artifactRepo->findLatestForDocument($documentId, self::TENANT);
        self::assertNotNull($artifact);

        foreach (
            [
                'show'    => ['id' => (string) $documentId],
                'content' => ['id' => (string) $documentId],
                'artifactContent' => ['id' => (string) $documentId, 'artifactId' => (string) $artifact['id']],
                'rerender' => ['id' => (string) $documentId],
            ] as $method => $params
        ) {
            $res = $this->callAs($method, self::OTHER_TENANT_ADMIN, self::OTHER_TENANT, $params);
            self::assertSame(404, $res->getStatusCode(), "{$method} must 404 across tenants");
        }

        $list = $this->callAs('list', self::OTHER_TENANT_ADMIN, self::OTHER_TENANT, []);
        self::assertSame([], self::decode($list)['data']);
    }

    /**
     * The artifact lookup binds the document as well as the tenant, so an id
     * belonging to another document in the SAME tenant is not reachable through
     * the wrong parent.
     */
    public function testAnArtifactCannotBeFetchedThroughAnotherDocument(): void
    {
        $first = $this->issue(self::OWNER, 'Invoice A');
        $second = $this->issue(self::OWNER, 'Invoice B');

        $firstArtifact = $this->artifactRepo->findLatestForDocument($first, self::TENANT);
        self::assertNotNull($firstArtifact);

        $res = $this->call('artifactContent', self::OWNER, [
            'id' => (string) $second,
            'artifactId' => (string) $firstArtifact['id'],
        ]);

        self::assertSame(404, $res->getStatusCode());
    }

    // ── visibility ───────────────────────────────────────────────────────────

    public function testAColleagueWithoutReadAllSeesNothingOfADocumentTheyDidNotRaise(): void
    {
        $documentId = $this->issue(self::OWNER, 'Invoice');

        $show = $this->call('show', self::VIEWER, ['id' => (string) $documentId]);
        self::assertSame(404, $show->getStatusCode(), 'never a 403 — that would confirm the id exists');

        $content = $this->call('content', self::VIEWER, ['id' => (string) $documentId]);
        self::assertSame(404, $content->getStatusCode());
    }

    public function testReadAllHolderSeesEveryonesDocuments(): void
    {
        $documentId = $this->issue(self::OWNER, 'Invoice');

        $show = $this->call('show', self::AUDITOR, ['id' => (string) $documentId]);
        self::assertSame(200, $show->getStatusCode());
        self::assertSame('Invoice', self::data($show)['title']);
    }

    public function testCallerSeesTheirOwnDocumentWithoutReadAll(): void
    {
        $documentId = $this->issue(self::VIEWER, 'Mine');

        $show = $this->call('show', self::VIEWER, ['id' => (string) $documentId]);
        self::assertSame(200, $show->getStatusCode());
    }

    /**
     * The list filter is a SQL predicate, so the pagination total describes the
     * same set the page does. A post-filter over a fetched page would report
     * the tenant's total here and hand back two rows, which is a page count the
     * caller can never reach.
     */
    public function testListAndItsTotalAreBothFilteredToWhatTheCallerMaySee(): void
    {
        $this->issue(self::OWNER, 'Theirs 1');
        $this->issue(self::OWNER, 'Theirs 2');
        $this->issue(self::VIEWER, 'Mine');

        $mine = self::decode($this->call('list', self::VIEWER, []));
        self::assertCount(1, $mine['data']);
        self::assertSame('Mine', $mine['data'][0]['title']);
        self::assertSame(1, $mine['pagination']['total']);
        self::assertSame(1, $mine['pagination']['totalPages']);

        $all = self::decode($this->call('list', self::AUDITOR, []));
        self::assertCount(3, $all['data']);
        self::assertSame(3, $all['pagination']['total']);
        // Newest first.
        self::assertSame('Mine', $all['data'][0]['title']);
    }

    // ── the wire shape ───────────────────────────────────────────────────────

    public function testStorageKeyIsNeverExposedOnAnyReadRoute(): void
    {
        $documentId = $this->issue(self::OWNER, 'Invoice');

        foreach (['list' => [], 'show' => ['id' => (string) $documentId]] as $method => $params) {
            $body = $this->call($method, self::OWNER, $params)->getBody();
            self::assertStringNotContainsString('storage_key', $body, "{$method} must not leak the storage key");
            self::assertStringNotContainsString('tenants/1/documents', $body, "{$method} must not leak a backend address");
        }
    }

    public function testShowCarriesTheDurableReferences(): void
    {
        $documentId = $this->issue(self::OWNER, 'Invoice');

        $doc = self::data($this->call('show', self::OWNER, ['id' => (string) $documentId]));

        // "Durable" means a client can actually fetch them, so both carry the
        // '/v1' the router serves these routes at. Both expectations here used
        // to be unversioned and both passed throughout the entire period the
        // viewer showed an error box for every document (#1016): asserting the
        // presenter's output against a second copy of the presenter's own idea
        // of the path can only ever agree with it. What makes a reference
        // durable is that it RESOLVES — see Tests\Core\Document\DocumentContentUrlTest.
        self::assertSame("/api/v1/documents/{$documentId}/content", $doc['content_url']);
        self::assertSame(
            "/api/v1/documents/{$documentId}/artifacts/{$doc['artifacts'][0]['id']}/content",
            $doc['artifacts'][0]['content_url']
        );
    }

    /**
     * The origin unit is captured at issue time from the raiser's primary
     * membership — the fact #947 item 5's "raised by my unit" folder is a
     * subtree query over.
     */
    public function testOriginUnitIsCapturedFromTheRaisersPrimaryMembership(): void
    {
        $documentId = $this->issue(self::OWNER, 'Invoice');

        $doc = $this->documentRepo->findById($documentId, self::TENANT);
        self::assertNotNull($doc);
        self::assertSame(7, $doc['origin_ou_id'], "the raiser's unit, not the reader's");

        // A raiser with no unit records none rather than guessing one.
        $unaffiliated = $this->issue(self::VIEWER, 'No unit');
        $other = $this->documentRepo->findById($unaffiliated, self::TENANT);
        self::assertNotNull($other);
        self::assertNull($other['origin_ou_id']);
    }

    // ── what a correction prints ─────────────────────────────────────────────

    /**
     * A correction re-renders the VERSION the document was issued from, with
     * the VALUES it was issued with — not the template's newest version, and
     * not whatever the template's fields would say today.
     *
     * The template is republished with different wording between the issue
     * and the correction, so a correction that followed the template's
     * published pointer instead of the document's own version goes red, and the
     * own field is typed only at issue time, so a correction that lost the
     * stored values prints a blank where `INV-2026-0042` belongs.
     */
    public function testReRenderPrintsTheSameVersionWithTheStoredValues(): void
    {
        $templateId = $this->editor->published(self::TENANT, self::OWNER, 'Invoice', [
            $this->editor->para('Invoice ', $this->editor->field('template.number')),
        ], ['own_fields' => [['key' => 'number', 'label' => 'Number']]]);
        $documentId = $this->issueFrom(self::OWNER, $templateId, 'Invoice', ['template.number' => 'INV-2026-0042']);

        $this->editor->redraft(self::TENANT, $templateId, ['blocks' => [
            $this->editor->para('Pro forma ', $this->editor->field('template.number')),
        ]], self::OWNER);
        $this->editor->publish(self::TENANT, $templateId, self::OWNER);

        $res = $this->call('rerender', self::OWNER, ['id' => (string) $documentId]);
        self::assertSame(201, $res->getStatusCode(), $res->getBody());

        self::assertCount(2, $this->fakeRender->flowCalls, 'one render to issue, one to correct');
        $texts = EditorTemplateFixture::texts($this->fakeRender->flowCalls[1]);
        self::assertContains('Invoice INV-2026-0042', $texts, 'the version it was issued from, with the values it was issued with');
        self::assertNotContains('Pro forma INV-2026-0042', $texts, 'never the template\'s newer version');

        $doc = self::data($res);
        self::assertSame(1, $doc['document_source_version'], 'and the record still says so');
    }

    /**
     * A correction carries the SAME verification code as the original: paper
     * already in circulation stays valid, and the record does not grow a second
     * code a reader would have to choose between.
     */
    public function testReRenderCarriesTheSameVerificationCode(): void
    {
        $this->settingsService->setTenant(self::TENANT, SettingsRegistry::DOCUMENTS_QR_ENABLED, 'true');
        $documentId = $this->issue(self::OWNER, 'Certificate');

        $this->call('rerender', self::OWNER, ['id' => (string) $documentId]);

        self::assertCount(2, $this->fakeRender->flowCalls);
        $first = EditorTemplateFixture::qrBlocks($this->fakeRender->flowCalls[0]);
        $second = EditorTemplateFixture::qrBlocks($this->fakeRender->flowCalls[1]);
        self::assertCount(1, $first);
        self::assertSame($first, $second, 'the same URL and the same printed reference');
        self::assertCount(1, $this->qr->history(self::TENANT, $documentId), 'no second code was minted');
    }

    // ── re-render refusals ───────────────────────────────────────────────────

    /**
     * Once the template is deleted there is nothing to re-render from, and the
     * answer is a 409 rather than a guess. What was already issued stands —
     * the whole point of storing the output instead of re-deriving it.
     */
    public function testReRenderIsRefusedWith409OnceTheTemplateIsGone(): void
    {
        $templateId = $this->editor->published(self::TENANT, self::OWNER, 'Invoice Template');
        $documentId = $this->issueFrom(self::OWNER, $templateId, 'Invoice');

        $this->editor->sourceRepo->delete($templateId, self::TENANT);

        $res = $this->call('rerender', self::OWNER, ['id' => (string) $documentId]);
        self::assertSame(409, $res->getStatusCode());
        self::assertCount(1, $this->artifactRepo->listForDocument($documentId, self::TENANT), 'nothing appended');

        $content = $this->call('content', self::OWNER, ['id' => (string) $documentId]);
        self::assertSame(200, $content->getStatusCode());

        $doc = $this->documentRepo->findById($documentId, self::TENANT);
        self::assertNotNull($doc);
        self::assertSame('Invoice Template', $doc['template_name'], 'the snapshot keeps the record legible');
    }

    /**
     * A template the caller can no longer SEE is as gone as a deleted one: a
     * correction must not be a way to print from a template its audience was
     * narrowed away from.
     */
    public function testReRenderIsRefusedWith409WhenTheTemplateIsHiddenFromTheCaller(): void
    {
        $templateId = $this->editor->published(self::TENANT, self::OWNER, 'Invoice Template');
        $documentId = $this->issueFrom(self::AUDITOR, $templateId, 'Invoice');

        $this->editor->sourceRepo->updateAudience($templateId, self::TENANT, ['scope' => 'personal']);

        self::assertSame(409, $this->call('rerender', self::AUDITOR, ['id' => (string) $documentId])->getStatusCode());
        self::assertSame(201, $this->call('rerender', self::OWNER, ['id' => (string) $documentId])->getStatusCode(), 'its author still can');
    }

    /**
     * A document from the OLD Document designer cannot be re-rendered: its
     * template was a canvas the new renderer does not print. The 409 names the
     * editor template it was converted to (`details.document_source_id`), so
     * the client can offer "issue a new one from it" instead of a dead end.
     */
    public function testAnOldDesignerDocumentIsRefusedWithItsConvertedTemplate(): void
    {
        $converted = $this->editor->published(self::TENANT, self::OWNER, 'Faculty circular');
        // Converted by migration 165: the template is known, the version never was.
        $documentId = $this->documentRepo->create(self::TENANT, [
            'document_source_id' => $converted,
            'template_name' => 'Faculty circular',
            'title' => 'Circular 7/2025',
            'created_by' => self::OWNER,
            'variable_data' => [['reference' => 'FAC-2025-007']],
        ]);

        $res = $this->call('rerender', self::OWNER, ['id' => (string) $documentId]);

        self::assertSame(409, $res->getStatusCode());
        self::assertSame($converted, self::decode($res)['details']['document_source_id']);
        self::assertSame([], $this->fakeRender->calls);
    }

    /**
     * The same refusal for a designer document whose template was never
     * converted (only `document_template_id` survives): still a 409, and the
     * detail says there is no converted template rather than inventing one.
     */
    public function testAnUnconvertedDesignerDocumentIsRefusedWithNoTemplateNamed(): void
    {
        $documentId = $this->documentRepo->create(self::TENANT, [
            'template_name' => 'Old circular',
            'title' => 'Circular 3/2024',
            'created_by' => self::OWNER,
        ]);
        $this->pdo->prepare('UPDATE documents SET document_template_id = ? WHERE id = ?')->execute([41, $documentId]);

        $res = $this->call('rerender', self::OWNER, ['id' => (string) $documentId]);

        self::assertSame(409, $res->getStatusCode());
        self::assertArrayHasKey('document_source_id', self::decode($res)['details']);
        self::assertNull(self::decode($res)['details']['document_source_id']);
    }

    /**
     * A document raised by a form or a plugin was never issued from a template
     * at all; there is nothing to re-render and it is a 409, not a crash.
     */
    public function testADocumentWithNoTemplateIsRefused(): void
    {
        $documentId = $this->documentRepo->create(self::TENANT, [
            'template_name' => 'Leave request',
            'title' => 'Leave request 12',
            'created_by' => self::OWNER,
        ]);

        self::assertSame(409, $this->call('rerender', self::OWNER, ['id' => (string) $documentId])->getStatusCode());
    }

    public function testReRenderIsRefusedWhenRenderingIsDisabled(): void
    {
        $documentId = $this->issue(self::OWNER, 'Invoice');
        $this->settingsService->setGlobal(SettingsRegistry::DOCUMENTS_RENDER_ENABLED, 'false');

        $res = $this->call('rerender', self::OWNER, ['id' => (string) $documentId]);
        self::assertSame(503, $res->getStatusCode());
        self::assertCount(1, $this->artifactRepo->listForDocument($documentId, self::TENANT));
    }

    public function testReRenderIsRefusedWhenPersistenceIsDisabled(): void
    {
        $documentId = $this->issue(self::OWNER, 'Invoice');
        $this->settingsService->setGlobal(SettingsRegistry::DOCUMENTS_PERSIST_ENABLED, 'false');

        $res = $this->call('rerender', self::OWNER, ['id' => (string) $documentId]);
        self::assertSame(503, $res->getStatusCode());
        self::assertCount(1, $this->artifactRepo->listForDocument($documentId, self::TENANT));
    }

    /**
     * A render the service refuses is a 422 and appends nothing: the document
     * cannot be printed with those values, and retrying will not change that.
     */
    public function testAReRenderTheServiceRefusesIs422AndAppendsNothing(): void
    {
        $documentId = $this->issue(self::OWNER, 'Invoice');
        $this->fakeRender->rejectWith = 'The page is too small for this content';

        $res = $this->call('rerender', self::OWNER, ['id' => (string) $documentId]);

        self::assertSame(422, $res->getStatusCode());
        self::assertCount(1, $this->artifactRepo->listForDocument($documentId, self::TENANT));
    }

    /** An outage is a 503 — retry later — and appends nothing either. */
    public function testAReRenderDuringAnOutageIs503AndAppendsNothing(): void
    {
        $documentId = $this->issue(self::OWNER, 'Invoice');
        $this->fakeRender->throwOnRender = true;

        $res = $this->call('rerender', self::OWNER, ['id' => (string) $documentId]);

        self::assertSame(503, $res->getStatusCode());
        self::assertCount(1, $this->artifactRepo->listForDocument($documentId, self::TENANT));
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** Issue a document the way production does, from its own template. */
    private function issue(int $actorId, string $title): int
    {
        // Tenant-wide so a second profile can legitimately raise from it; the
        // DOCUMENT's visibility is what these tests are about, and a personal
        // template would hide it behind the template policy first.
        return $this->issueFrom($actorId, $this->editor->published(self::TENANT, $actorId, $title . ' Template'), $title);
    }

    /**
     * `POST /api/documents` with `render: true`, so the document is issued
     * WITH its first artifact.
     *
     * @param array<string, string> $values The template's own fields.
     */
    private function issueFrom(int $actorId, int $templateId, string $title, array $values = []): int
    {
        TenantContext::reset();
        TenantContext::setTenantId(self::TENANT);
        $body = ['document_source_id' => $templateId, 'title' => $title, 'render' => true];
        if ($values !== []) {
            $body['values'] = $values;
        }
        $req = new Request('POST', '/api/documents', [], (string) json_encode($body));
        $req->user = (object) ['profile_id' => $actorId, 'active_tenant_id' => self::TENANT];

        $res = $this->handler->create($req);
        self::assertSame(201, $res->getStatusCode(), 'fixture setup: issuing must succeed: ' . $res->getBody());
        self::assertTrue(self::decode($res)['render']['stored'], 'fixture setup: the first artifact must be stored');

        return (int) self::data($res)['id'];
    }

    /**
     * @param array<string, string> $params
     */
    private function call(string $method, int $actorId, array $params): Response
    {
        return $this->callAs($method, $actorId, self::TENANT, $params);
    }

    /**
     * @param array<string, string> $params
     */
    private function callAs(string $method, int $actorId, int $tenantId, array $params): Response
    {
        TenantContext::reset();
        TenantContext::setTenantId($tenantId);
        $req = new Request('POST', '/api/documents', [], '');
        $req->user = (object) ['profile_id' => $actorId, 'active_tenant_id' => $tenantId];

        // Spelled out rather than dispatched dynamically: a typo in a route name
        // would otherwise surface as a fatal deep inside a loop instead of here.
        return match ($method) {
            'list'            => $this->handler->list($req),
            'show'            => $this->handler->show($req, $params),
            'content'         => $this->handler->content($req, $params),
            'artifactContent' => $this->handler->artifactContent($req, $params),
            'rerender'        => $this->handler->rerender($req, $params),
            default           => throw new \InvalidArgumentException("Unknown route method: {$method}"),
        };
    }

    /**
     * The decoded JSON body of a response, as an array PHPStan can reason about.
     *
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
     * The `data` element of a single-item response.
     *
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
        $pdo->exec("INSERT INTO organizational_units (id, tenant_id, name, slug, created_at)
                    VALUES (7, 1, 'Registry', 'registry', datetime('now'))");

        // admin role (1) is seeded and granted documents:* by migrations 060/109.
        $pdo->exec("INSERT OR IGNORE INTO roles (id, name, description, tenant_id, created_at) VALUES (1, 'admin', '', NULL, datetime('now'))");
        $pdo->exec("INSERT INTO roles (id, name, description, tenant_id, created_at) VALUES
            (101, 'viewer', '', 1, datetime('now')),
            (102, 'auditor', '', 1, datetime('now'))");

        foreach ([CorePermissions::DOCUMENTS_READ, CorePermissions::DOCUMENTS_RENDER] as $permission) {
            $this->grant($pdo, 101, $permission);
            $this->grant($pdo, 102, $permission);
        }
        $this->grant($pdo, 102, CorePermissions::DOCUMENTS_READ_ALL);

        $pdo->exec("
            INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at) VALUES
                (10, 'owner',   'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
                (11, 'viewer',  'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
                (12, 'auditor', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
                (20, 'other-admin', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ");
        // Only the owner belongs to a unit — so the origin-OU test can prove
        // both that it is captured and that its absence is recorded as absence.
        $pdo->exec("
            INSERT INTO memberships (id, profile_id, tenant_id, role_id, ou_id, is_primary, status, created_at) VALUES
                (1000, 10, 1, 1,   7,    true,  'active', datetime('now')),
                (1001, 11, 1, 101, NULL, true,  'active', datetime('now')),
                (1002, 12, 1, 102, NULL, true,  'active', datetime('now')),
                (1003, 20, 2, 1,   NULL, true,  'active', datetime('now'))
        ");

        return $pdo;
    }

    private function grant(PDO $pdo, int $roleId, string $permission): void
    {
        $pdo->prepare('INSERT OR IGNORE INTO permissions (name, description, created_at) VALUES (?, ?, NOW())')
            ->execute([$permission, '']);
        $sel = $pdo->prepare('SELECT id FROM permissions WHERE name = ?');
        $sel->execute([$permission]);
        $pid = (int) $sel->fetchColumn();
        $pdo->prepare('INSERT OR IGNORE INTO role_permissions (role_id, permission_id, created_at) VALUES (?, ?, NOW())')
            ->execute([$roleId, $pid]);
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
