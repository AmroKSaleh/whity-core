<?php

declare(strict_types=1);

namespace Tests\Core\Settings;

use PHPUnit\Framework\TestCase;
use Whity\Core\Settings\InvalidSettingDeclarationException;
use Whity\Core\Settings\PluginSettingsRegistry;
use Whity\Core\Settings\SettingDefinition;
use Whity\Core\Settings\SettingsRegistry;

/**
 * #713 item 1: the plugin-declared half of the settings catalogue.
 *
 * The failure this exists to remove is a specific one, and worth restating
 * because every test below is aimed at it: a plugin that cannot contribute keys
 * builds a private table shaped exactly like `tenant_settings` with no declared
 * keys and no validation, and in that table a typo writes a NEW INVISIBLE ROW
 * rather than failing. So the bar here is not "the registry stores things" — it
 * is that a wrong declaration is refused loudly, at load time, with a message
 * that says what was wrong.
 */
final class PluginSettingsRegistryTest extends TestCase
{
    private function registry(): PluginSettingsRegistry
    {
        return new PluginSettingsRegistry();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function declaration(): array
    {
        return [
            'sync_interval' => [
                'type' => 'int',
                'default' => 300,
                'min' => 60,
                'max' => 86400,
                'label' => ['en' => 'Sync interval', 'AR' => 'فترة المزامنة'],
                'description' => 'Seconds between synchronisations.',
                'admin' => true,
            ],
            'mode' => [
                'type' => 'enum',
                'options' => ['off', 'shadow', 'live'],
                'default' => 'off',
            ],
        ];
    }

    // ==================== namespacing ====================

    public function testDeclaredKeysAreNamespacedUnderTheLoaderSuppliedSource(): void
    {
        $registry = $this->registry();

        $registered = $registry->register('DemoCatalog', $this->declaration());

        self::assertSame(['democatalog:sync_interval', 'democatalog:mode'], $registered);
        self::assertTrue($registry->has('democatalog:sync_interval'));
        // The BARE key is not registered: the prefix is not decoration, it is
        // the identity.
        self::assertFalse($registry->has('sync_interval'));
    }

    public function testTwoPluginsDeclaringTheSameBareKeyDoNotCollide(): void
    {
        $registry = $this->registry();

        $registry->register('Alpha', ['mode' => ['type' => 'string', 'default' => 'a']]);
        $registry->register('Beta', ['mode' => ['type' => 'string', 'default' => 'b']]);

        self::assertSame('a', $registry->get('alpha:mode')?->defaultValue());
        self::assertSame('b', $registry->get('beta:mode')?->defaultValue());
        self::assertSame(['alpha:mode', 'beta:mode'], $registry->keys());
    }

    /**
     * The important half of the previous test: two plugins that DID collide
     * would share one row per tenant, so each would silently read the other's
     * configuration. Proving the keys differ is proving the storage differs.
     */
    public function testCollidingPluginsGetSeparateStorageKeys(): void
    {
        $registry = $this->registry();

        $registry->register('Alpha', ['mode' => ['type' => 'string', 'default' => '']]);
        $registry->register('Beta', ['mode' => ['type' => 'string', 'default' => '']]);

        self::assertNotSame(
            PluginSettingsRegistry::canonicalKey('Alpha', 'mode'),
            PluginSettingsRegistry::canonicalKey('Beta', 'mode')
        );
    }

    public function testCanonicalKeyHelperMatchesWhatRegistrationStored(): void
    {
        $registry = $this->registry();
        $registry->register('Acme\\Widgets\\Plugin', ['mode' => ['type' => 'string', 'default' => '']]);

        // The helper exists so a plugin never concatenates the prefix by hand;
        // if it disagreed with registration, every value a plugin wrote would be
        // written under a key nothing reads.
        self::assertTrue($registry->has(PluginSettingsRegistry::canonicalKey('Acme\\Widgets\\Plugin', 'mode')));
    }

    // ==================== a plugin cannot shadow core ====================

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function coreShadowAttempts(): array
    {
        return [
            'bare core key' => ['Whatever', 'site_name'],
            'dotted core key' => ['Mail', 'transport'],
            'a plugin named like the core prefix' => ['Storage', 'driver'],
            'governance flag' => ['Auth', 'sso_enabled'],
        ];
    }

    /**
     * @dataProvider coreShadowAttempts
     */
    public function testAPluginCannotShadowACoreKey(string $source, string $bareKey): void
    {
        $registry = $this->registry();

        $registry->register($source, [$bareKey => ['type' => 'string', 'default' => 'hijacked']]);

        // Whatever it registered, it is NOT a core key, and core's catalogue is
        // untouched. The separator is what makes this structural: no core key
        // contains a colon and every plugin key contains exactly one.
        foreach ($registry->keys() as $key) {
            self::assertFalse(
                SettingsRegistry::isKnown($key),
                "Plugin key {$key} collides with a core setting key"
            );
            self::assertStringContainsString(':', $key);
        }
    }

    public function testAPluginCannotWriteItsOwnPrefix(): void
    {
        $this->expectException(InvalidSettingDeclarationException::class);
        $this->expectExceptionMessageMatches('/no colon/');

        // A key carrying a colon would let a plugin choose its own namespace and
        // therefore claim another plugin's — or core's — keys.
        $this->registry()->register('Acme', ['core:site_name' => ['type' => 'string', 'default' => 'x']]);
    }

    // ==================== declaration validation ====================

    public function testTypeMustBeOneOfTheDeclarableTypes(): void
    {
        $this->expectException(InvalidSettingDeclarationException::class);
        $this->expectExceptionMessageMatches('/must be one of string, bool, int, enum/');

        $this->registry()->register('Acme', ['k' => ['type' => 'json', 'default' => '{}']]);
    }

    public function testAssetTypeIsNotDeclarable(): void
    {
        // Asset values are storage references written by the branding upload
        // endpoints — core-only surfaces a plugin has no path into.
        $this->expectException(InvalidSettingDeclarationException::class);

        $this->registry()->register('Acme', ['k' => ['type' => 'asset', 'default' => '']]);
    }

    public function testDefaultIsRequired(): void
    {
        $this->expectException(InvalidSettingDeclarationException::class);
        $this->expectExceptionMessageMatches("/'default' is required/");

        $this->registry()->register('Acme', ['k' => ['type' => 'string']]);
    }

    /**
     * The loudest rule in the file. A default is what the key resolves to on
     * every fresh install and after every clear; if it fails the declaration's
     * OWN rules the key is born invalid and can never be reset to anything the
     * host accepts.
     */
    public function testADefaultThatFailsItsOwnValidationIsRefused(): void
    {
        $this->expectException(InvalidSettingDeclarationException::class);
        $this->expectExceptionMessageMatches('/can never be reset/');

        $this->registry()->register('Acme', [
            'mode' => ['type' => 'enum', 'options' => ['on', 'off'], 'default' => 'maybe'],
        ]);
    }

    public function testADefaultOutsideDeclaredIntBoundsIsRefused(): void
    {
        $this->expectException(InvalidSettingDeclarationException::class);
        $this->expectExceptionMessageMatches('/can never be reset/');

        $this->registry()->register('Acme', [
            'n' => ['type' => 'int', 'default' => 5, 'min' => 10],
        ]);
    }

    public function testEnumRequiresOptions(): void
    {
        $this->expectException(InvalidSettingDeclarationException::class);
        $this->expectExceptionMessageMatches("/'options' is required for type 'enum'/");

        $this->registry()->register('Acme', ['k' => ['type' => 'enum', 'default' => 'a']]);
    }

    public function testOptionsOnANonEnumIsRefusedRatherThanIgnored(): void
    {
        // Silently dropping it would leave the author believing the value set is
        // constrained when any string is accepted.
        $this->expectException(InvalidSettingDeclarationException::class);
        $this->expectExceptionMessageMatches("/only meaningful for type 'enum'/");

        $this->registry()->register('Acme', [
            'k' => ['type' => 'string', 'default' => 'a', 'options' => ['a', 'b']],
        ]);
    }

    public function testBoundsOnANonIntAreRefused(): void
    {
        $this->expectException(InvalidSettingDeclarationException::class);
        $this->expectExceptionMessageMatches("/only meaningful for type 'int'/");

        $this->registry()->register('Acme', ['k' => ['type' => 'string', 'default' => '', 'min' => 1]]);
    }

    public function testMinAboveMaxIsRefused(): void
    {
        $this->expectException(InvalidSettingDeclarationException::class);
        $this->expectExceptionMessageMatches("/must not exceed 'max'/");

        $this->registry()->register('Acme', [
            'k' => ['type' => 'int', 'default' => 5, 'min' => 10, 'max' => 1],
        ]);
    }

    public function testAnUnrecognisedFieldIsRefusedRatherThanIgnored(): void
    {
        // `maxlength` for `max_length` is a constraint the author believes is
        // enforced and is not — exactly the class of silent miss this whole
        // change exists to eliminate.
        $this->expectException(InvalidSettingDeclarationException::class);
        $this->expectExceptionMessageMatches('/not a recognised declaration field/');

        $this->registry()->register('Acme', [
            'k' => ['type' => 'string', 'default' => '', 'maxlength' => 10],
        ]);
    }

    public function testAPatternCarryingItsOwnDelimitersIsRefused(): void
    {
        $this->expectException(InvalidSettingDeclarationException::class);
        $this->expectExceptionMessageMatches('/must not contain a delimiter/');

        $this->registry()->register('Acme', [
            'k' => ['type' => 'string', 'default' => 'a', 'pattern' => '/^a$/i'],
        ]);
    }

    public function testAnUncompilablePatternIsRefusedAtDeclarationTime(): void
    {
        $this->expectException(InvalidSettingDeclarationException::class);
        $this->expectExceptionMessageMatches('/not a valid regular expression/');

        $this->registry()->register('Acme', [
            'k' => ['type' => 'string', 'default' => 'a', 'pattern' => '^([a-z]$'],
        ]);
    }

    public function testAKeyTooLongForTheSettingsColumnIsRefused(): void
    {
        // `setting_key` is VARCHAR(100) in BOTH settings tables. An over-long key
        // would declare cleanly, appear on the admin screen, and fail on every
        // write forever.
        $long = 'a' . str_repeat('b', PluginSettingsRegistry::MAX_KEY_LENGTH);

        $this->expectException(InvalidSettingDeclarationException::class);
        $this->expectExceptionMessageMatches('/could never be written/');

        $this->registry()->register('Acme', [$long => ['type' => 'string', 'default' => '']]);
    }

    public function testDuplicateKeysFromOneSourceAreRefused(): void
    {
        $registry = $this->registry();
        $registry->register('Acme', ['k' => ['type' => 'string', 'default' => 'first']]);

        $this->expectException(InvalidSettingDeclarationException::class);
        $this->expectExceptionMessageMatches('/already registered/');

        $registry->register('Acme', ['k' => ['type' => 'string', 'default' => 'second']]);
    }

    public function testANamelessSourceIsRefused(): void
    {
        $this->expectException(InvalidSettingDeclarationException::class);
        $this->expectExceptionMessageMatches('/no usable namespace prefix/');

        $this->registry()->register('___', ['k' => ['type' => 'string', 'default' => '']]);
    }

    // ==================== secrets ====================

    /**
     * @return list<array{0: string}>
     */
    public static function secretFields(): array
    {
        return [['secret'], ['encrypted'], ['write_only']];
    }

    /**
     * Secret-shaped settings are NOT supported in this pass, and the declaration
     * is refused rather than silently downgraded to a readable string. Settings
     * are stored as TEXT and served by an API gated on `settings:read`; a
     * credential declared here would be readable by everyone holding it. Failing
     * at load time is how a plugin author finds that out before shipping, rather
     * than after.
     *
     * @dataProvider secretFields
     */
    public function testSecretShapedDeclarationsAreRefusedNotDowngraded(string $field): void
    {
        $this->expectException(InvalidSettingDeclarationException::class);
        $this->expectExceptionMessageMatches('/secret-shaped settings are not supported/');

        $this->registry()->register('Acme', [
            'api_token' => ['type' => 'string', 'default' => '', $field => true],
        ]);
    }

    // ==================== per-setting rejection ====================

    /**
     * Rejection is PER SETTING: a plugin with six keys and one typo keeps the
     * five that are fine. The loader logs the first failure against the plugin;
     * it does not discard the rest of the declaration.
     */
    public function testAMalformedEntryDoesNotDiscardTheEntriesValidatedBeforeIt(): void
    {
        $registry = $this->registry();

        try {
            $registry->register('Acme', [
                'good_one' => ['type' => 'string', 'default' => 'a'],
                'bad_one' => ['type' => 'nonsense', 'default' => 'b'],
                'never_reached' => ['type' => 'string', 'default' => 'c'],
            ]);
            self::fail('Expected the malformed entry to be refused');
        } catch (InvalidSettingDeclarationException) {
            // expected
        }

        self::assertTrue($registry->has('acme:good_one'));
        self::assertFalse($registry->has('acme:bad_one'));
    }

    // ==================== typing, defaults, validation ====================

    public function testDeclaredKeysAreTypedAndDefaulted(): void
    {
        $registry = $this->registry();
        $registry->register('DemoCatalog', $this->declaration());

        $interval = $registry->get('democatalog:sync_interval');
        self::assertInstanceOf(SettingDefinition::class, $interval);
        self::assertSame('int', $interval->type());
        // Declared as the int 300; stored as the canonical TEXT form.
        self::assertSame('300', $interval->defaultValue());
        self::assertSame('DemoCatalog', $interval->source());
        self::assertTrue($interval->isAdminVisible());

        $mode = $registry->get('democatalog:mode');
        self::assertNotNull($mode);
        self::assertSame('enum', $mode->type());
        self::assertSame(['off', 'shadow', 'live'], $mode->options());
        self::assertFalse($mode->isAdminVisible(), 'admin visibility is opt-in, not the default');
    }

    public function testBooleanDefaultsAreStoredInCoreCanonicalForm(): void
    {
        $registry = $this->registry();
        $registry->register('Acme', [
            'on' => ['type' => 'bool', 'default' => true],
            'off' => ['type' => 'bool', 'default' => false],
        ]);

        // PHP's own (string) false is '', which would be a silent wrong answer;
        // core stores the literal words and so does this.
        self::assertSame('true', $registry->get('acme:on')?->defaultValue());
        self::assertSame('false', $registry->get('acme:off')?->defaultValue());
    }

    public function testLabelsFallBackToTheBareKeyAndAreLowercasedByLocale(): void
    {
        $registry = $this->registry();
        $registry->register('DemoCatalog', $this->declaration());

        self::assertSame(
            ['en' => 'Sync interval', 'ar' => 'فترة المزامنة'],
            $registry->get('democatalog:sync_interval')?->labels()
        );
        // No label declared: a screen still has something to render.
        self::assertSame(['en' => 'mode'], $registry->get('democatalog:mode')?->labels());
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: bool}>
     */
    public static function validationCases(): array
    {
        return [
            'int in range' => ['sync_interval', '600', true],
            'int below min' => ['sync_interval', '5', false],
            'int above max' => ['sync_interval', '99999999', false],
            'int not a number' => ['sync_interval', 'soon', false],
            'int empty' => ['sync_interval', '', false],
            'enum member' => ['mode', 'live', true],
            'enum non-member' => ['mode', 'LIVE', false],
        ];
    }

    /**
     * @dataProvider validationCases
     */
    public function testDeclaredKeysValidateExactlyAsCoreKeysDo(string $bare, string $value, bool $valid): void
    {
        $registry = $this->registry();
        $registry->register('DemoCatalog', $this->declaration());

        $reason = $registry->get("democatalog:{$bare}")?->validate($value);

        if ($valid) {
            self::assertNull($reason);
        } else {
            self::assertIsString($reason);
            self::assertNotSame('', $reason, 'a refusal must say why — the API relays it as a field detail');
        }
    }

    public function testStringConstraintsApplyToTheNormalisedValue(): void
    {
        $registry = $this->registry();
        $registry->register('Acme', [
            'code' => ['type' => 'string', 'default' => 'abc', 'max_length' => 3, 'pattern' => '^[a-z]+$'],
        ]);
        $definition = $registry->get('acme:code');
        self::assertNotNull($definition);

        // Trimmed first, so incidental whitespace is not counted against the
        // limit and cannot fail the pattern.
        self::assertNull($definition->validate('  abc  '));
        self::assertSame('abc', $definition->normalize('  abc  '));
        self::assertIsString($definition->validate('abcd'));
        self::assertIsString($definition->validate('ABC'));
    }

    public function testMultibyteLengthIsCountedInCharactersNotBytes(): void
    {
        $registry = $this->registry();
        $registry->register('Acme', [
            'name' => ['type' => 'string', 'default' => '', 'max_length' => 4],
        ]);

        // Four Arabic characters is eight bytes. The limit an operator declared
        // is the one they can see, not the one the encoding produces — a
        // byte-counted limit would refuse this and allow the six-character one.
        $fourChars = 'اهلا';
        self::assertSame(4, mb_strlen($fourChars));
        self::assertSame(8, strlen($fourChars));
        self::assertNull($registry->get('acme:name')?->validate($fourChars));
        self::assertIsString($registry->get('acme:name')?->validate('اهلابكم'));
    }

    public function testOnlyStringsAreTrimmedOnNormalisation(): void
    {
        $registry = $this->registry();
        $registry->register('Acme', [
            's' => ['type' => 'string', 'default' => ''],
            'n' => ['type' => 'int', 'default' => 1],
        ]);

        self::assertSame('x', $registry->get('acme:s')?->normalize('  x  '));
        self::assertSame('7', $registry->get('acme:n')?->normalize('7'));
    }

    public function testDescriptorCarriesAttributionAndPresentation(): void
    {
        $registry = $this->registry();
        $registry->register('DemoCatalog', $this->declaration());

        $definition = $registry->get('democatalog:sync_interval');
        self::assertNotNull($definition);
        $descriptor = $definition->describe();

        self::assertSame('democatalog:sync_interval', $descriptor['key']);
        self::assertSame('int', $descriptor['type']);
        self::assertSame('300', $descriptor['default']);
        // An operator looking at `democatalog:sync_interval` on a shared screen
        // needs to know which plugin owns it before changing it.
        self::assertSame('DemoCatalog', $descriptor['source']);
        self::assertSame('Seconds between synchronisations.', $descriptor['description'] ?? null);
        self::assertArrayNotHasKey('options', $descriptor, 'options belong to enums only');
    }

    public function testKeysBySourceReportsOnlyThatSourcesKeys(): void
    {
        $registry = $this->registry();
        $registry->register('Alpha', ['a' => ['type' => 'string', 'default' => '']]);
        $registry->register('Beta', ['b' => ['type' => 'string', 'default' => '']]);

        self::assertSame(['alpha:a'], $registry->keysBySource('Alpha'));
        self::assertSame(['beta:b'], $registry->keysBySource('Beta'));
        self::assertSame([], $registry->keysBySource('Gamma'));
    }

    public function testAnEmptyDeclarationRegistersNothingAndDoesNotFail(): void
    {
        $registry = $this->registry();

        self::assertSame([], $registry->register('Acme', []));
        self::assertSame([], $registry->keys());
    }
}
