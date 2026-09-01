<?php

declare(strict_types=1);

namespace Tests\Core\Settings;

use PHPUnit\Framework\TestCase;
use Whity\Core\Settings\PluginSettingsRegistry;
use Whity\Core\Settings\SettingsCatalog;
use Whity\Core\Settings\SettingsRegistry;

/**
 * The regression net for opening the settings registry to plugins (#713 item 1).
 *
 * {@see SettingsRegistry} has roughly 330 static call sites across three dozen
 * files, and almost none of them are in this repo's settings tests — they are in
 * {@see \Whity\Core\Mail\MailerFactory}, {@see \Whity\Storage\StorageDriverFactory},
 * the registration handler, the payment wall. A change to the catalogue that
 * silently dropped or retyped a key would surface as a mail transport that
 * stopped resolving or a signup flow that opened itself, far from anything
 * obviously about settings.
 *
 * So this file pins the catalogue itself: the exact key set, the exact defaults,
 * and a sample of validation outcomes chosen for the ones that would hurt. It is
 * deliberately literal rather than derived — a test that recomputed the
 * expectation from the same constant would pass no matter what the constant
 * said.
 *
 * A legitimate new core key updates this file. That is the point: the diff makes
 * the addition visible, rather than letting it merge as an invisible one-line
 * change to a private const.
 */
final class SettingsRegistryCorePinTest extends TestCase
{
    /**
     * The complete core catalogue, in declared order.
     *
     * Order matters and is pinned with it: {@see SettingsRegistry::keys()} is
     * what the settings service iterates and what the admin screen renders.
     *
     * @return list<string>
     */
    private static function expectedKeys(): array
    {
        return [
            'site_name',
            'timezone',
            'locale',
            'support_email',
            'branding_logo_wide',
            'branding_logo_square',
            'branding_favicon',
            'mcp.enabled',
            'auth.self_registration_enabled',
            'auth.registration_approval_required',
            'auth.self_password_reset_enabled',
            'auth.password_reset_approval_required',
            'auth.self_2fa_recovery_enabled',
            'auth.sso_enabled',
            'auth.desktop_login_max_hours',
            'storage.driver',
            'storage.s3.endpoint',
            'storage.s3.region',
            'storage.s3.bucket',
            'storage.s3.access_key',
            'storage.s3.path_style',
            'storage.s3.public_base_url',
            'mail.transport',
            'mail.smtp.host',
            'mail.smtp.port',
            'mail.smtp.encryption',
            'mail.smtp.username',
            'mail.from_address',
            'mail.from_name',
            'mail.events.welcome_enabled',
            'mail.events.approval_enabled',
            'mail.events.invitation_enabled',
            'mail.events.verification_enabled',
            'mail.events.deletion_enabled',
            'mail.events.password_reset_enabled',
            'mail.brand_color',
            'mail.footer_text',
            'billing.enforcement_default',
            'billing.grace_days',
            'plugins.store_allowed_hosts',
            'plugins.store_enabled',
            'documents.render_enabled',
            'documents.render_max_rows',
            'documents.render_max_pages',
            'documents.render_max_template_bytes',
            'documents.flow_max_blocks',
            'documents.flow_max_table_rows',
            'documents.flow_max_bytes',
            'documents.persist_enabled',
            // #947 item 3 — routing ceilings, tenant-overridable like the render ones.
            'documents.routing_max_steps',
            'documents.routing_max_recipients_per_step',
            'documents.routing_approval_quorum',
            // #1054: which channels a routing notification is offered on.
            // Tenant-overridable because it is a fact about how an
            // organisation reaches its people, not about what a route means
            // — which is why it is here and not a field on a route step.
            'documents.routing_notification_channels',
            // #1036: QR verification on documents. Two keys, both
            // per-tenant, both defaulting closed — the switch is off and
            // the public page discloses the minimum. This pin firing on
            // them was the pin working; they are added here deliberately.
            'documents.qr_enabled',
            'documents.qr_public_detail',
            // #999 — how many people a USER GROUP preview SHOWS. Not a ceiling
            // on resolution: the count a preview reports is exact and unbounded,
            // this is the size of the sample beside it.
            'groups.preview_sample_size',
            'data_types.bulk_max_ids',
            'error_tracking.enabled',
            'error_tracking.provider',
            'error_tracking.environment',
            'error_tracking.notify_admins',
            'error_tracking.retention_days',
            'i18n.enabled',
            'auth.invitation_ttl_days',
            // #1068. This pin firing on the key was the pin working; it is
            // added here deliberately. A DISPLAY key: every timestamp keeps
            // being written, keeps being queryable, keeps its place in the
            // audit trail. Only the screen changes.
            'ui.hide_dates',
        ];
    }

    public function testTheCoreKeySetIsExactlyWhatItWas(): void
    {
        self::assertSame(self::expectedKeys(), SettingsRegistry::keys());
    }

    /**
     * Not one core key carries a colon, which is what makes a plugin collision
     * structurally impossible rather than merely checked: every canonical plugin
     * key carries exactly one.
     */
    public function testNoCoreKeyUsesTheNamespaceSeparator(): void
    {
        foreach (SettingsRegistry::keys() as $key) {
            self::assertStringNotContainsString(
                PluginSettingsRegistry::NAMESPACE_SEPARATOR,
                $key,
                "Core key {$key} now carries the plugin namespace separator; a plugin could shadow it"
            );
        }
    }

    /**
     * Every core key fits the column its values are stored in, so the length
     * ceiling plugin keys are held to is the same one core already satisfies.
     */
    public function testEveryCoreKeyFitsTheSettingsColumn(): void
    {
        foreach (SettingsRegistry::keys() as $key) {
            self::assertLessThanOrEqual(PluginSettingsRegistry::MAX_KEY_LENGTH, strlen($key), $key);
        }
    }

    /**
     * The defaults, pinned literally. Several of these are SECURITY postures —
     * signup closed, blocking off, no trusted plugin store, render tier
     * disabled — and a flipped default would open an instance without anyone
     * editing a setting.
     *
     * @return array<string, string>
     */
    private static function expectedDefaults(): array
    {
        return [
            'site_name' => 'Whity',
            'timezone' => 'UTC',
            'locale' => 'en',
            'support_email' => '',
            'branding_logo_wide' => '',
            'branding_logo_square' => '',
            'branding_favicon' => '',
            'mcp.enabled' => 'false',
            'auth.self_registration_enabled' => 'false',
            'auth.registration_approval_required' => 'true',
            'auth.self_password_reset_enabled' => 'true',
            'auth.password_reset_approval_required' => 'false',
            'auth.self_2fa_recovery_enabled' => 'true',
            'auth.sso_enabled' => 'true',
            'auth.desktop_login_max_hours' => '72',
            'storage.driver' => 'local',
            'storage.s3.endpoint' => '',
            'storage.s3.region' => '',
            'storage.s3.bucket' => '',
            'storage.s3.access_key' => '',
            'storage.s3.path_style' => 'true',
            'storage.s3.public_base_url' => '',
            'mail.transport' => 'none',
            'mail.smtp.host' => '',
            'mail.smtp.port' => '587',
            'mail.smtp.encryption' => 'tls',
            'mail.smtp.username' => '',
            'mail.from_address' => '',
            'mail.from_name' => '',
            'mail.events.welcome_enabled' => 'true',
            'mail.events.approval_enabled' => 'true',
            'mail.events.invitation_enabled' => 'true',
            'mail.events.verification_enabled' => 'true',
            'mail.events.deletion_enabled' => 'true',
            'mail.events.password_reset_enabled' => 'true',
            'mail.brand_color' => '#2B6CD2',
            'mail.footer_text' => '',
            'billing.enforcement_default' => 'warn',
            'billing.grace_days' => '7',
            'plugins.store_allowed_hosts' => '',
            'plugins.store_enabled' => 'true',
            'documents.render_enabled' => 'false',
            'documents.render_max_rows' => '500',
            'documents.render_max_pages' => '2000',
            'documents.render_max_template_bytes' => '2000000',
            'documents.flow_max_blocks' => '20000',
            'documents.flow_max_table_rows' => '5000',
            'documents.flow_max_bytes' => '20971520',
            // #947 item 1. Opt-OUT where documents.render_enabled is opt-in:
            // the master switch is already off by default, so a deployment that
            // reaches this key has turned the render tier on deliberately, and
            // a second off-by-default gate would 503 a correctly-configured
            // `persist: true`.
            'documents.persist_enabled' => 'true',
            // 20 steps: well past the longest real approval chain, low enough
            // that a client looping over a step builder cannot commission a
            // thousand-step transaction.
            'documents.routing_max_steps' => '20',
            // 500, matching the render row ceiling: the point at which "this is
            // a distribution" stops being a plausible reading of one step.
            'documents.routing_max_recipients_per_step' => '500',
            // #1014. `all` rather than `any`, and the choice is the
            // feature's most consequential default: approving with too few
            // people is a SILENT authority failure found in an audit years
            // later, while requiring too many is a document that visibly
            // stops and a complaint the same afternoon. Changing this line
            // changes who can authorise a document, so it should be a
            // deliberate edit rather than a number that drifted.
            'documents.routing_approval_quorum' => 'all',
            // #1054. `in_app` alone. Routing sent no notifications at all
            // before it, so whatever this says starts happening on every
            // existing route the day a deployment upgrades — and an e-mail
            // is a send that costs money and reaches people outside the
            // app. A tenant that wants it writes `in_app,email` once.
            'documents.routing_notification_channels' => 'in_app',
            // #1036. OFF, because turning it on publishes an
            // unauthenticated verification surface for this tenant's
            // documents; MINIMAL, because that is the level that cannot
            // leak where a document sits internally.
            'documents.qr_enabled' => 'false',
            'documents.qr_public_detail' => 'minimal',
            // Ten faces: enough to recognise a group at a glance, small enough
            // that nobody mistakes the sample for the list.
            'groups.preview_sample_size' => '10',
            'data_types.bulk_max_ids' => '500',
            'error_tracking.enabled' => 'false',
            'error_tracking.provider' => 'internal',
            'error_tracking.environment' => '',
            'error_tracking.notify_admins' => 'true',
            'error_tracking.retention_days' => '90',
            // ENABLED, unlike the other opt-in capability flags above: i18n
            // shipped before its flag did, so defaulting it off would switch a
            // live feature off on every existing deployment at upgrade time.
            'i18n.enabled' => 'true',
            // WHIT-417. Tenant-overridable rather than global-only: the tenant
            // issuing an invitation is the one that knows how long its own
            // people need to act on it.
            'auth.invitation_ttl_days' => '7',
            // #1068. OFF. The opposite default would blank every timestamp on
            // every screen of every deployment at upgrade time, for a
            // preference most of them have not expressed — which is the same
            // argument i18n.enabled won above, pointing the other way.
            'ui.hide_dates' => 'false',
        ];
    }

    public function testEveryCoreDefaultIsExactlyWhatItWas(): void
    {
        self::assertSame(self::expectedDefaults(), SettingsRegistry::defaults());

        // …and through the per-key accessor, which is what most call sites use.
        foreach (self::expectedDefaults() as $key => $expected) {
            self::assertSame($expected, SettingsRegistry::defaultFor($key), $key);
        }
    }

    /**
     * A sample of validation outcomes, chosen for the keys where a loosened rule
     * would matter: a TTL that could be extended past the credential ceiling, a
     * boolean that started accepting `'1'`, an enum that started accepting an
     * unhandled mode, a port outside the legal range.
     *
     * @return list<array{0: string, 1: string, 2: bool}>
     */
    public static function validationSamples(): array
    {
        return [
            ['site_name', 'Acme', true],
            ['site_name', '   ', false],
            ['site_name', str_repeat('a', 121), false],
            ['timezone', 'Asia/Riyadh', true],
            ['timezone', 'Mars/Olympus', false],
            ['locale', 'en-US', true],
            ['locale', 'english', false],
            ['support_email', '', true],
            ['support_email', 'not-an-email', false],
            ['mcp.enabled', 'true', true],
            ['mcp.enabled', '1', false],
            // #1036. The boolean arm and the enum arm, each with a value the
            // registry must refuse — a key with no validate() arm falls through
            // to "Unknown setting key" and 422s on a key the registry knows,
            // which is the exact bug the error_tracking.* keys shipped with.
            ['documents.qr_enabled', 'true', true],
            ['documents.qr_enabled', 'yes', false],
            ['documents.qr_public_detail', 'stage', true],
            // #1068's third level, BELOW the default: `minimal` with the date
            // withheld, so a tenant that wants no date on the PUBLIC page can
            // say so on this key rather than acquiring it as a side effect of
            // ui.hide_dates, which deliberately does not reach that page.
            ['documents.qr_public_detail', 'undated', true],
            ['documents.qr_public_detail', 'everything', false],
            ['auth.self_registration_enabled', 'false', true],
            ['auth.self_registration_enabled', 'yes', false],
            ['auth.desktop_login_max_hours', '2160', true],
            ['auth.desktop_login_max_hours', '2161', false],
            ['auth.desktop_login_max_hours', '0', false],
            ['storage.driver', 's3', true],
            ['storage.driver', 'ftp', false],
            ['mail.transport', 'smtp', true],
            ['mail.transport', 'sendmail', false],
            ['mail.smtp.port', '587', true],
            ['mail.smtp.port', '65536', false],
            ['mail.brand_color', '#2B6CD2', true],
            ['mail.brand_color', '2B6CD2', false],
            ['billing.enforcement_default', 'block_all', true],
            ['billing.enforcement_default', 'block', false],
            ['billing.grace_days', '0', true],
            ['billing.grace_days', '3651', false],
            // These five had no validate() arm at all until #713 item 1 — they
            // fell through to "Unknown setting key" and the admin Error-tracking
            // tab could not save a single field.
            ['error_tracking.provider', 'sentry', true],
            ['error_tracking.provider', 'rollbar', false],
            ['error_tracking.enabled', 'true', true],
            ['error_tracking.enabled', 'on', false],
            ['error_tracking.notify_admins', 'false', true],
            ['error_tracking.environment', 'production', true],
            ['error_tracking.environment', '', true],
            ['error_tracking.retention_days', '90', true],
            ['error_tracking.retention_days', '0', false],
            ['error_tracking.retention_days', '3651', false],
            ['error_tracking.retention_days', 'forever', false],
            ['documents.render_max_template_bytes', '1024', true],
            ['documents.render_max_template_bytes', '1023', false],
            // The flowing-mode ceilings (#1072). `0` is rejected for the same
            // reason `data_types.bulk_max_ids` rejects it below: a zero ceiling
            // refuses every render, which from the outside is indistinguishable
            // from the render tier being down.
            ['documents.flow_max_blocks', '1', true],
            ['documents.flow_max_blocks', '0', false],
            ['documents.flow_max_blocks', '200001', false],
            ['documents.flow_max_blocks', 'many', false],
            ['documents.flow_max_table_rows', '5000', true],
            ['documents.flow_max_table_rows', '100001', false],
            // Above the render service's own 20 MiB hard limit, and ACCEPTED on
            // purpose — the service answers 422 naming its limit and the client
            // relays that as a 422, so an operator raising this is not silently
            // handed an outage. Pinned so nobody "tightens" it into one.
            ['documents.flow_max_bytes', '25165824', true],
            ['documents.flow_max_bytes', '1023', false],
            // The bulk lifecycle batch ceiling. `0` is rejected rather than
            // clamped: a zero ceiling refuses every batch, which is
            // indistinguishable from the endpoint being broken.
            ['data_types.bulk_max_ids', '1', true],
            ['data_types.bulk_max_ids', '10000', true],
            ['data_types.bulk_max_ids', '0', false],
            ['data_types.bulk_max_ids', '10001', false],
            ['data_types.bulk_max_ids', 'lots', false],
            // The i18n master switch stores the LITERAL 'true'/'false'. A key
            // that quietly began accepting '1' would read back as unset and
            // display as ON while the product behaved as OFF.
            ['i18n.enabled', 'true', true],
            ['i18n.enabled', 'false', true],
            ['i18n.enabled', '1', false],
            ['i18n.enabled', 'off', false],
            // The invitation lifetime is bounded at both ends, mirroring
            // InvitationService's clamp — a value the API accepts must be one
            // the service honours rather than silently narrows.
            ['auth.invitation_ttl_days', '7', true],
            ['auth.invitation_ttl_days', '1', true],
            ['auth.invitation_ttl_days', '90', true],
            ['auth.invitation_ttl_days', '0', false],
            ['auth.invitation_ttl_days', '91', false],
            ['auth.invitation_ttl_days', 'a week', false],
            // #1068. The literal 'true'/'false' contract, for the reason
            // i18n.enabled has its own line below: a key that quietly began
            // accepting '1' would read back as unset and display as ON while
            // the product behaved as OFF.
            ['ui.hide_dates', 'true', true],
            ['ui.hide_dates', 'false', true],
            ['ui.hide_dates', '1', false],
            ['ui.hide_dates', 'yes', false],
            ['branding_favicon', 'anything', false],
            ['not_a_setting_at_all', 'x', false],
        ];
    }

    /**
     * @dataProvider validationSamples
     */
    public function testCoreValidationOutcomesAreUnchanged(string $key, string $value, bool $valid): void
    {
        $reason = SettingsRegistry::validate($key, $value);

        if ($valid) {
            self::assertNull($reason, "{$key} should accept '{$value}'");
        } else {
            self::assertIsString($reason, "{$key} should reject '{$value}'");
        }
    }

    /**
     * The same sample, through the UNION with a plugin loaded. This is the
     * assertion that actually protects the 330 call sites: whatever a plugin
     * declares, a core key's answer does not move.
     *
     * @dataProvider validationSamples
     */
    public function testCoreValidationOutcomesSurviveAPluginContribution(string $key, string $value, bool $valid): void
    {
        $plugins = new PluginSettingsRegistry();
        $plugins->register('Acme', [
            'site_name' => ['type' => 'string', 'default' => 'hijacked', 'admin' => true],
            'timezone' => ['type' => 'string', 'default' => 'hijacked', 'admin' => true],
        ]);
        $catalog = new SettingsCatalog($plugins);

        $reason = $catalog->validate($key, $value);

        if ($valid) {
            self::assertNull($reason, "{$key} should still accept '{$value}'");
        } else {
            self::assertIsString($reason, "{$key} should still reject '{$value}'");
        }
        self::assertSame(SettingsRegistry::validate($key, $value), $reason);
    }

    public function testNormalisationIsUnchanged(): void
    {
        self::assertSame('Acme', SettingsRegistry::normalize('site_name', '  Acme  '));
        self::assertSame('  x  ', SettingsRegistry::normalize('mail.footer_text', '  x  '));
        self::assertSame('  x  ', SettingsRegistry::normalize('unknown_key', '  x  '));
    }

    public function testGlobalOnlyFeatureFlagAndAssetPartitionsAreUnchanged(): void
    {
        // Governance keys stay operator-level: a per-tenant override would be
        // inert and misleading.
        self::assertTrue(SettingsRegistry::isGlobalOnly('auth.self_registration_enabled'));
        self::assertTrue(SettingsRegistry::isGlobalOnly('mail.transport'));
        self::assertTrue(SettingsRegistry::isGlobalOnly('documents.render_enabled'));
        self::assertFalse(SettingsRegistry::isGlobalOnly('site_name'));
        // The render LIMITS are deliberately tenant-overridable while the master
        // switch is not.
        self::assertFalse(SettingsRegistry::isGlobalOnly('documents.render_max_rows'));

        // Languages are a PLATFORM catalogue with no tenant_id column, and the
        // sign-in screen resolves one before any tenant is known.
        self::assertTrue(SettingsRegistry::isGlobalOnly('i18n.enabled'));

        self::assertTrue(SettingsRegistry::isFeatureFlag('mcp.enabled'));
        self::assertFalse(SettingsRegistry::isFeatureFlag('mail.events.welcome_enabled'));
        self::assertFalse(SettingsRegistry::isFeatureFlag('storage.s3.path_style'));
        // Marked isFlag so it appears on the admin Feature Flags tab, which
        // filters on that field alone rather than a hardcoded key list.
        self::assertTrue(SettingsRegistry::isFeatureFlag('i18n.enabled'));

        self::assertSame('asset', SettingsRegistry::kindFor('branding_favicon'));
        self::assertSame('text', SettingsRegistry::kindFor('site_name'));
        self::assertSame(['site_name', 'timezone', 'locale', 'support_email'], array_slice(SettingsRegistry::textKeys(), 0, 4));
        self::assertNotContains('branding_favicon', SettingsRegistry::textKeys());
    }

    public function testDescriptorShapeIsUnchanged(): void
    {
        $byKey = [];
        foreach (SettingsRegistry::describe() as $entry) {
            $byKey[$entry['key']] = $entry;
        }

        self::assertSame(
            ['key' => 'site_name', 'type' => 'string', 'default' => 'Whity'],
            $byKey['site_name'] ?? null
        );
        self::assertSame(
            ['key' => 'mail.transport', 'type' => 'enum', 'default' => 'none', 'options' => ['none', 'log', 'smtp']],
            $byKey['mail.transport'] ?? null
        );
        self::assertSame(
            ['key' => 'mcp.enabled', 'type' => 'bool', 'default' => 'false', 'isFlag' => true],
            $byKey['mcp.enabled'] ?? null
        );
        // The i18n master switch, exactly as the Feature Flags tab receives it:
        // a bool flag whose published DEFAULT is 'true'. This is the descriptor
        // an upgrading deployment reads before it has stored any value, so the
        // literal here is what keeps a shipped feature switched on.
        self::assertSame(
            ['key' => 'i18n.enabled', 'type' => 'bool', 'default' => 'true', 'isFlag' => true],
            $byKey['i18n.enabled'] ?? null
        );
    }
}
