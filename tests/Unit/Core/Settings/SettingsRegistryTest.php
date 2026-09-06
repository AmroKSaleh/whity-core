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
             'auth.self_password_reset_enabled', 'auth.password_reset_approval_required',
             'auth.self_2fa_recovery_enabled',
             'auth.sso_enabled', 'auth.desktop_login_max_hours',
             'storage.driver', 'storage.s3.endpoint', 'storage.s3.region', 'storage.s3.bucket',
             'storage.s3.access_key', 'storage.s3.path_style', 'storage.s3.public_base_url',
             'mail.transport', 'mail.smtp.host', 'mail.smtp.port', 'mail.smtp.encryption',
             'mail.smtp.username', 'mail.from_address', 'mail.from_name',
             'mail.events.welcome_enabled', 'mail.events.approval_enabled',
             'mail.events.invitation_enabled', 'mail.events.verification_enabled',
             'mail.events.deletion_enabled', 'mail.events.password_reset_enabled',
             'mail.brand_color', 'mail.footer_text',
             'billing.enforcement_default', 'billing.grace_days',
             'seats.enforcement', 'seats.count_invited',
             'plugins.store_allowed_hosts', 'plugins.store_enabled',
             'documents.render_enabled', 'documents.render_max_rows',
             'documents.render_max_pages', 'documents.render_max_template_bytes',
             'documents.flow_max_blocks', 'documents.flow_max_table_rows',
             'documents.flow_max_bytes',
             // #947 item 1: may a render be STORED as a document record? Gates
             // the storage cost, not the render container, so unlike the master
             // switch above it is tenant-overridable.
             'documents.persist_enabled',
             // #947 item 3 - routing ceilings, tenant-overridable like the render ones.
             'documents.routing_max_steps', 'documents.routing_max_recipients_per_step',
             // #1014: what "this node approved" MEANS when an approval step fans
             // out to many people. Tenant-overridable for the same reason the
             // ceilings are, and NOT governance: it grants nobody anything, it
             // says how many of the people already asked have to say yes.
             'documents.routing_approval_quorum',
             // #1054: which channels a routing notification goes out on. The
             // operator's half of a deliberate split — a route STEP declares
             // that its people are told rather than asked, and how they are
             // reached is configuration, or moving a tenant from in-app to
             // e-mail would mean re-authoring every route that mentions it.
             'documents.routing_notification_channels',
             'documents.qr_enabled', 'documents.qr_public_detail',
             // #999: how many people a USER GROUP preview SHOWS beside its
             // (always exact) count. Tenant-overridable — see the governance
             // test below for why this one is not operator-only.
             'groups.preview_sample_size',
             // WC-746: the bulk data-type lifecycle batch ceiling.
             'data_types.bulk_max_ids',
             // WC-error-tracking. The DSN is deliberately absent: it is a
             // credential, stored encrypted under a reserved key, never exposed
             // through the settings surface this list describes.
             'error_tracking.enabled', 'error_tracking.provider',
             'error_tracking.environment', 'error_tracking.notify_admins',
             'error_tracking.retention_days',
             // WC-i18n-feature-flag. The master switch for the whole interface
             // language surface; ENABLED by default (see SettingsRegistry).
             'i18n.enabled',
             'auth.invitation_ttl_days',
             // #1068: whether dates and times are shown on screen at all.
             // Tenant-overridable and NOT governance — it grants nobody
             // anything and takes nothing away, it says what the interface
             // volunteers. Appended rather than slotted in beside the other
             // `ui.`-shaped keys because there are none: this opens the
             // namespace, and appending keeps the diff to one line.
             'ui.hide_dates'],
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
        self::assertTrue(SettingsRegistry::isGlobalOnly('auth.self_password_reset_enabled'));
        self::assertTrue(SettingsRegistry::isGlobalOnly('auth.password_reset_approval_required'));
        self::assertTrue(SettingsRegistry::isGlobalOnly('auth.self_2fa_recovery_enabled'));
        self::assertTrue(SettingsRegistry::isGlobalOnly('auth.sso_enabled'));
        self::assertFalse(SettingsRegistry::isGlobalOnly('site_name'));

        // The per-tenant surface excludes the global-only governance keys.
        self::assertNotContains('auth.self_registration_enabled', SettingsRegistry::tenantTextKeys());
        self::assertNotContains('auth.registration_approval_required', SettingsRegistry::tenantTextKeys());
        self::assertNotContains('auth.self_password_reset_enabled', SettingsRegistry::tenantTextKeys());
        self::assertNotContains('auth.password_reset_approval_required', SettingsRegistry::tenantTextKeys());
        self::assertNotContains('auth.self_2fa_recovery_enabled', SettingsRegistry::tenantTextKeys());
        self::assertNotContains('auth.sso_enabled', SettingsRegistry::tenantTextKeys());
        self::assertTrue(SettingsRegistry::isGlobalOnly('storage.driver'));
        self::assertNotContains('storage.driver', SettingsRegistry::tenantTextKeys());
        // Only the genuinely tenant-overridable text keys remain: site_name,
        // timezone, locale, support_email, mcp.enabled, the desktop login TTL,
        // the three render batch limits (ADR 0012 — a per-tenant ceiling is
        // meaningful, unlike the render_enabled master switch itself), the
        // bulk lifecycle batch ceiling (WC-746, per-tenant for the same reason)
        // the invitation TTL (WHIT-417 — how long an invite stays valid is
        // a tenant's own onboarding policy, not a platform constant) and
        // documents.persist_enabled (#947 item 1 — whether a render may be
        // stored is about the storage this tenant consumes, so one tenant can
        // be issuing documents while another only previews labels).
        self::assertContains('site_name', SettingsRegistry::tenantTextKeys());
        self::assertContains('data_types.bulk_max_ids', SettingsRegistry::tenantTextKeys());
        self::assertFalse(SettingsRegistry::isGlobalOnly('data_types.bulk_max_ids'));
        self::assertContains('documents.persist_enabled', SettingsRegistry::tenantTextKeys());
        self::assertFalse(SettingsRegistry::isGlobalOnly('documents.persist_enabled'));
        self::assertTrue(SettingsRegistry::isGlobalOnly('documents.render_enabled'));
        // 14 since #947 item 3: the two routing ceilings are per-tenant
        // overridable for the same reason the render ceilings are - one tenant
        // circulating institution-wide notices and another routing three-person
        // approvals want genuinely different numbers.
        self::assertContains('documents.routing_max_steps', SettingsRegistry::tenantTextKeys());
        self::assertFalse(SettingsRegistry::isGlobalOnly('documents.routing_max_steps'));
        self::assertContains('documents.routing_max_recipients_per_step', SettingsRegistry::tenantTextKeys());
        self::assertFalse(SettingsRegistry::isGlobalOnly('documents.routing_max_recipients_per_step'));
        // 15 since #999: the user-group preview SAMPLE SIZE. Deliberately
        // tenant-overridable rather than operator-only, and this is the
        // assertion that states it.
        //
        // Resolution is LAYERED, not a choice between two surfaces: a call site
        // reads the per-tenant override, else the global value, else the
        // registry default (SettingsService::effective, the same chain the
        // render and routing ceilings use). Shipping it in this list rather
        // than in GLOBAL_ONLY_KEYS means a deployment that never sets a tenant
        // value behaves exactly as if the key were global-only, and a tenant
        // that later needs its own number is an operator decision rather than a
        // schema migration — no call site changes either way.
        //
        // Why a tenant would want its own: ten faces is enough to recognise a
        // departmental group and not enough to recognise a faculty-wide one, so
        // an operator running both must be able to raise one without raising
        // the other. That is the same argument the render and routing ceilings
        // won on, and it is why this key is NOT governance — it tunes how much
        // of an answer a screen shows, and grants nobody anything.
        self::assertContains('groups.preview_sample_size', SettingsRegistry::tenantTextKeys());
        self::assertFalse(SettingsRegistry::isGlobalOnly('groups.preview_sample_size'));
        self::assertSame('10', SettingsRegistry::defaultFor('groups.preview_sample_size'));

        // #1014's approval quorum is per-tenant overridable too, and its default
        // is the STRICTEST rule rather than the most convenient one: approving
        // with too few people is a silent authority failure found in an audit
        // years later, while requiring too many is a document that visibly stops
        // and a complaint the same afternoon.
        self::assertContains('documents.routing_approval_quorum', SettingsRegistry::tenantTextKeys());
        self::assertFalse(SettingsRegistry::isGlobalOnly('documents.routing_approval_quorum'));
        self::assertSame('all', SettingsRegistry::defaultFor('documents.routing_approval_quorum'));

        // 17 since #1036: the QR master switch and the public-disclosure level.
        // Both are per-tenant and NEITHER is governance, which is the claim this
        // block exists to make rather than assume.
        //
        // `documents.qr_enabled` is per-tenant for a reason the render master
        // switch is NOT: that one asks whether a Chromium container exists on
        // this machine, which no tenant can answer for itself, while this asks
        // whether an ORGANISATION publishes verifiable documents. A registry
        // issuing certificates and a workshop circulating internal memos want
        // different answers on the same instance.
        //
        // `documents.qr_public_detail` is per-tenant because #1036 says in so
        // many words that what a stranger holding the paper is told is a
        // TENANT'S decision — some institutions want a holder to see where a
        // document sits, and some would consider the same sentence a leak.
        //
        // Both DEFAULT to the closed position: off, and minimal disclosure. An
        // operator who sets neither gets exactly today's behaviour.
        self::assertContains('documents.qr_enabled', SettingsRegistry::tenantTextKeys());
        self::assertFalse(SettingsRegistry::isGlobalOnly('documents.qr_enabled'));
        self::assertSame('false', SettingsRegistry::defaultFor('documents.qr_enabled'));
        self::assertContains('documents.qr_public_detail', SettingsRegistry::tenantTextKeys());
        self::assertFalse(SettingsRegistry::isGlobalOnly('documents.qr_public_detail'));
        self::assertSame('minimal', SettingsRegistry::defaultFor('documents.qr_public_detail'));
        // #1068 added a THIRD level, `undated`, BELOW the default. The pin is
        // here rather than only in the core pin file because this is the
        // assertion that says the default did not move when it was added: a
        // quieter level must not change what an existing tenant discloses.
        self::assertNull(SettingsRegistry::validate('documents.qr_public_detail', 'undated'));
        self::assertSame(
            ['undated', 'minimal', 'stage'],
            SettingsRegistry::optionsFor('documents.qr_public_detail')
        );
        // 18: 16 on develop (#1014's quorum was the sixteenth) plus these two.
        // 19 since #1054 added documents.routing_notification_channels.
        self::assertContains('documents.routing_notification_channels', SettingsRegistry::tenantTextKeys());
        self::assertFalse(SettingsRegistry::isGlobalOnly('documents.routing_notification_channels'));
        self::assertSame('in_app', SettingsRegistry::defaultFor('documents.routing_notification_channels'));

        // 20 since #1068 added ui.hide_dates. Tenant-overridable rather than
        // global-only, and the distinction is real: it grants nobody anything
        // and takes nothing away — a reader who could see a document before
        // can see it now — so it is a preference, not governance. Two tenants
        // on one instance answer it differently, which is the test every
        // per-tenant key in this list passes.
        //
        // DEFAULT FALSE, so an instance that never sets it renders exactly the
        // dates it renders today.
        self::assertContains('ui.hide_dates', SettingsRegistry::tenantTextKeys());
        self::assertFalse(SettingsRegistry::isGlobalOnly('ui.hide_dates'));
        self::assertSame('false', SettingsRegistry::defaultFor('ui.hide_dates'));
        self::assertSame('bool', SettingsRegistry::typeFor('ui.hide_dates'));
        // NOT a feature flag: that tab is instance-wide capability toggles with
        // no per-tenant surface, and a tenant-overridable key sitting there
        // would give an operator a switch that looks global and is not.
        self::assertFalse(SettingsRegistry::isFeatureFlag('ui.hide_dates'));
        // 23 since #1072: the three documents.flow_max_* ceilings are
        // tenant-overridable on purpose — a tenant issuing hundred-page
        // submissions and one printing two-page receipts should not be held to
        // one number. The instance-wide documents.render_enabled switch above
        // them stays global-only.
        self::assertCount(23, SettingsRegistry::tenantTextKeys());

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
        // 58 since #1014 added documents.routing_approval_quorum.
        // 60 since #1036 added documents.qr_enabled + documents.qr_public_detail.
        // 61 since #1054 added documents.routing_notification_channels.
        // 62 since #1068 added ui.hide_dates.
        // 65 since #1072 added the three documents.flow_max_* ceilings.
        // 67 since seats added seats.enforcement + seats.count_invited.
        self::assertCount(67, $describe);
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
                  'mail.events.deletion_enabled', 'mail.events.password_reset_enabled',
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
        // WC-password-reset-2fa-recovery: the three forgotten-password / 2FA-
        // recovery instance-governance toggles are curated the same way as the
        // registration toggles above.
        self::assertTrue(SettingsRegistry::isFeatureFlag('auth.self_password_reset_enabled'));
        self::assertTrue(SettingsRegistry::isFeatureFlag('auth.password_reset_approval_required'));
        self::assertTrue(SettingsRegistry::isFeatureFlag('auth.self_2fa_recovery_enabled'));
        self::assertTrue(SettingsRegistry::isFeatureFlag('auth.sso_enabled'));
        // WC-feature-flags-audit: the plugin-marketplace master switch and the
        // document render tier's master switch are both genuine heavyweight/
        // optional-subsystem toggles, curated the same way as the four above.
        self::assertTrue(SettingsRegistry::isFeatureFlag('plugins.store_enabled'));
        self::assertTrue(SettingsRegistry::isFeatureFlag('documents.render_enabled'));

        // Weak candidates: booleans, but config detail rather than a platform
        // capability — deliberately excluded from the curated set.
        self::assertFalse(SettingsRegistry::isFeatureFlag('storage.s3.path_style'));
        self::assertFalse(SettingsRegistry::isFeatureFlag('mail.events.welcome_enabled'));
        self::assertFalse(SettingsRegistry::isFeatureFlag('mail.events.approval_enabled'));
        self::assertFalse(SettingsRegistry::isFeatureFlag('mail.events.invitation_enabled'));
        self::assertFalse(SettingsRegistry::isFeatureFlag('mail.events.verification_enabled'));
        self::assertFalse(SettingsRegistry::isFeatureFlag('mail.events.deletion_enabled'));
        self::assertFalse(SettingsRegistry::isFeatureFlag('mail.events.password_reset_enabled'));

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
        self::assertTrue($byKey['plugins.store_enabled']['isFlag'] ?? null);
        self::assertTrue($byKey['documents.render_enabled']['isFlag'] ?? null);

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

    // ---- plugins.store_enabled (WC-feature-flags-audit) ----

    public function testPluginsStoreEnabledIsGlobalOnlyBooleanDefaultTrue(): void
    {
        // Opt-OUT default (unlike documents.render_enabled's opt-in default):
        // the allowlist (plugins.store_allowed_hosts, empty by default) is
        // already the primary off-switch, so this master switch defaults to
        // 'true' to avoid silently disabling an already-configured deployment.
        self::assertSame('true', SettingsRegistry::defaultFor('plugins.store_enabled'));
        self::assertSame('bool', SettingsRegistry::typeFor('plugins.store_enabled'));
        self::assertTrue(SettingsRegistry::isGlobalOnly('plugins.store_enabled'));
        self::assertNotContains('plugins.store_enabled', SettingsRegistry::tenantTextKeys());

        self::assertNull(SettingsRegistry::validate('plugins.store_enabled', 'true'));
        self::assertNull(SettingsRegistry::validate('plugins.store_enabled', 'false'));
        self::assertNotNull(SettingsRegistry::validate('plugins.store_enabled', '1'));
        self::assertNotNull(SettingsRegistry::validate('plugins.store_enabled', ''));
    }

    public function testPluginsStoreAllowedHostsIsGlobalOnlyFreeformDefaultEmpty(): void
    {
        // The allowlist itself is free-form (validated at fetch time by the
        // handler, not the registry) — empty is the secure-by-default OFF state.
        self::assertSame('', SettingsRegistry::defaultFor('plugins.store_allowed_hosts'));
        self::assertSame('string', SettingsRegistry::typeFor('plugins.store_allowed_hosts'));
        self::assertTrue(SettingsRegistry::isGlobalOnly('plugins.store_allowed_hosts'));
        self::assertNull(SettingsRegistry::validate('plugins.store_allowed_hosts', 'store.example.com'));
        self::assertNull(SettingsRegistry::validate('plugins.store_allowed_hosts', ''));
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
