<?php

namespace Whity\Auth;

/**
 * Static helper class for secure cookie management
 *
 * Provides methods to set and clear JWT tokens in HTTP-only cookies. Cookies are
 * issued with HttpOnly and SameSite=Lax. The Secure flag is intentionally omitted
 * so cookies work over localhost HTTP during development; production deployments
 * served over HTTPS should add Secure (see SECURE_FLAGS).
 *
 * Token cookies are stored separately with different expiration times:
 * - access_token: 15 minutes (path=/api)
 * - refresh_token: 7 days (path=/api/auth/refresh)
 */
class CookieManager
{
    // Remove Secure flag for development (localhost HTTP). Production should use HTTPS.
    private const SECURE_FLAGS = '; HttpOnly; SameSite=Lax';

    /**
     * Set access token cookie
     *
     * Stores the access token in an HTTP-only cookie with default 15-minute expiration.
     * Cookie path is /api to restrict to API endpoints.
     *
     * @param string $token JWT token value
     * @param int $expirySeconds Expiration time in seconds (default: 900 = 15 minutes)
     * @return void
     */
    public static function setAccessToken(string $token, int $expirySeconds = 900): void
    {
        $cookieHeader = sprintf(
            'access_token=%s; Max-Age=%d; Path=/api%s',
            $token,
            $expirySeconds,
            self::SECURE_FLAGS
        );
        header('Set-Cookie: ' . $cookieHeader, false);
    }

    /**
     * Set refresh token cookie
     *
     * Stores the refresh token in an HTTP-only cookie with default 7-day expiration.
     * Cookie path is /api/auth/refresh to restrict refresh endpoint.
     *
     * @param string $token JWT token value
     * @param int $expirySeconds Expiration time in seconds (default: 604800 = 7 days)
     * @return void
     */
    public static function setRefreshToken(string $token, int $expirySeconds = 604800): void
    {
        $cookieHeader = sprintf(
            'refresh_token=%s; Max-Age=%d; Path=/api%s',
            $token,
            $expirySeconds,
            self::SECURE_FLAGS
        );
        header('Set-Cookie: ' . $cookieHeader, false);
    }

    /**
     * Clear access token cookie
     *
     * Removes the access token cookie by setting Max-Age to 0.
     * Must use same Path as setAccessToken.
     *
     * @return void
     */
    public static function clearAccessToken(): void
    {
        $cookieHeader = sprintf(
            'access_token=; Max-Age=0; Path=/api%s',
            self::SECURE_FLAGS
        );
        header('Set-Cookie: ' . $cookieHeader, false);
    }

    /**
     * Clear refresh token cookie
     *
     * Removes the refresh token cookie by setting Max-Age to 0.
     * Must use same Path as setRefreshToken.
     *
     * @return void
     */
    public static function clearRefreshToken(): void
    {
        $cookieHeader = sprintf(
            'refresh_token=; Max-Age=0; Path=/api%s',
            self::SECURE_FLAGS
        );
        header('Set-Cookie: ' . $cookieHeader, false);
    }

    /**
     * Get access token from cookies
     *
     * Retrieves the access token from the $_COOKIE superglobal.
     *
     * @return ?string Access token value, or null if not set
     */
    public static function getAccessToken(): ?string
    {
        return $_COOKIE['access_token'] ?? null;
    }

    /**
     * Get refresh token from cookies
     *
     * Retrieves the refresh token from the $_COOKIE superglobal.
     *
     * @return ?string Refresh token value, or null if not set
     */
    public static function getRefreshToken(): ?string
    {
        return $_COOKIE['refresh_token'] ?? null;
    }

    /**
     * Set temporary authentication token cookie
     *
     * Stores a temporary token used during 2FA login flow.
     * Token has a short expiration (default: 5 minutes).
     * Cookie path is /api to restrict to API endpoints.
     *
     * @param string $token JWT token value
     * @param int $expiresIn Expiration time in seconds (default: 300 = 5 minutes)
     * @return void
     */
    public static function setTempToken(string $token, int $expiresIn = 300): void
    {
        $cookieHeader = sprintf(
            'temp_auth_token=%s; Max-Age=%d; Path=/api%s',
            $token,
            $expiresIn,
            self::SECURE_FLAGS
        );
        header('Set-Cookie: ' . $cookieHeader, false);
    }

    /**
     * Get temporary authentication token from cookies
     *
     * Retrieves the temporary token from the $_COOKIE superglobal.
     * Used during 2FA login flow.
     *
     * @return ?string Temporary token value, or null if not set
     */
    public static function getTempToken(): ?string
    {
        return $_COOKIE['temp_auth_token'] ?? null;
    }

    /**
     * Clear temporary authentication token cookie
     *
     * Removes the temporary token cookie by setting Max-Age to 0.
     * Must use same Path as setTempToken.
     *
     * @return void
     */
    public static function clearTempToken(): void
    {
        $cookieHeader = sprintf(
            'temp_auth_token=; Max-Age=0; Path=/api%s',
            self::SECURE_FLAGS
        );
        header('Set-Cookie: ' . $cookieHeader, false);
    }
}
