<?php

declare(strict_types=1);

namespace Whity\Core\Document\Routing;

use PDO;

/**
 * Data-access for `document_route_steps` (#947 item 3) — the ordered plan a
 * route follows.
 *
 * A STEP NAMES A RULE, AND THERE IS NOWHERE TO PUT A PERSON
 * --------------------------------------------------------
 * The columns are `(position, rule_kind, rule_config, label)`. No profile id,
 * no recipient list, no join table to one. That absence is the first of #947's
 * three semantics expressed in storage: a step cannot record who it will reach,
 * so it has to be resolved when it is reached — against the organisation as it
 * stands then, which is how a unit created last week is included without anyone
 * remembering to add it.
 *
 * The rejected alternative fails SILENTLY, which is what makes it worth
 * refusing at the schema level rather than by convention: a stored list omits
 * the new unit, the document still renders, the step still completes and the
 * run still reports success.
 *
 * NO UPDATE, NO DELETE
 * --------------------
 * Steps are written once, with their route, inside {@see DocumentRouter::issue()}'s
 * transaction. Amending a circulation already under way is a new route on the
 * same document — see {@see RouteRepository} for why there is no draft state to
 * amend, and why that is the same argument migration 108 makes against a status
 * column.
 *
 * `rule_config` IS JSONB AND THAT IS NOT A CONTRADICTION
 * ------------------------------------------------------
 * Migration 112 argues at length that the TRAIL needs real columns because its
 * shape is fixed and known to core. A rule's parameters are the opposite: open
 * by construction, since core cannot know what an `acme:committee` rule needs to
 * be told. The only code that knows is the resolver the plugin registered, which
 * is why {@see \Whity\Sdk\Routing\RoutingRuleResolverInterface::validate()} is
 * called on every step before any of them is written, and why the column is
 * opaque afterwards.
 *
 * ENCODED HERE, NOT AT THE BOUNDARY
 * ---------------------------------
 * The config crosses the driver as a JSON string in both directions. PostgreSQL
 * hands back `jsonb` as text and SQLite (the offline/desktop engine) stores TEXT
 * outright, so a repository that returned the raw column would hand its callers
 * a string on one engine and — with a future driver flag — an array on the
 * other. Decoding in one place is what stops that dialect difference reaching
 * the resolvers.
 *
 * TENANT-OWNED. Every statement binds a literal `tenant_id` predicate, spelled
 * out in SQL so scripts/ci-tenant-predicate-guard.php can verify it by reading
 * this file.
 */
final class RouteStepRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * Append one step to a route and return its id.
     *
     * `position` is 1-based and unique within the route (migration 112), so a
     * caller that computed two identical positions gets an integrity error
     * rather than a route whose "next step" is decided by insertion order.
     *
     * @param array<string, mixed> $ruleConfig Validated by the rule's own resolver
     *                                         before this is called.
     */
    public function create(
        int $tenantId,
        int $routeId,
        int $position,
        string $ruleKind,
        array $ruleConfig,
        ?string $label,
    ): int {
        $stmt = $this->db->prepare(
            'INSERT INTO document_route_steps
                 (tenant_id, route_id, position, rule_kind, rule_config, label, created_at)
             VALUES (:tenant_id, :route_id, :position, :rule_kind, :rule_config, :label, NOW())'
        );
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':route_id' => $routeId,
            ':position' => $position,
            ':rule_kind' => $ruleKind,
            // An empty config encodes as `{}` rather than `[]`: PHP cannot tell
            // an empty map from an empty list, and `[]` is not a valid jsonb
            // OBJECT, so PostgreSQL would accept it and every read would then
            // decode a list where the resolver expects a map.
            ':rule_config' => $ruleConfig === [] ? '{}' : (string) json_encode($ruleConfig),
            ':label' => $label,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * One step, tenant-scoped. Null when it does not exist or belongs elsewhere.
     *
     * @return array<string, mixed>|null
     */
    public function findById(int $id, int $tenantId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, tenant_id, route_id, position, rule_kind, rule_config, label, created_at
               FROM document_route_steps
              WHERE id = :id AND tenant_id = :tenant_id'
        );
        $stmt->execute([':id' => $id, ':tenant_id' => $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? self::normalize($row) : null;
    }

    /**
     * A route's steps in order.
     *
     * @return list<array<string, mixed>>
     */
    public function listForRoute(int $routeId, int $tenantId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, tenant_id, route_id, position, rule_kind, rule_config, label, created_at
               FROM document_route_steps
              WHERE tenant_id = :tenant_id AND route_id = :route_id
              ORDER BY position ASC'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':route_id' => $routeId]);

        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(self::normalize(...), $rows);
    }

    /**
     * The step immediately after `$position` in a route, or null when there is
     * none.
     *
     * THIS IS THE ONE PLACE THE ROUTE'S SHAPE IS ASSUMED TO BE LINEAR, and it is
     * deliberately the only one. `position` is an authoring ordinal rather than a
     * depth (migration 112), so a branching route — "if approved go here, if
     * returned go there" — is expressible as an edges table plus a rewrite of
     * THIS METHOD, with its single caller
     * ({@see DocumentRouter::act()}) unchanged in shape: it asks "what comes
     * next for this actor" and does not care whether the answer came from an
     * ordinal or from the outgoing edge whose condition the act matched.
     *
     * Confining the assumption here is what makes branching an addition rather
     * than a retrofit. Spreading `position + 1` through the engine would have
     * put the linear assumption in every call site instead.
     *
     * A QUERY RATHER THAN ARITHMETIC, even today: ordinals are unique but not
     * required to be contiguous, so `position + 1` would silently find nothing
     * on a route whose numbering has a gap — and a forward that resolves to
     * nothing while reporting success is the failure this whole item is written
     * against.
     *
     * @return array<string, mixed>|null
     */
    public function findNext(int $routeId, int $tenantId, int $position): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, tenant_id, route_id, position, rule_kind, rule_config, label, created_at
               FROM document_route_steps
              WHERE tenant_id = :tenant_id AND route_id = :route_id AND position > :position
              ORDER BY position ASC
              LIMIT 1'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':route_id' => $routeId, ':position' => $position]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? self::normalize($row) : null;
    }

    /**
     * Map a raw row to the typed shape, decoding the config exactly once.
     *
     * A config that will not decode comes back as an empty map rather than as a
     * fatal: the row exists, the route is real, and the resolver's own
     * validation is what turns "this rule cannot work with this config" into a
     * message. Failing here would make one corrupt row un-listable, taking the
     * whole route's history with it.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function normalize(array $row): array
    {
        $decoded = json_decode((string) ($row['rule_config'] ?? '{}'), true);

        return [
            'id' => (int) $row['id'],
            'tenant_id' => (int) $row['tenant_id'],
            'route_id' => (int) $row['route_id'],
            'position' => (int) $row['position'],
            'rule_kind' => (string) $row['rule_kind'],
            'rule_config' => is_array($decoded) ? $decoded : [],
            'label' => $row['label'] !== null ? (string) $row['label'] : null,
            'created_at' => (string) $row['created_at'],
        ];
    }
}
