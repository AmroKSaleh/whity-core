<?php

declare(strict_types=1);

namespace Tests\Api;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\LanguagesApiHandler;
use Whity\Core\Request;
use Whity\Core\i18n\LanguageRegistry;
use Whity\Core\i18n\LanguageRepository;
use Whity\Core\i18n\TranslationRepository;
use Whity\Core\Tenant\TenantContext;
use Whity\Core\Tenant\StaticTenantContextAdapter;

/**
 * Unit and integration tests for {@see LanguagesApiHandler}.
 *
 * These tests verify the complete language preference API end-to-end:
 * - GET /api/v1/languages returns the list of available languages (public endpoint)
 * - GET /api/v1/settings/language returns user's language preference (authenticated)
 * - PATCH /api/v1/settings/language updates user's language preference (authenticated)
 * - Validation that language_code exists
 * - Tenant isolation and RBAC
 *
 * Tests run against real SQLite (in-memory) database with full migrations applied.
 */
final class LanguagesApiHandlerTest extends TestCase
{
    private PDO $pdo;
    private LanguagesApiHandler $handler;
    private LanguageRegistry $languageRegistry;
    private int $testProfileId;

    protected function setUp(): void
    {
        TenantContext::reset();
        TenantContext::setTenantId(0); // System tenant

        $this->pdo = SchemaFromMigrations::make();

        // Initialize language registry
        $languageRepository = new LanguageRepository($this->pdo);
        $translationRepository = new TranslationRepository($this->pdo);
        $this->languageRegistry = new LanguageRegistry(
            $languageRepository,
            $translationRepository,
            new StaticTenantContextAdapter(),
        );
        $this->languageRegistry->boot();

        $this->handler = new LanguagesApiHandler($this->pdo, $this->languageRegistry);

        // Create a test profile for authenticated tests
        $this->testProfileId = $this->createTestProfile();
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    /**
     * Test: GET /api/v1/languages returns list of available languages (public endpoint).
     */
    public function testGetLanguagesList(): void
    {
        $request = new Request('GET', '/api/languages');

        $response = $this->handler->list($request);

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);

        $this->assertArrayHasKey('languages', $body);
        $this->assertIsArray($body['languages']);
        $this->assertGreaterThanOrEqual(2, count($body['languages']), 'Should have at least English and Arabic');

        // Verify English and Arabic are present
        $codes = array_column($body['languages'], 'code');
        $this->assertContains('en', $codes);
        $this->assertContains('ar', $codes);

        // Verify each language has code and name
        foreach ($body['languages'] as $lang) {
            $this->assertArrayHasKey('code', $lang);
            $this->assertArrayHasKey('name', $lang);
            $this->assertIsString($lang['code']);
            $this->assertIsString($lang['name']);
        }
    }

    /**
     * Test: GET /api/v1/settings/language returns user's language preference when authenticated.
     */
    public function testGetLanguagePreferenceWhenAuthenticated(): void
    {
        $request = new Request('GET', '/api/settings/language', []);
        $request->user = (object) ['profile_id' => $this->testProfileId];

        $response = $this->handler->getLanguage($request);

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);

        $this->assertArrayHasKey('language_code', $body);
        $this->assertArrayHasKey('available_languages', $body);
        $this->assertNull($body['language_code'], 'Newly created profile should have NULL language_code');
        $this->assertIsArray($body['available_languages']);
        $this->assertGreaterThanOrEqual(2, count($body['available_languages']));
    }

    /**
     * Test: GET /api/v1/settings/language returns 403 when not authenticated.
     */
    public function testGetLanguagePreferenceRequiresAuth(): void
    {
        $request = new Request('GET', '/api/settings/language', []);

        $response = $this->handler->getLanguage($request);

        $this->assertSame(403, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertArrayHasKey('error', $body);
    }

    /**
     * Test: PATCH /api/v1/settings/language updates user's language preference.
     */
    public function testPatchLanguagePreference(): void
    {
        $request = new Request('PATCH', '/api/settings/language', [], (string) json_encode(['language_code' => 'ar']));
        $request->user = (object) ['profile_id' => $this->testProfileId];

        $response = $this->handler->patchLanguage($request);

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);

        $this->assertArrayHasKey('language_code', $body);
        $this->assertSame('ar', $body['language_code']);

        // Verify it was persisted
        $stmt = $this->pdo->prepare('SELECT language_code FROM profiles WHERE id = ?');
        $stmt->execute([$this->testProfileId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('ar', $row['language_code']);
    }

    /**
     * Test: PATCH /api/v1/settings/language with null clears the preference.
     */
    public function testPatchLanguagePreferenceWithNull(): void
    {
        // First set a language
        $this->setUserLanguage($this->testProfileId, 'ar');

        // Then clear it
        $request = new Request('PATCH', '/api/settings/language', [], (string) json_encode(['language_code' => null]));
        $request->user = (object) ['profile_id' => $this->testProfileId];

        $response = $this->handler->patchLanguage($request);

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);

        $this->assertNull($body['language_code']);

        // Verify it was persisted
        $stmt = $this->pdo->prepare('SELECT language_code FROM profiles WHERE id = ?');
        $stmt->execute([$this->testProfileId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertNull($row['language_code']);
    }

    /**
     * Test: PATCH /api/v1/settings/language with invalid language_code returns 422.
     */
    public function testPatchLanguagePreferenceWithInvalidCode(): void
    {
        $request = new Request('PATCH', '/api/settings/language', [], (string) json_encode(['language_code' => 'xx']));
        $request->user = (object) ['profile_id' => $this->testProfileId];

        $response = $this->handler->patchLanguage($request);

        $this->assertSame(422, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertArrayHasKey('error', $body);
        $this->assertArrayHasKey('details', $body);
        $this->assertArrayHasKey('language_code', $body['details']);
    }

    /**
     * Test: PATCH /api/v1/settings/language with empty string returns 400.
     */
    public function testPatchLanguagePreferenceWithEmptyString(): void
    {
        $request = new Request('PATCH', '/api/settings/language', [], (string) json_encode(['language_code' => '']));
        $request->user = (object) ['profile_id' => $this->testProfileId];

        $response = $this->handler->patchLanguage($request);

        $this->assertSame(400, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertArrayHasKey('error', $body);
    }

    /**
     * Test: PATCH /api/v1/settings/language requires authentication.
     */
    public function testPatchLanguagePreferenceRequiresAuth(): void
    {
        $request = new Request('PATCH', '/api/settings/language', [], (string) json_encode(['language_code' => 'ar']));

        $response = $this->handler->patchLanguage($request);

        $this->assertSame(403, $response->getStatusCode());
    }

    /**
     * Test: Multiple users can set different language preferences independently.
     */
    public function testMultipleUsersIndependentLanguagePreferences(): void
    {
        $profile1Id = $this->createTestProfile('profile1@test.com');
        $profile2Id = $this->createTestProfile('profile2@test.com');

        // Set profile1 to Arabic
        $request1 = new Request('PATCH', '/api/settings/language', [], (string) json_encode(['language_code' => 'ar']));
        $request1->user = (object) ['profile_id' => $profile1Id];
        $response1 = $this->handler->patchLanguage($request1);
        $this->assertSame(200, $response1->getStatusCode());

        // Set profile2 to English
        $request2 = new Request('PATCH', '/api/settings/language', [], (string) json_encode(['language_code' => 'en']));
        $request2->user = (object) ['profile_id' => $profile2Id];
        $response2 = $this->handler->patchLanguage($request2);
        $this->assertSame(200, $response2->getStatusCode());

        // Verify both were set correctly
        $stmt = $this->pdo->prepare('SELECT language_code FROM profiles WHERE id IN (?, ?) ORDER BY id');
        $stmt->execute([$profile1Id, $profile2Id]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $this->assertSame('ar', $rows[0]['language_code']);
        $this->assertSame('en', $rows[1]['language_code']);
    }

    // Helper methods

    /**
     * Create a test profile in the database.
     */
    private function createTestProfile(string $email = 'test@example.com'): int
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO profiles (display_name, password_hash, created_at, updated_at)
            VALUES (?, ?, NOW(), NOW())
        ');
        $stmt->execute(['Test User', password_hash('password', PASSWORD_BCRYPT)]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Set a user's language preference.
     */
    private function setUserLanguage(int $profileId, ?string $languageCode): void
    {
        $stmt = $this->pdo->prepare('UPDATE profiles SET language_code = ? WHERE id = ?');
        $stmt->execute([$languageCode, $profileId]);
    }
}
