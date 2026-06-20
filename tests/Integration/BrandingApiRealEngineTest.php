<?php
declare(strict_types=1);
namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\BrandingApiHandler;
use Whity\Core\Branding\BrandingAssetValidator;
use Whity\Core\Branding\BrandingService;
use Whity\Core\Branding\HostResolver;
use Whity\Core\Branding\SvgSanitizer;
use Whity\Core\Branding\TenantHostRepository;
use Whity\Core\Request;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;
use Whity\Core\Tenant\TenantContext;
use Whity\Storage\LocalStorageDriver;

/**
 * Real-engine integration tests for GET /api/v1/branding (Task 3.1).
 *
 * Drives the REAL BrandingApiHandler + BrandingService + repositories against
 * the full migration-built schema on in-memory SQLite. Proves:
 *  - Tenant resolves by exact custom branding_host
 *  - Tenant resolves by slug subdomain of the configured base domain
 *  - Unknown host falls back to global
 *  - Authenticated TenantContext overrides host-based resolution
 *  - Only branding fields are exposed (support_email and other settings never leak)
 */
final class BrandingApiRealEngineTest extends TestCase
{
    private PDO $pdo;
    private BrandingApiHandler $handler;
    private string $root;

    protected function setUp(): void
    {
        TenantContext::reset();
        $this->root = sys_get_temp_dir() . '/branding-http-' . bin2hex(random_bytes(6));
        $this->pdo = SchemaFromMigrations::make(true);
        $this->pdo->exec("INSERT OR IGNORE INTO tenants (id, name) VALUES (0, 'system')");
        $this->pdo->exec("INSERT INTO tenants (id, name, slug, branding_host) VALUES (1, 'Acme', 'acme', 'app.acme.com')");
        $settings = new SettingsService(new GlobalSettingsRepository($this->pdo), new TenantSettingsRepository($this->pdo));
        $settings->setGlobal('site_name', 'Platform');
        $settings->setTenant(1, 'site_name', 'Acme');
        $settings->setTenant(1, 'support_email', 'secret@acme.com'); // must NOT leak
        $branding = new BrandingService($settings, new LocalStorageDriver($this->root), new BrandingAssetValidator(new SvgSanitizer()));
        $hostResolver = new HostResolver(new TenantHostRepository($this->pdo), 'whity.app');
        $this->handler = new BrandingApiHandler($branding, $hostResolver, null);
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    /** @param array<string, string> $headers */
    private function req(string $path, array $headers = []): Request
    {
        return new Request('GET', $path, $headers, '');
    }

    public function testResolvesByCustomHostAndOmitsOtherSettings(): void
    {
        $res = $this->handler->get($this->req('/api/v1/branding', ['Host' => 'app.acme.com']));
        self::assertSame(200, $res->getStatusCode());
        $body = json_decode($res->getBody(), true);
        self::assertSame('Acme', $body['data']['siteName']);
        self::assertSame(['siteName', 'logoWideUrl', 'logoSquareUrl', 'faviconUrl'], array_keys($body['data']));
        self::assertStringNotContainsString('secret@acme.com', $res->getBody());
    }

    public function testResolvesBySlugSubdomain(): void
    {
        $res = $this->handler->get($this->req('/api/v1/branding', ['Host' => 'acme.whity.app']));
        self::assertSame(200, $res->getStatusCode());
        self::assertSame('Acme', json_decode($res->getBody(), true)['data']['siteName']);
    }

    public function testUnknownHostFallsBackToGlobal(): void
    {
        $res = $this->handler->get($this->req('/api/v1/branding', ['Host' => 'whity.app']));
        self::assertSame(200, $res->getStatusCode());
        self::assertSame('Platform', json_decode($res->getBody(), true)['data']['siteName']);
    }

    public function testAuthenticatedTenantWins(): void
    {
        TenantContext::setTenantId(1);
        // Host says global, but JWT context overrides to tenant 1
        $res = $this->handler->get($this->req('/api/v1/branding', ['Host' => 'whity.app']));
        self::assertSame(200, $res->getStatusCode());
        self::assertSame('Acme', json_decode($res->getBody(), true)['data']['siteName']);
    }

    public function testResponseHasCacheControlHeader(): void
    {
        $res = $this->handler->get($this->req('/api/v1/branding', ['Host' => 'whity.app']));
        self::assertSame(200, $res->getStatusCode());
        $headers = $res->getHeaders();
        self::assertArrayHasKey('cache-control', $headers);
        self::assertStringContainsString('public', $headers['cache-control']);
    }

    public function testNullUrlsWhenNoAssetsSet(): void
    {
        $res = $this->handler->get($this->req('/api/v1/branding', ['Host' => 'app.acme.com']));
        $data = json_decode($res->getBody(), true)['data'];
        self::assertNull($data['logoWideUrl']);
        self::assertNull($data['logoSquareUrl']);
        self::assertNull($data['faviconUrl']);
    }
}
