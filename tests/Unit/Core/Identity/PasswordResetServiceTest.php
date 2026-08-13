<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Identity;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Identity\PasswordResetService;

/**
 * Real-engine tests for {@see PasswordResetService} against the full
 * migration-built schema (WC-password-reset-2fa-recovery).
 *
 * Proves the token lifecycle (issue/confirm, single-use, superseding, expiry),
 * the immediate-apply vs stage-for-approval branch, the token_epoch bump on
 * final application, tenant-scoped admin listing/approve/reject, and —
 * critically — that NOTHING here ever touches a `two_factor_*` column.
 */
final class PasswordResetServiceTest extends TestCase
{
    private PDO $pdo;
    private PasswordResetService $service;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $this->service = new PasswordResetService($this->pdo);
    }

    // ==================== issue() / confirm() (immediate apply) ====================

    public function testIssueThenConfirmAppliesImmediatelyWhenApprovalNotRequired(): void
    {
        $profileId = $this->seedProfile('user@acme.test', 'old-password-hash');
        $oldEpoch  = $this->epochOf($profileId);

        $token  = $this->service->issue($profileId);
        $result = $this->service->confirm($token, 'brand-new-password-1', false);

        self::assertNotNull($result);
        self::assertSame($profileId, $result['profile_id']);
        self::assertTrue($result['applied']);

        // password_hash changed and verifies the NEW password.
        $hash = (string) $this->col("SELECT password_hash FROM profiles WHERE id = {$profileId}");
        self::assertTrue(password_verify('brand-new-password-1', $hash));

        // token_epoch bumped — invalidates every existing session, mirroring a
        // self-service password change (AuthHandler::handleUpdateMe()).
        self::assertSame($oldEpoch + 1, $this->epochOf($profileId));

        // Row is marked applied + consumed.
        self::assertSame('applied', (string) $this->col(
            "SELECT status FROM password_resets WHERE id = {$result['request_id']}"
        ));
        self::assertNotNull($this->col(
            "SELECT consumed_at FROM password_resets WHERE id = {$result['request_id']}"
        ));
    }

    public function testConfirmIsSingleUse(): void
    {
        $profileId = $this->seedProfile('single@acme.test', 'x');
        $token = $this->service->issue($profileId);

        self::assertNotNull($this->service->confirm($token, 'first-new-password', false));
        // Replaying the same token must fail — it is already consumed.
        self::assertNull($this->service->confirm($token, 'second-new-password', false));
    }

    public function testConfirmRejectsUnknownToken(): void
    {
        self::assertNull($this->service->confirm('not-a-real-token', 'whatever-password', false));
    }

    public function testConfirmRejectsExpiredToken(): void
    {
        $profileId = $this->seedProfile('expired@acme.test', 'x');
        $token = $this->service->issue($profileId, -1); // already-expired TTL

        self::assertNull($this->service->confirm($token, 'new-password-123', false));
    }

    public function testIssueSupersedesAnyPriorOutstandingToken(): void
    {
        $profileId = $this->seedProfile('supersede@acme.test', 'x');
        $firstToken = $this->service->issue($profileId);
        $secondToken = $this->service->issue($profileId);

        // Only one outstanding row remains for this profile.
        self::assertSame(1, (int) $this->col(
            "SELECT COUNT(*) FROM password_resets WHERE profile_id = {$profileId} AND consumed_at IS NULL"
        ));
        self::assertNull($this->service->confirm($firstToken, 'irrelevant-password', false));
        self::assertNotNull($this->service->confirm($secondToken, 'irrelevant-password', false));
    }

    // ==================== approval branch ====================

    public function testConfirmStagesInsteadOfApplyingWhenApprovalRequired(): void
    {
        $profileId = $this->seedProfile('staged@acme.test', 'original-hash-value');
        $originalHash = (string) $this->col("SELECT password_hash FROM profiles WHERE id = {$profileId}");
        $oldEpoch = $this->epochOf($profileId);

        $token  = $this->service->issue($profileId);
        $result = $this->service->confirm($token, 'staged-new-password', true);

        self::assertNotNull($result);
        self::assertFalse($result['applied']);

        // profiles.password_hash and token_epoch are UNTOUCHED until approval.
        self::assertSame($originalHash, (string) $this->col(
            "SELECT password_hash FROM profiles WHERE id = {$profileId}"
        ));
        self::assertSame($oldEpoch, $this->epochOf($profileId));

        // The row is staged awaiting approval, and the token is already burned
        // (single-use regardless of the approval branch).
        self::assertSame('awaiting_approval', (string) $this->col(
            "SELECT status FROM password_resets WHERE id = {$result['request_id']}"
        ));
        self::assertNotNull($this->col(
            "SELECT consumed_at FROM password_resets WHERE id = {$result['request_id']}"
        ));
        $stagedHash = (string) $this->col(
            "SELECT staged_password_hash FROM password_resets WHERE id = {$result['request_id']}"
        );
        self::assertTrue(password_verify('staged-new-password', $stagedHash));
    }

    public function testStagedHashOnlyExistsAfterTokenOwnershipIsProven(): void
    {
        // issue() alone (before confirm()) must NEVER populate staged_password_hash
        // — an attacker who merely knows an email address must never get a
        // password staged for approval.
        $profileId = $this->seedProfile('preissue@acme.test', 'x');
        $this->service->issue($profileId);

        self::assertNull($this->col(
            "SELECT staged_password_hash FROM password_resets WHERE profile_id = {$profileId}"
        ));
    }

    public function testApproveForTenantAppliesStagedHashAndBumpsEpoch(): void
    {
        $tenantId  = $this->seedTenant('Acme');
        $profileId = $this->seedProfile('approve@acme.test', 'old-hash', $tenantId);
        $oldEpoch  = $this->epochOf($profileId);

        $token  = $this->service->issue($profileId);
        $result = $this->service->confirm($token, 'admin-approved-password', true);
        self::assertNotNull($result);
        $requestId = $result['request_id'];

        $approved = $this->service->approveForTenant($requestId, $tenantId);
        self::assertNotNull($approved);
        self::assertSame($profileId, $approved['profile_id']);
        self::assertSame('approve@acme.test', $approved['email']);

        $hash = (string) $this->col("SELECT password_hash FROM profiles WHERE id = {$profileId}");
        self::assertTrue(password_verify('admin-approved-password', $hash));
        self::assertSame($oldEpoch + 1, $this->epochOf($profileId));

        self::assertSame('approved', (string) $this->col(
            "SELECT status FROM password_resets WHERE id = {$requestId}"
        ));
        self::assertNull($this->col(
            "SELECT staged_password_hash FROM password_resets WHERE id = {$requestId}"
        ), 'the staged hash must be cleared once applied');
    }

    public function testApproveForTenantRejectsCallerFromAnotherTenant(): void
    {
        $tenantA   = $this->seedTenant('TenantA');
        $tenantB   = $this->seedTenant('TenantB');
        $profileId = $this->seedProfile('cross@acme.test', 'old-hash', $tenantA);

        $token = $this->service->issue($profileId);
        $result = $this->service->confirm($token, 'should-not-apply', true);
        self::assertNotNull($result);
        $requestId = $result['request_id'];

        // TenantB's admin must not be able to approve TenantA's requester.
        self::assertNull($this->service->approveForTenant($requestId, $tenantB));

        $hash = (string) $this->col("SELECT password_hash FROM profiles WHERE id = {$profileId}");
        self::assertFalse(password_verify('should-not-apply', $hash));
        self::assertSame('awaiting_approval', (string) $this->col(
            "SELECT status FROM password_resets WHERE id = {$requestId}"
        ));
    }

    public function testRejectForTenantDiscardsStagedHashWithoutTouchingProfile(): void
    {
        $tenantId  = $this->seedTenant('Rejecto');
        $profileId = $this->seedProfile('reject@acme.test', 'stays-the-same', $tenantId);
        $originalHash = (string) $this->col("SELECT password_hash FROM profiles WHERE id = {$profileId}");

        $token = $this->service->issue($profileId);
        $result = $this->service->confirm($token, 'never-applied-password', true);
        self::assertNotNull($result);
        $requestId = $result['request_id'];

        $rejected = $this->service->rejectForTenant($requestId, $tenantId);
        self::assertNotNull($rejected);
        self::assertSame($profileId, $rejected['profile_id']);

        self::assertSame($originalHash, (string) $this->col(
            "SELECT password_hash FROM profiles WHERE id = {$profileId}"
        ));
        self::assertSame('rejected', (string) $this->col(
            "SELECT status FROM password_resets WHERE id = {$requestId}"
        ));
        self::assertNull($this->col(
            "SELECT staged_password_hash FROM password_resets WHERE id = {$requestId}"
        ));
    }

    public function testListPendingForTenantIsScopedToActiveMembers(): void
    {
        $tenantA = $this->seedTenant('ListA');
        $tenantB = $this->seedTenant('ListB');
        $inA = $this->seedProfile('inA@acme.test', 'x', $tenantA);
        $inB = $this->seedProfile('inB@acme.test', 'x', $tenantB);

        $tokenA = $this->service->issue($inA);
        $this->service->confirm($tokenA, 'password-for-a', true);
        $tokenB = $this->service->issue($inB);
        $this->service->confirm($tokenB, 'password-for-b', true);

        $pendingA = $this->service->listPendingForTenant($tenantA);
        self::assertCount(1, $pendingA);
        self::assertSame('inA@acme.test', $pendingA[0]['email']);

        $pendingB = $this->service->listPendingForTenant($tenantB);
        self::assertCount(1, $pendingB);
        self::assertSame('inB@acme.test', $pendingB[0]['email']);
    }

    // ==================== hard invariant: NEVER touches 2FA fields ====================

    public function testImmediateApplyNeverTouchesTwoFactorFields(): void
    {
        $profileId = $this->seedProfileWithTwoFactor('has2fa@acme.test');

        $token = $this->service->issue($profileId);
        $result = $this->service->confirm($token, 'brand-new-password-2', false);
        self::assertNotNull($result);
        self::assertTrue($result['applied']);

        $this->assertTwoFactorUntouched($profileId);
    }

    public function testStagedApprovalNeverTouchesTwoFactorFields(): void
    {
        $tenantId  = $this->seedTenant('TwoFaTenant');
        $profileId = $this->seedProfileWithTwoFactor('has2fa-staged@acme.test', $tenantId);

        $token = $this->service->issue($profileId);
        $result = $this->service->confirm($token, 'staged-password-2', true);
        self::assertNotNull($result);
        $this->service->approveForTenant($result['request_id'], $tenantId);

        $this->assertTwoFactorUntouched($profileId);
    }

    // ==================== helpers ====================

    private function assertTwoFactorUntouched(int $profileId): void
    {
        $stmt = $this->pdo->query(
            "SELECT two_factor_enabled, two_factor_secret, two_factor_backup_codes_version
             FROM profiles WHERE id = {$profileId}"
        );
        if ($stmt === false) {
            self::fail('query failed');
        }
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);

        self::assertContains((string) $row['two_factor_enabled'], ['1', 't', 'true'], 'two_factor_enabled must remain ON');
        self::assertSame('encrypted-secret-blob', (string) $row['two_factor_secret'], 'the secret must be untouched');
        self::assertSame('3', (string) $row['two_factor_backup_codes_version'], 'the backup-codes version must be untouched');
    }

    private function seedProfileWithTwoFactor(string $email, ?int $tenantId = null): int
    {
        $this->pdo->exec(
            "INSERT INTO profiles
                (display_name, password_hash, two_factor_enabled, two_factor_secret,
                 two_factor_backup_codes_version, token_epoch, created_at, updated_at)
             VALUES ('2fa user', 'x', true, 'encrypted-secret-blob', 3, 0, datetime('now'), datetime('now'))"
        );
        $profileId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec(
            "INSERT INTO profile_emails (profile_id, email, verified, is_primary, created_at)
             VALUES ({$profileId}, '{$email}', true, true, datetime('now'))"
        );
        if ($tenantId !== null) {
            $this->addActiveMembership($profileId, $tenantId);
        }

        return $profileId;
    }

    private function seedProfile(string $email, string $passwordSeed, ?int $tenantId = null): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO profiles
                (display_name, password_hash, two_factor_enabled, two_factor_secret,
                 two_factor_backup_codes_version, token_epoch, created_at, updated_at)
             VALUES (:dn, :ph, false, NULL, 0, 0, datetime(\'now\'), datetime(\'now\'))'
        );
        $stmt->execute([':dn' => 'Test User', ':ph' => password_hash($passwordSeed, PASSWORD_BCRYPT)]);
        $profileId = (int) $this->pdo->lastInsertId();

        $emailStmt = $this->pdo->prepare(
            'INSERT INTO profile_emails (profile_id, email, verified, is_primary, created_at)
             VALUES (:pid, :email, true, true, datetime(\'now\'))'
        );
        $emailStmt->execute([':pid' => $profileId, ':email' => $email]);

        if ($tenantId !== null) {
            $this->addActiveMembership($profileId, $tenantId);
        }

        return $profileId;
    }

    private function addActiveMembership(int $profileId, int $tenantId): void
    {
        $roleId = $this->baseRoleId();
        $stmt = $this->pdo->prepare(
            "INSERT INTO memberships (profile_id, tenant_id, role_id, status, created_at)
             VALUES (:pid, :tid, :rid, 'active', datetime('now'))"
        );
        $stmt->execute([':pid' => $profileId, ':tid' => $tenantId, ':rid' => $roleId]);
    }

    private function seedTenant(string $name): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO tenants (name, slug, created_at) VALUES (:n, :s, datetime(\'now\'))');
        $stmt->execute([':n' => $name, ':s' => strtolower($name) . '-' . bin2hex(random_bytes(3))]);

        return (int) $this->pdo->lastInsertId();
    }

    private function baseRoleId(): int
    {
        $stmt = $this->pdo->query('SELECT id FROM roles ORDER BY id ASC LIMIT 1');
        $existing = $stmt === false ? false : $stmt->fetchColumn();
        if ($existing !== false) {
            return (int) $existing;
        }
        $this->pdo->exec("INSERT INTO roles (name, description, tenant_id, created_at) VALUES ('admin', '', NULL, datetime('now'))");

        return (int) $this->pdo->lastInsertId();
    }

    private function epochOf(int $profileId): int
    {
        return (int) $this->col("SELECT token_epoch FROM profiles WHERE id = {$profileId}");
    }

    private function col(string $sql): mixed
    {
        $stmt = $this->pdo->query($sql);
        if ($stmt === false) {
            self::fail("query failed: {$sql}");
        }

        return $stmt->fetchColumn();
    }
}
