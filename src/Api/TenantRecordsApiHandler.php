<?php

declare(strict_types=1);

namespace Whity\Api;

use Throwable;
use Whity\Auth\RoleChecker;
use Whity\Core\Plugin\Tenant\RecordActor;
use Whity\Core\Plugin\Tenant\RecordWriter;
use Whity\Core\Plugin\Tenant\TenantManifestHost;
use Whity\Core\Plugin\Tenant\TenantRecordRepository;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Tenant\TenantContext;
use Whity\Http\JsonBody;
use Whity\Sdk\Manifest\PluginManifest;
use Whity\Sdk\PluginNamespace;

/**
 * The record routes of workspace plugins (Whity Builder, WHIT-761):
 * `/api/v1/x/{plugin}/{type}` and below.
 *
 * ONE FIXED ROUTE FAMILY, registered once by core. Which plugin and record
 * type a call means, and which permission gates it, are read from the
 * workspace's live manifest on each request, as the data-type routes read a
 * type's declared permissions. So the routes carry no route-level permission;
 * this handler checks the record type's declared one through the same
 * RoleChecker the RBAC middleware uses, and fails closed on everything else.
 *
 * An unknown plugin, record type or record answers 404 whether it doesn't
 * exist or belongs to another workspace, so ids can't be probed.
 *
 * Writes go through {@see RecordWriter}, the path automations write through
 * too, so rules, audit and automation triggers are the same for both.
 */
final class TenantRecordsApiHandler
{
    public const AUDIT_CREATED = RecordWriter::AUDIT_CREATED;
    public const AUDIT_UPDATED = RecordWriter::AUDIT_UPDATED;
    public const AUDIT_MOVED = RecordWriter::AUDIT_MOVED;
    public const AUDIT_DELETED = RecordWriter::AUDIT_DELETED;

    public function __construct(
        private readonly TenantManifestHost $host,
        private readonly TenantRecordRepository $records,
        private readonly RoleChecker $roleChecker,
        private readonly RecordWriter $writer,
    ) {
    }

    /**
     * GET /api/x/{plugin}/{type}?state=&after=&limit=
     *
     * @param array<string, mixed> $params
     */
    public function list(Request $request, array $params = []): Response
    {
        return $this->guard($request, $params, 'read', function (array $ctx) use ($request): Response {
            $query = self::query($request);
            $state = isset($query['state']) && is_string($query['state']) && $query['state'] !== '' ? $query['state'] : null;
            $after = isset($query['after']) && ctype_digit((string) $query['after']) ? (int) $query['after'] : null;
            $limit = isset($query['limit']) && ctype_digit((string) $query['limit']) ? (int) $query['limit'] : 50;
            $rows = $this->records->list($ctx['tenant'], $ctx['plugin'], $ctx['type'], $state, $after, $limit);

            return Response::json(['data' => array_map([self::class, 'present'], $rows)]);
        });
    }

    /**
     * GET /api/x/{plugin}/{type}/{id}
     *
     * @param array<string, mixed> $params
     */
    public function get(Request $request, array $params = []): Response
    {
        $id = (string) ($params['id'] ?? '0');

        return $this->guard($request, $params, 'read', function (array $ctx) use ($id): Response {
            $row = $this->records->find($ctx['tenant'], $ctx['plugin'], $ctx['type'], (int) $id);

            return $row === null ? self::notFound() : Response::json(['data' => self::present($row)]);
        });
    }

    /**
     * POST /api/x/{plugin}/{type}
     *
     * @param array<string, mixed> $params
     */
    public function create(Request $request, array $params = []): Response
    {
        return $this->guard($request, $params, 'create', function (array $ctx) use ($request): Response {
            $result = $this->writer->create($ctx['tenant'], $ctx['manifest'], $ctx['type'], JsonBody::parsed($request), RecordActor::person($ctx['profile']));
            if (!$result['ok']) {
                return Response::json(['issues' => $result['issues']], 422);
            }
            $id = $result['id'];
            $row = $this->records->find($ctx['tenant'], $ctx['plugin'], $ctx['type'], $id);

            return Response::json(['data' => $row === null ? ['id' => $id] : self::present($row)], 201);
        });
    }

    /**
     * PATCH /api/x/{plugin}/{type}/{id}
     *
     * @param array<string, mixed> $params
     */
    public function update(Request $request, array $params = []): Response
    {
        $id = (string) ($params['id'] ?? '0');

        return $this->guard($request, $params, 'edit', function (array $ctx) use ($request, $id): Response {
            $result = $this->writer->update($ctx['tenant'], $ctx['manifest'], $ctx['type'], (int) $id, JsonBody::parsed($request), RecordActor::person($ctx['profile']));
            if (!$result['ok']) {
                return $result['reason'] === 'issues' ? Response::json(['issues' => $result['issues']], 422) : self::notFound();
            }

            return Response::json(['data' => self::present((array) $this->records->find($ctx['tenant'], $ctx['plugin'], $ctx['type'], (int) $id))]);
        });
    }

    /**
     * POST /api/x/{plugin}/{type}/{id}/moves/{move}
     *
     * @param array<string, mixed> $params
     */
    public function move(Request $request, array $params = []): Response
    {
        $id = (string) ($params['id'] ?? '0');
        $move = (string) ($params['move'] ?? '');

        return $this->guard($request, $params, 'read', function (array $ctx) use ($id, $move): Response {
            $declared = $ctx['decl']['lifecycle']['moves'][$move] ?? null;
            if (!is_array($declared)) {
                return self::notFound();
            }
            if (!$this->roleChecker->hasPermissionForProfile($ctx['profile'], (string) $declared['permission'], $ctx['tenant'])) {
                return Response::json(['error' => 'Insufficient permissions', 'details' => ['reason' => 'insufficient_permissions', 'required' => $declared['permission']]], 403);
            }
            $result = $this->writer->move($ctx['tenant'], $ctx['manifest'], $ctx['type'], (int) $id, $move, RecordActor::person($ctx['profile']));
            if (!$result['ok']) {
                return match ($result['reason']) {
                    'issues' => Response::json(['issues' => $result['issues']], 422),
                    'move_not_available' => Response::json([
                        'error' => 'This move isn\'t available from the record\'s state',
                        'details' => ['reason' => 'move_not_available', 'state' => $result['state'], 'from' => $result['from']],
                    ], 409),
                    default => self::notFound(),
                };
            }

            return Response::json(['data' => self::present((array) $this->records->find($ctx['tenant'], $ctx['plugin'], $ctx['type'], (int) $id))]);
        });
    }

    /**
     * DELETE /api/x/{plugin}/{type}/{id}
     *
     * @param array<string, mixed> $params
     */
    public function delete(Request $request, array $params = []): Response
    {
        $id = (string) ($params['id'] ?? '0');

        return $this->guard($request, $params, 'delete', function (array $ctx) use ($id): Response {
            if (!$this->writer->delete($ctx['tenant'], $ctx['manifest'], $ctx['type'], (int) $id, RecordActor::person($ctx['profile']))) {
                return self::notFound();
            }

            return new Response(204, '');
        });
    }

    /**
     * Resolve the caller, the plugin and the record type, check the type's
     * declared permission for `$action`, then run `$then`.
     *
     * @param array<string, mixed> $params Route params: plugin, type.
     * @param callable(array{tenant: int, profile: int, plugin: string, type: string, decl: array<string, mixed>, manifest: PluginManifest}): Response $then
     */
    private function guard(Request $request, array $params, string $action, callable $then): Response
    {
        $plugin = (string) ($params['plugin'] ?? '');
        $type = (string) ($params['type'] ?? '');
        try {
            $tenantId = TenantContext::getTenantId();
            if ($tenantId === null) {
                return Response::error('Tenant context is required', 403);
            }
            $actor = $request->user;
            $profileId = is_object($actor) && isset($actor->profile_id) && is_int($actor->profile_id) ? $actor->profile_id : null;
            if ($profileId === null) {
                return Response::error('Authentication required', 401);
            }

            $manifest = null;
            foreach ($this->host->manifestsFor($tenantId) as $candidate) {
                if (PluginNamespace::slug($candidate->name()) === $plugin) {
                    $manifest = $candidate;
                    break;
                }
            }
            $decl = $manifest?->records()[$type] ?? null;
            if ($manifest === null || !is_array($decl)) {
                return self::notFound();
            }

            $permission = (string) ($decl['permissions'][$action] ?? '');
            if ($permission === '' || !$this->roleChecker->hasPermissionForProfile($profileId, $permission, $tenantId)) {
                // A caller who can't read the type learns nothing about it.
                if ($action === 'read' || $permission === '' || !$this->roleChecker->hasPermissionForProfile($profileId, (string) ($decl['permissions']['read'] ?? ''), $tenantId)) {
                    return self::notFound();
                }
                return Response::json(['error' => 'Insufficient permissions', 'details' => ['reason' => 'insufficient_permissions', 'required' => $permission]], 403);
            }

            return $then([
                'tenant' => $tenantId,
                'profile' => $profileId,
                'plugin' => $manifest->name(),
                'type' => $type,
                'decl' => $decl,
                'manifest' => $manifest,
            ]);
        } catch (Throwable $e) {
            error_log('[TenantRecordsApiHandler] ' . $e->getMessage());

            return Response::error('The record could not be processed', 500);
        }
    }

    /**
     * A record as the API and data-bound blocks read it: the store's own
     * columns, then the fields.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function present(array $row): array
    {
        /** @var array<string, mixed> $data */
        $data = $row['data'] ?? [];

        return ['id' => $row['id'], 'state' => $row['state'], 'created_at' => $row['created_at'], 'updated_at' => $row['updated_at']] + $data;
    }

    /** @return array<string, mixed> */
    private static function query(Request $request): array
    {
        $raw = parse_url($request->getPath(), PHP_URL_QUERY);
        $query = [];
        if (is_string($raw) && $raw !== '') {
            parse_str($raw, $query);
        }

        /** @var array<string, mixed> */
        return $query;
    }

    private static function notFound(): Response
    {
        return Response::json(['error' => 'Not found', 'details' => ['reason' => 'not_found']], 404);
    }
}
