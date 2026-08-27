<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Core\RBAC\CorePermissions;
use Whity\Database\Database;

/**
 * CreateFormPermissions — the three capabilities migration 127's tables need,
 * and the grants that put them in the right hands on an existing install.
 *
 * Separate from 127 on purpose. A schema change and an authorization change are
 * two different things to review, and an operator reading a migration list
 * should be able to see "this one hands somebody a new power" without reading
 * three hundred lines of DDL to find out.
 *
 * WHY THREE SLUGS AND NOT THE USUAL READ/WRITE PAIR
 * --------------------------------------------------
 * Because there are three audiences, and two of them barely overlap.
 *
 *   `forms:read`    See which forms exist and what has been submitted. Held by
 *                   anyone who works with the records — an approver looking at a
 *                   submission holds this and nothing else.
 *   `forms:manage`  AUTHOR forms: add fields, change what is required, decide
 *                   where submissions go. This is organisational policy. The
 *                   person who decides what everyone must declare is not the
 *                   same person who reads the declarations, and an install where
 *                   they were one grant could not express that.
 *   `forms:submit`  FILL IN a published form. The everyday act, held by the
 *                   largest audience by far — most people who submit a form will
 *                   never be allowed to author one, and several will not be
 *                   allowed to read anybody else's submission.
 *
 * Each of the three is a permission somebody would revoke separately, which is
 * the #987 test for whether a slug is a real capability or a second name for an
 * existing one. Folding `:submit` into `:read` would be the tempting one and it
 * is the wrong one: it would mean that letting somebody file a request also lets
 * them read every request everybody else filed.
 *
 * WHAT `forms:submit` DOES *NOT* GATE
 * ------------------------------------
 * Reading back one's OWN submissions. `GET /api/v1/me/form-submissions` is gated
 * on `forms:submit` because a person who may file a thing may see the things
 * they filed — the row already names exactly one person, so a tenant-wide
 * permission has nothing left to decide. That is the same argument migration 113
 * makes about routing ("being a recipient IS the authorization") and the same
 * one #978 makes about the inbox.
 *
 * WHY THE GRANTS ARE KEYED ON CAPABILITIES, NOT ON THE ROLE NAMED `admin`
 * -----------------------------------------------------------------------
 * Migration 110's rule, restated by 120 and 126: a deployment running a custom
 * administrative role silently LOSES a capability on upgrade when the grant is
 * keyed on the name `admin`. A grant keyed on a capability the deployment
 * actually granted is the one that survives whatever they called it.
 *
 * A NOTE ON `roles` AND ITS PARTIAL UNIQUE INDEXES
 * ------------------------------------------------
 * This migration creates NO roles, which is why no `ON CONFLICT` clause here
 * carries a predicate. If a later migration in this subsystem ever does seed a
 * role, it must not repeat the defect migration 093 (#712) created and
 * {@see \Whity\Core\PluginRoleSeeder} documents: `roles_name_key` is gone,
 * replaced by two PARTIAL unique indexes — `uq_roles_global_name (name) WHERE
 * tenant_id IS NULL` and its per-tenant twin. A conflict target must match one
 * of them INCLUDING its predicate; an unqualified `ON CONFLICT (name)` matches
 * no index at all and PostgreSQL rejects the statement outright.
 *
 * `permissions.name` is a plain UNIQUE and takes the unqualified target below,
 * exactly as migration 120's catalogue insert does.
 *
 * Idempotent and reversible via down().
 */
final class CreateFormPermissions
{
    /**
     * The three slugs this migration introduces.
     *
     * Descriptions are written for somebody reading a permission picker, so they
     * say what the permission LETS A PERSON DO rather than restating the slug.
     *
     * @var array<string, string>
     */
    private const PERMISSIONS = [
        CorePermissions::FORMS_READ =>
            'See the tenant\'s forms and the submissions made against them',
        CorePermissions::FORMS_MANAGE =>
            'Author forms — add and order fields, decide what is required, and set where submissions go',
        CorePermissions::FORMS_SUBMIT =>
            'Fill in and submit a published form, and read back one\'s own submissions',
    ];

    /**
     * Which existing capability identifies the audience for each new slug.
     *
     * `forms:manage` goes to whoever may already rewrite the organisation's
     * access policy (`roles:write`) — authoring the form everyone must fill in is
     * an act of the same kind.
     *
     * `forms:read` has two audiences because two different jobs need it: the
     * people who author forms, and the people who will read what came back.
     * `documents:read` identifies the second, and it is the right handle rather
     * than a coincidence — a submission BECOMES a document (migration 127), so
     * whoever may read the tenant's documents is exactly whoever may read its
     * submissions. Granting less here would produce the worst possible state: a
     * reader who can open the routed document but not the submission that
     * explains it.
     *
     * `forms:submit` follows `documents:read` alone. It is the broadest of the
     * three and this is the broadest handle available that still means something
     * — everybody who works with the tenant's records. It is deliberately NOT
     * granted to every role: a service principal that reads documents for an
     * integration has no business filing requests, and an install that wants the
     * whole tenant to submit grants the slug to its base role, which is one
     * statement in the roles admin rather than a decision this migration makes
     * on their behalf.
     *
     * @var array<string, list<string>>
     */
    private const AUDIENCES = [
        CorePermissions::FORMS_MANAGE => [CorePermissions::ROLES_WRITE],
        CorePermissions::FORMS_READ => [CorePermissions::ROLES_WRITE, CorePermissions::DOCUMENTS_READ],
        CorePermissions::FORMS_SUBMIT => [CorePermissions::DOCUMENTS_READ],
    ];

    public static function up(Database $db): void
    {
        foreach (self::PERMISSIONS as $name => $description) {
            // Migration 013 seeds the whole CorePermissions list, so on a fresh
            // install these rows already exist by the time this runs; the insert
            // is here so the migration stands on its own against a database whose
            // catalogue drifted, and it can never overwrite a human-written
            // description (ON CONFLICT DO NOTHING).
            $db->query(
                'INSERT INTO permissions (name, description, created_at)
                 VALUES (:name, :description, NOW())
                 ON CONFLICT (name) DO NOTHING',
                [':name' => $name, ':description' => $description]
            );
        }

        foreach (self::AUDIENCES as $slug => $audiencePermissions) {
            $permissionId = self::permissionId($db, $slug);
            if ($permissionId === null) {
                continue;
            }

            foreach (self::rolesHoldingAny($db, $audiencePermissions) as $roleId) {
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
        // Grants first: `role_permissions` has a foreign key to `permissions`, so
        // a catalogue row cannot go while a grant still points at it.
        //
        // The audience is re-resolved the way up() resolved it. A role granted
        // `documents:read` AFTER this migration ran never received these, so it
        // has nothing to take back; a role that LOST it in between keeps them,
        // which is the conservative direction for a down() — it leaves an
        // operator holding a permission they may not need rather than removing
        // one they do.
        foreach (self::AUDIENCES as $slug => $audiencePermissions) {
            $permissionId = self::permissionId($db, $slug);
            if ($permissionId === null) {
                continue;
            }

            foreach (self::rolesHoldingAny($db, $audiencePermissions) as $roleId) {
                $db->query(
                    'DELETE FROM role_permissions WHERE role_id = :role_id AND permission_id = :permission_id',
                    [':role_id' => $roleId, ':permission_id' => $permissionId]
                );
            }

            // Only when nothing else holds it. Migration 013 seeded the catalogue
            // and owns its removal; this clause is the safety net for a database
            // where 013's catalogue step did not run.
            $db->query(
                'DELETE FROM permissions
                 WHERE name = :name
                   AND NOT EXISTS (
                       SELECT 1 FROM role_permissions rp WHERE rp.permission_id = permissions.id
                   )',
                [':name' => $slug]
            );
        }
    }

    /**
     * The ids of every role holding ANY of the given permissions DIRECTLY.
     *
     * Direct grants only, the rule migration 110 states: effective resolution
     * walks role inheritance, organizational units and delegations, and a
     * migration that followed those paths would write grant rows onto roles that
     * hold the capability transitively — turning an inherited permission into an
     * independent one and quietly changing what revoking the parent does.
     *
     * De-duplicated, because the two audiences for `forms:read` overlap on every
     * ordinary install (one administrative role holds both), and inserting twice
     * would rely on the conflict clause to hide a bug here.
     *
     * @param list<string> $permissions
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

        return array_map('intval', array_keys($roleIds));
    }

    private static function permissionId(Database $db, string $name): ?int
    {
        $result = $db->query('SELECT id FROM permissions WHERE name = :name', [':name' => $name])->fetch();

        return $result === false ? null : (int) $result['id'];
    }
}
