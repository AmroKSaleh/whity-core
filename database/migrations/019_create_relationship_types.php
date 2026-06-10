<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * CreateRelationshipTypes migration (WC-65 — Family Relations Management System)
 *
 * Creates and seeds the `relationship_types` vocabulary (ADR 0002). A
 * relationship type names a kind of edge (Parent, Child, Spouse, Sibling) and
 * carries the two columns that drive reciprocal derivation at read time:
 *
 *  - `inverse_type_id` — the type the edge reads as from the OTHER endpoint.
 *    "Alice Parent-of Bob" is stored once; listing Bob's relations flips it
 *    through Parent's inverse (Child) → "Bob Child-of Alice". This is why the
 *    feature stores ONE row per relationship and never persists both directions.
 *  - `symmetric` — true when the type is its own inverse (Spouse, Sibling), so it
 *    reads identically from either end.
 *
 * Seeded set (fixed for v1):
 *   Parent  ↔ Child   (directed inverses: Parent.inverse = Child, Child.inverse = Parent)
 *   Spouse  ↔ Spouse  (symmetric self-inverse)
 *   Sibling ↔ Sibling (symmetric self-inverse)
 *
 * The `name` column is UNIQUE, so the seed is idempotent via ON CONFLICT and the
 * inverse wiring (a second pass that resolves names → ids) is safe to re-run.
 * The `inverse_type_id`/`symmetric` columns leave room for tenant-custom types
 * later without a schema change (out of scope for v1).
 *
 * Additive, idempotent and reversible: down() drops the table (CASCADE).
 */
class CreateRelationshipTypes
{
    /**
     * The seeded vocabulary: name => [symmetric, inverse name].
     *
     * @var array<string, array{symmetric: bool, inverse: string}>
     */
    private const SEED = [
        'Parent' => ['symmetric' => false, 'inverse' => 'Child'],
        'Child' => ['symmetric' => false, 'inverse' => 'Parent'],
        'Spouse' => ['symmetric' => true, 'inverse' => 'Spouse'],
        'Sibling' => ['symmetric' => true, 'inverse' => 'Sibling'],
    ];

    public static function up(Database $db): void
    {
        // The relationship-type vocabulary. inverse_type_id self-references the
        // table; it is nullable so the seed can insert rows first and wire the
        // inverses in a second pass (Parent and Child reference each other).
        $db->exec('
            CREATE TABLE IF NOT EXISTS relationship_types (
                id SERIAL PRIMARY KEY,
                name VARCHAR(64) NOT NULL UNIQUE,
                inverse_type_id INTEGER NULL REFERENCES relationship_types(id) ON DELETE SET NULL,
                is_symmetric BOOLEAN NOT NULL DEFAULT false,
                created_at TIMESTAMP NOT NULL DEFAULT NOW()
            )
        ');

        // Pass 1: seed the rows (idempotent on the UNIQUE name).
        foreach (self::SEED as $name => $meta) {
            $db->query(
                'INSERT INTO relationship_types (name, is_symmetric, created_at)
                 VALUES (:name, :is_symmetric, NOW())
                 ON CONFLICT (name) DO NOTHING',
                [':name' => $name, ':is_symmetric' => $meta['symmetric'] ? 1 : 0]
            );
        }

        // Pass 2: wire inverse_type_id by name now that every row exists. Safe to
        // re-run — it simply re-sets each pointer to the same id.
        foreach (self::SEED as $name => $meta) {
            $db->query(
                'UPDATE relationship_types
                 SET inverse_type_id = (SELECT id FROM relationship_types WHERE name = :inverse)
                 WHERE name = :name',
                [':inverse' => $meta['inverse'], ':name' => $name]
            );
        }
    }

    public static function down(Database $db): void
    {
        // Break the self-referencing inverse pointers first so dropping the table
        // can never trip an FK on engines that check on DROP, then drop it.
        $db->exec('UPDATE relationship_types SET inverse_type_id = NULL');
        $db->exec('DROP TABLE IF EXISTS relationship_types CASCADE');
    }
}
