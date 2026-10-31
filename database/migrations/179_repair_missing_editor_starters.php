<?php

declare(strict_types=1);

namespace Database\Migrations;

use PDO;
use Whity\Core\Document\DocumentSourceRepository;
use Whity\Core\Document\DocumentSourceStarterSeeder;
use Whity\Database\Database;

/**
 * RepairMissingEditorStarters (WHIT-803).
 *
 * Upgrading from 0.2.19 to 0.2.20 left every existing workspace without the
 * editor's starters (Letter, Invoice, Exam sheet, Production note, Shipping
 * labels, Certificate). Migration 165 seeds them through the document
 * repository, which in 0.2.20 also wrote a column migration 171 adds later;
 * the seeder caught the error and logged it, so the upgrade went on. 165 now
 * seeds strictly and the repository writes that column only when it is
 * given, but 165 has already run on those installs, so this puts the starters
 * back.
 *
 * It seeds only a workspace that has NO starter at all, which is what the
 * failed upgrade left. A workspace that has any (a fresh 0.2.20 workspace,
 * or one repaired by hand) is left exactly as it is. Strict: a failure stops
 * the upgrade instead of being logged.
 *
 * down() removes nothing: the starters are the workspace's documents now.
 */
class RepairMissingEditorStarters
{
    private const SYSTEM_TENANT_ID = 0;

    public static function up(Database $db): void
    {
        $pdo = $db->getPdo();
        $tenants = $pdo->prepare(
            'SELECT t.id FROM tenants t
              WHERE t.id <> :system
                AND NOT EXISTS (
                    SELECT 1 FROM document_sources s
                     WHERE s.tenant_id = t.id AND s.starter_key IS NOT NULL
                )
              ORDER BY t.id'
        );
        $tenants->execute([':system' => self::SYSTEM_TENANT_ID]);

        $starters = new DocumentSourceStarterSeeder(new DocumentSourceRepository($pdo));
        foreach ($tenants->fetchAll(PDO::FETCH_COLUMN) as $tenantId) {
            $starters->seedForTenant((int) $tenantId, strict: true);
        }
    }

    public static function down(Database $db): void
    {
    }
}
