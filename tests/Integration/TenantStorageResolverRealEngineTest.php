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
use Whity\Storage\S3\S3StorageDriver;
use Whity\Storage\StorageException;
use Whity\Storage\TenantStorageConfigRepository;
use Whity\Storage\TenantStorageResolver;

/**
 * Real-engine tests for {@see TenantStorageResolver} (WC-storage): a tenant uses
 * its own backend ONLY when it both holds the storage.custom_backend entitlement
 * and has a config row; every other case falls back to the platform default.
 * Also the missing-secret fail-loud and the per-worker driver memo.
 */
final class TenantStorageResolverRealEngineTest extends TestCase
{
    private const KEY = 'storage_test_key_0123456789abcdef0123456789';

    private PDO $pdo;
    private LocalStorageDriver $default;
    private EncryptedSecretStore $secrets;
    private TenantStorageConfigRepository $configs;
    private EntitlementService $entitlements;
    private TenantStorageResolver $resolver;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        for ($id = 1; $id <= 5; $id++) {
            $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES ({$id}, 't{$id}', 't{$id}')");
        }

        $this->default = new LocalStorageDriver(sys_get_temp_dir() . '/whity-storage-test');
        $this->secrets = new EncryptedSecretStore(['v1' => self::KEY], 'v1');
        $this->configs = new TenantStorageConfigRepository($this->pdo);
        $this->entitlements = new EntitlementService(new TenantEntitlementRepository($this->pdo));
        $this->resolver = new TenantStorageResolver($this->default, $this->configs, $this->entitlements, $this->secrets);
    }

    private function entitle(int $tenantId): void
    {
        $this->entitlements->set($tenantId, EntitlementRegistry::STORAGE_CUSTOM_BACKEND, 'true', null);
    }

    private function configure(int $tenantId): void
    {
        $this->configs->upsert($tenantId, [
            'driver'           => 's3',
            'endpoint'         => 'https://s3.example.com',
            'region'           => 'us-east-1',
            'bucket'           => "tenant-{$tenantId}",
            'access_key'       => 'AKIAEXAMPLE',
            'secret_encrypted' => $this->secrets->encrypt('super-secret'),
            'path_style'       => true,
            'public_base_url'  => null,
        ]);
    }

    public function testEntitledAndConfiguredTenantGetsItsOwnS3Driver(): void
    {
        $this->entitle(1);
        $this->configure(1);

        $driver = $this->resolver->forTenant(1);
        self::assertInstanceOf(S3StorageDriver::class, $driver);
        self::assertNotSame($this->default, $driver);
    }

    public function testConfiguredButNotEntitledFallsBackToDefault(): void
    {
        // Config present but the tenant lacks storage.custom_backend → default.
        $this->configure(2);

        self::assertSame($this->default, $this->resolver->forTenant(2));
    }

    public function testEntitledButNotConfiguredFallsBackToDefault(): void
    {
        $this->entitle(3);

        self::assertSame($this->default, $this->resolver->forTenant(3));
    }

    public function testUnconfiguredUnentitledTenantUsesDefault(): void
    {
        self::assertSame($this->default, $this->resolver->forTenant(4));
    }

    public function testSystemTenantAlwaysUsesDefault(): void
    {
        // The system tenant is implicitly unlimited (entitled) but a non-positive
        // id short-circuits to the default before any lookup.
        self::assertSame($this->default, $this->resolver->forTenant(0));
    }

    public function testEntitledConfiguredButMissingSecretFailsLoud(): void
    {
        $this->entitle(5);
        // Insert a row with an empty secret directly (NOT NULL allows '').
        $this->pdo->exec("
            INSERT INTO tenant_storage_config
                (tenant_id, driver, endpoint, region, bucket, access_key, secret_encrypted, path_style, created_at, updated_at)
            VALUES (5, 's3', 'https://s3.example.com', 'us-east-1', 'b5', 'AK', '', true, NOW(), NOW())
        ");

        $this->expectException(StorageException::class);
        $this->resolver->forTenant(5);
    }

    public function testDriverIsMemoisedPerTenant(): void
    {
        $this->entitle(1);
        $this->configure(1);

        self::assertSame($this->resolver->forTenant(1), $this->resolver->forTenant(1));
    }
}
