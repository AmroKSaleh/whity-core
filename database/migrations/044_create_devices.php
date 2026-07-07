<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * WC-b-device-tokens: registered-device registry for native/desktop clients.
 *
 * Creates the `devices` table, which tracks long-lived per-device credentials
 * issued to non-browser clients (e.g. the KeyHub KiCad companion). Each row is
 * one enrolled device; the credential is an HS256 JWT with type='device' and
 * aud='device' whose jti is recorded here. Per-device revocation inserts that
 * jti into the existing `revoked_tokens` table — the same jti keyspace as
 * access/refresh/mcp tokens — so a revoked device can no longer exchange its
 * credential for a session.
 *
 * TENANT-SCOPED: every row carries profile_id AND tenant_id (post-cutover we key
 * on profiles.id directly, like mcp_tokens after migration 040), so listing and
 * revocation are always profile+tenant isolated.
 *
 * Schema notes:
 *   - jti UNIQUE: the JWT ID uniquely identifies the credential platform-wide.
 *   - platform: free-text client OS/kind ('windows'|'macos'|'linux'|'ios'|
 *     'android'|'other') — informational, not an enforced enum.
 *   - fingerprint: optional client-supplied public-key/hardware fingerprint,
 *     recorded for audit; not currently used as a second factor.
 *   - expires_at: mirrors the credential's 'exp' claim; lets listing filter out
 *     naturally-expired credentials without re-parsing JWTs.
 *   - last_seen_at: bumped each time the credential is exchanged for a session.
 */
class CreateDevices
{
    public static function up(Database $db): void
    {
        $db->exec("
            CREATE TABLE IF NOT EXISTS devices (
                id           BIGSERIAL    NOT NULL PRIMARY KEY,
                jti          VARCHAR(255) NOT NULL,
                profile_id   INTEGER      NOT NULL REFERENCES profiles(id) ON DELETE CASCADE,
                tenant_id    INTEGER      NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                name         VARCHAR(255) NOT NULL,
                platform     VARCHAR(64)  NOT NULL,
                fingerprint  VARCHAR(255),
                expires_at   TIMESTAMP    NOT NULL,
                last_seen_at TIMESTAMP,
                created_at   TIMESTAMP    NOT NULL DEFAULT NOW(),
                UNIQUE(jti)
            )
        ");

        $db->exec("
            CREATE INDEX IF NOT EXISTS idx_devices_profile_tenant
                ON devices (profile_id, tenant_id)
        ");

        $db->exec("
            CREATE INDEX IF NOT EXISTS idx_devices_expires_at
                ON devices (expires_at)
        ");
    }

    public static function down(Database $db): void
    {
        $db->exec('DROP TABLE IF EXISTS devices CASCADE');
    }
}
