<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Entitlement;

use PHPUnit\Framework\TestCase;
use Whity\Core\Entitlement\EntitlementRegistry;

/**
 * Unit tests for the {@see EntitlementRegistry} catalogue: key/type/default
 * consistency, validation, normalisation, and typed casting.
 */
final class EntitlementRegistryTest extends TestCase
{
    public function testEveryKeyHasATypeADefaultAndADescription(): void
    {
        foreach (EntitlementRegistry::keys() as $key) {
            self::assertContains(
                EntitlementRegistry::typeFor($key),
                ['bool', 'int'],
                "{$key} must be a known type",
            );
            // defaultFor / describe must not throw for a known key.
            self::assertIsString(EntitlementRegistry::defaultFor($key));
            self::assertNotSame('', EntitlementRegistry::describe($key));
            // The default value must itself validate.
            self::assertNull(
                EntitlementRegistry::validate($key, EntitlementRegistry::defaultFor($key)),
                "{$key}'s own default must be valid",
            );
        }
    }

    public function testKnownAndUnknownKeys(): void
    {
        self::assertTrue(EntitlementRegistry::isKnown(EntitlementRegistry::STORAGE_CUSTOM_BACKEND));
        self::assertFalse(EntitlementRegistry::isKnown('nope.not.a.key'));
    }

    public function testDefaultForUnknownKeyThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        EntitlementRegistry::defaultFor('nope.not.a.key');
    }

    public function testValidateRejectsUnknownKey(): void
    {
        self::assertNotNull(EntitlementRegistry::validate('nope', 'true'));
    }

    public function testBoolEntitlementValidationAndNormalisation(): void
    {
        $key = EntitlementRegistry::SSO_TENANT_IDP; // bool

        foreach (['true', 'false', '1', '0', 'yes', 'NO', ' True '] as $ok) {
            self::assertNull(EntitlementRegistry::validate($key, $ok), "{$ok} should be valid");
        }
        self::assertSame('true', EntitlementRegistry::normalize($key, 'YES'));
        self::assertSame('false', EntitlementRegistry::normalize($key, '0'));
        self::assertNotNull(EntitlementRegistry::validate($key, 'maybe'));

        self::assertTrue(EntitlementRegistry::cast($key, 'true'));
        self::assertFalse(EntitlementRegistry::cast($key, 'false'));
    }

    public function testIntEntitlementValidationAndNormalisation(): void
    {
        $key = EntitlementRegistry::MEMBERS_MAX; // int

        self::assertNull(EntitlementRegistry::validate($key, '25'));
        self::assertNull(EntitlementRegistry::validate($key, '0'));
        self::assertNull(EntitlementRegistry::validate($key, '-1')); // UNLIMITED sentinel
        // Below the -1 sentinel is meaningless.
        self::assertNotNull(EntitlementRegistry::validate($key, '-2'));
        self::assertNotNull(EntitlementRegistry::validate($key, 'lots'));
        self::assertNotNull(EntitlementRegistry::validate($key, '3.5'));

        self::assertSame('25', EntitlementRegistry::normalize($key, ' 25 '));
        self::assertSame(25, EntitlementRegistry::cast($key, '25'));
        self::assertSame(EntitlementRegistry::UNLIMITED, EntitlementRegistry::cast($key, '-1'));
    }

    public function testCastUnknownKeyThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        EntitlementRegistry::cast('nope', '1');
    }
}
