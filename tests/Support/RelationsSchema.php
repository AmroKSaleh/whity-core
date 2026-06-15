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
     * Insert a user and return its id.
     */
    public static function seedUser(PDO $pdo, int $tenantId, string $email, int $roleId = 2): int
    {
        $pdo->prepare('INSERT INTO users (tenant_id, email, password, role_id, ou_id, created_at) VALUES (?, ?, ?, ?, NULL, NOW())')
            ->execute([$tenantId, $email, 'x', $roleId]);

        return (int) $pdo->lastInsertId();
    }

    /**
     * Insert a non-user person and return its id.
     */
    public static function seedPerson(PDO $pdo, int $tenantId, string $displayName): int
    {
        $pdo->prepare('INSERT INTO persons (tenant_id, display_name, user_id, deceased, created_at) VALUES (?, ?, NULL, 0, NOW())')
            ->execute([$tenantId, $displayName]);

        return (int) $pdo->lastInsertId();
    }
}
