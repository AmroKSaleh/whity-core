<?php

namespace Database\Migrations;

use Whity\Database\Database;

class CreateUsersRoles
{
    public static function up(Database $db): void
    {
        // Create roles table
        $db->exec('
            CREATE TABLE IF NOT EXISTS roles (
                id SERIAL PRIMARY KEY,
                name VARCHAR(255) NOT NULL UNIQUE,
                created_at TIMESTAMP NOT NULL DEFAULT NOW()
            )
        ');

        // Create users table
        $db->exec('
            CREATE TABLE IF NOT EXISTS users (
                id SERIAL PRIMARY KEY,
                email VARCHAR(255) NOT NULL UNIQUE,
                role_id INTEGER NOT NULL REFERENCES roles(id),
                created_at TIMESTAMP NOT NULL DEFAULT NOW()
            )
        ');

        // Insert default roles
        $db->query(
            'INSERT INTO roles (name, created_at) VALUES (:name, NOW())',
            [':name' => 'admin']
        );

        $db->query(
            'INSERT INTO roles (name, created_at) VALUES (:name, NOW())',
            [':name' => 'user']
        );
    }

    public static function down(Database $db): void
    {
        $db->exec('DROP TABLE IF EXISTS users CASCADE');
        $db->exec('DROP TABLE IF EXISTS roles CASCADE');
    }
}
