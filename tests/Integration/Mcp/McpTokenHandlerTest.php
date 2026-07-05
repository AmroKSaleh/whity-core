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
 * After migration 040, mcp_tokens is keyed on profiles.id. The handler
 * resolves profile_id from the access token claims (profile_id preferred;
 * user_id fallback for the dual-window period).
 */
final class McpTokenHandlerTest extends TestCase
{
    private const JWT_SECRET  = 'mcp-handler-test-secret-padded-to-32-chars-min';
    // During the dual-window, session access tokens carry user_id. The handler
    // falls back to user_id when profile_id is absent. We seed profiles with ids
    // that MATCH the user_ids so the FK to profiles(id) resolves correctly.
    private const USER_A_ID   = 10;
    private const USER_B_ID   = 11;
    private const PROFILE_ID  = 10;  // same as USER_A_ID for dual-window simplicity
    private const PROFILE_ID2 = 11;  // same as USER_B_ID
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

        // Verify the returned token is a real MCP JWT with profile_id claim
        $claims = $this->jwtParser->parse((string) $body['token']);
        self::assertNotNull($claims);
        self::assertSame('mcp', $claims['type']);
        self::assertSame(self::PROFILE_ID, $claims['profile_id']);
        self::assertArrayNotHasKey('user_id', $claims, 'Issued MCP token must not carry user_id');
    }

    public function testCreate_withNewClaimsInAccessToken_issuesTokenWithProfileId(): void
    {
        // Tokens that carry the new claim pair {profile_id, active_tenant_id}
        // should be accepted by the membership guard (with a live membership)
        // and the handler should use profile_id for the issued MCP token.
        // We seed a membership for profile A in the test tenant.
        $this->pdo->exec("
            INSERT INTO memberships (profile_id, tenant_id, role_id, status, created_at)
            VALUES (" . self::PROFILE_ID . ", 1, 1, 'active', datetime('now'))
            ON CONFLICT DO NOTHING
        ");

        $newClaimsToken = $this->jwtParser->create([
            'profile_id'       => self::PROFILE_ID,
            'active_tenant_id' => self::TENANT_ID,
            'tenant_id'        => self::TENANT_ID,
            'token_epoch'      => 0,
        ], 900, 'access');

        $_COOKIE['access_token'] = $newClaimsToken;
        $request  = new Request('POST', '/api/mcp/tokens', ['Content-Type' => 'application/json'], '{"name":"new-claims token","scope":["tools:call"]}');
        $response = $this->handler->create($request);

        self::assertSame(201, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $claims = $this->jwtParser->parse((string) $body['token']);
        self::assertIsArray($claims);
        self::assertSame(self::PROFILE_ID, $claims['profile_id']);
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
        // User A issues a token
        $_COOKIE['access_token'] = $this->mintAccessToken(self::USER_A_ID);
        $this->handler->create(new Request('POST', '/api/mcp/tokens', ['Content-Type' => 'application/json'], '{"name":"userA-token","scope":["tools:call"]}'));

        // User B logs in — should see empty list
        $_COOKIE['access_token'] = $this->mintAccessToken(self::USER_B_ID);
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
        // User A creates a token
        $_COOKIE['access_token'] = $this->mintAccessToken(self::USER_A_ID);
        $createResponse = $this->handler->create(new Request('POST', '/api/mcp/tokens', ['Content-Type' => 'application/json'], '{"name":"userA-token","scope":["tools:call"]}'));
        $jti = (string) json_decode($createResponse->getBody(), true)['jti'];

        // User B tries to revoke it
        $_COOKIE['access_token'] = $this->mintAccessToken(self::USER_B_ID);
        $revokeResponse = $this->handler->revoke(new Request('DELETE', "/api/mcp/tokens/{$jti}", []), ['jti' => $jti]);

        self::assertSame(404, $revokeResponse->getStatusCode(), 'Cross-profile revoke must return 404');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Mint a legacy-style access token (user_id + tenant_id, no new claims).
     *
     * During the dual-window, session access tokens carry user_id. The handler
     * falls back to user_id when profile_id is absent, using it as the profileId
     * for mcp_tokens INSERT/SELECT/DELETE. We seed profiles with the same numeric
     * IDs as the users so the FK to profiles(id) resolves correctly.
     */
    private function mintAccessToken(int $userId = self::USER_A_ID): string
    {
        return $this->jwtParser->create([
            'user_id'     => $userId,
            'tenant_id'   => self::TENANT_ID,
            'token_epoch' => 0,
        ], 900, 'access');
    }

    private function seedFixtures(): void
    {
        $this->pdo->exec("INSERT INTO tenants (id, name) VALUES (1, 'Test') ON CONFLICT DO NOTHING");
        $this->pdo->exec("INSERT INTO roles (id, tenant_id, name) VALUES (1, 1, 'admin') ON CONFLICT DO NOTHING");

        $hash = password_hash('pw', PASSWORD_BCRYPT);

        // Seed users (for epoch check in token validation).
        $this->pdo->prepare("
            INSERT INTO users (id, tenant_id, email, password, role_id, token_epoch)
            VALUES (?, 1, ?, ?, 1, 0) ON CONFLICT DO NOTHING
        ")->execute([self::USER_A_ID, 'userA@test.com', $hash]);
        $this->pdo->prepare("
            INSERT INTO users (id, tenant_id, email, password, role_id, token_epoch)
            VALUES (?, 1, ?, ?, 1, 0) ON CONFLICT DO NOTHING
        ")->execute([self::USER_B_ID, 'userB@test.com', $hash]);

        // Seed a legacy user for the fallback test (user_id = 999, no profile).
        $this->pdo->prepare("
            INSERT INTO users (id, tenant_id, email, password, role_id, token_epoch)
            VALUES (999, 1, 'legacy@test.com', ?, 1, 0) ON CONFLICT DO NOTHING
        ")->execute([$hash]);

        // Seed profiles with ids matching user ids so the dual-window fallback
        // (user_id used as profileId) resolves to a valid profiles.id FK.
        $this->pdo->prepare("
            INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled,
                two_factor_backup_codes_version, token_epoch, created_at, updated_at)
            VALUES (?, 'Profile A', ?, false, 0, 0, datetime('now'), datetime('now'))
            ON CONFLICT DO NOTHING
        ")->execute([self::PROFILE_ID, $hash]);
        $this->pdo->prepare("
            INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled,
                two_factor_backup_codes_version, token_epoch, created_at, updated_at)
            VALUES (?, 'Profile B', ?, false, 0, 0, datetime('now'), datetime('now'))
            ON CONFLICT DO NOTHING
        ")->execute([self::PROFILE_ID2, $hash]);
    }
}
