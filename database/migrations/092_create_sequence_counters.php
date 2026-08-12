<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * CreateSequenceCounters — the host-owned table behind
 * {@see \Whity\Sdk\Sql\SequenceAllocator}, so a plugin that needs uniquely
 * numbered records ships no table, no migration and no SQL of its own.
 *
 * The defect this closes
 * ----------------------
 * Numbering a document is the plainest requirement there is, and the obvious
 * implementation is wrong:
 *
 *     SELECT value FROM counters WHERE name = 'invoice';   -- two clients read 3
 *     UPDATE counters SET value = 4 WHERE name = 'invoice'; -- two clients write 4
 *
 * Two documents whose entire purpose is to be uniquely numbered come out
 * numbered the same, nothing errors, and it surfaces as a billing dispute weeks
 * later. An adopter shipped exactly that, under a docblock already claiming the
 * operation was atomic.
 *
 * The fix is not a bigger lock, it is removing the read: an allocation is one
 * `INSERT … ON CONFLICT DO UPDATE … RETURNING` statement, which computes the
 * new value inside the write on the row the write locks. There is no interval
 * between observing and updating because nothing is observed.
 *
 * Why CORE owns the storage
 * -------------------------
 * Publishing the correct SQL and letting each plugin paste it over a counter
 * table of its own would leave every adopter shipping a migration for a table
 * with no domain meaning, and would leave the correctness in N places to be got
 * wrong independently. Core owning it is the same trade
 * `data_type_restore_states` (089) makes: one table nobody has to know about,
 * versus N plugin migrations that each have to be right.
 *
 * Shape
 * -----
 *   (tenant_id, name) → value
 *
 * The pair IS the identity of a counter, so it is the primary key rather than a
 * uniqueness rule bolted onto a surrogate — and, being the primary key, it is
 * also the index that makes the upsert's conflict target resolvable, which is
 * what the whole mechanism rests on.
 *
 * `tenant_id` is a real column with a real cascade, not a scope inferred
 * elsewhere: the tenant-predicate scanner can see it, dropping a tenant takes
 * its counters with it, and one tenant's `invoice` counter cannot be advanced
 * by naming it from another tenant. A genuinely PLATFORM-WIDE counter is the
 * system tenant's (id 0) counter — one storage shape, one predicate, instead of
 * a second global table nothing polices.
 *
 * `value` is BIGINT because a change-feed cursor on a busy deployment will
 * outgrow INTEGER, and widening a primary-key-adjacent column later is a
 * migration nobody enjoys.
 *
 * Rows here are AUTHORITATIVE, unlike 089's derived memories: losing one
 * re-issues numbers that have already been handed out. It is never truncated as
 * a cleanup step, and `down()` says so.
 */
class CreateSequenceCounters
{
    public static function up(Database $db): void
    {
        // `updated_at` defaults to CURRENT_TIMESTAMP rather than NOW(): it is
        // the SQL-standard spelling and needs no dialect translation, so this
        // DDL and the `updated_at = CURRENT_TIMESTAMP` in the allocator's
        // DO UPDATE branch are literally the same expression on both engines.
        $db->exec('
            CREATE TABLE IF NOT EXISTS sequence_counters (
                tenant_id INTEGER NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                name VARCHAR(128) NOT NULL,
                value BIGINT NOT NULL DEFAULT 0,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (tenant_id, name)
            )
        ');
    }

    public static function down(Database $db): void
    {
        // Reversible in the schema sense only. Re-running up() after this gives
        // back an EMPTY table, and a counter that restarts at 1 re-issues every
        // number it has already handed out. Rolling this back on a deployment
        // that has allocated anything is a data-loss operation, not a tidy-up.
        $db->exec('DROP TABLE IF EXISTS sequence_counters CASCADE');
    }
}
