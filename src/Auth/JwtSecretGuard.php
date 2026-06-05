<?php

declare(strict_types=1);

namespace Whity\Auth;

/**
 * Validates the strength/presence of the JWT signing secret at boot.
 *
 * Outside the development environment the JWT secret must be present and at least
 * {@see self::MIN_SECRET_LENGTH} characters long; a short or missing secret is
 * brute-forceable and the application must refuse to start. Development is
 * unaffected so local setups can rely on a throwaway fallback.
 */
final class JwtSecretGuard
{
    /** Minimum acceptable secret length (characters) outside development. */
    public const MIN_SECRET_LENGTH = 32;

    /**
     * Assert the JWT secret is acceptable for the given environment.
     *
     * @param string|null $secret The configured JWT_SECRET value (null when unset).
     * @param string      $appEnv The active APP_ENV (e.g. 'development', 'production').
     * @return void
     * @throws \RuntimeException If a non-development secret is missing or too short.
     */
    public static function assertValid(?string $secret, string $appEnv): void
    {
        if ($appEnv === 'development') {
            return;
        }

        if ($secret === null || $secret === '') {
            throw new \RuntimeException(
                'JWT_SECRET environment variable must be set in production environments'
            );
        }

        if (strlen($secret) < self::MIN_SECRET_LENGTH) {
            throw new \RuntimeException(
                'JWT_SECRET must be at least ' . self::MIN_SECRET_LENGTH
                . ' characters in production environments'
            );
        }
    }
}
