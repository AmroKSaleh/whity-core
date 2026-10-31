<?php

declare(strict_types=1);

namespace Tests\Api;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Sdk\Manifest\DocumentSlotValidatorTest;
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
use Whity\Core\Document\Fields\TenantRecordFieldProvider;
use Whity\Core\Document\Slots\SlotBindings;
use Whity\Core\Document\Slots\TemplateSlot;
use Whity\Core\Document\Slots\TemplateSlotRegistry;
use Whity\Core\Plugin\Tenant\TenantRecordRepository;
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
use Whity\Sdk\Hooks\Events;
use Whity\Sdk\Hooks\HookVetoException;
use Whity\Sdk\Http\Response;
use Whity\Sdk\Manifest\PluginManifest;
use Whity\Storage\LocalStorageDriver;

/**
 * `POST /api/documents` — the create path (#947 item 1), issuing from editor
 * templates since editor M4c.
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
 * persistence of it, or the re-render's reading of it regresses — and the
 * failure it guards against is silent by construction: without the column, a
 * correction reissues the document with the reference blank, the request
 * succeeds, a new artifact appears, and nothing anywhere errors. Counting
 * artifacts would pass. Only inspecting the text that reached the renderer
 * catches it.
 *
 * The body is `{document_source_id, record?, values?, title?, render?}`: the
 * template is an editor template, the document is raised from its PUBLISHED
 * version, and the only values a caller types are the template's own fields
 * (`template.<key>`); everything else is read from the record and the shared
 * fields (Company, Today) for the person asking.
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

    /** The template's own fields: what a person types when raising it. */
    private const OWN_FIELDS = [
        ['key' => 'reference', 'label' => 'Reference'],
        ['key' => 'date', 'label' => 'Date'],
    ];

    private PDO $pdo;
    private FakeRenderServiceClient $fakeRender;
    private SettingsService $settings;
    private EditorTemplateFixture $editor;
    private DocumentRepository $documents;
    private DocumentArtifactRepository $artifacts;
    private DocumentQrService $qr;
    private DocumentsApiHandler $handler;
    private TemplateSlotRegistry $slots;
    /** @var \Closure(): DocumentsApiHandler */
    private \Closure $rebuild;
    private string $storageRoot;

    protected function setUp(): void
    {
        RoleChecker::clearCache();
        $this->pdo = $this->makeSchema();
        $db = $this->wrapSqlite($this->pdo);

        $this->storageRoot = sys_get_temp_dir() . '/whity-doc-create-' . bin2hex(random_bytes(6));
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
        $roleChecker = new RoleChecker($db, new PermissionRegistry());
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
            $this->settings,
            $roleChecker,
            $this->fakeRender,
            new DocumentIssuer($this->pdo, $this->documents, $this->artifacts, $store),
            $this->documents,
            $visibility,
            $this->qr,
        );

        $substrates = new DocumentSubstrateRegistry(new PdoSchemaPresence($this->pdo));
        CoreDocumentSubstrates::registerInto($substrates);
        $views = new DocumentViewRegistry($substrates);
        CoreDocumentViews::registerInto($views);

        $this->slots = new TemplateSlotRegistry();
        $this->rebuild = fn (): DocumentsApiHandler => new DocumentsApiHandler(
            $this->documents,
            $this->artifacts,
            $store,
            $visibility,
            $this->editor->sources,
            $this->editor->fields,
            $this->editor->issuing,
            $roleChecker,
            $this->settings,
            $views,
            $substrates,
            new DocumentCollectionRepository($this->pdo),
            $this->pdo,
            qr: $this->qr,
            slots: $this->slots,
        );
        $this->rebuildHandler();

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
     * Both halves matter and the second is the one that breaks silently. A
     * document is raised on an instance with no render tier (the default), the
     * operator later turns rendering on, and somebody renders the document. The
     * PDF must carry `FAC-2026-014`, which nothing but the row remembers — a
     * re-render that lost it would print the reference blank, successfully,
     * with a fresh artifact and no error anywhere.
     */
    public function testValuesRaisedWithSurviveAndAreWhatARenderLaterUses(): void
    {
        $templateId = $this->circular('Faculty circular');

        $created = $this->create(self::RAISER, [
            'document_source_id' => $templateId,
            'title' => 'Semester circular 2026/1',
            'values' => ['template.reference' => 'FAC-2026-014', 'template.date' => '2026-08-24'],
        ]);
        self::assertSame(201, $created->getStatusCode(), $created->getBody());
        $documentId = (int) self::data($created)['id'];

        // Nothing was rendered, because nothing could be.
        self::assertSame([], $this->fakeRender->calls, 'the render service must not be called when the tier is off');
        self::assertSame([], $this->artifacts->listForDocument($documentId, self::TENANT));
        self::assertSame(
            ['attempted' => false, 'stored' => false, 'reason' => 'disabled'],
            self::decode($created)['render']
        );

        // The row remembers what it was raised with.
        $stored = $this->storedValues($documentId);
        self::assertSame('FAC-2026-014', $stored['template.reference']);
        self::assertSame('2026-08-24', $stored['template.date']);

        // Weeks later the operator switches the render tier on and the document
        // is rendered with no values in the request — there is no body any more.
        $this->settings->setGlobal(SettingsRegistry::DOCUMENTS_RENDER_ENABLED, 'true');
        $rendered = $this->call('rerender', self::RAISER, ['id' => (string) $documentId]);
        self::assertSame(201, $rendered->getStatusCode(), $rendered->getBody());

        self::assertCount(1, $this->fakeRender->flowCalls);
        self::assertContains(
            'Ref: FAC-2026-014 of 2026-08-24',
            EditorTemplateFixture::texts($this->fakeRender->flowCalls[0]),
            'a render with no supplied values must use the ones the document was RAISED with'
        );
    }

    /**
     * What is stored is what PRINTED: every field the version uses, with a
     * field that had no value recorded as the blank it printed as.
     *
     * The row is the only record of the content; a field missing from it would
     * make a correction print differently from the original for no reason
     * anybody could see.
     */
    public function testTheStoredValuesAreWhatPrintedIncludingBlanks(): void
    {
        $this->settings->setTenant(self::TENANT, SettingsRegistry::BILLING_SELLER_NAME, 'Example Bakery');
        $templateId = $this->editor->published(self::TENANT, self::RAISER, 'Delivery note', [
            $this->editor->para('From ', $this->editor->field('company.name'), ', ', $this->editor->field('template.reference')),
        ], ['own_fields' => self::OWN_FIELDS]);

        $res = $this->create(self::RAISER, ['document_source_id' => $templateId]);
        self::assertSame(201, $res->getStatusCode(), $res->getBody());

        $stored = $this->storedValues((int) self::data($res)['id']);
        self::assertSame('Example Bakery', $stored['company.name'], 'the shared fields are read for the person raising it');
        self::assertSame('', $stored['company.tax_number'] ?? null, 'a field with no value is stored as the blank it printed as');
        self::assertArrayNotHasKey('template.reference', $stored, 'an own field nobody typed is not invented');
    }

    // ── template scoping ─────────────────────────────────────────────────────

    /**
     * A template the caller cannot SEE cannot be raised from — and the refusal
     * is a 404, so the route does not confirm the template exists.
     *
     * The template here is filed at unit 7 AND tagged with a permission the
     * caller does not hold, so it is refused twice over. That makes it a fair
     * test of "cannot see means cannot raise" and a POOR test of either gate on
     * its own — placement alone already refuses this caller, so the tag is
     * inert here. The two gates are isolated separately, one variable at a time:
     * placement by {@see testTheSamePlacedTemplateIsRaisableFromInsideTheUnit},
     * the permission tag by {@see testAnUnplacedTemplatesPermissionTagIsTheOnlyVariable}.
     *
     * It is checked with the EDITOR's policy, not the document one: creating
     * from a gated template must not be a way to read a gated template.
     */
    public function testATemplateTheCallerCannotSeeCannotBeRaisedFrom(): void
    {
        $templateId = $this->editor->published(self::TENANT, self::RAISER, 'Contract template', [], [
            'required_permission' => CorePermissions::DOCUMENTS_PUBLISH,
            'owner_ou_id' => self::UNIT,
        ]);

        // The registry officer holds documents:render (so the ROUTE gate passes)
        // but neither documents:publish nor any standing at unit 7.
        $res = $this->create(self::REGISTRY_OFFICER, ['document_source_id' => $templateId]);

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
        // Created by the RAISER, so the author-always-reaches-their-own-row
        // shortcut cannot be what makes this pass for the secretary.
        $templateId = $this->editor->published(self::TENANT, self::RAISER, 'Faculty-only circular', [], [
            'owner_ou_id' => self::UNIT,
        ]);

        self::assertSame(
            404,
            $this->create(self::REGISTRY_OFFICER, ['document_source_id' => $templateId])->getStatusCode(),
            'a caller standing nowhere does not reach a placed template'
        );
        self::assertSame(
            201,
            $this->create(self::SECRETARY, ['document_source_id' => $templateId])->getStatusCode(),
            'a caller standing AT the unit does'
        );
    }

    /**
     * An UNPLACED template's `required_permission` is the only variable: the
     * caller who holds it raises, the caller who does not gets a 404.
     *
     * `DocumentAccessPolicy` asks two orthogonal questions — WHERE the row is
     * filed and WHAT KIND OF PERSON may see it — and the tests above vary
     * placement. This one holds placement fixed at NOTHING (`owner_ou_id` null,
     * so reach cannot refuse anybody) and varies only the tag, which is the only
     * arrangement in which a create that ignored `required_permission` entirely
     * would go red.
     *
     * The two callers are chosen so the tag is genuinely all that differs: the
     * registry officer holds `documents:render` and stands nowhere, the
     * secretary stands at a unit and does not hold it. Neither authored the
     * template, so the author-always-reaches-their-own-row shortcut cannot be
     * what makes either answer come out.
     */
    public function testAnUnplacedTemplatesPermissionTagIsTheOnlyVariable(): void
    {
        $templateId = $this->editor->published(self::TENANT, self::RAISER, 'Render-tagged circular', [], [
            'required_permission' => CorePermissions::DOCUMENTS_RENDER,
        ]);

        self::assertSame(
            404,
            $this->create(self::SECRETARY, ['document_source_id' => $templateId])->getStatusCode(),
            'a caller who does not hold the tag cannot raise from a tagged template'
        );
        self::assertSame(0, $this->countDocuments(), 'and nothing is written for them');

        self::assertSame(
            201,
            $this->create(self::REGISTRY_OFFICER, ['document_source_id' => $templateId])->getStatusCode(),
            'a caller who holds it can — otherwise this passes against a policy that refuses everything'
        );
        self::assertSame(1, $this->countDocuments());
    }

    /**
     * A personal template is its author's alone: anybody else raising from it
     * gets the same 404 as for one that does not exist.
     */
    public function testSomebodyElsesPersonalTemplateIsNotReachable(): void
    {
        $templateId = $this->editor->published(self::TENANT, self::SECRETARY, 'My draft letter', [], ['scope' => 'personal']);

        self::assertSame(404, $this->create(self::RAISER, ['document_source_id' => $templateId])->getStatusCode());
        self::assertSame(201, $this->create(self::SECRETARY, ['document_source_id' => $templateId])->getStatusCode());
    }

    /** A template in another tenant is not reachable by id. */
    public function testATemplateInAnotherTenantIsNotReachable(): void
    {
        $templateId = $this->editor->published(self::OTHER_TENANT, self::OTHER_TENANT_ADMIN, 'Their template');

        $res = $this->create(self::RAISER, ['document_source_id' => $templateId]);

        self::assertSame(404, $res->getStatusCode());
        self::assertSame(0, $this->countDocuments());
    }

    /**
     * Only templates issue documents. A label sheet prints labels and a note is
     * never published; neither is a document's origin.
     */
    /**
     * "Edit before issuing": a one-off letter is issued as it is NOW, frozen as
     * a version first so the document records exactly what it said. Issuing it
     * again unchanged reuses that version; an edit makes the next one.
     */
    public function testAOneOffLetterIsIssuedAsItIsAndFrozenAsAVersion(): void
    {
        $letter = $this->editor->draft(self::TENANT, self::RAISER, 'Letter to Example Clinic', [], ['kind' => 'letter', 'scope' => 'personal']);

        $first = $this->create(self::RAISER, ['document_source_id' => $letter, 'render' => false]);
        self::assertSame(201, $first->getStatusCode(), $first->getBody());
        $doc = json_decode($first->getBody(), true)['data'];
        self::assertSame($letter, $doc['document_source_id']);
        self::assertSame(1, $doc['document_source_version'], 'its draft was frozen as version 1');

        $again = json_decode($this->create(self::RAISER, ['document_source_id' => $letter, 'render' => false])->getBody(), true)['data'];
        self::assertSame(1, $again['document_source_version'], 'unchanged, so the same version');

        $this->editor->redraft(self::TENANT, $letter, ['blocks' => [$this->editor->para('Dear Example Clinic, thank you.')]], self::RAISER);
        $edited = json_decode($this->create(self::RAISER, ['document_source_id' => $letter, 'render' => false])->getBody(), true)['data'];
        self::assertSame(2, $edited['document_source_version'], 'an edit is frozen as the next version');
    }

    public function testOnlyATemplateIssuesDocuments(): void
    {
        $labels = $this->editor->published(self::TENANT, self::RAISER, 'Parcel labels', [], ['kind' => 'label']);

        $res = $this->create(self::RAISER, ['document_source_id' => $labels]);

        self::assertSame(422, $res->getStatusCode());
        self::assertSame(0, $this->countDocuments());
    }

    /**
     * A template that was never published cannot issue a document — the draft
     * is somebody's work in progress — and the refusal is a 409 (the template is
     * there, its state is wrong) that writes nothing.
     */
    public function testATemplateWithNoPublishedVersionIsAConflict(): void
    {
        $templateId = $this->editor->draft(self::TENANT, self::RAISER, 'Faculty circular');

        $res = $this->create(self::RAISER, ['document_source_id' => $templateId]);

        self::assertSame(409, $res->getStatusCode());
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
        $templateId = $this->circular('Faculty circular');

        $res = $this->create(self::REGISTRY_OFFICER, [
            'document_source_id' => $templateId,
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
        $templateId = $this->circular('Faculty circular');

        $res = $this->create(self::RAISER, ['document_source_id' => $templateId]);

        $row = $this->documents->findById((int) self::data($res)['id'], self::TENANT);
        self::assertNotNull($row);
        self::assertSame(self::UNIT, $row['origin_ou_id']);
    }

    /**
     * The document records the template AND the version it was issued from,
     * which is what a correction re-renders — so a template republished since
     * cannot change what a correction of an older document prints.
     */
    public function testTheTemplateAndItsPublishedVersionAreRecorded(): void
    {
        $templateId = $this->circular('Faculty circular');
        $first = $this->create(self::RAISER, ['document_source_id' => $templateId]);

        $this->editor->redraft(self::TENANT, $templateId, ['title' => 'Faculty circular, second edition'], self::RAISER);
        $this->editor->publish(self::TENANT, $templateId, self::RAISER);
        $second = $this->create(self::RAISER, ['document_source_id' => (string) $templateId]);

        self::assertSame($templateId, self::data($first)['document_source_id']);
        self::assertSame(1, self::data($first)['document_source_version']);
        self::assertSame(2, self::data($second)['document_source_version']);

        $row = $this->documents->findById((int) self::data($first)['id'], self::TENANT);
        self::assertNotNull($row);
        self::assertSame($templateId, $row['document_source_id']);
        self::assertSame(1, $row['document_source_version']);
        self::assertNull($row['document_template_id'], 'the old designer is not involved');
    }

    /**
     * `template_name` is a SNAPSHOT of the PUBLISHED version's title, and the
     * whole point of it is that it outlives the template.
     *
     * Asserted by actually deleting the template and reading the document back
     * — a test that merely compared the name at create time would pass against
     * a join. The draft is renamed first, so a snapshot of the draft rather than
     * of what was printed would also go red.
     */
    public function testTheTemplateNameIsSnapshottedAndSurvivesTheTemplatesDeletion(): void
    {
        $templateId = $this->circular('Faculty circular');
        $this->editor->redraft(self::TENANT, $templateId, ['title' => 'A rename nobody published'], self::RAISER);
        $res = $this->create(self::RAISER, ['document_source_id' => $templateId, 'title' => 'Circular 12']);
        $documentId = (int) self::data($res)['id'];

        $this->editor->sourceRepo->delete($templateId, self::TENANT);

        $row = $this->documents->findById($documentId, self::TENANT);
        self::assertNotNull($row);
        self::assertSame('Faculty circular', $row['template_name'], 'the snapshot is what keeps the record legible');
        self::assertSame('Circular 12', $row['title']);
    }

    /**
     * An untitled document is named after the published version, and after the
     * record it was raised for when there is one: "<version title> · <record
     * label>" — rather than left blank.
     */
    public function testAnUntitledDocumentTakesTheVersionTitle(): void
    {
        $templateId = $this->circular('Faculty circular');

        $res = $this->create(self::RAISER, ['document_source_id' => $templateId, 'title' => '   ']);

        self::assertSame('Faculty circular', self::data($res)['title']);
        // Read back, because a response carrying the right title over a row that
        // stored the literal '   ' is exactly the bug this guards.
        $row = $this->documents->findById((int) self::data($res)['id'], self::TENANT);
        self::assertNotNull($row);
        self::assertSame('Faculty circular', $row['title']);
    }

    /** With a record, the default title names it too. */
    public function testAnUntitledDocumentForARecordNamesTheRecord(): void
    {
        $about = $this->seedDocument(self::RAISER, 'Order 118 for Example Clinic');
        $templateId = $this->editor->published(self::TENANT, self::RAISER, 'Payment reminder', [
            $this->editor->para('About ', $this->editor->field('document.title')),
        ], ['record_type' => 'document']);

        $res = $this->create(self::RAISER, ['document_source_id' => $templateId, 'record' => 'document:' . $about]);

        self::assertSame(201, $res->getStatusCode(), $res->getBody());
        self::assertSame('Payment reminder · #' . $about . ' · Order 118 for Example Clinic', self::data($res)['title']);
        self::assertSame(
            'Order 118 for Example Clinic',
            $this->storedValues((int) self::data($res)['id'])['document.title'],
            'the record\'s values are read and frozen in'
        );
    }

    // ── the record ───────────────────────────────────────────────────────────

    /**
     * A template with a record type needs a record of THAT type, and a record
     * the caller cannot see is the same 404 as one that does not exist (E.12).
     * None of the refusals writes anything.
     */
    public function testARecordTemplateNeedsAVisibleRecordOfItsType(): void
    {
        $hidden = $this->seedDocument(self::SECRETARY, 'The secretary\'s own notes');
        $templateId = $this->editor->published(self::TENANT, self::REGISTRY_OFFICER, 'Payment reminder', [], ['record_type' => 'document']);
        $before = $this->countDocuments();

        self::assertSame(422, $this->create(self::REGISTRY_OFFICER, ['document_source_id' => $templateId])->getStatusCode(), 'no record');
        self::assertSame(422, $this->create(self::REGISTRY_OFFICER, ['document_source_id' => $templateId, 'record' => 'invoice:1'])->getStatusCode(), 'another type');
        self::assertSame(422, $this->create(self::REGISTRY_OFFICER, ['document_source_id' => $templateId, 'record' => 'not a record'])->getStatusCode());
        self::assertSame(404, $this->create(self::REGISTRY_OFFICER, ['document_source_id' => $templateId, 'record' => 'document:99999'])->getStatusCode());
        self::assertSame(
            404,
            $this->create(self::REGISTRY_OFFICER, ['document_source_id' => $templateId, 'record' => 'document:' . $hidden])->getStatusCode(),
            'hidden is the same as missing'
        );
        self::assertSame($before, $this->countDocuments());
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
        $templateId = $this->circular('Faculty circular');

        $res = $this->create(self::RAISER, [
            'document_source_id' => $templateId,
            'values' => ['template.reference' => 'R-1', 'template.date' => '2026-02-02'],
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
        // And the values the caller supplied are the ones that were rendered,
        // through the flowing renderer.
        self::assertCount(1, $this->fakeRender->flowCalls);
        self::assertContains('Ref: R-1 of 2026-02-02', EditorTemplateFixture::texts($this->fakeRender->flowCalls[0]));
    }

    /**
     * `render: false` keeps the record and renders nothing even on an instance
     * that could — the "raise it now, print it later" case.
     */
    public function testRenderFalseDeclinesTheArtifactOnACapableInstance(): void
    {
        $this->settings->setGlobal(SettingsRegistry::DOCUMENTS_RENDER_ENABLED, 'true');
        $templateId = $this->circular('Faculty circular');

        $res = $this->create(self::RAISER, ['document_source_id' => $templateId, 'render' => false]);

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
        $templateId = $this->circular('Faculty circular');

        $res = $this->create(self::RAISER, ['document_source_id' => $templateId, 'render' => true]);

        self::assertSame(503, $res->getStatusCode());
        self::assertSame(0, $this->countDocuments());
    }

    /**
     * The same for an instance that renders but may not KEEP the PDF: asking
     * for one there is a 503 before anything is written, not a record whose
     * required artifact silently never arrives.
     */
    public function testRequiringARenderWithPersistenceOffIsRefusedAndWritesNothing(): void
    {
        $this->settings->setGlobal(SettingsRegistry::DOCUMENTS_RENDER_ENABLED, 'true');
        $this->settings->setGlobal(SettingsRegistry::DOCUMENTS_PERSIST_ENABLED, 'false');
        $templateId = $this->circular('Faculty circular');

        $res = $this->create(self::RAISER, ['document_source_id' => $templateId, 'render' => true]);

        self::assertSame(503, $res->getStatusCode());
        self::assertSame(0, $this->countDocuments());
        self::assertSame([], $this->fakeRender->calls);
    }

    /**
     * `render: true` on an instance that CAN render succeeds and stores.
     *
     * Without this, the 503s above are the only tests in the suite that send
     * `render: true` at all — and a handler that answered 503 to every
     * `render: true`, capable instance or not, would pass them. The default-path
     * test omits the key entirely, so it exercises "absent → render if you can",
     * a different branch.
     */
    public function testRequiringARenderOnACapableInstanceSucceedsAndStores(): void
    {
        $this->settings->setGlobal(SettingsRegistry::DOCUMENTS_RENDER_ENABLED, 'true');
        $this->fakeRender->pdfBytes = "%PDF-1.4\nrequired\n%%EOF";
        $templateId = $this->circular('Faculty circular');

        $res = $this->create(self::RAISER, ['document_source_id' => $templateId, 'render' => true]);

        self::assertSame(201, $res->getStatusCode());
        self::assertSame(
            ['attempted' => true, 'stored' => true, 'reason' => null],
            self::decode($res)['render']
        );
        $documentId = (int) self::data($res)['id'];
        self::assertCount(1, $this->artifacts->listForDocument($documentId, self::TENANT));
        self::assertSame(
            "%PDF-1.4\nrequired\n%%EOF",
            $this->call('content', self::RAISER, ['id' => (string) $documentId])->getBody()
        );
    }

    /** Persistence off is reported as its own reason, not as "disabled". */
    public function testPersistenceOffIsReportedSeparatelyFromRenderingOff(): void
    {
        $this->settings->setGlobal(SettingsRegistry::DOCUMENTS_RENDER_ENABLED, 'true');
        $this->settings->setGlobal(SettingsRegistry::DOCUMENTS_PERSIST_ENABLED, 'false');
        $templateId = $this->circular('Faculty circular');

        $res = $this->create(self::RAISER, ['document_source_id' => $templateId]);

        self::assertSame(201, $res->getStatusCode(), 'a storage cap is not a reason a document cannot exist');
        self::assertSame('persist_disabled', self::decode($res)['render']['reason']);
        self::assertSame([], $this->artifacts->listForDocument((int) self::data($res)['id'], self::TENANT));
        self::assertSame([], $this->fakeRender->calls, 'nothing is rendered that could not be kept');
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
        $templateId = $this->circular('Faculty circular');

        $res = $this->create(self::RAISER, [
            'document_source_id' => $templateId,
            'values' => ['template.reference' => 'KEEP-ME', 'template.date' => '2026-03-03'],
        ]);

        self::assertSame(201, $res->getStatusCode());
        self::assertSame(
            ['attempted' => true, 'stored' => false, 'reason' => 'unavailable'],
            self::decode($res)['render']
        );

        self::assertSame('KEEP-ME', $this->storedValues((int) self::data($res)['id'])['template.reference']);
        self::assertNull(self::data($res)['content_url'], 'a record with no artifact must not promise bytes');
    }

    /**
     * A payload the service REFUSES is reported as `rejected`, not as an
     * outage: retrying an outage can help, retrying a rejection cannot, and a
     * client told the wrong one retries forever. The record stands either way.
     */
    public function testARejectedRenderIsReportedAsRejectedAndLeavesTheDocument(): void
    {
        $this->settings->setGlobal(SettingsRegistry::DOCUMENTS_RENDER_ENABLED, 'true');
        $this->fakeRender->rejectWith = 'The page is too small for this content';
        $templateId = $this->circular('Faculty circular');

        $res = $this->create(self::RAISER, ['document_source_id' => $templateId]);

        self::assertSame(201, $res->getStatusCode());
        self::assertSame(['attempted' => true, 'stored' => false, 'reason' => 'rejected'], self::decode($res)['render']);
        self::assertSame(1, $this->countDocuments());
    }

    // ── the verification code ────────────────────────────────────────────────

    /**
     * With the tenant's QR switch ON, the code minted for THIS document reaches
     * the renderer as a `{type:'qr'}` block at the end of the flow, carrying
     * the URL a phone opens and the short reference printed under it.
     *
     * The URL is rebuilt from the token READ OUT OF THE DATABASE rather than
     * from the service that produced it, so a bug in
     * {@see DocumentQrService::verificationUrl()} cannot agree with itself.
     */
    public function testWithQrOnTheMintedCodeIsAppendedToTheFlow(): void
    {
        $this->settings->setGlobal(SettingsRegistry::DOCUMENTS_RENDER_ENABLED, 'true');
        $this->settings->setTenant(self::TENANT, SettingsRegistry::DOCUMENTS_QR_ENABLED, 'true');
        $templateId = $this->circular('Faculty circular');

        $res = $this->create(self::RAISER, ['document_source_id' => $templateId]);
        self::assertSame(201, $res->getStatusCode(), $res->getBody());
        $documentId = (int) self::data($res)['id'];

        $token = $this->qr->active(self::TENANT, $documentId);
        self::assertIsArray($token, 'a code is minted for the document it is printed on');
        $payload = $this->fakeRender->flowCalls[0];
        $qr = EditorTemplateFixture::qrBlocks($payload);
        self::assertCount(1, $qr);
        self::assertSame('https://docs.example.test/verify/' . $token['token'], $qr[0]['value']);
        self::assertSame($this->qr->reference((string) $token['token']), $qr[0]['reference']);
        self::assertSame($qr[0], $payload['content'][count($payload['content']) - 1], 'appended after the template\'s own blocks');
    }

    /**
     * With the switch OFF (the default), nothing is minted and nothing is
     * appended — a document carries no code its tenant has not turned on.
     */
    public function testWithQrOffNothingIsMintedOrAppended(): void
    {
        $this->settings->setGlobal(SettingsRegistry::DOCUMENTS_RENDER_ENABLED, 'true');
        $templateId = $this->circular('Faculty circular');

        $res = $this->create(self::RAISER, ['document_source_id' => $templateId]);

        self::assertSame(201, $res->getStatusCode());
        self::assertSame([], EditorTemplateFixture::qrBlocks($this->fakeRender->flowCalls[0]));
        self::assertNull($this->qr->active(self::TENANT, (int) self::data($res)['id']));
    }

    // ── validation ───────────────────────────────────────────────────────────

    /**
     * A value for a field the template does not declare is refused, by name,
     * and nothing is written.
     *
     * Accepting it is the failure that is invisible until a recipient reads the
     * document: `template.refrence` would be stored, `template.reference` would
     * never be filled, and the finished PDF would carry a blank where the
     * reference number belongs.
     */
    public function testAValueForAnUndeclaredFieldIsRefusedByName(): void
    {
        $templateId = $this->circular('Faculty circular');

        $res = $this->create(self::RAISER, [
            'document_source_id' => $templateId,
            'values' => ['template.reference' => 'R-1', 'template.refrence' => 'typo'],
        ]);

        self::assertSame(422, $res->getStatusCode());
        self::assertStringContainsString('template.refrence', self::decode($res)['error'] ?? '');
        self::assertSame(0, $this->countDocuments());
    }

    /**
     * Merge data can't overwrite what the record resolved (DOCUMENT-AUTOMATION.md
     * 3.3): a value for the tax number, the customer's name or an amount the
     * record holds is a 422 `field_conflict`, on an official document. A field
     * the record left empty can be filled.
     */
    public function testAValueCanFillAnEmptyFieldButNotOverwriteAResolvedOne(): void
    {
        $templateId = $this->circular('Faculty circular');

        // No tax number in the settings: the field is empty, so it can be given.
        $filled = $this->create(self::RAISER, [
            'document_source_id' => $templateId,
            'values' => ['company.tax_number' => '0123456'],
            'render' => false,
        ]);
        self::assertSame(201, $filled->getStatusCode(), $filled->getBody());
        self::assertSame('0123456', $this->storedValues((int) self::decode($filled)['data']['id'])['company.tax_number']);

        $this->settings->setTenant(self::TENANT, SettingsRegistry::BILLING_SELLER_TAX_ID, '7654321');
        $before = $this->countDocuments();
        $res = $this->create(self::RAISER, [
            'document_source_id' => $templateId,
            'values' => ['company.tax_number' => '0000000'],
        ]);

        self::assertSame(422, $res->getStatusCode());
        $body = self::decode($res);
        self::assertStringContainsString('company.tax_number', $body['error'] ?? '');
        self::assertSame('field_conflict', $body['details']['code'] ?? null);
        self::assertSame(['company.tax_number'], $body['details']['conflicts'] ?? null);
        self::assertSame($before, $this->countDocuments(), 'nothing written');
    }

    public function testABadValuesShapeIsRefusedAndWritesNothing(): void
    {
        $templateId = $this->circular('Faculty circular');

        foreach (['not-an-object', ['R-1', '2026-01-01'], ['template.reference' => 12]] as $bad) {
            $res = $this->create(self::RAISER, ['document_source_id' => $templateId, 'values' => $bad]);
            self::assertSame(422, $res->getStatusCode(), (string) json_encode($bad));
        }
        self::assertSame(0, $this->countDocuments());
    }

    /**
     * A required field with no value refuses the document, names the field,
     * writes nothing and sends nothing to Whity Render (E.27). The company tax
     * number is fiscal, so required.
     */
    public function testARequiredFieldWithNoValueIsRefusedByName(): void
    {
        $this->settings->setGlobal(SettingsRegistry::DOCUMENTS_RENDER_ENABLED, 'true');
        $templateId = $this->editor->published(self::TENANT, self::RAISER, 'Tax invoice', [
            $this->editor->para('Tax no. ', $this->editor->field('company.tax_number')),
        ]);

        $res = $this->create(self::RAISER, ['document_source_id' => $templateId]);

        self::assertSame(422, $res->getStatusCode());
        self::assertSame(['company.tax_number'], self::decode($res)['details']['missing']);
        self::assertSame(0, $this->countDocuments());
        self::assertSame([], $this->fakeRender->calls);

        // And with the value set, the same request issues it.
        $this->settings->setTenant(self::TENANT, SettingsRegistry::BILLING_SELLER_TAX_ID, '0123456');
        self::assertSame(201, $this->create(self::RAISER, ['document_source_id' => $templateId])->getStatusCode());
    }

    public function testNamingNoTemplateIsRefusedRatherThanReadAsTemplateZero(): void
    {
        foreach ([['title' => 'Orphan'], ['document_source_id' => 0], ['document_source_id' => 'abc'], ['document_template_id' => 1]] as $body) {
            $res = $this->create(self::RAISER, $body);
            self::assertSame(422, $res->getStatusCode(), (string) json_encode($body));
        }
        self::assertSame(0, $this->countDocuments());
    }

    /**
     * A numeric STRING id is accepted. Form-driven clients routinely send one,
     * and a 422 whose cause is invisible in the payload the developer is reading
     * is a bad afternoon.
     */
    // ── one issuing service: origin keys and events (DOCUMENT-AUTOMATION.md) ──

    /**
     * An origin key names what a document is ("the circular for term 2"):
     * issuing it again returns the first document (200, replayed) instead of a
     * second one, so a retried request or a redelivered event never issues
     * twice. The same key for another template is a conflict.
     */
    public function testAnOriginKeyIssuesOnceAndReplaysTheSameDocument(): void
    {
        $templateId = $this->circular('Faculty circular');
        $body = ['document_source_id' => $templateId, 'origin_key' => 'term:2026-2:circular', 'render' => false];

        $first = $this->create(self::RAISER, $body);
        self::assertSame(201, $first->getStatusCode(), $first->getBody());
        self::assertFalse(self::decode($first)['replayed']);
        $id = self::decode($first)['data']['id'];
        self::assertSame('term:2026-2:circular', self::decode($first)['data']['origin_key']);

        $again = $this->create(self::RAISER, $body);
        self::assertSame(200, $again->getStatusCode());
        self::assertTrue(self::decode($again)['replayed']);
        self::assertSame($id, self::decode($again)['data']['id']);
        self::assertSame(1, $this->countDocuments(), 'issued once');

        $other = $this->circular('Exam timetable');
        $conflict = $this->create(self::RAISER, ['document_source_id' => $other] + $body);
        self::assertSame(409, $conflict->getStatusCode());
        self::assertSame('origin_key_conflict', self::decode($conflict)['details']['code'] ?? null);
        self::assertSame($id, self::decode($conflict)['details']['document_id'] ?? null);

        self::assertSame(422, $this->create(self::RAISER, ['document_source_id' => $templateId, 'origin_key' => 'has spaces'])->getStatusCode());
        self::assertSame(1, $this->countDocuments());
    }

    /**
     * `document.issuing` runs before anything is written, and a listener may
     * refuse; `document.issued` follows with the new id. Payloads carry ids,
     * never field values.
     */
    public function testIssuingCanBeVetoedAndIssuedIsAnnouncedWithIdsOnly(): void
    {
        $templateId = $this->circular('Faculty circular');
        $seen = [];
        $this->editor->hooks->listen(Events::DOCUMENT_ISSUED, static function (array $data) use (&$seen): array {
            $seen[] = $data;

            return $data;
        });

        $res = $this->create(self::RAISER, ['document_source_id' => $templateId, 'values' => ['template.reference' => 'REF-1042'], 'render' => false]);
        self::assertSame(201, $res->getStatusCode(), $res->getBody());
        self::assertCount(1, $seen);
        self::assertSame(self::decode($res)['data']['id'], $seen[0]['id']);
        self::assertSame($templateId, $seen[0]['source_id']);
        self::assertSame(1, $seen[0]['version']);
        self::assertSame(self::RAISER, $seen[0]['actor']);
        self::assertStringNotContainsString('REF-1042', (string) json_encode($seen), 'no field values in an event');

        $this->editor->hooks->listen(Events::DOCUMENT_ISSUING, static function (): array {
            throw HookVetoException::forEvent(Events::DOCUMENT_ISSUING, 'Example Bakery issues circulars on Mondays only');
        });
        $before = $this->countDocuments();
        $vetoed = $this->create(self::RAISER, ['document_source_id' => $templateId, 'render' => false]);
        self::assertSame(409, $vetoed->getStatusCode());
        self::assertSame('Example Bakery issues circulars on Mondays only', self::decode($vetoed)['error']);
        self::assertSame($before, $this->countDocuments(), 'nothing written');
    }

    // ── template slots (WHIT-742) ─────────────────────────────────────────────

    /**
     * A plugin names its slot and the workspace's binding decides the
     * template: the document is issued from the bound template's published
     * version, exactly as naming the template would.
     */
    public function testASlotIssuesFromTheTemplateTheWorkspaceBoundToIt(): void
    {
        $about = $this->seedDocument(self::RAISER, 'Order 4812');
        $templateId = $this->slotTemplate();
        $this->bindSlot($templateId);

        $res = $this->create(self::RAISER, ['slot' => 'Courier.receipt', 'record' => 'document:' . $about, 'render' => false]);

        self::assertSame(201, $res->getStatusCode(), $res->getBody());
        self::assertSame($templateId, self::data($res)['document_source_id']);
    }

    public function testASlotThatCantBeIssuedFromSaysWhyAndWritesNothing(): void
    {
        $about = $this->seedDocument(self::RAISER, 'Order 4812');
        $body = ['slot' => 'Courier.receipt', 'record' => 'document:' . $about];

        $unknown = $this->create(self::RAISER, $body);
        self::assertSame(409, $unknown->getStatusCode());
        self::assertSame('slot_unavailable', self::decode($unknown)['details']['code'] ?? null, $unknown->getBody());
        self::assertSame('Courier', self::decode($unknown)['details']['plugin'] ?? null);

        $this->registerSlot();
        $unbound = $this->create(self::RAISER, $body);
        self::assertSame(409, $unbound->getStatusCode());
        self::assertSame('slot_unbound', self::decode($unbound)['details']['code'] ?? null);

        // Bound while it fitted, then made personal: refused, not issued from.
        $templateId = $this->slotTemplate();
        $this->bindSlot($templateId);
        $this->pdo->exec("UPDATE document_sources SET scope = 'personal' WHERE id = {$templateId}");
        $misfit = $this->create(self::RAISER, $body);
        self::assertSame(409, $misfit->getStatusCode());
        self::assertSame('slot_misfit', self::decode($misfit)['details']['code'] ?? null);
        self::assertSame('personal_template', self::decode($misfit)['details']['reason'] ?? null);

        self::assertSame(422, $this->create(self::RAISER, $body + ['document_source_id' => $templateId])->getStatusCode(), 'slot or template, not both');
        self::assertSame(1, $this->countDocuments(), 'only the seeded document');
    }

    /**
     * The whole path Builder's automations take: a workspace plugin's slot,
     * bound to a template for its own record type, issued for one of its
     * records (`CakeOrders.order:12`), with the record's values printed.
     */
    public function testAWorkspacePluginsSlotIssuesForOneOfItsRecords(): void
    {
        $manifest = PluginManifest::fromArray(DocumentSlotValidatorTest::manifest());
        $records = new TenantRecordRepository($this->pdo);
        $this->editor->fields->addTenantSource(static fn (int $tenantId): array => $tenantId === self::TENANT
            ? TenantRecordFieldProvider::forManifests([$manifest], $records)
            : []);
        $this->slots = new TemplateSlotRegistry(static fn (int $tenantId): array => $tenantId === self::TENANT ? [$manifest] : []);
        $this->rebuildHandler();
        $order = $records->create(self::TENANT, 'CakeOrders', 'order', 'draft', ['customer' => 'Example Bakery', 'pickup_on' => '2026-11-05', 'price' => 18000], self::RAISER);
        $templateId = $this->editor->published(self::TENANT, self::RAISER, 'Order confirmation', [
            $this->editor->para('For ', $this->editor->field('record.customer'), ', ', $this->editor->field('record.price')),
        ], ['record_type' => 'CakeOrders.order', 'scope' => 'tenant']);
        $this->settings->setTenant(self::TENANT, SettingsRegistry::DOCUMENTS_SLOT_BINDINGS, SlotBindings::encode(['CakeOrders.order_confirmation' => $templateId]));
        $this->grantTo(self::RAISER, 'cakeorders:read');

        $res = $this->create(self::RAISER, ['slot' => 'CakeOrders.order_confirmation', 'record' => 'CakeOrders.order:' . $order, 'render' => false]);

        self::assertSame(201, $res->getStatusCode(), $res->getBody());
        $values = $this->storedValues(self::data($res)['id']);
        self::assertSame('Example Bakery', $values['record.customer'] ?? null);
        self::assertSame('18.000 JOD', $values['record.price'] ?? null);
    }

    private function rebuildHandler(): void
    {
        $this->handler = ($this->rebuild)();
    }

    private function grantTo(int $profileId, string $permission): void
    {
        self::assertSame(self::RAISER, $profileId, 'the raiser holds role 1');
        $this->grant($this->pdo, 1, $permission);
        RoleChecker::clearCache();
    }

    private function registerSlot(): void
    {
        $this->slots->register('Courier', [
            new TemplateSlot('Courier.receipt', 'Courier', ['en' => 'Receipt', 'ar' => 'إيصال'], 'document'),
        ]);
    }

    private function slotTemplate(): int
    {
        $this->registerSlot();

        return $this->editor->published(self::TENANT, self::RAISER, 'Receipt', [
            $this->editor->para('Receipt'),
        ], ['record_type' => 'document', 'scope' => 'tenant']);
    }

    private function bindSlot(int $templateId): void
    {
        $this->settings->setTenant(self::TENANT, SettingsRegistry::DOCUMENTS_SLOT_BINDINGS, SlotBindings::encode(['Courier.receipt' => $templateId]));
    }

    public function testANumericStringTemplateIdIsAccepted(): void
    {
        $templateId = $this->circular('Faculty circular');

        $res = $this->create(self::RAISER, ['document_source_id' => (string) $templateId]);

        self::assertSame(201, $res->getStatusCode());
        // A 201 alone would also be returned by a coercion that resolved "5" to
        // a DIFFERENT visible template, or that wrote no row at all. The row is
        // the evidence: it names the template the caller actually asked for.
        self::assertSame(1, $this->countDocuments());
        $row = $this->documents->findById((int) self::data($res)['id'], self::TENANT);
        self::assertNotNull($row);
        self::assertSame($templateId, $row['document_source_id']);
        self::assertSame('Faculty circular', $row['template_name']);
    }

    // ── the created document is a first-class one ────────────────────────────

    /**
     * A document created this way is immediately visible through the ordinary
     * read paths — which is the whole point of it having an identity, and the
     * one thing a create route can get wrong while returning a perfect 201.
     */
    public function testTheCreatedDocumentIsImmediatelyListableAndReadable(): void
    {
        $templateId = $this->circular('Faculty circular');
        $res = $this->create(self::RAISER, ['document_source_id' => $templateId, 'title' => 'Findable']);
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
        $templateId = $this->circular('Faculty circular');
        $res = $this->create(self::RAISER, [
            'document_source_id' => $templateId,
            'values' => ['template.reference' => 'PRIVATE-1', 'template.date' => '2026-04-04'],
        ]);

        self::assertArrayNotHasKey('variable_data', self::data($res));
        self::assertStringNotContainsString('PRIVATE-1', $res->getBody());
        $show = $this->call('show', self::RAISER, ['id' => (string) self::data($res)['id']]);
        self::assertStringNotContainsString('PRIVATE-1', $show->getBody());
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /**
     * A published, tenant-wide, unplaced template shaped like the demo faculty
     * circular: two own fields printed in one line, so a test can read the
     * values back out of the payload the renderer was handed.
     */
    private function circular(string $title): int
    {
        return $this->editor->published(self::TENANT, self::RAISER, $title, [
            $this->editor->para($title),
            $this->editor->para('Ref: ', $this->editor->field('template.reference'), ' of ', $this->editor->field('template.date')),
        ], ['own_fields' => self::OWN_FIELDS]);
    }

    /** A document to raise another one ABOUT, owned by `$createdBy`. */
    private function seedDocument(int $createdBy, string $title): int
    {
        return $this->documents->create(self::TENANT, [
            'template_name' => 'Orders',
            'title' => $title,
            'created_by' => $createdBy,
        ]);
    }

    /**
     * The one row of values a document was raised with, by field path.
     *
     * Read by key, never compared as a whole: `variable_data` is JSONB, and
     * PostgreSQL does not keep a JSON object's keys in the order they arrived.
     * An order-sensitive comparison passes on SQLite and fails on the real
     * engine (it did, once), and key order means nothing here — a field is
     * looked up by its path.
     *
     * @return array<string, string>
     */
    private function storedValues(int $documentId): array
    {
        $row = $this->documents->findById($documentId, self::TENANT);
        self::assertNotNull($row);
        $rows = $row['variable_data'];
        self::assertIsArray($rows);
        self::assertCount(1, $rows, 'a document from a template is raised with exactly one row of values');
        self::assertIsArray($rows[0]);

        /** @var array<string, string> */
        return $rows[0];
    }

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
     */
    private function call(string $method, int $actorId, array $params): Response
    {
        TenantContext::reset();
        TenantContext::setTenantId(self::TENANT);
        $req = new Request('GET', '/api/documents', [], '');
        $req->user = (object) ['profile_id' => $actorId, 'active_tenant_id' => self::TENANT];

        return match ($method) {
            'list'     => $this->handler->list($req),
            'show'     => $this->handler->show($req, $params),
            'content'  => $this->handler->content($req, $params),
            'rerender' => $this->handler->rerender($req, $params),
            default    => throw new \InvalidArgumentException("Unknown route method: {$method}"),
        };
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
        $pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'Example Bakery', 'example-bakery')");
        $pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (2, 'Example Clinic', 'example-clinic')");
        $pdo->exec("INSERT INTO organizational_units (id, tenant_id, name, slug, created_at)
                    VALUES (7, 1, 'Faculty', 'faculty', datetime('now'))");

        // admin role (1) is seeded and granted documents:* by migrations 060/109.
        $pdo->exec("INSERT OR IGNORE INTO roles (id, name, description, tenant_id, created_at)
                    VALUES (1, 'admin', '', NULL, datetime('now'))");
        $pdo->exec("INSERT INTO roles (id, name, description, tenant_id, created_at) VALUES
            (101, 'secretary', '', 1, datetime('now')),
            (102, 'registry-officer', '', 1, datetime('now'))");

        // Mirrors the demo fixture's own grants (DemoOrganisationSeeder): the
        // secretary may write templates and NOT render, the registry officer may
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
