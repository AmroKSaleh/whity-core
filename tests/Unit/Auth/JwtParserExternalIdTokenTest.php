<?php

declare(strict_types=1);

namespace Tests\Unit\Auth;

use Firebase\JWT\JWT;
use PHPUnit\Framework\TestCase;
use Whity\Auth\JwtParser;

/**
 * Unit tests for {@see JwtParser::verifyExternalIdToken()} (WC-24a1): kid-aware
 * RS256 verification against a provider JWKS, plus the OIDC claim checks
 * (iss/aud/nonce/exp). Uses a freshly generated RSA keypair so the whole
 * sign → JWKS → verify path is exercised for real.
 */
final class JwtParserExternalIdTokenTest extends TestCase
{
    private JwtParser $parser;
    private string $privatePem;
    private string $publicPem;
    /** @var array<string, mixed> */
    private array $jwks;
    private string $kid = 'test-key-1';

    private const ISSUER = 'https://accounts.google.com';
    private const AUDIENCE = 'client-id-123.apps.googleusercontent.com';

    protected function setUp(): void
    {
        // The internal secret is irrelevant to the external path; any value works.
        $this->parser = new JwtParser('internal_hs256_secret_min_32_chars_aaaa');

        $res = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        if ($res === false) {
            self::fail('failed to generate RSA key');
        }
        openssl_pkey_export($res, $privatePem);
        $this->privatePem = $privatePem;

        $details = openssl_pkey_get_details($res);
        if ($details === false) {
            self::fail('failed to read RSA key details');
        }
        $this->publicPem = (string) $details['key'];
        $this->jwks = ['keys' => [$this->jwkFrom($details['rsa']['n'], $details['rsa']['e'], $this->kid)]];
    }

    /**
     * @return array<string, string>
     */
    private function jwkFrom(string $n, string $e, string $kid): array
    {
        return [
            'kty' => 'RSA',
            'use' => 'sig',
            'alg' => 'RS256',
            'kid' => $kid,
            'n'   => self::b64url($n),
            'e'   => self::b64url($e),
        ];
    }

    private static function b64url(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function signIdToken(array $overrides = [], string $kid = 'test-key-1'): string
    {
        $now = time();
        $claims = array_merge([
            'iss'   => self::ISSUER,
            'aud'   => self::AUDIENCE,
            'sub'   => 'google-sub-123',
            'email' => 'alice@example.com',
            'email_verified' => true,
            'iat'   => $now,
            'exp'   => $now + 3600,
        ], $overrides);

        return JWT::encode($claims, $this->privatePem, 'RS256', $kid);
    }

    public function testValidTokenVerifiesAndReturnsClaims(): void
    {
        $token = $this->signIdToken(['nonce' => 'n-abc']);

        $claims = $this->parser->verifyExternalIdToken(
            $token,
            $this->jwks,
            self::ISSUER,
            self::AUDIENCE,
            'n-abc'
        );

        self::assertNotNull($claims);
        self::assertSame('google-sub-123', $claims['sub']);
        self::assertSame('alice@example.com', $claims['email']);
    }

    public function testWrongIssuerIsRejected(): void
    {
        $token = $this->signIdToken();
        self::assertNull(
            $this->parser->verifyExternalIdToken($token, $this->jwks, 'https://evil.example', self::AUDIENCE)
        );
    }

    public function testWrongAudienceIsRejected(): void
    {
        $token = $this->signIdToken();
        self::assertNull(
            $this->parser->verifyExternalIdToken($token, $this->jwks, self::ISSUER, 'someone-elses-client-id')
        );
    }

    public function testAudienceListIsAccepted(): void
    {
        // aud may be an array; verification passes when our audience is a member.
        $token = $this->signIdToken(['aud' => ['other', self::AUDIENCE]]);
        self::assertNotNull(
            $this->parser->verifyExternalIdToken($token, $this->jwks, self::ISSUER, self::AUDIENCE)
        );
    }

    public function testNonceMismatchIsRejected(): void
    {
        $token = $this->signIdToken(['nonce' => 'real-nonce']);
        self::assertNull(
            $this->parser->verifyExternalIdToken($token, $this->jwks, self::ISSUER, self::AUDIENCE, 'expected-different')
        );
        // But when no nonce is expected, presence of one is fine.
        self::assertNotNull(
            $this->parser->verifyExternalIdToken($token, $this->jwks, self::ISSUER, self::AUDIENCE, null)
        );
    }

    public function testExpiredTokenIsRejected(): void
    {
        $now = time();
        $token = $this->signIdToken(['iat' => $now - 7200, 'exp' => $now - 3600]);
        self::assertNull(
            $this->parser->verifyExternalIdToken($token, $this->jwks, self::ISSUER, self::AUDIENCE)
        );
    }

    public function testUnknownKidIsRejected(): void
    {
        // JWKS advertises a different kid than the token was signed with.
        $token = $this->signIdToken([], 'some-other-kid');
        self::assertNull(
            $this->parser->verifyExternalIdToken($token, $this->jwks, self::ISSUER, self::AUDIENCE)
        );
    }

    public function testTamperedTokenIsRejected(): void
    {
        $token = $this->signIdToken();
        $parts = explode('.', $token);
        // Corrupt the payload segment; the RS256 signature no longer matches.
        $parts[1] = rtrim(strtr(base64_encode('{"iss":"x"}'), '+/', '-_'), '=');
        $tampered = implode('.', $parts);

        self::assertNull(
            $this->parser->verifyExternalIdToken($tampered, $this->jwks, self::ISSUER, self::AUDIENCE)
        );
    }

    public function testAlgConfusionHs256WithPublicKeyIsRejected(): void
    {
        // Classic JWKS attack: forge a token with header alg=HS256, HMAC-signed
        // using the RSA PUBLIC key (which the attacker has, from the JWKS), hoping
        // the verifier treats the public key as an HMAC secret. Because decode
        // selects the key by `kid` and verifies with THAT key's asymmetric alg
        // (RS256), the symmetric forgery cannot validate.
        $now = time();
        $forged = JWT::encode(
            ['iss' => self::ISSUER, 'aud' => self::AUDIENCE, 'sub' => 'attacker', 'iat' => $now, 'exp' => $now + 3600],
            $this->publicPem,
            'HS256',
            $this->kid
        );

        self::assertNull(
            $this->parser->verifyExternalIdToken($forged, $this->jwks, self::ISSUER, self::AUDIENCE),
            'an HS256/public-key alg-confusion forgery must be rejected'
        );
    }

    public function testInternalHs256PathIsUnaffected(): void
    {
        // Sanity: the internal token path still works and is independent.
        $internal = $this->parser->create(['profile_id' => 7], 900, 'access');
        $claims = $this->parser->parse($internal);
        self::assertNotNull($claims);
        self::assertSame(7, $claims['profile_id']);
    }
}
