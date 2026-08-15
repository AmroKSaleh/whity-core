<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * Adds `revoked_tokens.session_id` — the refresh-token FAMILY-tracking link
 * needed for reuse DETECTION (WC-refresh-reuse-family).
 *
 * Rotation already blacklists a superseded refresh jti in `revoked_tokens`
 * ({@see \Whity\Auth\AuthHandler::revokeRefreshToken()}), and a replay of that
 * jti is already rejected (401) and logged as `auth.refresh_token_reuse_detected`.
 * What was missing: a replayed, already-rotated jti is a strong signal the
 * token was stolen — a legitimate client never re-presents a token it already
 * rotated away from — so the correct response is to kill the WHOLE token
 * family (every jti descended from the same login), including whatever token
 * is CURRENTLY live in that family, not just reject the one replayed request
 * (RFC 6819 §5.2.2.3 refresh-token rotation reuse detection).
 *
 * Before this column, a blacklisted jti carried no link back to the `sessions`
 * row (WC-f-sessions-table, migration 045) it was rotated away from — that
 * row IS the family (one row per interactive login, updated in place on every
 * rotation), but once a jti is superseded the row has already moved on to a
 * newer jti, so nothing could trace the stale jti back to it. This column
 * closes that gap cheaply: at the moment a refresh jti is blacklisted
 * (rotation time), the row it was rotated FROM is still resolvable, so the
 * writer stamps its id here. A later replay of that jti can then look up
 * `session_id` and revoke that family's CURRENT jtis too.
 *
 * Nullable and NOT a foreign key: `revoked_tokens` is a sanctioned GLOBAL table
 * (no tenant_id) while `sessions` is tenant-owned, and most revocations here
 * are unrelated to a session (logout, password-change epoch bump, 2FA
 * changes) and simply leave this NULL — a soft, best-effort link, not a
 * structural relationship the schema must enforce.
 *
 * Idempotent (IF NOT EXISTS) and fully reversible via down().
 */
class AddSessionIdToRevokedTokens
{
    public static function up(Database $db): void
    {
        $db->exec('ALTER TABLE revoked_tokens ADD COLUMN IF NOT EXISTS session_id BIGINT DEFAULT NULL');
    }

    public static function down(Database $db): void
    {
        $db->exec('ALTER TABLE revoked_tokens DROP COLUMN IF EXISTS session_id');
    }
}
