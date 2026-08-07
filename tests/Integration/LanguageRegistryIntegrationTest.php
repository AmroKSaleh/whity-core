<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\i18n\LanguageRegistry;
use Whity\Core\i18n\LanguageRepository;
use Whity\Core\i18n\TranslationRepository;
use Whity\Core\Tenant\TenantContext;
use Whity\Core\Tenant\StaticTenantContextAdapter;

/**
 * Integration tests for LanguageRegistry against real SQLite and PostgreSQL databases.
 *
 * These tests verify the complete i18n system end-to-end: migrations run correctly,
 * repositories query real data, and the registry performs as expected. Tests run
 * against both in-memory SQLite and real PostgreSQL (when PHPUNIT_PG_DSN is set).
 *
 * Coverage areas:
 * - Migrations create correct schema (languages, translations tables)
 * - Base languages (English, Arabic) are seeded
 * - Repository queries work correctly (find by code, find by ID, etc.)
 * - Registry boot loads all translations
 * - Translation lookup respects tenant scoping
 * - Fallback chain works (tenant override → system default → key)
 */
class LanguageRegistryIntegrationTest extends TestCase
{
    private PDO $pdo;
    private LanguageRepository $languageRepository;
    private TranslationRepository $translationRepository;
    private LanguageRegistry $registry;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make();
        $this->languageRepository = new LanguageRepository($this->pdo);
        $this->translationRepository = new TranslationRepository($this->pdo);

        // Use system tenant (0) for most tests.
        TenantContext::reset();
        TenantContext::setTenantId(0);

        $this->registry = new LanguageRegistry(
            $this->languageRepository,
            $this->translationRepository,
            new StaticTenantContextAdapter(),
        );
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    /**
     * Test: migrations create the languages table correctly.
     */
    public function testMigrationsCreateLanguagesTable(): void
    {
        $stmt = $this->pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='languages'");
        $languages = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;

        $this->assertNotNull($languages, 'languages table should exist');
    }

    /**
     * Test: migrations create the translations table correctly.
     */
    public function testMigrationsCreateTranslationsTable(): void
    {
        $stmt = $this->pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='translations'");
        $translations = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;

        $this->assertNotNull($translations, 'translations table should exist');
    }

    /**
     * Test: seed migration inserts English and Arabic languages.
     */
    public function testSeedMigrationInsertsBaseLanguages(): void
    {
        $english = $this->languageRepository->findByCode('en');
        $this->assertNotNull($english);
        $this->assertSame('en', $english->code);
        $this->assertSame('English', $english->name);
        $this->assertTrue($english->enabled);

        $arabic = $this->languageRepository->findByCode('ar');
        $this->assertNotNull($arabic);
        $this->assertSame('ar', $arabic->code);
        $this->assertSame('العربية', $arabic->name);
        $this->assertTrue($arabic->enabled);
    }

    /**
     * Test: findAll() returns all languages.
     */
    public function testFindAllLanguages(): void
    {
        $languages = $this->languageRepository->findAll(enabled: true);

        $this->assertCount(2, $languages);
        $this->assertArrayHasKey('en', $languages);
        $this->assertArrayHasKey('ar', $languages);
    }

    /**
     * Test: findByCode() retrieves a language by code.
     */
    public function testFindLanguageByCode(): void
    {
        $english = $this->languageRepository->findByCode('en');
        $this->assertNotNull($english);
        $this->assertSame(1, $english->id);
        $this->assertSame('en', $english->code);

        $notFound = $this->languageRepository->findByCode('fr');
        $this->assertNull($notFound);
    }

    /**
     * Test: findById() retrieves a language by ID.
     */
    public function testFindLanguageById(): void
    {
        $english = $this->languageRepository->findById(1);
        $this->assertNotNull($english);
        $this->assertSame('en', $english->code);

        $notFound = $this->languageRepository->findById(9999);
        $this->assertNull($notFound);
    }

    /**
     * Test: insertions and queries of translations work correctly.
     */
    public function testInsertAndQueryTranslations(): void
    {
        $english = $this->languageRepository->findByCode('en');
        $this->assertNotNull($english);

        // Insert a test translation.
        $this->pdo->prepare(
            'INSERT INTO translations (language_id, domain, key, translation, tenant_id, created_at, updated_at)
             VALUES (:language_id, :domain, :key, :translation, NULL, NOW(), NOW())'
        )->execute([
            ':language_id' => $english->id,
            ':domain' => 'common',
            ':key' => 'button.save',
            ':translation' => 'Save',
        ]);

        // Query it back via the repository.
        $translations = $this->translationRepository->findByLanguageAndDomain($english->id, 'common');
        $this->assertCount(1, $translations);
        $this->assertArrayHasKey('button.save', $translations);
        $this->assertSame('Save', $translations['button.save']->translation);
    }

    /**
     * Test: registry boots and loads translations correctly.
     */
    public function testRegistryBootLoadsTranslations(): void
    {
        $english = $this->languageRepository->findByCode('en');
        $this->assertNotNull($english);

        // Insert test translations.
        $this->pdo->prepare(
            'INSERT INTO translations (language_id, domain, key, translation, tenant_id, created_at, updated_at)
             VALUES (:language_id, :domain, :key, :translation, NULL, NOW(), NOW())'
        )->execute([
            ':language_id' => $english->id,
            ':domain' => 'common',
            ':key' => 'button.save',
            ':translation' => 'Save',
        ]);

        $this->pdo->prepare(
            'INSERT INTO translations (language_id, domain, key, translation, tenant_id, created_at, updated_at)
             VALUES (:language_id, :domain, :key, :translation, NULL, NOW(), NOW())'
        )->execute([
            ':language_id' => $english->id,
            ':domain' => 'common',
            ':key' => 'button.cancel',
            ':translation' => 'Cancel',
        ]);

        // Boot the registry and verify translations are loaded.
        $this->registry->boot();

        $this->assertSame('Save', $this->registry->translate('en', 'common', 'button.save', 0));
        $this->assertSame('Cancel', $this->registry->translate('en', 'common', 'button.cancel', 0));
    }

    /**
     * Test: registry handles tenant overrides correctly.
     */
    public function testRegistryHandlesTenantOverrides(): void
    {
        $english = $this->languageRepository->findByCode('en');
        $this->assertNotNull($english);

        // Insert system default translation.
        $this->pdo->prepare(
            'INSERT INTO translations (language_id, domain, key, translation, tenant_id, created_at, updated_at)
             VALUES (:language_id, :domain, :key, :translation, NULL, NOW(), NOW())'
        )->execute([
            ':language_id' => $english->id,
            ':domain' => 'common',
            ':key' => 'button.save',
            ':translation' => 'Save',
        ]);

        // Insert tenant 123 override.
        $this->pdo->prepare(
            'INSERT INTO translations (language_id, domain, key, translation, tenant_id, created_at, updated_at)
             VALUES (:language_id, :domain, :key, :translation, :tenant_id, NOW(), NOW())'
        )->execute([
            ':language_id' => $english->id,
            ':domain' => 'common',
            ':key' => 'button.save',
            ':translation' => 'Store',
            ':tenant_id' => 123,
        ]);

        $this->registry->boot();

        // System tenant (0) sees the system default.
        $this->assertSame('Save', $this->registry->translate('en', 'common', 'button.save', 0));

        // Tenant 123 sees the override.
        $this->assertSame('Store', $this->registry->translate('en', 'common', 'button.save', 123));

        // Tenant 456 (no override) sees the system default.
        $this->assertSame('Save', $this->registry->translate('en', 'common', 'button.save', 456));
    }

    /**
     * Test: registry enforces cross-tenant isolation.
     */
    public function testCrossTenantIsolation(): void
    {
        $english = $this->languageRepository->findByCode('en');
        $this->assertNotNull($english);

        // Insert system default.
        $this->pdo->prepare(
            'INSERT INTO translations (language_id, domain, key, translation, tenant_id, created_at, updated_at)
             VALUES (:language_id, :domain, :key, :translation, NULL, NOW(), NOW())'
        )->execute([
            ':language_id' => $english->id,
            ':domain' => 'common',
            ':key' => 'text',
            ':translation' => 'Default',
        ]);

        // Tenant 100 has an override.
        $this->pdo->prepare(
            'INSERT INTO translations (language_id, domain, key, translation, tenant_id, created_at, updated_at)
             VALUES (:language_id, :domain, :key, :translation, :tenant_id, NOW(), NOW())'
        )->execute([
            ':language_id' => $english->id,
            ':domain' => 'common',
            ':key' => 'text',
            ':translation' => 'Tenant 100',
            ':tenant_id' => 100,
        ]);

        // Tenant 200 has a different override.
        $this->pdo->prepare(
            'INSERT INTO translations (language_id, domain, key, translation, tenant_id, created_at, updated_at)
             VALUES (:language_id, :domain, :key, :translation, :tenant_id, NOW(), NOW())'
        )->execute([
            ':language_id' => $english->id,
            ':domain' => 'common',
            ':key' => 'text',
            ':translation' => 'Tenant 200',
            ':tenant_id' => 200,
        ]);

        $this->registry->boot();

        // Verify cross-tenant isolation.
        $this->assertSame('Default', $this->registry->translate('en', 'common', 'text', 0));
        $this->assertSame('Tenant 100', $this->registry->translate('en', 'common', 'text', 100));
        $this->assertSame('Tenant 200', $this->registry->translate('en', 'common', 'text', 200));
    }

    /**
     * Test: registry handles both English and Arabic correctly.
     */
    public function testMultiLanguageSupport(): void
    {
        $english = $this->languageRepository->findByCode('en');
        $arabic = $this->languageRepository->findByCode('ar');
        $this->assertNotNull($english);
        $this->assertNotNull($arabic);

        // Insert English translation.
        $this->pdo->prepare(
            'INSERT INTO translations (language_id, domain, key, translation, tenant_id, created_at, updated_at)
             VALUES (:language_id, :domain, :key, :translation, NULL, NOW(), NOW())'
        )->execute([
            ':language_id' => $english->id,
            ':domain' => 'common',
            ':key' => 'button.save',
            ':translation' => 'Save',
        ]);

        // Insert Arabic translation.
        $this->pdo->prepare(
            'INSERT INTO translations (language_id, domain, key, translation, tenant_id, created_at, updated_at)
             VALUES (:language_id, :domain, :key, :translation, NULL, NOW(), NOW())'
        )->execute([
            ':language_id' => $arabic->id,
            ':domain' => 'common',
            ':key' => 'button.save',
            ':translation' => 'حفظ',
        ]);

        $this->registry->boot();

        // Verify both languages work.
        $this->assertSame('Save', $this->registry->translate('en', 'common', 'button.save', 0));
        $this->assertSame('حفظ', $this->registry->translate('ar', 'common', 'button.save', 0));
    }

    /**
     * Test: fallback to English works when a non-English translation is missing.
     */
    public function testFallbackToEnglish(): void
    {
        $english = $this->languageRepository->findByCode('en');
        $this->assertNotNull($english);

        // Insert only English translation (no Arabic).
        $this->pdo->prepare(
            'INSERT INTO translations (language_id, domain, key, translation, tenant_id, created_at, updated_at)
             VALUES (:language_id, :domain, :key, :translation, NULL, NOW(), NOW())'
        )->execute([
            ':language_id' => $english->id,
            ':domain' => 'common',
            ':key' => 'button.save',
            ':translation' => 'Save',
        ]);

        $this->registry->boot();

        // Requesting Arabic should fall back to English.
        $this->assertSame('Save', $this->registry->translate('ar', 'common', 'button.save', 0));
    }

    /**
     * Test: fallback to key itself when no translation exists.
     */
    public function testFallbackToKey(): void
    {
        $this->registry->boot();

        // Request a key that doesn't exist anywhere.
        $this->assertSame('unknown.key', $this->registry->translate('en', 'common', 'unknown.key', 0));
    }

    /**
     * Test: invalidateCache() reloads from database.
     */
    public function testInvalidateCacheReloadsFromDatabase(): void
    {
        $english = $this->languageRepository->findByCode('en');
        $this->assertNotNull($english);

        $this->registry->boot();

        // Verify translation doesn't exist yet.
        $this->assertSame('button.new', $this->registry->translate('en', 'common', 'button.new', 0));

        // Insert a new translation in the database.
        $this->pdo->prepare(
            'INSERT INTO translations (language_id, domain, key, translation, tenant_id, created_at, updated_at)
             VALUES (:language_id, :domain, :key, :translation, NULL, NOW(), NOW())'
        )->execute([
            ':language_id' => $english->id,
            ':domain' => 'common',
            ':key' => 'button.new',
            ':translation' => 'New',
        ]);

        // Invalidate cache and verify the new translation is now visible.
        $this->registry->invalidateCache();
        $this->assertSame('New', $this->registry->translate('en', 'common', 'button.new', 0));
    }

    /**
     * Test: getTranslator() returns a callable that works correctly.
     */
    public function testGetTranslator(): void
    {
        $english = $this->languageRepository->findByCode('en');
        $this->assertNotNull($english);

        $this->pdo->prepare(
            'INSERT INTO translations (language_id, domain, key, translation, tenant_id, created_at, updated_at)
             VALUES (:language_id, :domain, :key, :translation, NULL, NOW(), NOW())'
        )->execute([
            ':language_id' => $english->id,
            ':domain' => 'common',
            ':key' => 'button.save',
            ':translation' => 'Save',
        ]);

        $this->registry->boot();

        $t = $this->registry->getTranslator('common');
        $this->assertIsCallable($t);
        $this->assertSame('Save', $t('button.save'));
    }

    /**
     * Test: performance on real database — 1000 translations, 1000+ lookups.
     */
    public function testPerformanceOnRealDatabase(): void
    {
        $english = $this->languageRepository->findByCode('en');
        $this->assertNotNull($english);

        // Insert 1000 translations.
        $stmt = $this->pdo->prepare(
            'INSERT INTO translations (language_id, domain, key, translation, tenant_id, created_at, updated_at)
             VALUES (:language_id, :domain, :key, :translation, NULL, NOW(), NOW())'
        );

        for ($i = 0; $i < 1000; $i++) {
            $stmt->execute([
                ':language_id' => $english->id,
                ':domain' => 'common',
                ':key' => 'key_' . $i,
                ':translation' => 'Value ' . $i,
            ]);
        }

        // Boot the registry.
        $start = microtime(true);
        $this->registry->boot();
        $bootTime = microtime(true) - $start;

        // Boot should be fast (< 500ms even with 1000 translations).
        $this->assertLessThan(0.5, $bootTime, "Boot took {$bootTime}s, expected < 0.5s");

        // Perform 1000+ lookups.
        $start = microtime(true);
        for ($i = 0; $i < 1000; $i++) {
            $this->registry->translate('en', 'common', 'key_' . $i, 0);
        }
        $lookupTime = microtime(true) - $start;

        // Lookups should be very fast (< 10ms for 1000 lookups).
        $this->assertLessThan(0.01, $lookupTime, "1000 lookups took {$lookupTime}s, expected < 0.01s");
    }
}
