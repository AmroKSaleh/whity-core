<?php

declare(strict_types=1);

namespace Whity\Core\DesktopPlugins;

use PDO;
use Throwable;
use Whity\Api\DesktopPluginsApiHandler;

/**
 * Turns a plugin's source directory into a catalogued desktop-plugin release:
 * obfuscate → package → store → insert the `desktop_plugin_releases` row that
 * {@see DesktopPluginsApiHandler} then serves.
 *
 * STORAGE LAYOUT. The handler resolves a package at
 * `$storageDir . '/' . storage_path`, so this service writes each package to
 * `{name}/{version}/package.zip` (relative) under the SAME `$storageDir` the
 * handler is constructed with (`storage/desktop-plugins` in public/index.php)
 * and stores that relative path in the row.
 *
 * INTEGRITY. The SHA-256 and size come from {@see DesktopPluginPackager}, which
 * computes them from the final zip; this service moves that exact file into
 * place and re-verifies the hash before writing the row, so the catalog checksum
 * can never drift from the served bytes. The package file is placed on disk
 * BEFORE the row is inserted, so a catalogued release always has its bytes; on
 * an insert failure the just-placed file is removed again.
 *
 * IMMUTABILITY. `(plugin_name, version)` is unique; re-releasing an existing
 * version is refused unless the caller explicitly forces it (a deliberate re-cut
 * of a botched build). A forced re-cut UPDATES the row in place (ON CONFLICT DO
 * UPDATE) rather than delete-and-reinsert, so the row's id and created_at
 * survive — nothing downstream keyed off a release id is invalidated by a re-cut.
 *
 * ENTITLEMENT (v1). This inserts into a GLOBAL catalog with no tenant/plan
 * scoping — matching the migration, the handler and the shipped desktop client:
 * every authenticated device gated by `desktop-plugins:read` sees every release.
 * Per-tenant entitlement is a deferred follow-up; adding it means a scoping
 * column here AND a matching filter in {@see DesktopPluginsApiHandler::catalog}.
 */
final class DesktopPluginReleaseService
{
    private readonly DesktopPluginPackager $packager;

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $storageDir,
        ?DesktopPluginPackager $packager = null,
    ) {
        $this->packager = $packager ?? new DesktopPluginPackager();
    }

    /**
     * Build, store and catalogue one release.
     *
     * @param bool $force Replace an existing (name, version) release instead of
     *                    refusing. Use only to re-cut a botched build.
     *
     * @throws DesktopPluginReleaseException on validation/guard failure, or a
     *         duplicate (name, version) without $force.
     * @throws ObfuscationException if a source file cannot be obfuscated safely.
     */
    public function release(string $sourceDir, string $name, string $version, bool $force = false): ReleaseResult
    {
        if (preg_match(DesktopPluginsApiHandler::NAME_PATTERN, $name) !== 1) {
            throw new DesktopPluginReleaseException(
                "Invalid plugin name '{$name}': must match " . DesktopPluginsApiHandler::NAME_PATTERN
            );
        }
        if (preg_match(DesktopPluginsApiHandler::VERSION_PATTERN, $version) !== 1) {
            throw new DesktopPluginReleaseException(
                "Invalid version '{$version}': must match " . DesktopPluginsApiHandler::VERSION_PATTERN
            );
        }

        $exists = $this->releaseExists($name, $version);
        if ($exists && !$force) {
            throw new DesktopPluginReleaseException(
                "Release {$name}@{$version} already exists. A release is immutable; "
                . 'use force to re-cut it.'
            );
        }

        $storagePath = $name . '/' . $version . '/package.zip';
        $absolutePath = $this->storageDir . '/' . $storagePath;

        // Build to a private staging file first, so a failed build never leaves
        // a half-written package at the served path. Clean the staged zip up on
        // ANY failure — including one thrown by package() itself after it has
        // already written the zip (e.g. an oversized-package guard).
        $stagingZip = $this->stagingPath($name, $version);
        try {
            $result = $this->packager->package($sourceDir, $name, $stagingZip);
            $this->placePackage($stagingZip, $absolutePath, $result->sha256);
        } catch (Throwable $e) {
            @unlink($stagingZip);
            throw $e;
        }

        try {
            $this->writeRow($name, $version, $result->sha256, $result->sizeBytes, $storagePath, $force);
        } catch (Throwable $e) {
            // Never leave a package on disk that no row points at.
            @unlink($absolutePath);
            if ($e instanceof DesktopPluginReleaseException) {
                throw $e;
            }
            throw new DesktopPluginReleaseException(
                "Failed to catalogue {$name}@{$version}: " . $e->getMessage(),
                0,
                $e
            );
        }

        return new ReleaseResult(
            name: $name,
            version: $version,
            sha256: $result->sha256,
            sizeBytes: $result->sizeBytes,
            storagePath: $storagePath,
            absolutePath: $absolutePath,
            entryCount: $result->entryCount,
            replacedExisting: $exists,
        );
    }

    private function releaseExists(string $name, string $version): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM desktop_plugin_releases WHERE plugin_name = :name AND version = :version'
        );
        $stmt->execute([':name' => $name, ':version' => $version]);

        return $stmt->fetchColumn() !== false;
    }

    /** Move the staged zip to its served path and confirm the bytes survived. */
    private function placePackage(string $stagingZip, string $absolutePath, string $expectedSha256): void
    {
        $dir = dirname($absolutePath);
        if (!is_dir($dir) && !mkdir($dir, 0o755, true) && !is_dir($dir)) {
            throw new DesktopPluginReleaseException("Could not create storage directory: {$dir}");
        }

        // rename() will not clobber an existing target on Windows.
        if (is_file($absolutePath) && !unlink($absolutePath)) {
            throw new DesktopPluginReleaseException("Could not replace existing package: {$absolutePath}");
        }
        if (!rename($stagingZip, $absolutePath)) {
            throw new DesktopPluginReleaseException("Could not move package into place: {$absolutePath}");
        }

        $actual = hash_file('sha256', $absolutePath);
        if ($actual !== $expectedSha256) {
            @unlink($absolutePath);
            throw new DesktopPluginReleaseException(
                'Package checksum changed while being stored — refusing to catalogue a drifting release.'
            );
        }
    }

    private function writeRow(
        string $name,
        string $version,
        string $sha256,
        int $sizeBytes,
        string $storagePath,
        bool $force
    ): void {
        // A re-cut (--force) UPDATES the existing row in place rather than
        // delete-then-insert, so the release's id and created_at survive — a
        // future feature keyed off the release id (e.g. a download audit trail)
        // must not have its foreign key silently invalidated by a re-cut. The
        // caller has already refused a conflicting release unless $force is set,
        // so ON CONFLICT only ever fires on a genuine re-cut.
        $sql = $force
            ? 'INSERT INTO desktop_plugin_releases (plugin_name, version, sha256, size_bytes, storage_path)
               VALUES (:name, :version, :sha256, :size_bytes, :storage_path)
               ON CONFLICT (plugin_name, version)
               DO UPDATE SET sha256 = EXCLUDED.sha256,
                             size_bytes = EXCLUDED.size_bytes,
                             storage_path = EXCLUDED.storage_path,
                             released_at = NOW()'
            : 'INSERT INTO desktop_plugin_releases (plugin_name, version, sha256, size_bytes, storage_path)
               VALUES (:name, :version, :sha256, :size_bytes, :storage_path)';

        $this->pdo->beginTransaction();
        try {
            $insert = $this->pdo->prepare($sql);
            $insert->execute([
                ':name' => $name,
                ':version' => $version,
                ':sha256' => $sha256,
                ':size_bytes' => $sizeBytes,
                ':storage_path' => $storagePath,
            ]);

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    private function stagingPath(string $name, string $version): string
    {
        $stagingDir = $this->storageDir . '/.staging';
        if (!is_dir($stagingDir) && !mkdir($stagingDir, 0o755, true) && !is_dir($stagingDir)) {
            throw new DesktopPluginReleaseException("Could not create staging directory: {$stagingDir}");
        }

        return $stagingDir . '/' . $name . '-' . $version . '-' . bin2hex(random_bytes(6)) . '.zip';
    }
}
