<?php

declare(strict_types=1);

namespace Whity\Core\Billing\External;

use PDO;
use PDOException;

/**
 * Which notifications have already been acted on.
 *
 * Deliveries repeat by design: a notification whose response timed out is re-sent
 * with the SAME event id, and a delivery that failed halfway through the
 * receiver's own work comes back too. Without this, a replay re-applies whatever
 * the first one did.
 *
 * THE INSERT IS THE CHECK. A read-then-write has a window in which two workers
 * both see "not seen yet" — and that window is open precisely when something is
 * retrying, which is the only time this matters. So the primary key decides it:
 * the insert either succeeds, which means this caller won and may proceed, or it
 * collides, which means somebody already has it. Nothing is asked first.
 *
 * The ledger is global — no tenant column. An event is deduplicated before
 * anything is known about whose it is, and the id is unique across the sender's
 * whole account. Registered in SanctionedGlobalTables for that reason.
 */
final class EventLedger
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Claim an event id for processing.
     *
     * @return bool True if this caller is the first to see it and should do the
     *              work; false if it has already been recorded.
     */
    public function claim(string $eventId, string $eventType): bool
    {
        if ($eventId === '') {
            // No id means nothing can be deduplicated. Refusing is the safe
            // direction: a sender that omits it is not one whose deliveries
            // should be applied blind, and every real delivery carries one.
            return false;
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO billing_event_receipts (event_id, event_type, received_at)
             VALUES (:event_id, :event_type, :now)
             ON CONFLICT (event_id) DO NOTHING'
        );

        try {
            $statement->execute([
                ':event_id' => $eventId,
                ':event_type' => substr($eventType, 0, 64),
                ':now' => gmdate('Y-m-d H:i:s'),
            ]);
        } catch (PDOException) {
            // A duplicate raised rather than swallowed by ON CONFLICT — the same
            // answer either way: somebody else has this event.
            return false;
        }

        // rowCount is the whole result: 1 means this insert created the row, 0
        // means the conflict clause suppressed it because it was already there.
        return $statement->rowCount() === 1;
    }

    /**
     * Give an event id back, so a retry of it is processed rather than dropped.
     *
     * THE CLAIM IS TAKEN BEFORE THE WORK, which is what makes two workers
     * receiving the same retry safe. The cost is that a claim followed by a
     * failure would silence every future delivery of that event — the sender
     * would retry faithfully, this side would recognise the id and drop it, and
     * a tenant's access would never be corrected. Releasing closes that: a
     * transient failure puts the id back before answering with an error.
     *
     * Only ever called for a failure that might succeed later. An event that was
     * applied, or one that was rejected on its merits, keeps its receipt.
     */
    public function release(string $eventId): void
    {
        if ($eventId === '') {
            return;
        }

        $statement = $this->pdo->prepare(
            'DELETE FROM billing_event_receipts WHERE event_id = :event_id'
        );
        $statement->execute([':event_id' => $eventId]);
    }

    /**
     * Forget receipts older than the retention window.
     *
     * A ledger that only grows is its own outage. The window has to outlive the
     * sender's retry schedule by a wide margin — forgetting an id while a retry
     * of it is still possible would let that retry be applied a second time,
     * which is the exact thing this table exists to prevent.
     *
     * @return int Rows removed.
     */
    public function prune(int $olderThanDays = 30): int
    {
        $statement = $this->pdo->prepare(
            'DELETE FROM billing_event_receipts WHERE received_at < :cutoff'
        );
        $statement->execute([
            ':cutoff' => gmdate('Y-m-d H:i:s', time() - ($olderThanDays * 86400)),
        ]);

        return $statement->rowCount();
    }
}
