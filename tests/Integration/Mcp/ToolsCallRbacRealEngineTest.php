<?php

declare(strict_types=1);

namespace Tests\Integration\Mcp;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Auth\JwtParser;
use Whity\Auth\RoleChecker;
use Whity\Auth\TokenValidator;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\Router;
use Whity\Core\Tenant\TenantContext;
use Whity\Database\Database;
use Whity\Mcp\Auth\McpTokenService;
use Whity\Mcp\JsonRpc\Dispatcher;
use Whity\Mcp\JsonRpc\ErrorCode;
use Whity\Mcp\Tools\ToolDeriver;
use Whity\Mcp\Tools\ToolsCallHandler;
use Whity\Mcp\Tools\ToolsListHandler;
use Whity\Sdk\Http\Request as SdkRequest;
use Whity\Sdk\Http\Response as SdkResponse;

/**
 * Real-engine per-call RBAC for the MCP tools surface (WC-31468883).
 *
 * Unlike {@see \Tests\Unit\Mcp\Tools\ToolsCallHandlerTest}, which MOCKS the
 * RoleChecker to assert the handler *calls* it, this suite wires the PRODUCTION
 * authorization stack — a real {@see RoleChecker} resolving real
 * roles/permissions/role_permissions/OU rows on a from-migrations SQLite engine,
 * a real {@see Router} carrying the route permissions, real MCP tokens minted by
 * {@see McpTokenService}, and the real {@see Dispatcher} that binds
 * {@see TenantContext} from the validated principal — and drives the whole thing
 * through `tools/call` and `tools/list` exactly as an AI client would.
 *
 * This proves the guarantees the task calls out end-to-end, against a real DB:
 *  - read-can't-write: a `:read`-only principal is FORBIDDEN on a `:write` tool;
 *  - denied: a principal with no grant is FORBIDDEN;
 *  - granted: a `:write` principal reaches the handler;
 *  - each tool maps to ITS route's required permission (single source: the Router);
 *  - Router-contributed routes (the plugin contribution mechanism) are first-class,
 *    invocable MCP tools whose permission is still enforced;
 *  - tools/list capability filtering agrees with tools/call enforcement because
 *    both consult the same real RoleChecker;
 *  - cross-tenant rejection / no tenant-0 leak: a grant reached through a
 *    tenant-scoped OU never authorizes a call made under a DIFFERENT tenant id
 *    (including a forged system-tenant token).
 */
final class ToolsCallRbacRealEngineTest extends TestCase
{
    private const JWT_SECRET = 'mcp-rbac-real-engine-secret-padded-32c!!';
    private const TENANT_A   = 10;
    private const TENANT_B   = 20;
    private const SYSTEM_TENANT = 0;

    private JwtParser $jwtParser;
    private PDO $pdo;
    private TokenValidator $validator;
    private McpTokenService $tokens;
    private Dispatcher $dispatcher;

    /** @var array<string, int> email => user id */
    private array $userIds = [];

    /** @var array<string, int> email => profile id (for mcp_tokens issuance after migration 040) */
    private array $profileIds = [];

    protected function setUp(): void
    {
        ToolDeriver::clearCache();
        RoleChecker::clearCache();
        TenantContext::reset();

        $this->jwtParser = new JwtParser(self::JWT_SECRET);
        $this->pdo       = SchemaFromMigrations::make();
        $this->seedFixtures();

        $this->validator = new TokenValidator($this->jwtParser, $this->pdo);
        $this->tokens    = new McpTokenService($this->pdo, $this->jwtParser);

        $registry = new PermissionRegistry();
        // Mirror plugin permission registration: a non-core source contributing
        // its own resource:action permissions so RoleChecker::hasPermission()
        // (which rejects unregistered permissions outright) recognises them.
        $registry->register('widgets', ['widgets:read', 'widgets:write']);
        $roleChecker = new RoleChecker($this->wrapSqlite($this->pdo), $registry);

        $router = $this->buildRouter();
        // Pass NO static declarations: every tool is sourced from the Router,
        // exactly as a plugin-contributed route is discovered at derive time.
        $toolDeriver = new ToolDeriver([], [], $router);

        $this->dispatcher = new Dispatcher([
            'tools/list' => new ToolsListHandler($toolDeriver, $roleChecker, $this->validator),
            'tools/call' => new ToolsCallHandler($toolDeriver, $router, $roleChecker, $this->validator),
        ], $this->validator);
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
        ToolDeriver::clearCache();
        RoleChecker::clearCache();
    }

    // ── Per-call RBAC: read-can't-write / denied / granted ──────────────────────

    public function testReadOnlyPrincipal_canCallReadTool(): void
    {
        $token = $this->mcpTokenFor('reader@a.test', self::TENANT_A);
        $r = $this->callTool($token, 'list_widgets', []);

        self::assertArrayHasKey('result', $r, 'read tool must execute for a :read principal');
        self::assertFalse($r['result']['isError'], 'handler returned 200, so isError must be false');
    }

    public function testReadOnlyPrincipal_cannotCallWriteTool(): void
    {
        $token = $this->mcpTokenFor('reader@a.test', self::TENANT_A);
        $r = $this->callTool($token, 'create_widgets', ['name' => 'x']);

        // A :read grant must never satisfy a :write tool's route permission.
        self::assertSame(ErrorCode::FORBIDDEN, $r['error']['code']);
    }

    public function testPrincipalWithNoGrants_isForbiddenOnProtectedTool(): void
    {
        $token = $this->mcpTokenFor('none@a.test', self::TENANT_A);
        $r = $this->callTool($token, 'list_widgets', []);

        self::assertSame(ErrorCode::FORBIDDEN, $r['error']['code']);
    }

    public function testWriteGrantedPrincipal_canCallWriteTool(): void
    {
        $token = $this->mcpTokenFor('writer@a.test', self::TENANT_A);
        $r = $this->callTool($token, 'create_widgets', ['name' => 'gadget']);

        self::assertArrayHasKey('result', $r, ':write principal must reach the handler');
        self::assertFalse($r['result']['isError']);
    }

    public function testOpenTool_isCallableByAnyAuthenticatedPrincipal(): void
    {
        // ping_widgets carries no required permission; even a no-grant principal runs it.
        $token = $this->mcpTokenFor('none@a.test', self::TENANT_A);
        $r = $this->callTool($token, 'ping_widgets', []);

        self::assertArrayHasKey('result', $r);
        self::assertFalse($r['result']['isError']);
    }

    // ── Each tool maps to ITS route's required permission ───────────────────────

    public function testEachToolEnforcesItsOwnRoutePermission(): void
    {
        // The reader holds widgets:read ONLY. Granting that single permission must
        // flip exactly the read tool — not the write tool — proving the per-tool
        // permission is read from each tool's own route declaration.
        $token = $this->mcpTokenFor('reader@a.test', self::TENANT_A);

        $read  = $this->callTool($token, 'list_widgets', []);
        $write = $this->callTool($token, 'create_widgets', ['name' => 'x']);

        self::assertArrayHasKey('result', $read, 'widgets:read should authorize the read tool');
        self::assertSame(
            ErrorCode::FORBIDDEN,
            $write['error']['code'],
            'widgets:read must NOT authorize the write tool',
        );
    }

    // ── Router-contributed (plugin-mechanism) routes are first-class tools ──────

    public function testRouterContributedRoute_isAdvertisedAndInvocableWithRbac(): void
    {
        // Tools here come exclusively from the Router (ToolDeriver static list is
        // empty) — the same path plugin routes flow through. A Router-contributed
        // tool must be both advertised AND enforced.
        $writer = $this->mcpTokenFor('writer@a.test', self::TENANT_A);

        $names = $this->listToolNames($writer);
        self::assertContains('create_widgets', $names, 'Router-sourced route must appear as a tool');

        $r = $this->callTool($writer, 'create_widgets', ['name' => 'plugin-made']);
        self::assertFalse($r['result']['isError'], 'Router-sourced tool must be invocable end-to-end');
    }

    // ── Capability filtering agrees with call-time enforcement ──────────────────

    public function testToolsList_isScopedToCallerEffectivePermissions(): void
    {
        $readerNames = $this->listToolNames($this->mcpTokenFor('reader@a.test', self::TENANT_A));
        // Open + granted tools visible; the ungranted write tool is hidden.
        self::assertContains('ping_widgets', $readerNames);
        self::assertContains('list_widgets', $readerNames);
        self::assertNotContains('create_widgets', $readerNames, 'write tool must be hidden from a :read principal');

        $writerNames = $this->listToolNames($this->mcpTokenFor('writer@a.test', self::TENANT_A));
        self::assertContains('create_widgets', $writerNames, 'write tool visible once :write is granted');
        // A :write grant does not imply :read — the read tool stays hidden,
        // confirming list filtering is per-tool, not all-or-nothing.
        self::assertNotContains('list_widgets', $writerNames);
    }

    public function testToolsList_listAndCallAgree_forSamePrincipal(): void
    {
        $token = $this->mcpTokenFor('reader@a.test', self::TENANT_A);
        $names = $this->listToolNames($token);

        // Every tool advertised to this principal must actually be callable
        // (not FORBIDDEN), because both surfaces consult the same RoleChecker.
        foreach ($names as $name) {
            $r = $this->callTool($token, $name, []);
            self::assertArrayHasKey(
                'result',
                $r,
                "Tool '{$name}' was advertised by tools/list but rejected by tools/call",
            );
        }
    }

    // ── Cross-tenant rejection / no tenant-0 leak (tenant binding) ──────────────

    public function testOuGrant_authorizesUnderItsOwnTenant(): void
    {
        // ou_user inherits widgets:write via an OU role assignment in tenant A.
        $tokenA = $this->mcpTokenFor('ou@a.test', self::TENANT_A);
        $r = $this->callTool($tokenA, 'create_widgets', ['name' => 'via-ou']);

        self::assertArrayHasKey('result', $r, 'OU-inherited :write must authorize under the OU tenant');
        self::assertFalse($r['result']['isError']);
    }

    public function testOuGrant_doesNotLeakToADifferentTenant(): void
    {
        // Same profile, token claiming tenant B. The profile has no active membership
        // in tenant B, so the ActiveTenantMembershipGuard rejects the token at auth
        // (UNAUTHENTICATED). This is strictly MORE secure than failing at RBAC —
        // the caller cannot even probe whether the tool exists.
        $tokenB = $this->mcpTokenFor('ou@a.test', self::TENANT_B);
        $r = $this->callTool($tokenB, 'create_widgets', ['name' => 'cross-tenant']);

        self::assertArrayHasKey('error', $r, 'cross-tenant token must be rejected');
        self::assertContains(
            $r['error']['code'],
            [ErrorCode::UNAUTHENTICATED, ErrorCode::FORBIDDEN],
            'a tenant-A OU grant must not authorize a call made under tenant B',
        );
    }

    public function testOuGrant_doesNotLeakToSystemTenant(): void
    {
        // A forged system-tenant (id 0) token: the profile has no membership under
        // system tenant (id 0), so the membership guard rejects at auth.
        // RoleChecker under tenant 0 would also find no grants, but the auth gate fires first.
        $token0 = $this->mcpTokenFor('ou@a.test', self::SYSTEM_TENANT);
        $r = $this->callTool($token0, 'create_widgets', ['name' => 'tenant-zero']);

        self::assertArrayHasKey('error', $r, 'system-tenant token must be rejected');
        self::assertContains(
            $r['error']['code'],
            [ErrorCode::UNAUTHENTICATED, ErrorCode::FORBIDDEN],
        );
    }

    // ── Helpers ─────────────────────────────────────────────────────────────────

    /**
     * Issue a real MCP token for a seeded profile (resolved by email) bound to the
     * given tenant id. The tenant id is taken from the argument — NOT the profile's
     * own memberships — so the cross-tenant tests can mint a token claiming a tenant
     * the profile does not belong to.
     */
    private function mcpTokenFor(string $email, int $tenantId): string
    {
        $profileId = $this->profileIds[$email] ?? throw new \RuntimeException("Unseeded profile for: {$email}");

        return $this->tokens->issue($profileId, $tenantId, 'test', ['tools:list', 'tools:call']);
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed> Decoded JSON-RPC response.
     */
    private function callTool(string $token, string $name, array $arguments): array
    {
        $payload = (string) json_encode([
            'jsonrpc' => '2.0',
            'id'      => 1,
            'method'  => 'tools/call',
            'params'  => ['name' => $name, 'arguments' => $arguments],
        ], JSON_THROW_ON_ERROR);

        return $this->decode($this->dispatcher->handle($payload, $token));
    }

    /**
     * @return list<string> The tool names advertised to the bearer of $token.
     */
    private function listToolNames(string $token): array
    {
        $payload = (string) json_encode([
            'jsonrpc' => '2.0',
            'id'      => 1,
            'method'  => 'tools/list',
            'params'  => [],
        ], JSON_THROW_ON_ERROR);

        $r = $this->decode($this->dispatcher->handle($payload, $token));
        $tools = $r['result']['tools'] ?? [];
        self::assertIsArray($tools);

        $names = [];
        foreach ($tools as $tool) {
            if (is_array($tool) && isset($tool['name']) && is_string($tool['name'])) {
                $names[] = $tool['name'];
            }
        }

        return $names;
    }

    /** @return array<string, mixed> */
    private function decode(string $json): array
    {
        $data = json_decode($json, true);
        self::assertIsArray($data, "Expected a JSON object response, got: {$json}");

        return $data;
    }

    private function buildRouter(): Router
    {
        $router = new Router(''); // no version prefix keeps tool paths == route paths

        $ok = static fn (SdkRequest $req, array $params): SdkResponse => SdkResponse::json(['ok' => true]);

        $router->registerUnversioned('GET', '/api/widgets', $ok, null, null, 'widgets:read', [
            'operationId' => 'list_widgets',
            'summary'     => 'List widgets',
        ]);
        $router->registerUnversioned('POST', '/api/widgets', $ok, null, null, 'widgets:write', [
            'operationId' => 'create_widgets',
            'summary'     => 'Create a widget',
            'request'     => [
                'type'       => 'object',
                'properties' => ['name' => ['type' => 'string']],
            ],
        ]);
        // Open tool — no required role or permission.
        $router->registerUnversioned('GET', '/api/widgets/ping', $ok, null, null, null, [
            'operationId' => 'ping_widgets',
            'summary'     => 'Ping the widgets service',
        ]);

        return $router;
    }

    private function seedFixtures(): void
    {
        // Tenants (system + two regular). FK targets for users / mcp_tokens.
        $this->pdo->exec(
            "INSERT OR IGNORE INTO tenants (id, name) VALUES "
            . "(" . self::SYSTEM_TENANT . ", 'System'), "
            . "(" . self::TENANT_A . ", 'Tenant A'), "
            . "(" . self::TENANT_B . ", 'Tenant B')"
        );

        // Tenant-A roles. Migrations seed admin(1)/user(2); use high ids to avoid clashes.
        $this->pdo->exec(
            "INSERT OR IGNORE INTO roles (id, tenant_id, name, created_at) VALUES "
            . "(100, " . self::TENANT_A . ", 'widget_reader', NOW()), "
            . "(101, " . self::TENANT_A . ", 'widget_writer', NOW()), "
            . "(102, " . self::TENANT_A . ", 'widget_none',   NOW())"
        );

        $this->grant('widget_reader', 'widgets:read');
        $this->grant('widget_writer', 'widgets:write');

        // Seed users (for RoleChecker resolution which still reads users table)
        // AND matching profiles (for mcp_tokens issuance which now uses profile_id).
        $this->userIds['reader@a.test'] = $this->seedUser('reader@a.test', 100, self::TENANT_A, null);
        $this->userIds['writer@a.test'] = $this->seedUser('writer@a.test', 101, self::TENANT_A, null);
        $this->userIds['none@a.test']   = $this->seedUser('none@a.test',   102, self::TENANT_A, null);

        // OU in tenant A assigned the writer role; ou_user has the no-grant direct
        // role but inherits widgets:write through OU membership — within tenant A only.
        $ouId = $this->seedOu('Engineering', self::TENANT_A);
        $this->assignRoleToOu($ouId, 101, self::TENANT_A);
        $this->userIds['ou@a.test'] = $this->seedUser('ou@a.test', 102, self::TENANT_A, $ouId);

        // Seed profiles for each user (after migration 040 mcp_tokens references profiles.id).
        $this->profileIds['reader@a.test'] = $this->seedProfile($this->userIds['reader@a.test']);
        $this->profileIds['writer@a.test'] = $this->seedProfile($this->userIds['writer@a.test']);
        $this->profileIds['none@a.test']   = $this->seedProfile($this->userIds['none@a.test']);
        $this->profileIds['ou@a.test']     = $this->seedProfile($this->userIds['ou@a.test']);

        // Seed active memberships in TENANT_A so the ActiveTenantMembershipGuard accepts
        // MCP tokens that carry {profile_id, active_tenant_id=TENANT_A}.
        // Note: profiles deliberately have NO memberships in TENANT_B or SYSTEM_TENANT —
        // cross-tenant tokens are rejected at auth (UNAUTHENTICATED), which is MORE
        // secure than failing at RBAC. The cross-tenant tests below assert this.
        foreach ($this->profileIds as $profileId) {
            $this->pdo->prepare("
                INSERT OR IGNORE INTO memberships (profile_id, tenant_id, role_id, status, created_at)
                VALUES (?, ?, 100, 'active', NOW())
            ")->execute([$profileId, self::TENANT_A]);
        }
    }

    private function grant(string $roleName, string $permission): void
    {
        $this->pdo->prepare('INSERT OR IGNORE INTO permissions (name, description, created_at) VALUES (?, ?, NOW())')
            ->execute([$permission, null]);

        $roleStmt = $this->pdo->prepare('SELECT id FROM roles WHERE name = ?');
        $roleStmt->execute([$roleName]);
        $roleId = (int) $roleStmt->fetchColumn();

        $permStmt = $this->pdo->prepare('SELECT id FROM permissions WHERE name = ?');
        $permStmt->execute([$permission]);
        $permissionId = (int) $permStmt->fetchColumn();

        $this->pdo->prepare('INSERT OR IGNORE INTO role_permissions (role_id, permission_id, created_at) VALUES (?, ?, NOW())')
            ->execute([$roleId, $permissionId]);
    }

    private function seedUser(string $email, int $roleId, int $tenantId, ?int $ouId): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (tenant_id, email, password, role_id, ou_id, token_epoch, created_at)
             VALUES (?, ?, ?, ?, ?, 0, NOW())'
        );
        $stmt->execute([$tenantId, $email, 'x', $roleId, $ouId]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Seed a profile for the given user id and return the new profile id.
     * mcp_tokens is keyed on profiles.id after migration 040.
     */
    private function seedProfile(int $userId): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO profiles (display_name, password_hash, two_factor_enabled,
                two_factor_backup_codes_version, token_epoch, created_at, updated_at)
             VALUES (?, 'x', false, 0, 0, NOW(), NOW())"
        );
        $stmt->execute(["Profile for user {$userId}"]);

        return (int) $this->pdo->lastInsertId();
    }

    private function seedOu(string $name, int $tenantId): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO organizational_units (tenant_id, parent_id, name, slug, created_at)
             VALUES (?, NULL, ?, ?, NOW())'
        );
        $stmt->execute([$tenantId, $name, $name]);

        return (int) $this->pdo->lastInsertId();
    }

    private function assignRoleToOu(int $ouId, int $roleId, int $tenantId): void
    {
        $this->pdo->prepare('INSERT INTO ou_role_assignments (tenant_id, ou_id, role_id, created_at) VALUES (?, ?, ?, NOW())')
            ->execute([$tenantId, $ouId, $roleId]);
    }

    private function wrapSqlite(PDO $pdo): Database
    {
        $db = Database::withFactory(static fn (): PDO => $pdo);
        // Never recycle (a fresh :memory: handle would be empty) and never ping,
        // so the single seeded connection is reused for the whole test.
        $db->setMaxLifetimeSeconds(86400);
        $db->setPingIntervalSeconds(86400);
        $db->forceConnect();

        return $db;
    }
}
