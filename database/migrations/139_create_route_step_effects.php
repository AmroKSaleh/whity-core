<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * CreateRouteStepEffects (#1032) — what a routing stage DOES to the world,
 * and an honest record of every time it tried.
 *
 * WHY THIS EXISTS
 * ---------------
 * Migration 112 deferred this, and said exactly why:
 *
 *   "an effect declaration with no engine to run it is a stored intention that
 *   silently does nothing, which is the precise failure class this whole item
 *   is written against — something that still renders and still reports success
 *   while doing less than it claims."
 *
 * That argument has not weakened, so this migration ships only alongside the
 * runner. The two tables below are useless apart: a declaration nothing reads
 * is the stored intention 112 refused, and an attempt log with nothing to log
 * is an empty table.
 *
 * THE DISTINCTION 112 DRAWS, PRESERVED
 * ------------------------------------
 * ROUTING says who must act and when a step is satisfied. An EFFECT is a side
 * effect on the world. "Approve" and "forward" are routing; "send an email" is
 * not. Nothing here touches how a step is satisfied, and no effect can settle,
 * skip or redirect one — a step's outcome remains a function of what people did.
 *
 * DECISION 1: A TABLE, NOT A COLUMN
 * ---------------------------------
 * A step may declare several effects and their ORDER matters — "notify the
 * registry, then notify the archive" is a different instruction from its
 * reverse when the first one fails. A JSONB column on `document_route_steps`
 * would have held them, and was rejected for the reason every other list in
 * this schema is a table: `position` is then a column the database can make
 * unique, rather than an array index nothing checks.
 *
 * DECISION 2: THE ATTEMPT LOG IS ITS OWN TABLE, NOT MORE `action` VERBS
 * ---------------------------------------------------------------------
 * Migration 112 named this too, and it is the constraint most worth restating:
 *
 *   "Note what the seam must NOT be: widening this table's `action` CHECK. An
 *   effect's outcome has its own fixed shape (which effect, succeeded or
 *   failed, how many attempts) that is not a human act's, and putting two
 *   shapes in one column would give up the exact guarantee the closed
 *   vocabulary buys."
 *
 * So `document_route_events` is untouched. Its five verbs still mean "a person
 * did this", and a reader of the route trail still sees only human acts, in
 * order, with nobody's mail server interleaved among them.
 *
 * There is a second, harder reason. `document_route_events.action` carries an
 * inline CHECK, and widening one is `DROP CONSTRAINT` + `ADD CONSTRAINT` on
 * PostgreSQL and IMPOSSIBLE on SQLite — which is the engine the desktop host
 * and the test suite both run. Migration 125 records this. A vocabulary that
 * cannot be widened on half the engines is one to leave alone.
 *
 * DECISION 3: THE ATTEMPT SURVIVES THE DECLARATION
 * ------------------------------------------------
 * `effect_id` is nullable with ON DELETE SET NULL, and `effect_kind` is copied
 * onto the attempt row rather than joined. Deleting a step's effect declaration
 * must not erase the record that it once ran — that is the same append-only
 * principle `document_route_events` holds, and the moment somebody most wants
 * to tidy the history is exactly when it must survive. A reader of an old
 * attempt can still see WHICH effect ran even though the declaration is gone.
 *
 * DECISION 4: `skipped` IS A RECORDED OUTCOME, NOT SILENCE
 * --------------------------------------------------------
 * An effect whose audience resolves to nobody, or whose tenant has switched its
 * channels off, has NOT succeeded and has NOT failed. Recording that as either
 * would be a lie in one direction or the other, and recording nothing at all is
 * the silent no-op this whole feature exists to prevent. So the vocabulary is
 * three-valued and the row says which, with `detail` naming the reason.
 *
 * WHAT IS DELIBERATELY NOT HERE
 * -----------------------------
 * NO CHANNEL COLUMN. A step declares INTENT ("tell the registry"); the tenant's
 * `documents.routing_notification_channels` decides HOW that is delivered. That
 * split is already argued in SettingsRegistry and migration 125, and putting a
 * transport on a step would let an author hard-code a channel an operator had
 * deliberately turned off.
 *
 * NO FOREIGN KEY ON `effect_kind`. The catalogue of kinds is CODE — a registry
 * populated at boot by core and by plugins — not rows. Same as `rule_kind` on
 * `document_route_steps`, and for the same reason migration 112 gives: a kind
 * table would have to be kept in step with a registry that is already the
 * source of truth.
 *
 * NO STATUS COLUMN ANYWHERE. State stays derived from the append-only log. "Did
 * this effect run?" is a query over attempts, not a mutable flag that can drift
 * from them.
 *
 * NO RETRY SCHEDULER. `attempt` counts tries so a reader can see that something
 * was retried; scheduling one is the QUEUE's job, and the notification
 * subsystem already enqueues durable delivery jobs with their own retry. An
 * effect that dispatched into that queue has done its work.
 *
 * Idempotent (IF NOT EXISTS) and reversible via down().
 */
class CreateRouteStepEffects
{
    public static function up(Database $db): void
    {
        // THE DECLARATION. One literal create-table statement per table, never
        // a loop over interpolated names — TenantOwnedTablesTest and
        // CoreTablesTest re-derive their registries by scanning this source, so
        // every name has to appear literally.
        //
        // HYPHENATED in prose, as migrations 108 and 112 also write it, and not
        // merely as a style: MigrationSchemaTest scans every migration for the
        // two words followed by an identifier and requires an `IF NOT EXISTS`
        // between them. It matches case-INSENSITIVELY, so lowercasing the
        // keyword is not enough — this comment, written with a space, failed
        // that test by describing a table called "statement".
        //
        // `UNIQUE (step_id, position)` is what makes the ordering the database's
        // to enforce rather than the author's to remember, mirroring
        // `document_route_steps`' own `UNIQUE (route_id, position)`.
        $db->exec("
            CREATE TABLE IF NOT EXISTS document_route_step_effects (
                id            BIGSERIAL    NOT NULL PRIMARY KEY,
                tenant_id     INTEGER      NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                step_id       BIGINT       NOT NULL REFERENCES document_route_steps(id) ON DELETE CASCADE,
                position      INTEGER      NOT NULL,
                effect_kind   VARCHAR(128) NOT NULL,
                effect_config JSONB        NOT NULL DEFAULT '{}'::jsonb,
                created_at    TIMESTAMP    NOT NULL DEFAULT NOW(),
                UNIQUE (step_id, position)
            )
        ");

        // Every routing table carries a tenant index, because every read of one
        // is tenant-scoped by predicate and the scanner in
        // ci-tenant-predicate-guard.php enforces that it is.
        $db->exec('CREATE INDEX IF NOT EXISTS idx_document_route_step_effects_tenant_id ON document_route_step_effects (tenant_id)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_document_route_step_effects_tenant_step ON document_route_step_effects (tenant_id, step_id, position)');

        // THE RECORD. Append-only, like `document_route_events`, and with its
        // own shape rather than borrowed verbs — see decision 2.
        //
        // The CHECK is inline, which is what lets this land on SQLite as well as
        // PostgreSQL in one statement (migration 125 records the rule).
        //
        // `document_id` rather than only `event_id`: an effect is a fact about a
        // DOCUMENT's history, and the event it fired from may be deleted with a
        // step long before anyone stops caring which documents sent mail.
        $db->exec("
            CREATE TABLE IF NOT EXISTS document_route_effect_attempts (
                id          BIGSERIAL    NOT NULL PRIMARY KEY,
                tenant_id   INTEGER      NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                document_id BIGINT       NOT NULL REFERENCES documents(id) ON DELETE CASCADE,
                event_id    BIGINT       REFERENCES document_route_events(id) ON DELETE SET NULL,
                effect_id   BIGINT       REFERENCES document_route_step_effects(id) ON DELETE SET NULL,
                effect_kind VARCHAR(128) NOT NULL,
                status      VARCHAR(16)  NOT NULL CHECK (status IN ('succeeded', 'failed', 'skipped')),
                attempt     INTEGER      NOT NULL DEFAULT 1,
                detail      TEXT,
                occurred_at TIMESTAMP    NOT NULL DEFAULT NOW()
            )
        ");

        $db->exec('CREATE INDEX IF NOT EXISTS idx_document_route_effect_attempts_tenant_id ON document_route_effect_attempts (tenant_id)');
        // The read this table actually serves: "what did this document's effects
        // do, most recent first", on the record page.
        $db->exec('CREATE INDEX IF NOT EXISTS idx_document_route_effect_attempts_document ON document_route_effect_attempts (tenant_id, document_id, occurred_at)');
    }

    /**
     * Drop both tables.
     *
     * The attempt log is HISTORY, and dropping it destroys the only record that
     * a document's stages ever reached the outside world. That is stated rather
     * than softened: there is no way to reverse this migration and keep it, the
     * loss is bounded to data this migration itself made storable, and a
     * deployment that has run effects should think before reversing.
     *
     * Dropped in dependency order — attempts reference declarations.
     */
    public static function down(Database $db): void
    {
        $db->exec('DROP TABLE IF EXISTS document_route_effect_attempts');
        $db->exec('DROP TABLE IF EXISTS document_route_step_effects');
    }
}
