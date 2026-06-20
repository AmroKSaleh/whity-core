<?php
declare(strict_types=1);
namespace Tests\Integration;

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
use Whity\Storage\LocalStorageDriver;

/**
 * Tests for the public asset-serving route GET /api/v1/branding/asset/{tid}/{name}
 * (Task 3.2 — Tenant Branding).
 *
 * Verifies byte delivery, hardened security headers, MIME detection, ETag
 * generation, and 404 behaviour for missing assets.
 */
final class BrandingAssetServingTest extends TestCase
{
    private function handler(string $root, LocalStorageDriver $storage): BrandingApiHandler
    {
        $pdo = SchemaFromMigrations::make(true);
        $pdo->exec("INSERT OR IGNORE INTO tenants (id, name) VALUES (0, 'system')");
        $settings = new SettingsService(new GlobalSettingsRepository($pdo), new TenantSettingsRepository($pdo));
        $branding = new BrandingService($settings, $storage, new BrandingAssetValidator(new SvgSanitizer()));
        $hostResolver = new HostResolver(new TenantHostRepository($pdo), 'whity.app');
        return new BrandingApiHandler($branding, $hostResolver, null, new TenantHostRepository($pdo), $storage);
    }

    public function testServesBytesWithHardenedHeaders(): void
    {
        $root = sys_get_temp_dir() . '/serve-' . bin2hex(random_bytes(5));
        $storage = new LocalStorageDriver($root);
        $storage->put('tenants/1/branding/logo_wide-abc.png', 'PNGDATA');
        $h = $this->handler($root, $storage);

        $res = $h->serveAsset(
            new Request('GET', '/api/v1/branding/asset/1/logo_wide-abc.png', [], ''),
            ['tenantId' => '1', 'name' => 'logo_wide-abc.png']
        );

        self::assertSame(200, $res->getStatusCode());
        self::assertSame('PNGDATA', $res->getBody());

        $headers = $res->getHeaders();
        self::assertSame('image/png', $headers['content-type']);
        self::assertSame('nosniff', $headers['x-content-type-options']);
        self::assertStringContainsString("default-src 'none'", $headers['content-security-policy']);
        self::assertStringContainsString('immutable', $headers['cache-control']);
        self::assertSame('inline', $headers['content-disposition']);
        // ETag must be present and quoted
        self::assertStringStartsWith('"', $headers['etag'] ?? '');
    }

    public function testMissingAssetIs404(): void
    {
        $root = sys_get_temp_dir() . '/serve-' . bin2hex(random_bytes(5));
        $storage = new LocalStorageDriver($root);
        $h = $this->handler($root, $storage);

        $res = $h->serveAsset(
            new Request('GET', '/api/v1/branding/asset/1/missing.png', [], ''),
            ['tenantId' => '1', 'name' => 'missing.png']
        );

        self::assertSame(404, $res->getStatusCode());
    }

    public function testMissingTenantOrNameParams404(): void
    {
        $root = sys_get_temp_dir() . '/serve-' . bin2hex(random_bytes(5));
        $storage = new LocalStorageDriver($root);
        $h = $this->handler($root, $storage);

        $res = $h->serveAsset(
            new Request('GET', '/api/v1/branding/asset/', [], ''),
            []
        );

        self::assertSame(404, $res->getStatusCode());
    }

    public function testSvgMimeType(): void
    {
        $root = sys_get_temp_dir() . '/serve-' . bin2hex(random_bytes(5));
        $storage = new LocalStorageDriver($root);
        $storage->put('tenants/0/branding/favicon-abc.svg', '<svg/>');
        $h = $this->handler($root, $storage);

        $res = $h->serveAsset(
            new Request('GET', '/api/v1/branding/asset/0/favicon-abc.svg', [], ''),
            ['tenantId' => '0', 'name' => 'favicon-abc.svg']
        );

        self::assertSame(200, $res->getStatusCode());
        self::assertSame('image/svg+xml', $res->getHeaders()['content-type']);
    }

    public function testEtagIsConsistentForSameContent(): void
    {
        $root = sys_get_temp_dir() . '/serve-' . bin2hex(random_bytes(5));
        $storage = new LocalStorageDriver($root);
        $storage->put('tenants/1/branding/logo_square-def.png', 'BYTES');
        $h = $this->handler($root, $storage);

        $params = ['tenantId' => '1', 'name' => 'logo_square-def.png'];
        $req = new Request('GET', '/api/v1/branding/asset/1/logo_square-def.png', [], '');

        $etag1 = $h->serveAsset($req, $params)->getHeaders()['etag'] ?? '';
        $etag2 = $h->serveAsset($req, $params)->getHeaders()['etag'] ?? '';
        self::assertSame($etag1, $etag2);
        self::assertNotEmpty($etag1);
    }
}
