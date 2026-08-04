<?php

declare(strict_types=1);

namespace Whity\Core\Security;

/**
 * Validates the strength/presence of the platform's at-rest `ENCRYPTION_KEY`
 * (WC-security-audit).
 *
 * Mirrors {@see \Whity\Auth\JwtSecretGuard}: outside development the key must
 * be present and at least {@see self::MIN_KEY_LENGTH} characters — the same
 * "≥32-char secret" convention already enforced in code for `JWT_SECRET`
 * ({@see \Whity\Auth\JwtSecretGuard}) and `RENDER_SHARED_SECRET`
 * (`render-service/src/server.js`), and already DOCUMENTED for
 * `ENCRYPTION_KEY` itself in `.env.example` ("must be >= 32 chars ... outside
 * APP_ENV=development") — but, before this class, never actually checked in
 * code. `Crypto::encryptWithPassword()` (defuse/php-encryption) stretches
 * whatever passphrase it is given via PBKDF2, but stretching a short,
 * low-entropy passphrase is still far weaker than requiring real entropy up
 * front: the previous check only rejected an EMPTY key, so an operator could
 * run production with a trivially guessable passphrase protecting every
 * stored TOTP secret, IdP client secret, and OAuth/webhook token.
 *
 * Shared by both consumers of `ENCRYPTION_KEY` so neither can silently
 * diverge: {@see \Whity\Auth\TotpService::resolveEncryptionKey()} (2FA
 * secrets) and {@see EncryptedSecretStore::fromEnv()} (IdP/webhook/OAuth
 * secrets).
 */
final class EncryptionKeyGuard
{
    /** Minimum acceptable key length (characters) outside development. */
    public const MIN_KEY_LENGTH = 32;

    /**
     * Assert the ENCRYPTION_KEY value is acceptable for the given environment.
     *
     * @param string|null $key    The configured ENCRYPTION_KEY value (null when unset).
     * @param string      $appEnv The active APP_ENV (e.g. 'development', 'production').
     * @throws \RuntimeException If a non-development key is missing or too short.
     */
    public static function assertValid(?string $key, string $appEnv): void
    {
        if ($appEnv === 'development') {
            return;
        }

        if ($key === null || $key === '') {
            throw new \RuntimeException(
                'ENCRYPTION_KEY environment variable must be set in non-development environments'
            );
        }

        if (strlen($key) < self::MIN_KEY_LENGTH) {
            throw new \RuntimeException(
                'ENCRYPTION_KEY must be at least ' . self::MIN_KEY_LENGTH
                . ' characters in non-development environments'
            );
        }
    }
}
