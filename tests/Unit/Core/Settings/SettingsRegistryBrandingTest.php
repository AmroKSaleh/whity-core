<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Settings;

use PHPUnit\Framework\TestCase;
use Whity\Core\Settings\SettingsRegistry;

final class SettingsRegistryBrandingTest extends TestCase
{
    public function testBrandingAssetKeysAreKnown(): void
    {
        self::assertTrue(SettingsRegistry::isKnown('branding_logo_wide'));
        self::assertTrue(SettingsRegistry::isKnown('branding_logo_square'));
        self::assertTrue(SettingsRegistry::isKnown('branding_favicon'));
    }

    public function testKindForDistinguishesTextAndAsset(): void
    {
        self::assertSame('text', SettingsRegistry::kindFor('site_name'));
        self::assertSame('asset', SettingsRegistry::kindFor('branding_logo_wide'));
        self::assertSame('asset', SettingsRegistry::kindFor('branding_favicon'));
    }

    public function testAssetKeysDefaultToEmpty(): void
    {
        self::assertSame('', SettingsRegistry::defaultFor('branding_logo_square'));
    }

    public function testAssetKeysRejectTextValidation(): void
    {
        // Asset keys are not writable via the text PATCH path.
        self::assertNotNull(SettingsRegistry::validate('branding_logo_wide', 'anything'));
    }

    public function testDescribeReportsAssetType(): void
    {
        $byKey = [];
        foreach (SettingsRegistry::describe() as $d) {
            $byKey[$d['key']] = $d['type'];
        }
        self::assertSame('asset', $byKey['branding_favicon']);
        self::assertSame('string', $byKey['site_name']);
    }
}
