<?php

declare(strict_types=1);

namespace Whity\Sdk\Routing;

/**
 * Everything a routing rule is told when it is asked to resolve (#947 item 3).
 *
 * The whole of a resolver's input, passed as one object rather than seven
 * arguments so that adding a fact later is not a signature break for every
 * plugin that implemented the interface.
 *
 * THE ACTOR IS THE POINT
 * ----------------------
 * `actorProfileId` / `actorOuId` are what make #947's second semantic —
 * distribution fans out, it does not block — expressible at all. A step is
 * resolved ONCE PER ACTING RECIPIENT, relative to that recipient, so
 * `role_below_actor` means something different for each of them and each chain
 * proceeds on its own. A context that carried only the route would force every
 * resolver to answer the same question for everybody, which is a global
 * barrier written in a different place.
 *
 * `actorOuId` is the unit the actor was REACHED THROUGH on the step they are
 * acting from — not their primary membership. A person forwarding an item that
 * arrived via their committee is acting from the committee, and a rule that
 * silently substituted their home department would send the next step somewhere
 * nobody chose. It is null at issue time when the raiser belongs to no unit, and
 * null thereafter when the rule that reached them was not unit-scoped.
 *
 * NO DATABASE HANDLE, DELIBERATELY
 * --------------------------------
 * A resolver that needs to query brings its own collaborators through its
 * constructor — a plugin builds its resolvers, so it already has them. Handing
 * every resolver a PDO would make the SDK depend on the host's data layer (it
 * depends on nothing but PHP, which is what lets it be vendored), and would
 * make it possible for a resolver to write, when the entire safety argument for
 * plugin-supplied rules is that they only ever RETURN a suggestion the host
 * validates.
 *
 * Immutable value object.
 */
final class RoutingRuleContext
{
    /**
     * @param int                  $tenantId        The tenant the route belongs to. A resolver
     *                                              must scope every query it makes to this;
     *                                              the host additionally filters the result.
     * @param int                  $documentId      The document being routed.
     * @param int                  $routeId         The routing instance.
     * @param int                  $stepId          The step being resolved.
     * @param int                  $position        The step's 1-based position in the route.
     * @param int|null             $actorProfileId  Who caused this step to be resolved: the
     *                                              raiser at issue time, or the recipient who
     *                                              forwarded. Null only when a route is issued
     *                                              by an unauthenticated internal path.
     * @param int|null             $actorOuId       The unit the actor was acting FROM, or null.
     * @param array<string, mixed> $config          The step's `rule_config`, exactly as
     *                                              {@see RoutingRuleResolverInterface::validate()}
     *                                              accepted it.
     */
    public function __construct(
        public readonly int $tenantId,
        public readonly int $documentId,
        public readonly int $routeId,
        public readonly int $stepId,
        public readonly int $position,
        public readonly ?int $actorProfileId,
        public readonly ?int $actorOuId,
        public readonly array $config,
    ) {
    }
}
