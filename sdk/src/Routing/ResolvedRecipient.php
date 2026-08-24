<?php

declare(strict_types=1);

namespace Whity\Sdk\Routing;

/**
 * One person a routing rule resolved to, and the unit it reached them through
 * (#947 item 3).
 *
 * Deliberately TWO fields and nothing else. A resolver's job is to answer "who,
 * and via where" — not to decide what happens next, not to write anything, and
 * not to say why. Everything downstream of the answer (de-duplicating across
 * chains, writing the inbox row, stamping the trail) belongs to the host, which
 * is what makes a resolver a pure function of the organisation as it currently
 * stands.
 *
 * WHY `ouId` IS HERE AT ALL
 * -------------------------
 * A profile can hold more than one route into the organisation, and which one a
 * rule reached them through is a FACT ABOUT THE ROUTING, not a property of the
 * person. "Every department head below me" reaches a person through the
 * department they head; the same person may also sit on a committee three
 * branches away. Recording the unit the rule actually used is what lets #947
 * item 5's "passed through my unit" folder answer correctly, and what stops it
 * being re-derived later from a membership that has since changed.
 *
 * Null when the rule reached the person through no unit at all — a tenant-wide
 * role, for instance. Null is the honest answer there; picking their primary
 * unit would attribute the routing to a unit that had nothing to do with it.
 *
 * WHAT THE HOST DOES WITH IT (AND WHY A RESOLVER CANNOT ESCAPE ITS TENANT)
 * -----------------------------------------------------------------------
 * A returned recipient is a SUGGESTION, not a write. The host filters every
 * `profileId` against the active memberships of the tenant the route belongs to
 * before any row is inserted, so a resolver — core's or a plugin's, correct or
 * buggy — cannot place a document in the inbox of somebody outside the tenant.
 * That check lives in the host precisely so it is not a rule every resolver
 * author has to remember.
 *
 * Immutable value object with no dependencies: the SDK is vendored into plugins
 * and depends on nothing but PHP.
 */
final class ResolvedRecipient
{
    /**
     * @param int      $profileId The profile the rule resolved to.
     * @param int|null $ouId      The unit it reached them through, or null when
     *                            the rule is not unit-scoped.
     */
    public function __construct(
        public readonly int $profileId,
        public readonly ?int $ouId = null,
    ) {
    }
}
