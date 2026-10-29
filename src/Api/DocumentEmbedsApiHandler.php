<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Auth\RoleChecker;
use Whity\Core\Document\Embed\DocumentEmbedRegistry;
use Whity\Core\RBAC\ScopedPermissionSet;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Tenant\TenantContext;

/**
 * Live data for the editor's Record and Chart blocks (editor M3, E.16).
 *
 *   GET /api/document-embeds                       → catalogue() (documents:read)
 *   GET /api/document-embeds/records/{type}/{id}   → record()    (documents:read)
 *   GET /api/document-embeds/charts/{key}          → chart()     (documents:read)
 *
 * Every answer is for the CALLER, not the document: a Record or Chart block in
 * a shared document shows each reader only what that reader may see, and the
 * providers in {@see DocumentEmbedRegistry} do the checking. A record that
 * does not exist and one the reader may not see are the same 404, so a block
 * never reveals that a hidden record exists. Values are read live; a PDF
 * freezes them when it renders (M4).
 */
final class DocumentEmbedsApiHandler
{
    public function __construct(
        private readonly DocumentEmbedRegistry $registry,
        private readonly RoleChecker $roleChecker,
    ) {
    }

    public function catalogue(Request $request): Response
    {
        $ctx = $this->context($request);
        if ($ctx instanceof Response) {
            return $ctx;
        }

        return Response::json(['data' => [
            'record_types' => array_map(
                static fn ($p): array => ['key' => $p->type(), 'label' => $p->label()],
                $this->registry->recordProviders(),
            ),
            'chart_sources' => array_map(
                static fn ($s): array => ['key' => $s->key(), 'label' => $s->label()],
                $this->registry->chartSources(),
            ),
        ]]);
    }

    /**
     * @param array<string, string> $params
     */
    public function record(Request $request, array $params): Response
    {
        $ctx = $this->context($request);
        if ($ctx instanceof Response) {
            return $ctx;
        }
        [$tenantId, $callerId] = $ctx;

        $provider = $this->registry->recordProvider((string) ($params['type'] ?? ''));
        $view = $provider?->resolve($tenantId, $callerId, $this->permissions($callerId, $tenantId), (string) ($params['id'] ?? ''));
        if ($view === null) {
            return Response::error('Record not found', 404);
        }

        return Response::json(['data' => $view]);
    }

    /**
     * @param array<string, string> $params
     */
    public function chart(Request $request, array $params): Response
    {
        $ctx = $this->context($request);
        if ($ctx instanceof Response) {
            return $ctx;
        }
        [$tenantId, $callerId] = $ctx;

        $source = $this->registry->chartSource((string) ($params['key'] ?? ''));
        $data = $source?->load($tenantId, $callerId, $this->permissions($callerId, $tenantId), self::stringQuery($request));
        if ($data === null) {
            return Response::error('Chart source not found', 404);
        }

        return Response::json(['data' => $data]);
    }

    /**
     * The query string's scalar values, which are the block's `source.params`.
     *
     * @return array<string, string>
     */
    private static function stringQuery(Request $request): array
    {
        $query = parse_url($request->getPath(), PHP_URL_QUERY);
        $params = [];
        if (is_string($query) && $query !== '') {
            parse_str($query, $params);
        }

        return array_filter($params, static fn ($v, $k): bool => is_string($k) && is_string($v), ARRAY_FILTER_USE_BOTH);
    }

    /**
     * @return array{0: int, 1: int}|Response
     */
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
     * @return callable(string, int|null=): bool
     */
    private function permissions(int $callerId, int $tenantId): callable
    {
        return ScopedPermissionSet::forProfile($this->roleChecker, $callerId, $tenantId);
    }
}
