<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * WC-f-sessions-table: interactive login-session registry.
 *
 * Sessions are otherwise stateless (access+refresh JWTs). This table records one
 * row per interactive LOGIN session so a user can SEE their active sessions
 * (browser/user-agent, IP, when created / last seen) and revoke them
 * individually. A "session" is a refresh-token FAMILY: the row tracks the
 * CURRENT access + refresh jti, which rotate on every refresh (the row is
 * updated in place, so the family stays one row across rotations).
 *
 * Revocation reuses the shared `revoked_tokens` jti keyspace: revoking a session
 * blacklists its current access + refresh jti (so validateAccessToken's existing
 * isTokenRevoked check kills it immediately) and stamps revoked_at.
 *
 * NOT for native-device credentials — those live in `devices` (migration 044)
 * and are managed via their own list. This table is interactive logins only.
 *
 * TENANT-SCOPED: every row carries profile_id AND tenant_id (post-cutover we key
 * on profiles.id), so listing and revocation are always profile+tenant isolated.
 *
 * Schema notes:
 *   - refresh_jti UNIQUE: the current refresh jti uniquely identifies the family
 *     platform-wide and is how a refresh call locates the row to rotate.
 *   - access_jti: nullable current access jti; blacklisted on revoke so the live
 *     access token dies immediately (not just at its ≤15-min expiry).
 *   - expires_at: the current refresh token's expiry; lets listing filter out
 *     naturally-expired sessions without re-parsing JWTs.
 */
class CreateSessions
{
    public static function up(Database $db): void
    {
        $db->exec("
            CREATE TABLE IF NOT EXISTS sessions (
                id           BIGSERIAL    NOT NULL PRIMARY KEY,
                profile_id   INTEGER      NOT NULL REFERENCES profiles(id) ON DELETE CASCADE,
                tenant_id    INTEGER      NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                refresh_jti  VARCHAR(255) NOT NULL,
                access_jti   VARCHAR(255),
                user_agent   VARCHAR(512),
                ip_address   VARCHAR(45),
                created_at   TIMESTAMP    NOT NULL DEFAULT NOW(),
                last_seen_at TIMESTAMP    NOT NULL DEFAULT NOW(),
                expires_at   TIMESTAMP    NOT NULL,
                revoked_at   TIMESTAMP,
                UNIQUE(refresh_jti)
            )
        ");

        $db->exec("
            CREATE INDEX IF NOT EXISTS idx_sessions_profile_tenant
                ON sessions (profile_id, tenant_id)
        ");

        $db->exec("
            CREATE INDEX IF NOT EXISTS idx_sessions_expires_at
                ON sessions (expires_at)
        ");
    }

    public static function down(Database $db): void
    {
        $db->exec('DROP TABLE IF EXISTS sessions CASCADE');
    }
}
