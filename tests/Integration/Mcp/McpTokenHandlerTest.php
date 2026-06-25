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
 */
final class McpTokenHandlerTest extends TestCase
{
    private const JWT_SECRET = 'mcp-handler-test-secret-padded-to-32-chars-min';
    private const USER_ID    = 2;
    private const TENANT_ID  = 1;

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

        // Verify the returned token is a real MCP JWT
        $claims = $this->jwtParser->parse((string) $body['token']);
        self::assertNotNull($claims);
        self::assertSame('mcp', $claims['type']);
    }

    // ── GET /api/mcp/tokens ───────────────────────────────────────────────────

    public function testList_returns401_withoutAccessToken(): void
    {
        $request  = new Request('GET', '/api/mcp/tokens', []);
        $response = $this->handler->list($request);

        self::assertSame(401, $response->getStatusCode());
    }

    public function testList_returnsActiveTokensForCurrentUser(): void
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

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function mintAccessToken(): string
    {
        return $this->jwtParser->create([
            'user_id'     => self::USER_ID,
            'tenant_id'   => self::TENANT_ID,
            'email'       => 'user@test.com',
            'role'        => 'admin',
            'token_epoch' => 0,
        ], 900, 'access');
    }

    private function seedFixtures(): void
    {
        $this->pdo->exec("INSERT INTO tenants (id, name) VALUES (1, 'Test') ON CONFLICT DO NOTHING");
        $this->pdo->exec("INSERT INTO roles (id, tenant_id, name) VALUES (1, 1, 'admin') ON CONFLICT DO NOTHING");
        $hash = password_hash('pw', PASSWORD_BCRYPT);
        $this->pdo->prepare("INSERT INTO users (id, tenant_id, email, password, role_id, token_epoch) VALUES (2, 1, 'u@test.com', ?, 1, 0) ON CONFLICT DO NOTHING")->execute([$hash]);
    }
}
