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
             'auth.sso_enabled', 'auth.desktop_login_max_hours',
             'storage.driver', 'storage.s3.endpoint', 'storage.s3.region', 'storage.s3.bucket',
             'storage.s3.access_key', 'storage.s3.path_style', 'storage.s3.public_base_url',
             'mail.transport', 'mail.smtp.host', 'mail.smtp.port', 'mail.smtp.encryption',
             'mail.smtp.username', 'mail.from_address', 'mail.from_name',
             'mail.events.welcome_enabled', 'mail.events.approval_enabled',
             'mail.events.invitation_enabled', 'mail.events.verification_enabled',
             'mail.events.deletion_enabled',
             'mail.brand_color', 'mail.footer_text',
             'billing.enforcement_default', 'billing.grace_days',
             'plugins.store_allowed_hosts',
             'documents.render_enabled', 'documents.render_max_rows',
             'documents.render_max_pages', 'documents.render_max_template_bytes'],
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
        // timezone, locale, support_email, mcp.enabled, the desktop login TTL,
        // and the three render batch limits (ADR 0012 — a per-tenant ceiling is
        // meaningful, unlike the render_enabled master switch itself).
        self::assertContains('site_name', SettingsRegistry::tenantTextKeys());
        self::assertCount(9, SettingsRegistry::tenantTextKeys());

        // The desktop-login TTL is per-tenant overridable (NOT global-only) and a
        // plain numeric string key.
        self::assertFalse(SettingsRegistry::isGlobalOnly('auth.desktop_login_max_hours'));
        self::assertContains('auth.desktop_login_max_hours', SettingsRegistry::tenantTextKeys());
        self::assertSame('string', SettingsRegistry::typeFor('auth.desktop_login_max_hours'));

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
        self::assertCount(40, $describe);
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

    // ---- feature flags (WC-feature-flags-settings-page) ----

    public function testFeatureFlagKeysAreExactlyTheCuratedCapabilityToggles(): void
    {
        // Strong candidates: platform capability toggles an operator would
        // recognise as a "feature flag".
        self::assertTrue(SettingsRegistry::isFeatureFlag('mcp.enabled'));
        self::assertTrue(SettingsRegistry::isFeatureFlag('auth.self_registration_enabled'));
        self::assertTrue(SettingsRegistry::isFeatureFlag('auth.registration_approval_required'));
        self::assertTrue(SettingsRegistry::isFeatureFlag('auth.sso_enabled'));

        // Weak candidates: booleans, but config detail rather than a platform
        // capability — deliberately excluded from the curated set.
        self::assertFalse(SettingsRegistry::isFeatureFlag('storage.s3.path_style'));
        self::assertFalse(SettingsRegistry::isFeatureFlag('mail.events.welcome_enabled'));
        self::assertFalse(SettingsRegistry::isFeatureFlag('mail.events.approval_enabled'));
        self::assertFalse(SettingsRegistry::isFeatureFlag('mail.events.invitation_enabled'));
        self::assertFalse(SettingsRegistry::isFeatureFlag('mail.events.verification_enabled'));
        self::assertFalse(SettingsRegistry::isFeatureFlag('mail.events.deletion_enabled'));

        // Non-boolean and unknown keys are never feature flags.
        self::assertFalse(SettingsRegistry::isFeatureFlag('site_name'));
        self::assertFalse(SettingsRegistry::isFeatureFlag('not_a_setting'));
    }

    public function testDescriptorMarksFeatureFlagKeysWithIsFlagTrueAndOmitsItOtherwise(): void
    {
        $byKey = [];
        foreach (SettingsRegistry::describe() as $d) {
            $byKey[$d['key']] = $d;
        }

        self::assertTrue($byKey['mcp.enabled']['isFlag'] ?? null);
        self::assertTrue($byKey['auth.sso_enabled']['isFlag'] ?? null);

        // Mirrors the `options` field's shape: absent (not `false`) when the
        // key is not a feature flag, including for other boolean keys.
        self::assertArrayNotHasKey('isFlag', $byKey['mail.events.welcome_enabled']);
        self::assertArrayNotHasKey('isFlag', $byKey['storage.s3.path_style']);
        self::assertArrayNotHasKey('isFlag', $byKey['site_name']);

        // Exact-shape check (mirrors testDescribePublishesKeyTypeAndDefault):
        // a flag descriptor is key+type+default+isFlag, nothing more.
        self::assertSame(
            ['key' => 'mcp.enabled', 'type' => 'bool', 'default' => 'false', 'isFlag' => true],
            $byKey['mcp.enabled']
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

    // ---- documents.render_* (ADR 0012 / WC-docdesigner Track 2) ----

    public function testDocumentsRenderEnabledIsGlobalOnlyBooleanDefaultFalse(): void
    {
        self::assertSame('false', SettingsRegistry::defaultFor('documents.render_enabled'));
        self::assertSame('bool', SettingsRegistry::typeFor('documents.render_enabled'));
        self::assertTrue(SettingsRegistry::isGlobalOnly('documents.render_enabled'));
        self::assertNotContains('documents.render_enabled', SettingsRegistry::tenantTextKeys());

        self::assertNull(SettingsRegistry::validate('documents.render_enabled', 'true'));
        self::assertNull(SettingsRegistry::validate('documents.render_enabled', 'false'));
        self::assertNotNull(SettingsRegistry::validate('documents.render_enabled', '1'));
    }

    public function testDocumentsRenderLimitsAreTenantOverridableWithSaneDefaults(): void
    {
        foreach (['documents.render_max_rows', 'documents.render_max_pages', 'documents.render_max_template_bytes'] as $key) {
            self::assertFalse(SettingsRegistry::isGlobalOnly($key), "{$key} must be tenant-overridable, not global-only");
            self::assertContains($key, SettingsRegistry::tenantTextKeys());
            self::assertSame('string', SettingsRegistry::typeFor($key));
        }

        self::assertSame('500', SettingsRegistry::defaultFor('documents.render_max_rows'));
        self::assertSame('2000', SettingsRegistry::defaultFor('documents.render_max_pages'));
        self::assertSame('2000000', SettingsRegistry::defaultFor('documents.render_max_template_bytes'));
    }

    public function testDocumentsRenderMaxRowsValidation(): void
    {
        self::assertNull(SettingsRegistry::validate('documents.render_max_rows', '1'));
        self::assertNull(SettingsRegistry::validate('documents.render_max_rows', '100000'));
        self::assertNotNull(SettingsRegistry::validate('documents.render_max_rows', '0'));
        self::assertNotNull(SettingsRegistry::validate('documents.render_max_rows', '100001'));
        self::assertNotNull(SettingsRegistry::validate('documents.render_max_rows', 'abc'));
    }

    public function testDocumentsRenderMaxPagesValidation(): void
    {
        self::assertNull(SettingsRegistry::validate('documents.render_max_pages', '1'));
        self::assertNull(SettingsRegistry::validate('documents.render_max_pages', '1000000'));
        self::assertNotNull(SettingsRegistry::validate('documents.render_max_pages', '0'));
        self::assertNotNull(SettingsRegistry::validate('documents.render_max_pages', '1000001'));
    }

    public function testDocumentsRenderMaxTemplateBytesValidation(): void
    {
        self::assertNull(SettingsRegistry::validate('documents.render_max_template_bytes', '1024'));
        self::assertNull(SettingsRegistry::validate('documents.render_max_template_bytes', (string) (20 * 1024 * 1024)));
        self::assertNotNull(SettingsRegistry::validate('documents.render_max_template_bytes', '1023'));
        self::assertNotNull(SettingsRegistry::validate('documents.render_max_template_bytes', (string) (20 * 1024 * 1024 + 1)));
    }
}
