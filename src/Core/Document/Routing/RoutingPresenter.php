<?php

declare(strict_types=1);

namespace Whity\Core\Document\Routing;

use Whity\Core\Db\DbBool;

/**
 * Wire shapes for the routing surface (#947 item 3).
 *
 * One place, so the three views #978 builds — the trail, the inbox and the route
 * composer — cannot each grow their own idea of what a step or an event looks
 * like. {@see \Whity\Core\Document\DocumentPresenter} plays the same role for the
 * document itself.
 *
 * WHAT IS NOT ON THE WIRE
 * -----------------------
 * `tenant_id`, on anything. Every route here is already tenant-scoped by the
 * repositories, so echoing the tenant back tells the caller nothing they could
 * act on and hands an attacker confirmation of which tenant a guessed id landed
 * in. `documents` publishes it today (migration 108's `Document` schema), and
 * that is a wart this surface declines to copy rather than a precedent it has to
 * follow.
 *
 * Stateless — worker-safe.
 */
final class RoutingPresenter
{
    /**
     * A route with its ordered steps and its verdict edges.
     *
     * `edges` is a flat list on the ROUTE rather than fields on each step,
     * deliberately: an edge is a relationship between two steps and belongs to
     * neither of them, and a node-based editor (#1027) reads a node list and an
     * edge list. Nesting it under the source step would make the same fact
     * reachable by two paths and give a client a reason to disagree with itself.
     *
     * Note the asymmetry with AUTHORING, which is unavoidable rather than
     * sloppy: an author declares `on_approved` / `on_rejected` by POSITION,
     * because while composing a route the steps have no ids yet. Reads publish
     * ids, because by then they do. {@see DocumentRouter::issue()} is the one
     * place the two spellings meet.
     *
     * `default_quorum` rides along for the reason
     * {@see \Whity\Core\Document\RouteTemplate\RouteTemplatePresenter::graph()}
     * sends it to the editor, and the reason is sharper here (#1041). A step's
     * own `decision_quorum` is NULL far more often than not, and NULL means
     * "follow the tenant's setting" rather than "no quorum" — so a client that
     * only had the step could not tell an approver whether their single approval
     * carries the gate or is one of four hundred required. The answer lives in
     * the settings chain, and the person standing on a decision step is the least
     * likely person in the tenant to hold `settings:read`: asking them to fetch
     * it would 403 exactly the reader who needs it.
     *
     * PROVENANCE (#1031) IS PUBLISHED AS BOTH HALVES, AND THE NAME IS NOT A
     * CONVENIENCE COPY OF THE POINTER. `template_id` is null on a route composed
     * by hand AND on one whose design has since been deleted; `template_name`
     * survives the second case and not the first. A client that rendered "from a
     * template" from the id alone would silently stop crediting every design its
     * author ever tidied up, which is the same staleness the snapshot exists to
     * prevent — so both go out and neither is derivable from the other.
     *
     * @param array<string, mixed>       $route
     * @param list<array<string, mixed>> $steps
     * @param list<array<string, mixed>> $edges
     * @param string $defaultQuorum What a step with a NULL `decision_quorum`
     *        actually does, already resolved through the tenant's settings chain.
     * @param array<int, int> $rejectionsByStep step id => rejections recorded at
     *        it (#1037). Defaults to empty, which publishes every step as zero —
     *        the honest answer for a caller that did not ask the trail, and the
     *        same one a route with no rejections yet produces.
     * @param array<int, int> $cohortsByStep step id => cohorts opened there
     *        (#1140), which is how many times the step SETTLED. Defaults to
     *        empty for the same reason, and zero is honest here too: a step
     *        nothing has reached has opened no cohorts.
     * @return array<string, mixed>
     */
    public static function route(
        array $route,
        array $steps,
        array $edges = [],
        string $defaultQuorum = RouteQuorum::ALL,
        array $rejectionsByStep = [],
        array $cohortsByStep = [],
    ): array {
        return [
            'id' => (int) $route['id'],
            'document_id' => (int) $route['document_id'],
            'title' => (string) $route['title'],
            'created_by' => $route['created_by'],
            'template_id' => $route['template_id'] ?? null,
            'template_name' => $route['template_name'] ?? null,
            'created_at' => (string) $route['created_at'],
            'steps' => array_map(
                static fn (array $step): array => self::step(
                    $step,
                    $rejectionsByStep[(int) $step['id']] ?? 0,
                    $cohortsByStep[(int) $step['id']] ?? 0,
                ),
                $steps,
            ),
            'edges' => array_map(self::edge(...), $edges),
            'default_quorum' => $defaultQuorum,
        ];
    }

    /**
     * One verdict edge: where a settled verdict sends this step's document.
     *
     * @param array<string, mixed> $edge
     * @return array<string, mixed>
     */
    public static function edge(array $edge): array
    {
        return [
            'id' => (int) $edge['id'],
            'route_id' => (int) $edge['route_id'],
            'from_step_id' => (int) $edge['from_step_id'],
            'to_step_id' => (int) $edge['to_step_id'],
            'verdict' => (string) $edge['verdict'],
        ];
    }

    /**
     * One step.
     *
     * `rule_config` goes out as it came in. It is the rule's own vocabulary, and
     * the composer that authored it is the thing that reads it back — core
     * re-shaping it would mean core claiming to understand a plugin's config.
     *
     * @param array<string, mixed> $step
     * @return array<string, mixed>
     */
    public static function step(array $step, int $rejections = 0, int $cohorts = 0): array
    {
        return [
            'id' => (int) $step['id'],
            // #1140: how many COHORTS this step has opened. One is ordinary;
            // more than one means the step SETTLED MORE THAN ONCE and opened a
            // continuation each time, so everything downstream ran again —
            // including anything that emails people or ends a chain.
            //
            // Published as the count rather than as a boolean, because "twice"
            // and "nine times" are different situations and a flag would make
            // them look identical. Zero means nothing has arrived here yet,
            // which is distinct from one.
            //
            // NOT A DEFECT REPORT. Settling once per cohort is deliberate and
            // documented; what was missing is any surface saying it happened to
            // a PARTICULAR document. The canvas cannot say it — whether a second
            // arrival is late depends on who acts and how long a rework loop
            // takes, not on the graph an author drew — so it can only be read
            // back afterwards, from here.
            'cohort_count' => $cohorts,
            // #1037: how many times a rejection has sent the document back from
            // here. Derived from the trail's verdict rows, never stored — a
            // counter beside the trail is a second source of truth that can
            // disagree with it, and the trail is the auditable one.
            //
            // Published even when zero, so a client can render "1st time"
            // rather than having to treat an absent field as either "never" or
            // "this server is too old to say".
            'rejection_count' => $rejections,
            'position' => (int) $step['position'],
            'rule_kind' => (string) $step['rule_kind'],
            'rule_config' => is_array($step['rule_config']) ? $step['rule_config'] : [],
            'label' => $step['label'],
            // #1014. `decision` is whether this step is a GATE; `decision_quorum`
            // is its own override of what "this node approved" means, and NULL
            // there means the tenant's setting decides rather than "no quorum".
            // Both are published so an editor can redraw a node exactly as it was
            // authored, and so a client can tell an approval node from a
            // circulation node without inferring it from the edges - which would
            // make deleting an edge look like turning a sign-off into a
            // circulation.
            'decision' => DbBool::of($step['decision'] ?? false),
            'decision_quorum' => $step['decision_quorum'] ?? null,
            // #1054. WHETHER ANYBODY IS ASKED TO ACT at this step. Published
            // for the same reason `decision` is, and the cost of omitting it is
            // sharper: a client that cannot tell a delivery step apart offers
            // Forward / Acknowledge / Return on a step where every one of them
            // is a 422, to a person whose item was closed the instant it was
            // created. It also lets a trail reader say "delivered to the
            // faculty" where it would otherwise say "sent to 300 people",
            // leaving each of them looking like somebody who has not replied.
            'satisfied_by' => is_string($step['satisfied_by'] ?? null)
                ? (string) $step['satisfied_by']
                : RouteSatisfaction::fallback(),
        ];
    }

    /**
     * One trail event.
     *
     * @param array<string, mixed> $event
     * @return array<string, mixed>
     */
    public static function event(array $event): array
    {
        return [
            'id' => (int) $event['id'],
            'document_id' => (int) $event['document_id'],
            'route_id' => (int) $event['route_id'],
            'step_id' => $event['step_id'],
            'actor_profile_id' => $event['actor_profile_id'],
            'action' => (string) $event['action'],
            'from_ou_id' => $event['from_ou_id'],
            'to_ou_id' => $event['to_ou_id'],
            'note' => $event['note'],
            // #1014: what the actor DECIDED, as opposed to what they did.
            // NULL on every act that decided nothing - which is every act on a
            // circulation step, every note, and every event recorded before
            // migration 119. It never means "not approved".
            'verdict' => $event['verdict'] ?? null,
            'occurred_at' => (string) $event['occurred_at'],
        ];
    }

    /**
     * One recipient row, as the document's own view of who it reached.
     *
     * `open` is published as a boolean DERIVED from `closed_by_event_id` and the
     * pointer is published beside it, deliberately: the boolean is what a screen
     * renders, and the event id is what lets a reader follow the claim back into
     * the trail and check it. Publishing only the boolean would make the API a
     * place where routing's state is asserted rather than evidenced.
     *
     * @param array<string, mixed> $recipient
     * @return array<string, mixed>
     */
    public static function recipient(array $recipient): array
    {
        return [
            'id' => (int) $recipient['id'],
            'document_id' => (int) $recipient['document_id'],
            'route_id' => (int) $recipient['route_id'],
            'step_id' => (int) $recipient['step_id'],
            'profile_id' => (int) $recipient['profile_id'],
            'ou_id' => $recipient['ou_id'],
            'parent_recipient_id' => $recipient['parent_recipient_id'],
            'created_by_event_id' => (int) $recipient['created_by_event_id'],
            'closed_by_event_id' => $recipient['closed_by_event_id'],
            'open' => $recipient['closed_by_event_id'] === null,
            // #1054. WAS THIS CLOSED BY A PERSON, OR BY THE DOCUMENT REACHING
            // THEM? Without it a delivery step's three hundred rows read exactly
            // like three hundred people who acted, which is the same
            // misattribution the "Awaiting me" phantom was, arriving one screen
            // over — and this is the list a reader opens to ask precisely "what
            // became of it for each of them".
            //
            // DERIVED FROM THE ROW ITSELF, from a shape no human act can produce:
            // acting closes a row with a LATER event than the one that opened it,
            // always, because the act happens after the arrival. Only
            // {@see DocumentRouter::openChain()} closes a row with its own
            // creating event.
            //
            // The AUTHORITY is still the step's `satisfied_by` — this is the
            // row-local witness of it, published so a client reading a recipient
            // list need not join every row back to its step to render the one
            // thing that distinguishes them.
            'closed_by_delivery' => $recipient['closed_by_event_id'] !== null
                && (int) $recipient['closed_by_event_id'] === (int) $recipient['created_by_event_id'],
            'created_at' => (string) $recipient['created_at'],
        ];
    }
}
