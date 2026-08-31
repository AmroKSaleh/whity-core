<?php

declare(strict_types=1);

namespace Tests\Api;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\MockRequestFactory;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\DelegationsApiHandler;
use Whity\Auth\RoleChecker;
use Whity\Core\Delegation\DelegationRepository;
use Whity\Core\Delegation\DelegationService;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\Request;
use Whity\Core\Tenant\TenantContext;
use Whity\Database\Database;

/**
 * Real-engine (in-memory SQLite) tests for {@see DelegationsApiHandler} (WC-34).
 *
 * Drives the create/list/revoke endpoints against a genuine SQL engine so the
 * real INSERT/SELECT/UPDATE semantics — and the typed-error → HTTP-status
 * translation — are exercised. `PDO::ATTR_STRINGIFY_FETCHES` mirrors Postgres so
 * int-vs-string bugs surface.
 *
 * Asserts the API contract around the core invariant: a subset violation returns
 * 422 (no row written), a held-permission delegation returns 201, listing is
 * tenant-scoped, an unknown grantee returns 404, and revocation is tenant-scoped.
 */
final class DelegationsApiHandlerRealEngineTest extends TestCase
{
    private PDO $pdo;
    private Database $db;

    protected function setUp(): void
    {
        RoleChecker::clearCache();
        $this->pdo = self::makeSqliteSchema();
        $this->db = self::wrapSqlite($this->pdo);
        MockRequestFactory::setTestTenant(1);
        $_GET = [];
    }

    protected function tearDown(): void
    {
        RoleChecker::clearCache();
        TenantContext::reset();
        $_GET = [];
    }

    /**
     * WC-167 review BLOCKER regression: at runtime FrankenPHP strips the query
     * string from the path, so filters MUST be read from $_GET (the path-query
     * form below only ever existed in tests). A revoked delegation appears
     * when includeRevoked arrives via the superglobal.
     */
    public function testFiltersAreReadFromTheGetSuperglobal(): void
    {
        // Seed the grantor/grantee profiles referenced by the delegation's FKs
        // (real PG enforces grantor_profile_id -> profiles.id; SQLite does not).
        $grantorId = $this->seedUser('grantor@example.com', 'admin', 1);
        $granteeId = $this->seedUser('grantee@example.com', 'user', 1);

        $stmt = $this->pdo->prepare("
            INSERT INTO permission_delegations
                (tenant_id, grantor_profile_id, grantee_type, grantee_id, permission, granted_at, revoked_at)
            VALUES (1, ?, 'profile', ?, 'users:read', datetime('now'), datetime('now'))
        ");
        $stmt->execute([$grantorId, $granteeId]);

        $_GET = ['includeRevoked' => '1'];
        $response = $this->handler()->list(new Request('GET', '/api/delegations'));

        $body = json_decode($response->getBody(), true);
        $this->assertCount(
            1,
            $body['data'] ?? [],
            'includeRevoked supplied via $_GET (the runtime shape) must surface the revoked delegation'
        );
    }

    /**
     * The documented 400 for an invalid granteeType must fire when the filter
     * arrives via $_GET, not silently return 200 with wrong data.
     */
    public function testInvalidGranteeTypeFromGetSuperglobalReturns400(): void
    {
        $_GET = ['granteeType' => 'bogus'];
        $response = $this->handler()->list(new Request('GET', '/api/delegations'));

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testCreateRejectsPermissionGrantorDoesNotHoldWith422(): void
    {
        // Grantor is a plain 'user' with no grants.
        $grantorId = $this->seedUser('grantor@example.com', 'user', 1);
        $granteeId = $this->seedUser('grantee@example.com', 'user', 1);

        $response = $this->handler()->create($this->authedRequest($grantorId, 1, [
            'granteeType' => 'user',
            'granteeId' => $granteeId,
            'permissions' => ['users:read'],
        ]));

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(
            0,
            (int) $this->pdo->query('SELECT COUNT(*) FROM permission_delegations')->fetchColumn(),
            'A 422 subset violation must write no rows.'
        );
    }

    public function testCreateSucceedsForHeldPermissionWith201(): void
    {
        $this->grant('admin', 'users:read');
        $grantorId = $this->seedUser('admin@example.com', 'admin', 1);
        $granteeId = $this->seedUser('grantee@example.com', 'user', 1);

        $response = $this->handler()->create($this->authedRequest($grantorId, 1, [
            'granteeType' => 'user',
            'granteeId' => $granteeId,
            'permissions' => ['users:read'],
        ]));

        $this->assertSame(201, $response->getStatusCode());
        $data = json_decode($response->getBody(), true)['data'];
        $this->assertSame(1, $data['count']);
        $this->assertSame(
            1,
            (int) $this->pdo->query('SELECT COUNT(*) FROM permission_delegations WHERE revoked_at IS NULL')->fetchColumn()
        );
    }

    public function testCreateReturns404ForUnknownGrantee(): void
    {
        $this->grant('admin', 'users:read');
        $grantorId = $this->seedUser('admin@example.com', 'admin', 1);

        $response = $this->handler()->create($this->authedRequest($grantorId, 1, [
            'granteeType' => 'user',
            'granteeId' => 9999,
            'permissions' => ['users:read'],
        ]));

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testCreateReturns404WhenGranteeUserBelongsToAnotherTenant(): void
    {
        $this->grant('admin', 'users:read');
        $grantorId = $this->seedUser('admin@example.com', 'admin', 1);
        // Grantee user lives in tenant 2.
        $otherTenantUser = $this->seedUser('other@example.com', 'user', 2);

        $response = $this->handler()->create($this->authedRequest($grantorId, 1, [
            'granteeType' => 'user',
            'granteeId' => $otherTenantUser,
            'permissions' => ['users:read'],
        ]));

        $this->assertSame(404, $response->getStatusCode(), 'A cross-tenant grantee must not be visible.');
    }

    public function testListIsTenantScoped(): void
    {
        $this->grant('admin', 'users:read');
        $grantorId = $this->seedUser('admin@example.com', 'admin', 1);
        $granteeId = $this->seedUser('grantee@example.com', 'user', 1);

        $this->handler()->create($this->authedRequest($grantorId, 1, [
            'granteeType' => 'user',
            'granteeId' => $granteeId,
            'permissions' => ['users:read'],
        ]));

        // Tenant 1 sees it.
        $listT1 = json_decode($this->handler()->list(new Request('GET', '/api/delegations'))->getBody(), true)['data'];
        $this->assertCount(1, $listT1);

        // Tenant 2 does not.
        TenantContext::reset();
        MockRequestFactory::setTestTenant(2);
        $listT2 = json_decode($this->handler()->list(new Request('GET', '/api/delegations'))->getBody(), true)['data'];
        $this->assertCount(0, $listT2, 'Tenant 2 must not see tenant 1 delegations.');
    }

    public function testRevokeMarksDelegationRevokedAndIsTenantScoped(): void
    {
        $this->grant('admin', 'users:read');
        $grantorId = $this->seedUser('admin@example.com', 'admin', 1);
        $granteeId = $this->seedUser('grantee@example.com', 'user', 1);

        $created = json_decode($this->handler()->create($this->authedRequest($grantorId, 1, [
            'granteeType' => 'user',
            'granteeId' => $granteeId,
            'permissions' => ['users:read'],
        ]))->getBody(), true)['data'];
        $delegationId = (int) $created['ids'][0];

        // Tenant 2 cannot revoke tenant 1's delegation.
        TenantContext::reset();
        MockRequestFactory::setTestTenant(2);
        $deniedRevoke = $this->handler()->revoke(new Request('DELETE', '/api/delegations/' . $delegationId), ['id' => (string) $delegationId]);
        $this->assertSame(404, $deniedRevoke->getStatusCode(), 'Cross-tenant revoke must 404.');
        $this->assertNull(
            $this->pdo->query('SELECT revoked_at FROM permission_delegations WHERE id = ' . $delegationId)->fetchColumn() ?: null
        );

        // Tenant 1 can.
        TenantContext::reset();
        MockRequestFactory::setTestTenant(1);
        $ok = $this->handler()->revoke(new Request('DELETE', '/api/delegations/' . $delegationId), ['id' => (string) $delegationId]);
        $this->assertSame(200, $ok->getStatusCode());
        $this->assertNotNull(
            $this->pdo->query('SELECT revoked_at FROM permission_delegations WHERE id = ' . $delegationId)->fetchColumn() ?: null,
            'Revoked delegation must carry a revoked_at timestamp.'
        );

        // Revoking again is a no-op 404 (already revoked).
        $again = $this->handler()->revoke(new Request('DELETE', '/api/delegations/' . $delegationId), ['id' => (string) $delegationId]);
        $this->assertSame(404, $again->getStatusCode());
    }

    public function testRevokeReturns404ForUnknownDelegation(): void
    {
        $response = $this->handler()->revoke(new Request('DELETE', '/api/delegations/424242'), ['id' => '424242']);
        $this->assertSame(404, $response->getStatusCode());
    }

    public function testCreateValidatesGranteeType(): void
    {
        $grantorId = $this->seedUser('admin@example.com', 'admin', 1);
        $response = $this->handler()->create($this->authedRequest($grantorId, 1, [
            'granteeType' => 'bogus',
            'granteeId' => 1,
            'permissions' => ['users:read'],
        ]));
        $this->assertSame(400, $response->getStatusCode());
    }

    // ============ #1102: the shared list contract (sort + search) ============
    //
    // This is a SECURITY surface — every row is a permission somebody granted
    // to somebody else — so these assert both halves: that sort and search
    // work, and that neither widens the set. The search is appended to the same
    // WHERE the tenant scope and the filters build, so it can only narrow.

    /**
     * Every key the endpoint offers actually reorders the list, and the default
     * is the `granted_at DESC` this table has always been read with.
     *
     * @dataProvider delegationSortCases
     * @param list<string> $expected Permissions in the order the endpoint must return them.
     */
    public function testEachSortKeyReordersTheList(string $query, array $expected): void
    {
        $this->seedSortableDelegations();

        $this->assertSame($expected, $this->listedPermissions('/api/delegations?' . $query));
    }

    /**
     * @return array<string, array{0: string, 1: list<string>}>
     */
    public static function delegationSortCases(): array
    {
        return [
            'permission ascending'  => ['sort=permission&dir=asc', ['documents:read', 'documents:write', 'roles:read', 'users:read']],
            'permission descending' => ['sort=permission&dir=desc', ['users:read', 'roles:read', 'documents:write', 'documents:read']],
            // role, role, profile, profile — 'profile' sorts before 'role', and
            // the pairs tie, broken by id (insertion order).
            'granteeType ascending' => ['sort=granteeType&dir=asc', ['roles:read', 'documents:read', 'users:read', 'documents:write']],
            // Grantee ids 5, 7, 3, 9 → 3, 5, 7, 9.
            'granteeId ascending'   => ['sort=granteeId&dir=asc', ['roles:read', 'users:read', 'documents:write', 'documents:read']],
            // Tenant-wide (NULL ou_id) sorts as 0, ahead of the OU-scoped row.
            'scope ascending'       => ['sort=scope&dir=asc', ['users:read', 'documents:write', 'roles:read', 'documents:read']],
            'grantedAt ascending'   => ['sort=grantedAt&dir=asc', ['roles:read', 'users:read', 'documents:read', 'documents:write']],
            'no sort is grantedAt descending' => ['', ['documents:write', 'documents:read', 'users:read', 'roles:read']],
        ];
    }

    /**
     * `status` sorts live delegations ahead of revoked ones, in both directions.
     *
     * It does NOT sort on `revoked_at` itself: that column is NULL for every
     * active row, and PostgreSQL orders NULLs last ascending while SQLite orders
     * them first — the same request would put the live delegations at opposite
     * ends of the table depending on the deployment's engine. The spec sorts a
     * NULL-free CASE instead, which is why this assertion can hold at all.
     */
    public function testStatusSortsActiveBeforeRevokedOnBothEngines(): void
    {
        $this->seedSortableDelegations();
        $this->pdo->exec("UPDATE permission_delegations SET revoked_at = NOW() WHERE permission = 'users:read'");

        $_GET = ['includeRevoked' => '1'];
        $ascending = $this->listedPermissions('/api/delegations?sort=status&dir=asc');
        $descending = $this->listedPermissions('/api/delegations?sort=status&dir=desc');

        $this->assertSame('users:read', end($ascending), 'the revoked row sorts last ascending');
        $this->assertSame('users:read', $descending[0], 'and first descending');
        $this->assertCount(4, $ascending);
    }

    /** An unrecognised sort key falls back to the default rather than erroring. */
    public function testAnUnknownSortKeyFallsBackToTheDefault(): void
    {
        $this->seedSortableDelegations();

        $bogus = $this->listedPermissions('/api/delegations?sort=not_a_column');

        $this->assertSame($this->listedPermissions('/api/delegations'), $bogus);
        $this->assertSame(
            ['documents:write', 'documents:read', 'users:read', 'roles:read'],
            $bogus
        );
    }

    /**
     * A sort key cannot carry SQL — it is only ever a key into the repository's
     * own map. Asserting the rows, not the query text, is what proves it.
     */
    public function testASortKeyCannotInjectSql(): void
    {
        $this->seedSortableDelegations();

        $attack = $this->listedPermissions(
            '/api/delegations?sort=' . rawurlencode('permission; DROP TABLE permission_delegations--')
        );

        $this->assertSame($this->listedPermissions('/api/delegations'), $attack);
        $this->assertSame(
            5,
            $this->scalar('SELECT COUNT(*) FROM permission_delegations'),
            'the table is still there (four tenant-1 rows plus the tenant-2 decoy)'
        );
    }

    /** The search narrows the reported TOTAL, not only the returned rows. */
    public function testSearchNarrowsBothTheRowsAndTheTotal(): void
    {
        $this->seedSortableDelegations();

        $this->assertSame(4, $this->listBody('/api/delegations')['pagination']['total']);

        $filtered = $this->listBody('/api/delegations?q=documents');

        $this->assertSame(
            ['documents:write', 'documents:read'],
            array_column($filtered['data'], 'permission')
        );
        $this->assertSame(2, $filtered['pagination']['total'], 'the COUNT must carry the search predicate');
        $this->assertSame(1, $filtered['pagination']['totalPages']);
    }

    /** A filtered total does not advertise pages that come back empty. */
    public function testSearchTotalIsFilteredEvenWhenPaged(): void
    {
        $this->seedSortableDelegations();

        $body = $this->listBody('/api/delegations?q=documents&per_page=1');

        $this->assertCount(1, $body['data']);
        $this->assertSame(2, $body['pagination']['total']);
        $this->assertSame(2, $body['pagination']['totalPages']);
    }

    /** Search is case-insensitive on whichever engine the suite is running. */
    public function testSearchIsCaseInsensitive(): void
    {
        $this->seedSortableDelegations();

        $this->assertSame(
            $this->listedPermissions('/api/delegations?q=documents'),
            $this->listedPermissions('/api/delegations?q=DOCUMENTS'),
            'ILIKE on PostgreSQL, LIKE on SQLite — the same answer either way'
        );
    }

    /** The search composes with the existing filters instead of replacing them. */
    public function testSearchComposesWithTheGranteeTypeFilter(): void
    {
        $this->seedSortableDelegations();

        $_GET = ['granteeType' => 'role'];
        $body = $this->listBody('/api/delegations?q=documents');

        // Two rows are role-granted and two are documents permissions, but only
        // 'documents:write' is both — so neither condition swallowed the other.
        $this->assertSame(['documents:write'], array_column($body['data'], 'permission'));
        $this->assertSame(1, $body['pagination']['total']);
    }

    /** And a revoked row stays out of reach of a search unless it was asked for. */
    public function testSearchDoesNotSurfaceRevokedRowsByDefault(): void
    {
        $this->seedSortableDelegations();
        $this->pdo->exec("UPDATE permission_delegations SET revoked_at = NOW() WHERE permission = 'documents:read'");

        $body = $this->listBody('/api/delegations?q=documents');

        $this->assertSame(['documents:write'], array_column($body['data'], 'permission'));
        $this->assertSame(1, $body['pagination']['total']);
    }

    /**
     * THE SECURITY PROPERTY: no search term reaches another tenant's delegation.
     *
     * Tenant 2 holds a delegation of exactly the permission tenant 1 searches
     * for. The term is appended to the same WHERE that carries
     * `tenant_id = :tenant_id`, never substituted for it, so the row is
     * unreachable — and, just as importantly, uncounted.
     */
    public function testSearchCannotSurfaceAnotherTenantsDelegation(): void
    {
        $this->seedSortableDelegations();

        $body = $this->listBody('/api/delegations?q=secrets:read');

        $this->assertSame([], $body['data'], 'tenant 2 must stay invisible to tenant 1');
        $this->assertSame(0, $body['pagination']['total'], 'and must not be counted either');

        // The row really is there — the search just cannot see it.
        $this->assertSame(
            1,
            $this->scalar("SELECT COUNT(*) FROM permission_delegations WHERE permission = 'secrets:read'")
        );
    }

    /**
     * Paging over a TIED sort column returns every delegation exactly once.
     *
     * A single `delegate()` call writes one row per granted permission, all
     * sharing one `granted_at` — so a tie here is the ORDINARY case, not an edge.
     * Without the id tiebreaker `LIMIT/OFFSET` can show one row twice and never
     * show another, and on this screen the row that vanishes is a permission
     * somebody still holds.
     */
    public function testPagingOverATiedSortColumnSeesEveryDelegationExactlyOnce(): void
    {
        $expected = $this->seedTiedDelegations();

        $seen = [];
        for ($page = 1; $page <= 3; $page++) {
            foreach ($this->listedPermissions("/api/delegations?sort=grantedAt&per_page=2&page={$page}") as $permission) {
                $seen[] = $permission;
            }
        }

        sort($seen);
        $this->assertSame($expected, $seen, 'every delegation exactly once across the walk');
    }

    /** And two consecutive pages of a tied sort never overlap. */
    public function testConsecutivePagesOfATiedSortDoNotOverlap(): void
    {
        $this->seedTiedDelegations();

        $first  = $this->listedPermissions('/api/delegations?sort=grantedAt&per_page=2&page=1');
        $second = $this->listedPermissions('/api/delegations?sort=grantedAt&per_page=2&page=2');

        $this->assertCount(2, $first);
        $this->assertCount(2, $second);
        $this->assertSame([], array_intersect($first, $second));
    }

    /** The envelope is unchanged, so a client cannot tell the endpoint moved. */
    public function testThePaginationEnvelopeKeepsItsShape(): void
    {
        $this->seedSortableDelegations();

        $body = $this->listBody('/api/delegations?page=2&per_page=2&sort=permission');

        $this->assertSame(
            ['page' => 2, 'perPage' => 2, 'total' => 4, 'totalPages' => 2],
            $body['pagination']
        );
    }

    // ---- list-contract fixtures & helpers ----

    /**
     * @return array{data: list<array<string, mixed>>, pagination: array<string, int>}
     */
    private function listBody(string $path): array
    {
        $response = $this->handler()->list(new Request('GET', $path));
        $this->assertSame(200, $response->getStatusCode(), $response->getBody());

        /** @var array{data: list<array<string, mixed>>, pagination: array<string, int>} $body */
        $body = json_decode($response->getBody(), true);

        return $body;
    }

    /**
     * The permissions the endpoint returned, in the order it returned them.
     *
     * @return list<string>
     */
    private function listedPermissions(string $path): array
    {
        return array_map('strval', array_column($this->listBody($path)['data'], 'permission'));
    }

    /**
     * Four tenant-1 delegations whose permission, grantee type, grantee id,
     * scope and grant date each produce a DIFFERENT order, so a sort that used
     * the wrong column cannot pass by coincidence — plus a tenant-2 delegation
     * the isolation test searches for by name.
     */
    private function seedSortableDelegations(): void
    {
        $grantor = $this->seedUser('grantor@example.com', 'admin', 1);
        $ouId    = $this->seedOu(1, 'Field Operations', 'field-ops');

        // Inserted in this order, so ids run 1..4 and ties break predictably.
        $rows = [
            ['users:read',      'role',    5, null,  '-6 days'],
            ['documents:write', 'role',    7, null,  '-2 days'],
            ['roles:read',      'profile', 3, null,  '-8 days'],
            ['documents:read',  'profile', 9, $ouId, '-4 days'],
        ];

        $stmt = $this->pdo->prepare('
            INSERT INTO permission_delegations
                (tenant_id, grantor_profile_id, grantee_type, grantee_id, permission, ou_id, granted_at)
            VALUES (1, ?, ?, ?, ?, ?, ?)
        ');
        foreach ($rows as [$permission, $granteeType, $granteeId, $ou, $granted]) {
            $stmt->execute([
                $grantor,
                $granteeType,
                $granteeId,
                $permission,
                $ou,
                date('Y-m-d H:i:s', (int) strtotime($granted)),
            ]);
        }

        // The decoy: another tenant's delegation, of a permission tenant 1 will
        // search for by its exact name.
        $otherGrantor = $this->seedUser('grantor@tenant-b.example.com', 'admin', 2);
        $this->pdo->prepare('
            INSERT INTO permission_delegations
                (tenant_id, grantor_profile_id, grantee_type, grantee_id, permission, ou_id, granted_at)
            VALUES (2, ?, ?, ?, ?, NULL, NOW())
        ')->execute([$otherGrantor, 'role', 5, 'secrets:read']);
    }

    /**
     * Six delegations written as one grant — every row sharing one `granted_at`,
     * which is exactly the state unstable paging misbehaves in.
     *
     * @return list<string> The permissions, sorted, for comparison against a page walk.
     */
    private function seedTiedDelegations(): array
    {
        $grantor = $this->seedUser('grantor@example.com', 'admin', 1);
        $grantedAt = date('Y-m-d H:i:s', (int) strtotime('-1 day'));

        $permissions = [
            'tied:alpha', 'tied:bravo', 'tied:charlie',
            'tied:delta', 'tied:echo', 'tied:foxtrot',
        ];

        $stmt = $this->pdo->prepare('
            INSERT INTO permission_delegations
                (tenant_id, grantor_profile_id, grantee_type, grantee_id, permission, ou_id, granted_at)
            VALUES (1, ?, \'role\', 5, ?, NULL, ?)
        ');
        foreach ($permissions as $permission) {
            $stmt->execute([$grantor, $permission, $grantedAt]);
        }

        return $permissions;
    }

    /** Seed one organizational unit so an OU-scoped delegation's FK holds. */
    private function seedOu(int $tenantId, string $name, string $slug): int
    {
        $this->pdo->prepare(
            'INSERT INTO organizational_units (tenant_id, parent_id, name, slug, created_at)
             VALUES (?, NULL, ?, ?, NOW())'
        )->execute([$tenantId, $name, $slug]);

        return (int) $this->pdo->lastInsertId();
    }

    // ==================== Helpers ====================

    private function handler(): DelegationsApiHandler
    {
        $repo = new DelegationRepository($this->pdo);
        $baseChecker = new RoleChecker($this->db, new PermissionRegistry());
        $service = new DelegationService($repo, $baseChecker, new PermissionRegistry());

        return new DelegationsApiHandler($this->pdo, $service);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function authedRequest(int $profileId, int $tenantId, array $body): Request
    {
        $request = new Request('POST', '/api/delegations', [], (string) json_encode($body));
        // ADR 0005: the acting identity is a profile_id.
        $request->user = (object) ['profile_id' => $profileId, 'tenant_id' => $tenantId];

        return $request;
    }

    private function grant(string $roleName, string $permission): void
    {
        $this->pdo->prepare(
            'INSERT OR IGNORE INTO permissions (name, description, created_at) VALUES (?, ?, NOW())'
        )->execute([$permission, null]);

        $roleId = (int) $this->pdo->query("SELECT id FROM roles WHERE name = '{$roleName}'")->fetchColumn();
        $stmt = $this->pdo->prepare('SELECT id FROM permissions WHERE name = ?');
        $stmt->execute([$permission]);
        $permissionId = (int) $stmt->fetchColumn();

        $this->pdo->prepare(
            'INSERT OR IGNORE INTO role_permissions (role_id, permission_id, created_at) VALUES (?, ?, NOW())'
        )->execute([$roleId, $permissionId]);
    }

    /**
     * Seed a PROFILE with an ACTIVE membership in the tenant carrying the given
     * role; returns the profile id (WC-bc07b6de: delegations are profile-keyed).
     * The name is retained for churn minimisation; it now returns a profile id.
     */
    private function seedUser(string $email, string $roleName, int $tenantId): int
    {
        $roleId = (int) $this->pdo->query("SELECT id FROM roles WHERE name = '{$roleName}'")->fetchColumn();

        $this->pdo->prepare(
            "INSERT INTO profiles
                 (display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version,
                  token_epoch, created_at, updated_at)
             VALUES ('', '', false, 0, 0, NOW(), NOW())"
        )->execute();
        $profileId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare(
            "INSERT INTO memberships (profile_id, tenant_id, role_id, ou_id, status, created_at)
             VALUES (?, ?, ?, NULL, 'active', NOW())"
        )->execute([$profileId, $tenantId, $roleId]);

        return $profileId;
    }

    private static function wrapSqlite(PDO $pdo): Database
    {
        $db = Database::withFactory(static fn (): PDO => $pdo);
        $db->setMaxLifetimeSeconds(86400);
        $db->setPingIntervalSeconds(86400);
        $db->forceConnect();

        return $db;
    }

    private static function makeSqliteSchema(): PDO
    {
        $pdo = SchemaFromMigrations::make(true);
        // Seed tenants referenced by seeded memberships' tenant_id FK
        // (real PG enforces the constraint; SQLite does not).
        $pdo->exec("INSERT OR IGNORE INTO tenants (id, name) VALUES (1, 'tenant-a'), (2, 'tenant-b')");
        return $pdo;
    }

    /**
     * One integer out of a scalar query.
     *
     * `PDO::query()` can return false, and PHPStan says so. This file carries a
     * baseline of six grandfathered call sites that ignore that; adding more
     * would grow a baseline whose whole purpose is to stop growing, so the new
     * assertions go through here instead.
     */
    private function scalar(string $sql): int
    {
        $stmt = $this->pdo->query($sql);
        $this->assertNotFalse($stmt, "query failed: {$sql}");

        return (int) $stmt->fetchColumn();
    }

}
