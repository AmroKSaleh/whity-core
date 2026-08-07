<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Security;

use PHPUnit\Framework\TestCase;
use Whity\Core\Security\EncryptionKeyGuard;

/**
 * Tests for the ENCRYPTION_KEY strength/presence guard (WC-security-audit).
 *
 * Mirrors {@see \Tests\Auth\JwtSecretGuardTest}: before this guard existed,
 * ENCRYPTION_KEY's only enforced rule was "non-empty outside development" —
 * the >= 32 char convention was documented in .env.example but never actually
 * checked, unlike the equivalent JWT_SECRET / RENDER_SHARED_SECRET guards.
 */
final class EncryptionKeyGuardTest extends TestCase
{
    public function testShortKeyInProductionThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('at least 32 characters');

        EncryptionKeyGuard::assertValid('too-short', 'production');
    }

    public function testMissingKeyInProductionThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('must be set');

        EncryptionKeyGuard::assertValid(null, 'production');
    }

    public function testEmptyKeyInProductionThrows(): void
    {
        $this->expectException(\RuntimeException::class);

        EncryptionKeyGuard::assertValid('', 'production');
    }

    public function testLongKeyInProductionIsAccepted(): void
    {
        // Exactly 32 characters is the boundary and must be accepted.
        $key = str_repeat('a', EncryptionKeyGuard::MIN_KEY_LENGTH);

        EncryptionKeyGuard::assertValid($key, 'production');

        $this->assertSame(32, EncryptionKeyGuard::MIN_KEY_LENGTH);
    }

    public function testJustUnderBoundaryInProductionThrows(): void
    {
        $key = str_repeat('a', EncryptionKeyGuard::MIN_KEY_LENGTH - 1);

        $this->expectException(\RuntimeException::class);

        EncryptionKeyGuard::assertValid($key, 'production');
    }

    public function testShortKeyInDevelopmentIsAccepted(): void
    {
        EncryptionKeyGuard::assertValid('dev_secret', 'development');

        $this->expectNotToPerformAssertions();
    }

    public function testMissingKeyInDevelopmentIsAccepted(): void
    {
        EncryptionKeyGuard::assertValid(null, 'development');

        $this->expectNotToPerformAssertions();
    }

    public function testNonDevelopmentEnvOtherThanProductionIsGuarded(): void
    {
        // Any env that is not 'development' (e.g. 'staging') is guarded.
        $this->expectException(\RuntimeException::class);

        EncryptionKeyGuard::assertValid('short', 'staging');
    }
}
