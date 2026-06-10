<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * CreatePersons migration (WC-65 — Family Relations Management System)
 *
 * Creates the `persons` table: the ONE and ONLY graph node in the family
 * relations feature (ADR 0002). Every human in a family graph — whether or not
 * they have a platform login — is a `persons` row, and relationship edges are
 * always `person → person`. A platform user participates by having an
 * (auto-provisioned) person row linked back to them via `persons.user_id`; a
 * relative without an account is simply a person with `user_id = NULL`.
 *
 * Schema notes
 * ------------
 *  - `tenant_id` is NOT NULL and FK-cascades with the tenant, consistent with
 *    every other tenant-scoped table — a person belongs to exactly one tenant
 *    and disappears with it.
 *  - `user_id` is NULLABLE and UNIQUE with `ON DELETE SET NULL`: at most one
 *    person may shadow a given user (the auto-provision invariant), and when the
 *    underlying user is deleted the person row survives as a now-account-less
 *    relative rather than being destroyed (genealogy data outlives logins). It is
 *    intentionally a FK so an orphaned link can never point at a missing user.
 *  - `display_name` is NOT NULL — the human-readable label shown in the UI and
 *    graph. For an auto-provisioned user shadow it is seeded from the user.
 *  - `birth_date` / `deceased` / `notes` are optional genealogy fields; `deceased`
 *    defaults to false so existing rows read as living.
 *
 * The `(tenant_id)` index backs the primary listing query (a tenant's persons);
 * the partial-style `(user_id)` lookup is covered by the UNIQUE constraint, which
 * Postgres backs with an index, so the user→person resolution used by
 * auto-provision is already cheap.
 *
 * Additive, idempotent (IF NOT EXISTS) and fully reversible via down(), which
 * drops the table (CASCADE removes its indexes and dependent relation edges).
 */
class CreatePersons
{
    public static function up(Database $db): void
    {
        // The single family-graph node. A user links in via user_id; a non-user
        // relative is a row with user_id = NULL.
        $db->exec('
            CREATE TABLE IF NOT EXISTS persons (
                id SERIAL PRIMARY KEY,
                tenant_id INTEGER NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                display_name VARCHAR(255) NOT NULL,
                user_id INTEGER NULL UNIQUE REFERENCES users(id) ON DELETE SET NULL,
                birth_date DATE NULL,
                deceased BOOLEAN NOT NULL DEFAULT false,
                notes TEXT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT NOW()
            )
        ');

        // Primary access pattern: list/search the persons of one tenant.
        $db->exec('CREATE INDEX IF NOT EXISTS idx_persons_tenant_id ON persons (tenant_id)');
    }

    public static function down(Database $db): void
    {
        // Drop the node table; CASCADE also removes the relation edges that
        // reference it (defensive — the relations migration's own down() runs
        // first under a normal rollback).
        $db->exec('DROP TABLE IF EXISTS persons CASCADE');
    }
}
