<?php

declare(strict_types=1);

namespace Tests\Api;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\DocumentQrApiHandler;
use Whity\Api\DocumentVerificationApiHandler;
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
use Whity\Core\Document\Qr\DocumentQrScanRepository;
use Whity\Core\Document\Qr\DocumentQrService;
use Whity\Core\Document\Qr\DocumentQrTokenRepository;
use Whity\Core\Document\Render\DocumentRenderer;
use Whity\Core\Document\Routing\RouteEventRepository;
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
use Whity\Core\Store\DatabaseSharedStore;
use Whity\Core\Tenant\TenantContext;
use Whity\Database\Database;
use Whity\Sdk\Http\Response;
use Whity\Storage\LocalStorageDriver;
use Tests\Support\FakeRenderServiceClient;

/**
 * POSSESSING A VALID QR TOKEN GRANTS NOTHING (#1036).
 *
 * This is the test the whole feature stands or falls on. A QR printed on paper
 * is a bearer token in the physical world — anybody who photographs the sheet
 * holds it permanently — so if holding it conferred access, photographing a
 * document would be privilege escalation and the RBAC model would be bypassed by
 * a photocopier.
 *
 * The claim under test, stated so it can be falsified:
 *
 *   1. An ANONYMOUS holder gets the public verification payload and NOTHING that
 *      identifies the record — no id, no title, no content.
 *   2. A SIGNED-IN holder with no reach gets a 404 from the scan-through route,
 *      byte-for-byte the same refusal `GET /api/documents/{id}` already gives
 *      them, so the token told them nothing the public page had not.
 *   3. That refusal is DocumentVisibilityPolicy refusing, not some other rule
 *      that happens to fire first — proven by the same caller succeeding once
 *      the policy would admit them, and by the holder of the document succeeding
 *      through the identical call.
 *   4. A refused caller writes NOTHING. A scan row for a document they cannot
 *      see would be a way to touch a tenant's trail from outside it.
 *
 * HOW TO CHECK THIS TEST CAN ACTUALLY FAIL — and it was checked this way before
 * being committed, because a permission test whose subject is inert is a green
 * check that proves nothing:
 *
 *   In {@see DocumentQrApiHandler::resolveToken()}, delete the
 *   `|| !$this->visibility->canView(...)` clause from the refusal condition —
 *   i.e. let a resolved token through on the strength of the token alone.
 *   {@see testAHolderWithNoReachIsRefusedTheRecord} and
 *   {@see testARefusedHolderWritesNothingToTheScanTrail} both go red.
 *   Restoring the clause turns them green again.
 *
 * WHAT IS NOT UNDER TEST HERE. Whether the public payload is the RIGHT set of
 * facts is {@see DocumentVerificationApiRealEngineTest}'s subject; this file
 * only cares that none of them names the record.
 */
final class DocumentQrTokenGrantsNothingTest extends TestCase
{
    private const TENANT = 1;

    /** admin in tenant 1: raises the document, so `canView` admits them as its creator. */
    private const OWNER = 10;

    /**
     * Holds `documents:read` — so the ROUTE gate would admit them — and nothing
     * else. No `documents:read:all`, they raised nothing, no route reached them,
     * and no resource role names them, so {@see DocumentVisibilityPolicy} is the
     * ONLY thing that refuses them. That is what keeps the subject live: a
     * caller refused earlier by a different rule would make this file assert
     * something it is not testing.
     */
    private const OUTSIDER = 13;

    private PDO $pdo;
    private SettingsService $settings;
    private DocumentsApiHandler $documents;
    private DocumentQrApiHandler $qrHandler;
    private DocumentVerificationApiHandler $publicHandler;
    private DocumentQrService $qr;
    private DocumentTemplateRepository $templates;
    private string $storageRoot;

    protected function setUp(): void
    {
        RoleChecker::clearCache();
        $this->pdo = $this->makeSchema();
        $db = $this->wrapSqlite($this->pdo);

        $this->storageRoot = sys_get_temp_dir() . '/whity-qrgrant-' . bin2hex(random_bytes(6));

        $this->templates = new DocumentTemplateRepository($this->pdo);
        $documentRepo = new DocumentRepository($this->pdo);
        $artifactRepo = new DocumentArtifactRepository($this->pdo);
        $this->settings = new SettingsService(
            new GlobalSettingsRepository($this->pdo),
            new TenantSettingsRepository($this->pdo)
        );

        $store = new DocumentArtifactStore(new LocalStorageDriver($this->storageRoot));
        $renderer = new DocumentRenderer(
            new DocumentBlockRepository($this->pdo),
            $this->settings,
            new FakeRenderServiceClient()
        );
        $issuer = new DocumentIssuer($this->pdo, $documentRepo, $artifactRepo, $store);
        $roleChecker = new RoleChecker($db, new PermissionRegistry());
        $visibility = new DocumentVisibilityPolicy(
            new RouteRecipientRepository($this->pdo),
            new ResourceRoleAssignmentRepository($this->pdo, new ResourceTypeRegistry())
        );
        $ouReach = new OuReachResolver(
            $this->pdo,
            new ResourceRoleAssignmentRepository($this->pdo, new ResourceTypeRegistry())
        );

        $substrates = new DocumentSubstrateRegistry(new PdoSchemaPresence($this->pdo));
        CoreDocumentSubstrates::registerInto($substrates);
        $views = new DocumentViewRegistry($substrates);
        CoreDocumentViews::registerInto($views);

        $tokenRepo = new DocumentQrTokenRepository($this->pdo);
        $scanRepo = new DocumentQrScanRepository($this->pdo);
        $this->qr = new DocumentQrService($this->pdo, $tokenRepo, $scanRepo, 'https://docs.example.test');

        $this->documents = new DocumentsApiHandler(
            $documentRepo,
            $artifactRepo,
            $store,
            $visibility,
            $this->templates,
            new DocumentAccessPolicy(),
            $renderer,
            $issuer,
            $roleChecker,
            $this->settings,
            $views,
            $substrates,
            new DocumentCollectionRepository($this->pdo),
            $this->pdo,
            $ouReach,
            null,
            null,
            null,
            $this->qr,
        );

        $this->qrHandler = new DocumentQrApiHandler(
            $documentRepo,
            $this->templates,
            $visibility,
            $this->qr,
            $scanRepo,
            $roleChecker,
            $this->settings,
        );

        $this->publicHandler = new DocumentVerificationApiHandler(
            $this->qr,
            $documentRepo,
            new RouteEventRepository($this->pdo),
            $this->settings,
            new DatabaseSharedStore($this->pdo),
        );

        $this->settings->setGlobal(SettingsRegistry::DOCUMENTS_QR_ENABLED, 'true');
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
        RoleChecker::clearCache();
        self::removeTree($this->storageRoot);
    }

    // ── 1. the anonymous holder ──────────────────────────────────────────────

    /**
     * The public payload confirms the document and names nothing that could
     * find it.
     *
     * The absences are asserted against the RAW BODY rather than against a list
     * of keys I would have written from the same understanding that produced the
     * handler. A key list would pass if a future change nested the id one level
     * deeper; a substring search over the serialised response would not.
     */
    public function testAnAnonymousHolderLearnsNothingThatIdentifiesTheRecord(): void
    {
        $documentId = $this->raise(self::OWNER, 'Disciplinary decision 44/2026');
        $token = $this->tokenFor($documentId);

        $response = $this->publicHandler->verify($this->anonymousRequest($token), ['token' => $token]);
        $body = $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue(self::data($response)['verified'], 'fixture: a live code must verify');

        // The id, as a JSON value and as a bare number in any field.
        self::assertStringNotContainsString('"id"', $body);
        self::assertStringNotContainsString('document_id', $body);
        // The title is CONTENT. A public page that printed
        // "Disciplinary decision 44/2026" would disclose more than the paper's
        // own existence, to somebody who may have found the paper in a bin.
        self::assertStringNotContainsString('Disciplinary decision', $body);
        // The token itself is never echoed back — only the short reference is,
        // and the reference is a prefix of a value the caller already holds.
        self::assertStringNotContainsString($token, $body);
    }

    // ── 2 and 3. the signed-in holder with no reach ──────────────────────────

    /**
     * A signed-in caller holding the token, with no reach, is refused the record
     * — and refused IDENTICALLY to the way the plain id route refuses them.
     *
     * The expected refusal is not a literal I typed. It is taken from
     * `GET /api/documents/{id}`, the route that already decides this question,
     * so this asserts the two agree rather than asserting that each matches my
     * own idea of what they should say. If the id route's refusal ever changes,
     * this test demands the scan-through change with it — which is the property
     * that matters: a token must not be a way to tell two refusals apart.
     */
    public function testAHolderWithNoReachIsRefusedTheRecord(): void
    {
        $documentId = $this->raise(self::OWNER, 'Minutes');
        $token = $this->tokenFor($documentId);

        self::assertTrue(
            $this->hasPermission(self::OUTSIDER, CorePermissions::DOCUMENTS_READ),
            'fixture: the outsider must clear the ROUTE gate, so the row policy is the only refuser'
        );

        $byId = $this->call($this->documents->show(...), self::OUTSIDER, ['id' => (string) $documentId]);
        $byToken = $this->call($this->qrHandler->resolveToken(...), self::OUTSIDER, ['token' => $token]);

        self::assertSame(404, $byId->getStatusCode(), 'fixture: the id route already refuses this caller');
        self::assertSame($byId->getStatusCode(), $byToken->getStatusCode());
        self::assertSame($byId->getBody(), $byToken->getBody());
    }

    /**
     * The same call, by a caller the policy admits, succeeds — so the refusal
     * above is the POLICY refusing rather than the route being broken.
     *
     * Without this, weakening the policy check would leave the previous test
     * green if anything else in the path happened to 404, and the file would be
     * asserting nothing. This is the half that keeps the subject alive.
     */
    public function testTheSameScanThroughSucceedsForACallerThePolicyAdmits(): void
    {
        $documentId = $this->raise(self::OWNER, 'Minutes');
        $token = $this->tokenFor($documentId);

        $response = $this->call($this->qrHandler->resolveToken(...), self::OWNER, ['token' => $token]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame($documentId, self::data($response)['id']);
        self::assertTrue(self::data($response)['code_honoured']);
    }

    /**
     * The panel is refused to the same caller, with the same 404 — the token is
     * not a way in through a second door either.
     */
    public function testAHolderWithNoReachIsRefusedTheCodePanel(): void
    {
        $documentId = $this->raise(self::OWNER, 'Minutes');
        $this->tokenFor($documentId);

        $response = $this->call($this->qrHandler->show(...), self::OUTSIDER, ['id' => (string) $documentId]);

        self::assertSame(404, $response->getStatusCode());
    }

    // ── 4. a refused caller writes nothing ───────────────────────────────────

    /**
     * A caller the policy refuses leaves no trace on the tenant's scan trail.
     *
     * Recording the attempt would sound prudent and would be a hole: the scan
     * trail is tenant data, and a stranger with a photograph would be able to
     * write to it, one row per request, about a document they cannot see.
     */
    public function testARefusedHolderWritesNothingToTheScanTrail(): void
    {
        $documentId = $this->raise(self::OWNER, 'Minutes');
        $token = $this->tokenFor($documentId);

        $before = $this->scanCount($documentId);
        $this->call($this->qrHandler->resolveToken(...), self::OUTSIDER, ['token' => $token]);

        self::assertSame($before, $this->scanCount($documentId));
    }

    /** ...while an admitted caller's scan IS recorded, against their own id. */
    public function testAnAdmittedHoldersScanIsRecordedAgainstThem(): void
    {
        $documentId = $this->raise(self::OWNER, 'Minutes');
        $token = $this->tokenFor($documentId);

        $this->call($this->qrHandler->resolveToken(...), self::OWNER, ['token' => $token]);

        $statement = $this->pdo->query(
            'SELECT scanner_profile_id, outcome FROM document_qr_scans ORDER BY id DESC LIMIT 1'
        );
        self::assertNotFalse($statement);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        self::assertIsArray($row);
        self::assertSame(self::OWNER, (int) $row['scanner_profile_id']);
        self::assertSame('verified', $row['outcome']);
    }

    /**
     * A code minted in ANOTHER tenant collapses into the same 404.
     *
     * Cross-tenant is the one refusal that would be tempting to report
     * differently ("wrong tenant"), and doing so would tell a caller that a
     * token they hold is real somewhere.
     */
    public function testACodeFromAnotherTenantIsRefusedIdentically(): void
    {
        $documentId = $this->raise(self::OWNER, 'Minutes');
        $token = $this->tokenFor($documentId);

        // The same, live, valid token — asked for by a caller acting in tenant 2.
        TenantContext::reset();
        TenantContext::setTenantId(2);
        $request = new Request('GET', '/api/documents/by-verification/' . $token, [], '');
        $request->user = (object) ['profile_id' => self::OWNER, 'active_tenant_id' => 2];

        $response = $this->qrHandler->resolveToken($request, ['token' => $token]);

        self::assertSame(404, $response->getStatusCode());
        self::assertStringContainsString('Document not found', $response->getBody());
    }

    // ── fixtures ─────────────────────────────────────────────────────────────

    private function raise(int $actorId, string $title): int
    {
        $templateId = $this->createTemplate($actorId, $title . ' Template');

        TenantContext::reset();
        TenantContext::setTenantId(self::TENANT);
        $request = new Request(
            'POST',
            '/api/documents',
            [],
            (string) json_encode(['document_template_id' => $templateId, 'title' => $title, 'render' => false])
        );
        $request->user = (object) ['profile_id' => $actorId, 'active_tenant_id' => self::TENANT];

        $response = $this->documents->create($request);
        self::assertSame(201, $response->getStatusCode(), 'fixture setup: raising the document must succeed');

        return (int) self::data($response)['id'];
    }

    private function createTemplate(int $actorId, string $name): int
    {
        return $this->templates->create(self::TENANT, [
            'name' => $name,
            'data' => [
                'version' => 2,
                'name' => $name,
                'page' => ['widthMm' => 210, 'heightMm' => 297, 'marginMm' => 10, 'background' => '#fff'],
                'placeholders' => [],
                'pages' => [['id' => 'p1', 'elements' => []]],
            ],
            'scope' => 'tenant',
            'required_permission' => null,
            'is_system' => false,
            'created_by' => $actorId,
            'owner_ou_id' => null,
        ]);
    }

    /** The live code for a document — minted by `create()`, read back here. */
    private function tokenFor(int $documentId): string
    {
        $token = $this->qr->active(self::TENANT, $documentId);
        self::assertIsArray($token, 'fixture: creating a document with QR on must mint a code');

        return (string) $token['token'];
    }

    private function scanCount(int $documentId): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM document_qr_scans WHERE tenant_id = :tenant_id AND document_id = :document_id'
        );
        $statement->execute([':tenant_id' => self::TENANT, ':document_id' => $documentId]);

        return (int) $statement->fetchColumn();
    }

    private function hasPermission(int $profileId, string $permission): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*)
               FROM memberships m
               JOIN role_permissions rp ON rp.role_id = m.role_id
               JOIN permissions p ON p.id = rp.permission_id
              WHERE m.profile_id = :profile_id AND m.tenant_id = :tenant_id AND p.name = :permission'
        );
        $statement->execute([
            ':profile_id' => $profileId,
            ':tenant_id' => self::TENANT,
            ':permission' => $permission,
        ]);

        return (int) $statement->fetchColumn() > 0;
    }

    /**
     * @param callable(Request, array<string, string>): Response $handler
     * @param array<string, string> $params
     */
    private function call(callable $handler, int $actorId, array $params): Response
    {
        TenantContext::reset();
        TenantContext::setTenantId(self::TENANT);
        $request = new Request('GET', '/api/documents', [], '');
        $request->user = (object) ['profile_id' => $actorId, 'active_tenant_id' => self::TENANT];

        return $handler($request, $params);
    }

    /**
     * A request with NO user and NO tenant context, which is what the public
     * route actually receives — the middleware never resolves either for it.
     */
    private function anonymousRequest(string $token): Request
    {
        TenantContext::reset();

        return new Request(
            'GET',
            '/api/v1/document-verifications/' . $token,
            ['REMOTE_ADDR' => '203.0.113.9'],
            ''
        );
    }

    private function makeSchema(): PDO
    {
        $pdo = SchemaFromMigrations::make(true);
        $pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'Ministry of Records', 'ministry')");
        $pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (2, 'Other', 'other')");

        $pdo->exec("INSERT OR IGNORE INTO roles (id, name, description, tenant_id, created_at)
                    VALUES (1, 'admin', '', NULL, CURRENT_TIMESTAMP)");
        $pdo->exec("INSERT INTO roles (id, name, description, tenant_id, created_at)
                    VALUES (103, 'outsider', '', 1, CURRENT_TIMESTAMP)");

        // documents:read ONLY. Not read:all — that is the grant that would make
        // the outsider visible-by-permission and quietly turn this whole file
        // into a test of nothing.
        $this->grant($pdo, 103, CorePermissions::DOCUMENTS_READ);
        $this->grant($pdo, 103, CorePermissions::DOCUMENTS_RENDER);

        $pdo->exec("
            INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at) VALUES
                (10, 'owner',    'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
                (13, 'outsider', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ");
        $pdo->exec("
            INSERT INTO memberships (id, profile_id, tenant_id, role_id, ou_id, is_primary, status, created_at) VALUES
                (1000, 10, 1, 1,   NULL, true, 'active', CURRENT_TIMESTAMP),
                (1003, 13, 1, 103, NULL, true, 'active', CURRENT_TIMESTAMP)
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
    private static function data(Response $response): array
    {
        $decoded = json_decode($response->getBody(), true);
        self::assertIsArray($decoded);
        $data = $decoded['data'] ?? null;
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
