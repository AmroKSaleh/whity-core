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
     */
    public function withRequestScope(?int $restrictToCreator, ?string $search): self
    {
        return new self(
            restrictToCreator: $restrictToCreator,
            createdBy: $this->createdBy,
            originOuIds: $this->originOuIds,
            inCollectionId: $this->inCollectionId,
            search: $search,
            matchesNothing: $this->matchesNothing,
        );
    }
}
