<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\PluginLoader;
use Whity\Core\PluginInstaller;
use Whity\Core\PluginMigrationRunner;
use Whity\Core\Audit\AuditLogger;
use Whity\Core\Exception\PluginAlreadyInstalled;
use Whity\Core\Exception\PluginEnableMigrationFailed;
use Whity\Core\Exception\PluginExtractionUnsafe;
use Whity\Core\Exception\PluginIncompatible;
use Whity\Core\Exception\PluginInstallException;
use Whity\Core\Exception\PluginNameUnsafe;
use Whity\Core\Exception\PluginPackageInvalid;
use Whity\Core\Exception\PluginPersistenceException;

/**
 * Plugins API Handler
 *
 * Handles plugin discovery, enabling, and disabling at the filesystem level, and
 * - when a PluginLoader is wired in - exposes per-plugin lifecycle state plus a
 * manual re-enable action for plugins that have been auto-disabled after
 * repeated failures.
 */
class PluginsApiHandler
{
    /**
     * Propagation/staleness indicator surfaced on the plugin list (WC-210).
     *
     * Per-plugin lifecycle state is worker-local (held in each FrankenPHP
     * worker's PluginLoader). Admin enable/disable/uninstall now persist to
     * disk, so other workers converge on their next reload or restart; the
     * consecutive-error auto-fail state remains per-worker until the Phase-F
     * shared store. This typed `meta` block tells clients the listing reflects
     * the answering worker only.
     *
     * @var array{worker_local: bool, note: string}
     */
    private const WORKER_LOCAL_META = [
        'worker_local' => true,
        'note' => 'Plugin lifecycle state is per-worker. Admin enable/disable/uninstall '
            . 'persist to disk and converge across workers on reload or worker restart; '
            . 'auto-fail (consecutive-error) state is per-worker until the shared store lands.',
    ];

    private string $pluginDir;

    /**
     * Optional in-memory plugin loader used to read/mutate lifecycle state.
     *
     * Filesystem operations (enable/disable by renaming files) work without it,
     * but lifecycle reporting and re-enable require the live loader instance.
     */
    private ?PluginLoader $pluginLoader;

    /**
     * Optional audit sink for the upload/enable lifecycle records (WC-220).
     *
     * Threaded in so {@see upload()} and {@see enable()} can emit secret-free
     * `plugin.upload` / `plugin.enable` audit rows. Optional so tests that drive
     * the handler without a DB keep working.
     */
    private readonly ?AuditLogger $auditLogger;

    /**
     * @param string $pluginDir Directory containing plugin files.
     * @param PluginLoader|null $pluginLoader Optional live loader for lifecycle state.
     * @param \PDO|null $pdo Optional database connection for migration rollback.
     * @param AuditLogger|null $auditLogger Optional audit sink (WC-220 upload/enable).
     */
    public function __construct(
        string $pluginDir,
        ?PluginLoader $pluginLoader = null,
        private readonly ?\PDO $pdo = null,
        ?AuditLogger $auditLogger = null
    ) {
        $this->pluginDir = $pluginDir;
        $this->pluginLoader = $pluginLoader;
        $this->auditLogger = $auditLogger;
    }

    /**
     * POST /api/plugins/upload - Stage an uploaded plugin package (lands DISABLED).
     *
     * Reads the multipart `package` file part, hands it to {@see PluginInstaller}
     * which validates (zip-slip / zip-bomb / name allowlist / version gate /
     * collision), commits the artifact to disk marked DISABLED, and converges the
     * loader. NO plugin code is executed and NO migrations run here. Typed
     * installer failures map to uniform envelopes; anything unexpected is logged
     * server-side and returned as a generic 500 (no raw exception text leaks).
     *
     * @param Request $request The incoming multipart/form-data request.
     * @param array<string, string> $params Route parameters (unused).
     * @return Response The staged entry (status: disabled) or an error envelope.
     */
    public function upload(Request $request, array $params = []): Response
    {
        try {
            $files = $request->getUploadedFiles();
        } catch (\Throwable $e) {
            // A malformed multipart body / cap violation from the parser.
            error_log('[PluginsApiHandler] upload parse failed: ' . $e->getMessage());
            return Response::error('The uploaded package could not be read.', 400);
        }

        $package = $files['package'] ?? null;
        if ($package === null) {
            return Response::error('A plugin package file (field "package") is required.', 400);
        }

        $installer = new PluginInstaller($this->pluginDir, $this->pluginLoader, $this->auditLogger);

        try {
            $entry = $installer->installFromUpload($package);
        } catch (PluginPackageInvalid | PluginNameUnsafe | PluginExtractionUnsafe $e) {
            return $this->installError($e, 400);
        } catch (PluginIncompatible $e) {
            return $this->installError($e, 422);
        } catch (PluginAlreadyInstalled $e) {
            return $this->installError($e, 409);
        } catch (\Throwable $e) {
            error_log('[PluginsApiHandler] upload failed: ' . $e->getMessage());
            return Response::error('Failed to install plugin', 500);
        }

        return Response::json(['data' => $entry], 200);
    }

    /**
     * Build the uniform error envelope for a typed installer failure.
     *
     * Surfaces only the exception's SAFE client message + details — never a
     * stack trace or internal path (WC-186 / WC-216).
     *
     * @param PluginInstallException $e The typed installer failure.
     * @param int $status The HTTP status to return.
     * @return Response The error envelope.
     */
    private function installError(PluginInstallException $e, int $status): Response
    {
        return Response::error($e->clientMessage(), $status, $e->clientDetails());
    }

    /**
     * Response for a plugin lifecycle change that applied to THIS worker but could
     * not be persisted for other workers (WC-210 convergence). The raw filesystem
     * reason is logged server-side; the client gets a safe, actionable message.
     */
    private function persistenceFailed(string $verb, PluginPersistenceException $e): Response
    {
        error_log('[PluginsApiHandler] plugin ' . $verb . ' could not be persisted: ' . $e->getMessage());

        return Response::error(
            "The plugin was {$verb} on this node, but the change could not be persisted for other workers "
            . '(check the plugins directory is writable), so it may not take effect fleet-wide. Please retry.',
            500
        );
    }

    /**
     * GET /api/plugins - List all plugins
     *
     * Each entry carries the AC #1 contract fields: name, version, status (from
     * the WC-9 lifecycle), routes_count, and permissions_count. The filesystem
     * id/enabled/file fields and the lifecycle error details are also included
     * for backward compatibility. When a loader is available, loaded plugins are
     * enriched with their live metadata and lifecycle state; without a loader the
     * handler falls back to a plain filesystem listing.
     *
     * @param Request $request The incoming request.
     * @return Response
     */
    public function list(Request $request): Response
    {
        try {
            $lifecycleByName = $this->lifecycleStatusByName();
            $metadataByName = $this->metadataByName();

            // Deduplicate by plugin id: a single plugin can transiently have BOTH
            // an enabled `Foo.php` and a stale `Foo.php.disabled` on disk (an
            // interrupted enable/disable, or a leftover), and each would otherwise
            // produce a separate entry with the SAME id — a duplicate the admin UI
            // keys on, breaking rendering. The enabled file is authoritative
            // (presence of `Foo.php` == enabled), so it wins over `.disabled`.
            $byId = [];
            $files = scandir($this->pluginDir);

            if ($files === false) {
                $files = [];
            }

            foreach ($files as $file) {
                // Skip ALL dot-prefixed entries (`.`, `..`, `.gitkeep`, and the
                // installer's atomic-commit temp sibling `.<Name>.tmp_<rand>`):
                // dotfiles/dot-dirs are never plugins and must not be listed
                // (WC-220 minor; subsumes the prior `.gitkeep` special-case).
                if (str_starts_with($file, '.')) {
                    continue;
                }

                $path = $this->pluginDir . '/' . $file;
                $extension = pathinfo($file, PATHINFO_EXTENSION);

                if (is_dir($path) || $extension === 'php' || (strpos($file, '.php.disabled') !== false)) {
                    $id = str_replace(['.php.disabled', '.php'], '', $file);
                    $enabled = !is_dir($path) ? $extension === 'php' : true;

                    $entry = [
                        'id' => $id,
                        'name' => $id,
                        'enabled' => $enabled,
                        'file' => $file,
                    ];

                    $entry += $this->matchLifecycle($id, $lifecycleByName);
                    $entry += $this->matchMetadata($id, $metadataByName);
                    $entry += $this->defaultMetadata($enabled);

                    // First entry for this id wins, EXCEPT an enabled file upgrades
                    // a previously-seen disabled one — so a plugin is listed once.
                    $existing = $byId[$id] ?? null;
                    if ($existing === null || ($enabled && $existing['enabled'] !== true)) {
                        $byId[$id] = $entry;
                    }
                }
            }

            $plugins = array_values($byId);

            // Surface any loaded plugins that have no on-disk file entry matched
            // above (e.g. nested plugins whose folder name differs from the id).
            foreach ($this->loaderMetadata() as $meta) {
                if (!$this->metadataAlreadyListed($meta, $plugins)) {
                    $plugins[] = [
                        'id' => $meta['id'],
                        'name' => $meta['name'],
                        'enabled' => $meta['status'] !== 'disabled',
                        'file' => null,
                        'version' => $meta['version'],
                        'status' => $meta['status'],
                        'routes_count' => $meta['routes_count'],
                        'permissions_count' => $meta['permissions_count'],
                    ];
                }
            }

            return Response::json([
                'data' => $plugins,
                'meta' => self::WORKER_LOCAL_META,
            ], 200);
        } catch (\Throwable $e) {
            error_log('[PluginsApiHandler] list failed: ' . $e->getMessage());
            return Response::error('Failed to list plugins', 500);
        }
    }

    /**
     * POST /api/plugins/{name}/enable - Enable (or re-enable) a plugin
     *
     * When a loader is wired and the identifier resolves to a loaded plugin, the
     * plugin is re-enabled at runtime (WC-9 re-enable path): its lifecycle
     * returns to active and any routes/hooks torn down by a prior disable are
     * re-registered. Otherwise the handler falls back to the filesystem rename
     * that enables a `.php.disabled` plugin file on disk.
     *
     * @param Request $request The incoming request.
     * @param array<string, string> $params Route parameters (accepts 'name' or 'id').
     * @return Response
     */
    public function enable(Request $request, array $params): Response
    {
        $identifier = $this->identifier($params);
        if ($identifier === null) {
            return Response::error('Plugin identifier required', 400);
        }

        // Prefer the live loader: re-enable a runtime-disabled/failed plugin.
        $loader = $this->pluginLoader;
        if ($loader !== null) {
            $key = $this->resolvePluginKey($identifier);
            if ($key !== null) {
                // WC-220: BEFORE the plugin serves traffic, apply its declared
                // migrations that are not yet recorded. The plugin is still
                // disabled here, so on a migration FAILURE we leave it disabled
                // (sentinel intact) and surface a typed 422 — never activating a
                // plugin whose schema could not be applied. A second enable is a
                // migration no-op (already-recorded migrations are skipped).
                try {
                    $this->applyMigrationsOnEnable($key);
                } catch (PluginEnableMigrationFailed $e) {
                    return $this->installError($e, 422);
                }

                try {
                    $loader->reEnablePlugin($key);
                } catch (PluginPersistenceException $e) {
                    return $this->persistenceFailed('enabled', $e);
                }
                $lifecycle = $loader->getLifecycle($key);

                $this->auditLogger?->record('plugin.enable', [
                    'target_type' => 'plugin',
                    'metadata' => ['plugin' => $identifier, 'result' => 'enabled'],
                ]);

                return Response::json([
                    'data' => [
                        'message' => "Plugin {$identifier} enabled",
                        'state' => $lifecycle?->getState()->value,
                    ],
                ], 200);
            }
        }

        return $this->enableOnDisk($identifier);
    }

    /**
     * Apply a (currently-disabled) plugin's not-yet-recorded migrations before
     * it is activated (WC-220).
     *
     * Reads the retained plugin instance from the loader (kept across a disable),
     * runs its declared migrations through {@see PluginMigrationRunner} — each in
     * its own transaction, tracked `plugin:<Name>:<Class>`, idempotent — and on
     * ANY failure leaves the plugin disabled, records a
     * `plugin.enable.migrate_failed` audit entry, and throws a typed error. When
     * no PDO is wired, migration-on-enable is a no-op (the lifecycle-only flow
     * used by tests without a database is preserved).
     *
     * @param string $key The plugin's stable identity (original FQCN).
     * @return void
     * @throws PluginEnableMigrationFailed When a migration fails to apply.
     */
    private function applyMigrationsOnEnable(string $key): void
    {
        if ($this->pdo === null || $this->pluginLoader === null) {
            return;
        }

        $plugin = $this->pluginLoader->getPluginInstance($key);
        if ($plugin === null) {
            return;
        }

        try {
            (new PluginMigrationRunner($this->pdo))->applyForPlugin($plugin);
        } catch (\Throwable $e) {
            // The plugin stays DISABLED (we never reached reEnablePlugin). Log
            // the raw cause server-side; surface only a safe typed error.
            error_log('[PluginsApiHandler] enable migration failed for ' . $key . ': ' . $e->getMessage());

            $this->auditLogger?->record('plugin.enable.migrate_failed', [
                'target_type' => 'plugin',
                'metadata' => ['plugin' => $plugin->getName(), 'result' => 'migrate_failed'],
            ]);

            throw new PluginEnableMigrationFailed(
                'The plugin could not be enabled because its database migration failed.',
                ['plugin' => $plugin->getName()],
                $e
            );
        }
    }

    /**
     * POST /api/plugins/{name}/disable - Disable a plugin
     *
     * When a loader is wired and the identifier resolves to a loaded plugin, the
     * plugin is disabled at runtime: its routes are unregistered (WC-8's
     * {@see \Whity\Core\Router::unregisterByNamespace()}), its hooks are removed,
     * and its lifecycle transitions to 'disabled'. Otherwise the handler falls
     * back to renaming the plugin file on disk to `.php.disabled`.
     *
     * @param Request $request The incoming request.
     * @param array<string, string> $params Route parameters (accepts 'name' or 'id').
     * @return Response
     */
    public function disable(Request $request, array $params): Response
    {
        $identifier = $this->identifier($params);
        if ($identifier === null) {
            return Response::error('Plugin identifier required', 400);
        }

        // Prefer the live loader: tear down the plugin's runtime capabilities.
        if ($this->pluginLoader !== null) {
            $key = $this->resolvePluginKey($identifier);
            if ($key !== null) {
                try {
                    if (!$this->pluginLoader->disablePlugin($key)) {
                        return Response::error('Plugin not found', 404);
                    }
                } catch (PluginPersistenceException $e) {
                    return $this->persistenceFailed('disabled', $e);
                }

                $lifecycle = $this->pluginLoader->getLifecycle($key);

                return Response::json([
                    'data' => [
                        'message' => "Plugin {$identifier} disabled",
                        'state' => $lifecycle?->getState()->value,
                    ],
                ], 200);
            }

            // A loader is present but the plugin is not loaded in-memory; only
            // fall through to the filesystem path when a matching file exists.
            if (!$this->hasPluginFile($identifier)) {
                return Response::error('Plugin not found', 404);
            }
        }

        return $this->disableOnDisk($identifier);
    }

    /**
     * POST /api/plugins/{id}/re-enable - Clear the failed state of a plugin
     *
     * Returns an auto-disabled (failed) or administratively disabled plugin to
     * the active state with a clean error counter, so it may serve requests
     * again. Identity is the plugin's runtime key (its original FQCN).
     *
     * @param Request $request The incoming request.
     * @param array<string, string> $params Route parameters (expects 'id').
     * @return Response
     */
    public function reEnable(Request $request, array $params): Response
    {
        $id = $params['id'] ?? null;
        if ($id === null || $id === '') {
            return Response::error('Plugin ID required', 400);
        }

        if ($this->pluginLoader === null) {
            return Response::error('Plugin lifecycle management is unavailable', 503);
        }

        try {
            if (!$this->pluginLoader->reEnablePlugin($id)) {
                return Response::error('Plugin not found', 404);
            }
        } catch (PluginPersistenceException $e) {
            return $this->persistenceFailed('re-enabled', $e);
        }

        $lifecycle = $this->pluginLoader->getLifecycle($id);

        return Response::json([
            'data' => [
                'message' => "Plugin {$id} re-enabled",
                'state' => $lifecycle?->getState()->value,
            ],
        ], 200);
    }

    /**
     * POST /api/plugins/reload - Explicitly rescan the plugins directory.
     *
     * This RBAC-protected admin action is the sanctioned way to pick up
     * added/removed plugin files outside development, where the per-request
     * hot-reload loop is disabled (WC-160). In development the worker loop
     * already reloads each request, so this is usually a no-op there.
     *
     * A MODIFIED already-loaded plugin cannot be redefined in a live worker
     * (WC-212), so the new code is served only after a worker restart — the
     * response surfaces this via `worker_restart_required`, consistent with
     * WC-210's documented worker-restart contract.
     *
     * @param Request $request The incoming request.
     * @return Response
     */
    public function reload(Request $request): Response
    {
        if ($this->pluginLoader === null) {
            return Response::json(['data' => ['message' => 'Plugin loader unavailable; no reload performed']], 200);
        }

        $changed = $this->pluginLoader->reload();

        // A modified already-loaded plugin cannot be hot-swapped in-process; the
        // loader requests a worker recycle instead. Surface that so the operator
        // knows the new code lands only after a worker restart (WC-212). Use the
        // non-clearing peek: the worker loop owns the single authoritative
        // consume, so REPORTING here must not clear the flag and defeat it.
        $workerRestartRequired = $this->pluginLoader->isWorkerRecyclePending();

        return Response::json([
            'data' => [
                'message' => $changed ? 'Plugins reloaded' : 'No plugin changes detected',
                'changed' => $changed,
                'worker_restart_required' => $workerRestartRequired,
            ],
        ], 200);
    }

    /**
     * POST /api/plugins/{id}/uninstall - Uninstall (disable + rollback + remove) a plugin.
     *
     * Accepts an optional JSON body: `{ "dry_run": true, "force": false }`.
     * When dry_run is true the method returns the plan without mutating anything.
     * When force is true the directory is removed even if migration rollback had
     * errors.
     *
     * Requires the PluginLoader and a PDO connection to be wired; returns 503
     * when either is absent. Returns 409 when migration rollback fails and force
     * is false.
     *
     * @param Request $request The incoming request.
     * @param array<string, string> $params Route parameters (expects 'id').
     * @return Response
     */
    public function uninstall(Request $request, array $params): Response
    {
        $identifier = $this->identifier($params);
        if ($identifier === null) {
            return Response::error('Plugin identifier required', 400);
        }

        // Parse body options (all optional).
        $body = [];
        $rawBody = $request->getBody();
        if ($rawBody !== '' && $rawBody !== null) {
            $decoded = json_decode($rawBody, true);
            if (is_array($decoded)) {
                $body = $decoded;
            }
        }

        $dryRun = (bool) ($body['dry_run'] ?? false);
        $force = (bool) ($body['force'] ?? false);

        if ($this->pdo === null) {
            return Response::error('Database connection unavailable for migration rollback', 503);
        }

        // Resolve the plugin's stable FQCN key if a loader is wired.
        $key = null;
        if ($this->pluginLoader !== null) {
            $key = $this->resolvePluginKey($identifier);
        }

        if ($key === null) {
            // Filesystem fallback (plugin on disk but not loaded / no loader).
            // Validate the identifier and resolve EXACTLY ONE concrete target
            // (file OR dir) once, so the reachability gate and the removal
            // target can never diverge. safePluginPath() rejects any identifier
            // that could escape the plugin directory and realpath-anchors the
            // candidate under it.
            $target = $this->safePluginPath($identifier, '.php')          // enabled file
                ?? $this->safePluginPath($identifier, '.php.disabled')    // disabled file
                ?? $this->safePluginPath($identifier);                    // directory plugin

            if ($target === null) {
                // Distinguish a malformed/unsafe identifier (400) from a simply
                // absent one (404): if the identifier itself is invalid, none of
                // the suffix variants could ever resolve, so re-check the bare id.
                if (
                    $identifier === ''
                    || str_contains($identifier, '/')
                    || str_contains($identifier, '\\')
                    || str_contains($identifier, '.')
                ) {
                    return Response::error('Invalid plugin identifier', 400);
                }
                return Response::error('Plugin not found', 404);
            }

            // Plugin is on disk but not in the loader — perform a filesystem-only
            // uninstall: rollback orphaned tracking rows and delete the target.
            $rollbackSvc = new \Whity\Core\PluginMigrationRollback($this->pdo);

            if ($dryRun) {
                $rollbackNames = $rollbackSvc->listMigrationsForPlugin($identifier);

                return Response::json([
                    'data' => [
                        'plugin' => $identifier,
                        'status' => 'unloaded',
                        'migrations_to_roll_back' => $rollbackNames,
                        'directory' => $target,
                        'will_remove_directory' => true,
                    ],
                ], 200);
            }

            $rollbackResult = $rollbackSvc->rollback($identifier);
            $errors = $rollbackResult['errors'];

            if ($errors !== [] && !$force) {
                return Response::json([
                    'data' => [
                        'plugin' => $identifier,
                        'disabled' => false,
                        'migrations_rolled_back' => $rollbackResult['rolled_back'],
                        'directory_removed' => false,
                        'errors' => $errors,
                    ],
                ], 409);
            }

            // Remove exactly the target the gate matched.
            $this->removePath($target);

            return Response::json([
                'data' => [
                    'plugin' => $identifier,
                    'disabled' => false,
                    'migrations_rolled_back' => $rollbackResult['rolled_back'],
                    'directory_removed' => true,
                    'errors' => $errors,
                ],
            ], 200);
        }

        // Plugin is loaded — use PluginLoader's orchestrated uninstall.
        // $key !== null here means $this->pluginLoader !== null (resolvePluginKey requires it).
        if ($this->pluginLoader === null) {
            return Response::error('Plugin loader unavailable', 503);
        }

        if ($dryRun) {
            $plan = $this->pluginLoader->planUninstall($key);
            if ($plan === null) {
                return Response::error('Plugin not found', 404);
            }

            return Response::json(['data' => $plan], 200);
        }

        try {
            $result = $this->pluginLoader->uninstallPlugin($key, $this->pdo, $force);
        } catch (\Throwable $e) {
            error_log('[PluginsApiHandler] uninstall failed: ' . $e->getMessage());
            return Response::error('Uninstall failed', 500);
        }

        if ($result['errors'] !== [] && !$force) {
            return Response::json(['data' => $result], 409);
        }

        return Response::json(['data' => $result], 200);
    }

    /**
     * Remove a path recursively (file or directory).
     *
     * @param string $path Absolute path to remove.
     */
    private function removePath(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            @unlink($path);
            return;
        }

        if (!is_dir($path)) {
            return;
        }

        $entries = scandir($path);
        if ($entries === false) {
            return;
        }
        $files = array_diff($entries, ['.', '..']);
        foreach ($files as $file) {
            $this->removePath($path . '/' . (string) $file);
        }

        @rmdir($path);
    }

    /**
     * Get lifecycle status snapshots from the loader, if available.
     *
     * @return array<int, array{id: string, name: string, state: string, consecutive_errors: int, last_error: array{message: string, type: string, trace: string, at: int}|null}>
     */
    private function loaderStatuses(): array
    {
        if ($this->pluginLoader === null) {
            return [];
        }

        return $this->pluginLoader->getPluginStatuses();
    }

    /**
     * Index loader statuses by plugin name for matching against on-disk files.
     *
     * @return array<string, array{id: string, name: string, state: string, consecutive_errors: int, last_error: array{message: string, type: string, trace: string, at: int}|null}>
     */
    private function lifecycleStatusByName(): array
    {
        $byName = [];
        foreach ($this->loaderStatuses() as $status) {
            // A quarantined duplicate must not mask the healthy plugin that
            // actually serves under this name (WC-165 review): the ACTIVE
            // lifecycle wins the by-name slot.
            if (isset($byName[$status['name']]) && $byName[$status['name']]['state'] === 'active') {
                continue;
            }
            $byName[$status['name']] = $status;
        }

        // Fallback index: quarantined plugins whose declared name differs from
        // their folder name would otherwise be invisible in the listing — key
        // them additionally by the namespace root of their FQCN id (which IS
        // the folder name for directory plugins).
        foreach ($this->loaderStatuses() as $status) {
            $namespaceRoot = explode('\\', $status['id'])[0];
            if ($namespaceRoot !== '' && !isset($byName[$namespaceRoot])) {
                $byName[$namespaceRoot] = $status;
            }
        }

        return $byName;
    }

    /**
     * Resolve the lifecycle fields for a filesystem-listed plugin id.
     *
     * @param string $id The filesystem-derived plugin id.
     * @param array<string, array{id: string, name: string, state: string, consecutive_errors: int, last_error: array{message: string, type: string, trace: string, at: int}|null}> $byName
     * @return array{state?: string, consecutive_errors?: int, last_error?: array{message: string, type: string, trace: string, at: int}|null, lifecycle_id?: string}
     */
    private function matchLifecycle(string $id, array $byName): array
    {
        $status = $byName[$id] ?? null;
        if ($status === null) {
            return [];
        }

        return [
            'state' => $status['state'],
            'consecutive_errors' => $status['consecutive_errors'],
            'last_error' => $status['last_error'],
            'lifecycle_id' => $status['id'],
        ];
    }

    /**
     * Get admin-facing plugin metadata from the loader, if available.
     *
     * @return array<int, array{id: string, name: string, version: string, status: string, routes_count: int, permissions_count: int}>
     */
    private function loaderMetadata(): array
    {
        if ($this->pluginLoader === null) {
            return [];
        }

        return $this->pluginLoader->getPluginMetadata();
    }

    /**
     * Index loader metadata by plugin name for matching against on-disk files.
     *
     * @return array<string, array{id: string, name: string, version: string, status: string, routes_count: int, permissions_count: int}>
     */
    private function metadataByName(): array
    {
        $byName = [];
        foreach ($this->loaderMetadata() as $meta) {
            $byName[$meta['name']] = $meta;
        }

        return $byName;
    }

    /**
     * Resolve the AC #1 metadata fields for a filesystem-listed plugin id.
     *
     * @param string $id The filesystem-derived plugin id.
     * @param array<string, array{id: string, name: string, version: string, status: string, routes_count: int, permissions_count: int}> $byName
     * @return array{version?: string, status?: string, routes_count?: int, permissions_count?: int}
     */
    private function matchMetadata(string $id, array $byName): array
    {
        $meta = $byName[$id] ?? null;
        if ($meta === null) {
            return [];
        }

        return [
            'version' => $meta['version'],
            'status' => $meta['status'],
            'routes_count' => $meta['routes_count'],
            'permissions_count' => $meta['permissions_count'],
        ];
    }

    /**
     * Default metadata for plugins with no live loader information.
     *
     * The `+=` merge in {@see list()} means these only fill gaps left by
     * {@see matchMetadata()}/{@see matchLifecycle()}, so a loaded plugin's real
     * values always win. Status defaults to the on-disk enabled/disabled flag.
     *
     * @param bool $enabled Whether the plugin file is enabled on disk.
     * @return array{version: string|null, status: string, routes_count: int|null, permissions_count: int|null}
     */
    private function defaultMetadata(bool $enabled): array
    {
        return [
            'version' => null,
            'status' => $enabled ? 'enabled' : 'disabled',
            'routes_count' => null,
            'permissions_count' => null,
        ];
    }

    /**
     * Determine whether loader metadata was already represented in the listing.
     *
     * @param array{id: string, name: string, version: string, status: string, routes_count: int, permissions_count: int} $meta
     * @param array<int, array<string, mixed>> $plugins
     * @return bool
     */
    private function metadataAlreadyListed(array $meta, array $plugins): bool
    {
        foreach ($plugins as $plugin) {
            if (($plugin['name'] ?? null) === $meta['name']) {
                return true;
            }
            if (($plugin['name'] ?? null) === $meta['id']) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extract the plugin identifier from route params, accepting 'name' or 'id'.
     *
     * @param array<string, string> $params Route parameters.
     * @return string|null The identifier, or null when absent/empty.
     */
    private function identifier(array $params): ?string
    {
        $identifier = $params['name'] ?? $params['id'] ?? null;

        return ($identifier === null || $identifier === '') ? null : $identifier;
    }

    /**
     * Resolve a human name or FQCN identifier to a loaded plugin's stable key.
     *
     * Matches first by the plugin's runtime key (original FQCN), then by its
     * human-readable name, so admin routes may use either.
     *
     * @param string $identifier The name or FQCN supplied by the caller.
     * @return string|null The plugin's stable key, or null if it is not loaded.
     */
    private function resolvePluginKey(string $identifier): ?string
    {
        foreach ($this->loaderMetadata() as $meta) {
            if ($meta['id'] === $identifier || $meta['name'] === $identifier) {
                return $meta['id'];
            }
        }

        return null;
    }

    /**
     * Whether an enabled or disabled plugin file exists on disk for the id.
     *
     * @param string $id The filesystem-derived plugin id.
     * @return bool
     */
    private function hasPluginFile(string $id): bool
    {
        return $this->safePluginPath($id, '.php') !== null
            || $this->safePluginPath($id, '.php.disabled') !== null;
    }

    /**
     * Resolve a deletion/lookup candidate path for a plugin identifier, refusing
     * any identifier that could escape the plugin directory.
     *
     * A plugin identifier is a bare class-name segment: it must not be empty,
     * contain a path separator (`/` or `\`), or contain a `.` (which would allow
     * `.`/`..` relative components or dotted traversal). After building the
     * candidate path it is realpath-anchored under the plugin directory so that
     * even symlink/normalisation tricks cannot point outside it. Mirrors the
     * proven guard in PluginLoader::resolvePluginDirectory.
     *
     * @param string $identifier The raw route identifier.
     * @param string $suffix Optional suffix to append (e.g. '.php' or '').
     * @return string|null The validated absolute candidate path, or null when the
     *                     identifier is unsafe OR no such path exists on disk.
     */
    private function safePluginPath(string $identifier, string $suffix = ''): ?string
    {
        // Reject empty, separators, and any dot (no relative components, no
        // dotted traversal). A plugin id is a single bare name segment.
        if (
            $identifier === ''
            || str_contains($identifier, '/')
            || str_contains($identifier, '\\')
            || str_contains($identifier, '.')
        ) {
            return null;
        }

        $candidate = $this->pluginDir . '/' . $identifier . $suffix;

        $realPluginDir = realpath($this->pluginDir);
        if ($realPluginDir === false) {
            // Plugin dir does not exist (tests / fresh installs): the dot/separator
            // rejection above already prevents traversal, so fall back to a plain
            // existence check against the constructed candidate.
            return file_exists($candidate) ? $candidate : null;
        }

        $realCandidate = realpath($candidate);
        if ($realCandidate === false) {
            return null;
        }

        // Anchor with a trailing separator so "/var/plugins_evil" cannot pass a
        // prefix check against "/var/plugins".
        $anchor = rtrim($realPluginDir, '/\\') . DIRECTORY_SEPARATOR;
        if (!str_starts_with($realCandidate . DIRECTORY_SEPARATOR, $anchor)) {
            return null;
        }

        return $candidate;
    }

    /**
     * Enable a plugin by renaming its `.php.disabled` file back to `.php`.
     *
     * @param string $id The filesystem-derived plugin id.
     * @return Response
     */
    private function enableOnDisk(string $id): Response
    {
        $disabledPath = $this->pluginDir . '/' . $id . '.php.disabled';
        $enabledPath = $this->pluginDir . '/' . $id . '.php';

        if (file_exists($enabledPath)) {
            return Response::json(['data' => ['message' => 'Plugin already enabled']], 200);
        }

        if (!file_exists($disabledPath)) {
            return Response::error('Plugin not found', 404);
        }

        if (rename($disabledPath, $enabledPath)) {
            return Response::json(['data' => ['message' => "Plugin {$id} enabled"]], 200);
        }

        return Response::error('Failed to enable plugin', 500);
    }

    /**
     * Disable a plugin by renaming its `.php` file to `.php.disabled`.
     *
     * @param string $id The filesystem-derived plugin id.
     * @return Response
     */
    private function disableOnDisk(string $id): Response
    {
        $enabledPath = $this->pluginDir . '/' . $id . '.php';
        $disabledPath = $this->pluginDir . '/' . $id . '.php.disabled';

        if (file_exists($disabledPath)) {
            return Response::json(['data' => ['message' => 'Plugin already disabled']], 200);
        }

        if (!file_exists($enabledPath)) {
            return Response::error('Plugin not found', 404);
        }

        if (rename($enabledPath, $disabledPath)) {
            return Response::json(['data' => ['message' => "Plugin {$id} disabled"]], 200);
        }

        return Response::error('Failed to disable plugin', 500);
    }
}
