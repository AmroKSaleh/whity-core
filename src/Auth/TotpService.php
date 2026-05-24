<?php

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
