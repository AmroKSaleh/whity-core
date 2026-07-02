<?php

declare(strict_types=1);

namespace Tests\Auth;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Auth\JwtParser;
use Whity\Auth\TokenValidator;
use Whity\Core\Identity\MembershipRepository;
use Whity\Mcp\Auth\McpPrincipal;

/**
 * WC-d4340daf: dual-claim JWT validation against a real engine.
 *
 * The JWT claim model is evolving from the legacy {user_id, tenant_id} pair to
 * the ADR 0005 target {profile_id, active_tenant_id}. During the dual-claim
 * window BOTH shapes must validate:
 *
 *  - LEGACY tokens (user_id/tenant_id only, no profile_id/active_tenant_id)
 *    keep today's behaviour EXACTLY — no membership lookup is performed,
 *    because pre-migration users have no profiles/memberships rows yet.
 *  - NEW-CLAIMS tokens (profile_id + active_tenant_id) are additionally gated
 *    on a live `memberships` row: an active membership in active_tenant_id, or
 *    system-tenant authority when active_tenant_id = 0 (the id-0 convention).
 *  - PARTIAL claim sets (one of the two new claims without the other) are
 *    never issued and must fail closed.
 *
 * Runs on real SQLite locally and real PostgreSQL in CI (PHPUNIT_PG_DSN), via
 * SchemaFromMigrations.
 */
final class DualClaimValidationRealEngineTest extends TestCase
{
    private const SECRET = 'dual-claim-test-secret-key-padded-min-32-byte-key';

    private const TENANT_A = 1;
    private const TENANT_B = 2;

    private PDO $pdo;
    private JwtParser $jwtParser;
    private TokenValidator $validator;
    private MembershipRepository $memberships;

    /** Profile id of the seeded test profile. */
    private int $profileId;

    /** Legacy users row id (tenant A). */
    private int $userId;

    protected function setUp(): void
    {
        $_COOKIE = [];

        $this->pdo = SchemaFromMigrations::make();
        $this->jwtParser = new JwtParser(self::SECRET);
        $this->validator = new TokenValidator($this->jwtParser, $this->pdo);
        $this->memberships = new MembershipRepository($this->pdo);

        // Tenants + role fixtures (system tenant 0 is migration-seeded).
        $this->pdo->exec("INSERT OR IGNORE INTO tenants (id, name, created_at) VALUES (1, 'tenant-a', datetime('now'))");
        $this->pdo->exec("INSERT OR IGNORE INTO tenants (id, name, created_at) VALUES (2, 'tenant-b', datetime('now'))");
        $this->pdo->exec("INSERT OR IGNORE INTO roles (id, name) VALUES (1, 'admin')");

        // Legacy users row (epoch 0) so legacy-claims epoch checks keep working.
        $stmt = $this->pdo->prepare(
            "INSERT INTO users (tenant_id, email, password, role_id, created_at, token_epoch)
             VALUES (?, ?, ?, ?, datetime('now'), 0)"
        );
        $stmt->execute([self::TENANT_A, 'dual@example.com', password_hash('pw-not-used-here', PASSWORD_BCRYPT), 1]);
        $this->userId = (int) $this->pdo->lastInsertId();

        // Global profile (ADR 0005): the new identity anchor.
        $this->pdo->exec(
            "INSERT INTO profiles (display_name, password_hash, two_factor_enabled,
                two_factor_backup_codes_version, token_epoch, created_at, updated_at)
             VALUES ('Dual', 'x', false, 0, 0, datetime('now'), datetime('now'))"
        );
        $this->profileId = (int) $this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        $_COOKIE = [];
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $claims
     */
    private function mint(array $claims, string $type = 'access', int $ttl = 900): string
    {
        return $this->jwtParser->create($claims, $ttl, $type);
    }

    /**
     * @return array<string, mixed> Legacy claim set for the seeded user.
     */
    private function legacyClaims(): array
    {
        return [
            'user_id' => $this->userId,
            'tenant_id' => self::TENANT_A,
            'email' => 'dual@example.com',
            'role' => 'admin',
            'token_epoch' => 0,
        ];
    }

    /**
     * @return array<string, mixed> Dual claim set (legacy + new claims).
     */
    private function dualClaims(int $activeTenantId = self::TENANT_A): array
    {
        return $this->legacyClaims() + [
            'profile_id' => $this->profileId,
            'active_tenant_id' => $activeTenantId,
        ];
    }

    // ── backward compatibility: legacy tokens keep working ──────────────────────

    public function testLegacyAccessTokenWithoutMembershipRowsIsAccepted(): void
    {
        // No memberships seeded at all: pre-migration users must not be locked out.
        $_COOKIE['access_token'] = $this->mint($this->legacyClaims());

        $claims = $this->validator->validateAccessToken();

        self::assertIsArray($claims, 'Legacy tokens must validate without any membership rows (dual window).');
        self::assertSame($this->userId, $claims['user_id']);
    }

    public function testLegacyRefreshTokenWithoutMembershipRowsIsAccepted(): void
    {
        $_COOKIE['refresh_token'] = $this->mint($this->legacyClaims(), 'refresh', 604800);

        self::assertIsArray($this->validator->validateRefreshToken());
    }

    // ── new-claims tokens: membership gate ─────────────────────────────────────

    public function testDualClaimAccessTokenWithActiveMembershipIsAccepted(): void
    {
        $this->memberships->insert($this->profileId, self::TENANT_A, 1);

        $_COOKIE['access_token'] = $this->mint($this->dualClaims());

        $claims = $this->validator->validateAccessToken();

        self::assertIsArray($claims);
        self::assertSame($this->profileId, $claims['profile_id']);
        self::assertSame(self::TENANT_A, $claims['active_tenant_id']);
    }

    public function testDualClaimAccessTokenWithoutMembershipIsRejected(): void
    {
        // Membership exists only in tenant B; token claims tenant A.
        $this->memberships->insert($this->profileId, self::TENANT_B, 1);

        $_COOKIE['access_token'] = $this->mint($this->dualClaims(self::TENANT_A));

        self::assertNull(
            $this->validator->validateAccessToken(),
            'A new-claims token whose active_tenant_id has no active membership must be rejected.'
        );
    }

    public function testDualClaimAccessTokenWithSuspendedMembershipIsRejected(): void
    {
        $id = $this->memberships->insert($this->profileId, self::TENANT_A, 1);
        $this->memberships->suspend($id, self::TENANT_A);

        $_COOKIE['access_token'] = $this->mint($this->dualClaims());

        self::assertNull(
            $this->validator->validateAccessToken(),
            'A suspended membership must refuse the token (ADR 0005 §5).'
        );
    }

    public function testDualClaimAccessTokenWithInvitedMembershipIsRejected(): void
    {
        $this->memberships->invite($this->profileId, self::TENANT_A, 1);

        $_COOKIE['access_token'] = $this->mint($this->dualClaims());

        self::assertNull(
            $this->validator->validateAccessToken(),
            "An 'invited' (not yet accepted) membership must not grant access."
        );
    }

    public function testSystemTenantZeroActiveTenantIsUnscopedByConvention(): void
    {
        // No membership rows at all: active_tenant_id = 0 is the system tenant
        // and carries cross-tenant authority per the platform id-0 convention.
        $claims = $this->legacyClaims();
        $claims['tenant_id'] = 0;
        $claims += ['profile_id' => $this->profileId, 'active_tenant_id' => 0];

        // The legacy user row lives in tenant A; give the epoch check a row in
        // tenant 0 to match (system users live in tenant 0).
        $stmt = $this->pdo->prepare(
            "INSERT INTO users (tenant_id, email, password, role_id, created_at, token_epoch)
             VALUES (0, 'sys-dual@example.com', 'x', 1, datetime('now'), 0)"
        );
        $stmt->execute();
        $claims['user_id'] = (int) $this->pdo->lastInsertId();
        $claims['email'] = 'sys-dual@example.com';

        $_COOKIE['access_token'] = $this->mint($claims);

        self::assertIsArray(
            $this->validator->validateAccessToken(),
            'active_tenant_id = 0 (system tenant) must not require a membership row.'
        );
    }

    public function testDualClaimRefreshTokenWithoutMembershipIsRejected(): void
    {
        $_COOKIE['refresh_token'] = $this->mint($this->dualClaims(), 'refresh', 604800);

        self::assertNull($this->validator->validateRefreshToken());
    }

    public function testDualClaimRefreshTokenWithActiveMembershipIsAccepted(): void
    {
        $this->memberships->insert($this->profileId, self::TENANT_A, 1);

        $_COOKIE['refresh_token'] = $this->mint($this->dualClaims(), 'refresh', 604800);

        self::assertIsArray($this->validator->validateRefreshToken());
    }

    // ── partial claim sets fail closed ─────────────────────────────────────────

    public function testActiveTenantIdWithoutProfileIdIsRejected(): void
    {
        $claims = $this->legacyClaims();
        $claims['active_tenant_id'] = self::TENANT_A; // no profile_id — never issued

        $_COOKIE['access_token'] = $this->mint($claims);

        self::assertNull(
            $this->validator->validateAccessToken(),
            'active_tenant_id without profile_id is an un-issuable shape and must fail closed.'
        );
    }

    public function testProfileIdWithoutActiveTenantIdIsRejected(): void
    {
        $claims = $this->legacyClaims();
        $claims['profile_id'] = $this->profileId; // no active_tenant_id — never issued

        $_COOKIE['access_token'] = $this->mint($claims);

        self::assertNull(
            $this->validator->validateAccessToken(),
            'profile_id without active_tenant_id is an un-issuable shape and must fail closed.'
        );
    }

    public function testNonIntegerProfileIdIsRejected(): void
    {
        $claims = $this->legacyClaims();
        $claims['profile_id'] = 'not-an-int';
        $claims['active_tenant_id'] = self::TENANT_A;

        $_COOKIE['access_token'] = $this->mint($claims);

        self::assertNull($this->validator->validateAccessToken());
    }

    // ── epoch semantics (WC-185) across the dual window ───────────────────────

    public function testDualClaimTokenIsStillInvalidatedByUsersEpochBump(): void
    {
        // SECURITY PIN: during the dual window the password-change path bumps
        // users.token_epoch. A dual-claim token must keep being validated
        // against the users table, or a password change would no longer kill
        // migrated users' sessions.
        $this->memberships->insert($this->profileId, self::TENANT_A, 1);

        $stmt = $this->pdo->prepare('UPDATE users SET token_epoch = 1 WHERE id = ? AND tenant_id = ?');
        $stmt->execute([$this->userId, self::TENANT_A]);

        $_COOKIE['access_token'] = $this->mint($this->dualClaims()); // token_epoch = 0

        self::assertNull(
            $this->validator->validateAccessToken(),
            'A dual-claim token minted before a users.token_epoch bump must be rejected.'
        );
    }

    public function testNewClaimsOnlyTokenIsGatedByProfilesEpoch(): void
    {
        $this->memberships->insert($this->profileId, self::TENANT_A, 1);

        $stmt = $this->pdo->prepare('UPDATE profiles SET token_epoch = 1 WHERE id = ?');
        $stmt->execute([$this->profileId]);

        // Post-cutover shape: no legacy claims; token epoch 0 < profiles epoch 1.
        $_COOKIE['access_token'] = $this->mint([
            'profile_id' => $this->profileId,
            'active_tenant_id' => self::TENANT_A,
            'token_epoch' => 0,
        ]);

        self::assertNull(
            $this->validator->validateAccessToken(),
            'A new-claims-only token must be epoch-gated by profiles.token_epoch (ADR 0005 §5).'
        );

        // A current-epoch token passes.
        $_COOKIE['access_token'] = $this->mint([
            'profile_id' => $this->profileId,
            'active_tenant_id' => self::TENANT_A,
            'token_epoch' => 1,
        ]);

        self::assertIsArray($this->validator->validateAccessToken());
    }

    public function testNewClaimsOnlyTokenWithDeletedProfileFailsClosed(): void
    {
        $_COOKIE['access_token'] = $this->mint([
            'profile_id' => 999999, // no such profile
            'active_tenant_id' => 0, // system tenant: membership gate skipped
            'token_epoch' => 0,
        ]);

        self::assertNull(
            $this->validator->validateAccessToken(),
            'A new-claims-only token for a missing profile row must fail closed.'
        );
    }

    // ── MCP: both token shapes yield a principal ───────────────────────────────

    public function testMcpSessionBearerAcceptsLegacyShape(): void
    {
        $token = $this->mint($this->legacyClaims());

        $principal = $this->validator->validateBearerForMcp($token);

        self::assertInstanceOf(McpPrincipal::class, $principal);
        self::assertSame($this->userId, $principal->userId);
        self::assertSame(self::TENANT_A, $principal->tenantId);
        self::assertSame('session', $principal->principalKind);
    }

    public function testMcpSessionBearerAcceptsNewClaimsOnlyShape(): void
    {
        $this->memberships->insert($this->profileId, self::TENANT_A, 1);

        // Post-cutover shape: profile_id/active_tenant_id only, no legacy claims.
        $token = $this->mint([
            'profile_id' => $this->profileId,
            'active_tenant_id' => self::TENANT_A,
        ]);

        $principal = $this->validator->validateBearerForMcp($token);

        self::assertInstanceOf(McpPrincipal::class, $principal);
        self::assertSame($this->profileId, $principal->userId, 'Principal userId derives from profile_id.');
        self::assertSame(self::TENANT_A, $principal->tenantId, 'Principal tenantId derives from active_tenant_id.');
        self::assertSame('session', $principal->principalKind);
    }

    public function testMcpSessionBearerDualShapeGatedOnMembership(): void
    {
        // Dual-shape token but NO membership: must be rejected like the cookie path.
        $token = $this->mint($this->dualClaims());

        self::assertNull($this->validator->validateBearerForMcp($token));
    }

    public function testMcpSessionBearerDualShapeWithMembershipYieldsPrincipal(): void
    {
        $this->memberships->insert($this->profileId, self::TENANT_A, 1);

        $token = $this->mint($this->dualClaims());

        $principal = $this->validator->validateBearerForMcp($token);

        self::assertInstanceOf(McpPrincipal::class, $principal);
        self::assertSame($this->userId, $principal->userId);
        self::assertSame(self::TENANT_A, $principal->tenantId);
    }
}
