<?php

declare(strict_types=1);

namespace Tests\Api;

use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\AdminPasswordResetApiHandler;
use Whity\Auth\RoleChecker;
use Whity\Core\Audit\AuditLogger;
use Whity\Core\Identity\PasswordResetMailer;
use Whity\Core\Identity\PasswordResetService;
use Whity\Core\Identity\ProfileEmailRepository;
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
 * Real-engine tests for the administrator-side password-reset surface
 * (WC-797 §1 and §4a).
 *
 * Two properties matter most here and both are asserted directly:
 *  1. the administrator triggers a LINK, never a password — the target's
 *     `password_hash` and `token_epoch` are untouched by the call, and no
 *     credential ever appears in the response;
 *  2. the approver-coverage answer is tenant-scoped, so the "you are about to
 *     strand this tenant" warning cannot be driven by another tenant's roster.
 */
final class AdminPasswordResetApiHandlerRealEngineTest extends TestCase
{
    private const SYSTEM_TENANT = 0;
    private const TENANT_A = 1;
    private const TENANT_B = 2;

    private const ADMIN_A = 10;
    private const ADMIN_SYS = 11;
    private const MEMBER_A = 12;
    private const MEMBER_B = 13;

    private PDO $pdo;
    private PasswordResetService $service;
    private SettingsService $settings;
    /** @var Mailer&object{sent: list<array{to: string, subject: string, body: string, html: ?string}>} */
    private Mailer $mailer;
    private AdminPasswordResetApiHandler $handler;

    protected function setUp(): void
    {
        RoleChecker::clearCache();
        TenantContext::reset();

        $this->pdo = $this->makeSchema();
        $this->service = new PasswordResetService($this->pdo);
        $this->settings = new SettingsService(
            new GlobalSettingsRepository($this->pdo),
            new TenantSettingsRepository($this->pdo)
        );
        $this->settings->setGlobal(SettingsRegistry::MAIL_EVENT_PASSWORD_RESET, 'true');
        $this->settings->setGlobal(SettingsRegistry::PASSWORD_RESET_APPROVAL_REQUIRED, 'true');

        $this->mailer = new class implements Mailer {
            /** @var list<array{to: string, subject: string, body: string, html: ?string}> */
            public array $sent = [];

            public function send(string $toEmail, string $subject, string $textBody, ?string $htmlBody = null): void
            {
                $this->sent[] = ['to' => $toEmail, 'subject' => $subject, 'body' => $textBody, 'html' => $htmlBody];
            }
        };

        $registry = new PermissionRegistry();
        $registry->registerCorePermissions();

        $this->handler = new AdminPasswordResetApiHandler(
            $this->pdo,
            $this->service,
            new PasswordResetMailer($this->mailer, 'https://app.test/reset-password', new EmailLayout(), $this->settings),
            new ProfileEmailRepository($this->pdo),
            new AuditLogger($this->pdo, new NullLogger()),
            $this->settings,
            new RoleChecker($this->databaseFor($this->pdo), $registry),
        );
    }

    protected function tearDown(): void
    {
        RoleChecker::clearCache();
        TenantContext::reset();
    }

    // ==================== §1 — admin-triggered reset link ====================

    public function testSendsAResetLinkForAProfileInTheCallersTenant(): void
    {
        TenantContext::setTenantId(self::TENANT_A);

        $res = $this->sendLink(self::MEMBER_A, self::ADMIN_A);
        self::assertSame(202, $res->getStatusCode(), $res->getBody());

        self::assertCount(1, $this->mailer->sent);
        self::assertSame('member-a@acme.test', $this->mailer->sent[0]['to']);
        self::assertStringContainsString('https://app.test/reset-password?token=', $this->mailer->sent[0]['body']);
        self::assertSame(1, $this->auditCount('auth.password_reset.admin_requested'));
    }

    public function testTheLinkIsMintedByTheSharedServiceSoOnlyItsHashIsStored(): void
    {
        TenantContext::setTenantId(self::TENANT_A);
        $this->sendLink(self::MEMBER_A, self::ADMIN_A);

        $row = $this->row('SELECT token_hash, status, consumed_at FROM password_resets WHERE profile_id = ' . self::MEMBER_A);
        self::assertSame('pending', (string) $row['status']);
        self::assertNull($row['consumed_at']);
        self::assertSame(64, strlen((string) $row['token_hash']));

        // The raw token exists only inside the mail. Nothing about it reaches
        // the administrator who pressed the button.
        preg_match('/token=([0-9a-f]+)/', $this->mailer->sent[0]['body'], $m);
        $rawToken = (string) ($m[1] ?? '');
        self::assertNotSame('', $rawToken);
        self::assertSame(hash('sha256', $rawToken), (string) $row['token_hash']);
    }

    public function testNeverChangesTheTargetsCredentialItself(): void
    {
        $before = $this->row('SELECT password_hash, token_epoch FROM profiles WHERE id = ' . self::MEMBER_A);

        TenantContext::setTenantId(self::TENANT_A);
        $res = $this->sendLink(self::MEMBER_A, self::ADMIN_A);

        $after = $this->row('SELECT password_hash, token_epoch FROM profiles WHERE id = ' . self::MEMBER_A);
        self::assertSame($before['password_hash'], $after['password_hash']);
        self::assertSame((string) $before['token_epoch'], (string) $after['token_epoch']);
        self::assertStringNotContainsString('password', strtolower($res->getBody()));
    }

    public function testSupersedesAnyOutstandingTokenForTheSameProfile(): void
    {
        TenantContext::setTenantId(self::TENANT_A);
        $this->service->issue(self::MEMBER_A);
        $this->sendLink(self::MEMBER_A, self::ADMIN_A);

        self::assertSame(1, (int) $this->col(
            'SELECT COUNT(*) FROM password_resets WHERE profile_id = ' . self::MEMBER_A . ' AND consumed_at IS NULL'
        ));
    }

    public function testTargetOutsideTheCallersTenantIsNotFound(): void
    {
        TenantContext::setTenantId(self::TENANT_A);

        $res = $this->sendLink(self::MEMBER_B, self::ADMIN_A);
        self::assertSame(404, $res->getStatusCode());
        self::assertSame([], $this->mailer->sent);
        self::assertSame(0, (int) $this->col('SELECT COUNT(*) FROM password_resets'));
    }

    public function testSystemTenantMayTriggerForAnyTenantsProfile(): void
    {
        TenantContext::setTenantId(self::SYSTEM_TENANT);

        $res = $this->sendLink(self::MEMBER_B, self::ADMIN_SYS);
        self::assertSame(202, $res->getStatusCode(), $res->getBody());
        self::assertSame('member-b@acme.test', $this->mailer->sent[0]['to']);
    }

    public function testProfileWithoutAnAddressToMailIsRejected(): void
    {
        TenantContext::setTenantId(self::TENANT_A);
        $this->pdo->exec('DELETE FROM profile_emails WHERE profile_id = ' . self::MEMBER_A);

        $res = $this->sendLink(self::MEMBER_A, self::ADMIN_A);
        self::assertSame(422, $res->getStatusCode());
        self::assertSame(0, (int) $this->col('SELECT COUNT(*) FROM password_resets'));
    }

    public function testRefusesWhenPasswordResetMailIsDisabledRatherThanSilentlyDoingNothing(): void
    {
        $this->settings->setGlobal(SettingsRegistry::MAIL_EVENT_PASSWORD_RESET, 'false');
        TenantContext::setTenantId(self::TENANT_A);

        $res = $this->sendLink(self::MEMBER_A, self::ADMIN_A);
        self::assertSame(409, $res->getStatusCode());
        self::assertSame([], $this->mailer->sent);
        self::assertSame(0, (int) $this->col('SELECT COUNT(*) FROM password_resets'), 'no orphan token when nothing can be delivered');
    }

    public function testUnknownProfileIsNotFound(): void
    {
        TenantContext::setTenantId(self::TENANT_A);
        self::assertSame(404, $this->sendLink(99999, self::ADMIN_A)->getStatusCode());
    }

    // ==================== §4a — approver coverage ====================

    public function testApproverCoverageReportsTheCallersTenantRoster(): void
    {
        TenantContext::setTenantId(self::TENANT_A);

        $res = $this->handler->approverCoverage($this->req(self::ADMIN_A));
        self::assertSame(200, $res->getStatusCode(), $res->getBody());

        $data = $this->decode($res)['data'];
        self::assertSame(self::TENANT_A, $data['tenant_id']);
        self::assertSame(2, $data['minimum_recommended']);
        self::assertTrue($data['approval_required']);
        self::assertSame([self::ADMIN_A], $data['approver_profile_ids']);
        self::assertSame(['admin'], $data['approver_role_names']);
        self::assertSame(1, $data['approver_count']);
        self::assertTrue($data['below_minimum'], 'one approver is exactly the strandable state');
    }

    public function testApproverCoverageClearsTheWarningOnceASecondApproverExists(): void
    {
        $this->pdo->exec(
            "INSERT INTO memberships (profile_id, tenant_id, role_id, status, is_primary, created_at)
             VALUES (" . self::MEMBER_A . ", " . self::TENANT_A . ", 1, 'active', false, datetime('now'))"
        );
        RoleChecker::clearCache();
        TenantContext::setTenantId(self::TENANT_A);

        $data = $this->decode($this->handler->approverCoverage($this->req(self::ADMIN_A)))['data'];
        self::assertSame(2, $data['approver_count']);
        self::assertFalse($data['below_minimum']);
    }

    public function testApproverCoverageNeverCountsAnotherTenantsApprovers(): void
    {
        TenantContext::setTenantId(self::TENANT_B);

        $data = $this->decode($this->handler->approverCoverage($this->req(self::MEMBER_B)))['data'];
        self::assertSame(self::TENANT_B, $data['tenant_id']);
        self::assertSame([], $data['approver_profile_ids']);
        self::assertSame([], $data['approver_role_names']);
        self::assertSame(0, $data['approver_count']);
        self::assertTrue($data['below_minimum']);
    }

    public function testApproverCoverageIgnoresInactiveMemberships(): void
    {
        $this->pdo->exec(
            'UPDATE memberships SET status = \'inactive\' WHERE profile_id = ' . self::ADMIN_A
            . ' AND tenant_id = ' . self::TENANT_A
        );
        RoleChecker::clearCache();
        TenantContext::setTenantId(self::TENANT_A);

        $data = $this->decode($this->handler->approverCoverage($this->req(self::ADMIN_A)))['data'];
        self::assertSame(0, $data['approver_count']);
    }

    // ==================== helpers ====================

    private function sendLink(int $targetProfileId, int $actorProfileId): \Whity\Sdk\Http\Response
    {
        return $this->handler->sendResetLink(
            $this->req($actorProfileId, 'POST', "/api/users/{$targetProfileId}/password-reset"),
            ['id' => (string) $targetProfileId]
        );
    }

    private function req(int $profileId, string $method = 'GET', string $path = '/api/password-resets/approver-coverage'): Request
    {
        $request = new Request($method, $path, [], '');
        $request->user = (object) ['profile_id' => $profileId];

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

    /**
     * @return array<string, mixed>
     */
    private function row(string $sql): array
    {
        $stmt = $this->pdo->query($sql);
        if ($stmt === false) {
            self::fail("query failed: {$sql}");
        }
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);

        return $row;
    }

    private function col(string $sql): mixed
    {
        $stmt = $this->pdo->query($sql);
        if ($stmt === false) {
            self::fail("query failed: {$sql}");
        }

        return $stmt->fetchColumn();
    }

    private function auditCount(string $action): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM audit_log WHERE action = :a');
        $stmt->execute([':a' => $action]);

        return (int) $stmt->fetchColumn();
    }

    private function databaseFor(PDO $pdo): Database
    {
        $db = Database::withFactory(static fn (): PDO => $pdo, 86400, 86400);
        $db->forceConnect();

        return $db;
    }

    /**
     * Two tenants. Tenant A has exactly ONE account holding
     * `password_resets:approve` (via the seeded admin role, migration 078) —
     * the strandable shape §4a warns about. Tenant B has none.
     */
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
                (" . self::ADMIN_A . ", 'admin-a', 'hash-a', false, 0, 3, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
                (" . self::ADMIN_SYS . ", 'admin-sys', 'hash-sys', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
                (" . self::MEMBER_A . ", 'member-a', 'hash-m-a', false, 0, 7, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
                (" . self::MEMBER_B . ", 'member-b', 'hash-m-b', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ");

        $pdo->exec("
            INSERT INTO profile_emails (profile_id, email, verified, is_primary, created_at) VALUES
                (" . self::ADMIN_A . ", 'admin-a@acme.test', true, true, datetime('now')),
                (" . self::ADMIN_SYS . ", 'admin-sys@acme.test', true, true, datetime('now')),
                (" . self::MEMBER_A . ", 'member-a@acme.test', true, true, datetime('now')),
                (" . self::MEMBER_B . ", 'member-b@acme.test', true, true, datetime('now'))
        ");

        $pdo->exec("
            INSERT INTO memberships (profile_id, tenant_id, role_id, status, created_at) VALUES
                (" . self::ADMIN_A . ", " . self::TENANT_A . ", 1,   'active', datetime('now')),
                (" . self::ADMIN_SYS . ", " . self::SYSTEM_TENANT . ", 1, 'active', datetime('now')),
                (" . self::MEMBER_A . ", " . self::TENANT_A . ", 101, 'active', datetime('now')),
                (" . self::MEMBER_B . ", " . self::TENANT_B . ", 101, 'active', datetime('now'))
        ");

        return $pdo;
    }
}
