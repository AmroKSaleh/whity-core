<?php

declare(strict_types=1);

namespace Tests\Unit\Sdk\Auth;

use PHPUnit\Framework\TestCase;
use Whity\Sdk\Auth\ExternalIdentity;

/**
 * Unit tests for the normalized federated-identity value object (WC-7ad4).
 */
final class ExternalIdentityTest extends TestCase
{
    public function testConstructsAndExposesReadonlyFields(): void
    {
        $identity = new ExternalIdentity(
            issuer: 'https://accounts.google.com',
            subject: '1234567890',
            email: 'Alice@Example.com',
            emailVerified: true,
            displayName: 'Alice',
            claims: ['iss' => 'https://accounts.google.com', 'sub' => '1234567890', 'hd' => 'example.com'],
        );

        self::assertSame('https://accounts.google.com', $identity->issuer);
        self::assertSame('1234567890', $identity->subject);
        self::assertSame('Alice@Example.com', $identity->email);
        self::assertTrue($identity->emailVerified);
        self::assertSame('Alice', $identity->displayName);
        self::assertSame('example.com', $identity->claims['hd']);
    }

    public function testHasVerifiedEmailRequiresBothFlagAndNonEmptyAddress(): void
    {
        self::assertTrue(
            (new ExternalIdentity('iss', 'sub', 'a@b.com', true))->hasVerifiedEmail()
        );
        // Verified flag false → not trusted.
        self::assertFalse(
            (new ExternalIdentity('iss', 'sub', 'a@b.com', false))->hasVerifiedEmail()
        );
        // No email → not trusted even if the flag is somehow true.
        self::assertFalse(
            (new ExternalIdentity('iss', 'sub', null, true))->hasVerifiedEmail()
        );
        self::assertFalse(
            (new ExternalIdentity('iss', 'sub', '', true))->hasVerifiedEmail()
        );
    }

    public function testNormalizedEmailLowercasesAndTrims(): void
    {
        self::assertSame(
            'alice@example.com',
            (new ExternalIdentity('iss', 'sub', '  Alice@Example.com '))->normalizedEmail()
        );
        self::assertNull((new ExternalIdentity('iss', 'sub', null))->normalizedEmail());
        self::assertNull((new ExternalIdentity('iss', 'sub', '   '))->normalizedEmail());
    }

    public function testDefaultsAreSafe(): void
    {
        $identity = new ExternalIdentity('iss', 'sub');
        self::assertNull($identity->email);
        self::assertFalse($identity->emailVerified);
        self::assertNull($identity->displayName);
        self::assertSame([], $identity->claims);
        self::assertFalse($identity->hasVerifiedEmail());
    }
}
