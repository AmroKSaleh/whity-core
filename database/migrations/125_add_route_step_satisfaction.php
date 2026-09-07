<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * AddRouteStepSatisfaction (#1054) — a routing step that is satisfied by
 * DELIVERY rather than by somebody acting.
 *
 * WHY THIS EXISTS
 * ---------------
 * Every verb in migration 112's action vocabulary is an ACTOR-DRIVEN transition:
 * `issued`, `forwarded`, `acknowledged`, `returned`, `noted` all record that a
 * person did something. There is no way to model the last stage of a policy
 * circular — "put the PDF in every instructor's mailbox" — where nobody
 * downstream logs in and nobody acknowledges anything.
 *
 * Today such a step is authored as an ordinary one, and the damage is not that
 * the reports look wrong. It is that `document_route_recipients` keeps
 * `closed_by_event_id IS NULL` for every one of those people FOR EVER:
 *
 *  - "Awaiting me" ({@see \Whity\Core\Document\Organizer\CoreDocumentViews}) is
 *    derived from open recipient rows, so a node resolving to every instructor
 *    in a faculty puts a permanent phantom item in hundreds of inboxes that no
 *    act can clear — there is no act to make;
 *  - the #881 routing inbox reads the same rows and inherits it;
 *  - a decision step's QUORUM (migration 119) counts the rows one act opened, so
 *    such a step must never also be a gate, or it produces a quorum nobody can
 *    satisfy.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * DECISION 1: A COLUMN ON THE STEP, NOT A SIXTH ACTION VERB
 * ─────────────────────────────────────────────────────────────────────────
 * The obvious move is a `delivered` verb in `document_route_events.action`. It
 * is refused for the same two reasons migration 119 refuses `approved` and
 * `rejected`, and one more that is specific to this feature.
 *
 * MEANING. `noted` is the closest existing verb and it means A PERSON NOTED
 * THIS. Using it for a system close would make an append-only audit log assert
 * that somebody did something they did not do, which is the worst possible
 * content for the one table whose entire value is recording what people actually
 * did — and the trail has no update path, so it could never be put right.
 *
 * SAFETY. Widening an inline CHECK on `document_route_events` is
 * `DROP CONSTRAINT` + `ADD CONSTRAINT` on PostgreSQL and IMPOSSIBLE on SQLite —
 * the offline/desktop engine, and the engine
 * {@see \Tests\Support\SchemaFromMigrations} builds the test schema on — short of
 * the twelve-step rebuild of the one table that must never be rewritten.
 * Migration 119 hit this exact wall and answered it with a separate,
 * CHECK-constrained column mirrored by a PHP vocabulary class. This is that
 * answer applied again.
 *
 * WHOSE FACT IS IT. The third reason decides WHERE the column goes, and it rules
 * out the trail even if the first two had not. One event performs BOTH kinds of
 * close: the forward that a person makes closes their own row BECAUSE THEY ACTED
 * and, in the same transaction, closes the delivery rows it just opened at the
 * next stage BECAUSE THEY WERE TOLD. A flag on the event could not say which row
 * it meant. The fact is a property of the STEP — "the people here are told, and
 * are not asked to act" — so it lives on the step, exactly as migration 119's
 * `decision` does, and every row at a delivery step is delivery-closed by
 * construction rather than by a per-row marker anybody has to keep true.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * DECISION 2: A CLOSED VOCABULARY, NOT A SECOND BOOLEAN
 * ─────────────────────────────────────────────────────────────────────────
 * `satisfied_by` is CHECK-constrained to two values and mirrored by
 * {@see \Whity\Core\Document\Routing\RouteSatisfaction}, with
 * {@see \Tests\Core\Document\Routing\RouteSatisfactionVocabularyTest} pinning the
 * two together exactly as the action and verdict vocabularies are pinned.
 *
 * A `notify BOOLEAN` beside `decision` would have been shorter and would have
 * spread the single question "what settles this stage?" across two independent
 * flags, whose four combinations include one that means nothing
 * (`notify AND decision`) and would have to be refused anyway. One column
 * answers the question once.
 *
 * NOT NULL DEFAULT 'act' — so every step authored before today reads as what it
 * has always been, and this migration changes the behaviour of exactly zero
 * routes. There is no NULL state and therefore no third meaning to interpret.
 *
 * WIDENING THIS ONE LATER IS SAFE, which is the point of putting it here rather
 * than on the trail: `document_route_steps` is a SMALL table that is written
 * once with its route and never rewritten in place, so the SQLite rebuild that
 * is unthinkable for the trail is routine for it. Migration 119 makes the same
 * observation about `document_route_edges`.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * DECISION 3: THE SAME COLUMN ON THE TEMPLATE STEP
 * ─────────────────────────────────────────────────────────────────────────
 * `document_route_template_steps` (migration 120) gets the identical column,
 * default and CHECK. #1031 made a stored DESIGN runnable by
 * {@see \Whity\Core\Document\RouteTemplate\RouteTemplateInstantiation}, and its
 * docblock records the trap: a property the converter cannot CARRY is a property
 * the design silently loses. A delivery stage drawn on a canvas and converted
 * into an ordinary one would turn "tell every instructor" into "wait for every
 * instructor to acknowledge", which is precisely the permanent-phantom-item
 * failure this migration exists to remove, reached through the one door nobody
 * was watching.
 *
 * WHAT IS DELIBERATELY NOT HERE
 * -----------------------------
 * No CHANNEL. `"delivery": "email"` on a step would put a transport choice in
 * the route DESIGN, so changing a tenant from e-mail to in-app would mean
 * re-authoring every route that ever mentioned it. The step declares INTENT
 * ("these people are told, and are not asked to act"); the transport is operator
 * configuration and resolves through `documents.routing_notification_channels`
 * and the per-profile notification preferences. Platform declares capability,
 * operator decides presentation — the same split `route-act-panel.tsx` (#951)
 * makes when it takes the REASON a step is unavailable from the engine and
 * leaves hide-versus-degrade to the client.
 *
 * No RECIPIENT LIST, here or anywhere. A stage still names a RULE, and a
 * delivery stage resolving to "every instructor" re-resolves when it is reached,
 * against the organisation as it stands then.
 *
 * No STATUS COLUMN on anything. State stays derived from the append-only log.
 *
 * Idempotent (IF NOT EXISTS) and reversible via down().
 */
class AddRouteStepSatisfaction
{
    public static function up(Database $db): void
    {
        // THE ENGINE'S STEP. The CHECK is inline on the ADD COLUMN so it lands on
        // BOTH engines in one statement: SQLite accepts a column constraint on an
        // ADDED column even though it cannot alter an existing one, which is the
        // same property migration 119 relies on. NOT NULL is legal here because a
        // non-null DEFAULT is supplied, which is SQLite's condition for it.
        $db->exec("
            ALTER TABLE document_route_steps
                ADD COLUMN IF NOT EXISTS satisfied_by VARCHAR(16) NOT NULL DEFAULT 'act'
                CHECK (satisfied_by IN ('act', 'delivery'))
        ");

        // THE DESIGN'S STAGE. Identical column, identical default, identical
        // CHECK — see decision 3. A template that could not carry this would
        // convert a delivery stage into a circulation stage and nothing would
        // report an error.
        $db->exec("
            ALTER TABLE document_route_template_steps
                ADD COLUMN IF NOT EXISTS satisfied_by VARCHAR(16) NOT NULL DEFAULT 'act'
                CHECK (satisfied_by IN ('act', 'delivery'))
        ");

        // NO INDEX. `satisfied_by` is never a search predicate: nothing asks "all
        // the delivery steps in this tenant". It is read as a property of a step
        // already reached by its primary key or already joined from a recipient
        // row, and an index on a two-valued column feeding no query would be
        // write cost with no reader.
    }

    /**
     * Reverse both columns.
     *
     * Dropping them LOSES the distinction, and there is no way around that: which
     * stages were delivery stages is a fact these columns carry and nothing else
     * records. The loss is bounded to data this migration itself made storable —
     * every route authored before it reads identically afterwards — which is the
     * same trade migrations 104 and 119 state for their own added columns.
     */
    public static function down(Database $db): void
    {
        $db->exec('ALTER TABLE document_route_template_steps DROP COLUMN IF EXISTS satisfied_by');
        $db->exec('ALTER TABLE document_route_steps DROP COLUMN IF EXISTS satisfied_by');
    }
}
