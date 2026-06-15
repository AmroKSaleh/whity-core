<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Whity\Core\PasswordPolicy;

/**
 * Unit tests for PasswordPolicy.
 *
 * Confirms the 8-character minimum is enforced consistently and that the error
 * message is safe to surface to end-users.
 */
class PasswordPolicyTest extends TestCase
{
    public function testSevenCharacterPasswordThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/8 characters/i');

        PasswordPolicy::validate('1234567');
    }

    public function testEightCharacterPasswordPasses(): void
    {
        // Must not throw — no assertion needed beyond the absence of an exception.
        PasswordPolicy::validate('12345678');
        $this->addToAssertionCount(1);
    }

    public function testLongerPasswordPasses(): void
    {
        PasswordPolicy::validate('correct-horse-battery-staple');
        $this->addToAssertionCount(1);
    }

    public function testEmptyPasswordThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        PasswordPolicy::validate('');
    }

    public function testMinLengthConstantIsEight(): void
    {
        $this->assertSame(8, PasswordPolicy::MIN_LENGTH);
    }
}
