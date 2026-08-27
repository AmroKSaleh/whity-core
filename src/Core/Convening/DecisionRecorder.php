<?php

declare(strict_types=1);

namespace Whity\Core\Convening;

use PDO;
use Throwable;
use Whity\Core\Document\Routing\RoutingRejectedException;

/**
 * Minuting a decision — the one act in this subsystem that can move somebody
 * else's document.
 *
 * WHAT ONE CALL DOES, IN ONE TRANSACTION
 * --------------------------------------
 *  1. takes the decision number the CALLER supplied, or allocates one from
 *     `sequence_counters` when they supplied none ({@see DecisionNumbers}),
 *  2. asks the routing engine to apply the verdict, if there is anything to
 *     apply it to ({@see DecisionRouteBridge}),
 *  3. writes the decision row, carrying a pointer at the trail entry step 2
 *     produced.
 *
 * ALL THREE OR NONE, and the ordering inside the transaction is the point rather
 * than an implementation detail:
 *
 *  - If the ROUTING ACT FAILS, no decision row is written. That is deliberate and
 *    it is the sharp end of the design. A decision row saying "approved" beside a
 *    routing engine that refused the approval is a record claiming an
 *    authorization that did not happen — and it is a record somebody will later
 *    quote. The refusal reaches the caller as a 422 carrying the engine's own
 *    words.
 *  - If the DECISION INSERT FAILS, the routing act rolls back with it. The
 *    document does not advance on the strength of a minute nobody has.
 *
 * THE ONE THING THAT ESCAPES THE TRANSACTION, STATED PLAINLY
 * ----------------------------------------------------------
 * {@see \Whity\Core\Document\Routing\DocumentRouter::act()} dispatches its
 * broadcast AFTER its own commit — but when it JOINS a caller's transaction (as
 * it does here, by design: it only commits transactions it opened) that broadcast
 * fires while this transaction is still open. Its synchronous listeners include
 * {@see \Whity\Core\Document\Routing\RoutingNotifications}, whose writes are made
 * on this same connection and therefore roll back with everything else; what does
 * NOT roll back is anything a listener sends OUT OF THE DATABASE — an e-mail
 * already handed to a transport.
 *
 * The exposure is one rollback path (a failing decision insert after a successful
 * routing act) producing one spurious notification about a document that did not
 * move. It is recorded here rather than engineered around because every
 * alternative is worse: recording the decision outside the transaction reopens
 * the divergence above, and deferring the engine's broadcast would mean this
 * class reaching into the engine's contract with every other listener.
 *
 * WHY THIS IS NOT IN THE API HANDLER
 * ----------------------------------
 * Because "record a decision" is one operation with one set of invariants, and a
 * second caller — a scheduled minute-import, a CLI, a plugin — must get exactly
 * the same three steps in exactly the same order. A handler that opened the
 * transaction itself would be the only place the ordering above is written down.
 *
 * THE NUMBER MAY COME FROM THE INSTITUTION, AND THEN IT IS USED VERBATIM
 * ---------------------------------------------------------------------
 * A decision number is what a reviewer quotes back to the institution to check
 * that a decision was real; they look it up in the body's own minute book, which
 * is kept by hand, in the institution's own format, and often typed weeks after
 * the sitting. A number this system invented appears in no minute book. So
 * `$decisionNumber` is accepted, and when it is present nothing is derived from
 * it and nothing is reformatted — see {@see DecisionNumbers::validateSupplied()}
 * for exactly what is bounded (length, and characters that are not text) and
 * what is deliberately not (any shape at all).
 *
 * UNIQUENESS IS PER TENANT, WHICH IS WHERE MIGRATION 130 PUT IT
 * ------------------------------------------------------------
 * `UNIQUE (tenant_id, decision_number)` is a real index on the real table, and
 * it was left exactly where it was rather than narrowed to the body when
 * hand-typed numbers arrived. Three reasons, in order of weight:
 *
 *  1. It is STRICTER than the requirement. "Two decisions of the same body must
 *    not share a number" is implied by "two decisions of the same tenant must
 *    not", so nothing is lost on the case that matters.
 *  2. Narrowing it means DROPPING a unique index on live data. That direction
 *    only ever weakens an invariant, and it would do so silently for every
 *    deployment already relying on it — a report that joins on the number, an
 *    integration that treats it as a key.
 *  3. The number is quoted OUTSIDE this system, in correspondence that does not
 *    carry a body id. Tenant-wide uniqueness is what makes "decision 14/2026"
 *    resolvable from a letter.
 *
 * WHAT IT COSTS: two bodies in one tenant that each keep a minute book numbered
 * bare (`14/2026`) cannot both record `14/2026`. That refusal is deliberate and
 * it is actionable — {@see record()} names the meeting and body already holding
 * the number, and the fix is the prefix the institution almost certainly already
 * writes on paper.
 *
 * THE APPLICATION CHECK IS THE EXPLANATION; THE INDEX IS THE GUARANTEE. The
 * lookup below is a read followed by a write, so two of eight FrankenPHP workers
 * can both pass it. That is why the insert is also wrapped: a unique violation
 * is translated into the SAME refusal rather than escaping as a 500, which is
 * what "checked in the application" alone would have produced on a busy
 * Tuesday.
 *
 * AND THE COUNTER IS MOVED PAST A MANUAL NUMBER IT COULD HAVE MINTED, so the
 * automatic series can never reissue one. See
 * {@see DecisionNumbers::reserveIfAllocatable()}, which carries the argument.
 */
final class DecisionRecorder
{
    /**
     * How many times an ALLOCATED number is re-drawn when it turns out to be
     * taken.
     *
     * This should never fire. {@see DecisionNumbers::reserveIfAllocatable()}
     * moves the counter past any manual number in the canonical shape, so the
     * sequence cannot walk into one. It exists for the states that mechanism
     * cannot cover: a manual number recorded by a deployment running an older
     * build, a row inserted by hand, a counter restored from a backup older than
     * the decisions beside it. In all of those the caller supplied nothing and
     * deserves a working request rather than somebody else's collision.
     *
     * Bounded, and it terminates: the counter is monotonic, so every retry draws
     * a strictly higher number and at most this many strings can be tried before
     * one is free. An unbounded loop against a corrupted counter would hold a
     * transaction open instead.
     */
    private const ALLOCATION_ATTEMPTS = 50;

    public function __construct(
        private readonly PDO $db,
        private readonly ConveningBodyRepository $bodies,
        private readonly MeetingRepository $meetings,
        private readonly AgendaRepository $agenda,
        private readonly DecisionRepository $decisions,
        private readonly DecisionNumbers $numbers,
        private readonly DecisionRouteBridge $bridge,
    ) {
    }

    /**
     * Record what a body concluded about one agenda item.
     *
     * @param string $verdict    A {@see DecisionVerdict} value.
     * @param string $decidedAt  When the body decided — supplied, not defaulted
     *        to now(), for the reason {@see MeetingRepository::hold()} gives: a
     *        body routinely minutes yesterday's sitting, and a server-stamped
     *        date would put the decision (and its NUMBER, which contains the
     *        year) in the wrong period.
     * @param ?string $decisionNumber The number the INSTITUTION assigned, taken
     *        verbatim. Null — which is what every caller written before this
     *        existed sends — allocates one from the body's counter, so nothing
     *        that worked before changes.
     *
     * @return array{decision: array<string, mixed>, routing: array{applied: bool, reason: string,
     *               explanation: string, route_id: ?int, step_id: ?int, actor_profile_id: ?int,
     *               event_id: ?int, decided: ?string}}
     *
     * @throws ConveningRejectedException  When the request is not acceptable.
     * @throws RoutingRejectedException    When the engine refuses the verdict.
     */
    public function record(
        int $tenantId,
        int $agendaItemId,
        string $verdict,
        ?string $rationale,
        string $decidedAt,
        ?int $recordedBy,
        ?string $decisionNumber = null
    ): array {
        if (!DecisionVerdict::isValid($verdict)) {
            throw ConveningRejectedException::because(
                "'{$verdict}' is not a decision; expected one of: "
                . implode(', ', DecisionVerdict::all()) . '.'
            );
        }

        $item = $this->agenda->find($tenantId, $agendaItemId);
        if ($item === null) {
            throw ConveningRejectedException::because('That agenda item does not exist in this tenant.');
        }

        $meeting = $this->meetings->find($tenantId, (int) $item['meeting_id']);
        if ($meeting === null) {
            throw ConveningRejectedException::because('That agenda item names a meeting that could not be read.');
        }

        if ((string) $meeting['status'] !== MeetingStatus::HELD) {
            // A decision is something a body took AT a sitting. Recording one
            // against a meeting that has not been held would put a conclusion on
            // the record for a discussion that has not happened — and would mint
            // a number for it, which is not reclaimable. Hold the meeting first;
            // that act is a single call and it is the assertion being made.
            throw ConveningRejectedException::because(
                'Decisions can only be recorded against a meeting that has been held. This one is '
                . '"' . (string) $meeting['status'] . '". Record that the meeting took place first.'
            );
        }

        $body = $this->bodies->find($tenantId, (int) $meeting['body_id']);
        if ($body === null) {
            throw ConveningRejectedException::because('That meeting names a body that could not be read.');
        }

        $decidedAt = self::normalizeTimestamp($decidedAt, 'decided_at');

        // VALIDATED BEFORE THE TRANSACTION OPENS. A number that is not text at
        // all is a refusal that needs no database, and doing it here keeps the
        // transaction below to the three acts that actually have to be atomic.
        $supplied = $decisionNumber === null
            ? null
            : DecisionNumbers::validateSupplied($decisionNumber);

        $bodyKey = (string) $body['body_key'];
        $year = DecisionNumbers::yearOf($decidedAt);

        $owned = !$this->db->inTransaction();
        if ($owned) {
            $this->db->beginTransaction();
        }

        try {
            if ($supplied !== null) {
                // The collision check and the reservation are both INSIDE the
                // transaction. Outside it, a decision whose routing act was then
                // refused would leave the counter advanced for a number that
                // does not exist — the desynchronisation this is here to
                // prevent, arrived at from the other direction.
                $this->refuseIfNumberTaken($tenantId, $supplied);
                $this->numbers->reserveIfAllocatable($tenantId, $bodyKey, $year, $supplied);
                $number = $supplied;
            } else {
                $number = $this->allocateFreeNumber($tenantId, $bodyKey, $year);
            }

            // THE ROUTING ACT COMES FIRST. If the engine is going to refuse this
            // verdict, it must refuse before a decision row exists to contradict
            // it — see the class docblock.
            $routing = $this->bridge->apply(
                $tenantId,
                (int) $body['id'],
                $item['document_id'] !== null ? (int) $item['document_id'] : null,
                $verdict,
                $recordedBy,
                $rationale,
            );

            try {
                $decisionId = $this->decisions->create(
                    $tenantId,
                    (int) $meeting['id'],
                    (int) $item['id'],
                    $number,
                    $verdict,
                    $rationale,
                    $decidedAt,
                    $recordedBy,
                );
            } catch (\PDOException $e) {
                // THE INDEX WINNING THE RACE THE APPLICATION CHECK CANNOT.
                // `refuseIfNumberTaken()` above is a read, and two workers can
                // both pass it before either writes. Without this branch the
                // loser gets a 500 for a request that is merely a duplicate,
                // which is both wrong and unactionable. Translated into the
                // SAME refusal the check produces, so the two paths are
                // indistinguishable to a caller — which is the point: a
                // constraint is not a different kind of problem from the check
                // that anticipates it.
                if (!self::isUniqueViolation($e)) {
                    throw $e;
                }

                throw ConveningRejectedException::because(
                    'Decision number "' . $number . '" was taken by another decision while this one '
                    . 'was being recorded. Nothing was saved. Try again — with a different number if '
                    . 'you supplied this one.'
                );
            }

            if ($routing['route_id'] !== null) {
                $this->decisions->attachRoute(
                    $tenantId,
                    $decisionId,
                    (int) $routing['route_id'],
                    $routing['event_id'] !== null ? (int) $routing['event_id'] : null,
                );
            }

            $decision = $this->decisions->find($tenantId, $decisionId);

            if ($owned) {
                $this->db->commit();
            }
        } catch (Throwable $e) {
            if ($owned && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        if ($decision === null) {
            throw new \RuntimeException('Decision was recorded but could not be read back.');
        }

        return [
            'decision' => $decision,
            'routing' => [
                'applied' => $routing['reason'] === DecisionRouteBridge::APPLIED,
                'reason' => $routing['reason'],
                // The sentence a person reads. Always present, including on the
                // applied path, so a client never has to decide whether the
                // absence of an explanation means success or means nobody wrote
                // one.
                'explanation' => DecisionRouteBridge::explain($routing['reason']),
                'route_id' => $routing['route_id'],
                'step_id' => $routing['step_id'],
                'actor_profile_id' => $routing['actor_profile_id'],
                'event_id' => $routing['event_id'],
                // What the STEP concluded — null while a quorum is still short,
                // which is not the same as the body having decided nothing.
                'decided' => $routing['decided'],
            ],
        ];
    }

    /**
     * Refuse a supplied number that another decision already holds, naming what
     * it collides with.
     *
     * THE MESSAGE IS THE FEATURE. "Duplicate" tells a secretary nothing they can
     * act on; "meeting 7 of the Standards Board already recorded this on
     * 2026-03-04" tells them whether they mistyped, whether they are re-entering
     * a decision somebody has already entered, or whether two bodies are keeping
     * minute books that number the same way — which are three different
     * problems with three different fixes.
     *
     * Scope: PER TENANT, matching the index. See the class docblock for why that
     * scope was kept rather than narrowed to the body.
     *
     * @throws ConveningRejectedException
     */
    private function refuseIfNumberTaken(int $tenantId, string $number): void
    {
        $existing = $this->decisions->findByNumber($tenantId, $number);
        if ($existing === null) {
            return;
        }

        // "Another decision in this tenant" is the honest fallback for the case
        // where the colliding row's meeting or body cannot be read — which a
        // caller holding a narrower scope can genuinely hit. Vaguer than the
        // named form and still actionable: the number is taken, and this is not
        // the place to explain why the pointer could not be followed.
        $where = 'another decision in this tenant';
        $meeting = $this->meetings->find($tenantId, (int) $existing['meeting_id']);

        if ($meeting !== null) {
            $body = $this->bodies->find($tenantId, (int) $meeting['body_id']);
            if ($body !== null) {
                $where = '"' . (string) $body['display_name'] . '", meeting #'
                    . (string) $meeting['meeting_number'];
            }
        }

        throw ConveningRejectedException::because(
            'Decision number "' . $number . '" is already used by ' . $where . ' (recorded '
            . (string) $existing['decided_at'] . '). Two minutes under one number cannot be told '
            . 'apart afterwards, which is the whole reason a decision has a number. Use the number '
            . 'the minute book actually carries for this decision.'
        );
    }

    /**
     * Draw a number from the body's counter, skipping any that is somehow
     * already taken.
     *
     * The loop should never run twice — {@see DecisionNumbers::reserveIfAllocatable()}
     * keeps the sequence clear of manual numbers in the canonical shape. It is
     * here for the states that cannot cover: rows written by an older build, by
     * hand, or beside a counter restored from an older backup. See
     * {@see self::ALLOCATION_ATTEMPTS}.
     *
     * @throws ConveningRejectedException When the counter cannot find a free
     *         number, which means something is wrong with the counter rather
     *         than with this request — and says so.
     */
    private function allocateFreeNumber(int $tenantId, string $bodyKey, int $year): string
    {
        for ($attempt = 0; $attempt < self::ALLOCATION_ATTEMPTS; $attempt++) {
            $number = $this->numbers->allocate($tenantId, $bodyKey, $year);

            if ($this->decisions->findByNumber($tenantId, $number) === null) {
                return $number;
            }
        }

        throw ConveningRejectedException::because(
            'Could not allocate a free decision number for this body: the last '
            . self::ALLOCATION_ATTEMPTS . ' the sequence produced are all already in use. That '
            . 'means the counter is behind the decisions recorded against it — an operator should '
            . 'look at the "' . DecisionNumbers::counterName($bodyKey, $year) . '" counter. You can '
            . 'record this decision now by supplying decision_number yourself.'
        );
    }

    /**
     * Is this the unique index refusing a duplicate, as opposed to any other
     * database failure?
     *
     * SQLSTATE, not the driver message. 23505 is PostgreSQL's unique_violation;
     * SQLite reports the same class as the generic integrity-constraint 23000,
     * and both engines run this code (real PostgreSQL in production and in the
     * real-engine tests, SQLite in CI's unit job). Matching on the message text
     * instead would be matching on a string that differs between the two and
     * changes between server versions.
     *
     * Anything else is re-thrown untouched: a connection that dropped mid-insert
     * is not a duplicate number, and reporting it as one would tell somebody to
     * change a number that was never the problem.
     */
    private static function isUniqueViolation(\PDOException $e): bool
    {
        return in_array($e->getCode(), ['23505', '23000'], true);
    }

    /**
     * Accept an ISO-8601-ish timestamp and store it in the one format both
     * engines compare correctly.
     *
     * `strtotime` rather than a regex, because a caller sending
     * `2026-08-26T14:00:00Z` and one sending `2026-08-26 14:00:00` mean the same
     * moment and refusing one of them is a papercut with no upside. What is
     * refused is a string that names no moment at all — silently storing the
     * epoch for it would date every such decision to 1970 and number it under
     * that year.
     *
     * @throws ConveningRejectedException
     */
    public static function normalizeTimestamp(string $value, string $field): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            throw ConveningRejectedException::because("{$field} is required.");
        }

        $timestamp = strtotime($trimmed);
        if ($timestamp === false) {
            throw ConveningRejectedException::because(
                "{$field} is not a date and time this system can read. Send it as "
                . '"YYYY-MM-DD HH:MM:SS" or as an ISO-8601 instant.'
            );
        }

        return date('Y-m-d H:i:s', $timestamp);
    }
}
