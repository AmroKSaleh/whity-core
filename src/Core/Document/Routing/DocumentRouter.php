<?php

declare(strict_types=1);

namespace Whity\Core\Document\Routing;

use InvalidArgumentException;
use PDO;
use Throwable;
use Whity\Core\Audience\ActiveMemberFilter;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Ou\PrimaryMembershipOu;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Sdk\Routing\ResolvedRecipient;
use Whity\Sdk\Routing\RoutingRuleContext;

/**
 * The routing ENGINE (#947 item 3): the one place a route is issued and the one
 * place a recipient's act is applied.
 *
 * Everything else in this namespace stores or reads. This class is where the
 * three semantics #947 names are actually enforced, so they are worth stating
 * against the code that does it:
 *
 *  1. A STEP NAMES A RULE, NEVER A PERSON. {@see resolveStep()} calls the
 *     registered resolver at the moment the step is REACHED — at issue for the
 *     first, and again per acting recipient thereafter. Nothing anywhere stores
 *     who a step will reach, because {@see RouteStepRepository} has no column
 *     for it.
 *
 *  2. DISTRIBUTION FANS OUT, IT DOES NOT BLOCK. {@see act()} resolves the next
 *     step from the ACTOR's own position and links the new rows to the actor's
 *     row through `parent_recipient_id`. There is no step-completion check
 *     anywhere in this class — not "are all step-2 recipients done", not a
 *     counter, not a barrier — because there is no row that could hold one.
 *     Each chain advances on its own.
 *
 *  3. THE TRAIL IS APPEND-ONLY, with no footnote. Every state change here
 *     RESOLVES first (a pure read), then appends one event carrying everything
 *     it will ever say — including the destination unit — then opens the
 *     recipient rows that point AT that event. So the trail is written by
 *     INSERT and by nothing else: {@see RouteEventRepository} offers no update
 *     and no delete, and this class issues none either. A correction is
 *     {@see RouteAction::NOTED}, a new row beside the old one.
 *
 * ONE TRAIL, ONE BROADCAST
 * ------------------------
 * Every appended event is also dispatched through
 * {@see HookManager::dispatchAsync()}, which persists it to `domain_events` and
 * the outbox relay (migration 066). #947 notes that documents emit nothing into
 * the spine today; this closes that without moving the system of record.
 *
 * The two cannot disagree, because the emission is DERIVED from the insert, in
 * the same call, in ONE DIRECTION: trail to spine, never spine to trail. That
 * is not two audit trails — it is one trail and one broadcast, and only one of
 * them is ever read as authoritative. Migration 112's docblock carries the full
 * argument for why the trail itself is a dedicated table.
 *
 * The dispatch happens AFTER the commit, deliberately. `dispatchAsync` is
 * documented as non-critical and swallows its own persistence failures, but a
 * listener registered by a plugin runs synchronously inside it, and a listener
 * that throws inside our transaction would roll back a routing act that had
 * already succeeded. A broadcast that is lost is a missed notification; a
 * routing act rolled back by somebody else's listener is a document a person was
 * told they had forwarded and has not.
 *
 * A RESOLVER CANNOT ESCAPE ITS TENANT
 * -----------------------------------
 * A resolver — core's or a plugin's — returns {@see ResolvedRecipient} objects
 * and writes nothing. {@see ActiveMemberFilter::apply()} then intersects that
 * answer with the ACTIVE MEMBERSHIPS of the route's own tenant before a single
 * row is inserted. So a buggy or hostile resolver cannot place a document in
 * another tenant's inbox, and the check lives in the HOST rather than being a
 * rule every resolver author has to remember.
 *
 * That filter was private to this class until #999, which gave it a second
 * caller — a named user group resolving the same kinds outside routing. It moved
 * out rather than being written twice: a security boundary with two copies has
 * one copy nobody is watching.
 *
 * APPROVAL IS A DISTINCT ACT (#1014)
 * ----------------------------------
 * A step may be a GATE. On one, the only answer is `acknowledged` carrying a
 * VERDICT ({@see RouteVerdict}), and where the document goes next is derived
 * here — from the verdict, the step's edges and the QUORUM — rather than chosen
 * by the person answering. Three things are worth stating against the code:
 *
 *  a. THE VERDICT IS A SECOND COLUMN, NOT TWO MORE VERBS. Migration 119 carries
 *     the full argument; the short form is that under a quorum the same verdict
 *     has different routing effects, so it cannot be a member of a vocabulary
 *     whose defining property is that the verb determines the effect.
 *
 *  b. REJECTION DOES NOT INHERIT APPROVAL'S DESTINATION. {@see nextForVerdict()}
 *     is the whole of #1014: an approval with no edge continues to the next
 *     ordinal, a rejection with no edge goes NOWHERE, and no code path anywhere
 *     lets a rejection fall through to the step an approval would have opened.
 *
 *  c. THE QUORUM IS A BARRIER, AND IT IS THE ONLY ONE. Migration 112 refuses
 *     step-level completion state and argues at length against barriers, which is
 *     right for CIRCULATION and is exactly what SIGN-OFF is. The reconciliation
 *     is that nothing is stored: {@see decide()} counts the cohort out of the
 *     append-only trail and the recipient rows at the moment of each act, so
 *     there is still no counter, no `steps.completed_at` and no aggregate any
 *     chain could be held by. Two chains reaching one step decide separately.
 *
 * A STEP CAN BE SATISFIED BY DELIVERY (#1054)
 * -------------------------------------------
 * Every verb in {@see RouteAction} is an ACTOR-DRIVEN transition, so until now
 * there was no way to model the last stage of a circular: *put this in every
 * instructor's mailbox; nobody downstream is being asked for anything*. Authored
 * as an ordinary step it left every one of those people holding an open
 * recipient row for ever — a permanent phantom item in "Awaiting me" and in the
 * #881 inbox that no act could clear, because there was no act to make.
 *
 * A step now declares {@see RouteSatisfaction} (migration 124), and three things
 * about the implementation are worth stating against the code:
 *
 *  a. THE CLOSE APPENDS NOTHING AND CLAIMS NOTHING. {@see openChain()} closes a
 *     delivery step's rows by naming THE EVENT THAT CREATED THEM. No sixth verb
 *     was added — widening the trail's inline CHECK is impossible on SQLite, and
 *     spelling a system close as `noted` would make an append-only audit log
 *     assert that a person did something they did not. The people told appear in
 *     the trail as ACTORS nowhere, because they were not.
 *
 *  b. THE DOCUMENT DOES NOT STOP THERE. Nobody at a delivery step will forward
 *     it, so {@see planChain()} walks on to the next ordinal in the same act,
 *     resolved from the same actor. A version that closed the rows and stopped
 *     would turn a mid-route delivery step into a silent dead end that every
 *     screen renders as a document travelling normally.
 *
 *  c. IT CAN NEVER BE A GATE. A quorum counts the rows one act opened, and here
 *     they are closed the instant they exist. {@see validateSteps()} refuses the
 *     pair at authoring time rather than storing a step that looks like an
 *     approval and can never be given one.
 *
 * WHAT THE STEP DOES NOT SAY IS THE CHANNEL. `satisfied_by` is the route
 * DESIGN's business; e-mail versus in-app versus SMS is the operator's, and it
 * resolves through `documents.routing_notification_channels` and each profile's
 * own notification preferences. {@see \Whity\Core\Document\Routing\RoutingNotifications}
 * is the subscriber that turns a routing broadcast into notifications, and it is
 * a SUBSCRIBER rather than a call from this class on purpose: the engine's job
 * ends when the trail and the rows are right.
 *
 * CEILINGS ARE SETTINGS, AND EXCEEDING ONE IS A REFUSAL
 * ----------------------------------------------------
 * `documents.routing_max_steps` and `documents.routing_max_recipients_per_step`
 * resolve per-tenant, then global, then the registry default — never hardcoded.
 * Exceeding either is a 422 that NAMES the number, not a truncation: silently
 * delivering to the first 500 of 900 people is the stored-recipient-list
 * failure wearing a different hat, and it would report success.
 */
final class DocumentRouter
{
    public function __construct(
        private readonly PDO $db,
        private readonly RouteRepository $routes,
        private readonly RouteStepRepository $steps,
        private readonly RouteEventRepository $events,
        private readonly RouteRecipientRepository $recipients,
        private readonly RouteEdgeRepository $edges,
        private readonly RoutingRuleRegistry $rules,
        private readonly SettingsService $settings,
        private readonly ?HookManager $hooks = null,
    ) {
    }

    /**
     * Issue a route on a document: create it, its ordered steps, the `issued`
     * trail event, and the first step's recipients — all in one transaction.
     *
     * A route is created COMPLETE. There is no draft state to fill in later, for
     * the reason {@see RouteRepository} records: an authoring state would
     * reintroduce the lifecycle column migration 108 refused, sitting beside an
     * append-only trail and free to disagree with it.
     *
     * Every step is validated BEFORE anything is written, so an author fixing a
     * five-step route is told which step is wrong rather than watching a
     * half-built route appear.
     *
     * @param array<string, mixed>       $document A normalized `documents` row.
     * @param list<array<string, mixed>> $steps    Declared steps in order:
     *        `rule_kind`, optional `rule_config`, optional `label`, #1014's
     *        optional `decision` / `decision_quorum` / `on_approved` /
     *        `on_rejected`, and #1054's optional `satisfied_by`. The two edge
     *        fields name a target by its 1-BASED POSITION in this list, because
     *        ids do not exist until the steps are written and a position is the
     *        only handle an author has while composing.
     * @param ?int    $templateId   #1031: the route TEMPLATE this step list was
     *        converted from, or null for a route composed by hand. Recorded, not
     *        consulted — nothing downstream reads back through it, which is what
     *        makes the instance a snapshot rather than a view of a live design.
     * @param ?string $templateName The design's name AT THE MOMENT OF ISSUE, so a
     *        deleted design still reads correctly in the trail.
     *
     * @return array{route: array<string, mixed>, steps: list<array<string, mixed>>,
     *               edges: list<array<string, mixed>>, resolved: int, delivered: int}
     *
     * @throws RoutingRejectedException When the request is not acceptable (422).
     */
    public function issue(
        int $tenantId,
        ?int $actorId,
        array $document,
        string $title,
        array $steps,
        ?int $templateId = null,
        ?string $templateName = null,
    ): array {
        $documentId = (int) $document['id'];
        $declared = $this->validateSteps($tenantId, $steps);

        // The actor's unit, captured once and reused for the event and for the
        // first step's resolution — so "the unit this was raised from" and "the
        // unit the first rule scoped to" cannot differ within one request.
        $actorOuId = $actorId !== null
            ? PrimaryMembershipOu::forProfile($this->db, $tenantId, $actorId)
            : null;

        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) {
            $this->db->beginTransaction();
        }

        try {
            $routeId = $this->routes->create($tenantId, $documentId, $title, $actorId, $templateId, $templateName);

            $stepIds = [];
            foreach ($declared as $i => $step) {
                $stepIds[] = $this->steps->create(
                    $tenantId,
                    $routeId,
                    $i + 1,
                    $step['rule_kind'],
                    $step['rule_config'],
                    $step['label'],
                    $step['decision'],
                    $step['decision_quorum'],
                    $step['satisfied_by'],
                );
            }

            // EDGES AFTER STEPS, because an edge names two step ids and neither
            // exists until its step is written. The author declared them by
            // POSITION — the only handle they have while composing, since ids are
            // minted here — and this is the one place the two spellings are
            // translated. {@see validateSteps()} has already checked every target
            // is in range, so an out-of-range index cannot reach this loop.
            foreach ($declared as $i => $step) {
                foreach (RouteVerdict::all() as $verdict) {
                    $target = $step['edges'][$verdict] ?? null;
                    if ($target === null) {
                        continue;
                    }
                    $this->edges->create($tenantId, $routeId, $stepIds[$i], $stepIds[$target - 1], $verdict);
                }
            }

            // RESOLVE FIRST, then append, then open the rows. Three steps in
            // that order, and the order is the whole reason this table needs no
            // update path:
            //
            //  - resolution is a pure READ, so it can happen before anything is
            //    written, which means the event can carry its destination unit
            //    from the start instead of being revised once the answer is in;
            //  - the event must exist before the recipient rows, because
            //    `created_by_event_id` is NOT NULL — the dependency has to run
            //    one way for an append-only table to be insertable at all,
            //    which is also why migration 112 puts no `recipient_id` on the
            //    event.
            //
            // A ceiling breach therefore refuses before the trail is touched, so
            // a rejected issue writes literally nothing rather than relying on a
            // rollback to undo an event it should not have appended.
            //
            // #1054: a CHAIN rather than a single step, because a delivery step
            // does not stop the document. If step 1 is one, its people are
            // resolved, told and closed, and the plan carries straight on to
            // step 2 — all still as a pure read, so the single event below can
            // be appended already knowing every unit it reached.
            $first = $this->steps->findById($stepIds[0], $tenantId);
            if ($first === null) {
                throw new \RuntimeException('The first step could not be read back for planning.');
            }
            $plan = $this->planChain($tenantId, $documentId, $routeId, $first, $actorId, $actorOuId);

            $eventId = $this->events->append($tenantId, $documentId, [
                'route_id' => $routeId,
                'step_id' => $stepIds[0],
                'actor_profile_id' => $actorId,
                'action' => RouteAction::ISSUED,
                'from_ou_id' => $actorOuId,
                'to_ou_id' => $plan['destinationOuId'],
                'note' => null,
                // An `issued` is the engine's own act, not a person's answer, so
                // it never carries one.
                'verdict' => null,
            ]);

            $outcome = $this->openChain(
                $tenantId,
                $documentId,
                $routeId,
                $plan['stops'],
                parentRecipientId: null,
                eventId: $eventId,
            );

            $route = $this->routes->findById($routeId, $tenantId);
            $written = $this->steps->listForRoute($routeId, $tenantId);
            $writtenEdges = $this->edges->listForRoute($routeId, $tenantId);

            if ($ownTransaction) {
                $this->db->commit();
            }
        } catch (Throwable $e) {
            if ($ownTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        if ($route === null) {
            // Written and read back inside one transaction; a null here would
            // mean the row vanished between insert and read, which is not a
            // state this method can report meaningfully.
            throw new \RuntimeException('Route was issued but could not be read back.');
        }

        $this->broadcast('document.routed', $tenantId, $documentId, [
            'route_id' => $routeId,
            'action' => RouteAction::ISSUED,
            'actor_profile_id' => $actorId,
            'step_count' => count($written),
            'delivered' => $outcome['delivered'],
            // WHO this act reached, and whether each of them is being ASKED for
            // anything (#1054). A notifier cannot work it out for itself: the
            // recipient rows of a delivery step are already closed by the time
            // any listener runs, so re-reading the open set would find nobody
            // and announce nothing to the very people the step existed to tell.
            'recipients' => $outcome['recipients'],
        ]);

        return [
            'route' => $route,
            'steps' => $written,
            // Read back rather than echoed from the request, so the caller sees
            // the edges as IDS - which is how they will read on every subsequent
            // GET, and how an editor addresses them.
            'edges' => $writtenEdges,
            // Both counts, deliberately. `resolved` is what the rule answered;
            // `delivered` is how many rows that became after de-duplication
            // against chains that already reached those people. Reporting only
            // the second would make an author think a rule found fewer people
            // than it did; reporting only the first would hide that some of them
            // already had the item.
            'resolved' => $outcome['resolved'],
            'delivered' => $outcome['delivered'],
        ];
    }

    /**
     * Apply a recipient's act to a route.
     *
     * `noted` is open to anyone who may see the document — the person best
     * placed to correct the record is often one who has already acted, and
     * their row is closed. The other three require an OPEN recipient row,
     * because being a recipient IS the authorization (migration 113): the route
     * named a rule, the rule resolved to them, and the engine wrote the row.
     *
     * A DECISION STEP (#1014) narrows what is available here, and the narrowing
     * is the feature. See {@see assertActMatchesStep()}: on a gate the only
     * answer is `acknowledged` CARRYING A VERDICT, `forwarded` is refused
     * outright, and where the document goes next is derived from the verdict, the
     * step's edges and the quorum rather than chosen by the person answering.
     *
     * @param array<string, mixed> $route   A normalized `document_routes` row.
     * @param string|null          $verdict `approved` / `rejected` on a decision
     *        step, null everywhere else. {@see RouteVerdict}.
     *
     * @return array{event: array<string, mixed>, resolved: int, delivered: int, decided: ?string}
     *
     * @throws RoutingRejectedException When the act is not available to this
     *         caller on this route (422).
     */
    public function act(
        int $tenantId,
        int $actorId,
        array $route,
        string $action,
        ?string $note,
        ?string $verdict = null,
    ): array {
        $routeId = (int) $route['id'];
        $documentId = (int) $route['document_id'];

        if ($action === RouteAction::NOTED) {
            if ($verdict !== null) {
                throw RoutingRejectedException::because(
                    'A note carries no verdict. A remark on the trail decides nothing and moves nothing - '
                    . 'answer the step itself to approve or reject it.'
                );
            }

            return $this->appendNote($tenantId, $actorId, $routeId, $documentId, $note);
        }

        if (!in_array($action, RouteAction::recipientActions(), true)) {
            // Unreachable through the API, which validates the vocabulary at the
            // boundary, and cheap insurance against an internal caller inventing
            // a verb the CHECK constraint would then refuse mid-write.
            throw RoutingRejectedException::because(
                "'{$action}' is not something a recipient can do; expected one of: "
                . implode(', ', RouteAction::recipientActions())
            );
        }

        $recipient = $this->recipients->findOpenForProfile($tenantId, $routeId, $actorId);
        if ($recipient === null) {
            throw RoutingRejectedException::because(
                'You have no open item on this route. An item you have already acted on cannot be acted on '
                . 'again — add a note instead, which appends to the trail without changing what happened.'
            );
        }

        $step = $this->steps->findById((int) $recipient['step_id'], $tenantId);
        if ($step === null) {
            throw new \RuntimeException('Recipient row names a step that could not be read.');
        }

        $this->assertActMatchesStep($step, $action, $verdict);

        $next = null;
        $returnTo = null;
        // The verdict that RESOLVED this step's cohort, if this act resolved it.
        // Distinct from `$verdict`, which is only what THIS person said: under a
        // quorum of `all` the first two of three approvals decide nothing.
        $decided = null;

        if ($action === RouteAction::FORWARDED) {
            $next = $this->steps->findNext($routeId, $tenantId, (int) $step['position']);
            if ($next === null) {
                throw RoutingRejectedException::because(
                    'This is the last step of the route, so there is nothing to forward to. '
                    . 'Acknowledge it instead.'
                );
            }
        }

        if ($verdict !== null) {
            // Counted BEFORE anything is written, with this person's answer
            // overlaid arithmetically onto their still-open row. That keeps the
            // whole computation a pure read, which is what lets the event be
            // appended already carrying its destination - the property that makes
            // the trail need no update path (see issue()).
            $decided = $this->decide($tenantId, $step, $recipient, $verdict);

            if ($decided !== null) {
                $next = $this->nextForVerdict($tenantId, $routeId, $step, $decided);
            }
        }

        if ($action === RouteAction::RETURNED) {
            $parentId = $recipient['parent_recipient_id'];
            if (!is_int($parentId)) {
                throw RoutingRejectedException::because(
                    'This item came from the first step of the route, so there is no earlier recipient to '
                    . 'return it to. Acknowledge it, or add a note explaining the problem.'
                );
            }
            $returnTo = $this->recipients->findById($parentId, $tenantId);
            if ($returnTo === null) {
                throw new \RuntimeException('Recipient row names a parent that could not be read.');
            }
        }

        // Planned BEFORE the transaction opens, for the reason issue() records:
        // resolution is a pure read, so the event can be appended already
        // carrying its destination, and a ceiling breach refuses without having
        // touched the trail.
        //
        // #1054: a CHAIN, not a single step. `planChain()` walks on past any
        // DELIVERY step it lands on — nobody there will forward the document, so
        // a delivery step that merely closed its own rows would silently strand
        // this chain. Every stop it collects is resolved from the SAME actor and
        // the SAME unit, because none of the people in between acted.
        $plan = $next !== null
            ? $this->planChain(
                $tenantId,
                $documentId,
                $routeId,
                $next,
                $actorId,
                // Resolved relative to the ACTOR, from the unit they were reached
                // through — not their primary membership. A person forwarding an
                // item that arrived via a committee is acting from that
                // committee, and substituting their home department would send
                // the next step somewhere nobody chose. This is semantic 2, in
                // one argument.
                $recipient['ou_id'],
            )
            : ['stops' => [], 'destinationOuId' => null];

        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) {
            $this->db->beginTransaction();
        }

        try {
            $eventId = $this->events->append($tenantId, $documentId, [
                'route_id' => $routeId,
                // The step the actor was acting AT, not the one they are sending
                // to: the fact recorded is "X, holding their step-2 assignment,
                // forwarded". The new rows carry the next step themselves.
                'step_id' => (int) $step['id'],
                'actor_profile_id' => $actorId,
                'action' => $action,
                'from_ou_id' => $recipient['ou_id'],
                // A `returned` has a single, known destination: the unit of the
                // person it goes back to. Every other act's destination comes
                // from the plan above — the single unit its recipients landed in,
                // or null when they span more than one.
                'to_ou_id' => $returnTo !== null ? $returnTo['ou_id'] : $plan['destinationOuId'],
                'note' => $note,
                // What this person DECIDED, which is not the same fact as what
                // the engine then did about it. Null on every act that decided
                // nothing. {@see RouteVerdict}.
                'verdict' => $verdict,
            ]);

            $this->recipients->close($tenantId, (int) $recipient['id'], $eventId);

            if ($decided !== null) {
                // The step is settled, so the people still holding it are being
                // asked a question that now has an answer. Closing their rows
                // here is what stops a second approval firing the same edge and
                // opening the next step twice - and it takes finished work out of
                // an inbox that would otherwise never empty. They appear nowhere
                // in the trail as actors, because they did not act.
                $this->recipients->closeOutstandingCohort(
                    $tenantId,
                    $routeId,
                    (int) $step['id'],
                    (int) $recipient['created_by_event_id'],
                    $eventId,
                );
            }

            $outcome = ['resolved' => 0, 'delivered' => 0, 'recipients' => []];

            if ($plan['stops'] !== []) {
                $outcome = $this->openChain(
                    $tenantId,
                    $documentId,
                    $routeId,
                    $plan['stops'],
                    parentRecipientId: (int) $recipient['id'],
                    eventId: $eventId,
                );
            }

            if ($returnTo !== null) {
                // A NEW row for the predecessor, never an un-closing of their old
                // one: clearing their `closed_by_event_id` would erase the fact
                // that they acted, and that is the trail's business. Migration
                // 112's partial unique index (open rows only) is what makes the
                // second row legal.
                $reopened = $this->recipients->create($tenantId, [
                    'document_id' => $documentId,
                    'route_id' => $routeId,
                    'step_id' => (int) $returnTo['step_id'],
                    'profile_id' => (int) $returnTo['profile_id'],
                    'ou_id' => $returnTo['ou_id'],
                    'parent_recipient_id' => (int) $recipient['id'],
                    'created_by_event_id' => $eventId,
                ]);
                $outcome = [
                    'resolved' => 1,
                    'delivered' => $reopened === null ? 0 : 1,
                    // The predecessor is getting an OPEN item back and is being
                    // asked to do something about it, so they are announced like
                    // any other person a step reached. Empty when the row was
                    // de-duplicated away: they already hold it, and telling
                    // somebody twice about one item is noise, not safety.
                    'recipients' => $reopened === null ? [] : [[
                        'recipient_id' => $reopened,
                        'profile_id' => (int) $returnTo['profile_id'],
                        'step_id' => (int) $returnTo['step_id'],
                        'satisfied_by' => RouteSatisfaction::ACT,
                    ]],
                ];
            }

            $event = $this->events->findById($eventId, $tenantId);

            if ($ownTransaction) {
                $this->db->commit();
            }
        } catch (Throwable $e) {
            if ($ownTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        if ($event === null) {
            throw new \RuntimeException('Routing event was appended but could not be read back.');
        }

        $this->broadcast('document.route_acted', $tenantId, $documentId, [
            'route_id' => $routeId,
            'action' => $action,
            'actor_profile_id' => $actorId,
            'step_id' => (int) $step['id'],
            'delivered' => $outcome['delivered'],
            // Both, because they answer different questions and a listener has to
            // tell them apart: `verdict` is what this person said, `decided` is
            // what the STEP concluded - null while a quorum is still short. A
            // notifier watching only the first would announce a decision on the
            // first of three required approvals.
            'verdict' => $verdict,
            'decided' => $decided,
            // See issue(): who this act reached, and whether each of them is
            // being asked for anything.
            'recipients' => $outcome['recipients'],
        ]);

        return [
            'event' => $event,
            'resolved' => $outcome['resolved'],
            'delivered' => $outcome['delivered'],
            'decided' => $decided,
        ];
    }

    // -- internals ----------------------------------------------------------

    /**
     * Refuse an act that does not match the KIND of step it is being made on
     * (#1014).
     *
     * Every refusal here is loud, and each one exists because the silent version
     * is worse:
     *
     *  - `forwarded` ON A DECISION STEP is refused because `forwarded` means the
     *    ACTOR chose where the document goes, which is the one thing a gate
     *    exists to take away from them. Allowing it would give every approver a
     *    one-click path past the verdict, and the route would look like it had
     *    been approved because the document plainly moved on.
     *
     *  - A VERDICT ON A CIRCULATION STEP is refused rather than ignored. A stored
     *    verdict nothing routes on is a stored intention that silently does
     *    nothing, which is the failure class this whole subsystem is written
     *    against — somebody would read the trail later and conclude a document
     *    had been authorised.
     *
     *  - A DECISION STEP ANSWERED WITHOUT A VERDICT is refused because an
     *    approval step that can be closed by "I saw it" is not an approval step.
     *
     * `returned` stays available on a gate, and carries no verdict. It is the
     * escape — "I am not the person to decide this, take it back" — and it
     * already has a destination of its own (the predecessor's own row), so a
     * verdict on top would give one act two destinations. It also takes the row
     * OUT of the cohort rather than counting against it; see {@see decide()}.
     *
     * @param array<string, mixed> $step
     *
     * @throws RoutingRejectedException
     */
    private function assertActMatchesStep(array $step, string $action, ?string $verdict): void
    {
        $position = (int) $step['position'];
        $isDecision = ($step['decision'] ?? false) === true;

        if ($verdict !== null && !RouteVerdict::isValid($verdict)) {
            throw RoutingRejectedException::because(sprintf(
                "'%s' is not a verdict; expected one of: %s.",
                $verdict,
                implode(', ', RouteVerdict::all()),
            ));
        }

        if ($verdict !== null && $action !== RouteVerdict::carriedBy()) {
            throw RoutingRejectedException::because(sprintf(
                "A verdict is given by acknowledging the step, not by '%s'. Post action '%s' with your "
                . 'verdict instead.',
                $action,
                RouteVerdict::carriedBy(),
            ));
        }

        if (!$isDecision) {
            if ($verdict !== null) {
                throw RoutingRejectedException::because(sprintf(
                    'Step %d is a circulation step, so it takes no verdict — nothing in the route would '
                    . 'act on one, and a recorded approval that changed nothing would read later as an '
                    . 'authorisation that was never asked for.',
                    $position,
                ));
            }

            return;
        }

        if ($action === RouteAction::FORWARDED) {
            throw RoutingRejectedException::because(sprintf(
                'Step %d is a decision step: it is answered with %s plus a verdict, and where the document '
                . 'goes next follows from that verdict. Forwarding would let you choose the destination the '
                . 'step exists to decide.',
                $position,
                RouteVerdict::carriedBy(),
            ));
        }

        if ($action === RouteVerdict::carriedBy() && $verdict === null) {
            throw RoutingRejectedException::because(sprintf(
                'Step %d is a decision step, so answering it needs a verdict: one of %s.',
                $position,
                implode(', ', RouteVerdict::all()),
            ));
        }
    }

    /**
     * Has this act settled the step, and with which verdict?
     *
     * Returns the verdict the STEP concluded with, or null while the answer is
     * still open — which is not the same as the verdict the caller just gave.
     * Under a quorum of `all`, the first two of three approvals conclude nothing.
     *
     * A PURE READ, WITH THE PENDING ANSWER OVERLAID ARITHMETICALLY. Nothing is
     * written before this runs, so the trail event can be appended already
     * carrying its destination — the property that lets the trail have no update
     * path at all (see {@see issue()}).
     *
     * WHAT IS COUNTED
     * ---------------
     * The COHORT: the rows one act opened at this step, identified by
     * `created_by_event_id` ({@see RouteRecipientRepository::listCohort()}).
     * Chains stay independent — two chains reaching the same step each decide for
     * themselves — so migration 112's "distribution fans out, it does not block"
     * survives a feature that is, by its nature, a barrier.
     *
     * Three groups make up the denominator, and one group does not:
     *
     *   approvals   rows closed by an `approved` verdict, plus this act if it is one
     *   rejections  rows closed by a `rejected` verdict, plus this act if it is one
     *   still able   rows still OPEN whose holder is still an active member
     *   ─ excluded ─ rows closed WITHOUT a verdict — a `returned`. That person
     *                left the step rather than deciding at it, and the document
     *                has already gone back to their predecessor; counting them
     *                against a unanimity would make one person's "not mine to
     *                decide" a permanent veto.
     *
     * WHY DEPARTURES SHRINK THE DENOMINATOR AND ARRIVALS DO NOT
     * ---------------------------------------------------------
     * A user group (#999/#1003) resolves LIVE and deliberately stores no
     * membership list, so the set a rule answers with can change between the
     * moment a step was reached and the moment it is decided. This counts the
     * ROWS, which freezes the set at the instant the question was put — so an
     * instructor hired afterwards was never asked, holds no item, and cannot
     * silently raise the bar on a decision already under way.
     *
     * The one live input is the other direction. An open row whose holder is no
     * longer an active member is dropped from the count by
     * {@see ActiveMemberFilter}, which is the single definition of that predicate
     * in the codebase rather than a second copy of it here. Without that, `all`
     * plus one suspended account is a route stuck for ever with no remedy an
     * operator could apply — migration 112's barrier hazard arriving by the back
     * door. A departure never counts as an approval: their row is closed as
     * undecided, and the trail records only the approvals actually given.
     *
     * @param array<string, mixed> $step
     * @param array<string, mixed> $recipient The acting person's own open row.
     *
     * @return string|null The verdict the step concluded with, or null.
     */
    private function decide(int $tenantId, array $step, array $recipient, string $verdict): ?string
    {
        $rows = $this->recipients->listCohort(
            $tenantId,
            (int) $recipient['route_id'],
            (int) $step['id'],
            (int) $recipient['created_by_event_id'],
        );

        $approvals = 0;
        $rejections = 0;
        /** @var list<ResolvedRecipient> $stillOpen */
        $stillOpen = [];

        foreach ($rows as $row) {
            if ((int) $row['id'] === (int) $recipient['id']) {
                // This act, not yet written. Overlaid here rather than after the
                // insert so the whole computation stays a read.
                if ($verdict === RouteVerdict::APPROVED) {
                    $approvals++;
                } else {
                    $rejections++;
                }
                continue;
            }

            if ($row['closed_by_event_id'] === null) {
                $stillOpen[] = new ResolvedRecipient((int) $row['profile_id'], $row['ou_id']);
                continue;
            }

            if ($row['closing_verdict'] === RouteVerdict::APPROVED) {
                $approvals++;
                continue;
            }

            if ($row['closing_verdict'] === RouteVerdict::REJECTED) {
                $rejections++;
            }

            // Closed with no verdict: a `returned`. Out of the cohort entirely.
        }

        $stillAble = count(ActiveMemberFilter::apply($this->db, $tenantId, $stillOpen));
        $cohortSize = $approvals + $rejections + $stillAble;
        $quorum = $this->approvalQuorum($tenantId, $step);

        if (RouteQuorum::approvalCarried($quorum, $approvals, $stillAble, $cohortSize)) {
            return RouteVerdict::APPROVED;
        }

        if (RouteQuorum::approvalImpossible($quorum, $approvals, $stillAble, $cohortSize)) {
            return RouteVerdict::REJECTED;
        }

        return null;
    }

    /**
     * Where a settled verdict sends the document — and this is the method #1014
     * exists for.
     *
     * The two verdicts are deliberately NOT symmetric when the author drew no
     * edge:
     *
     *   APPROVED with no edge -> the next authoring ordinal. #1014's own words
     *       are "the route continues to the next step", and it is what makes a
     *       gate usable in an ordinary linear route: mark a step as a decision
     *       and it becomes an approval, with no graph to author.
     *
     *   REJECTED with no edge -> NOWHERE. The chain ends, the act is recorded,
     *       and nothing further opens.
     *
     * The fallback a rejection must NEVER get is the ordinal successor. That is
     * precisely the failure #1014 is written against: "a rejection that merely
     * records dissent and lets the document proceed is not approval". It is also
     * the failure that is invisible — the trail says `rejected`, the document
     * moves on exactly as an approved one would, and every screen looks correct.
     * {@see \Tests\Core\Document\Routing\DocumentRouterVerdictRealEngineTest}
     * asserts it directly rather than trusting this comment.
     *
     * @param array<string, mixed> $step
     * @return array<string, mixed>|null
     */
    private function nextForVerdict(int $tenantId, int $routeId, array $step, string $decided): ?array
    {
        $edge = $this->edges->findTarget($tenantId, (int) $step['id'], $decided);

        if ($edge !== null) {
            $target = $this->steps->findById((int) $edge['to_step_id'], $tenantId);
            if ($target === null) {
                // Both rows are written in one transaction and the FK cascades
                // together, so this is unreachable short of the row vanishing
                // between two reads.
                throw new \RuntimeException('A route edge names a step that could not be read.');
            }

            return $target;
        }

        if ($decided === RouteVerdict::REJECTED) {
            return null;
        }

        return $this->steps->findNext($routeId, $tenantId, (int) $step['position']);
    }

    /**
     * The quorum in force for a step: step override, then per-tenant, then
     * global, then the registry default.
     *
     * Four layers rather than the usual three, and the extra one is on TOP: a
     * step may name its own rule, and a step that names none defers to the
     * ordinary settings chain. That is what lets a deployment which never
     * configures anything work from the registry default, and a tenant that
     * changes its mind change every step at once without a single row being
     * rewritten.
     *
     * A stored value outside the vocabulary falls back rather than being obeyed,
     * and it falls back TOWARDS THE STRICTEST rule. The value has already passed
     * a CHECK constraint and a settings validator to get here, so a foreign
     * string means something upstream is broken — and the safe reading of a
     * broken approval rule is never the most permissive one. See
     * {@see RouteQuorum} for why the default is `all`.
     *
     * @param array<string, mixed> $step
     */
    private function approvalQuorum(int $tenantId, array $step): string
    {
        $onStep = $step['decision_quorum'] ?? null;
        if (is_string($onStep) && RouteQuorum::isValid($onStep)) {
            return $onStep;
        }

        return $this->defaultQuorum($tenantId);
    }

    /**
     * The bottom three layers of {@see approvalQuorum()}: what a step that names
     * NO quorum of its own actually does in this tenant.
     *
     * Public because a READER needs the same answer the engine will use (#1041).
     * A recipient standing on a decision step whose `decision_quorum` is null —
     * which is most of them — cannot otherwise be told whether their approval
     * carries the gate or is one of four hundred required, and the settings chain
     * that holds the answer is behind `settings:read`, which that person will not
     * hold. `RoutingPresenter::route()` publishes what this returns.
     *
     * Extracted rather than reimplemented on the API handler on purpose: a second
     * copy of this fallback ladder is a copy that can disagree with the engine
     * about what a route is going to do, and it would disagree SILENTLY — the
     * screen would name one rule and the engine apply another, which is the exact
     * class of failure {@see RouteQuorum} exists to make impossible.
     */
    public function defaultQuorum(int $tenantId): string
    {
        $effective = $this->settings->effective($tenantId);
        $configured = $effective[SettingsRegistry::DOCUMENTS_ROUTING_APPROVAL_QUORUM] ?? null;
        if (is_string($configured) && RouteQuorum::isValid($configured)) {
            return $configured;
        }

        $default = SettingsRegistry::defaults()[SettingsRegistry::DOCUMENTS_ROUTING_APPROVAL_QUORUM] ?? null;

        return is_string($default) && RouteQuorum::isValid($default) ? $default : RouteQuorum::ALL;
    }

    /**
     * Append a `noted` event. Closes nothing, opens nothing.
     *
     * The correction mechanism: the trail has no update and no delete path, so a
     * mistaken note, a wrong unit or a misspelled name is answered by appending
     * beside it. Both rows survive, which is more useful as well as safer —
     * "this was corrected on the 14th" is itself a fact somebody may need.
     *
     * @return array{event: array<string, mixed>, resolved: int, delivered: int, decided: null}
     */
    private function appendNote(
        int $tenantId,
        int $actorId,
        int $routeId,
        int $documentId,
        ?string $note,
    ): array {
        if ($note === null || trim($note) === '') {
            throw RoutingRejectedException::because('A note needs some text — an empty note records nothing.');
        }

        // The note is attributed to the unit the author is acting from. Their
        // OPEN recipient row is preferred when they have one, because that is
        // the unit this document actually reached them through; otherwise their
        // primary membership, which is the best available answer for a raiser or
        // an observer who was never a recipient.
        $open = $this->recipients->findOpenForProfile($tenantId, $routeId, $actorId);
        $fromOuId = $open !== null
            ? $open['ou_id']
            : PrimaryMembershipOu::forProfile($this->db, $tenantId, $actorId);

        $eventId = $this->events->append($tenantId, $documentId, [
            'route_id' => $routeId,
            'step_id' => $open !== null ? (int) $open['step_id'] : null,
            'actor_profile_id' => $actorId,
            'action' => RouteAction::NOTED,
            'from_ou_id' => $fromOuId,
            'to_ou_id' => null,
            'note' => $note,
            'verdict' => null,
        ]);

        $event = $this->events->findById($eventId, $tenantId);
        if ($event === null) {
            throw new \RuntimeException('Routing note was appended but could not be read back.');
        }

        $this->broadcast('document.route_acted', $tenantId, $documentId, [
            'route_id' => $routeId,
            'action' => RouteAction::NOTED,
            'actor_profile_id' => $actorId,
            'step_id' => $open !== null ? (int) $open['step_id'] : null,
            'delivered' => 0,
            // A note reaches nobody: it closes nothing and opens nothing. The
            // key is present rather than absent so every routing broadcast has
            // one shape and a subscriber needs no special case for the verb that
            // happens to deliver to no one.
            'recipients' => [],
        ]);

        return ['event' => $event, 'resolved' => 0, 'delivered' => 0, 'decided' => null];
    }

    /**
     * Plan every step ONE ACT REACHES, walking on past each DELIVERY step
     * (#1054). Writes nothing.
     *
     * WHY A CHAIN AND NOT A STEP
     * --------------------------
     * A delivery step is satisfied by reaching it, so nobody standing on it will
     * ever forward the document. A version of this feature that closed a
     * delivery step's rows and stopped would therefore leave a delivery step in
     * the MIDDLE of a route as a silent dead end: every chain that reached it
     * would end, the trail would show a document that had travelled normally,
     * and no screen anywhere would report a problem. That is the exact failure
     * class this subsystem is written against, so "non-blocking" is implemented
     * as what it says — the document does not stop.
     *
     * Every stop is resolved from THE SAME actor and THE SAME unit, because
     * nobody in between acted. The people at a delivery step do not become the
     * origin of anything: substituting one of them would make the next step
     * resolve relative to somebody who was copied in, which is not a position
     * anybody authored.
     *
     * STILL A PURE READ, which is the property the whole engine is built on: one
     * event is appended afterwards already carrying its destination, and a
     * ceiling breach anywhere in the chain refuses before the trail is touched.
     * {@see issue()} records the argument in full.
     *
     * THE DESTINATION IS THE UNION. `to_ou_id` is set when every person the act
     * reached — across every stop — is in exactly ONE unit, and null otherwise.
     * That is {@see planStep()}'s rule generalised rather than a new one, and it
     * stays honest: an act that told a faculty and then opened a step in the
     * registry has no single destination, and naming one would make #947 item
     * 5's "passed through my unit" folder report a unit that was never involved.
     * For an ordinary route (one stop) it is identical to what planStep answers,
     * so nothing authored before this migration reads differently.
     *
     * @param array<string, mixed> $firstStep The step the act reaches first.
     *
     * @return array{stops: list<array{step: array<string, mixed>, members: list<ResolvedRecipient>}>,
     *               destinationOuId: int|null}
     */
    private function planChain(
        int $tenantId,
        int $documentId,
        int $routeId,
        array $firstStep,
        ?int $actorId,
        ?int $actorOuId,
    ): array {
        $stops = [];
        $units = [];
        $seen = [];
        $step = $firstStep;

        while (true) {
            $stepId = (int) $step['id'];

            if (isset($seen[$stepId])) {
                // Unreachable while {@see RouteStepRepository::findNext()}
                // answers by strictly increasing ordinal, which is why this is a
                // RuntimeException rather than a refusal a caller could act on.
                // It is here because the day that method is rewritten to follow
                // edges — which migration 112 explicitly leaves room for — a
                // cycle of delivery steps becomes expressible, and the failure it
                // would otherwise produce is an endless loop inside a request.
                throw new \RuntimeException(
                    'A chain of delivery steps revisited step ' . $stepId . '; route step succession must '
                    . 'be acyclic.'
                );
            }
            $seen[$stepId] = true;

            $plan = $this->planStep(
                $tenantId,
                $documentId,
                $routeId,
                $stepId,
                (int) $step['position'],
                $actorId,
                $actorOuId,
            );

            $stops[] = ['step' => $step, 'members' => $plan['members']];
            foreach ($plan['members'] as $member) {
                if ($member->ouId !== null) {
                    $units[$member->ouId] = true;
                }
            }

            if (!RouteSatisfaction::isDelivery($step['satisfied_by'] ?? null)) {
                break;
            }

            // A delivery step never branches: it cannot be a decision step, so it
            // has no edges, and its successor is the next authoring ordinal —
            // the same answer a plain forward gets, from the same method.
            $next = $this->steps->findNext($routeId, $tenantId, (int) $step['position']);
            if ($next === null) {
                // The ordinary shape of the motivating case: the last stage puts
                // the document in front of everybody and the route is done.
                break;
            }

            $step = $next;
        }

        return [
            'stops' => $stops,
            'destinationOuId' => count($units) === 1 ? (int) array_key_first($units) : null,
        ];
    }

    /**
     * Work out WHO a step reaches and WHERE the act is directed, writing nothing.
     *
     * Pure read, deliberately, and that is what lets the trail have no update
     * path: the event can be appended already carrying its `to_ou_id` instead of
     * being revised once the rule has answered. It also means a ceiling breach
     * is refused before a single row exists.
     *
     * `destinationOuId` is set when every person the act reached is in exactly
     * ONE unit, and null otherwise. The rule is generic — it holds for a
     * plugin's unit-scoped rule as much as for core's — and it is the honest
     * one: a distribution spanning three units has no single destination, and
     * naming one would make #947 item 5's "passed through my unit" folder report
     * a unit that was never involved.
     *
     * @return array{members: list<ResolvedRecipient>, destinationOuId: int|null}
     */
    private function planStep(
        int $tenantId,
        int $documentId,
        int $routeId,
        int $stepId,
        int $position,
        ?int $actorId,
        ?int $actorOuId,
    ): array {
        $step = $this->steps->findById($stepId, $tenantId);
        if ($step === null) {
            throw new \RuntimeException('Step could not be read back for planning.');
        }

        $resolved = $this->resolveStep($tenantId, $documentId, $routeId, $step, $position, $actorId, $actorOuId);
        // The security boundary for plugin-supplied rules, and it lives in
        // {@see ActiveMemberFilter} rather than here since #999 — a named user
        // group resolving its own rule needs the identical check, and two copies
        // of a security boundary are two things to update when the membership
        // model changes, with the missed one being the copy nobody was looking
        // at. The behaviour is unchanged: de-duplicate by profile, keep only
        // active members of THIS tenant, drop the rest rather than failing.
        $members = ActiveMemberFilter::apply($this->db, $tenantId, $resolved);

        $ceiling = $this->maxRecipientsPerStep($tenantId);
        if (count($members) > $ceiling) {
            // A refusal, not a truncation. Delivering to the first N of M
            // silently is the stored-list failure in another costume: it would
            // report success while omitting people, which is the single outcome
            // this whole item exists to prevent. The number is named because it
            // is tenant-configurable and therefore unknowable from outside.
            throw RoutingRejectedException::because(sprintf(
                "Step %d resolved to %d recipients, over this tenant's limit of %d for a single step. "
                . 'Narrow the rule, or raise documents.routing_max_recipients_per_step.',
                $position,
                count($members),
                $ceiling,
            ));
        }

        $units = [];
        foreach ($members as $recipient) {
            if ($recipient->ouId !== null) {
                $units[$recipient->ouId] = true;
            }
        }

        return [
            'members' => $members,
            'destinationOuId' => count($units) === 1 ? (int) array_key_first($units) : null,
        ];
    }

    /**
     * Open the inbox rows for every stop one act reached, and CLOSE the ones
     * that were only ever going to be told (#1054).
     *
     * THE WHOLE OF "SATISFIED BY DELIVERY" IS THE TWO LINES THAT CLOSE A ROW
     * -----------------------------------------------------------------------
     * A delivery stop's rows are closed by `close()` naming THE EVENT THAT
     * CREATED THEM. That is deliberate, and it is what makes the close both
     * honest and unmistakable:
     *
     *  - NO NEW EVENT IS APPENDED. There is no verb for "the system delivered
     *    this", and inventing one out of the existing five would have the trail
     *    assert that a person did something they did not. What the trail says is
     *    exactly what happened: somebody issued or forwarded a document, and the
     *    recipient rows record who that reached.
     *
     *  - `closed_by_event_id = created_by_event_id` IS A SHAPE NO HUMAN ACT CAN
     *    PRODUCE. Acting closes a row with a LATER event than the one that
     *    opened it — always, because the act has to happen after the arrival.
     *    So a reader can tell the two apart from the row alone, without knowing
     *    anything about steps.
     *
     *  - AND THEY DO NOT HAVE TO. The authoritative answer is the STEP's
     *    `satisfied_by`, which is why the column is there: every row at a
     *    delivery step is delivery-closed by construction, so the inbox reads it
     *    off the join it already does rather than inferring it from a pointer
     *    comparison somebody could later break without noticing.
     *
     * THE ROW IS STILL WRITTEN, and that is not a formality. `document_route_
     * recipients` is the only place that records WHICH PEOPLE a rule actually
     * resolved to and when — rules resolve against the organisation as it stood
     * at that instant, and replaying one later answers a different question. Not
     * writing the row would make "who was told?" unanswerable for exactly the
     * distribution most likely to be asked about.
     *
     * @param list<array{step: array<string, mixed>, members: list<ResolvedRecipient>}> $stops
     * @return array{resolved: int, delivered: int,
     *               recipients: list<array{recipient_id: int, profile_id: int, step_id: int, satisfied_by: string}>}
     */
    private function openChain(
        int $tenantId,
        int $documentId,
        int $routeId,
        array $stops,
        ?int $parentRecipientId,
        int $eventId,
    ): array {
        $resolved = 0;
        $delivered = 0;
        $reached = [];

        foreach ($stops as $stop) {
            $step = $stop['step'];
            $stepId = (int) $step['id'];
            $satisfiedBy = RouteSatisfaction::isDelivery($step['satisfied_by'] ?? null)
                ? RouteSatisfaction::DELIVERY
                : RouteSatisfaction::ACT;

            $resolved += count($stop['members']);

            foreach ($stop['members'] as $member) {
                $id = $this->recipients->create($tenantId, [
                    'document_id' => $documentId,
                    'route_id' => $routeId,
                    'step_id' => $stepId,
                    'profile_id' => $member->profileId,
                    'ou_id' => $member->ouId,
                    // The ACTOR's row for every stop in the chain, including the
                    // ones after a delivery step. Nobody at a delivery step
                    // acted, so none of them produced anything — and this is
                    // what keeps `returned` meaningful: it goes back to the
                    // person who actually sent the document, not to somebody who
                    // was merely copied on the way past and has no open row to
                    // receive it.
                    'parent_recipient_id' => $parentRecipientId,
                    'created_by_event_id' => $eventId,
                ]);

                if ($id === null) {
                    // Another chain already put this item in front of them.
                    // Counted as resolved-but-not-delivered, and NOT announced:
                    // they hold it already, and telling somebody twice about one
                    // item is noise rather than safety.
                    continue;
                }

                $delivered++;

                if ($satisfiedBy === RouteSatisfaction::DELIVERY) {
                    // Closed by the event that opened it. See the docblock — the
                    // single mutation in the routing tables, setting one column
                    // to a real trail row id, so it cannot make a claim the
                    // trail does not already make.
                    $this->recipients->close($tenantId, $id, $eventId);
                }

                $reached[] = [
                    'recipient_id' => $id,
                    'profile_id' => $member->profileId,
                    'step_id' => $stepId,
                    'satisfied_by' => $satisfiedBy,
                ];
            }
        }

        // Both counts: `resolved` is what the rules answered across every stop,
        // `delivered` how many rows that became after de-duplicating against
        // chains that already reached those people.
        return ['resolved' => $resolved, 'delivered' => $delivered, 'recipients' => $reached];
    }

    /**
     * Ask the step's registered rule who it reaches.
     *
     * A kind nothing registered fails LOUDLY and by name. Migration 112
     * deliberately puts no foreign key on `rule_kind`, because the catalogue is
     * code rather than rows and an uninstalled plugin leaving steps behind is a
     * real state — so the failure has to say which kind is missing. Skipping
     * such a step would drop a whole class of people from a distribution and
     * report success.
     *
     * @param array<string, mixed> $step
     * @return list<ResolvedRecipient>
     */
    private function resolveStep(
        int $tenantId,
        int $documentId,
        int $routeId,
        array $step,
        int $position,
        ?int $actorId,
        ?int $actorOuId,
    ): array {
        $kind = (string) $step['rule_kind'];
        $resolver = $this->rules->get($kind);
        if ($resolver === null) {
            throw RoutingRejectedException::because(sprintf(
                "Step %d names the routing rule '%s', which nothing on this instance provides. "
                . 'The plugin that supplied it may have been removed.',
                $position,
                $kind,
            ));
        }

        /** @var array<string, mixed> $config */
        $config = is_array($step['rule_config']) ? $step['rule_config'] : [];

        $context = new RoutingRuleContext(
            tenantId: $tenantId,
            documentId: $documentId,
            routeId: $routeId,
            stepId: (int) $step['id'],
            position: $position,
            actorProfileId: $actorId,
            actorOuId: $actorOuId,
            config: $config,
        );

        try {
            return $resolver->resolve($context);
        } catch (InvalidArgumentException $e) {
            // The rule is telling the caller its config is unusable, in words
            // written for them. Same treatment as authoring-time validation.
            throw RoutingRejectedException::because(sprintf('Step %d: %s', $position, $e->getMessage()));
        } catch (Throwable $e) {
            // A resolver failing at RUN time is plugin code misbehaving, not a
            // message for the caller — so its text is logged and withheld. The
            // caller is told which step could not be resolved, which is what
            // they can act on, and nothing is committed: a half-resolved
            // distribution is worse than a refused one.
            error_log("[DocumentRouter] routing rule '{$kind}' failed to resolve: " . $e->getMessage());

            throw RoutingRejectedException::because(sprintf(
                "Step %d could not be resolved by the routing rule '%s'.",
                $position,
                $kind,
            ));
        }
    }

    /**
     * Validate every declared step, then hand back the normalized list.
     *
     * Nothing is written until this returns, so a route is never half-built.
     *
     * `validate()` is the rule's own, and its message reaches the author
     * verbatim — see {@see RoutingRejectedException} for why that text needs a
     * field of its own rather than travelling as a throwable message.
     *
     * #1014 adds three more things a step may declare, and each one is REFUSED
     * rather than ignored when it cannot mean anything — see the checks below.
     * An ignored declaration is a stored intention that silently does nothing,
     * which is the failure this whole subsystem is written against.
     *
     * @param list<array<string, mixed>> $steps
     * @return list<array{rule_kind: string, rule_config: array<string, mixed>, label: ?string,
     *                    decision: bool, decision_quorum: ?string, satisfied_by: string,
     *                    edges: array<string, int>}>
     */
    private function validateSteps(int $tenantId, array $steps): array
    {
        if ($steps === []) {
            throw RoutingRejectedException::because(
                'A route needs at least one step. A route with none would issue a document to nobody '
                . 'and record it as sent.'
            );
        }

        $maxSteps = $this->maxSteps($tenantId);
        if (count($steps) > $maxSteps) {
            throw RoutingRejectedException::because(sprintf(
                "This route declares %d steps, over this tenant's limit of %d. "
                . 'Raise documents.routing_max_steps if the route genuinely needs them.',
                count($steps),
                $maxSteps,
            ));
        }

        $out = [];
        foreach (array_values($steps) as $i => $step) {
            $position = $i + 1;

            $kind = $step['rule_kind'] ?? null;
            if (!is_string($kind) || $kind === '') {
                throw RoutingRejectedException::because(
                    "Step {$position} must name a routing rule in 'rule_kind'."
                );
            }
            if (!RoutingRuleRegistry::isValidKind($kind)) {
                throw RoutingRejectedException::because(
                    "Step {$position}: '{$kind}' is not a well-formed routing rule kind."
                );
            }

            $resolver = $this->rules->get($kind);
            if ($resolver === null) {
                throw RoutingRejectedException::because(sprintf(
                    "Step %d names the routing rule '%s', which nothing on this instance provides.",
                    $position,
                    $kind,
                ));
            }

            $config = $step['rule_config'] ?? [];
            if (!is_array($config)) {
                throw RoutingRejectedException::because(
                    "Step {$position}: 'rule_config' must be an object."
                );
            }

            try {
                $resolver->validate($config);
            } catch (InvalidArgumentException $e) {
                throw RoutingRejectedException::because(sprintf('Step %d: %s', $position, $e->getMessage()));
            }

            $label = $step['label'] ?? null;
            if ($label !== null && !is_string($label)) {
                throw RoutingRejectedException::because("Step {$position}: 'label' must be a string when present.");
            }

            $decision = $step['decision'] ?? false;
            if (!is_bool($decision)) {
                throw RoutingRejectedException::because(
                    "Step {$position}: 'decision' must be true or false when present."
                );
            }

            // #1054. WHAT SETTLES THIS STEP, defaulting to what every step has
            // always meant.
            $satisfiedBy = $step['satisfied_by'] ?? RouteSatisfaction::fallback();
            if (!is_string($satisfiedBy) || !RouteSatisfaction::isValid($satisfiedBy)) {
                throw RoutingRejectedException::because(sprintf(
                    "Step %d: 'satisfied_by' must be one of: %s.",
                    $position,
                    implode(', ', RouteSatisfaction::all()),
                ));
            }

            if ($satisfiedBy === RouteSatisfaction::DELIVERY && $decision) {
                // THE ONE COMBINATION THAT CANNOT MEAN ANYTHING, and it is
                // refused here rather than left to fail later, because later it
                // would not fail at all — it would sit there looking like an
                // approval nobody had got round to.
                //
                // A quorum is counted over the recipient rows one act opened
                // (migration 119). A delivery step closes every one of those rows
                // the instant it creates them, so there would be nobody holding
                // an item, nobody able to answer, and no act that could ever
                // settle the gate. The route would stop at a step whose whole
                // point was not to stop it.
                throw RoutingRejectedException::because(sprintf(
                    'Step %d asks to be satisfied by delivery AND to be a decision step. It cannot be '
                    . 'both: a decision needs somebody holding the item to answer it, and a delivery step '
                    . 'closes every item the moment it is sent. Drop one of the two.',
                    $position,
                ));
            }

            $quorum = $step['decision_quorum'] ?? null;
            if ($quorum !== null) {
                if (!is_string($quorum) || !RouteQuorum::isValid($quorum)) {
                    throw RoutingRejectedException::because(sprintf(
                        "Step %d: 'decision_quorum' must be one of: %s.",
                        $position,
                        implode(', ', RouteQuorum::all()),
                    ));
                }
                if (!$decision) {
                    // Refused, not ignored. A quorum on a step that demands no
                    // verdict is a number nothing ever reads, and the author
                    // believes they have configured an approval.
                    throw RoutingRejectedException::because(
                        "Step {$position}: 'decision_quorum' only means something on a decision step. "
                        . "Set 'decision' to true, or drop the quorum."
                    );
                }
            }

            $edges = [];
            foreach (RouteVerdict::all() as $verdict) {
                $key = 'on_' . $verdict;
                $target = $step[$key] ?? null;
                if ($target === null) {
                    continue;
                }
                if (!$decision) {
                    throw RoutingRejectedException::because(
                        "Step {$position}: '{$key}' only means something on a decision step, because nothing "
                        . 'else can produce a verdict to follow it. Set \'decision\' to true, or drop the edge.'
                    );
                }
                if (!is_int($target)) {
                    throw RoutingRejectedException::because(
                        "Step {$position}: '{$key}' must be the 1-based position of another step in this route."
                    );
                }
                if ($target < 1 || $target > count($steps)) {
                    throw RoutingRejectedException::because(sprintf(
                        "Step %d: '%s' points at position %d, which this route does not have "
                        . '(it declares %d steps).',
                        $position,
                        $key,
                        $target,
                        count($steps),
                    ));
                }
                if ($target === $position) {
                    // A self-edge is the one graph shape with no reading at all:
                    // the step would re-open itself for the people who just
                    // decided it, for ever, with no act able to leave it. Edges
                    // pointing BACKWARDS are legal and intended — 'rejected goes
                    // back to the author for correction' is the main reason this
                    // table exists.
                    throw RoutingRejectedException::because(
                        "Step {$position}: '{$key}' cannot point at the step itself."
                    );
                }
                $edges[$verdict] = $target;
            }

            /** @var array<string, mixed> $config */
            $out[] = [
                'rule_kind' => $kind,
                'rule_config' => $config,
                'label' => is_string($label) && trim($label) !== '' ? trim($label) : null,
                'decision' => $decision,
                'decision_quorum' => is_string($quorum) ? $quorum : null,
                'satisfied_by' => $satisfiedBy,
                'edges' => $edges,
            ];
        }

        return $out;
    }

    /**
     * Per-tenant, then global, then the registry default. Never hardcoded.
     */
    private function maxSteps(int $tenantId): int
    {
        return $this->positiveSetting($tenantId, SettingsRegistry::DOCUMENTS_ROUTING_MAX_STEPS);
    }

    private function maxRecipientsPerStep(int $tenantId): int
    {
        return $this->positiveSetting($tenantId, SettingsRegistry::DOCUMENTS_ROUTING_MAX_RECIPIENTS_PER_STEP);
    }

    /**
     * Resolve a numeric ceiling through the settings chain.
     *
     * `effective()` already layers the tenant override over the global value;
     * the registry default is the last resort, for a database whose settings
     * rows have not been seeded. A non-numeric or non-positive stored value
     * falls back to the default rather than disabling the ceiling: a "0" typed
     * into a settings field must not silently mean "no limit".
     */
    private function positiveSetting(int $tenantId, string $key): int
    {
        $effective = $this->settings->effective($tenantId);
        $raw = $effective[$key] ?? null;
        if (is_string($raw) && preg_match('/^\d+$/', $raw) === 1 && (int) $raw > 0) {
            return (int) $raw;
        }

        $default = SettingsRegistry::defaults()[$key] ?? '1';

        return max(1, (int) $default);
    }

    /**
     * Emit one appended trail event onto the platform's durable event spine.
     *
     * AFTER the commit, and never inside the transaction — see the class
     * docblock. `dispatchAsync` persists to `domain_events` + the outbox relay
     * (migration 066) and is documented as non-critical, but a plugin listener
     * runs synchronously inside it, and one that throws inside our transaction
     * would roll back a routing act that had already succeeded.
     *
     * The `.async` suffix is the convention `HookManager::dispatchAsync()`
     * derives its aggregate from; the aggregate is the DOCUMENT, because a
     * routing event is something that happened to a document and that is what a
     * consumer will be watching.
     *
     * @param array<string, mixed> $payload
     */
    private function broadcast(string $event, int $tenantId, int $documentId, array $payload): void
    {
        if ($this->hooks === null) {
            return;
        }

        $full = $payload + [
            'id' => $documentId,
            'document_id' => $documentId,
            'tenant_id' => $tenantId,
        ];

        // THE SYNCHRONOUS HALF, AND WHY IT IS NOT REDUNDANT (#1054).
        //
        // `dispatchAsync()` PERSISTS an event; it does not run listeners. Nothing
        // in this codebase drains `event_outbox` yet, so a subscriber registered
        // with `HookManager::listen()` against a routing event would never have
        // been called at all — it would have looked wired up, logged nothing and
        // done nothing. #1054 predicted "a subscriber is what is missing"; the
        // accurate version is that a subscriber had nothing to subscribe TO.
        //
        // So routing now emits BOTH, which is the shape every other core writer
        // already uses ({@see \Whity\Api\OusApiHandler}, {@see
        // \Whity\Api\RolesApiHandler}): `x` synchronously for listeners, and
        // `x.async` onto the durable spine for the relay. One trail, two
        // broadcasts of it, and neither is ever read as authoritative.
        //
        // TWO try/catch BLOCKS, not one. A plugin listener that throws inside
        // the first must not cost us the durable append in the second — the
        // spine is the record that survives a bad listener, so it is exactly the
        // thing that must not share a failure path with one.
        try {
            $this->hooks->dispatch($event, $full);
        } catch (Throwable $e) {
            // AFTER THE COMMIT, and this is why the class docblock insists on
            // that ordering: a listener runs synchronously right here, and one
            // that threw inside our transaction would roll back a routing act
            // that had already succeeded. A person told they had forwarded
            // something, who had not, is a far worse outcome than a notification
            // nobody sent.
            error_log('[DocumentRouter] a listener on a routing event failed: ' . $e->getMessage());
        }

        try {
            $this->hooks->dispatchAsync($event . '.async', $full);
        } catch (Throwable $e) {
            // The trail is already committed and is the system of record. A
            // broadcast that could not be recorded is a missed notification, not
            // a lost routing act, so it is logged and swallowed rather than
            // turning a successful forward into a 500 for the person who did it.
            error_log('[DocumentRouter] emitting a routing event to the spine failed: ' . $e->getMessage());
        }
    }
}
