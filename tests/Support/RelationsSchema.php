<?php

declare(strict_types=1);

namespace Tests\Support;

use PDO;

/**
 * Shared real-engine (in-memory SQLite) schema builder for the WC-65 family
 * relations tests.
 *
 * The schema mirrors the WC-65 migrations (018/019/020) closely enough to
 * exercise the real SQL the repositories/resolver emit, and turns on
 * `PDO::ATTR_STRINGIFY_FETCHES` so integer/boolean columns come back as PHP
 * STRINGS exactly as PostgreSQL's PDO driver returns them — the int-vs-string
 * trap the project's real-engine tests exist to catch. A `NOW()` UDF stands in
 * for PostgreSQL's NOW() (SQLite has none).
 *
 * The seeded relationship-type vocabulary matches the migration:
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
     * Build a fresh in-memory SQLite PDO with the relations schema + seeded
     * vocabulary, plus tenants/users/roles/permissions for resolution + RBAC.
     */
    public static function make(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        // Mirror PostgreSQL: integers/booleans come back as strings.
        $pdo->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, true);
        $pdo->sqliteCreateFunction('NOW', static fn (): string => date('Y-m-d H:i:s'), 0);
        // Honour the relations FK cascade so person-delete cascade is real.
        $pdo->exec('PRAGMA foreign_keys = ON');

        // Tenants: 0 system, 1 tenant-a, 2 tenant-b.
        $pdo->exec('CREATE TABLE tenants (id INTEGER PRIMARY KEY, name TEXT)');
        $pdo->exec("INSERT INTO tenants (id, name) VALUES (0,'system'),(1,'tenant-a'),(2,'tenant-b')");

        // RBAC tables (for the integration test driving RbacMiddleware).
        $pdo->exec('CREATE TABLE roles (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL UNIQUE, parent_id INTEGER, tenant_id INTEGER, created_at TEXT)');
        $pdo->exec("INSERT INTO roles (id, name, created_at) VALUES (1,'admin',NOW()),(2,'user',NOW())");
        $pdo->exec('CREATE TABLE permissions (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL UNIQUE, description TEXT, created_at TEXT)');
        $pdo->exec('CREATE TABLE role_permissions (id INTEGER PRIMARY KEY AUTOINCREMENT, role_id INTEGER NOT NULL, permission_id INTEGER NOT NULL, created_at TEXT, UNIQUE(role_id, permission_id))');
        // OU tables are referenced by RoleChecker's resolution; keep them present.
        $pdo->exec('CREATE TABLE organizational_units (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, parent_id INTEGER, name TEXT NOT NULL, slug TEXT, created_at TEXT)');
        $pdo->exec('CREATE TABLE ou_role_assignments (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, ou_id INTEGER NOT NULL, role_id INTEGER NOT NULL, created_at TEXT, UNIQUE(ou_id, role_id))');

        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, email TEXT NOT NULL, password TEXT NOT NULL, role_id INTEGER, ou_id INTEGER, created_at TEXT)');

        // WC-65 tables.
        $pdo->exec('
            CREATE TABLE persons (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id INTEGER NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                display_name TEXT NOT NULL,
                user_id INTEGER NULL UNIQUE REFERENCES users(id) ON DELETE SET NULL,
                birth_date TEXT NULL,
                deceased INTEGER NOT NULL DEFAULT 0,
                notes TEXT NULL,
                created_at TEXT
            )
        ');
        $pdo->exec('
            CREATE TABLE relationship_types (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL UNIQUE,
                inverse_type_id INTEGER NULL REFERENCES relationship_types(id),
                symmetric INTEGER NOT NULL DEFAULT 0,
                created_at TEXT
            )
        ');
        $pdo->exec('
            CREATE TABLE relations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id INTEGER NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                from_person_id INTEGER NOT NULL REFERENCES persons(id) ON DELETE CASCADE,
                to_person_id INTEGER NOT NULL REFERENCES persons(id) ON DELETE CASCADE,
                relationship_type_id INTEGER NOT NULL REFERENCES relationship_types(id),
                created_at TEXT,
                UNIQUE (tenant_id, from_person_id, to_person_id, relationship_type_id)
            )
        ');

        // Seed the vocabulary with the same ids/inverses the migration produces.
        $pdo->exec("INSERT INTO relationship_types (id, name, symmetric, created_at) VALUES
            (1,'Parent',0,NOW()),
            (2,'Child',0,NOW()),
            (3,'Spouse',1,NOW()),
            (4,'Sibling',1,NOW())");
        $pdo->exec('UPDATE relationship_types SET inverse_type_id = 2 WHERE id = 1');
        $pdo->exec('UPDATE relationship_types SET inverse_type_id = 1 WHERE id = 2');
        $pdo->exec('UPDATE relationship_types SET inverse_type_id = 3 WHERE id = 3');
        $pdo->exec('UPDATE relationship_types SET inverse_type_id = 4 WHERE id = 4');

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
