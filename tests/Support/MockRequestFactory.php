<?php

namespace Tests\Support;

use Whity\Auth\JwtParser;
use Whity\Core\Request;
use Whity\Core\Tenant\TenantContext;

/**
 * Mock request factory for testing
 *
 * Provides static factory methods for building Request objects with JWT tokens
 * and managing TenantContext state during tests.
 */
class MockRequestFactory
{
    /**
     * Test JWT secret key for token signing
     *
     * WARNING: This is only for testing. Never use this in production.
     */
    private const TEST_JWT_SECRET = 'test-secret-key-do-not-use-in-prod';

    /**
     * Shared JwtParser instance for token operations
     */
    private static ?JwtParser $jwtParser = null;

    /**
     * Build a Request with a Bearer token from JWT payload
     *
     * Creates an HTTP Request with an Authorization header containing a signed JWT token.
     * The JWT is signed using HMAC-SHA256 with the test secret key. The token includes
     * standard claims like exp (expiration).
     *
     * @param string $method HTTP method (GET, POST, PUT, DELETE, etc.)
     * @param string $path Request path (e.g., '/api/users')
     * @param array $jwtPayload JWT payload as array (user_id, tenant_id, email, role, etc.)
     * @param array|null $body Optional request body as array (will be JSON-encoded)
     * @return Request HTTP Request with Bearer token in Authorization header
     */
    public static function withBearerToken(
        string $method,
        string $path,
        array $jwtPayload,
        ?array $body = null
    ): Request {
        $jwtParser = self::getJwtParser();
        $token = $jwtParser->create($jwtPayload);

        $headers = [
            'Authorization' => "Bearer {$token}"
        ];

        $requestBody = $body !== null ? json_encode($body) : '';

        return new Request($method, $path, $headers, $requestBody);
    }

    /**
     * Set the current test tenant and configure TenantContext
     *
     * Resets the TenantContext and then sets the specified tenant ID.
     * This ensures each test starts with a clean state.
     *
     * @param int $tenantId Tenant ID to set
     * @return void
     */
    public static function setTestTenant(int $tenantId): void
    {
        TenantContext::reset();
        TenantContext::setTenantId($tenantId);
    }

    /**
     * Reset TenantContext to initial state
     *
     * Clears the tenant ID and unlocks the context. Call this in test tearDown
     * to clean up state between tests.
     *
     * @return void
     */
    public static function reset(): void
    {
        TenantContext::reset();
    }

    /**
     * Get or create the shared JwtParser instance
     *
     * Uses a lazy-loaded singleton pattern to avoid creating multiple parser instances.
     *
     * @return JwtParser Shared JWT parser instance
     */
    private static function getJwtParser(): JwtParser
    {
        if (self::$jwtParser === null) {
            self::$jwtParser = new JwtParser(self::TEST_JWT_SECRET);
        }
        return self::$jwtParser;
    }
}
