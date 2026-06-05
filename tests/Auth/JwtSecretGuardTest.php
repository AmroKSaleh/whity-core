<?php

declare(strict_types=1);

namespace Tests\Auth;

use PHPUnit\Framework\TestCase;
use Whity\Auth\JwtSecretGuard;

/**
 * Tests for the JWT secret strength/presence guard (WC-53).
 */
class JwtSecretGuardTest extends TestCase
{
    public function testShortSecretInProductionThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('at least 32 characters');

        JwtSecretGuard::assertValid('too-short', 'production');
    }

    public function testMissingSecretInProductionThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('must be set');

        JwtSecretGuard::assertValid(null, 'production');
    }

    public function testEmptySecretInProductionThrows(): void
    {
        $this->expectException(\RuntimeException::class);

        JwtSecretGuard::assertValid('', 'production');
    }

    public function testLongSecretInProductionIsAccepted(): void
    {
        // Exactly 32 characters is the boundary and must be accepted.
        $secret = str_repeat('a', JwtSecretGuard::MIN_SECRET_LENGTH);

        JwtSecretGuard::assertValid($secret, 'production');

        $this->assertSame(32, JwtSecretGuard::MIN_SECRET_LENGTH);
    }

    public function testJustUnderBoundaryInProductionThrows(): void
    {
        $secret = str_repeat('a', JwtSecretGuard::MIN_SECRET_LENGTH - 1);

        $this->expectException(\RuntimeException::class);

        JwtSecretGuard::assertValid($secret, 'production');
    }

    public function testShortSecretInDevelopmentIsAccepted(): void
    {
        // Development must remain unaffected: a short secret is fine.
        JwtSecretGuard::assertValid('dev_secret', 'development');

        $this->expectNotToPerformAssertions();
    }

    public function testMissingSecretInDevelopmentIsAccepted(): void
    {
        JwtSecretGuard::assertValid(null, 'development');

        $this->expectNotToPerformAssertions();
    }

    public function testNonDevelopmentEnvOtherThanProductionIsGuarded(): void
    {
        // Any env that is not 'development' (e.g. 'staging') is guarded.
        $this->expectException(\RuntimeException::class);

        JwtSecretGuard::assertValid('short', 'staging');
    }
}
