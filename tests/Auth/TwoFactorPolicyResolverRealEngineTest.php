<?php

declare(strict_types=1);

namespace Tests\Auth;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Auth\TwoFactorPolicyResolver;
use Whity\Database\Database;

/**
 * Real-engine (in-memory SQLite) tests for {@see TwoFactorPolicyResolver}
 * (WC-525 PR-1).
 *
 * Pattern mirrors {@see RoleCheckerRealEngineTest}: runs against the real
 * production schema via {@see SchemaFromMigrations}, so the OU-chain walk and
 * the `two_factor_policies` migration are exercised together rather than
 * mocked.
 */
final class TwoFactorPolicyResolverRealEngineTest extends TestCase
{
    private const TENANT_ID = 1;

    private PDO $pdo;
    private Database $db;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make();
        $this->pdo->exec("INSERT OR IGNORE INTO tenants (id, name) VALUES (1, 'test-tenant')");
        $this->pdo->exec("INSERT OR IGNORE INTO tenants (id, name) VALUES (2, 'other-tenant')");
        $this->db = self::wrapSqlite($this->pdo);
    }

    public function testNoPolicyMeansNotEnforced(): void
    {
        $profileId = $this->seedProfile(null);

        $resolver = $this->resolver();
        $this->assertFalse($resolver->isEnforced(self::TENANT_ID, $profileId, null));
        $this->assertNull($resolver->enforcementDeadline(self::TENANT_ID, $profileId, null));
    }

    public function testTenantWidePolicyEnforcesEveryMembership(): void
    {
        $profileId = $this->seedProfile(null);
        $this->insertPolicy('tenant', null, 7);

        $resolver = $this->resolver();
        $this->assertTrue($resolver->isEnforced(self::TENANT_ID, $profileId, null));
    }

    public function testTenantWidePolicyNeverLeaksAcrossTenants(): void
    {
        $this->insertPolicy('tenant', null, 0, self::TENANT_ID);
        $otherTenantProfileId = $this->seedProfile(null, 2);

        $resolver = $this->resolver();
        $this->assertFalse(
            $resolver->isEnforced(2, $otherTenantProfileId, null),
            'A tenant-1 policy must never enforce for a tenant-2 profile.'
        );
    }

    public function testUserSpecificPolicyEnforcesOnlyThatProfile(): void
    {
        $targetProfileId = $this->seedProfile(null);
        $otherProfileId = $this->seedProfile(null);
        $this->insertPolicy('user', $targetProfileId, 0);

        $resolver = $this->resolver();
        $this->assertTrue($resolver->isEnforced(self::TENANT_ID, $targetProfileId, null));
        $this->assertFalse($resolver->isEnforced(self::TENANT_ID, $otherProfileId, null));
    }

    public function testOuPolicyEnforcesDescendantMembersThroughTheChain(): void
    {
        $rootOuId = $this->seedOu(null);
        $childOuId = $this->seedOu($rootOuId);
        $grandchildOuId = $this->seedOu($childOuId);

        $memberOfGrandchild = $this->seedProfile($grandchildOuId);
        $siblingOuId = $this->seedOu(null);
        $memberOfSiblingOu = $this->seedProfile($siblingOuId);

        $this->insertPolicy('ou', $rootOuId, 3);

        $resolver = $this->resolver();
        $this->assertTrue(
            $resolver->isEnforced(self::TENANT_ID, $memberOfGrandchild, $grandchildOuId),
            'A policy on the root OU must cover a member of a grandchild OU.'
        );
        $this->assertFalse(
            $resolver->isEnforced(self::TENANT_ID, $memberOfSiblingOu, $siblingOuId),
            'A policy on an unrelated OU chain must not apply.'
        );
    }

    public function testStrictestDeadlineWinsAcrossOverlappingPolicies(): void
    {
        $ouId = $this->seedOu(null);
        $profileId = $this->seedProfile($ouId);

        // Tenant-wide: 30-day grace. OU: 5-day grace (stricter, earlier deadline).
        $this->insertPolicy('tenant', null, 30);
        $this->insertPolicy('ou', $ouId, 5);
        $this->insertPolicy('user', $profileId, 60);

        $resolver = $this->resolver();
        $this->assertTrue($resolver->isEnforced(self::TENANT_ID, $profileId, $ouId));

        $deadline = $resolver->enforcementDeadline(self::TENANT_ID, $profileId, $ouId);
        $this->assertNotNull($deadline);

        // The OU policy (5-day grace) must produce the earliest deadline.
        $ouDeadlineRow = $this->fetchOne("SELECT created_at FROM two_factor_policies WHERE scope_type = 'ou'");
        $expectedOuDeadline = strtotime((string) $ouDeadlineRow['created_at']) + 5 * 86400;

        $this->assertSame($expectedOuDeadline, $deadline);
    }

    public function testZeroGracePeriodDeadlineEqualsCreationTime(): void
    {
        $profileId = $this->seedProfile(null);
        $this->insertPolicy('tenant', null, 0);

        $resolver = $this->resolver();
        $deadline = $resolver->enforcementDeadline(self::TENANT_ID, $profileId, null);

        $row = $this->fetchOne("SELECT created_at FROM two_factor_policies WHERE scope_type = 'tenant'");
        $expected = strtotime((string) $row['created_at']);

        $this->assertSame($expected, $deadline);
    }

    public function testCircularOuChainDoesNotHang(): void
    {
        $ouA = $this->seedOu(null);
        $ouB = $this->seedOu($ouA);
        // Force a cycle: A's parent becomes B.
        $this->pdo->prepare('UPDATE organizational_units SET parent_id = ? WHERE id = ?')->execute([$ouB, $ouA]);

        $profileId = $this->seedProfile($ouB);
        $this->insertPolicy('ou', $ouA, 0);

        $resolver = $this->resolver();
        // Must terminate (not hang) and still resolve correctly for the cycle members.
        $this->assertTrue($resolver->isEnforced(self::TENANT_ID, $profileId, $ouB));
    }

    // ==================== Helpers ====================

    private function resolver(): TwoFactorPolicyResolver
    {
        return new TwoFactorPolicyResolver($this->db);
    }

    private function insertPolicy(string $scopeType, ?int $scopeId, int $gracePeriodDays, int $tenantId = self::TENANT_ID): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO two_factor_policies (tenant_id, scope_type, scope_id, grace_period_days, created_at, updated_at)
             VALUES (?, ?, ?, ?, datetime(\'now\'), datetime(\'now\'))'
        );
        $stmt->execute([$tenantId, $scopeType, $scopeId, $gracePeriodDays]);
    }

    private function seedProfile(?int $ouId, int $tenantId = self::TENANT_ID): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO profiles (display_name, password_hash, two_factor_enabled,
                 two_factor_backup_codes_version, token_epoch, created_at, updated_at)
             VALUES ('test', 'x', 0, 0, 0, datetime('now'), datetime('now'))"
        );
        $stmt->execute();
        $profileId = (int) $this->pdo->lastInsertId();

        $roleId = (int) $this->fetchOne("SELECT id FROM roles WHERE name = 'user'")['id'];

        $this->pdo->prepare(
            'INSERT INTO memberships (profile_id, tenant_id, role_id, ou_id, status, created_at)
             VALUES (?, ?, ?, ?, \'active\', datetime(\'now\'))'
        )->execute([$profileId, $tenantId, $roleId, $ouId]);

        return $profileId;
    }

    private function seedOu(?int $parentId): int
    {
        $slug = 'ou-' . uniqid();
        $stmt = $this->pdo->prepare(
            'INSERT INTO organizational_units (tenant_id, name, slug, parent_id, created_at)
             VALUES (?, ?, ?, ?, datetime(\'now\'))'
        );
        $stmt->execute([self::TENANT_ID, $slug, $slug, $parentId]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchOne(string $sql): array
    {
        $statement = $this->pdo->query($sql);
        $this->assertNotFalse($statement, "Query failed: {$sql}");
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        $this->assertIsArray($row, "Query returned no row: {$sql}");

        return $row;
    }

    private static function wrapSqlite(PDO $pdo): Database
    {
        $db = Database::withFactory(static fn(): PDO => $pdo);
        $db->setMaxLifetimeSeconds(86400);
        $db->setPingIntervalSeconds(86400);
        $db->forceConnect();

        return $db;
    }
}
