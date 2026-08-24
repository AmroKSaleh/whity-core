<?php

declare(strict_types=1);

namespace Tests\Api;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeRenderServiceClient;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\DocumentsApiHandler;
use Whity\Auth\RoleChecker;
use Whity\Core\Document\DocumentAccessPolicy;
use Whity\Core\Document\DocumentArtifactRepository;
use Whity\Core\Document\DocumentArtifactStore;
use Whity\Core\Document\DocumentBlockRepository;
use Whity\Core\Document\DocumentCollectionRepository;
use Whity\Core\Document\DocumentIssuer;
use Whity\Core\Document\DocumentRepository;
use Whity\Core\Document\DocumentTemplateRepository;
use Whity\Core\Document\DocumentVisibilityPolicy;
use Whity\Core\Document\Organizer\CoreDocumentSubstrates;
use Whity\Core\Document\Organizer\CoreDocumentViews;
use Whity\Core\Document\Organizer\DocumentSubstrateRegistry;
use Whity\Core\Document\Organizer\DocumentViewRegistry;
use Whity\Core\Document\Organizer\PdoSchemaPresence;
use Whity\Core\Document\Render\DocumentRenderer;
use Whity\Core\Document\Routing\RouteRecipientRepository;
use Whity\Core\Ou\OuReachResolver;
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
 * `POST /api/documents` — the create path (#947 item 1, the half that was
 * missing).
 *
 * WHAT THIS FILE IS FOR, AND WHAT IT REFUSES TO ASSERT
 * ---------------------------------------------------
 * The easy version of this file asserts 201s. A 201 is worth almost nothing
 * here: the route answers 201 on an instance with no render tier, so a create
 * that stored nothing at all and a create that worked perfectly look identical
 * from the status line. So every test below reads back the thing that would
 * actually be wrong if the feature broke — the row, the artifact count, or the
 * PAYLOAD THE RENDER SERVICE WAS HANDED.
 *
 * The keystone is {@see testValuesRaisedWithSurviveAndAreWhatARenderLaterUses}.
 * It is the one that fails if the variable-data column, the create path's
 * persistence of it, or the re-render's fallback order regresses — and the
 * failure it guards against is silent by construction: without the column, a
 * correction reissues the document reading `Ref: DEMO-0001` (the template's
 * sample) instead of the real reference, the request succeeds, a new artifact
 * appears, and nothing anywhere errors. Counting artifacts would pass. Only
 * inspecting the values that reached the renderer catches it.
 */
final class DocumentCreateApiRealEngineTest extends TestCase
{
    private const TENANT = 1;
    private const OTHER_TENANT = 2;

    /** admin in tenant 1: documents:* including render, IN a unit (7). */
    private const RAISER = 10;
    /**
     * documents:read + documents:write, a member of unit 7, and NO
     * documents:render. The route gate (middleware) is what refuses them the
     * create, so this file cannot test that half — it drives the handler
     * directly, below the gate. What it CAN test, and does, is the other thing
     * this profile is here for: standing at a unit, which is the positive half
     * of OU reach.
     */
    private const SECRETARY = 11;
    /** documents:read + documents:render, in NO organizational unit at all. */
    private const REGISTRY_OFFICER = 12;
    private const OTHER_TENANT_ADMIN = 20;

    private const UNIT = 7;

    private PDO $pdo;
    private FakeRenderServiceClient $fakeRender;
    private SettingsService $settings;
    private DocumentTemplateRepository $templates;
    private DocumentRepository $documents;
    private DocumentArtifactRepository $artifacts;
    private DocumentsApiHandler $handler;
    private string $storageRoot;

    protected function setUp(): void
    {
        RoleChecker::clearCache();
        $this->pdo = $this->makeSchema();
        $db = $this->wrapSqlite($this->pdo);

        $this->storageRoot = sys_get_temp_dir() . '/whity-doc-create-' . bin2hex(random_bytes(6));
        $this->templates = new DocumentTemplateRepository($this->pdo);
        $this->documents = new DocumentRepository($this->pdo);
        $this->artifacts = new DocumentArtifactRepository($this->pdo);
        $this->fakeRender = new FakeRenderServiceClient();
        $this->settings = new SettingsService(
            new GlobalSettingsRepository($this->pdo),
            new TenantSettingsRepository($this->pdo)
        );

        // A REAL storage driver over a throwaway directory, not a fake: the
        // assertions about whether an artifact exists are about what is on the
        // backend, and a fake store would let a write that never happened pass.
        $store = new DocumentArtifactStore(new LocalStorageDriver($this->storageRoot));
        $renderer = new DocumentRenderer(
            new DocumentBlockRepository($this->pdo),
            $this->settings,
            $this->fakeRender
        );
        $roleChecker = new RoleChecker($db, new PermissionRegistry());
        $reach = new OuReachResolver($this->pdo, new ResourceRoleAssignmentRepository($this->pdo, new ResourceTypeRegistry()));

        $substrates = new DocumentSubstrateRegistry(new PdoSchemaPresence($this->pdo));
        CoreDocumentSubstrates::registerInto($substrates);
        $views = new DocumentViewRegistry($substrates);
        CoreDocumentViews::registerInto($views);

        $this->handler = new DocumentsApiHandler(
            $this->documents,
            $this->artifacts,
            $store,
            new DocumentVisibilityPolicy(
                new RouteRecipientRepository($this->pdo),
                new ResourceRoleAssignmentRepository($this->pdo, new ResourceTypeRegistry())
            ),
            $this->templates,
            new DocumentAccessPolicy(),
            $renderer,
            new DocumentIssuer($this->pdo, $this->documents, $this->artifacts, $store),
            $roleChecker,
            $this->settings,
            $views,
            $substrates,
            new DocumentCollectionRepository($this->pdo),
            $this->pdo,
            $reach,
        );

        // DELIBERATELY NOT TOUCHED HERE. `documents.render_enabled` defaults to
        // false and the default is the case most installs are in, so it is the
        // case these tests start from — the sibling file
        // (DocumentsApiHandlerRealEngineTest) turns it on in setUp() because
        // everything it tests needs an artifact to exist. Tests below that need
        // the render tier switch it on themselves and say so.
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
        RoleChecker::clearCache();
        self::removeTree($this->storageRoot);
    }

    // ── the keystone ─────────────────────────────────────────────────────────

    /**
     * The values a person typed survive the create, and are what a LATER render
     * of the same document uses.
     *
     * Both halves matter and the second is the one that used to be broken. A
     * document is raised on an instance with no render tier (the default), the
     * operator later turns rendering on, and somebody renders the document. The
     * bytes must carry `FAC-2026-014`, which nothing but the row remembers —
     * before this feature the re-render fell back to the template's placeholder
     * SAMPLE and produced `DEMO-0001`, successfully, with a fresh artifact and
     * no error anywhere.
     */
    public function testValuesRaisedWithSurviveAndAreWhatARenderLaterUses(): void
    {
        $templateId = $this->createTemplate(self::RAISER, 'Faculty circular');

        $created = $this->create(self::RAISER, [
            'document_template_id' => $templateId,
            'title' => 'Semester circular 2026/1',
            'dataRows' => [['reference' => 'FAC-2026-014', 'date' => '2026-08-24']],
        ]);
        self::assertSame(201, $created->getStatusCode());
        $documentId = (int) self::data($created)['id'];

        // Nothing was rendered, because nothing could be.
        self::assertSame([], $this->fakeRender->calls, 'the render service must not be called when the tier is off');
        self::assertSame([], $this->artifacts->listForDocument($documentId, self::TENANT));
        self::assertSame(
            ['attempted' => false, 'stored' => false, 'reason' => 'disabled'],
            self::decode($created)['render']
        );

        // The row remembers what it was raised with.
        $row = $this->documents->findById($documentId, self::TENANT);
        self::assertNotNull($row);
        self::assertSame([['reference' => 'FAC-2026-014', 'date' => '2026-08-24']], $row['variable_data']);

        // Weeks later the operator switches the render tier on and the document
        // is rendered with NO dataRows in the request.
        $this->settings->setGlobal(SettingsRegistry::DOCUMENTS_RENDER_ENABLED, 'true');
        $rendered = $this->call('rerender', self::RAISER, ['id' => (string) $documentId]);
        self::assertSame(201, $rendered->getStatusCode());

        self::assertCount(1, $this->fakeRender->calls);
        self::assertSame(
            [['reference' => 'FAC-2026-014', 'date' => '2026-08-24']],
            $this->renderedRows(),
            'a render with no supplied values must use the ones the document was RAISED with, '
            . 'never the template\'s samples'
        );
    }

    /**
     * A request that supplies its own values still wins over the stored ones —
     * correcting the values is exactly what that field is for — and the stored
     * ones are NOT rewritten by it.
     *
     * The row records what the document was raised with, in the same way
     * `template_name`, `origin_ou_id` and `created_at` do. If a correction
     * overwrote it, the row and the artifact history would be able to disagree
     * with nothing recording that they had.
     */
    public function testAnExplicitReRenderWinsWithoutRewritingWhatWasRaised(): void
    {
        $this->settings->setGlobal(SettingsRegistry::DOCUMENTS_RENDER_ENABLED, 'true');
        $templateId = $this->createTemplate(self::RAISER, 'Faculty circular');

        $created = $this->create(self::RAISER, [
            'document_template_id' => $templateId,
            'dataRows' => [['reference' => 'FAC-001', 'date' => '2026-01-01']],
        ]);
        $documentId = (int) self::data($created)['id'];

        $this->call('rerender', self::RAISER, ['id' => (string) $documentId], [
            'dataRows' => [['reference' => 'FAC-001-CORRECTED', 'date' => '2026-01-02']],
        ]);

        self::assertSame(
            [['reference' => 'FAC-001-CORRECTED', 'date' => '2026-01-02']],
            $this->renderedRows(count($this->fakeRender->calls) - 1)
        );

        $row = $this->documents->findById($documentId, self::TENANT);
        self::assertNotNull($row);
        self::assertSame(
            [['reference' => 'FAC-001', 'date' => '2026-01-01']],
            $row['variable_data'],
            'the values the document was RAISED with are provenance and are never rewritten'
        );
    }

    /**
     * Absent `dataRows` records the template's own samples rather than nothing,
     * so a client that offered no form still produces a document whose content
     * is defined and whose later render is reproducible.
     */
    public function testOmittingValuesRecordsTheTemplatesSamples(): void
    {
        $templateId = $this->createTemplate(self::RAISER, 'Faculty circular');

        $created = $this->create(self::RAISER, ['document_template_id' => $templateId]);
        $row = $this->documents->findById((int) self::data($created)['id'], self::TENANT);

        self::assertNotNull($row);
        self::assertSame([['reference' => 'DEMO-0001', 'date' => '2026-01-15']], $row['variable_data']);
    }

    // ── template scoping ─────────────────────────────────────────────────────

    /**
     * A template the caller cannot SEE cannot be raised from — and the refusal
     * is a 404, so the route does not confirm the template exists.
     *
     * The template here is filed at unit 7 and tagged with a permission the
     * secretary does not hold, which is the shape migration 117 exists for. It
     * is checked with the DESIGNER's policy, not the document one: creating from
     * a gated template must not be a way to read a gated template.
     */
    public function testATemplateTheCallerCannotSeeCannotBeRaisedFrom(): void
    {
        $templateId = $this->templates->create(self::TENANT, [
            'name' => 'Contract template',
            'data' => $this->templateData('Contract'),
            'scope' => 'tenant',
            'required_permission' => CorePermissions::DOCUMENTS_PUBLISH,
            'owner_ou_id' => self::UNIT,
            'created_by' => self::RAISER,
        ]);

        // The registry officer holds documents:render (so the ROUTE gate passes)
        // but neither documents:publish nor any standing at unit 7.
        $res = $this->create(self::REGISTRY_OFFICER, ['document_template_id' => $templateId]);

        self::assertSame(404, $res->getStatusCode(), 'never a 403 — that would confirm the template exists');
        self::assertSame(0, $this->countDocuments(), 'nothing may be written for a template the caller cannot see');
    }

    /**
     * The same placement, reached from INSIDE the unit, succeeds.
     *
     * The negative test above is only half an assertion: a policy that refused
     * EVERYTHING would pass it. This is the half that proves reach is being
     * consulted rather than placement simply hiding a row from everyone — same
     * template, same placement, a caller who stands at that unit, and the answer
     * flips.
     *
     * The two callers also differ ONLY in where they stand: both hold
     * documents:read, neither holds documents:publish, and the template carries
     * no permission tag. So reach is the only variable, which is exactly the
     * distinction migration 117 exists to make (*"a secretary for a dean might
     * have access to templates ... more than a secretary of a department head"*).
     */
    public function testTheSamePlacedTemplateIsRaisableFromInsideTheUnit(): void
    {
        $templateId = $this->templates->create(self::TENANT, [
            'name' => 'Faculty-only circular',
            'data' => $this->templateData('Faculty-only circular'),
            'scope' => 'tenant',
            'owner_ou_id' => self::UNIT,
            // Created by the RAISER, so the author-always-reaches-their-own-row
            // shortcut cannot be what makes this pass for the secretary.
            'created_by' => self::RAISER,
        ]);

        self::assertSame(
            404,
            $this->create(self::REGISTRY_OFFICER, ['document_template_id' => $templateId])->getStatusCode(),
            'a caller standing nowhere does not reach a placed template'
        );
        self::assertSame(
            201,
            $this->create(self::SECRETARY, ['document_template_id' => $templateId])->getStatusCode(),
            'a caller standing AT the unit does'
        );
    }

    /** A template in another tenant is not reachable by id. */
    public function testATemplateInAnotherTenantIsNotReachable(): void
    {
        $templateId = $this->templates->create(self::OTHER_TENANT, [
            'name' => 'Their template',
            'data' => $this->templateData('Theirs'),
            'scope' => 'tenant',
            'created_by' => self::OTHER_TENANT_ADMIN,
        ]);

        $res = $this->create(self::RAISER, ['document_template_id' => $templateId]);

        self::assertSame(404, $res->getStatusCode());
        self::assertSame(0, $this->countDocuments());
    }

    // ── provenance ───────────────────────────────────────────────────────────

    /**
     * A creator who belongs to NO organizational unit raises a document
     * successfully, with a null origin.
     *
     * This is a real case rather than an error — the demo fixture's registry
     * officer is exactly this person — and the organizer already handles the
     * consequence by rendering unit-anchored folders disabled WITH A REASON
     * rather than hiding them. Refusing the create would be the one response
     * that makes the case unrepresentable.
     */
    public function testACreatorInNoUnitRaisesADocumentWithANullOrigin(): void
    {
        $templateId = $this->createTemplate(self::RAISER, 'Faculty circular');

        $res = $this->create(self::REGISTRY_OFFICER, [
            'document_template_id' => $templateId,
            'title' => 'Registry notice',
        ]);

        self::assertSame(201, $res->getStatusCode());
        $row = $this->documents->findById((int) self::data($res)['id'], self::TENANT);
        self::assertNotNull($row);
        self::assertNull($row['origin_ou_id']);
        self::assertSame(self::REGISTRY_OFFICER, $row['created_by']);
    }

    /** A creator who IS in a unit has it stamped, at create time. */
    public function testACreatorsUnitIsStampedOnTheRecord(): void
    {
        $templateId = $this->createTemplate(self::RAISER, 'Faculty circular');

        $res = $this->create(self::RAISER, ['document_template_id' => $templateId]);

        $row = $this->documents->findById((int) self::data($res)['id'], self::TENANT);
        self::assertNotNull($row);
        self::assertSame(self::UNIT, $row['origin_ou_id']);
    }

    /**
     * `template_name` is a SNAPSHOT, and the whole point of it is that it
     * outlives the template.
     *
     * Migration 108 makes the foreign key ON DELETE SET NULL so retiring a
     * template cannot delete the documents issued from it; that only pays off if
     * the name was copied. Asserted by actually deleting the template and
     * reading the document back — a test that merely compared the name at create
     * time would pass against a join.
     */
    public function testTheTemplateNameIsSnapshottedAndSurvivesTheTemplatesDeletion(): void
    {
        $templateId = $this->createTemplate(self::RAISER, 'Faculty circular');
        $res = $this->create(self::RAISER, ['document_template_id' => $templateId, 'title' => 'Circular 12']);
        $documentId = (int) self::data($res)['id'];

        self::assertSame(1, $this->templates->delete($templateId, self::TENANT));

        $row = $this->documents->findById($documentId, self::TENANT);
        self::assertNotNull($row);
        self::assertNull($row['document_template_id'], 'the pointer is cleared by the foreign key');
        self::assertSame('Faculty circular', $row['template_name'], 'the snapshot is what keeps the record legible');
        self::assertSame('Circular 12', $row['title']);
    }

    /** An untitled document is named after its template rather than left blank. */
    public function testAnUntitledDocumentTakesTheTemplatesName(): void
    {
        $templateId = $this->createTemplate(self::RAISER, 'Faculty circular');

        $res = $this->create(self::RAISER, ['document_template_id' => $templateId, 'title' => '   ']);

        self::assertSame('Faculty circular', self::data($res)['title']);
    }

    // ── the render decision ──────────────────────────────────────────────────

    /**
     * With the tier ON, a create renders and stores the artifact — so the
     * default create is a complete document on an instance that can make one.
     */
    public function testWithRenderingEnabledTheCreateStoresAnArtifact(): void
    {
        $this->settings->setGlobal(SettingsRegistry::DOCUMENTS_RENDER_ENABLED, 'true');
        $this->fakeRender->pdfBytes = "%PDF-1.4\nraised\n%%EOF";
        $templateId = $this->createTemplate(self::RAISER, 'Faculty circular');

        $res = $this->create(self::RAISER, [
            'document_template_id' => $templateId,
            'dataRows' => [['reference' => 'R-1', 'date' => '2026-02-02']],
        ]);

        self::assertSame(201, $res->getStatusCode());
        self::assertSame(
            ['attempted' => true, 'stored' => true, 'reason' => null],
            self::decode($res)['render']
        );

        $documentId = (int) self::data($res)['id'];
        self::assertCount(1, $this->artifacts->listForDocument($documentId, self::TENANT));
        // The BYTES, read back through the route that serves them — the response
        // saying "stored" is the claim, not the evidence.
        $content = $this->call('content', self::RAISER, ['id' => (string) $documentId]);
        self::assertSame("%PDF-1.4\nraised\n%%EOF", $content->getBody());
        // And the values the caller supplied are the ones that were rendered.
        self::assertSame([['reference' => 'R-1', 'date' => '2026-02-02']], $this->renderedRows());
    }

    /**
     * `render: false` keeps the record and renders nothing even on an instance
     * that could — the "raise it now, print it later" case.
     */
    public function testRenderFalseDeclinesTheArtifactOnACapableInstance(): void
    {
        $this->settings->setGlobal(SettingsRegistry::DOCUMENTS_RENDER_ENABLED, 'true');
        $templateId = $this->createTemplate(self::RAISER, 'Faculty circular');

        $res = $this->create(self::RAISER, ['document_template_id' => $templateId, 'render' => false]);

        self::assertSame(201, $res->getStatusCode());
        self::assertSame(
            ['attempted' => false, 'stored' => false, 'reason' => 'declined'],
            self::decode($res)['render']
        );
        self::assertSame([], $this->fakeRender->calls);
        self::assertSame([], $this->artifacts->listForDocument((int) self::data($res)['id'], self::TENANT));
    }

    /**
     * `render: true` on an instance that cannot render is a 503 AND WRITES
     * NOTHING.
     *
     * The second half is the assertion worth having. A caller who declared they
     * need the bytes must not be left owning a record they did not ask for and
     * cannot find — so the refusal happens before anything is inserted, which is
     * only observable by counting rows.
     */
    public function testRequiringARenderOnAnInstanceThatCannotIsRefusedAndWritesNothing(): void
    {
        $templateId = $this->createTemplate(self::RAISER, 'Faculty circular');

        $res = $this->create(self::RAISER, ['document_template_id' => $templateId, 'render' => true]);

        self::assertSame(503, $res->getStatusCode());
        self::assertSame(0, $this->countDocuments());
    }

    /** Persistence off is reported as its own reason, not as "disabled". */
    public function testPersistenceOffIsReportedSeparatelyFromRenderingOff(): void
    {
        $this->settings->setGlobal(SettingsRegistry::DOCUMENTS_RENDER_ENABLED, 'true');
        $this->settings->setGlobal(SettingsRegistry::DOCUMENTS_PERSIST_ENABLED, 'false');
        $templateId = $this->createTemplate(self::RAISER, 'Faculty circular');

        $res = $this->create(self::RAISER, ['document_template_id' => $templateId]);

        self::assertSame(201, $res->getStatusCode(), 'a storage cap is not a reason a document cannot exist');
        self::assertSame('persist_disabled', self::decode($res)['render']['reason']);
        self::assertSame([], $this->artifacts->listForDocument((int) self::data($res)['id'], self::TENANT));
        self::assertSame(1, $this->countDocuments());
    }

    /**
     * A render service that is down does NOT take the document with it.
     *
     * The record is committed first, on purpose: the values a person typed and
     * the id routing needs are worth more than the PDF, and discarding them
     * because an optional container is restarting would lose real work on
     * exactly the deployments where that container is least reliable.
     */
    public function testARenderServiceFailureLeavesTheDocumentStanding(): void
    {
        $this->settings->setGlobal(SettingsRegistry::DOCUMENTS_RENDER_ENABLED, 'true');
        $this->fakeRender->throwOnRender = true;
        $templateId = $this->createTemplate(self::RAISER, 'Faculty circular');

        $res = $this->create(self::RAISER, [
            'document_template_id' => $templateId,
            'dataRows' => [['reference' => 'KEEP-ME', 'date' => '2026-03-03']],
        ]);

        self::assertSame(201, $res->getStatusCode());
        self::assertSame(
            ['attempted' => true, 'stored' => false, 'reason' => 'unavailable'],
            self::decode($res)['render']
        );

        $row = $this->documents->findById((int) self::data($res)['id'], self::TENANT);
        self::assertNotNull($row);
        self::assertSame([['reference' => 'KEEP-ME', 'date' => '2026-03-03']], $row['variable_data']);
        self::assertNull(self::data($res)['content_url'], 'a record with no artifact must not promise bytes');
    }

    // ── validation ───────────────────────────────────────────────────────────

    /**
     * A value for a placeholder the template does not declare is refused, by
     * name, and nothing is written.
     *
     * Accepting it is the failure that is invisible until a recipient reads the
     * document: `{{refrence}}` would be stored, `{{reference}}` would never be
     * substituted, and the finished PDF would carry the literal placeholder text
     * where the reference number belongs.
     */
    public function testAValueForAnUndeclaredPlaceholderIsRefusedByName(): void
    {
        $templateId = $this->createTemplate(self::RAISER, 'Faculty circular');

        $res = $this->create(self::RAISER, [
            'document_template_id' => $templateId,
            'dataRows' => [['reference' => 'R-1', 'refrence' => 'typo']],
        ]);

        self::assertSame(422, $res->getStatusCode());
        self::assertStringContainsString('refrence', self::decode($res)['error'] ?? '');
        self::assertSame(0, $this->countDocuments());
    }

    public function testABadDataRowsShapeIsRefusedAndWritesNothing(): void
    {
        $templateId = $this->createTemplate(self::RAISER, 'Faculty circular');

        $res = $this->create(self::RAISER, ['document_template_id' => $templateId, 'dataRows' => 'not-a-list']);

        self::assertSame(422, $res->getStatusCode());
        self::assertSame(0, $this->countDocuments());
    }

    public function testNamingNoTemplateIsRefusedRatherThanReadAsTemplateZero(): void
    {
        $res = $this->create(self::RAISER, ['title' => 'Orphan']);

        self::assertSame(422, $res->getStatusCode());
        self::assertSame(0, $this->countDocuments());
    }

    /**
     * A numeric STRING id is accepted. Form-driven clients routinely send one,
     * and a 422 whose cause is invisible in the payload the developer is reading
     * is a bad afternoon.
     */
    public function testANumericStringTemplateIdIsAccepted(): void
    {
        $templateId = $this->createTemplate(self::RAISER, 'Faculty circular');

        $res = $this->create(self::RAISER, ['document_template_id' => (string) $templateId]);

        self::assertSame(201, $res->getStatusCode());
    }

    // ── the created document is a first-class one ────────────────────────────

    /**
     * A document created this way is immediately visible through the ordinary
     * read paths — which is the whole point of it having an identity, and the
     * one thing a create route can get wrong while returning a perfect 201.
     */
    public function testTheCreatedDocumentIsImmediatelyListableAndReadable(): void
    {
        $templateId = $this->createTemplate(self::RAISER, 'Faculty circular');
        $res = $this->create(self::RAISER, ['document_template_id' => $templateId, 'title' => 'Findable']);
        $documentId = (int) self::data($res)['id'];

        $show = $this->call('show', self::RAISER, ['id' => (string) $documentId]);
        self::assertSame(200, $show->getStatusCode());
        self::assertSame('Findable', self::data($show)['title']);

        $list = $this->call('list', self::RAISER, []);
        $titles = array_column(self::decode($list)['data'], 'title');
        self::assertContains('Findable', $titles);
    }

    /**
     * The variable data is NOT on the wire, on any route.
     *
     * Stated as an assertion rather than left implicit, because it is a
     * deliberate omission with a live consequence: `DocumentPresenter` is the
     * only thing that would publish it and it does not, so a client cannot yet
     * read back the values a document was raised with. Pinning it here means
     * ADDING it later is a decision somebody makes on purpose, and means this
     * file says out loud what the API does and does not promise.
     */
    public function testTheStoredValuesAreNotPublishedOnTheWire(): void
    {
        $templateId = $this->createTemplate(self::RAISER, 'Faculty circular');
        $res = $this->create(self::RAISER, [
            'document_template_id' => $templateId,
            'dataRows' => [['reference' => 'PRIVATE-1', 'date' => '2026-04-04']],
        ]);

        self::assertArrayNotHasKey('variable_data', self::data($res));
        self::assertStringNotContainsString('PRIVATE-1', $res->getBody());
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $body
     */
    private function create(int $actorId, array $body): Response
    {
        TenantContext::reset();
        TenantContext::setTenantId(self::TENANT);
        $req = new Request('POST', '/api/documents', [], (string) json_encode($body));
        $req->user = (object) ['profile_id' => $actorId, 'active_tenant_id' => self::TENANT];

        return $this->handler->create($req);
    }

    /**
     * @param array<string, string> $params
     * @param array<string, mixed>  $body
     */
    private function call(string $method, int $actorId, array $params, array $body = []): Response
    {
        TenantContext::reset();
        TenantContext::setTenantId(self::TENANT);
        $req = new Request('GET', '/api/documents', [], (string) json_encode($body));
        $req->user = (object) ['profile_id' => $actorId, 'active_tenant_id' => self::TENANT];

        return match ($method) {
            'list'     => $this->handler->list($req),
            'show'     => $this->handler->show($req, $params),
            'content'  => $this->handler->content($req, $params),
            'rerender' => $this->handler->rerender($req, $params),
            default    => throw new \InvalidArgumentException("Unknown route method: {$method}"),
        };
    }

    private function createTemplate(int $actorId, string $name): int
    {
        return $this->templates->create(self::TENANT, [
            'name' => $name,
            'data' => $this->templateData($name),
            // Tenant-wide and unplaced, so template visibility is not the
            // variable under test in the tests that are not about it.
            'scope' => 'tenant',
            'created_by' => $actorId,
        ]);
    }

    /**
     * A template shaped like the demo faculty circular: two placeholders, each
     * with a SAMPLE that is visibly not a real value. The samples are what a
     * regression falls back to, so they have to be distinguishable from anything
     * a test supplies.
     *
     * @return array<string, mixed>
     */
    private function templateData(string $name): array
    {
        return [
            'version' => 2,
            'name' => $name,
            'page' => ['widthMm' => 210, 'heightMm' => 297, 'marginMm' => 10, 'background' => '#ffffff'],
            'placeholders' => [
                ['key' => 'reference', 'label' => 'Reference', 'sample' => 'DEMO-0001'],
                ['key' => 'date', 'label' => 'Date', 'sample' => '2026-01-15'],
            ],
            'pages' => [['id' => 'p1', 'elements' => []]],
        ];
    }

    /**
     * The `dataRows` the render service was actually handed on the Nth call.
     *
     * Read through a helper rather than indexed inline so a MISSING call fails
     * as "the renderer was never asked" instead of as an undefined-offset
     * notice - which is the difference between a test that names the regression
     * and one that merely breaks near it.
     *
     * @return list<array<string, string>>
     */
    private function renderedRows(int $call = 0): array
    {
        self::assertArrayHasKey($call, $this->fakeRender->calls, 'the render service was never called');
        $rows = $this->fakeRender->calls[$call]['dataRows'] ?? null;
        self::assertIsArray($rows);

        /** @var list<array<string, string>> $rows */
        return $rows;
    }

    private function countDocuments(): int
    {
        $stmt = $this->pdo->query('SELECT COUNT(*) FROM documents');
        self::assertNotFalse($stmt);

        return (int) $stmt->fetchColumn();
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
        $pdo->exec("INSERT INTO organizational_units (id, tenant_id, name, slug, created_at)
                    VALUES (7, 1, 'Faculty', 'faculty', datetime('now'))");

        // admin role (1) is seeded and granted documents:* by migrations 060/109.
        $pdo->exec("INSERT OR IGNORE INTO roles (id, name, description, tenant_id, created_at)
                    VALUES (1, 'admin', '', NULL, datetime('now'))");
        $pdo->exec("INSERT INTO roles (id, name, description, tenant_id, created_at) VALUES
            (101, 'secretary', '', 1, datetime('now')),
            (102, 'registry-officer', '', 1, datetime('now'))");

        // Mirrors the demo fixture's own grants (DemoOrganisationSeeder): the
        // secretary may design templates and NOT render, the registry officer may
        // render and belongs to no unit. Those two roles are why this file can
        // test both the create gate and the no-unit creator against grants a real
        // deployment actually has, rather than invented ones.
        foreach ([CorePermissions::DOCUMENTS_READ, CorePermissions::DOCUMENTS_WRITE] as $permission) {
            $this->grant($pdo, 101, $permission);
        }
        $this->grant($pdo, 102, CorePermissions::DOCUMENTS_READ);
        $this->grant($pdo, 102, CorePermissions::DOCUMENTS_RENDER);

        $pdo->exec("
            INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at) VALUES
                (10, 'raiser',    'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
                (11, 'secretary', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
                (12, 'registry',  'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
                (20, 'other',     'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ");
        // The registry officer's membership names NO unit — active and complete,
        // it simply has no place, which is what memberships.ou_id being nullable
        // means and which the demo fixture models on purpose.
        $pdo->exec("
            INSERT INTO memberships (id, profile_id, tenant_id, role_id, ou_id, is_primary, status, created_at) VALUES
                (1000, 10, 1, 1,   7,    true, 'active', datetime('now')),
                (1001, 11, 1, 101, 7,    true, 'active', datetime('now')),
                (1002, 12, 1, 102, NULL, true, 'active', datetime('now')),
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
