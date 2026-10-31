<?php

declare(strict_types=1);

namespace Tests\Integration;

use Firebase\JWT\JWT;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Auth\JwtParser;
use Whity\Auth\Oidc\JwksProvider;
use Whity\Auth\TokenValidator;
use Whity\Core\Http\HttpClient;
use Whity\Core\Identity\ExternalIdentityRepository;
use Whity\Core\Tenant\TenantContext;
use Whity\Mcp\Auth\OAuth\OAuthAccessTokenVerifier;
use Whity\Mcp\Auth\OAuth\OAuthTokenRefusedException;
use Whity\Mcp\JsonRpc\Dispatcher;
use Whity\Mcp\JsonRpc\ErrorCode;
use Whity\Mcp\Lifecycle\PingHandler;

/**
 * Access tokens from the OAuth authorization server at /mcp (WHIT-709 part B),
 * against the migration-built schema and a real RSA keypair, so the whole
 * sign -> discover -> JWKS -> verify -> link -> membership path runs for real.
 */
final class McpOAuthAccessTokenRealEngineTest extends TestCase
{
    private const ISSUER   = 'https://auth.whity.test';
    private const RESOURCE = 'https://app.whity.test/mcp';
    private const KID      = 'key-1';

    private PDO $pdo;
    private string $privatePem;
    /** @var array<string, mixed> */
    private array $jwks;
    /** @var list<string> */
    private array $fetched = [];

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);

        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($key);
        openssl_pkey_export($key, $pem);
        $this->privatePem = $pem;
        $details = openssl_pkey_get_details($key);
        self::assertNotFalse($details);
        $rsa = $details['rsa'];
        $this->jwks = ['keys' => [[
            'kty' => 'RSA', 'use' => 'sig', 'alg' => 'RS256', 'kid' => self::KID,
            'n' => self::b64url($rsa['n']), 'e' => self::b64url($rsa['e']),
        ]]];
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    public function testATokenForALinkedMemberBecomesAPrincipal(): void
    {
        $profileId = $this->linkedProfile('sub-1');
        $this->member($profileId, 7);

        $principal = $this->verifier()->verify($this->token(['sub' => 'sub-1', 'scope' => 'tools:call openid']));

        self::assertNotNull($principal);
        self::assertSame($profileId, $principal->profileId);
        self::assertSame(7, $principal->tenantId);
        self::assertSame('oauth', $principal->principalKind);
        self::assertSame(['tools:call'], $principal->scope, 'only MCP scopes are kept');
        self::assertSame(
            [self::ISSUER . '/.well-known/oauth-authorization-server', 'https://auth.whity.test/jwks'],
            $this->fetched,
            'JWKS found through RFC 8414 metadata',
        );
    }

    public function testTheTenantClaimPicksTheWorkspace(): void
    {
        $profileId = $this->linkedProfile('sub-1');
        $this->member($profileId, 7);
        $this->member($profileId, 8);

        $principal = $this->verifier()->verify($this->token(['sub' => 'sub-1', 'tenant_id' => 8]));

        self::assertSame(8, $principal?->tenantId);
    }

    public function testSeveralWorkspacesWithoutAClaimIsRefusedWithTheFix(): void
    {
        $profileId = $this->linkedProfile('sub-1');
        $this->member($profileId, 7);
        $this->member($profileId, 8);

        $this->expectException(OAuthTokenRefusedException::class);
        $this->expectExceptionMessage('"tenant_id" claim');
        $this->verifier()->verify($this->token(['sub' => 'sub-1']));
    }

    public function testAClaimedWorkspaceNeedsAnActiveMembership(): void
    {
        $profileId = $this->linkedProfile('sub-1');
        $this->member($profileId, 7);
        $this->member($profileId, 9, 'suspended');

        $this->expectException(OAuthTokenRefusedException::class);
        $this->verifier()->verify($this->token(['sub' => 'sub-1', 'tenant_id' => 9]));
    }

    public function testTheSystemTenantIsNeverReachable(): void
    {
        $profileId = $this->linkedProfile('sub-1');
        $this->member($profileId, 7);

        // Tenant 0 needs no membership row, so an outside server naming it would
        // otherwise hand cross-tenant authority to any linked account.
        $this->expectException(OAuthTokenRefusedException::class);
        $this->verifier()->verify($this->token(['sub' => 'sub-1', 'tenant_id' => 0]));
    }

    public function testAnUnlinkedSubjectIsRefusedWithTheFix(): void
    {
        $this->expectException(OAuthTokenRefusedException::class);
        $this->expectExceptionMessage('not linked to a Whity account');
        $this->verifier()->verify($this->token(['sub' => 'nobody']));
    }

    public function testATenantTrustLinkDoesNotCount(): void
    {
        // A tenant's own IdP could claim any issuer; its links must never
        // answer for the operator-configured authorization server.
        $profileId = $this->profile();
        (new ExternalIdentityRepository($this->pdo))->link($profileId, 'byo', self::ISSUER, 'sub-1', null, 42);
        $this->member($profileId, 7);

        $this->expectException(OAuthTokenRefusedException::class);
        $this->verifier()->verify($this->token(['sub' => 'sub-1']));
    }

    public function testATokenWithNoMcpScopeIsRefused(): void
    {
        $this->member($this->linkedProfile('sub-1'), 7);

        $this->expectException(OAuthTokenRefusedException::class);
        $this->verifier()->verify($this->token(['sub' => 'sub-1', 'scope' => 'openid email']));
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function invalidClaims(): array
    {
        return [
            'another resource'     => [['aud' => 'https://elsewhere.test/mcp']],
            'another issuer'       => [['iss' => 'https://evil.test']],
            'expired'              => [['exp' => time() - 3600]],
            'no expiry'            => [['exp' => null]],
        ];
    }

    /** @param array<string, mixed> $claims */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidClaims')]
    public function testAnInvalidTokenIsNullSoTheClientSignsInAgain(array $claims): void
    {
        $this->member($this->linkedProfile('sub-1'), 7);

        self::assertNull($this->verifier()->verify($this->token(['sub' => 'sub-1'] + $claims)));
    }

    public function testASignatureFromAnotherKeyIsRejected(): void
    {
        $this->member($this->linkedProfile('sub-1'), 7);
        $other = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($other);
        openssl_pkey_export($other, $otherPem);

        self::assertNull($this->verifier()->verify($this->token(['sub' => 'sub-1'], $otherPem)));
    }

    public function testASymmetricKeyInTheJwksIsIgnored(): void
    {
        $this->member($this->linkedProfile('sub-1'), 7);
        // If the server ever published a shared secret, anyone holding it could
        // mint tokens. It must not be a usable key.
        $secret = str_repeat('s', 32);
        $this->jwks = ['keys' => [['kty' => 'oct', 'kid' => 'hs', 'alg' => 'HS256', 'k' => self::b64url($secret)]]];

        $token = JWT::encode($this->claims(['sub' => 'sub-1']), $secret, 'HS256', 'hs');

        self::assertNull($this->verifier()->verify($token));
    }

    public function testAnAgentClientsTokenIsAnAgentActingForItsPerson(): void
    {
        $profileId = $this->linkedProfile('sub-1');
        $this->member($profileId, 7);

        $first  = $this->verifier()->verify($this->token(['sub' => 'sub-1', 'whity_agent' => true, 'client_id' => 'whity-builder', 'jti' => 'a', 'scope' => 'tools:call openid grant:users:manage=allow']));
        $second = $this->verifier()->verify($this->token(['sub' => 'sub-1', 'whity_agent' => true, 'client_id' => 'whity-builder', 'jti' => 'b', 'scope' => 'tools:call']));

        self::assertNotNull($first);
        self::assertSame('ai-agent', $first->principalKind);
        self::assertSame($profileId, $first->profileId);
        self::assertSame(7, $first->tenantId);
        // No grant entries ride on the token, even one it names: the default
        // grant applies, so it can never be given more than Ask first for writes.
        self::assertSame(['tools:call'], $first->scope);
        // Stable across its access tokens, so its proposals stay its own.
        self::assertSame($first->jti, $second?->jti);
        self::assertStringStartsWith(OAuthAccessTokenVerifier::AGENT_ID_PREFIX, $first->jti);
        self::assertLessThanOrEqual(64, strlen($first->jti));
    }

    public function testTwoAgentClientsAreTwoAgents(): void
    {
        $this->member($this->linkedProfile('sub-1'), 7);

        $builder = $this->verifier()->verify($this->token(['sub' => 'sub-1', 'whity_agent' => true, 'client_id' => 'whity-builder']));
        $other   = $this->verifier()->verify($this->token(['sub' => 'sub-1', 'whity_agent' => true, 'azp' => 'another-agent']));

        self::assertNotSame($builder?->jti, $other?->jti);
    }

    /** @return array<string, array{mixed}> */
    public static function notAnAgentClaim(): array
    {
        return [
            'absent'   => [null],
            'false'    => [false],
            'a string' => ['true'],
            'one'      => [1],
            'a list'   => [[true]],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('notAnAgentClaim')]
    public function testOnlyExactlyTrueMakesAnAgent(mixed $claim): void
    {
        $this->member($this->linkedProfile('sub-1'), 7);
        $claims = ['sub' => 'sub-1', 'client_id' => 'whity-builder'] + ($claim !== null ? ['whity_agent' => $claim] : []);

        self::assertSame('oauth', $this->verifier()->verify($this->token($claims))?->principalKind);
    }

    public function testAnAgentTokenMustNameItsClient(): void
    {
        $this->member($this->linkedProfile('sub-1'), 7);

        $this->expectException(OAuthTokenRefusedException::class);
        $this->expectExceptionMessage('client_id');
        $this->verifier()->verify($this->token(['sub' => 'sub-1', 'whity_agent' => true]));
    }

    /** @return array<string, array{array<string, mixed>, bool}> */
    public static function forgedAgentClaims(): array
    {
        return [
            'another issuer'   => [['iss' => 'https://evil.test'], false],
            'another resource' => [['aud' => 'https://elsewhere.test/mcp'], false],
            'another key'      => [[], true],
        ];
    }

    /**
     * A claim the trusted issuer did not sign is never believed: the token is
     * not valid at all, so it cannot become an agent or anything else.
     *
     * @param array<string, mixed> $claims
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('forgedAgentClaims')]
    public function testAForgedAgentClaimIsNoPrincipalAtAll(array $claims, bool $otherKey): void
    {
        $this->member($this->linkedProfile('sub-1'), 7);
        $pem = null;
        if ($otherKey) {
            $other = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            self::assertNotFalse($other);
            openssl_pkey_export($other, $pem);
        }

        $token = $this->token(['sub' => 'sub-1', 'whity_agent' => true, 'client_id' => 'whity-builder'] + $claims, $pem);

        self::assertNull($this->verifier()->verify($token));
    }

    public function testAStrayBearerCostsNoFetch(): void
    {
        self::assertNull($this->verifier()->verify('not-a-jwt'));
        self::assertNull($this->verifier()->verify(JWT::encode(['iss' => 'https://other.test'], str_repeat('x', 32), 'HS256')));
        self::assertSame([], $this->fetched);
    }

    public function testMadeUpKeyIdsCannotForceARefetchOnEveryRequest(): void
    {
        $fetches = 0;
        $jwks = new JwksProvider(function () use (&$fetches): array {
            $fetches++;
            return $this->jwks;
        }, minRefreshSeconds: 60);

        $jwks->getForKid('https://auth.whity.test/jwks', self::KID);
        for ($i = 0; $i < 5; $i++) {
            $jwks->getForKid('https://auth.whity.test/jwks', 'made-up-' . $i);
        }

        self::assertSame(1, $fetches);
    }

    public function testTheDispatcherAnswers403WithTheMessageForARefusal(): void
    {
        $validator = new TokenValidator(new JwtParser(str_repeat('i', 40)), $this->pdo);
        $validator->acceptOAuthAccessTokens($this->verifier());
        $dispatcher = new Dispatcher(['ping' => new PingHandler()], $validator);

        $out = json_decode($dispatcher->handle('{"jsonrpc":"2.0","method":"ping","id":1}', $this->token(['sub' => 'nobody'])), true);

        self::assertSame(403, $dispatcher->httpStatus());
        self::assertSame(ErrorCode::FORBIDDEN, $out['error']['code']);
        self::assertStringContainsString('not linked', $out['error']['message']);
    }

    public function testTheDispatcherAcceptsAGoodTokenAndStill401sABadOne(): void
    {
        $this->member($this->linkedProfile('sub-1'), 7);
        $validator = new TokenValidator(new JwtParser(str_repeat('i', 40)), $this->pdo);
        $validator->acceptOAuthAccessTokens($this->verifier());
        $dispatcher = new Dispatcher(['ping' => new PingHandler()], $validator);

        $out = json_decode($dispatcher->handle('{"jsonrpc":"2.0","method":"ping","id":1}', $this->token(['sub' => 'sub-1'])), true);
        self::assertSame(200, $dispatcher->httpStatus());
        self::assertArrayHasKey('result', $out);

        $dispatcher->handle('{"jsonrpc":"2.0","method":"ping","id":1}', $this->token(['sub' => 'sub-1', 'aud' => 'https://elsewhere.test']));
        self::assertSame(401, $dispatcher->httpStatus());
    }

    private function verifier(): OAuthAccessTokenVerifier
    {
        $record = function (string $url): void {
            $this->fetched[] = $url;
        };
        $http = new class ($record) implements HttpClient {
            public function __construct(private readonly \Closure $record) {}

            public function getJson(string $url): ?array
            {
                ($this->record)($url);
                return $url === 'https://auth.whity.test/.well-known/oauth-authorization-server'
                    ? ['issuer' => 'https://auth.whity.test', 'jwks_uri' => 'https://auth.whity.test/jwks']
                    : null;
            }

            public function postForm(string $url, array $params): ?array
            {
                return null;
            }
        };
        $jwks = new JwksProvider(function (string $uri): array {
            $this->fetched[] = $uri;
            return $this->jwks;
        });

        return new OAuthAccessTokenVerifier(self::ISSUER, self::RESOURCE, $http, $jwks, $this->pdo);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function claims(array $overrides): array
    {
        $claims = array_merge([
            'iss'   => self::ISSUER,
            'aud'   => self::RESOURCE,
            'iat'   => time(),
            'exp'   => time() + 600,
            'jti'   => bin2hex(random_bytes(8)),
            'scope' => 'tools:call resources:read prompts:read',
        ], $overrides);
        return array_filter($claims, static fn ($v): bool => $v !== null);
    }

    /** @param array<string, mixed> $overrides */
    private function token(array $overrides, ?string $pem = null): string
    {
        return JWT::encode($this->claims($overrides), $pem ?? $this->privatePem, 'RS256', self::KID);
    }

    private function profile(): int
    {
        $this->pdo->prepare(
            'INSERT INTO profiles
                (display_name, password_hash, two_factor_enabled, two_factor_secret,
                 two_factor_backup_codes_version, token_epoch, created_at, updated_at)
             VALUES (:dn, :ph, false, NULL, 0, 0, NOW(), NOW())'
        )->execute([':dn' => 'Alice', ':ph' => '']);
        return (int) $this->pdo->lastInsertId();
    }

    private function linkedProfile(string $subject): int
    {
        $profileId = $this->profile();
        (new ExternalIdentityRepository($this->pdo))->link($profileId, 'whity-auth', self::ISSUER, $subject);
        return $profileId;
    }

    private function member(int $profileId, int $tenantId, string $status = 'active'): void
    {
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM tenants WHERE id = ?');
        $count->execute([$tenantId]);
        $exists = (int) $count->fetchColumn();
        if ($exists === 0) {
            $this->pdo->prepare('INSERT INTO tenants (id, name, created_at) VALUES (:id, :name, NOW())')
                ->execute([':id' => $tenantId, ':name' => 'tenant-' . $tenantId]);
        }
        $role = $this->pdo->prepare("SELECT id FROM roles WHERE name = 'user' LIMIT 1");
        $role->execute();
        $roleId = (int) $role->fetchColumn();
        $this->pdo->prepare(
            'INSERT INTO memberships (profile_id, tenant_id, role_id, ou_id, status, created_at)
             VALUES (:pid, :tid, :rid, NULL, :status, NOW())'
        )->execute([':pid' => $profileId, ':tid' => $tenantId, ':rid' => $roleId, ':status' => $status]);
    }

    private static function b64url(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }
}
