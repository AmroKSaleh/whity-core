<?php

declare(strict_types=1);

namespace Whity\Core\Group;

use PDO;

/**
 * Data-access for `user_groups` (#999) — the named rules, and nothing about who
 * is in them.
 *
 * THERE IS NO MEMBER QUERY HERE, AND THERE CANNOT BE
 * -------------------------------------------------
 * Every method on this class reads or writes a DEFINITION. None of them can
 * answer "who is in group 7", because that answer is not stored anywhere: it is
 * computed by {@see GroupResolver} from the rule, against the organisation as it
 * stands at the instant of asking. That absence is the whole design expressed in
 * one class — a stored membership list omits the instructor hired last week,
 * still renders, and still reports success, and migration 116 argues it at
 * length.
 *
 * The consequence worth naming, because it is the price: "which groups is this
 * person in" is not an SQL question. It can only be answered by resolving every
 * group and testing each answer, and nothing in core does that today. The
 * alternative — a materialised membership table maintained on every change to
 * memberships, roles, the unit tree and any plugin table a plugin's rule happens
 * to read — is a cache whose invalidation surface is unknowable, which is why it
 * is not offered rather than offered badly.
 *
 * `rule_config` IS OPAQUE AND IS DECODED IN EXACTLY ONE PLACE
 * ----------------------------------------------------------
 * The config crosses the driver as a JSON string in both directions: PostgreSQL
 * hands `jsonb` back as text, the offline SQLite engine stores TEXT outright, and
 * a repository that returned the raw column would give its callers a string on
 * one engine and — with a future driver flag — an array on the other. Decoded
 * here, once, for the same reason {@see \Whity\Core\Document\Routing\RouteStepRepository}
 * decodes there: a dialect difference must not reach the resolvers.
 *
 * TENANT-OWNED. Every statement binds a literal `tenant_id` predicate, spelled
 * out in SQL so scripts/ci-tenant-predicate-guard.php can verify it by reading
 * this file. A group belongs to one tenant and an id from another must not
 * resolve — reported as ABSENT rather than forbidden, the posture
 * {@see \Whity\Core\Document\DocumentVisibilityPolicy} and
 * {@see \Whity\Core\Document\DocumentCollectionRepository} already take, because
 * group ids are enumerable integers and a 403 would confirm which of them exist.
 */
final class UserGroupRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * A page of this tenant's groups, by name.
     *
     * Ordered by name because that is how a picker reads and because it is the
     * order the unique constraint's index already provides — no sort, no second
     * index.
     *
     * DELIBERATELY WITHOUT A MEMBER COUNT. `document_collections` carries an
     * `item_count` on its list rows and this does not, and the difference is the
     * point: there the count is a covered sub-select over an index, here it would
     * be a full rule resolution — a fan-out query per row, on every render of
     * every list. Forty groups on a page would commission forty resolutions to
     * decorate a screen nobody asked a membership question on. A count is
     * available, exactly, from the preview endpoint, one group at a time, where
     * somebody has asked for it.
     *
     * @return list<array<string, mixed>>
     */
    public function listForTenant(int $tenantId, int $limit, int $offset): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, tenant_id, name, description, rule_kind, rule_config,
                    created_by, created_at, updated_at
               FROM user_groups
              WHERE tenant_id = :tenant_id
              ORDER BY name ASC, id ASC
              LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(self::normalize(...), $rows);
    }

    /**
     * How many groups this tenant has, for the pagination envelope.
     *
     * A separate count rather than a window function: the two engines disagree
     * about `COUNT(*) OVER ()` on an empty result, and a total that is absent
     * when a page is empty is a total a client cannot render.
     */
    public function countForTenant(int $tenantId): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) AS c FROM user_groups WHERE tenant_id = :tenant_id');
        $stmt->execute([':tenant_id' => $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? 0 : (int) $row['c'];
    }

    /**
     * One group, tenant-scoped. Null when it does not exist or belongs elsewhere.
     *
     * Null covers "no such id" and "another tenant's" identically, and every
     * caller turns it into a 404.
     *
     * @return array<string, mixed>|null
     */
    public function findById(int $id, int $tenantId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, tenant_id, name, description, rule_kind, rule_config,
                    created_by, created_at, updated_at
               FROM user_groups
              WHERE id = :id AND tenant_id = :tenant_id'
        );
        $stmt->execute([':id' => $id, ':tenant_id' => $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? self::normalize($row) : null;
    }

    /**
     * A group by name within a tenant, for the pre-flight duplicate check.
     *
     * The UNIQUE constraint is the authority — this only exists so a duplicate is
     * a 409 naming the group somebody already made, rather than a driver
     * integrity error the caller cannot read. A concurrent create still lands on
     * the constraint, which is correct: the check narrows the common case and the
     * constraint closes the race.
     *
     * @return array<string, mixed>|null
     */
    public function findByName(string $name, int $tenantId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, tenant_id, name, description, rule_kind, rule_config,
                    created_by, created_at, updated_at
               FROM user_groups
              WHERE tenant_id = :tenant_id AND name = :name'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':name' => $name]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? self::normalize($row) : null;
    }

    /**
     * Create a group and return its id.
     *
     * The rule has already been validated by its own resolver before this is
     * called — see {@see GroupResolver::validateExpression()}. This method does
     * not re-validate, and must not: the only code that knows what an
     * `acme:committee` config means is the resolver the plugin registered, and a
     * second opinion here would be a guess that can disagree with it.
     *
     * @param array<string, mixed> $ruleConfig
     */
    public function create(
        int $tenantId,
        string $name,
        ?string $description,
        string $ruleKind,
        array $ruleConfig,
        ?int $createdBy,
    ): int {
        $stmt = $this->db->prepare(
            'INSERT INTO user_groups
                 (tenant_id, name, description, rule_kind, rule_config, created_by, created_at, updated_at)
             VALUES (:tenant_id, :name, :description, :rule_kind, :rule_config, :created_by, NOW(), NOW())'
        );
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':name' => $name,
            ':description' => $description,
            ':rule_kind' => $ruleKind,
            // An empty config encodes as `{}` rather than `[]`: PHP cannot tell
            // an empty map from an empty list, `[]` is not a valid jsonb OBJECT,
            // and every read would then decode a list where the resolver expects
            // a map. The same choice, for the same reason, as
            // {@see \Whity\Core\Document\Routing\RouteStepRepository::create()}.
            ':rule_config' => $ruleConfig === [] ? '{}' : (string) json_encode($ruleConfig),
            ':created_by' => $createdBy,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Update a group in place, and return whether a row changed.
     *
     * UPDATE, NOT INSERT-AND-RETIRE. A group is referenced BY ID from route steps
     * that may still be running, so redefining "instructors" has to keep being
     * group 7 — versioning it would leave every existing reference pointing at
     * the old meaning while the screen showed the new one, which is a worse
     * failure than the one versioning would prevent.
     *
     * The redefinition is therefore visible immediately to everything that names
     * the group, INCLUDING routes already in flight, and that is the intended
     * reading: the group means what it now says, and a step reached tomorrow
     * resolves against tomorrow's definition. A circulation that must be pinned
     * to a set of people as they were is asking for `explicit`, or for the
     * trail — which records who each step ACTUALLY reached, immutably, and is
     * where "who got this in March" is answered.
     *
     * Every field is written on every update, including `updated_at`. A partial
     * update path would need a second SQL builder for the same table and would be
     * one place for a caller to forget the timestamp.
     *
     * @param array<string, mixed> $ruleConfig
     */
    public function update(
        int $id,
        int $tenantId,
        string $name,
        ?string $description,
        string $ruleKind,
        array $ruleConfig,
    ): bool {
        $stmt = $this->db->prepare(
            'UPDATE user_groups
                SET name = :name,
                    description = :description,
                    rule_kind = :rule_kind,
                    rule_config = :rule_config,
                    updated_at = NOW()
              WHERE id = :id AND tenant_id = :tenant_id'
        );
        $stmt->execute([
            ':id' => $id,
            ':tenant_id' => $tenantId,
            ':name' => $name,
            ':description' => $description,
            ':rule_kind' => $ruleKind,
            ':rule_config' => $ruleConfig === [] ? '{}' : (string) json_encode($ruleConfig),
        ]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Delete a group, and return whether a row went.
     *
     * NOTHING IS CHECKED FIRST, DELIBERATELY. A route step naming this group
     * keeps its `rule_config` (`{"group_id": 7}`) and will fail LOUDLY, by name,
     * when it is reached — see {@see GroupRuleResolver}. Refusing the delete
     * instead would mean scanning every step's opaque JSONB in two SQL dialects
     * to protect against a state the resolver already reports honestly, and it
     * would make a group undeletable because of a route somebody abandoned.
     *
     * Loud failure is the point. Silently resolving a deleted group to nobody
     * would drop a whole class of people from a distribution and report success,
     * which is the single outcome this design is written against.
     */
    public function delete(int $id, int $tenantId): bool
    {
        $stmt = $this->db->prepare('DELETE FROM user_groups WHERE id = :id AND tenant_id = :tenant_id');
        $stmt->execute([':id' => $id, ':tenant_id' => $tenantId]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Map a raw row to the typed shape, decoding the config exactly once.
     *
     * A config that will not decode comes back as an empty map rather than as a
     * fatal: the row exists, the group is real, and the resolver's own validation
     * is what turns "this rule cannot work with this config" into a message.
     * Failing here would make one corrupt row un-listable, taking the tenant's
     * whole group list with it.
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
            'name' => (string) $row['name'],
            'description' => $row['description'] !== null ? (string) $row['description'] : null,
            'rule_kind' => (string) $row['rule_kind'],
            'rule_config' => is_array($decoded) ? $decoded : [],
            'created_by' => $row['created_by'] !== null ? (int) $row['created_by'] : null,
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }
}
