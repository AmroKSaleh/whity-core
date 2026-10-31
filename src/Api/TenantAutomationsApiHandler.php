<?php

declare(strict_types=1);

namespace Whity\Api;

use Throwable;
use Whity\Auth\RoleChecker;
use Whity\Core\Audit\AuditLogger;
use Whity\Core\Plugin\Tenant\Automation\AutomationRunRepository;
use Whity\Core\Plugin\Tenant\TenantManifestHost;
use Whity\Core\Plugin\Tenant\TenantPluginRepository;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Tenant\TenantContext;
use Whity\Http\JsonBody;
use Whity\Sdk\Manifest\PluginManifest;
use Whity\Sdk\PluginNamespace;

/**
 * A workspace plugin's automations (Whity Builder, WHIT-763): their run
 * traces, and the switch that turns them off.
 *
 *   GET /api/workspace-plugins/{plugin}/automation-runs        (plugins:read)
 *   GET /api/workspace-plugins/{plugin}/automation-runs/{id}   (plugins:read)
 *   PUT /api/workspace-plugins/{plugin}/automations {enabled}  (plugins:enable to switch on,
 *                                                               plugins:disable to switch off)
 *
 * `{plugin}` is the slug the record routes use. A plugin that isn't one of
 * this workspace's live plugins, and a run of another workspace, answer 404.
 *
 * A trace carries record values (a step's input, a filled-in message), so a
 * run is shown only to someone who can also read its record type, and a
 * create step's target type: `plugins:read` alone isn't enough.
 */
final class TenantAutomationsApiHandler
{
    public const AUDIT_SWITCHED = 'tenant_plugin.automations_switched';

    public function __construct(
        private readonly TenantManifestHost $host,
        private readonly TenantPluginRepository $plugins,
        private readonly AutomationRunRepository $runs,
        private readonly RoleChecker $roleChecker,
        private readonly ?AuditLogger $audit = null,
    ) {
    }

    /**
     * @param array<string, mixed> $params
     */
    public function runs(Request $request, array $params = []): Response
    {
        return $this->withPlugin($params, function (int $tenantId, PluginManifest $manifest) use ($request): Response {
            $raw = parse_url($request->getPath(), PHP_URL_QUERY);
            $query = [];
            if (is_string($raw)) {
                parse_str($raw, $query);
            }
            $before = is_string($query['before'] ?? null) && ctype_digit($query['before']) ? (int) $query['before'] : null;
            $limit = is_string($query['limit'] ?? null) && ctype_digit($query['limit']) ? (int) $query['limit'] : 50;

            $visible = array_values(array_filter(
                $this->runs->list($tenantId, $manifest->name(), $before, $limit),
                fn (array $run): bool => $this->mayRead($request, $tenantId, $manifest, $run),
            ));

            return Response::json(['data' => $visible]);
        });
    }

    /**
     * @param array<string, mixed> $params
     */
    public function run(Request $request, array $params = []): Response
    {
        $id = (int) ($params['id'] ?? 0);

        return $this->withPlugin($params, function (int $tenantId, PluginManifest $manifest) use ($request, $id): Response {
            $run = $this->runs->find($tenantId, $id);

            return $run === null || $run['plugin'] !== $manifest->name() || !$this->mayRead($request, $tenantId, $manifest, $run)
                ? self::notFound()
                : Response::json(['data' => $run]);
        });
    }

    /**
     * @param array<string, mixed> $params
     */
    public function switch(Request $request, array $params = []): Response
    {
        return $this->withPlugin($params, function (int $tenantId, PluginManifest $manifest) use ($request): Response {
            $plugin = $manifest->name();
            $body = JsonBody::parsed($request);
            if (!is_bool($body['enabled'] ?? null)) {
                return Response::json(['error' => 'enabled must be true or false', 'details' => ['reason' => 'invalid']], 422);
            }
            $enabled = $body['enabled'];
            $permission = $enabled ? CorePermissions::PLUGINS_ENABLE : CorePermissions::PLUGINS_DISABLE;
            $profileId = self::profileId($request);
            if ($profileId === null || !$this->roleChecker->hasPermissionForProfile($profileId, $permission, $tenantId)) {
                return Response::json(['error' => 'Insufficient permissions', 'details' => ['reason' => 'insufficient_permissions', 'required' => $permission]], 403);
            }
            $this->plugins->setAutomationsEnabled($tenantId, $plugin, $enabled);
            $this->audit?->record(self::AUDIT_SWITCHED, [
                'tenant_id' => $tenantId,
                'actor_user_id' => $profileId,
                'target_type' => 'tenant_plugin',
                'metadata' => ['plugin' => $plugin, 'enabled' => $enabled],
            ]);

            return Response::json(['data' => ['plugin' => $plugin, 'automationsEnabled' => $enabled]]);
        });
    }

    /**
     * @param array<string, mixed> $params
     * @param callable(int, PluginManifest): Response $then
     */
    private function withPlugin(array $params, callable $then): Response
    {
        try {
            $tenantId = TenantContext::getTenantId();
            if ($tenantId === null) {
                return Response::error('Tenant context is required', 403);
            }
            $slug = (string) ($params['plugin'] ?? '');
            foreach ($this->host->manifestsFor($tenantId) as $manifest) {
                if (PluginNamespace::slug($manifest->name()) === $slug) {
                    return $then($tenantId, $manifest);
                }
            }

            return self::notFound();
        } catch (Throwable $e) {
            error_log('[TenantAutomationsApiHandler] ' . $e->getMessage());

            return Response::error('The request could not be processed', 500);
        }
    }

    /**
     * Whether the caller can read the record types a run's trace shows.
     *
     * @param array<string, mixed> $run
     */
    private function mayRead(Request $request, int $tenantId, PluginManifest $manifest, array $run): bool
    {
        $profileId = self::profileId($request);
        if ($profileId === null) {
            return false;
        }
        $records = $manifest->records();
        $types = [(string) $run['record_type']];
        foreach ($manifest->automations()[$run['automation']]['steps'] ?? [] as $step) {
            if (isset($step['create_record']['type'])) {
                $types[] = (string) $step['create_record']['type'];
            }
        }
        foreach (array_unique($types) as $type) {
            $read = $records[$type]['permissions']['read'] ?? null;
            if (!is_string($read) || !$this->roleChecker->hasPermissionForProfile($profileId, $read, $tenantId)) {
                return false;
            }
        }

        return true;
    }

    private static function profileId(Request $request): ?int
    {
        $actor = $request->user;

        return is_object($actor) && isset($actor->profile_id) && is_int($actor->profile_id) ? $actor->profile_id : null;
    }

    private static function notFound(): Response
    {
        return Response::json(['error' => 'Not found', 'details' => ['reason' => 'not_found']], 404);
    }
}
