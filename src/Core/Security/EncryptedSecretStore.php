<?php

declare(strict_types=1);

namespace Whity\Core\Security;

use Defuse\Crypto\Crypto;
use Defuse\Crypto\Exception\CryptoException;

/**
 * Key-rotation-aware encrypted-secret store (WC-20b7).
 *
 * A reusable primitive for encrypting secrets at rest — IdP client secrets,
 * OAuth access/refresh tokens, webhook signing secrets — with authenticated
 * encryption (defuse/php-encryption, AES-256-CTR + HMAC, Encrypt-then-MAC), the
 * same library {@see \Whity\Auth\TotpService} uses for TOTP secrets. Generalizes
 * that per-feature helper into a shared service.
 *
 * KEY ROTATION: each ciphertext is prefixed with the id of the key it was
 * encrypted under — `"<keyId>:<hex-ciphertext>"`. {@see encrypt()} always uses
 * the CURRENT key; {@see decrypt()} looks up the key named in the prefix, so a
 * store configured with the new current key PLUS the retired old key(s) keeps
 * decrypting existing ciphertext while writing new ciphertext under the new key.
 * {@see isEncryptedWithCurrentKey()} lets a caller lazily re-encrypt on read.
 *
 * The store is stateless crypto — it does NOT persist anything. Callers store
 * the returned ciphertext in their own (tenant-owned) columns, where tenant
 * isolation is enforced by the usual tenant_id predicate.
 */
final class EncryptedSecretStore
{
    /** Key ids are constrained so the "<keyId>:" prefix parses unambiguously. */
    private const KEY_ID_PATTERN = '/^[A-Za-z0-9_]+$/';

    /**
     * @param array<string, string> $keys         Map of key id => key material (current + retired).
     * @param string                $currentKeyId The id (a key of $keys) used for new encryption.
     */
    public function __construct(
        private readonly array $keys,
        private readonly string $currentKeyId,
    ) {
        if ($this->currentKeyId === '' || preg_match(self::KEY_ID_PATTERN, $this->currentKeyId) !== 1) {
            throw new \InvalidArgumentException('EncryptedSecretStore: key id must be non-empty [A-Za-z0-9_].');
        }
        $current = $this->keys[$this->currentKeyId] ?? '';
        if ($current === '') {
            throw new \InvalidArgumentException('EncryptedSecretStore: the current key is missing or empty.');
        }
    }

    /**
     * Build from the environment.
     *
     * Reads the current key from `ENCRYPTION_KEY` (the platform key, shared with
     * TOTP) under the id `ENCRYPTION_KEY_ID` (default `v1`). Retired keys, present
     * only during a rotation window, are read from `ENCRYPTION_KEYS_RETIRED` as a
     * comma-separated `id=key` list — those keys can DECRYPT but are never used to
     * encrypt.
     *
     * @param array<string, mixed> $env Environment map (e.g. $_ENV).
     */
    public static function fromEnv(array $env): self
    {
        $currentKey = (string) ($env['ENCRYPTION_KEY'] ?? '');
        $currentKeyId = (string) ($env['ENCRYPTION_KEY_ID'] ?? '');
        if ($currentKeyId === '') {
            $currentKeyId = 'v1';
        }

        $keys = [$currentKeyId => $currentKey];

        $retired = trim((string) ($env['ENCRYPTION_KEYS_RETIRED'] ?? ''));
        if ($retired !== '') {
            foreach (explode(',', $retired) as $pair) {
                $pair = trim($pair);
                if ($pair === '') {
                    continue;
                }
                $eq = strpos($pair, '=');
                if ($eq === false) {
                    continue;
                }
                $id = trim(substr($pair, 0, $eq));
                $key = substr($pair, $eq + 1);
                // Never let a retired entry silently clobber the current key.
                if ($id !== '' && $id !== $currentKeyId && preg_match(self::KEY_ID_PATTERN, $id) === 1) {
                    $keys[$id] = $key;
                }
            }
        }

        return new self($keys, $currentKeyId);
    }

    /**
     * Encrypt a plaintext secret under the current key.
     *
     * @return string `"<currentKeyId>:<hex-ciphertext>"` — safe to store as text.
     */
    public function encrypt(string $plaintext): string
    {
        $ciphertext = Crypto::encryptWithPassword($plaintext, $this->keys[$this->currentKeyId]);

        return $this->currentKeyId . ':' . $ciphertext;
    }

    /**
     * Decrypt a value produced by {@see encrypt()}.
     *
     * @throws \RuntimeException On a malformed value, an unknown key id, or a
     *                           failed authentication (tampered/corrupt ciphertext).
     */
    public function decrypt(string $stored): string
    {
        $colon = strpos($stored, ':');
        if ($colon === false) {
            throw new \RuntimeException('EncryptedSecretStore: malformed ciphertext (no key id prefix).');
        }

        $keyId = substr($stored, 0, $colon);
        $ciphertext = substr($stored, $colon + 1);

        $key = $this->keys[$keyId] ?? null;
        if ($key === null || $key === '') {
            throw new \RuntimeException('EncryptedSecretStore: no key available for key id "' . $keyId . '".');
        }

        try {
            return Crypto::decryptWithPassword($ciphertext, $key);
        } catch (CryptoException $e) {
            // Never leak the underlying crypto detail to callers/clients.
            throw new \RuntimeException('EncryptedSecretStore: failed to decrypt secret.', 0, $e);
        }
    }

    /**
     * Whether $stored was encrypted under the CURRENT key. When false, the caller
     * may re-encrypt (read the plaintext via {@see decrypt()} then {@see encrypt()})
     * to complete a key rotation lazily.
     */
    public function isEncryptedWithCurrentKey(string $stored): bool
    {
        $colon = strpos($stored, ':');
        if ($colon === false) {
            return false;
        }

        return substr($stored, 0, $colon) === $this->currentKeyId;
    }

    /** The id of the key new secrets are encrypted under. */
    public function currentKeyId(): string
    {
        return $this->currentKeyId;
    }
}
