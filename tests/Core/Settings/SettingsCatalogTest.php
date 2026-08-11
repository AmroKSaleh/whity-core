<?php

declare(strict_types=1);

namespace Tests\Core\Settings;

use PHPUnit\Framework\TestCase;
use Whity\Core\Settings\PluginSettingsRegistry;
use Whity\Core\Settings\SettingsCatalog;
use Whity\Core\Settings\SettingsRegistry;

/**
 * #713 item 1: the SEAM between core's static catalogue and the plugin one.
 *
 * The design constraint this class exists to satisfy, restated because every
 * test below is a consequence of it: the catalogue must not become mutable
 * static state (statics are per FrankenPHP worker — PR #701, PR #727 — and a key
 * missing from one worker's catalogue does not throw, it reads as "unknown
 * setting"), AND {@see SettingsRegistry}'s ~330 static call sites must keep
 * behaving exactly as they do today.
 *
 * Both hold only if the halves stay separate: core's contribution stays a
 * compile-time `private const`, plugin contributions live in an instance, and
 * this class is the ONE place they are unioned. So the two properties under test
 * are "the union sees both" and "core sees only itself".
 */
final class SettingsCatalogTest extends TestCase
{
    private function catalogWithPlugin(): SettingsCatalog
    {
        $plugins = new PluginSettingsRegistry();
        $plugins->register('DemoCatalog', [
            'sync_interval' => [
                'type' => 'int',
                'default' => 300,
                'min' => 60,
                'admin' => true,
            ],
            'mode' => [
                'type' => 'enum',
                'options' => ['off', 'live'],
                'default' => 'off',
                'admin' => true,
            ],
            'internal_cursor' => [
                'type' => 'string',
                'default' => '',
            ],
            'endpoint' => [
                'type' => 'string',
                'default' => '',
                'global_only' => true,
                'admin' => true,
            ],
        ]);

        return new SettingsCatalog($plugins);
    }

    // ==================== the union sees both halves ====================

    public function testKnownSpansCoreAndPluginKeys(): void
    {
        $catalog = $this->catalogWithPlugin();

        self::assertTrue($catalog->isKnown(SettingsRegistry::SITE_NAME));
        self::assertTrue($catalog->isKnown('democatalog:sync_interval'));
        self::assertFalse($catalog->isKnown('democatalog:nonexistent'));
        self::assertFalse($catalog->isKnown('typo_nobody_declared'));
    }

    public function testKeysListsCoreFirstThenPluginContributions(): void
    {
        $catalog = $this->catalogWithPlugin();
        $keys = $catalog->keys();

        // Core first is not cosmetic: it is the order the service resolves in
        // and the order the screen renders, and an operator looks for site_name
        // before anything a plugin added.
        self::assertSame(SettingsRegistry::keys(), array_slice($keys, 0, count(SettingsRegistry::keys())));
        self::assertSame(
            ['democatalog:sync_interval', 'democatalog:mode', 'democatalog:internal_cursor', 'democatalog:endpoint'],
            array_slice($keys, count(SettingsRegistry::keys()))
        );
    }

    public function testTypeDefaultAndOptionsResolveForPluginKeys(): void
    {
        $catalog = $this->catalogWithPlugin();

        self::assertSame('int', $catalog->typeFor('democatalog:sync_interval'));
        self::assertSame('300', $catalog->defaultFor('democatalog:sync_interval'));
        self::assertSame(['off', 'live'], $catalog->optionsFor('democatalog:mode'));
        self::assertNull($catalog->optionsFor('democatalog:sync_interval'));
        self::assertSame('text', $catalog->kindFor('democatalog:sync_interval'));
    }

    public function testValidationAndNormalisationResolveForPluginKeys(): void
    {
        $catalog = $this->catalogWithPlugin();

        self::assertNull($catalog->validate('democatalog:sync_interval', '600'));
        self::assertIsString($catalog->validate('democatalog:sync_interval', '5'));
        self::assertIsString($catalog->validate('democatalog:mode', 'sideways'));
        self::assertSame('x', $catalog->normalize('democatalog:internal_cursor', '  x  '));
    }

    public function testAnUnknownKeyFailsValidationWithCoresOwnMessage(): void
    {
        $catalog = $this->catalogWithPlugin();

        // Byte-identical to the core registry's wording, so a caller cannot tell
        // from the response whether it addressed a core key or a plugin one.
        self::assertSame(
            SettingsRegistry::validate('nope', 'x'),
            $catalog->validate('nope', 'x')
        );
    }

    public function testDefaultForAnUnknownKeyThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->catalogWithPlugin()->defaultFor('democatalog:nope');
    }

    public function testDefaultsMapCarriesBothHalves(): void
    {
        $defaults = $this->catalogWithPlugin()->defaults();

        self::assertSame('Whity', $defaults[SettingsRegistry::SITE_NAME] ?? null);
        self::assertSame('300', $defaults['democatalog:sync_interval'] ?? null);
    }

    public function testSourceAttributionDistinguishesCoreFromPlugin(): void
    {
        $catalog = $this->catalogWithPlugin();

        self::assertSame('core', $catalog->sourceOf(SettingsRegistry::SITE_NAME));
        self::assertSame('DemoCatalog', $catalog->sourceOf('democatalog:sync_interval'));
        self::assertNull($catalog->sourceOf('democatalog:nope'));
        self::assertTrue($catalog->isCoreKey(SettingsRegistry::SITE_NAME));
        self::assertFalse($catalog->isCoreKey('democatalog:sync_interval'));
    }

    // ==================== core keeps resolving core-only ====================

    /**
     * The other half of the seam, and the reason this change touches ~330 call
     * sites and modifies none of them. `MailerFactory` asking about
     * `mail.transport` and `StorageDriverFactory` asking about `storage.driver`
     * are core code asking about CORE configuration; widening what they can see
     * could only ever let a plugin key answer a question core asked about
     * itself.
     */
    public function testTheStaticCoreRegistryNeverSeesPluginKeys(): void
    {
        $this->catalogWithPlugin();

        self::assertFalse(SettingsRegistry::isKnown('democatalog:sync_interval'));
        self::assertNotContains('democatalog:sync_interval', SettingsRegistry::keys());
        self::assertSame(
            'Unknown setting key: democatalog:sync_interval',
            SettingsRegistry::validate('democatalog:sync_interval', '600')
        );
    }

    public function testACoreOnlyCatalogueBehavesExactlyLikeTheStaticRegistry(): void
    {
        $catalog = SettingsCatalog::coreOnly();

        self::assertSame(SettingsRegistry::keys(), $catalog->keys());
        self::assertSame(SettingsRegistry::textKeys(), $catalog->textKeys());
        self::assertSame(SettingsRegistry::tenantTextKeys(), $catalog->tenantTextKeys());
        self::assertSame(SettingsRegistry::defaults(), $catalog->defaults());
        self::assertSame(SettingsRegistry::describe(), $catalog->describe());
        self::assertSame(SettingsRegistry::describeText(), $catalog->describeText());
        self::assertSame(SettingsRegistry::describeTenantText(), $catalog->describeTenantText());
        self::assertFalse($catalog->isKnown('democatalog:sync_interval'));
    }

    /**
     * With plugins loaded, every question about a CORE key must still get
     * core's answer. A union that changed one core answer would be a behaviour
     * change smuggled in behind an additive feature.
     */
    public function testEveryCoreKeyAnswersIdenticallyWithPluginsLoaded(): void
    {
        $catalog = $this->catalogWithPlugin();

        foreach (SettingsRegistry::keys() as $key) {
            self::assertTrue($catalog->isKnown($key), $key);
            self::assertSame(SettingsRegistry::defaultFor($key), $catalog->defaultFor($key), $key);
            self::assertSame(SettingsRegistry::typeFor($key), $catalog->typeFor($key), $key);
            self::assertSame(SettingsRegistry::kindFor($key), $catalog->kindFor($key), $key);
            self::assertSame(SettingsRegistry::optionsFor($key), $catalog->optionsFor($key), $key);
            self::assertSame(SettingsRegistry::isGlobalOnly($key), $catalog->isGlobalOnly($key), $key);
            self::assertSame(SettingsRegistry::isFeatureFlag($key), $catalog->isFeatureFlag($key), $key);
            // Identical answers, whatever they are — including the asset keys,
            // which core deliberately refuses on the text path.
            $default = SettingsRegistry::defaultFor($key);
            self::assertSame(
                SettingsRegistry::validate($key, $default),
                $catalog->validate($key, $default),
                "{$key} validates differently through the union"
            );
            self::assertSame(
                SettingsRegistry::normalize($key, $default),
                $catalog->normalize($key, $default),
                "{$key} normalises differently through the union"
            );
        }
    }

    // ==================== the admin surface is opt-in ====================

    public function testOnlyOptedInPluginKeysReachTheAdminSurface(): void
    {
        $catalog = $this->catalogWithPlugin();
        $textKeys = $catalog->textKeys();

        self::assertContains('democatalog:sync_interval', $textKeys);
        self::assertContains('democatalog:mode', $textKeys);
        self::assertContains('democatalog:endpoint', $textKeys);
        // Declared without `admin => true`: stored, resolved and validated
        // identically, simply not published on a screen gated by CORE
        // settings permissions.
        self::assertNotContains('democatalog:internal_cursor', $textKeys);
        // …and still fully known, which is the whole point of the distinction.
        self::assertTrue($catalog->isKnown('democatalog:internal_cursor'));
        self::assertContains('democatalog:internal_cursor', $catalog->keys());
    }

    public function testGlobalOnlyPluginKeysAreExcludedFromTheTenantSurface(): void
    {
        $catalog = $this->catalogWithPlugin();

        self::assertContains('democatalog:endpoint', $catalog->textKeys());
        self::assertNotContains('democatalog:endpoint', $catalog->tenantTextKeys());
        self::assertContains('democatalog:sync_interval', $catalog->tenantTextKeys());
        self::assertTrue($catalog->isGlobalOnly('democatalog:endpoint'));
    }

    public function testCoreTextKeysAreUnchangedByPluginContributions(): void
    {
        $catalog = $this->catalogWithPlugin();

        $coreSlice = array_slice($catalog->textKeys(), 0, count(SettingsRegistry::textKeys()));
        self::assertSame(SettingsRegistry::textKeys(), $coreSlice);

        $tenantSlice = array_slice($catalog->tenantTextKeys(), 0, count(SettingsRegistry::tenantTextKeys()));
        self::assertSame(SettingsRegistry::tenantTextKeys(), $tenantSlice);
    }

    public function testDescribeExposesTheFullCatalogueWhileDescribeTextExposesTheSurface(): void
    {
        $catalog = $this->catalogWithPlugin();

        $described = array_column($catalog->describe(), 'key');
        // The DISCOVERY surface: everything, opted-in or not.
        self::assertContains('democatalog:internal_cursor', $described);

        $surface = array_column($catalog->describeText(), 'key');
        self::assertNotContains('democatalog:internal_cursor', $surface);
        self::assertContains('democatalog:mode', $surface);
    }

    public function testPluginDescriptorsCarryTheSameFieldsAClientAlreadySwitchesOn(): void
    {
        $catalog = $this->catalogWithPlugin();

        $byKey = [];
        foreach ($catalog->describeText() as $entry) {
            $byKey[$entry['key']] = $entry;
        }

        $mode = $byKey['democatalog:mode'] ?? [];
        self::assertSame('enum', $mode['type'] ?? null);
        self::assertSame('off', $mode['default'] ?? null);
        self::assertSame(['off', 'live'], $mode['options'] ?? null);
        self::assertSame('DemoCatalog', $mode['source'] ?? null);
    }

    /**
     * A plugin cannot nominate itself into a CURATED list. The Feature Flags tab
     * is a hand-picked set of core capability toggles — chosen one at a time for
     * what an operator would recognise as a feature — not "every boolean".
     */
    public function testAPluginKeyIsNeverAFeatureFlag(): void
    {
        $plugins = new PluginSettingsRegistry();
        $plugins->register('Acme', ['enabled' => ['type' => 'bool', 'default' => true, 'admin' => true]]);
        $catalog = new SettingsCatalog($plugins);

        self::assertFalse($catalog->isFeatureFlag('acme:enabled'));
        foreach ($catalog->describeText() as $entry) {
            if ($entry['key'] === 'acme:enabled') {
                self::assertArrayNotHasKey('isFlag', $entry);
            }
        }
    }

    public function testEveryCoreTextKeyIsAdminVisible(): void
    {
        $catalog = $this->catalogWithPlugin();

        foreach (SettingsRegistry::textKeys() as $key) {
            self::assertTrue($catalog->isAdminVisible($key), $key);
        }
    }
}
