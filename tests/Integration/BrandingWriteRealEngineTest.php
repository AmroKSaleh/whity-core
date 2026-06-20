<?php
declare(strict_types=1);
namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\BrandingApiHandler;
use Whity\Auth\RoleChecker;
use Whity\Core\Branding\BrandingAssetValidator;
use Whity\Core\Branding\BrandingService;
use Whity\Core\Branding\HostResolver;
use Whity\Core\Branding\SvgSanitizer;
use Whity\Core\Branding\TenantHostRepository;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\Request;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;
use Whity\Core\Tenant\TenantContext;
use Whity\Database\Database;
use Whity\Storage\LocalStorageDriver;

/**
 * Real-engine integration tests for the Branding write endpoints (Task 3.3 + 3.4).
 *
 * Drives the REAL BrandingApiHandler + BrandingService + repositories + a REAL
 * RoleChecker against the full migration-built schema on in-memory SQLite.
 *
 * User seeding mirrors SettingsApiRealEngineTest:
 *   10 = admin (all settings perms)
 *   11 = reader only (settings:read)
 *   12 = no settings perms
 *   13 = settings:write but NOT settings:manage
 *
 * Task 3.3 tests: uploadTenant, clearTenant, uploadGlobal, clearGlobal
 * Task 3.4 tests: setBrandingHost
 */
final class BrandingWriteRealEngineTest extends TestCase
{
    private const TENANT_A = 1;
    private const SYSTEM_TENANT = 0;
    private const USER_FULL = 10;
    private const USER_READ_ONLY = 11;
    private const USER_NONE = 12; // @phpstan-ignore classConstant.unused
    private const USER_WRITE_NO_MANAGE = 13;

    private PDO $pdo;
    private BrandingApiHandler $handler;
    private string $root;

    protected function setUp(): void
    {
        RoleChecker::clearCache();
        TenantContext::reset();
        $this->root = sys_get_temp_dir() . '/branding-write-' . bin2hex(random_bytes(6));
        $this->pdo = $this->makeSchema();
        $this->handler = $this->makeHandler();
    }

    protected function tearDown(): void
    {
        RoleChecker::clearCache();
        TenantContext::reset();
    }

    // ==================== Task 3.3: uploadTenant ====================

    public function testTenantWriterUploads(): void
    {
        TenantContext::setTenantId(self::TENANT_A);
        $req = $this->multipartReq('POST', '/api/v1/branding/assets/logo_wide', self::USER_WRITE_NO_MANAGE, $this->fakePng());
        $res = $this->handler->uploadTenant($req, ['key' => 'logo_wide']);
        self::assertSame(200, $res->getStatusCode(), $res->getBody());
        $data = json_decode($res->getBody(), true)['data'];
        self::assertNotNull($data['logoWideUrl'], 'logoWideUrl must be set after upload');
        self::assertStringContainsString('/api/v1/branding/asset/', $data['logoWideUrl']);
    }

    public function testTenantUploadRequiresWrite(): void
    {
        TenantContext::setTenantId(self::TENANT_A);
        $req = $this->multipartReq('POST', '/api/v1/branding/assets/logo_wide', self::USER_READ_ONLY, $this->fakePng());
        $res = $this->handler->uploadTenant($req, ['key' => 'logo_wide']);
        self::assertSame(403, $res->getStatusCode());
    }

    public function testSystemTenantUpload422(): void
    {
        // System tenant (0) has no per-tenant override layer; the BrandingService
        // calls setTenantAsset which throws SettingsValidationException for tenant 0
        TenantContext::setTenantId(self::SYSTEM_TENANT);
        $req = $this->multipartReq('POST', '/api/v1/branding/assets/logo_wide', self::USER_FULL, $this->fakePng());
        $res = $this->handler->uploadTenant($req, ['key' => 'logo_wide']);
        self::assertSame(422, $res->getStatusCode(), $res->getBody());
    }

    public function testUploadUnknownKeyIs404(): void
    {
        TenantContext::setTenantId(self::TENANT_A);
        $req = $this->multipartReq('POST', '/api/v1/branding/assets/nope', self::USER_WRITE_NO_MANAGE, $this->fakePng());
        $res = $this->handler->uploadTenant($req, ['key' => 'nope']);
        self::assertSame(404, $res->getStatusCode());
    }

    public function testUploadWithoutFileIs400(): void
    {
        TenantContext::setTenantId(self::TENANT_A);
        $req = new Request('POST', '/api/v1/branding/assets/logo_wide', [], '');
        $req->user = (object) ['user_id' => self::USER_WRITE_NO_MANAGE, 'tenant_id' => self::TENANT_A];
        $res = $this->handler->uploadTenant($req, ['key' => 'logo_wide']);
        self::assertSame(400, $res->getStatusCode());
    }

    // ==================== Task 3.3: clearTenant ====================

    public function testTenantWriterClears(): void
    {
        TenantContext::setTenantId(self::TENANT_A);
        // Upload first
        $up = $this->multipartReq('POST', '/api/v1/branding/assets/logo_wide', self::USER_WRITE_NO_MANAGE, $this->fakePng());
        $this->handler->uploadTenant($up, ['key' => 'logo_wide']);
        // Then clear
        $del = $this->plainReq('DELETE', '/api/v1/branding/assets/logo_wide', self::USER_WRITE_NO_MANAGE);
        $res = $this->handler->clearTenant($del, ['key' => 'logo_wide']);
        self::assertSame(200, $res->getStatusCode(), $res->getBody());
        $data = json_decode($res->getBody(), true)['data'];
        self::assertNull($data['logoWideUrl'], 'logoWideUrl must be null after clear');
    }

    public function testClearUnknownKeyIs404(): void
    {
        TenantContext::setTenantId(self::TENANT_A);
        $req = $this->plainReq('DELETE', '/api/v1/branding/assets/nope', self::USER_WRITE_NO_MANAGE);
        $res = $this->handler->clearTenant($req, ['key' => 'nope']);
        self::assertSame(404, $res->getStatusCode());
    }

    public function testSystemTenantClear422(): void
    {
        // System tenant (0) has no per-tenant override layer; clearTenant must
        // return 422 rather than attempting a clear that has no target.
        TenantContext::setTenantId(self::SYSTEM_TENANT);
        $req = $this->plainReq('DELETE', '/api/v1/branding/assets/logo_wide', self::USER_FULL);
        $res = $this->handler->clearTenant($req, ['key' => 'logo_wide']);
        self::assertSame(422, $res->getStatusCode(), $res->getBody());
    }

    // ==================== Task 3.3: uploadGlobal / clearGlobal ====================

    public function testGlobalUploadRequiresManage(): void
    {
        TenantContext::setTenantId(self::TENANT_A);
        $req = $this->multipartReq('POST', '/api/v1/branding/global/assets/logo_wide', self::USER_WRITE_NO_MANAGE, $this->fakePng());
        $res = $this->handler->uploadGlobal($req, ['key' => 'logo_wide']);
        self::assertSame(403, $res->getStatusCode());
        self::assertSame('settings:manage', json_decode($res->getBody(), true)['details']['required'] ?? null);
    }

    public function testGlobalUploadSucceedsForManageCaller(): void
    {
        TenantContext::setTenantId(self::TENANT_A);
        $req = $this->multipartReq('POST', '/api/v1/branding/global/assets/logo_wide', self::USER_FULL, $this->fakePng());
        $res = $this->handler->uploadGlobal($req, ['key' => 'logo_wide']);
        self::assertSame(200, $res->getStatusCode(), $res->getBody());
        // After uploading a global logo, the effective branding for tenant 0 reflects it
        $data = json_decode($res->getBody(), true)['data'];
        self::assertNotNull($data['logoWideUrl']);
    }

    public function testGlobalClearSucceedsForManageCaller(): void
    {
        TenantContext::setTenantId(self::TENANT_A);
        // Upload first
        $up = $this->multipartReq('POST', '/api/v1/branding/global/assets/favicon', self::USER_FULL, $this->fakePng());
        $this->handler->uploadGlobal($up, ['key' => 'favicon']);
        // Clear
        $del = $this->plainReq('DELETE', '/api/v1/branding/global/assets/favicon', self::USER_FULL);
        $res = $this->handler->clearGlobal($del, ['key' => 'favicon']);
        self::assertSame(200, $res->getStatusCode(), $res->getBody());
    }

    // ==================== Task 3.4: setBrandingHost ====================

    public function testSetBrandingHostValidHost(): void
    {
        TenantContext::setTenantId(self::TENANT_A);
        $req = $this->jsonReq('PUT', '/api/v1/tenants/1/branding-host', self::USER_FULL, ['host' => 'brand.example.com']);
        $res = $this->handler->setBrandingHost($req, ['id' => '1']);
        self::assertSame(200, $res->getStatusCode(), $res->getBody());
        $data = json_decode($res->getBody(), true)['data'];
        self::assertSame('brand.example.com', $data['branding_host']);
    }

    public function testSetBrandingHostNullClears(): void
    {
        TenantContext::setTenantId(self::TENANT_A);
        $req = $this->jsonReq('PUT', '/api/v1/tenants/1/branding-host', self::USER_FULL, ['host' => null]);
        $res = $this->handler->setBrandingHost($req, ['id' => '1']);
        self::assertSame(200, $res->getStatusCode(), $res->getBody());
        $data = json_decode($res->getBody(), true)['data'];
        self::assertNull($data['branding_host']);
    }

    public function testSetBrandingHostEmptyStringClears(): void
    {
        TenantContext::setTenantId(self::TENANT_A);
        $req = $this->jsonReq('PUT', '/api/v1/tenants/1/branding-host', self::USER_FULL, ['host' => '']);
        $res = $this->handler->setBrandingHost($req, ['id' => '1']);
        self::assertSame(200, $res->getStatusCode(), $res->getBody());
        $data = json_decode($res->getBody(), true)['data'];
        self::assertNull($data['branding_host']);
    }

    public function testSetBrandingHostBadFormatIs422(): void
    {
        TenantContext::setTenantId(self::TENANT_A);
        $req = $this->jsonReq('PUT', '/api/v1/tenants/1/branding-host', self::USER_FULL, ['host' => 'notahostname']);
        $res = $this->handler->setBrandingHost($req, ['id' => '1']);
        self::assertSame(422, $res->getStatusCode());
        self::assertArrayHasKey('host', json_decode($res->getBody(), true)['details'] ?? []);
    }

    public function testSetBrandingHostDuplicateIs409(): void
    {
        // Tenant 2 already holds 'dup.example.com'
        $this->pdo->exec("INSERT INTO tenants (id, name, slug, branding_host) VALUES (2, 'Other', 'other', 'dup.example.com')");
        TenantContext::setTenantId(self::TENANT_A);
        $req = $this->jsonReq('PUT', '/api/v1/tenants/1/branding-host', self::USER_FULL, ['host' => 'dup.example.com']);
        $res = $this->handler->setBrandingHost($req, ['id' => '1']);
        self::assertSame(409, $res->getStatusCode());
    }

    public function testSetBrandingHostRequiresManage(): void
    {
        TenantContext::setTenantId(self::TENANT_A);
        $req = $this->jsonReq('PUT', '/api/v1/tenants/1/branding-host', self::USER_WRITE_NO_MANAGE, ['host' => 'brand.example.com']);
        $res = $this->handler->setBrandingHost($req, ['id' => '1']);
        self::assertSame(403, $res->getStatusCode());
        self::assertSame('settings:manage', json_decode($res->getBody(), true)['details']['required'] ?? null);
    }

    // ==================== helpers ====================

    private function makeHandler(): BrandingApiHandler
    {
        $storage = new LocalStorageDriver($this->root);
        $settings = new SettingsService(
            new GlobalSettingsRepository($this->pdo),
            new TenantSettingsRepository($this->pdo)
        );
        $branding = new BrandingService($settings, $storage, new BrandingAssetValidator(new SvgSanitizer()));
        $hostRepo = new TenantHostRepository($this->pdo);
        $hostResolver = new HostResolver($hostRepo, 'whity.app');
        $registry = new PermissionRegistry();
        $registry->registerCorePermissions();
        $pdo = $this->pdo;
        $db = Database::withFactory(static fn(): PDO => $pdo, 86400, 86400);
        $db->forceConnect();
        $roleChecker = new RoleChecker($db, $registry);
        return new BrandingApiHandler($branding, $hostResolver, $roleChecker, $hostRepo, $storage);
    }

    /**
     * Build a multipart/form-data request carrying the given file bytes in a
     * 'file' field. The SDK's getUploadedFiles() will parse the body via
     * MultipartParser and spill the part to a real temp file, so
     * BrandingApiHandler can read it via file_get_contents(getStreamPath()).
     */
    private function multipartReq(string $method, string $path, int $userId, string $bytes): Request
    {
        $boundary = '----WBTest' . bin2hex(random_bytes(6));
        $body = "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"file\"; filename=\"x.png\"\r\n"
            . "Content-Type: image/png\r\n"
            . "\r\n"
            . $bytes
            . "\r\n--{$boundary}--\r\n";
        $req = new Request($method, $path, [
            'Content-Type' => "multipart/form-data; boundary={$boundary}",
        ], $body);
        $req->user = (object) ['user_id' => $userId, 'tenant_id' => self::TENANT_A];
        return $req;
    }

    private function plainReq(string $method, string $path, int $userId): Request
    {
        $req = new Request($method, $path, [], '');
        $req->user = (object) ['user_id' => $userId, 'tenant_id' => self::TENANT_A];
        return $req;
    }

    /** @param array<string, mixed> $body */
    private function jsonReq(string $method, string $path, int $userId, array $body): Request
    {
        $req = new Request($method, $path, ['Content-Type' => 'application/json'], (string) json_encode($body));
        $req->user = (object) ['user_id' => $userId, 'tenant_id' => self::TENANT_A];
        return $req;
    }

    /** Minimal 1×1 valid PNG bytes. */
    private function fakePng(): string
    {
        return base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='
        );
    }

    /**
     * In-memory SQLite with the full production schema seeded with four users in
     * Tenant A holding different settings-permission subsets.
     */
    private function makeSchema(): PDO
    {
        $pdo = SchemaFromMigrations::make(true);

        $pdo->exec("INSERT OR IGNORE INTO tenants (id, name) VALUES (0, 'system')");
        $pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'tenant-a', 'tenant-a')");

        $pdo->exec("INSERT OR IGNORE INTO roles (id, name, description, tenant_id, created_at) VALUES (1, 'admin', '', NULL, datetime('now'))");
        $pdo->exec("
            INSERT INTO roles (id, name, description, tenant_id, created_at) VALUES
                (100, 'settings-reader', '', 1, datetime('now')),
                (101, 'no-settings',     '', 1, datetime('now')),
                (102, 'settings-writer', '', 1, datetime('now'))
        ");

        /** @var \PDOStatement $readStmt */
        $readStmt   = $pdo->query("SELECT id FROM permissions WHERE name = 'settings:read'");
        /** @var \PDOStatement $writeStmt */
        $writeStmt  = $pdo->query("SELECT id FROM permissions WHERE name = 'settings:write'");
        $readPermId  = (int) $readStmt->fetchColumn();
        $writePermId = (int) $writeStmt->fetchColumn();
        $pdo->exec("
            INSERT INTO role_permissions (role_id, permission_id, created_at) VALUES
                (100, {$readPermId},  datetime('now')),
                (102, {$readPermId},  datetime('now')),
                (102, {$writePermId}, datetime('now'))
        ");

        $pdo->exec("
            INSERT INTO users (id, tenant_id, email, password, role_id, created_at) VALUES
                (10, 1, 'admin@t1.example',  'x', 1,   datetime('now')),
                (11, 1, 'reader@t1.example', 'x', 100, datetime('now')),
                (12, 1, 'none@t1.example',   'x', 101, datetime('now')),
                (13, 1, 'writer@t1.example', 'x', 102, datetime('now'))
        ");

        return $pdo;
    }
}
