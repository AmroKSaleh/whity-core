<?php

declare(strict_types=1);

namespace Whity\Auth;

use PDO;

/**
 * One-off data migration: re-encrypt stored TOTP secrets from the legacy unauthenticated
 * AES-256-CBC scheme into the current authenticated-encryption format (WC-158).
 *
 * Idempotent and self-detecting: a row that already decrypts under the current scheme is
 * left untouched; a row that does not is treated as legacy ciphertext, decrypted with the
 * old scheme, and re-stored under the new one. A row that can be read by neither scheme is
 * counted as failed and left as-is (never destroyed).
 *
 * This is a data migration, kept deliberately separate from structural schema migrations.
 */
final class TotpSecretReencryptor
{
    public function __construct(
        private readonly TotpService $totpService,
        private readonly string $encryptionKey,
    ) {
    }

    /**
     * Re-encrypt every legacy TOTP secret in the `profiles` table.
     *
     * @return array{migrated: int, skipped: int, failed: int}
     */
    public function reencrypt(PDO $pdo): array
    {
        $migrated = 0;
        $skipped = 0;
        $failed = 0;

        // @tenant-guard-ignore: profiles is a sanctioned GLOBAL table (ADR 0005 §1); platform-maintenance re-encryption sweep runs across all profiles by design (no tenant context)
        $select = $pdo->query('SELECT id, two_factor_secret FROM profiles WHERE two_factor_secret IS NOT NULL');
        if ($select === false) {
            return ['migrated' => 0, 'skipped' => 0, 'failed' => 0];
        }

        /** @var list<array{id: mixed, two_factor_secret: mixed}> $rows */
        $rows = $select->fetchAll(PDO::FETCH_ASSOC);
        // @tenant-guard-ignore: profiles is a sanctioned GLOBAL table (ADR 0005 §1); PK-targeted write inside the platform-maintenance sweep
        $update = $pdo->prepare('UPDATE profiles SET two_factor_secret = ? WHERE id = ?');

        foreach ($rows as $row) {
            $stored = (string) $row['two_factor_secret'];

            // Already in the current authenticated format → decrypts cleanly → leave it.
            try {
                $this->totpService->decryptSecret($stored);
                $skipped++;
                continue;
            } catch (\Throwable) {
                // Not new-format; fall through to the legacy path.
            }

            $plaintext = $this->legacyDecrypt($stored);
            if ($plaintext === null) {
                $failed++;
                continue;
            }

            $update->execute([$this->totpService->encryptSecret($plaintext), $row['id']]);
            $migrated++;
        }

        return ['migrated' => $migrated, 'skipped' => $skipped, 'failed' => $failed];
    }

    /**
     * Decrypt a secret stored under the legacy unauthenticated AES-256-CBC scheme.
     *
     * @return string|null The plaintext secret, or null if the blob is not legacy ciphertext.
     */
    private function legacyDecrypt(string $encrypted): ?string
    {
        $algorithm = 'aes-256-cbc';
        $key = hash('sha256', $this->encryptionKey, true);
        $ivLength = (int) openssl_cipher_iv_length($algorithm);

        $data = base64_decode($encrypted, true);
        if ($data === false || strlen($data) <= $ivLength) {
            return null;
        }

        $iv = substr($data, 0, $ivLength);
        $ciphertext = substr($data, $ivLength);
        $decrypted = openssl_decrypt($ciphertext, $algorithm, $key, OPENSSL_RAW_DATA, $iv);

        return $decrypted === false ? null : $decrypted;
    }
}
