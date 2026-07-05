<?php

declare(strict_types=1);

namespace Tests\Auth;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Auth\JwtParser;
use Whity\Auth\TokenValidator;
use Whity\Core\Identity\MembershipRepository;

/**
 * WC-idcut-E: post-cutover token shape — no split-brain possible.
 *
 * After step E the dual-claim window is closed. No token carries both
 * {tenant_id} and {active_tenant_id}, so the split-brain invariant
 * (hasSplitBrainClaims) is deleted from TokenValidator.
 *
 * This file now tests the post-cutover invariants:
 *  - A valid {profile_id, active_tenant_id} token with membership is accepted.
 *  - A token missing profile_id or active_tenant_id fails the MCP path.
 *  - The principalIdsFromClaims resolution uses only profile_id/active_tenant_id.
 *
 * Runs on real SQLite locally and real PostgreSQL in CI (PHPUNIT_PG_DSN).
 */
final class TokenSplitBrainInvariantRealEngineTest extends TestCase
{
    private const SECRET    = 'wc-c35c4ce0-split-brain-test-secret-padded-32b!';
    private const TENANT_A  = 1;
    private const TENANT_B  = 2;

    private PDO $pdo;
    private JwtParser $jwtParser;
    private TokenValidator $validator;
    private MembershipRepository $memberships;

    private int $profileId;

    protected function setUp(): void
    {
        $_COOKIE = [];

        $this->pdo = SchemaFromMigrations::make();
        $this->jwtParser = new JwtParser(self::SECRET);
        $this->validator = new TokenValidator($this->jwtParser, $this->pdo);
        $this->memberships = new MembershipRepository($this->pdo);

        $this->pdo->exec("INSERT OR IGNORE INTO tenants (id, name, created_at) VALUES (1, 'tenant-a', datetime('now'))");
        $this->pdo->exec("INSERT OR IGNORE INTO tenants (id, name, created_at) VALUES (2, 'tenant-b', datetime('now'))");
        $this->pdo->exec("INSERT OR IGNORE INTO roles (id, name) VALUES (1, 'admin')");

        // Global profile.
        $this->pdo->exec(
            "INSERT INTO profiles (display_name, password_hash, two_factor_enabled,
                 two_factor_backup_codes_version, token_epoch, created_at, updated_at)
             VALUES ('PostCutover', 'x', false, 0, 0, datetime('now'), datetime('now'))"
        );
        $this->profileId = (int) $this->pdo->lastInsertId();

        // Active membership in TENANT A only.
        $this->memberships->insert($this->profileId, self::TENANT_A, 1);
    }

    protected function tearDown(): void
    {
        $_COOKIE = [];
    }

    // ── post-cutover: {profile_id, active_tenant_id} only ────────────────────

    /**
     * A well-formed post-cutover token with an active membership is accepted.
     */
    public function testWellFormedPostCutoverTokenIsAccepted(): void
    {
        $_COOKIE['access_token'] = $this->mint([
            'profile_id'       => $this->profileId,
            'active_tenant_id' => self::TENANT_A,
            'email'            => 'postcut@example.com',
            'role'             => 'admin',
            'token_epoch'      => 0,
        ]);

        self::assertIsArray(
            $this->validator->validateAccessToken(),
            'A {profile_id, active_tenant_id} token with an active membership must be accepted.'
        );
    }

    /**
     * A token for a tenant where the profile has no active membership is rejected.
     */
    public function testTokenForUnmemberedTenantIsRejected(): void
    {
        // Membership is in TENANT_A; token claims TENANT_B.
        $_COOKIE['access_token'] = $this->mint([
            'profile_id'       => $this->profileId,
            'active_tenant_id' => self::TENANT_B,
            'email'            => 'postcut@example.com',
            'role'             => 'admin',
            'token_epoch'      => 0,
        ]);

        self::assertNull(
            $this->validator->validateAccessToken(),
            'A token claiming a tenant where the profile has no active membership must be rejected.'
        );
    }

    /**
     * A refresh token for a tenant where the profile has no active membership is rejected.
     */
    public function testRefreshTokenForUnmemberedTenantIsRejected(): void
    {
        $_COOKIE['refresh_token'] = $this->mint([
            'profile_id'       => $this->profileId,
            'active_tenant_id' => self::TENANT_B,
            'email'            => 'postcut@example.com',
            'role'             => 'admin',
            'token_epoch'      => 0,
        ], 'refresh', 604800);

        self::assertNull(
            $this->validator->validateRefreshToken(),
            'A refresh token claiming a tenant where the profile has no active membership must be rejected.'
        );
    }

    /**
     * The MCP principal resolution uses profile_id/active_tenant_id exclusively.
     * A token without profile_id returns null from validateBearerForMcp.
     */
    public function testMcpPrincipalUsesProfileIdNotUserId(): void
    {
        // Token with ONLY profile_id/active_tenant_id — the post-cutover shape.
        $token = $this->mint([
            'profile_id'       => $this->profileId,
            'active_tenant_id' => self::TENANT_A,
            'token_epoch'      => 0,
        ]);

        $principal = $this->validator->validateBearerForMcp($token);

        self::assertNotNull($principal, 'A valid post-cutover session bearer must yield a principal.');
        self::assertSame($this->profileId, $principal->profileId);
        self::assertSame($this->profileId, $principal->userId, 'userId == profileId post-cutover.');
        self::assertSame(self::TENANT_A, $principal->tenantId);
    }

    /**
     * A token with no profile_id/active_tenant_id fails the MCP principal path.
     */
    public function testMcpPrincipalFailsWithoutProfileId(): void
    {
        // No profile_id or active_tenant_id — principalIdsFromClaims returns null.
        $token = $this->mint([
            'email'       => 'anon@example.com',
            'token_epoch' => 0,
        ]);

        $principal = $this->validator->validateBearerForMcp($token);

        self::assertNull(
            $principal,
            'A token without profile_id/active_tenant_id must not yield an MCP principal.'
        );
    }

    /**
     * System tenant (active_tenant_id = 0) bypasses the membership gate —
     * the invariant that no split-brain is possible still holds because
     * there is only ONE tenant claim (active_tenant_id).
     */
    public function testSystemTenantTokenIsAcceptedWithoutMembership(): void
    {
        $_COOKIE['access_token'] = $this->mint([
            'profile_id'       => $this->profileId,
            'active_tenant_id' => 0, // system tenant — no membership required
            'token_epoch'      => 0,
        ]);

        self::assertIsArray(
            $this->validator->validateAccessToken(),
            'System tenant (active_tenant_id = 0) must be accepted without a membership row.'
        );
    }

    // ── helper ────────────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $claims
     */
    private function mint(array $claims, string $type = 'access', int $ttl = 900): string
    {
        return $this->jwtParser->create($claims, $ttl, $type);
    }
}
