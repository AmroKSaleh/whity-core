<?php

declare(strict_types=1);

namespace Whity\Core\RBAC;

use PDO;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Whity\Core\Tenant\TenantContext;

/**
 * Writes and reads resource-scoped role grants (WC-712 §2).
 *
 * The single write path for `resource_role_assignments`. It exists so the
 * tenant-isolation guard is enforced in ONE place: a plugin that hand-rolled its
 * own INSERT would have to re-derive "is this role visible to this tenant?", and
 * a partial re-derivation is how a tenant ends up attaching another tenant's
 * private role to its own record.
 *
 * The guard mirrors {@see \Whity\Api\OusApiHandler::assignRole()} exactly:
 *   - the ROLE must be the caller's own or a global (NULL tenant_id) role;
 *   - a role outside that set is reported as NOT FOUND rather than forbidden, so
 *     cross-tenant role existence is never disclosed;
 *   - the tenant_id written is the CALLER's, never one taken from input, so a
 *     supplied resource_id belonging to another tenant produces a row that
 *     resolution (which filters on tenant_id) can never read back.
 *
 * Resource ownership itself is the caller's to check: `resource_id` is
 * polymorphic and this class cannot know which table to look in. Callers must
 * confirm the resource is visible in the tenant before granting — exactly as
 * OusApiHandler checks the OU exists in the tenant before assigning.
 */
class ResourceRoleAssignmentRepository
{
    /**
     * Audit sink for refused cross-tenant grants.
     *
     * Defaults to a {@see NullLogger} so the repository is silent unless a real
     * PSR-3 logger is wired in — the same contract as
     * {@see \Whity\Http\Middleware\EnforceTenantIsolation}. This is not a
     * cosmetic preference: a refused grant is an EXPECTED, handled outcome that
     * the test suite triggers deliberately, so writing it to the process-wide
     * error log made a PASSING suite emit to STDERR. Infection stops its initial
     * test run on the first byte of STDERR
     * ({@see \Infection\Process\Runner\InitialTestsRunner}), which killed the
     * scheduled mutation-testing job with a misleading "tests must be in a
     * passing state / hidden dependencies" message. Production still gets the
     * record — public/index.php injects the application logger.
     */
    private LoggerInterface $logger;

    /**
     * @param PDO                   $db            Connection owning `resource_role_assignments`.
     * @param ResourceTypeRegistry  $resourceTypes Registry the resource type must be declared in.
     * @param LoggerInterface|null  $logger        Optional PSR-3 audit sink for refused grants.
     *                                             When null a {@see NullLogger} is used.
     */
    public function __construct(
        private readonly PDO $db,
        private readonly ResourceTypeRegistry $resourceTypes,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Grant a role at one resource. Idempotent.
     *
     * @param int      $tenantId     The CALLER's tenant (never client-supplied).
     * @param string   $resourceType A registered resource type.
     * @param int      $resourceId   The record the grant is addressed at.
     * @param int      $roleId       The role being granted.
     * @param int|null $profileId    NULL = everyone at this resource; else one profile.
     *
     * @return int|null The assignment id, or null when the grant already existed.
     *
     * @throws InvalidResourceTypeException When the type is not registered.
     * @throws RoleNotVisibleException      When the role is not the tenant's own or global.
     */
    public function grant(
        int $tenantId,
        string $resourceType,
        int $resourceId,
        int $roleId,
        ?int $profileId = null
    ): ?int {
        $this->assertResourceTypeRegistered($resourceType);
        $this->assertRoleVisibleToTenant($roleId, $tenantId);

        if ($this->find($tenantId, $resourceType, $resourceId, $roleId, $profileId) !== null) {
            return null;
        }

        $statement = $this->db->prepare(
            'INSERT INTO resource_role_assignments
                 (tenant_id, resource_type, resource_id, role_id, profile_id, created_at)
             VALUES (?, ?, ?, ?, ?, NOW())'
        );
        $statement->execute([$tenantId, $resourceType, $resourceId, $roleId, $profileId]);

        // Every worker holds its own resolution cache, so a grant written here is
        // invisible to the other workers until they are cleared too (PR #701).
        \Whity\Auth\RoleChecker::clearCache();

        return (int) $this->db->lastInsertId();
    }

    /**
     * Revoke a grant. Returns whether a row was removed.
     *
     * `profile_id` participates in the match: revoking the everyone-grant must
     * not silently remove a profile's individual grant at the same resource.
     */
    public function revoke(
        int $tenantId,
        string $resourceType,
        int $resourceId,
        int $roleId,
        ?int $profileId = null
    ): bool {
        $sql = 'DELETE FROM resource_role_assignments
                WHERE tenant_id = ? AND resource_type = ? AND resource_id = ? AND role_id = ?
                  AND ' . ($profileId === null ? 'profile_id IS NULL' : 'profile_id = ?');

        $params = [$tenantId, $resourceType, $resourceId, $roleId];
        if ($profileId !== null) {
            $params[] = $profileId;
        }

        $statement = $this->db->prepare($sql);
        $statement->execute($params);

        $removed = $statement->rowCount() > 0;
        if ($removed) {
            \Whity\Auth\RoleChecker::clearCache();
        }

        return $removed;
    }

    /**
     * Revoke ONE grant by its own id, tenant scoped.
     *
     * The id-addressed counterpart of {@see revoke()}. An id names exactly one
     * row, so nothing has to re-encode "everyone here" versus "this profile
     * here" — the distinction {@see revoke()} must carry through a nullable
     * argument, and the one an HTTP caller cannot express unambiguously because
     * an omitted query parameter and an explicit null look identical.
     *
     * The `tenant_id` predicate is what makes an id safe to accept from a
     * client: another tenant's grant id matches nothing, so a caller probing
     * ids can neither delete nor detect a row that is not its own.
     */
    public function revokeById(int $tenantId, int $id): bool
    {
        $statement = $this->db->prepare(
            'DELETE FROM resource_role_assignments WHERE id = ? AND tenant_id = ?'
        );
        $statement->execute([$id, $tenantId]);

        $removed = $statement->rowCount() > 0;
        if ($removed) {
            \Whity\Auth\RoleChecker::clearCache();
        }

        return $removed;
    }

    /**
     * The id of an existing grant, or null.
     *
     * Exists so an idempotent caller can answer "which row already says this?"
     * — {@see grant()} reports only THAT the grant existed, and a caller that
     * has to return the row's id would otherwise re-derive this query, taking
     * the nullable-profile_id match with it.
     */
    public function findGrantId(
        int $tenantId,
        string $resourceType,
        int $resourceId,
        int $roleId,
        ?int $profileId = null
    ): ?int {
        $row = $this->find($tenantId, $resourceType, $resourceId, $roleId, $profileId);

        return $row === null ? null : (int) $row['id'];
    }

    /**
     * Whether ONE PROFILE holds any grant addressed at one resource (#947 item 3).
     *
     * The cheap existence question, for a caller that only needs to know whether
     * a person has authority here — {@see \Whity\Core\Document\DocumentVisibilityPolicy}
     * asks it to decide whether somebody may read a document. Distinct from
     * {@see listFor()}, which returns every grant at the resource including the
     * everyone-grants; fetching that list to answer a yes/no is a wider read for
     * a narrower question.
     *
     * EVERYONE-GRANTS (`profile_id IS NULL`) ARE DELIBERATELY EXCLUDED. Migration
     * 088 defines that row as "everyone WITH ACCESS TO this resource gets role R
     * here" — it modifies what people who can already reach the record may do,
     * and is not itself a grant of access. Counting it here would inverted its
     * meaning: a document carrying one everyone-grant would become readable by
     * every profile in the tenant, which is precisely the widening the row is
     * not.
     */
    public function hasProfileGrantAt(
        int $tenantId,
        string $resourceType,
        int $resourceId,
        int $profileId,
    ): bool {
        $statement = $this->db->prepare(
            'SELECT 1 FROM resource_role_assignments
             WHERE tenant_id = ? AND resource_type = ? AND resource_id = ? AND profile_id = ?
             LIMIT 1'
        );
        $statement->execute([$tenantId, $resourceType, $resourceId, $profileId]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * Every grant at one resource, tenant scoped.
     *
     * @return list<array{id: int, role_id: int, profile_id: int|null}>
     */
    public function listFor(int $tenantId, string $resourceType, int $resourceId): array
    {
        $statement = $this->db->prepare(
            'SELECT id, role_id, profile_id FROM resource_role_assignments
             WHERE tenant_id = ? AND resource_type = ? AND resource_id = ?
             ORDER BY id'
        );
        $statement->execute([$tenantId, $resourceType, $resourceId]);

        $out = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $out[] = [
                'id' => (int) $row['id'],
                'role_id' => (int) $row['role_id'],
                'profile_id' => $row['profile_id'] === null ? null : (int) $row['profile_id'],
            ];
        }

        return $out;
    }

    /**
     * Remove every grant addressed at a resource — the cleanup an owner must run
     * when it deletes the record itself.
     *
     * `resource_id` carries no foreign key (the target table varies by type), so
     * nothing removes these rows automatically. Left behind, they would grant
     * authority at an id that a later record could reuse.
     */
    public function revokeAllFor(int $tenantId, string $resourceType, int $resourceId): int
    {
        $statement = $this->db->prepare(
            'DELETE FROM resource_role_assignments
             WHERE tenant_id = ? AND resource_type = ? AND resource_id = ?'
        );
        $statement->execute([$tenantId, $resourceType, $resourceId]);

        $count = $statement->rowCount();
        if ($count > 0) {
            \Whity\Auth\RoleChecker::clearCache();
        }

        return $count;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function find(
        int $tenantId,
        string $resourceType,
        int $resourceId,
        int $roleId,
        ?int $profileId
    ): ?array {
        $sql = 'SELECT id FROM resource_role_assignments
                WHERE tenant_id = ? AND resource_type = ? AND resource_id = ? AND role_id = ?
                  AND ' . ($profileId === null ? 'profile_id IS NULL' : 'profile_id = ?');

        $params = [$tenantId, $resourceType, $resourceId, $roleId];
        if ($profileId !== null) {
            $params[] = $profileId;
        }

        $statement = $this->db->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    private function assertResourceTypeRegistered(string $resourceType): void
    {
        if (!$this->resourceTypes->exists($resourceType)) {
            throw InvalidResourceTypeException::forResourceType($resourceType);
        }
    }

    /**
     * The tenant-isolation guard, matching OusApiHandler::assignRole().
     */
    private function assertRoleVisibleToTenant(int $roleId, int $tenantId): void
    {
        $statement = $this->db->prepare(
            'SELECT id FROM roles WHERE id = ? AND (tenant_id = ? OR tenant_id IS NULL)'
        );
        $statement->execute([$roleId, $tenantId]);

        if ($statement->fetch() === false) {
            $this->logger->warning(
                'RBAC: resource role grant denied — role not visible to tenant',
                ['tenant_id' => $tenantId, 'role_id' => $roleId]
            );

            throw RoleNotVisibleException::forRole($roleId);
        }
    }
}
