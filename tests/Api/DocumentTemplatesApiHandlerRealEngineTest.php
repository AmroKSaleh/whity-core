<?php

declare(strict_types=1);

namespace Tests\Api;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\DocumentTemplatesApiHandler;
use Whity\Auth\RoleChecker;
use Whity\Database\Database;
use Whity\Core\Document\DocumentAccessPolicy;
use Whity\Core\Document\DocumentTemplateRepository;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\Request;
use Whity\Core\Tenant\TenantContext;

/**
 * Real-engine tests for {@see DocumentTemplatesApiHandler} (WC-docdesigner): the
 * server-side RBAC visibility filter (personal=owner, system=all, tenant-gated by
 * required_permission — a caller lacking the tag never receives the row), the
 * publish gate (documents:publish), CRUD, and 404-not-403 for hidden rows.
 */
final class DocumentTemplatesApiHandlerRealEngineTest extends TestCase
{
    private const TENANT = 1;

    // Seeded profiles.
    private const OWNER   = 10; // admin role → read/write/publish (migration 060), NOT the contracts tag
    private const VIEWER  = 11; // read only, no publish, no contracts tag
    private const WRITER  = 12; // read+write, NO publish
    private const MANAGER = 13; // read + documents:use:contracts (the gated tag), no publish

    private const CONTRACTS_PERM = 'documents:use:contracts';

    private PDO $pdo;
    private DocumentTemplatesApiHandler $handler;

    protected function setUp(): void
    {
        RoleChecker::clearCache();
        $this->pdo = $this->makeSchema();
        $db = $this->wrapSqlite($this->pdo);
        $this->handler = new DocumentTemplatesApiHandler(
            new DocumentTemplateRepository($this->pdo),
            new DocumentAccessPolicy(),
            new RoleChecker($db, new PermissionRegistry())
        );
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
        RoleChecker::clearCache();
    }

    // ── visibility ──────────────────────────────────────────────────────────

    public function testPersonalTemplateVisibleOnlyToItsCreator(): void
    {
        $id = $this->create(self::OWNER, ['name' => 'Mine', 'data' => ['version' => 2]]);
        self::assertSame(201, $id->getStatusCode(), $id->getBody());

        self::assertCount(1, $this->list(self::OWNER), 'owner sees own personal');
        self::assertCount(0, $this->list(self::VIEWER), 'another user does not see a personal template');
    }

    public function testSystemScopeVisibleToEveryone(): void
    {
        $this->create(self::OWNER, ['name' => 'Starter', 'data' => ['version' => 2], 'scope' => 'system']);
        self::assertCount(1, $this->list(self::VIEWER), 'system-scope templates are visible to all in the tenant');
    }

    public function testTenantScopeGatedByRequiredPermission(): void
    {
        // Owner (has publish) creates a tenant-wide template gated on the contracts tag.
        $res = $this->create(self::OWNER, [
            'name' => 'Contract', 'data' => ['version' => 2],
            'scope' => 'tenant', 'required_permission' => self::CONTRACTS_PERM,
        ]);
        self::assertSame(201, $res->getStatusCode(), $res->getBody());

        // The manager holds the tag → sees it; the viewer does not → never receives it.
        self::assertCount(1, $this->list(self::MANAGER), 'a holder of the required permission sees the gated template');
        self::assertCount(0, $this->list(self::VIEWER), 'a technician without the tag never receives the gated template');
    }

    public function testHiddenTemplateShowReturns404NotForbidden(): void
    {
        $id = $this->decodeId($this->create(self::OWNER, [
            'name' => 'Contract', 'data' => ['version' => 2],
            'scope' => 'tenant', 'required_permission' => self::CONTRACTS_PERM,
        ]));

        $res = $this->show(self::VIEWER, $id);
        self::assertSame(404, $res->getStatusCode(), 'a gated row must 404 (not 403) to a caller who may not see it');
    }

    // ── publish gate ──────────────────────────────────────────────────────────

    public function testPublishingSharedScopeRequiresPublishPermission(): void
    {
        // WRITER has documents:write (route) but NOT documents:publish → 403 on a shared scope.
        $res = $this->create(self::WRITER, ['name' => 'Shared', 'data' => ['version' => 2], 'scope' => 'tenant']);
        self::assertSame(403, $res->getStatusCode());

        // Personal scope is fine without publish.
        self::assertSame(201, $this->create(self::WRITER, ['name' => 'Mine', 'data' => ['version' => 2]])->getStatusCode());
    }

    public function testUpdatingAPersonalTemplateToSharedNeedsPublish(): void
    {
        $id = $this->decodeId($this->create(self::WRITER, ['name' => 'Mine', 'data' => ['version' => 2]]));
        $res = $this->patch(self::WRITER, $id, ['scope' => 'tenant']);
        self::assertSame(403, $res->getStatusCode(), 'promoting to a shared scope is a publish action');
    }

    // ── CRUD + validation ─────────────────────────────────────────────────────

    public function testCreateValidatesNameAndData(): void
    {
        self::assertSame(422, $this->create(self::OWNER, ['name' => '', 'data' => ['v' => 2]])->getStatusCode());
        self::assertSame(422, $this->create(self::OWNER, ['name' => 'x', 'data' => []])->getStatusCode());
        self::assertSame(422, $this->create(self::OWNER, ['name' => 'x', 'data' => ['v' => 2], 'scope' => 'nope'])->getStatusCode());
    }

    public function testOwnerUpdatesAndDeletes(): void
    {
        $id = $this->decodeId($this->create(self::OWNER, ['name' => 'A', 'data' => ['version' => 2]]));
        self::assertSame(200, $this->patch(self::OWNER, $id, ['name' => 'A2'])->getStatusCode());
        self::assertSame(204, $this->delete(self::OWNER, $id)->getStatusCode());
        self::assertSame(404, $this->show(self::OWNER, $id)->getStatusCode());
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    private function actAs(int $userId): Request
    {
        TenantContext::reset();
        TenantContext::setTenantId(self::TENANT);
        $req = new Request('GET', '/api/document-templates', [], '');
        $req->user = (object) ['profile_id' => $userId, 'active_tenant_id' => self::TENANT];
        return $req;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function reqWithBody(int $userId, array $body): Request
    {
        TenantContext::reset();
        TenantContext::setTenantId(self::TENANT);
        $req = new Request('POST', '/api/document-templates', [], (string) json_encode($body));
        $req->user = (object) ['profile_id' => $userId, 'active_tenant_id' => self::TENANT];
        return $req;
    }

    /** @param array<string, mixed> $body */
    private function create(int $userId, array $body): \Whity\Sdk\Http\Response
    {
        return $this->handler->create($this->reqWithBody($userId, $body));
    }

    /** @return list<array<string,mixed>> */
    private function list(int $userId): array
    {
        $res = $this->handler->list($this->actAs($userId));
        $d = json_decode($res->getBody(), true);
        self::assertIsArray($d);
        return $d['data'] ?? [];
    }

    private function show(int $userId, int $id): \Whity\Sdk\Http\Response
    {
        return $this->handler->show($this->actAs($userId), ['id' => (string) $id]);
    }

    /** @param array<string, mixed> $body */
    private function patch(int $userId, int $id, array $body): \Whity\Sdk\Http\Response
    {
        return $this->handler->update($this->reqWithBody($userId, $body), ['id' => (string) $id]);
    }

    private function delete(int $userId, int $id): \Whity\Sdk\Http\Response
    {
        return $this->handler->delete($this->actAs($userId), ['id' => (string) $id]);
    }

    private function decodeId(\Whity\Sdk\Http\Response $res): int
    {
        self::assertSame(201, $res->getStatusCode(), $res->getBody());
        $d = json_decode($res->getBody(), true);
        return (int) $d['data']['id'];
    }

    private function makeSchema(): PDO
    {
        $pdo = SchemaFromMigrations::make(true);
        $pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'a', 'a')");

        // admin role (1) is seeded + granted documents:* by migration 060. Custom
        // tenant roles: viewer (read), writer (read+write), manager (read+contracts tag).
        $pdo->exec("INSERT OR IGNORE INTO roles (id, name, description, tenant_id, created_at) VALUES (1, 'admin', '', NULL, datetime('now'))");
        $pdo->exec("INSERT INTO roles (id, name, description, tenant_id, created_at) VALUES
            (101, 'viewer', '', 1, datetime('now')),
            (102, 'writer', '', 1, datetime('now')),
            (103, 'manager', '', 1, datetime('now'))");

        $this->grant($pdo, 101, 'documents:read');
        $this->grant($pdo, 102, 'documents:read');
        $this->grant($pdo, 102, 'documents:write');
        $this->grant($pdo, 103, 'documents:read');
        $this->grant($pdo, 103, self::CONTRACTS_PERM); // the gated tag

        $pdo->exec("
            INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at) VALUES
                (10, 'owner',   'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
                (11, 'viewer',  'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
                (12, 'writer',  'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
                (13, 'manager', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ");
        $pdo->exec("
            INSERT INTO memberships (id, profile_id, tenant_id, role_id, status, created_at) VALUES
                (1000, 10, 1, 1,   'active', datetime('now')),
                (1001, 11, 1, 101, 'active', datetime('now')),
                (1002, 12, 1, 102, 'active', datetime('now')),
                (1003, 13, 1, 103, 'active', datetime('now'))
        ");
        return $pdo;
    }

    private function grant(PDO $pdo, int $roleId, string $permission): void
    {
        $pdo->prepare('INSERT OR IGNORE INTO permissions (name, description, created_at) VALUES (?, ?, NOW())')
            ->execute([$permission, '']);
        $sel = $pdo->prepare('SELECT id FROM permissions WHERE name = ?');
        $sel->execute([$permission]);
        $pid = (int) $sel->fetchColumn();
        $pdo->prepare('INSERT OR IGNORE INTO role_permissions (role_id, permission_id, created_at) VALUES (?, ?, NOW())')
            ->execute([$roleId, $pid]);
    }

    private function wrapSqlite(PDO $pdo): Database
    {
        $db = Database::withFactory(static fn (): PDO => $pdo);
        $db->setMaxLifetimeSeconds(86400);
        $db->setPingIntervalSeconds(86400);
        $db->forceConnect();
        return $db;
    }
}
