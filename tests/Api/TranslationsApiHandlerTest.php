<?php

declare(strict_types=1);

namespace Tests\Api;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\I18nAdminTestSeed;
use Whity\Api\TranslationsApiHandler;
use Whity\Auth\RoleChecker;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\Request;
use Whity\Core\i18n\LanguageRegistry;
use Whity\Core\i18n\LanguageRepository;
use Whity\Core\i18n\TranslationRepository;
use Whity\Core\Tenant\StaticTenantContextAdapter;
use Whity\Core\Tenant\TenantContext;

/**
 * Handler-level tests for {@see TranslationsApiHandler}'s admin CRUD surface
 * (WC-583): RBAC gating (translations:manage), the System-Tenant Context
 * write-access asymmetry (404 for a regular tenant touching a global/foreign
 * row, 422 for the system tenant touching a per-tenant override), and the
 * admin listing's system-default-vs-tenant-override shape.
 *
 * Cross-tenant ISOLATION at the repository/predicate level (the mutating
 * statement itself, not just this handler's guard) is covered separately in
 * {@see \Tests\Integration\CrossTenantRejectionRealEngineTest}.
 *
 * Uses {@see I18nAdminTestSeed}: tenant 1's manager (profile 910) and tenant
 * 2's manager (profile 920) both hold translations:manage; tenant 1's viewer
 * (profile 911) holds neither; the system tenant's manager (profile 930, a
 * membership in tenant 0) holds it too.
 */
final class TranslationsApiHandlerTest extends TestCase
{
    private PDO $pdo;
    private TranslationsApiHandler $handler;
    private TranslationRepository $translationRepository;
    private int $englishLanguageId;

    protected function setUp(): void
    {
        RoleChecker::clearCache();
        TenantContext::reset();

        $this->pdo = I18nAdminTestSeed::make();

        $languageRepository = new LanguageRepository($this->pdo);
        $this->translationRepository = new TranslationRepository($this->pdo);
        $languageRegistry = new LanguageRegistry(
            $languageRepository,
            $this->translationRepository,
            new StaticTenantContextAdapter(),
        );
        $languageRegistry->boot();

        $roleChecker = new RoleChecker(I18nAdminTestSeed::wrap($this->pdo), new PermissionRegistry());

        $this->handler = new TranslationsApiHandler(
            $languageRepository,
            $this->translationRepository,
            new StaticTenantContextAdapter(),
            $roleChecker,
            $languageRegistry
        );

        $this->englishLanguageId = (int) $this->pdo->query("SELECT id FROM languages WHERE code = 'en'")->fetchColumn();
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
        RoleChecker::clearCache();
    }

    // ==================== create ====================

    public function testRegularTenantCreatesItsOwnOverride(): void
    {
        $response = $this->handler->create($this->req(1, 910, 'POST', [
            'language_code' => 'en',
            'domain' => 'common',
            'key' => 'greeting',
            'translation' => 'A-Hello',
        ]));

        $this->assertSame(201, $response->getStatusCode(), $response->getBody());
        $body = json_decode($response->getBody(), true);
        $this->assertSame(1, $body['data']['tenant_id']);
        $this->assertSame('A-Hello', $body['data']['translation']);
    }

    public function testSystemTenantCreatesASystemDefault(): void
    {
        $response = $this->handler->create($this->req(0, 930, 'POST', [
            'language_code' => 'en',
            'domain' => 'common',
            'key' => 'greeting',
            'translation' => 'Hello',
        ]));

        $this->assertSame(201, $response->getStatusCode(), $response->getBody());
        $body = json_decode($response->getBody(), true);
        $this->assertNull($body['data']['tenant_id']);
    }

    public function testCreateWithoutPermissionIsForbidden(): void
    {
        $response = $this->handler->create($this->req(1, 911, 'POST', [
            'language_code' => 'en',
            'domain' => 'common',
            'key' => 'greeting',
            'translation' => 'Nope',
        ]));

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testCreateRejectsDuplicateInSameScope(): void
    {
        $body = ['language_code' => 'en', 'domain' => 'common', 'key' => 'dup', 'translation' => 'First'];
        $this->assertSame(201, $this->handler->create($this->req(1, 910, 'POST', $body))->getStatusCode());

        $again = $this->handler->create($this->req(1, 910, 'POST', ['translation' => 'Second'] + $body));
        $this->assertSame(409, $again->getStatusCode());
    }

    public function testCreateWithUnknownLanguageIs404(): void
    {
        $response = $this->handler->create($this->req(1, 910, 'POST', [
            'language_code' => 'xx',
            'domain' => 'common',
            'key' => 'k',
            'translation' => 'v',
        ]));

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testCreateRejectsInvalidDomain(): void
    {
        $response = $this->handler->create($this->req(1, 910, 'POST', [
            'language_code' => 'en',
            'domain' => 'bad domain!',
            'key' => 'k',
            'translation' => 'v',
        ]));

        $this->assertSame(422, $response->getStatusCode());
    }

    // ==================== update / delete write-access asymmetry ====================

    public function testUpdateOwnOverrideSucceeds(): void
    {
        $id = $this->seedRow(1, 'k', 'orig');

        $response = $this->handler->update($this->req(1, 910, 'PATCH', ['translation' => 'updated']), ['id' => (string) $id]);

        $this->assertSame(200, $response->getStatusCode(), $response->getBody());
        $this->assertSame('updated', $this->translationRepository->findById($id)?->translation);
    }

    public function testUpdateForeignTenantOverrideIs404AndSurvives(): void
    {
        $id = $this->seedRow(2, 'k', 'B-orig');

        $response = $this->handler->update($this->req(1, 910, 'PATCH', ['translation' => 'hijack']), ['id' => (string) $id]);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('B-orig', $this->translationRepository->findById($id)?->translation);
    }

    /**
     * WC-583 (System-Tenant Context asymmetry): a regular tenant touching the
     * GLOBAL system-default row is reported not-found, mirroring WC-110's
     * base-role rule.
     */
    public function testRegularTenantUpdatingSystemDefaultIs404AndSurvives(): void
    {
        $id = $this->seedRow(null, 'k', 'sys-orig');

        $response = $this->handler->update($this->req(1, 910, 'PATCH', ['translation' => 'hijack']), ['id' => (string) $id]);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('sys-orig', $this->translationRepository->findById($id)?->translation);
    }

    /**
     * WC-583 (System-Tenant Context asymmetry, the other direction): the
     * system tenant targeting a PER-TENANT override is a 422, mirroring
     * PATCH /api/settings's "the system tenant has no per-tenant override
     * layer" rejection.
     */
    public function testSystemTenantUpdatingTenantOverrideIs422AndSurvives(): void
    {
        $id = $this->seedRow(1, 'k', 'A-orig');

        $response = $this->handler->update($this->req(0, 930, 'PATCH', ['translation' => 'hijack']), ['id' => (string) $id]);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('A-orig', $this->translationRepository->findById($id)?->translation);
    }

    public function testSystemTenantUpdatingSystemDefaultSucceeds(): void
    {
        $id = $this->seedRow(null, 'k', 'orig');

        $response = $this->handler->update($this->req(0, 930, 'PATCH', ['translation' => 'updated']), ['id' => (string) $id]);

        $this->assertSame(200, $response->getStatusCode(), $response->getBody());
        $this->assertSame('updated', $this->translationRepository->findById($id)?->translation);
    }

    public function testUpdateNonexistentIdIs404(): void
    {
        $response = $this->handler->update($this->req(1, 910, 'PATCH', ['translation' => 'x']), ['id' => '999999']);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testUpdateWithoutPermissionIsForbidden(): void
    {
        $id = $this->seedRow(1, 'k', 'orig');

        $response = $this->handler->update($this->req(1, 911, 'PATCH', ['translation' => 'x']), ['id' => (string) $id]);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testDeleteOwnOverrideSucceeds(): void
    {
        $id = $this->seedRow(1, 'k', 'orig');

        $response = $this->handler->delete($this->req(1, 910, 'DELETE', []), ['id' => (string) $id]);

        $this->assertSame(204, $response->getStatusCode());
        $this->assertNull($this->translationRepository->findById($id));
    }

    public function testDeleteForeignTenantOverrideIs404AndSurvives(): void
    {
        $id = $this->seedRow(2, 'k', 'B-orig');

        $response = $this->handler->delete($this->req(1, 910, 'DELETE', []), ['id' => (string) $id]);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertNotNull($this->translationRepository->findById($id));
    }

    public function testSystemTenantDeletingTenantOverrideIs422AndSurvives(): void
    {
        $id = $this->seedRow(1, 'k', 'A-orig');

        $response = $this->handler->delete($this->req(0, 930, 'DELETE', []), ['id' => (string) $id]);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertNotNull($this->translationRepository->findById($id));
    }

    // ==================== adminList ====================

    public function testAdminListShowsSystemDefaultAndOwnOverrideDistinctly(): void
    {
        $this->translationRepository->create($this->englishLanguageId, 'common', 'greeting', 'Hello', null);
        $this->translationRepository->create($this->englishLanguageId, 'common', 'greeting', 'A-Hello', 1);
        $this->translationRepository->create($this->englishLanguageId, 'common', 'farewell', 'Bye', null);

        $response = $this->handler->adminList(
            $this->req(1, 910, 'GET', null, '/api/translations?language_code=en&domain=common')
        );

        $this->assertSame(200, $response->getStatusCode(), $response->getBody());
        $rows = json_decode($response->getBody(), true)['data'];
        $byKey = array_column($rows, null, 'key');

        $this->assertSame('Hello', $byKey['greeting']['system_default']['translation']);
        $this->assertSame('A-Hello', $byKey['greeting']['tenant_override']['translation']);
        $this->assertSame('Bye', $byKey['farewell']['system_default']['translation']);
        $this->assertNull($byKey['farewell']['tenant_override'], 'a key with no override must report null, not the system default again');
    }

    public function testAdminListForSystemTenantNeverShowsATenantOverride(): void
    {
        $this->translationRepository->create($this->englishLanguageId, 'common', 'greeting', 'Hello', null);
        $this->translationRepository->create($this->englishLanguageId, 'common', 'greeting', 'A-Hello', 1);

        $response = $this->handler->adminList(
            $this->req(0, 930, 'GET', null, '/api/translations?language_code=en&domain=common')
        );

        $this->assertSame(200, $response->getStatusCode(), $response->getBody());
        $rows = json_decode($response->getBody(), true)['data'];
        $byKey = array_column($rows, null, 'key');
        $this->assertNull($byKey['greeting']['tenant_override'], 'the system tenant has no override layer of its own to show');
    }

    public function testAdminListRejectsMissingQueryParams(): void
    {
        $response = $this->handler->adminList($this->req(1, 910, 'GET', null, '/api/translations'));

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testAdminListWithoutPermissionIsForbidden(): void
    {
        $response = $this->handler->adminList(
            $this->req(1, 911, 'GET', null, '/api/translations?language_code=en&domain=common')
        );

        $this->assertSame(403, $response->getStatusCode());
    }

    // ==================== the untranslated gap ====================
    //
    // A missing translation has NO ROW, so every listing here could only ever
    // show work already done — and a language read as complete precisely when
    // nobody had started it. Since strings are now extracted from source and
    // seeded in English only, that gap is the normal state of every language
    // but the source, and these are the tests that make it visible.

    public function testAdminListIncludesAKeyThatHasNoRowInThisLanguageYet(): void
    {
        $arabicLanguageId = $this->languageId('ar');
        $this->translationRepository->create($this->englishLanguageId, 'common', 'greeting', 'Hello', null);
        $this->translationRepository->create($arabicLanguageId, 'common', 'farewell', 'مع السلامة', null);

        $response = $this->handler->adminList(
            $this->req(0, 930, 'GET', null, '/api/translations?language_code=ar&domain=common')
        );

        $this->assertSame(200, $response->getStatusCode(), $response->getBody());
        $byKey = array_column(json_decode($response->getBody(), true)['data'], null, 'key');

        $this->assertArrayHasKey('greeting', $byKey, 'a key seeded in English must appear in Arabic as untranslated work');
        $this->assertNull($byKey['greeting']['system_default']);
        $this->assertFalse($byKey['greeting']['translated']);
        $this->assertSame('Hello', $byKey['greeting']['source_text'], 'the translator needs the English they are translating FROM');
        $this->assertTrue($byKey['farewell']['translated']);
    }

    public function testAdminListUntranslatedFilterReturnsExactlyTheWorkRemaining(): void
    {
        $arabicLanguageId = $this->languageId('ar');
        $this->translationRepository->create($this->englishLanguageId, 'common', 'greeting', 'Hello', null);
        $this->translationRepository->create($this->englishLanguageId, 'common', 'farewell', 'Bye', null);
        $this->translationRepository->create($arabicLanguageId, 'common', 'farewell', 'مع السلامة', null);

        $response = $this->handler->adminList(
            $this->req(0, 930, 'GET', null, '/api/translations?language_code=ar&domain=common&untranslated=1')
        );

        $this->assertSame(200, $response->getStatusCode(), $response->getBody());
        $rows = json_decode($response->getBody(), true)['data'];

        $this->assertSame(['greeting'], array_column($rows, 'key'));
    }

    public function testAdminListCountsATenantOverrideAsTranslatedForThatTenant(): void
    {
        $arabicLanguageId = $this->languageId('ar');
        $this->translationRepository->create($this->englishLanguageId, 'common', 'greeting', 'Hello', null);
        $this->translationRepository->create($arabicLanguageId, 'common', 'greeting', 'مرحبا', 1);

        $response = $this->handler->adminList(
            $this->req(1, 910, 'GET', null, '/api/translations?language_code=ar&domain=common&untranslated=1')
        );

        $this->assertSame(200, $response->getStatusCode(), $response->getBody());
        $this->assertSame(
            [],
            json_decode($response->getBody(), true)['data'],
            "a tenant's own override is what its users read, so the key is not outstanding work for it"
        );
    }

    public function testCoverageReportsTheGapPerLanguageAndDomain(): void
    {
        $arabicLanguageId = $this->languageId('ar');
        // `auth` is already seeded in BOTH languages by migration 091, so it
        // contributes nothing to the gap — which is exactly the shape a real
        // instance has: some domains done, some untouched.
        $this->translationRepository->create($this->englishLanguageId, 'common', 'greeting', 'Hello', null);
        $this->translationRepository->create($this->englishLanguageId, 'common', 'farewell', 'Bye', null);
        $this->translationRepository->create($this->englishLanguageId, 'errors', 'notFound', 'Not found', null);
        $this->translationRepository->create($arabicLanguageId, 'common', 'greeting', 'مرحبا', null);

        $response = $this->handler->coverage($this->req(0, 930, 'GET', null, '/api/translations/coverage'));

        $this->assertSame(200, $response->getStatusCode(), $response->getBody());
        $body = json_decode($response->getBody(), true)['data'];
        $this->assertSame('en', $body['source_language_code']);

        $byLanguage = array_column($body['languages'], null, 'language_code');
        $this->assertSame(0, $byLanguage['en']['missing'], 'the source language is complete by construction');
        $this->assertSame($byLanguage['en']['total'], $byLanguage['ar']['total'], 'the universe of keys is the source language, not what Arabic happens to have');
        $this->assertSame(2, $byLanguage['ar']['missing']);

        $byDomain = array_column($byLanguage['ar']['domains'], null, 'domain');
        $this->assertSame(2, $byDomain['common']['total']);
        $this->assertSame(1, $byDomain['common']['translated']);
        $this->assertSame(1, $byDomain['common']['missing']);
        $this->assertSame(1, $byDomain['errors']['missing'], 'a domain Arabic has NO rows in must still be reported, or it is invisible');
        $this->assertSame(0, $byDomain['auth']['missing']);
    }

    public function testCoverageWithoutPermissionIsForbidden(): void
    {
        $response = $this->handler->coverage($this->req(1, 911, 'GET', null, '/api/translations/coverage'));

        $this->assertSame(403, $response->getStatusCode());
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** The id of a seeded language, for fixtures in a language other than English. */
    private function languageId(string $code): int
    {
        $statement = $this->pdo->prepare('SELECT id FROM languages WHERE code = :code');
        $this->assertNotFalse($statement);
        $statement->execute([':code' => $code]);

        return (int) $statement->fetchColumn();
    }

    /** Create a fixture row directly via the repository, bypassing the handler. */
    private function seedRow(?int $tenantId, string $key, string $text): int
    {
        $row = $this->translationRepository->create($this->englishLanguageId, 'common', $key, $text, $tenantId);
        $this->assertNotNull($row, 'fixture row must be created');
        return $row->id;
    }

    /** @param array<string, mixed>|null $body */
    private function req(int $tenantId, int $profileId, string $method, ?array $body, string $path = '/api/translations'): Request
    {
        TenantContext::reset();
        TenantContext::setTenantId($tenantId);
        $request = new Request($method, $path, [], $body !== null ? (string) json_encode($body) : '');
        $request->user = (object) ['profile_id' => $profileId, 'active_tenant_id' => $tenantId];
        return $request;
    }
}
