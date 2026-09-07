<?php

declare(strict_types=1);

namespace Whity\Core\TimeWindow;

/**
 * WHAT A CLOSE IS ABOUT TO SEAL (#1070).
 *
 * The difference between a control and a trap. Sealing a period silently is a
 * trap: whoever performs it learns what was inside afterwards, from whoever
 * comes looking for the thing they can no longer finish. Being told first — "you
 * are about to close this period; four items in it are still unfinished, and two
 * periods inside it are still open" — is the same act with the information
 * attached, and it costs one query.
 *
 * TWO KINDS OF CONTENT, AND ONLY ONE IS CORE'S TO KNOW
 * ---------------------------------------------------
 * {@see openChildren()} is structural and core knows it outright: a period
 * nesting inside this one, still open. It is also the one BLOCKING finding —
 * see {@see isBlocked()}.
 *
 * {@see unfinished()} is domain knowledge and core has none. Core owns no
 * records that are scoped to a period; whether an item in a period counts as
 * unfinished is a question only the subsystem holding it can answer, and the
 * answer differs per subsystem. So the counts are CONTRIBUTED, through the
 * `time_window.close_report` filter hook, and core ships no contributor. An empty
 * `unfinished` therefore means "nothing volunteered a count", which is an honest
 * answer and not the same as "nothing is unfinished" — {@see hasContributions()}
 * lets a caller tell those apart and say so.
 *
 * WHY THE CONTRIBUTION SEAM IS A HOOK AND NOT AN SDK CONTRACT
 * ----------------------------------------------------------
 * Deliberate, and the reason is the open question this subsystem ships with. A
 * published SDK interface is vendored into plugins and version-pinned; changing
 * one is a breaking release. What "unfinished" should MEAN when a record is
 * mid-flight across a period boundary is genuinely unresolved (see the issue),
 * and the shape of a typed contract depends on how that is answered — whether a
 * contributor is asked merely to count, or to say whether the close may proceed,
 * or to be told the close happened. Publishing a contract before that is
 * publishing one that then has to break. This is the same reasoning
 * {@see \Whity\Core\Inbox\InboxSourceRegistry} states for holding back its own
 * plugin-facing half, and it should be revisited the same way: when the question
 * is answered, not before.
 *
 * NOTHING HERE DECIDES ANYTHING
 * -----------------------------
 * A report is a report. It does not close, does not refuse, and does not modify
 * a single record. `isBlocked()` is the ONE place it expresses a rule, and that
 * rule is the nesting invariant, which is structural rather than a policy about
 * anybody's records.
 */
final class WindowCloseReport
{
    /**
     * @param array<string, mixed>             $window       The period being reported on, in API shape.
     * @param list<array<string, mixed>>       $openChildren Periods nesting inside it that are still open.
     * @param list<array{label: string, count: int, source: string}> $unfinished Contributed counts.
     * @param bool                             $hasContributions Whether anything answered the hook at all.
     */
    public function __construct(
        private readonly array $window,
        private readonly array $openChildren,
        private readonly array $unfinished,
        private readonly bool $hasContributions,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function window(): array
    {
        return $this->window;
    }

    /**
     * Periods nesting directly inside this one that are still open.
     *
     * Direct children only, and that is sufficient: a grandchild cannot be open
     * while its own parent is closed — the nesting invariant forbids it — so an
     * open grandchild implies an open child, which is already listed.
     *
     * @return list<array<string, mixed>>
     */
    public function openChildren(): array
    {
        return $this->openChildren;
    }

    /**
     * Contributed counts of what is still unfinished inside this period.
     *
     * @return list<array{label: string, count: int, source: string}>
     */
    public function unfinished(): array
    {
        return $this->unfinished;
    }

    /**
     * Whether any contributor answered at all.
     *
     * The distinction that keeps the report honest: "nobody is tracking
     * unfinished work in this period" and "nothing is unfinished" both produce an
     * empty list, and only one of them is a reason to close with confidence.
     */
    public function hasContributions(): bool
    {
        return $this->hasContributions;
    }

    /**
     * The sum of every contributed count.
     */
    public function unfinishedTotal(): int
    {
        $total = 0;
        foreach ($this->unfinished as $group) {
            $total += $group['count'];
        }

        return $total;
    }

    /**
     * Whether closing must be refused as things stand.
     *
     * TRUE only for open children. Unfinished contributed items do NOT block:
     * they are told to the operator, who decides. That asymmetry is the whole
     * design of this class — an open child period makes the seal self-
     * contradictory (records could still land inside a sealed period), whereas
     * four unfinished items are a judgement about whether to wait, and a
     * judgement belongs to a person.
     */
    public function isBlocked(): bool
    {
        return $this->openChildren !== [];
    }

    /**
     * The wire shape.
     *
     * @return array{
     *     window: array<string, mixed>,
     *     blocked: bool,
     *     open_children: list<array<string, mixed>>,
     *     unfinished: list<array{label: string, count: int, source: string}>,
     *     unfinished_total: int,
     *     unfinished_reported: bool
     * }
     */
    public function toArray(): array
    {
        return [
            'window' => $this->window,
            'blocked' => $this->isBlocked(),
            'open_children' => $this->openChildren,
            'unfinished' => $this->unfinished,
            'unfinished_total' => $this->unfinishedTotal(),
            'unfinished_reported' => $this->hasContributions,
        ];
    }
}
