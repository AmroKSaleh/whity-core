<?php

declare(strict_types=1);

namespace Whity\Database\ScaleSeeder;

use PDO;
use PDOStatement;
use RuntimeException;
use Throwable;
use Whity\Database\Database;
use Whity\Database\InitialPassword;

/**
 * Bulk-inserts a realistic, deterministic, multi-tenant dataset for load
 * testing, OU/graph-hierarchy rendering testing, and pagination testing
 * (WC-35, part of the "Performance baseline & load/stress gating" epic #35).
 *
 * Per tenant this seeds: the tenant itself, an OU hierarchy tree (configurable
 * depth/breadth), a handful of tenant-scoped custom roles (with a random
 * permission grant subset), N users (profile + profile_email + membership,
 * following the identity/membership model of ADR 0005), a 1:1 shadow `persons`
 * row per user (so the family-relations graph has real nodes), and a target
 * density of `relations` edges among those persons.
 *
 * Determinism: every random choice is drawn from an INDEPENDENTLY-{@see
 * DeterministicRandom::derive()}d generator, keyed by that specific entity's
 * own identity (its tenant/user/role index, or OU path) — never from one
 * stream shared across many entities. This is deliberate, not merely
 * stylistic: a shared stream desyncs the moment a "reuse" branch skips a draw
 * that the matching "create" branch would have made (e.g. a user whose
 * profile already exists never draws a display name), so on a rerun every
 * entity AFTER the first reused one would silently draw different values —
 * corrupting both determinism and idempotency. Deriving each entity's
 * generator from (seed, phase, indices) instead makes its value depend ONLY
 * on its own identity, never on how many other entities were created vs.
 * reused earlier in the same run.
 *
 * Idempotency: every insert is guarded (a natural-key existence pre-check, or
 * `ON CONFLICT ... DO NOTHING` + a fallback lookup on conflict), so re-running
 * {@see run()} with the SAME config against a database that already holds
 * this exact scale-seeded dataset creates nothing new and reports the
 * pre-existing rows as "reused" rather than duplicating them. Uniqueness
 * across independently-chosen seeds is guaranteed by embedding the seed and
 * tenant/user index directly into every globally-unique value (tenant
 * name/slug, custom role name, user email) — see {@see NameGenerator}.
 *
 * Tenant isolation: every statement that reads or writes a TENANT-OWNED table
 * (`organizational_units`, `roles`, `persons`, `relations`; `memberships` is
 * write-only here) binds an explicit `tenant_id` predicate — the two
 * exceptions are the one-time GLOBAL (`tenant_id IS NULL`) admin/user role
 * lookup, which itself carries a `tenant_id IS NULL` predicate rather than an
 * ignore-annotation, and `profiles`/`profile_emails`, which are sanctioned
 * global tables with no `tenant_id` column at all (ADR 0005).
 *
 * Performance note: this is an offline batch tool, not a request-time hot
 * path, so it favours the codebase's established insert idiom (see
 * {@see \Whity\Database\Seeder::seed()}: a natural-key existence check, then a
 * guarded insert) over raw throughput. The one real hot-spot — bcrypt password
 * hashing — is avoided by hashing the shared scale-seeded-user password ONCE
 * per run (see {@see ScaleSeederConfig::PASSWORD_ENV_VAR}) rather than once
 * per user. Each tenant's writes run inside one transaction, additionally
 * committed every `--batch-size` users so a very large `--users-per-tenant`
 * run never holds one unbounded transaction open.
 */
final class ScaleSeeder
{
    /** Relationship types this seeder distributes across the generated family graph. */
    private const RELATIONSHIP_TYPE_NAMES = ['Parent', 'Child', 'Spouse', 'Sibling'];

    public function __construct(private readonly Database $db)
    {
    }

    /** Pure arithmetic preview of what {@see run()} would insert. No DB access. */
    public function plan(ScaleSeederConfig $config): ScaleSeederPlan
    {
        return ScaleSeederPlan::fromConfig($config);
    }

    /**
     * Delete every tenant this seed's parameters would have created — along
     * with its OUs/roles/memberships/persons/relations — plus every profile
     * (+ profile_email) it created, matched by their deterministic
     * slug/email patterns.
     *
     * Every table is deleted EXPLICITLY rather than relying on the schema's
     * `ON DELETE CASCADE`/`SET NULL` foreign keys: those fire correctly on
     * PostgreSQL (which always enforces FKs), but SQLite enforces foreign
     * keys only when `PRAGMA foreign_keys = ON` is set on the connection —
     * which is NOT guaranteed here (a migration earlier in the chain may
     * leave it off) — so relying on cascade would silently leave orphaned
     * rows behind on that engine. Explicit deletes are correct on both.
     *
     * @return array{tenantsDeleted: int, profilesDeleted: int}
     */
    public function reset(ScaleSeederConfig $config): array
    {
        $pdo = $this->db->getPdo();

        $tenantSlugPattern = sprintf('scale-%d-t%%', $config->seed);
        $emailPattern = sprintf('scale-seed%d-t%%', $config->seed);

        $pdo->beginTransaction();
        try {
            // Resolve the target tenant ids up front so every subsequent
            // delete can bind an explicit tenant_id predicate (required by
            // the tenant-isolation guard) instead of re-joining tenants.slug
            // each time.
            $tenantIdStmt = $pdo->prepare('SELECT id FROM tenants WHERE slug LIKE :pattern');
            $tenantIdStmt->execute([':pattern' => $tenantSlugPattern]);
            $tenantIds = array_map(
                static fn(array $row): int => (int) $row['id'],
                $tenantIdStmt->fetchAll(PDO::FETCH_ASSOC)
            );

            if ($tenantIds !== []) {
                $placeholders = implode(',', array_fill(0, count($tenantIds), '?'));

                // role_permissions carries no tenant_id of its own (scoped
                // transitively via role_id — see TenantOwnedTables); capture
                // this seed's custom role ids BEFORE deleting the roles so
                // their grants can be cleaned up explicitly too.
                $roleIdStmt = $pdo->prepare("SELECT id FROM roles WHERE tenant_id IN ({$placeholders})");
                $roleIdStmt->execute($tenantIds);
                $roleIds = array_map(
                    static fn(array $row): int => (int) $row['id'],
                    $roleIdStmt->fetchAll(PDO::FETCH_ASSOC)
                );

                // Child-to-parent order so no statement depends on a cascade
                // that may not fire. Each DELETE names its table as a literal
                // (not a variable) and binds an explicit tenant_id predicate,
                // so it is both correct regardless of FK-enforcement state and
                // verifiable by the tenant-predicate guard.
                $pdo->prepare("DELETE FROM relations WHERE tenant_id IN ({$placeholders})")->execute($tenantIds);
                $pdo->prepare("DELETE FROM persons WHERE tenant_id IN ({$placeholders})")->execute($tenantIds);
                $pdo->prepare("DELETE FROM memberships WHERE tenant_id IN ({$placeholders})")->execute($tenantIds);
                $pdo->prepare("DELETE FROM organizational_units WHERE tenant_id IN ({$placeholders})")
                    ->execute($tenantIds);

                if ($roleIds !== []) {
                    $rolePlaceholders = implode(',', array_fill(0, count($roleIds), '?'));
                    $pdo->prepare("DELETE FROM role_permissions WHERE role_id IN ({$rolePlaceholders})")
                        ->execute($roleIds);
                }
                $pdo->prepare("DELETE FROM roles WHERE tenant_id IN ({$placeholders})")->execute($tenantIds);
            }

            // Profiles carry no tenant_id at all (ADR 0005 — global identity):
            // resolve which ones this seed created from its deterministic
            // email pattern, then delete both profile_emails and profiles
            // explicitly (not via cascade), matched by those ids.
            $profilesDeleted = $this->deleteScaleSeededProfiles($pdo, $emailPattern);

            // Now that every tenant-scoped and profile-scoped row is gone,
            // remove the tenants themselves.
            $tenantStmt = $pdo->prepare('DELETE FROM tenants WHERE slug LIKE :pattern');
            $tenantStmt->execute([':pattern' => $tenantSlugPattern]);
            $tenantsDeleted = $tenantStmt->rowCount();

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return ['tenantsDeleted' => $tenantsDeleted, 'profilesDeleted' => $profilesDeleted];
    }

    /**
     * Delete the profiles (and their profile_emails) matching this seed's
     * deterministic email pattern. Explicit two-step delete — id capture then
     * delete-by-id for both tables — so it is correct regardless of whether
     * the engine enforces `profile_emails.profile_id REFERENCES profiles(id)
     * ON DELETE CASCADE` (SQLite does not unless `PRAGMA foreign_keys = ON`).
     */
    private function deleteScaleSeededProfiles(PDO $pdo, string $emailPattern): int
    {
        $idStmt = $pdo->prepare('SELECT DISTINCT profile_id FROM profile_emails WHERE email LIKE :pattern');
        $idStmt->execute([':pattern' => $emailPattern]);
        $profileIds = array_map(
            static fn(array $row): int => (int) $row['profile_id'],
            $idStmt->fetchAll(PDO::FETCH_ASSOC)
        );

        if ($profileIds === []) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($profileIds), '?'));

        $pdo->prepare("DELETE FROM profile_emails WHERE profile_id IN ({$placeholders})")->execute($profileIds);

        $deleteStmt = $pdo->prepare("DELETE FROM profiles WHERE id IN ({$placeholders})");
        $deleteStmt->execute($profileIds);

        return $deleteStmt->rowCount();
    }

    /**
     * Run the generator against the live connection.
     *
     * @param (callable(int $tenantIndex, int $totalTenants, ScaleSeederResult $soFar): void)|null $onTenantDone
     *        Invoked after each tenant finishes (progress reporting).
     */
    public function run(ScaleSeederConfig $config, ?callable $onTenantDone = null): ScaleSeederResult
    {
        $pdo = $this->db->getPdo();
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $seed = $config->seed;

        $result = new ScaleSeederResult();

        $adminRoleId = $this->resolveGlobalRoleId($pdo, 'admin');
        $userRoleId = $this->resolveGlobalRoleId($pdo, 'user');
        $relationshipTypeIds = $this->resolveRelationshipTypeIds($pdo);
        $permissionIds = $this->resolvePermissionIds($pdo);
        $passwordHash = InitialPassword::hashFor(ScaleSeederConfig::PASSWORD_ENV_VAR, 'scale-seeded users');

        for ($t = 1; $t <= $config->tenants; $t++) {
            $pdo->beginTransaction();
            try {
                $tenantId = $this->ensureTenant($pdo, $driver, $seed, $t, $result);
                $ouIds = $this->ensureOuTree($pdo, $driver, $tenantId, $seed, $t, $config, $result);
                $customRoleIds = $this->ensureCustomRoles(
                    $pdo,
                    $driver,
                    $tenantId,
                    $seed,
                    $t,
                    $config,
                    $permissionIds,
                    $result
                );
                $roleChoices = $this->buildRoleChoices($adminRoleId, $userRoleId, $customRoleIds);

                $profileIds = [];
                $processedSinceCommit = 0;
                for ($u = 1; $u <= $config->usersPerTenant; $u++) {
                    $profileIds[] = $this->ensureUser(
                        $pdo,
                        $driver,
                        $tenantId,
                        $seed,
                        $t,
                        $u,
                        $passwordHash,
                        $roleChoices,
                        $ouIds,
                        $result
                    );

                    $processedSinceCommit++;
                    if ($processedSinceCommit >= $config->batchSize) {
                        $pdo->commit();
                        $pdo->beginTransaction();
                        $processedSinceCommit = 0;
                    }
                }

                $personIds = [];
                foreach ($profileIds as $profileId) {
                    $personIds[] = $this->ensurePerson($pdo, $driver, $tenantId, $profileId, $result);
                }

                $this->buildRelations(
                    $pdo,
                    $driver,
                    $tenantId,
                    $seed,
                    $t,
                    $personIds,
                    $relationshipTypeIds,
                    $config->relationsPerPerson,
                    $result
                );

                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }

            if ($onTenantDone !== null) {
                $onTenantDone($t, $config->tenants, $result);
            }
        }

        return $result;
    }

    // ── Tenant ───────────────────────────────────────────────────────────────

    private function ensureTenant(
        PDO $pdo,
        string $driver,
        int $seed,
        int $tenantIndex,
        ScaleSeederResult $result
    ): int {
        $slug = NameGenerator::tenantSlug($seed, $tenantIndex);

        // Draw the name UNCONDITIONALLY, before the existence check: this
        // entity's own derived generator must always advance the same way,
        // whether or not the row turns out to already exist (see class
        // docblock — determinism/idempotency depends on this).
        $tenantRng = DeterministicRandom::derive($seed, 'tenant', (string) $tenantIndex);
        $name = (new NameGenerator($tenantRng))->companyName($seed, $tenantIndex);

        $existing = $this->fetchOne($pdo, 'SELECT id FROM tenants WHERE slug = :slug', [':slug' => $slug]);
        if ($existing !== null) {
            $result->tenantsReused++;
            return (int) $existing['id'];
        }

        $insertSql = $driver === 'pgsql'
            ? 'INSERT INTO tenants (name, slug, created_at) VALUES (:name, :slug, NOW())
               ON CONFLICT (slug) DO NOTHING RETURNING id'
            : 'INSERT INTO tenants (name, slug, created_at) VALUES (:name, :slug, NOW())
               ON CONFLICT (slug) DO NOTHING';
        $stmt = $pdo->prepare($insertSql);
        $stmt->execute([':name' => $name, ':slug' => $slug]);

        $id = $this->resolveInsertedId($pdo, $driver, $stmt);
        if ($id === null) {
            $row = $this->fetchOne($pdo, 'SELECT id FROM tenants WHERE slug = :slug', [':slug' => $slug]);
            if ($row === null) {
                throw new RuntimeException("ScaleSeeder: tenant '{$slug}' neither inserted nor found.");
            }
            $result->tenantsReused++;
            return (int) $row['id'];
        }

        $result->tenantsCreated++;
        return $id;
    }

    // ── Organizational units ─────────────────────────────────────────────────

    /**
     * Build (or resume) this tenant's OU tree: one root, then `ouBreadth`
     * children per node for `ouDepth - 1` further levels.
     *
     * @return list<int> Every OU id in the tree, flattened.
     */
    private function ensureOuTree(
        PDO $pdo,
        string $driver,
        int $tenantId,
        int $seed,
        int $tenantIndex,
        ScaleSeederConfig $config,
        ScaleSeederResult $result
    ): array {
        /** @var array<string, int> $existingBySlug slug => id, preloaded once for this tenant */
        $existingBySlug = [];
        $preload = $pdo->prepare('SELECT id, slug FROM organizational_units WHERE tenant_id = :tenant_id');
        $preload->execute([':tenant_id' => $tenantId]);
        foreach ($preload->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $existingBySlug[(string) $row['slug']] = (int) $row['id'];
        }

        $insertSql = $driver === 'pgsql'
            ? 'INSERT INTO organizational_units (tenant_id, parent_id, name, slug, description, created_at)
               VALUES (:tenant_id, :parent_id, :name, :slug, :description, NOW())
               ON CONFLICT (tenant_id, slug) DO NOTHING RETURNING id'
            : 'INSERT INTO organizational_units (tenant_id, parent_id, name, slug, description, created_at)
               VALUES (:tenant_id, :parent_id, :name, :slug, :description, NOW())
               ON CONFLICT (tenant_id, slug) DO NOTHING';
        $insertStmt = $pdo->prepare($insertSql);

        $allIds = [];

        // Root name is a fixed literal (no PRNG draw), so it needs no derived
        // generator to stay reproducible.
        $rootSlug = NameGenerator::ouSlug($seed, $tenantIndex, 'HQ');
        $rootId = $this->resolveOrInsertOu(
            $pdo,
            $driver,
            $insertStmt,
            $tenantId,
            null,
            'Head Office',
            $rootSlug,
            'Top-level organizational unit',
            $existingBySlug,
            $result
        );
        $allIds[] = $rootId;
        $parents = [$rootId];

        for ($level = 2; $level <= $config->ouDepth; $level++) {
            $nextParents = [];
            foreach (array_values($parents) as $parentIndex => $parentId) {
                for ($child = 1; $child <= $config->ouBreadth; $child++) {
                    $pathLabel = sprintf('L%d-%d-%d', $level, $parentIndex + 1, $child);
                    $slug = NameGenerator::ouSlug($seed, $tenantIndex, $pathLabel);
                    // Every node's own generator is derived from its unique
                    // path label, so its name is reproducible independent of
                    // any other node's create/reuse outcome.
                    $ouRng = DeterministicRandom::derive($seed, 'ou', (string) $tenantIndex, $pathLabel);
                    $name = (new NameGenerator($ouRng))->ouName($pathLabel);
                    $id = $this->resolveOrInsertOu(
                        $pdo,
                        $driver,
                        $insertStmt,
                        $tenantId,
                        $parentId,
                        $name,
                        $slug,
                        '',
                        $existingBySlug,
                        $result
                    );
                    $allIds[] = $id;
                    $nextParents[] = $id;
                }
            }
            $parents = $nextParents;
        }

        return $allIds;
    }

    /** @param array<string, int> $existingBySlug */
    private function resolveOrInsertOu(
        PDO $pdo,
        string $driver,
        PDOStatement $insertStmt,
        int $tenantId,
        ?int $parentId,
        string $name,
        string $slug,
        string $description,
        array &$existingBySlug,
        ScaleSeederResult $result
    ): int {
        if (isset($existingBySlug[$slug])) {
            $result->ousReused++;
            return $existingBySlug[$slug];
        }

        $insertStmt->execute([
            ':tenant_id' => $tenantId,
            ':parent_id' => $parentId,
            ':name' => $name,
            ':slug' => $slug,
            ':description' => $description,
        ]);

        $id = $this->resolveInsertedId($pdo, $driver, $insertStmt);
        if ($id === null) {
            $row = $this->fetchOne(
                $pdo,
                'SELECT id FROM organizational_units WHERE tenant_id = :tenant_id AND slug = :slug',
                [':tenant_id' => $tenantId, ':slug' => $slug]
            );
            if ($row === null) {
                throw new RuntimeException("ScaleSeeder: OU '{$slug}' neither inserted nor found.");
            }
            $id = (int) $row['id'];
            $result->ousReused++;
        } else {
            $result->ousCreated++;
        }

        $existingBySlug[$slug] = $id;
        return $id;
    }

    // ── Roles ─────────────────────────────────────────────────────────────────

    /** Resolve a GLOBAL (NULL-tenant) base role id by name — matches the Seeder::seed() bootstrap idiom. */
    private function resolveGlobalRoleId(PDO $pdo, string $name): int
    {
        $stmt = $pdo->prepare('SELECT id FROM roles WHERE name = :name AND tenant_id IS NULL');
        $stmt->execute([':name' => $name]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new RuntimeException("ScaleSeeder: global role '{$name}' not found; run migrations first.");
        }

        return (int) $row['id'];
    }

    /**
     * @param list<int> $permissionIds
     * @return list<int> The tenant's custom role ids (newly created + pre-existing).
     */
    private function ensureCustomRoles(
        PDO $pdo,
        string $driver,
        int $tenantId,
        int $seed,
        int $tenantIndex,
        ScaleSeederConfig $config,
        array $permissionIds,
        ScaleSeederResult $result
    ): array {
        $roleIds = [];

        for ($r = 1; $r <= $config->customRolesPerTenant; $r++) {
            // This role's own generator, derived from its (tenant, role
            // index) identity. Both draws below — the name's word pick, then
            // the permission-grant shuffle — happen UNCONDITIONALLY in this
            // fixed order every time, whether or not the role already
            // exists, so both stay reproducible independent of anything else
            // in the run.
            $roleRng = DeterministicRandom::derive($seed, 'role', (string) $tenantIndex, (string) $r);
            $name = (new NameGenerator($roleRng))->customRoleName($seed, $tenantIndex, $r);

            $grants = [];
            if ($permissionIds !== []) {
                $shuffled = $permissionIds;
                $roleRng->shuffle($shuffled);
                $grantCount = min(count($shuffled), $roleRng->nextInt(2, 6));
                $grants = array_slice($shuffled, 0, $grantCount);
            }

            $existing = $this->fetchOne(
                $pdo,
                'SELECT id FROM roles WHERE tenant_id = :tenant_id AND name = :name',
                [':tenant_id' => $tenantId, ':name' => $name]
            );
            if ($existing !== null) {
                $result->customRolesReused++;
                $roleIds[] = (int) $existing['id'];
                continue;
            }

            $insertSql = $driver === 'pgsql'
                ? 'INSERT INTO roles (name, description, tenant_id, created_at)
                   VALUES (:name, :description, :tenant_id, NOW())
                   ON CONFLICT (name) DO NOTHING RETURNING id'
                : 'INSERT INTO roles (name, description, tenant_id, created_at)
                   VALUES (:name, :description, :tenant_id, NOW())
                   ON CONFLICT (name) DO NOTHING';
            $stmt = $pdo->prepare($insertSql);
            $stmt->execute([
                ':name' => $name,
                ':description' => 'Scale-seeded custom role',
                ':tenant_id' => $tenantId,
            ]);

            $id = $this->resolveInsertedId($pdo, $driver, $stmt);
            if ($id === null) {
                $row = $this->fetchOne(
                    $pdo,
                    'SELECT id FROM roles WHERE tenant_id = :tenant_id AND name = :name',
                    [':tenant_id' => $tenantId, ':name' => $name]
                );
                if ($row === null) {
                    throw new RuntimeException("ScaleSeeder: role '{$name}' neither inserted nor found.");
                }
                $result->customRolesReused++;
                $id = (int) $row['id'];
            } else {
                $result->customRolesCreated++;

                // Write the grant subset computed above (RBAC realism) — only
                // on first creation; a reused role already has its grants
                // from whichever run created it.
                if ($grants !== []) {
                    $grantStmt = $pdo->prepare(
                        'INSERT INTO role_permissions (role_id, permission_id, created_at)
                         VALUES (:role_id, :permission_id, NOW())
                         ON CONFLICT (role_id, permission_id) DO NOTHING'
                    );
                    foreach ($grants as $permissionId) {
                        $grantStmt->execute([':role_id' => $id, ':permission_id' => $permissionId]);
                    }
                }
            }

            $roleIds[] = $id;
        }

        return $roleIds;
    }

    /**
     * @param list<int> $customRoleIds
     * @return list<array{0: int, 1: float}>
     */
    private function buildRoleChoices(int $adminRoleId, int $userRoleId, array $customRoleIds): array
    {
        if ($customRoleIds === []) {
            return [[$adminRoleId, 0.1], [$userRoleId, 0.9]];
        }

        $choices = [[$adminRoleId, 0.1], [$userRoleId, 0.6]];
        $each = 0.3 / count($customRoleIds);
        foreach ($customRoleIds as $roleId) {
            $choices[] = [$roleId, $each];
        }

        return $choices;
    }

    // ── Users (profile + profile_email + membership) ────────────────────────

    /**
     * @param list<array{0: int, 1: float}> $roleChoices
     * @param list<int>                     $ouIds
     */
    private function ensureUser(
        PDO $pdo,
        string $driver,
        int $tenantId,
        int $seed,
        int $tenantIndex,
        int $userIndex,
        string $passwordHash,
        array $roleChoices,
        array $ouIds,
        ScaleSeederResult $result
    ): int {
        $email = NameGenerator::userEmail($seed, $tenantIndex, $userIndex);

        // This user's own generator, derived from its (tenant, user index)
        // identity. Every draw below — display name, then role, then OU —
        // happens UNCONDITIONALLY in this fixed order every time, whether or
        // not the profile already exists, so all three stay reproducible
        // independent of anything else in the run.
        $userRng = DeterministicRandom::derive($seed, 'user', (string) $tenantIndex, (string) $userIndex);
        $displayName = (new NameGenerator($userRng))->personName()['display'];

        $existing = $this->fetchOne(
            $pdo,
            'SELECT profile_id FROM profile_emails WHERE email = :email',
            [':email' => $email]
        );

        if ($existing !== null) {
            $profileId = (int) $existing['profile_id'];
            $result->usersReused++;
        } else {
            // `two_factor_enabled` is BOOLEAN. The literal must be `false`, not
            // `0`: PostgreSQL types a bare `0` as integer and refuses the insert
            // outright ("column two_factor_enabled is of type boolean but
            // expression is of type integer"), while SQLite stores it happily.
            // The pgsql branch here was written FOR PostgreSQL and still carried
            // the SQLite literal, because the only engine it was ever executed
            // on was SQLite — `scale:seed` was broken on every real deployment.
            // The two integer zeros that follow are the INTEGER
            // two_factor_backup_codes_version / token_epoch and are correct.
            $profileInsertSql = $driver === 'pgsql'
                ? "INSERT INTO profiles
                       (display_name, password_hash, two_factor_enabled, two_factor_secret,
                        two_factor_backup_codes_version, token_epoch, created_at, updated_at)
                   VALUES
                       (:display_name, :password_hash, false, NULL, 0, 0, NOW(), NOW())
                   RETURNING id"
                : "INSERT INTO profiles
                       (display_name, password_hash, two_factor_enabled, two_factor_secret,
                        two_factor_backup_codes_version, token_epoch, created_at, updated_at)
                   VALUES
                       (:display_name, :password_hash, false, NULL, 0, 0, NOW(), NOW())";
            $profileStmt = $pdo->prepare($profileInsertSql);
            $profileStmt->execute([':display_name' => $displayName, ':password_hash' => $passwordHash]);

            $profileId = $this->resolveInsertedId($pdo, $driver, $profileStmt);
            if ($profileId === null) {
                throw new RuntimeException('ScaleSeeder: profile insert unexpectedly produced no id.');
            }

            $emailStmt = $pdo->prepare(
                'INSERT INTO profile_emails (profile_id, email, verified, is_primary, created_at)
                 VALUES (:profile_id, :email, :verified, :is_primary, NOW())
                 ON CONFLICT (email) DO NOTHING'
            );
            $emailStmt->execute([
                ':profile_id' => $profileId,
                ':email' => $email,
                ':verified' => 1,
                ':is_primary' => 1,
            ]);

            $result->usersCreated++;
        }

        $roleId = (int) $userRng->weightedPick($roleChoices);
        $ouId = ($ouIds !== [] && $userRng->chance(0.9)) ? (int) $userRng->pick($ouIds) : null;

        $membershipStmt = $pdo->prepare(
            "INSERT INTO memberships (profile_id, tenant_id, role_id, ou_id, status, created_at)
             VALUES (:profile_id, :tenant_id, :role_id, :ou_id, 'active', NOW())
             ON CONFLICT (profile_id, tenant_id) DO NOTHING"
        );
        $membershipStmt->execute([
            ':profile_id' => $profileId,
            ':tenant_id' => $tenantId,
            ':role_id' => $roleId,
            ':ou_id' => $ouId,
        ]);

        return $profileId;
    }

    // ── Persons (1:1 shadow of each profile) ─────────────────────────────────

    private function ensurePerson(
        PDO $pdo,
        string $driver,
        int $tenantId,
        int $profileId,
        ScaleSeederResult $result
    ): int {
        $existing = $this->fetchOne(
            $pdo,
            'SELECT id FROM persons WHERE tenant_id = :tenant_id AND profile_id = :profile_id',
            [':tenant_id' => $tenantId, ':profile_id' => $profileId]
        );
        if ($existing !== null) {
            $result->personsReused++;
            return (int) $existing['id'];
        }

        // profiles has no tenant_id column (global identity table, ADR 0005);
        // no predicate applies.
        $profile = $this->fetchOne($pdo, 'SELECT display_name FROM profiles WHERE id = :id', [':id' => $profileId]);
        $displayName = $profile !== null ? (string) $profile['display_name'] : ('Person ' . $profileId);

        $insertSql = $driver === 'pgsql'
            ? 'INSERT INTO persons (tenant_id, display_name, profile_id, deceased, created_at)
               VALUES (:tenant_id, :display_name, :profile_id, false, NOW())
               RETURNING id'
            : 'INSERT INTO persons (tenant_id, display_name, profile_id, deceased, created_at)
               VALUES (:tenant_id, :display_name, :profile_id, false, NOW())';
        $stmt = $pdo->prepare($insertSql);
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':display_name' => $displayName,
            ':profile_id' => $profileId,
        ]);

        $id = $this->resolveInsertedId($pdo, $driver, $stmt);
        if ($id === null) {
            // profile_id is UNIQUE (nullable): a race is the only way this
            // insert (no ON CONFLICT — the pre-check already proved no row
            // exists) could report zero rows on SQLite.
            $row = $this->fetchOne(
                $pdo,
                'SELECT id FROM persons WHERE tenant_id = :tenant_id AND profile_id = :profile_id',
                [':tenant_id' => $tenantId, ':profile_id' => $profileId]
            );
            if ($row === null) {
                throw new RuntimeException("ScaleSeeder: person for profile {$profileId} neither inserted nor found.");
            }
            $result->personsReused++;
            return (int) $row['id'];
        }

        $result->personsCreated++;
        return $id;
    }

    // ── Relations (family/social graph among a tenant's persons) ────────────

    /** @return array<string, int> Relationship-type name => id. */
    private function resolveRelationshipTypeIds(PDO $pdo): array
    {
        $stmt = $pdo->prepare('SELECT id, name FROM relationship_types');
        $stmt->execute();

        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $map[(string) $row['name']] = (int) $row['id'];
        }

        foreach (self::RELATIONSHIP_TYPE_NAMES as $required) {
            if (!isset($map[$required])) {
                throw new RuntimeException(
                    "ScaleSeeder: relationship type '{$required}' not found; run migrations first."
                );
            }
        }

        return $map;
    }

    /** @return list<int> */
    private function resolvePermissionIds(PDO $pdo): array
    {
        $stmt = $pdo->prepare('SELECT id FROM permissions');
        $stmt->execute();

        return array_map(
            static fn(array $row): int => (int) $row['id'],
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    /**
     * @param list<int>            $personIds
     * @param array<string, int>   $relationshipTypeIds
     */
    private function buildRelations(
        PDO $pdo,
        string $driver,
        int $tenantId,
        int $seed,
        int $tenantIndex,
        array $personIds,
        array $relationshipTypeIds,
        float $relationsPerPerson,
        ScaleSeederResult $result
    ): void {
        $n = count($personIds);
        if ($n < 2 || $relationsPerPerson <= 0.0) {
            return;
        }

        $targetEdges = (int) round(($n * $relationsPerPerson) / 2);
        if ($targetEdges < 1) {
            return;
        }

        // This tenant's own generator for the whole relation-building pass,
        // independent of every other phase.
        $rng = DeterministicRandom::derive($seed, 'relations', (string) $tenantIndex);

        /** @var list<array{0: int, 1: float}> $typeChoices */
        $typeChoices = [
            [$relationshipTypeIds['Spouse'], 1.0],
            [$relationshipTypeIds['Sibling'], 1.0],
            [$relationshipTypeIds['Parent'], 1.0],
        ];

        // Selection is deliberately kept independent of the database: which
        // pairs get targeted depends ONLY on this tenant's derived rng stream
        // and the in-memory dedup below — NEVER on which relations already
        // exist. That is what makes a rerun idempotent: it selects the
        // IDENTICAL set of pairs every time (same seed => same draws => same
        // dedup outcome), and each selected pair is then inserted
        // idempotently (ON CONFLICT DO NOTHING) below, so a rerun finds every
        // pair already present and creates nothing new. (An earlier version
        // fed already-connected pairs back into the selection loop's stop
        // condition to try to replicate the original run's stopping point —
        // that broke down because a rerun's replayed sequence does not
        // consume rng draws in the same pattern as an original run whose
        // early exit depended on how many distinct pairs it had found so
        // far. Decoupling selection from DB state sidesteps the problem
        // entirely instead of patching around it.)
        /** @var array<string, array{0: int, 1: int, 2: int}> $selected key => [from, to, typeId] */
        $selected = [];
        $attempts = 0;
        $maxAttempts = $targetEdges * 20 + 50;

        while (count($selected) < $targetEdges && $attempts < $maxAttempts) {
            $attempts++;

            $from = (int) $rng->pick($personIds);
            $to = (int) $rng->pick($personIds);
            $typeId = (int) $rng->weightedPick($typeChoices);

            if ($from === $to) {
                continue;
            }

            $key = self::pairKey($from, $to);
            if (isset($selected[$key])) {
                continue; // at most one edge per unordered pair
            }

            $selected[$key] = [$from, $to, $typeId];
        }

        if ($selected === []) {
            return;
        }

        $insertSql = $driver === 'pgsql'
            ? 'INSERT INTO relations (tenant_id, from_person_id, to_person_id, relationship_type_id, created_at)
               VALUES (:tenant_id, :from_id, :to_id, :type_id, NOW())
               ON CONFLICT (tenant_id, from_person_id, to_person_id, relationship_type_id) DO NOTHING
               RETURNING id'
            : 'INSERT INTO relations (tenant_id, from_person_id, to_person_id, relationship_type_id, created_at)
               VALUES (:tenant_id, :from_id, :to_id, :type_id, NOW())
               ON CONFLICT (tenant_id, from_person_id, to_person_id, relationship_type_id) DO NOTHING';
        $stmt = $pdo->prepare($insertSql);

        foreach ($selected as [$from, $to, $typeId]) {
            $stmt->execute([
                ':tenant_id' => $tenantId,
                ':from_id' => $from,
                ':to_id' => $to,
                ':type_id' => $typeId,
            ]);

            if ($this->resolveInsertedId($pdo, $driver, $stmt) !== null) {
                $result->relationsCreated++;
            } else {
                $result->relationsReused++;
            }
        }
    }

    private static function pairKey(int $a, int $b): string
    {
        return $a < $b ? "{$a}-{$b}" : "{$b}-{$a}";
    }

    // ── Shared helpers ────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>|null
     */
    private function fetchOne(PDO $pdo, string $sql, array $params): ?array
    {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * Resolve the id an INSERT just produced, or null when an
     * `ON CONFLICT ... DO NOTHING` guard skipped it (row already existed).
     *
     * On PostgreSQL the statement MUST carry `RETURNING id`; on SQLite (or any
     * other driver) it must NOT, and the id is resolved via `rowCount()` +
     * `lastInsertId()`.
     */
    private function resolveInsertedId(PDO $pdo, string $driver, PDOStatement $stmt): ?int
    {
        if ($driver === 'pgsql') {
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row !== false ? (int) $row['id'] : null;
        }

        return $stmt->rowCount() > 0 ? (int) $pdo->lastInsertId() : null;
    }
}
