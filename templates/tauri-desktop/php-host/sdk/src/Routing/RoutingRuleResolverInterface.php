<?php

declare(strict_types=1);

namespace Whity\Sdk\Routing;

use InvalidArgumentException;

/**
 * One routing RULE KIND: the thing a route step names instead of a person
 * (#947 item 3).
 *
 * A step stores `rule_kind` + `rule_config` and has nowhere to put a profile
 * id. That is the design, and this interface is what makes it extensible: core
 * ships `role` and `role_below_actor`, both generic; anything a particular
 * organisation means by "the next people" arrives as an implementation of this,
 * registered through {@see PluginRoutingRulesInterface}.
 *
 * WHY A RULE AND NOT A LIST
 * -------------------------
 * A stored recipient list is resolved once, when the route is authored, and is
 * wrong from the first reorganisation onwards. It omits the unit created last
 * week, the document still renders, every step still completes and the run
 * reports success — nothing anywhere says a department was skipped. A rule is
 * resolved at SEND time against the organisation as it stands at that instant,
 * so the new unit is included because it exists, not because somebody
 * remembered to add it.
 *
 * THE SAME RULE, USABLE OUTSIDE ROUTING (#999)
 * --------------------------------------------
 * A named USER GROUP is this same expression given a name and stored once, so
 * that "the instructors" is one thing referenced from many places rather than a
 * thousand rows re-listed per place. It asks the identical question with no
 * document, route or step in it.
 *
 * A resolver that never reads those three — which is most of them, and both of
 * core's — makes itself usable as a group definition by ALSO implementing
 * {@see \Whity\Sdk\Audience\AudienceRuleResolverInterface} and widening its
 * parameter to {@see \Whity\Sdk\Audience\AudienceRuleContext}. That is legal
 * here (a widened parameter is contravariant) and needs no second method body,
 * because {@see RoutingRuleContext} IS an `AudienceRuleContext`.
 *
 * This interface is UNCHANGED by #999. A resolver that genuinely needs the
 * document keeps the narrow parameter and is simply not offered as a group
 * definition — the honest outcome, since such a rule cannot answer a question
 * that has no document in it.
 *
 * TWO METHODS, AND THEY RUN AT DIFFERENT TIMES
 * --------------------------------------------
 * {@see validate()} runs when a route is AUTHORED — synchronously, in the
 * request that creates it, so a malformed config is a 422 the author can fix
 * rather than a route that silently resolves to nobody months later. It is the
 * only code that knows the shape of its own config, which is why the config
 * column is opaque JSONB and this method is not optional.
 *
 * {@see resolve()} runs when the step is REACHED — at issue for step 1, and
 * once per acting recipient thereafter.
 *
 * WHAT A RESOLVER MAY AND MAY NOT DO
 * ----------------------------------
 * It returns {@see ResolvedRecipient} objects. It writes nothing. The host
 * takes the answer, filters every profile against the ACTIVE MEMBERSHIPS of the
 * route's tenant, de-duplicates against the chains that already reached those
 * people, writes the inbox rows and appends the trail event. So a resolver — a
 * plugin's or core's, correct or buggy — cannot place a document in somebody
 * else's tenant, cannot double-deliver, and cannot write history. Returning a
 * profile that fails the membership check is not an error; it is silently
 * dropped, because "this person has left" is an ordinary answer and failing the
 * whole distribution over it would strand everyone else on the step.
 *
 * ORDER AND DUPLICATES DO NOT MATTER. The host de-duplicates and the inbox has
 * no order of its own, so a resolver may return the same profile twice or in
 * any sequence without changing the outcome.
 *
 * AN EMPTY RESULT IS LEGAL, AND VISIBLE. A rule that resolves to nobody is a
 * real answer — the role exists and is held by no one, the subtree is empty —
 * and the trail records the distribution with an empty recipient set rather
 * than failing. That is the whole point of the rejected alternative: a stored
 * list fails INVISIBLY, and this fails in a row somebody can read.
 *
 * THREADING. A resolver instance is constructed once per request by the plugin
 * and may be called several times within it (once per acting recipient), so it
 * must not accumulate per-call state on itself.
 *
 * Example:
 *
 *     final class CommitteeRule implements RoutingRuleResolverInterface
 *     {
 *         public function __construct(private readonly CommitteeRepository $committees) {}
 *
 *         public function label(): string
 *         {
 *             return 'Members of a committee';
 *         }
 *
 *         public function validate(array $config): void
 *         {
 *             if (!isset($config['committee']) || !is_string($config['committee'])) {
 *                 throw new \InvalidArgumentException('committee must be a string');
 *             }
 *         }
 *
 *         public function resolve(RoutingRuleContext $context): array
 *         {
 *             $members = $this->committees->membersOf(
 *                 $context->tenantId,
 *                 (string) $context->config['committee'],
 *             );
 *
 *             return array_map(
 *                 static fn (array $m): ResolvedRecipient
 *                     => new ResolvedRecipient((int) $m['profile_id'], $m['ou_id'] ?? null),
 *                 $members,
 *             );
 *         }
 *     }
 */
interface RoutingRuleResolverInterface
{
    /**
     * A short human name for the kind, shown wherever a route is composed.
     *
     * Not a description of the configured step ("Members of the Finance
     * committee") — the kind is what appears in a picker before anything is
     * configured, so it names the SHAPE of the question this rule answers.
     */
    public function label(): string;

    /**
     * Reject a config this rule cannot resolve, at authoring time.
     *
     * Called once per step when a route is created, before anything is written.
     * Throwing here is how a bad step becomes a 422 with a message the author
     * can act on; returning quietly is a promise that {@see resolve()} will not
     * fail on this config for a reason the author could have been told about
     * now.
     *
     * Validate the SHAPE, not the world. "committee must be a string" belongs
     * here; "committee `finance` exists" does not, because a committee that is
     * created next week is exactly the case a rule exists to accommodate, and
     * refusing to author the step would recreate the stored-list failure in the
     * validator.
     *
     * @param array<string, mixed> $config The step's declared `rule_config`.
     *
     * @throws InvalidArgumentException With a message written for the person
     *         composing the route. It reaches them verbatim.
     */
    public function validate(array $config): void;

    /**
     * The people this rule resolves to, right now.
     *
     * Called at issue time for the first step, and once per acting recipient for
     * every step after — see {@see RoutingRuleContext} for why the actor is in
     * the context and what that buys.
     *
     * Scope every query to `$context->tenantId`. The host re-checks the result
     * against that tenant's memberships, but a resolver that reads across
     * tenants has already leaked whatever it read.
     *
     * A throw is treated as a failure of THIS step for THIS actor: the act is
     * refused and nothing is written, rather than a half-resolved distribution
     * being committed. Return an empty array to say "nobody", which is not an
     * error.
     *
     * @return list<ResolvedRecipient>
     */
    public function resolve(RoutingRuleContext $context): array;
}
