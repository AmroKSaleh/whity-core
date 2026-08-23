<?php

declare(strict_types=1);

namespace Whity\Core\Group;

use InvalidArgumentException;
use Whity\Sdk\Routing\ResolvedRecipient;
use Whity\Sdk\Routing\RoutingRuleContext;
use Whity\Sdk\Routing\RoutingRuleResolverInterface;

/**
 * Core routing rule `group` (#999): the people a NAMED USER GROUP resolves to.
 *
 * WHY THIS KIND IS THE POINT OF THE WHOLE FEATURE
 * ----------------------------------------------
 * Without it, a group is a list somebody reads. With it, "send this to the
 * instructors" is one step naming one thing, and the step keeps meaning what the
 * institution currently means by "instructors" — including the person hired
 * after the route was authored, and excluding the one who left.
 *
 * It is also what makes a group REUSABLE rather than merely named. The same
 * definition backs a routing step, a preview screen and whatever reads it next,
 * because the definition is stored once and dereferenced here.
 *
 * CONFIG
 * ------
 *   {"group_id": 7}
 *
 * The group's id rather than its NAME. Renaming a group is an ordinary
 * administrative act — "Instructors" becomes "Teaching staff" — and it must not
 * silently re-point, or un-point, every route step that named it. Ids never
 * change; names are unique only per tenant (migration 116), so a name would not
 * even be a key without a tenant beside it.
 *
 * ROUTING-ONLY, AND THAT IS WHAT MAKES NESTING IMPOSSIBLE
 * ------------------------------------------------------
 * This class implements {@see RoutingRuleResolverInterface} and NOT
 * {@see \Whity\Sdk\Audience\AudienceRuleResolverInterface}, so `group` never
 * appears in the catalogue of kinds a group may be DEFINED as. A group cannot
 * therefore contain a group, and there is no cycle for anything to detect: the
 * guarantee is structural rather than a runtime depth counter that has to be
 * right.
 *
 * The absence is deliberate in the other direction too. A one-deep alias
 * ("group A means whatever group B means") is not composition, and shipping it
 * as though it were would fix the wrong meaning for "a group of groups" before
 * the real vocabulary — set union and intersection over sub-expressions, with
 * their own validation and their own preview semantics — has been designed.
 * {@see GroupResolver} carries that argument in full.
 *
 * `validate()` CHECKS THE SHAPE AND CANNOT CHECK THE GROUP EXISTS
 * --------------------------------------------------------------
 * {@see RoutingRuleResolverInterface::validate()} is handed a config and NO
 * TENANT — a contract this class cannot change, and should not want to. Looking
 * a group id up without a tenant would mean reading across tenants to answer a
 * question about one, which turns a validation convenience into a cross-tenant
 * read. So a group id naming nothing is caught at RESOLUTION and named there,
 * exactly as an uninstalled plugin's kind is (#989): step 1 fails the
 * route-creation request outright, and a later step fails loudly when it is
 * reached, rather than reaching nobody and reporting success.
 *
 * In practice an author picks a group from a list, so a bad id is a client bug
 * rather than a typo — and it produces a message naming the group, which is what
 * a client bug should produce.
 *
 * WHY A `GroupRejectedException` BECOMES AN `InvalidArgumentException` HERE
 * ------------------------------------------------------------------------
 * The routing engine distinguishes two failure classes by TYPE: an
 * `InvalidArgumentException` from a resolver is a message for the caller and
 * reaches them verbatim, prefixed with the step number; anything else is code
 * misbehaving, gets logged, and the caller is told only which step failed. A
 * deleted group is firmly the first kind — the author needs to read "user group
 * 7 does not exist" — so the group layer's own refusal is translated at this
 * boundary instead of leaking a type routing would treat as a crash.
 *
 * Stateless apart from its collaborator — worker-safe, and called once per acting
 * recipient within a request.
 */
final class GroupRuleResolver implements RoutingRuleResolverInterface
{
    public function __construct(private readonly GroupResolver $groups)
    {
    }

    public function label(): string
    {
        return 'Everyone in a user group';
    }

    /**
     * @param array<string, mixed> $config
     */
    public function validate(array $config): void
    {
        self::requireGroupId($config);
    }

    /**
     * @return list<ResolvedRecipient>
     */
    public function resolve(RoutingRuleContext $context): array
    {
        $groupId = self::requireGroupId($context->config);

        try {
            // Tenant-scoped by the context, and scoped again by the repository:
            // a `group_id` belonging to another tenant does not resolve to that
            // tenant's people, it resolves to a refusal naming the id.
            //
            // The answer comes back already filtered to this tenant's active
            // members. The router filters it again, which is not waste — the
            // boundary must not depend on a resolver having been polite, and the
            // check is idempotent.
            return $this->groups->resolveGroup(
                $context->tenantId,
                $groupId,
                $context->actorProfileId,
                $context->actorOuId,
            );
        } catch (GroupRejectedException $e) {
            // See the class docblock: this is a message for the route's author,
            // and only the InvalidArgumentException channel reaches them.
            throw new InvalidArgumentException($e->clientMessage);
        }
    }

    /**
     * The configured group id, or a message the route's author can act on.
     *
     * Shared by {@see validate()} and {@see resolve()} so the two cannot drift: a
     * config that validated at authoring time must still be readable when the
     * step is reached, possibly months later and after an upgrade.
     *
     * @param array<string, mixed> $config
     *
     * @throws InvalidArgumentException
     */
    private static function requireGroupId(array $config): int
    {
        $raw = $config['group_id'] ?? null;

        // Both spellings of the same number are accepted, for the reason
        // {@see \Whity\Core\Document\Routing\RoleRuleResolver::requireRoleId()}
        // records: a JSON body decodes `7` as int and `"7"` as string, a JSONB
        // round trip can return either depending on the driver, and refusing one
        // would make a route resolvable on PostgreSQL and broken on the offline
        // SQLite engine.
        if (is_int($raw) && $raw > 0) {
            return $raw;
        }
        if (is_string($raw) && preg_match('/^\d+$/', $raw) === 1 && (int) $raw > 0) {
            return (int) $raw;
        }

        throw new InvalidArgumentException(
            "the 'group' rule needs a 'group_id' naming the user group whose people it reaches"
        );
    }
}
