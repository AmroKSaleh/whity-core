<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * WC-event-spine (#154) — the durable, tenant-scoped event spine that
 * automation, notifications, audit and native-sync all build on.
 *
 * TWO tables, two concerns (transactional-outbox pattern):
 *
 *  • `domain_events` — the IMMUTABLE, append-only log. One row per event
 *    dispatched via HookManager::dispatchAsync (#162 rewires it here from the
 *    old log-only Queue stub). `id` is a ULID so byte-order == time-order,
 *    giving a natural tenant-scoped cursor for the native change feed. Never
 *    UPDATEd — history is the value, valuable and queryable even before any
 *    consumer exists.
 *
 *  • `event_outbox` — the MUTABLE relay bookkeeping. Exactly one row per event
 *    (PK = event_id), written in the SAME transaction as its domain_events row
 *    (see DomainEventStore::append) so an event and its intent-to-relay commit
 *    atomically with the originating business write. A relay worker drains
 *    `pending` rows (reserve → deliver/enqueue → mark relayed), with
 *    retry/backoff/dead-letter + lease-reclaim mirroring the `jobs` queue.
 *
 * Both tables are TENANT-OWNED ({@see \Whity\Core\Tenant\TenantOwnedTables}):
 * `tenant_id` NOT NULL + ON DELETE CASCADE. The RELAY mechanics run as system
 * infra ACROSS tenants (annotated @tenant-guard-ignore in DomainEventStore);
 * `event_outbox.tenant_id` is denormalised from the event so the relay can
 * scope/keep tenant on one row without a join. Append stamps tenant_id from the
 * trusted caller (TenantContext).
 *
 * Idempotent (IF NOT EXISTS) and reversible via down() (child table first).
 */
class CreateDomainEvents
{
    public static function up(Database $db): void
    {
        $db->exec("
            CREATE TABLE IF NOT EXISTS domain_events (
                id             VARCHAR(26)  NOT NULL PRIMARY KEY,
                tenant_id      INTEGER      NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                event_name     VARCHAR(191) NOT NULL,
                aggregate_type VARCHAR(191),
                aggregate_id   VARCHAR(191),
                actor_user_id  INTEGER,
                payload        JSONB        NOT NULL DEFAULT '{}'::jsonb,
                occurred_at    TIMESTAMP    NOT NULL DEFAULT NOW(),
                created_at     TIMESTAMP    NOT NULL DEFAULT NOW()
            )
        ");
        // Tenant-scoped, time-ordered scan (ULID id == time order) — the change-feed cursor.
        $db->exec('CREATE INDEX IF NOT EXISTS idx_domain_events_tenant ON domain_events(tenant_id, id)');
        // Lookups by event kind and by aggregate, within a tenant.
        $db->exec('CREATE INDEX IF NOT EXISTS idx_domain_events_name ON domain_events(tenant_id, event_name, id)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_domain_events_aggregate ON domain_events(tenant_id, aggregate_type, aggregate_id)');

        $db->exec("
            CREATE TABLE IF NOT EXISTS event_outbox (
                event_id     VARCHAR(26)  NOT NULL PRIMARY KEY REFERENCES domain_events(id) ON DELETE CASCADE,
                tenant_id    INTEGER      NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                status       VARCHAR(16)  NOT NULL DEFAULT 'pending',
                attempts     INTEGER      NOT NULL DEFAULT 0,
                max_attempts INTEGER      NOT NULL DEFAULT 25,
                available_at TIMESTAMP    NOT NULL DEFAULT NOW(),
                reserved_at  TIMESTAMP,
                relayed_at   TIMESTAMP,
                last_error   TEXT,
                created_at   TIMESTAMP    NOT NULL DEFAULT NOW(),
                updated_at   TIMESTAMP    NOT NULL DEFAULT NOW(),
                CHECK (status IN ('pending', 'reserved', 'relayed', 'dead'))
            )
        ");
        // Relay claim: oldest available pending rows first.
        $db->exec('CREATE INDEX IF NOT EXISTS idx_event_outbox_drain ON event_outbox(status, available_at)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_event_outbox_tenant ON event_outbox(tenant_id)');
        // Reaper scan for lease-expired reserved rows (a crashed relay worker).
        $db->exec('CREATE INDEX IF NOT EXISTS idx_event_outbox_reclaim ON event_outbox(status, reserved_at)');
    }

    public static function down(Database $db): void
    {
        // Child (FK → domain_events) first.
        $db->exec('DROP TABLE IF EXISTS event_outbox CASCADE');
        $db->exec('DROP TABLE IF EXISTS domain_events CASCADE');
    }
}
