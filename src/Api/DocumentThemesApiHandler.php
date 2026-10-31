<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Core\Document\Theme\DocumentThemeRef;
use Whity\Core\Document\Theme\DocumentThemeRepository;
use Whity\Core\Document\Theme\DocumentThemeResolver;
use Whity\Core\Document\Theme\DocumentThemeTokens;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Tenant\TenantContext;
use Whity\Http\JsonBody;

/**
 * `/api/document-themes`: the themes a workspace's documents can use (editor
 * M4d; E.44, DOCUMENT-THEMES.md 5).
 *
 * Reads (documents:read): the list — Whity's standard theme and the
 * workspace's own (plugin themes come with M7) — the token schema, one theme
 * with its resolution, where a theme is used, and a read-only resolve that
 * powers a live preview. Writes (documents:themes): create, change (If-Match
 * with the theme's version: 428 without, 409 when stale), archive, restore and
 * delete. Whity's standard theme can't be changed.
 *
 * A workspace theme is addressed as `tenant.<key>`. Every query binds the
 * tenant (DocumentThemeRepository).
 */
final class DocumentThemesApiHandler
{
    private const MAX_NAME = 120;

    public function __construct(
        private readonly DocumentThemeRepository $themes,
        private readonly DocumentThemeResolver $resolver,
        private readonly SettingsService $settings,
    ) {
    }

    /** GET /api/document-themes */
    public function list(Request $request): Response
    {
        $ctx = $this->context($request);
        if ($ctx instanceof Response) {
            return $ctx;
        }
        [$tenantId] = $ctx;
        $q = self::query($request);
        $scope = is_string($q['scope'] ?? null) ? $q['scope'] : null;
        if ($scope !== null && !in_array($scope, ['system', 'tenant'], true)) {
            return Response::error('scope must be system or tenant', 422);
        }
        $archived = ($q['archived'] ?? null) === '1' || ($q['archived'] ?? null) === 'true';

        $defaults = $this->defaults($tenantId);
        $out = [];
        if ($scope === null || $scope === 'system') {
            $out[] = $this->standard($defaults);
        }
        if (($scope === null || $scope === 'tenant') && $tenantId !== 0) {
            foreach ($this->themes->list($tenantId, $archived) as $row) {
                $out[] = $this->present($row, $defaults);
            }
        }

        return Response::json(['data' => $out, 'meta' => ['total' => count($out)]]);
    }

    /** GET /api/document-themes/schema */
    public function schema(Request $request): Response
    {
        $ctx = $this->context($request);
        if ($ctx instanceof Response) {
            return $ctx;
        }

        return Response::json(['data' => DocumentThemeTokens::schema()]);
    }

    /** @param array<string, string> $params */
    public function show(Request $request, array $params): Response
    {
        $ctx = $this->context($request);
        if ($ctx instanceof Response) {
            return $ctx;
        }
        [$tenantId] = $ctx;
        $ref = (string) ($params['ref'] ?? '');
        $defaults = $this->defaults($tenantId);

        if ($ref === DocumentThemeRef::STANDARD) {
            return Response::json(['data' => $this->standard($defaults) + [
                'resolved' => $this->resolver->resolve($tenantId, ['ref' => $ref]),
            ]]);
        }
        $row = $this->find($tenantId, $ref);
        if ($row === null) {
            return Response::error('Theme not found', 404);
        }

        return Response::json(['data' => $this->present($row, $defaults) + [
            'resolved' => $this->resolver->resolve($tenantId, ['ref' => $ref]),
        ]])->withHeaders(['ETag' => self::etag((int) $row['version'])]);
    }

    /** POST /api/document-themes */
    public function create(Request $request): Response
    {
        $ctx = $this->writeContext($request);
        if ($ctx instanceof Response) {
            return $ctx;
        }
        [$tenantId, $callerId] = $ctx;
        $body = JsonBody::parsed($request);

        $key = $body['key'] ?? null;
        if (!is_string($key) || preg_match(DocumentThemeRef::KEY_PATTERN, $key) !== 1) {
            return Response::error('key must be 1-40 lower-case letters, digits and underscores', 422);
        }
        $fields = $this->fields($body, true);
        if ($fields instanceof Response) {
            return $fields;
        }
        if ($this->themes->find($tenantId, $key) !== null) {
            return Response::error('A theme with this key already exists', 409, ['ref' => DocumentThemeRef::forTenant($key)]);
        }

        $row = $this->themes->create(
            $tenantId,
            $key,
            (string) ($fields['name'] ?? ''),
            (string) ($fields['name_ar'] ?? ''),
            $fields['extends'] ?? DocumentThemeRef::STANDARD,
            $fields['tokens'] ?? [],
            $callerId,
        );

        return Response::json(['data' => $this->present($row, $this->defaults($tenantId))], 201)
            ->withHeaders(['ETag' => self::etag((int) $row['version'])]);
    }

    /** @param array<string, string> $params */
    public function update(Request $request, array $params): Response
    {
        $ctx = $this->writeContext($request);
        if ($ctx instanceof Response) {
            return $ctx;
        }
        [$tenantId, $callerId] = $ctx;
        $ref = (string) ($params['ref'] ?? '');
        if ($ref === DocumentThemeRef::STANDARD) {
            return Response::error("Whity's standard theme can't be changed; make a workspace theme that extends it", 409);
        }
        $row = $this->find($tenantId, $ref);
        if ($row === null) {
            return Response::error('Theme not found', 404);
        }
        $base = DocumentSourcesApiHandler::parseIfMatch($request->getHeader('If-Match'));
        if ($base === null) {
            return Response::error("If-Match with the theme's version is required", 428);
        }
        $fields = $this->fields(JsonBody::parsed($request), false);
        if ($fields instanceof Response) {
            return $fields;
        }
        if ($fields === []) {
            return Response::error('No updatable fields supplied', 422);
        }

        $saved = $this->themes->update($tenantId, (string) $row['key'], $base, $fields, $callerId);
        if ($saved === null) {
            $current = $this->themes->find($tenantId, (string) $row['key']);

            return Response::error('The theme changed since this edit was made', 409, ['version' => $current['version'] ?? null])
                ->withHeaders($current !== null ? ['ETag' => self::etag((int) $current['version'])] : []);
        }

        return Response::json(['data' => $this->present($saved, $this->defaults($tenantId))])
            ->withHeaders(['ETag' => self::etag((int) $saved['version'])]);
    }

    /** @param array<string, string> $params */
    public function archive(Request $request, array $params): Response
    {
        return $this->setArchived($request, $params, true);
    }

    /** @param array<string, string> $params */
    public function restore(Request $request, array $params): Response
    {
        return $this->setArchived($request, $params, false);
    }

    /**
     * DELETE: only a theme nothing uses. One that a draft names, a published
     * version printed with, or a workspace default points at answers 409 with
     * where it is used; archive it instead.
     *
     * @param array<string, string> $params
     */
    public function delete(Request $request, array $params): Response
    {
        $ctx = $this->writeContext($request);
        if ($ctx instanceof Response) {
            return $ctx;
        }
        [$tenantId] = $ctx;
        $ref = (string) ($params['ref'] ?? '');
        if ($ref === DocumentThemeRef::STANDARD) {
            return Response::error("Whity's standard theme can't be deleted", 409);
        }
        $row = $this->find($tenantId, $ref);
        if ($row === null) {
            return Response::error('Theme not found', 404);
        }
        $usage = $this->usageOf($tenantId, $ref);
        if ($usage['drafts'] !== [] || $usage['published'] !== [] || $usage['defaults'] !== []) {
            return Response::error('This theme is in use; archive it instead', 409, ['usage' => $usage]);
        }
        $this->themes->delete($tenantId, (string) $row['key']);

        return Response::json([], 204);
    }

    /** @param array<string, string> $params */
    public function usage(Request $request, array $params): Response
    {
        $ctx = $this->context($request);
        if ($ctx instanceof Response) {
            return $ctx;
        }
        [$tenantId] = $ctx;
        $ref = (string) ($params['ref'] ?? '');
        if ($ref !== DocumentThemeRef::STANDARD && $this->find($tenantId, $ref) === null) {
            return Response::error('Theme not found', 404);
        }

        return Response::json(['data' => $this->usageOf($tenantId, $ref)]);
    }

    /**
     * POST /api/document-themes/resolve: the theme a document would print
     * with, for a live preview. Reads only.
     */
    public function resolve(Request $request): Response
    {
        $ctx = $this->context($request);
        if ($ctx instanceof Response) {
            return $ctx;
        }
        [$tenantId] = $ctx;
        $body = JsonBody::parsed($request);

        $ref = $body['ref'] ?? null;
        if ($ref !== null && (!is_string($ref) || !DocumentThemeRef::isValid($ref))) {
            return Response::error('ref must be a theme ref such as whity.standard or tenant.<key>', 422);
        }
        $overrides = $body['overrides'] ?? null;
        if ($overrides !== null) {
            $errors = DocumentThemeTokens::errors($overrides, overridesOnly: true);
            if ($errors !== []) {
                return Response::error('Invalid theme overrides', 422, $errors);
            }
        }
        $kind = $body['kind'] ?? null;
        if ($kind !== null && !in_array($kind, DocumentThemeRef::KINDS, true)) {
            return Response::error('kind must be one of: ' . implode(', ', DocumentThemeRef::KINDS), 422);
        }

        $theme = [];
        if (is_string($ref)) {
            $theme['ref'] = $ref;
        }
        if (is_array($overrides)) {
            $theme['overrides'] = $overrides;
        }

        return Response::json(['data' => $this->resolver->resolve($tenantId, $theme, is_string($kind) ? $kind : null)]);
    }

    /** @param array<string, string> $params */
    private function setArchived(Request $request, array $params, bool $archived): Response
    {
        $ctx = $this->writeContext($request);
        if ($ctx instanceof Response) {
            return $ctx;
        }
        [$tenantId, $callerId] = $ctx;
        $ref = (string) ($params['ref'] ?? '');
        if ($ref === DocumentThemeRef::STANDARD) {
            return Response::error("Whity's standard theme can't be archived", 409);
        }
        $row = $this->find($tenantId, $ref);
        if ($row === null) {
            return Response::error('Theme not found', 404);
        }
        $saved = $this->themes->setArchived($tenantId, (string) $row['key'], $archived, $callerId);
        if ($saved === null) {
            return Response::error('Theme not found', 404);
        }

        $body = ['data' => $this->present($saved, $this->defaults($tenantId))];
        if ($archived) {
            // Archiving a theme still in use is allowed, with a warning (5):
            // what names it keeps its look, but the theme leaves the list.
            $usage = $this->usageOf($tenantId, $ref);
            if ($usage['drafts'] !== [] || $usage['published'] !== [] || $usage['defaults'] !== []) {
                $body['warning'] = 'This theme is still in use. What uses it keeps its look, but it no longer appears in the list of themes to choose.';
                $body['usage'] = $usage;
            }
        }

        return Response::json($body);
    }

    /**
     * The body's name, Arabic name, extends and tokens, validated. On create,
     * both names are required.
     *
     * @param array<string, mixed> $body
     * @return array{name?: string, name_ar?: string, extends?: ?string, tokens?: array<string, mixed>}|Response
     */
    private function fields(array $body, bool $creating): array|Response
    {
        $fields = [];
        foreach (['name' => 'name', 'name_ar' => 'name_ar'] as $key => $column) {
            if (!array_key_exists($key, $body)) {
                if ($creating) {
                    return Response::error("{$key} is required: every theme has an English and an Arabic name", 422);
                }
                continue;
            }
            $value = $body[$key];
            if (!is_string($value) || trim($value) === '' || mb_strlen($value) > self::MAX_NAME) {
                return Response::error("{$key} must be a non-empty string of at most " . self::MAX_NAME . ' characters', 422);
            }
            $fields[$column] = trim($value);
        }
        if (array_key_exists('extends', $body)) {
            // Plugin themes come with M7; until then a workspace theme extends Whity's own.
            if ($body['extends'] !== null && $body['extends'] !== DocumentThemeRef::STANDARD) {
                return Response::error('extends must be whity.standard (plugin themes come later)', 422);
            }
            $fields['extends'] = DocumentThemeRef::STANDARD;
        }
        if (array_key_exists('tokens', $body)) {
            $errors = DocumentThemeTokens::errors($body['tokens']);
            if ($errors !== []) {
                return Response::error('Invalid theme tokens', 422, $errors);
            }
            /** @var array<string, mixed> $tokens */
            $tokens = $body['tokens'];
            $fields['tokens'] = DocumentThemeTokens::normalize($tokens);
        }

        /** @var array{name?: string, name_ar?: string, extends?: ?string, tokens?: array<string, mixed>} $fields */
        return $fields;
    }

    /**
     * @param array{theme: string, byKind: array<string, string>} $defaults
     * @return array<string, mixed>
     */
    private function standard(array $defaults): array
    {
        $ref = DocumentThemeRef::STANDARD;

        return [
            'ref' => $ref,
            'scope' => 'system',
            'key' => 'standard',
            'name' => 'Whity standard',
            'name_ar' => 'نسق Whity القياسي',
            'extends' => null,
            'tokens' => DocumentThemeTokens::STANDARD,
            'version' => 0,
            'archived' => false,
            'default_for' => self::defaultFor($ref, $defaults),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @param array{theme: string, byKind: array<string, string>} $defaults
     * @return array<string, mixed>
     */
    private function present(array $row, array $defaults): array
    {
        $ref = DocumentThemeRef::forTenant((string) $row['key']);

        return [
            'ref' => $ref,
            'scope' => 'tenant',
            'key' => $row['key'],
            'name' => $row['name'],
            'name_ar' => $row['name_ar'],
            'extends' => $row['extends'],
            'tokens' => $row['tokens'] === [] ? new \stdClass() : $row['tokens'],
            'version' => $row['version'],
            'archived' => $row['archived_at'] !== null,
            'archived_at' => $row['archived_at'],
            'default_for' => self::defaultFor($ref, $defaults),
            'created_by' => $row['created_by'],
            'updated_by' => $row['updated_by'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ];
    }

    /**
     * Which workspace defaults point at a theme: `workspace` and the kinds.
     *
     * @param array{theme: string, byKind: array<string, string>} $defaults
     * @return list<string>
     */
    private static function defaultFor(string $ref, array $defaults): array
    {
        $out = $defaults['theme'] === $ref ? ['workspace'] : [];
        foreach ($defaults['byKind'] as $kind => $kindRef) {
            if ($kindRef === $ref) {
                $out[] = $kind;
            }
        }

        return $out;
    }

    /** @return array{theme: string, byKind: array<string, string>} */
    private function defaults(int $tenantId): array
    {
        $effective = $this->settings->effective($tenantId);

        return [
            'theme' => (string) ($effective[SettingsRegistry::DOCUMENTS_THEME] ?? DocumentThemeRef::STANDARD),
            'byKind' => DocumentThemeRef::byKind((string) ($effective[SettingsRegistry::DOCUMENTS_THEME_BY_KIND] ?? '')),
        ];
    }

    /**
     * @return array{drafts: list<array{id: int, title: string, kind: string}>, published: list<array{id: int, title: string, version: int}>, defaults: list<string>}
     */
    private function usageOf(int $tenantId, string $ref): array
    {
        $usage = $tenantId !== 0 ? $this->themes->usage($tenantId, $ref) : ['drafts' => [], 'published' => []];

        return $usage + ['defaults' => self::defaultFor($ref, $this->defaults($tenantId))];
    }

    /** @return array<string, mixed>|null */
    private function find(int $tenantId, string $ref): ?array
    {
        $key = DocumentThemeRef::tenantKey($ref);

        return $key !== null && $tenantId !== 0 ? $this->themes->find($tenantId, $key) : null;
    }

    /** @return array{0: int, 1: int}|Response */
    private function context(Request $request): array|Response
    {
        $tenantId = TenantContext::getTenantId();
        if ($tenantId === null) {
            return Response::error('Tenant context is required', 400);
        }
        $actor = $request->user;
        $callerId = is_object($actor) && isset($actor->profile_id) && is_int($actor->profile_id) ? $actor->profile_id : null;
        if ($callerId === null) {
            return Response::error('Authentication required', 401);
        }

        return [$tenantId, $callerId];
    }

    /**
     * As context(), for writes: the system tenant has no workspace themes of
     * its own, so it is told so instead of writing rows no workspace sees.
     *
     * @return array{0: int, 1: int}|Response
     */
    private function writeContext(Request $request): array|Response
    {
        $ctx = $this->context($request);
        if (!$ctx instanceof Response && $ctx[0] === 0) {
            return Response::error('The system tenant has no workspace themes; sign in to a workspace to manage its themes', 422);
        }

        return $ctx;
    }

    private static function etag(int $version): string
    {
        return '"' . $version . '"';
    }

    /** @return array<int|string, mixed> */
    private static function query(Request $request): array
    {
        $query = parse_url($request->getPath(), PHP_URL_QUERY);
        $params = [];
        if (is_string($query) && $query !== '') {
            parse_str($query, $params);
        }

        return $params;
    }
}
