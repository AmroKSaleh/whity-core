<?php

declare(strict_types=1);

namespace Whity\Core\Document\Demo;

use PDO;
use RuntimeException;
use Whity\Core\Identity\ProfileProvisioner;
use Whity\Core\RBAC\CorePermissions;
use Whity\Database\InitialPassword;

/**
 * The ORGANISATION the document demo needs in order to demonstrate anything: a
 * faculty with two departments, and eight people — seven placed in them and one
 * placed nowhere.
 *
 * WHY AN ORGANISATION IS PART OF A *DOCUMENT* DEMO
 * -----------------------------------------------
 * Because five of the nine folders {@see \Whity\Core\Document\Organizer\CoreDocumentViews}
 * ships, and the whole of migration 117's placement rule, are answers about
 * UNITS. On a flat tenant "raised by my unit", "everything below my unit" and
 * "passed through my unit" return the same rows, and a faculty secretary and a
 * department secretary see the same templates — so every screen renders, every
 * query succeeds, and not one of the distinctions the last week of work exists
 * to draw is visible. A faculty with two departments, each with staff, is the
 * SMALLEST shape in which those three folders differ from each other and in
 * which two holders of one role reach different sets.
 *
 * Two departments rather than one is likewise not decoration: with a single
 * department, "below my unit" and "my unit's subtree minus my unit" coincide,
 * and a fan-out cannot span more than one unit — so
 * {@see \Whity\Core\Document\Routing\DocumentRouter}'s rule that a distribution
 * touching several units records NO single destination (`to_ou_id` null) never
 * fires, and the reader would take the single-unit case for the general one.
 *
 * IDENTITIES COME FROM THE REAL SEAM
 * ----------------------------------
 * People are created by {@see ProfileProvisioner::findOrCreate()}, not by an
 * INSERT here, for the same reason the routing states go through
 * {@see \Whity\Core\Document\Routing\DocumentRouter}: a hand-rolled
 * `profiles` row can sit in a state the provisioning path would never produce —
 * an address that is not primary or not verified, a non-zero `token_epoch`, an
 * `auth_method` claiming a local credential the row does not carry — and then
 * the demo teaches an identity behaviour the product does not have. It also
 * means a demo address that somehow already belongs to a real person REUSES
 * that identity instead of minting a second one, which is what that seam is for.
 *
 * The visible consequence is the display name: the provisioner derives it from
 * the address's local part, so the demo people show as `dean`,
 * `faculty-secretary`, `civil-head` and so on rather than as prose names. That
 * is a fair trade and arguably the better label — it is the same string as the
 * login, so a reader comparing two secretaries' screens can tell at a glance
 * which one they are looking at.
 *
 * WHAT IS STILL WRITTEN IN SQL, AND WHY THAT IS DIFFERENT
 * ------------------------------------------------------
 * Units, roles, permission grants and memberships. Those genuinely have no
 * service to drive: OU creation lives inline in
 * {@see \Whity\Api\OusApiHandler::create()}, which is an HTTP handler — it
 * parses a request, returns a {@see \Whity\Http\Response} and dispatches
 * `ou.creating`/`ou.created` — and there is no `OuRepository` behind it.
 * {@see \Whity\Database\ScaleSeeder\ScaleSeeder} reached the same wall and
 * writes these same tables directly; so does {@see \Whity\Database\Seeder} for
 * memberships.
 *
 * That is acceptable for these tables and would not be for a recipient row, and
 * the difference is worth stating because it is the whole reason the split
 * exists. A unit row is an INPUT to the routing resolvers, not an output of
 * them: there is no state a hand-written `organizational_units` row can express
 * that the engine would refuse to produce, because the engine does not produce
 * them at all. A hand-written `document_route_recipients` row is the opposite —
 * it can name a step whose rule resolves to somebody else entirely, and then the
 * demo teaches a routing behaviour the engine does not have.
 *
 * IDEMPOTENT, PER ROW
 * -------------------
 * Every unit is looked up by `(tenant_id, slug)` and every role by
 * `(tenant_id, name)` before it is written, every insert additionally carries
 * `ON CONFLICT DO NOTHING` as the backstop, and every person is found-or-created
 * by address. A second run resolves the same ids and writes nothing. Unit and
 * role ids are re-SELECTed after the insert rather than taken from `RETURNING`
 * or `lastInsertId()`, which is one query more and removes the driver branch the
 * two spellings would otherwise need — this is an offline seeder, not a hot
 * path.
 *
 * DEMO-ONLY BY CONSTRUCTION
 * -------------------------
 * Every name is `demo-` prefixed and every address is under `demo.example.com`
 * (RFC 2606 reserved, so it can never reach a real mailbox). Both are load
 * bearing rather than cosmetic: the prefix is what stops a re-seed ADOPTING a
 * role an operator happens to have called `secretary` in their own dev tenant —
 * which would silently give the demo whatever permissions that role holds — and
 * it is what makes the fixture identifiable if somebody wants it gone.
 */
final class DemoOrganisationSeeder
{
    // ── unit keys ────────────────────────────────────────────────────────────
    public const OU_FACULTY = 'faculty';
    public const OU_DEPT_CIVIL = 'dept-civil';
    public const OU_DEPT_MECHANICAL = 'dept-mechanical';

    // ── role keys (also the stored role names) ───────────────────────────────
    public const ROLE_DEAN = 'demo-dean';
    public const ROLE_HEAD = 'demo-department-head';
    public const ROLE_TECHNICIAN = 'demo-technician';

    /**
     * Held by ONE person, who belongs to NO unit.
     *
     * A role of its own rather than a third holder of an existing one, because
     * every existing role is load bearing in a routing rule: a third
     * `demo-secretary` would change what step 2 of the budget route fans out to,
     * and a third `demo-technician` would change the calibration fan-out — so
     * the unaffiliated person would arrive by quietly editing two other
     * demonstrations.
     *
     * Somebody in no unit is not an edge case worth skipping. It is the ordinary
     * shape of a tenant administrator ({@see \Whity\Core\Document\DocumentAccessPolicy}
     * says so in as many words), it is the case the organizer answers with
     * `unanchored` rather than an empty page, and a document they raise has NO
     * origin unit — which is the only thing that separates "everything below my
     * unit" from "passed through my unit" at the top of the tree.
     */
    public const ROLE_REGISTRY_OFFICER = 'demo-registry-officer';

    /**
     * ONE role, held by two people standing in different units.
     *
     * This is the sharpest available statement of what migration 117 fixed. Two
     * DIFFERENT roles carrying the same permissions would demonstrate the same
     * thing and leave the reader wondering whether some difference between the
     * roles was doing the work. With one role there is nothing left that could
     * be: the two secretaries are identical in every respect the permission
     * system can see, and they see different template sets.
     */
    public const ROLE_SECRETARY = 'demo-secretary';

    // ── people (the key IS the address, so a report needs no second table) ───
    public const DEAN = 'dean@demo.example.com';
    public const FACULTY_SECRETARY = 'faculty-secretary@demo.example.com';
    public const CIVIL_HEAD = 'civil-head@demo.example.com';
    public const CIVIL_SECRETARY = 'civil-secretary@demo.example.com';
    public const CIVIL_TECHNICIAN = 'civil-technician@demo.example.com';
    public const MECHANICAL_HEAD = 'mechanical-head@demo.example.com';
    public const MECHANICAL_TECHNICIAN = 'mechanical-technician@demo.example.com';
    /** In no unit at all — see {@see ROLE_REGISTRY_OFFICER}. */
    public const REGISTRY_OFFICER = 'registry-officer@demo.example.com';

    /**
     * Supplies the ONE password every demo person shares.
     *
     * One variable and one bcrypt hash per run, following
     * {@see \Whity\Database\ScaleSeeder\ScaleSeederConfig::PASSWORD_ENV_VAR}:
     * eight separate variables would be eight things to set to preview one
     * feature, and eight bcrypt hashes to compute for a fixture. Unset means
     * {@see InitialPassword} generates one and announces it ONCE — which is the
     * behaviour that makes these accounts usable at all, and the reason to log
     * in as them is the point of the whole exercise: "the two secretaries see
     * different template sets" is a claim you check by being each of them.
     */
    public const PASSWORD_ENV_VAR = 'DEMO_SEED_PASSWORD';

    public function __construct(
        private readonly PDO $db,
        private readonly ProfileProvisioner $profiles,
    ) {
    }

    /**
     * Seed (or resolve) the demo organisation in one tenant.
     *
     * ONE TRANSACTION, because {@see ProfileProvisioner} requires it: it writes
     * the profile and its primary email as two statements, and says in as many
     * words that a profile without its email is a broken identity no later
     * request can repair. The caller owns the boundary, so this is where it
     * belongs. Entered only if one is not already open, the same courtesy every
     * other service in this codebase extends to a caller running a larger unit
     * of work.
     *
     * @throws RuntimeException When a permission the fixture needs is not in the
     *         database. Loud rather than skipped: a `demo-secretary` without
     *         `documents:write` is a secretary who sees no templates at all, and
     *         the demo would then "prove" that placement hides everything.
     */
    public function seed(int $tenantId): DemoOrganisation
    {
        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) {
            $this->db->beginTransaction();
        }

        try {
            $ouIds = $this->seedUnits($tenantId);
            $roleIds = $this->seedRoles($tenantId);
            $profileIds = $this->seedPeople($tenantId, $ouIds, $roleIds);

            if ($ownTransaction) {
                $this->db->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        return new DemoOrganisation($tenantId, $ouIds, $roleIds, $profileIds);
    }

    /**
     * The unit tree. Parents are inserted before children, so the `parent_id`
     * of each row is an id this method already holds.
     *
     * @return array<string, int>
     */
    private function seedUnits(int $tenantId): array
    {
        $faculty = $this->upsertUnit(
            $tenantId,
            null,
            'Demo Faculty of Engineering',
            'demo-faculty-engineering',
            'Demo fixture: the faculty the document demo is raised from and routed within.',
        );

        return [
            self::OU_FACULTY => $faculty,
            self::OU_DEPT_CIVIL => $this->upsertUnit(
                $tenantId,
                $faculty,
                'Demo Department of Civil Engineering',
                'demo-dept-civil',
                'Demo fixture: a department beneath the faculty.',
            ),
            self::OU_DEPT_MECHANICAL => $this->upsertUnit(
                $tenantId,
                $faculty,
                'Demo Department of Mechanical Engineering',
                'demo-dept-mechanical',
                'Demo fixture: a second department, so a fan-out can span two units.',
            ),
        ];
    }

    /**
     * The four demo roles and their permission grants.
     *
     * The grants are what make the fixture browsable: a demo person who cannot
     * read a document list has nothing to show. `documents:read:all` is granted
     * to NOBODY here on purpose — it is the override that makes every document
     * visible regardless of routing, and handing it to the dean would hide the
     * fact that the other six people see only what reached them, which is
     * {@see \Whity\Core\Document\DocumentVisibilityPolicy}'s whole point.
     *
     * @return array<string, int>
     */
    private function seedRoles(int $tenantId): array
    {
        /** @var array<string, array{description: string, permissions: list<string>}> $declared */
        $declared = [
            self::ROLE_DEAN => [
                'description' => 'Demo fixture: raises circulars at the faculty and routes them downward.',
                'permissions' => [
                    CorePermissions::DOCUMENTS_READ,
                    CorePermissions::DOCUMENTS_WRITE,
                    CorePermissions::DOCUMENTS_PUBLISH,
                    CorePermissions::DOCUMENTS_RENDER,
                    CorePermissions::DOCUMENTS_ROUTE,
                ],
            ],
            self::ROLE_HEAD => [
                'description' => 'Demo fixture: receives the faculty circular and forwards it into a department.',
                'permissions' => [
                    CorePermissions::DOCUMENTS_READ,
                    CorePermissions::DOCUMENTS_WRITE,
                    CorePermissions::DOCUMENTS_RENDER,
                    CorePermissions::DOCUMENTS_ROUTE,
                ],
            ],
            // Deliberately identical to nothing else: the two secretaries differ
            // by PLACE and by nothing this list can express.
            self::ROLE_SECRETARY => [
                'description' => 'Demo fixture: two holders, in two units, seeing two template sets.',
                'permissions' => [
                    CorePermissions::DOCUMENTS_READ,
                    CorePermissions::DOCUMENTS_WRITE,
                ],
            ],
            self::ROLE_TECHNICIAN => [
                'description' => 'Demo fixture: the last step of the circular.',
                'permissions' => [
                    CorePermissions::DOCUMENTS_READ,
                    CorePermissions::DOCUMENTS_RENDER,
                ],
            ],
            self::ROLE_REGISTRY_OFFICER => [
                'description' => 'Demo fixture: raises documents from no unit at all.',
                'permissions' => [
                    CorePermissions::DOCUMENTS_READ,
                    CorePermissions::DOCUMENTS_WRITE,
                    CorePermissions::DOCUMENTS_RENDER,
                    CorePermissions::DOCUMENTS_ROUTE,
                ],
            ],
        ];

        $roleIds = [];
        foreach ($declared as $name => $spec) {
            $roleId = $this->upsertRole($tenantId, $name, $spec['description']);
            foreach ($spec['permissions'] as $permission) {
                $this->grant($roleId, $permission);
            }
            $roleIds[$name] = $roleId;
        }

        return $roleIds;
    }

    /**
     * The eight people, each with ONE active primary membership naming their role
     * and — for all but the registry officer — their unit.
     *
     * A single membership each, deliberately. `memberships.ou_id` is what
     * {@see \Whity\Core\Ou\PrimaryMembershipOu} reads to stamp a document's
     * origin unit and a trail event's `from_ou_id`, and what
     * {@see \Whity\Core\Ou\OuReachResolver} reads for standing — so a person
     * with two memberships has an origin unit decided by an ORDER BY, which is a
     * fine thing for the engine to be tested on and a terrible thing for a demo
     * to rest on.
     *
     * @param array<string, int> $ouIds
     * @param array<string, int> $roleIds
     * @return array<string, int>
     */
    private function seedPeople(int $tenantId, array $ouIds, array $roleIds): array
    {
        /** @var array<string, array{role: string, ou: ?string}> $people */
        $people = [
            self::DEAN => [
                'role' => self::ROLE_DEAN,
                'ou' => self::OU_FACULTY,
            ],
            self::FACULTY_SECRETARY => [
                'role' => self::ROLE_SECRETARY,
                'ou' => self::OU_FACULTY,
            ],
            self::CIVIL_HEAD => [
                'role' => self::ROLE_HEAD,
                'ou' => self::OU_DEPT_CIVIL,
            ],
            self::CIVIL_SECRETARY => [
                'role' => self::ROLE_SECRETARY,
                'ou' => self::OU_DEPT_CIVIL,
            ],
            self::CIVIL_TECHNICIAN => [
                'role' => self::ROLE_TECHNICIAN,
                'ou' => self::OU_DEPT_CIVIL,
            ],
            self::MECHANICAL_HEAD => [
                'role' => self::ROLE_HEAD,
                'ou' => self::OU_DEPT_MECHANICAL,
            ],
            self::MECHANICAL_TECHNICIAN => [
                'role' => self::ROLE_TECHNICIAN,
                'ou' => self::OU_DEPT_MECHANICAL,
            ],
            self::REGISTRY_OFFICER => [
                'role' => self::ROLE_REGISTRY_OFFICER,
                // NULL, not a unit. The membership is active and complete; it
                // simply names no place, which is what `memberships.ou_id` being
                // nullable means.
                'ou' => null,
            ],
        ];

        // Hashed ONCE for the whole set. bcrypt is the only expensive thing this
        // seeder does, and hashing per person would also mean announcing a
        // generated password eight times for one fixture. Resolved eagerly
        // rather than on first miss because the provisioner takes the hash as an
        // argument and ignores it for a profile that already exists, so there is
        // no cheaper moment to decide.
        $passwordHash = password_hash(
            InitialPassword::resolvePlaintext(
                self::PASSWORD_ENV_VAR,
                'the document-demo accounts (all under demo.example.com)'
            ),
            PASSWORD_BCRYPT
        );

        $profileIds = [];
        foreach ($people as $email => $person) {
            // The real identity seam. ProfileProvisioner::findOrCreate() is
            // find-or-create against the globally UNIQUE profile_emails.email,
            // and it exists so that callers stop writing their own INSERT — the
            // same argument that puts the routing states through DocumentRouter.
            // A hand-rolled profile row can be in a state the provisioning path
            // would never produce (an unverified or non-primary address, a
            // token_epoch that is not zero, an auth_method claiming a local
            // credential the row does not carry), and then the demo teaches an
            // identity behaviour the product does not have.
            //
            // It also means a demo address that somehow already belongs to a
            // real person REUSES that identity rather than minting a second one,
            // which is the whole reason the seam exists: two profiles for one
            // address split that person's credentials and token epoch, so a
            // password change or a forced logout reaches only one of them.
            //
            // The password argument is used ONLY on creation, so a re-run never
            // rewrites an existing credential — the same posture Seeder takes.
            $profileId = $this->profiles->findOrCreate(strtolower(trim($email)), $passwordHash);

            $this->upsertMembership(
                $tenantId,
                $profileId,
                $roleIds[$person['role']],
                $person['ou'] === null ? null : $ouIds[$person['ou']],
            );

            $profileIds[$email] = $profileId;
        }

        return $profileIds;
    }

    // ── the writes ───────────────────────────────────────────────────────────

    private function upsertUnit(
        int $tenantId,
        ?int $parentId,
        string $name,
        string $slug,
        string $description,
    ): int {
        $found = $this->findUnitBySlug($tenantId, $slug);
        if ($found !== null) {
            return $found;
        }

        $stmt = $this->db->prepare(
            'INSERT INTO organizational_units (tenant_id, parent_id, name, slug, description, created_at)
             VALUES (:tenant_id, :parent_id, :name, :slug, :description, NOW())
             ON CONFLICT DO NOTHING'
        );
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':parent_id' => $parentId,
            ':name' => $name,
            ':slug' => $slug,
            ':description' => $description,
        ]);

        $id = $this->findUnitBySlug($tenantId, $slug);
        if ($id === null) {
            throw new RuntimeException("Demo unit '{$slug}' could not be created or read back.");
        }

        return $id;
    }

    private function findUnitBySlug(int $tenantId, string $slug): ?int
    {
        $stmt = $this->db->prepare(
            'SELECT id FROM organizational_units WHERE tenant_id = :tenant_id AND slug = :slug'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':slug' => $slug]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    private function upsertRole(int $tenantId, string $name, string $description): int
    {
        $found = $this->findRoleByName($tenantId, $name);
        if ($found !== null) {
            return $found;
        }

        // A bare `ON CONFLICT DO NOTHING` with no target: migration 093 replaced
        // the global UNIQUE(name) with two PARTIAL unique indexes, so a named
        // target has to carry the matching predicate (see ScaleSeeder). The
        // untargeted form needs no predicate, and both engines accept it.
        $stmt = $this->db->prepare(
            'INSERT INTO roles (name, description, tenant_id, created_at)
             VALUES (:name, :description, :tenant_id, NOW())
             ON CONFLICT DO NOTHING'
        );
        $stmt->execute([':name' => $name, ':description' => $description, ':tenant_id' => $tenantId]);

        $id = $this->findRoleByName($tenantId, $name);
        if ($id === null) {
            throw new RuntimeException("Demo role '{$name}' could not be created or read back.");
        }

        return $id;
    }

    private function findRoleByName(int $tenantId, string $name): ?int
    {
        $stmt = $this->db->prepare(
            'SELECT id FROM roles WHERE tenant_id = :tenant_id AND name = :name'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':name' => $name]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    /**
     * Grant one existing permission to a role.
     *
     * The permission is NOT created when absent. Every name granted here is a
     * {@see CorePermissions} constant that a migration inserts, so a missing one
     * means the database is not migrated — and inventing the row would produce a
     * permission no route gates on, which the CI permission-holder guard exists
     * to catch and which would make the demo's authorization silently vacuous.
     */
    private function grant(int $roleId, string $permission): void
    {
        // `permissions` is a platform-global catalogue with no tenant_id column,
        // so this lookup carries no tenant predicate and needs none.
        $lookup = $this->db->prepare('SELECT id FROM permissions WHERE name = :name');
        $lookup->execute([':name' => $permission]);
        $permissionId = $lookup->fetchColumn();

        if ($permissionId === false) {
            throw new RuntimeException(
                "The demo fixture needs the '{$permission}' permission, which is not in this database. "
                . 'Run the migrations before seeding.'
            );
        }

        $stmt = $this->db->prepare(
            'INSERT INTO role_permissions (role_id, permission_id, created_at)
             VALUES (:role_id, :permission_id, NOW())
             ON CONFLICT DO NOTHING'
        );
        $stmt->execute([':role_id' => $roleId, ':permission_id' => (int) $permissionId]);
    }

    private function upsertMembership(int $tenantId, int $profileId, int $roleId, ?int $ouId): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO memberships (profile_id, tenant_id, role_id, ou_id, status, created_at)
             VALUES (:profile_id, :tenant_id, :role_id, :ou_id, 'active', NOW())
             ON CONFLICT (profile_id, tenant_id) WHERE is_primary DO NOTHING"
        );
        $stmt->execute([
            ':profile_id' => $profileId,
            ':tenant_id' => $tenantId,
            ':role_id' => $roleId,
            ':ou_id' => $ouId,
        ]);
    }
}
