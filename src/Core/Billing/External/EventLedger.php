<?php

declare(strict_types=1);

namespace Whity\Core\Billing\External;

use Whity\Core\Store\SharedStoreInterface;

/**
 * Which notifications have already been acted on.
 *
 * Deliveries repeat by design: one whose response timed out is re-sent with the
 * SAME event id, and one that failed halfway through the receiver's own work
 * comes back too. Without this, a replay re-applies whatever the first did.
 *
 * ── Why this is not its own table ───────────────────────────────────────────
 *
 * It was, briefly. Two things argued it back out.
 *
 * A dedicated table needed a pruning job, and a receipt table that is never
 * pruned grows for the life of the deployment — one row per notification,
 * forever, to answer a question that stops mattering within hours. The shared
 * store expires keys itself, so the retention window is a TTL rather than a cron
 * job somebody has to remember to schedule.
 *
 * And a new table meant one more entry on the sanctioned-global allowlist, a
 * list whose entire purpose is to stay short because every entry is a table
 * outside tenant-isolation enforcement. Spending one of those on an idempotency
 * counter, when an atomic counter store already exists, is the kind of growth
 * that list is meant to provoke a second thought about.
 *
 * ── Why the counter IS the claim ────────────────────────────────────────────
 *
 * `increment()` is a single atomic upsert that returns the new value, so the
 * FIRST caller for a key sees exactly 1 and everyone after sees more. A
 * read-then-write would have a window in which two workers both see "not seen
 * yet" — and that window is open precisely when something is retrying, which is
 * the only time any of this matters. So nothing is asked first: the write is the
 * question and its answer.
 */
final class EventLedger
{
    /**
     * How long a claim is remembered.
     *
     * Has to comfortably outlive the sender's retry schedule — eight attempts on
     * a widening backoff run to roughly half a day — because forgetting an id
     * while a retry of it is still possible would let that retry be applied a
     * second time, which is the exact thing this exists to prevent. Thirty days
     * is far past that and costs one short-lived row per notification.
     */
    public const DEFAULT_RETENTION_SECONDS = 30 * 86400;

    private const PREFIX = 'billing:event:';

    public function __construct(
        private readonly SharedStoreInterface $store,
        private readonly int $retentionSeconds = self::DEFAULT_RETENTION_SECONDS,
    ) {
    }

    /**
     * Claim an event id for processing.
     *
     * @return bool True if this caller is the first to see it and should do the
     *              work; false if it has already been claimed.
     */
    public function claim(string $eventId): bool
    {
        if ($eventId === '') {
            // No id means nothing can be deduplicated. Refusing is the safe
            // direction: a sender that omits it is not one whose deliveries
            // should be applied blind, and every real delivery carries one.
            return false;
        }

        return $this->store->increment(self::PREFIX . $eventId, $this->retentionSeconds) === 1;
    }

    /**
     * Give an event id back, so a retry of it is processed rather than dropped.
     *
     * THE CLAIM IS TAKEN BEFORE THE WORK, which is what makes two workers
     * receiving the same retry safe. The cost is that a claim followed by a
     * failure would silence every future delivery of that event — the sender
     * would retry faithfully, this side would recognise the id and drop it, and
     * a tenant's access would never be corrected. Releasing closes that.
     *
     * Decrementing rather than deleting, deliberately: the store floors a
     * counter at zero, so a release cannot drive it negative, and the next
     * `increment()` on the released key returns 1 again — a clean claim.
     *
     * Only ever called for a failure that might succeed later. An event that was
     * applied, or rejected on its merits, keeps its claim.
     */
    public function release(string $eventId): void
    {
        if ($eventId === '') {
            return;
        }

        $this->store->decrement(self::PREFIX . $eventId);
    }
}
