<?php

declare(strict_types=1);

namespace Whity\Api;

use PDO;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\i18n\LanguageRegistry;
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
 *
 * Language preference is stored in the profiles.language_code column:
 *  - NULL = use tenant default language
 *  - explicit code (e.g., 'ar') = user has opted for a specific language
 *
 * Tenant scoping: languages are global (not tenant-specific), but a user's
 * language preference is per-profile and follows them across all tenant memberships.
 *
 * Holds no request state — safe for a FrankenPHP worker.
 */
final class LanguagesApiHandler
{
    private PDO $db;
    private LanguageRegistry $languageRegistry;

    public function __construct(PDO $db, LanguageRegistry $languageRegistry)
    {
        $this->db = $db;
        $this->languageRegistry = $languageRegistry;
    }

    /**
     * GET /api/v1/languages — public endpoint, returns list of available languages.
     *
     * No authentication required. Returns all enabled languages in the system.
     *
     * Response: { languages: [ { code: 'en', name: 'English' }, { code: 'ar', name: 'العربية' } ] }
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
     * GET /api/v1/settings/language — authenticated, returns user's language preference.
     *
     * Returns the current user's language preference and the list of available languages.
     * If the user has no explicit language_code set (NULL), returns null for language_code.
     *
     * Response: { language_code: 'ar'|null, available_languages: [...] }
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
