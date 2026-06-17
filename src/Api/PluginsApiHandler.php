<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\PluginLoader;

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
    private string $pluginDir;

    /**
     * Optional in-memory plugin loader used to read/mutate lifecycle state.
     *
     * Filesystem operations (enable/disable by renaming files) work without it,
     * but lifecycle reporting and re-enable require the live loader instance.
     */
    private ?PluginLoader $pluginLoader;

    /**
     * @param string $pluginDir Directory containing plugin files.
     * @param PluginLoader|null $pluginLoader Optional live loader for lifecycle state.
     * @param \PDO|null $pdo Optional database connection for migration rollback.
     */
    public function __construct(
        string $pluginDir,
        ?PluginLoader $pluginLoader = null,
        private readonly ?\PDO $pdo = null
    ) {
        $this->pluginDir = $pluginDir;
        $this->pluginLoader = $pluginLoader;
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

            $plugins = [];
            $files = scandir($this->pluginDir);

            if ($files === false) {
                $files = [];
            }

            foreach ($files as $file) {
                if ($file === '.' || $file === '..' || $file === '.gitkeep') {
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
                    $plugins[] = $entry;
                }
            }

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

            return Response::json(['data' => $plugins], 200);
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
        if ($this->pluginLoader !== null) {
            $key = $this->resolvePluginKey($identifier);
            if ($key !== null) {
                $this->pluginLoader->reEnablePlugin($key);
                $lifecycle = $this->pluginLoader->getLifecycle($key);

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
                if (!$this->pluginLoader->disablePlugin($key)) {
                    return Response::error('Plugin not found', 404);
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

        if (!$this->pluginLoader->reEnablePlugin($id)) {
            return Response::error('Plugin not found', 404);
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
     * @param Request $request The incoming request.
     * @return Response
     */
    public function reload(Request $request): Response
    {
        if ($this->pluginLoader === null) {
            return Response::json(['data' => ['message' => 'Plugin loader unavailable; no reload performed']], 200);
        }

        $changed = $this->pluginLoader->reload();

        return Response::json([
            'data' => [
                'message' => $changed ? 'Plugins reloaded' : 'No plugin changes detected',
                'changed' => $changed,
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
