<?php

declare(strict_types=1);

namespace Tests\Core\Payment;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Payment\Cliq\CliqSecrets;
use Whity\Core\Security\EncryptedSecretStore;
use Whity\Core\Settings\GlobalSettingsRepository;

/**
 * The rail signing secrets: stored encrypted, read back decrypted, never
 * reachable through the settings API.
 *
 * THIS TEST EXISTS BECAUSE THE FIRST VERSION HAD NONE, and the absence was the
 * bug. `CliqSecrets` originally held two key strings and a docblock claiming
 * they were "encrypted at rest by EncryptedSecretStore". Nothing encrypted
 * them, nothing decrypted them, the wiring read the raw value, and there was no
 * way to set either one. A deployment following that docblock would have stored
 * ciphertext and had every signature check fail; one ignoring it would have run
 * with the secret in the clear. The comment was the only evidence anybody had
 * thought about it, and a comment is not a mechanism.
 */
final class CliqSecretsRealEngineTest extends TestCase
{
    private PDO $pdo;
    private GlobalSettingsRepository $globals;
    private EncryptedSecretStore $secrets;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make();
        $this->globals = new GlobalSettingsRepository($this->pdo);
        $this->secrets = EncryptedSecretStore::fromEnv([
            'APP_ENV' => 'testing',
            'ENCRYPTION_KEY' => str_repeat('k', 32),
            'ENCRYPTION_KEY_ID' => 'v1',
        ]);
    }

    // ── the round trip ───────────────────────────────────────────────────────

    public function testASecretWrittenIsReadBackIntact(): void
    {
        CliqSecrets::write($this->globals, $this->secrets, CliqSecrets::WEBHOOK_SECRET_KEY, 'a-sufficiently-long-secret');

        self::assertSame(
            'a-sufficiently-long-secret',
            CliqSecrets::read($this->globals, $this->secrets, CliqSecrets::WEBHOOK_SECRET_KEY)
        );
    }

    /**
     * THE PROPERTY THE DOCBLOCK USED TO ONLY CLAIM. What lands in the database
     * is not the secret.
     */
    public function testWhatIsStoredIsNotThePlaintext(): void
    {
        CliqSecrets::write($this->globals, $this->secrets, CliqSecrets::WEBHOOK_SECRET_KEY, 'a-sufficiently-long-secret');

        $stored = $this->globals->get(CliqSecrets::WEBHOOK_SECRET_KEY);

        self::assertNotNull($stored);
        self::assertNotSame('a-sufficiently-long-secret', $stored);
        self::assertStringNotContainsString('sufficiently', $stored);
    }

    // ── every failure converges on "refuse" ──────────────────────────────────

    public function testAnUnsetSecretReadsAsEmptySoTheRailRefuses(): void
    {
        self::assertSame('', CliqSecrets::read($this->globals, $this->secrets, CliqSecrets::WEBHOOK_SECRET_KEY));
        self::assertFalse(CliqSecrets::isConfigured($this->globals, $this->secrets, CliqSecrets::WEBHOOK_SECRET_KEY));
    }

    /**
     * A ROTATED-AWAY KEY MUST NOT BECOME AN OPEN DOOR. The safe direction is the
     * rail refusing every callback, never accepting an unverified one — so an
     * undecryptable value reads as absent rather than raising, and the caller's
     * existing "no secret means refuse" path handles it.
     */
    public function testASecretEncryptedWithAKeyWeNoLongerHoldReadsAsEmpty(): void
    {
        $old = EncryptedSecretStore::fromEnv([
            'APP_ENV' => 'testing',
            'ENCRYPTION_KEY' => str_repeat('z', 32),
            'ENCRYPTION_KEY_ID' => 'v0',
        ]);
        CliqSecrets::write($this->globals, $old, CliqSecrets::WEBHOOK_SECRET_KEY, 'a-sufficiently-long-secret');

        // Read with the CURRENT store, which has never seen that key.
        self::assertSame('', CliqSecrets::read($this->globals, $this->secrets, CliqSecrets::WEBHOOK_SECRET_KEY));
    }

    /** Garbage somebody wrote by hand is the same story. */
    public function testAValueThatIsNotCiphertextReadsAsEmpty(): void
    {
        $this->globals->set(CliqSecrets::WEBHOOK_SECRET_KEY, 'plaintext-somebody-pasted');

        self::assertSame('', CliqSecrets::read($this->globals, $this->secrets, CliqSecrets::WEBHOOK_SECRET_KEY));
    }

    // ── clearing, and refusing a weak one ────────────────────────────────────

    /** Clearing is a complete stop, not a half-configured state. */
    public function testClearingRemovesTheSecretEntirely(): void
    {
        CliqSecrets::write($this->globals, $this->secrets, CliqSecrets::WEBHOOK_SECRET_KEY, 'a-sufficiently-long-secret');
        CliqSecrets::write($this->globals, $this->secrets, CliqSecrets::WEBHOOK_SECRET_KEY, '');

        self::assertNull($this->globals->get(CliqSecrets::WEBHOOK_SECRET_KEY));
        self::assertFalse(CliqSecrets::isConfigured($this->globals, $this->secrets, CliqSecrets::WEBHOOK_SECRET_KEY));
    }

    /**
     * HMAC is only as strong as its key, and a memorable one is guessable
     * offline by anybody who has seen a signed payload — which, for a webhook,
     * is anybody who can post to the endpoint and watch what happens.
     */
    public function testAShortSecretIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/at least 16/');

        CliqSecrets::write($this->globals, $this->secrets, CliqSecrets::WEBHOOK_SECRET_KEY, 'hunter2');
    }

    // ── it must stay out of the settings API ─────────────────────────────────

    /**
     * THE REASON THESE ARE NOT REGISTRY KEYS. The settings API iterates the
     * registry and hands values back on GET, so a secret registered there would
     * be readable by anybody holding the settings permission — which is not a
     * secret. If somebody ever adds these to the registry "for consistency",
     * this fails.
     */
    public function testTheSecretKeysAreNotSettingsRegistryKeys(): void
    {
        foreach (CliqSecrets::KEY_FOR_PROVIDER as $provider => $key) {
            self::assertFalse(
                \Whity\Core\Settings\SettingsRegistry::isKnown($key),
                "{$provider}'s signing secret is a SettingsRegistry key, so GET /settings would return it"
            );
        }
    }

    /** Both rails are addressable by name, which is what the CLI resolves. */
    public function testEveryRailWithASecretIsReachableByName(): void
    {
        self::assertSame(CliqSecrets::WEBHOOK_SECRET_KEY, CliqSecrets::KEY_FOR_PROVIDER['cliq']);
        self::assertSame(CliqSecrets::MOCK_SECRET_KEY, CliqSecrets::KEY_FOR_PROVIDER['mock']);
    }
}
