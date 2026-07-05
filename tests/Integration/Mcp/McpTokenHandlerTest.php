<?php

declare(strict_types=1);

namespace Tests\Integration\Mcp;

use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Auth\JwtParser;
use Whity\Auth\TokenValidator;
use Whity\Core\Request;
use Whity\Mcp\Auth\McpTokenHandler;
use Whity\Mcp\Auth\McpTokenService;

/**
 * Integration tests for the MCP token management HTTP endpoints.
 *
 * Covers: issuance (POST /api/mcp/tokens), listing (GET /api/mcp/tokens),
 * and revocation (DELETE /api/mcp/tokens/{jti}).
 *
 * Uses a real in-memory SQLite schema so SQL is exercised end-to-end.
 * Auth simulation: access tokens are minted directly with JwtParser and
 * injected via the access_token cookie.
 *
 * WC-idcut-E: post-cutover — session tokens carry only profile_id/active_tenant_id.
 * No user_id fallback. mcp_tokens is keyed on profiles.id.
 */
final class McpTokenHandlerTest extends TestCase
{
    private const JWT_SECRET  = 'mcp-handler-test-secret-padded-to-32-chars-min';
    private const PROFILE_A   = 10;
    private const PROFILE_B   = 11;
    private const TENANT_ID   = 1;

    private JwtParser $jwtParser;
    private \PDO $pdo;
    private McpTokenHandler $handler;

    protected function setUp(): void
    {
        $this->jwtParser = new JwtParser(self::JWT_SECRET);
        $this->pdo       = SchemaFromMigrations::make();
        $this->seedFixtures();

        $validator = new TokenValidator($this->jwtParser, $this->pdo);
        $service   = new McpTokenService($this->pdo, $this->jwtParser);
        $this->handler = new McpTokenHandler($validator, $service);

        unset($_COOKIE['access_token']);
    }

    protected function tearDown(): void
    {
        unset($_COOKIE['access_token']);
    }

    // ── POST /api/mcp/tokens ──────────────────────────────────────────────────

    public function testCreate_returns401_withoutAccessToken(): void
    {
        $request  = new Request('POST', '/api/mcp/tokens', ['Content-Type' => 'application/json'], '{"name":"test","scope":["tools:call"]}');
        $response = $this->handler->create($request);

        self::assertSame(401, $response->getStatusCode());
    }

    public function testCreate_returns422_forMissingName(): void
    {
        $_COOKIE['access_token'] = $this->mintAccessToken();
        $request  = new Request('POST', '/api/mcp/tokens', ['Content-Type' => 'application/json'], '{"scope":["tools:call"]}');
        $response = $this->handler->create($request);

        self::assertSame(422, $response->getStatusCode());
    }

    public function testCreate_returns422_forEmptyScope(): void
    {
        $_COOKIE['access_token'] = $this->mintAccessToken();
        $request  = new Request('POST', '/api/mcp/tokens', ['Content-Type' => 'application/json'], '{"name":"test","scope":[]}');
        $response = $this->handler->create($request);

        self::assertSame(422, $response->getStatusCode());
    }

    public function testCreate_returns201_withTokenAndMetadata(): void
    {
        $_COOKIE['access_token'] = $this->mintAccessToken();
        $request  = new Request('POST', '/api/mcp/tokens', ['Content-Type' => 'application/json'], '{"name":"n8n prod","scope":["tools:call","resources:read"]}');
        $response = $this->handler->create($request);

        self::assertSame(201, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        self::assertIsArray($body);
        self::assertArrayHasKey('jti', $body);
        self::assertArrayHasKey('token', $body);
        self::assertArrayHasKey('name', $body);
        self::assertArrayHasKey('expires_at', $body);
        self::assertSame('n8n prod', $body['name']);

        // Verify the returned token is a real MCP JWT with profile_id claim.
        $claims = $this->jwtParser->parse((string) $body['token']);
        self::assertNotNull($claims);
        self::assertSame('mcp', $claims['type']);
        self::assertSame(self::PROFILE_A, $claims['profile_id']);
        // WC-idcut-E: no legacy user_id claim in issued MCP tokens.
        self::assertArrayNotHasKey('user_id', $claims, 'Issued MCP token must not carry user_id');
        // WC-idcut-E: no legacy tenant_id claim — only active_tenant_id.
        self::assertArrayNotHasKey('tenant_id', $claims, 'Issued MCP token must not carry tenant_id');
        self::assertArrayHasKey('active_tenant_id', $claims, 'Issued MCP token must carry active_tenant_id');
    }

    // ── GET /api/mcp/tokens ───────────────────────────────────────────────────

    public function testList_returns401_withoutAccessToken(): void
    {
        $request  = new Request('GET', '/api/mcp/tokens', []);
        $response = $this->handler->list($request);

        self::assertSame(401, $response->getStatusCode());
    }

    public function testList_returnsActiveTokensForCurrentProfile(): void
    {
        $_COOKIE['access_token'] = $this->mintAccessToken();

        // Issue two tokens via the handler
        $this->handler->create(new Request('POST', '/api/mcp/tokens', ['Content-Type' => 'application/json'], '{"name":"alpha","scope":["tools:call"]}'));
        $this->handler->create(new Request('POST', '/api/mcp/tokens', ['Content-Type' => 'application/json'], '{"name":"beta","scope":["resources:read"]}'));

        $response = $this->handler->list(new Request('GET', '/api/mcp/tokens', []));

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        self::assertIsArray($body);
        self::assertArrayHasKey('tokens', $body);
        self::assertCount(2, $body['tokens']);
    }

    public function testList_doesNotReturnOtherProfilesTokens(): void
    {
        // Profile A issues a token
        $_COOKIE['access_token'] = $this->mintAccessToken(self::PROFILE_A);
        $this->handler->create(new Request('POST', '/api/mcp/tokens', ['Content-Type' => 'application/json'], '{"name":"profileA-token","scope":["tools:call"]}'));

        // Profile B logs in — should see empty list
        $_COOKIE['access_token'] = $this->mintAccessToken(self::PROFILE_B);
        $response = $this->handler->list(new Request('GET', '/api/mcp/tokens', []));

        $body = json_decode($response->getBody(), true);
        self::assertCount(0, $body['tokens'], 'Profile B must not see profile A tokens');
    }

    // ── DELETE /api/mcp/tokens/{jti} ──────────────────────────────────────────

    public function testRevoke_returns401_withoutAccessToken(): void
    {
        $request  = new Request('DELETE', '/api/mcp/tokens/somejti', []);
        $response = $this->handler->revoke($request, ['jti' => 'somejti']);

        self::assertSame(401, $response->getStatusCode());
    }

    public function testRevoke_returns404_forUnknownJti(): void
    {
        $_COOKIE['access_token'] = $this->mintAccessToken();
        $request  = new Request('DELETE', '/api/mcp/tokens/nonexistent', []);
        $response = $this->handler->revoke($request, ['jti' => 'nonexistent']);

        self::assertSame(404, $response->getStatusCode());
    }

    public function testRevoke_returns204_andTokenDisappearsFromList(): void
    {
        $_COOKIE['access_token'] = $this->mintAccessToken();

        // Create a token
        $createResponse = $this->handler->create(new Request('POST', '/api/mcp/tokens', ['Content-Type' => 'application/json'], '{"name":"to revoke","scope":["tools:call"]}'));
        $jti = (string) json_decode($createResponse->getBody(), true)['jti'];

        // Revoke it
        $revokeResponse = $this->handler->revoke(new Request('DELETE', "/api/mcp/tokens/{$jti}", []), ['jti' => $jti]);
        self::assertSame(204, $revokeResponse->getStatusCode());

        // List should now be empty
        $listResponse = $this->handler->list(new Request('GET', '/api/mcp/tokens', []));
        $body = json_decode($listResponse->getBody(), true);
        self::assertCount(0, $body['tokens']);
    }

    public function testRevoke_returns404_whenProfileBTriesToRevokeProfileAToken(): void
    {
        // Profile A creates a token
        $_COOKIE['access_token'] = $this->mintAccessToken(self::PROFILE_A);
        $createResponse = $this->handler->create(new Request('POST', '/api/mcp/tokens', ['Content-Type' => 'application/json'], '{"name":"profileA-token","scope":["tools:call"]}'));
        $jti = (string) json_decode($createResponse->getBody(), true)['jti'];

        // Profile B tries to revoke it
        $_COOKIE['access_token'] = $this->mintAccessToken(self::PROFILE_B);
        $revokeResponse = $this->handler->revoke(new Request('DELETE', "/api/mcp/tokens/{$jti}", []), ['jti' => $jti]);

        self::assertSame(404, $revokeResponse->getStatusCode(), 'Cross-profile revoke must return 404');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Mint a post-cutover access token (profile_id + active_tenant_id only).
     *
     * WC-idcut-E: no user_id/tenant_id fallback. The membership guard in
     * TokenValidator requires an active membership row for non-system tenants;
     * we seed memberships for both profile fixtures.
     */
    private function mintAccessToken(int $profileId = self::PROFILE_A): string
    {
        return $this->jwtParser->create([
            'profile_id'       => $profileId,
            'active_tenant_id' => self::TENANT_ID,
            'token_epoch'      => 0,
        ], 900, 'access');
    }

    private function seedFixtures(): void
    {
        $this->pdo->exec("INSERT INTO tenants (id, name) VALUES (1, 'Test') ON CONFLICT DO NOTHING");
        $this->pdo->exec("INSERT INTO roles (id, name) VALUES (1, 'admin') ON CONFLICT DO NOTHING");

        $hash = password_hash('pw', PASSWORD_BCRYPT);

        // Seed profiles for both test subjects.
        $this->pdo->prepare("
            INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled,
                two_factor_backup_codes_version, token_epoch, created_at, updated_at)
            VALUES (?, 'Profile A', ?, false, 0, 0, datetime('now'), datetime('now'))
            ON CONFLICT DO NOTHING
        ")->execute([self::PROFILE_A, $hash]);
        $this->pdo->prepare("
            INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled,
                two_factor_backup_codes_version, token_epoch, created_at, updated_at)
            VALUES (?, 'Profile B', ?, false, 0, 0, datetime('now'), datetime('now'))
            ON CONFLICT DO NOTHING
        ")->execute([self::PROFILE_B, $hash]);

        // Active memberships — required by the TokenValidator membership gate.
        $this->pdo->prepare("
            INSERT INTO memberships (profile_id, tenant_id, role_id, status, created_at)
            VALUES (?, 1, 1, 'active', datetime('now'))
            ON CONFLICT DO NOTHING
        ")->execute([self::PROFILE_A]);
        $this->pdo->prepare("
            INSERT INTO memberships (profile_id, tenant_id, role_id, status, created_at)
            VALUES (?, 1, 1, 'active', datetime('now'))
            ON CONFLICT DO NOTHING
        ")->execute([self::PROFILE_B]);
    }
}
