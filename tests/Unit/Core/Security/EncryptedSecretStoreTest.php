<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Security;

use PHPUnit\Framework\TestCase;
use Whity\Core\Security\EncryptedSecretStore;

/**
 * Unit tests for the key-rotation-aware {@see EncryptedSecretStore} (WC-20b7):
 * authenticated round-trip, the "<keyId>:" prefix, rotation (decrypt under a
 * retired key while encrypting under the new one), tamper/unknown-key rejection,
 * and env construction.
 */
final class EncryptedSecretStoreTest extends TestCase
{
    private const KEY_V1 = 'store_key_v1_0123456789abcdef0123456789';
    private const KEY_V2 = 'store_key_v2_abcdef0123456789abcdef0123';

    private function store(): EncryptedSecretStore
    {
        return new EncryptedSecretStore(['v1' => self::KEY_V1], 'v1');
    }

    public function testEncryptDecryptRoundTrip(): void
    {
        $store = $this->store();
        $secret = 'super-secret-oauth-refresh-token';

        $cipher = $store->encrypt($secret);
        self::assertSame($secret, $store->decrypt($cipher));
    }

    public function testCiphertextIsPrefixedNonPlaintextAndNonDeterministic(): void
    {
        $store = $this->store();

        $a = $store->encrypt('same-plaintext');
        $b = $store->encrypt('same-plaintext');

        self::assertStringStartsWith('v1:', $a);
        self::assertStringNotContainsString('same-plaintext', $a);
        // defuse uses a random IV, so two encryptions of the same input differ.
        self::assertNotSame($a, $b);
        self::assertSame('same-plaintext', $store->decrypt($a));
        self::assertSame('same-plaintext', $store->decrypt($b));
    }

    public function testDecryptsRetiredKeyCiphertextAfterRotation(): void
    {
        // Something encrypted under v1 before rotation.
        $legacy = $this->store()->encrypt('legacy-secret');

        // After rotation: current key is v2, v1 is retired-but-present.
        $rotated = new EncryptedSecretStore(['v1' => self::KEY_V1, 'v2' => self::KEY_V2], 'v2');

        // Old ciphertext still decrypts …
        self::assertSame('legacy-secret', $rotated->decrypt($legacy));
        self::assertFalse($rotated->isEncryptedWithCurrentKey($legacy));

        // … and new ciphertext is written under v2.
        $fresh = $rotated->encrypt('new-secret');
        self::assertStringStartsWith('v2:', $fresh);
        self::assertTrue($rotated->isEncryptedWithCurrentKey($fresh));
        self::assertSame('new-secret', $rotated->decrypt($fresh));
    }

    public function testTamperedCiphertextIsRejected(): void
    {
        $store = $this->store();
        $cipher = $store->encrypt('secret');
        // Flip a character in the hex body (after the "v1:" prefix).
        $tampered = substr($cipher, 0, -1) . ($cipher[-1] === 'a' ? 'b' : 'a');

        $this->expectException(\RuntimeException::class);
        $store->decrypt($tampered);
    }

    public function testUnknownKeyIdIsRejected(): void
    {
        $store = $this->store();
        $cipher = $store->encrypt('secret');
        $foreign = 'v9:' . substr($cipher, 3);

        $this->expectException(\RuntimeException::class);
        $store->decrypt($foreign);
    }

    public function testMalformedCiphertextIsRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->store()->decrypt('no-prefix-here');
    }

    public function testConstructorRejectsMissingCurrentKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new EncryptedSecretStore(['v1' => ''], 'v1');
    }

    public function testFromEnvUsesEncryptionKeyAndDefaultId(): void
    {
        $store = EncryptedSecretStore::fromEnv(['ENCRYPTION_KEY' => self::KEY_V1]);
        self::assertSame('v1', $store->currentKeyId());
        self::assertSame('secret', $store->decrypt($store->encrypt('secret')));
    }

    public function testFromEnvSupportsCustomIdAndRetiredKeys(): void
    {
        // Legacy ciphertext under v1.
        $legacy = (new EncryptedSecretStore(['v1' => self::KEY_V1], 'v1'))->encrypt('old');

        $store = EncryptedSecretStore::fromEnv([
            'ENCRYPTION_KEY'          => self::KEY_V2,
            'ENCRYPTION_KEY_ID'       => 'v2',
            'ENCRYPTION_KEYS_RETIRED' => 'v1=' . self::KEY_V1,
        ]);

        self::assertSame('v2', $store->currentKeyId());
        self::assertSame('old', $store->decrypt($legacy), 'retired key still decrypts');
        self::assertStringStartsWith('v2:', $store->encrypt('new'));
    }

    public function testFromEnvIgnoresRetiredEntryCollidingWithCurrentId(): void
    {
        // A retired entry that reuses the current id must not clobber the real key.
        $store = EncryptedSecretStore::fromEnv([
            'ENCRYPTION_KEY'          => self::KEY_V1,
            'ENCRYPTION_KEY_ID'       => 'v1',
            'ENCRYPTION_KEYS_RETIRED' => 'v1=bogus',
        ]);
        self::assertSame('secret', $store->decrypt($store->encrypt('secret')));
    }
}
