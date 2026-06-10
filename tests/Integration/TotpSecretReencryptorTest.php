<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Whity\Auth\TotpSecretReencryptor;
use Whity\Auth\TotpService;

/**
 * Real-engine (SQLite) test for the WC-158 TOTP-secret re-encryption migration.
 *
 * Verifies that secrets stored under the legacy unauthenticated AES-256-CBC scheme are
 * migrated to the current authenticated-encryption format, that already-migrated and
 * NULL rows are left alone, and that the migration is idempotent.
 */
class TotpSecretReencryptorTest extends TestCase
{
    private const KEY = 'reencrypt-test-key';

    private function pdo(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, two_factor_secret TEXT)');

        return $pdo;
    }

    /** Replicates the OLD unauthenticated AES-256-CBC scheme to seed a legacy row. */
    private function legacyEncrypt(string $secret, string $key): string
    {
        $algorithm = 'aes-256-cbc';
        $derived = hash('sha256', $key, true);
        $iv = openssl_random_pseudo_bytes((int) openssl_cipher_iv_length($algorithm));
        $enc = openssl_encrypt($secret, $algorithm, $derived, OPENSSL_RAW_DATA, $iv);

        return base64_encode($iv . (string) $enc);
    }

    public function testMigratesLegacyRowsSkipsOthersAndIsIdempotent(): void
    {
        $pdo = $this->pdo();
        $totp = new TotpService(self::KEY);

        $legacyPlain = 'JBSWY3DPEHPK3PXP';
        $legacyBlob = $this->legacyEncrypt($legacyPlain, self::KEY);
        $newPlain = 'KRSXG5CTMVRXEZLU';
        $newBlob = $totp->encryptSecret($newPlain);

        $insert = $pdo->prepare('INSERT INTO users (id, two_factor_secret) VALUES (?, ?)');
        $insert->execute([1, $legacyBlob]);   // legacy → must be migrated
        $insert->execute([2, null]);          // no secret → not selected
        $insert->execute([3, $newBlob]);      // already new → skipped

        $reencryptor = new TotpSecretReencryptor($totp, self::KEY);
        $stats = $reencryptor->reencrypt($pdo);

        $this->assertSame(1, $stats['migrated']);
        $this->assertSame(1, $stats['skipped']);
        $this->assertSame(0, $stats['failed']);

        // User 1 is now stored in the new authenticated format and decrypts to the original.
        $row1 = (string) $pdo->query('SELECT two_factor_secret FROM users WHERE id = 1')->fetchColumn();
        $this->assertNotSame($legacyBlob, $row1);
        $this->assertSame($legacyPlain, $totp->decryptSecret($row1));

        // User 3 (already migrated) is untouched.
        $row3 = (string) $pdo->query('SELECT two_factor_secret FROM users WHERE id = 3')->fetchColumn();
        $this->assertSame($newBlob, $row3);

        // Idempotent: a second run migrates nothing.
        $stats2 = $reencryptor->reencrypt($pdo);
        $this->assertSame(0, $stats2['migrated']);
        $this->assertSame(2, $stats2['skipped']);
    }
}
