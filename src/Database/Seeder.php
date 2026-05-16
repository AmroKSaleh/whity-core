<?php

namespace Whity\Database;

/**
 * Seeder class for database initialization
 *
 * Seeds default tenant, roles, and users with hashed passwords.
 * All inserts use ON CONFLICT for idempotent execution.
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
        $adminRoleResult = $db->query(
            'SELECT id FROM roles WHERE name = :name',
            [':name' => 'admin']
        );
        $adminRole = $adminRoleResult->fetch();
        $adminRoleId = $adminRole['id'] ?? 1;

        $userRoleResult = $db->query(
            'SELECT id FROM roles WHERE name = :name',
            [':name' => 'user']
        );
        $userRole = $userRoleResult->fetch();
        $userRoleId = $userRole['id'] ?? 2;

        // Hash passwords
        $adminPassword = password_hash('admin123', PASSWORD_BCRYPT);
        $userPassword = password_hash('user123', PASSWORD_BCRYPT);

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
    }
}
