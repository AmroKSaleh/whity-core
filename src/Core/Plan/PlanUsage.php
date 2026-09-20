<?php

declare(strict_types=1);

namespace Whity\Core\Plan;

/**
 * What a tier is still holding, and therefore what may be done to it.
 *
 * ── The bug this exists to stop ────────────────────────────────────────────
 *
 * `DELETE FROM plans` succeeded on ANY tier, silently, and the database made it
 * look clean. Both of the columns that carry a customer's commercial history are
 * `ON DELETE SET NULL`:
 *
 *     tenant_plan.plan_id  -> NULL   the workspace keeps its subscription and
 *                                    forgets which tier it is on
 *     invoices.plan_id     -> NULL   a paid invoice forgets what it was for
 *
 * So tidying up an old tier quietly detached live subscribers and blanked the
 * tier out of invoices somebody had already paid. No error, no cascade refusal,
 * nothing in a log — the only evidence is a report that stops adding up months
 * later, by which time the tier's name is gone and nobody can say what those
 * customers were buying.
 *
 * ── The rule ───────────────────────────────────────────────────────────────
 *
 * A tier may be DELETED only when nothing references it and nothing ever has.
 * Otherwise it is RETIRED — deactivated, so it stops being sold while every row
 * that points at it keeps pointing at something real.
 *
 *   subscribers > 0   refuse. Somebody is on it right now. The remedies are to
 *                     move them to another tier or to retire this one; both
 *                     leave the tier in place.
 *   invoices > 0      refuse, permanently. Money changed hands against this
 *                     tier, and an invoice naming nothing is not a record. This
 *                     one does not become deletable by moving anybody.
 *
 * PRICES, LIMITS AND PROMOTION LINKS DO NOT BLOCK A DELETE, deliberately. They
 * cascade, and they should: they are the tier's own configuration — what it
 * cost, what it included, which campaigns pointed at it — not a record of what
 * a customer did. A tier nobody ever bought is free to take its own settings
 * with it. They are still COUNTED, because "this will also remove 3 prices"
 * belongs in the confirmation.
 */
final class PlanUsage
{
    public function __construct(
        /** Workspaces on this tier right now (`tenant_plan`). */
        public readonly int $subscribers,
        /** Invoices ever raised against it — the durable financial record. */
        public readonly int $invoices,
        /** Its own price rows; cascade on delete. */
        public readonly int $prices,
        /** Its own entitlement bundle; cascades on delete. */
        public readonly int $limits,
        /** Campaigns pointing at it; the LINK cascades, the promotion survives. */
        public readonly int $promotions,
    ) {
    }

    /** Nothing points at it and nothing ever did. */
    public function isDeletable(): bool
    {
        return $this->subscribers === 0 && $this->invoices === 0;
    }

    /**
     * Whether this tier can NEVER be deleted, however it is tidied up.
     *
     * Worth its own question because it changes what an interface should offer.
     * A tier with subscribers has a route to deletion — move them off — and a
     * screen can say so. A tier with invoices does not, and offering "delete"
     * greyed out with no explanation invites somebody to keep trying.
     */
    public function isPermanentlyUndeletable(): bool
    {
        return $this->invoices > 0;
    }

    /**
     * Why a delete was refused, in the words somebody needs to act on.
     *
     * CARRIES THE NUMBERS AND THE REMEDY. "This plan is in use" tells nobody
     * what to do next; "3 workspaces are on it — move them to another tier or
     * retire it instead" does. Null when the tier is deletable.
     */
    public function refusalReason(): ?string
    {
        if ($this->isDeletable()) {
            return null;
        }

        if ($this->invoices > 0 && $this->subscribers > 0) {
            return sprintf(
                '%d workspace(s) are on this tier and %d invoice(s) were raised against it. '
                . 'Move those workspaces to another tier, then retire this one — it cannot be '
                . 'deleted, because deleting it would blank the tier out of invoices that have '
                . 'already been paid.',
                $this->subscribers,
                $this->invoices
            );
        }

        if ($this->invoices > 0) {
            return sprintf(
                '%d invoice(s) were raised against this tier. Retire it instead — deleting it '
                . 'would blank the tier out of invoices that have already been paid.',
                $this->invoices
            );
        }

        return sprintf(
            '%d workspace(s) are on this tier. Move them to another tier or retire this one; '
            . 'deleting it would leave them subscribed to nothing.',
            $this->subscribers
        );
    }
}
