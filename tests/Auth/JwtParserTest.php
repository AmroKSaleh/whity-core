<?php

namespace Tests\Auth;

use PHPUnit\Framework\TestCase;
use Whity\Auth\JwtParser;

/**
 * Tests for JwtParser class
 */
class JwtParserTest extends TestCase
{
    private JwtParser $parser;
    private string $secret = 'test-secret-key';

    protected function setUp(): void
    {
        $this->parser = new JwtParser($this->secret);
    }

    /**
     * Test creating and parsing a token with matching payload
     */
    public function testCreateAndParseToken(): void
    {
        $payload = [
            'sub' => 'user123',
            'email' => 'user@example.com',
            'role' => 'user'
        ];

        $token = $this->parser->create($payload);
        $parsed = $this->parser->parse($token);

        $this->assertNotNull($parsed);
        $this->assertSame('user123', $parsed['sub']);
        $this->assertSame('user@example.com', $parsed['email']);
        $this->assertSame('user', $parsed['role']);
        $this->assertArrayHasKey('exp', $parsed);
    }

    /**
     * Test that tampered token signature is rejected
     */
    public function testInvalidSignatureReturnsNull(): void
    {
        $payload = ['sub' => 'user123'];
        $token = $this->parser->create($payload);

        // Tamper with the payload part
        $parts = explode('.', $token);
        $parts[1] = 'dGFtcGVyZWRwYXlsb2Fk'; // 'tamperedpayload' in base64
        $tamperedToken = implode('.', $parts);

        $parsed = $this->parser->parse($tamperedToken);
        $this->assertNull($parsed);
    }

    /**
     * Test that expired token is rejected
     */
    public function testExpiredTokenReturnsNull(): void
    {
        $payload = ['sub' => 'user123'];
        // Create token that expires immediately
        $token = $this->parser->create($payload, 0);

        // Wait a small amount to ensure expiration
        sleep(1);

        $parsed = $this->parser->parse($token);
        $this->assertNull($parsed);
    }

    /**
     * Test that malformed token format is rejected
     */
    public function testMalformedTokenReturnsNull(): void
    {
        // Token with wrong number of parts
        $result = $this->parser->parse('only.two.parts.extra');
        $this->assertNull($result);

        // Token with only 2 parts
        $result = $this->parser->parse('header.payload');
        $this->assertNull($result);

        // Token with only 1 part
        $result = $this->parser->parse('singlepart');
        $this->assertNull($result);

        // Empty string
        $result = $this->parser->parse('');
        $this->assertNull($result);
    }

    /**
     * Test that different secret rejects the token
     */
    public function testDifferentSecretRejectsToken(): void
    {
        $payload = ['sub' => 'user123'];
        $token = $this->parser->create($payload);

        $differentParser = new JwtParser('different-secret');
        $parsed = $differentParser->parse($token);

        $this->assertNull($parsed);
    }

    /**
     * Test token with custom expiration time
     */
    public function testTokenWithCustomExpiration(): void
    {
        $payload = ['sub' => 'user123'];
        $token = $this->parser->create($payload, 7200);

        $parsed = $this->parser->parse($token);
        $this->assertNotNull($parsed);

        // Check that expiration is approximately 2 hours from now
        $expectedExp = time() + 7200;
        $this->assertEqualsWithDelta($expectedExp, $parsed['exp'], 2);
    }

    /**
     * Test that payload with complex data structures works
     */
    public function testComplexPayloadStructure(): void
    {
        $payload = [
            'sub' => 'user123',
            'email' => 'user@example.com',
            'roles' => ['user', 'admin'],
            'permissions' => ['read', 'write', 'delete'],
            'metadata' => [
                'firstName' => 'John',
                'lastName' => 'Doe',
                'age' => 30
            ]
        ];

        $token = $this->parser->create($payload);
        $parsed = $this->parser->parse($token);

        $this->assertNotNull($parsed);
        $this->assertSame('user123', $parsed['sub']);
        $this->assertSame(['user', 'admin'], $parsed['roles']);
        $this->assertSame(['read', 'write', 'delete'], $parsed['permissions']);
        $this->assertSame('John', $parsed['metadata']['firstName']);
        $this->assertSame(30, $parsed['metadata']['age']);
    }

    /**
     * Test that invalid base64 in token returns null
     */
    public function testInvalidBase64ReturnsNull(): void
    {
        // Create a token with invalid base64 in payload
        $header = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9'; // valid base64
        $invalidPayload = '!!!invalid!!!'; // invalid base64
        $signature = 'somesignature';
        $token = $header . '.' . $invalidPayload . '.' . $signature;

        $parsed = $this->parser->parse($token);
        $this->assertNull($parsed);
    }

    /**
     * Test that non-JSON payload returns null
     */
    public function testInvalidJsonPayloadReturnsNull(): void
    {
        // Create a token with payload that decodes to invalid JSON
        $header = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9'; // valid base64
        // Create base64 of invalid JSON
        $invalidPayload = base64_encode('{invalid json}');
        // Make it URL-safe
        $invalidPayload = strtr($invalidPayload, '+/', '-_');
        $signature = 'somesignature';
        $token = $header . '.' . $invalidPayload . '.' . $signature;

        $parsed = $this->parser->parse($token);
        $this->assertNull($parsed);
    }

    /**
     * Test that create() generates a valid jti (32 hex characters)
     */
    public function testCreateGeneratesValidJti(): void
    {
        $payload = ['sub' => 'user123'];
        $token = $this->parser->create($payload);
        $parsed = $this->parser->parse($token);

        $this->assertNotNull($parsed);
        $this->assertArrayHasKey('jti', $parsed);
        // jti should be 32 hex characters (16 random bytes * 2)
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $parsed['jti']);
    }

    /**
     * Test that create() includes type field with default value 'access'
     */
    public function testCreateIncludesTypeFieldDefault(): void
    {
        $payload = ['sub' => 'user123'];
        $token = $this->parser->create($payload);
        $parsed = $this->parser->parse($token);

        $this->assertNotNull($parsed);
        $this->assertArrayHasKey('type', $parsed);
        $this->assertSame('access', $parsed['type']);
    }

    /**
     * Test that create() accepts and includes 'refresh' type
     */
    public function testCreateIncludesRefreshType(): void
    {
        $payload = ['sub' => 'user123'];
        $token = $this->parser->create($payload, 3600, 'refresh');
        $parsed = $this->parser->parse($token);

        $this->assertNotNull($parsed);
        $this->assertArrayHasKey('type', $parsed);
        $this->assertSame('refresh', $parsed['type']);
    }

    /**
     * Test that create() accepts and includes 'access' type explicitly
     */
    public function testCreateIncludesAccessType(): void
    {
        $payload = ['sub' => 'user123'];
        $token = $this->parser->create($payload, 3600, 'access');
        $parsed = $this->parser->parse($token);

        $this->assertNotNull($parsed);
        $this->assertArrayHasKey('type', $parsed);
        $this->assertSame('access', $parsed['type']);
    }

    /**
     * Test that each call to create() generates a unique jti
     */
    public function testCreateGeneratesUniqueJti(): void
    {
        $payload = ['sub' => 'user123'];

        $token1 = $this->parser->create($payload);
        $parsed1 = $this->parser->parse($token1);

        $token2 = $this->parser->create($payload);
        $parsed2 = $this->parser->parse($token2);

        $this->assertNotNull($parsed1);
        $this->assertNotNull($parsed2);
        // jti values should be different
        $this->assertNotSame($parsed1['jti'], $parsed2['jti']);
    }

    /**
     * Test that parse() returns null if jti is missing
     */
    public function testParseReturnsNullIfJtiMissing(): void
    {
        // Manually create a token without jti
        $payload = [
            'sub' => 'user123',
            'type' => 'access',
            'exp' => time() + 3600
        ];

        $header = [
            'alg' => 'HS256',
            'typ' => 'JWT'
        ];

        $headerB64 = strtr(base64_encode(json_encode($header)), '+/', '-_');
        $payloadB64 = strtr(base64_encode(json_encode($payload)), '+/', '-_');

        // Create a fake signature (won't be valid, but we need the structure)
        $fakeSignature = 'fakesignature';
        $token = $headerB64 . '.' . $payloadB64 . '.' . $fakeSignature;

        $parsed = $this->parser->parse($token);
        $this->assertNull($parsed);
    }

    /**
     * Test that parse() returns null if type is missing
     */
    public function testParseReturnsNullIfTypeMissing(): void
    {
        // Manually create a token without type
        $payload = [
            'sub' => 'user123',
            'jti' => 'a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6',
            'exp' => time() + 3600
        ];

        $header = [
            'alg' => 'HS256',
            'typ' => 'JWT'
        ];

        $headerB64 = strtr(base64_encode(json_encode($header)), '+/', '-_');
        $payloadB64 = strtr(base64_encode(json_encode($payload)), '+/', '-_');

        // Create a fake signature
        $fakeSignature = 'fakesignature';
        $token = $headerB64 . '.' . $payloadB64 . '.' . $fakeSignature;

        $parsed = $this->parser->parse($token);
        $this->assertNull($parsed);
    }

    /**
     * Test that parse() returns null if both jti and type are missing
     */
    public function testParseReturnsNullIfBothJtiAndTypeMissing(): void
    {
        // Create payload without jti and type
        $payload = [
            'sub' => 'user123',
            'exp' => time() + 3600
        ];

        $header = [
            'alg' => 'HS256',
            'typ' => 'JWT'
        ];

        $headerB64 = strtr(base64_encode(json_encode($header)), '+/', '-_');
        $payloadB64 = strtr(base64_encode(json_encode($payload)), '+/', '-_');
        $fakeSignature = 'fakesignature';
        $token = $headerB64 . '.' . $payloadB64 . '.' . $fakeSignature;

        $parsed = $this->parser->parse($token);
        $this->assertNull($parsed);
    }

    /**
     * Test that jti and type survive a full create/parse cycle
     */
    public function testJtiAndTypeSurviveFullCycle(): void
    {
        $payload = [
            'sub' => 'user123',
            'email' => 'user@example.com',
            'roles' => ['admin', 'user']
        ];

        // Create with explicit type
        $token = $this->parser->create($payload, 3600, 'refresh');
        $parsed = $this->parser->parse($token);

        $this->assertNotNull($parsed);
        // Verify original payload data is preserved
        $this->assertSame('user123', $parsed['sub']);
        $this->assertSame('user@example.com', $parsed['email']);
        $this->assertSame(['admin', 'user'], $parsed['roles']);
        // Verify new fields are present
        $this->assertArrayHasKey('jti', $parsed);
        $this->assertArrayHasKey('type', $parsed);
        $this->assertSame('refresh', $parsed['type']);
    }
}
