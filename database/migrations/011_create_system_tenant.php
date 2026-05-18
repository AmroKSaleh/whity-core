<?php

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * Create System Tenant
 *
 * Creates a special system tenant with ID 0 for master admins.
 * System tenant users can see and manage all tenants and OUs.
 */
class CreateSystemTenant
{
    public static function up(Database $db): void
    {
        // Insert system tenant with explicit ID 0
        // PostgreSQL allows INSERT with explicit serial value if we use setval to update sequence
        $db->exec('ALTER SEQUENCE tenants_id_seq RESTART WITH 0');

        $db->query(
            'INSERT INTO tenants (id, name, created_at) VALUES (0, :name, NOW()) ON CONFLICT DO NOTHING',
            [':name' => 'System']
        );

        // Reset sequence to start at 1 for normal tenants
        $db->exec('ALTER SEQUENCE tenants_id_seq RESTART WITH 1');

        // Create system admin user
        $adminPassword = password_hash('system_admin_123', PASSWORD_BCRYPT);
        $db->query(
            'INSERT INTO users (tenant_id, email, password, role_id, created_at)
             VALUES (0, :email, :password, 1, NOW())
             ON CONFLICT (tenant_id, email) DO NOTHING',
            [
                ':email' => 'system@whity.local',
                ':password' => $adminPassword,
            ]
        );
    }

    public static function down(Database $db): void
    {
        // Delete system admin user
        $db->exec('DELETE FROM users WHERE tenant_id = 0');

        // Delete system tenant
        $db->exec('DELETE FROM tenants WHERE id = 0');

        // Reset sequence
        $db->exec('ALTER SEQUENCE tenants_id_seq RESTART WITH 1');
    }
}
