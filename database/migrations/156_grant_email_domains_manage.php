<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;
use Whity\Core\RBAC\CorePermissions;

/**
 * GrantEmailDomainsManage migration (#990, the `email_domains:*` group).
 *
 * Registers `email_domains:manage` and grants it to everyone who can already
 * configure how people sign in to this tenant, so the five
 * `/api/v1/email-domains` routes can stop being gated on the `admin` ROLE NAME.
 *
 * WHY THE ROUTES HAD TO MOVE
 * --------------------------
 * `RbacMiddleware` resolves a role gate through
 * `hasRoleForProfile($profileId, 'admin', $tenantId)`. On a deployment whose
 * administrative role is named anything else, all five answered 403 — to the
 * administrator they were written for. Being renameable is the point of a
 * white-label platform.
 *
 * WHO IT GRANTS TO, AND WHY NOT `admin`
 * -------------------------------------
 * Every role already holding `auth_providers:manage`, resolved by capability
 * rather than by name — the pattern migration 110 established and
 * `scripts/ci-grant-by-role-name-guard.php` enforces. The ~20 older
 * `grant_*_to_admin` migrations target `admin` literally, which is the recorded
 * hazard (#834): a deployment running a custom administrative role silently
 * LOSES the capability on upgrade, because the migration that introduced the
 * gate reached only the seeded role. Repeating that here would re-create, in a
 * brand-new migration, exactly the defect the re-gate exists to remove.
 *
 * `auth_providers:manage` is the right anchor rather than `settings:manage` or
 * `tenants:write`, and the reason is that the two features answer one question.
 * A tenant's verified email domains and its identity providers both decide WHO
 * MAY SIGN IN HERE AND HOW: a verified domain is what lets an address from that
 * domain be trusted for JIT provisioning, and a provider is what it is trusted
 * through. Whoever may add an OIDC provider can already admit a population of
 * users; withholding domain verification from that same role would gate the
 * weaker half of one decision behind a different permission, which reads as
 * caution and is really just two names for one authority.
 *
 * A DATABASE WHERE NOBODY HOLDS THE ANCHOR
 * ----------------------------------------
 * Gets the catalogue row and no grants. Not an error: `up()` on a fresh install
 * runs before whatever seeds that grant, and migration 049 grants
 * `auth_providers:manage` to the seeded `admin` role at a LOWER number, so the
 * ordinary ordering already puts an audience in place before this runs.
 */
class GrantEmailDomainsManage
{
    /** @var array<string, string> */
    private const PERMISSIONS = [
        CorePermissions::EMAIL_DOMAINS_MANAGE =>
            'Manage this tenant\'s verified email domains (add, verify, remove)',
    ];

    /** Grant to every role that already holds this, never to a role by name. */
    private const AUDIENCE_PERMISSION = CorePermissions::AUTH_PROVIDERS_MANAGE;

    public static function up(Database $db): void
    {
        // Catalogue upsert first, so the migration stands on its own against a
        // database whose catalogue drifted. ON CONFLICT DO NOTHING can never
        // overwrite a human-edited description.
        foreach (self::PERMISSIONS as $name => $description) {
            $db->query(
                'INSERT INTO permissions (name, description, created_at)
                 VALUES (:name, :description, NOW())
                 ON CONFLICT (name) DO NOTHING',
                [':name' => $name, ':description' => $description]
            );
        }

        $audience = self::rolesHolding($db, self::AUDIENCE_PERMISSION);
        if ($audience === []) {
            return;
        }

        foreach (array_keys(self::PERMISSIONS) as $name) {
            $permissionId = self::permissionId($db, $name);
            if ($permissionId === null) {
                continue;
            }

            foreach ($audience as $roleId) {
                $db->query(
                    'INSERT INTO role_permissions (role_id, permission_id, created_at)
                     VALUES (:role_id, :permission_id, NOW())
                     ON CONFLICT (role_id, permission_id) DO NOTHING',
                    [':role_id' => $roleId, ':permission_id' => $permissionId]
                );
            }
        }
    }

    public static function down(Database $db): void
    {
        // Resolved the same way up() did. A role granted the anchor AFTER this
        // ran never received the new slug and has nothing to take back; a role
        // that lost the anchor in between keeps it, which is the conservative
        // direction for a down() — an operator left holding a permission they
        // may not need, rather than stripped of one they do.
        $audience = self::rolesHolding($db, self::AUDIENCE_PERMISSION);

        foreach (array_keys(self::PERMISSIONS) as $name) {
            $permissionId = self::permissionId($db, $name);
            if ($permissionId === null) {
                continue;
            }

            foreach ($audience as $roleId) {
                $db->query(
                    'DELETE FROM role_permissions WHERE role_id = :role_id AND permission_id = :permission_id',
                    [':role_id' => $roleId, ':permission_id' => $permissionId]
                );
            }

            // Only when nothing else holds it — this migration introduced the
            // catalogue row, so it owns its removal, but never at the cost of
            // orphaning a grant somebody else made.
            $db->query(
                'DELETE FROM permissions
                 WHERE name = :name
                   AND NOT EXISTS (
                       SELECT 1 FROM role_permissions rp WHERE rp.permission_id = permissions.id
                   )',
                [':name' => $name]
            );
        }
    }

    /**
     * Role ids holding a permission, by slug.
     *
     * @return list<int>
     */
    private static function rolesHolding(Database $db, string $permission): array
    {
        $rows = $db->query(
            'SELECT rp.role_id
               FROM role_permissions rp
               JOIN permissions p ON p.id = rp.permission_id
              WHERE p.name = :name',
            [':name' => $permission]
        )->fetchAll();

        if ($rows === false) {
            return [];
        }

        return array_map(static fn (array $row): int => (int) $row['role_id'], $rows);
    }

    private static function permissionId(Database $db, string $name): ?int
    {
        $result = $db->query('SELECT id FROM permissions WHERE name = :name', [':name' => $name])->fetch();

        return $result === false ? null : (int) $result['id'];
    }
}
