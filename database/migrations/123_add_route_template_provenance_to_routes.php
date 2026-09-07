<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * AddRouteTemplateProvenanceToRoutes (#1031) — where a circulation CAME FROM.
 *
 * THE SEAM MIGRATION 112 NAMED, TAKEN
 * ------------------------------------
 * Migration 112's deferral notes specify this shape exactly: "a nullable
 * `template_id` plus a `template_name` SNAPSHOT on `document_routes` — the
 * provenance shape migration 108 already argues for" on `documents`. Migration
 * 120 built the design side; #1014 taught the engine to follow verdict edges.
 * This is the last column the two needed to meet.
 *
 * TWO COLUMNS, AND THE SECOND IS NOT REDUNDANT
 * ---------------------------------------------
 * `template_id` is the POINTER and it can go: a design is a living record its
 * author may delete, and `ON DELETE SET NULL` is the only behaviour that does
 * not either forbid the deletion or take the circulation with it. `template_name`
 * is the SNAPSHOT and it cannot, so a trail still reads "issued from Purchase
 * approval" six months after the design was thrown away. Migration 108 makes the
 * identical argument for `documents.document_template_id` + `template_name`, and
 * this is deliberately the same pair spelled the same way rather than a second
 * idea about provenance.
 *
 * BOTH ARE NULLABLE, AND THAT IS THE AD-HOC ROUTE
 * ------------------------------------------------
 * `documents.template_name` is NOT NULL because every document is made from
 * something. A route is not: the composer's step-by-step list is the original way
 * to start one and stays supported, and a route composed by hand has no design
 * behind it to name. NULL here means "nobody applied a template", which is a
 * fact, and inventing a placeholder name for it would put a design in the trail
 * that never existed.
 *
 * WHY THIS IS WRITTEN AT INSERT AND NEVER AFTERWARDS
 * --------------------------------------------------
 * {@see \Whity\Core\Document\Routing\RouteRepository} offers no update and no
 * delete, on purpose: a route is created COMPLETE inside one transaction, and an
 * amendable route would reintroduce the lifecycle state migration 108 refused.
 * So provenance is a constructor argument, not a later stamp — there is nowhere
 * to stamp it from.
 *
 * WHAT THIS MIGRATION DELIBERATELY DOES NOT ADD
 * ----------------------------------------------
 * A pointer from an INSTANCE step back to the TEMPLATE step it was copied from.
 * The instance is a snapshot — the whole reason the steps are copied rather than
 * referenced is that editing a design must not change a circulation already under
 * way — and a back-pointer would invite a reader to follow it and render today's
 * design beside yesterday's trail. That is exactly the staleness the copy exists
 * to prevent, reached through a column nobody thought of as a cache.
 *
 * `VARCHAR(160)` matches `document_route_templates.name` rather than
 * `documents.template_name`'s 255, so the snapshot can hold anything the source
 * column can and never silently truncates.
 *
 * Idempotent (IF NOT EXISTS) and reversible via down().
 */
final class AddRouteTemplateProvenanceToRoutes
{
    public static function up(Database $db): void
    {
        // The pointer. SET NULL rather than CASCADE or RESTRICT: deleting a
        // design must neither delete a circulation that already happened nor be
        // forbidden by one. `document_route_templates` is created by migration
        // 120, so the reference resolves on every path that reaches here.
        $db->exec(
            'ALTER TABLE document_routes
                 ADD COLUMN IF NOT EXISTS template_id BIGINT
                 REFERENCES document_route_templates(id) ON DELETE SET NULL'
        );

        // The snapshot. See the class docblock: this is what survives the delete.
        $db->exec('ALTER TABLE document_routes ADD COLUMN IF NOT EXISTS template_name VARCHAR(160)');

        // "Which circulations came from this design?" is the question an author
        // about to delete one asks, and it is also the scan PostgreSQL performs
        // on every template delete to apply SET NULL. Without an index that is a
        // sequential scan of every route in the install, taken on a path an
        // ordinary editor action reaches. `tenant_id` leads because the predicate
        // guard requires every read of this table to bind it.
        $db->exec(
            'CREATE INDEX IF NOT EXISTS idx_document_routes_tenant_template
                ON document_routes(tenant_id, template_id)'
        );
    }

    public static function down(Database $db): void
    {
        $db->exec('DROP INDEX IF EXISTS idx_document_routes_tenant_template');
        $db->exec('ALTER TABLE document_routes DROP COLUMN IF EXISTS template_name');
        $db->exec('ALTER TABLE document_routes DROP COLUMN IF EXISTS template_id');
    }
}
