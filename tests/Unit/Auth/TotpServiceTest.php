<?php

namespace Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use Whity\Auth\TotpService;

/**
 * Tests for TotpService class
 *
 * Tests TOTP secret generation, encryption/decryption, validation, and QR code URL generation
 */
class TotpServiceTest extends TestCase
{
    private TotpService $totpService;
    private string $encryptionKey = 'test-encryption-key-for-totp';

    protected function setUp(): void
    {
        $this->totpService = new TotpService($this->encryptionKey);
    }

    /**
     * Test generateSecret returns a Base32-encoded string
     */
    public function testGenerateSecretReturnsBase32String(): void
    {
        $secret = $this->totpService->generateSecret();

        $this->assertIsString($secret);
        $this->assertNotEmpty($secret);
        // Base32 string contains only A-Z and 2-7
        $this->assertMatchesRegularExpression('/^[A-Z2-7]+$/', $secret);
    }

    /**
     * Test generateSecret returns unique secrets on each call
     */
    public function testGenerateSecretReturnsUniqueSecrets(): void
    {
        $secret1 = $this->totpService->generateSecret();
        $secret2 = $this->totpService->generateSecret();

        $this->assertNotEquals($secret1, $secret2);
    }

    /**
     * Test encryptSecret and decryptSecret round-trip
     */
    public function testEncryptDecryptRoundTrip(): void
    {
        $originalSecret = $this->totpService->generateSecret();

        $encrypted = $this->totpService->encryptSecret($originalSecret);
        $decrypted = $this->totpService->decryptSecret($encrypted);

        $this->assertSame($originalSecret, $decrypted);
    }

    /**
     * Test encryptSecret produces different output for same input
     */
    public function testEncryptSecretProducesDifferentOutputDueToIv(): void
    {
        $secret = $this->totpService->generateSecret();

        $encrypted1 = $this->totpService->encryptSecret($secret);
        $encrypted2 = $this->totpService->encryptSecret($secret);

        // Due to random IV, encrypted outputs should be different
        $this->assertNotEquals($encrypted1, $encrypted2);

        // But both should decrypt to the same value
        $this->assertSame($secret, $this->totpService->decryptSecret($encrypted1));
        $this->assertSame($secret, $this->totpService->decryptSecret($encrypted2));
    }

    /**
     * Test validateCode accepts valid current TOTP code
     */
    public function testValidateCodeAcceptsValidCode(): void
    {
        $secret = $this->totpService->generateSecret();
        $encrypted = $this->totpService->encryptSecret($secret);

        // Get current TOTP code using the TOTP library
        $totp = \OTPHP\TOTP::create($secret);
        $validCode = $totp->now();

        $this->assertTrue($this->totpService->validateCode($encrypted, $validCode));
    }

    /**
     * Test validateCode rejects invalid code
     */
    public function testValidateCodeRejectsInvalidCode(): void
    {
        $secret = $this->totpService->generateSecret();
        $encrypted = $this->totpService->encryptSecret($secret);

        // Use an obviously invalid code
        $invalidCode = '000000';

        $this->assertFalse($this->totpService->validateCode($encrypted, $invalidCode));
    }

    /**
     * Test validateCode rejects malformed encrypted secret
     */
    public function testValidateCodeRejectsMalformedSecret(): void
    {
        $malformedEncrypted = 'not-a-valid-encrypted-secret';
        $code = '123456';

        $this->assertFalse($this->totpService->validateCode($malformedEncrypted, $code));
    }

    /**
     * Test generateQrCodeUrl returns otpauth URL
     */
    public function testGenerateQrCodeUrlReturnsOtpauthUrl(): void
    {
        $email = 'user@example.com';
        $secret = $this->totpService->generateSecret();

        $url = $this->totpService->generateQrCodeUrl($email, $secret);

        $this->assertStringStartsWith('otpauth://totp/', $url);
        // URL encodes the email, so check for encoded version
        $this->assertStringContainsString(urlencode($email), $url);
        $this->assertStringContainsString($secret, $url);
        $this->assertStringContainsString('Whity', $url);
    }

    /**
     * Test generateQrCodeUrl URL is properly encoded
     */
    public function testGenerateQrCodeUrlProperlyEncoded(): void
    {
        $email = 'user+tag@example.com';
        $secret = $this->totpService->generateSecret();

        $url = $this->totpService->generateQrCodeUrl($email, $secret);

        // The URL should be properly encoded (+ should be encoded as %2B or similar)
        $this->assertIsString($url);
        $this->assertTrue(filter_var($url, FILTER_VALIDATE_URL) !== false);
    }

    /**
     * Test that different encryption keys produce different encrypted secrets
     */
    public function testDifferentKeysProduceDifferentCiphertext(): void
    {
        $service1 = new TotpService('key-1');
        $service2 = new TotpService('key-2');

        $secret = 'SAMPLSECRET123456789';

        $encrypted1 = $service1->encryptSecret($secret);
        $encrypted2 = $service2->encryptSecret($secret);

        // Different keys should produce different ciphertext
        $this->assertNotEquals($encrypted1, $encrypted2);

        // Each key should only decrypt its own ciphertext
        $this->assertSame($secret, $service1->decryptSecret($encrypted1));

        // Service2 should not be able to decrypt service1's encrypted secret
        try {
            $decrypted = $service2->decryptSecret($encrypted1);
            $this->assertNotSame($secret, $decrypted);
        } catch (\Exception $e) {
            // Exception is also acceptable since decryption fails
            $this->assertTrue(true);
        }
    }

    /**
     * Tampering with stored ciphertext MUST be detected (authenticated encryption),
     * not silently decrypted to a corrupted secret.
     *
     * Mutating an early byte of an unauthenticated AES-256-CBC blob lands in the IV
     * region, so OpenSSL decrypts it to a corrupted-but-unsignalled plaintext and never
     * raises — that is the integrity gap (WC-158). Authenticated encryption must reject
     * any modification.
     */
    public function testDecryptRejectsTamperedCiphertext(): void
    {
        $secret = $this->totpService->generateSecret();
        $encrypted = $this->totpService->encryptSecret($secret);

        // Mutate one character near the start of the stored blob (IV/header region).
        $tampered = $encrypted;
        $tampered[5] = $tampered[5] === 'A' ? 'B' : 'A';
        $this->assertNotSame($encrypted, $tampered, 'tamper mutation must change the blob');

        $this->expectException(\Throwable::class);
        $this->totpService->decryptSecret($tampered);
    }

    /**
     * matchedStep() must return the step a valid code matches, and that step
     * must be reproducible: calling it again with the SAME code within the
     * same window returns the SAME step (the anti-replay caller relies on
     * this being stable so it can compare against the stored floor).
     */
    public function testMatchedStepReturnsSameStepForSameCode(): void
    {
        $secret = $this->totpService->generateSecret();
        $encrypted = $this->totpService->encryptSecret($secret);
        $code = \OTPHP\TOTP::create($secret)->now();

        $step1 = $this->totpService->matchedStep($encrypted, $code);
        $step2 = $this->totpService->matchedStep($encrypted, $code);

        $this->assertIsInt($step1);
        $this->assertSame($step1, $step2);
    }

    /**
     * matchedStep() must return null for a code that does not verify.
     */
    public function testMatchedStepReturnsNullForInvalidCode(): void
    {
        $secret = $this->totpService->generateSecret();
        $encrypted = $this->totpService->encryptSecret($secret);

        $this->assertNull($this->totpService->matchedStep($encrypted, '000000'));
    }

    /**
     * matchedStep() must return null for a malformed encrypted secret,
     * mirroring validateCode()'s fail-closed behaviour.
     */
    public function testMatchedStepReturnsNullForMalformedSecret(): void
    {
        $this->assertNull($this->totpService->matchedStep('not-a-valid-encrypted-secret', '123456'));
    }

    /**
     * A code from an ADJACENT step (±1 period, i.e. ±30s clock skew) must
     * still match, and at a DIFFERENT step value than "now" — proving the
     * returned step actually tracks which step matched, not just a fixed
     * current-time value.
     */
    public function testMatchedStepDistinguishesAdjacentSteps(): void
    {
        $secret = $this->totpService->generateSecret();
        $encrypted = $this->totpService->encryptSecret($secret);
        $totp = \OTPHP\TOTP::create($secret);

        $nowStep = $this->totpService->matchedStep($encrypted, $totp->now());
        $futureCode = $totp->at(time() + $totp->getPeriod());
        $futureStep = $this->totpService->matchedStep($encrypted, $futureCode);

        $this->assertIsInt($nowStep);
        $this->assertIsInt($futureStep);
        $this->assertGreaterThan($nowStep, $futureStep);
    }

    /**
     * verifyPlainCode validates a code against a PLAINTEXT secret — used during enrollment
     * confirmation, where the caller still holds the plaintext and must not round-trip it
     * through encrypt()+decrypt() just to validate (WC-158).
     */
    public function testVerifyPlainCodeValidatesAgainstPlaintextSecret(): void
    {
        $secret = $this->totpService->generateSecret();
        $validCode = \OTPHP\TOTP::create($secret)->now();

        $this->assertTrue($this->totpService->verifyPlainCode($secret, $validCode));
        $this->assertFalse($this->totpService->verifyPlainCode($secret, '000000'));
    }

    /**
     * resolveEncryptionKey() (WC-security-audit): outside development, a
     * SHORT (but non-empty) ENCRYPTION_KEY must be rejected, not silently
     * accepted. Before this fix only an EMPTY key was rejected — the >= 32
     * char convention was documented in .env.example but never enforced.
     */
    public function testResolveEncryptionKeyRejectsShortKeyOutsideDevelopment(): void
    {
        $_ENV['APP_ENV'] = 'production';
        $_ENV['ENCRYPTION_KEY'] = 'too-short';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('at least 32 characters');

        try {
            TotpService::resolveEncryptionKey();
        } finally {
            unset($_ENV['APP_ENV'], $_ENV['ENCRYPTION_KEY']);
        }
    }

    /**
     * A key of exactly 32 chars must be accepted outside development.
     */
    public function testResolveEncryptionKeyAcceptsThirtyTwoCharKeyOutsideDevelopment(): void
    {
        $_ENV['APP_ENV'] = 'production';
        $_ENV['ENCRYPTION_KEY'] = str_repeat('k', 32);

        try {
            $this->assertSame(str_repeat('k', 32), TotpService::resolveEncryptionKey());
        } finally {
            unset($_ENV['APP_ENV'], $_ENV['ENCRYPTION_KEY']);
        }
    }

    /**
     * Development must remain unaffected: a short (or the well-known dev
     * default) key is still fine there.
     */
    public function testResolveEncryptionKeyAcceptsShortKeyInDevelopment(): void
    {
        $_ENV['APP_ENV'] = 'development';
        $_ENV['ENCRYPTION_KEY'] = 'short';

        try {
            $this->assertSame('short', TotpService::resolveEncryptionKey());
        } finally {
            unset($_ENV['APP_ENV'], $_ENV['ENCRYPTION_KEY']);
        }
    }
}
