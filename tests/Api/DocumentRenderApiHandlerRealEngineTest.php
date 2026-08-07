<?php

declare(strict_types=1);

namespace Tests\Api;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeRenderServiceClient;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\DocumentRenderApiHandler;
use Whity\Auth\RoleChecker;
use Whity\Core\Document\DocumentAccessPolicy;
use Whity\Core\Document\DocumentBlockRepository;
use Whity\Core\Document\DocumentTemplateRepository;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\Request;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;
use Whity\Core\Tenant\TenantContext;
use Whity\Database\Database;

/**
 * Real-engine tests for {@see DocumentRenderApiHandler} (ADR 0012 /
 * WC-docdesigner Track 2):
 *
 *  - the feature-flag-off path returns a clean 503 and NEVER even attempts to
 *    call the render service (asserted via the fake client's call log);
 *  - RBAC/tenant-scoping: a caller can never render another tenant's
 *    template, nor one hidden from it by the document access policy (both
 *    404, never a 403 that would confirm existence);
 *  - the happy path calls the render service with the expected payload shape
 *    (template data, a default single sample-data row when none is supplied,
 *    resolved blockInstance references) and streams back the PDF bytes with
 *    the right Content-Type;
 *  - batch limits (max rows / max total render units / max template bytes)
 *    are enforced as 422s, sourced from settings (not hardcoded).
 *
 * The actual whity_render Docker round-trip (a real Puppeteer render
 * producing real PDF bytes) is proven separately — see
 * tests/Integration/DocumentRenderServiceDockerTest.php — since standing up a
 * throwaway Chromium-bearing container on every `phpunit` invocation would
 * make this suite slow/flaky; here the render SERVICE ITSELF is faked so the
 * handler's own logic (flag/RBAC/limits/payload-assembly) is exercised in
 * isolation.
 */
final class DocumentRenderApiHandlerRealEngineTest extends TestCase
{
    private const TENANT = 1;
    private const OTHER_TENANT = 2;

    // Seeded profiles (tenant 1 unless noted).
    private const OWNER = 10; // admin role -> documents:read/write/publish (migration 060)
    private const VIEWER = 11; // read only, no publish, no contracts tag
    private const MANAGER = 13; // read + documents:use:contracts (the gated tag)
    private const OTHER_TENANT_ADMIN = 20; // admin role, but in TENANT 2

    private const CONTRACTS_PERM = 'documents:use:contracts';

    private PDO $pdo;
    private FakeRenderServiceClient $fakeRender;
    private SettingsService $settingsService;
    private DocumentTemplateRepository $templateRepo;

    protected function setUp(): void
    {
        RoleChecker::clearCache();
        $this->pdo = $this->makeSchema();
        $db = $this->wrapSqlite($this->pdo);

        $this->templateRepo = new DocumentTemplateRepository($this->pdo);
        $this->fakeRender = new FakeRenderServiceClient();
        $this->settingsService = new SettingsService(
            new GlobalSettingsRepository($this->pdo),
            new TenantSettingsRepository($this->pdo)
        );

        $this->handler = new DocumentRenderApiHandler(
            $this->templateRepo,
            new DocumentBlockRepository($this->pdo),
            new DocumentAccessPolicy(),
            new RoleChecker($db, new PermissionRegistry()),
            $this->settingsService,
            $this->fakeRender
        );
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
        RoleChecker::clearCache();
    }

    private DocumentRenderApiHandler $handler;

    // ── feature flag ────────────────────────────────────────────────────────

    public function testFeatureFlagOffReturns503AndNeverCallsRenderService(): void
    {
        // Default is 'false' — nothing enabled here.
        $id = $this->createTemplate(self::OWNER, ['name' => 'Label', 'data' => ['version' => 2, 'placeholders' => [], 'pages' => [['id' => 'p1', 'elements' => []]]]]);

        $res = $this->render(self::OWNER, $id);

        self::assertSame(503, $res->getStatusCode());
        self::assertSame([], $this->fakeRender->calls, 'the render service must never be called while the flag is off');
    }

    public function testFeatureFlagOffEvenWhenTemplateMissingStillChecksFlagFirst(): void
    {
        // Order doesn't strictly matter for correctness, but a disabled instance
        // must 503 rather than leak a 404 that would imply the flag check ran
        // AFTER an (unnecessary) template lookup and found nothing.
        $res = $this->render(self::OWNER, 999999);

        self::assertSame(503, $res->getStatusCode());
        self::assertSame([], $this->fakeRender->calls);
    }

    // ── RBAC / tenant scoping ────────────────────────────────────────────────

    public function testCannotRenderAnotherTenantsTemplate(): void
    {
        $this->enableRendering();
        $id = $this->createTemplate(self::OWNER, ['name' => 'Label', 'data' => $this->minimalTemplateData()]);

        // Same profile id acting under a DIFFERENT tenant context must 404, not
        // see/render tenant 1's template.
        $res = $this->renderAs(self::OTHER_TENANT_ADMIN, self::OTHER_TENANT, $id);

        self::assertSame(404, $res->getStatusCode());
        self::assertSame([], $this->fakeRender->calls);
    }

    public function testCannotRenderATemplateHiddenByAccessPolicy(): void
    {
        $this->enableRendering();
        // Owner publishes a tenant-wide template gated on the contracts tag.
        $id = $this->createTemplate(self::OWNER, [
            'name' => 'Contract',
            'data' => $this->minimalTemplateData(),
            'scope' => 'tenant',
            'required_permission' => self::CONTRACTS_PERM,
        ]);

        // The viewer lacks the tag -> 404 (never 403 — never confirm existence).
        $hidden = $this->render(self::VIEWER, $id);
        self::assertSame(404, $hidden->getStatusCode());
        self::assertSame([], $this->fakeRender->calls);

        // The manager HOLDS the tag -> may render.
        $visible = $this->render(self::MANAGER, $id);
        self::assertSame(200, $visible->getStatusCode());
        self::assertCount(1, $this->fakeRender->calls);
    }

    public function testCannotRenderAPersonalTemplateBelongingToSomeoneElse(): void
    {
        $this->enableRendering();
        $id = $this->createTemplate(self::OWNER, ['name' => 'Mine', 'data' => $this->minimalTemplateData()]);

        $res = $this->render(self::VIEWER, $id);

        self::assertSame(404, $res->getStatusCode());
    }

    // ── happy path / payload shape ───────────────────────────────────────────

    public function testRenderSucceedsAndStreamsPdfBytes(): void
    {
        $this->enableRendering();
        $id = $this->createTemplate(self::OWNER, ['name' => 'My Label', 'data' => $this->minimalTemplateData()]);

        $res = $this->render(self::OWNER, $id);

        self::assertSame(200, $res->getStatusCode());
        // Response header names are stored normalised (lowercase) by the base
        // SDK Response — see HeaderUtil::normalize().
        self::assertSame('application/pdf', $res->getHeaders()['content-type'] ?? null);
        self::assertStringContainsString('my-label.pdf', $res->getHeaders()['content-disposition'] ?? '');
        self::assertStringStartsWith('%PDF-', $res->getBody());
        self::assertCount(1, $this->fakeRender->calls);
    }

    public function testDefaultDataRowsFallsBackToPlaceholderSamples(): void
    {
        $this->enableRendering();
        $data = $this->minimalTemplateData();
        $data['placeholders'] = [['key' => 'sku', 'label' => 'SKU', 'sample' => 'ABC-123']];
        $id = $this->createTemplate(self::OWNER, ['name' => 'Label', 'data' => $data]);

        $res = $this->render(self::OWNER, $id);

        self::assertSame(200, $res->getStatusCode());
        $payload = $this->fakeRender->calls[0];
        self::assertSame([['sku' => 'ABC-123']], $payload['dataRows']);
    }

    /**
     * A template that binds NO placeholders defaults to one EMPTY data row.
     * An empty PHP array json_encode()s as a JSON ARRAY (`[]`), not the `{}`
     * object the render harness's per-row `Record<string, string>` shape
     * needs — regression coverage for exactly that ambiguity (caught by the
     * real Docker round-trip test, which rejected `[[]]` as a malformed row
     * shape before this was fixed).
     */
    public function testEmptyDefaultDataRowSerialisesAsAJsonObjectNotArray(): void
    {
        $this->enableRendering();
        // minimalTemplateData() already has empty placeholders.
        $id = $this->createTemplate(self::OWNER, ['name' => 'Label', 'data' => $this->minimalTemplateData()]);

        $res = $this->render(self::OWNER, $id);

        self::assertSame(200, $res->getStatusCode());
        $payload = $this->fakeRender->calls[0];
        self::assertCount(1, $payload['dataRows']);
        self::assertInstanceOf(\stdClass::class, $payload['dataRows'][0]);
        self::assertSame('[{}]', json_encode($payload['dataRows']));
    }

    public function testExplicitDataRowsArePassedThrough(): void
    {
        $this->enableRendering();
        $id = $this->createTemplate(self::OWNER, ['name' => 'Label', 'data' => $this->minimalTemplateData()]);

        $res = $this->render(self::OWNER, $id, ['dataRows' => [['sku' => 'A'], ['sku' => 'B']]]);

        self::assertSame(200, $res->getStatusCode());
        self::assertSame([['sku' => 'A'], ['sku' => 'B']], $this->fakeRender->calls[0]['dataRows']);
    }

    public function testBlockInstanceReferencesAreResolvedIntoThePayload(): void
    {
        $this->enableRendering();
        $blockRepo = new DocumentBlockRepository($this->pdo);
        $blockId = $blockRepo->create(self::TENANT, [
            'name' => 'Header',
            'data' => [['id' => 'el1', 'type' => 'text', 'x' => 0, 'y' => 0, 'w' => 10, 'h' => 10, 'rotation' => 0, 'z' => 1, 'text' => 'Hi', 'style' => []]],
        ]);

        $data = $this->minimalTemplateData();
        $data['pages'][0]['elements'] = [
            ['id' => 'inst1', 'type' => 'blockInstance', 'x' => 0, 'y' => 0, 'w' => 10, 'h' => 10, 'rotation' => 0, 'z' => 1, 'blockId' => (string) $blockId],
        ];
        $id = $this->createTemplate(self::OWNER, ['name' => 'WithBlock', 'data' => $data]);

        $res = $this->render(self::OWNER, $id);

        self::assertSame(200, $res->getStatusCode());
        $blocks = $this->fakeRender->calls[0]['blocks'];
        self::assertArrayHasKey((string) $blockId, (array) $blocks);
    }

    // ── batch limits ─────────────────────────────────────────────────────────

    public function testTooManyDataRowsIsRejectedWith422(): void
    {
        $this->enableRendering();
        $this->settingsService->setGlobal(SettingsRegistry::DOCUMENTS_RENDER_MAX_ROWS, '2');
        $id = $this->createTemplate(self::OWNER, ['name' => 'Label', 'data' => $this->minimalTemplateData()]);

        $res = $this->render(self::OWNER, $id, ['dataRows' => [['a' => '1'], ['a' => '2'], ['a' => '3']]]);

        self::assertSame(422, $res->getStatusCode());
        self::assertSame([], $this->fakeRender->calls);
    }

    public function testTooManyTotalUnitsIsRejectedWith422(): void
    {
        $this->enableRendering();
        $this->settingsService->setGlobal(SettingsRegistry::DOCUMENTS_RENDER_MAX_PAGES, '2');
        $data = $this->minimalTemplateData();
        // 2 pages per row x 2 rows = 4 units > the max of 2.
        $data['pages'][] = ['id' => 'p2', 'elements' => []];
        $id = $this->createTemplate(self::OWNER, ['name' => 'Label', 'data' => $data]);

        $res = $this->render(self::OWNER, $id, ['dataRows' => [['a' => '1'], ['a' => '2']]]);

        self::assertSame(422, $res->getStatusCode());
        self::assertSame([], $this->fakeRender->calls);
    }

    public function testInvalidDataRowsShapeIsRejectedWith422(): void
    {
        $this->enableRendering();
        $id = $this->createTemplate(self::OWNER, ['name' => 'Label', 'data' => $this->minimalTemplateData()]);

        $res = $this->render(self::OWNER, $id, ['dataRows' => 'not-a-list']);

        self::assertSame(422, $res->getStatusCode());
    }

    // ── render-service failure ───────────────────────────────────────────────

    public function testRenderServiceFailureReturns503NotARawException(): void
    {
        $this->enableRendering();
        $this->fakeRender->throwOnRender = true;
        $id = $this->createTemplate(self::OWNER, ['name' => 'Label', 'data' => $this->minimalTemplateData()]);

        $res = $this->render(self::OWNER, $id);

        self::assertSame(503, $res->getStatusCode());
        self::assertStringNotContainsString('simulated render-service failure', $res->getBody());
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function enableRendering(): void
    {
        $this->settingsService->setGlobal(SettingsRegistry::DOCUMENTS_RENDER_ENABLED, 'true');
    }

    /**
     * @return array<string, mixed>
     */
    private function minimalTemplateData(): array
    {
        return [
            'version' => 2,
            'page' => ['widthMm' => 50, 'heightMm' => 25, 'marginMm' => 2, 'background' => '#fff'],
            'placeholders' => [],
            'pages' => [['id' => 'p1', 'elements' => []]],
        ];
    }

    /**
     * @param array<string, mixed> $body
     */
    private function createTemplate(int $userId, array $body): int
    {
        return $this->templateRepo->create(self::TENANT, [
            'name' => (string) $body['name'],
            'data' => $body['data'],
            'scope' => $body['scope'] ?? 'personal',
            'required_permission' => $body['required_permission'] ?? null,
            'created_by' => $userId,
        ]);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function render(int $userId, int $templateId, array $body = []): \Whity\Sdk\Http\Response
    {
        return $this->renderAs($userId, self::TENANT, $templateId, $body);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function renderAs(int $userId, int $tenantId, int $templateId, array $body = []): \Whity\Sdk\Http\Response
    {
        TenantContext::reset();
        TenantContext::setTenantId($tenantId);
        $req = new Request('POST', "/api/document-templates/{$templateId}/render", [], (string) json_encode($body));
        $req->user = (object) ['profile_id' => $userId, 'active_tenant_id' => $tenantId];

        return $this->handler->render($req, ['id' => (string) $templateId]);
    }

    private function makeSchema(): PDO
    {
        $pdo = SchemaFromMigrations::make(true);
        $pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'a', 'a')");
        $pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (2, 'b', 'b')");

        // admin role (1) is seeded + granted documents:* by migration 060.
        $pdo->exec("INSERT OR IGNORE INTO roles (id, name, description, tenant_id, created_at) VALUES (1, 'admin', '', NULL, datetime('now'))");
        $pdo->exec("INSERT INTO roles (id, name, description, tenant_id, created_at) VALUES
            (101, 'viewer', '', 1, datetime('now')),
            (103, 'manager', '', 1, datetime('now'))");

        $this->grant($pdo, 101, 'documents:read');
        $this->grant($pdo, 103, 'documents:read');
        $this->grant($pdo, 103, self::CONTRACTS_PERM);

        $pdo->exec("
            INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at) VALUES
                (10, 'owner',   'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
                (11, 'viewer',  'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
                (13, 'manager', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
                (20, 'other-admin', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ");
        $pdo->exec("
            INSERT INTO memberships (id, profile_id, tenant_id, role_id, status, created_at) VALUES
                (1000, 10, 1, 1,   'active', datetime('now')),
                (1001, 11, 1, 101, 'active', datetime('now')),
                (1003, 13, 1, 103, 'active', datetime('now')),
                (1004, 20, 2, 1,   'active', datetime('now'))
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
}
