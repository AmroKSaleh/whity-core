<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Settings;

use PHPUnit\Framework\TestCase;
use Whity\Core\Settings\SettingsRegistry;

/**
 * Unit tests for {@see SettingsRegistry} (Website Settings).
 *
 * Pins the known-key set, the per-field validation contract (valid + invalid
 * per field), unknown-key rejection, and the hardcoded fallback defaults from
 * the design.
 */
final class SettingsRegistryTest extends TestCase
{
    public function testKnownKeysAreExactlyTheDesignedFields(): void
    {
        self::assertSame(
            ['site_name', 'timezone', 'locale', 'support_email',
             'branding_logo_wide', 'branding_logo_square', 'branding_favicon',
             'mcp.enabled',
             'auth.self_registration_enabled', 'auth.registration_approval_required'],
            SettingsRegistry::keys()
        );
    }

    public function testIsKnownRejectsUnknownKeys(): void
    {
        self::assertTrue(SettingsRegistry::isKnown('site_name'));
        self::assertFalse(SettingsRegistry::isKnown('not_a_setting'));
    }

    public function testDefaultsMatchTheDesign(): void
    {
        self::assertSame('Whity', SettingsRegistry::defaultFor('site_name'));
        self::assertSame('UTC', SettingsRegistry::defaultFor('timezone'));
        self::assertSame('en', SettingsRegistry::defaultFor('locale'));
        self::assertSame('', SettingsRegistry::defaultFor('support_email'));
    }

    public function testDefaultForUnknownKeyThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SettingsRegistry::defaultFor('nope');
    }

    public function testValidateRejectsUnknownKey(): void
    {
        self::assertNotNull(SettingsRegistry::validate('nope', 'x'));
    }

    // ---- site_name ----

    public function testSiteNameAcceptsNonEmptyWithinLimit(): void
    {
        self::assertNull(SettingsRegistry::validate('site_name', 'Acme'));
        self::assertNull(SettingsRegistry::validate('site_name', str_repeat('a', 120)));
    }

    public function testSiteNameRejectsEmptyOrWhitespace(): void
    {
        self::assertNotNull(SettingsRegistry::validate('site_name', ''));
        self::assertNotNull(SettingsRegistry::validate('site_name', '   '));
    }

    public function testSiteNameRejectsOverLimit(): void
    {
        self::assertNotNull(SettingsRegistry::validate('site_name', str_repeat('a', 121)));
    }

    public function testSiteNameAcceptsValueWhoseTrimmedFormIsWithinLimit(): void
    {
        // 120 real chars wrapped in whitespace: the limit applies to the trimmed
        // value (what gets stored), so this is valid even though the raw string
        // is longer than 120.
        self::assertNull(SettingsRegistry::validate('site_name', '  ' . str_repeat('a', 120) . '  '));
        // But 121 real chars (post-trim) is still over the limit.
        self::assertNotNull(SettingsRegistry::validate('site_name', '  ' . str_repeat('a', 121) . '  '));
    }

    public function testNormalizeTrimsSiteName(): void
    {
        self::assertSame('Acme', SettingsRegistry::normalize('site_name', ' Acme '));
        self::assertSame('Acme Co', SettingsRegistry::normalize('site_name', "\tAcme Co\n"));
        // Internal whitespace is preserved; only the surrounding whitespace goes.
        self::assertSame('Acme', SettingsRegistry::normalize('site_name', 'Acme'));
    }

    public function testNormalizeLeavesOtherKeysVerbatim(): void
    {
        self::assertSame('Europe/Berlin', SettingsRegistry::normalize('timezone', 'Europe/Berlin'));
        self::assertSame('en-US', SettingsRegistry::normalize('locale', 'en-US'));
        self::assertSame('help@example.com', SettingsRegistry::normalize('support_email', 'help@example.com'));
    }

    // ---- timezone ----

    public function testTimezoneAcceptsValidIana(): void
    {
        self::assertNull(SettingsRegistry::validate('timezone', 'UTC'));
        self::assertNull(SettingsRegistry::validate('timezone', 'Europe/Berlin'));
    }

    public function testTimezoneRejectsInvalid(): void
    {
        self::assertNotNull(SettingsRegistry::validate('timezone', 'Mars/Phobos'));
        self::assertNotNull(SettingsRegistry::validate('timezone', ''));
    }

    // ---- locale ----

    public function testLocaleAcceptsShortAndRegioned(): void
    {
        self::assertNull(SettingsRegistry::validate('locale', 'en'));
        self::assertNull(SettingsRegistry::validate('locale', 'en-US'));
    }

    public function testLocaleRejectsMalformed(): void
    {
        self::assertNotNull(SettingsRegistry::validate('locale', 'english'));
        self::assertNotNull(SettingsRegistry::validate('locale', 'EN'));
        self::assertNotNull(SettingsRegistry::validate('locale', 'en_US'));
        self::assertNotNull(SettingsRegistry::validate('locale', 'en-us'));
    }

    // ---- support_email ----

    public function testSupportEmailAcceptsValidOrEmpty(): void
    {
        self::assertNull(SettingsRegistry::validate('support_email', ''));
        self::assertNull(SettingsRegistry::validate('support_email', 'help@example.com'));
    }

    public function testSupportEmailRejectsInvalid(): void
    {
        self::assertNotNull(SettingsRegistry::validate('support_email', 'not-an-email'));
    }

    public function testDescribePublishesKeyTypeAndDefault(): void
    {
        $describe = SettingsRegistry::describe();
        self::assertCount(10, $describe);
        self::assertSame(
            ['key' => 'site_name', 'type' => 'string', 'default' => 'Whity'],
            $describe[0]
        );
    }

    // ---- mcp.enabled (WC-149b2fc9) ----

    public function testMcpEnabledDefaultIsFalse(): void
    {
        self::assertSame('false', SettingsRegistry::defaultFor('mcp.enabled'));
    }

    public function testMcpEnabledAcceptsTrueAndFalseStrings(): void
    {
        self::assertNull(SettingsRegistry::validate('mcp.enabled', 'true'));
        self::assertNull(SettingsRegistry::validate('mcp.enabled', 'false'));
    }

    public function testMcpEnabledRejectsOtherValues(): void
    {
        self::assertNotNull(SettingsRegistry::validate('mcp.enabled', '1'));
        self::assertNotNull(SettingsRegistry::validate('mcp.enabled', 'yes'));
        self::assertNotNull(SettingsRegistry::validate('mcp.enabled', ''));
    }
}
