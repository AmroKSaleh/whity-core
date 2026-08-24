<?php

declare(strict_types=1);

namespace Whity\Core\Document\Organizer;

/**
 * What a document list is filtered by (#978) — a CLOSED vocabulary that
 * {@see \Whity\Core\Document\DocumentRepository} knows how to turn into literal
 * SQL.
 *
 * WHY A VALUE OBJECT AND NOT AN SQL FRAGMENT
 * ------------------------------------------
 * The obvious way to make views extensible is to let each view supply its own
 * `AND …` fragment and bindings. It is more powerful and it was rejected, for a
 * reason that is specific to this codebase rather than general taste:
 * scripts/ci-tenant-predicate-guard.php verifies the platform's #1 isolation
 * invariant by READING LITERAL SQL out of the source. A predicate assembled
 * outside the repository — worse, one supplied by a plugin — is exactly the
 * statement CI cannot police, and "the document browser" is a poor place to
 * open the one hole in cross-tenant isolation.
 *
 * So the vocabulary is closed and every fragment that consumes it is a literal
 * in one file. The cost is real and worth stating: a future view whose
 * predicate is not expressible here needs a slot added to this class and a
 * matching literal fragment in the repository. That is roughly three lines, and
 * — this is the part that matters — the registry, the API surface, the
 * presenter, the capability gating and the entire UI are untouched by it. The
 * seam that has to stay open is AVAILABILITY, not SQL; see
 * {@see DocumentViewRegistry}.
 *
 * THE ROUTING SLOTS PAID THAT PRICE, AND IT WAS THE QUOTED ONE
 * ------------------------------------------------------------
 * #947 item 5's three routing folders arrived exactly as costed above: three
 * slots here, three literal `EXISTS` fragments in the repository, and not one
 * line changed in the registry, the presenter, the handler or the rail. That is
 * the evidence for the trade rather than an argument for it — the alternative,
 * a view handing down its own `AND …`, would have saved these three properties
 * and given up the only mechanical check the platform has on cross-tenant
 * isolation.
 *
 * {@see $awaitingProfileId} carries the one semantic worth stating twice: it
 * matches OPEN recipient rows only. A closed row is a thing you already did, and
 * an inbox that also lists those never empties. See its note below.
 *
 * TWO CREATOR SLOTS, DELIBERATELY
 * -------------------------------
 * `restrictToCreator` is the VISIBILITY answer from
 * {@see \Whity\Core\Document\DocumentVisibilityPolicy}; `createdBy` is what a
 * VIEW asked for. They are usually the same value and they are not the same
 * thing: collapsing them would let a view widen a visibility restriction by
 * overwriting it, which is a permission bug wearing a filter's clothes. Both
 * are ANDed.
 *
 * Immutable — worker-safe.
 */
final class DocumentCriteria
{
    /**
     * @param int|null       $restrictToCreator Visibility: only documents this profile raised.
     *                                          Null when the caller holds `documents:read:all`.
     * @param int|null       $createdBy         View filter: documents this profile raised.
     * @param list<int>|null $originOuIds       View filter: documents raised from one of these units.
     *                                          Null means no unit constraint.
     * @param int|null       $inCollectionId    View filter: documents filed in this collection.
     *                                          The caller's ownership of it is established BEFORE
     *                                          it reaches here — this is a join, never a grant.
     * @param int|null       $awaitingProfileId View filter: documents with an OPEN routing recipient
     *                                          row addressed to this profile — the inbox.
     *
     *                                          OPEN is the whole content of this slot. A recipient
     *                                          row holds no status of its own; migration 112 gives
     *                                          it `closed_by_event_id`, NULL while the item is
     *                                          outstanding and a trail row id once somebody acted.
     *                                          Matching every row instead of the open ones produces
     *                                          an inbox that never empties, which is a worse answer
     *                                          than no inbox: it is wrong in the direction that
     *                                          looks like work.
     * @param int|null       $actedOnByProfileId View filter: documents whose routing trail records
     *                                          this profile as the ACTOR of some event. Deliberately
     *                                          the complement of the slot above rather than its
     *                                          inverse — a document may be both awaiting you and
     *                                          already acted on by you, because a return puts back
     *                                          in your inbox something you once forwarded.
     * @param int|null       $verdictByProfileId View filter: documents whose trail records this
     *                                          profile as having reached a VERDICT (#1014). Paired
     *                                          with {@see $verdict}; both or neither.
     * @param string|null    $verdict           Which verdict the slot above is asking about —
     *                                          `approved` or `rejected`.
     *
     *                                          TWO SLOTS RATHER THAN TWO BOOLEANS, so that the
     *                                          predicate is one indexed EXISTS either way and a
     *                                          third verdict (should one ever exist) needs no new
     *                                          field. And deliberately NOT a narrowing of
     *                                          {@see $actedOnByProfileId}: "acted on by me" keeps
     *                                          meaning every act, notes included, because a folder
     *                                          of "things you did, except the kind we decided did
     *                                          not count" is a folder people stop trusting.
     * @param list<int>|null $routedThroughOuIds View filter: documents whose trail records an event
     *                                          leaving OR arriving at one of these units. Null means
     *                                          no constraint; an EMPTY list means nothing matches,
     *                                          the same reading {@see $originOuIds} carries.
     *
     *                                          BOTH ENDS, because migration 112 records a transition:
     *                                          `from_ou_id` is where the actor acted from and
     *                                          `to_ou_id` is where the act was directed. A unit that
     *                                          only ever received would be invisible to a `from`-only
     *                                          predicate, and a folder called "passed through my
     *                                          unit" that omits what arrived there is a folder whose
     *                                          name is a lie.
     * @param string|null    $search            Case-insensitive substring of the title.
     * @param bool           $matchesNothing    A view that resolved to an empty set (an anchor with
     *                                          no members, a starred pile that does not exist yet).
     *                                          Distinct from "no filter": one is an honest empty
     *                                          answer, the other is every document in the tenant.
     */
    public function __construct(
        public readonly ?int $restrictToCreator = null,
        public readonly ?int $createdBy = null,
        public readonly ?array $originOuIds = null,
        public readonly ?int $inCollectionId = null,
        public readonly ?int $awaitingProfileId = null,
        public readonly ?int $actedOnByProfileId = null,
        public readonly ?int $verdictByProfileId = null,
        public readonly ?string $verdict = null,
        public readonly ?array $routedThroughOuIds = null,
        public readonly ?string $search = null,
        public readonly bool $matchesNothing = false,
    ) {
    }

    /** No view filter at all — every document the caller may see. */
    public static function unfiltered(): self
    {
        return new self();
    }

    /** A view that can only ever return nothing, said out loud rather than by an empty `IN ()`. */
    public static function nothing(): self
    {
        return new self(matchesNothing: true);
    }

    /**
     * Return a copy carrying the visibility restriction and the search term the
     * REQUEST supplied.
     *
     * Applied after a view resolves rather than before, so a view can never
     * see — let alone drop — the restriction it is being narrowed by.
     *
     * Every view slot is re-stated here, and a slot ADDED to this class and
     * forgotten here is the failure worth naming: it would be dropped on the way
     * to the repository, and the folder it belongs to would silently widen from
     * "awaiting me" to the whole tenant — a folder that is wrong while looking
     * busy. Nothing in the language catches that, so DocumentViewRegistryTest
     * pins it: the test resolves every registered core view, pushes each result
     * through this method, and asserts nothing was lost.
     */
    public function withRequestScope(?int $restrictToCreator, ?string $search): self
    {
        return new self(
            restrictToCreator: $restrictToCreator,
            createdBy: $this->createdBy,
            originOuIds: $this->originOuIds,
            inCollectionId: $this->inCollectionId,
            awaitingProfileId: $this->awaitingProfileId,
            actedOnByProfileId: $this->actedOnByProfileId,
            verdictByProfileId: $this->verdictByProfileId,
            verdict: $this->verdict,
            routedThroughOuIds: $this->routedThroughOuIds,
            search: $search,
            matchesNothing: $this->matchesNothing,
        );
    }
}
