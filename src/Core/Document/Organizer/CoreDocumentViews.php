<?php

declare(strict_types=1);

namespace Whity\Core\Document\Organizer;

/**
 * The folders core ships (#978, implementing #947 item 5).
 *
 * Six were specified. THREE are here, and the missing three are missing on
 * purpose:
 *
 * | Folder                    | Computed from                        | Here? |
 * |---------------------------|--------------------------------------|-------|
 * | Created by me             | `documents.created_by`               | yes   |
 * | Raised by my unit         | `documents.origin_ou_id`             | yes   |
 * | Everything below my unit  | `origin_ou_id` + the OU subtree      | yes   |
 * | Awaiting me               | `routing.recipients` (#947 item 3)   | no    |
 * | Acted on by me            | `routing.trail` (item 3)             | no    |
 * | Passed through my unit    | `routing.trail` (item 3)             | no    |
 *
 * #947 places the browser at item 5 "in parallel" with routing at item 3. Three
 * of its six folders are DERIVED FROM item 3, so they cannot precede it; #978
 * corrects that sequencing.
 *
 * WHAT ITEM 3 LANDING DID AND DID NOT CHANGE
 * ------------------------------------------
 * It shipped the facts, so {@see CoreDocumentSubstrates::ROUTING_RECIPIENTS}
 * and {@see CoreDocumentSubstrates::ROUTING_TRAIL} both resolve now. The three
 * folders are still absent, because a resolvable substrate is a FACT SOURCE and
 * not a view: each needs a predicate on {@see DocumentCriteria} — the criteria
 * vocabulary has no recipient or trail slot — and a registration in this file.
 *
 * They are deliberately not registered ahead of those predicates. A view
 * registered behind a substrate that already resolves is not filtered out; it
 * appears, and answers nothing, and an empty "Awaiting me" asserts *"nothing
 * awaits you"*, which a person cannot tell apart from having nothing to do.
 * Registering them while the substrate was still absent would have been no
 * better — a stub with a label, live the moment the substrate resolved, which
 * is exactly what happened to the substrate the day item 3 merged.
 *
 * Two more are here that #947 does not list, both backed by migration 114 and
 * both claims about the CALLER rather than about a document: `starred` and
 * `collection`. They are the honest half of a Drive-shaped browser — a label I
 * applied to a document says nothing about where it lives, which is exactly why
 * a stored folder tree is refused and these are not.
 *
 * WHY "ALL DOCUMENTS" IS A REGISTERED VIEW AND NOT THE ABSENCE OF ONE
 * -------------------------------------------------------------------
 * It would be simpler for `?view=` to be optional and mean "everything". Making
 * the unfiltered list a view instead means the rail is uniform (every entry the
 * client renders comes from one response), the default is NAMED on the wire
 * rather than implied by an omission, and a deployment that wanted to drop it
 * could. The list route still defaults to it when `view` is absent, so no
 * existing caller changes.
 */
final class CoreDocumentViews
{
    public const ALL = 'all';
    public const CREATED_BY_ME = 'created-by-me';
    public const RAISED_BY_MY_UNIT = 'raised-by-my-unit';
    public const BELOW_MY_UNIT = 'below-my-unit';
    public const STARRED = 'starred';
    public const COLLECTION = 'collection';

    /** Rail sections. `derived` is a fact about the document; `personal` is a fact about you. */
    public const GROUP_DERIVED = 'derived';
    public const GROUP_PERSONAL = 'personal';

    private function __construct()
    {
    }

    public static function registerInto(DocumentViewRegistry $registry): void
    {
        $registry->register(new DocumentView(
            self::ALL,
            'All documents',
            'Every document you can see in this tenant, newest first.',
            self::GROUP_DERIVED,
            [CoreDocumentSubstrates::DOCUMENT_RECORDS],
            [],
            static fn (DocumentViewContext $ctx): DocumentViewResolution
                => DocumentViewResolution::of(DocumentCriteria::unfiltered()),
            10,
        ));

        $registry->register(new DocumentView(
            self::CREATED_BY_ME,
            'Created by me',
            'Documents you raised.',
            self::GROUP_DERIVED,
            [CoreDocumentSubstrates::AUTHORSHIP],
            [],
            static fn (DocumentViewContext $ctx): DocumentViewResolution
                => DocumentViewResolution::of(new DocumentCriteria(createdBy: $ctx->callerProfileId)),
            20,
        ));

        // `ou_id` is OPTIONAL on both unit folders: without it they mean "my
        // unit", with it they mean the unit picked in the `ouScopePicker`. One
        // view with an optional anchor rather than two ("my unit" and "a unit")
        // — the query is identical and the second would exist only to say the
        // anchor came from somewhere else.
        //
        // A caller in no unit gets `unanchored`, not an empty page: "raised by
        // my unit" returning nothing would be read as "my unit has raised
        // nothing", which is a statement about the unit rather than about the
        // reader not having one. See DocumentViewResolution.
        $registry->register(new DocumentView(
            self::RAISED_BY_MY_UNIT,
            'Raised by my unit',
            'Documents raised from your own unit — or from a unit you select.',
            self::GROUP_DERIVED,
            [CoreDocumentSubstrates::ORIGIN_OU],
            [['name' => 'ou_id', 'required' => false]],
            static function (DocumentViewContext $ctx): DocumentViewResolution {
                $anchor = $ctx->effectiveOuId();
                if ($anchor === null) {
                    return DocumentViewResolution::unanchored(
                        'You do not belong to an organizational unit. Select one to use this folder.'
                    );
                }

                return DocumentViewResolution::of(new DocumentCriteria(originOuIds: [$anchor]));
            },
            30,
        ));

        $registry->register(new DocumentView(
            self::BELOW_MY_UNIT,
            'Everything below my unit',
            'Documents raised from your unit or any unit beneath it in the hierarchy.',
            self::GROUP_DERIVED,
            [CoreDocumentSubstrates::ORIGIN_OU],
            [['name' => 'ou_id', 'required' => false]],
            static function (DocumentViewContext $ctx): DocumentViewResolution {
                $anchor = $ctx->effectiveOuId();
                if ($anchor === null) {
                    return DocumentViewResolution::unanchored(
                        'You do not belong to an organizational unit. Select one to use this folder.'
                    );
                }

                return DocumentViewResolution::of(
                    new DocumentCriteria(originOuIds: $ctx->ouSubtree($anchor))
                );
            },
            40,
        ));

        // A starred pile that does not exist yet resolves to an honestly EMPTY
        // result rather than to `unanchored`. The difference from "raised by my
        // unit" with no unit is that this claim is checkable by the person
        // reading it: they know whether they have starred anything, and the
        // control that would change it is on every row of the table beside it.
        $registry->register(new DocumentView(
            self::STARRED,
            'Starred',
            'Documents you starred. Starring is a collection with a well-known name, not a separate mark.',
            self::GROUP_PERSONAL,
            [CoreDocumentSubstrates::COLLECTIONS],
            [],
            static fn (DocumentViewContext $ctx): DocumentViewResolution => DocumentViewResolution::of(
                $ctx->starredCollectionId === null
                    ? DocumentCriteria::nothing()
                    : new DocumentCriteria(inCollectionId: $ctx->starredCollectionId)
            ),
            50,
        ));

        // `collection_id` is REQUIRED, so this view is a template the client
        // instantiates once per collection in the rail rather than a folder of
        // its own. Opening it without an id is a client error (400) — a
        // different statement from "you cannot open collections", and the only
        // one that is true.
        //
        // Ownership of the id is established BEFORE resolution, in the handler:
        // a collection is looked up by (tenant, profile, id), so another
        // person's id is not found rather than refused.
        $registry->register(new DocumentView(
            self::COLLECTION,
            'Collection',
            'Documents you filed into one of your own collections.',
            self::GROUP_PERSONAL,
            [CoreDocumentSubstrates::COLLECTIONS],
            [['name' => 'collection_id', 'required' => true]],
            static fn (DocumentViewContext $ctx): DocumentViewResolution => DocumentViewResolution::of(
                $ctx->collectionId === null
                    ? DocumentCriteria::nothing()
                    : new DocumentCriteria(inCollectionId: $ctx->collectionId)
            ),
            60,
        ));
    }
}
