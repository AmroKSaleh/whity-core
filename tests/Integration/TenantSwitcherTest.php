<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Auth\AuthHandler;
use Whity\Auth\JwtParser;
use Whity\Auth\TokenValidator;
use Whity\Core\Request;

/**
 * Claim-capturing JWT parser for this suite (same concept as in DualClaimAuthFlowTest,
 * declared here to keep this file self-contained without coupling to that test file).
 *
 * @internal
 */
final class SwitchTenantCapturingJwtParser extends JwtParser
{
    /** @var list<array{payload: array<string, mixed>, type: string}> */
    public array $captured = [];

    /**
     * @param array<string, mixed> $payload
     */
    public function create(array $payload, int $expiresIn = 3600, string $type = 'access'): string
    {
        $this->captured[] = ['payload' => $payload, 'type' => $type];

        return parent::create($payload, $expiresIn, $type);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function lastPayloadOfType(string $type): ?array
    {
        foreach (array_reverse($this->captured) as $entry) {
            if ($entry['type'] === $type) {
                return $entry['payload'];
            }
        }

        return null;
    }
}

/**
 * WC-f8164c87: Integration tests for POST /api/auth/switch-tenant.
 *
 * Validates the security model:
 *  - Switching to an active membership succeeds and the new JWT carries the
 *    correct active_tenant_id.
 *  - Switching to a tenant the caller has NO membership in is refused 403.
 *  - Switching to a tenant where the caller's membership is SUSPENDED is refused
 *    403 (suspended != active).
 *  - GET /api/me returns only the caller's OWN memberships (cross-tenant
 *    isolation: another profile's memberships are never exposed).
 *
 * Runs against in-memory SQLite locally; the same suite is re-run on real
 * PostgreSQL in CI via PHPUNIT_PG_DSN (see .github/workflows/automated-tests.yml).
 */
final class TenantSwitcherTest extends TestCase
{
    private const SECRET = 'tenant-switcher-test-secret-key-padded-min-32-byte';
    private const PASSWORD = 'testpassword123';

    private const TENANT_A = 1;
    private const TENANT_B = 2;
    private const TENANT_C = 3; // caller has no membership here

    private PDO $pdo;
    private SwitchTenantCapturingJwtParser $jwtParser;
    private AuthHandler $handler;

    private int $aliceProfileId;
    private int $bobProfileId;

    protected function setUp(): void
    {
        $_COOKIE = [];

        $this->pdo = SchemaFromMigrations::make();
        $this->jwtParser = new SwitchTenantCapturingJwtParser(self::SECRET);
        $this->handler = new AuthHandler(
            $this->pdo,
            $this->jwtParser,
            new TokenValidator($this->jwtParser, $this->pdo),
        );

        // Seed tenants and role
        $this->pdo->exec("INSERT OR IGNORE INTO tenants (id, name) VALUES
            (1, 'Tenant A'),
            (2, 'Tenant B'),
            (3, 'Tenant C')");
        $this->pdo->exec("INSERT OR IGNORE INTO roles (id, name) VALUES (1, 'admin'), (2, 'user')");

        // Alice: multi-tenant profile — ACTIVE in A and B; no membership in C
        $this->seedUser('alice@example.com', self::TENANT_A);
        $this->aliceProfileId = $this->seedProfile('Alice', 'alice@example.com');
        $this->seedMembership($this->aliceProfileId, self::TENANT_A, 1, 'active');
        $this->seedMembership($this->aliceProfileId, self::TENANT_B, 2, 'active');

        // Bob: single-tenant profile — SUSPENDED in A (used for suspension test)
        $this->seedUser('bob@example.com', self::TENANT_A);
        $this->bobProfileId = $this->seedProfile('Bob', 'bob@example.com');
        $this->seedMembership($this->bobProfileId, self::TENANT_A, 1, 'suspended');
    }

    protected function tearDown(): void
    {
        $_COOKIE = [];
    }

    // ── switch-tenant: happy path ──────────────────────────────────────────────

    /**
     * A profile with an active membership in the target tenant may switch to it.
     * The newly minted access token must carry the new active_tenant_id.
     */
    public function testSwitchToActiveMembershipSucceeds(): void
    {
        // Alice is currently in Tenant A; she switches to Tenant B.
        $this->setAccessCookie($this->aliceProfileId, self::TENANT_A);

        $request = $this->makeRequest(['tenant_id' => self::TENANT_B]);
        $response = $this->handler->handleSwitchTenant($request);

        self::assertSame(200, $response->getStatusCode());

        $body = json_decode($response->getBody(), true);
        self::assertIsArray($body);
        self::assertArrayHasKey('user', $body);
        self::assertSame(self::TENANT_B, (int) $body['user']['tenant_id']);

        // The freshly minted access token must carry active_tenant_id = TENANT_B.
        $minted = $this->jwtParser->lastPayloadOfType('access');
        self::assertIsArray($minted, 'An access token must have been minted on switch.');
        self::assertSame($this->aliceProfileId, $minted['profile_id'] ?? null);
        self::assertSame(self::TENANT_B, $minted['active_tenant_id'] ?? null);
    }

    /**
     * Switching to the already-active tenant is a valid no-op (idempotent).
     */
    public function testSwitchToCurrentTenantSucceeds(): void
    {
        $this->setAccessCookie($this->aliceProfileId, self::TENANT_A);

        $request = $this->makeRequest(['tenant_id' => self::TENANT_A]);
        $response = $this->handler->handleSwitchTenant($request);

        self::assertSame(200, $response->getStatusCode());

        $minted = $this->jwtParser->lastPayloadOfType('access');
        self::assertIsArray($minted);
        self::assertSame(self::TENANT_A, $minted['active_tenant_id'] ?? null);
    }

    // ── switch-tenant: security gates ─────────────────────────────────────────

    /**
     * Switching to a tenant the profile has NO membership in must be refused 403.
     * No token must be re-minted (the count must not increase after the 403 response).
     */
    public function testSwitchToTenantWithoutMembershipReturns403(): void
    {
        $this->setAccessCookie($this->aliceProfileId, self::TENANT_A);

        // Capture AFTER setting the cookie (setAccessCookie itself mints a token).
        $capturedBefore = count($this->jwtParser->captured);

        $request = $this->makeRequest(['tenant_id' => self::TENANT_C]);
        $response = $this->handler->handleSwitchTenant($request);

        self::assertSame(403, $response->getStatusCode());

        // No additional token must have been minted after the capture point.
        self::assertCount($capturedBefore, $this->jwtParser->captured, 'No token must be minted on a refused switch.');
    }

    /**
     * Switching to a tenant where the profile's membership is SUSPENDED is refused.
     *
     * Bob has a suspended membership in Tenant A. We give him a system-tenant
     * token (which passes the membership guard) and then ask to switch to Tenant A
     * — the switch handler's own membership check must refuse it with 403 because
     * the Tenant A membership is not 'active'.
     */
    public function testSwitchToSuspendedMembershipReturns403(): void
    {
        // Use system-tenant (id=0) for the current token: the membership guard
        // skips tenant-0 (cross-tenant authority by convention), so validateAccessToken
        // succeeds and the request reaches our handleSwitchTenant logic.
        $token = $this->jwtParser->create([
            'profile_id'       => $this->bobProfileId,
            'active_tenant_id' => 0, // system tenant — no membership row required
            'email'            => 'bob@example.com',
            'role'             => '',
            'token_epoch'      => 0,
        ], 900, 'access');
        $_COOKIE['access_token'] = $token;

        $capturedBefore = count($this->jwtParser->captured);

        $request = $this->makeRequest(['tenant_id' => self::TENANT_A]);
        $response = $this->handler->handleSwitchTenant($request);

        // Bob's Tenant A membership is suspended (not active), so the switch
        // must be refused 403 — the handler must never treat 'suspended' as 'active'.
        self::assertSame(403, $response->getStatusCode());
        self::assertCount($capturedBefore, $this->jwtParser->captured, 'No token must be minted on a suspended-membership switch.');
    }

    /**
     * Missing or non-integer tenant_id body field must return 400.
     */
    public function testSwitchWithMissingTenantIdReturns400(): void
    {
        $this->setAccessCookie($this->aliceProfileId, self::TENANT_A);

        $request = $this->makeRequest([]);
        $response = $this->handler->handleSwitchTenant($request);

        self::assertSame(400, $response->getStatusCode());
    }

    /**
     * Missing or invalid access token must return 401.
     */
    public function testSwitchWithNoAccessTokenReturns401(): void
    {
        // No cookie set
        $request = $this->makeRequest(['tenant_id' => self::TENANT_B]);
        $response = $this->handler->handleSwitchTenant($request);

        self::assertSame(401, $response->getStatusCode());
    }

    // ── GET /api/me: memberships surface ──────────────────────────────────────

    /**
     * GET /api/me returns the caller's own memberships (tenant_id, tenant_name,
     * role) and not those of another profile — cross-tenant/cross-profile
     * isolation.
     */
    public function testGetMeReturnsMembershipsForCallerOnly(): void
    {
        $this->setAccessCookie($this->aliceProfileId, self::TENANT_A);

        $request = new Request('GET', '/api/me', []);
        $response = $this->handler->handleMe($request);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        self::assertIsArray($body);
        self::assertArrayHasKey('memberships', $body, 'GET /api/me must include a memberships array.');

        $memberships = $body['memberships'];
        self::assertIsArray($memberships);

        // Alice has exactly 2 active memberships (Tenant A and Tenant B).
        self::assertCount(2, $memberships, 'Alice must see exactly her 2 active memberships.');

        $tenantIds = array_column($memberships, 'tenant_id');
        self::assertContains(self::TENANT_A, $tenantIds);
        self::assertContains(self::TENANT_B, $tenantIds);

        // Each entry must have tenant_name and role.
        foreach ($memberships as $m) {
            self::assertArrayHasKey('tenant_id', $m);
            self::assertArrayHasKey('tenant_name', $m);
            self::assertArrayHasKey('role', $m);
        }
    }

    /**
     * Bob's suspended memberships must NOT appear in his GET /api/me response —
     * only active memberships are exposed.
     */
    public function testGetMeExcludesSuspendedMemberships(): void
    {
        // Give Bob a valid session (he has no active memberships — the real app
        // would block login; here we mint directly to test the /api/me shape).
        $token = $this->jwtParser->create([
            'profile_id'       => $this->bobProfileId,
            'active_tenant_id' => 0, // system tenant for this token so it passes validation
            'email'            => 'bob@example.com',
            'role'             => '',
            'token_epoch'      => 0,
        ], 900, 'access');
        $_COOKIE['access_token'] = $token;

        $request = new Request('GET', '/api/me', []);
        $response = $this->handler->handleMe($request);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        self::assertIsArray($body);
        self::assertArrayHasKey('memberships', $body);

        // Bob's suspended membership must not appear.
        self::assertEmpty($body['memberships'], 'Suspended memberships must not appear in GET /api/me.');
    }

    /**
     * GET /api/me must not expose another profile's memberships when called by
     * Alice (cross-profile isolation check).
     */
    public function testGetMeDoesNotLeakOtherProfileMemberships(): void
    {
        $this->setAccessCookie($this->aliceProfileId, self::TENANT_A);

        $request = new Request('GET', '/api/me', []);
        $response = $this->handler->handleMe($request);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        self::assertIsArray($body);

        $membershipTenantIds = array_column($body['memberships'] ?? [], 'tenant_id');

        // Bob's membership (Tenant A, suspended) must not appear in Alice's list.
        // Alice has Tenant A and B (active). Tenant A appears once (Alice's own).
        // The suspended Bob-in-Tenant-A row must NOT show up.
        $tenantACount = count(array_filter($membershipTenantIds, static fn ($id) => $id === self::TENANT_A));
        self::assertSame(1, $tenantACount, 'Tenant A must appear exactly once in Alice\'s memberships.');
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $body
     */
    private function makeRequest(array $body): Request
    {
        return new Request(
            'POST',
            '/api/auth/switch-tenant',
            ['Content-Type' => 'application/json'],
            (string) json_encode($body)
        );
    }

    private function setAccessCookie(int $profileId, int $tenantId): void
    {
        $_COOKIE['access_token'] = $this->jwtParser->create([
            'profile_id'       => $profileId,
            'active_tenant_id' => $tenantId,
            'email'            => 'alice@example.com',
            'role'             => 'admin',
            'token_epoch'      => 0,
        ], 900, 'access');
    }

    private function seedUser(string $email, int $tenantId): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO users (tenant_id, email, password, role_id, created_at, token_epoch)
             VALUES (?, ?, ?, 1, datetime('now'), 0)"
        );
        $stmt->execute([
            $tenantId,
            $email,
            password_hash(self::PASSWORD, PASSWORD_BCRYPT),
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    private function seedProfile(string $displayName, string $email): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO profiles (display_name, password_hash, two_factor_enabled,
                two_factor_backup_codes_version, token_epoch, created_at, updated_at)
             VALUES (?, ?, false, 0, 0, datetime('now'), datetime('now'))"
        );
        $stmt->execute([$displayName, password_hash(self::PASSWORD, PASSWORD_BCRYPT)]);
        $profileId = (int) $this->pdo->lastInsertId();

        $emailStmt = $this->pdo->prepare(
            "INSERT INTO profile_emails (profile_id, email, verified, is_primary, created_at)
             VALUES (?, ?, true, true, datetime('now'))"
        );
        $emailStmt->execute([$profileId, $email]);

        return $profileId;
    }

    /**
     * @param 'active'|'suspended'|'invited' $status
     */
    private function seedMembership(int $profileId, int $tenantId, int $roleId, string $status): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO memberships (profile_id, tenant_id, role_id, status, created_at)
             VALUES (?, ?, ?, ?, datetime('now'))"
        );
        $stmt->execute([$profileId, $tenantId, $roleId, $status]);
        return (int) $this->pdo->lastInsertId();
    }
}
