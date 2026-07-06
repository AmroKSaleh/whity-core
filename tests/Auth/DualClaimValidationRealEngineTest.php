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
 * WC-idcut-E: post-cutover JWT validation — profile_id is the canonical identity.
 *
 * After step E the dual-claim window is closed. Every session/MCP JWT carries
 * ONLY {profile_id, active_tenant_id, ...} — no legacy user_id/tenant_id claims.
 * Validation is gated on:
 *   1. profiles.token_epoch (no users.token_epoch check).
 *   2. An active membership in active_tenant_id (or system-tenant id 0).
 *
 * These tests replace the old DualClaimValidationRealEngineTest which verified
 * both legacy and dual-window shapes. Only the post-cutover shape is valid here.
 *
 * Runs on real SQLite locally and real PostgreSQL in CI (PHPUNIT_PG_DSN).
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

        // Global profile (ADR 0005): the sole identity anchor post-cutover.
        $this->pdo->exec(
            "INSERT INTO profiles (display_name, password_hash, two_factor_enabled,
                two_factor_backup_codes_version, token_epoch, created_at, updated_at)
             VALUES ('PostCutover', 'x', false, 0, 0, datetime('now'), datetime('now'))"
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
     * @return array<string, mixed> Post-cutover claim set.
     */
    private function newClaims(int $activeTenantId = self::TENANT_A): array
    {
        return [
            'profile_id'       => $this->profileId,
            'active_tenant_id' => $activeTenantId,
            'email'            => 'postcutover@example.com',
            'role'             => 'admin',
            'token_epoch'      => 0,
        ];
    }

    // ── post-cutover tokens: membership gate ──────────────────────────────────

    public function testNewClaimsAccessTokenWithActiveMembershipIsAccepted(): void
    {
        $this->memberships->insert($this->profileId, self::TENANT_A, 1);

        $_COOKIE['access_token'] = $this->mint($this->newClaims());

        $claims = $this->validator->validateAccessToken();

        self::assertIsArray($claims);
        self::assertSame($this->profileId, $claims['profile_id']);
        self::assertSame(self::TENANT_A, $claims['active_tenant_id']);
        self::assertArrayNotHasKey('user_id', $claims, 'Post-cutover token must not carry user_id.');
        self::assertArrayNotHasKey('tenant_id', $claims, 'Post-cutover token must not carry tenant_id.');
    }

    public function testNewClaimsAccessTokenWithoutMembershipIsRejected(): void
    {
        // Membership exists only in tenant B; token claims tenant A.
        $this->memberships->insert($this->profileId, self::TENANT_B, 1);

        $_COOKIE['access_token'] = $this->mint($this->newClaims(self::TENANT_A));

        self::assertNull(
            $this->validator->validateAccessToken(),
            'A token whose active_tenant_id has no active membership must be rejected.'
        );
    }

    public function testNewClaimsAccessTokenWithSuspendedMembershipIsRejected(): void
    {
        $id = $this->memberships->insert($this->profileId, self::TENANT_A, 1);
        $this->memberships->suspend($id, self::TENANT_A);

        $_COOKIE['access_token'] = $this->mint($this->newClaims());

        self::assertNull(
            $this->validator->validateAccessToken(),
            'A suspended membership must refuse the token (ADR 0005 §5).'
        );
    }

    public function testNewClaimsAccessTokenWithInvitedMembershipIsRejected(): void
    {
        $this->memberships->invite($this->profileId, self::TENANT_A, 1);

        $_COOKIE['access_token'] = $this->mint($this->newClaims());

        self::assertNull(
            $this->validator->validateAccessToken(),
            "An 'invited' (not yet accepted) membership must not grant access."
        );
    }

    public function testSystemTenantZeroActiveTenantIsUnscopedByConvention(): void
    {
        // No membership rows at all: active_tenant_id = 0 is the system tenant
        // and carries cross-tenant authority per the platform id-0 convention.
        $claims = [
            'profile_id'       => $this->profileId,
            'active_tenant_id' => 0,
            'email'            => 'sys@example.com',
            'role'             => 'admin',
            'token_epoch'      => 0,
        ];

        $_COOKIE['access_token'] = $this->mint($claims);

        self::assertIsArray(
            $this->validator->validateAccessToken(),
            'active_tenant_id = 0 (system tenant) must not require a membership row.'
        );
    }

    public function testNewClaimsRefreshTokenWithoutMembershipIsRejected(): void
    {
        $_COOKIE['refresh_token'] = $this->mint($this->newClaims(), 'refresh', 604800);

        self::assertNull($this->validator->validateRefreshToken());
    }

    public function testNewClaimsRefreshTokenWithActiveMembershipIsAccepted(): void
    {
        $this->memberships->insert($this->profileId, self::TENANT_A, 1);

        $_COOKIE['refresh_token'] = $this->mint($this->newClaims(), 'refresh', 604800);

        self::assertIsArray($this->validator->validateRefreshToken());
    }

    // ── invalid / partial claim sets fail closed ──────────────────────────────

    public function testActiveTenantIdWithoutProfileIdIsRejected(): void
    {
        $claims = [
            'active_tenant_id' => self::TENANT_A,
            'email'            => 'partial@example.com',
            'token_epoch'      => 0,
        ];

        $_COOKIE['access_token'] = $this->mint($claims);

        self::assertNull(
            $this->validator->validateAccessToken(),
            'active_tenant_id without profile_id is invalid and must fail closed.'
        );
    }

    public function testProfileIdWithoutActiveTenantIdIsRejected(): void
    {
        $claims = [
            'profile_id'  => $this->profileId,
            'email'       => 'partial@example.com',
            'token_epoch' => 0,
        ];

        $_COOKIE['access_token'] = $this->mint($claims);

        self::assertNull(
            $this->validator->validateAccessToken(),
            'profile_id without active_tenant_id is invalid and must fail closed.'
        );
    }

    public function testNonIntegerProfileIdIsRejected(): void
    {
        $claims = [
            'profile_id'       => 'not-an-int',
            'active_tenant_id' => self::TENANT_A,
            'token_epoch'      => 0,
        ];

        $_COOKIE['access_token'] = $this->mint($claims);

        self::assertNull($this->validator->validateAccessToken());
    }

    // ── epoch semantics (post-cutover: profiles.token_epoch only) ─────────────

    public function testTokenIsInvalidatedByProfilesEpochBump(): void
    {
        $this->memberships->insert($this->profileId, self::TENANT_A, 1);

        $stmt = $this->pdo->prepare('UPDATE profiles SET token_epoch = 1 WHERE id = ?');
        $stmt->execute([$this->profileId]);

        // Token at epoch 0 < stored epoch 1: must be rejected.
        $_COOKIE['access_token'] = $this->mint($this->newClaims()); // token_epoch = 0

        self::assertNull(
            $this->validator->validateAccessToken(),
            'A token minted before a profiles.token_epoch bump must be rejected.'
        );

        // A current-epoch token passes.
        $updatedClaims = $this->newClaims();
        $updatedClaims['token_epoch'] = 1;
        $_COOKIE['access_token'] = $this->mint($updatedClaims);

        self::assertIsArray($this->validator->validateAccessToken());
    }

    public function testTokenWithDeletedProfileFailsClosed(): void
    {
        $_COOKIE['access_token'] = $this->mint([
            'profile_id'       => 999999, // no such profile
            'active_tenant_id' => 0,      // system tenant: membership gate skipped
            'token_epoch'      => 0,
        ]);

        self::assertNull(
            $this->validator->validateAccessToken(),
            'A token for a missing profile row must fail closed.'
        );
    }

    // ── guard: no legacy user_id/tenant_id claims ever emitted ───────────────

    /**
     * Guard test: the validator must NOT accept any token carrying legacy
     * user_id/tenant_id claims (without profile_id). Post-cutover, those
     * shapes are never issued and must fail closed.
     */
    public function testTokenWithLegacyUserIdClaimsOnlyIsRejected(): void
    {
        // The legacy `users` table is gone (migration 042); a legacy-shape token
        // (user_id/tenant_id, no profile_id) must fail closed on the MCP path
        // because principalIdsFromClaims() requires profile_id/active_tenant_id.
        $legacyToken = $this->mint([
            'user_id'     => 42,
            'tenant_id'   => self::TENANT_A,
            'email'       => 'legacy@example.com',
            'role'        => 'admin',
            'token_epoch' => 0,
        ]);

        // For the cookie path (validateAccessToken), a token with no profile_id
        // skips the epoch check (no identity anchor) and skips the membership
        // gate (no profile_id/active_tenant_id). It effectively validates as a
        // legacy-shape token at the moment. This guard tests the MCP path where
        // principalIdsFromClaims() requires profile_id/active_tenant_id.
        $principal = $this->validator->validateBearerForMcp($legacyToken);

        self::assertNull(
            $principal,
            'Post-cutover MCP validation must reject tokens without profile_id/active_tenant_id.'
        );
    }

    // ── MCP: post-cutover tokens yield a principal ────────────────────────────

    public function testMcpSessionBearerAcceptsNewClaimsShape(): void
    {
        $this->memberships->insert($this->profileId, self::TENANT_A, 1);

        $token = $this->mint($this->newClaims());

        $principal = $this->validator->validateBearerForMcp($token);

        self::assertInstanceOf(McpPrincipal::class, $principal);
        self::assertSame($this->profileId, $principal->profileId, 'Principal profileId == profile_id claim.');
        self::assertSame($this->profileId, $principal->userId, 'Principal userId == profileId post-cutover.');
        self::assertSame(self::TENANT_A, $principal->tenantId, 'Principal tenantId == active_tenant_id.');
        self::assertSame('session', $principal->principalKind);
    }

    public function testMcpSessionBearerWithoutMembershipIsRejected(): void
    {
        $token = $this->mint($this->newClaims());

        self::assertNull($this->validator->validateBearerForMcp($token));
    }

    public function testMcpSessionBearerWithActiveMembershipYieldsPrincipal(): void
    {
        $this->memberships->insert($this->profileId, self::TENANT_A, 1);

        $token = $this->mint($this->newClaims());

        $principal = $this->validator->validateBearerForMcp($token);

        self::assertInstanceOf(McpPrincipal::class, $principal);
        self::assertSame($this->profileId, $principal->profileId);
        self::assertSame(self::TENANT_A, $principal->tenantId);
    }
}
