<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Core\RBAC\CorePermissions;
use Whity\Database\Database;

/**
 * GrantBillingPermissions — the tenant-facing billing capabilities, and who
 * gets them.
 *
 * `billing:view` and `billing:pay` are new in #billing, and they are TENANT
 * capabilities rather than platform ones. That is the distinction the existing
 * catalogue was missing: `plans:manage` and `subscriptions:manage` are operator
 * powers that additionally require acting in the system tenant, because they
 * decide what somebody ELSE is charged. These two are for a customer looking at
 * their own account.
 *
 * WHY TWO SLUGS AND NOT ONE. Reading the account and spending from it are
 * different jobs. A finance viewer, an accountant, an auditor — all of them
 * have a reason to see what is owed and what has been paid, and none of them is
 * necessarily someone who may start a payment. Collapsing the two would mean
 * every person who needs the invoice list also gets the pay button, which is
 * the kind of over-grant nobody notices until it matters.
 *
 * WHO THEY ARE GRANTED TO
 * -----------------------
 * By CAPABILITY, never by role name. `scripts/ci-grant-by-role-name-guard.php`
 * enforces that, and migration 110 recorded the reason (#834): the ~20
 * `grant_*_to_admin` migrations that came before all target `admin` by name, so
 * a deployment running a custom administrative role silently LOSES a capability
 * on upgrade.
 *
 * The anchors here are the settings capabilities, and that choice follows a
 * precedent already in the codebase rather than being invented: a tenant
 * admin's read-only view of its own subscription is already gated on
 * `settings:read` — {@see CorePermissions::SUBSCRIPTIONS_MANAGE} says so. Where
 * somebody can already see the tenant's subscription state, they can see its
 * invoices.
 *
 *   billing:view ← settings:read   (may see this tenant's configuration)
 *   billing:pay  ← settings:write  (may change it, so may commit it to a bill)
 *
 * The asymmetry is deliberate: `pay` anchors on the stronger capability, so a
 * read-only administrator does not silently acquire the ability to spend.
 *
 * NEITHER GRANT SETTLES ANYTHING. `billing:pay` starts a payment; only a
 * verified provider callback marks an invoice paid. So the worst a holder of
 * this can do is ask to be charged, which is not a power worth withholding from
 * somebody who can already reconfigure the tenant.
 */
final class GrantBillingPermissions
{
    /** @var array<string, string> */
    private const PERMISSIONS = [
        CorePermissions::BILLING_VIEW => 'View this tenant\'s own invoices and payment history',
        CorePermissions::BILLING_PAY => 'Start a payment for this tenant\'s own invoices',
    ];

    /**
     * Which anchor grants which slug. Separate lists rather than one shared
     * audience, so that `pay` really does need the stronger capability.
     *
     * @var array<string, list<string>>
     */
    private const AUDIENCE_FOR = [
        CorePermissions::BILLING_VIEW => [CorePermissions::SETTINGS_READ, CorePermissions::SETTINGS_WRITE],
        CorePermissions::BILLING_PAY => [CorePermissions::SETTINGS_WRITE],
    ];

    public static function up(Database $db): void
    {
        foreach (self::PERMISSIONS as $name => $description) {
            $db->query(
                'INSERT INTO permissions (name, description, created_at)
                 VALUES (:name, :description, NOW())
                 ON CONFLICT (name) DO NOTHING',
                [':name' => $name, ':description' => $description]
            );
        }

        foreach (self::AUDIENCE_FOR as $slug => $anchors) {
            $permissionId = self::permissionId($db, $slug);
            if ($permissionId === null) {
                continue;
            }

            // A database where nobody may administer settings has nobody to
            // grant to. Not an error: on a fresh install `up()` runs before
            // whatever seeds that grant, and the catalogue row above is all
            // this migration owes such a database.
            foreach (self::rolesHoldingAny($db, $anchors) as $roleId) {
                $db->query(
                    'INSERT INTO role_permissions (role_id, permission_id, created_at)
                     VALUES (:role_id, :permission_id, NOW())
                     ON CONFLICT (role_id, permission_id) DO NOTHING',
                    [':role_id' => $roleId, ':permission_id' => $permissionId]
                );
            }
        }
    }

    /**
     * Take the grants back from the same audiences `up()` gave them to.
     *
     * The catalogue rows go too, unlike migration 138's — these slugs are NEW
     * here rather than pre-existing, so removing them restores the catalogue to
     * what it was rather than deleting something a deployment may have granted
     * deliberately.
     */
    public static function down(Database $db): void
    {
        foreach (self::AUDIENCE_FOR as $slug => $anchors) {
            $permissionId = self::permissionId($db, $slug);
            if ($permissionId === null) {
                continue;
            }

            foreach (self::rolesHoldingAny($db, $anchors) as $roleId) {
                $db->query(
                    'DELETE FROM role_permissions WHERE role_id = :role_id AND permission_id = :permission_id',
                    [':role_id' => $roleId, ':permission_id' => $permissionId]
                );
            }
        }

        foreach (array_keys(self::PERMISSIONS) as $name) {
            $db->query('DELETE FROM permissions WHERE name = :name', [':name' => $name]);
        }
    }

    /**
     * Role ids holding at least one of the given permissions.
     *
     * @param  list<string> $permissions
     * @return list<int>
     */
    private static function rolesHoldingAny(Database $db, array $permissions): array
    {
        $roleIds = [];

        foreach ($permissions as $permission) {
            $rows = $db->query(
                'SELECT rp.role_id
                   FROM role_permissions rp
                   JOIN permissions p ON p.id = rp.permission_id
                  WHERE p.name = :name',
                [':name' => $permission]
            )->fetchAll();

            if ($rows === false) {
                continue;
            }

            foreach ($rows as $row) {
                $roleIds[(int) $row['role_id']] = true;
            }
        }

        return array_keys($roleIds);
    }

    private static function permissionId(Database $db, string $name): ?int
    {
        $row = $db->query(
            'SELECT id FROM permissions WHERE name = :name',
            [':name' => $name]
        )->fetch();

        return is_array($row) && isset($row['id']) ? (int) $row['id'] : null;
    }
}
