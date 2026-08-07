<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * AddLanguagePreferenceToProfiles migration
 *
 * Adds language_code column to profiles table for user's language preference.
 * A NULL language_code means the user will use the tenant's default language.
 * An explicit language_code means the user has opted for a specific language.
 */
class AddLanguagePreferenceToProfiles
{
    public static function up(Database $db): void
    {
        // Add language_code column if it doesn't exist
        $db->exec('
            ALTER TABLE profiles
            ADD COLUMN IF NOT EXISTS language_code VARCHAR(10) REFERENCES languages(code) ON DELETE SET NULL
        ');

        // Create index for performance on language lookups
        $db->exec('CREATE INDEX IF NOT EXISTS idx_profiles_language_code ON profiles(language_code)');
    }

    public static function down(Database $db): void
    {
        $db->exec('DROP INDEX IF EXISTS idx_profiles_language_code');
        $db->exec('ALTER TABLE profiles DROP COLUMN IF EXISTS language_code');
    }
}
