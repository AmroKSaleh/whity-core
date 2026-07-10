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
             'auth.self_registration_enabled', 'auth.registration_approval_required',
             'auth.sso_enabled',
             'storage.driver', 'storage.s3.endpoint', 'storage.s3.region', 'storage.s3.bucket',
             'storage.s3.access_key', 'storage.s3.path_style', 'storage.s3.public_base_url',
             'mail.transport', 'mail.smtp.host', 'mail.smtp.port', 'mail.smtp.encryption',
             'mail.smtp.username', 'mail.from_address', 'mail.from_name',
             'mail.events.welcome_enabled', 'mail.events.approval_enabled',
             'mail.events.invitation_enabled', 'mail.events.verification_enabled',
             'mail.events.deletion_enabled',
             'mail.brand_color', 'mail.footer_text'],
            SettingsRegistry::keys()
        );
    }

    public function testIsKnownRejectsUnknownKeys(): void
    {
        self::assertTrue(SettingsRegistry::isKnown('site_name'));
        self::assertFalse(SettingsRegistry::isKnown('not_a_setting'));
    }

    public function testGovernanceKeysAreGlobalOnlyAndExcludedFromTenantSurface(): void
    {
        self::assertTrue(SettingsRegistry::isGlobalOnly('auth.self_registration_enabled'));
        self::assertTrue(SettingsRegistry::isGlobalOnly('auth.registration_approval_required'));
        self::assertTrue(SettingsRegistry::isGlobalOnly('auth.sso_enabled'));
        self::assertFalse(SettingsRegistry::isGlobalOnly('site_name'));

        // The per-tenant surface excludes the global-only governance keys.
        self::assertNotContains('auth.self_registration_enabled', SettingsRegistry::tenantTextKeys());
        self::assertNotContains('auth.registration_approval_required', SettingsRegistry::tenantTextKeys());
        self::assertNotContains('auth.sso_enabled', SettingsRegistry::tenantTextKeys());
        self::assertTrue(SettingsRegistry::isGlobalOnly('storage.driver'));
        self::assertNotContains('storage.driver', SettingsRegistry::tenantTextKeys());
        // Only the genuinely tenant-overridable text keys remain: site_name,
        // timezone, locale, support_email, mcp.enabled.
        self::assertContains('site_name', SettingsRegistry::tenantTextKeys());
        self::assertCount(5, SettingsRegistry::tenantTextKeys());

        // Boolean flags report type 'bool' (clients render a toggle).
        self::assertSame('bool', SettingsRegistry::typeFor('auth.sso_enabled'));
        self::assertSame('bool', SettingsRegistry::typeFor('mcp.enabled'));
        self::assertSame('string', SettingsRegistry::typeFor('site_name'));
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
        self::assertCount(32, $describe);
        self::assertSame(
            ['key' => 'site_name', 'type' => 'string', 'default' => 'Whity'],
            $describe[0]
        );
    }

    // ---- email settings (WC-email) ----

    public function testMailKeysAreGlobalOnly(): void
    {
        foreach (['mail.transport', 'mail.smtp.host', 'mail.smtp.port', 'mail.smtp.encryption',
                  'mail.smtp.username', 'mail.from_address', 'mail.from_name',
                  'mail.events.welcome_enabled', 'mail.events.approval_enabled',
                  'mail.events.invitation_enabled', 'mail.events.verification_enabled',
                  'mail.events.deletion_enabled',
                  'mail.brand_color', 'mail.footer_text'] as $key) {
            self::assertTrue(SettingsRegistry::isGlobalOnly($key), "{$key} must be global-only");
            self::assertNotContains($key, SettingsRegistry::tenantTextKeys());
        }
    }

    public function testMailBrandColorValidation(): void
    {
        self::assertSame('#2B6CD2', SettingsRegistry::defaultFor('mail.brand_color'));
        self::assertNull(SettingsRegistry::validate('mail.brand_color', '#2B6CD2'));
        self::assertNull(SettingsRegistry::validate('mail.brand_color', '#abc123'));
        self::assertNotNull(SettingsRegistry::validate('mail.brand_color', '2B6CD2'));   // no #
        self::assertNotNull(SettingsRegistry::validate('mail.brand_color', '#FFF'));     // shorthand not allowed
        self::assertNotNull(SettingsRegistry::validate('mail.brand_color', 'red'));
        // footer_text is free-form.
        self::assertNull(SettingsRegistry::validate('mail.footer_text', 'Acme Inc · 123 St'));
        self::assertNull(SettingsRegistry::validate('mail.footer_text', ''));
    }

    public function testMailDefaultsAreOffAndSubmissionShaped(): void
    {
        self::assertSame('none', SettingsRegistry::defaultFor('mail.transport'));
        self::assertSame('587', SettingsRegistry::defaultFor('mail.smtp.port'));
        self::assertSame('tls', SettingsRegistry::defaultFor('mail.smtp.encryption'));
        self::assertSame('true', SettingsRegistry::defaultFor('mail.events.welcome_enabled'));
    }

    public function testEnumTypeAndOptionsPublished(): void
    {
        self::assertSame('enum', SettingsRegistry::typeFor('mail.transport'));
        self::assertSame('enum', SettingsRegistry::typeFor('mail.smtp.encryption'));
        self::assertSame(['none', 'log', 'smtp'], SettingsRegistry::optionsFor('mail.transport'));
        self::assertSame(['none', 'tls', 'ssl'], SettingsRegistry::optionsFor('mail.smtp.encryption'));
        self::assertNull(SettingsRegistry::optionsFor('mail.smtp.host'));

        // Enum descriptors carry an options list; non-enum descriptors do not.
        $byKey = [];
        foreach (SettingsRegistry::describe() as $d) {
            $byKey[$d['key']] = $d;
        }
        self::assertSame(['none', 'log', 'smtp'], $byKey['mail.transport']['options'] ?? null);
        self::assertArrayNotHasKey('options', $byKey['site_name']);
    }

    public function testMailTransportValidation(): void
    {
        self::assertNull(SettingsRegistry::validate('mail.transport', 'smtp'));
        self::assertNull(SettingsRegistry::validate('mail.transport', 'none'));
        self::assertNotNull(SettingsRegistry::validate('mail.transport', 'sendmail'));
    }

    public function testMailPortValidation(): void
    {
        self::assertNull(SettingsRegistry::validate('mail.smtp.port', '587'));
        self::assertNull(SettingsRegistry::validate('mail.smtp.port', '1'));
        self::assertNull(SettingsRegistry::validate('mail.smtp.port', '65535'));
        self::assertNotNull(SettingsRegistry::validate('mail.smtp.port', '0'));
        self::assertNotNull(SettingsRegistry::validate('mail.smtp.port', '70000'));
        self::assertNotNull(SettingsRegistry::validate('mail.smtp.port', 'abc'));
        self::assertNotNull(SettingsRegistry::validate('mail.smtp.port', ''));
    }

    public function testMailFromAddressValidation(): void
    {
        self::assertNull(SettingsRegistry::validate('mail.from_address', ''));
        self::assertNull(SettingsRegistry::validate('mail.from_address', 'no-reply@example.com'));
        self::assertNotNull(SettingsRegistry::validate('mail.from_address', 'not-an-email'));
    }

    public function testMailEventTogglesAreBoolean(): void
    {
        self::assertSame('bool', SettingsRegistry::typeFor('mail.events.welcome_enabled'));
        self::assertNull(SettingsRegistry::validate('mail.events.welcome_enabled', 'true'));
        self::assertNotNull(SettingsRegistry::validate('mail.events.welcome_enabled', 'yes'));
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
