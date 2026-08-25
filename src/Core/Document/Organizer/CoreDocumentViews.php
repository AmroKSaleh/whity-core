<?php

declare(strict_types=1);

namespace Whity\Core\Document\Organizer;

use Whity\Core\Document\Routing\RouteVerdict;

/**
 * The folders core ships (#978, implementing #947 item 5; completed here).
 *
 * Six were specified and all six are now here:
 *
 * | Folder                    | Computed from                        | Since |
 * |---------------------------|--------------------------------------|-------|
 * | Created by me             | `documents.created_by`               | #978  |
 * | Raised by my unit         | `documents.origin_ou_id`             | #978  |
 * | Everything below my unit  | `origin_ou_id` + the OU subtree      | #978  |
 * | Awaiting me               | `routing.recipients` (#947 item 3)   | here  |
 * | Acted on by me            | `routing.trail` (item 3)             | here  |
 * | Passed through my unit    | `routing.trail` + the OU subtree     | here  |
 *
 * #947 places the browser at item 5 "in parallel" with routing at item 3. Three
 * of its six folders are DERIVED FROM item 3, so they could not precede it; #978
 * corrected that sequencing by shipping the three that never needed routing and
 * REFUSING to render the three that did, on the rule that a folder which cannot
 * be computed must be absent rather than empty.
 *
 * WHAT THE ROUTING THREE COST, NOW THAT THEY ARE BUILT
 * ----------------------------------------------------
 * Exactly what #978 said they would, which is the reason to record it: three
 * slots on {@see DocumentCriteria}, three literal `EXISTS` fragments in
 * {@see \Whity\Core\Document\DocumentRepository}, and three registrations here.
 * Nothing in {@see DocumentViewRegistry}, {@see DocumentViewPresenter}, the API
 * handler or the rail changed to admit them — they appear because their
 * substrates resolve, which is what the seam was for.
 *
 * They were NOT registered earlier behind their substrate so as to "appear
 * automatically" when item 3 landed. A view registered behind a substrate that
 * already resolves is not filtered out; it appears and answers nothing, and an
 * empty "Awaiting me" asserts *"nothing awaits you"*, which a person cannot tell
 * apart from having nothing to do. The gate is availability, never intent, so a
 * folder becomes real when somebody writes its predicate and not a day before.
 *
 * That protection has not been spent by building them: each of the three still
 * declares the substrate it reads, so on an installation that has not run
 * migration 112 all three are absent from the rail and 404 on request, and the
 * two that read the trail go without the one that reads recipients. Removing the
 * routing tables is a test in DocumentViewRegistryTest and in the real-engine
 * suite, not a claim.
 *
 * Two more are here that #947 does not list, both backed by migration 114 and
 * both claims about the CALLER rather than about a document: `starred` and
 * `collection`. They are the honest half of a Drive-shaped browser — a label I
 * applied to a document says nothing about where it lives, which is exactly why
 * a stored folder tree is refused and these are not.
 *
 * WHY THE ROUTING THREE ARE `derived` AND NOT A GROUP OF THEIR OWN
 * -----------------------------------------------------------------
 * `derived` means a fact about the DOCUMENT and `personal` a fact about YOU, and
 * a recipient row is the first of those: the organisation recorded that this
 * document was sent to this person. "Awaiting me" reads like a personal folder
 * and is not one — I cannot put a document in it or take one out, which is the
 * property `starred` has and this does not.
 *
 * A third group ("Routing") was the tempting alternative and would read well in
 * the rail. It was rejected because the rail filters on the two group names it
 * knows and drops anything else, so a `routing` group would have made three
 * folders the server offers render nowhere at all — absent-versus-empty, one
 * layer up, arriving as a blank section. Adding a group is a client change, and
 * the point of this PR is that adding a FOLDER is not.
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
    public const AWAITING_ME = 'awaiting-me';
    public const ACTED_ON_BY_ME = 'acted-on-by-me';
    public const APPROVED_BY_ME = 'approved-by-me';
    public const REJECTED_BY_ME = 'rejected-by-me';
    public const PASSED_THROUGH_MY_UNIT = 'passed-through-my-unit';
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

        // ── #947 item 5's three routing folders ─────────────────────────────
        //
        // Placed after the provenance folders rather than at the head of the
        // rail. "Awaiting me" is the folder with a deadline attached and the
        // argument for putting it first is real, but the PRIMARY inbox is not
        // here: routing registers
        // {@see \Whity\Core\Document\Routing\DocumentRoutingInboxSource} with
        // #881's aggregation, and that is the surface a person opens to find
        // what is owed. This folder is the same rows seen from the browser, for
        // somebody already browsing, so it does not need to displace four
        // folders that shipped in order to assert an importance the rail cannot
        // express anyway.

        // THE INBOX. `awaitingProfileId` matches OPEN recipient rows only —
        // see DocumentCriteria: a closed row is something you already did, and a
        // folder that keeps listing those never empties.
        //
        // No `unanchored` branch and no parameter: this folder anchors on the
        // caller, who always exists. A person who has never been sent anything
        // gets an honest empty page, which is the #756 case and is checkable —
        // unlike "your unit has raised nothing" said to somebody with no unit.
        $registry->register(new DocumentView(
            self::AWAITING_ME,
            'Awaiting me',
            'Documents routed to you that you have not yet acted on.',
            self::GROUP_DERIVED,
            [CoreDocumentSubstrates::ROUTING_RECIPIENTS],
            [],
            static fn (DocumentViewContext $ctx): DocumentViewResolution => DocumentViewResolution::of(
                new DocumentCriteria(awaitingProfileId: $ctx->callerProfileId)
            ),
            50,
        ));

        // Everything you have ever done to a document, including what you have
        // since handed on. Deliberately NOT "awaiting me, inverted": a document
        // returned to you is both acted on by you and awaiting you, and a folder
        // that excluded the overlap would hide the one case where seeing both
        // matters.
        $registry->register(new DocumentView(
            self::ACTED_ON_BY_ME,
            'Acted on by me',
            'Documents whose routing trail records you as the actor — including ones you have passed on.',
            self::GROUP_DERIVED,
            [CoreDocumentSubstrates::ROUTING_TRAIL],
            [],
            static fn (DocumentViewContext $ctx): DocumentViewResolution => DocumentViewResolution::of(
                new DocumentCriteria(actedOnByProfileId: $ctx->callerProfileId)
            ),
            60,
        ));

        // ── #1014's two VERDICT folders ─────────────────────────────────────
        //
        // "Acted on by me" above is deliberately left alone and still means every
        // act, notes included. These two are not a replacement for it and not a
        // narrowing of it: a person looking for "the thing I approved last
        // Tuesday" and a person looking for "everything I have touched" are
        // asking different questions, and answering the second with the first is
        // what makes a folder stop being opened.
        //
        // TWO FOLDERS RATHER THAN ONE WITH A PARAMETER. A `verdict` parameter on
        // a single "decided by me" would put the difference behind a control, and
        // the difference is the whole point — approved and rejected are the two
        // states #1014 says a user will expect to tell apart at a glance. The
        // unit folders take a parameter because "my unit" and "that unit" are the
        // same question about a different subject; these are different questions.
        //
        // Both anchor on the caller and therefore need no `unanchored` branch: a
        // person who has approved nothing gets an honest empty page, which is
        // checkable, unlike "your unit has raised nothing" said to somebody with
        // no unit.
        $registry->register(new DocumentView(
            self::APPROVED_BY_ME,
            'Approved by me',
            'Documents you authorised at an approval step.',
            self::GROUP_DERIVED,
            [CoreDocumentSubstrates::ROUTING_VERDICT],
            [],
            static fn (DocumentViewContext $ctx): DocumentViewResolution => DocumentViewResolution::of(
                new DocumentCriteria(
                    verdictByProfileId: $ctx->callerProfileId,
                    verdict: RouteVerdict::APPROVED,
                )
            ),
            61,
        ));

        $registry->register(new DocumentView(
            self::REJECTED_BY_ME,
            'Rejected by me',
            'Documents you refused at an approval step.',
            self::GROUP_DERIVED,
            [CoreDocumentSubstrates::ROUTING_VERDICT],
            [],
            static fn (DocumentViewContext $ctx): DocumentViewResolution => DocumentViewResolution::of(
                new DocumentCriteria(
                    verdictByProfileId: $ctx->callerProfileId,
                    verdict: RouteVerdict::REJECTED,
                )
            ),
            62,
        ));

        // THE SUBTREE, not the single unit. "Passed through my unit" asked of a
        // faculty head means the faculty and its departments — the same reading
        // {@see \Whity\Core\Ou\OuSubtree} gives every other scope-of-authority
        // question in the platform, and the reason it says so in one place
        // rather than in each caller.
        //
        // The walk arrives as a closure with the tenant already bound, so this
        // view can ask what is beneath a unit and nothing else. It is the same
        // capability "everything below my unit" uses; there is deliberately no
        // second subtree implementation to disagree with the first.
        //
        // Unanchored for a caller in no unit, exactly as the two unit folders
        // are: an empty page would read as "nothing passed through my unit",
        // which is a statement about the unit rather than about the reader not
        // having one.
        $registry->register(new DocumentView(
            self::PASSED_THROUGH_MY_UNIT,
            'Passed through my unit',
            'Documents whose routing left or reached your unit, or any unit beneath it.',
            self::GROUP_DERIVED,
            // TWO substrates, and neither is `documents.origin_ou`. This folder
            // never reads `documents.origin_ou_id` — it reads the trail's own
            // unit columns — so declaring the origin substrate would make it
            // resolve, and fail to resolve, for a reason that has nothing to do
            // with it. What it does need beyond the trail is a HIERARCHY to
            // walk, which is its own fact and is declared as one.
            [CoreDocumentSubstrates::ROUTING_TRAIL, CoreDocumentSubstrates::OU_TREE],
            [['name' => 'ou_id', 'required' => false]],
            static function (DocumentViewContext $ctx): DocumentViewResolution {
                $anchor = $ctx->effectiveOuId();
                if ($anchor === null) {
                    return DocumentViewResolution::unanchored(
                        'You do not belong to an organizational unit. Select one to use this folder.'
                    );
                }

                return DocumentViewResolution::of(
                    new DocumentCriteria(routedThroughOuIds: $ctx->ouSubtree($anchor))
                );
            },
            70,
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
            // 80/90 rather than 50/60: the routing folders took the derived
            // group's next three slots, and the personal ones move down to stay
            // behind them. Renumbering rather than squeezing the new folders in
            // at 45/46/47 because these values are internal — they are not on
            // the wire and no client reads them — so the readable numbering is
            // free, and a rail whose order depends on decimals nobody can
            // explain is how the next author picks a number that collides.
            80,
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
            90,
        ));
    }
}
