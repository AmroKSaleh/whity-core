<?php

declare(strict_types=1);

namespace Whity\Core\Document\Routing;

use InvalidArgumentException;
use PDO;
use Whity\Core\Ou\OuSubtree;
use Whity\Sdk\Audience\AudienceRuleContext;
use Whity\Sdk\Audience\AudienceRuleResolverInterface;
use Whity\Sdk\Routing\ResolvedRecipient;
use Whity\Sdk\Routing\RoutingRuleResolverInterface;

/**
 * Core routing rule `role_below_actor` (#947 item 3): everyone holding a named
 * role, within the ACTING PERSON's own unit and everything beneath it.
 *
 * The scoped fan-out — and the rule that makes #947's second semantic visible
 * in the schema. "Every department head below me" is a different set of people
 * for a dean than for a faculty officer, so a step naming this kind resolves
 * ONCE PER ACTING RECIPIENT, relative to them. Each chain then proceeds on its
 * own, which is what "distribution fans out, it does not block" means when you
 * write it down: there is no aggregate anywhere that could hold the fast chains
 * while the slow one catches up, because each is resolved from a different root.
 *
 * A stored recipient list cannot express this at all. It would have to be
 * flattened at authoring time into one list of everybody under everybody, which
 * is a different distribution — it sends the dean's copy to the faculty
 * officer's departments and reports success.
 *
 * "BELOW" INCLUDES THE ACTOR'S OWN UNIT
 * -------------------------------------
 * {@see OuSubtree::descendantIds()} is root-inclusive, and that is the right
 * reading here. A department head routing to the technicians *below* them means
 * the technicians in their department; the strict-descendants reading resolves
 * to NOBODY for every leaf unit in the tree, while the step still completes and
 * the run still reports success. That is precisely the silent-omission failure
 * #947 item 3 exists to prevent, so the inclusive reading is the safe default.
 *
 * The actor is not excluded from the result either, when they happen to hold
 * the role themselves. De-duplication is the host's job and it already has to
 * handle a person reached by two chains, so a special case here would be a
 * second, subtly different answer to a question already answered once.
 *
 * AN ACTOR WITH NO UNIT RESOLVES TO NOBODY
 * ----------------------------------------
 * `actorOuId` is null when the raiser belongs to no unit, or when the rule that
 * reached the actor was not unit-scoped. There is no subtree to walk, so the
 * answer is the empty set — not the whole tenant, which is what falling back to
 * "unscoped" would quietly do: a route that was authored to keep a document
 * inside one faculty would broadcast it to the institution the first time
 * somebody without a unit touched it. The empty result is recorded in the trail
 * as an empty distribution, so it is visible rather than merely safe.
 *
 * CONFIG
 * ------
 *   {"role_id": 7}
 *
 * Identical to {@see RoleRuleResolver}, deliberately — the two kinds differ only
 * in scope, so an author switching between them keeps the same configuration and
 * a picker can offer them side by side. See that class for why the id and not
 * the name.
 *
 * ALSO A GROUP DEFINITION, AND IT IS RELATIVE TO WHOEVER USES IT (#999)
 * ---------------------------------------------------------------------
 * This rule never reads the document, the route or the step, so it can define a
 * named USER GROUP — declared by implementing
 * {@see AudienceRuleResolverInterface} alongside the routing one and widening the
 * parameter to {@see AudienceRuleContext}. One body serves both.
 *
 * A group defined this way resolves to a DIFFERENT SET FOR EACH PERSON who uses
 * it, and that is the feature rather than a defect: "the instructors in my own
 * unit and below" is ONE named rule that every unit head can address a document
 * to and that means their own unit each time. The alternative — one group per
 * unit head — is the thousand-nodes problem, restated.
 *
 * The consequence is that a PREVIEW of such a group is relative to whoever asked
 * for it, which is why {@see \Whity\Core\Audience\AudiencePreview} reports the
 * actor it resolved against. Without that, two colleagues would read two
 * different counts off the same screen with nothing to explain the difference.
 *
 * Stateless apart from its PDO handle — worker-safe, and called once per acting
 * recipient within a request.
 */
final class RoleBelowActorRuleResolver implements RoutingRuleResolverInterface, AudienceRuleResolverInterface
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function label(): string
    {
        return 'Everyone holding a role, in my unit and below';
    }

    /**
     * @param array<string, mixed> $config
     */
    public function validate(array $config): void
    {
        self::requireRoleId($config);
    }

    /**
     * @return list<ResolvedRecipient>
     */
    public function resolve(AudienceRuleContext $context): array
    {
        $roleId = self::requireRoleId($context->config);

        if ($context->actorOuId === null) {
            return [];
        }

        // Tenant-scoped inside the walk itself: the projection it reads is one
        // tenant's units, so an actor OU from elsewhere reaches nothing.
        $scope = OuSubtree::descendantIds($this->db, $context->tenantId, [$context->actorOuId]);
        if ($scope === []) {
            return [];
        }

        // The unit list is expanded into bound placeholders rather than
        // interpolated, and the statement still carries a literal tenant
        // predicate so scripts/ci-tenant-predicate-guard.php can verify it by
        // reading this file. Placeholders are generated positionally (:ou0,
        // :ou1, …) because a subtree has no fixed width; every value in it came
        // from a tenant-scoped query above, never from the request.
        $placeholders = [];
        $params = [':tenant_id' => $context->tenantId, ':role_id' => $roleId];
        foreach (array_values($scope) as $i => $ouId) {
            $name = ':ou' . $i;
            $placeholders[] = $name;
            $params[$name] = $ouId;
        }

        $stmt = $this->db->prepare(
            "SELECT profile_id, ou_id
               FROM memberships
              WHERE tenant_id = :tenant_id
                AND role_id = :role_id
                AND status = 'active'
                AND ou_id IN (" . implode(', ', $placeholders) . ')'
        );
        $stmt->execute($params);

        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(
            static fn (array $row): ResolvedRecipient => new ResolvedRecipient(
                (int) $row['profile_id'],
                $row['ou_id'] !== null ? (int) $row['ou_id'] : null,
            ),
            $rows
        );
    }

    /**
     * The configured role id, or a message the route's author can act on.
     *
     * @param array<string, mixed> $config
     *
     * @throws InvalidArgumentException
     */
    private static function requireRoleId(array $config): int
    {
        $raw = $config['role_id'] ?? null;

        // Both spellings of the same number are accepted, for the reason
        // {@see RoleRuleResolver::requireRoleId()} records: a JSONB round trip
        // can return either depending on the driver, and refusing one would
        // make a route resolvable on one engine and not the other.
        if (is_int($raw) && $raw > 0) {
            return $raw;
        }
        if (is_string($raw) && preg_match('/^\d+$/', $raw) === 1 && (int) $raw > 0) {
            return (int) $raw;
        }

        throw new InvalidArgumentException(
            "the 'role_below_actor' rule needs a 'role_id' naming the role its recipients hold"
        );
    }
}
