<?php

declare(strict_types=1);

namespace Whity\Api;

use PDO;
use Whity\Auth\RoleChecker;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Tenant\TenantContext;
use Whity\Core\i18n\Language;
use Whity\Core\i18n\LanguageRegistry;
use Whity\Core\i18n\LanguageRepositoryInterface;
use Whity\Http\InputLimits;
use Whity\Http\JsonBody;

/**
 * Languages API handler.
 *
 * Exposes language management and user language preference endpoints:
 *  - GET /api/v1/languages — public endpoint, returns list of available languages
 *    (no auth required)
 *  - GET /api/v1/settings/language — authenticated, returns user's language preference
 *    and list of available languages
 *  - PATCH /api/v1/settings/language — authenticated, updates user's language preference
 *  - GET /api/v1/admin/languages — admin: every language INCLUDING disabled
 *    ones, with id + enabled status (languages:manage, SYSTEM TENANT ONLY)
 *  - POST /api/v1/languages — admin: create a language (languages:manage, SYSTEM
 *    TENANT ONLY — see the class docblock on {@see self::authorizeManage()})
 *  - PATCH /api/v1/languages/{id} — admin: update a language's name and/or
 *    toggle enabled/disabled (languages:manage, SYSTEM TENANT ONLY)
 *
 * Language preference is stored in the profiles.language_code column:
 *  - NULL = use tenant default language
 *  - explicit code (e.g., 'ar') = user has opted for a specific language
 *
 * DIRECTION travels WITH the language. Every language payload carries the
 * record's `direction` ('ltr'|'rtl', migration 090) and the client sets `dir`
 * on <html> from it — there is no separate direction preference and no code
 * anywhere that tests a language code to guess one. Adding a right-to-left
 * language is therefore a POST to this handler, not a release.
 *
 * Tenant scoping: languages are global (not tenant-specific) — there is no
 * `tenant_id` column on the `languages` table at all, so create/update is a
 * PLATFORM capability restricted to the SYSTEM tenant (id 0), mirroring
 * ENTITLEMENTS_MANAGE/PLANS_MANAGE: `languages:manage` is necessary but not
 * sufficient, otherwise any tenant holding it could disable a language for
 * the whole install. A user's language PREFERENCE remains per-profile and
 * follows them across all tenant memberships.
 *
 * Holds no request state — safe for a FrankenPHP worker.
 */
final class LanguagesApiHandler
{
    private PDO $db;
    private LanguageRegistry $languageRegistry;
    private LanguageRepositoryInterface $languageRepository;
    private RoleChecker $roleChecker;

    public function __construct(
        PDO $db,
        LanguageRegistry $languageRegistry,
        LanguageRepositoryInterface $languageRepository,
        RoleChecker $roleChecker
    ) {
        $this->db = $db;
        $this->languageRegistry = $languageRegistry;
        $this->languageRepository = $languageRepository;
        $this->roleChecker = $roleChecker;
    }

    /**
     * GET /api/v1/languages — public endpoint, returns list of available languages.
     *
     * No authentication required. Returns all enabled languages in the system.
     *
     * Response: { languages: [ { code: 'en', name: 'English', direction: 'ltr' },
     *                          { code: 'ar', name: 'العربية', direction: 'rtl' } ] }
     */
    public function list(Request $request): Response
    {
        try {
            // Get all available languages from the registry
            $languages = $this->languageRegistry->getLanguages();

            // Format for response
            $data = array_map(
                static fn ($lang): array => [
                    'code' => $lang->code,
                    'name' => $lang->name,
                    'direction' => $lang->direction,
                ],
                $languages
            );

            return Response::json(['languages' => array_values($data)], 200);
        } catch (\Throwable $e) {
            error_log('[LanguagesApiHandler] list failed: ' . $e->getMessage());
            return Response::error('Failed to fetch languages', 500);
        }
    }

    /**
     * GET /api/v1/admin/languages — admin: every language, INCLUDING disabled
     * ones, with the full admin shape (id, code, name, enabled, timestamps).
     *
     * SYSTEM TENANT ONLY (see class docblock). The public {@see self::list()}
     * intentionally omits `id`/disabled rows (it drives the end-user language
     * switcher); the admin management page needs both to render a toggle and
     * target a PATCH by id.
     *
     * Response: `{ data: [ { id, code, name, enabled, created_at, updated_at }, ... ] }`.
     */
    public function adminList(Request $request): Response
    {
        $auth = $this->authorizeManage($request);
        if ($auth instanceof Response) {
            return $auth;
        }

        try {
            $languages = $this->languageRepository->findAll();

            return Response::json([
                'data' => array_values(array_map(
                    static fn (Language $language): array => self::languagePayload($language),
                    $languages
                )),
            ], 200);
        } catch (\Throwable $e) {
            error_log('[LanguagesApiHandler] adminList failed: ' . $e->getMessage());
            return Response::error('Failed to fetch languages', 500);
        }
    }

    /**
     * GET /api/v1/settings/language — authenticated, returns user's language preference.
     *
     * Returns the current user's language preference and the list of available languages.
     * If the user has no explicit language_code set (NULL), returns null for language_code.
     *
     * Response: { language_code: 'ar'|null, available_languages: [ { code, name, direction }, ... ] }
     */
    public function getLanguage(Request $request): Response
    {
        $profileId = $this->getProfileId($request);
        if ($profileId === null) {
            return Response::error('Authentication required', 403);
        }

        try {
            // Fetch user's language preference from profiles table
            $stmt = $this->db->prepare('SELECT language_code FROM profiles WHERE id = ?');
            $stmt->execute([$profileId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                return Response::error('User profile not found', 404);
            }

            $languageCode = $row['language_code'] ?? null;

            // Get list of available languages
            $languages = $this->languageRegistry->getLanguages();
            $availableLanguages = array_map(
                static fn ($lang): array => [
                    'code' => $lang->code,
                    'name' => $lang->name,
                    'direction' => $lang->direction,
                ],
                $languages
            );

            return Response::json([
                'language_code' => $languageCode,
                'available_languages' => array_values($availableLanguages),
            ], 200);
        } catch (\Throwable $e) {
            error_log('[LanguagesApiHandler] getLanguage failed: ' . $e->getMessage());
            return Response::error('Failed to fetch language settings', 500);
        }
    }

    /**
     * PATCH /api/v1/settings/language — authenticated, updates user's language preference.
     *
     * Body: { language_code: 'ar' } or { language_code: null }
     *
     * Validates that the language_code exists in the languages table.
     * Returns 422 if language_code is invalid.
     *
     * Response: { language_code: 'ar' }
     */
    public function patchLanguage(Request $request): Response
    {
        $profileId = $this->getProfileId($request);
        if ($profileId === null) {
            return Response::error('Authentication required', 403);
        }

        $body = JsonBody::parsed($request);
        $languageCode = $body['language_code'] ?? null;

        // Validation: if language_code is provided, it must exist in languages table
        if ($languageCode !== null) {
            if (!is_string($languageCode) || $languageCode === '') {
                return Response::error('language_code must be a non-empty string or null', 400);
            }

            // Check if language exists
            $stmt = $this->db->prepare('SELECT id FROM languages WHERE code = ? AND enabled = true');
            $stmt->execute([$languageCode]);
            if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
                return Response::error('Invalid language code', 422, ['language_code' => 'Language not found or is disabled']);
            }
        }

        try {
            // Update user's language preference
            $stmt = $this->db->prepare('UPDATE profiles SET language_code = ? WHERE id = ?');
            $stmt->execute([$languageCode, $profileId]);

            // Log the change for audit purposes
            // TODO: Wire into audit_log when audit system is available
            error_log("[LanguagesApiHandler] User {$profileId} changed language preference to " . ($languageCode ?? 'NULL'));

            return Response::json(['language_code' => $languageCode], 200);
        } catch (\Throwable $e) {
            error_log('[LanguagesApiHandler] patchLanguage failed: ' . $e->getMessage());
            return Response::error('Failed to update language preference', 500);
        }
    }

    /**
     * POST /api/v1/languages — admin: create a language.
     *
     * SYSTEM TENANT ONLY (see class docblock). Body: `{ code, name, enabled? }`.
     * `enabled` defaults to true when omitted.
     *
     * Response: `{ data: { id, code, name, enabled, created_at, updated_at } }` (201),
     * or 409 when a language with this code already exists.
     */
    public function create(Request $request): Response
    {
        $auth = $this->authorizeManage($request);
        if ($auth instanceof Response) {
            return $auth;
        }

        $body = JsonBody::parsed($request);
        $code = is_string($body['code'] ?? null) ? trim($body['code']) : '';
        $name = is_string($body['name'] ?? null) ? trim($body['name']) : '';
        $enabled = array_key_exists('enabled', $body) ? (bool) $body['enabled'] : true;

        if ($code === '' || !preg_match('/^[a-z]{2,10}(-[A-Za-z]{2,10})?$/', $code)) {
            return Response::error(
                'code is required and must be a valid language code (e.g. "en", "ar", "en-US")',
                422,
                ['code' => $code]
            );
        }
        if ($name === '') {
            return Response::error('name is required', 422, ['name' => 'Name must be a non-empty string']);
        }
        if ($tooLong = InputLimits::firstViolation([
            'code' => [$code, 10],
            'name' => [$name, InputLimits::NAME_MAX],
        ])) {
            return $tooLong;
        }

        // A new right-to-left language (Hebrew, Farsi, Urdu…) is DATA: declare
        // its direction here and the interface follows, with no code change.
        $direction = self::readDirection($body);
        if ($direction instanceof Response) {
            return $direction;
        }

        try {
            $language = $this->languageRepository->create(
                $code,
                $name,
                $enabled,
                $direction ?? Language::DIRECTION_LTR
            );
            if ($language === null) {
                return Response::error('A language with this code already exists', 409);
            }

            $this->languageRegistry->invalidateCache();

            return Response::json(['data' => self::languagePayload($language)], 201);
        } catch (\Throwable $e) {
            error_log('[LanguagesApiHandler] create failed: ' . $e->getMessage());
            return Response::error('Failed to create language', 500);
        }
    }

    /**
     * PATCH /api/v1/languages/{id} — admin: update a language's name and/or
     * toggle enabled/disabled.
     *
     * SYSTEM TENANT ONLY (see class docblock). Body: `{ name?, enabled? }` — at
     * least one field must be present.
     *
     * Response: `{ data: { id, code, name, enabled, created_at, updated_at } }` (200),
     * or 404 when no language matches the id.
     *
     * @param array<string, mixed> $params Route params (expects `id`).
     */
    public function update(Request $request, array $params): Response
    {
        $auth = $this->authorizeManage($request);
        if ($auth instanceof Response) {
            return $auth;
        }

        $id = (int) ($params['id'] ?? 0);
        $body = JsonBody::parsed($request);

        if (
            !array_key_exists('name', $body)
            && !array_key_exists('enabled', $body)
            && !array_key_exists('direction', $body)
        ) {
            return Response::error('No updatable fields supplied (name, enabled, direction)', 422);
        }

        $name = null;
        if (array_key_exists('name', $body)) {
            $name = is_string($body['name']) ? trim($body['name']) : '';
            if ($name === '') {
                return Response::error('name must be a non-empty string', 422);
            }
            if ($tooLong = InputLimits::firstViolation(['name' => [$name, InputLimits::NAME_MAX]])) {
                return $tooLong;
            }
        }

        $enabled = null;
        if (array_key_exists('enabled', $body)) {
            if (!is_bool($body['enabled'])) {
                return Response::error('enabled must be a boolean', 422);
            }
            $enabled = $body['enabled'];
        }

        $direction = self::readDirection($body);
        if ($direction instanceof Response) {
            return $direction;
        }

        try {
            $language = $this->languageRepository->update($id, $name, $enabled, $direction);
            if ($language === null) {
                return Response::error('Language not found', 404);
            }

            $this->languageRegistry->invalidateCache();

            return Response::json(['data' => self::languagePayload($language)], 200);
        } catch (\Throwable $e) {
            error_log('[LanguagesApiHandler] update failed: ' . $e->getMessage());
            return Response::error('Failed to update language', 500);
        }
    }

    /**
     * Authorize an admin write: require `languages:manage` AND that the caller
     * is acting in the SYSTEM tenant (id 0). Languages carry no `tenant_id`
     * column at all, so allowing any tenant holding the permission to write
     * would let a regular tenant admin change a platform-wide catalogue — the
     * same reasoning as ENTITLEMENTS_MANAGE/PLANS_MANAGE (see class docblock).
     *
     * The route-level RbacMiddleware already enforces `languages:manage`; this
     * is defence in depth plus the system-tenant check the router cannot express.
     *
     * @return array{tenantId: int, userId: int}|Response
     */
    private function authorizeManage(Request $request): array|Response
    {
        $tenantId = TenantContext::getTenantId();
        if ($tenantId === null) {
            return Response::error('Tenant context is required', 403);
        }

        $actor = $request->user;
        $userId = is_object($actor) && isset($actor->profile_id) && is_int($actor->profile_id)
            ? $actor->profile_id
            : null;
        if ($userId === null || !$this->roleChecker->hasPermissionForProfile($userId, CorePermissions::LANGUAGES_MANAGE, $tenantId)) {
            return Response::error('Insufficient permissions', 403, ['required' => CorePermissions::LANGUAGES_MANAGE]);
        }

        if ($tenantId !== 0) {
            return Response::error('Language management is restricted to the system tenant', 403);
        }

        return ['tenantId' => $tenantId, 'userId' => $userId];
    }

    /**
     * @return array{id: int, code: string, name: string, direction: string, enabled: bool, created_at: string, updated_at: string}
     */
    private static function languagePayload(Language $language): array
    {
        return [
            'id' => $language->id,
            'code' => $language->code,
            'name' => $language->name,
            'direction' => $language->direction,
            'enabled' => $language->enabled,
            'created_at' => $language->createdAt,
            'updated_at' => $language->updatedAt,
        ];
    }

    /**
     * Read and validate a `direction` field from a request body.
     *
     * Returns the direction string, null when the field is absent (leave
     * unchanged / take the default), or a 422 Response when present but not one
     * of {@see Language::DIRECTIONS}. Rejecting rather than coercing matters:
     * an admin who typos 'rlt' when adding Hebrew must be told, not silently
     * given a left-to-right interface.
     *
     * @param array<string, mixed> $body
     */
    private static function readDirection(array $body): string|Response|null
    {
        if (!array_key_exists('direction', $body)) {
            return null;
        }

        $direction = $body['direction'];
        if (!is_string($direction) || !in_array($direction, Language::DIRECTIONS, true)) {
            return Response::error(
                'direction must be one of: ' . implode(', ', Language::DIRECTIONS),
                422,
                ['direction' => $direction]
            );
        }

        return $direction;
    }

    /**
     * Extract profile_id from the authenticated request.
     *
     * @return int|null The profile ID, or null if not authenticated.
     */
    private function getProfileId(Request $request): ?int
    {
        $actor = $request->user;
        if (!is_object($actor) || !isset($actor->profile_id) || !is_int($actor->profile_id)) {
            return null;
        }
        return $actor->profile_id;
    }
}
