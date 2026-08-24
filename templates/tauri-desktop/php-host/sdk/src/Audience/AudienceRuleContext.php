<?php

declare(strict_types=1);

namespace Whity\Sdk\Audience;

/**
 * Everything a rule is told when it is asked "WHICH PEOPLE?", with nothing in
 * it about why anybody is asking (#999).
 *
 * WHY THIS EXISTS SEPARATELY FROM RoutingRuleContext
 * -------------------------------------------------
 * #947 item 3 introduced rule kinds inside document ROUTING, and
 * {@see \Whity\Sdk\Routing\RoutingRuleContext} carries a document, a route, a
 * step and a position because a routing resolver is answering "who receives
 * THIS step of THIS circulation".
 *
 * A named USER GROUP asks the same question with none of that. "Everyone
 * holding the instructor role" is a set of people whether or not a document is
 * being circulated, and a group is precisely that expression given a name and
 * stored once so it can be referenced from many places. Resolving it needs the
 * tenant, the person asking, and the rule's own config — and nothing else.
 *
 * THE REJECTED ALTERNATIVES, BOTH OF WHICH WERE TRIED FIRST
 * ---------------------------------------------------------
 *  1. WIDEN `RoutingRuleContext`'s document/route/step/position to nullable and
 *     reuse it for groups. Every plugin resolver merged against #989 reads
 *     `$context->documentId` as an `int`; making it `?int` silently changes the
 *     type under code that is already written and shipped, for the benefit of a
 *     caller those resolvers never see. That is a contract break dressed as a
 *     widening.
 *
 *  2. PASS A ROUTING CONTEXT WITH ZEROS in the four routing fields. A resolver
 *     would then receive `documentId: 0`, which is not "absent" but a lie the
 *     type system endorses — and a resolver that looked the document up would
 *     get a miss it could not distinguish from a deleted row.
 *
 * So the general question got the general context, and the routing context
 * became a SUBTYPE of it: `RoutingRuleContext extends AudienceRuleContext`,
 * keeping every property and its constructor signature exactly as #989 shipped
 * them. Nothing already written changes, and a resolver that only needs the
 * general facts can declare
 * {@see AudienceRuleResolverInterface::resolve()} and serve both callers from
 * one method body.
 *
 * THE ACTOR IS STILL HERE, AND IT IS NOT VESTIGIAL
 * -----------------------------------------------
 * Some rules are ACTOR-RELATIVE by design — core's `role_below_actor` resolves
 * to a different set of people for a dean than for a faculty officer, which is
 * how "distribution fans out, it does not block" is expressed as data. A group
 * whose definition is such a rule is therefore relative to whoever uses it, and
 * that is a feature rather than an oversight: "the instructors in my own unit
 * and below" is one named rule that every unit head can use and that means
 * their own unit each time.
 *
 * A group PREVIEW consequently has to say which actor it resolved against,
 * because otherwise two people would read two different counts off the same
 * screen with nothing to explain the difference.
 *
 * `actorProfileId` and `actorOuId` are both nullable, and null is a real
 * answer: an unauthenticated internal path, or a person who belongs to no unit.
 * A rule that needs a unit and is given none must resolve to NOBODY rather than
 * falling back to "unscoped". Core's own `role_below_actor` records the reason:
 * a route authored to keep a document inside one faculty would broadcast it to
 * the whole institution the first time somebody without a unit touched it.
 *
 * NO DATABASE HANDLE, DELIBERATELY — the same rule
 * {@see \Whity\Sdk\Routing\RoutingRuleContext} states. A resolver that needs to
 * query brings its own collaborators through its constructor. Handing every
 * resolver a PDO would make the SDK depend on the host's data layer (it depends
 * on nothing but PHP, which is what lets it be vendored into a plugin and onto
 * an offline device), and would make it possible for a resolver to WRITE, when
 * the entire safety argument for plugin-supplied rules is that they only ever
 * return a suggestion the host then validates.
 *
 * Immutable value object. NOT final: `RoutingRuleContext` extends it.
 */
class AudienceRuleContext
{
    /**
     * @param int                  $tenantId       The tenant whose people are being asked
     *                                             about. A resolver must scope every query it
     *                                             makes to this; the host additionally filters
     *                                             the result against that tenant's active
     *                                             memberships.
     * @param int|null             $actorProfileId Who is asking. The raiser or the forwarding
     *                                             recipient when a route is being resolved; the
     *                                             caller when a group is being previewed. Null
     *                                             only on an unauthenticated internal path.
     * @param int|null             $actorOuId      The unit the actor is acting FROM, or null
     *                                             when there is none. In routing this is the
     *                                             unit they were REACHED THROUGH, not their
     *                                             primary membership.
     * @param array<string, mixed> $config         The rule's own parameters, exactly as
     *                                             {@see AudienceRuleResolverInterface::validate()}
     *                                             accepted them.
     */
    public function __construct(
        public readonly int $tenantId,
        public readonly ?int $actorProfileId,
        public readonly ?int $actorOuId,
        public readonly array $config,
    ) {
    }
}
