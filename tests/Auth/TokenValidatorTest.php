<?php

namespace Tests\Auth;

use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use Whity\Auth\CookieManager;
use Whity\Auth\JwtParser;
use Whity\Auth\TokenValidator;

/**
 * Tests for TokenValidator class
 */
class TokenValidatorTest extends TestCase
{
    private TokenValidator $validator;
    private JwtParser $jwtParser;
    private PDO $mockDb;
    private string $secret = 'test-secret-key';

    protected function setUp(): void
    {
        // Clear $_COOKIE before each test
        $_COOKIE = [];

        // Create JWT parser
        $this->jwtParser = new JwtParser($this->secret);

        // Create mock PDO with default behavior (token not revoked)
        $this->mockDb = $this->createMock(PDO::class);

        // Default mock statement for revocation check (not revoked)
        $defaultStatement = $this->createMock(PDOStatement::class);
        $defaultStatement->method('execute')->willReturn(true);
        $defaultStatement->method('rowCount')->willReturn(0); // Default: token not revoked

        $this->mockDb->method('prepare')
            ->willReturn($defaultStatement);

        // Create the validator
        $this->validator = new TokenValidator($this->jwtParser, $this->mockDb);
    }

    /**
     * Test validateAccessToken returns null when no token is set
     */
    public function testValidateAccessTokenReturnsNullWhenNoToken(): void
    {
        $result = $this->validator->validateAccessToken();
        $this->assertNull($result);
    }

    /**
     * Test validateAccessToken returns claims when token is valid
     */
    public function testValidateAccessTokenReturnsClainsWhenValid(): void
    {
        $payload = [
            'sub' => 'user123',
            'email' => 'user@example.com'
        ];

        $token = $this->jwtParser->create($payload, 3600, 'access');
        $_COOKIE['access_token'] = $token;

        $result = $this->validator->validateAccessToken();

        $this->assertNotNull($result);
        $this->assertSame('user123', $result['sub']);
        $this->assertSame('user@example.com', $result['email']);
        $this->assertSame('access', $result['type']);
    }

    /**
     * Test validateAccessToken returns null when token type is wrong
     */
    public function testValidateAccessTokenReturnsNullWhenWrongType(): void
    {
        $payload = ['sub' => 'user123'];

        // Create a refresh token instead of access token
        $token = $this->jwtParser->create($payload, 3600, 'refresh');
        $_COOKIE['access_token'] = $token;

        $result = $this->validator->validateAccessToken();

        $this->assertNull($result);
    }

    /**
     * Test validateAccessToken returns null when token is expired
     */
    public function testValidateAccessTokenReturnsNullWhenExpired(): void
    {
        $payload = ['sub' => 'user123'];

        // Create token that expires immediately
        $token = $this->jwtParser->create($payload, 0, 'access');
        $_COOKIE['access_token'] = $token;

        // Wait for token to expire
        sleep(1);

        $result = $this->validator->validateAccessToken();

        $this->assertNull($result);
    }

    /**
     * Test validateAccessToken returns null when token is malformed
     */
    public function testValidateAccessTokenReturnsNullWhenMalformed(): void
    {
        $_COOKIE['access_token'] = 'invalid.token.format.extra';

        $result = $this->validator->validateAccessToken();

        $this->assertNull($result);
    }

    /**
     * Test validateAccessToken returns null when token signature is invalid
     */
    public function testValidateAccessTokenReturnsNullWhenSignatureInvalid(): void
    {
        $payload = ['sub' => 'user123'];
        $token = $this->jwtParser->create($payload, 3600, 'access');

        // Tamper with the token
        $parts = explode('.', $token);
        $parts[2] = 'invalidsignature';
        $tamperedToken = implode('.', $parts);

        $_COOKIE['access_token'] = $tamperedToken;

        $result = $this->validator->validateAccessToken();

        $this->assertNull($result);
    }

    /**
     * Test validateRefreshToken returns null when no token is set
     */
    public function testValidateRefreshTokenReturnsNullWhenNoToken(): void
    {
        $result = $this->validator->validateRefreshToken();
        $this->assertNull($result);
    }

    /**
     * Test validateRefreshToken returns claims when token is valid and not revoked
     */
    public function testValidateRefreshTokenReturnsClainsWhenValidAndNotRevoked(): void
    {
        $payload = [
            'sub' => 'user123',
            'email' => 'user@example.com'
        ];

        $token = $this->jwtParser->create($payload, 604800, 'refresh');
        $_COOKIE['refresh_token'] = $token;

        $result = $this->validator->validateRefreshToken();

        $this->assertNotNull($result);
        $this->assertSame('user123', $result['sub']);
        $this->assertSame('user@example.com', $result['email']);
        $this->assertSame('refresh', $result['type']);
    }

    /**
     * Test validateRefreshToken returns null when token type is wrong
     */
    public function testValidateRefreshTokenReturnsNullWhenWrongType(): void
    {
        $payload = ['sub' => 'user123'];

        // Create an access token instead of refresh token
        $token = $this->jwtParser->create($payload, 3600, 'access');
        $_COOKIE['refresh_token'] = $token;

        $result = $this->validator->validateRefreshToken();

        $this->assertNull($result);
    }

    /**
     * Test validateRefreshToken returns null when token is expired
     */
    public function testValidateRefreshTokenReturnsNullWhenExpired(): void
    {
        $payload = ['sub' => 'user123'];

        // Create token that expires immediately
        $token = $this->jwtParser->create($payload, 0, 'refresh');
        $_COOKIE['refresh_token'] = $token;

        // Wait for token to expire
        sleep(1);

        $result = $this->validator->validateRefreshToken();

        $this->assertNull($result);
    }

    /**
     * Test validateRefreshToken returns null when token is revoked
     */
    public function testValidateRefreshTokenReturnsNullWhenRevoked(): void
    {
        $payload = ['sub' => 'user123'];
        $token = $this->jwtParser->create($payload, 604800, 'refresh');

        // Parse to get the jti
        $claims = $this->jwtParser->parse($token);

        // Create a new validator with mock that reports token as revoked
        $mockDb = $this->createMock(PDO::class);
        $mockStatement = $this->createMock(PDOStatement::class);
        $mockStatement->method('execute')->willReturn(true);
        $mockStatement->method('rowCount')->willReturn(1); // Token is revoked

        $mockDb->method('prepare')
            ->willReturn($mockStatement);

        $validator = new TokenValidator($this->jwtParser, $mockDb);

        $_COOKIE['refresh_token'] = $token;

        $result = $validator->validateRefreshToken();

        $this->assertNull($result);
    }

    /**
     * Test validateRefreshToken returns null when token is malformed
     */
    public function testValidateRefreshTokenReturnsNullWhenMalformed(): void
    {
        $_COOKIE['refresh_token'] = 'invalid.token.format.extra';

        $result = $this->validator->validateRefreshToken();

        $this->assertNull($result);
    }

    /**
     * Test validateRefreshToken returns null when token signature is invalid
     */
    public function testValidateRefreshTokenReturnsNullWhenSignatureInvalid(): void
    {
        $payload = ['sub' => 'user123'];
        $token = $this->jwtParser->create($payload, 604800, 'refresh');

        // Tamper with the token
        $parts = explode('.', $token);
        $parts[2] = 'invalidsignature';
        $tamperedToken = implode('.', $parts);

        $_COOKIE['refresh_token'] = $tamperedToken;

        $result = $this->validator->validateRefreshToken();

        $this->assertNull($result);
    }

    /**
     * Test that access and refresh tokens can be validated independently
     */
    public function testAccessAndRefreshTokensCanBeValidatedIndependently(): void
    {
        $payload = ['sub' => 'user123'];

        $accessToken = $this->jwtParser->create($payload, 3600, 'access');
        $refreshToken = $this->jwtParser->create($payload, 604800, 'refresh');

        $_COOKIE['access_token'] = $accessToken;
        $_COOKIE['refresh_token'] = $refreshToken;

        $accessResult = $this->validator->validateAccessToken();
        $refreshResult = $this->validator->validateRefreshToken();

        $this->assertNotNull($accessResult);
        $this->assertNotNull($refreshResult);
        $this->assertSame('access', $accessResult['type']);
        $this->assertSame('refresh', $refreshResult['type']);
    }

    /**
     * Test validateAccessToken with empty cookie string
     */
    public function testValidateAccessTokenWithEmptyString(): void
    {
        $_COOKIE['access_token'] = '';

        $result = $this->validator->validateAccessToken();

        $this->assertNull($result);
    }

    /**
     * Test validateRefreshToken with empty cookie string
     */
    public function testValidateRefreshTokenWithEmptyString(): void
    {
        $_COOKIE['refresh_token'] = '';

        $result = $this->validator->validateRefreshToken();

        $this->assertNull($result);
    }

    /**
     * Test validateAccessToken with token from different secret
     */
    public function testValidateAccessTokenWithDifferentSecret(): void
    {
        $payload = ['sub' => 'user123'];

        // Create parser with different secret
        $differentParser = new JwtParser('different-secret');
        $token = $differentParser->create($payload, 3600, 'access');

        $_COOKIE['access_token'] = $token;

        // Validator uses original secret
        $result = $this->validator->validateAccessToken();

        $this->assertNull($result);
    }

    /**
     * Test validateRefreshToken with token from different secret
     */
    public function testValidateRefreshTokenWithDifferentSecret(): void
    {
        $payload = ['sub' => 'user123'];

        // Create parser with different secret
        $differentParser = new JwtParser('different-secret');
        $token = $differentParser->create($payload, 604800, 'refresh');

        $_COOKIE['refresh_token'] = $token;

        // Validator uses original secret
        $result = $this->validator->validateRefreshToken();

        $this->assertNull($result);
    }

    /**
     * Test validateRefreshToken does not reject valid tokens when revocation table is empty
     */
    public function testValidateRefreshTokenWithEmptyRevocationTable(): void
    {
        $payload = ['sub' => 'user123'];
        $token = $this->jwtParser->create($payload, 604800, 'refresh');

        // Mock the revoked_tokens query to return no rows
        $mockStatement = $this->createMock(PDOStatement::class);
        $mockStatement->method('execute')->willReturn(true);
        $mockStatement->method('rowCount')->willReturn(0); // Token is not revoked

        $this->mockDb->method('prepare')
            ->willReturn($mockStatement);

        $_COOKIE['refresh_token'] = $token;

        $result = $this->validator->validateRefreshToken();

        $this->assertNotNull($result);
        $this->assertSame('refresh', $result['type']);
    }

    /**
     * Test validateRefreshToken returns different tokens without cross-contamination
     */
    public function testMultipleRefreshTokensAreValidatedIndependently(): void
    {
        $payload1 = ['sub' => 'user123'];
        $payload2 = ['sub' => 'user456'];

        $token1 = $this->jwtParser->create($payload1, 604800, 'refresh');
        $token2 = $this->jwtParser->create($payload2, 604800, 'refresh');

        // Parse to get jti for token1
        $claims1 = $this->jwtParser->parse($token1);
        $claims2 = $this->jwtParser->parse($token2);

        // Track state across calls
        $revokedJti = $claims1['jti'];
        $lastJti = null;

        // Mock the revoked_tokens query with side effects based on jti
        $mockDb = $this->createMock(PDO::class);
        $mockStatement = $this->createMock(PDOStatement::class);
        $mockStatement->method('execute')
            ->willReturnCallback(function ($params) use (&$lastJti) {
                $lastJti = $params[0];
                return true;
            });
        $mockStatement->method('rowCount')
            ->willReturnCallback(function () use (&$lastJti, $revokedJti) {
                // Return 1 (revoked) for token1, 0 (not revoked) for token2
                return ($lastJti === $revokedJti) ? 1 : 0;
            });

        $mockDb->method('prepare')
            ->willReturn($mockStatement);

        $validator = new TokenValidator($this->jwtParser, $mockDb);

        // Token1 should be rejected
        $_COOKIE['refresh_token'] = $token1;
        $result1 = $validator->validateRefreshToken();
        $this->assertNull($result1);

        // Token2 should be accepted
        $_COOKIE['refresh_token'] = $token2;
        $result2 = $validator->validateRefreshToken();
        $this->assertNotNull($result2);
        $this->assertSame('user456', $result2['sub']);
    }

    /**
     * Test validateAccessToken preserves complex payload structures
     */
    public function testValidateAccessTokenPreservesComplexPayload(): void
    {
        $payload = [
            'sub' => 'user123',
            'email' => 'user@example.com',
            'roles' => ['user', 'admin'],
            'permissions' => ['read', 'write'],
            'metadata' => [
                'firstName' => 'John',
                'lastName' => 'Doe'
            ]
        ];

        $token = $this->jwtParser->create($payload, 3600, 'access');
        $_COOKIE['access_token'] = $token;

        $result = $this->validator->validateAccessToken();

        $this->assertNotNull($result);
        $this->assertSame('user123', $result['sub']);
        $this->assertSame(['user', 'admin'], $result['roles']);
        $this->assertSame('John', $result['metadata']['firstName']);
    }

    /**
     * Test validateRefreshToken preserves complex payload structures
     */
    public function testValidateRefreshTokenPreservesComplexPayload(): void
    {
        $payload = [
            'sub' => 'user123',
            'email' => 'user@example.com',
            'roles' => ['user', 'admin'],
            'permissions' => ['read', 'write'],
            'metadata' => [
                'firstName' => 'John',
                'lastName' => 'Doe'
            ]
        ];

        $token = $this->jwtParser->create($payload, 604800, 'refresh');
        $_COOKIE['refresh_token'] = $token;

        $result = $this->validator->validateRefreshToken();

        $this->assertNotNull($result);
        $this->assertSame('user123', $result['sub']);
        $this->assertSame(['user', 'admin'], $result['roles']);
        $this->assertSame('John', $result['metadata']['firstName']);
    }
}
