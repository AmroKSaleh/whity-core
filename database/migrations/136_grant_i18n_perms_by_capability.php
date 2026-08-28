<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Core\RBAC\CorePermissions;
use Whity\Database\Database;

/**
 * GrantI18nPermsByCapability — give the i18n permissions to whoever a deployment
 * actually trusts with global settings, not only to the role literally called
 * `admin`.
 *
 * WHAT THIS IS NOT FIXING. #1047 reported `languages:manage` as held by nobody,
 * so the Languages item never rendered in the SYSTEM nav. That does not
 * reproduce: on a database built from these migrations, and on the live
 * instance, the slug is held by the global `admin` role and three people hold
 * that role. The report was accurate when filed against an instance whose
 * migrations had drifted behind its checkout — migration 086 had not yet run —
 * and running it resolved the symptom.
 *
 * WHAT IS STILL WRONG, WHICH IS WHY THIS EXISTS. Migration 086 grants both i18n
 * permissions with `SELECT id FROM roles WHERE name = 'admin'`. That is the
 * hazard migration 110 records as #834: a deployment running a custom
 * administrative role — `superuser`, `operator`, anything not spelled `admin` —
 * silently receives NOTHING from a `grant_*_to_admin` migration, and discovers
 * it as a 403 or, as here, as a nav item that is simply absent. The capability
 * is not missing from the catalogue and nothing reports a problem; the screen
 * just is not there.
 *
 * The query is also unqualified — no tenant predicate and no LIMIT — so on an
 * installation where several tenants each define a role named `admin`, it grants
 * to whichever row the database returns first. That is arbitrary rather than
 * wrong-in-one-direction, which is worse to diagnose.
 *
 * THE ANCHOR. `settings:manage` — whoever a deployment already trusts to edit
 * the GLOBAL website-settings defaults. Languages and translations are the same
 * kind of decision at the same scope: instance-wide vocabulary, set by whoever
 * configures the instance. Anchoring follows the deployment's own grants rather
 * than a name we chose, so an operator who created `superuser` and gave it
 * `settings:manage` gets these too, without knowing this migration exists.
 *
 * Migration 026 grants `settings:manage` and runs long before this, so the
 * audience is never empty here — the failure mode migration 131 warns about,
 * where an anchor granted later by the SEEDER leaves the slug held by nobody at
 * migrate time.
 *
 * Additive and idempotent. down() removes only the grants this migration made,
 * and deliberately leaves the `admin` grants from 086 alone: those are 086's to
 * own, and taking them back here would revoke a capability this file never gave.
 */
final class GrantI18nPermsByCapability
{
    /** The i18n slugs, and the capability that identifies who should hold them. */
    private const ANCHOR = CorePermissions::SETTINGS_MANAGE;

    /** @var list<string> */
    private const SLUGS = [
        CorePermissions::LANGUAGES_MANAGE,
        CorePermissions::TRANSLATIONS_MANAGE,
    ];

    public static function up(Database $db): void
    {
        $anchorHolders = self::rolesHolding($db, self::ANCHOR);
        if ($anchorHolders === []) {
            // Nothing to widen to. Not an error: a database where nobody holds
            // `settings:manage` has made a deliberate choice, and inventing an
            // audience here would grant instance-wide vocabulary control to
            // somebody this deployment never nominated.
            return;
        }

        foreach (self::SLUGS as $slug) {
            $permissionId = self::permissionId($db, $slug);
            if ($permissionId === null) {
                // Migration 086 puts the row there. A database missing it has a
                // drifted catalogue, and creating it here would attach a
                // description this file does not own to a slug 086 is
                // responsible for.
                continue;
            }

            foreach ($anchorHolders as $roleId) {
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
        // The audience is re-resolved the way up() resolved it. A role granted
        // the anchor AFTER this ran never received these, so it has nothing to
        // take back; a role that LOST the anchor in between keeps them, which is
        // the conservative direction — it leaves an operator holding a
        // capability they may no longer need rather than removing one they are
        // relying on. Mirrors migration 131's down().
        $anchorHolders = self::rolesHolding($db, self::ANCHOR);

        foreach (self::SLUGS as $slug) {
            $permissionId = self::permissionId($db, $slug);
            if ($permissionId === null) {
                continue;
            }

            foreach ($anchorHolders as $roleId) {
                // The role named `admin` is skipped: migration 086 granted that
                // one, and this migration never did. Removing it here would take
                // away a grant this file is not the author of.
                if (self::roleIsNamedAdmin($db, $roleId)) {
                    continue;
                }

                $db->query(
                    'DELETE FROM role_permissions
                      WHERE role_id = :role_id AND permission_id = :permission_id',
                    [':role_id' => $roleId, ':permission_id' => $permissionId]
                );
            }
        }
    }

    /** @return list<int> */
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

        $roleIds = [];
        foreach ($rows as $row) {
            $roleIds[(int) $row['role_id']] = true;
        }

        return array_map('intval', array_keys($roleIds));
    }

    private static function permissionId(Database $db, string $name): ?int
    {
        $result = $db->query('SELECT id FROM permissions WHERE name = :name', [':name' => $name])->fetch();

        return $result === false ? null : (int) $result['id'];
    }

    private static function roleIsNamedAdmin(Database $db, int $roleId): bool
    {
        $result = $db->query('SELECT name FROM roles WHERE id = :id', [':id' => $roleId])->fetch();

        return $result !== false && $result['name'] === 'admin';
    }
}
