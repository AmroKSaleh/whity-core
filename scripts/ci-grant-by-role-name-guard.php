<?php

declare(strict_types=1);

/**
 * CI grant-by-role-name guard (#1047).
 *
 * THE INVARIANT
 * -------------
 * A migration may not decide WHO holds a permission by looking a role up by its
 * NAME. It must resolve its audience by CAPABILITY — the roles that already hold
 * some other permission — the way migrations 110, 111 and 136 do.
 *
 * WHY A NAME IS THE WRONG KEY
 * ---------------------------
 * `SELECT id FROM roles WHERE name = 'admin'` returns nothing on a deployment
 * whose administrative role is called `superuser`, `operator`, or anything else.
 * Every migration written this way then returns early and grants NOBODY — with
 * no error, because "no role called admin" is indistinguishable from "already
 * granted". Migration 110 recorded this as #834; this guard is what stops the
 * twenty-third instance.
 *
 * The symptom is not a 500. It is a screen that exists, answers 200 to a direct
 * request, and cannot be reached: the nav filters its entry out because the
 * caller does not hold the permission, so an administrator sees a menu with a
 * hole in it and nothing anywhere says why (#1047).
 *
 * The lookup is also unqualified — no tenant predicate, no LIMIT — so on an
 * installation where several tenants each define a role named `admin`, it grants
 * to whichever row the database happens to return first. Arbitrary rather than
 * wrong in one direction, which is harder to diagnose than either.
 *
 * WHAT THIS DOES NOT DO, DELIBERATELY
 * -----------------------------------
 * It does not re-grant anything. Restoring the intent of the twenty-seven
 * migrations below would mean choosing, for each, a capability that identifies
 * "the administrator" on a deployment this repository cannot see — and every
 * anchor available bottoms out in another by-name grant, so the choice would be
 * a guess. Guessing wrong hands `users:write` and `security:manage` to whoever
 * happens to hold the anchor. A guard that stops the pattern spreading is worth
 * more than a migration that widens twenty-two permissions on somebody else's
 * installation.
 *
 * Occupancy on a REAL database is a different question with a different home:
 * {@see ci-permission-holder-guard.php} states that it belongs in an
 * operator-facing health check, where the answer is about that deployment and
 * somebody can act on it.
 *
 * THE GRANDFATHERED LIST IS CHECKED IN BOTH DIRECTIONS
 * ----------------------------------------------------
 * An entry that no longer matches the pattern is as much a failure as a new file
 * that does — otherwise the list outlives its reason and quietly becomes a
 * permanent exemption. That is the shape `UNHELD_BY_DESIGN` established in the
 * permission-holder guard.
 *
 * Migrations already merged are NOT to be edited to satisfy this: they have run
 * on real databases and rewriting one changes history other deployments have
 * recorded. The list is how they stay honest without being touched.
 *
 * Usage:  php scripts/ci-grant-by-role-name-guard.php
 */

/**
 * Migrations that resolve a role by NAME and grant to it, as of #1047.
 *
 * Every one predates the rule. None may be edited to remove itself from this
 * list; the way off it is a NEW migration that re-grants by capability, at which
 * point the old file still matches and stays listed.
 *
 * @var list<string>
 */
const GRANDFATHERED = [
    '009_assign_ou_permissions_to_roles.php',
    '013_grant_plugins_manage_to_admin.php',
    '015_grant_delegation_manage_to_admin.php',
    '016_create_audit_log.php',
    '020_create_relations.php',
    '022_grant_users_permissions_to_admin.php',
    '023_grant_admin_write_permissions.php',
    '026_grant_settings_permissions_to_admin.php',
    '034_grant_mcp_tokens_manage_to_admin.php',
    '043_grant_registrations_approve_to_admin.php',
    '049_grant_auth_providers_manage_to_admin.php',
    '052_grant_entitlements_manage_to_admin.php',
    '054_grant_storage_manage_to_admin.php',
    '056_grant_plans_manage_to_admin.php',
    '058_grant_subscriptions_manage_to_admin.php',
    '060_grant_documents_perms_to_admin.php',
    '062_grant_security_manage_to_admin.php',
    '064_grant_tags_perms_to_admin.php',
    '068_grant_jobs_perms_to_admin.php',
    '074_grant_notification_perms_to_admin.php',
    '078_grant_password_resets_approve_to_admin.php',
    '079_grant_two_factor_recovery_approve_to_admin.php',
    '086_grant_i18n_perms_to_admin.php',
    '098_grant_desktop_plugins_read_to_admin.php',
    '100_grant_desktop_app_updates_read_to_admin.php',
    '101_revoke_ous_read_from_user_role.php',
    '109_grant_documents_read_all_to_admin.php',
];

$projectRoot = dirname(__DIR__);
$migrationDir = $projectRoot . '/database/migrations';

if (!is_dir($migrationDir)) {
    fwrite(STDERR, "FAIL: no database/migrations directory at {$migrationDir}.\n");
    exit(1);
}

/**
 * Source with comments removed.
 *
 * Read from the CODE only: a docblock explaining why by-name lookups are wrong —
 * which several of these files carry, and which this guard's own remediation
 * asks authors to write — must not itself trip the guard.
 */
function code_of(string $path): string
{
    $src = (string) file_get_contents($path);
    $src = (string) preg_replace('#/\*.*?\*/#s', '', $src);

    return (string) preg_replace('#//[^\n]*#', '', $src);
}

/** Does this migration look a role up by name AND write grant rows? */
function grants_by_role_name(string $code): bool
{
    if (!str_contains($code, 'role_permissions')) {
        return false;
    }

    return preg_match('/FROM\s+roles\s+WHERE\s+name/i', $code) === 1;
}

$files = glob($migrationDir . '/*.php') ?: [];
sort($files);

$offenders = [];
foreach ($files as $file) {
    if (grants_by_role_name(code_of($file))) {
        $offenders[] = basename($file);
    }
}

$grandfathered = GRANDFATHERED;
sort($grandfathered);

$new = array_values(array_diff($offenders, $grandfathered));
$stale = array_values(array_diff($grandfathered, $offenders));

if ($new !== []) {
    fwrite(STDERR, "FAIL: a migration decides who holds a permission by ROLE NAME.\n\n");
    foreach ($new as $file) {
        fwrite(STDERR, "  database/migrations/{$file}\n");
    }
    fwrite(
        STDERR,
        "\nA role looked up as `WHERE name = 'admin'` does not exist on a deployment whose\n"
        . "administrative role is called something else. The migration then grants NOBODY and\n"
        . "says nothing, and the capability surfaces later as a screen that answers 200 and has\n"
        . "no nav entry (#1047), or as a 403 nobody can explain.\n\n"
        . "Resolve the audience by CAPABILITY instead — the roles that already hold a related\n"
        . "permission. Migrations 110, 111 and 136 are the pattern:\n\n"
        . "    SELECT rp.role_id FROM role_permissions rp\n"
        . "      JOIN permissions p ON p.id = rp.permission_id\n"
        . "     WHERE p.name = :anchor\n\n"
        . "Pick an anchor at the SAME SCOPE as what you are granting (136 uses\n"
        . "`settings:manage` for instance-wide vocabulary), and state in the docblock why that\n"
        . "anchor identifies the right audience. An empty audience is a legal outcome: grant\n"
        . "nothing rather than fall back to a name.\n"
    );
    exit(1);
}

if ($stale !== []) {
    fwrite(STDERR, "FAIL: the grandfathered list names files that no longer match.\n\n");
    foreach ($stale as $file) {
        fwrite(STDERR, "  database/migrations/{$file}\n");
    }
    fwrite(
        STDERR,
        "\nEither the file was edited — which it must not be, since it has run on real\n"
        . "databases and rewriting it changes history other deployments recorded — or it was\n"
        . "renamed or removed. Fix the file, or drop its entry from GRANDFATHERED in this\n"
        . "script if it genuinely no longer exists.\n\n"
        . "The list is checked in BOTH directions on purpose: an entry that outlives its reason\n"
        . "is a permanent exemption nobody reviews again.\n"
    );
    exit(1);
}

printf(
    "OK: %d migration(s) resolve a role by name, all predating the rule; no new one does.\n",
    count($offenders)
);
