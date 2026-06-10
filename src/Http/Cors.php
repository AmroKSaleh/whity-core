<?php

declare(strict_types=1);

namespace Whity\Http;

/**
 * Centralized CORS header policy.
 *
 * Replaces the previously duplicated wildcard ("*") CORS headers. The request
 * Origin is reflected back ONLY when it appears in the configured allowlist,
 * alongside Access-Control-Allow-Credentials: true (required by the httpOnly
 * cookie auth model). A wildcard is never emitted together with credentials.
 *
 * The allowlist is read from the CORS_ALLOWED_ORIGINS environment variable as a
 * comma-separated list. When unset, a development-friendly default of
 * http://localhost:3000 is used.
 */
final class Cors
{
    private const DEFAULT_ALLOWED_ORIGINS = 'http://localhost:3000';
    private const ALLOWED_METHODS = 'GET, POST, PUT, PATCH, DELETE, OPTIONS';
    // X-Requested-With is the CSRF defense header required on the auth POSTs
    // (WC-160); it must be preflight-approved for credentialed CORS clients.
    private const ALLOWED_HEADERS = 'Content-Type, Authorization, X-Requested-With';

    /**
     * Build the CORS response headers for a given request Origin.
     *
     * Always emits the allowed methods/headers. The Origin and credentials
     * headers are only added when the supplied Origin matches the allowlist,
     * so a non-allowlisted (or absent) Origin is never reflected.
     *
     * @param string|null  $origin         The request's Origin header, if any.
     * @param list<string>|null $allowedOrigins Explicit allowlist; when null it is
     *                                          read from CORS_ALLOWED_ORIGINS.
     * @return array<string, string> CORS headers to merge into the response.
     */
    public static function headers(?string $origin, ?array $allowedOrigins = null): array
    {
        $headers = [
            'Access-Control-Allow-Methods' => self::ALLOWED_METHODS,
            'Access-Control-Allow-Headers' => self::ALLOWED_HEADERS,
        ];

        $allowlist = $allowedOrigins ?? self::allowedOriginsFromEnv();

        if ($origin !== null && $origin !== '' && in_array($origin, $allowlist, true)) {
            $headers['Access-Control-Allow-Origin'] = $origin;
            $headers['Access-Control-Allow-Credentials'] = 'true';
            // Caches must vary on Origin since the response differs per origin.
            $headers['Vary'] = 'Origin';
        }

        return $headers;
    }

    /**
     * Parse the CORS_ALLOWED_ORIGINS environment variable into a list.
     *
     * @return list<string> Trimmed, non-empty allowed origins.
     */
    public static function allowedOriginsFromEnv(): array
    {
        $raw = $_ENV['CORS_ALLOWED_ORIGINS'] ?? getenv('CORS_ALLOWED_ORIGINS');
        if (!is_string($raw) || trim($raw) === '') {
            $raw = self::DEFAULT_ALLOWED_ORIGINS;
        }

        $origins = [];
        foreach (explode(',', $raw) as $origin) {
            $origin = trim($origin);
            if ($origin !== '') {
                $origins[] = $origin;
            }
        }

        return $origins;
    }
}
