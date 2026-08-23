<?php

declare(strict_types=1);

namespace Whity\Core\Document\Routing;

use InvalidArgumentException;
use PDO;
use Whity\Sdk\Routing\ResolvedRecipient;
use Whity\Sdk\Routing\RoutingRuleContext;
use Whity\Sdk\Routing\RoutingRuleResolverInterface;

/**
 * Core routing rule `role` (#947 item 3): everyone holding a named role,
 * anywhere in the tenant.
 *
 * The unscoped fan-out. "This circular goes to every registrar" — wherever they
 * sit, however the organisation is shaped, including registrars in a unit that
 * did not exist when the route was authored. That last clause is the entire
 * reason a step names a rule rather than a list.
 *
 * WHY THIS ONE IS GENERIC ENOUGH FOR CORE
 * ---------------------------------------
 * Every deployment has roles, and `memberships.role_id` is a column core owns.
 * There is no organisational vocabulary to guess at, no assumption about tree
 * shape, and the answer is correct on an install core has never seen. Contrast a
 * `supervisor` rule, which sounds equally generic and means three different
 * things in three deployments — that one belongs in a plugin, through
 * {@see \Whity\Sdk\Routing\PluginRoutingRulesInterface}.
 *
 * WHAT "HOLDING A ROLE" MEANS HERE: THE DIRECT MEMBERSHIP ROLE, AND NOTHING ELSE
 * -----------------------------------------------------------------------------
 * A profile is resolved when their ACTIVE membership in this tenant has
 * `role_id` equal to the configured role. Two things that look like they should
 * count are deliberately excluded:
 *
 *  - ROLE INHERITANCE (`roles.parent_id`). Inheritance is about PERMISSIONS,
 *    not identity. A senior role that inherits from `registrar` holds
 *    everything a registrar may DO; it does not make its holder a registrar,
 *    and treating it as such would silently copy every circular addressed to
 *    registrars onto a group nobody addressed. The fan-out would grow every
 *    time somebody re-parented a role, for reasons invisible in the route.
 *
 *  - RESOURCE-SCOPED GRANTS (`ou_role_assignments`, `resource_role_assignments`).
 *    Those say "at THIS unit / on THIS record, you act as R" — a statement
 *    about authority over one thing, not about who a person is in the
 *    organisation. Routing asks the second question.
 *
 * The narrow reading is also the one an author can predict from the picker they
 * used: they chose a role, and the people who have that role are the people who
 * receive it.
 *
 * INACTIVE MEMBERSHIPS ARE EXCLUDED, AND THAT IS NOT A SPECIAL CASE
 * ----------------------------------------------------------------
 * `status = 'active'` — a suspended or merely invited member is not part of the
 * organisation for the purpose of receiving work. {@see DocumentRouter} applies
 * the same membership filter to EVERY resolver's output, core's and plugins'
 * alike, so this predicate is belt-and-braces rather than the only line of
 * defence; it is here so the query returns the right rows rather than returning
 * wrong ones for the host to discard.
 *
 * CONFIG
 * ------
 *   {"role_id": 7}
 *
 * The role's ID rather than its NAME, deliberately. Renaming a role is a normal
 * administrative act and must not silently re-point — or un-point — every route
 * that named it; role ids never change. (`roles.name` is unique only per tenant
 * and separately among global roles, per migration 093, so a name is not even a
 * unique key without a tenant beside it.) Neither is enforced by a foreign key:
 * `rule_config` is opaque JSONB, for the reasons migration 112 records.
 *
 * A ROLE THAT NOBODY HOLDS RESOLVES TO NOBODY, AND SAYS SO
 * -------------------------------------------------------
 * Not an error. {@see validate()} checks the SHAPE of the config and not the
 * world, as the interface requires: a role held by no one today may be held
 * tomorrow, and refusing to author the step would rebuild the stored-list
 * failure inside the validator. What makes the empty case safe is that it is
 * VISIBLE — the trail records the distribution with an empty recipient set, and
 * the route-creation response reports the count per step, so an author who
 * picked the wrong role finds out in the response rather than in a complaint
 * six weeks later.
 *
 * Stateless apart from its PDO handle — worker-safe, and called once per acting
 * recipient within a request.
 */
final class RoleRuleResolver implements RoutingRuleResolverInterface
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function label(): string
    {
        return 'Everyone holding a role';
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
    public function resolve(RoutingRuleContext $context): array
    {
        $roleId = self::requireRoleId($context->config);

        // Tenant-scoped in literal SQL so scripts/ci-tenant-predicate-guard.php
        // can verify it by reading this file. The role id is bound, never
        // interpolated, and is bounded by the tenant on the membership side —
        // so a config naming a role that belongs to a different tenant resolves
        // to nobody rather than to that tenant's people.
        $stmt = $this->db->prepare(
            "SELECT profile_id, ou_id
               FROM memberships
              WHERE tenant_id = :tenant_id
                AND role_id = :role_id
                AND status = 'active'"
        );
        $stmt->execute([':tenant_id' => $context->tenantId, ':role_id' => $roleId]);

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
     * Shared by {@see validate()} and {@see resolve()} so the two cannot drift:
     * a config that validated at authoring time must still be readable when the
     * step is reached, possibly months later and after an upgrade.
     *
     * @param array<string, mixed> $config
     *
     * @throws InvalidArgumentException
     */
    private static function requireRoleId(array $config): int
    {
        $raw = $config['role_id'] ?? null;

        // A JSON body decodes `7` as int and `"7"` as string, and a config
        // round-tripped through JSONB can come back either way depending on the
        // driver. Accepting both spellings of the same number is not laxity —
        // refusing one would make a route un-resolvable on one engine and fine
        // on the other, which is the worst possible place for a dialect
        // difference to surface.
        if (is_int($raw) && $raw > 0) {
            return $raw;
        }
        if (is_string($raw) && preg_match('/^\d+$/', $raw) === 1 && (int) $raw > 0) {
            return (int) $raw;
        }

        throw new InvalidArgumentException(
            "the 'role' rule needs a 'role_id' naming the role its recipients hold"
        );
    }
}
