<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * SeedCliServicePrincipal — forward migration (#928, migration 107).
 *
 * Creates the principal the CLI authorizes as. Data only; migration 106 makes
 * `auth_method = 'service'` legal.
 *
 * WHY THE CLI NEEDS A REAL ROW
 * ----------------------------
 * Since the identity cutover (#398), `RbacMiddleware` requires an integer
 * `profile_id` claim and then checks the role against the AUTHORITATIVE STORE:
 *
 *     $this->roleChecker->hasRoleForProfile($profileId, $requiredRole, $tenantId)
 *
 * The CLI's synthetic token still carried the pre-cutover shape
 * (`user_id`/`role`), so every gated route answered `401 Invalid token payload`
 * and the entire CLI API surface was dead. A fabricated id would not help — the
 * store is consulted — so authorization needs a principal that genuinely holds
 * the role.
 *
 * WHY NOT REUSE THE SYSTEM ADMINISTRATOR (migration 036)
 * ------------------------------------------------------
 * Because `EnforceTenantIsolation` feeds the token's profile id into
 * {@see \Whity\Core\Audit\AuditContext}, so whatever the CLI authorizes as
 * becomes the AUDIT ACTOR. Borrowing a real administrator's id would attribute
 * every operator shell command to that person — a row that reads as a human
 * having done it, indistinguishable from one where they really did. That is
 * worse than the missing row #844 removed, and #931 anticipated this exact
 * principal as the way out.
 *
 * WHY IT HAS NO EMAIL
 * -------------------
 * `AuthHandler` resolves a login strictly through `profile_emails.email`. A
 * profile with no such row cannot be addressed by the login endpoint at all, so
 * unauthenticatability is structural here and not only a policy in code. The
 * `'service'` auth method is the second, independent layer: it refuses the
 * password write itself, so adding an email later still does not produce a
 * login.
 *
 * TENANT 0, NOT TENANT 1
 * ----------------------
 * The old token claimed `tenant_id: 1`, which `EnforceTenantIsolation` then
 * pins every request to — so `tenant update 5` would have been refused even
 * once the 401 was fixed. Tenant 0 is the platform-wide scope these commands
 * have always been intended to operate at, and it is the scope the system
 * administrator already holds.
 *
 * IDEMPOTENCY
 * -----------
 * Keyed on `auth_method = 'service'` (indexed by migration 104) rather than on
 * an email or a fixed id: the row's identity IS the held fact, so there is no
 * second convention to keep in step.
 */
final class SeedCliServicePrincipal
{
    /** Shown wherever the trail or an admin screen renders the actor. */
    public const DISPLAY_NAME = 'CLI service principal';

    public static function up(Database $db): void
    {
        $pdo = $db->getPdo();

        // @tenant-guard-ignore: profiles is a sanctioned GLOBAL identity table (ADR 0005 §1)
        $existing = $pdo->query("SELECT id FROM profiles WHERE auth_method = 'service' LIMIT 1");
        $row = $existing !== false ? $existing->fetch() : false;
        if (is_array($row) && isset($row['id'])) {
            self::ensureMembership($db, (int) $row['id']);

            return;
        }

        // password_hash '' is what the storage layer has always meant by "no
        // verifiable credential" (migration 104). It is belt to auth_method's
        // braces, not the mechanism: the refusal lives in AuthMethod.
        // @tenant-guard-ignore: profiles is a sanctioned GLOBAL identity table (ADR 0005 §1)
        $insert = $pdo->prepare(
            "INSERT INTO profiles (display_name, password_hash, auth_method, created_at, updated_at)
             VALUES (:name, '', 'service', NOW(), NOW())"
        );
        $insert->execute([':name' => self::DISPLAY_NAME]);

        // @tenant-guard-ignore: profiles is a sanctioned GLOBAL identity table (ADR 0005 §1)
        $created = $pdo->query("SELECT id FROM profiles WHERE auth_method = 'service' LIMIT 1");
        $createdRow = $created !== false ? $created->fetch() : false;
        if (!is_array($createdRow) || !isset($createdRow['id'])) {
            return;
        }

        self::ensureMembership($db, (int) $createdRow['id']);
    }

    /**
     * Grant the principal `admin` in the system tenant, once.
     *
     * Mirrors migration 036's membership shape. Split out so the re-run path
     * repairs a principal whose membership was removed rather than assuming the
     * two rows can only ever exist together.
     */
    private static function ensureMembership(Database $db, int $profileId): void
    {
        $pdo = $db->getPdo();

        // @tenant-guard-ignore: seed-time bootstrap; role lookup by name is global
        $roleRow = $pdo->query("SELECT id FROM roles WHERE name = 'admin' AND tenant_id IS NULL LIMIT 1");
        $role = $roleRow !== false ? $roleRow->fetch() : false;
        if (!is_array($role) || !isset($role['id'])) {
            // @tenant-guard-ignore: seed-time bootstrap; role lookup by name is global
            $fallback = $pdo->query("SELECT id FROM roles WHERE name = 'admin' LIMIT 1");
            $role = $fallback !== false ? $fallback->fetch() : false;
        }
        if (!is_array($role) || !isset($role['id'])) {
            return;
        }

        // Existence check rather than ON CONFLICT: migration 094 replaced the
        // table-wide UNIQUE(profile_id, tenant_id) with a PARTIAL index
        // (`WHERE is_primary`), which no bare conflict target matches. Asking
        // first is also driver-agnostic, which this migration needs to be.
        $check = $pdo->prepare(
            'SELECT 1 FROM memberships WHERE profile_id = :profile AND tenant_id = 0 LIMIT 1'
        );
        $check->execute([':profile' => $profileId]);
        if ($check->fetch() !== false) {
            return;
        }

        // Column list mirrors migration 036's membership insert. `is_primary`
        // is left to its DEFAULT TRUE: this is the principal's only membership,
        // so it is the row that answers "what is this principal here".
        $stmt = $pdo->prepare(
            "INSERT INTO memberships (profile_id, tenant_id, role_id, ou_id, status, created_at)
             VALUES (:profile, 0, :role, NULL, 'active', NOW())"
        );
        $stmt->execute([
            ':profile' => $profileId,
            ':role'    => (int) $role['id'],
        ]);
    }

    /**
     * Remove the principal and its membership.
     *
     * Safe to reverse: nothing references it except the CLI, which resolves it
     * by `auth_method` at startup and reports a clear error when it is absent —
     * rather than falling back to some other identity, which is the outcome
     * worth avoiding.
     */
    public static function down(Database $db): void
    {
        $pdo = $db->getPdo();

        // @tenant-guard-ignore: profiles is a sanctioned GLOBAL identity table (ADR 0005 §1)
        $stmt = $pdo->query("SELECT id FROM profiles WHERE auth_method = 'service'");
        $ids = $stmt !== false ? $stmt->fetchAll() : [];

        foreach (is_array($ids) ? $ids : [] as $row) {
            if (!is_array($row) || !isset($row['id'])) {
                continue;
            }
            $profileId = (int) $row['id'];

            $del = $pdo->prepare('DELETE FROM memberships WHERE profile_id = :id AND tenant_id = 0');
            $del->execute([':id' => $profileId]);

            // @tenant-guard-ignore: profiles is a sanctioned GLOBAL identity table (ADR 0005 §1)
            $delProfile = $pdo->prepare('DELETE FROM profiles WHERE id = :id');
            $delProfile->execute([':id' => $profileId]);
        }
    }
}
