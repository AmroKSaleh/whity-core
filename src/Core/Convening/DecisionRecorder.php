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
 *  1. allocates the decision number from `sequence_counters`
 *     ({@see DecisionNumbers}),
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
 */
final class DecisionRecorder
{
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
        ?int $recordedBy
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

        $owned = !$this->db->inTransaction();
        if ($owned) {
            $this->db->beginTransaction();
        }

        try {
            $number = $this->numbers->allocate(
                $tenantId,
                (string) $body['body_key'],
                DecisionNumbers::yearOf($decidedAt)
            );

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
