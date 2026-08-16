<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * Desktop plugin release catalog (WC-desktop-plugins).
 *
 * A single GLOBAL table (no tenant_id — every authenticated device on this
 * instance sees every release in v1; per-tenant entitlement is a deferred
 * follow-up, mirroring how `plugins`/`plans` start as global catalogs).
 *
 * Each row is one immutable build of one desktop plugin at one version:
 * `storage_path` is a path relative to the handler's configured storage
 * directory (see DesktopPluginsApiHandler), `sha256`/`size_bytes` let a
 * device verify the download before installing it into its offline PHP
 * host. (plugin_name, version) is unique — a release is never overwritten
 * in place, only superseded by a new version row.
 *
 * This migration creates the schema only; populating it with real
 * obfuscated builds is an explicitly separate follow-up (the build/release
 * pipeline).
 *
 * Idempotent (IF NOT EXISTS) and reversible via down().
 */
class CreateDesktopPluginReleases
{
    public static function up(Database $db): void
    {
        $db->exec("
            CREATE TABLE IF NOT EXISTS desktop_plugin_releases (
                id           BIGSERIAL     NOT NULL PRIMARY KEY,
                plugin_name  VARCHAR(128)  NOT NULL,
                version      VARCHAR(64)   NOT NULL,
                sha256       CHAR(64)      NOT NULL,
                size_bytes   BIGINT        NOT NULL,
                storage_path TEXT          NOT NULL,
                released_at  TIMESTAMP     NOT NULL DEFAULT NOW(),
                created_at   TIMESTAMP     NOT NULL DEFAULT NOW(),
                UNIQUE (plugin_name, version)
            )
        ");
        $db->exec('CREATE INDEX IF NOT EXISTS idx_desktop_plugin_releases_plugin_name ON desktop_plugin_releases(plugin_name)');
    }

    public static function down(Database $db): void
    {
        $db->exec('DROP TABLE IF EXISTS desktop_plugin_releases CASCADE');
    }
}
