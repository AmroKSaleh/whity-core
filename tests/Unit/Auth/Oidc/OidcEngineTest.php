<?php

declare(strict_types=1);

namespace Tests\Unit\Auth\Oidc;

use Firebase\JWT\JWT;
use PHPUnit\Framework\TestCase;
use Whity\Auth\JwtParser;
use Whity\Auth\Oidc\JwksProvider;
use Whity\Auth\Oidc\OidcEngine;
use Whity\Auth\Oidc\StandardOidcProvider;
use Whity\Core\Http\HttpClient;

/**
 * Unit tests for {@see OidcEngine} (WC-ae16) — the Authorization Code + PKCE
 * protocol logic. HTTP is stubbed; the ID token is signed with a freshly
 * generated RSA key so signature verification runs for real.
 */
final class OidcEngineTest extends TestCase
{
    private const ISSUER = 'https://idp.example';
    private const CLIENT_ID = 'client-abc';

    private string $privatePem;
    /** @var array<string, mixed> */
    private array $jwks;
    private string $kid = 'kid-1';
    private StubHttpClient $http;

    /** @var array<string, mixed> */
    private array $discovery = [
        'issuer'                 => self::ISSUER,
        'authorization_endpoint' => 'https://idp.example/authorize',
        'token_endpoint'         => 'https://idp.example/token',
        'jwks_uri'               => 'https://idp.example/jwks',
    ];

    protected function setUp(): void
    {
        $res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if ($res === false) {
            self::fail('key gen failed');
        }
        openssl_pkey_export($res, $priv);
        $this->privatePem = $priv;
        $d = openssl_pkey_get_details($res);
        if ($d === false) {
            self::fail('key details failed');
        }
        $this->jwks = ['keys' => [[
            'kty' => 'RSA', 'use' => 'sig', 'alg' => 'RS256', 'kid' => $this->kid,
            'n' => self::b64url($d['rsa']['n']), 'e' => self::b64url($d['rsa']['e']),
        ]]];

        $this->http = new StubHttpClient();
    }

    private function engine(): OidcEngine
    {
        $jwksProvider = new JwksProvider(fn(string $uri): array => $this->jwks, 3600);
        return new OidcEngine($this->http, $jwksProvider, new JwtParser('internal_secret_min_32_chars_aaaaaaaa'));
    }

    private function provider(): StandardOidcProvider
    {
        return new StandardOidcProvider('google', 'Google');
    }

    private static function b64url(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function signIdToken(array $overrides = []): string
    {
        $now = time();
        return JWT::encode(array_merge([
            'iss' => self::ISSUER, 'aud' => self::CLIENT_ID, 'sub' => 'sub-1',
            'email' => 'user@idp.example', 'email_verified' => true, 'name' => 'A User',
            'iat' => $now, 'exp' => $now + 3600,
        ], $overrides), $this->privatePem, 'RS256', $this->kid);
    }

    // (helper test-double defined below the class)

    public function testGeneratePkceProducesValidS256Challenge(): void
    {
        $pkce = $this->engine()->generatePkce();
        self::assertMatchesRegularExpression('/^[A-Za-z0-9\-_]{43}$/', $pkce['verifier']);
        $expected = rtrim(strtr(base64_encode(hash('sha256', $pkce['verifier'], true)), '+/', '-_'), '=');
        self::assertSame($expected, $pkce['challenge']);
    }

    public function testDiscoverReturnsValidDocument(): void
    {
        $this->http->discoveryDoc = $this->discovery;
        self::assertSame($this->discovery, $this->engine()->discover('https://idp.example/.well-known/openid-configuration'));
    }

    public function testDiscoverRejectsMissingEndpoint(): void
    {
        $doc = $this->discovery;
        unset($doc['token_endpoint']);
        $this->http->discoveryDoc = $doc;
        self::assertNull($this->engine()->discover('https://idp.example/.well-known/openid-configuration'));
    }

    public function testDiscoverRejectsNonHttpsEndpoint(): void
    {
        $doc = $this->discovery;
        $doc['token_endpoint'] = 'http://idp.example/token';
        $this->http->discoveryDoc = $doc;
        self::assertNull($this->engine()->discover('https://idp.example/.well-known/openid-configuration'));
    }

    public function testBuildAuthorizationUrlContainsPkceAndParams(): void
    {
        $url = $this->engine()->buildAuthorizationUrl(
            $this->provider(),
            $this->discovery,
            self::CLIENT_ID,
            'https://app.example/callback',
            'state-xyz',
            'nonce-123',
            'challenge-abc'
        );
        self::assertStringStartsWith('https://idp.example/authorize?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        /** @var array<string, string> $q */
        self::assertSame(self::CLIENT_ID, (string) $q['client_id']);
        self::assertSame('code', (string) $q['response_type']);
        self::assertSame('S256', (string) $q['code_challenge_method']);
        self::assertSame('challenge-abc', (string) $q['code_challenge']);
        self::assertSame('state-xyz', (string) $q['state']);
        self::assertSame('nonce-123', (string) $q['nonce']);
        self::assertStringContainsString('openid', (string) $q['scope']);
        // Google-specific: refresh-token opt-in.
        self::assertSame('offline', (string) $q['access_type']);
        self::assertSame('consent', (string) $q['prompt']);
    }

    public function testExchangeCodeSendsAuthorizationCodeGrant(): void
    {
        $this->http->tokenResponse = ['id_token' => 'x', 'access_token' => 'y'];
        $result = $this->engine()->exchangeCode(
            $this->discovery,
            self::CLIENT_ID,
            'the-secret',
            'auth-code',
            'https://app.example/callback',
            'verifier-1'
        );
        self::assertSame(['id_token' => 'x', 'access_token' => 'y'], $result);
        self::assertSame('authorization_code', $this->http->lastPostParams['grant_type']);
        self::assertSame('auth-code', $this->http->lastPostParams['code']);
        self::assertSame('verifier-1', $this->http->lastPostParams['code_verifier']);
        self::assertSame('the-secret', $this->http->lastPostParams['client_secret']);
    }

    public function testVerifyIdTokenReturnsIdentity(): void
    {
        $identity = $this->engine()->verifyIdToken(
            $this->signIdToken(['nonce' => 'n-1']),
            $this->discovery,
            self::CLIENT_ID,
            self::ISSUER,
            'n-1',
            $this->provider()
        );
        self::assertNotNull($identity);
        self::assertSame(self::ISSUER, $identity->issuer);
        self::assertSame('sub-1', $identity->subject);
        self::assertSame('user@idp.example', $identity->email);
        self::assertTrue($identity->emailVerified);
    }

    public function testVerifyIdTokenRejectsDiscoveryIssuerMismatch(): void
    {
        // A substituted discovery doc whose issuer differs from the configured
        // expected issuer must be rejected before trusting its keys.
        $doc = $this->discovery;
        $doc['issuer'] = 'https://attacker.example';
        self::assertNull($this->engine()->verifyIdToken(
            $this->signIdToken(),
            $doc,
            self::CLIENT_ID,
            self::ISSUER,
            null,
            $this->provider()
        ));
    }

    public function testVerifyIdTokenRejectsNonceMismatch(): void
    {
        self::assertNull($this->engine()->verifyIdToken(
            $this->signIdToken(['nonce' => 'real']),
            $this->discovery,
            self::CLIENT_ID,
            self::ISSUER,
            'expected-other',
            $this->provider()
        ));
    }

    public function testVerifyIdTokenRejectsWrongAudience(): void
    {
        self::assertNull($this->engine()->verifyIdToken(
            $this->signIdToken(['aud' => 'someone-else']),
            $this->discovery,
            self::CLIENT_ID,
            self::ISSUER,
            null,
            $this->provider()
        ));
    }
}

/**
 * Stub HTTP client: canned discovery + token responses, capturing the last POST.
 */
final class StubHttpClient implements HttpClient
{
    /** @var array<string, mixed>|null */
    public ?array $discoveryDoc = null;
    /** @var array<string, mixed>|null */
    public ?array $tokenResponse = null;
    /** @var array<string, string> */
    public array $lastPostParams = [];

    public function getJson(string $url): ?array
    {
        return $this->discoveryDoc;
    }

    public function postForm(string $url, array $params): ?array
    {
        $this->lastPostParams = $params;
        return $this->tokenResponse;
    }
}
