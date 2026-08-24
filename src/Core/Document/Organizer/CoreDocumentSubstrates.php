<?php

declare(strict_types=1);

namespace Whity\Core\Document\Organizer;

/**
 * The fact sources core knows about, declared in one place (#978).
 *
 * Every entry is declared at COLUMN granularity wherever a column is what
 * matters, because `documents` and `document_route_events` exist on any migrated
 * installation and a table-level declaration would resolve for the wrong reason
 * — reporting a fact source present because its TABLE is, while the column the
 * folder actually reads is not there.
 *
 * THE ROUTING ONES WERE DECLARED A RELEASE BEFORE ANY VIEW READ THEM
 * ------------------------------------------------------------------
 * {@see ROUTING_RECIPIENTS} and {@see ROUTING_TRAIL} were registered by #978
 * while #947 item 3's tables existed and no folder was computed from them, on
 * the principle that a resolvable substrate is a FACT SOURCE and not a folder.
 * Their three folders needed predicates on {@see DocumentCriteria} and
 * registrations in {@see CoreDocumentViews}, and item 3's arrival did not
 * conjure those; registering the views ahead of their predicates would have been
 * a stub with a label, live the day the substrate resolved.
 *
 * Those three folders now exist. What has NOT changed is the rule — a view is
 * absent unless everything it names resolves — and this file is still where the
 * naming is measured rather than asserted.
 *
 * Declaring the substrates early was worth it for one reason: it is the half
 * that could be verified before the views existed. The real-engine test asserts
 * these keys resolve against the real schema, so a rename of
 * `document_route_recipients` fails a build instead of silently emptying a
 * folder — which it would now do to somebody's inbox.
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
     * There is an organizational HIERARCHY to walk (migration 030) — the fact
     * behind every "and everything beneath it" folder.
     *
     * Declared separately from {@see ORIGIN_OU} even though that one already
     * names `organizational_units`, because the two are different claims and one
     * folder needs the tree without needing the origin column at all: "passed
     * through my unit" reads `document_route_events`, not `documents`. Folding
     * the tree into the origin substrate a second time would make that folder
     * disappear whenever `documents.origin_ou_id` did, for no reason a reader
     * could reconstruct, and would report the wrong missing thing in the
     * operator-facing diagnostic.
     *
     * ORIGIN_OU is deliberately NOT narrowed to match. It ships as #978 wrote
     * it, and the only installation the two declarations could disagree about is
     * one with an `organizational_units` table and no `parent_id` on it, which
     * no migration produces.
     */
    public const OU_TREE = 'ou.tree';

    /**
     * Recipient rows: who a document is currently awaiting (#947 item 3,
     * migration 112). The substrate behind {@see CoreDocumentViews::AWAITING_ME}.
     */
    public const ROUTING_RECIPIENTS = 'routing.recipients';

    /**
     * The append-only routing trail: who acted, and between which units
     * (#947 item 3, migration 112). The substrate behind "acted on by me" and
     * "passed through my unit" — two folders, one fact source, which is why
     * this is separate from {@see ROUTING_RECIPIENTS} rather than one coarse
     * `routing.engine` covering both.
     */
    public const ROUTING_TRAIL = 'routing.trail';

    /**
     * The trail records a VERDICT as well as an act (#1014, migration 119). The
     * substrate behind "approved by me" and "rejected by me".
     *
     * Declared SEPARATELY from {@see ROUTING_TRAIL} rather than folded into it,
     * and the reason is the one that folder already states about the OU tree: a
     * deployment that has migration 112 but not 118 has a perfectly working
     * trail, and "acted on by me" and "passed through my unit" must keep
     * resolving there. Adding `verdict` to the trail substrate would make those
     * two folders disappear for a reason that has nothing to do with them, and
     * would name the wrong missing thing in the operator-facing diagnostic.
     */
    public const ROUTING_VERDICT = 'routing.verdict';

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

        // The trail needs `document_route_events` AND both units columns on it:
        // "passed through my unit" is a query over `from_ou_id` OR `to_ou_id` —
        // migration 112 records a TRANSITION, and a unit that only ever received
        // is invisible to a `from`-only reading — while a table-only declaration
        // would resolve on a trail that recorded no unit at all, which is a
        // folder that runs and answers nothing.
        $registry->register(new DocumentSubstrate(
            self::ROUTING_TRAIL,
            'Routing keeps an append-only trail of who acted on a document and between which units.',
            [
                'document_route_events.actor_profile_id',
                'document_route_events.from_ou_id',
                'document_route_events.to_ou_id',
            ],
            '#947 item 3 (migration 112)',
        ));

        $registry->register(new DocumentSubstrate(
            self::ROUTING_VERDICT,
            'Routing records whether an act approved or rejected the document, not only that it happened.',
            ['document_route_events.verdict'],
            '#1014 (migration 119)',
        ));

        // The downward walk reads `parent_id`, so that is what is measured. A
        // bare `organizational_units` would resolve on a flat table and leave
        // "passed through my unit" answering about one unit while claiming to
        // answer about a subtree.
        $registry->register(new DocumentSubstrate(
            self::OU_TREE,
            'Organizational units form a hierarchy that can be walked downward.',
            ['organizational_units.parent_id'],
            'migration 030',
        ));
    }
}
