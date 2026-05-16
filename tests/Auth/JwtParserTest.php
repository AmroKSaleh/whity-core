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
}
