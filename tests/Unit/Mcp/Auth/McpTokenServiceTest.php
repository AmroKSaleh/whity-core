<?php

declare(strict_types=1);

namespace Tests\Unit\Mcp\Auth;

use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Auth\JwtParser;
use Whity\Auth\TokenValidator;
use Whity\Mcp\Auth\McpPrincipal;
use Whity\Mcp\Auth\McpTokenService;

/**
 * Unit tests for McpTokenService and TokenValidator::validateMcpToken().
 *
 * Uses the real SQLite schema (via SchemaFromMigrations) so SQL queries are
 * exercised against a real engine, not mocked PDO statements.
 *
 * After migration 040, mcp_tokens is keyed on profiles.id (profile_id) rather
 * than users.id. Fixtures seed a profile row; tokens are issued / listed /
 * revoked by profile_id.
 */
final class McpTokenServiceTest extends TestCase
{
    private const JWT_SECRET  = 'test-jwt-secret-for-mcp-token-tests-min-32-chars';
    private const PROFILE_ID  = 42;
    private const PROFILE_ID2 = 43;
    private const TENANT_ID   = 1;

    private JwtParser $jwtParser;
    private \PDO $pdo;
    private McpTokenService $service;

    protected function setUp(): void
    {
        $this->jwtParser = new JwtParser(self::JWT_SECRET);
        $this->pdo       = SchemaFromMigrations::make();
        $this->seedFixtures();
        $this->service = new McpTokenService($this->pdo, $this->jwtParser);
    }

    // ── McpTokenService::issue() ──────────────────────────────────────────────

    public function testIssue_returnsValidJwtWithMcpType(): void
    {
        $token  = $this->service->issue(self::PROFILE_ID, self::TENANT_ID, 'test token', ['tools:call']);
        $claims = $this->jwtParser->parse($token);

        self::assertNotNull($claims);
        self::assertSame('mcp', $claims['type']);
        self::assertSame('mcp', $claims['aud']);
        // Post-cutover: tokens carry profile_id + active_tenant_id (no user_id/tenant_id).
        self::assertSame(self::PROFILE_ID, $claims['profile_id']);
        self::assertArrayNotHasKey('user_id', $claims, 'New MCP tokens must not carry a user_id claim');
        self::assertSame(self::TENANT_ID, $claims['active_tenant_id']);
        self::assertArrayNotHasKey('tenant_id', $claims, 'New MCP tokens must not carry a legacy tenant_id claim');
        self::assertSame(['tools:call'], $claims['scope']);
        self::assertSame('user', $claims['principal_kind']);
    }

    public function testIssue_storesJtiInMcpTokensByProfileId(): void
    {
        $token  = $this->service->issue(self::PROFILE_ID, self::TENANT_ID, 'n8n prod', ['tools:call']);
        $claims = $this->jwtParser->parse($token);
        self::assertNotNull($claims);

        $stmt = $this->pdo->prepare('SELECT name, scope, profile_id FROM mcp_tokens WHERE jti = ?');
        $stmt->execute([$claims['jti']]);
        $row = $stmt->fetch();

        self::assertIsArray($row);
        self::assertSame('n8n prod', $row['name']);
        self::assertSame((string) self::PROFILE_ID, (string) $row['profile_id']);
    }

    public function testIssue_tokenLifetimeIs90Days(): void
    {
        $token  = $this->service->issue(self::PROFILE_ID, self::TENANT_ID, 'long-lived', ['tools:call']);
        $claims = $this->jwtParser->parse($token);
        self::assertNotNull($claims);

        $lifetime = (int) $claims['exp'] - (int) $claims['iat'];
        self::assertSame(McpTokenService::TOKEN_LIFETIME_SECONDS, $lifetime);
    }

    // ── McpTokenService::listForUser() ───────────────────────────────────────

    public function testListForUser_returnsActiveTokens(): void
    {
        $this->service->issue(self::PROFILE_ID, self::TENANT_ID, 'token A', ['tools:call']);
        $this->service->issue(self::PROFILE_ID, self::TENANT_ID, 'token B', ['resources:read']);

        $tokens = $this->service->listForUser(self::PROFILE_ID, self::TENANT_ID);

        self::assertCount(2, $tokens);
        $names = array_column($tokens, 'name');
        self::assertContains('token A', $names);
        self::assertContains('token B', $names);
    }

    public function testListForUser_excludesRevokedTokens(): void
    {
        $token  = $this->service->issue(self::PROFILE_ID, self::TENANT_ID, 'revokeme', ['tools:call']);
        $claims = $this->jwtParser->parse($token);
        self::assertNotNull($claims);

        $this->service->revoke((string) $claims['jti'], self::PROFILE_ID, self::TENANT_ID);

        $tokens = $this->service->listForUser(self::PROFILE_ID, self::TENANT_ID);
        self::assertCount(0, $tokens);
    }

    public function testListForUser_isolatesAcrossProfiles(): void
    {
        $this->service->issue(self::PROFILE_ID, self::TENANT_ID, 'profile1 token', ['tools:call']);
        $this->service->issue(self::PROFILE_ID2, self::TENANT_ID, 'profile2 token', ['tools:call']);

        $tokens = $this->service->listForUser(self::PROFILE_ID, self::TENANT_ID);
        self::assertCount(1, $tokens);
        self::assertSame('profile1 token', $tokens[0]['name']);
    }

    // ── McpTokenService::revoke() ─────────────────────────────────────────────

    public function testRevoke_returnsTrue_andInsertsIntoRevokedTokens(): void
    {
        $token  = $this->service->issue(self::PROFILE_ID, self::TENANT_ID, 'revoke test', ['tools:call']);
        $claims = $this->jwtParser->parse($token);
        self::assertNotNull($claims);
        $jti = (string) $claims['jti'];

        $result = $this->service->revoke($jti, self::PROFILE_ID, self::TENANT_ID);

        self::assertTrue($result);
        $stmt = $this->pdo->prepare('SELECT 1 FROM revoked_tokens WHERE jti = ?');
        $stmt->execute([$jti]);
        self::assertNotFalse($stmt->fetchColumn(), 'jti must appear in revoked_tokens after revoke');
    }

    public function testRevoke_returnsFalse_forOtherProfilesToken(): void
    {
        $token  = $this->service->issue(self::PROFILE_ID, self::TENANT_ID, 'owned by profile1', ['tools:call']);
        $claims = $this->jwtParser->parse($token);
        self::assertNotNull($claims);

        // Profile 2 cannot revoke profile 1's token
        $result = $this->service->revoke((string) $claims['jti'], self::PROFILE_ID2, self::TENANT_ID);

        self::assertFalse($result);
    }

    public function testRevoke_returnsFalse_forUnknownJti(): void
    {
        $result = $this->service->revoke('nonexistent-jti', self::PROFILE_ID, self::TENANT_ID);
        self::assertFalse($result);
    }

    // ── TokenValidator::validateMcpToken() ───────────────────────────────────

    public function testValidateMcpToken_returnsNull_forEmptyString(): void
    {
        $validator = new TokenValidator($this->jwtParser, $this->pdo);
        self::assertNull($validator->validateMcpToken(''));
    }

    public function testValidateMcpToken_returnsNull_forAccessToken(): void
    {
        $accessToken = $this->jwtParser->create(
            ['profile_id' => self::PROFILE_ID, 'tenant_id' => self::TENANT_ID],
            900,
            'access'
        );

        $validator = new TokenValidator($this->jwtParser, $this->pdo);
        self::assertNull($validator->validateMcpToken($accessToken));
    }

    public function testValidateMcpToken_returnsNull_forMissingAud(): void
    {
        // mcp type but no aud claim
        $token = $this->jwtParser->create(
            ['profile_id' => self::PROFILE_ID, 'tenant_id' => self::TENANT_ID, 'scope' => [], 'principal_kind' => 'user'],
            McpTokenService::TOKEN_LIFETIME_SECONDS,
            'mcp'
        );

        $validator = new TokenValidator($this->jwtParser, $this->pdo);
        self::assertNull($validator->validateMcpToken($token));
    }

    public function testValidateMcpToken_returnsNull_forRevokedToken(): void
    {
        $token  = $this->service->issue(self::PROFILE_ID, self::TENANT_ID, 'to revoke', ['tools:call']);
        $claims = $this->jwtParser->parse($token);
        self::assertNotNull($claims);
        $this->service->revoke((string) $claims['jti'], self::PROFILE_ID, self::TENANT_ID);

        $validator = new TokenValidator($this->jwtParser, $this->pdo);
        self::assertNull($validator->validateMcpToken($token));
    }

    public function testValidateMcpToken_returnsNull_ifNotInMcpTokensTable(): void
    {
        // Hand-craft a token that looks valid but was never stored in mcp_tokens
        $token = $this->jwtParser->create([
            'profile_id'     => self::PROFILE_ID,
            'tenant_id'      => self::TENANT_ID,
            'aud'            => 'mcp',
            'principal_kind' => 'user',
            'scope'          => ['tools:call'],
        ], McpTokenService::TOKEN_LIFETIME_SECONDS, 'mcp');

        $validator = new TokenValidator($this->jwtParser, $this->pdo);
        self::assertNull($validator->validateMcpToken($token));
    }

    public function testValidateMcpToken_returnsMcpPrincipal_forValidToken(): void
    {
        $token     = $this->service->issue(self::PROFILE_ID, self::TENANT_ID, 'valid', ['tools:call', 'resources:read']);
        $validator = new TokenValidator($this->jwtParser, $this->pdo);

        $principal = $validator->validateMcpToken($token);

        self::assertInstanceOf(McpPrincipal::class, $principal);
        self::assertSame(self::PROFILE_ID, $principal->profileId);
        self::assertSame(self::TENANT_ID, $principal->tenantId);
        self::assertSame('user', $principal->principalKind);
        self::assertSame(['tools:call', 'resources:read'], $principal->scope);
        self::assertNotEmpty($principal->jti);
    }

    public function testValidateMcpToken_profileIdAndUserIdBothSet_forNewToken(): void
    {
        // New tokens carry profile_id; userId on principal must match profileId.
        $token     = $this->service->issue(self::PROFILE_ID, self::TENANT_ID, 'p+u match', ['tools:call']);
        $validator = new TokenValidator($this->jwtParser, $this->pdo);

        $principal = $validator->validateMcpToken($token);

        self::assertInstanceOf(McpPrincipal::class, $principal);
        self::assertSame(self::PROFILE_ID, $principal->profileId);
        self::assertSame(self::PROFILE_ID, $principal->userId, 'userId must equal profileId for new-style MCP tokens');
    }

    // ── Cross-profile ownership (security) ───────────────────────────────────

    public function testTokenIssuedForProfileA_cannotBeListedByProfileB(): void
    {
        $this->service->issue(self::PROFILE_ID, self::TENANT_ID, 'profile-a-token', ['tools:call']);

        $profileBTokens = $this->service->listForUser(self::PROFILE_ID2, self::TENANT_ID);
        self::assertCount(0, $profileBTokens, 'Profile B must not see profile A tokens');
    }

    public function testTokenIssuedForProfileA_cannotBeRevokedByProfileB(): void
    {
        $token  = $this->service->issue(self::PROFILE_ID, self::TENANT_ID, 'owned', ['tools:call']);
        $claims = $this->jwtParser->parse($token);
        self::assertNotNull($claims);

        $result = $this->service->revoke((string) $claims['jti'], self::PROFILE_ID2, self::TENANT_ID);
        self::assertFalse($result, 'Profile B must not be able to revoke profile A token');

        // Token must still be active
        $activeTokens = $this->service->listForUser(self::PROFILE_ID, self::TENANT_ID);
        self::assertCount(1, $activeTokens, 'Token must still be active after cross-profile revoke attempt');
    }

    public function testCascadeDeleteOnProfileDeleteRemovesTokens(): void
    {
        $this->service->issue(self::PROFILE_ID, self::TENANT_ID, 'will be deleted', ['tools:call']);

        // Verify token exists
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM mcp_tokens WHERE profile_id = ?');
        $stmt->execute([self::PROFILE_ID]);
        self::assertSame('1', (string) $stmt->fetchColumn());

        // Delete the profile — should cascade to mcp_tokens
        $this->pdo->prepare('DELETE FROM profiles WHERE id = ?')->execute([self::PROFILE_ID]);

        $stmt->execute([self::PROFILE_ID]);
        self::assertSame('0', (string) $stmt->fetchColumn(), 'Deleting a profile must cascade-delete its mcp_tokens');
    }

    // ── Session bearer path (dual-window) ────────────────────────────────────

    public function testSessionBearerStillAuthenticatesMcp_dualWindowIntact(): void
    {
        // Post-cutover (WC-idcut-E): the dual-claim window is gone. Session access
        // tokens must carry {profile_id, active_tenant_id}; legacy {user_id, tenant_id}
        // tokens are rejected. This test verifies that a correctly-shaped session
        // token is accepted by validateSessionBearerForMcp().
        $validator = new TokenValidator($this->jwtParser, $this->pdo);

        // Use the seeded profile (PROFILE_ID) + its active membership in TENANT_ID.
        $sessionToken = $this->jwtParser->create([
            'profile_id'       => self::PROFILE_ID,
            'active_tenant_id' => self::TENANT_ID,
            'token_epoch'      => 0,
        ], 900, 'access');

        $principal = $validator->validateSessionBearerForMcp($sessionToken);

        self::assertInstanceOf(McpPrincipal::class, $principal);
        self::assertSame('session', $principal->principalKind);
        self::assertSame(self::PROFILE_ID, $principal->userId, 'session bearer: userId must equal profileId post-cutover');
        self::assertSame(self::PROFILE_ID, $principal->profileId);
        self::assertSame(self::TENANT_ID, $principal->tenantId);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function seedFixtures(): void
    {
        // Enable FK enforcement on SQLite (needed for cascade delete test).
        $this->pdo->exec('PRAGMA foreign_keys = ON');

        $this->pdo->exec("
            INSERT INTO tenants (id, name) VALUES (1, 'Test Tenant')
            ON CONFLICT DO NOTHING
        ");
        $this->pdo->exec("
            INSERT INTO roles (id, tenant_id, name) VALUES (1, 1, 'admin')
            ON CONFLICT DO NOTHING
        ");
        // Seed two profiles (mcp_tokens references profiles.id after migration 040).
        $hash = password_hash('password123', PASSWORD_BCRYPT);
        $this->pdo->prepare("
            INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled,
                two_factor_backup_codes_version, token_epoch, created_at, updated_at)
            VALUES (?, 'Test Profile', ?, false, 0, 0, datetime('now'), datetime('now'))
            ON CONFLICT DO NOTHING
        ")->execute([self::PROFILE_ID, $hash]);
        $this->pdo->prepare("
            INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled,
                two_factor_backup_codes_version, token_epoch, created_at, updated_at)
            VALUES (?, 'Test Profile 2', ?, false, 0, 0, datetime('now'), datetime('now'))
            ON CONFLICT DO NOTHING
        ")->execute([self::PROFILE_ID2, $hash]);

        // Seed active memberships so the TokenValidator's ActiveTenantMembershipGuard
        // accepts tokens carrying {profile_id, active_tenant_id} for TENANT_ID.
        $this->pdo->prepare("
            INSERT INTO memberships (profile_id, tenant_id, role_id, status, created_at)
            VALUES (?, ?, 1, 'active', datetime('now'))
            ON CONFLICT DO NOTHING
        ")->execute([self::PROFILE_ID, self::TENANT_ID]);
        $this->pdo->prepare("
            INSERT INTO memberships (profile_id, tenant_id, role_id, status, created_at)
            VALUES (?, ?, 1, 'active', datetime('now'))
            ON CONFLICT DO NOTHING
        ")->execute([self::PROFILE_ID2, self::TENANT_ID]);
    }
}
