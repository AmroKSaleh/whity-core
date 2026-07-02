<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\PluginPackageFixtures;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\PluginsApiHandler;
use Whity\Auth\JwtParser;
use Whity\Auth\RoleChecker;
use Whity\Core\PluginLoader;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\Request;
use Whity\Core\Router;
use Whity\Core\Tenant\TenantContext;
use Whity\Database\Database;
use Whity\Http\Middleware\CsrfGuard;
use Whity\Http\Middleware\RequestBodyValidator;
use Whity\Http\RbacMiddleware;
use Whity\Sdk\Http\Response;

/**
 * WC-220: endpoint + middleware-pipeline coverage for POST /api/plugins/upload.
 *
 * Drives the REAL pipeline a multipart upload traverses — RequestBodyValidator
 * (must NOT reject a multipart body), CsrfGuard (the standard X-Requested-With
 * header passes), RbacMiddleware (plugins:upload gate), the Router, and the
 * PluginsApiHandler — so the slice proves not just the handler logic but that a
 * multipart/form-data POST actually REACHES the handler instead of being 400'd
 * by the JSON-envelope validator.
 */
final class PluginUploadApiRbacTest extends TestCase
{
    private const SECRET = 'test-secret-key-padded-for-hs256-min-32-byte-key';
    private const TENANT = 1;

    private JwtParser $jwtParser;
    private PermissionRegistry $registry;
    private RoleChecker $roleChecker;
    private RbacMiddleware $middleware;
    private Router $router;
    private string $pluginDir;
    private string $workDir;
    private PluginLoader $loader;
    private PluginsApiHandler $handler;

    protected function setUp(): void
    {
        RoleChecker::clearCache();
        TenantContext::reset();
        TenantContext::setTenantId(self::TENANT);

        $this->jwtParser = new JwtParser(self::SECRET);
        $this->registry = new PermissionRegistry();
        $this->roleChecker = new RoleChecker($this->wrapSqlite(SchemaFromMigrations::make()), $this->registry);
        $this->middleware = new RbacMiddleware($this->jwtParser, $this->roleChecker);
        $this->router = new Router('');

        $this->pluginDir = sys_get_temp_dir() . '/whity_upload_plugins_' . uniqid();
        $this->workDir = sys_get_temp_dir() . '/whity_upload_work_' . uniqid();
        mkdir($this->pluginDir, 0775, true);
        mkdir($this->workDir, 0775, true);

        $this->loader = new PluginLoader($this->pluginDir, $this->router);
        $this->loader->load();
        $this->handler = new PluginsApiHandler($this->pluginDir, $this->loader);

        $this->router->register(
            'POST',
            '/api/plugins/upload',
            [$this->handler, 'upload'],
            null,
            null,
            CorePermissions::PLUGINS_UPLOAD
        );
    }

    protected function tearDown(): void
    {
        $this->removeRecursive($this->pluginDir);
        $this->removeRecursive($this->workDir);
        RoleChecker::clearCache();
        TenantContext::reset();
    }

    /**
     * Dispatch through the FULL upload pipeline, mirroring the kernel order in
     * public/index.php: RequestBodyValidator → CsrfGuard → RBAC → handler.
     */
    private function dispatch(Request $request): Response
    {
        $match = $this->router->match($request);
        if ($match === null) {
            return Response::error('Not Found', 404);
        }

        $handler = $match['handler'];
        $params = $match['params'];

        $rbac = fn(Request $req): Response => $this->middleware->handle(
            $req,
            static fn(Request $r): Response => $handler($r, $params),
            $match['requiredRole'],
            $match['requiredPermission']
        );

        // Compose outer→inner exactly as HttpKernel does: body validator first,
        // then CSRF, then RBAC, then the handler.
        return (new RequestBodyValidator())->handle(
            $request,
            static fn(Request $r): Response => (new CsrfGuard())->handle($r, $rbac)
        );
    }

    /**
     * Build a multipart upload request, optionally cookie-authenticated.
     *
     * @param string $contentType The multipart Content-Type (with boundary).
     * @param string $body The raw multipart body.
     * @param int $userId The acting user id (token subject).
     * @param bool $withXrw Whether to send the CSRF X-Requested-With header.
     */
    private function uploadRequest(string $contentType, string $body, int $userId, bool $withXrw = true): Request
    {
        $headers = [
            'Authorization' => 'Bearer ' . $this->tokenFor($userId),
            'Content-Type' => $contentType,
        ];
        if ($withXrw) {
            $headers['X-Requested-With'] = 'XMLHttpRequest';
        }

        return new Request('POST', '/api/plugins/upload', $headers, $body);
    }

    public function testUploadWithoutPermissionIsForbidden(): void
    {
        $userId = 30;
        $this->seedRolePermissions($userId, [CorePermissions::PLUGINS_READ]);

        $zip = PluginPackageFixtures::validDirectoryZip($this->workDir, 'EndpointDenied');
        [$ct, $body] = PluginPackageFixtures::multipartBody('package', 'EndpointDenied.zip', (string) file_get_contents($zip));

        $response = $this->dispatch($this->uploadRequest($ct, $body, $userId));

        self::assertSame(403, $response->getStatusCode());
        $payload = json_decode($response->getBody(), true);
        self::assertSame('plugins:upload', $payload['required'] ?? null);
        self::assertDirectoryDoesNotExist($this->pluginDir . '/EndpointDenied');
    }

    public function testMultipartUploadPassesPipelineAndStagesDisabled(): void
    {
        $userId = 31;
        $this->seedRolePermissions($userId, [CorePermissions::PLUGINS_UPLOAD]);

        $zip = PluginPackageFixtures::validDirectoryZip($this->workDir, 'EndpointStaged');
        [$ct, $body] = PluginPackageFixtures::multipartBody('package', 'EndpointStaged.zip', (string) file_get_contents($zip));

        $response = $this->dispatch($this->uploadRequest($ct, $body, $userId));

        // The multipart request reached the handler (not a 400 from the JSON
        // envelope validator, not a 403 from CSRF) and staged the plugin.
        self::assertSame(200, $response->getStatusCode(), $response->getBody());
        $payload = json_decode($response->getBody(), true);
        self::assertSame('EndpointStaged', $payload['data']['name']);
        self::assertSame('disabled', $payload['data']['status']);
        self::assertFalse($payload['data']['enabled']);
        self::assertFileExists($this->pluginDir . '/EndpointStaged/' . PluginLoader::DIR_DISABLED_SENTINEL);
    }

    public function testCookieAuthMultipartWithoutCsrfHeaderIsRejected(): void
    {
        // A cookie-authenticated multipart POST without X-Requested-With is a
        // forgeable state change — CsrfGuard must reject it with a 403.
        $body = 'irrelevant';
        $request = new Request('POST', '/api/plugins/upload', [
            'Cookie' => 'access_token=abc',
            'Content-Type' => 'multipart/form-data; boundary=xyz',
        ], $body);

        $response = $this->dispatch($request);
        self::assertSame(403, $response->getStatusCode());
    }

    public function testMissingPackageFieldIsBadRequest(): void
    {
        $userId = 32;
        $this->seedRolePermissions($userId, [CorePermissions::PLUGINS_UPLOAD]);

        // A multipart body with a DIFFERENT field name (no 'package').
        [$ct, $body] = PluginPackageFixtures::multipartBody('wrongfield', 'x.zip', 'PK');

        $response = $this->dispatch($this->uploadRequest($ct, $body, $userId));
        self::assertSame(400, $response->getStatusCode());
    }

    public function testZipSlipUploadReturns400UniformEnvelope(): void
    {
        $userId = 33;
        $this->seedRolePermissions($userId, [CorePermissions::PLUGINS_UPLOAD]);

        $zip = PluginPackageFixtures::zipSlipArchive($this->workDir);
        [$ct, $body] = PluginPackageFixtures::multipartBody('package', 'evil.zip', (string) file_get_contents($zip));

        $response = $this->dispatch($this->uploadRequest($ct, $body, $userId));

        self::assertSame(400, $response->getStatusCode());
        $payload = json_decode($response->getBody(), true);
        self::assertArrayHasKey('error', $payload);
        // No raw exception text / stack / path leaks.
        self::assertStringNotContainsStringIgnoringCase('exception', (string) $payload['error']);
        self::assertStringNotContainsString('/', (string) $payload['error']);
    }

    public function testIncompatibleUploadReturns422(): void
    {
        $userId = 34;
        $this->seedRolePermissions($userId, [CorePermissions::PLUGINS_UPLOAD]);

        $zip = PluginPackageFixtures::incompatibleZip($this->workDir, 'EndpointIncompatible');
        [$ct, $body] = PluginPackageFixtures::multipartBody('package', 'inc.zip', (string) file_get_contents($zip));

        $response = $this->dispatch($this->uploadRequest($ct, $body, $userId));
        self::assertSame(422, $response->getStatusCode());
        self::assertDirectoryDoesNotExist($this->pluginDir . '/EndpointIncompatible');
    }

    public function testCollisionUploadReturns409(): void
    {
        $userId = 35;
        $this->seedRolePermissions($userId, [CorePermissions::PLUGINS_UPLOAD]);

        $zip = PluginPackageFixtures::validDirectoryZip($this->workDir, 'EndpointCollide');
        [$ct, $body] = PluginPackageFixtures::multipartBody('package', 'c.zip', (string) file_get_contents($zip));
        $first = $this->dispatch($this->uploadRequest($ct, $body, $userId));
        self::assertSame(200, $first->getStatusCode(), $first->getBody());

        // Second upload of the same plugin name collides.
        $second = $this->dispatch($this->uploadRequest($ct, $body, $userId));
        self::assertSame(409, $second->getStatusCode());
    }

    // ─── helpers (mirroring PluginsApiRbacTest) ─────────────────────────────

    /**
     * @param array<int, string> $grantedPermissions
     */
    private function seedRolePermissions(int $userId, array $grantedPermissions): void
    {
        $pdo = SchemaFromMigrations::make();

        // Tenant 1 hosts every fixture; seed it so users.tenant_id FK is
        // satisfied on PostgreSQL (SQLite does not enforce FKs by default).
        $pdo->exec("INSERT OR IGNORE INTO tenants (id, name) VALUES (1, 'tenant-a')");

        $pdo->prepare('INSERT INTO roles (name, created_at) VALUES (?, NOW())')->execute(['role_' . $userId]);
        $roleId = (int) $pdo->lastInsertId();
        foreach ($grantedPermissions as $permission) {
            $pdo->prepare('INSERT OR IGNORE INTO permissions (name, created_at) VALUES (?, NOW())')->execute([$permission]);
            $stmt = $pdo->prepare('SELECT id FROM permissions WHERE name = ?');
            $stmt->execute([$permission]);
            $permissionId = (int) $stmt->fetchColumn();
            $pdo->prepare('INSERT OR IGNORE INTO role_permissions (role_id, permission_id, created_at) VALUES (?, ?, NOW())')
                ->execute([$roleId, $permissionId]);
        }
        $pdo->prepare('INSERT INTO users (id, tenant_id, email, password, role_id, ou_id, created_at) VALUES (?, ?, ?, ?, ?, NULL, NOW())')
            ->execute([$userId, self::TENANT, "user{$userId}@example.com", 'x', $roleId]);

        $this->roleChecker = new RoleChecker($this->wrapSqlite($pdo), $this->registry);
        $this->middleware = new RbacMiddleware($this->jwtParser, $this->roleChecker);
    }

    private function tokenFor(int $userId): string
    {
        return $this->jwtParser->create([
            'user_id' => $userId,
            'email' => "user{$userId}@example.com",
            'tenant_id' => self::TENANT,
        ]);
    }

    private function wrapSqlite(PDO $pdo): Database
    {
        $db = Database::withFactory(static fn(): PDO => $pdo);
        $db->setMaxLifetimeSeconds(86400);
        $db->setPingIntervalSeconds(86400);
        $db->forceConnect();

        return $db;
    }

    private function removeRecursive(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (array_diff((array) scandir($path), ['.', '..']) as $entry) {
            $this->removeRecursive($path . '/' . (string) $entry);
        }
        @rmdir($path);
    }
}
