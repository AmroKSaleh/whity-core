<?php

declare(strict_types=1);

namespace Tests\Api;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeRenderServiceClient;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\DocumentRenderApiHandler;
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
use Whity\Core\Document\Routing\RouteEventRepository;
use Whity\Core\Document\Routing\RouteRecipientRepository;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\Ou\OuReachResolver;
use Whity\Core\RBAC\ResourceRoleAssignmentRepository;
use Whity\Core\RBAC\ResourceTypeRegistry;
use Whity\Core\RBAC\RecordSectionResolver;
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
 * The document record page's per-region verdicts (#993, applying #910/#975).
 *
 * `GET /api/documents/{id}` now carries a `sections` map so the record page can
 * render three regions in three states resolved SERVER-SIDE. The invariants
 * below are the ones {@see RoleRecordSectionsRealEngineTest} locks in for roles,
 * restated for the facts that differ here — a document is immutable, so its
 * regions are governed by three INDEPENDENT record predicates rather than by one
 * manageability flag, and that is where a mistake would hide.
 *
 * WHAT IS DELIBERATELY NOT ASSERTED HERE. The five roles invariants include "a
 * write to a read-only region is refused rather than dropped". A document has no
 * PATCH: `document`'s write is `POST /{id}/render`, which is separately gated on
 * `documents:render` by the route table and re-checks the template and both
 * settings itself — {@see DocumentsApiHandlerRealEngineTest} covers that path,
 * and {@see DocumentsApiHandler::documentIsReissuable()} asks the same three
 * questions in advance precisely so the verdict and the route cannot disagree.
 * The two other regions have no write on this endpoint at all. So there is no
 * "renders but does not enforce" gap to test for; there is only the reverse
 * risk, that the verdict promises something the route refuses, which is what
 * {@see self::testTheDocumentRegionIsRefusedByTheRECORDWhenItsTemplateIsGone()}
 * and its re-render counterpart together pin down.
 *
 * Routing rows are INSERTED directly rather than issued through
 * {@see \Whity\Core\Document\Routing\DocumentRouter}. The subject here is what a
 * verdict says about a given set of rows; wiring the rule registry and its
 * resolvers would test the router's own behaviour a second time and would make
 * these assertions depend on which rules happen to resolve to which people.
 */
final class DocumentRecordSectionsRealEngineTest extends TestCase
{
    private const TENANT = 1;

    /** admin in tenant 1: documents:* plus permissions:read (migrations 060/109). */
    private const OWNER = 10;
    /** documents:read + documents:read:all + documents:render, NO permissions:read. */
    private const RENDERER = 11;
    /** documents:read + documents:read:all only — can see the document, cannot re-issue it. */
    private const READER = 12;

    private PDO $pdo;
    private SettingsService $settingsService;
    private DocumentTemplateRepository $templateRepo;
    private DocumentsApiHandler $handler;
    /** The same handler MINUS the resolver and the two routing reads. */
    private DocumentsApiHandler $unwired;
    private DocumentRenderApiHandler $renderHandler;
    private string $storageRoot;

    protected function setUp(): void
    {
        RoleChecker::clearCache();
        $this->pdo = $this->makeSchema();
        $db = $this->wrapSqlite($this->pdo);

        $this->storageRoot = sys_get_temp_dir() . '/whity-docsections-' . bin2hex(random_bytes(6));
        $this->templateRepo = new DocumentTemplateRepository($this->pdo);
        $documentRepo = new DocumentRepository($this->pdo);
        $artifactRepo = new DocumentArtifactRepository($this->pdo);
        $this->settingsService = new SettingsService(
            new GlobalSettingsRepository($this->pdo),
            new TenantSettingsRepository($this->pdo)
        );

        $store = new DocumentArtifactStore(new LocalStorageDriver($this->storageRoot));
        $renderer = new DocumentRenderer(
            new DocumentBlockRepository($this->pdo),
            $this->settingsService,
            new FakeRenderServiceClient()
        );
        $issuer = new DocumentIssuer($this->pdo, $documentRepo, $artifactRepo, $store);
        $roleChecker = new RoleChecker($db, new PermissionRegistry());
        $policy = new DocumentAccessPolicy();
        $visibility = new DocumentVisibilityPolicy(
            new RouteRecipientRepository($this->pdo),
            new ResourceRoleAssignmentRepository($this->pdo, new ResourceTypeRegistry())
        );

        $substrates = new DocumentSubstrateRegistry(new PdoSchemaPresence($this->pdo));
        CoreDocumentSubstrates::registerInto($substrates);
        $views = new DocumentViewRegistry($substrates);
        CoreDocumentViews::registerInto($views);

        // Migration 117's template reach predicate. REQUIRED since #1004, and
        // shared rather than per-handler: the issue path reads a template too, so
        // withholding one filed at a unit the caller has no standing at must hold
        // on both, or the record page becomes a way around the designer's list.
        $ouReach = new OuReachResolver(
            $this->pdo,
            new ResourceRoleAssignmentRepository($this->pdo, new ResourceTypeRegistry())
        );

        // The fifteen shared arguments, so the two handlers below differ ONLY in
        // the three this PR adds. A hand-written second construction would let
        // the "unwired" case drift into differing for some other reason.
        $shared = [
            $documentRepo,
            $artifactRepo,
            $store,
            $visibility,
            $this->templateRepo,
            $policy,
            $renderer,
            $issuer,
            $roleChecker,
            $this->settingsService,
            $views,
            $substrates,
            new DocumentCollectionRepository($this->pdo),
            $this->pdo,
            $ouReach,
        ];

        $this->handler = new DocumentsApiHandler(
            ...[
                ...$shared,
                new RecordSectionResolver($roleChecker),
                new RouteEventRepository($this->pdo),
                new RouteRecipientRepository($this->pdo),
            ]
        );
        $this->unwired = new DocumentsApiHandler(...$shared);

        $this->renderHandler = new DocumentRenderApiHandler(
            $this->templateRepo,
            $policy,
            $roleChecker,
            $this->settingsService,
            $renderer,
            $issuer,
            $ouReach
        );

        // Both must be on for a document to be re-issuable at all; the record
        // predicate reads exactly these two, so the happy path needs both set.
        $this->settingsService->setGlobal(SettingsRegistry::DOCUMENTS_RENDER_ENABLED, 'true');
        $this->settingsService->setGlobal(SettingsRegistry::DOCUMENTS_PERSIST_ENABLED, 'true');
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
        RoleChecker::clearCache();
        self::removeTree($this->storageRoot);
    }

    // ── the shape of the map ─────────────────────────────────────────────────

    /**
     * All three regions are reported, and `document` is the only one a
     * permission can refuse.
     *
     * The read gates are all null on purpose (see
     * {@see DocumentsApiHandler::recordSections()}), so a caller who received a
     * payload at all is entitled to every region. Asserting the exact key SET
     * rather than three separate `assertArrayHasKey`s is deliberate: a fourth
     * region added without a test is the thing that would slip through.
     */
    public function testAllThreeRegionsAreReportedToACallerWhoCanSeeTheDocument(): void
    {
        $documentId = $this->issue(self::OWNER, 'Minutes');

        $sections = $this->sections(self::OWNER, $documentId);

        self::assertSame(['document', 'trail', 'recipients'], array_keys($sections));
    }

    /** The list route carries no verdicts — a verdict is about ONE record. */
    public function testTheLISTRouteCarriesNoVerdicts(): void
    {
        $this->issue(self::OWNER, 'Minutes');

        TenantContext::reset();
        TenantContext::setTenantId(self::TENANT);
        $request = new Request('GET', '/api/documents', [], '');
        $request->user = (object) ['profile_id' => self::OWNER, 'active_tenant_id' => self::TENANT];

        $rows = self::decode($this->handler->list($request))['data'] ?? null;
        self::assertIsArray($rows);
        self::assertNotSame([], $rows, 'fixture: the list must return the issued document');
        foreach ($rows as $row) {
            self::assertIsArray($row);
            self::assertArrayNotHasKey('sections', $row);
        }
    }

    /**
     * A host with no resolver reports NO `sections` key at all.
     *
     * The distinction the client depends on: `null` means "this host makes no
     * region claims" and the record renders as it did before #993, while an
     * empty MAP would mean "regions were resolved and you were granted none".
     * A client that read the two the same way would render a full page for a
     * half-wired deployment.
     */
    public function testAHostWithNoResolverReportsNoSectionsAtAll(): void
    {
        $documentId = $this->issue(self::OWNER, 'Minutes');

        $data = self::data($this->callOn($this->unwired, self::OWNER, $documentId));

        self::assertArrayNotHasKey('sections', $data);
    }

    // ── the `document` region: the one write an immutable record has ─────────

    public function testTheDocumentRegionIsEditableForARenderHolder(): void
    {
        $documentId = $this->issue(self::OWNER, 'Minutes');

        $sections = $this->sections(self::RENDERER, $documentId);

        self::assertSame('editable', $sections['document']['state']);
        self::assertNull($sections['document']['denial']);
    }

    public function testTheDocumentRegionIsRefusedByPERMISSIONWithoutRender(): void
    {
        $documentId = $this->issue(self::OWNER, 'Minutes');

        $sections = $this->sections(self::READER, $documentId);

        self::assertSame('read-only', $sections['document']['state']);
        self::assertSame(RecordSectionResolver::CODE_PERMISSION, $sections['document']['denial']['code']);
        // Audience-safe prose that names no slug. The slug lives in `detail`,
        // which this caller does not hold `permissions:read` for.
        self::assertStringNotContainsString('documents:render', $sections['document']['denial']['reason']);
    }

    /**
     * A document whose template is gone is refused by the RECORD, not by a
     * permission — even for a caller who holds `documents:render`.
     *
     * This is the pair of codes earning their separation. An operator told "you
     * lack a permission" here would go looking for a grant that could not have
     * helped: `POST /{id}/render` answers 409 for this document no matter who
     * asks, because there is nothing left to render from.
     */
    public function testTheDocumentRegionIsRefusedByTheRECORDWhenItsTemplateIsGone(): void
    {
        $templateId = $this->createTemplate(self::OWNER, 'Doomed Template');
        $documentId = $this->issueFromTemplate(self::OWNER, $templateId, 'Minutes');
        $this->templateRepo->delete($templateId, self::TENANT);

        $sections = $this->sections(self::RENDERER, $documentId);

        self::assertSame('read-only', $sections['document']['state']);
        self::assertSame(RecordSectionResolver::CODE_RECORD, $sections['document']['denial']['code']);
        // Nothing operator-grade to add: a record refusal is not fixable by a
        // grant, so there is no slug that would help anyone reading it.
        self::assertNull($sections['document']['denial']['detail']);
    }

    /** Rendering turned off refuses the region the same way, and for the record. */
    public function testTheDocumentRegionIsRefusedByTheRECORDWhenRenderingIsOff(): void
    {
        $documentId = $this->issue(self::OWNER, 'Minutes');
        $this->settingsService->setGlobal(SettingsRegistry::DOCUMENTS_RENDER_ENABLED, 'false');

        $sections = $this->sections(self::RENDERER, $documentId);

        self::assertSame('read-only', $sections['document']['state']);
        self::assertSame(RecordSectionResolver::CODE_RECORD, $sections['document']['denial']['code']);
    }

    // ── the `trail` region: the empty state the page must not fake ───────────

    /**
     * A document nobody has circulated is refused by the RECORD, with a sentence
     * saying so.
     *
     * The point of the whole region. A trail rendered as an empty list would
     * state *"nothing has happened to this document"* — which is unfalsifiable
     * from the outside and pixel-identical to a trail that failed to load. The
     * verdict is what lets the page say "not circulated" instead (#756, #951).
     */
    public function testTheTrailRegionIsRefusedByTheRECORDBeforeAnythingHasHappened(): void
    {
        $documentId = $this->issue(self::OWNER, 'Minutes');

        $sections = $this->sections(self::OWNER, $documentId);

        self::assertSame('read-only', $sections['trail']['state']);
        self::assertSame(RecordSectionResolver::CODE_RECORD, $sections['trail']['denial']['code']);
        self::assertStringContainsString('not been put into circulation', $sections['trail']['denial']['reason']);
    }

    public function testTheTrailRegionBecomesEditableOnceAnEventExists(): void
    {
        $documentId = $this->issue(self::OWNER, 'Minutes');
        $routeId = $this->openRoute($documentId);
        $this->appendEvent($documentId, $routeId, self::OWNER, 'issued');

        $sections = $this->sections(self::OWNER, $documentId);

        self::assertSame('editable', $sections['trail']['state']);
    }

    /**
     * Appending to a trail is not permission-gated, and the verdict says so.
     *
     * `DocumentRouter::act()` handles `noted` BEFORE its recipient check, so
     * anyone who can read the document can append to its trail. A region gated
     * on `documents:route` would have hidden that from exactly the people a
     * route was built to reach.
     */
    public function testTheTrailRegionIsEditableForAReaderWhoHoldsNoRoutingPermission(): void
    {
        $documentId = $this->issue(self::OWNER, 'Minutes');
        $routeId = $this->openRoute($documentId);
        $this->appendEvent($documentId, $routeId, self::OWNER, 'issued');

        $sections = $this->sections(self::READER, $documentId);

        self::assertSame('editable', $sections['trail']['state']);
    }

    // ── the `recipients` region: open versus acted-upon ──────────────────────

    public function testTheRecipientsRegionIsRefusedWhenTheDocumentIsNotAwaitingYou(): void
    {
        $documentId = $this->issue(self::OWNER, 'Minutes');
        $routeId = $this->openRoute($documentId);
        $eventId = $this->appendEvent($documentId, $routeId, self::OWNER, 'issued');
        // Awaiting somebody ELSE.
        $this->addRecipient($documentId, $routeId, self::READER, $eventId);

        $sections = $this->sections(self::OWNER, $documentId);

        self::assertSame('read-only', $sections['recipients']['state']);
        self::assertSame(RecordSectionResolver::CODE_RECORD, $sections['recipients']['denial']['code']);
        self::assertStringContainsString('not awaiting you', $sections['recipients']['denial']['reason']);
    }

    public function testTheRecipientsRegionIsEditableForTheProfileTheDocumentAwaits(): void
    {
        $documentId = $this->issue(self::OWNER, 'Minutes');
        $routeId = $this->openRoute($documentId);
        $eventId = $this->appendEvent($documentId, $routeId, self::OWNER, 'issued');
        $this->addRecipient($documentId, $routeId, self::READER, $eventId);

        $sections = $this->sections(self::READER, $documentId);

        self::assertSame('editable', $sections['recipients']['state']);
    }

    /**
     * A CLOSED recipient row is something already done, and stops counting.
     *
     * `closed_by_event_id` is the whole open/acted-upon distinction — migration
     * 112's partial unique index is defined on it — and a predicate that counted
     * closed rows would tell a reader a document is with them long after they
     * dealt with it. That is the failure this asserts against, and it is exactly
     * the one a naive `hasAnyForProfile()` would have produced.
     */
    public function testAClosedRecipientRowNoLongerCountsAsAwaiting(): void
    {
        $documentId = $this->issue(self::OWNER, 'Minutes');
        $routeId = $this->openRoute($documentId);
        $issuedEvent = $this->appendEvent($documentId, $routeId, self::OWNER, 'issued');
        $recipientId = $this->addRecipient($documentId, $routeId, self::READER, $issuedEvent);

        self::assertSame(
            'editable',
            $this->sections(self::READER, $documentId)['recipients']['state'],
            'fixture: the row must start open'
        );

        $ack = $this->appendEvent($documentId, $routeId, self::READER, 'acknowledged');
        $this->pdo->prepare('UPDATE document_route_recipients SET closed_by_event_id = ? WHERE id = ?')
            ->execute([$ack, $recipientId]);

        self::assertSame('read-only', $this->sections(self::READER, $documentId)['recipients']['state']);
    }

    // ── who may read the operator-grade half ─────────────────────────────────

    /**
     * `detail` names a permission slug, so it goes only to a caller the SERVER
     * decided may read one — `permissions:read`, the permission that governs
     * seeing slugs at all.
     *
     * Both directions in one test, because the risk is asymmetric and a
     * one-sided assertion passes for a build that always sends the detail as
     * well as for one that never sends it.
     */
    public function testTheSlugNamingDetailIsGatedOnPermissionsRead(): void
    {
        $documentId = $this->issue(self::OWNER, 'Minutes');

        $withoutPermissionsRead = $this->sections(self::READER, $documentId);
        self::assertNull($withoutPermissionsRead['document']['denial']['detail']);

        // The owner is admin, which holds permissions:read — but also holds
        // documents:render, so the region is editable and has no denial at all.
        // Granting the reader `permissions:read` is what isolates the variable.
        $this->grant($this->pdo, 102, CorePermissions::PERMISSIONS_READ);
        RoleChecker::clearCache();

        $withPermissionsRead = $this->sections(self::READER, $documentId);
        self::assertSame(
            "changing this requires the 'documents:render' permission",
            $withPermissionsRead['document']['denial']['detail']
        );
    }

    // ── fixtures ─────────────────────────────────────────────────────────────

    /**
     * The `sections` map for one caller and one document.
     *
     * @return array<string, array<string, mixed>>
     */
    private function sections(int $actorId, int $documentId): array
    {
        $data = self::data($this->callOn($this->handler, $actorId, $documentId));
        $sections = $data['sections'] ?? null;
        self::assertIsArray($sections, 'the wired handler must report a sections map');

        /** @var array<string, array<string, mixed>> $sections */
        return $sections;
    }

    private function callOn(DocumentsApiHandler $handler, int $actorId, int $documentId): Response
    {
        TenantContext::reset();
        TenantContext::setTenantId(self::TENANT);
        $request = new Request('GET', "/api/documents/{$documentId}", [], '');
        $request->user = (object) ['profile_id' => $actorId, 'active_tenant_id' => self::TENANT];

        return $handler->show($request, ['id' => (string) $documentId]);
    }

    private function issue(int $actorId, string $title): int
    {
        return $this->issueFromTemplate($actorId, $this->createTemplate($actorId, $title . ' Template'), $title);
    }

    private function issueFromTemplate(int $actorId, int $templateId, string $title): int
    {
        TenantContext::reset();
        TenantContext::setTenantId(self::TENANT);
        $request = new Request(
            'POST',
            "/api/document-templates/{$templateId}/render",
            [],
            (string) json_encode(['persist' => true, 'title' => $title])
        );
        $request->user = (object) ['profile_id' => $actorId, 'active_tenant_id' => self::TENANT];

        $response = $this->renderHandler->render($request, ['id' => (string) $templateId]);
        self::assertSame(201, $response->getStatusCode(), 'fixture setup: the persisted render must succeed');

        return (int) self::data($response)['id'];
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
            // Tenant-scoped, so a profile other than the raiser can still READ
            // the template — otherwise `documentIsReissuable` would be refusing
            // on the template policy and every "editable" assertion below would
            // be measuring the wrong gate.
            'scope' => 'tenant',
            'created_by' => $actorId,
        ]);
    }

    private function openRoute(int $documentId): int
    {
        $this->pdo->prepare(
            'INSERT INTO document_routes (tenant_id, document_id, title, created_by, created_at)
             VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)'
        )->execute([self::TENANT, $documentId, 'Circulation', self::OWNER]);

        return (int) $this->pdo->lastInsertId();
    }

    private function appendEvent(int $documentId, int $routeId, int $actorId, string $action): int
    {
        $this->pdo->prepare(
            'INSERT INTO document_route_events
                 (tenant_id, document_id, route_id, actor_profile_id, action, occurred_at)
             VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP)'
        )->execute([self::TENANT, $documentId, $routeId, $actorId, $action]);

        return (int) $this->pdo->lastInsertId();
    }

    /** An OPEN recipient row: `closed_by_event_id` is left null, which is what open MEANS. */
    private function addRecipient(int $documentId, int $routeId, int $profileId, int $createdByEventId): int
    {
        $this->pdo->prepare(
            'INSERT INTO document_route_steps (tenant_id, route_id, position, rule_kind, rule_config, created_at)
             VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP)'
        )->execute([self::TENANT, $routeId, 1, 'role', '{}']);
        $stepId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare(
            'INSERT INTO document_route_recipients
                 (tenant_id, document_id, route_id, step_id, profile_id, created_by_event_id, created_at)
             VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)'
        )->execute([self::TENANT, $documentId, $routeId, $stepId, $profileId, $createdByEventId]);

        return (int) $this->pdo->lastInsertId();
    }

    private function makeSchema(): PDO
    {
        $pdo = SchemaFromMigrations::make(true);
        $pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'a', 'a')");
        $pdo->exec("INSERT INTO organizational_units (id, tenant_id, name, slug, created_at)
                    VALUES (7, 1, 'Registry', 'registry', CURRENT_TIMESTAMP)");

        $pdo->exec("INSERT OR IGNORE INTO roles (id, name, description, tenant_id, created_at)
                    VALUES (1, 'admin', '', NULL, CURRENT_TIMESTAMP)");
        $pdo->exec("INSERT INTO roles (id, name, description, tenant_id, created_at) VALUES
            (101, 'renderer', '', 1, CURRENT_TIMESTAMP),
            (102, 'reader',   '', 1, CURRENT_TIMESTAMP)");

        // Both non-admin roles get read:all so the VISIBILITY policy is never
        // the thing under test here — this file is about verdicts, and a caller
        // filtered out at the record route would produce a 404 that looks like a
        // hidden region.
        foreach ([101, 102] as $roleId) {
            $this->grant($pdo, $roleId, CorePermissions::DOCUMENTS_READ);
            $this->grant($pdo, $roleId, CorePermissions::DOCUMENTS_READ_ALL);
        }
        $this->grant($pdo, 101, CorePermissions::DOCUMENTS_RENDER);

        $pdo->exec("
            INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at) VALUES
                (10, 'owner',    'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
                (11, 'renderer', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
                (12, 'reader',   'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ");
        $pdo->exec("
            INSERT INTO memberships (id, profile_id, tenant_id, role_id, ou_id, is_primary, status, created_at) VALUES
                (1000, 10, 1, 1,   7,    true, 'active', CURRENT_TIMESTAMP),
                (1001, 11, 1, 101, NULL, true, 'active', CURRENT_TIMESTAMP),
                (1002, 12, 1, 102, NULL, true, 'active', CURRENT_TIMESTAMP)
        ");

        return $pdo;
    }

    private function grant(PDO $pdo, int $roleId, string $permission): void
    {
        $pdo->prepare('INSERT OR IGNORE INTO permissions (name, description, created_at) VALUES (?, ?, NOW())')
            ->execute([$permission, '']);
        $select = $pdo->prepare('SELECT id FROM permissions WHERE name = ?');
        $select->execute([$permission]);
        $permissionId = (int) $select->fetchColumn();
        $pdo->prepare('INSERT OR IGNORE INTO role_permissions (role_id, permission_id, created_at) VALUES (?, ?, NOW())')
            ->execute([$roleId, $permissionId]);
    }

    private function wrapSqlite(PDO $pdo): Database
    {
        $db = Database::withFactory(static fn (): PDO => $pdo);
        $db->setMaxLifetimeSeconds(86400);
        $db->setPingIntervalSeconds(86400);
        $db->forceConnect();

        return $db;
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
