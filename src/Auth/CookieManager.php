<?php

declare(strict_types=1);

namespace Whity\Auth;

/**
 * Static helper class for secure cookie management
 *
 * Provides methods to set and clear JWT tokens in HTTP-only cookies. Cookies are
 * issued with HttpOnly and SameSite=Lax always, plus the Secure flag whenever
 * APP_ENV is not 'development' (WC-160). Development omits Secure so cookies
 * work over localhost HTTP; every other (or unset) environment is treated as
 * HTTPS-served and gets Secure — fail safe.
 *
 * Token cookies are stored separately with different expiration times:
 * - access_token: 15 minutes (Path=/api)
 * - refresh_token: 7 days (Path=/api)
 */
class CookieManager
{
    /** Flags every auth cookie always carries. */
    private const BASE_FLAGS = '; HttpOnly; SameSite=Lax';

    /**
     * Build a Set-Cookie header value with the environment-appropriate flags.
     *
     * Always emits HttpOnly and SameSite=Lax; appends Secure unless
     * APP_ENV === 'development' (an unset APP_ENV counts as non-development,
     * so misconfigured deployments still mark cookies Secure).
     *
     * @param string $name Cookie name.
     * @param string $value Cookie value (empty string when clearing).
     * @param int $maxAge Max-Age in seconds (0 clears the cookie).
     * @param string $path Cookie Path attribute.
     * @return string The full Set-Cookie header value.
     */
    public static function buildCookieHeader(string $name, string $value, int $maxAge, string $path): string
    {
        $header = sprintf('%s=%s; Max-Age=%d; Path=%s%s', $name, $value, $maxAge, $path, self::BASE_FLAGS);

        if (($_ENV['APP_ENV'] ?? 'production') !== 'development') {
            $header .= '; Secure';
        }

        return $header;
    }

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
        header('Set-Cookie: ' . self::buildCookieHeader('access_token', $token, $expirySeconds, '/api'), false);
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
        header('Set-Cookie: ' . self::buildCookieHeader('refresh_token', $token, $expirySeconds, '/api'), false);
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
        header('Set-Cookie: ' . self::buildCookieHeader('access_token', '', 0, '/api'), false);
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
        header('Set-Cookie: ' . self::buildCookieHeader('refresh_token', '', 0, '/api'), false);
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
        header('Set-Cookie: ' . self::buildCookieHeader('temp_auth_token', $token, $expiresIn, '/api'), false);
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
        header('Set-Cookie: ' . self::buildCookieHeader('temp_auth_token', '', 0, '/api'), false);
    }

    /**
     * Set the tenant-selection token cookie (ADR 0005 §6).
     *
     * Issued by login when a profile has MULTIPLE active memberships and the user
     * must choose a tenant. Short-lived (default 5 min) and path-restricted to
     * /api like the 2FA temp token; it binds the login and the subsequent
     * POST /api/auth/select-tenant so a caller can only complete the login they
     * started (and only into a tenant they belong to — re-validated server-side).
     *
     * @param string $token     JWT of type 'tenant_select'.
     * @param int    $expiresIn Expiration in seconds (default 300).
     */
    public static function setTenantSelectionToken(string $token, int $expiresIn = 300): void
    {
        header('Set-Cookie: ' . self::buildCookieHeader('tenant_select_token', $token, $expiresIn, '/api'), false);
    }

    /** Get the tenant-selection token from cookies, or null if absent. */
    public static function getTenantSelectionToken(): ?string
    {
        return $_COOKIE['tenant_select_token'] ?? null;
    }

    /** Clear the tenant-selection token cookie (same Path as the setter). */
    public static function clearTenantSelectionToken(): void
    {
        header('Set-Cookie: ' . self::buildCookieHeader('tenant_select_token', '', 0, '/api'), false);
    }
}
