<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * Agent proposals: "Ask first" made real on the server (WHIT-784; register
 * 7.5a Proposal, 7.5b Approvals inbox; engineering handoff item 10).
 *
 * When an agent's call needs a permission its grant sets to Ask first, the
 * call is not executed. It is stored here for the person who granted the
 * agent to approve, edit or decline. Approving executes it as that person's
 * delegate, after every check runs again.
 *
 * - `agent_principal_id`: the agent credential that asked (its token id).
 * - `on_behalf_of_profile_id`: the granting person, the only one who decides.
 * - `tool`, `arguments`: the operation and its arguments, as the agent sent them.
 * - `diff`: what would change, when the operation can preview it; null otherwise.
 * - `scope`: the credential's scope when it asked, so approval re-checks the
 *   same toolsets and grant.
 * - `permissions`: the permissions the operation uses, shown on the card.
 * - `undo_window_seconds`: how long Undo stays after it runs; null when it
 *   can't be undone.
 * - `status`: waiting · approved · declined · expired · undone.
 * - `result`: what the operation answered when it ran.
 * - `expires_at`: a waiting proposal expires after 7 days (owner decision 10).
 */
class CreateAgentProposals
{
    public static function up(Database $db): void
    {
        $db->exec("
            CREATE TABLE IF NOT EXISTS agent_proposals (
                id                      BIGSERIAL    NOT NULL PRIMARY KEY,
                tenant_id               INTEGER      NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                agent_principal_id      VARCHAR(64)  NOT NULL,
                on_behalf_of_profile_id INTEGER      NOT NULL REFERENCES profiles(id) ON DELETE CASCADE,
                tool                    VARCHAR(128) NOT NULL,
                arguments               JSONB        NOT NULL DEFAULT '{}'::jsonb,
                diff                    JSONB,
                scope                   JSONB        NOT NULL DEFAULT '[]'::jsonb,
                permissions             JSONB        NOT NULL DEFAULT '[]'::jsonb,
                summary                 TEXT         NOT NULL DEFAULT '',
                undo_window_seconds     INTEGER,
                status                  VARCHAR(16)  NOT NULL DEFAULT 'waiting',
                result                  JSONB,
                decided_by_profile_id   INTEGER      REFERENCES profiles(id) ON DELETE SET NULL,
                decided_at              TIMESTAMP,
                expires_at              TIMESTAMP    NOT NULL,
                created_at              TIMESTAMP    NOT NULL DEFAULT NOW()
            )
        ");
        // The approvals inbox: one person's waiting proposals, oldest first.
        $db->exec('CREATE INDEX IF NOT EXISTS idx_agent_proposals_inbox ON agent_proposals(tenant_id, on_behalf_of_profile_id, status, id)');
    }

    public static function down(Database $db): void
    {
        $db->exec('DROP INDEX IF EXISTS idx_agent_proposals_inbox');
        $db->exec('DROP TABLE IF EXISTS agent_proposals');
    }
}
