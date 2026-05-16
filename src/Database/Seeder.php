<?php

namespace Whity\Database;

class Seeder
{
    public static function seed(Database $db): void
    {
        // Insert admin user
        $db->query(
            'INSERT INTO users (email, role_id, created_at) VALUES (:email, :role_id, NOW())',
            [
                ':email' => 'admin@example.com',
                ':role_id' => 1, // admin role
            ]
        );

        // Insert regular user
        $db->query(
            'INSERT INTO users (email, role_id, created_at) VALUES (:email, :role_id, NOW())',
            [
                ':email' => 'user@example.com',
                ':role_id' => 2, // user role
            ]
        );
    }
}
