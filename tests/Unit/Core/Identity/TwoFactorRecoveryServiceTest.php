<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Identity;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Auth\BackupCodesService;
use Whity\Core\Identity\PasswordResetService;
use Whity\Core\Identity\TwoFactorRecoveryService;

/**
 * Real-engine tests for {@see TwoFactorRecoveryService}
 * (WC-password-reset-2fa-recovery).
 *
 * Proves the two-step token lifecycle (issue → confirm creates the
 * admin-visible pending row; nothing is cleared until then), tenant-scoped
 * admin approve/reject, that approve() clears EXACTLY the fields
 * {@see \Whity\Api\TwoFactorHandler::disable()} clears and issues a fresh
 * password-reset token, that reject() leaves the target untouched, and the
 * secondary admin-direct force-reset fallback.
 */
final class TwoFactorRecoveryServiceTest extends TestCase
{
    private PDO $pdo;
    private TwoFactorRecoveryService $service;
    private PasswordResetService $passwordResets;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $this->passwordResets = new PasswordResetService($this->pdo);
        $dbWrapper = new class ($this->pdo) {
            public function __construct(private readonly PDO $pdo) {}

            /**
             * @param array<int|string, mixed> $params
             */
            public function query(string $sql, array $params = []): \PDOStatement
            {
                $stmt = $this->pdo->prepare($sql);
                if ($stmt === false) {
                    throw new \RuntimeException('prepare failed');
                }
                $stmt->execute($params);

                return $stmt;
            }
        };
        $this->service = new TwoFactorRecoveryService(
            $this->pdo,
            $this->passwordResets,
            new BackupCodesService($dbWrapper)
        );
    }

    // ==================== two-step token lifecycle ====================

    public function testIssueAloneDoesNotCreateAnAdminVisiblePendingRequest(): void
    {
        $profileId = $this->seedProfileWithTwoFactor('issued-only@acme.test');
        $this->service->issue($profileId);

        // Not yet 'pending' — only 'pending_confirmation'. The admin queue
        // query only ever selects status='pending', so nothing is visible yet.
        self::assertSame('pending_confirmation', (string) $this->col(
            "SELECT status FROM two_factor_recovery_requests WHERE profile_id = {$profileId}"
        ));
    }

    public function testConfirmCreatesThePendingRequestAndBurnsTheToken(): void
    {
        $profileId = $this->seedProfileWithTwoFactor('confirm@acme.test');
        $token = $this->service->issue($profileId);

        $result = $this->service->confirm($token);
        self::assertNotNull($result);
        self::assertSame($profileId, $result['profile_id']);

        self::assertSame('pending', (string) $this->col(
            "SELECT status FROM two_factor_recovery_requests WHERE id = {$result['request_id']}"
        ));
        self::assertNotNull($this->col(
            "SELECT consumed_at FROM two_factor_recovery_requests WHERE id = {$result['request_id']}"
        ));

        // Confirming must NOT itself touch 2FA — only an admin approval does.
        $this->assertTwoFactorStillEnabled($profileId);
    }

    public function testConfirmIsSingleUse(): void
    {
        $profileId = $this->seedProfileWithTwoFactor('replay@acme.test');
        $token = $this->service->issue($profileId);

        self::assertNotNull($this->service->confirm($token));
        self::assertNull($this->service->confirm($token));
    }

    public function testConfirmRejectsExpiredToken(): void
    {
        $profileId = $this->seedProfileWithTwoFactor('expired@acme.test');
        $token = $this->service->issue($profileId, -1);

        self::assertNull($this->service->confirm($token));
    }

    // ==================== admin approve: clears 2FA + issues reset ====================

    public function testApproveForTenantClearsExactlyTheDisableFieldsAndIssuesResetToken(): void
    {
        $tenantId  = $this->seedTenant('Acme2fa');
        $profileId = $this->seedProfileWithTwoFactor('approve2fa@acme.test', $tenantId);

        $token = $this->service->issue($profileId);
        $confirmed = $this->service->confirm($token);
        self::assertNotNull($confirmed);
        $requestId = $confirmed['request_id'];

        $approved = $this->service->approveForTenant($requestId, $tenantId);
        self::assertNotNull($approved);
        self::assertSame($profileId, $approved['profile_id']);
        self::assertSame('approve2fa@acme.test', $approved['email']);
        self::assertNotSame('', $approved['reset_token']);

        $row = $this->fetchOneRow(
            "SELECT two_factor_enabled, two_factor_secret, two_factor_backup_codes_version
             FROM profiles WHERE id = {$profileId}"
        );

        // Exactly the fields TwoFactorHandler::disable() clears.
        self::assertContains((string) $row['two_factor_enabled'], ['0', 'f', 'false', ''], '2FA must be disabled');
        self::assertNull($row['two_factor_secret'], 'the secret must be cleared');
        self::assertSame('0', (string) $row['two_factor_backup_codes_version'], 'the backup-codes version must reset to 0');

        // Old backup codes are invalidated.
        self::assertSame(0, (int) $this->col(
            "SELECT COUNT(*) FROM backup_codes WHERE profile_id = {$profileId} AND used = false"
        ));

        // A fresh password-reset token now exists for the same profile and is
        // usable to actually confirm() a new password (a full clean recovery).
        $resetResult = $this->passwordResets->confirm($approved['reset_token'], 'brand-new-password-after-2fa', false);
        self::assertNotNull($resetResult);
        self::assertSame($profileId, $resetResult['profile_id']);

        self::assertSame('approved', (string) $this->col(
            "SELECT status FROM two_factor_recovery_requests WHERE id = {$requestId}"
        ));
    }

    public function testApproveForTenantRejectsCallerFromAnotherTenant(): void
    {
        $tenantA   = $this->seedTenant('2faTenantA');
        $tenantB   = $this->seedTenant('2faTenantB');
        $profileId = $this->seedProfileWithTwoFactor('cross2fa@acme.test', $tenantA);

        $token = $this->service->issue($profileId);
        $confirmed = $this->service->confirm($token);
        self::assertNotNull($confirmed);

        self::assertNull($this->service->approveForTenant($confirmed['request_id'], $tenantB));
        $this->assertTwoFactorStillEnabled($profileId);
    }

    public function testRejectForTenantLeavesTargetCompletelyUntouched(): void
    {
        $tenantId  = $this->seedTenant('Reject2fa');
        $profileId = $this->seedProfileWithTwoFactor('reject2fa@acme.test', $tenantId);

        $token = $this->service->issue($profileId);
        $confirmed = $this->service->confirm($token);
        self::assertNotNull($confirmed);
        $requestId = $confirmed['request_id'];

        $rejected = $this->service->rejectForTenant($requestId, $tenantId);
        self::assertNotNull($rejected);
        self::assertSame($profileId, $rejected['profile_id']);

        $this->assertTwoFactorStillEnabled($profileId);
        self::assertSame('rejected', (string) $this->col(
            "SELECT status FROM two_factor_recovery_requests WHERE id = {$requestId}"
        ));
    }

    public function testListPendingForTenantIsScopedToActiveMembers(): void
    {
        $tenantA = $this->seedTenant('List2faA');
        $tenantB = $this->seedTenant('List2faB');
        $inA = $this->seedProfileWithTwoFactor('inA2fa@acme.test', $tenantA);
        $inB = $this->seedProfileWithTwoFactor('inB2fa@acme.test', $tenantB);

        $this->service->confirm($this->service->issue($inA));
        $this->service->confirm($this->service->issue($inB));

        $pendingA = $this->service->listPendingForTenant($tenantA);
        self::assertCount(1, $pendingA);
        self::assertSame('inA2fa@acme.test', $pendingA[0]['email']);
    }

    // ==================== secondary fallback: admin-direct force ====================

    public function testForceResetForTenantClearsTwoFactorWithNoPriorRequest(): void
    {
        $tenantId  = $this->seedTenant('ForceTenant');
        $profileId = $this->seedProfileWithTwoFactor('force@acme.test', $tenantId);

        // No issue()/confirm() call at all — this is the "no prior request" path.
        $result = $this->service->forceResetForTenant($profileId, $tenantId);
        self::assertNotNull($result);
        self::assertSame($profileId, $result['profile_id']);
        self::assertSame('force@acme.test', $result['email']);

        $row = $this->fetchOneRow(
            "SELECT two_factor_enabled, two_factor_secret FROM profiles WHERE id = {$profileId}"
        );
        self::assertContains((string) $row['two_factor_enabled'], ['0', 'f', 'false', '']);
        self::assertNull($row['two_factor_secret']);
    }

    public function testForceResetForTenantRejectsTargetInAnotherTenant(): void
    {
        $tenantA   = $this->seedTenant('ForceA');
        $tenantB   = $this->seedTenant('ForceB');
        $profileId = $this->seedProfileWithTwoFactor('forcecross@acme.test', $tenantA);

        self::assertNull($this->service->forceResetForTenant($profileId, $tenantB));
        $this->assertTwoFactorStillEnabled($profileId);
    }

    // ==================== helpers ====================

    private function assertTwoFactorStillEnabled(int $profileId): void
    {
        $row = $this->fetchOneRow(
            "SELECT two_factor_enabled, two_factor_secret, two_factor_backup_codes_version
             FROM profiles WHERE id = {$profileId}"
        );

        self::assertContains((string) $row['two_factor_enabled'], ['1', 't', 'true']);
        self::assertSame('encrypted-secret-blob', (string) $row['two_factor_secret']);
        self::assertSame('2', (string) $row['two_factor_backup_codes_version']);
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchOneRow(string $sql): array
    {
        $stmt = $this->pdo->query($sql);
        if ($stmt === false) {
            self::fail("query failed: {$sql}");
        }
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);

        return $row;
    }

    private function seedProfileWithTwoFactor(string $email, ?int $tenantId = null): int
    {
        $this->pdo->exec(
            "INSERT INTO profiles
                (display_name, password_hash, two_factor_enabled, two_factor_secret,
                 two_factor_backup_codes_version, token_epoch, created_at, updated_at)
             VALUES ('2fa user', 'x', true, 'encrypted-secret-blob', 2, 0, datetime('now'), datetime('now'))"
        );
        $profileId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec(
            "INSERT INTO profile_emails (profile_id, email, verified, is_primary, created_at)
             VALUES ({$profileId}, '{$email}', true, true, datetime('now'))"
        );

        // A couple of unused backup codes at the current version, so the
        // invalidate-old-codes step has something real to flip.
        $this->pdo->exec(
            "INSERT INTO backup_codes (profile_id, code, version, used) VALUES
                ({$profileId}, 'hash-a', 2, false),
                ({$profileId}, 'hash-b', 2, false)"
        );

        if ($tenantId !== null) {
            $roleId = $this->baseRoleId();
            $stmt = $this->pdo->prepare(
                "INSERT INTO memberships (profile_id, tenant_id, role_id, status, created_at)
                 VALUES (:pid, :tid, :rid, 'active', datetime('now'))"
            );
            $stmt->execute([':pid' => $profileId, ':tid' => $tenantId, ':rid' => $roleId]);
        }

        return $profileId;
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

    private function col(string $sql): mixed
    {
        $stmt = $this->pdo->query($sql);
        if ($stmt === false) {
            self::fail("query failed: {$sql}");
        }

        return $stmt->fetchColumn();
    }
}
