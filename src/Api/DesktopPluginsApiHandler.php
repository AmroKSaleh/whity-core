<?php

declare(strict_types=1);

namespace Whity\Api;

use PDO;
use Whity\Core\Request;
use Whity\Core\Response;

/**
 * Desktop Plugins API Handler (WC-desktop-plugins).
 *
 * Lets an already-enrolled desktop device (authenticated with the SAME bearer
 * access token it uses for every other authenticated call, issued by
 * POST /api/v1/devices/token) list and download its instance's "desktop
 * plugin" releases — obfuscated PHP plugin packages the desktop app installs
 * into its own offline PHP host at runtime. Gated entirely by the
 * `desktop-plugins:read` permission via the standard RBAC route pipeline; no
 * new auth mechanism is introduced here.
 *
 * `desktop_plugin_releases` is a GLOBAL catalog (no tenant scoping) in v1:
 * every authenticated device on this instance sees every release.
 * Per-tenant entitlement is a deferred follow-up.
 *
 * Storage layout: `storage_path` (a DB column) is a path RELATIVE to the
 * `$storageDir` this handler is constructed with (e.g. index.php wires
 * `__DIR__ . '/../storage/desktop-plugins'`), so a release's package lives at
 * `$storageDir . '/' . $row['storage_path']`. Populating the table with real
 * obfuscated builds (the build/release pipeline) is an explicitly separate
 * follow-up — this class only serves what is already catalogued.
 */
class DesktopPluginsApiHandler
{
    /**
     * Plugin-name allowlist for the {name} path parameter.
     *
     * Deliberately the SAME charset as {@see \Whity\Core\PluginInstaller}'s
     * server-side name allowlist and the desktop installer's client-side
     * `validate_name` (`^[A-Za-z0-9_-]+$`), because {name} becomes the plugin's
     * top-level directory on the device — and the device's PSR-4 loader maps
     * that directory name to the namespace root. It is therefore case-sensitive
     * and mixed-case: the shipped plugins (`DemoCatalog`, `HelloWorld`, …) are
     * PascalCase, matching their namespaces. (An earlier lowercase-first regex
     * rejected exactly those names with a 422, so every real release 404/422'd
     * before it could install.) The `{1,128}` bound matches the
     * `plugin_name VARCHAR(128)` column. The release pipeline validates a name
     * against THIS constant before cataloguing it, so a name that cannot be
     * downloaded can never be released.
     */
    public const NAME_PATTERN = '/^[A-Za-z0-9_-]{1,128}$/';

    /**
     * Version allowlist for the {version} path parameter — semver-ish, bounded
     * to the `version VARCHAR(64)` column. The pipeline validates against this
     * too, for the same reason.
     */
    public const VERSION_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9.+_-]{0,63}$/';

    public function __construct(
        private readonly string $storageDir,
        private readonly PDO $pdo
    ) {
    }

    /**
     * GET /api/desktop-plugins - List every catalogued desktop plugin release.
     *
     * Rows are grouped by plugin_name; each group's `latestVersion` is its most
     * recently released version (the query orders released_at DESC within each
     * plugin, so the first row seen per group is the latest).
     *
     * @param Request $request The incoming request.
     * @param array<string, string> $params Route parameters (unused).
     * @return Response
     */
    public function catalog(Request $request, array $params = []): Response
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT plugin_name, version, sha256, size_bytes, released_at
                 FROM desktop_plugin_releases
                 ORDER BY plugin_name, released_at DESC'
            );
            $stmt->execute();
            /** @var array<int, array<string, mixed>> $rows */
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $byPlugin = [];
            foreach ($rows as $row) {
                $name = (string) $row['plugin_name'];
                $version = [
                    'version' => (string) $row['version'],
                    'sha256' => (string) $row['sha256'],
                    'sizeBytes' => (int) $row['size_bytes'],
                    'releasedAt' => (string) $row['released_at'],
                ];

                if (!isset($byPlugin[$name])) {
                    $byPlugin[$name] = [
                        'name' => $name,
                        'latestVersion' => $version['version'],
                        'versions' => [],
                    ];
                }
                $byPlugin[$name]['versions'][] = $version;
            }

            return Response::json(['data' => array_values($byPlugin)], 200);
        } catch (\Throwable $e) {
            error_log('[DesktopPluginsApiHandler] catalog failed: ' . $e->getMessage());
            return Response::error('Failed to list desktop plugin releases', 500);
        }
    }

    /**
     * GET /api/desktop-plugins/{name}/versions/{version}/download - Download a
     * release's package as a raw zip.
     *
     * The 404 for "no such row" is deliberately generic — it does not reveal
     * whether the plugin name exists but the version doesn't, so a caller
     * cannot use it to enumerate entitlement/version existence.
     *
     * @param Request $request The incoming request.
     * @param array<string, string> $params Route parameters ('name', 'version').
     * @return Response
     */
    public function download(Request $request, array $params): Response
    {
        $name = $params['name'] ?? '';
        $version = $params['version'] ?? '';

        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            return Response::error('Invalid plugin name.', 422);
        }
        if (preg_match(self::VERSION_PATTERN, $version) !== 1) {
            return Response::error('Invalid version.', 422);
        }

        try {
            $stmt = $this->pdo->prepare(
                'SELECT plugin_name, version, storage_path
                 FROM desktop_plugin_releases
                 WHERE plugin_name = :name AND version = :version'
            );
            $stmt->execute([':name' => $name, ':version' => $version]);
            /** @var array<string, mixed>|false $row */
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            error_log('[DesktopPluginsApiHandler] download lookup failed: ' . $e->getMessage());
            return Response::error('Failed to fetch desktop plugin release', 500);
        }

        if ($row === false) {
            // Generic message: never confirm/deny whether the name exists but
            // the version doesn't.
            return Response::error('Not found', 404);
        }

        $path = $this->storageDir . '/' . $row['storage_path'];
        $bytes = is_file($path) ? file_get_contents($path) : false;
        if ($bytes === false) {
            error_log(
                '[DesktopPluginsApiHandler] release row present but file missing on disk: '
                . $row['plugin_name'] . '@' . $row['version']
            );
            return Response::error('Failed to fetch desktop plugin release', 500);
        }

        return new Response(200, $bytes, [
            'Content-Type' => 'application/zip',
            'Content-Disposition' => 'attachment; filename="' . $row['plugin_name'] . '-' . $row['version'] . '.zip"',
        ]);
    }
}
