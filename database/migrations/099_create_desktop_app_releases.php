<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * Desktop app self-update release catalog (WC-app-self-update).
 *
 * A single GLOBAL table (no tenant_id — same v1 posture as
 * `desktop_plugin_releases`: every authenticated device on this instance
 * sees the latest release for its platform in v1; per-tenant staged rollout
 * is a deferred follow-up).
 *
 * Each row is one immutable, SIGNED build of the Tauri desktop app for one
 * platform target: `target` is a Rust-target-triple-derived string (e.g.
 * `windows-x86_64`) — the key `tauri-plugin-updater`'s protocol looks up
 * under its `platforms{}` map. `url`/`signature` point at the installer
 * artifact and its minisign signature (published as GitHub Release assets by
 * the tauri-desktop-release.yml CI workflow — see docs/wiki/Desktop-App-
 * Updates.md); this table never stores the binary itself, only where to find
 * it and how to verify it. (version, target) is unique — a release is never
 * overwritten in place, only superseded by a new version row, mirroring
 * `desktop_plugin_releases`'s own immutability posture.
 *
 * This migration creates the schema only; registering a real signed release
 * is `bin/desktop-app-release`'s job, run by CI or an operator after a
 * tagged build is signed and published — see that script's own docs.
 *
 * Idempotent (IF NOT EXISTS) and reversible via down().
 */
class CreateDesktopAppReleases
{
    public static function up(Database $db): void
    {
        $db->exec("
            CREATE TABLE IF NOT EXISTS desktop_app_releases (
                id           BIGSERIAL     NOT NULL PRIMARY KEY,
                version      VARCHAR(64)   NOT NULL,
                target       VARCHAR(64)   NOT NULL,
                url          TEXT          NOT NULL,
                signature    TEXT          NOT NULL,
                notes        TEXT,
                pub_date     TIMESTAMP     NOT NULL DEFAULT NOW(),
                released_at  TIMESTAMP     NOT NULL DEFAULT NOW(),
                created_at   TIMESTAMP     NOT NULL DEFAULT NOW(),
                UNIQUE (version, target)
            )
        ");
        $db->exec('CREATE INDEX IF NOT EXISTS idx_desktop_app_releases_target_released ON desktop_app_releases(target, released_at DESC)');
    }

    public static function down(Database $db): void
    {
        $db->exec('DROP TABLE IF EXISTS desktop_app_releases CASCADE');
    }
}
