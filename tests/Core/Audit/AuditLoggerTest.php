<?php

declare(strict_types=1);

namespace Tests\Core\Audit;

use PDO;
use PHPUnit\Framework\TestCase;
use Whity\Core\Audit\AuditContext;
use Whity\Core\Audit\AuditLogger;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Tenant\TenantContext;

/**
 * Real-engine (in-memory SQLite) tests for {@see AuditLogger} (WC-34).
 *
 * Exercises the single audit writer against a genuine SQL engine: direct
 * record() calls, hook-driven recording, secret/PII stripping, tenant/actor
 * resolution and fail-soft behaviour. STRINGIFY_FETCHES mirrors PostgreSQL.
 */
final class AuditLoggerTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = self::makeSqliteSchema();
        TenantContext::reset();
        AuditContext::reset();
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
        AuditContext::reset();
    }

    public function testRecordPersistsAnEntryWithExplicitFields(): void
    {
        $logger = new AuditLogger($this->pdo);
        $logger->record('role.created', [
            'tenant_id' => 7,
            'actor_user_id' => 42,
            'target_type' => 'role',
            'target_id' => 13,
            'metadata' => ['name' => 'editor'],
            'ip_address' => '203.0.113.5',
        ]);

        $row = $this->onlyRow();
        $this->assertSame('7', (string) $row['tenant_id']);
        $this->assertSame('42', (string) $row['actor_user_id']);
        $this->assertSame('role.created', $row['action']);
        $this->assertSame('role', $row['target_type']);
        $this->assertSame('13', (string) $row['target_id']);
        $this->assertSame('203.0.113.5', $row['ip_address']);
        $this->assertSame(['name' => 'editor'], json_decode($row['metadata'], true));
    }

    public function testRecordResolvesTenantFromContextWhenNotGiven(): void
    {
        TenantContext::setTenantId(5);
        $logger = new AuditLogger($this->pdo);

        $logger->record('user.created', ['target_type' => 'user', 'target_id' => 1]);

        $this->assertSame('5', (string) $this->onlyRow()['tenant_id']);
    }

    public function testRecordFallsBackToSystemTenantWhenUnresolved(): void
    {
        // No TenantContext, no explicit tenant: a pre-auth action (failed login).
        $logger = new AuditLogger($this->pdo);
        $logger->record('auth.login.failure');

        $this->assertSame('0', (string) $this->onlyRow()['tenant_id'], 'Unresolved tenant must fall back to system tenant 0.');
    }

    public function testRecordResolvesActorAndIpFromAuditContext(): void
    {
        AuditContext::set(77, '198.51.100.9');
        $logger = new AuditLogger($this->pdo);

        $logger->record('tenant.updated', ['tenant_id' => 3, 'target_type' => 'tenant', 'target_id' => 3]);

        $row = $this->onlyRow();
        $this->assertSame('77', (string) $row['actor_user_id']);
        $this->assertSame('198.51.100.9', $row['ip_address']);
    }

    public function testMetadataNeverStoresSecretsOrPii(): void
    {
        $logger = new AuditLogger($this->pdo);
        $logger->record('user.created', [
            'tenant_id' => 1,
            'metadata' => [
                'email' => 'a@b.c',
                'password' => 'plaintext',
                'password_hash' => '$2y$...',
                'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
                'backup_code' => '12345',
                'totp_code' => '000111',
                'access_token' => 'eyJ...',
                'nested' => ['secret' => 'x', 'keep' => 'ok'],
            ],
        ]);

        $metadata = json_decode($this->onlyRow()['metadata'], true);

        $this->assertArrayHasKey('email', $metadata);
        $this->assertSame('ok', $metadata['nested']['keep']);
        foreach (['password', 'password_hash', 'two_factor_secret', 'backup_code', 'totp_code', 'access_token'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $metadata, "{$forbidden} must never be stored.");
        }
        $this->assertArrayNotHasKey('secret', $metadata['nested'], 'Nested secrets must be stripped too.');
    }

    public function testRecordIsFailSoftOnWriteError(): void
    {
        // A PDO with no audit_log table: the INSERT will throw, but record() must
        // swallow it so the audited action is never broken.
        $brokenPdo = new PDO('sqlite::memory:');
        $brokenPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $logger = new AuditLogger($brokenPdo);

        // No exception must escape.
        $logger->record('role.created', ['tenant_id' => 1]);
        $this->expectNotToPerformAssertions();
    }

    // ==================== Hook subscription ====================

    public function testSubscribedCrudHooksProduceAuditRows(): void
    {
        $hooks = new HookManager();
        $logger = new AuditLogger($this->pdo);
        $logger->subscribe($hooks);

        TenantContext::setTenantId(4);
        AuditContext::set(9, null);

        $hooks->dispatch('role.created', ['id' => 100, 'name' => 'editor', 'tenant_id' => 4]);
        $hooks->dispatch('user.deleted', ['id' => 200, 'tenant_id' => 4]);
        $hooks->dispatch('tenant.created', ['id' => 5, 'name' => 'Acme', 'slug' => 'acme']);

        $rows = $this->allRows();
        $this->assertCount(3, $rows);

        $byAction = [];
        foreach ($rows as $row) {
            $byAction[$row['action']] = $row;
        }

        $this->assertSame('role', $byAction['role.created']['target_type']);
        $this->assertSame('100', (string) $byAction['role.created']['target_id']);
        $this->assertSame('9', (string) $byAction['role.created']['actor_user_id']);
        // Metadata keeps the non-id context (name) and drops id/tenant_id.
        $meta = json_decode($byAction['role.created']['metadata'], true);
        $this->assertSame('editor', $meta['name']);
        $this->assertArrayNotHasKey('id', $meta);
        $this->assertArrayNotHasKey('tenant_id', $meta);

        $this->assertSame('user', $byAction['user.deleted']['target_type']);
        $this->assertSame('200', (string) $byAction['user.deleted']['target_id']);
    }

    public function testSubscribedOuRoleAssignmentRecordsRoleInMetadata(): void
    {
        $hooks = new HookManager();
        $logger = new AuditLogger($this->pdo);
        $logger->subscribe($hooks);

        TenantContext::setTenantId(2);
        $hooks->dispatch('ou.role_assigned', ['id' => 1, 'ou_id' => 50, 'role_id' => 7, 'tenant_id' => 2]);

        $row = $this->onlyRow();
        $this->assertSame('ou.role_assigned', $row['action']);
        $this->assertSame('ou', $row['target_type']);
        $this->assertSame('50', (string) $row['target_id']);
        $this->assertSame(['role_id' => 7], json_decode($row['metadata'], true));
    }

    public function testSubscribedHooksReturnDataUnchanged(): void
    {
        // A subscribed listener must not break the filter chain (returns $data).
        $hooks = new HookManager();
        (new AuditLogger($this->pdo))->subscribe($hooks);

        TenantContext::setTenantId(1);
        $payload = ['id' => 1, 'name' => 'x', 'tenant_id' => 1];
        $result = $hooks->dispatch('role.created', $payload);

        $this->assertSame($payload, $result, 'Audit listener must thread the data through unchanged.');
    }

    // ==================== Helpers ====================

    /**
     * @return array<string, mixed>
     */
    private function onlyRow(): array
    {
        $rows = $this->allRows();
        $this->assertCount(1, $rows, 'Expected exactly one audit row.');
        return $rows[0];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function allRows(): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->pdo->query('SELECT * FROM audit_log ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        return $rows;
    }

    private static function makeSqliteSchema(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, true);
        // SQLite has no NOW(); the writer uses NOW() in its INSERT.
        $pdo->sqliteCreateFunction('NOW', static fn (): string => date('Y-m-d H:i:s'), 0);

        $pdo->exec('
            CREATE TABLE audit_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id INTEGER NOT NULL,
                actor_user_id INTEGER NULL,
                action TEXT NOT NULL,
                target_type TEXT NULL,
                target_id INTEGER NULL,
                metadata TEXT NOT NULL DEFAULT \'{}\',
                ip_address TEXT NULL,
                created_at TEXT NOT NULL
            )
        ');

        return $pdo;
    }
}
