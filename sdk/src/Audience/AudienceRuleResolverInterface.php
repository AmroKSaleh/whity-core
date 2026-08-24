<?php

declare(strict_types=1);

namespace Whity\Sdk\Audience;

use InvalidArgumentException;
use Whity\Sdk\Routing\ResolvedRecipient;
use Whity\Sdk\Routing\RoutingRuleResolverInterface;

/**
 * A rule kind that can name a SET OF PEOPLE without a document in sight (#999).
 *
 * WHAT THIS ADDS TO {@see RoutingRuleResolverInterface}
 * ----------------------------------------------------
 * Nothing, in behaviour. The three methods are the same three, and a resolver
 * that already answers "who receives this step" almost always answers "who is
 * in this set" with the identical query — core's `role` reads
 * `memberships.role_id` either way.
 *
 * What it adds is a PROMISE ABOUT THE CONTEXT: that the resolver needs only the
 * tenant, the actor and its own config, and therefore works when nobody is
 * routing anything. That promise is what makes a kind usable as the definition
 * of a named USER GROUP.
 *
 * HOW A KIND DECLARES BOTH
 * ------------------------
 * `RoutingRuleContext extends AudienceRuleContext`, so ONE method body serves
 * both interfaces:
 *
 *     final class CommitteeRule implements
 *         RoutingRuleResolverInterface,
 *         AudienceRuleResolverInterface
 *     {
 *         public function label(): string { return 'Members of a committee'; }
 *
 *         public function validate(array $config): void
 *         {
 *             if (!isset($config['committee']) || !is_string($config['committee'])) {
 *                 throw new \InvalidArgumentException('committee must be a string');
 *             }
 *         }
 *
 *         // Widened parameter: legal for both interfaces, because a
 *         // RoutingRuleContext IS an AudienceRuleContext.
 *         public function resolve(AudienceRuleContext $context): array
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
 *
 * A resolver that genuinely needs the document — one that reads the artifact, or
 * branches on the route's title — implements only
 * {@see RoutingRuleResolverInterface}, keeps the narrow parameter, and is simply
 * not offered as a group definition. That is the honest outcome rather than an
 * error: such a rule cannot answer the question a group asks.
 *
 * REGISTRATION IS STILL THROUGH THE ROUTING RULE CATALOGUE, ON PURPOSE
 * -------------------------------------------------------------------
 * There is ONE registry in the host — fed by
 * {@see \Whity\Sdk\Routing\PluginRoutingRulesInterface} — and it still
 * requires {@see RoutingRuleResolverInterface}. So there is no such thing as an
 * audience-only kind, and that is deliberate: a rule that can name a set of
 * people can name the recipients of a step, and a second registry for "the same
 * vocabulary, for the other caller" is exactly the duplication one shared
 * catalogue exists to avoid.
 *
 * WHY THE RETURN TYPE IS STILL `ResolvedRecipient`
 * -----------------------------------------------
 * It is already, precisely, "a profile and the unit a rule reached them
 * through" — two fields, no routing in either of them. Minting a
 * `ResolvedPrincipal` with the same two fields would mean every resolver
 * converting between two identical objects, and renaming the existing one would
 * break every plugin merged against #989. The name records where the value
 * object was born, not who may return it.
 *
 * WHAT A RESOLVER MAY AND MAY NOT DO — unchanged, and it is the whole safety
 * argument. It RETURNS SUGGESTIONS and writes nothing. The host intersects the
 * answer with the ACTIVE MEMBERSHIPS of the tenant before anything acts on it,
 * so a resolver — core's or a plugin's, correct or buggy — cannot leak a profile
 * from another tenant into a group's membership or a document's inbox, and that
 * check lives in the host precisely so it is not a rule every resolver author
 * has to remember.
 *
 * THREADING. One instance is constructed per request by the plugin and may be
 * called several times within it, so it must not accumulate per-call state on
 * itself.
 */
interface AudienceRuleResolverInterface
{
    /**
     * A short human name for the kind, shown wherever a rule is composed.
     *
     * Names the SHAPE of the question ("Everyone holding a role"), not a
     * configured instance of it ("Members of the Finance committee") — the label
     * appears in a picker before anything has been configured.
     */
    public function label(): string;

    /**
     * Reject a config this rule cannot resolve, at AUTHORING time.
     *
     * Called once when a group is created or updated, and once per step when a
     * route is issued, before anything is written. Throwing here is how a bad
     * definition becomes a 422 with a message the author can act on; returning
     * quietly is a promise that {@see resolve()} will not fail on this config
     * for a reason the author could have been told about now.
     *
     * Validate the SHAPE, not the world. "role_id must be a positive integer"
     * belongs here; "role 7 is held by somebody" does not — a role held by
     * nobody today may be held tomorrow, and refusing to save the group would
     * rebuild the stale-list failure inside the validator.
     *
     * @param array<string, mixed> $config
     *
     * @throws InvalidArgumentException With a message written for the person
     *         composing the rule. It reaches them verbatim.
     */
    public function validate(array $config): void;

    /**
     * The people this rule resolves to, RIGHT NOW.
     *
     * There is no cached, materialised or precomputed form of this answer
     * anywhere in core, and that is the point of the whole design: a stored
     * membership list omits the instructor hired last week, still renders, and
     * still reports success. Resolution is live so that it cannot.
     *
     * Scope every query to `$context->tenantId`. The host re-checks the result
     * against that tenant's memberships, but a resolver that reads across
     * tenants has already leaked whatever it read.
     *
     * ORDER AND DUPLICATES DO NOT MATTER — the host de-duplicates by profile.
     * AN EMPTY RESULT IS LEGAL and is reported as a count of zero rather than as
     * an error: "the role exists and nobody holds it" is a real answer, and it
     * is one a preview shows the author immediately.
     *
     * A throw is a failure of this resolution: the caller is refused and nothing
     * is written, rather than a half-resolved answer being treated as complete.
     *
     * @return list<ResolvedRecipient>
     */
    public function resolve(AudienceRuleContext $context): array;
}
