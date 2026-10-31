<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * Plugins a workspace declares for itself in `plugin.json` (Whity Builder,
 * WHIT-760; docs/design/WHITY-BUILDER.md 2.3).
 *
 * A code plugin is files on disk, loaded for every workspace. A tenant plugin
 * is data: one workspace's own logic, published without a deploy and read per
 * request. Three TENANT-OWNED tables:
 *
 *   plugin_manifest_versions — every published version, immutable. `hash` is
 *                              the SHA-256 of the canonical manifest JSON; a
 *                              worker's parsed-manifest cache is keyed on it.
 *                              Rollback (WHIT-764) moves the pointer below to
 *                              an older row, so rows are never updated.
 *   tenant_plugins           — one row per plugin a workspace has: which
 *                              version is live, and whether it is switched on.
 *   tenant_plugin_permissions— the permission slugs each workspace's plugins
 *                              declare. Slugs live in the global `permissions`
 *                              catalogue (role grants reference it), so this is
 *                              what lets the catalogue and the roles API hide
 *                              one workspace's plugin permissions from another.
 *
 * Registered in CoreTables and TenantOwnedTables; every query binds tenant_id.
 * Idempotent and reversible.
 */
class CreateTenantPluginManifests
{
    public static function up(Database $db): void
    {
        $db->exec("
            CREATE TABLE IF NOT EXISTS plugin_manifest_versions (
                id                 BIGSERIAL     NOT NULL PRIMARY KEY,
                tenant_id          INTEGER       NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                plugin_key         VARCHAR(64)   NOT NULL,
                version            VARCHAR(64)   NOT NULL,
                manifest           JSONB         NOT NULL,
                hash               CHAR(64)      NOT NULL,
                notes              TEXT,
                published_by       BIGINT,
                acting_for         BIGINT,
                approved_by        BIGINT,
                published_at       TIMESTAMP     NOT NULL DEFAULT NOW()
            )
        ");
        $db->exec('CREATE INDEX IF NOT EXISTS idx_plugin_manifest_versions_tenant ON plugin_manifest_versions(tenant_id, plugin_key)');

        $db->exec("
            CREATE TABLE IF NOT EXISTS tenant_plugins (
                id                 BIGSERIAL     NOT NULL PRIMARY KEY,
                tenant_id          INTEGER       NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                plugin_key         VARCHAR(64)   NOT NULL,
                active_version_id  BIGINT        NOT NULL REFERENCES plugin_manifest_versions(id),
                enabled            BOOLEAN       NOT NULL DEFAULT TRUE,
                updated_at         TIMESTAMP     NOT NULL DEFAULT NOW()
            )
        ");
        $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS uq_tenant_plugins_key ON tenant_plugins(tenant_id, plugin_key)');

        $db->exec("
            CREATE TABLE IF NOT EXISTS tenant_plugin_permissions (
                id                 BIGSERIAL     NOT NULL PRIMARY KEY,
                tenant_id          INTEGER       NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                plugin_key         VARCHAR(64)   NOT NULL,
                permission         VARCHAR(128)  NOT NULL
            )
        ");
        $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS uq_tenant_plugin_permissions ON tenant_plugin_permissions(tenant_id, permission)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_tenant_plugin_permissions_permission ON tenant_plugin_permissions(permission)');
    }

    public static function down(Database $db): void
    {
        $db->exec('DROP TABLE IF EXISTS tenant_plugin_permissions CASCADE');
        $db->exec('DROP TABLE IF EXISTS tenant_plugins CASCADE');
        $db->exec('DROP TABLE IF EXISTS plugin_manifest_versions CASCADE');
    }
}
