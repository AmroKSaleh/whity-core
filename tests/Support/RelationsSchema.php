<?php

declare(strict_types=1);

namespace Tests\Support;

use PDO;

/**
 * Shared real-engine (in-memory SQLite) schema builder for the WC-65 family
 * relations tests.
 *
 * Delegates schema creation to {@see SchemaFromMigrations::make()} so the
 * table structure always matches the production migration files. ATTR_STRINGIFY_FETCHES
 * is turned on so integer/boolean columns come back as PHP STRINGS exactly as
 * PostgreSQL's PDO driver returns them — the int-vs-string trap the project's
 * real-engine tests exist to catch.
 *
 * The seeded relationship-type vocabulary is provided by migration 019:
 *   1 Parent  ↔ 2 Child   (directed inverses)
 *   3 Spouse  (symmetric self-inverse)
 *   4 Sibling (symmetric self-inverse)
 */
final class RelationsSchema
{
    public const TYPE_PARENT = 1;
    public const TYPE_CHILD = 2;
    public const TYPE_SPOUSE = 3;
    public const TYPE_SIBLING = 4;

    /**
     * Build a fresh in-memory SQLite PDO with the full production schema applied
     * (via SchemaFromMigrations) plus the test-specific tenants and FK enforcement.
     */
    public static function make(): PDO
    {
        // SchemaFromMigrations::make(true) registers NOW(), opens sqlite::memory:,
        // runs all migrations (which seed roles admin/user, permissions, and the
        // relationship-type vocabulary), and enables ATTR_STRINGIFY_FETCHES.
        $pdo = SchemaFromMigrations::make(true);

        // Honour the relations FK cascade so person-delete cascade is real.
        $pdo->exec('PRAGMA foreign_keys = ON');

        // Migration 010 inserts the system tenant (id=0). Add the two additional
        // test tenants that the relations tests depend on.
        $pdo->exec("INSERT OR IGNORE INTO tenants (id, name) VALUES (1,'tenant-a'),(2,'tenant-b')");

        return $pdo;
    }

    /**
     * Insert a profile + profile_email + membership and return the profile id.
     *
     * Replaces the old seedUser() (which inserted into the legacy `users` table).
     * The resolver now resolves `kind:'profile'` via profiles + memberships, so
     * tests seed the profile identity model directly.
     */
    public static function seedProfile(PDO $pdo, int $tenantId, string $email, int $roleId = 2): int
    {
        // Create the profile.
        $pdo->prepare(
            "INSERT INTO profiles (display_name, password_hash, two_factor_enabled, token_epoch, created_at, updated_at)
             VALUES (?, 'x', false, 0, NOW(), NOW())"
        )->execute([strstr($email, '@', true) ?: $email]);
        $profileId = (int) $pdo->lastInsertId();

        // Attach the email as primary.
        $pdo->prepare(
            'INSERT INTO profile_emails (profile_id, email, verified, is_primary, created_at)
             VALUES (?, ?, true, true, NOW())'
        )->execute([$profileId, $email]);

        // Create an active membership in the tenant so the resolver finds the
        // profile when scoped to that tenant.
        $pdo->prepare(
            "INSERT OR IGNORE INTO memberships (profile_id, tenant_id, role_id, ou_id, status, created_at)
             VALUES (?, ?, ?, NULL, 'active', NOW())"
        )->execute([$profileId, $tenantId, $roleId]);

        return $profileId;
    }

    /**
     * Insert a non-account person and return its id.
     */
    public static function seedPerson(PDO $pdo, int $tenantId, string $displayName): int
    {
        // `deceased` is BOOLEAN — use false so the literal is accepted by both
        // PostgreSQL (strict boolean typing) and SQLite (stores as 0).
        $pdo->prepare('INSERT INTO persons (tenant_id, display_name, profile_id, deceased, created_at) VALUES (?, ?, NULL, false, NOW())')
            ->execute([$tenantId, $displayName]);

        return (int) $pdo->lastInsertId();
    }
}
