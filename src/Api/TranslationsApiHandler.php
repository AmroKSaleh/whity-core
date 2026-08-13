<?php

declare(strict_types=1);

namespace Whity\Api;

use PDO;
use Whity\Auth\RoleChecker;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\i18n\LanguageRegistry;
use Whity\Core\i18n\LanguageRepository;
use Whity\Core\i18n\Translation;
use Whity\Core\i18n\TranslationDomain;
use Whity\Core\i18n\TranslationRepository;
use Whity\Core\Tenant\TenantContextInterface;
use Whity\Http\InputLimits;
use Whity\Http\JsonBody;

/**
 * Translations API handler.
 *
 * Exposes the translation layer for UI string localization, plus admin CRUD
 * over individual translation rows (WC-583):
 *  - GET /api/v1/translations/{language_code}/{domain} — authenticated, returns translations
 *    for a specific language and domain (implements fallback chain: tenant override →
 *    system default → English → key)
 *  - GET /api/v1/translations — admin (translations:manage): raw rows for a
 *    language+domain (?language_code=&domain=), showing the system-default and
 *    this tenant's override SIDE BY SIDE (not merged) for a management UI.
 *  - POST /api/v1/translations — admin: create a translation row.
 *  - PATCH /api/v1/translations/{id} — admin: update a translation row's text.
 *  - DELETE /api/v1/translations/{id} — admin: delete a translation row.
 *
 * This endpoint allows clients to fetch translated strings for any language and domain.
 * Translations are cached client-side in localStorage for performance.
 *
 * DOMAIN NAMING is decided in exactly one place, {@see TranslationDomain}: core
 * domains are bare (`auth`, `common`), a plugin's are prefixed with its source
 * slug (`acme:catalog`), matching the resource-type registry's `acme:record`.
 * Every path here validates through that helper, so a domain that can be
 * written can always be read back.
 *
 * Fallback behavior (read path):
 *  1. Tenant-specific override (if a custom translation is set for this tenant)
 *  2. System default (the canonical translation in the system)
 *  3. English translation (fallback if the requested language has no translation)
 *  4. The key itself (if no translation is found anywhere)
 *
 * Write scope (WC-583, mirrors the base-roles / global-settings asymmetry):
 * a translation row's `tenant_id` is NULL for a system default or the owning
 * tenant id for an override. The CALLER's scope determines what it writes —
 * the system tenant (id 0) writes/deletes only system-default rows; a regular
 * tenant writes/deletes only its OWN override row. Touching a row outside the
 * caller's scope is reported as 404 (regular tenant touching a global/foreign
 * row) or 422 (system tenant touching a per-tenant override — "the system
 * tenant has no per-tenant override layer"), mirroring the platform's
 * System-Tenant Context convention (WC-110 / WC-224).
 *
 * Holds no request state — safe for a FrankenPHP worker.
 */
final class TranslationsApiHandler
{
    private LanguageRepository $languageRepository;
    private TranslationRepository $translationRepository;
    private TenantContextInterface $tenantContext;
    private RoleChecker $roleChecker;
    private LanguageRegistry $languageRegistry;

    public function __construct(
        LanguageRepository $languageRepository,
        TranslationRepository $translationRepository,
        TenantContextInterface $tenantContext,
        RoleChecker $roleChecker,
        LanguageRegistry $languageRegistry,
    ) {
        $this->languageRepository = $languageRepository;
        $this->translationRepository = $translationRepository;
        $this->tenantContext = $tenantContext;
        $this->roleChecker = $roleChecker;
        $this->languageRegistry = $languageRegistry;
    }

    /**
     * GET /api/v1/translations/{language_code}/{domain} — fetch translations.
     *
     * Returns a map of translation keys to their translated strings for the given
     * language and domain. Implements the fallback chain:
     *  1. Tenant-specific override
     *  2. System default
     *  3. English fallback
     *  4. Key itself
     *
     * Response: { translations: { "key": "translation", ... } }
     *
     * @param Request $request The HTTP request
     * @param array $params Path parameters: { language_code, domain }
     * @return Response JSON response with translations or error
     */
    public function getTranslations(Request $request, array $params = []): Response
    {
        // Extract language_code and domain from path parameters
        $languageCode = $params['language_code'] ?? null;
        $domain = $params['domain'] ?? null;

        // Validate input
        if (!$languageCode || !is_string($languageCode) || $languageCode === '') {
            return Response::error('Missing or invalid language_code parameter', 400);
        }

        if (!$domain || !is_string($domain) || $domain === '') {
            return Response::error('Missing or invalid domain parameter', 400);
        }

        // Validate the domain against the ONE naming rule — a bare core slug
        // ('auth') or a plugin-namespaced one ('acme:catalog').
        if (!TranslationDomain::isValid($domain)) {
            return Response::error('Invalid domain format', 400);
        }

        try {
            // Get language by code
            $language = $this->languageRepository->findByCode($languageCode);
            if (!$language) {
                return Response::error("Language '{$languageCode}' not found or is disabled", 404);
            }

            // Get the current tenant ID (may be null for system tenant)
            $tenantId = $this->tenantContext->getTenantId();

            // Fetch translations with fallback chain
            // This includes both system defaults and tenant overrides
            $translations = $this->translationRepository->findByLanguageAndDomain(
                $language->id,
                $domain,
                $tenantId
            );

            // If no translations found for the requested language, try English as fallback
            if (empty($translations) && $languageCode !== 'en') {
                $englishLanguage = $this->languageRepository->findByCode('en');
                if ($englishLanguage) {
                    $translations = $this->translationRepository->findByLanguageAndDomain(
                        $englishLanguage->id,
                        $domain,
                        $tenantId
                    );
                }
            }

            // Format translations as simple key => value map
            $formatted = [];
            foreach ($translations as $translation) {
                $formatted[$translation->key] = $translation->translation;
            }

            return Response::json(['translations' => $formatted], 200);
        } catch (\Throwable $e) {
            error_log('[TranslationsApiHandler] getTranslations failed: ' . $e->getMessage());
            return Response::error('Failed to fetch translations', 500);
        }
    }

    /**
     * GET /api/v1/translations — admin: raw translation rows for a
     * language+domain, for a management UI.
     *
     * Query params: `language_code` (required), `domain` (required),
     * `untranslated` (optional, `1`/`true`). Unlike {@see self::getTranslations()}
     * (which returns one merged key => value map), this returns the
     * system-default and this tenant's override SIDE BY SIDE per key so the UI
     * can show both distinctly. The system tenant (id 0) sees only
     * system-default rows — it has no override layer of its own to show.
     *
     * KEYS WITH NO ROW IN THIS LANGUAGE ARE INCLUDED. That is the point of the
     * screen: a key seeded in English and never translated has no Arabic row at
     * all, so listing only what exists would show a translator an empty table
     * and call the language finished. The key universe is therefore the union of
     * this language's rows and the SOURCE language's system defaults, and each
     * row carries `source_text` — the English a translator is translating FROM —
     * plus `translated`, whether this language actually resolves to anything.
     *
     * `untranslated=1` narrows the list to exactly the work remaining.
     *
     * Response: `{ data: [ { key, system_default: {id, translation}|null,
     * tenant_override: {id, translation}|null, source_text: string|null,
     * translated: bool }, ... ] }` (200).
     */
    public function adminList(Request $request): Response
    {
        $auth = $this->authorize($request, CorePermissions::TRANSLATIONS_MANAGE);
        if ($auth instanceof Response) {
            return $auth;
        }
        ['tenantId' => $tenantId] = $auth;

        $query = self::queryParams($request);
        $languageCode = $query['language_code'] ?? '';
        $domain = $query['domain'] ?? '';
        $untranslatedOnly = in_array($query['untranslated'] ?? '', ['1', 'true'], true);

        if ($languageCode === '' || $domain === '') {
            return Response::error('language_code and domain query parameters are required', 400);
        }
        if (!TranslationDomain::isValid($domain)) {
            return Response::error('Invalid domain format', 400);
        }

        try {
            $language = $this->languageRepository->findByCode($languageCode);
            if ($language === null) {
                return Response::error("Language '{$languageCode}' not found", 404);
            }

            $systemDefaults = $this->translationRepository->findAllSystemDefaults($language->id)[$domain] ?? [];
            $overrides = $tenantId !== 0
                ? ($this->translationRepository->findAllTenantOverrides($language->id, $tenantId)[$domain] ?? [])
                : [];

            // The English a translator works FROM, and the reason a
            // never-translated key still gets a row.
            $sourceTexts = [];
            if ($languageCode !== LanguageRegistry::SOURCE_LANGUAGE) {
                $sourceLanguage = $this->languageRepository->findByCode(LanguageRegistry::SOURCE_LANGUAGE);
                if ($sourceLanguage !== null) {
                    $sourceTexts = $this->translationRepository->findAllSystemDefaults($sourceLanguage->id)[$domain] ?? [];
                }
            }

            $keys = array_values(array_unique(array_merge(
                array_keys($systemDefaults),
                array_keys($overrides),
                array_keys($sourceTexts)
            )));
            sort($keys);

            $rows = array_map(
                /** @return array<string, mixed> */
                static function (string $key) use ($systemDefaults, $overrides, $sourceTexts): array {
                    $sys = $systemDefaults[$key] ?? null;
                    $ovr = $overrides[$key] ?? null;
                    $source = $sourceTexts[$key] ?? null;
                    return [
                        'key' => $key,
                        'system_default' => $sys !== null ? ['id' => $sys->id, 'translation' => $sys->translation] : null,
                        'tenant_override' => $ovr !== null ? ['id' => $ovr->id, 'translation' => $ovr->translation] : null,
                        'source_text' => $source?->translation,
                        'translated' => $sys !== null || $ovr !== null,
                    ];
                },
                $keys
            );

            if ($untranslatedOnly) {
                $rows = array_filter($rows, static fn (array $row): bool => $row['translated'] === false);
            }

            return Response::json(['data' => array_values($rows)], 200);
        } catch (\Throwable $e) {
            error_log('[TranslationsApiHandler] adminList failed: ' . $e->getMessage());
            return Response::error('Failed to list translations', 500);
        }
    }

    /**
     * GET /api/v1/translations/coverage — admin: how much of each language is
     * actually translated, per domain.
     *
     * THE QUESTION THIS ANSWERS is "what still needs translating for language
     * X", and before it existed nothing could. Missing keys have no rows, so
     * every list in the system was a list of work already DONE; the work
     * remaining was invisible, and a language looked complete precisely when
     * nobody had started it. That matters more now than it did: strings are
     * extracted from source in bulk and seeded in English only, so the gap is
     * the normal state of every language except the source, and the person who
     * closes it is a translator with no access to the code.
     *
     * A key counts as TRANSLATED in a language when the caller's scope resolves
     * it to text — a system default for the system tenant, a system default or
     * this tenant's own override for anyone else. Totals are the union of that
     * language's keys and the source language's, so a key that exists only in
     * Arabic still counts, and a key that exists only in English counts as
     * missing rather than vanishing.
     *
     * No query parameters: it reports every enabled language at once, because
     * the screen's first question is which language needs attention.
     *
     * Response: `{ data: { source_language_code, languages: [ { language_code,
     * name, total, translated, missing, domains: [ { domain, total, translated,
     * missing } ] } ] } }` (200).
     */
    public function coverage(Request $request): Response
    {
        $auth = $this->authorize($request, CorePermissions::TRANSLATIONS_MANAGE);
        if ($auth instanceof Response) {
            return $auth;
        }
        ['tenantId' => $tenantId] = $auth;

        // The system tenant reads and writes system defaults; everyone else
        // sees a key as translated when either layer supplies the text.
        $scopeTenantId = $tenantId === 0 ? null : $tenantId;

        try {
            $languages = $this->languageRepository->findAll(true);

            $sourceKeys = [];
            foreach ($languages as $language) {
                if ($language->code === LanguageRegistry::SOURCE_LANGUAGE) {
                    $sourceKeys = $this->translationRepository->keysByDomain($language->id, null);
                    break;
                }
            }

            $report = [];
            foreach ($languages as $language) {
                $present = $this->translationRepository->keysByDomain($language->id, $scopeTenantId);
                $report[] = self::coverageForLanguage($language->code, $language->name, $sourceKeys, $present);
            }

            return Response::json([
                'data' => [
                    'source_language_code' => LanguageRegistry::SOURCE_LANGUAGE,
                    'languages' => $report,
                ],
            ], 200);
        } catch (\Throwable $e) {
            error_log('[TranslationsApiHandler] coverage failed: ' . $e->getMessage());
            return Response::error('Failed to compute translation coverage', 500);
        }
    }

    /**
     * Fold one language's key sets into the counts the console renders.
     *
     * @param array<string, array<string, true>> $sourceKeys domain => key => true
     * @param array<string, array<string, true>> $present    domain => key => true
     * @return array<string, mixed>
     */
    private static function coverageForLanguage(
        string $code,
        string $name,
        array $sourceKeys,
        array $present,
    ): array {
        $domains = [];
        $total = 0;
        $translated = 0;

        /** @var list<string> $domainNames */
        $domainNames = array_values(array_unique(array_merge(array_keys($sourceKeys), array_keys($present))));
        sort($domainNames);

        foreach ($domainNames as $domain) {
            $universe = ($sourceKeys[$domain] ?? []) + ($present[$domain] ?? []);
            $domainTotal = count($universe);
            $domainTranslated = count($present[$domain] ?? []);

            $domains[] = [
                'domain' => $domain,
                'total' => $domainTotal,
                'translated' => $domainTranslated,
                'missing' => $domainTotal - $domainTranslated,
            ];

            $total += $domainTotal;
            $translated += $domainTranslated;
        }

        return [
            'language_code' => $code,
            'name' => $name,
            'total' => $total,
            'translated' => $translated,
            'missing' => $total - $translated,
            'domains' => $domains,
        ];
    }

    /**
     * POST /api/v1/translations — admin: create a translation row.
     *
     * Body: `{ language_code, domain, key, translation }`. The row's scope
     * follows the CALLER, not the body: the system tenant (0) creates a
     * system-default row (tenant_id NULL); a regular tenant creates its OWN
     * override row (tenant_id = caller). There is no way to target another
     * tenant's override through this endpoint.
     *
     * Response: `{ data: {...} }` (201), or 409 when a row already exists for
     * this (language, domain, key) in the caller's scope.
     */
    public function create(Request $request): Response
    {
        $auth = $this->authorize($request, CorePermissions::TRANSLATIONS_MANAGE);
        if ($auth instanceof Response) {
            return $auth;
        }
        ['tenantId' => $tenantId] = $auth;

        $body = JsonBody::parsed($request);
        $languageCode = is_string($body['language_code'] ?? null) ? $body['language_code'] : '';
        $domain = is_string($body['domain'] ?? null) ? $body['domain'] : '';
        $key = is_string($body['key'] ?? null) ? $body['key'] : '';
        $text = is_string($body['translation'] ?? null) ? $body['translation'] : '';

        if ($languageCode === '') {
            return Response::error('language_code is required', 422);
        }
        if (!TranslationDomain::isValid($domain)) {
            return Response::error(
                'domain is required and must be a bare slug (core, e.g. "auth") or a '
                . 'plugin-namespaced one (e.g. "acme:catalog")',
                422
            );
        }
        if ($key === '') {
            return Response::error('key is required', 422);
        }
        if ($text === '') {
            return Response::error('translation is required', 422);
        }
        if ($tooLong = InputLimits::firstViolation([
            'domain' => [$domain, InputLimits::NAME_MAX],
            'key' => [$key, InputLimits::NAME_MAX],
            'translation' => [$text, InputLimits::TEXT_MAX],
        ])) {
            return $tooLong;
        }

        try {
            $language = $this->languageRepository->findByCode($languageCode);
            if ($language === null) {
                return Response::error("Language '{$languageCode}' not found", 404);
            }

            $ownerTenantId = $tenantId === 0 ? null : $tenantId;

            $translation = $this->translationRepository->create($language->id, $domain, $key, $text, $ownerTenantId);
            if ($translation === null) {
                return Response::error('A translation for this key already exists in this scope', 409);
            }

            $this->languageRegistry->invalidateCache();

            return Response::json(['data' => self::translationPayload($translation)], 201);
        } catch (\Throwable $e) {
            error_log('[TranslationsApiHandler] create failed: ' . $e->getMessage());
            return Response::error('Failed to create translation', 500);
        }
    }

    /**
     * PATCH /api/v1/translations/{id} — admin: update a translation row's text.
     *
     * Body: `{ translation }`. Write access follows the System-Tenant Context
     * convention (see class docblock): 404 when the row is outside the
     * caller's scope (a regular tenant touching a global/foreign row), 422
     * when the system tenant targets a per-tenant override.
     *
     * @param array<string, mixed> $params Route params (expects `id`).
     */
    public function update(Request $request, array $params): Response
    {
        $auth = $this->authorize($request, CorePermissions::TRANSLATIONS_MANAGE);
        if ($auth instanceof Response) {
            return $auth;
        }
        ['tenantId' => $tenantId] = $auth;

        $id = (int) ($params['id'] ?? 0);

        try {
            $existing = $this->translationRepository->findById($id);
            if ($existing === null) {
                return Response::error('Translation not found', 404);
            }

            $access = self::writeAccessFor($existing->tenantId, $tenantId);
            if ($access === 'not_found') {
                return Response::error('Translation not found', 404);
            }
            if ($access === 'wrong_scope') {
                return Response::error(
                    'The system tenant has no per-tenant override layer; edit the system-default translation instead',
                    422
                );
            }

            $body = JsonBody::parsed($request);
            $text = is_string($body['translation'] ?? null) ? $body['translation'] : '';
            if ($text === '') {
                return Response::error('translation is required and must be a non-empty string', 422);
            }
            if ($tooLong = InputLimits::firstViolation(['translation' => [$text, InputLimits::TEXT_MAX]])) {
                return $tooLong;
            }

            $expectedTenantId = $tenantId === 0 ? null : $tenantId;
            if (!$this->translationRepository->update($id, $text, $expectedTenantId)) {
                return Response::error('Translation not found', 404);
            }

            $this->languageRegistry->invalidateCache();

            $fresh = $this->translationRepository->findById($id);
            return Response::json(['data' => $fresh !== null ? self::translationPayload($fresh) : null], 200);
        } catch (\Throwable $e) {
            error_log('[TranslationsApiHandler] update failed: ' . $e->getMessage());
            return Response::error('Failed to update translation', 500);
        }
    }

    /**
     * DELETE /api/v1/translations/{id} — admin: delete a translation row.
     *
     * Write access follows the same rule as {@see self::update()}.
     *
     * @param array<string, mixed> $params Route params (expects `id`).
     */
    public function delete(Request $request, array $params): Response
    {
        $auth = $this->authorize($request, CorePermissions::TRANSLATIONS_MANAGE);
        if ($auth instanceof Response) {
            return $auth;
        }
        ['tenantId' => $tenantId] = $auth;

        $id = (int) ($params['id'] ?? 0);

        try {
            $existing = $this->translationRepository->findById($id);
            if ($existing === null) {
                return Response::error('Translation not found', 404);
            }

            $access = self::writeAccessFor($existing->tenantId, $tenantId);
            if ($access === 'not_found') {
                return Response::error('Translation not found', 404);
            }
            if ($access === 'wrong_scope') {
                return Response::error(
                    'The system tenant has no per-tenant override layer; edit the system-default translation instead',
                    422
                );
            }

            $expectedTenantId = $tenantId === 0 ? null : $tenantId;
            if (!$this->translationRepository->delete($id, $expectedTenantId)) {
                return Response::error('Translation not found', 404);
            }

            $this->languageRegistry->invalidateCache();

            return Response::json([], 204);
        } catch (\Throwable $e) {
            error_log('[TranslationsApiHandler] delete failed: ' . $e->getMessage());
            return Response::error('Failed to delete translation', 500);
        }
    }

    /**
     * Whether the CALLER (identified by its tenant id) may write the row
     * identified by $rowTenantId — the System-Tenant Context asymmetry:
     *  - the SYSTEM tenant (0) may only write a system-default row (tenant_id
     *    NULL); targeting a per-tenant override is 'wrong_scope' (422).
     *  - a REGULAR tenant may only write its OWN override row (tenant_id ===
     *    caller); a global row or another tenant's row is 'not_found' (404).
     *
     * @return 'ok'|'not_found'|'wrong_scope'
     */
    private static function writeAccessFor(?int $rowTenantId, int $callerTenantId): string
    {
        if ($callerTenantId === 0) {
            return $rowTenantId === null ? 'ok' : 'wrong_scope';
        }

        return $rowTenantId === $callerTenantId ? 'ok' : 'not_found';
    }

    /**
     * @return array{id: int, language_id: int, domain: string, key: string, translation: string, tenant_id: int|null, created_at: string, updated_at: string}
     */
    private static function translationPayload(Translation $translation): array
    {
        return [
            'id' => $translation->id,
            'language_id' => $translation->languageId,
            'domain' => $translation->domain,
            'key' => $translation->key,
            'translation' => $translation->translation,
            'tenant_id' => $translation->tenantId,
            'created_at' => $translation->createdAt,
            'updated_at' => $translation->updatedAt,
        ];
    }

    /**
     * Resolve the tenant + acting user and assert the required permission.
     * The route-level RbacMiddleware already enforces the permission; this is
     * defence in depth, mirroring TagsApiHandler/SettingsApiHandler.
     *
     * @return array{tenantId: int, userId: int}|Response
     */
    private function authorize(Request $request, string $permission): array|Response
    {
        $tenantId = $this->tenantContext->getTenantId();
        if ($tenantId === null) {
            return Response::error('Tenant context is required', 403);
        }

        $actor = $request->user;
        $userId = is_object($actor) && isset($actor->profile_id) && is_int($actor->profile_id)
            ? $actor->profile_id
            : null;
        if ($userId === null || !$this->roleChecker->hasPermissionForProfile($userId, $permission, $tenantId)) {
            return Response::error('Insufficient permissions', 403, ['required' => $permission]);
        }

        return ['tenantId' => $tenantId, 'userId' => $userId];
    }

    /**
     * Query params from $_GET (production) merged with the path query string
     * (tests), as string values.
     *
     * @return array<string, string>
     */
    private static function queryParams(Request $request): array
    {
        $query = [];
        foreach ($_GET as $k => $v) {
            if (is_string($k) && is_string($v)) {
                $query[$k] = $v;
            }
        }
        $qs = parse_url($request->getPath(), PHP_URL_QUERY);
        if (is_string($qs) && $qs !== '') {
            parse_str($qs, $parsed);
            foreach ($parsed as $k => $v) {
                if (is_string($k) && is_string($v)) {
                    $query[$k] = $v;
                }
            }
        }

        return $query;
    }
}
