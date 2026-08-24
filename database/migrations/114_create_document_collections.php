<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * CreateDocumentCollections (#978, implementing #947 item 5) — the ONE piece of
 * the document organizer that is stored, and the only claim it makes is about a
 * person rather than about a document.
 *
 * WHY THERE IS NO FOLDER TABLE HERE
 * ---------------------------------
 * The obvious migration for a Drive-shaped browser is `document_folders` with a
 * `parent_id`, and it is the wrong one. A document raised centrally and needed
 * by fifteen units has no single home, so a stored tree forces one of three
 * answers and all three are bad: duplicate the row fifteen times (fifteen
 * things that can diverge), file it once and make it undiscoverable from the
 * other fourteen, or add a `shortcuts` table — which is the same admission
 * written twice. A stored tree also has to be MAINTAINED as the organisation
 * changes, which is the failure mode #947 already rejects for stored recipient
 * lists and migration 108 rejects for a `status` column.
 *
 * So the organizer's folders are DERIVED — "raised by my unit" is a subtree
 * query over `documents.origin_ou_id`, "created by me" is a predicate on
 * `created_by`, and neither can go stale because neither is written down.
 * Drive works the same way once you look: *Shared with me*, *Recent* and
 * *Starred* are a query or a label, not a container.
 *
 * WHAT IS LEFT, AND WHY IT IS HONEST TO STORE IT
 * ----------------------------------------------
 * A user's own organization. "I put this in my Q3 audit pile" is a fact about
 * ME. It is not derivable from anything — nobody else can compute it, and no
 * later feature can supersede it — and, crucially, it asserts nothing about
 * where the document lives, who owns it or who else should see it. Two people
 * can file the same document in incompatible ways and both be right. That is
 * the whole test a stored folder tree fails.
 *
 * STARRING IS A COLLECTION, NOT A SECOND CONCEPT
 * ----------------------------------------------
 * There is no `document_stars` table, deliberately. A star is a collection
 * whose `system_key` is 'starred': same shape (this person, this document,
 * when), same lifecycle, same tenant scoping, one set of SQL.
 *
 * The rejected alternative — a dedicated table — is worth naming because it
 * looks cheaper and is not. It duplicates every column here, and then the two
 * diverge: "documents I have filed anywhere" becomes a UNION, "is this starred
 * AND in Q3 audit" becomes two round trips, and the per-page badge fetch that
 * is one `WHERE document_id IN (...)` becomes two. It also decides, for good,
 * that a star can never be renamed, translated, or joined by a second
 * well-known label without a third table.
 *
 * `system_key` rather than a reserved NAME is what makes that work: the star
 * affordance addresses the collection by KEY, so the row's display name is free
 * to change without the star icon losing its target. The API refuses rename and
 * delete on a keyed collection for the same reason — see
 * {@see \Whity\Api\DocumentCollectionsApiHandler}. It is created lazily on the
 * first star rather than seeded per profile: seeding would write a row for
 * every member of every tenant to record something nobody has done yet.
 *
 * FOREIGN KEYS, AND WHY `profile_id` CASCADES WHERE `documents.created_by` DOES NOT
 * ---------------------------------------------------------------------------------
 * Every `*_id` column here carries a real constraint. #751 landed because two
 * core tables named a profile with none, and scripts/ci-undeclared-reference-guard.php
 * now lints core's own migrations for exactly that.
 *
 *  - `tenant_id → tenants ON DELETE CASCADE`. What every tenant-owned table does.
 *
 *  - `profile_id → profiles ON DELETE CASCADE` — and this DISAGREES with
 *    `documents.created_by`, which migration 108 made ON DELETE SET NULL, on
 *    purpose. That column records who raised an ORGANISATIONAL record: an
 *    invoice raised by an employee who has since left is still the
 *    organisation's invoice, so the row must outlive the pointer. A collection
 *    is the opposite kind of thing all the way down — it is one person's
 *    private filing of documents that are not going anywhere, it is invisible
 *    to everyone else, and with its owner gone it means nothing to anybody.
 *    SET NULL here would leave an ownerless pile that no query can reach and no
 *    screen can show, which is a record of that person's reading habits kept
 *    for nobody, dressed as data retention.
 *
 *  - `collection_id → document_collections ON DELETE CASCADE`. An item is
 *    meaningless without its collection; nothing else names it.
 *
 *  - `document_id → documents ON DELETE CASCADE`. A collection holds POINTERS.
 *    When the document goes the pointer goes — the alternative is a row that
 *    renders as a gap in somebody's pile with nothing to say about it.
 *
 * MEMBERSHIP IS NEVER A CAPABILITY
 * --------------------------------
 * A row here does NOT mean its owner may still read the document. Visibility
 * can be narrowed after a document is filed, so every read through a collection
 * re-applies {@see \Whity\Core\Document\DocumentVisibilityPolicy} exactly as
 * the plain list does — the join is a filter, never a grant. `tenant_id` is
 * denormalised onto BOTH tables so the CI tenant-predicate guard can police
 * that directly instead of trusting a join, the same trade `document_artifacts`
 * (migration 108), `entity_tags` and `notification_deliveries` already make.
 *
 * Idempotent (IF NOT EXISTS) and reversible via down().
 */
class CreateDocumentCollections
{
    public static function up(Database $db): void
    {
        // NOTE: one literal create-table statement per table, not a loop over
        // interpolated names — TenantOwnedTablesTest and CoreTablesTest
        // re-derive their registries by SCANNING this source, so each name must
        // appear literally. Migrations 059 and 108 carry the same note, and
        // spell the keyword in lowercase in prose for the same reason:
        // MigrationSchemaTest would read a capitalised one in a comment as a
        // real table declaration.
        //
        // `name` is 160 rather than 255: it is a rail label a person types, and
        // the width that fits one is the width worth storing. The API rejects
        // longer, so the column and the validator agree.
        //
        // UNIQUE (tenant_id, profile_id, name) — two piles called "Q3 audit" in
        // one person's rail are indistinguishable to the person who made them.
        // Scoped to the profile rather than the tenant: my "Q3 audit" and yours
        // are different piles and neither should block the other.
        //
        // UNIQUE (tenant_id, profile_id, system_key) — at most one starred
        // collection per person per tenant. NULL is distinct from NULL in a
        // unique constraint on both engines, so ordinary user collections are
        // unaffected by it.
        $db->exec("
            CREATE TABLE IF NOT EXISTS document_collections (
                id         BIGSERIAL     NOT NULL PRIMARY KEY,
                tenant_id  INTEGER       NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                profile_id INTEGER       NOT NULL REFERENCES profiles(id) ON DELETE CASCADE,
                name       VARCHAR(160)  NOT NULL,
                system_key VARCHAR(32),
                created_at TIMESTAMP     NOT NULL DEFAULT NOW(),
                UNIQUE (tenant_id, profile_id, name),
                UNIQUE (tenant_id, profile_id, system_key)
            )
        ");

        // The rail: "my collections in this tenant".
        $db->exec(
            'CREATE INDEX IF NOT EXISTS idx_document_collections_tenant_profile
                ON document_collections(tenant_id, profile_id)'
        );

        $db->exec("
            CREATE TABLE IF NOT EXISTS document_collection_items (
                id            BIGSERIAL NOT NULL PRIMARY KEY,
                tenant_id     INTEGER   NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                collection_id BIGINT    NOT NULL REFERENCES document_collections(id) ON DELETE CASCADE,
                document_id   BIGINT    NOT NULL REFERENCES documents(id) ON DELETE CASCADE,
                added_at      TIMESTAMP NOT NULL DEFAULT NOW(),
                UNIQUE (collection_id, document_id)
            )
        ");

        // The two reads that exist, both entered through the tenant:
        //  - "the documents in this collection" (the collection view's join), and
        //  - "which of the documents on THIS page have I filed, and where" —
        //    one `document_id IN (...)` per page rather than one query per row.
        $db->exec(
            'CREATE INDEX IF NOT EXISTS idx_document_collection_items_tenant_collection
                ON document_collection_items(tenant_id, collection_id, document_id)'
        );
        $db->exec(
            'CREATE INDEX IF NOT EXISTS idx_document_collection_items_tenant_document
                ON document_collection_items(tenant_id, document_id)'
        );
    }

    public static function down(Database $db): void
    {
        // Children first: `document_collection_items` names `document_collections`.
        // CASCADE on the DROP covers it on PostgreSQL, but SQLite (the
        // test-schema engine) has no such clause, and ordering costs nothing.
        $db->exec('DROP INDEX IF EXISTS idx_document_collection_items_tenant_document');
        $db->exec('DROP INDEX IF EXISTS idx_document_collection_items_tenant_collection');
        $db->exec('DROP TABLE IF EXISTS document_collection_items CASCADE');

        $db->exec('DROP INDEX IF EXISTS idx_document_collections_tenant_profile');
        $db->exec('DROP TABLE IF EXISTS document_collections CASCADE');
    }
}
