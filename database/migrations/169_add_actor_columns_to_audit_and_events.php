<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * Who did it: a person, an agent acting for a person, or the system (WHIT-753).
 *
 * The register's agent model needs every write to say which of the three did
 * it, so "Billing assistant for Rana" can be shown wherever an action is. The
 * existing `actor_user_id` names a profile but not whether that profile acted
 * itself or through an agent. These columns add the rest, on the audit log and
 * on domain events (docs/design/IMPLEMENTATION-NOTES.md, actor columns):
 *
 * - `actor_kind`: person | agent | system. NULL on rows written before this.
 * - `agent_principal_id`: the agent credential that acted (its token id).
 * - `on_behalf_of_profile_id`: the person the agent acted for.
 *
 * No foreign keys: an audit row must outlive the credential and the person.
 * Additive and idempotent; down() drops what up() added.
 */
class AddActorColumnsToAuditAndEvents
{
    private const TABLES = ['audit_log', 'domain_events'];

    public static function up(Database $db): void
    {
        foreach (self::TABLES as $table) {
            $db->exec("ALTER TABLE {$table} ADD COLUMN IF NOT EXISTS actor_kind VARCHAR(16)");
            $db->exec("ALTER TABLE {$table} ADD COLUMN IF NOT EXISTS agent_principal_id VARCHAR(64)");
            $db->exec("ALTER TABLE {$table} ADD COLUMN IF NOT EXISTS on_behalf_of_profile_id INTEGER");
        }
    }

    public static function down(Database $db): void
    {
        foreach (self::TABLES as $table) {
            $db->exec("ALTER TABLE {$table} DROP COLUMN IF EXISTS on_behalf_of_profile_id");
            $db->exec("ALTER TABLE {$table} DROP COLUMN IF EXISTS agent_principal_id");
            $db->exec("ALTER TABLE {$table} DROP COLUMN IF EXISTS actor_kind");
        }
    }
}
