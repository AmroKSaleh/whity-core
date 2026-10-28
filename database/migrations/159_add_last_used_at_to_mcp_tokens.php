<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * AddLastUsedAtToMcpTokens — when an AI app last reached the server (WHIT-697).
 *
 * The "Connect an AI app" wizard ends by waiting for the app's first call, and
 * the connections list shows which apps are still in use. Both read this column.
 * The Dispatcher writes it after a dedicated MCP token passes the auth, tenant
 * and rate-limit gates (McpTokenService::recordUse), at most once a minute per
 * token, so a busy agent does not turn every tool call into an UPDATE.
 *
 * Nullable with no default: every existing token's last use is unknown, and
 * "never" would be untrue for tokens that have been working for weeks.
 */
class AddLastUsedAtToMcpTokens
{
    public static function up(Database $db): void
    {
        $db->getPdo()->exec('ALTER TABLE mcp_tokens ADD COLUMN IF NOT EXISTS last_used_at TIMESTAMP DEFAULT NULL');
    }

    public static function down(Database $db): void
    {
        $db->getPdo()->exec('ALTER TABLE mcp_tokens DROP COLUMN IF EXISTS last_used_at');
    }
}
