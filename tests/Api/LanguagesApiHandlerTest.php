<?php

declare(strict_types=1);

namespace Tests\Api;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\I18nAdminTestSeed;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\LanguagesApiHandler;
use Whity\Auth\RoleChecker;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\RBAC\PermissionRegistry;
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
    private LanguageRepository $languageRepository;
    private int $testProfileId;

    protected function setUp(): void
    {
        RoleChecker::clearCache();
        TenantContext::reset();
        TenantContext::setTenantId(0); // System tenant

        $this->pdo = SchemaFromMigrations::make();

        // Initialize language registry
        $this->languageRepository = new LanguageRepository($this->pdo);
        $translationRepository = new TranslationRepository($this->pdo);
        $this->languageRegistry = new LanguageRegistry(
            $this->languageRepository,
            $translationRepository,
            new StaticTenantContextAdapter(),
        );
        $this->languageRegistry->boot();

        $roleChecker = new RoleChecker(I18nAdminTestSeed::wrap($this->pdo), new PermissionRegistry());
        $this->handler = new LanguagesApiHandler($this->pdo, $this->languageRegistry, $this->languageRepository, $roleChecker);

        // Create a test profile for authenticated tests
        $this->testProfileId = $this->createTestProfile();
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
        RoleChecker::clearCache();
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

    // ==================== admin: POST /api/v1/languages (WC-583) ====================

    public function testCreateLanguageAsSystemTenantWithPermissionSucceeds(): void
    {
        $this->grantPermission($this->testProfileId, 0, CorePermissions::LANGUAGES_MANAGE);
        $request = new Request('POST', '/api/languages', [], (string) json_encode(['code' => 'fr', 'name' => 'Français']));
        $request->user = (object) ['profile_id' => $this->testProfileId];

        $response = $this->handler->create($request);

        $this->assertSame(201, $response->getStatusCode(), $response->getBody());
        $body = json_decode($response->getBody(), true);
        $this->assertSame('fr', $body['data']['code']);
        $this->assertSame('Français', $body['data']['name']);
        $this->assertTrue($body['data']['enabled'], 'enabled defaults to true when omitted');
    }

    public function testCreateLanguageWithoutPermissionIsForbidden(): void
    {
        // No grant for $this->testProfileId — RbacMiddleware would already
        // block this in production; the handler re-checks as defence in depth.
        $request = new Request('POST', '/api/languages', [], (string) json_encode(['code' => 'fr', 'name' => 'Français']));
        $request->user = (object) ['profile_id' => $this->testProfileId];

        $response = $this->handler->create($request);

        $this->assertSame(403, $response->getStatusCode());
    }

    /**
     * WC-583: languages carry no tenant_id column at all, so create/update is
     * restricted to the SYSTEM tenant even for a caller holding
     * languages:manage in a regular tenant (mirrors ENTITLEMENTS_MANAGE/
     * PLANS_MANAGE's system-tenant-only PLATFORM-capability gate).
     */
    public function testCreateLanguageAsRegularTenantIsForbiddenEvenWithPermission(): void
    {
        $this->grantPermission($this->testProfileId, 1, CorePermissions::LANGUAGES_MANAGE);
        TenantContext::reset();
        TenantContext::setTenantId(1);

        $request = new Request('POST', '/api/languages', [], (string) json_encode(['code' => 'fr', 'name' => 'Français']));
        $request->user = (object) ['profile_id' => $this->testProfileId];

        $response = $this->handler->create($request);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringContainsString('system tenant', (string) $response->getBody());
    }

    public function testCreateLanguageRejectsDuplicateCode(): void
    {
        $this->grantPermission($this->testProfileId, 0, CorePermissions::LANGUAGES_MANAGE);
        $request = new Request('POST', '/api/languages', [], (string) json_encode(['code' => 'en', 'name' => 'English (again)']));
        $request->user = (object) ['profile_id' => $this->testProfileId];

        $response = $this->handler->create($request);

        $this->assertSame(409, $response->getStatusCode());
    }

    public function testCreateLanguageRejectsInvalidCode(): void
    {
        $this->grantPermission($this->testProfileId, 0, CorePermissions::LANGUAGES_MANAGE);
        $request = new Request('POST', '/api/languages', [], (string) json_encode(['code' => '???', 'name' => 'Nope']));
        $request->user = (object) ['profile_id' => $this->testProfileId];

        $response = $this->handler->create($request);

        $this->assertSame(422, $response->getStatusCode());
    }

    // ==================== admin: PATCH /api/v1/languages/{id} (WC-583) ====================

    public function testUpdateLanguageTogglesEnabled(): void
    {
        $this->grantPermission($this->testProfileId, 0, CorePermissions::LANGUAGES_MANAGE);
        $languageId = (int) $this->pdo->query("SELECT id FROM languages WHERE code = 'ar'")->fetchColumn();

        $request = new Request('PATCH', "/api/languages/{$languageId}", [], (string) json_encode(['enabled' => false]));
        $request->user = (object) ['profile_id' => $this->testProfileId];

        $response = $this->handler->update($request, ['id' => (string) $languageId]);

        $this->assertSame(200, $response->getStatusCode(), $response->getBody());
        $body = json_decode($response->getBody(), true);
        $this->assertFalse($body['data']['enabled']);

        $stmt = $this->pdo->prepare('SELECT enabled FROM languages WHERE id = ?');
        $stmt->execute([$languageId]);
        $this->assertEquals(0, $stmt->fetchColumn());
    }

    public function testUpdateLanguageNotFoundReturns404(): void
    {
        $this->grantPermission($this->testProfileId, 0, CorePermissions::LANGUAGES_MANAGE);
        $request = new Request('PATCH', '/api/languages/999999', [], (string) json_encode(['enabled' => false]));
        $request->user = (object) ['profile_id' => $this->testProfileId];

        $response = $this->handler->update($request, ['id' => '999999']);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testUpdateLanguageAsRegularTenantIsForbiddenEvenWithPermission(): void
    {
        $this->grantPermission($this->testProfileId, 1, CorePermissions::LANGUAGES_MANAGE);
        TenantContext::reset();
        TenantContext::setTenantId(1);
        $languageId = (int) $this->pdo->query("SELECT id FROM languages WHERE code = 'ar'")->fetchColumn();

        $request = new Request('PATCH', "/api/languages/{$languageId}", [], (string) json_encode(['enabled' => false]));
        $request->user = (object) ['profile_id' => $this->testProfileId];

        $response = $this->handler->update($request, ['id' => (string) $languageId]);

        $this->assertSame(403, $response->getStatusCode());
    }

    // ==================== admin: GET /api/v1/admin/languages (WC-583) ====================

    public function testAdminListIncludesDisabledLanguagesWithFullShape(): void
    {
        $this->grantPermission($this->testProfileId, 0, CorePermissions::LANGUAGES_MANAGE);
        $arId = (int) $this->pdo->query("SELECT id FROM languages WHERE code = 'ar'")->fetchColumn();
        $disableRequest = new Request('PATCH', "/api/languages/{$arId}", [], (string) json_encode(['enabled' => false]));
        $disableRequest->user = (object) ['profile_id' => $this->testProfileId];
        $this->handler->update($disableRequest, ['id' => (string) $arId]);

        $request = new Request('GET', '/api/admin/languages');
        $request->user = (object) ['profile_id' => $this->testProfileId];

        $response = $this->handler->adminList($request);

        $this->assertSame(200, $response->getStatusCode(), $response->getBody());
        $body = json_decode($response->getBody(), true);
        $codes = array_column($body['data'], 'code');
        $this->assertContains('en', $codes);
        $this->assertContains('ar', $codes, 'a disabled language must still be listed for admins');

        $ar = array_values(array_filter($body['data'], static fn (array $l): bool => $l['code'] === 'ar'))[0];
        $this->assertFalse($ar['enabled']);
        $this->assertArrayHasKey('id', $ar);
        $this->assertArrayHasKey('created_at', $ar);
        $this->assertArrayHasKey('updated_at', $ar);
    }

    public function testAdminListWithoutPermissionIsForbidden(): void
    {
        $request = new Request('GET', '/api/admin/languages');
        $request->user = (object) ['profile_id' => $this->testProfileId];

        $response = $this->handler->adminList($request);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testAdminListAsRegularTenantIsForbiddenEvenWithPermission(): void
    {
        $this->grantPermission($this->testProfileId, 1, CorePermissions::LANGUAGES_MANAGE);
        TenantContext::reset();
        TenantContext::setTenantId(1);

        $request = new Request('GET', '/api/admin/languages');
        $request->user = (object) ['profile_id' => $this->testProfileId];

        $response = $this->handler->adminList($request);

        $this->assertSame(403, $response->getStatusCode());
    }

    // ============ direction as a property of the LANGUAGE (migration 090) ============

    /**
     * The seeded base languages carry their own direction, and the PUBLIC list
     * serves it — this is what the client sets <html dir> from, so the switcher
     * changing the language is also what changes the direction.
     */
    public function testPublicLanguageListCarriesEachLanguagesDirection(): void
    {
        $response = $this->handler->list(new Request('GET', '/api/languages'));

        $this->assertSame(200, $response->getStatusCode());
        $byCode = [];
        foreach (json_decode($response->getBody(), true)['languages'] as $language) {
            $byCode[$language['code']] = $language;
        }

        $this->assertSame('ltr', $byCode['en']['direction'], 'English is left-to-right.');
        $this->assertSame('rtl', $byCode['ar']['direction'], 'Arabic is right-to-left.');
    }

    /**
     * The per-user settings payload carries direction too, so a client that
     * only calls the authenticated endpoint still resolves a direction.
     */
    public function testLanguageSettingsPayloadCarriesDirection(): void
    {
        $request = new Request('GET', '/api/settings/language');
        $request->user = (object) ['profile_id' => $this->testProfileId];

        $response = $this->handler->getLanguage($request);

        $this->assertSame(200, $response->getStatusCode());
        $directions = array_column(
            json_decode($response->getBody(), true)['available_languages'],
            'direction',
            'code'
        );
        $this->assertSame('rtl', $directions['ar']);
        $this->assertSame('ltr', $directions['en']);
    }

    /**
     * THE POINT OF THE COLUMN: a THIRD right-to-left language is DATA. Creating
     * Hebrew through the admin API with direction 'rtl' makes the interface
     * mirror for it — no branch anywhere tests the code 'he', or 'ar' for that
     * matter.
     */
    public function testANewRightToLeftLanguageNeedsNoCodeChange(): void
    {
        $this->grantPermission($this->testProfileId, 0, CorePermissions::LANGUAGES_MANAGE);
        $request = new Request('POST', '/api/languages', [], (string) json_encode([
            'code' => 'he',
            'name' => 'עברית',
            'direction' => 'rtl',
        ]));
        $request->user = (object) ['profile_id' => $this->testProfileId];

        $response = $this->handler->create($request);

        $this->assertSame(201, $response->getStatusCode(), $response->getBody());
        $this->assertSame('rtl', json_decode($response->getBody(), true)['data']['direction']);

        // And it reaches the public list the client actually reads.
        $this->languageRegistry->invalidateCache();
        $listed = array_column(
            json_decode($this->handler->list(new Request('GET', '/api/languages'))->getBody(), true)['languages'],
            'direction',
            'code'
        );
        $this->assertSame('rtl', $listed['he']);
    }

    public function testCreateLanguageDefaultsToLeftToRightWhenDirectionOmitted(): void
    {
        $this->grantPermission($this->testProfileId, 0, CorePermissions::LANGUAGES_MANAGE);
        $request = new Request('POST', '/api/languages', [], (string) json_encode(['code' => 'fr', 'name' => 'Français']));
        $request->user = (object) ['profile_id' => $this->testProfileId];

        $response = $this->handler->create($request);

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame('ltr', json_decode($response->getBody(), true)['data']['direction']);
    }

    /**
     * A typo'd direction is REFUSED rather than coerced: silently handing an
     * admin a left-to-right interface for a right-to-left language is worse
     * than a 422 they can act on.
     */
    public function testCreateLanguageRejectsAnUnsupportedDirection(): void
    {
        $this->grantPermission($this->testProfileId, 0, CorePermissions::LANGUAGES_MANAGE);
        $request = new Request('POST', '/api/languages', [], (string) json_encode([
            'code' => 'fa',
            'name' => 'فارسی',
            'direction' => 'rlt',
        ]));
        $request->user = (object) ['profile_id' => $this->testProfileId];

        $response = $this->handler->create($request);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertNull($this->languageRepository->findByCode('fa'), 'Nothing is written on a rejected direction.');
    }

    public function testUpdateLanguageChangesDirection(): void
    {
        $this->grantPermission($this->testProfileId, 0, CorePermissions::LANGUAGES_MANAGE);
        $english = $this->languageRepository->findByCode('en');
        self::assertNotNull($english);

        $request = new Request('PATCH', '/api/languages/' . $english->id, [], (string) json_encode(['direction' => 'rtl']));
        $request->user = (object) ['profile_id' => $this->testProfileId];

        $response = $this->handler->update($request, ['id' => (string) $english->id]);

        $this->assertSame(200, $response->getStatusCode(), $response->getBody());
        $this->assertSame('rtl', json_decode($response->getBody(), true)['data']['direction']);
        $this->assertSame('rtl', $this->languageRepository->findByCode('en')?->direction);
    }

    public function testUpdateLanguageRejectsAnUnsupportedDirection(): void
    {
        $this->grantPermission($this->testProfileId, 0, CorePermissions::LANGUAGES_MANAGE);
        $arabic = $this->languageRepository->findByCode('ar');
        self::assertNotNull($arabic);

        $request = new Request('PATCH', '/api/languages/' . $arabic->id, [], (string) json_encode(['direction' => 'sideways']));
        $request->user = (object) ['profile_id' => $this->testProfileId];

        $response = $this->handler->update($request, ['id' => (string) $arabic->id]);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('rtl', $this->languageRepository->findByCode('ar')?->direction, 'Unchanged.');
    }

    // Helper methods

    /**
     * Grant a permission to $profileId via a fresh role + active membership in
     * $tenantId (0 = system tenant). Mirrors Tests\Support\I18nAdminTestSeed's
     * grant pattern, scoped to whatever profile/tenant a given test needs.
     */
    private function grantPermission(int $profileId, int $tenantId, string $permission): void
    {
        $roleId = 90000 + $profileId;
        $this->pdo->prepare(
            'INSERT INTO roles (id, name, description, tenant_id, created_at) VALUES (?, ?, ?, ?, NOW())'
        )->execute([$roleId, 'lang-test-role-' . $roleId, '', $tenantId]);

        $this->pdo->prepare('INSERT OR IGNORE INTO permissions (name, description, created_at) VALUES (?, ?, NOW())')
            ->execute([$permission, '']);
        $sel = $this->pdo->prepare('SELECT id FROM permissions WHERE name = ?');
        $sel->execute([$permission]);
        $permissionId = (int) $sel->fetchColumn();

        $this->pdo->prepare('INSERT INTO role_permissions (role_id, permission_id, created_at) VALUES (?, ?, NOW())')
            ->execute([$roleId, $permissionId]);

        $this->pdo->prepare(
            'INSERT INTO memberships (profile_id, tenant_id, role_id, status, created_at) VALUES (?, ?, ?, ?, NOW())'
        )->execute([$profileId, $tenantId, $roleId, 'active']);
    }

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
