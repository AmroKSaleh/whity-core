<?php

declare(strict_types=1);

namespace Tests\Integration;

use Firebase\JWT\JWT;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Auth\Oidc\JwksProvider;
use Whity\Core\Http\HttpClient;
use Whity\Core\Identity\ExternalIdentityRepository;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Mcp\Auth\OAuth\IssuerJwtVerifier;
use Whity\Mcp\Auth\OAuth\OAuthWorkspacesHandler;
use Whity\Mcp\Auth\OAuth\ProtectedResource;

/**
 * GET /api/v1/oauth/workspaces (WHIT-802): the authorization server asks which
 * workspaces a person belongs to, with an assertion signed by its published
 * key. Against the migration-built schema and a real RSA keypair, so the whole
 * sign -> discover -> JWKS -> verify -> link -> memberships path runs for real.
 */
final class OAuthWorkspacesRealEngineTest extends TestCase
{
    private const ISSUER   = 'https://auth.whity.test';
    private const APP_URL  = 'https://app.whity.test';
    private const AUDIENCE = 'https://app.whity.test/api/v1/oauth/workspaces';
    private const KID      = 'key-1';

    private PDO $pdo;
    private string $privatePem;
    /** @var array<string, mixed> */
    private array $jwks;

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

    public function testAValidAssertionListsOnlyActiveNonSystemMemberships(): void
    {
        $profileId = $this->linkedProfile('sub-1');
        $this->member($profileId, 0);
        $this->member($profileId, 9, 'Closed Kitchen', 'closed-kitchen', 'suspended');
        $this->member($profileId, 7, 'Olive Grove', null);
        $this->member($profileId, 3, 'Example Bakery', 'example-bakery');
        // Someone else's workspace never shows.
        $this->member($this->linkedProfile('sub-2'), 5, 'Corner Cafe', 'corner-cafe');

        $response = $this->call($this->assertion(['sub' => 'sub-1']));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([
            'linked'     => true,
            'workspaces' => [
                ['id' => 3, 'name' => 'Example Bakery', 'slug' => 'example-bakery'],
                ['id' => 7, 'name' => 'Olive Grove', 'slug' => null],
            ],
        ], json_decode($response->getBody(), true));
        self::assertSame('no-store', self::header($response, 'Cache-Control'));
    }

    public function testAnArrayAudienceContainingTheEndpointIsAccepted(): void
    {
        $this->member($this->linkedProfile('sub-1'), 3, 'Example Bakery', 'example-bakery');

        $response = $this->call($this->assertion(['sub' => 'sub-1', 'aud' => ['https://other.test', self::AUDIENCE]]));

        self::assertSame(200, $response->getStatusCode());
    }

    public function testALinkedPersonWithNoWorkspaceIsLinkedWithAnEmptyList(): void
    {
        $this->linkedProfile('sub-1');

        $response = $this->call($this->assertion(['sub' => 'sub-1']));

        self::assertSame(['linked' => true, 'workspaces' => []], json_decode($response->getBody(), true));
    }

    public function testAnUnlinkedSubjectIsLinkedFalse(): void
    {
        $response = $this->call($this->assertion(['sub' => 'nobody']));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['linked' => false, 'workspaces' => []], json_decode($response->getBody(), true));
    }

    public function testATenantTrustLinkDoesNotResolve(): void
    {
        // A tenant's own IdP could claim any issuer; its links must never
        // answer for the operator-configured authorization server.
        $profileId = $this->profile();
        (new ExternalIdentityRepository($this->pdo))->link($profileId, 'byo', self::ISSUER, 'sub-1', null, 42);
        $this->member($profileId, 3, 'Example Bakery', 'example-bakery');

        $response = $this->call($this->assertion(['sub' => 'sub-1']));

        self::assertSame(['linked' => false, 'workspaces' => []], json_decode($response->getBody(), true));
    }

    /** @return array<string, array{array<string, mixed>, array<string, mixed>}> */
    public static function invalidAssertions(): array
    {
        $now = time();
        return [
            'access token typ'      => [[], ['typ' => 'at+jwt']],
            'plain JWT typ'         => [[], ['typ' => 'JWT']],
            'no typ'                => [[], ['typ' => null]],
            'another audience'      => [['aud' => 'https://app.whity.test/mcp'], []],
            'audience with a slash' => [['aud' => self::AUDIENCE . '/'], []],
            'another issuer'        => [['iss' => 'https://evil.test'], []],
            'expired'               => [['iat' => $now - 600, 'exp' => $now - 500], []],
            'no expiry'             => [['exp' => null], []],
            'no issued-at'          => [['iat' => null], []],
            'lives over 120 s'      => [['iat' => $now, 'exp' => $now + 121], []],
            'empty subject'         => [['sub' => ''], []],
            'no subject'            => [['sub' => null], []],
        ];
    }

    /**
     * @param array<string, mixed> $claims
     * @param array<string, mixed> $header
     */
    #[DataProvider('invalidAssertions')]
    public function testAnInvalidAssertionIs401WithNoDetail(array $claims, array $header): void
    {
        $this->member($this->linkedProfile('sub-1'), 3, 'Example Bakery', 'example-bakery');

        $this->assertUnauthorized($this->call($this->assertion(array_merge(['sub' => 'sub-1'], $claims), $header)));
    }

    public function testExactly120SecondsIsAccepted(): void
    {
        $this->member($this->linkedProfile('sub-1'), 3, 'Example Bakery', 'example-bakery');
        $now = time();

        self::assertSame(200, $this->call($this->assertion(['sub' => 'sub-1', 'iat' => $now, 'exp' => $now + 120]))->getStatusCode());
    }

    public function testAMissingOrMalformedAuthorizationIs401(): void
    {
        $this->assertUnauthorized($this->call(null));
        $this->assertUnauthorized($this->call('not-a-jwt'));
        $handler = $this->handler();
        $this->assertUnauthorized($handler->list(new Request('GET', '/api/v1/oauth/workspaces', [
            'Authorization' => 'Basic ' . base64_encode('a:b'),
        ])));
    }

    public function testASignatureFromAnotherKeyIs401(): void
    {
        $this->member($this->linkedProfile('sub-1'), 3, 'Example Bakery', 'example-bakery');
        $other = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($other);
        openssl_pkey_export($other, $otherPem);

        $this->assertUnauthorized($this->call($this->assertion(['sub' => 'sub-1'], [], $otherPem)));
    }

    public function testASymmetricKeyAndAlgorithmAre401(): void
    {
        $this->member($this->linkedProfile('sub-1'), 3, 'Example Bakery', 'example-bakery');
        // A shared secret published in the JWKS must never be a usable key.
        $secret = str_repeat('s', 32);
        $this->jwks = ['keys' => [['kty' => 'oct', 'kid' => 'hs', 'alg' => 'HS256', 'k' => self::b64url($secret)]]];

        $token = JWT::encode($this->claims(['sub' => 'sub-1']), $secret, 'HS256', 'hs', ['typ' => OAuthWorkspacesHandler::ASSERTION_TYPE]);

        $this->assertUnauthorized($this->call($token));
    }

    public function testAnHs256TokenAgainstTheRsaKeyIs401(): void
    {
        $this->member($this->linkedProfile('sub-1'), 3, 'Example Bakery', 'example-bakery');
        // The classic confusion: HMAC keyed with the public key's material.
        $token = JWT::encode(
            $this->claims(['sub' => 'sub-1']),
            (string) json_encode($this->jwks['keys'][0]),
            'HS256',
            self::KID,
            ['typ' => OAuthWorkspacesHandler::ASSERTION_TYPE],
        );

        $this->assertUnauthorized($this->call($token));
    }

    public function testAnAlgNoneTokenIs401(): void
    {
        $this->member($this->linkedProfile('sub-1'), 3, 'Example Bakery', 'example-bakery');
        $token = self::b64url((string) json_encode(['alg' => 'none', 'typ' => OAuthWorkspacesHandler::ASSERTION_TYPE, 'kid' => self::KID]))
            . '.' . self::b64url((string) json_encode($this->claims(['sub' => 'sub-1'])))
            . '.';

        $this->assertUnauthorized($this->call($token));
    }

    public function testOAuthOffIs404(): void
    {
        $handler = OAuthWorkspacesHandler::fromEnv(
            new ProtectedResource(null, self::APP_URL . '/mcp'),
            self::APP_URL,
            $this->http(),
            $this->jwksProvider(),
            $this->pdo,
        );
        self::assertSame(404, $handler->list($this->request($this->assertion(['sub' => 'sub-1'])))->getStatusCode());

        $noAppUrl = OAuthWorkspacesHandler::fromEnv(
            new ProtectedResource(self::ISSUER, null),
            '',
            $this->http(),
            $this->jwksProvider(),
            $this->pdo,
        );
        self::assertSame(404, $noAppUrl->list($this->request($this->assertion(['sub' => 'sub-1'])))->getStatusCode());
    }

    public function testFromEnvBuildsTheAudienceFromAppUrl(): void
    {
        $this->member($this->linkedProfile('sub-1'), 3, 'Example Bakery', 'example-bakery');
        $handler = OAuthWorkspacesHandler::fromEnv(
            new ProtectedResource(self::ISSUER, self::APP_URL . '/mcp'),
            self::APP_URL . '/',
            $this->http(),
            $this->jwksProvider(),
            $this->pdo,
        );

        self::assertSame(200, $handler->list($this->request($this->assertion(['sub' => 'sub-1'])))->getStatusCode());
    }

    private function assertUnauthorized(Response $response): void
    {
        self::assertSame(401, $response->getStatusCode());
        self::assertSame(['error' => 'Unauthorized'], json_decode($response->getBody(), true));
        self::assertSame('Bearer error="invalid_token"', self::header($response, 'WWW-Authenticate'));
    }

    private static function header(Response $response, string $name): ?string
    {
        return array_change_key_case($response->getHeaders(), CASE_LOWER)[strtolower($name)] ?? null;
    }

    private function call(?string $token): Response
    {
        return $this->handler()->list($this->request($token));
    }

    private function request(?string $token): Request
    {
        return new Request('GET', '/api/v1/oauth/workspaces', $token === null ? [] : ['Authorization' => 'Bearer ' . $token]);
    }

    private function handler(): OAuthWorkspacesHandler
    {
        return new OAuthWorkspacesHandler(
            new IssuerJwtVerifier(self::ISSUER, $this->http(), $this->jwksProvider()),
            self::AUDIENCE,
            $this->pdo,
        );
    }

    private function http(): HttpClient
    {
        return new class () implements HttpClient {
            public function getJson(string $url): ?array
            {
                return $url === 'https://auth.whity.test/.well-known/oauth-authorization-server'
                    ? ['issuer' => 'https://auth.whity.test', 'jwks_uri' => 'https://auth.whity.test/jwks']
                    : null;
            }

            public function postForm(string $url, array $params): ?array
            {
                return null;
            }
        };
    }

    private function jwksProvider(): JwksProvider
    {
        return new JwksProvider(fn (string $uri): array => $this->jwks);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function claims(array $overrides): array
    {
        $claims = array_merge([
            'iss' => self::ISSUER,
            'aud' => self::AUDIENCE,
            'iat' => time(),
            'exp' => time() + 60,
            'jti' => bin2hex(random_bytes(8)),
        ], $overrides);
        return array_filter($claims, static fn ($v): bool => $v !== null);
    }

    /**
     * @param array<string, mixed> $overrides
     * @param array<string, mixed> $header
     */
    private function assertion(array $overrides, array $header = [], ?string $pem = null): string
    {
        $header += ['typ' => OAuthWorkspacesHandler::ASSERTION_TYPE];
        if ($header['typ'] === null) {
            // firebase/php-jwt always writes a typ; build the header by hand to omit it.
            $signingInput = self::b64url((string) json_encode(['alg' => 'RS256', 'kid' => self::KID]))
                . '.' . self::b64url((string) json_encode($this->claims($overrides)));
            openssl_sign($signingInput, $signature, $pem ?? $this->privatePem, OPENSSL_ALGO_SHA256);
            return $signingInput . '.' . self::b64url($signature);
        }
        return JWT::encode($this->claims($overrides), $pem ?? $this->privatePem, 'RS256', self::KID, $header);
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

    private function member(int $profileId, int $tenantId, ?string $name = null, ?string $slug = null, string $status = 'active'): void
    {
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM tenants WHERE id = ?');
        $count->execute([$tenantId]);
        if ((int) $count->fetchColumn() === 0) {
            $this->pdo->prepare('INSERT INTO tenants (id, name, slug, created_at) VALUES (:id, :name, :slug, NOW())')
                ->execute([':id' => $tenantId, ':name' => $name ?? 'tenant-' . $tenantId, ':slug' => $slug]);
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
