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
 */
final class McpTokenServiceTest extends TestCase
{
    private const JWT_SECRET = 'test-jwt-secret-for-mcp-token-tests-min-32-chars';
    private const USER_ID   = 2;
    private const TENANT_ID = 1;

    private JwtParser $jwtParser;
    private \PDO $pdo;
    private McpTokenService $service;

    protected function setUp(): void
    {
        $this->jwtParser = new JwtParser(self::JWT_SECRET);
        $this->pdo       = SchemaFromMigrations::make();
        $this->seedUser();
        $this->service = new McpTokenService($this->pdo, $this->jwtParser);
    }

    // ── McpTokenService::issue() ──────────────────────────────────────────────

    public function testIssue_returnsValidJwtWithMcpType(): void
    {
        $token  = $this->service->issue(self::USER_ID, self::TENANT_ID, 'test token', ['tools:call']);
        $claims = $this->jwtParser->parse($token);

        self::assertNotNull($claims);
        self::assertSame('mcp', $claims['type']);
        self::assertSame('mcp', $claims['aud']);
        self::assertSame(self::USER_ID, $claims['user_id']);
        self::assertSame(self::TENANT_ID, $claims['tenant_id']);
        self::assertSame(['tools:call'], $claims['scope']);
        self::assertSame('user', $claims['principal_kind']);
    }

    public function testIssue_storesJtiInMcpTokens(): void
    {
        $token  = $this->service->issue(self::USER_ID, self::TENANT_ID, 'n8n prod', ['tools:call']);
        $claims = $this->jwtParser->parse($token);
        self::assertNotNull($claims);

        $stmt = $this->pdo->prepare('SELECT name, scope FROM mcp_tokens WHERE jti = ?');
        $stmt->execute([$claims['jti']]);
        $row = $stmt->fetch();

        self::assertIsArray($row);
        self::assertSame('n8n prod', $row['name']);
    }

    public function testIssue_tokenLifetimeIs90Days(): void
    {
        $token  = $this->service->issue(self::USER_ID, self::TENANT_ID, 'long-lived', ['tools:call']);
        $claims = $this->jwtParser->parse($token);
        self::assertNotNull($claims);

        $lifetime = (int) $claims['exp'] - (int) $claims['iat'];
        self::assertSame(McpTokenService::TOKEN_LIFETIME_SECONDS, $lifetime);
    }

    // ── McpTokenService::listForUser() ───────────────────────────────────────

    public function testListForUser_returnsActiveTokens(): void
    {
        $this->service->issue(self::USER_ID, self::TENANT_ID, 'token A', ['tools:call']);
        $this->service->issue(self::USER_ID, self::TENANT_ID, 'token B', ['resources:read']);

        $tokens = $this->service->listForUser(self::USER_ID, self::TENANT_ID);

        self::assertCount(2, $tokens);
        $names = array_column($tokens, 'name');
        self::assertContains('token A', $names);
        self::assertContains('token B', $names);
    }

    public function testListForUser_excludesRevokedTokens(): void
    {
        $token  = $this->service->issue(self::USER_ID, self::TENANT_ID, 'revokeme', ['tools:call']);
        $claims = $this->jwtParser->parse($token);
        self::assertNotNull($claims);

        $this->service->revoke((string) $claims['jti'], self::USER_ID, self::TENANT_ID);

        $tokens = $this->service->listForUser(self::USER_ID, self::TENANT_ID);
        self::assertCount(0, $tokens);
    }

    public function testListForUser_isolatesAcrossUsers(): void
    {
        $this->seedUserTwo();
        $this->service->issue(self::USER_ID, self::TENANT_ID, 'user1 token', ['tools:call']);
        $this->service->issue(3, self::TENANT_ID, 'user2 token', ['tools:call']);

        $tokens = $this->service->listForUser(self::USER_ID, self::TENANT_ID);
        self::assertCount(1, $tokens);
        self::assertSame('user1 token', $tokens[0]['name']);
    }

    // ── McpTokenService::revoke() ─────────────────────────────────────────────

    public function testRevoke_returnsTrue_andInsertsIntoRevokedTokens(): void
    {
        $token  = $this->service->issue(self::USER_ID, self::TENANT_ID, 'revoke test', ['tools:call']);
        $claims = $this->jwtParser->parse($token);
        self::assertNotNull($claims);
        $jti = (string) $claims['jti'];

        $result = $this->service->revoke($jti, self::USER_ID, self::TENANT_ID);

        self::assertTrue($result);
        $stmt = $this->pdo->prepare('SELECT 1 FROM revoked_tokens WHERE jti = ?');
        $stmt->execute([$jti]);
        self::assertNotFalse($stmt->fetchColumn(), 'jti must appear in revoked_tokens after revoke');
    }

    public function testRevoke_returnsFalse_forOtherUsersToken(): void
    {
        $this->seedUserTwo();
        $token  = $this->service->issue(self::USER_ID, self::TENANT_ID, 'owned by user1', ['tools:call']);
        $claims = $this->jwtParser->parse($token);
        self::assertNotNull($claims);

        // User 3 cannot revoke user 1's token
        $result = $this->service->revoke((string) $claims['jti'], 3, self::TENANT_ID);

        self::assertFalse($result);
    }

    public function testRevoke_returnsFalse_forUnknownJti(): void
    {
        $result = $this->service->revoke('nonexistent-jti', self::USER_ID, self::TENANT_ID);
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
            ['user_id' => self::USER_ID, 'tenant_id' => self::TENANT_ID],
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
            ['user_id' => self::USER_ID, 'tenant_id' => self::TENANT_ID, 'scope' => [], 'principal_kind' => 'user'],
            McpTokenService::TOKEN_LIFETIME_SECONDS,
            'mcp'
        );

        $validator = new TokenValidator($this->jwtParser, $this->pdo);
        self::assertNull($validator->validateMcpToken($token));
    }

    public function testValidateMcpToken_returnsNull_forRevokedToken(): void
    {
        $token  = $this->service->issue(self::USER_ID, self::TENANT_ID, 'to revoke', ['tools:call']);
        $claims = $this->jwtParser->parse($token);
        self::assertNotNull($claims);
        $this->service->revoke((string) $claims['jti'], self::USER_ID, self::TENANT_ID);

        $validator = new TokenValidator($this->jwtParser, $this->pdo);
        self::assertNull($validator->validateMcpToken($token));
    }

    public function testValidateMcpToken_returnsNull_ifNotInMcpTokensTable(): void
    {
        // Hand-craft a token that looks valid but was never stored in mcp_tokens
        $token = $this->jwtParser->create([
            'user_id'        => self::USER_ID,
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
        $token     = $this->service->issue(self::USER_ID, self::TENANT_ID, 'valid', ['tools:call', 'resources:read']);
        $validator = new TokenValidator($this->jwtParser, $this->pdo);

        $principal = $validator->validateMcpToken($token);

        self::assertInstanceOf(McpPrincipal::class, $principal);
        self::assertSame(self::USER_ID, $principal->userId);
        self::assertSame(self::TENANT_ID, $principal->tenantId);
        self::assertSame('user', $principal->principalKind);
        self::assertSame(['tools:call', 'resources:read'], $principal->scope);
        self::assertNotEmpty($principal->jti);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function seedUser(): void
    {
        $this->pdo->exec("
            INSERT INTO tenants (id, name) VALUES (1, 'Test Tenant')
            ON CONFLICT DO NOTHING
        ");
        $this->pdo->exec("
            INSERT INTO roles (id, tenant_id, name) VALUES (1, 1, 'admin')
            ON CONFLICT DO NOTHING
        ");
        $hash = password_hash('password123', PASSWORD_BCRYPT);
        $this->pdo->prepare("
            INSERT INTO users (id, tenant_id, email, password, role_id, token_epoch)
            VALUES (2, 1, 'user1@test.com', ?, 1, 0)
            ON CONFLICT DO NOTHING
        ")->execute([$hash]);
    }

    private function seedUserTwo(): void
    {
        $hash = password_hash('password123', PASSWORD_BCRYPT);
        $this->pdo->prepare("
            INSERT INTO users (id, tenant_id, email, password, role_id, token_epoch)
            VALUES (3, 1, 'user2@test.com', ?, 1, 0)
            ON CONFLICT DO NOTHING
        ")->execute([$hash]);
    }
}
