<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\BootstrapIdentity;
use Whity\Database\Database;

/**
 * ApplyBootstrapAdminEmail — move the bootstrap administrator to the address
 * the operator configured (WC-779).
 *
 * Migrations 010 and 036 hardcoded `system@whity.local`, so every install —
 * production ones included — arrives with a live administrator credential at a
 * vendor-named, unroutable `.local` address that can never receive a password
 * reset or a verification mail.
 *
 * Why a NEW migration rather than an edit to 010/036
 * ──────────────────────────────────────────────────
 * Those two have already run on every existing database and will never run
 * again there, so editing them would move nothing for any real install while
 * changing the migration set that the schema fingerprint (and any operator
 * diffing core) is pinned to. The address is instead reconciled forward, once,
 * by this migration — which is what makes `migrate run` ALONE honour
 * INITIAL_SYSTEM_ADMIN_EMAIL, and `migrate run` alone is exactly the path that
 * produces the bootstrap administrator on a real install (the seeder is a
 * separate, optional step).
 *
 * Behaviour
 * ─────────
 *  - INITIAL_SYSTEM_ADMIN_EMAIL unset (or naming the historical default):
 *    a strict no-op. An existing install upgrading past this migration keeps
 *    system@whity.local exactly as it was.
 *  - Set to a valid address: the EXISTING profile_emails row is renamed, so
 *    the bootstrap administrator keeps its profile, its credential, and its
 *    tenant-0 admin membership. No second account is created.
 *  - Set to an address another identity already holds: reported and skipped —
 *    colliding two accounts to honour an environment variable is worse than
 *    leaving the rename undone.
 *
 * The decision logic lives in {@see BootstrapIdentity} rather than here because
 * {@see \Whity\Database\Seeder} calls the same reconciler: an operator who only
 * decides to rename AFTER this migration has already run (as a no-op) can set
 * the variable and re-run `seed` instead of being told to UPDATE rows by hand.
 *
 * Reversible: down() moves the account back to the historical default, again
 * only when that address is free.
 */
class ApplyBootstrapAdminEmail
{
    public static function up(Database $db): void
    {
        BootstrapIdentity::applyConfiguredEmail($db);
    }

    public static function down(Database $db): void
    {
        BootstrapIdentity::revertConfiguredEmail($db);
    }
}
