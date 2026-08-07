<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\TwoFactorRecoveryApprovalsApiHandler;
use Whity\Auth\BackupCodesService;
use Whity\Auth\RoleChecker;
use Whity\Core\Audit\AuditLogger;
use Whity\Core\Identity\PasswordResetMailer;
use Whity\Core\Identity\PasswordResetService;
use Whity\Core\Identity\TwoFactorRecoveryService;
use Whity\Core\Mail\EmailLayout;
use Whity\Core\Mail\Mailer;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\Request;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;
use Whity\Core\Tenant\TenantContext;
use Whity\Database\Database;

/**
 * Real-engine integration tests for the tenant-scoped 2FA-recovery approval
 * queue (WC-password-reset-2fa-recovery).
 *
 * Drives the REAL {@see TwoFactorRecoveryApprovalsApiHandler} + a REAL
 * {@see RoleChecker} against the full migration-built schema (migrations
 * 077/079 create the table and grant `two_factor_recovery:approve` to the
 * seeded `admin` role). Proves:
 *  1. TENANT SCOPING — mirrors PasswordResetApprovalsApiRealEngineTest.
 *  2. PERMISSION GATING.
 *  3. Approve clears EXACTLY the target's 2FA fields (never the caller's) and
 *     issues a fresh, usable password-reset token; reject leaves the target
 *     completely untouched.
 *  4. The secondary admin-direct force-reset fallback (no prior request).
 */
final class TwoFactorRecoveryApprovalsApiRealEngineTest extends TestCase
{
    private const TENANT_A = 1;
    private const TENANT_B = 2;

    private const ADMIN_A = 10;
    private const ADMIN_B = 11;
    private const NOPERM_A = 12;

    private PDO $pdo;
    private TwoFactorRecoveryService $service;
    private PasswordResetService $passwordResets;
    private TwoFactorRecoveryApprovalsApiHandler $handler;

    protected function setUp(): void
    {
        RoleChecker::clearCache();
        TenantContext::reset();
        $this->pdo = $this->makeSchema();
        $this->passwordResets = new PasswordResetService($this->pdo);
        $dbWrapper = new class ($this->pdo) {
            public function __construct(private readonly PDO $pdo) {}

            /**
             * @param array<int|string, mixed> $params
             */
            public function query(string $sql, array $params = []): PDOStatement
            {
                $stmt = $this->pdo->prepare($sql);
                if ($stmt === false) {
                    throw new \RuntimeException('prepare failed');
                }
                $stmt->execute($params);

                return $stmt;
            }
        };
        $this->service = new TwoFactorRecoveryService($this->pdo, $this->passwordResets, new BackupCodesService($dbWrapper));
        $this->handler = $this->makeHandler();
    }

    protected function tearDown(): void
    {
        RoleChecker::clearCache();
        TenantContext::reset();
    }

    // ==================== list ====================

    public function testListPendingIsScopedToTheCallersOwnTenant(): void
    {
        $inA = $this->seedProfileWithTwoFactor('inA@acme.test', self::TENANT_A);
        $inB = $this->seedProfileWithTwoFactor('inB@acme.test', self::TENANT_B);
        $this->confirmRequestFor($inA);
        $this->confirmRequestFor($inB);

        TenantContext::setTenantId(self::TENANT_A);
        $res = $this->handler->listPending($this->req(self::ADMIN_A, self::TENANT_A));
        $items = $this->decode($res)['data'];
        self::assertCount(1, $items);
        self::assertSame('inA@acme.test', $items[0]['email']);
    }

    public function testListPendingRejectsCallerWithoutThePermission(): void
    {
        TenantContext::setTenantId(self::TENANT_A);
        $res = $this->handler->listPending($this->req(self::NOPERM_A, self::TENANT_A));
        self::assertSame(403, $res->getStatusCode());
    }

    // ==================== approve ====================

    public function testApproveClearsExactlyTheTargetsTwoFactorAndIssuesAUsableResetToken(): void
    {
        $target = $this->seedProfileWithTwoFactor('target@acme.test', self::TENANT_A);
        $requestId = $this->confirmRequestFor($target);

        TenantContext::setTenantId(self::TENANT_A);
        $res = $this->handler->approve($this->req(self::ADMIN_A, self::TENANT_A), ['id' => (string) $requestId]);
        self::assertSame(200, $res->getStatusCode(), $res->getBody());

        $row = $this->fetchOneRow(
            "SELECT two_factor_enabled, two_factor_secret, two_factor_backup_codes_version FROM profiles WHERE id = {$target}"
        );
        self::assertContains((string) $row['two_factor_enabled'], ['0', 'f', 'false', '']);
        self::assertNull($row['two_factor_secret']);
        self::assertSame('0', (string) $row['two_factor_backup_codes_version']);

        // Distinct audit trail: approved, 2FA cleared, reset link sent.
        self::assertSame(1, $this->auditCount('auth.2fa_recovery.approved'));
        self::assertSame(1, $this->auditCount('auth.2fa_recovery.two_factor_cleared'));
        self::assertSame(1, $this->auditCount('auth.2fa_recovery.reset_link_sent'));

        // A fresh, USABLE password-reset token now exists for the target.
        self::assertSame(1, (int) $this->col(
            "SELECT COUNT(*) FROM password_resets WHERE profile_id = {$target} AND status = 'pending'"
        ));
    }

    public function testApproveNeverTouchesTheAdminCallersOwnTwoFactor(): void
    {
        // The admin caller ALSO has 2FA enabled on their own profile — approving
        // someone else's recovery request must never touch the caller's own 2FA.
        $target = $this->seedProfileWithTwoFactor('victim@acme.test', self::TENANT_A);
        $requestId = $this->confirmRequestFor($target);
        $this->enableTwoFactorOn(self::ADMIN_A);

        TenantContext::setTenantId(self::TENANT_A);
        $this->handler->approve($this->req(self::ADMIN_A, self::TENANT_A), ['id' => (string) $requestId]);

        $adminRow = $this->fetchOneRow(
            "SELECT two_factor_enabled, two_factor_secret FROM profiles WHERE id = " . self::ADMIN_A
        );
        self::assertContains((string) $adminRow['two_factor_enabled'], ['1', 't', 'true']);
        self::assertSame('admin-own-secret', (string) $adminRow['two_factor_secret']);
    }

    public function testApproveRejectsAnotherTenantsAdmin(): void
    {
        $target = $this->seedProfileWithTwoFactor('protected2fa@acme.test', self::TENANT_A);
        $requestId = $this->confirmRequestFor($target);

        TenantContext::setTenantId(self::TENANT_B);
        $res = $this->handler->approve($this->req(self::ADMIN_B, self::TENANT_B), ['id' => (string) $requestId]);
        self::assertSame(404, $res->getStatusCode());

        $row = $this->fetchOneRow("SELECT two_factor_enabled FROM profiles WHERE id = {$target}");
        self::assertContains((string) $row['two_factor_enabled'], ['1', 't', 'true']);
    }

    // ==================== reject ====================

    public function testRejectLeavesTheTargetCompletelyUntouched(): void
    {
        $target = $this->seedProfileWithTwoFactor('reject2fa@acme.test', self::TENANT_A);
        $requestId = $this->confirmRequestFor($target);

        TenantContext::setTenantId(self::TENANT_A);
        $res = $this->handler->reject($this->req(self::ADMIN_A, self::TENANT_A), ['id' => (string) $requestId]);
        self::assertSame(200, $res->getStatusCode(), $res->getBody());

        $row = $this->fetchOneRow(
            "SELECT two_factor_enabled, two_factor_secret FROM profiles WHERE id = {$target}"
        );
        self::assertContains((string) $row['two_factor_enabled'], ['1', 't', 'true']);
        self::assertSame('encrypted-secret-blob', (string) $row['two_factor_secret']);
        self::assertSame('rejected', (string) $this->col(
            "SELECT status FROM two_factor_recovery_requests WHERE id = {$requestId}"
        ));
    }

    // ==================== secondary fallback: force-reset ====================

    public function testForceResetClearsTargetsTwoFactorWithNoPriorRequest(): void
    {
        $target = $this->seedProfileWithTwoFactor('forced@acme.test', self::TENANT_A);

        TenantContext::setTenantId(self::TENANT_A);
        $request = new Request('POST', '/api/2fa-recovery/force-reset', [], (string) json_encode(['profile_id' => $target]));
        $request->user = (object) ['profile_id' => self::ADMIN_A, 'active_tenant_id' => self::TENANT_A];

        $res = $this->handler->forceReset($request);
        self::assertSame(200, $res->getStatusCode(), $res->getBody());

        $row = $this->fetchOneRow("SELECT two_factor_enabled FROM profiles WHERE id = {$target}");
        self::assertContains((string) $row['two_factor_enabled'], ['0', 'f', 'false', '']);
        self::assertSame(1, $this->auditCount('auth.2fa_recovery.forced'));
    }

    public function testForceResetRejectsTargetInAnotherTenant(): void
    {
        $target = $this->seedProfileWithTwoFactor('crossforce@acme.test', self::TENANT_A);

        TenantContext::setTenantId(self::TENANT_B);
        $request = new Request('POST', '/api/2fa-recovery/force-reset', [], (string) json_encode(['profile_id' => $target]));
        $request->user = (object) ['profile_id' => self::ADMIN_B, 'active_tenant_id' => self::TENANT_B];

        $res = $this->handler->forceReset($request);
        self::assertSame(404, $res->getStatusCode());

        $row = $this->fetchOneRow("SELECT two_factor_enabled FROM profiles WHERE id = {$target}");
        self::assertContains((string) $row['two_factor_enabled'], ['1', 't', 'true']);
    }

    // ==================== helpers ====================

    private function confirmRequestFor(int $profileId): int
    {
        $token = $this->service->issue($profileId);
        $result = $this->service->confirm($token);
        self::assertNotNull($result);

        return $result['request_id'];
    }

    private function enableTwoFactorOn(int $profileId): void
    {
        $this->pdo->exec(
            "UPDATE profiles SET two_factor_enabled = 1, two_factor_secret = 'admin-own-secret',
                two_factor_backup_codes_version = 1 WHERE id = {$profileId}"
        );
    }

    private function auditCount(string $action): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM audit_log WHERE action = :a');
        $stmt->execute([':a' => $action]);

        return (int) $stmt->fetchColumn();
    }

    private function col(string $sql): mixed
    {
        $stmt = $this->pdo->query($sql);
        if ($stmt === false) {
            self::fail("query failed: {$sql}");
        }

        return $stmt->fetchColumn();
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

    private function seedProfileWithTwoFactor(string $email, int $tenantId): int
    {
        $this->pdo->exec(
            "INSERT INTO profiles
                (display_name, password_hash, two_factor_enabled, two_factor_secret,
                 two_factor_backup_codes_version, token_epoch, created_at, updated_at)
             VALUES ('2fa user', 'x', true, 'encrypted-secret-blob', 1, 0, datetime('now'), datetime('now'))"
        );
        $profileId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec(
            "INSERT INTO profile_emails (profile_id, email, verified, is_primary, created_at)
             VALUES ({$profileId}, '{$email}', true, true, datetime('now'))"
        );
        $this->pdo->exec(
            "INSERT INTO memberships (profile_id, tenant_id, role_id, status, created_at)
             VALUES ({$profileId}, {$tenantId}, 1, 'active', datetime('now'))"
        );

        return $profileId;
    }

    private function makeHandler(): TwoFactorRecoveryApprovalsApiHandler
    {
        $registry = new PermissionRegistry();
        $registry->registerCorePermissions();
        $roleChecker = new RoleChecker($this->databaseFor($this->pdo), $registry);

        $settings = new SettingsService(
            new GlobalSettingsRepository($this->pdo),
            new TenantSettingsRepository($this->pdo)
        );
        $settings->setGlobal(SettingsRegistry::MAIL_EVENT_PASSWORD_RESET, 'true');

        $noopMailer = new class implements Mailer {
            public function send(string $toEmail, string $subject, string $textBody, ?string $htmlBody = null): void {}
        };
        $resetMailer = new PasswordResetMailer($noopMailer, 'https://app.test/reset-password', new EmailLayout(), $settings);

        return new TwoFactorRecoveryApprovalsApiHandler(
            $this->service,
            $roleChecker,
            new AuditLogger($this->pdo, new NullLogger()),
            $resetMailer
        );
    }

    private function databaseFor(PDO $pdo): Database
    {
        $db = Database::withFactory(static fn (): PDO => $pdo, 86400, 86400);
        $db->forceConnect();

        return $db;
    }

    private function req(int $userId, int $tenantId): Request
    {
        $request = new Request('GET', '/api/2fa-recovery/pending', [], '');
        $request->user = (object) ['profile_id' => $userId, 'active_tenant_id' => $tenantId];

        return $request;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(\Whity\Sdk\Http\Response $response): array
    {
        $decoded = json_decode($response->getBody(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function makeSchema(): PDO
    {
        $pdo = SchemaFromMigrations::make(true);

        $pdo->exec("INSERT OR IGNORE INTO tenants (id, name, slug) VALUES (0, 'system', 'system')");
        $pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (" . self::TENANT_A . ", 'tenant-a', 'tenant-a')");
        $pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (" . self::TENANT_B . ", 'tenant-b', 'tenant-b')");

        $pdo->exec("INSERT OR IGNORE INTO roles (id, name, description, tenant_id, created_at) VALUES (1, 'admin', '', NULL, datetime('now'))");
        $pdo->exec("INSERT INTO roles (id, name, description, tenant_id, created_at) VALUES (101, 'no-perm', '', 0, datetime('now'))");

        $pdo->exec("
            INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at) VALUES
                (" . self::ADMIN_A . ", 'admin-a', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
                (" . self::ADMIN_B . ", 'admin-b', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
                (" . self::NOPERM_A . ", 'noperm-a', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ");

        $pdo->exec("
            INSERT INTO memberships (profile_id, tenant_id, role_id, status, created_at) VALUES
                (" . self::ADMIN_A . ", " . self::TENANT_A . ", 1,   'active', datetime('now')),
                (" . self::ADMIN_B . ", " . self::TENANT_B . ", 1,   'active', datetime('now')),
                (" . self::NOPERM_A . ", " . self::TENANT_A . ", 101, 'active', datetime('now'))
        ");

        return $pdo;
    }
}
