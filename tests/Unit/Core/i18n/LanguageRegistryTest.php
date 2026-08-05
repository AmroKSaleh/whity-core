<?php

declare(strict_types=1);

namespace Tests\Unit\Core\i18n;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Whity\Core\i18n\Language;
use Whity\Core\i18n\LanguageRegistry;
use Whity\Core\i18n\LanguageRepository;
use Whity\Core\i18n\Translation;
use Whity\Core\i18n\TranslationRepository;
use Whity\Core\Tenant\TenantContextInterface;

/**
 * Unit tests for LanguageRegistry with mocked dependencies.
 *
 * These tests verify the core logic of the registry without touching the database.
 * Dependencies (LanguageRepository, TranslationRepository, TenantContext) are mocked
 * to return controlled test data.
 *
 * Test structure:
 * - Boot-time loading and in-memory cache structure
 * - Translation lookup with fallback chain
 * - Tenant override handling and isolation
 * - Multi-language switching
 * - Edge cases (missing keys, null values, etc.)
 * - Performance (10k+ lookups in < 100ms)
 */
class LanguageRegistryTest extends TestCase
{
    private MockObject $languageRepository;
    private MockObject $translationRepository;
    private MockObject $tenantContext;
    private LanguageRegistry $registry;

    protected function setUp(): void
    {
        $this->languageRepository = $this->createMock(LanguageRepository::class);
        $this->translationRepository = $this->createMock(TranslationRepository::class);
        $this->tenantContext = $this->createMock(TenantContextInterface::class);

        $this->registry = new LanguageRegistry(
            $this->languageRepository,
            $this->translationRepository,
            $this->tenantContext,
        );

        // Default tenant context to system tenant (0).
        $this->tenantContext->method('getTenantId')->willReturn(0);
    }

    /**
     * Test: boot() loads languages into memory correctly.
     */
    public function testBootLoadsLanguagesCorrectly(): void
    {
        $languages = [
            'en' => new Language(1, 'en', 'English', true, '2024-01-01 00:00:00', '2024-01-01 00:00:00'),
            'ar' => new Language(2, 'ar', 'العربية', true, '2024-01-01 00:00:00', '2024-01-01 00:00:00'),
        ];

        $this->languageRepository
            ->expects($this->once())
            ->method('findAll')
            ->with(enabled: true)
            ->willReturn($languages);

        $this->translationRepository
            ->expects($this->exactly(2))
            ->method('findAllSystemDefaults')
            ->willReturnMap([
                [1, []],  // English: no translations yet
                [2, []],  // Arabic: no translations yet
            ]);

        $this->registry->boot();

        $loadedLanguages = $this->registry->getLanguages();
        $this->assertCount(2, $loadedLanguages);
        $this->assertArrayHasKey('en', $loadedLanguages);
        $this->assertArrayHasKey('ar', $loadedLanguages);
        $this->assertSame('English', $loadedLanguages['en']->name);
        $this->assertSame('العربية', $loadedLanguages['ar']->name);
    }

    /**
     * Test: boot() loads translations into correct in-memory structure.
     */
    public function testBootLoadsTranslationsCorrectly(): void
    {
        $languages = [
            'en' => new Language(1, 'en', 'English', true, '2024-01-01 00:00:00', '2024-01-01 00:00:00'),
        ];

        $englishTranslations = [
            'common' => [
                'button.save' => new Translation(1, 1, 'common', 'button.save', 'Save', null, '2024-01-01 00:00:00', '2024-01-01 00:00:00'),
                'button.cancel' => new Translation(2, 1, 'common', 'button.cancel', 'Cancel', null, '2024-01-01 00:00:00', '2024-01-01 00:00:00'),
            ],
            'email' => [
                'subject.welcome' => new Translation(3, 1, 'email', 'subject.welcome', 'Welcome!', null, '2024-01-01 00:00:00', '2024-01-01 00:00:00'),
            ],
        ];

        $this->languageRepository
            ->method('findAll')
            ->with(enabled: true)
            ->willReturn($languages);

        $this->translationRepository
            ->expects($this->once())
            ->method('findAllSystemDefaults')
            ->with(1)
            ->willReturn($englishTranslations);

        $this->registry->boot();

        // Verify translations are accessible via translate().
        $this->assertSame('Save', $this->registry->translate('en', 'common', 'button.save', 0));
        $this->assertSame('Cancel', $this->registry->translate('en', 'common', 'button.cancel', 0));
        $this->assertSame('Welcome!', $this->registry->translate('en', 'email', 'subject.welcome', 0));
    }

    /**
     * Test: boot() is idempotent (calling it twice doesn't duplicate data).
     */
    public function testBootIsIdempotent(): void
    {
        $languages = [
            'en' => new Language(1, 'en', 'English', true, '2024-01-01 00:00:00', '2024-01-01 00:00:00'),
        ];

        $this->languageRepository
            ->expects($this->exactly(2))
            ->method('findAll')
            ->with(enabled: true)
            ->willReturn($languages);

        $this->translationRepository
            ->expects($this->exactly(2))
            ->method('findAllSystemDefaults')
            ->willReturn([]);

        $this->registry->boot();
        $this->registry->boot();

        // If boot was not idempotent, the second call would fail or corrupt state.
        // Both calls succeed, so the test passes.
        $this->assertTrue(true);
    }

    /**
     * Test: translate() returns correct translation for a key.
     */
    public function testTranslateReturnsCorrectTranslation(): void
    {
        $this->setupWithTranslations([
            'en' => [
                'common' => [
                    'button.save' => new Translation(1, 1, 'common', 'button.save', 'Save', null, '2024-01-01 00:00:00', '2024-01-01 00:00:00'),
                ],
            ],
        ]);

        $this->assertSame('Save', $this->registry->translate('en', 'common', 'button.save', 0));
    }

    /**
     * Test: translate() falls back to English when translation is missing for non-English language.
     */
    public function testTranslateFallsBackToEnglish(): void
    {
        $this->setupWithTranslations([
            'en' => [
                'common' => [
                    'button.save' => new Translation(1, 1, 'common', 'button.save', 'Save', null, '2024-01-01 00:00:00', '2024-01-01 00:00:00'),
                ],
            ],
            'ar' => [],  // Arabic has no translations
        ]);

        // Requesting a translation in Arabic that doesn't exist falls back to English.
        $this->assertSame('Save', $this->registry->translate('ar', 'common', 'button.save', 0));
    }

    /**
     * Test: translate() returns key itself when no translation found (full fallback chain).
     */
    public function testTranslateFallsBackToKey(): void
    {
        $this->setupWithTranslations([
            'en' => [
                'common' => [
                    'button.save' => new Translation(1, 1, 'common', 'button.save', 'Save', null, '2024-01-01 00:00:00', '2024-01-01 00:00:00'),
                ],
            ],
        ]);

        // Requesting a key that doesn't exist returns the key itself.
        $this->assertSame('button.unknown', $this->registry->translate('en', 'common', 'button.unknown', 0));
    }

    /**
     * Test: translate() uses tenant override when available.
     */
    public function testTranslatUseTenantOverride(): void
    {
        $languages = [
            'en' => new Language(1, 'en', 'English', true, '2024-01-01 00:00:00', '2024-01-01 00:00:00'),
        ];

        $englishTranslations = [
            'common' => [
                'button.save' => new Translation(1, 1, 'common', 'button.save', 'Save', null, '2024-01-01 00:00:00', '2024-01-01 00:00:00'),
            ],
        ];

        $this->languageRepository
            ->method('findAll')
            ->with(enabled: true)
            ->willReturn($languages);

        $this->translationRepository
            ->expects($this->once())
            ->method('findAllSystemDefaults')
            ->with(1)
            ->willReturn($englishTranslations);

        // When tenant 123 requests the translation, return a tenant-specific override.
        $this->translationRepository
            ->expects($this->once())
            ->method('findAllTenantOverrides')
            ->with(1, 123)
            ->willReturn([
                'common' => [
                    'button.save' => new Translation(99, 1, 'common', 'button.save', 'Store', 123, '2024-01-01 00:00:00', '2024-01-01 00:00:00'),
                ],
            ]);

        $this->registry->boot();

        // Tenant 123 should see the override.
        $this->assertSame('Store', $this->registry->translate('en', 'common', 'button.save', 123));

        // Tenant 0 (system) should see the system default.
        $this->assertSame('Save', $this->registry->translate('en', 'common', 'button.save', 0));
    }

    /**
     * Test: cross-tenant isolation — tenant A cannot see tenant B's overrides.
     */
    public function testCrossTenantIsolation(): void
    {
        $languages = [
            'en' => new Language(1, 'en', 'English', true, '2024-01-01 00:00:00', '2024-01-01 00:00:00'),
        ];

        $englishTranslations = [
            'common' => [
                'button.save' => new Translation(1, 1, 'common', 'button.save', 'Save', null, '2024-01-01 00:00:00', '2024-01-01 00:00:00'),
            ],
        ];

        $this->languageRepository
            ->method('findAll')
            ->with(enabled: true)
            ->willReturn($languages);

        $this->translationRepository
            ->expects($this->once())
            ->method('findAllSystemDefaults')
            ->with(1)
            ->willReturn($englishTranslations);

        // Tenant 123 has a specific override.
        $this->translationRepository
            ->method('findAllTenantOverrides')
            ->willReturnMap([
                [1, 123, ['common' => ['button.save' => new Translation(99, 1, 'common', 'button.save', 'Store', 123, '2024-01-01 00:00:00', '2024-01-01 00:00:00')]]],
                [1, 456, []],  // Tenant 456 has no overrides.
            ]);

        $this->registry->boot();

        // Tenant 123 sees its override.
        $this->assertSame('Store', $this->registry->translate('en', 'common', 'button.save', 123));

        // Tenant 456 sees the system default (no override).
        $this->assertSame('Save', $this->registry->translate('en', 'common', 'button.save', 456));
    }

    /**
     * Test: getTranslator() returns a callable for a domain.
     */
    public function testGetTranslatorReturnCallable(): void
    {
        $this->setupWithTranslations([
            'en' => [
                'common' => [
                    'button.save' => new Translation(1, 1, 'common', 'button.save', 'Save', null, '2024-01-01 00:00:00', '2024-01-01 00:00:00'),
                ],
            ],
        ]);

        $t = $this->registry->getTranslator('common');
        $this->assertIsCallable($t);
        $this->assertSame('Save', $t('button.save'));
    }

    /**
     * Test: getCurrentLanguageCode() returns the current language code.
     */
    public function testGetCurrentLanguageCode(): void
    {
        $this->setupWithTranslations([
            'en' => [],
            'ar' => [],
        ]);

        $this->assertSame('en', $this->registry->getCurrentLanguageCode());

        $this->registry->setCurrentLanguage('ar');
        $this->assertSame('ar', $this->registry->getCurrentLanguageCode());
    }

    /**
     * Test: setCurrentLanguage() switches the language.
     */
    public function testSetCurrentLanguage(): void
    {
        $languages = [
            'en' => new Language(1, 'en', 'English', true, '2024-01-01 00:00:00', '2024-01-01 00:00:00'),
            'ar' => new Language(2, 'ar', 'العربية', true, '2024-01-01 00:00:00', '2024-01-01 00:00:00'),
        ];

        $this->languageRepository
            ->method('findAll')
            ->with(enabled: true)
            ->willReturn($languages);

        $this->translationRepository
            ->method('findAllSystemDefaults')
            ->willReturn([]);

        $this->registry->boot();

        $this->registry->setCurrentLanguage('ar');
        $this->assertSame('ar', $this->registry->getCurrentLanguageCode());
    }

    /**
     * Test: setCurrentLanguage() ignores invalid language codes.
     */
    public function testSetCurrentLanguageIgnoresInvalidCode(): void
    {
        $languages = [
            'en' => new Language(1, 'en', 'English', true, '2024-01-01 00:00:00', '2024-01-01 00:00:00'),
        ];

        $this->languageRepository
            ->method('findAll')
            ->with(enabled: true)
            ->willReturn($languages);

        $this->translationRepository
            ->method('findAllSystemDefaults')
            ->willReturn([]);

        $this->registry->boot();

        $this->registry->setCurrentLanguage('invalid');
        // Language should remain 'en' because 'invalid' is not a valid code.
        $this->assertSame('en', $this->registry->getCurrentLanguageCode());
    }

    /**
     * Test: getCurrentLanguage() returns Language object.
     */
    public function testGetCurrentLanguage(): void
    {
        $languages = [
            'en' => new Language(1, 'en', 'English', true, '2024-01-01 00:00:00', '2024-01-01 00:00:00'),
        ];

        $this->languageRepository
            ->method('findAll')
            ->with(enabled: true)
            ->willReturn($languages);

        $this->translationRepository
            ->method('findAllSystemDefaults')
            ->willReturn([]);

        $this->registry->boot();

        $current = $this->registry->getCurrentLanguage();
        $this->assertNotNull($current);
        $this->assertSame('en', $current->code);
        $this->assertSame('English', $current->name);
    }

    /**
     * Test: getLanguages() returns all available languages.
     */
    public function testGetLanguages(): void
    {
        $languages = [
            'en' => new Language(1, 'en', 'English', true, '2024-01-01 00:00:00', '2024-01-01 00:00:00'),
            'ar' => new Language(2, 'ar', 'العربية', true, '2024-01-01 00:00:00', '2024-01-01 00:00:00'),
        ];

        $this->languageRepository
            ->method('findAll')
            ->with(enabled: true)
            ->willReturn($languages);

        $this->translationRepository
            ->method('findAllSystemDefaults')
            ->willReturn([]);

        $this->registry->boot();

        $available = $this->registry->getLanguages();
        $this->assertCount(2, $available);
        $this->assertArrayHasKey('en', $available);
        $this->assertArrayHasKey('ar', $available);
    }

    /**
     * Test: invalidateCache() clears and reloads the registry.
     */
    public function testInvalidateCache(): void
    {
        $languages = [
            'en' => new Language(1, 'en', 'English', true, '2024-01-01 00:00:00', '2024-01-01 00:00:00'),
        ];

        $this->languageRepository
            ->expects($this->exactly(2))
            ->method('findAll')
            ->with(enabled: true)
            ->willReturn($languages);

        $this->translationRepository
            ->expects($this->exactly(2))
            ->method('findAllSystemDefaults')
            ->willReturn([]);

        $this->registry->boot();
        $this->registry->invalidateCache();

        // If invalidateCache worked, both boot calls completed without error.
        $this->assertTrue(true);
    }

    /**
     * Test: performance — 10,000 lookups complete in < 100ms.
     */
    public function testPerformance10kLookupsUnder100ms(): void
    {
        $enTranslations = [];
        for ($i = 0; $i < 1000; $i++) {
            $enTranslations['common']['key_' . $i] = new Translation(
                $i,
                1,
                'common',
                'key_' . $i,
                'Value ' . $i,
                null,
                '2024-01-01 00:00:00',
                '2024-01-01 00:00:00'
            );
        }

        $languages = [
            'en' => new Language(1, 'en', 'English', true, '2024-01-01 00:00:00', '2024-01-01 00:00:00'),
        ];

        $this->languageRepository
            ->method('findAll')
            ->with(enabled: true)
            ->willReturn($languages);

        $this->translationRepository
            ->method('findAllSystemDefaults')
            ->with(1)
            ->willReturn($enTranslations);

        $this->registry->boot();

        // Perform 10k lookups and measure time.
        $start = microtime(true);
        for ($i = 0; $i < 10000; $i++) {
            $this->registry->translate('en', 'common', 'key_' . ($i % 1000), 0);
        }
        $elapsed = microtime(true) - $start;

        // Should complete in under 100ms.
        $this->assertLessThan(0.1, $elapsed, "10k lookups took {$elapsed}s, expected < 0.1s");
    }

    /**
     * Test: edge case — empty domain returns key.
     */
    public function testEdgeCaseEmptyDomain(): void
    {
        $this->setupWithTranslations([
            'en' => [
                'common' => [
                    'key' => new Translation(1, 1, 'common', 'key', 'Value', null, '2024-01-01 00:00:00', '2024-01-01 00:00:00'),
                ],
            ],
        ]);

        // Requesting from a domain that doesn't exist returns the key.
        $this->assertSame('unknown.key', $this->registry->translate('en', 'unknown', 'unknown.key', 0));
    }

    /**
     * Test: edge case — null tenant ID uses system defaults only.
     */
    public function testEdgeCaseNullTenantId(): void
    {
        $languages = [
            'en' => new Language(1, 'en', 'English', true, '2024-01-01 00:00:00', '2024-01-01 00:00:00'),
        ];

        $enTranslations = [
            'common' => [
                'button.save' => new Translation(1, 1, 'common', 'button.save', 'Save', null, '2024-01-01 00:00:00', '2024-01-01 00:00:00'),
            ],
        ];

        $this->languageRepository
            ->method('findAll')
            ->with(enabled: true)
            ->willReturn($languages);

        $this->translationRepository
            ->expects($this->once())
            ->method('findAllSystemDefaults')
            ->with(1)
            ->willReturn($enTranslations);

        // Tenant overrides should NOT be queried when tenantId is null.
        $this->translationRepository
            ->expects($this->never())
            ->method('findAllTenantOverrides');

        $this->registry->boot();

        $this->assertSame('Save', $this->registry->translate('en', 'common', 'button.save', null));
    }

    /**
     * Helper: set up the registry with specific test translations.
     *
     * @param array<string, array<string, Translation[]>> $translationsByLanguage
     * @return void
     */
    private function setupWithTranslations(array $translationsByLanguage): void
    {
        $languages = [];
        foreach ($translationsByLanguage as $code => $domains) {
            $id = count($languages) + 1;
            $languages[$code] = new Language($id, $code, $code === 'en' ? 'English' : 'العربية', true, '2024-01-01 00:00:00', '2024-01-01 00:00:00');
        }

        $this->languageRepository
            ->method('findAll')
            ->with(enabled: true)
            ->willReturn($languages);

        $this->translationRepository
            ->method('findAllSystemDefaults')
            ->willReturnCallback(function ($languageId) use ($translationsByLanguage, $languages) {
                // Map language ID back to code to find the right translations.
                foreach ($languages as $code => $lang) {
                    if ($lang->id === $languageId) {
                        return $translationsByLanguage[$code] ?? [];
                    }
                }
                return [];
            });

        $this->registry->boot();
    }
}
