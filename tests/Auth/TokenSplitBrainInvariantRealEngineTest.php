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
 * WC-c35c4ce0 security follow-up (b):
 *
 * When BOTH legacy {user_id, tenant_id} and new {profile_id, active_tenant_id}
 * claims are present in the SAME token, McpPrincipal reads tenant_id while
 * TenantContext reads active_tenant_id. If they differ the caller can "split
 * brain" — declaring tenant A to the membership gate (active_tenant_id) while
 * operating as tenant B in all tenant-scoped queries (tenant_id).
 *
 * The fix: TokenValidator must reject any token where
 *   tenant_id !== active_tenant_id
 * when BOTH claims are present (integer-valued).
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
    private int $userId;
    private int $userIdB;

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
             VALUES ('SplitBrain', 'x', false, 0, 0, datetime('now'), datetime('now'))"
        );
        $this->profileId = (int) $this->pdo->lastInsertId();

        // Legacy users row in tenant A.
        $stmt = $this->pdo->prepare(
            "INSERT INTO users (tenant_id, email, password, role_id, created_at, token_epoch)
             VALUES (?, 'split@example.com', 'x', 1, datetime('now'), 0)"
        );
        $stmt->execute([self::TENANT_A]);
        $this->userId = (int) $this->pdo->lastInsertId();

        // ALSO seed a users row in tenant B with the same email (UNIQUE(tenant_id, email)
        // allows this), so that the epoch check cannot accidentally catch the split-brain
        // by failing to find a users row for (userId, TENANT_B).  The test must catch the
        // split-brain via the explicit invariant, not via epoch-check's fail-closed path.
        $stmtB = $this->pdo->prepare(
            "INSERT INTO users (tenant_id, email, password, role_id, created_at, token_epoch)
             VALUES (?, 'split@example.com', 'x', 1, datetime('now'), 0)"
        );
        $stmtB->execute([self::TENANT_B]);
        $this->userIdB = (int) $this->pdo->lastInsertId();

        // Active membership in TENANT A only (not B — the split-brain target).
        $this->memberships->insert($this->profileId, self::TENANT_A, 1);
    }

    protected function tearDown(): void
    {
        $_COOKIE = [];
    }

    // ── invariant: tenant_id must equal active_tenant_id when both present ─────────

    /**
     * A well-formed dual-claim token (tenant_id == active_tenant_id) must be accepted.
     */
    public function testWellFormedDualClaimTokenIsAccepted(): void
    {
        $_COOKIE['access_token'] = $this->mint([
            'user_id'          => $this->userId,
            'tenant_id'        => self::TENANT_A,
            'email'            => 'split@example.com',
            'role'             => 'admin',
            'token_epoch'      => 0,
            'profile_id'       => $this->profileId,
            'active_tenant_id' => self::TENANT_A, // same as tenant_id ✓
        ]);

        self::assertIsArray(
            $this->validator->validateAccessToken(),
            'A dual-claim token with tenant_id === active_tenant_id must be accepted.'
        );
    }

    /**
     * A split-brain token (tenant_id ≠ active_tenant_id) must be rejected.
     *
     * The profile has a membership in tenant A only. The token declares
     * active_tenant_id = A (passes the membership gate) but tenant_id = B
     * (which McpPrincipal / TenantContext would read for tenant-scoped queries).
     *
     * To ensure the invariant itself is what catches this (not the epoch check
     * failing-closed on a missing row), we seed a users row in tenant B with
     * epoch = 0 so the epoch check sees a valid row and would pass.
     */
    public function testSplitBrainTokenTenantIdDiffersFromActiveTenantIdIsRejected(): void
    {
        // userIdB has a users row in TENANT_B with epoch=0, so the epoch check
        // for (userIdB, TENANT_B) would PASS — only the explicit invariant stops this.
        $_COOKIE['access_token'] = $this->mint([
            'user_id'          => $this->userIdB,  // user row exists in B (epoch 0)
            'tenant_id'        => self::TENANT_B,  // ← mismatch with active_tenant_id
            'email'            => 'split@example.com',
            'role'             => 'admin',
            'token_epoch'      => 0,
            'profile_id'       => $this->profileId,
            'active_tenant_id' => self::TENANT_A,  // ← mismatch with tenant_id
        ]);

        self::assertNull(
            $this->validator->validateAccessToken(),
            'A dual-claim token where tenant_id !== active_tenant_id must be rejected (split-brain).'
        );
    }

    /**
     * A refresh token with the same split-brain mismatch must also be rejected.
     */
    public function testSplitBrainRefreshTokenIsRejected(): void
    {
        $_COOKIE['refresh_token'] = $this->mint([
            'user_id'          => $this->userIdB,  // user row exists in B (epoch 0)
            'tenant_id'        => self::TENANT_B,  // ← mismatch with active_tenant_id
            'email'            => 'split@example.com',
            'role'             => 'admin',
            'token_epoch'      => 0,
            'profile_id'       => $this->profileId,
            'active_tenant_id' => self::TENANT_A,  // ← mismatch with tenant_id
        ], 'refresh', 604800);

        self::assertNull(
            $this->validator->validateRefreshToken(),
            'A dual-claim refresh token where tenant_id !== active_tenant_id must be rejected.'
        );
    }

    /**
     * A legacy token (no active_tenant_id) is unaffected by the invariant.
     */
    public function testLegacyTokenWithoutActiveTenantIdIsUnaffected(): void
    {
        $_COOKIE['access_token'] = $this->mint([
            'user_id'     => $this->userId,
            'tenant_id'   => self::TENANT_A,
            'email'       => 'split@example.com',
            'role'        => 'admin',
            'token_epoch' => 0,
            // no profile_id / active_tenant_id — legacy shape
        ]);

        self::assertIsArray(
            $this->validator->validateAccessToken(),
            'Legacy tokens (no active_tenant_id claim) must not be affected by the invariant.'
        );
    }

    /**
     * A new-claims-only token (profile_id + active_tenant_id, no tenant_id) is
     * unaffected — the invariant only applies when BOTH legacy and new claims
     * are present.
     */
    public function testNewClaimsOnlyTokenIsUnaffectedByInvariant(): void
    {
        $_COOKIE['access_token'] = $this->mint([
            'profile_id'       => $this->profileId,
            'active_tenant_id' => self::TENANT_A,
            'token_epoch'      => 0,
            // no user_id / tenant_id — post-cutover shape
        ]);

        self::assertIsArray(
            $this->validator->validateAccessToken(),
            'New-claims-only tokens (no legacy claims) must not be affected by the invariant.'
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
