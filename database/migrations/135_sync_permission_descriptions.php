<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Core\RBAC\CorePermissionDescriptions;
use Whity\Database\Database;

/**
 * SyncPermissionDescriptions — replace the generated filler in the permissions
 * catalogue with what each permission actually does.
 *
 * THE SYMPTOM. On a freshly-migrated database, 43 of 63 permissions carried
 * `Core permission (groups:read)` — the slug, restated. That text is rendered
 * and searched in the role editor, so the one string whose entire job is to
 * explain a grant was explaining nothing, at exactly the moment somebody was
 * deciding whether to hand it out.
 *
 * THE CAUSE, AND WHY IT GETS WORSE RATHER THAN BETTER. Migration 013 seeds the
 * whole of `CorePermissions::all()` with placeholder text — and it reads that
 * list from TODAY's class, not from the catalogue as it stood when 013 was
 * written. So 013 pre-creates permissions invented years after it, filler and
 * all. Every later migration that carries the real description then inserts
 * `ON CONFLICT (name) DO NOTHING`, finds the row already present, and throws its
 * good text away.
 *
 * The consequence runs backwards: an OLD installation kept the real descriptions
 * it was given at the time, and a NEW one gets filler for everything. The fresh
 * install is the degraded case, which is why this went unnoticed — nobody
 * re-reads the permission list on a database they have been running for a year.
 *
 * WHY THIS OVERWRITES, WHERE MOST SEEDING MIGRATIONS DO NOT. `ON CONFLICT DO
 * NOTHING` is right whenever the database might hold something a person put
 * there. Here nothing can: `permissions.description` has no write path anywhere
 * outside migrations — no API route updates it, no handler, no seeder — so there
 * is no human edit to protect and no tenant customisation to preserve. The code
 * is simply authoritative, and the row should match it.
 *
 * That is the difference from #1057, which solved the same shape for
 * translations by recording row provenance (`source_managed`): a translation CAN
 * be edited by a person, so a re-sync has to know which rows it owns. Adding
 * that machinery here would be protecting an edit that cannot happen.
 *
 * SCOPE. Core permissions only. A plugin's permissions are described by the
 * plugin that declares them, and are left untouched — the map keys are the
 * catalogue, so a slug that is not in it is never named in an UPDATE.
 *
 * IDEMPOTENT. Running it twice writes the same text twice. Re-runnable by
 * design: it is the mechanism that keeps the catalogue honest, not a one-off
 * repair, and {@see \Tests\Unit\RBAC\CorePermissionDescriptionsTest} fails the
 * build if a new permission ships without text for it to write.
 */
final class SyncPermissionDescriptions
{
    public static function up(Database $db): void
    {
        foreach (CorePermissionDescriptions::all() as $slug => $description) {
            // Matched on `name`, which is UNIQUE. A slug absent from this
            // database affects zero rows rather than creating one: inserting
            // here would put a permission into the catalogue that no migration
            // declared, and the catalogue's membership is not this file's to
            // decide — only its prose is.
            $db->query(
                'UPDATE permissions SET description = :description WHERE name = :name',
                [':description' => $description, ':name' => $slug]
            );
        }
    }

    /**
     * Deliberately does nothing.
     *
     * The only honest inverse would be to write `Core permission (x)` back over
     * every row — restoring filler, which is the defect. A down() that recreates
     * a bug to satisfy symmetry is worse than one that declines: rolling this
     * migration back leaves the descriptions accurate, and nothing downstream
     * reads them for anything but display.
     */
    public static function down(Database $db): void
    {
    }
}
