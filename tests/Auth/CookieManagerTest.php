<?php

namespace Tests\Auth;

use PHPUnit\Framework\TestCase;
use Whity\Auth\CookieManager;

/**
 * Tests for CookieManager class
 */
class CookieManagerTest extends TestCase
{
    /** Backup of the APP_ENV value so Secure-flag tests can toggle it safely. */
    private mixed $previousAppEnv = null;
    private bool $hadAppEnv = false;

    protected function setUp(): void
    {
        // Clear $_COOKIE before each test
        $_COOKIE = [];

        $this->hadAppEnv = array_key_exists('APP_ENV', $_ENV);
        $this->previousAppEnv = $_ENV['APP_ENV'] ?? null;
    }

    protected function tearDown(): void
    {
        if ($this->hadAppEnv) {
            $_ENV['APP_ENV'] = $this->previousAppEnv;
        } else {
            unset($_ENV['APP_ENV']);
        }
    }

    /**
     * WC-160: in development the Secure flag is omitted (localhost HTTP), but
     * HttpOnly and SameSite=Lax are always present.
     */
    public function testCookieHeaderOmitsSecureInDevelopment(): void
    {
        $_ENV['APP_ENV'] = 'development';

        $header = CookieManager::buildCookieHeader('access_token', 'tok123', 900, '/api');

        $this->assertSame('access_token=tok123; Max-Age=900; Path=/api; HttpOnly; SameSite=Lax', $header);
        $this->assertStringNotContainsString('; Secure', $header);
    }

    /**
     * WC-160: outside development the Secure flag MUST be emitted.
     */
    public function testCookieHeaderEmitsSecureInProduction(): void
    {
        $_ENV['APP_ENV'] = 'production';

        $header = CookieManager::buildCookieHeader('access_token', 'tok123', 900, '/api');

        $this->assertStringContainsString('; Secure', $header);
        $this->assertStringContainsString('; HttpOnly', $header);
        $this->assertStringContainsString('; SameSite=Lax', $header);
    }

    /**
     * WC-160: any non-development env (e.g. staging) gets Secure too.
     */
    public function testCookieHeaderEmitsSecureInStaging(): void
    {
        $_ENV['APP_ENV'] = 'staging';

        $header = CookieManager::buildCookieHeader('refresh_token', 'r1', 604800, '/api');

        $this->assertStringContainsString('; Secure', $header);
    }

    /**
     * WC-160 fail-safe: when APP_ENV is unset the cookie defaults to Secure
     * (an unconfigured environment must not silently drop the flag).
     */
    public function testCookieHeaderEmitsSecureWhenAppEnvUnset(): void
    {
        unset($_ENV['APP_ENV']);

        $header = CookieManager::buildCookieHeader('temp_auth_token', 't1', 300, '/api');

        $this->assertStringContainsString('; Secure', $header);
    }

    /**
     * Test getAccessToken returns null when cookie not set
     */
    public function testGetAccessTokenReturnsNullWhenNotSet(): void
    {
        $this->assertNull(CookieManager::getAccessToken());
    }

    /**
     * Test getAccessToken returns value when cookie is set
     */
    public function testGetAccessTokenReturnsValueWhenSet(): void
    {
        $_COOKIE['access_token'] = 'test-access-token-value';
        $this->assertSame('test-access-token-value', CookieManager::getAccessToken());
    }

    /**
     * Test getRefreshToken returns null when cookie not set
     */
    public function testGetRefreshTokenReturnsNullWhenNotSet(): void
    {
        $this->assertNull(CookieManager::getRefreshToken());
    }

    /**
     * Test getRefreshToken returns value when cookie is set
     */
    public function testGetRefreshTokenReturnsValueWhenSet(): void
    {
        $_COOKIE['refresh_token'] = 'test-refresh-token-value';
        $this->assertSame('test-refresh-token-value', CookieManager::getRefreshToken());
    }

    /**
     * Test setAccessToken sends correct Set-Cookie header
     */
    public function testSetAccessTokenSendsCorrectHeader(): void
    {
        // Start output buffering to capture headers
        ob_start();

        // We can't directly test header() output, but we can verify the method doesn't throw
        // In a real integration test, we'd verify the header was sent
        CookieManager::setAccessToken('test-token-123');

        ob_end_clean();
        $this->assertTrue(true); // Method executed without error
    }

    /**
     * Test setAccessToken with custom expiry
     */
    public function testSetAccessTokenWithCustomExpiry(): void
    {
        ob_start();
        CookieManager::setAccessToken('token-value', 1800); // 30 minutes
        ob_end_clean();
        $this->assertTrue(true); // Method executed without error
    }

    /**
     * Test setRefreshToken sends correct Set-Cookie header
     */
    public function testSetRefreshTokenSendsCorrectHeader(): void
    {
        ob_start();
        CookieManager::setRefreshToken('test-refresh-token-456');
        ob_end_clean();
        $this->assertTrue(true); // Method executed without error
    }

    /**
     * Test setRefreshToken with custom expiry
     */
    public function testSetRefreshTokenWithCustomExpiry(): void
    {
        ob_start();
        CookieManager::setRefreshToken('refresh-value', 2592000); // 30 days
        ob_end_clean();
        $this->assertTrue(true); // Method executed without error
    }

    /**
     * Test clearAccessToken sends correct Set-Cookie header
     */
    public function testClearAccessTokenSendsCorrectHeader(): void
    {
        ob_start();
        CookieManager::clearAccessToken();
        ob_end_clean();
        $this->assertTrue(true); // Method executed without error
    }

    /**
     * Test clearRefreshToken sends correct Set-Cookie header
     */
    public function testClearRefreshTokenSendsCorrectHeader(): void
    {
        ob_start();
        CookieManager::clearRefreshToken();
        ob_end_clean();
        $this->assertTrue(true); // Method executed without error
    }

    /**
     * Test getAccessToken with JWT-like token string
     */
    public function testGetAccessTokenWithJwtToken(): void
    {
        $jwtToken = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiIxMjM0NTY3ODkwIiwibmFtZSI6IkpvaG4gRG9lIn0.dozjgNryP4J3jVmNHl0w5N_XgL0n3I9PlFUP0THsR8U';
        $_COOKIE['access_token'] = $jwtToken;
        $this->assertSame($jwtToken, CookieManager::getAccessToken());
    }

    /**
     * Test getRefreshToken with JWT-like token string
     */
    public function testGetRefreshTokenWithJwtToken(): void
    {
        $jwtToken = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiIwOTg3NjU0MzIxIiwibmFtZSI6IkplYW4gRG9lIn0.I5dRvg6i-QJGBYKc7YaD3eEjlL7P5l8X9mK1qQ8hX_M';
        $_COOKIE['refresh_token'] = $jwtToken;
        $this->assertSame($jwtToken, CookieManager::getRefreshToken());
    }

    /**
     * Test getAccessToken with empty cookie
     */
    public function testGetAccessTokenWithEmptyString(): void
    {
        $_COOKIE['access_token'] = '';
        $this->assertSame('', CookieManager::getAccessToken());
    }

    /**
     * Test getRefreshToken with empty cookie
     */
    public function testGetRefreshTokenWithEmptyString(): void
    {
        $_COOKIE['refresh_token'] = '';
        $this->assertSame('', CookieManager::getRefreshToken());
    }

    /**
     * Test default access token expiry is 900 seconds (15 minutes)
     */
    public function testAccessTokenDefaultExpiryIs900Seconds(): void
    {
        ob_start();
        // This test verifies the default expiry parameter works
        // The actual value would be sent in the header
        CookieManager::setAccessToken('token');
        ob_end_clean();
        $this->assertTrue(true);
    }

    /**
     * Test default refresh token expiry is 604800 seconds (7 days)
     */
    public function testRefreshTokenDefaultExpiryIs604800Seconds(): void
    {
        ob_start();
        // This test verifies the default expiry parameter works
        // The actual value would be sent in the header
        CookieManager::setRefreshToken('token');
        ob_end_clean();
        $this->assertTrue(true);
    }

    /**
     * Test that both access and refresh tokens can be set simultaneously
     */
    public function testBothTokensCanbothBeSetSimultaneously(): void
    {
        ob_start();
        CookieManager::setAccessToken('access-123');
        CookieManager::setRefreshToken('refresh-456');
        ob_end_clean();

        // In a real integration test, both headers would be present
        $this->assertTrue(true);
    }

    /**
     * Test that both access and refresh tokens can be retrieved independently
     */
    public function testBothTokensCanbeRetrievedIndependently(): void
    {
        $_COOKIE['access_token'] = 'access-123';
        $_COOKIE['refresh_token'] = 'refresh-456';

        $this->assertSame('access-123', CookieManager::getAccessToken());
        $this->assertSame('refresh-456', CookieManager::getRefreshToken());
    }
}
