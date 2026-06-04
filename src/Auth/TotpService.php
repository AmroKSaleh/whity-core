<?php

declare(strict_types=1);

namespace Whity\Auth;

use OTPHP\TOTP;
use ParagonieConstantTime\Encoding;

/**
 * TOTP (Time-based One-Time Password) Service
 *
 * Handles TOTP secret generation, encryption/decryption, validation, and QR code URL generation
 * for two-factor authentication.
 *
 * Uses spomky-labs/otphp for TOTP generation and validation (RFC 6238).
 * Encrypts secrets using AES-256-CBC with random IV for storage.
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
     * Encrypt a TOTP secret using AES-256-CBC
     *
     * Uses random IV for each encryption to prevent pattern analysis.
     *
     * @param string $secret The TOTP secret to encrypt
     * @return string Base64-encoded encrypted secret with IV prepended
     */
    public function encryptSecret(string $secret): string
    {
        $algorithm = 'aes-256-cbc';
        $key = hash('sha256', $this->encryptionKey, true);
        $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length($algorithm));

        $encrypted = openssl_encrypt($secret, $algorithm, $key, OPENSSL_RAW_DATA, $iv);

        // Prepend IV to encrypted data and base64 encode
        return base64_encode($iv . $encrypted);
    }

    /**
     * Decrypt a TOTP secret encrypted with encryptSecret()
     *
     * @param string $encrypted Base64-encoded encrypted secret with IV prepended
     * @return string The decrypted TOTP secret
     * @throws \Exception If decryption fails
     */
    public function decryptSecret(string $encrypted): string
    {
        $algorithm = 'aes-256-cbc';
        $key = hash('sha256', $this->encryptionKey, true);
        $ivLength = openssl_cipher_iv_length($algorithm);

        try {
            $data = base64_decode($encrypted, true);
            if ($data === false) {
                throw new \Exception('Invalid base64 encoding');
            }

            if (strlen($data) < $ivLength) {
                throw new \Exception('Encrypted data too short');
            }

            $iv = substr($data, 0, $ivLength);
            $ciphertext = substr($data, $ivLength);

            $decrypted = openssl_decrypt($ciphertext, $algorithm, $key, OPENSSL_RAW_DATA, $iv);

            if ($decrypted === false) {
                throw new \Exception('Decryption failed');
            }

            return $decrypted;
        } catch (\Exception $e) {
            throw new \Exception('Failed to decrypt secret: ' . $e->getMessage());
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
