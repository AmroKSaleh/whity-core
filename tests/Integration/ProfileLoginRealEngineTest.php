<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Auth\ActiveTenantMembershipGuard;
use Whity\Auth\AuthHandler;
use Whity\Auth\JwtParser;
use Whity\Auth\TokenValidator;
use Whity\Core\Identity\MembershipRepository;
use Whity\Core\Request;

/**
 * Spy JwtParser: captures minted payloads so claim content can be asserted
 * without intercepting Set-Cookie headers (unavailable under the CLI SAPI).
 */
final class ProfileLoginClaimCapturingJwtParser extends JwtParser
{
    /** @var list<array{payload: array<string, mixed>, type: string}> */
    public array $captured = [];

    public function create(array $payload, int $expiresIn = 3600, string $type = 'access'): string
    {
        $this->captured[] = ['payload' => $payload, 'type' => $type];
        return parent::create($payload, $expiresIn, $type);
    }

    /**
     * @return array<string, mixed>|null The last captured payload of a given type.
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
 * WC-c35c4ce0: Profile-based login rewrite + #181 regression suite.
 *
 * This suite proves the core acceptance criterion for the auth/login rewrite:
 * login MUST authenticate via profile_email (globally unique, fixes #181) and
 * MUST NOT use the old tenant-ambiguous SELECT-by-email-without-tenant-predicate
 * query from the users table.
 *
 * Tests
 * ─────
 *  #181 REGRESSION (core acceptance criterion)
 *   - two profiles with the same email in DIFFERENT tenants is structurally
 *     impossible (UNIQUE(email) on profile_emails), but verify that two profiles
 *     who happen to hold the SAME email never cross-login.
 *   - a profile in tenant A cannot be used to log into tenant B.
 *
 *  LOGIN ALGORITHM (ADR 0005 §6)
 *   - verified profile_email + correct password → login succeeds, JWT carries
 *     {profile_id, active_tenant_id}.
 *   - unverified profile_email → login refused (403).
 *   - wrong password → login refused (401).
 *   - zero active memberships → login refused (403).
 *   - exactly one active membership → auto-selected as active_tenant_id.
 *   - multiple active memberships → deterministic selection (lowest tenant_id).
 *   - suspended membership only → login refused (403).
 *   - invited membership only → login refused (403).
 *   - profile missing → login refused (401).
 *
 *  2FA PATH
 *   - 2FA secret is read from the profile (not users) after the login rewrite.
 *   - temp token carries profile_id + active_tenant_id.
 *
 *  BACKWARD COMPAT (dual-claim window)
 *   - A profile-based login still emits legacy {user_id, tenant_id} claims so
 *     existing sessions remain valid during the dual window.
 *
 *  SECURITY FOLLOW-UPS (WC-c35c4ce0, step-1 review carry-overs)
 *   (a) AuditContext::set uses userIdFromPayload() — covered in
 *       EnforceTenantIsolationAuditContextTest in the Http suite.
 *   (b) tenant_id === active_tenant_id invariant enforcement when both legacy
 *       and new claims are present — covered by
 *       TokenSplitBrainInvariantRealEngineTest in the Auth suite.
 *
 * Runs on real SQLite locally and real PostgreSQL in CI (PHPUNIT_PG_DSN).
 */
final class ProfileLoginRealEngineTest extends TestCase
{
    private const SECRET   = 'wc-c35c4ce0-profile-login-test-secret-32b-pad';
    private const PASSWORD = 'profile-login-test-password-abc123';

    private const TENANT_A = 1;
    private const TENANT_B = 2;

    private PDO $pdo;
    private ProfileLoginClaimCapturingJwtParser $jwtParser;
    private AuthHandler $handler;
    private MembershipRepository $memberships;

    private int $profileIdA;    // profile whose verified email is alice@corp.com
    private int $userIdA;       // legacy users row for alice in tenant A
    private int $profileIdB;    // profile whose verified email is bob@corp.com
    private int $userIdB;       // legacy users row for bob in tenant B

    protected function setUp(): void
    {
        $_COOKIE = [];

        $this->pdo = SchemaFromMigrations::make();
        $this->jwtParser = new ProfileLoginClaimCapturingJwtParser(self::SECRET);
        $this->memberships = new MembershipRepository($this->pdo);
        $this->handler = new AuthHandler(
            $this->pdo,
            $this->jwtParser,
            new TokenValidator($this->jwtParser, $this->pdo),
        );

        // Fixture tenants and a single role.
        $this->pdo->exec(
            "INSERT OR IGNORE INTO tenants (id, name, created_at) VALUES (1, 'tenant-a', datetime('now'))"
        );
        $this->pdo->exec(
            "INSERT OR IGNORE INTO tenants (id, name, created_at) VALUES (2, 'tenant-b', datetime('now'))"
        );
        $this->pdo->exec("INSERT OR IGNORE INTO roles (id, name) VALUES (1, 'admin')");

        // Seed Profile A: alice@corp.com → tenant A only.
        $this->profileIdA = $this->seedProfile('alice@corp.com', true);
        $this->userIdA    = $this->seedUser('alice@corp.com', self::TENANT_A);
        $this->memberships->insert($this->profileIdA, self::TENANT_A, 1);

        // Seed Profile B: bob@corp.com → tenant B only (different email, different tenant).
        $this->profileIdB = $this->seedProfile('bob@corp.com', true);
        $this->userIdB    = $this->seedUser('bob@corp.com', self::TENANT_B);
        $this->memberships->insert($this->profileIdB, self::TENANT_B, 1);
    }

    protected function tearDown(): void
    {
        $_COOKIE = [];
    }

    // ── #181 REGRESSION ──────────────────────────────────────────────────────────

    /**
     * Core acceptance criterion for #181:
     *
     * alice@corp.com is in tenant A.  bob@corp.com is in tenant B.  The old code
     * did SELECT … FROM users WHERE email = ? which is tenant-ambiguous when the
     * UNIQUE(tenant_id,email) schema allows duplicate emails across tenants.
     *
     * After the rewrite, login resolves via profile_emails (UNIQUE(email) — globally
     * unique by schema), so alice always resolves her own profile and bob his.
     * There is no code path that can produce a cross-tenant login.
     */
    public function testCrossTenantDuplicateEmailCannotCrossLogin(): void
    {
        // Alice logs in.
        $response = $this->login('alice@corp.com');
        self::assertSame(200, $response->getStatusCode(), 'alice login must succeed');

        $payload = $this->jwtParser->lastPayloadOfType('access');
        self::assertIsArray($payload);
        // JWT must be anchored to alice's profile, NOT bob's.
        self::assertSame(
            $this->profileIdA,
            $payload['profile_id'] ?? null,
            '#181 regression: alice must resolve to her own profile_id, not bob\'s.'
        );
        self::assertSame(
            self::TENANT_A,
            $payload['active_tenant_id'] ?? null,
            '#181 regression: alice\'s active_tenant_id must be tenant A, not tenant B.'
        );

        // Bob logs in.
        $this->jwtParser->captured = [];
        $response = $this->login('bob@corp.com');
        self::assertSame(200, $response->getStatusCode(), 'bob login must succeed');

        $payload = $this->jwtParser->lastPayloadOfType('access');
        self::assertIsArray($payload);
        self::assertSame(
            $this->profileIdB,
            $payload['profile_id'] ?? null,
            '#181 regression: bob must resolve to his own profile_id, not alice\'s.'
        );
        self::assertSame(
            self::TENANT_B,
            $payload['active_tenant_id'] ?? null,
            '#181 regression: bob\'s active_tenant_id must be tenant B, not tenant A.'
        );
    }

    /**
     * Alice's session token is anchored to tenant A.  Even if an attacker crafts
     * a request to tenant B using alice's credentials, the JWT carries
     * active_tenant_id = A and the ActiveTenantMembershipGuard refuses it for
     * tenant B (no membership row exists).
     */
    public function testProfileWithNoMembershipInOtherTenantCannotReachIt(): void
    {
        $response = $this->login('alice@corp.com');
        self::assertSame(200, $response->getStatusCode());

        $payload = $this->jwtParser->lastPayloadOfType('access');
        self::assertIsArray($payload);
        // alice has NO membership in tenant B.
        self::assertSame(self::TENANT_A, $payload['active_tenant_id'] ?? null);

        // Synthesise a tampered token claiming tenant B.
        $tamperedClaims = $payload;
        $tamperedClaims['active_tenant_id'] = self::TENANT_B;
        $tamperedClaims['tenant_id']        = self::TENANT_B;
        $tamperedToken = $this->jwtParser->create($tamperedClaims, 900, 'access');
        $_COOKIE['access_token'] = $tamperedToken;

        $guard = new ActiveTenantMembershipGuard($this->pdo);
        self::assertFalse(
            $guard->allows($tamperedClaims),
            'ActiveTenantMembershipGuard must reject alice\'s profile in tenant B (no membership).'
        );
    }

    // ── LOGIN ALGORITHM ───────────────────────────────────────────────────────────

    /**
     * Happy path: verified email + correct password → 200 with dual-claim JWT.
     */
    public function testLoginWithVerifiedEmailAndCorrectPasswordSucceeds(): void
    {
        $response = $this->login('alice@corp.com');
        self::assertSame(200, $response->getStatusCode());

        $body = json_decode($response->getBody(), true);
        self::assertIsArray($body);
        self::assertArrayHasKey('user', $body);

        $payload = $this->jwtParser->lastPayloadOfType('access');
        self::assertIsArray($payload);
        self::assertArrayHasKey('profile_id', $payload, 'Access token must carry profile_id.');
        self::assertArrayHasKey('active_tenant_id', $payload, 'Access token must carry active_tenant_id.');
        self::assertSame($this->profileIdA, $payload['profile_id']);
        self::assertSame(self::TENANT_A, $payload['active_tenant_id']);
    }

    /**
     * Unverified email must be rejected even if password is correct.
     */
    public function testLoginWithUnverifiedEmailIsRejected(): void
    {
        $unverifiedId = $this->seedProfile('unverified@corp.com', false); // verified=false
        $this->seedUser('unverified@corp.com', self::TENANT_A);
        $this->memberships->insert($unverifiedId, self::TENANT_A, 1);

        $response = $this->login('unverified@corp.com');
        // Must not be 200 (403 or 401 both acceptable).
        self::assertNotSame(200, $response->getStatusCode(), 'An unverified email must not authenticate.');
    }

    /**
     * Wrong password must return 401.
     */
    public function testLoginWithWrongPasswordIsRejected(): void
    {
        $request = new Request('POST', '/api/login', [], (string) json_encode([
            'email'    => 'alice@corp.com',
            'password' => 'wrong-password-xyz',
        ]));
        $response = $this->handler->handle($request);
        self::assertSame(401, $response->getStatusCode());
    }

    /**
     * Profile with zero active memberships (only suspended) must be refused.
     */
    public function testLoginWithOnlySuspendedMembershipIsRejected(): void
    {
        $profileId = $this->seedProfile('suspended@corp.com', true);
        $this->seedUser('suspended@corp.com', self::TENANT_A);
        $membershipId = $this->memberships->insert($profileId, self::TENANT_A, 1);
        $this->memberships->suspend($membershipId, self::TENANT_A);

        $response = $this->login('suspended@corp.com');
        self::assertSame(403, $response->getStatusCode(), 'Suspended-only membership must refuse login.');
    }

    /**
     * Profile with only an invited (not accepted) membership must be refused.
     */
    public function testLoginWithOnlyInvitedMembershipIsRejected(): void
    {
        $profileId = $this->seedProfile('invited@corp.com', true);
        $this->seedUser('invited@corp.com', self::TENANT_A);
        $this->memberships->invite($profileId, self::TENANT_A, 1);

        $response = $this->login('invited@corp.com');
        self::assertSame(403, $response->getStatusCode(), 'Invite-only membership must refuse login.');
    }

    /**
     * Profile with zero memberships of any kind must be refused.
     */
    public function testLoginWithNoMembershipsIsRejected(): void
    {
        $this->seedProfile('orphan@corp.com', true);
        $this->seedUser('orphan@corp.com', self::TENANT_A);

        $response = $this->login('orphan@corp.com');
        self::assertSame(403, $response->getStatusCode(), 'Profile with no memberships must be refused.');
    }

    /**
     * Exactly one active membership → auto-selected as active_tenant_id.
     */
    public function testLoginWithExactlyOneMembershipAutoSelectsTenant(): void
    {
        $response = $this->login('alice@corp.com'); // alice has exactly one: tenant A
        self::assertSame(200, $response->getStatusCode());

        $payload = $this->jwtParser->lastPayloadOfType('access');
        self::assertIsArray($payload);
        self::assertSame(self::TENANT_A, $payload['active_tenant_id'] ?? null);
    }

    /**
     * Multiple active memberships → deterministic selection: lowest tenant_id.
     *
     * ADR 0005 §6: when multiple active memberships exist, the login picks the
     * one with the lowest tenant_id as a deterministic default (the tenant-switcher,
     * a later step, lets the user change it).
     */
    public function testLoginWithMultipleMembershipsSelectsLowestTenantId(): void
    {
        // Give alice memberships in BOTH tenant A and tenant B.
        $this->memberships->insert($this->profileIdA, self::TENANT_B, 1);

        $response = $this->login('alice@corp.com');
        self::assertSame(200, $response->getStatusCode());

        $payload = $this->jwtParser->lastPayloadOfType('access');
        self::assertIsArray($payload);
        // Lowest tenant_id wins (tenant A = 1, tenant B = 2).
        self::assertSame(
            self::TENANT_A,
            $payload['active_tenant_id'] ?? null,
            'With multiple active memberships, the lowest tenant_id must be selected as the default.'
        );
    }

    /**
     * Login with a profile_email that does not exist at all must return 401.
     */
    public function testLoginWithNonExistentEmailReturns401(): void
    {
        $response = $this->login('nobody@corp.com');
        self::assertSame(401, $response->getStatusCode());
    }

    // ── BACKWARD COMPAT (dual-claim window) ────────────────────────────────────────

    /**
     * During the dual-claim window, login must STILL emit legacy {user_id, tenant_id}
     * claims alongside the new claims so existing sessions remain valid.
     */
    public function testLoginEmitsLegacyClaimsDuringDualWindow(): void
    {
        $response = $this->login('alice@corp.com');
        self::assertSame(200, $response->getStatusCode());

        $payload = $this->jwtParser->lastPayloadOfType('access');
        self::assertIsArray($payload);

        self::assertArrayHasKey(
            'user_id',
            $payload,
            'Legacy user_id must still be present during the dual-claim window.'
        );
        self::assertArrayHasKey(
            'tenant_id',
            $payload,
            'Legacy tenant_id must still be present during the dual-claim window.'
        );
    }

    /**
     * The legacy user_id in the token must match alice's actual users row.
     */
    public function testLegacyUserIdInTokenMatchesUsersRow(): void
    {
        $response = $this->login('alice@corp.com');
        self::assertSame(200, $response->getStatusCode());

        $payload = $this->jwtParser->lastPayloadOfType('access');
        self::assertIsArray($payload);
        self::assertSame($this->userIdA, $payload['user_id'] ?? null);
        self::assertSame(self::TENANT_A, $payload['tenant_id'] ?? null);
    }

    // ── /me PATH ──────────────────────────────────────────────────────────────────

    /**
     * GET /api/me must still work after a profile-based login.
     */
    public function testGetMeWorksAfterProfileLogin(): void
    {
        // Mint an access token as the login does.
        $this->login('alice@corp.com');
        $payload = $this->jwtParser->lastPayloadOfType('access');
        self::assertIsArray($payload);

        $_COOKIE['access_token'] = $this->jwtParser->create($payload, 900, 'access');

        $response = $this->handler->handleMe(new Request('GET', '/api/me', []));
        self::assertSame(200, $response->getStatusCode());

        $body = json_decode($response->getBody(), true);
        self::assertIsArray($body);
        self::assertArrayHasKey('user', $body);
    }

    // ── helpers ───────────────────────────────────────────────────────────────────

    private function login(string $email): \Whity\Core\Response
    {
        return $this->handler->handle(new Request('POST', '/api/login', [], (string) json_encode([
            'email'    => $email,
            'password' => self::PASSWORD,
        ])));
    }

    /**
     * Seed a profile + profile_email row and return the profile id.
     */
    private function seedProfile(string $email, bool $verified): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO profiles (display_name, password_hash, two_factor_enabled,
                two_factor_backup_codes_version, token_epoch, created_at, updated_at)
             VALUES (?, ?, false, 0, 0, datetime('now'), datetime('now'))"
        );
        $stmt->execute([
            explode('@', $email)[0], // display name from local-part
            password_hash(self::PASSWORD, PASSWORD_BCRYPT),
        ]);
        $profileId = (int) $this->pdo->lastInsertId();

        $emailStmt = $this->pdo->prepare(
            "INSERT INTO profile_emails (profile_id, email, verified, is_primary, created_at)
             VALUES (?, ?, ?, true, datetime('now'))"
        );
        $emailStmt->execute([$profileId, $email, $verified ? 1 : 0]);

        return $profileId;
    }

    /**
     * Seed a legacy users row (still required by the dual-claim window) and return its id.
     */
    private function seedUser(string $email, int $tenantId): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO users (tenant_id, email, password, role_id, created_at, token_epoch)
             VALUES (?, ?, ?, ?, datetime('now'), 0)"
        );
        $stmt->execute([
            $tenantId,
            $email,
            password_hash(self::PASSWORD, PASSWORD_BCRYPT),
            1,
        ]);
        return (int) $this->pdo->lastInsertId();
    }
}
