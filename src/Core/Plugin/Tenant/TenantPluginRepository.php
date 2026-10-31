<?php

declare(strict_types=1);

namespace Whity\Core\Plugin\Tenant;

use PDO;
use Throwable;
use Whity\Core\Db\DbBool;

/**
 * Data access for a workspace's own plugins (Whity Builder, WHIT-760;
 * migration 164).
 *
 * TENANT-OWNED: every statement binds tenant_id, and a version or plugin from
 * another workspace is reported absent. The one read that looks across
 * workspaces, {@see self::permissionsOfOtherTenants()}, returns only slugs, and
 * exists to HIDE them.
 */
final class TenantPluginRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * Store a new immutable version, point the workspace's plugin at it, and
     * record the permission slugs it declares, all in one transaction.
     *
     * @param list<string> $permissions
     * @return int The new version's id.
     */
    public function publish(
        int $tenantId,
        string $pluginKey,
        string $version,
        string $manifestJson,
        string $hash,
        array $permissions,
        ?int $publishedBy = null,
        ?int $actingFor = null,
        ?int $approvedBy = null,
        ?string $notes = null,
    ): int {
        $own = !$this->db->inTransaction();
        if ($own) {
            $this->db->beginTransaction();
        }
        try {
            $insert = $this->db->prepare(
                'INSERT INTO plugin_manifest_versions
                    (tenant_id, plugin_key, version, manifest, hash, notes, published_by, acting_for, approved_by)
                 VALUES (:tenant_id, :plugin_key, :version, :manifest, :hash, :notes, :published_by, :acting_for, :approved_by)'
            );
            $insert->execute([
                ':tenant_id' => $tenantId,
                ':plugin_key' => $pluginKey,
                ':version' => $version,
                ':manifest' => $manifestJson,
                ':hash' => $hash,
                ':notes' => $notes,
                ':published_by' => $publishedBy,
                ':acting_for' => $actingFor,
                ':approved_by' => $approvedBy,
            ]);
            $versionId = (int) $this->db->lastInsertId();

            $this->point($tenantId, $pluginKey, $versionId);

            $this->replacePermissions($tenantId, $pluginKey, $permissions);

            if ($own) {
                $this->db->commit();
            }

            return $versionId;
        } catch (Throwable $e) {
            if ($own && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * The live version of each switched-on plugin in this workspace: one cheap
     * read per request, whose hashes key the parsed-manifest cache.
     *
     * @return list<array{plugin_key: string, version_id: int, hash: string}>
     */
    public function active(int $tenantId): array
    {
        $stmt = $this->db->prepare(
            'SELECT tp.plugin_key, v.id AS version_id, v.hash
               FROM tenant_plugins tp
               JOIN plugin_manifest_versions v ON v.id = tp.active_version_id AND v.tenant_id = tp.tenant_id
              WHERE tp.tenant_id = :tenant_id AND tp.enabled = :enabled
              ORDER BY tp.plugin_key'
        );
        $stmt->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
        $stmt->bindValue(':enabled', true, PDO::PARAM_BOOL);
        $stmt->execute();

        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[] = [
                'plugin_key' => (string) $row['plugin_key'],
                'version_id' => (int) $row['version_id'],
                'hash' => trim((string) $row['hash']),
            ];
        }

        return $rows;
    }

    /**
     * One version's manifest, decoded, or null when it isn't this workspace's.
     *
     * @return array<string, mixed>|null
     */
    public function manifest(int $tenantId, int $versionId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT manifest FROM plugin_manifest_versions WHERE id = :id AND tenant_id = :tenant_id'
        );
        $stmt->execute([':id' => $versionId, ':tenant_id' => $tenantId]);
        $raw = $stmt->fetchColumn();
        if (!is_string($raw)) {
            return null;
        }
        $decoded = json_decode($raw, true);

        /** @var array<string, mixed>|null */
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * The workspace's plugins, switched on or not, with their live version
     * (WHIT-764).
     *
     * @return list<array{plugin: string, version_id: int, version: string, enabled: bool, automations_enabled: bool, updated_at: string}>
     */
    public function plugins(int $tenantId): array
    {
        $stmt = $this->db->prepare(
            'SELECT tp.plugin_key, tp.active_version_id, v.version, tp.enabled, tp.automations_enabled, tp.updated_at
               FROM tenant_plugins tp
               JOIN plugin_manifest_versions v ON v.id = tp.active_version_id AND v.tenant_id = tp.tenant_id
              WHERE tp.tenant_id = :tenant_id
              ORDER BY tp.plugin_key'
        );
        $stmt->execute([':tenant_id' => $tenantId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[] = [
                'plugin' => (string) $row['plugin_key'],
                'version_id' => (int) $row['active_version_id'],
                'version' => (string) $row['version'],
                'enabled' => DbBool::of($row['enabled']),
                'automations_enabled' => DbBool::of($row['automations_enabled']),
                'updated_at' => (string) $row['updated_at'],
            ];
        }

        return $out;
    }

    /** The live version's id of a workspace's plugin, switched on or not. */
    public function liveVersionId(int $tenantId, string $pluginKey): ?int
    {
        $stmt = $this->db->prepare(
            'SELECT active_version_id FROM tenant_plugins WHERE tenant_id = :tenant_id AND plugin_key = :plugin_key'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':plugin_key' => $pluginKey]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    /**
     * Every published version of a workspace's plugin, newest first. The
     * manifests themselves stay out; read one with {@see self::manifest()}.
     *
     * @return list<array{id: int, version: string, hash: string, notes: ?string, published_by: ?int, acting_for: ?int, approved_by: ?int, published_at: string}>
     */
    public function history(int $tenantId, string $pluginKey): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, version, hash, notes, published_by, acting_for, approved_by, published_at
               FROM plugin_manifest_versions
              WHERE tenant_id = :tenant_id AND plugin_key = :plugin_key
              ORDER BY id DESC'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':plugin_key' => $pluginKey]);
        $int = static fn (mixed $v): ?int => $v === null ? null : (int) $v;
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[] = [
                'id' => (int) $row['id'],
                'version' => (string) $row['version'],
                'hash' => trim((string) $row['hash']),
                'notes' => $row['notes'] === null ? null : (string) $row['notes'],
                'published_by' => $int($row['published_by']),
                'acting_for' => $int($row['acting_for']),
                'approved_by' => $int($row['approved_by']),
                'published_at' => (string) $row['published_at'],
            ];
        }

        return $out;
    }

    /**
     * Make a version's permission slugs the plugin's own again, as publish
     * does (a rollback).
     *
     * @param list<string> $permissions
     */
    public function replacePermissions(int $tenantId, string $pluginKey, array $permissions): void
    {
        $delete = $this->db->prepare(
            'DELETE FROM tenant_plugin_permissions WHERE tenant_id = :tenant_id AND plugin_key = :plugin_key'
        );
        $delete->execute([':tenant_id' => $tenantId, ':plugin_key' => $pluginKey]);
        $add = $this->db->prepare(
            'INSERT INTO tenant_plugin_permissions (tenant_id, plugin_key, permission)
             VALUES (:tenant_id, :plugin_key, :permission)'
        );
        foreach (array_unique($permissions) as $permission) {
            $add->execute([':tenant_id' => $tenantId, ':plugin_key' => $pluginKey, ':permission' => $permission]);
        }
    }

    /**
     * Move a workspace's plugin to one of its own versions (publish, and
     * rollback in WHIT-764). Refuses a version from another plugin or workspace.
     */
    public function point(int $tenantId, string $pluginKey, int $versionId): bool
    {
        $check = $this->db->prepare(
            'SELECT 1 FROM plugin_manifest_versions WHERE id = :id AND tenant_id = :tenant_id AND plugin_key = :plugin_key'
        );
        $check->execute([':id' => $versionId, ':tenant_id' => $tenantId, ':plugin_key' => $pluginKey]);
        if ($check->fetchColumn() === false) {
            return false;
        }

        $update = $this->db->prepare(
            'UPDATE tenant_plugins SET active_version_id = :version_id, updated_at = CURRENT_TIMESTAMP
              WHERE tenant_id = :tenant_id AND plugin_key = :plugin_key'
        );
        $update->execute([':version_id' => $versionId, ':tenant_id' => $tenantId, ':plugin_key' => $pluginKey]);
        if ($update->rowCount() > 0) {
            return true;
        }

        $insert = $this->db->prepare(
            'INSERT INTO tenant_plugins (tenant_id, plugin_key, active_version_id, enabled)
             VALUES (:tenant_id, :plugin_key, :version_id, :enabled)'
        );
        $insert->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
        $insert->bindValue(':plugin_key', $pluginKey);
        $insert->bindValue(':version_id', $versionId, PDO::PARAM_INT);
        $insert->bindValue(':enabled', true, PDO::PARAM_BOOL);
        $insert->execute();

        return true;
    }

    /** Switch a workspace's plugin off or on without touching its versions. */
    public function setEnabled(int $tenantId, string $pluginKey, bool $enabled): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE tenant_plugins SET enabled = :enabled, updated_at = CURRENT_TIMESTAMP
              WHERE tenant_id = :tenant_id AND plugin_key = :plugin_key'
        );
        $stmt->bindValue(':enabled', $enabled, PDO::PARAM_BOOL);
        $stmt->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
        $stmt->bindValue(':plugin_key', $pluginKey);
        $stmt->execute();

        return $stmt->rowCount() > 0;
    }

    /**
     * Switch a workspace plugin's automations off or on (WHIT-763). The plugin
     * keeps serving its screens and records either way.
     */
    public function setAutomationsEnabled(int $tenantId, string $pluginKey, bool $enabled): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE tenant_plugins SET automations_enabled = :enabled, updated_at = CURRENT_TIMESTAMP
              WHERE tenant_id = :tenant_id AND plugin_key = :plugin_key'
        );
        $stmt->bindValue(':enabled', $enabled, PDO::PARAM_BOOL);
        $stmt->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
        $stmt->bindValue(':plugin_key', $pluginKey);
        $stmt->execute();

        return $stmt->rowCount() > 0;
    }

    /** Whether a workspace plugin is switched on and its automations are too. */
    public function automationsEnabled(int $tenantId, string $pluginKey): bool
    {
        $stmt = $this->db->prepare(
            'SELECT enabled, automations_enabled FROM tenant_plugins
              WHERE tenant_id = :tenant_id AND plugin_key = :plugin_key'
        );
        $stmt->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
        $stmt->bindValue(':plugin_key', $pluginKey);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) && DbBool::of($row['enabled']) && DbBool::of($row['automations_enabled']);
    }

    /**
     * Permission slugs other workspaces' plugins declare and this workspace's
     * don't. Callers use it only to HIDE those slugs from this workspace's
     * permission catalogue and role grants: a slug like `cake_orders:read`
     * names another workspace's business logic.
     *
     * @return list<string>
     */
    public function permissionsOfOtherTenants(int $tenantId): array
    {
        // @tenant-guard-ignore: returns only other workspaces' permission slugs, so this workspace can be shown fewer, never more
        $stmt = $this->db->prepare(
            'SELECT DISTINCT permission FROM tenant_plugin_permissions
              WHERE tenant_id <> :tenant_id
                AND permission NOT IN (
                    SELECT permission FROM tenant_plugin_permissions WHERE tenant_id = :own_tenant_id
                )'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':own_tenant_id' => $tenantId]);

        /** @var list<string> */
        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
}
