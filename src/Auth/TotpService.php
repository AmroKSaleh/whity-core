<?php

declare(strict_types=1);

namespace Whity\Auth;

use Defuse\Crypto\Crypto;
use Defuse\Crypto\Exception\CryptoException;
use OTPHP\TOTP;
use ParagonieConstantTime\Encoding;

/**
 * TOTP (Time-based One-Time Password) Service
 *
 * Handles TOTP secret generation, encryption/decryption, validation, and QR code URL generation
 * for two-factor authentication.
 *
 * Uses spomky-labs/otphp for TOTP generation and validation (RFC 6238).
 * Encrypts secrets with authenticated encryption (defuse/php-encryption) for storage.
 */
class TotpService
{
    /**
     * Development-only fallback for the secret-encryption key.
     *
     * This is the single source of truth for the dev default. Every code path that needs a
     * TotpService MUST derive its key via {@see TotpService::resolveEncryptionKey()} so the
     * store/confirm path and the login-validation path can never diverge (see WC-95). Outside
     * `APP_ENV=development`, a missing/empty ENCRYPTION_KEY fails fast rather than falling back
     * to this guessable value.
     */
    private const DEV_ENCRYPTION_KEY = 'dev_secret';

    private string $encryptionKey;

    /**
     * Constructor
     *
     * @param string $encryptionKey Encryption key for secret storage (will be hashed to 256 bits)
     */
    public function __construct(string $encryptionKey)
    {
        $this->encryptionKey = $encryptionKey;
    }

    /**
     * Resolve the TOTP secret-encryption key from the environment.
     *
     * Single source of truth for the encryption key across every 2FA code path (setup/confirm,
     * login TOTP validation, backup-code/version flows). Mirrors how JWT_SECRET is handled in the
     * application bootstrap: a missing/empty ENCRYPTION_KEY is fatal outside development, and only
     * `APP_ENV=development` may fall back to the well-known dev default.
     *
     * @return string The resolved encryption key.
     * @throws \RuntimeException If ENCRYPTION_KEY is missing/empty in a non-development environment.
     */
    public static function resolveEncryptionKey(): string
    {
        $appEnv = $_ENV['APP_ENV'] ?? 'production';
        $key = $_ENV['ENCRYPTION_KEY'] ?? '';

        if ($key === '') {
            if ($appEnv !== 'development') {
                throw new \RuntimeException(
                    'ENCRYPTION_KEY environment variable must be set in non-development environments'
                );
            }

            return self::DEV_ENCRYPTION_KEY;
        }

        return $key;
    }

    /**
     * Generate a new TOTP secret
     *
     * @return string Base32-encoded TOTP secret (typical length 16-32 chars)
     */
    public function generateSecret(): string
    {
        // TOTP::create generates a new random secret and returns it
        $totp = TOTP::create();
        return $totp->getSecret();
    }

    /**
     * Encrypt a TOTP secret with authenticated encryption.
     *
     * Uses defuse/php-encryption (AES-256-CTR + HMAC-SHA256, Encrypt-then-MAC) with a key
     * derived from the configured passphrase via PBKDF2. A fresh random salt/IV is used per
     * call, and the authentication tag lets {@see decryptSecret()} detect any tampering —
     * closing the integrity gap of the previous unauthenticated AES-256-CBC scheme (WC-158).
     *
     * @param string $secret The TOTP secret to encrypt
     * @return string Opaque authenticated-ciphertext token safe for storage
     */
    public function encryptSecret(string $secret): string
    {
        return Crypto::encryptWithPassword($secret, $this->encryptionKey);
    }

    /**
     * Decrypt a TOTP secret produced by {@see encryptSecret()}.
     *
     * The ciphertext is authenticated: a wrong key, a corrupted blob, or any tampering
     * raises rather than returning a corrupted secret.
     *
     * @param string $encrypted The authenticated-ciphertext token from encryptSecret()
     * @return string The decrypted TOTP secret
     * @throws \RuntimeException If the ciphertext is invalid, tampered, or the key is wrong
     */
    public function decryptSecret(string $encrypted): string
    {
        try {
            return Crypto::decryptWithPassword($encrypted, $this->encryptionKey);
        } catch (CryptoException $e) {
            throw new \RuntimeException('Failed to decrypt TOTP secret', 0, $e);
        }
    }

    /**
     * Validate a TOTP code against an encrypted secret
     *
     * Uses a time window of ±1 steps (±30 seconds) to account for clock skew.
     *
     * @param string $encrypted The encrypted TOTP secret
     * @param string $code The TOTP code to validate (6 digits)
     * @return bool True if the code is valid, false otherwise
     */
    public function validateCode(string $encrypted, string $code): bool
    {
        try {
            $secret = $this->decryptSecret($encrypted);
            $totp = TOTP::create($secret);

            // Verify with ±1 window (current time ± 30 seconds)
            return $totp->verify($code, null, 1);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Validate a TOTP code against a PLAINTEXT secret.
     *
     * Used during enrollment confirmation, where the caller already holds the freshly
     * generated (not-yet-stored) secret. Mirrors {@see validateCode()} but skips the
     * decrypt step, so the secret is never needlessly round-tripped through
     * encrypt()/decrypt() merely to validate a code.
     *
     * @param string $secret The plaintext (Base32) TOTP secret
     * @param string $code The TOTP code to validate (6 digits)
     * @return bool True if the code is valid, false otherwise
     */
    public function verifyPlainCode(string $secret, string $code): bool
    {
        try {
            return TOTP::create($secret)->verify($code, null, 1);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Generate an otpauth:// URL for QR code generation
     *
     * The URL can be encoded as a QR code for easy setup in authenticator apps.
     *
     * @param string $email The user's email address (displayed as label)
     * @param string $secret The TOTP secret (unencrypted)
     * @return string The otpauth:// URL
     */
    public function generateQrCodeUrl(string $email, string $secret): string
    {
        $totp = TOTP::create($secret);
        $totp->setLabel($email);
        $totp->setIssuer('Whity');

        return $totp->getProvisioningUri();
    }
}
