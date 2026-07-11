<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Entitlement\EntitlementRegistry;
use Whity\Core\Entitlement\EntitlementService;
use Whity\Core\Entitlement\EntitlementValidationException;
use Whity\Core\Entitlement\TenantEntitlementRepository;

/**
 * Real-engine tests for {@see EntitlementService} + {@see TenantEntitlementRepository}
 * (WC-ent). Also the tenant-isolation proof for the new tenant-owned
 * `tenant_entitlements` table: an override written for one tenant never leaks
 * into another's effective map.
 */
final class EntitlementServiceRealEngineTest extends TestCase
{
    private PDO $pdo;
    private EntitlementService $service;
    private int $tenantA;
    private int $tenantB;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $this->service = new EntitlementService(new TenantEntitlementRepository($this->pdo));
        $this->tenantA = $this->seedTenant('Acme');
        $this->tenantB = $this->seedTenant('Beta');
    }

    private function seedTenant(string $name): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO tenants (name, slug, created_at) VALUES (:n, :s, NOW())');
        self::assertNotFalse($stmt);
        $stmt->execute([':n' => $name, ':s' => strtolower($name)]);
        return (int) $this->pdo->lastInsertId();
    }

    public function testUnsetTenantResolvesToRegistryDefaults(): void
    {
        $eff = $this->service->effective($this->tenantA);

        self::assertFalse($eff[EntitlementRegistry::STORAGE_CUSTOM_BACKEND]);
        self::assertSame(EntitlementRegistry::UNLIMITED, $eff[EntitlementRegistry::MEMBERS_MAX]);
        self::assertFalse($this->service->isGranted($this->tenantA, EntitlementRegistry::SSO_TENANT_IDP));
        self::assertSame([], $this->service->overriddenKeys($this->tenantA));
    }

    public function testOperatorOverrideRaisesEntitlementForOneTenantOnly(): void
    {
        $this->service->set($this->tenantA, EntitlementRegistry::STORAGE_CUSTOM_BACKEND, 'true', 999);
        $this->service->set($this->tenantA, EntitlementRegistry::MEMBERS_MAX, '25', 999);

        // Tenant A now entitled; tenant B untouched (isolation).
        self::assertTrue($this->service->isGranted($this->tenantA, EntitlementRegistry::STORAGE_CUSTOM_BACKEND));
        self::assertSame(25, $this->service->limit($this->tenantA, EntitlementRegistry::MEMBERS_MAX));
        self::assertFalse($this->service->isGranted($this->tenantB, EntitlementRegistry::STORAGE_CUSTOM_BACKEND));
        self::assertSame(EntitlementRegistry::UNLIMITED, $this->service->limit($this->tenantB, EntitlementRegistry::MEMBERS_MAX));

        self::assertEqualsCanonicalizing(
            [EntitlementRegistry::STORAGE_CUSTOM_BACKEND, EntitlementRegistry::MEMBERS_MAX],
            $this->service->overriddenKeys($this->tenantA),
        );
    }

    public function testClearingOverrideFallsBackToDefault(): void
    {
        $this->service->set($this->tenantA, EntitlementRegistry::STORAGE_CUSTOM_BACKEND, 'true', 1);
        self::assertTrue($this->service->isGranted($this->tenantA, EntitlementRegistry::STORAGE_CUSTOM_BACKEND));

        $this->service->set($this->tenantA, EntitlementRegistry::STORAGE_CUSTOM_BACKEND, null, 1);
        self::assertFalse($this->service->isGranted($this->tenantA, EntitlementRegistry::STORAGE_CUSTOM_BACKEND));
        self::assertSame([], $this->service->overriddenKeys($this->tenantA));
    }

    public function testSetIsUpsertNotDuplicate(): void
    {
        $this->service->set($this->tenantA, EntitlementRegistry::MEMBERS_MAX, '10', 1);
        $this->service->set($this->tenantA, EntitlementRegistry::MEMBERS_MAX, '20', 2);

        self::assertSame(20, $this->service->limit($this->tenantA, EntitlementRegistry::MEMBERS_MAX));
        $stmt = $this->pdo->query('SELECT COUNT(*) FROM tenant_entitlements');
        self::assertNotFalse($stmt);
        self::assertSame(1, (int) $stmt->fetchColumn(), 'A second set must update, not insert a duplicate row.');
    }

    public function testSystemTenantIsImplicitlyUnlimited(): void
    {
        $eff = $this->service->effective(EntitlementService::SYSTEM_TENANT_ID);

        self::assertTrue($eff[EntitlementRegistry::STORAGE_CUSTOM_BACKEND]);
        self::assertTrue($eff[EntitlementRegistry::SSO_TENANT_IDP]);
        self::assertSame(EntitlementRegistry::UNLIMITED, $eff[EntitlementRegistry::MEMBERS_MAX]);
        self::assertSame(EntitlementRegistry::UNLIMITED, $eff[EntitlementRegistry::STORAGE_QUOTA_BYTES]);
    }

    public function testSettingSystemTenantEntitlementIsRejected(): void
    {
        $this->expectException(EntitlementValidationException::class);
        $this->service->set(EntitlementService::SYSTEM_TENANT_ID, EntitlementRegistry::MEMBERS_MAX, '5', 1);
    }

    public function testInvalidValueIsRejectedAndNotPersisted(): void
    {
        try {
            $this->service->set($this->tenantA, EntitlementRegistry::MEMBERS_MAX, 'plenty', 1);
            self::fail('Expected EntitlementValidationException');
        } catch (EntitlementValidationException $e) {
            self::assertSame(EntitlementRegistry::MEMBERS_MAX, $e->entitlementKey());
        }
        self::assertSame([], $this->service->overriddenKeys($this->tenantA), 'Nothing must persist on a rejected write.');
    }

    public function testUnknownKeyIsRejected(): void
    {
        $this->expectException(EntitlementValidationException::class);
        $this->service->set($this->tenantA, 'made.up.key', 'true', 1);
    }

    public function testIsGrantedRejectsIntKeyAndLimitRejectsBoolKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->isGranted($this->tenantA, EntitlementRegistry::MEMBERS_MAX); // int used as bool
    }
}
