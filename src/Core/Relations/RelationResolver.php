<?php

declare(strict_types=1);

namespace Whity\Core\Relations;

use PDO;
use Whity\Api\Exception\CrossTenantReferenceException;
use Whity\Api\Exception\DuplicateRelationException;
use Whity\Api\Exception\PersonNotFoundException;
use Whity\Api\Exception\SelfRelationException;

/**
 * Resolves polymorphic relation references to person ids and enforces the
 * relation integrity rules (WC-65).
 *
 * The `relations` storage is uniformly `person → person`, but the API accepts
 * references that may name a USER or a PERSON. This resolver is the SINGLE place
 * that knows about user-vs-person:
 *
 *  - A `{kind:'person', id}` reference resolves to that person (tenant-scoped).
 *  - A `{kind:'user', id}` reference resolves to the user's shadow person,
 *    AUTO-PROVISIONING one (display name seeded from the user's email local
 *    part) the first time the user participates in a relation. `persons.user_id`
 *    is UNIQUE, so a user has at most one shadow.
 *
 * Cross-tenant safety: a reference to a person/user outside the acting tenant is
 * treated as not-found ({@see PersonNotFoundException}) — a person that exists in
 * another tenant raises {@see CrossTenantReferenceException}, which the handler
 * also surfaces as 404 so cross-tenant existence is never disclosed.
 *
 * The resolver does NOT open transactions itself; it relies on the shared worker
 * connection and is worker-safe (no request state in statics).
 */
class RelationResolver
{
    /** Reference kind: the id names a row in `persons`. */
    public const KIND_PERSON = 'person';

    /** Reference kind: the id names a platform user (resolve to its shadow person). */
    public const KIND_USER = 'user';

    private PDO $db;
    private PersonRepository $persons;
    private RelationRepository $relations;

    /**
     * @param PDO                $db        Database connection (user existence checks).
     * @param PersonRepository   $persons   Person data access.
     * @param RelationRepository $relations Relation data access.
     */
    public function __construct(PDO $db, PersonRepository $persons, RelationRepository $relations)
    {
        $this->db = $db;
        $this->persons = $persons;
        $this->relations = $relations;
    }

    /**
     * Resolve a `{kind,id}` reference to a person id within the acting tenant,
     * auto-provisioning a user's shadow person when needed.
     *
     * @param string $kind     {@see self::KIND_PERSON} or {@see self::KIND_USER}.
     * @param int    $id       The person id or user id, per the kind.
     * @param int    $tenantId The acting tenant.
     * @return int The resolved person id.
     *
     * @throws PersonNotFoundException       When the person/user is absent or not visible.
     * @throws CrossTenantReferenceException When it exists but in another tenant.
     */
    public function resolveRef(string $kind, int $id, int $tenantId): int
    {
        if ($kind === self::KIND_PERSON) {
            return $this->resolvePerson($id, $tenantId);
        }

        return $this->resolveUser($id, $tenantId);
    }

    /**
     * Resolve a person id, ensuring it is visible to the acting tenant.
     *
     * @throws PersonNotFoundException       When absent / not visible (system tenant: absent).
     * @throws CrossTenantReferenceException When it exists in another tenant.
     */
    private function resolvePerson(int $personId, int $tenantId): int
    {
        // System tenant may reference any person.
        if ($tenantId === 0) {
            $person = $this->persons->findById($personId, 0);
            if ($person === null) {
                throw PersonNotFoundException::forPerson($personId);
            }
            return (int) $person['id'];
        }

        // Look up unscoped to distinguish "does not exist" from "wrong tenant".
        $person = $this->persons->findById($personId, 0);
        if ($person === null) {
            throw PersonNotFoundException::forPerson($personId);
        }
        if ((int) $person['tenant_id'] !== $tenantId) {
            throw CrossTenantReferenceException::forPerson(
                $personId,
                (int) $person['tenant_id'],
                $tenantId
            );
        }

        return (int) $person['id'];
    }

    /**
     * Resolve a user id to its shadow person within the acting tenant,
     * auto-provisioning the shadow when the user has none yet.
     *
     * Provisioning is a WRITE, so this is only used on write paths (e.g. creating a
     * relation). Read paths must use {@see resolveExistingUserPerson()} instead so a
     * read permission never triggers an insert.
     *
     * @throws PersonNotFoundException       When the user is absent / not visible.
     * @throws CrossTenantReferenceException When the user exists in another tenant.
     */
    private function resolveUser(int $userId, int $tenantId): int
    {
        $user = $this->requireUserVisible($userId, $tenantId);

        // The shadow person lives in the user's own tenant (matters for the
        // system tenant, which acts across tenants but must store the shadow with
        // the user's real tenant_id so isolation stays correct).
        $shadowTenant = (int) $user['tenant_id'];

        $existing = $this->persons->findByUserId($userId, $shadowTenant);
        if ($existing !== null) {
            return (int) $existing['id'];
        }

        // Auto-provision: create the user's invisible shadow person on demand.
        return $this->persons->insert($shadowTenant, $this->displayNameForUser($user), $userId);
    }

    /**
     * Resolve a user's EXISTING shadow person WITHOUT provisioning one.
     *
     * Read-only counterpart to {@see resolveUser()}: returns null when the user has
     * no shadow yet, so a `relations:read` path can report "no relations" without
     * performing a write.
     *
     * @return int|null The shadow person id, or null when the user has none.
     * @throws PersonNotFoundException       When the user is absent / not visible.
     * @throws CrossTenantReferenceException When the user exists in another tenant.
     */
    public function resolveExistingUserPerson(int $userId, int $tenantId): ?int
    {
        $user = $this->requireUserVisible($userId, $tenantId);
        $existing = $this->persons->findByUserId($userId, (int) $user['tenant_id']);

        return $existing === null ? null : (int) $existing['id'];
    }

    /**
     * Fetch a user row and assert it is visible to the acting tenant.
     *
     * @return array{id: int, tenant_id: int, email: string}
     * @throws PersonNotFoundException       When the user is absent.
     * @throws CrossTenantReferenceException When the user exists in another tenant.
     */
    private function requireUserVisible(int $userId, int $tenantId): array
    {
        $user = $this->findUser($userId);
        if ($user === null) {
            throw PersonNotFoundException::forUser($userId);
        }

        $userTenant = (int) $user['tenant_id'];
        if ($tenantId !== 0 && $userTenant !== $tenantId) {
            throw CrossTenantReferenceException::forPerson($userId, $userTenant, $tenantId);
        }

        return $user;
    }

    /**
     * Resolve and validate a relation request end-to-end: resolve both refs,
     * enforce the integrity rules, and return the concrete person ids + the
     * (validated) relationship type id ready for insertion.
     *
     * @param array{kind: string, id: int} $from               The from reference.
     * @param array{kind: string, id: int} $to                 The to reference.
     * @param int                          $relationshipTypeId The relationship type id.
     * @param int                          $tenantId           The acting tenant.
     * @return array{fromPersonId: int, toPersonId: int, relationshipTypeId: int}
     *
     * @throws PersonNotFoundException       When a ref / the type is absent or not visible.
     * @throws CrossTenantReferenceException When a ref exists in another tenant.
     * @throws SelfRelationException         When both refs resolve to the same person.
     * @throws DuplicateRelationException    When an identical edge already exists.
     */
    public function resolveRelation(array $from, array $to, int $relationshipTypeId, int $tenantId): array
    {
        // The relationship type must exist (404 surfaced by the handler otherwise).
        if ($this->relations->findType($relationshipTypeId) === null) {
            throw PersonNotFoundException::forPerson(0);
        }

        $fromPersonId = $this->resolveRef($from['kind'], $from['id'], $tenantId);
        $toPersonId = $this->resolveRef($to['kind'], $to['id'], $tenantId);

        if ($fromPersonId === $toPersonId) {
            throw SelfRelationException::forPerson($fromPersonId);
        }

        // Both persons MUST live in the same tenant; the edge belongs to that
        // tenant. A normal caller already had both refs pinned to $tenantId, so this
        // is a no-op for them. For the system tenant (which may reference any
        // tenant's persons) it prevents forging a cross-tenant edge that would
        // otherwise surface a foreign tenant's person in that tenant's reads.
        $fromTenant = $this->tenantOfPerson($fromPersonId);
        $toTenant = $this->tenantOfPerson($toPersonId);
        if ($fromTenant !== $toTenant) {
            throw CrossTenantReferenceException::forPerson($toPersonId, $toTenant, $fromTenant);
        }

        $storeTenant = $tenantId === 0 ? $fromTenant : $tenantId;

        if ($this->relations->exists($storeTenant, $fromPersonId, $toPersonId, $relationshipTypeId)) {
            throw DuplicateRelationException::forEdge($fromPersonId, $toPersonId, $relationshipTypeId);
        }

        return [
            'fromPersonId' => $fromPersonId,
            'toPersonId' => $toPersonId,
            'relationshipTypeId' => $relationshipTypeId,
        ];
    }

    /**
     * The tenant that owns a resolved person (used by the system tenant to store
     * the edge with the persons' real tenant_id).
     */
    private function tenantOfPerson(int $personId): int
    {
        $person = $this->persons->findById($personId, 0);

        return $person === null ? 0 : (int) $person['tenant_id'];
    }

    /**
     * Fetch a user row (id, tenant_id, email) by id, or null when absent.
     *
     * @return array{id: int, tenant_id: int, email: string}|null
     */
    private function findUser(int $userId): ?array
    {
        $stmt = $this->db->prepare('SELECT id, tenant_id, email FROM users WHERE id = :id');
        $stmt->execute([':id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'tenant_id' => (int) $row['tenant_id'],
            'email' => (string) ($row['email'] ?? ''),
        ];
    }

    /**
     * Derive a shadow person's display name from a user row.
     *
     * Mirrors the OU/users handlers: the `users` table has no `name` column, so
     * the name is the email local-part (falling back to the full email).
     *
     * @param array{id: int, tenant_id: int, email: string} $user
     */
    private function displayNameForUser(array $user): string
    {
        $email = $user['email'];
        $localPart = strstr($email, '@', true);

        if ($localPart !== false && $localPart !== '') {
            return $localPart;
        }

        return $email !== '' ? $email : ('User #' . $user['id']);
    }
}
