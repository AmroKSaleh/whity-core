<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * PlaceDocumentDesignerRowsInOus — the WHERE coordinate for templates and blocks.
 *
 * THE GAP THIS CLOSES
 * -------------------
 * Migration 059 gave `document_templates` and `document_blocks` a `scope` and a
 * `required_permission`, and {@see \Whity\Core\Document\DocumentAccessPolicy}
 * enforces both. But `required_permission` is a FLAT, TENANT-WIDE answer: it can
 * say "only contract clerks may see this", and cannot say "only the Faculty of
 * Engineering's contract clerks".
 *
 * The requirement it cannot express, in the requester's words: *a secretary for
 * a dean might have access to templates and design blocks more than a secretary
 * of a department head*. Both secretaries hold `documents:write`. Under 059 they
 * therefore see exactly the same set, and no combination of permission tags
 * fixes that — the thing that differs between them is not WHAT KIND of person
 * they are, it is WHERE IN THE ORGANISATION they act. Nothing in the model said
 * where a template belongs, so nothing could discriminate.
 *
 * `owner_ou_id` is that missing coordinate, and only that. It is deliberately
 * NOT the access rule; see below.
 *
 * NULL MEANS TENANT-WIDE, AND EVERY EXISTING ROW IS NULL
 * -----------------------------------------------------
 * An unplaced row behaves exactly as it does today: visible to whoever passes
 * its `required_permission`, anywhere in the tenant. That is what makes this
 * migration a pure addition — no existing template changes audience, and an
 * installation that never places anything never notices the column. Placement
 * is opt-in, per row.
 *
 * It also means placing a row at the ROOT unit is NOT how you say "everyone".
 * NULL is. Reading a root placement as tenant-wide would collapse the two
 * meanings and leave the root unit unable to own templates of its own, which is
 * a thing a central registry office legitimately wants.
 *
 * WHY A COLUMN AND NOT A GRANT TABLE
 * ----------------------------------
 * To discriminate by place, the row must name a place — there is no way around
 * that, and a nullable integer is the whole of it. What must NOT follow is
 * reading the column as the answer to "who may see this", which would make
 * audience a function of tree shape: reparenting a department would silently
 * transfer every template filed beneath it to another faculty's people, and
 * "the dean's secretary also covers Materials Science" would be inexpressible
 * except by copying templates or moving units.
 *
 * So the column is the coordinate the authorization question is asked AT, and
 * the answer comes from `resource_role_assignments` (migration 088) — a role
 * granted to a profile at an OU, which is a durable, revocable statement about
 * PEOPLE that survives the tree being reshaped. No new grant table: a private
 * `document_template_access` table would be a second source of truth for the
 * same authorization question, which is the defect 088 was written to remove.
 *
 * ON DELETE SET NULL, WITH A GUARD IN FRONT OF IT
 * -----------------------------------------------
 * SET NULL is the house convention for a nullable OU reference (migrations 006,
 * 030, 096, 108, 112). Here it is the WRONG semantic on its own — a placed row
 * whose unit is deleted would silently become tenant-wide, i.e. a delete that
 * WIDENS visibility. {@see \Whity\Api\OusApiHandler::delete()} therefore refuses
 * to delete a unit that still holds placed templates or blocks (409), exactly as
 * it already refuses one with children or active members. The constraint stays
 * as the backstop for anything that deletes a unit by another route, where a
 * widened-but-present row beats a dangling pointer to a unit that no longer
 * exists.
 *
 * Idempotent (IF NOT EXISTS) and reversible.
 */
class PlaceDocumentDesignerRowsInOus
{
    public static function up(Database $db): void
    {
        // One literal statement per table rather than a loop over an interpolated
        // name: TenantOwnedTablesTest and the idempotency test derive their facts
        // by scanning this source, so the table names must appear literally.
        $db->exec(
            'ALTER TABLE document_templates
                 ADD COLUMN IF NOT EXISTS owner_ou_id INTEGER
                 REFERENCES organizational_units(id) ON DELETE SET NULL'
        );
        $db->exec(
            'ALTER TABLE document_blocks
                 ADD COLUMN IF NOT EXISTS owner_ou_id INTEGER
                 REFERENCES organizational_units(id) ON DELETE SET NULL'
        );

        // (tenant_id, owner_ou_id) rather than owner_ou_id alone: every read is
        // tenant-scoped first, so the tenant column belongs at the front of the
        // index for the same reason it is the first predicate of the query.
        $db->exec(
            'CREATE INDEX IF NOT EXISTS idx_document_templates_tenant_owner_ou
             ON document_templates(tenant_id, owner_ou_id)'
        );
        $db->exec(
            'CREATE INDEX IF NOT EXISTS idx_document_blocks_tenant_owner_ou
             ON document_blocks(tenant_id, owner_ou_id)'
        );
    }

    public static function down(Database $db): void
    {
        $db->exec('DROP INDEX IF EXISTS idx_document_blocks_tenant_owner_ou');
        $db->exec('DROP INDEX IF EXISTS idx_document_templates_tenant_owner_ou');
        $db->exec('ALTER TABLE document_blocks DROP COLUMN IF EXISTS owner_ou_id');
        $db->exec('ALTER TABLE document_templates DROP COLUMN IF EXISTS owner_ou_id');
    }
}
