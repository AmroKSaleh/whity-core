<?php

declare(strict_types=1);

namespace Whity\Sdk\Routing;

use Whity\Sdk\Audience\AudienceRuleContext;

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
 * A SUBTYPE OF {@see AudienceRuleContext}, AND WHY THAT DIRECTION
 * ---------------------------------------------------------------
 * #999 needed the same rule kinds to answer "which people?" with no document,
 * route or step in the question — a named USER GROUP is exactly that. The four
 * routing fields below are what this class ADDS to the general context; the
 * tenant, the actor and the config are inherited, unchanged and in the same
 * constructor positions they have always occupied.
 *
 * This is source-compatible in both directions that matter. Every property a
 * resolver merged against #989 reads is still here with the same type — nothing
 * became nullable — and every call site, positional or named, still compiles.
 * What it buys is that a resolver needing only the general facts may declare
 * `resolve(AudienceRuleContext $context)`, which satisfies BOTH
 * {@see RoutingRuleResolverInterface} (a widened parameter is legal) and
 * {@see \Whity\Sdk\Audience\AudienceRuleResolverInterface} from one body. Core's
 * `role` and `role_below_actor` do precisely that: neither ever read the
 * document, the route or the step.
 *
 * Immutable value object.
 */
final class RoutingRuleContext extends AudienceRuleContext
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
        int $tenantId,
        public readonly int $documentId,
        public readonly int $routeId,
        public readonly int $stepId,
        public readonly int $position,
        ?int $actorProfileId,
        ?int $actorOuId,
        array $config,
    ) {
        // The four inherited facts are handed up rather than re-promoted here:
        // promoting them again would DECLARE new properties shadowing the
        // parent's, and the shadowed pair would be free to disagree.
        parent::__construct($tenantId, $actorProfileId, $actorOuId, $config);
    }
}
