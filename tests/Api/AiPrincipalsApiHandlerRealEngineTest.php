<?php

declare(strict_types=1);

namespace Tests\Api;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\AiPrincipalsApiHandler;
use Whity\Auth\RoleChecker;
use Whity\Core\Request;
use Whity\Core\Tenant\TenantContext;

/**
 * Real-engine (in-memory SQLite) tests for {@see AiPrincipalsApiHandler} (WC-0208ce4d).
 *
 * Drives the handler against a genuine SQL engine so the real INSERT/SELECT
 * semantics — tenant scoping, revocation exclusion, pagination — are exercised,
 * not the forgiving behaviour of mocked PDO. STRINGIFY_FETCHES is enabled so
 * integer-vs-string comparison bugs surface as they do under PostgreSQL.
 *
 * After migration 040, mcp_tokens is keyed on profiles.id (profile_id).
 * Fixture helpers insert profile_id rather than user_id.
 *
 * Acceptance focus:
 *  - Tenant data isolation: tenant A sees only A's tokens, system tenant (id 0) sees all.
 *  - Revoked tokens are excluded from the listing.
 *  - Expired tokens are excluded from the listing.
 *  - Fail-closed when the tenant context is unresolved.
 *  - Defence-in-depth permission re-check (denied → 403).
 *  - Admin revoke: removes tokens from any profile in the tenant; returns 404 for unknown JTI.
 *  - Admin revoke: system tenant may revoke tokens from any tenant.
 *  - Admin revoke: regular tenant may not revoke tokens belonging to another tenant.
 */
final class AiPrincipalsApiHandlerRealEngineTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $_GET = [];
        TenantContext::reset();
        $this->seedBaseFixtures();
    }

    protected function tearDown(): void
    {
        $_GET = [];
        TenantContext::reset();
    }

    // ====================== Tenant data isolation ======================

    public function testListReturnsOnlyCurrentTenantTokens(): void
    {
        $this->seedToken('jti-a', 10, 1, 'Bot A');
        $this->seedToken('jti-b', 20, 2, 'Bot B');

        TenantContext::setTenantId(1);
        $response = $this->handler()->list($this->authedRequest('GET', '/api/admin/mcp/tokens', 1));

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertCount(1, $body['data']);
        $this->assertSame('jti-a', $body['data'][0]['jti']);
        $this->assertSame('Bot A', $body['data'][0]['name']);
    }

    public function testSystemTenantSeesAllTokens(): void
    {
        $this->seedToken('jti-a', 10, 1, 'Bot A');
        $this->seedToken('jti-b', 20, 2, 'Bot B');

        TenantContext::setTenantId(0);
        $response = $this->handler()->list($this->authedRequest('GET', '/api/admin/mcp/tokens', 1));

        $body = json_decode($response->getBody(), true);
        $this->assertSame(2, $body['pagination']['total'], 'SYSTEM tenant must see all tokens across all tenants.');
    }

    // ====================== Exclusion: revoked + expired ======================

    public function testRevokedTokensAreExcluded(): void
    {
        $this->seedToken('jti-active', 10, 1, 'Active');
        $this->seedToken('jti-revoked', 10, 1, 'Revoked');
        $this->revokeToken('jti-revoked');

        TenantContext::setTenantId(1);
        $response = $this->handler()->list($this->authedRequest('GET', '/api/admin/mcp/tokens', 1));

        $body = json_decode($response->getBody(), true);
        $this->assertCount(1, $body['data']);
        $this->assertSame('jti-active', $body['data'][0]['jti']);
    }

    public function testExpiredTokensAreExcluded(): void
    {
        $this->seedToken('jti-active', 10, 1, 'Active');
        $this->seedExpiredToken('jti-expired', 10, 1, 'Expired');

        TenantContext::setTenantId(1);
        $response = $this->handler()->list($this->authedRequest('GET', '/api/admin/mcp/tokens', 1));

        $body = json_decode($response->getBody(), true);
        $this->assertCount(1, $body['data']);
        $this->assertSame('jti-active', $body['data'][0]['jti']);
    }

    // ====================== Fail-closed & RBAC ======================

    public function testUnresolvedTenantContextFailsClosed(): void
    {
        $response = $this->handler()->list($this->authedRequest('GET', '/api/admin/mcp/tokens', 1));
        $this->assertSame(403, $response->getStatusCode());
    }

    public function testPermissionDeniedReturns403ForList(): void
    {
        TenantContext::setTenantId(1);
        $response = $this->handler(false)->list($this->authedRequest('GET', '/api/admin/mcp/tokens', 1));
        $this->assertSame(403, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertSame('mcp:tokens:manage', $body['details']['required']);
    }

    public function testPermissionDeniedReturns403ForRevoke(): void
    {
        $this->seedToken('jti-a', 10, 1, 'Bot A');
        TenantContext::setTenantId(1);
        $response = $this->handler(false)->revoke(
            $this->authedRequest('DELETE', '/api/admin/mcp/tokens/jti-a', 1),
            ['jti' => 'jti-a']
        );
        $this->assertSame(403, $response->getStatusCode());
    }

    // ====================== Admin revoke ======================

    public function testRevokeReturns204AndExcludesTokenFromListing(): void
    {
        $this->seedToken('jti-a', 10, 1, 'Bot A');

        TenantContext::setTenantId(1);
        $handler = $this->handler();

        $revokeResponse = $handler->revoke(
            $this->authedRequest('DELETE', '/api/admin/mcp/tokens/jti-a', 1),
            ['jti' => 'jti-a']
        );
        $this->assertSame(204, $revokeResponse->getStatusCode());

        // Verify the token is now excluded from the listing.
        $listResponse = $handler->list($this->authedRequest('GET', '/api/admin/mcp/tokens', 1));
        $body = json_decode($listResponse->getBody(), true);
        $this->assertCount(0, $body['data']);
    }

    public function testRevokeReturns404ForUnknownJti(): void
    {
        TenantContext::setTenantId(1);
        $response = $this->handler()->revoke(
            $this->authedRequest('DELETE', '/api/admin/mcp/tokens/nonexistent', 1),
            ['jti' => 'nonexistent']
        );
        $this->assertSame(404, $response->getStatusCode());
    }

    public function testRevokeReturns404ForTokenFromAnotherTenant(): void
    {
        $this->seedToken('jti-other', 20, 2, 'Other Tenant Bot');

        TenantContext::setTenantId(1);
        $response = $this->handler()->revoke(
            $this->authedRequest('DELETE', '/api/admin/mcp/tokens/jti-other', 1),
            ['jti' => 'jti-other']
        );
        $this->assertSame(404, $response->getStatusCode());
    }

    public function testSystemTenantCanRevokeTokenFromAnyTenant(): void
    {
        $this->seedToken('jti-any', 20, 2, 'Tenant 2 Bot');

        TenantContext::setTenantId(0);
        $response = $this->handler()->revoke(
            $this->authedRequest('DELETE', '/api/admin/mcp/tokens/jti-any', 1),
            ['jti' => 'jti-any']
        );
        $this->assertSame(204, $response->getStatusCode());
    }

    public function testRevokeIsIdempotentWhenAlreadyRevoked(): void
    {
        $this->seedToken('jti-a', 10, 1, 'Bot A');
        $this->revokeToken('jti-a');

        TenantContext::setTenantId(1);
        $response = $this->handler()->revoke(
            $this->authedRequest('DELETE', '/api/admin/mcp/tokens/jti-a', 1),
            ['jti' => 'jti-a']
        );
        $this->assertSame(204, $response->getStatusCode());
    }

    // ====================== Public contract shape ======================

    public function testPublicContractFields(): void
    {
        $this->seedToken('jti-x', 10, 1, 'Shape Test');

        TenantContext::setTenantId(1);
        $body = json_decode(
            $this->handler()->list($this->authedRequest('GET', '/api/admin/mcp/tokens', 1))->getBody(),
            true
        );

        $entry = $body['data'][0];
        $this->assertArrayHasKey('id', $entry);
        $this->assertArrayHasKey('jti', $entry);
        $this->assertArrayHasKey('profileId', $entry);
        // userId is still present (= profileId) for backward compat during the dual-window
        $this->assertArrayHasKey('userId', $entry);
        $this->assertArrayHasKey('tenantId', $entry);
        $this->assertArrayHasKey('name', $entry);
        $this->assertArrayHasKey('principalKind', $entry);
        $this->assertArrayHasKey('scope', $entry);
        $this->assertArrayHasKey('expiresAt', $entry);
        $this->assertArrayHasKey('createdAt', $entry);
        $this->assertIsArray($entry['scope']);
        $this->assertSame('jti-x', $entry['jti']);
        $this->assertSame('Shape Test', $entry['name']);
        // profileId must equal userId (both come from profile_id column after 040)
        $this->assertSame($entry['profileId'], $entry['userId']);
    }

    // ============ #1102: the shared list contract (sort + search) ============
    //
    // This is a SECURITY surface — every row is a long-lived MCP credential —
    // so the tests below assert two things at once: that sort and search work,
    // and that neither of them widens what the caller can see. The search is
    // ANDed onto the tenant scope, the expiry check and the revocation check,
    // so no term can reach a credential the caller could not already page to.

    /**
     * Every key the endpoint offers actually reorders the list, and the default
     * is the `created_at DESC` it always used.
     *
     * @dataProvider principalSortCases
     * @param list<string> $expected JTIs in the order the endpoint must return them.
     */
    public function testEachSortKeyReordersTheList(string $query, array $expected): void
    {
        $this->seedSortablePrincipals();
        TenantContext::setTenantId(1);

        $this->assertSame($expected, $this->listedJtis('/api/admin/mcp/tokens?' . $query));
    }

    /**
     * @return array<string, array{0: string, 1: list<string>}>
     */
    public static function principalSortCases(): array
    {
        return [
            'name ascending'  => ['sort=name&dir=asc', ['jti-alpha', 'jti-bravo', 'jti-delta', 'jti-echo']],
            'name descending' => ['sort=name&dir=desc', ['jti-echo', 'jti-delta', 'jti-bravo', 'jti-alpha']],
            // agent, agent, service, user — the two agents tie and are broken by
            // id, which is insertion order: delta before bravo.
            'principalKind ascending' => ['sort=principalKind&dir=asc', ['jti-delta', 'jti-bravo', 'jti-echo', 'jti-alpha']],
            // Profiles 10, 10, 11, 12 — another real tie on the issuing profile.
            'userId ascending'    => ['sort=userId&dir=asc', ['jti-delta', 'jti-alpha', 'jti-echo', 'jti-bravo']],
            'expiresAt ascending' => ['sort=expiresAt&dir=asc', ['jti-alpha', 'jti-echo', 'jti-delta', 'jti-bravo']],
            'createdAt ascending' => ['sort=createdAt&dir=asc', ['jti-bravo', 'jti-alpha', 'jti-delta', 'jti-echo']],
            'no sort is createdAt descending' => ['', ['jti-echo', 'jti-delta', 'jti-alpha', 'jti-bravo']],
        ];
    }

    /** An unrecognised sort key falls back to the default rather than erroring. */
    public function testAnUnknownSortKeyFallsBackToTheDefault(): void
    {
        $this->seedSortablePrincipals();
        TenantContext::setTenantId(1);

        $bogus = $this->listedJtis('/api/admin/mcp/tokens?sort=not_a_column');

        $this->assertSame($this->listedJtis('/api/admin/mcp/tokens'), $bogus);
        $this->assertSame(['jti-echo', 'jti-delta', 'jti-alpha', 'jti-bravo'], $bogus);
    }

    /**
     * A sort key cannot carry SQL — it is only ever a key into the handler's own
     * map. Asserting the rows, not the query text, is what proves it.
     */
    public function testASortKeyCannotInjectSql(): void
    {
        $this->seedSortablePrincipals();
        TenantContext::setTenantId(1);

        $attack = $this->listedJtis(
            '/api/admin/mcp/tokens?sort=' . rawurlencode('name; DROP TABLE mcp_tokens--')
        );

        $this->assertSame($this->listedJtis('/api/admin/mcp/tokens'), $attack);
        $this->assertSame(
            5,
            (int) $this->pdo->query('SELECT COUNT(*) FROM mcp_tokens')->fetchColumn(),
            'the table is still there (four tenant-1 fixtures plus the tenant-2 decoy)'
        );
    }

    /** The search narrows the reported TOTAL, not only the returned rows. */
    public function testSearchNarrowsBothTheRowsAndTheTotal(): void
    {
        $this->seedSortablePrincipals();
        TenantContext::setTenantId(1);

        $this->assertSame(4, $this->listBody('/api/admin/mcp/tokens')['pagination']['total']);

        $filtered = $this->listBody('/api/admin/mcp/tokens?q=bot');

        $this->assertSame(['jti-delta', 'jti-alpha', 'jti-bravo'], array_column($filtered['data'], 'jti'));
        $this->assertSame(3, $filtered['pagination']['total'], 'the COUNT must carry the search predicate');
    }

    /** A filtered total does not advertise pages that come back empty. */
    public function testSearchTotalIsFilteredEvenWhenPaged(): void
    {
        $this->seedSortablePrincipals();
        TenantContext::setTenantId(1);

        $body = $this->listBody('/api/admin/mcp/tokens?q=bot&per_page=2');

        $this->assertCount(2, $body['data']);
        $this->assertSame(3, $body['pagination']['total']);
        $this->assertSame(2, $body['pagination']['totalPages']);
    }

    /** Search is case-insensitive on whichever engine the suite is running. */
    public function testSearchIsCaseInsensitive(): void
    {
        $this->seedSortablePrincipals();
        TenantContext::setTenantId(1);

        $this->assertSame(
            $this->listedJtis('/api/admin/mcp/tokens?q=bot'),
            $this->listedJtis('/api/admin/mcp/tokens?q=BOT'),
            'ILIKE on PostgreSQL, LIKE on SQLite — the same answer either way'
        );
    }

    /**
     * The JTI is searchable, because it is the only identifier an admin holds
     * when a log line points at one credential — and it is already in every row
     * this endpoint returns, so searching it discloses nothing new.
     */
    public function testSearchMatchesTheJti(): void
    {
        $this->seedSortablePrincipals();
        TenantContext::setTenantId(1);

        $this->assertSame(['jti-echo'], $this->listedJtis('/api/admin/mcp/tokens?q=jti-echo'));
    }

    /** So is the principal kind, the column the screen shows beside the name. */
    public function testSearchMatchesThePrincipalKind(): void
    {
        $this->seedSortablePrincipals();
        TenantContext::setTenantId(1);

        $this->assertSame(['jti-echo'], $this->listedJtis('/api/admin/mcp/tokens?q=service'));
    }

    /**
     * THE SECURITY PROPERTY: no search term reaches another tenant's credential.
     *
     * Tenant 2 owns a token named exactly like one of tenant 1's. Searching for
     * that name as tenant 1 must return tenant 1's row and ONLY tenant 1's — the
     * search is one more AND on top of `t.tenant_id = :tenant_id`, never a
     * replacement for it.
     */
    public function testSearchCannotSurfaceAnotherTenantsCredential(): void
    {
        $this->seedSortablePrincipals();
        TenantContext::setTenantId(1);

        $body = $this->listBody('/api/admin/mcp/tokens?q=Delta%20Bot');

        $this->assertSame(['jti-delta'], array_column($body['data'], 'jti'));
        $this->assertSame(1, $body['pagination']['total'], 'the tenant-2 twin must not be counted either');
        foreach ($body['data'] as $row) {
            $this->assertSame(1, $row['tenantId']);
        }
    }

    /** Nor does a search reach a REVOKED or an EXPIRED credential. */
    public function testSearchCannotSurfaceRevokedOrExpiredCredentials(): void
    {
        $this->seedSortablePrincipals();
        $this->seedExpiredToken('jti-expired', 10, 1, 'Expired Bot');
        $this->seedToken('jti-revoked', 10, 1, 'Revoked Bot');
        $this->revokeToken('jti-revoked');

        TenantContext::setTenantId(1);
        $body = $this->listBody('/api/admin/mcp/tokens?q=bot');

        $jtis = array_column($body['data'], 'jti');
        $this->assertNotContains('jti-expired', $jtis);
        $this->assertNotContains('jti-revoked', $jtis);
        $this->assertSame(3, $body['pagination']['total']);
    }

    /**
     * Paging over a TIED sort column returns every credential exactly once.
     *
     * Six credentials share one `principal_kind`; `LIMIT/OFFSET` over an ORDER
     * BY with ties has no defined order within the tie, so without the id
     * tiebreaker one credential can appear on two pages while another never
     * appears at all — an admin auditing MCP tokens would simply not be shown
     * one of them.
     */
    public function testPagingOverATiedSortColumnSeesEveryCredentialExactlyOnce(): void
    {
        for ($i = 1; $i <= 6; $i++) {
            $this->seedToken(sprintf('jti-tied-%02d', $i), 10, 1, sprintf('Tied %02d', $i), ['tools:call'], 'agent');
        }
        TenantContext::setTenantId(1);

        $seen = [];
        for ($page = 1; $page <= 3; $page++) {
            foreach ($this->listedJtis("/api/admin/mcp/tokens?sort=principalKind&per_page=2&page={$page}") as $jti) {
                $seen[] = $jti;
            }
        }

        sort($seen);
        $this->assertSame(
            ['jti-tied-01', 'jti-tied-02', 'jti-tied-03', 'jti-tied-04', 'jti-tied-05', 'jti-tied-06'],
            $seen,
            'every credential exactly once across the walk'
        );
    }

    /** And two consecutive pages of a tied sort never overlap. */
    public function testConsecutivePagesOfATiedSortDoNotOverlap(): void
    {
        for ($i = 1; $i <= 6; $i++) {
            $this->seedToken(sprintf('jti-tied-%02d', $i), 10, 1, sprintf('Tied %02d', $i), ['tools:call'], 'agent');
        }
        TenantContext::setTenantId(1);

        $first  = $this->listedJtis('/api/admin/mcp/tokens?sort=principalKind&per_page=2&page=1');
        $second = $this->listedJtis('/api/admin/mcp/tokens?sort=principalKind&per_page=2&page=2');

        $this->assertCount(2, $first);
        $this->assertCount(2, $second);
        $this->assertSame([], array_intersect($first, $second));
    }

    /** The envelope is unchanged, so a client cannot tell the endpoint moved. */
    public function testThePaginationEnvelopeKeepsItsShape(): void
    {
        $this->seedSortablePrincipals();
        TenantContext::setTenantId(1);

        $body = $this->listBody('/api/admin/mcp/tokens?page=2&per_page=2&sort=name');

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
        $response = $this->handler()->list($this->authedRequest('GET', $path, 1));
        $this->assertSame(200, $response->getStatusCode(), $response->getBody());

        /** @var array{data: list<array<string, mixed>>, pagination: array<string, int>} $body */
        $body = json_decode($response->getBody(), true);

        return $body;
    }

    /**
     * The JTIs the endpoint returned, in the order it returned them.
     *
     * @return list<string>
     */
    private function listedJtis(string $path): array
    {
        return array_map('strval', array_column($this->listBody($path)['data'], 'jti'));
    }

    /**
     * Four tenant-1 credentials whose name, kind, issuing profile, expiry and
     * creation date each produce a DIFFERENT order, so a sort that used the
     * wrong column cannot pass by coincidence — plus a tenant-2 credential
     * whose name duplicates one of them, which is what the isolation tests
     * search for.
     *
     * Dates are relative so the fixtures never age into the expiry filter.
     */
    private function seedSortablePrincipals(): void
    {
        $this->seedProfile(11);
        $this->seedProfile(12);

        // Inserted in this order, so ids run 1..4 and the tiebreaker is
        // predictable: delta, alpha, echo, bravo.
        $rows = [
            ['jti-delta', 'Delta Bot',   'agent',   10, '-4 days', '+40 days'],
            ['jti-alpha', 'Alpha Bot',   'user',    10, '-6 days', '+10 days'],
            ['jti-echo',  'Echo Runner', 'service', 11, '-2 days', '+30 days'],
            ['jti-bravo', 'Bravo Bot',   'agent',   12, '-8 days', '+50 days'],
        ];

        foreach ($rows as [$jti, $name, $kind, $profileId, $created, $expires]) {
            $this->seedTokenAt($jti, $profileId, 1, $name, $kind, $created, $expires);
        }

        // The decoy: same name, different tenant.
        $this->seedTokenAt('jti-other-tenant', 20, 2, 'Delta Bot', 'agent', '-4 days', '+40 days');
    }

    /** Insert a profile so the mcp_tokens FK holds on real PostgreSQL. */
    private function seedProfile(int $id): void
    {
        $this->pdo->prepare("
            INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled,
                two_factor_backup_codes_version, token_epoch, created_at, updated_at)
            VALUES (?, ?, 'x', false, 0, 0, NOW(), NOW())
            ON CONFLICT DO NOTHING
        ")->execute([$id, 'Profile ' . $id]);
    }

    /** Seed one active token with explicit (relative) created/expires stamps. */
    private function seedTokenAt(
        string $jti,
        int $profileId,
        int $tenantId,
        string $name,
        string $principalKind,
        string $createdOffset,
        string $expiresOffset,
    ): void {
        $stmt = $this->pdo->prepare("
            INSERT INTO mcp_tokens (jti, profile_id, tenant_id, name, principal_kind, scope, expires_at, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $jti,
            $profileId,
            $tenantId,
            $name,
            $principalKind,
            json_encode(['tools:call']),
            date('Y-m-d H:i:s', (int) strtotime($expiresOffset)),
            date('Y-m-d H:i:s', (int) strtotime($createdOffset)),
        ]);
    }

    // ====================== Helpers ======================

    /**
     * Build the handler with a RoleChecker stub that grants (or denies) mcp:tokens:manage.
     */
    private function handler(bool $grant = true): AiPrincipalsApiHandler
    {
        $roleChecker = $this->createMock(RoleChecker::class);
        $roleChecker->method('hasPermissionForProfile')->willReturn($grant);

        return new AiPrincipalsApiHandler($this->pdo, $roleChecker);
    }

    private function authedRequest(string $method, string $path, int $userId): Request
    {
        $request = new Request($method, $path);
        $request->user = (object) ['profile_id' => $userId];
        return $request;
    }

    /**
     * Seed base tenants and profiles needed by token fixtures.
     */
    private function seedBaseFixtures(): void
    {
        $this->pdo->exec("INSERT INTO tenants (id, name) VALUES (1, 'Tenant One') ON CONFLICT DO NOTHING");
        $this->pdo->exec("INSERT INTO tenants (id, name) VALUES (2, 'Tenant Two') ON CONFLICT DO NOTHING");

        $hash = password_hash('pw', PASSWORD_BCRYPT);
        // Profile 10 for tenant 1 tokens
        $this->pdo->prepare("
            INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled,
                two_factor_backup_codes_version, token_epoch, created_at, updated_at)
            VALUES (10, 'Profile Ten', ?, false, 0, 0, datetime('now'), datetime('now'))
            ON CONFLICT DO NOTHING
        ")->execute([$hash]);
        // Profile 20 for tenant 2 tokens
        $this->pdo->prepare("
            INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled,
                two_factor_backup_codes_version, token_epoch, created_at, updated_at)
            VALUES (20, 'Profile Twenty', ?, false, 0, 0, datetime('now'), datetime('now'))
            ON CONFLICT DO NOTHING
        ")->execute([$hash]);
    }

    /**
     * Seed an active token (expires in the future) for the given profile and tenant.
     *
     * @param string[] $scope
     */
    private function seedToken(
        string $jti,
        int $profileId,
        int $tenantId,
        string $name,
        array $scope = ['tools:call'],
        string $principalKind = 'user',
    ): void {
        $stmt = $this->pdo->prepare("
            INSERT INTO mcp_tokens (jti, profile_id, tenant_id, name, principal_kind, scope, expires_at, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        // Compute dates in PHP so both SQLite and PG accept the ISO format.
        $expiresAt = date('Y-m-d H:i:s', strtotime('+90 days'));
        $stmt->execute([$jti, $profileId, $tenantId, $name, $principalKind, json_encode($scope), $expiresAt]);
    }

    /**
     * Seed an already-expired token for the given profile and tenant.
     */
    private function seedExpiredToken(string $jti, int $profileId, int $tenantId, string $name): void
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO mcp_tokens (jti, profile_id, tenant_id, name, principal_kind, scope, expires_at, created_at)
            VALUES (?, ?, ?, ?, 'user', '[]', ?, ?)
        ");
        $expiresAt = date('Y-m-d H:i:s', strtotime('-1 day'));
        $createdAt = date('Y-m-d H:i:s', strtotime('-2 days'));
        $stmt->execute([$jti, $profileId, $tenantId, $name, $expiresAt, $createdAt]);
    }

    /**
     * Insert a JTI into the revoked_tokens table.
     */
    private function revokeToken(string $jti): void
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO revoked_tokens (jti, expires_at)
            VALUES (?, ?)
            ON CONFLICT (jti) DO NOTHING
        ");
        $expiresAt = date('Y-m-d H:i:s', strtotime('+90 days'));
        $stmt->execute([$jti, $expiresAt]);
    }
}
