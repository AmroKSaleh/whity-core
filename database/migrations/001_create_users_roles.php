<?php

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * CreateUsersRoles migration
 *
 * Creates tenants, roles, and users tables with tenant isolation support.
 * Includes indexes for performance and ON CONFLICT clauses for idempotent inserts.
 */
class CreateUsersRoles
{
    public static function up(Database $db): void
    {
        // Create tenants table
        $db->exec('
            CREATE TABLE IF NOT EXISTS tenants (
                id SERIAL PRIMARY KEY,
                name VARCHAR(255) NOT NULL UNIQUE,
                created_at TIMESTAMP NOT NULL DEFAULT NOW()
            )
        ');

        // Create roles table
        $db->exec('
            CREATE TABLE IF NOT EXISTS roles (
                id SERIAL PRIMARY KEY,
                name VARCHAR(255) NOT NULL UNIQUE,
                created_at TIMESTAMP NOT NULL DEFAULT NOW()
            )
        ');

        // Create users table with tenant_id and password columns
        $db->exec('
            CREATE TABLE IF NOT EXISTS users (
                id SERIAL PRIMARY KEY,
                tenant_id INTEGER NOT NULL REFERENCES tenants(id),
                email VARCHAR(255) NOT NULL,
                password VARCHAR(255) NOT NULL,
                role_id INTEGER NOT NULL REFERENCES roles(id),
                created_at TIMESTAMP NOT NULL DEFAULT NOW(),
                UNIQUE(tenant_id, email)
            )
        ');

        // Create indexes for performance
        $db->exec('CREATE INDEX IF NOT EXISTS idx_users_tenant_id ON users(tenant_id)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_users_role_id ON users(role_id)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_users_email ON users(email)');

        // Insert default roles (idempotent with ON CONFLICT)
        $db->query(
            'INSERT INTO roles (name, created_at) VALUES (:name, NOW()) ON CONFLICT (name) DO NOTHING',
            [':name' => 'admin']
        );

        $db->query(
            'INSERT INTO roles (name, created_at) VALUES (:name, NOW()) ON CONFLICT (name) DO NOTHING',
            [':name' => 'user']
        );
    }

    public static function down(Database $db): void
    {
        $db->exec('DROP TABLE IF EXISTS users CASCADE');
        $db->exec('DROP TABLE IF EXISTS roles CASCADE');
        $db->exec('DROP TABLE IF EXISTS tenants CASCADE');
    }
}
