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
}
