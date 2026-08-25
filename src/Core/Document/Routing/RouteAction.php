<?php

declare(strict_types=1);

namespace Whity\Core\Document\Routing;

/**
 * The closed vocabulary of things that can HAPPEN to a routed document
 * (#947 item 3).
 *
 * Five verbs, CHECK-constrained in migration 112 so the database refuses a
 * sixth. That closure is deliberate and it is the one place routing is NOT
 * extensible:
 *
 *  - RULE KINDS are open, because "who are the next people" is a question about
 *    a particular organisation and core cannot know the answer. A plugin
 *    contributes one through {@see RoutingRuleRegistry}.
 *  - ACTIONS are closed, because what can happen to a routed document is the
 *    ENGINE's own semantics. A plugin adding a sixth verb would be adding a
 *    state transition core does not implement — the row would store, no engine
 *    code would act on it, and every reader would have to guess what it meant.
 *
 * A trail whose action column accepts any string is a trail whose readers must
 * handle any string, and the first typo becomes a permanent row that nothing
 * renders and nothing can correct, because the trail is append-only.
 *
 * WHAT EACH ONE MEANS, AND WHAT IT DOES TO THE INBOX
 * --------------------------------------------------
 * Only three of them close an inbox row, and none of them ever reopens one by
 * clearing a pointer — see {@see ISSUED} on why a `returned` is a new row.
 */
final class RouteAction
{
    /**
     * A route was created and its first step resolved.
     *
     * The actor is the person who raised the circulation, not a recipient;
     * `step_id` on the event names the first step, and the recipient rows it
     * creates point back at it through `created_by_event_id`.
     */
    public const ISSUED = 'issued';

    /**
     * A recipient acted and passed the document on.
     *
     * The NEXT step is resolved relative to THEM — their unit, their position
     * in the tree — which is what makes each chain independent. Closes the
     * actor's own inbox row and opens one for each person the next step
     * resolved to.
     *
     * Refused when the actor is standing on the last step: there is nothing to
     * forward to, and inventing a destination would be the engine deciding
     * something the route's author did not. {@see RouteAction::ACKNOWLEDGED} is
     * the terminal act.
     *
     * Also refused on a DECISION step (#1014), where choosing the destination is
     * exactly what the gate exists to take away from the person answering.
     * Allowing both would give every approver a one-click way past the verdict,
     * and the route would read as approved because the document plainly moved.
     */
    public const FORWARDED = 'forwarded';

    /**
     * A recipient acted and CHOSE NO DESTINATION.
     *
     * On a circulation step that means the document goes no further along their
     * chain: closes their inbox row and opens nothing. Legal at any step, not
     * only the last — a person who is the intended end of their branch says so
     * here, and the other chains are unaffected because there is no aggregate
     * that could be waiting on them.
     *
     * On a DECISION step (#1014) it is the act that carries a VERDICT
     * ({@see RouteVerdict}), and the engine may then open the verdict's edge. The
     * verb's meaning is unchanged by that and is why it is the one chosen: the
     * ACTOR still names no destination. Where the document goes is derived from
     * what they decided, which is the whole point of a gate — {@see FORWARDED},
     * where the actor DOES choose, is refused there.
     */
    public const ACKNOWLEDGED = 'acknowledged';

    /**
     * A recipient sent the document back to whoever sent it to them.
     *
     * Closes their inbox row and opens a NEW one for the predecessor, at the
     * predecessor's own step. It is a new row rather than an un-closing of the
     * old one on purpose: clearing the predecessor's `closed_by_event_id` would
     * erase the fact that they acted, and what they did is the trail's business
     * — the inbox does not get to overwrite it. Migration 112's partial unique
     * index (open rows only) is what makes the second row legal.
     *
     * Refused at the first step, where there is no predecessor to return to:
     * the raiser is not a recipient and has no inbox row to open.
     */
    public const RETURNED = 'returned';

    /**
     * A remark against the document's trail. Closes nothing, opens nothing.
     *
     * This is how a CORRECTION is made. The trail has no update and no delete
     * path, so a mistaken note, a wrong unit or a misspelled name is answered
     * by appending the correction beside it — and the original stays, which is
     * the point. The moment somebody most wants to tidy history is exactly the
     * moment it must be immutable.
     *
     * Open to anyone who may see the document, not only to open recipients: the
     * person best placed to correct the record is often the one who already
     * acted, and their row is closed.
     */
    public const NOTED = 'noted';

    /**
     * Every action, in the order a reader meets them.
     *
     * Must stay in step with the CHECK constraint in migration 112.
     * {@see \Tests\Core\Document\Routing\RouteActionVocabularyTest} reads the
     * migration source and asserts the two agree, so a verb added to one and not
     * the other fails a build rather than producing either a constant the
     * database refuses at insert or a value the schema admits that no reader can
     * render.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return [self::ISSUED, self::FORWARDED, self::ACKNOWLEDGED, self::RETURNED, self::NOTED];
    }

    /**
     * The actions a RECIPIENT may take on their own open item.
     *
     * {@see ISSUED} is absent because it is the engine's, not a person's: a
     * route is issued by creating it, and accepting `issued` as an act would let
     * a recipient mint a second beginning for a circulation already under way.
     *
     * @return list<string>
     */
    public static function recipientActions(): array
    {
        return [self::FORWARDED, self::ACKNOWLEDGED, self::RETURNED];
    }

    /**
     * Whether an action closes the acting recipient's own inbox row.
     *
     * {@see NOTED} does not, which is why anyone who can see the document may
     * add one without having anything to close.
     */
    public static function closesRecipient(string $action): bool
    {
        return in_array($action, self::recipientActions(), true);
    }
}
