<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Entitlement\EntitlementRegistry;
use Whity\Core\Entitlement\EntitlementService;
use Whity\Core\Entitlement\TenantEntitlementRepository;
use Whity\Core\Security\EncryptedSecretStore;
use Whity\Storage\LocalStorageDriver;
use Whity\Storage\TenantRoutingStorageDriver;
use Whity\Storage\TenantStorageConfigRepository;
use Whity\Storage\TenantStorageResolver;

/**
 * Real-engine tests for {@see TenantRoutingStorageDriver} (WC-storage): each op is
 * routed to the correct backend by the tenant segment parsed from the key. A
 * configured+entitled tenant hits its own S3 backend; every other key (including
 * one with no tenant segment) transparently uses the platform default.
 *
 * Uses publicUrl() to distinguish backends without any network: S3 (with a public
 * base) returns a computed URL; the local default throws. A local put/get proves
 * key pass-through to the default.
 */
final class TenantRoutingStorageDriverRealEngineTest extends TestCase
{
    private const KEY = 'storage_test_key_0123456789abcdef0123456789';
    private const PUBLIC_BASE = 'https://cdn.tenant-one.example';

    private LocalStorageDriver $default;
    private TenantRoutingStorageDriver $routing;
    private string $root;

    protected function setUp(): void
    {
        $pdo = SchemaFromMigrations::make(true);
        $pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 't1', 't1'), (2, 't2', 't2')");

        $this->root = sys_get_temp_dir() . '/whity-routing-test-' . bin2hex((string) getmypid());
        $this->default = new LocalStorageDriver($this->root);
        $secrets = new EncryptedSecretStore(['v1' => self::KEY], 'v1');
        $configs = new TenantStorageConfigRepository($pdo);
        $entitlements = new EntitlementService(new TenantEntitlementRepository($pdo));

        // Tenant 1: entitled + configured with a PUBLIC base → its own S3 backend.
        $entitlements->set(1, EntitlementRegistry::STORAGE_CUSTOM_BACKEND, 'true', null);
        $configs->upsert(1, [
            'driver'           => 's3',
            'endpoint'         => 'https://s3.example.com',
            'region'           => 'us-east-1',
            'bucket'           => 'tenant-1',
            'access_key'       => 'AKIAEXAMPLE',
            'secret_encrypted' => $secrets->encrypt('super-secret'),
            'path_style'       => true,
            'public_base_url'  => self::PUBLIC_BASE,
        ]);
        // Tenant 2: nothing configured → default.

        $resolver = new TenantStorageResolver($this->default, $configs, $entitlements, $secrets);
        $this->routing = new TenantRoutingStorageDriver($this->default, $resolver);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->root)) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $f) {
                $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
            }
            @rmdir($this->root);
        }
    }

    public function testTenantOneKeyRoutesToItsOwnS3Backend(): void
    {
        // publicUrl is computed by the S3 driver from the tenant's public base,
        // proving the op was routed to tenant 1's backend, not the local default.
        $url = $this->routing->publicUrl('tenants/1/branding/logo.png');
        self::assertStringStartsWith(self::PUBLIC_BASE, $url);
        self::assertStringContainsString('tenants/1/branding/logo.png', $url);
    }

    public function testTenantTwoKeyRoutesToTheDefaultBackend(): void
    {
        // Tenant 2 has no custom backend → the local default handles it, whose
        // publicUrl throws (proving it was NOT routed to an S3 backend).
        $this->expectException(\RuntimeException::class);
        $this->routing->publicUrl('tenants/2/branding/logo.png');
    }

    public function testKeyPassThroughRoundTripsOnTheDefault(): void
    {
        // A tenant-2 key round-trips through the default local driver unchanged.
        $key = 'tenants/2/branding/note.txt';
        $this->routing->put($key, 'hello');
        self::assertTrue($this->routing->exists($key));
        self::assertSame('hello', $this->routing->get($key));

        $this->routing->delete($key);
        self::assertFalse($this->routing->exists($key));
    }

    public function testKeyWithNoTenantSegmentUsesDefault(): void
    {
        // A malformed / tenant-less key must not blow up — it uses the default.
        $key = 'not-a-tenant-key.txt';
        $this->routing->put($key, 'x');
        self::assertTrue($this->routing->exists($key));
    }
}
