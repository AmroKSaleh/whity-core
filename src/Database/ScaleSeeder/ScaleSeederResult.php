<?php

declare(strict_types=1);

namespace Whity\Database\ScaleSeeder;

/**
 * Actual counts produced by a completed {@see ScaleSeeder::run()}.
 *
 * Distinguishes "newly inserted this run" from "already existed" (the latter
 * happens when re-running the same seed/parameters against a DB that already
 * has this exact scale-seeded dataset — every insert is ON-CONFLICT-guarded,
 * so a rerun is idempotent and reports the pre-existing rows as `reused`
 * rather than duplicating them).
 */
final class ScaleSeederResult
{
    public function __construct(
        public int $tenantsCreated = 0,
        public int $tenantsReused = 0,
        public int $ousCreated = 0,
        public int $ousReused = 0,
        public int $customRolesCreated = 0,
        public int $customRolesReused = 0,
        public int $usersCreated = 0,
        public int $usersReused = 0,
        public int $personsCreated = 0,
        public int $personsReused = 0,
        public int $relationsCreated = 0,
        public int $relationsReused = 0,
    ) {
    }
}
