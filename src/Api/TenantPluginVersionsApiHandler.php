<?php

declare(strict_types=1);

namespace Whity\Api;

use Throwable;
use Whity\Core\Audit\AuditLogger;
use Whity\Core\Plugin\Tenant\ManifestImpact;
use Whity\Core\Plugin\Tenant\ManifestTestRunner;
use Whity\Core\Plugin\Tenant\TenantManifestPublisher;
use Whity\Core\Plugin\Tenant\TenantPluginRepository;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Tenant\TenantContext;
use Whity\Http\JsonBody;
use Whity\Sdk\Manifest\InvalidManifestException;
use Whity\Sdk\Manifest\PluginManifest;
use Whity\Sdk\PluginNamespace;

/**
 * A workspace's own plugins and their versions (Whity Builder, WHIT-764;
 * docs/design/WHITY-BUILDER.md 2.6 and 2.7).
 *
 *   GET  /api/workspace-plugins                                    plugins:read
 *   POST /api/workspace-plugins/validate        {manifest}         plugins:build
 *   POST /api/workspace-plugins/impact          {manifest}         plugins:build
 *   POST /api/workspace-plugins/test            {manifest}         plugins:build
 *   POST /api/workspace-plugins                 {manifest, notes?, confirmDestructive?}
 *                                                                  plugins:publish
 *   GET  /api/workspace-plugins/{plugin}/versions                  plugins:read
 *   GET  /api/workspace-plugins/{plugin}/versions/{id}             plugins:read
 *   POST /api/workspace-plugins/{plugin}/versions/{id}/activate {confirmDestructive?}
 *                                                                  plugins:publish
 *
 * The route permissions are checked by the RBAC middleware. Publishing and
 * activating compare the version with the live one first ({@see ManifestImpact})
 * and answer 409 with the plan when it is destructive and wasn't confirmed.
 * Publishing runs the manifest's own tests first ({@see ManifestTestRunner},
 * the publish check) and answers 422 with the results when one fails.
 * Every publish, activation and refused publish is audited.
 *
 * Versions are immutable and all kept, so a rollback (activating an older
 * version) needs nothing but core.
 */
final class TenantPluginVersionsApiHandler
{
    public const AUDIT_REFUSED = 'tenant_plugin.publish_refused';

    public function __construct(
        private readonly TenantPluginRepository $plugins,
        private readonly TenantManifestPublisher $publisher,
        private readonly ManifestImpact $impact,
        private readonly ManifestTestRunner $tests,
        private readonly ?AuditLogger $audit = null,
    ) {
    }

    public function list(Request $request): Response
    {
        return $this->guarded(fn (int $tenantId): Response => Response::json(['data' => array_map(
            static fn (array $p): array => ['slug' => PluginNamespace::slug($p['plugin'])] + $p,
            $this->plugins->plugins($tenantId),
        )]));
    }

    public function validate(Request $request): Response
    {
        return $this->guarded(function (int $tenantId) use ($request): Response {
            $manifest = JsonBody::parsed($request)['manifest'] ?? null;
            if (!is_array($manifest)) {
                return self::invalid(['manifest: required, the plugin.json object']);
            }
            try {
                $parsed = $this->publisher->check($tenantId, $manifest);
            } catch (InvalidManifestException $e) {
                return Response::json(['data' => ['ok' => false, 'errors' => $e->getErrors()]]);
            }

            return Response::json(['data' => ['ok' => true, 'errors' => [], 'plugin' => $parsed->name(), 'version' => $parsed->version()]]);
        });
    }

    public function impact(Request $request): Response
    {
        return $this->guarded(function (int $tenantId) use ($request): Response {
            $manifest = JsonBody::parsed($request)['manifest'] ?? null;
            if (!is_array($manifest)) {
                return self::invalid(['manifest: required, the plugin.json object']);
            }
            try {
                $draft = $this->publisher->check($tenantId, $manifest);
            } catch (InvalidManifestException $e) {
                return self::invalid($e->getErrors());
            }

            return Response::json(['data' => $this->impact->plan($tenantId, $this->live($tenantId, $draft->name()), $draft)]);
        });
    }

    /** Run a draft's tests, rolled back; nothing is published. */
    public function test(Request $request): Response
    {
        return $this->guarded(function (int $tenantId) use ($request): Response {
            $manifest = JsonBody::parsed($request)['manifest'] ?? null;
            if (!is_array($manifest)) {
                return self::invalid(['manifest: required, the plugin.json object']);
            }
            try {
                $draft = $this->publisher->check($tenantId, $manifest);
            } catch (InvalidManifestException $e) {
                return self::invalid($e->getErrors());
            }

            return Response::json(['data' => $this->tests->run($tenantId, $draft)]);
        });
    }

    public function publish(Request $request): Response
    {
        return $this->guarded(function (int $tenantId) use ($request): Response {
            $body = JsonBody::parsed($request);
            $manifest = $body['manifest'] ?? null;
            $notes = isset($body['notes']) && is_string($body['notes']) ? mb_substr($body['notes'], 0, 2000) : null;
            $actor = self::profileId($request);
            if (!is_array($manifest)) {
                return self::invalid(['manifest: required, the plugin.json object']);
            }
            try {
                $draft = $this->publisher->check($tenantId, $manifest);
            } catch (InvalidManifestException $e) {
                $this->refused($tenantId, $actor, null, 'invalid', ['errors' => count($e->getErrors())]);

                return self::invalid($e->getErrors());
            }

            $tests = $this->tests->run($tenantId, $draft);
            if ($tests['failed'] > 0) {
                $this->refused($tenantId, $actor, $draft->name(), 'tests_failed', ['version' => $draft->version(), 'failed' => $tests['failed']]);

                return Response::json([
                    'error' => 'The plugin\'s own tests don\'t pass',
                    'details' => ['reason' => 'tests_failed', 'tests' => $tests],
                ], 422);
            }

            $plan = $this->impact->plan($tenantId, $this->live($tenantId, $draft->name()), $draft);
            if ($plan['level'] === ManifestImpact::DESTRUCTIVE && ($body['confirmDestructive'] ?? false) !== true) {
                $this->refused($tenantId, $actor, $draft->name(), 'destructive_unconfirmed', ['version' => $draft->version()]);

                return self::unconfirmed($plan);
            }

            $published = $this->publisher->publish($tenantId, $manifest, $actor, null, null, $notes);

            return Response::json(['data' => $published + ['impact' => $plan, 'tests' => $tests]], 201);
        });
    }

    /**
     * @param array<string, mixed> $params
     */
    public function versions(Request $request, array $params = []): Response
    {
        return $this->guarded(function (int $tenantId) use ($params): Response {
            $plugin = $this->pluginKey($tenantId, (string) ($params['plugin'] ?? ''));
            if ($plugin === null) {
                return self::notFound();
            }
            $live = $this->plugins->liveVersionId($tenantId, $plugin);

            return Response::json(['data' => array_map(
                static fn (array $v): array => $v + ['live' => $v['id'] === $live],
                $this->plugins->history($tenantId, $plugin),
            )]);
        });
    }

    /**
     * @param array<string, mixed> $params
     */
    public function version(Request $request, array $params = []): Response
    {
        return $this->guarded(function (int $tenantId) use ($params): Response {
            $plugin = $this->pluginKey($tenantId, (string) ($params['plugin'] ?? ''));
            $id = (int) ($params['id'] ?? 0);
            $entry = null;
            foreach ($plugin === null ? [] : $this->plugins->history($tenantId, $plugin) as $v) {
                if ($v['id'] === $id) {
                    $entry = $v;
                    break;
                }
            }
            if ($entry === null) {
                return self::notFound();
            }

            return Response::json(['data' => $entry + ['manifest' => $this->plugins->manifest($tenantId, $id)]]);
        });
    }

    /**
     * @param array<string, mixed> $params
     */
    public function activate(Request $request, array $params = []): Response
    {
        return $this->guarded(function (int $tenantId) use ($request, $params): Response {
            $plugin = $this->pluginKey($tenantId, (string) ($params['plugin'] ?? ''));
            $id = (int) ($params['id'] ?? 0);
            $data = $plugin === null ? null : $this->plugins->manifest($tenantId, $id);
            if ($plugin === null || $data === null) {
                return self::notFound();
            }
            $actor = self::profileId($request);
            try {
                $target = $this->publisher->check($tenantId, $data);
            } catch (InvalidManifestException $e) {
                $this->refused($tenantId, $actor, $plugin, 'invalid', ['version_id' => $id, 'errors' => count($e->getErrors())]);

                return self::invalid($e->getErrors());
            }
            if ($target->name() !== $plugin) {
                return self::notFound();
            }

            $plan = $this->impact->plan($tenantId, $this->live($tenantId, $plugin), $target);
            if ($plan['level'] === ManifestImpact::DESTRUCTIVE && (JsonBody::parsed($request)['confirmDestructive'] ?? false) !== true) {
                $this->refused($tenantId, $actor, $plugin, 'destructive_unconfirmed', ['version_id' => $id]);

                return self::unconfirmed($plan);
            }

            $activated = $this->publisher->activate($tenantId, $plugin, $id, $actor);

            return $activated === null
                ? self::notFound()
                : Response::json(['data' => $activated + ['impact' => $plan]]);
        });
    }

    /** The live manifest of a workspace's plugin, switched on or not. */
    private function live(int $tenantId, string $plugin): ?PluginManifest
    {
        $versionId = $this->plugins->liveVersionId($tenantId, $plugin);
        $data = $versionId === null ? null : $this->plugins->manifest($tenantId, $versionId);
        if ($data === null) {
            return null;
        }
        try {
            return PluginManifest::fromArray($data, 'live workspace plugin');
        } catch (InvalidManifestException) {
            // Validated when published; the format may have tightened since.
            // Compare against nothing rather than refuse to plan.
            return null;
        }
    }

    /** A plugin's name from the slug in its routes, when it is this workspace's. */
    private function pluginKey(int $tenantId, string $slug): ?string
    {
        foreach ($this->plugins->plugins($tenantId) as $p) {
            if (PluginNamespace::slug($p['plugin']) === $slug) {
                return $p['plugin'];
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function refused(int $tenantId, ?int $actor, ?string $plugin, string $reason, array $metadata): void
    {
        $this->audit?->record(self::AUDIT_REFUSED, [
            'tenant_id' => $tenantId,
            'actor_user_id' => $actor,
            'target_type' => 'tenant_plugin',
            'metadata' => ['plugin' => $plugin, 'reason' => $reason] + $metadata,
        ]);
    }

    /**
     * @param callable(int): Response $then
     */
    private function guarded(callable $then): Response
    {
        try {
            $tenantId = TenantContext::getTenantId();
            if ($tenantId === null || $tenantId === 0) {
                // Workspace plugins belong to a workspace; the system tenant has none.
                return Response::error('A workspace is required', 403);
            }

            return $then($tenantId);
        } catch (Throwable $e) {
            error_log('[TenantPluginVersionsApiHandler] ' . $e->getMessage());

            return Response::error('The request could not be processed', 500);
        }
    }

    private static function profileId(Request $request): ?int
    {
        $actor = $request->user;

        return is_object($actor) && isset($actor->profile_id) && is_int($actor->profile_id) ? $actor->profile_id : null;
    }

    /**
     * @param list<string> $errors
     */
    private static function invalid(array $errors): Response
    {
        return Response::json(['error' => 'The manifest isn\'t valid', 'details' => ['reason' => 'invalid_manifest', 'errors' => $errors]], 422);
    }

    /**
     * @param array<string, mixed> $plan
     */
    private static function unconfirmed(array $plan): Response
    {
        return Response::json([
            'error' => 'This would stop serving existing records; confirm to go ahead',
            'details' => ['reason' => 'destructive_unconfirmed', 'impact' => $plan],
        ], 409);
    }

    private static function notFound(): Response
    {
        return Response::json(['error' => 'Not found', 'details' => ['reason' => 'not_found']], 404);
    }
}
