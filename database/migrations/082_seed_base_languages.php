<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * SeedBaseLanguages migration
 *
 * Seeds the base languages (English and Arabic) into the languages table.
 *
 * This is a data-only migration that inserts the two foundational languages
 * used across the whity platform. The idempotent ON CONFLICT handling ensures
 * this migration is safe to replay (e.g. for database replication or re-init).
 *
 * The down() removes only the rows we know we inserted (by code), leaving any
 * other languages intact (respect the principle that down() should be reversible
 * without data loss if there are other rows).
 */
class SeedBaseLanguages
{
    public static function up(Database $db): void
    {
        // Seed English language (idempotent via ON CONFLICT).
        $db->query(
            'INSERT INTO languages (code, name, enabled, created_at, updated_at)
             VALUES (:code, :name, true, NOW(), NOW())
             ON CONFLICT (code) DO NOTHING',
            [
                ':code' => 'en',
                ':name' => 'English',
            ]
        );

        // Seed Arabic language (idempotent via ON CONFLICT).
        $db->query(
            'INSERT INTO languages (code, name, enabled, created_at, updated_at)
             VALUES (:code, :name, true, NOW(), NOW())
             ON CONFLICT (code) DO NOTHING',
            [
                ':code' => 'ar',
                ':name' => 'العربية',
            ]
        );
    }

    public static function down(Database $db): void
    {
        // Remove the seeded languages by code.
        // Only delete if they exist; this is safe if other languages were added.
        $db->query(
            'DELETE FROM languages WHERE code IN (?, ?)',
            ['en', 'ar']
        );
    }
}
