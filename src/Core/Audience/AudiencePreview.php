<?php

declare(strict_types=1);

namespace Whity\Core\Audience;

use Whity\Sdk\Routing\ResolvedRecipient;

/**
 * What a rule resolves to RIGHT NOW, said as a count and a handful of faces
 * (#999).
 *
 * THE PREVIEW CONTRACT, AND WHY IT IS NOT A LIST
 * ----------------------------------------------
 * An author who has just written "everyone holding the instructor role, in my
 * unit and below" needs to know they wrote what they meant. The useful answer is
 * "1,043 people right now, including these ten". The useless answer is 1,043
 * rows.
 *
 * That is not a performance argument, or not mainly. A design surface that
 * renders 1,043 people has rebuilt the exact thing the type-not-instance design
 * exists to avoid — the thousand nodes standing in for the one that says
 * "instructors" — and it does it on the screen where somebody is trying to check
 * one fact. So this object carries a total and a bounded sample, there is no
 * pagination over the sample, and none is coming: a caller who genuinely needs a
 * person-by-person list is asking a different question ("who holds this role"),
 * and the users API already answers it with its own filtering, its own paging
 * and its own permission.
 *
 * THE COUNT IS EXACT, AND IT COSTS A FULL RESOLUTION
 * -------------------------------------------------
 * {@see total} is not an estimate. It is `count()` of what the rule actually
 * answered, after the host's membership filter, which means producing a preview
 * costs precisely what using the group costs.
 *
 * A `count()` method on the resolver interface was considered and rejected. It
 * would be a SECOND implementation of the same question, free to disagree with
 * `resolve()` — and the disagreement would surface as "the preview said 1,043
 * and the route delivered 900", with nothing to say which was right. One
 * implementation that is sometimes expensive beats two that are cheap and
 * inconsistent, and a preview that is slow is itself information: a rule too
 * expensive to preview is too expensive to reach on every route step.
 *
 * THE SAMPLE IS SORTED, DELIBERATELY
 * ----------------------------------
 * Resolvers make no ordering promise — core's own read `memberships` with no
 * ORDER BY — so the first N rows a rule happens to return can differ between two
 * identical calls. Two previews of an unchanged group showing different faces
 * would read as "the group changed", which is the one thing a sanity check must
 * not imply. So the sample is the lowest N profile ids, which is stable for as
 * long as the answer is.
 *
 * WHO IT WAS RESOLVED FOR IS PART OF THE ANSWER
 * ---------------------------------------------
 * Some kinds are actor-relative by design — core's `role_below_actor` resolves
 * to a different set for a dean than for a faculty officer. A preview of such a
 * group is therefore relative to whoever asked, and two colleagues would
 * otherwise read two different counts off the same screen with nothing to
 * explain it. {@see resolvedForProfileId} / {@see resolvedForOuId} are that
 * explanation, and they are on every preview rather than only on the relative
 * ones, because whether a kind is relative is the resolver's business and not
 * something the host can ask.
 *
 * NAMES ARE NOT HERE, ON PURPOSE. This object carries profile ids and units. Who
 * may see a person's display name is a question `users:read` already answers,
 * and answering it again in core would put a permission decision inside a value
 * object. The API handler decorates the sample, or does not.
 *
 * Immutable value object.
 */
final class AudiencePreview
{
    /**
     * @param int                     $total                 How many people the rule resolves to
     *                                                       right now — exact, after the host's
     *                                                       active-membership filter.
     * @param list<ResolvedRecipient> $sample                At most `sampleSize` of them, lowest
     *                                                       profile id first.
     * @param int                     $sampleSize            The ceiling that produced the sample,
     *                                                       reported so a client can tell "that is
     *                                                       everybody" from "that is the first ten".
     * @param int|null                $resolvedForProfileId  The actor the rule was resolved
     *                                                       against, or null when there was none.
     * @param int|null                $resolvedForOuId       The unit that actor was acting from.
     */
    public function __construct(
        public readonly int $total,
        public readonly array $sample,
        public readonly int $sampleSize,
        public readonly ?int $resolvedForProfileId,
        public readonly ?int $resolvedForOuId,
    ) {
    }

    /**
     * Whether people were resolved that the sample does not show.
     *
     * Stated rather than left to the client to infer from `total > count(sample)`
     * — a client that got the inference wrong would present a sample as the
     * whole membership, which is the one misreading this whole shape exists to
     * prevent.
     */
    public function truncated(): bool
    {
        return $this->total > count($this->sample);
    }
}
