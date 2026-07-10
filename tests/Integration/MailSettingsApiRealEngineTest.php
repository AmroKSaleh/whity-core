<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\MailSettingsApiHandler;
use Whity\Auth\RoleChecker;
use Whity\Core\Mail\MailerFactory;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\Request;
use Whity\Core\Security\EncryptedSecretStore;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;
use Whity\Core\Tenant\TenantContext;
use Whity\Database\Database;

/**
 * Real-engine integration tests for the Email settings API (WC-email).
 *
 * Drives the REAL {@see MailSettingsApiHandler} + {@see SettingsService} +
 * repositories + a REAL {@see RoleChecker} + a REAL {@see EncryptedSecretStore}
 * against the migration-built schema on in-memory SQLite. Proves the
 * system-tenant + settings:manage gate, that the SMTP password is stored
 * encrypted and never returned, and the "not configured" test-send guard.
 */
final class MailSettingsApiRealEngineTest extends TestCase
{
    private const TENANT_A = 1;
    private const SYSTEM_TENANT = 0;

    // profile 10 = admin (settings:manage) in BOTH tenant 1 and system tenant 0;
    // profile 12 = no settings perms.
    private const USER_FULL = 10;
    private const USER_NONE = 12;

    private const KEY = 'unit-test-encryption-key-please-ignore-0123456789';

    private PDO $pdo;
    private GlobalSettingsRepository $globals;
    private MailSettingsApiHandler $handler;

    protected function setUp(): void
    {
        RoleChecker::clearCache();
        TenantContext::reset();
        $this->pdo = $this->makeSchema();
        $this->globals = new GlobalSettingsRepository($this->pdo);
        $this->handler = $this->makeHandler();
    }

    protected function tearDown(): void
    {
        RoleChecker::clearCache();
        TenantContext::reset();
    }

    // ==================== status ====================

    public function testStatusRejectsCallerWithoutManage(): void
    {
        TenantContext::setTenantId(self::SYSTEM_TENANT);
        $response = $this->handler->status(
            $this->req('GET', '/api/settings/mail/status', null, self::USER_NONE, self::SYSTEM_TENANT)
        );
        self::assertSame(403, $response->getStatusCode());
    }

    public function testStatusRejectsNonSystemTenantEvenWithManage(): void
    {
        // settings:manage in a regular tenant must NOT reach instance-wide mail.
        TenantContext::setTenantId(self::TENANT_A);
        $response = $this->handler->status(
            $this->req('GET', '/api/settings/mail/status', null, self::USER_FULL, self::TENANT_A)
        );
        self::assertSame(403, $response->getStatusCode());
    }

    public function testStatusReportsTransportAndNoPasswordByDefault(): void
    {
        TenantContext::setTenantId(self::SYSTEM_TENANT);
        $response = $this->handler->status(
            $this->req('GET', '/api/settings/mail/status', null, self::USER_FULL, self::SYSTEM_TENANT)
        );

        self::assertSame(200, $response->getStatusCode(), $response->getBody());
        $data = $this->decode($response)['data'];
        self::assertSame('none', $data['transport']);
        self::assertFalse($data['has_smtp_password']);
    }

    // ==================== smtp-password ====================

    public function testSetPasswordStoresEncryptedAndNeverReturnsIt(): void
    {
        TenantContext::setTenantId(self::SYSTEM_TENANT);
        $response = $this->handler->setPassword(
            $this->req('PUT', '/api/settings/mail/smtp-password', ['password' => 'hunter2'], self::USER_FULL, self::SYSTEM_TENANT)
        );

        // 204 No Content — the write-only password is never echoed back.
        self::assertSame(204, $response->getStatusCode(), $response->getBody());
        self::assertStringNotContainsString('hunter2', $response->getBody());
        // Status now reports the password is set.
        $status = $this->handler->status(
            $this->req('GET', '/api/settings/mail/status', null, self::USER_FULL, self::SYSTEM_TENANT)
        );
        self::assertTrue($this->decode($status)['data']['has_smtp_password']);

        // Stored value is ciphertext (prefixed keyId), not the plaintext.
        $stored = (string) $this->scalar(
            "SELECT value FROM app_settings WHERE setting_key = '" . MailerFactory::SMTP_PASSWORD_SETTING_KEY . "'"
        );
        self::assertNotSame('', $stored);
        self::assertStringNotContainsString('hunter2', $stored);
        // Round-trips through the same store.
        $secrets = new EncryptedSecretStore(['v1' => self::KEY], 'v1');
        self::assertSame('hunter2', $secrets->decrypt($stored));
    }

    public function testSetPasswordNullClearsIt(): void
    {
        TenantContext::setTenantId(self::SYSTEM_TENANT);
        // First set, then clear.
        $this->handler->setPassword(
            $this->req('PUT', '/api/settings/mail/smtp-password', ['password' => 'x'], self::USER_FULL, self::SYSTEM_TENANT)
        );
        $response = $this->handler->setPassword(
            $this->req('PUT', '/api/settings/mail/smtp-password', ['password' => null], self::USER_FULL, self::SYSTEM_TENANT)
        );

        self::assertSame(204, $response->getStatusCode());
        // The stored password row is gone.
        self::assertSame(
            0,
            (int) $this->scalar(
                "SELECT COUNT(*) FROM app_settings WHERE setting_key = '" . MailerFactory::SMTP_PASSWORD_SETTING_KEY . "'"
            )
        );
        // And status reflects it.
        $status = $this->handler->status(
            $this->req('GET', '/api/settings/mail/status', null, self::USER_FULL, self::SYSTEM_TENANT)
        );
        self::assertFalse($this->decode($status)['data']['has_smtp_password']);
    }

    public function testSetPasswordRequiresPasswordField(): void
    {
        TenantContext::setTenantId(self::SYSTEM_TENANT);
        $response = $this->handler->setPassword(
            $this->req('PUT', '/api/settings/mail/smtp-password', ['nope' => 'x'], self::USER_FULL, self::SYSTEM_TENANT)
        );
        self::assertSame(400, $response->getStatusCode());
    }

    // ==================== test-send ====================

    public function testTestSendRejectsInvalidEmail(): void
    {
        TenantContext::setTenantId(self::SYSTEM_TENANT);
        $response = $this->handler->test(
            $this->req('POST', '/api/settings/mail/test', ['to' => 'not-an-email'], self::USER_FULL, self::SYSTEM_TENANT)
        );
        self::assertSame(422, $response->getStatusCode());
    }

    public function testTestSendReturns422WhenEmailNotConfigured(): void
    {
        // transport defaults to 'none' → NullMailer → "not configured" guard.
        TenantContext::setTenantId(self::SYSTEM_TENANT);
        $response = $this->handler->test(
            $this->req('POST', '/api/settings/mail/test', ['to' => 'ops@example.com'], self::USER_FULL, self::SYSTEM_TENANT)
        );
        self::assertSame(422, $response->getStatusCode(), $response->getBody());
    }

    // ==================== helpers ====================

    private function makeHandler(): MailSettingsApiHandler
    {
        $service = new SettingsService($this->globals, new TenantSettingsRepository($this->pdo));

        $registry = new PermissionRegistry();
        $registry->registerCorePermissions();
        $roleChecker = new RoleChecker($this->databaseFor($this->pdo), $registry);

        return new MailSettingsApiHandler(
            $service,
            $this->globals,
            new EncryptedSecretStore(['v1' => self::KEY], 'v1'),
            $roleChecker,
            new NullLogger(),
        );
    }

    private function databaseFor(PDO $pdo): Database
    {
        $db = Database::withFactory(static fn (): PDO => $pdo, 86400, 86400);
        $db->forceConnect();

        return $db;
    }

    /**
     * @param array<string, mixed>|null $body
     */
    private function req(string $method, string $path, ?array $body, int $userId, int $tenantId): Request
    {
        $request = new Request($method, $path, [], $body !== null ? (string) json_encode($body) : '');
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

    private function scalar(string $sql): mixed
    {
        $stmt = $this->pdo->query($sql);
        self::assertNotFalse($stmt);

        return $stmt->fetchColumn();
    }

    /**
     * In-memory SQLite with the production schema; profile 10 is admin in both the
     * system tenant (0) and tenant 1, profile 12 holds no settings perms.
     */
    private function makeSchema(): PDO
    {
        $pdo = SchemaFromMigrations::make(true);

        $pdo->exec("INSERT OR IGNORE INTO tenants (id, name) VALUES (0, 'system')");
        $pdo->exec("INSERT INTO tenants (id, name) VALUES (1, 'tenant-a')");

        // admin role (1) holds settings:manage via migration 026.
        $pdo->exec("INSERT OR IGNORE INTO roles (id, name, description, tenant_id, created_at) VALUES (1, 'admin', '', NULL, datetime('now'))");
        $pdo->exec("INSERT INTO roles (id, name, description, tenant_id, created_at) VALUES (101, 'no-settings', '', 1, datetime('now'))");

        $pdo->exec("
            INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at) VALUES
                (10, 'admin', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
                (12, 'none',  'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ");
        // profile 10 is admin in BOTH the system tenant (0) and tenant 1, so the
        // system-tenant gate can be proven from a genuinely privileged caller.
        $pdo->exec("
            INSERT INTO memberships (profile_id, tenant_id, role_id, status, created_at) VALUES
                (10, 0, 1,   'active', datetime('now')),
                (10, 1, 1,   'active', datetime('now')),
                (12, 0, 101, 'active', datetime('now'))
        ");

        return $pdo;
    }
}
