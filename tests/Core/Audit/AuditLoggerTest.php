<?php

declare(strict_types=1);

namespace Tests\Core\Audit;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
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

    // ==================== Membership grants / revocations (#889) ============

    /**
     * A membership grant targets the USER, not the role.
     *
     * The role did not change when Alice was given it; Alice's authority did.
     * This is also what keeps a person's authority history retrievable in one
     * query — `user.created`/`updated`/`deleted` already target `user`, so a
     * membership row targeted at the role would scatter one person's history
     * across N role ids with nothing able to reassemble it.
     */
    public function testMembershipGrantTargetsTheUserAndKeepsTheRoleInMetadata(): void
    {
        $hooks = new HookManager();
        (new AuditLogger($this->pdo))->subscribe($hooks);

        TenantContext::setTenantId(3);
        AuditContext::set(11, '198.51.100.9');

        $hooks->dispatch('user.membership.added', [
            'profile_id'    => 77,
            'tenant_id'     => 3,
            'membership_id' => 501,
            'role_id'       => 9,
            'role_name'     => 'manager',
            'ou_id'         => 4,
        ]);

        $row = $this->onlyRow();
        $this->assertSame('user.membership.added', $row['action']);
        $this->assertSame('user', $row['target_type'], 'a membership change happens TO the user');
        $this->assertSame('77', (string) $row['target_id'], 'target_id is the profile_id, not the role id');
        $this->assertSame('3', (string) $row['tenant_id']);
        $this->assertSame('11', (string) $row['actor_user_id']);

        $meta = json_decode($row['metadata'], true);
        $this->assertSame(9, $meta['role_id']);
        $this->assertSame('manager', $meta['role_name']);
        $this->assertSame(4, $meta['ou_id']);
        $this->assertSame(501, $meta['membership_id']);
        // Promoted to first-class columns, so never duplicated into metadata.
        $this->assertArrayNotHasKey('profile_id', $meta);
        $this->assertArrayNotHasKey('tenant_id', $meta);
    }

    /**
     * A revocation row carries everything the deleted row held.
     *
     * This is the whole point of #889: after the DELETE the membership row is
     * gone, so if the audit row does not name the role, the OU and the tenant,
     * nothing anywhere can answer "what access was taken away".
     */
    public function testMembershipRevocationRecordsWhatWasLost(): void
    {
        $hooks = new HookManager();
        (new AuditLogger($this->pdo))->subscribe($hooks);

        TenantContext::setTenantId(2);
        AuditContext::set(11, null);

        $hooks->dispatch('user.membership.removed', [
            'profile_id'    => 77,
            'tenant_id'     => 2,
            'membership_id' => 501,
            'role_id'       => 9,
            'role_name'     => 'manager',
            'ou_id'         => 4,
            'status'        => 'active',
            'granted_at'    => '2026-01-02 03:04:05',
        ]);

        $row = $this->onlyRow();
        $this->assertSame('user.membership.removed', $row['action']);
        $this->assertSame('user', $row['target_type']);
        $this->assertSame('77', (string) $row['target_id']);
        $this->assertSame('2', (string) $row['tenant_id'], 'the tenant the authority was held in');

        $meta = json_decode($row['metadata'], true);
        $this->assertSame(9, $meta['role_id']);
        $this->assertSame('manager', $meta['role_name'], 'the name survives the role being deleted later');
        $this->assertSame(4, $meta['ou_id']);
        $this->assertSame('active', $meta['status']);
        $this->assertSame('2026-01-02 03:04:05', $meta['granted_at'], 'how long the access was held');
    }

    /**
     * A grant and its revocation describe the same authority with the same
     * field names, so the pair can be matched by `role_id` rather than by
     * judgement. A trail whose two halves speak different dialects cannot be
     * read as a sequence.
     */
    public function testGrantAndRevocationShareTheirVocabulary(): void
    {
        $hooks = new HookManager();
        (new AuditLogger($this->pdo))->subscribe($hooks);
        TenantContext::setTenantId(1);

        $common = [
            'profile_id'    => 5,
            'tenant_id'     => 1,
            'membership_id' => 8,
            'role_id'       => 3,
            'role_name'     => 'editor',
            'ou_id'         => null,
        ];
        $hooks->dispatch('user.membership.added', $common);
        $hooks->dispatch('user.membership.removed', $common + ['granted_at' => '2026-02-01 00:00:00']);

        $rows = $this->allRows();
        $this->assertCount(2, $rows);

        $added = json_decode($rows[0]['metadata'], true);
        $removed = json_decode($rows[1]['metadata'], true);

        foreach (['membership_id', 'role_id', 'role_name', 'ou_id'] as $field) {
            $this->assertArrayHasKey($field, $added, "grant must carry {$field}");
            $this->assertArrayHasKey($field, $removed, "revocation must carry {$field}");
            $this->assertSame($added[$field], $removed[$field], "{$field} must mean the same thing in both");
        }

        $this->assertSame('user', $rows[0]['target_type']);
        $this->assertSame('user', $rows[1]['target_type']);
        $this->assertSame($rows[0]['target_id'], $rows[1]['target_id']);
    }

    /**
     * Every authority-affecting action about a person targets `user`, which is
     * what makes `target_type=user&target_id=N` — the filter #885 added — return
     * that person's COMPLETE authority history in one query.
     *
     * This is the concrete payoff of choosing the user over the role as the
     * target, and it is asserted rather than asserted-in-prose because it is
     * the property a future action key could silently break.
     */
    public function testOnePersonsAuthorityHistoryIsOneQuery(): void
    {
        $hooks = new HookManager();
        (new AuditLogger($this->pdo))->subscribe($hooks);
        TenantContext::setTenantId(1);

        $hooks->dispatch('user.created', ['id' => 42, 'tenant_id' => 1, 'role_id' => 2]);
        $hooks->dispatch('user.membership.added', ['profile_id' => 42, 'tenant_id' => 1, 'role_id' => 3]);
        $hooks->dispatch('user.updated', ['id' => 42, 'tenant_id' => 1, 'previous_role_id' => 2, 'role_id' => 5]);
        $hooks->dispatch('user.membership.removed', ['profile_id' => 42, 'tenant_id' => 1, 'role_id' => 3]);
        $hooks->dispatch('user.deleted', ['id' => 42, 'tenant_id' => 1]);

        $stmt = $this->pdo->prepare(
            'SELECT action FROM audit_log WHERE target_type = ? AND target_id = ? ORDER BY id'
        );
        $stmt->execute(['user', 42]);
        $actions = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $this->assertSame([
            'user.created',
            'user.membership.added',
            'user.updated',
            'user.membership.removed',
            'user.deleted',
        ], $actions, 'one target filter must return the whole authority history of one person');
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
        $pdo = SchemaFromMigrations::make(true);
        // Seed the tenants used by these tests: audit_log.tenant_id has an FK to
        // tenants (real PG enforces it; SQLite does not). Without these rows the
        // AuditLogger's write fails-closed (audit must never break the request)
        // and the row is silently dropped.
        $pdo->exec("INSERT OR IGNORE INTO tenants (id, name) VALUES
            (1, 't1'), (2, 't2'), (3, 't3'), (4, 't4'), (5, 't5'), (7, 't7')");
        return $pdo;
    }
}
