<?php

declare(strict_types=1);

namespace Whity\Database;

/**
 * Seeder class for database initialization
 *
 * Seeds default tenant, roles, and users with hashed passwords.
 * All inserts use ON CONFLICT for idempotent execution.
 *
 * Initial user passwords are sourced from the INITIAL_ADMIN_PASSWORD,
 * INITIAL_USER_PASSWORD and INITIAL_SUPERUSER_PASSWORD environment variables;
 * when unset, a random password is generated and printed once
 * (see {@see InitialPassword}). No static default.
 *
 * In addition to the two default-tenant accounts, the seeder provisions a
 * system-tenant (id 0) superuser (superuser@example.com) holding the admin role.
 * Unlike the default-tenant admin, a system-tenant admin may manage the global
 * base roles (NULL-tenant roles) and every tenant — see WC-110/WC-223.
 */
class Seeder
{
    /**
     * Seed the database with default data
     *
     * @param Database $db Database connection instance
     * @return void
     */
    public static function seed(Database $db): void
    {
        // Create default tenant if not exists
        $db->query(
            'INSERT INTO tenants (name, created_at) VALUES (:name, NOW()) ON CONFLICT (name) DO NOTHING',
            [':name' => 'Default Tenant']
        );

        // Fetch the tenant ID
        $tenantResult = $db->query(
            'SELECT id FROM tenants WHERE name = :name',
            [':name' => 'Default Tenant']
        );
        $tenant = $tenantResult->fetch();
        $tenantId = $tenant['id'] ?? 1;

        // Get role IDs dynamically
        // @tenant-guard-ignore: seed-time bootstrap resolves global default role ids by name; no tenant context exists during seeding
        $adminRoleResult = $db->query(
            'SELECT id FROM roles WHERE name = :name',
            [':name' => 'admin']
        );
        $adminRole = $adminRoleResult->fetch();
        $adminRoleId = $adminRole['id'] ?? 1;

        // @tenant-guard-ignore: seed-time bootstrap resolves global default role ids by name; no tenant context exists during seeding
        $userRoleResult = $db->query(
            'SELECT id FROM roles WHERE name = :name',
            [':name' => 'user']
        );
        $userRole = $userRoleResult->fetch();
        $userRoleId = $userRole['id'] ?? 2;

        // Hash passwords. Sourced from env (INITIAL_ADMIN_PASSWORD /
        // INITIAL_USER_PASSWORD) or a one-time random value — never a static literal.
        $adminPassword = InitialPassword::hashFor('INITIAL_ADMIN_PASSWORD', 'admin@example.com');
        $userPassword = InitialPassword::hashFor('INITIAL_USER_PASSWORD', 'user@example.com');
        $superuserPassword = InitialPassword::hashFor('INITIAL_SUPERUSER_PASSWORD', 'superuser@example.com');

        // Insert admin user (idempotent with ON CONFLICT)
        $db->query(
            'INSERT INTO users (tenant_id, email, password, role_id, created_at)
             VALUES (:tenant_id, :email, :password, :role_id, NOW())
             ON CONFLICT (tenant_id, email) DO NOTHING',
            [
                ':tenant_id' => $tenantId,
                ':email' => 'admin@example.com',
                ':password' => $adminPassword,
                ':role_id' => $adminRoleId,
            ]
        );

        // Insert regular user (idempotent with ON CONFLICT)
        $db->query(
            'INSERT INTO users (tenant_id, email, password, role_id, created_at)
             VALUES (:tenant_id, :email, :password, :role_id, NOW())
             ON CONFLICT (tenant_id, email) DO NOTHING',
            [
                ':tenant_id' => $tenantId,
                ':email' => 'user@example.com',
                ':password' => $userPassword,
                ':role_id' => $userRoleId,
            ]
        );

        // Insert system-tenant superuser (idempotent with ON CONFLICT).
        // Tenant id 0 is the system tenant (created by migration
        // 010_create_system_tenant, which runs before seeding); a system-tenant
        // admin may manage global base roles and every tenant (WC-110/WC-223).
        // @tenant-guard-ignore: seed-time bootstrap; system-tenant (0) superuser
        $db->query(
            'INSERT INTO users (tenant_id, email, password, role_id, created_at)
             VALUES (0, :email, :password, :role_id, NOW())
             ON CONFLICT (tenant_id, email) DO NOTHING',
            [
                ':email' => 'superuser@example.com',
                ':password' => $superuserPassword,
                ':role_id' => $adminRoleId,
            ]
        );
    }
}
