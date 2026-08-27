<?php

declare(strict_types=1);

namespace Whity\Core\Form;

use Whity\Storage\StorageDriverInterface;
use Whity\Storage\StorageException;

/**
 * Deletes the uploads nobody ever submitted.
 *
 * THE PROBLEM THIS ANSWERS, STATED PLAINLY
 * -----------------------------------------
 * An upload happens BEFORE the submission exists — it has to, because the person
 * attaches the file while they are still filling the form in. So every abandoned
 * form leaves bytes in a tenant's storage that no row anywhere will ever
 * reference: they closed the tab, the deadline passed, they picked the wrong
 * file and chose another. There is no version of this feature without that
 * window; there is only a version that cleans up and a version that does not.
 *
 * A TTL WAS CHOSEN OVER THE ALTERNATIVES, AND HERE IS WHY THE OTHERS LOSE
 * -----------------------------------------------------------------------
 *   "Accept the cost." Storage that only grows is a bill that only grows, and
 *   the growth is driven by whoever abandons the most forms — which, on a public
 *   link, is a stranger. An accepted cost with an attacker-controlled magnitude
 *   is not an accepted cost.
 *
 *   "Delete on session end." There is no session on the public path, which is
 *   the path that needs this most.
 *
 *   "Reference-count from the answers." A submission's `data` is jsonb and a
 *   `file` answer is a string inside it, so "is any submission pointing at this
 *   key" is a scan of every submission in the install. `form_uploads.claimed_at`
 *   makes the same question an index lookup, and — more importantly — makes it a
 *   question about a row this subsystem OWNS rather than about the shape of
 *   somebody's answers.
 *
 * {@see self::DEFAULT_TTL_SECONDS} is 24 hours. It has to outlast a person who
 * attaches a paper, goes to find the co-author list, and comes back after lunch;
 * it does not have to outlast a person who abandoned the form yesterday. An
 * operator who disagrees passes a different number — the command takes one.
 *
 * ROW GONE, THEN OBJECT GONE — AND WHICH HALF-FAILURE IS ACCEPTED
 * ----------------------------------------------------------------
 * {@see FormUploadRepository::sweepUnclaimed()} deletes the rows first and hands
 * back only the keys whose rows it actually removed. This class then deletes
 * those objects. If the process dies between the two, an object survives with no
 * row — unreferenced, unreachable, and now invisible to the next sweep.
 *
 * That is the accepted side, and it is accepted rather than overlooked. The
 * other order — object first, row second — leaves a row a submission can still
 * claim, pointing at bytes that are gone: a `document_artifacts` row minted over
 * an empty address, which reports success and produces a 404 the first time
 * anybody opens the evidence. A leaked object costs money; a claimable row with
 * no bytes costs the truth of the record.
 *
 * The count of objects that could not be deleted is RETURNED rather than
 * swallowed, so the operator running this sees "42 swept, 3 objects unreachable"
 * instead of a silent success — the failure class this codebase keeps writing
 * against.
 */
final class FormUploadSweeper
{
    /** How long an unclaimed upload is kept before it is swept: 24 hours. */
    public const DEFAULT_TTL_SECONDS = 86400;

    public function __construct(
        private readonly FormUploadRepository $uploads,
        private readonly StorageDriverInterface $storage,
    ) {
    }

    /**
     * Run one pass.
     *
     * @return array{swept: int, unreachable: int} `swept` counts rows removed;
     *         `unreachable` counts objects whose delete failed — the bytes are
     *         still there and nothing points at them any more.
     */
    public function sweep(int $ttlSeconds = self::DEFAULT_TTL_SECONDS, int $limit = 500): array
    {
        $keys = $this->uploads->sweepUnclaimed($ttlSeconds, $limit);

        $unreachable = 0;
        foreach ($keys as $key) {
            try {
                $this->storage->delete($key);
            } catch (StorageException $e) {
                // Counted, logged, and NOT re-thrown: one unreadable object must
                // not abandon the rest of the pass. The row is already gone, so
                // this key will not come round again — which is why the number
                // is returned rather than hidden.
                $unreachable++;
                error_log('[FormUploadSweeper] could not delete ' . $key . ': ' . $e->getMessage());
            }
        }

        return ['swept' => count($keys), 'unreachable' => $unreachable];
    }
}
