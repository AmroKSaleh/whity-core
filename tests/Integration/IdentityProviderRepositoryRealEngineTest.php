<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Identity\IdentityProviderRepository;

/**
 * Real-engine tests for {@see IdentityProviderRepository} (WC-e6287). This is the
 * tenant-isolation proof for the new tenant-owned `identity_providers` table:
 * every read/write is tenant-scoped, so a tenant can only see/mutate its own
 * providers. Also covers secret omission from normal reads + the UNIQUE guard.
 */
final class IdentityProviderRepositoryRealEngineTest extends TestCase
{
    private PDO $pdo;
    private IdentityProviderRepository $repo;
    private int $tenantA;
    private int $tenantB;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $this->repo = new IdentityProviderRepository($this->pdo);
        $this->tenantA = $this->seedTenant('Acme');
        $this->tenantB = $this->seedTenant('Beta');
    }

    private function seedTenant(string $name): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO tenants (name, slug, created_at) VALUES (:n, :s, NOW())');
        if ($stmt === false) {
            self::fail('prepare failed');
        }
        $stmt->execute([':n' => $name, ':s' => strtolower($name)]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function providerData(array $overrides = []): array
    {
        return array_merge([
            'provider_key'            => 'google',
            'display_name'            => 'Google',
            'client_id'               => 'client-123.apps.googleusercontent.com',
            'client_secret_encrypted' => 'v1:ciphertext-placeholder',
            'issuer'                  => 'https://accounts.google.com',
            'discovery_url'           => 'https://accounts.google.com/.well-known/openid-configuration',
            'scopes'                  => 'openid email profile',
            'domain'                  => 'acme.test',
            'enabled'                 => true,
        ], $overrides);
    }

    private function col(string $sql): mixed
    {
        $stmt = $this->pdo->query($sql);
        if ($stmt === false) {
            self::fail("query failed: {$sql}");
        }
        return $stmt->fetchColumn();
    }

    public function testInsertAndFindByIdOmitsSecret(): void
    {
        $id = $this->repo->insert($this->tenantA, $this->providerData());

        $row = $this->repo->findById($id, $this->tenantA);
        self::assertNotNull($row);
        self::assertSame('google', $row['provider_key']);
        self::assertArrayNotHasKey('client_secret_encrypted', $row, 'the secret must never be exposed');
        self::assertTrue($row['has_secret']);
        self::assertTrue($row['enabled']);
    }

    public function testHasSecretFalseWhenNoneStored(): void
    {
        $id = $this->repo->insert($this->tenantA, $this->providerData(['client_secret_encrypted' => null]));
        $row = $this->repo->findById($id, $this->tenantA);
        self::assertNotNull($row);
        self::assertFalse($row['has_secret']);
    }

    public function testListIsTenantScoped(): void
    {
        $this->repo->insert($this->tenantA, $this->providerData(['provider_key' => 'google']));
        $this->repo->insert($this->tenantA, $this->providerData(['provider_key' => 'microsoft', 'display_name' => 'MS']));
        $this->repo->insert($this->tenantB, $this->providerData(['provider_key' => 'google']));

        self::assertCount(2, $this->repo->listForTenant($this->tenantA));
        self::assertCount(1, $this->repo->listForTenant($this->tenantB));
    }

    public function testFindByIdRejectsCrossTenantRead(): void
    {
        $id = $this->repo->insert($this->tenantA, $this->providerData());
        self::assertNull($this->repo->findById($id, $this->tenantB), 'a tenant cannot read another tenant\'s provider');
    }

    public function testUpdateRejectsCrossTenantAndLeavesRowUntouched(): void
    {
        $id = $this->repo->insert($this->tenantA, $this->providerData(['display_name' => 'Original']));

        $affected = $this->repo->update($id, $this->tenantB, ['display_name' => 'Hijacked']);
        self::assertSame(0, $affected);

        $row = $this->repo->findById($id, $this->tenantA);
        self::assertNotNull($row);
        self::assertSame('Original', $row['display_name'], 'the foreign row must be untouched');
    }

    public function testDeleteRejectsCrossTenantAndRowSurvives(): void
    {
        $id = $this->repo->insert($this->tenantA, $this->providerData());
        self::assertSame(0, $this->repo->delete($id, $this->tenantB));
        self::assertNotNull($this->repo->findById($id, $this->tenantA), 'the foreign row survives a cross-tenant delete');

        self::assertSame(1, $this->repo->delete($id, $this->tenantA));
        self::assertNull($this->repo->findById($id, $this->tenantA));
    }

    public function testPartialUpdateDoesNotClobberSecret(): void
    {
        $id = $this->repo->insert($this->tenantA, $this->providerData(['client_secret_encrypted' => 'v1:original-secret']));

        // An edit that changes only the display name must keep the stored secret.
        $this->repo->update($id, $this->tenantA, ['display_name' => 'Renamed']);

        self::assertSame(
            'v1:original-secret',
            (string) $this->col("SELECT client_secret_encrypted FROM identity_providers WHERE id = {$id}")
        );
    }

    public function testFindEnabledByProviderKeyIgnoresDisabled(): void
    {
        $this->repo->insert($this->tenantA, $this->providerData(['provider_key' => 'google', 'enabled' => false]));
        self::assertNull($this->repo->findEnabledByProviderKey($this->tenantA, 'google'));

        $this->repo->insert($this->tenantA, $this->providerData(['provider_key' => 'microsoft', 'display_name' => 'MS', 'enabled' => true]));
        self::assertNotNull($this->repo->findEnabledByProviderKey($this->tenantA, 'microsoft'));
    }

    public function testFindClientSecretCiphertextIsTenantScoped(): void
    {
        $id = $this->repo->insert($this->tenantA, $this->providerData(['client_secret_encrypted' => 'v1:the-secret']));
        self::assertSame('v1:the-secret', $this->repo->findClientSecretCiphertext($id, $this->tenantA));
        self::assertNull($this->repo->findClientSecretCiphertext($id, $this->tenantB), 'cross-tenant secret read is denied');
    }

    public function testDuplicateProviderKeyPerTenantIsRejected(): void
    {
        $this->repo->insert($this->tenantA, $this->providerData(['provider_key' => 'google']));
        $this->expectException(\PDOException::class);
        $this->repo->insert($this->tenantA, $this->providerData(['provider_key' => 'google']));
    }
}
