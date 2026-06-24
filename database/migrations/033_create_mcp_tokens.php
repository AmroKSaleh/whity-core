<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * WC-2686308f: MCP AI-principal token registry.
 *
 * Creates the `mcp_tokens` table, which tracks long-lived machine/service
 * tokens issued for MCP (Model Context Protocol) clients. Each row represents
 * one issued token; revocation is handled by inserting the token's jti into
 * the existing `revoked_tokens` table, consistent with the access/refresh
 * token revocation model.
 *
 * This is a TENANT-SCOPED table: every row carries both user_id and tenant_id
 * so listing and revocation checks are always tenant-isolated.
 *
 * Schema notes:
 *   - jti UNIQUE: the JWT ID uniquely identifies the token platform-wide, so
 *     mcp_tokens and revoked_tokens share the same jti keyspace.
 *   - scope TEXT: stored as a JSON array (e.g. '["tools:call","resources:read"]')
 *     so the column is schema-flexible without an additional join table.
 *   - principal_kind: always 'user' for Phase C; reserved for future
 *     'service_account' and 'api_key' principal types.
 *   - expires_at: mirrors the token's 'exp' claim; used to filter out naturally
 *     expired tokens in listing queries without re-parsing JWTs.
 */
class CreateMcpTokens
{
    public static function up(Database $db): void
    {
        $db->exec("
            CREATE TABLE IF NOT EXISTS mcp_tokens (
                id             BIGSERIAL    NOT NULL PRIMARY KEY,
                jti            VARCHAR(255) NOT NULL,
                user_id        INTEGER      NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                tenant_id      INTEGER      NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                name           VARCHAR(255) NOT NULL,
                principal_kind VARCHAR(50)  NOT NULL DEFAULT 'user',
                scope          TEXT         NOT NULL DEFAULT '[]',
                expires_at     TIMESTAMP    NOT NULL,
                created_at     TIMESTAMP    NOT NULL DEFAULT NOW(),
                UNIQUE(jti)
            )
        ");

        $db->exec("
            CREATE INDEX IF NOT EXISTS idx_mcp_tokens_user_tenant
                ON mcp_tokens (user_id, tenant_id)
        ");

        $db->exec("
            CREATE INDEX IF NOT EXISTS idx_mcp_tokens_expires_at
                ON mcp_tokens (expires_at)
        ");
    }

    public static function down(Database $db): void
    {
        $db->exec('DROP TABLE IF EXISTS mcp_tokens CASCADE');
    }
}
