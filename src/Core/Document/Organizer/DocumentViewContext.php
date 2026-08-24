<?php

declare(strict_types=1);

namespace Whity\Core\Document\Organizer;

use Closure;

/**
 * Everything a {@see DocumentView} is allowed to know when it resolves (#978):
 * who is asking, from where, and what they asked for.
 *
 * WHAT IS NOT HERE, AND WHY
 * -------------------------
 * No PDO, no repository, no permission resolver. A view resolves to a
 * {@see DocumentCriteria} — it does not run queries and it does not decide
 * visibility. Handing a view the connection would make every registered view,
 * including a plugin's, a place where an unscoped read could be written, and
 * the tenant-predicate guard reads literal SQL in known files rather than
 * wherever a closure happens to live.
 *
 * The one query a view legitimately needs is the OU subtree walk, and it arrives
 * as a CLOSURE with the tenant already bound — a capability that can answer
 * "what is beneath this unit?" and nothing else, of this tenant and no other.
 * {@see \Whity\Core\Ou\OuSubtree} is what it calls; passing that class plus a
 * connection instead would hand every view, a plugin's included, the means to
 * run any query at all.
 *
 * THE ANCHOR, AND WHY IT IS NOT PERMISSION-CHECKED HERE
 * -----------------------------------------------------
 * `anchorOuId` is a unit the caller explicitly picked (the `ouScopePicker`
 * control). It is validated to exist IN THIS TENANT before it reaches here and
 * is deliberately not otherwise restricted: row visibility is enforced by
 * {@see \Whity\Core\Document\DocumentVisibilityPolicy} on every result, so
 * anchoring at a unit the caller has no standing in returns whatever they could
 * already see and nothing more. Refusing the anchor as well would be a second,
 * weaker copy of the same rule — and one that reports "forbidden" for a query
 * whose real answer is "nothing", which tells an outsider the unit is busy.
 *
 * Immutable — worker-safe.
 */
final class DocumentViewContext
{
    /**
     * @param int      $tenantId              The active tenant. Every criteria this produces is scoped to it.
     * @param int      $callerProfileId       Who is asking.
     * @param int|null $primaryOuId           The caller's own unit — their primary active membership's,
     *                                        or null when they belong to none. The DEFAULT anchor.
     * @param int|null $anchorOuId            A unit the caller picked, overriding their own.
     * @param int|null $collectionId          The collection a collection-shaped view was opened on.
     * @param int|null $starredCollectionId   The caller's starred collection, or null when they have
     *                                        never starred anything (it is created lazily — see
     *                                        migration 114).
     * @param Closure(int): list<int> $subtree Anchor unit id => that unit and everything beneath it,
     *                                        tenant already bound by the caller.
     */
    public function __construct(
        public readonly int $tenantId,
        public readonly int $callerProfileId,
        public readonly ?int $primaryOuId,
        public readonly ?int $anchorOuId,
        public readonly ?int $collectionId,
        public readonly ?int $starredCollectionId,
        private readonly Closure $subtree,
    ) {
    }

    /**
     * The unit this request is anchored at: the caller's explicit pick, else
     * their own unit, else nothing.
     */
    public function effectiveOuId(): ?int
    {
        return $this->anchorOuId ?? $this->primaryOuId;
    }

    /**
     * The anchor unit and every unit beneath it, tenant-scoped.
     *
     * @return list<int>
     */
    public function ouSubtree(int $anchorOuId): array
    {
        return ($this->subtree)($anchorOuId);
    }
}
