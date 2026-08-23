<?php

declare(strict_types=1);

namespace Whity\Core\Document\Organizer;

/**
 * The fact sources core knows about, declared in one place (#978).
 *
 * Two kinds of entry live here and the difference is the point:
 *
 *  - PRESENT ones, backed by columns migration 108 and 111 create. Each is
 *    declared at COLUMN granularity where the column is what matters, because
 *    `documents` will still exist when routing ships and a table-level
 *    declaration would resolve for the wrong reason.
 *
 *  - The ROUTING ones, {@see ROUTING_RECIPIENTS} and {@see ROUTING_TRAIL}, which
 *    #947 item 3 (migration 112) supplies. They resolve on any installation that
 *    has run it — and NO VIEW READS THEM YET, which is the distinction worth
 *    holding on to: a resolvable substrate is a fact source, not a folder.
 *
 *    Item 5's three routing folders — "awaiting me", "acted on by me", "passed
 *    through my unit" — each still need a predicate on {@see DocumentCriteria}
 *    and a registration in {@see CoreDocumentViews}. Item 3's arrival does not
 *    conjure them, and registering them ahead of their predicates would be a
 *    stub with a label: see {@see DocumentViewRegistry}.
 *
 * WHY THEY ARE DECLARED AT ALL BEFORE ANY VIEW READS THEM
 * ------------------------------------------------------
 * So the follow-up is a view registration and nothing else. The alternative —
 * declare the substrate in the same change that adds the views — is fine too,
 * and this is here because it is the half that can be verified NOW: the
 * real-engine test asserts these keys resolve against the real schema, so a
 * later rename of `document_route_recipients` fails a build instead of silently
 * emptying a folder.
 *
 * THE TABLE NAMES ARE THE REAL ONES, AND THAT IS THE POINT
 * -------------------------------------------------------
 * An earlier draft of this file, written before item 3 landed, required
 * `routes`, `route_steps` and `recipients` — the names #947's prose used. The
 * shipped tables are `document_routes`, `document_route_steps`,
 * `document_route_events` and `document_route_recipients`, so that declaration
 * would have reported the substrate ABSENT for ever, and absent is the answer
 * that hides folders. It read as correct and cautious and was neither, which is
 * the argument for measuring a declaration against the schema
 * ({@see SchemaPresence}) rather than trusting whoever wrote it — including
 * when that is this file.
 */
final class CoreDocumentSubstrates
{
    /** Issued documents exist at all — the table migration 108 creates. */
    public const DOCUMENT_RECORDS = 'documents.records';

    /** Who raised a document (`documents.created_by`). */
    public const AUTHORSHIP = 'documents.authorship';

    /** Which unit a document was raised FROM, plus the tree to walk it with. */
    public const ORIGIN_OU = 'documents.origin_ou';

    /** A person's own filing of documents (migration 114). */
    public const COLLECTIONS = 'documents.collections';

    /**
     * Recipient rows: who a document is currently awaiting (#947 item 3,
     * migration 112). The substrate behind an "awaiting me" folder.
     */
    public const ROUTING_RECIPIENTS = 'routing.recipients';

    /**
     * The append-only routing trail: who acted, and which unit they acted from
     * (#947 item 3, migration 112). The substrate behind "acted on by me" and
     * "passed through my unit" — two folders, one fact source, which is why
     * this is separate from {@see ROUTING_RECIPIENTS} rather than one coarse
     * `routing.engine` covering both.
     */
    public const ROUTING_TRAIL = 'routing.trail';

    private function __construct()
    {
    }

    public static function registerInto(DocumentSubstrateRegistry $registry): void
    {
        $registry->register(new DocumentSubstrate(
            self::DOCUMENT_RECORDS,
            'Issued documents are persisted as records rather than re-rendered on demand.',
            ['documents'],
            '#947 item 1 (migration 108)',
        ));

        $registry->register(new DocumentSubstrate(
            self::AUTHORSHIP,
            'Each document records the profile that raised it.',
            ['documents.created_by'],
            '#947 item 1 (migration 108)',
        ));

        // `organizational_units` as well as the column: the subtree folder walks
        // the tree, and a deployment with documents but no OU table would
        // otherwise offer a folder whose walk has nothing to walk.
        $registry->register(new DocumentSubstrate(
            self::ORIGIN_OU,
            'Each document records the organizational unit it was raised from, captured at issue time.',
            ['documents.origin_ou_id', 'organizational_units'],
            '#947 item 1 (migration 108)',
        ));

        $registry->register(new DocumentSubstrate(
            self::COLLECTIONS,
            'Each person can file documents into their own collections, including a starred one.',
            ['document_collections', 'document_collection_items'],
            '#978 (migration 114)',
        ));

        $registry->register(new DocumentSubstrate(
            self::ROUTING_RECIPIENTS,
            'Routing records who a document is currently awaiting.',
            ['document_route_recipients.profile_id', 'document_route_recipients.document_id'],
            '#947 item 3 (migration 112)',
        ));

        // The trail needs `document_route_events` AND the units column on it:
        // "passed through my unit" is a query over `from_ou_id` specifically, and
        // a table-only declaration would resolve on a trail that did not record
        // the unit — which is a folder that runs and answers nothing.
        $registry->register(new DocumentSubstrate(
            self::ROUTING_TRAIL,
            'Routing keeps an append-only trail of who acted on a document and which unit they '
                . 'acted from.',
            ['document_route_events.actor_profile_id', 'document_route_events.from_ou_id'],
            '#947 item 3 (migration 112)',
        ));
    }
}
