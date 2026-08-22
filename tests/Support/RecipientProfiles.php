<?php

declare(strict_types=1);

namespace Tests\Support;

use PDO;

/**
 * Seeds the profile rows the notification fixtures address.
 *
 * #751 gave `notifications.recipient_profile_id` and
 * `user_notification_preferences.profile_id` a real foreign key to `profiles`.
 * Before that, a fixture could name profile 101 without one existing — which is
 * precisely the orphan state the constraint now forbids, so the fixtures have
 * to create the person they are notifying.
 *
 * A shared helper rather than a copy in each setUp: six test classes address the
 * same handful of ids, and six hand-rolled INSERTs would drift the moment the
 * `profiles` column list changes again (it has, four times — migrations 080,
 * 083, 084, 104).
 */
final class RecipientProfiles
{
    /**
     * The ids the notification fixtures use as recipients.
     *
     * Seeding the whole set rather than per-file lists is deliberate: which ids
     * a given test file happens to name is not interesting, and a per-file list
     * is one more thing to keep in step with the test that uses it.
     *
     * @var list<int>
     */
    public const IDS = [101, 202, 303, 404, 999];

    /**
     * Insert the recipient profiles, ignoring any that already exist.
     *
     * @param list<int>|null $ids Defaults to {@see IDS}.
     */
    public static function seed(PDO $pdo, ?array $ids = null): void
    {
        $statement = $pdo->prepare(
            'INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled,
                                   two_factor_backup_codes_version, token_epoch, created_at, updated_at)
             VALUES (:id, :name, :hash, false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)'
        );

        foreach ($ids ?? self::IDS as $id) {
            $statement->execute([
                ':id' => $id,
                ':name' => 'recipient-' . $id,
                ':hash' => 'test-hash',
            ]);
        }

        // These are EXPLICIT ids. PostgreSQL's sequence does not move when one
        // is supplied, so without this the next id-less INSERT hands back a
        // number already taken and the test dies on a duplicate key having
        // proven nothing.
        SchemaFromMigrations::syncSequences($pdo);
    }
}
