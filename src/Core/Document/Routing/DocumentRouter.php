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
     *        `rule_kind`, optional `rule_config`, optional `label`.
     *
     * @return array{route: array<string, mixed>, steps: list<array<string, mixed>>,
     *               resolved: int, delivered: int}
     *
     * @throws RoutingRejectedException When the request is not acceptable (422).
     */
    public function issue(int $tenantId, ?int $actorId, array $document, string $title, array $steps): array
    {
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
            $routeId = $this->routes->create($tenantId, $documentId, $title, $actorId);

            $stepIds = [];
            foreach ($declared as $i => $step) {
                $stepIds[] = $this->steps->create(
                    $tenantId,
                    $routeId,
                    $i + 1,
                    $step['rule_kind'],
                    $step['rule_config'],
                    $step['label'],
                );
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
            $plan = $this->planStep($tenantId, $documentId, $routeId, $stepIds[0], 1, $actorId, $actorOuId);

            $eventId = $this->events->append($tenantId, $documentId, [
                'route_id' => $routeId,
                'step_id' => $stepIds[0],
                'actor_profile_id' => $actorId,
                'action' => RouteAction::ISSUED,
                'from_ou_id' => $actorOuId,
                'to_ou_id' => $plan['destinationOuId'],
                'note' => null,
            ]);

            $outcome = $this->openInboxRows(
                $tenantId,
                $documentId,
                $routeId,
                $stepIds[0],
                $plan['members'],
                parentRecipientId: null,
                eventId: $eventId,
            );

            $route = $this->routes->findById($routeId, $tenantId);
            $written = $this->steps->listForRoute($routeId, $tenantId);

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
        ]);

        return [
            'route' => $route,
            'steps' => $written,
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
     * @param array<string, mixed> $route A normalized `document_routes` row.
     *
     * @return array{event: array<string, mixed>, resolved: int, delivered: int}
     *
     * @throws RoutingRejectedException When the act is not available to this
     *         caller on this route (422).
     */
    public function act(int $tenantId, int $actorId, array $route, string $action, ?string $note): array
    {
        $routeId = (int) $route['id'];
        $documentId = (int) $route['document_id'];

        if ($action === RouteAction::NOTED) {
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

        $next = null;
        $returnTo = null;

        if ($action === RouteAction::FORWARDED) {
            $next = $this->steps->findNext($routeId, $tenantId, (int) $step['position']);
            if ($next === null) {
                throw RoutingRejectedException::because(
                    'This is the last step of the route, so there is nothing to forward to. '
                    . 'Acknowledge it instead.'
                );
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
        $plan = $next !== null
            ? $this->planStep(
                $tenantId,
                $documentId,
                $routeId,
                (int) $next['id'],
                (int) $next['position'],
                $actorId,
                // Resolved relative to the ACTOR, from the unit they were reached
                // through — not their primary membership. A person forwarding an
                // item that arrived via a committee is acting from that
                // committee, and substituting their home department would send
                // the next step somewhere nobody chose. This is semantic 2, in
                // one argument.
                $recipient['ou_id'],
            )
            : ['members' => [], 'destinationOuId' => null];

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
            ]);

            $this->recipients->close($tenantId, (int) $recipient['id'], $eventId);

            $outcome = ['resolved' => 0, 'delivered' => 0];

            if ($next !== null) {
                $outcome = $this->openInboxRows(
                    $tenantId,
                    $documentId,
                    $routeId,
                    (int) $next['id'],
                    $plan['members'],
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
                $outcome = ['resolved' => 1, 'delivered' => $reopened === null ? 0 : 1];
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
        ]);

        return ['event' => $event, 'resolved' => $outcome['resolved'], 'delivered' => $outcome['delivered']];
    }

    // -- internals ----------------------------------------------------------

    /**
     * Append a `noted` event. Closes nothing, opens nothing.
     *
     * The correction mechanism: the trail has no update and no delete path, so a
     * mistaken note, a wrong unit or a misspelled name is answered by appending
     * beside it. Both rows survive, which is more useful as well as safer —
     * "this was corrected on the 14th" is itself a fact somebody may need.
     *
     * @return array{event: array<string, mixed>, resolved: int, delivered: int}
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
        ]);

        return ['event' => $event, 'resolved' => 0, 'delivered' => 0];
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
     * Open one inbox row per planned recipient, all pointing at the event that
     * created them.
     *
     * @param list<ResolvedRecipient> $members
     * @return array{resolved: int, delivered: int}
     */
    private function openInboxRows(
        int $tenantId,
        int $documentId,
        int $routeId,
        int $stepId,
        array $members,
        ?int $parentRecipientId,
        int $eventId,
    ): array {
        $delivered = 0;
        foreach ($members as $recipient) {
            $id = $this->recipients->create($tenantId, [
                'document_id' => $documentId,
                'route_id' => $routeId,
                'step_id' => $stepId,
                'profile_id' => $recipient->profileId,
                'ou_id' => $recipient->ouId,
                'parent_recipient_id' => $parentRecipientId,
                'created_by_event_id' => $eventId,
            ]);
            if ($id !== null) {
                $delivered++;
            }
        }

        // Both counts: `resolved` is what the rule answered, `delivered` how many
        // rows that became after de-duplicating against chains that already
        // reached those people.
        return ['resolved' => count($members), 'delivered' => $delivered];
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
     * @param list<array<string, mixed>> $steps
     * @return list<array{rule_kind: string, rule_config: array<string, mixed>, label: ?string}>
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

            /** @var array<string, mixed> $config */
            $out[] = [
                'rule_kind' => $kind,
                'rule_config' => $config,
                'label' => is_string($label) && trim($label) !== '' ? trim($label) : null,
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

        try {
            $this->hooks->dispatchAsync($event . '.async', $payload + [
                'id' => $documentId,
                'document_id' => $documentId,
                'tenant_id' => $tenantId,
            ]);
        } catch (Throwable $e) {
            // The trail is already committed and is the system of record. A
            // broadcast that could not be recorded is a missed notification, not
            // a lost routing act, so it is logged and swallowed rather than
            // turning a successful forward into a 500 for the person who did it.
            error_log('[DocumentRouter] emitting a routing event to the spine failed: ' . $e->getMessage());
        }
    }
}
