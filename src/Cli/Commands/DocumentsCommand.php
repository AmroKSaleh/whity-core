<?php

declare(strict_types=1);

namespace Whity\Cli\Commands;

use Whity\Core\Document\DocumentSourceRepository;
use Whity\Core\Document\DocumentSourceStarterSeeder;
use Whity\Core\Document\DocumentTemplateRepository;
use Whity\Core\Document\Legacy\LegacyTemplateMigrator;
use Whity\Database\Database;

/**
 * `whity-cli documents:convert-legacy [--tenant=ID] [--dry-run]`
 *
 * Brings existing tenants onto the document editor (editor M4b): adds the
 * editor's starters each tenant doesn't have yet, and copies each of its old
 * Document designer templates into an editor document once. New tenants get
 * the starters when they are provisioned; this is for the ones that already
 * existed. Safe to run again: only what is new is added. The old templates
 * are not changed.
 */
final class DocumentsCommand implements CommandHelp, CliCommand
{
    private const TENANT_FLAG = '--tenant';
    private const DRY_RUN_FLAG = '--dry-run';

    /** The platform's own tenant, which has no documents of its own. */
    private const SYSTEM_TENANT_ID = 0;

    public function printHelp(string $commandName): bool
    {
        echo "Bring existing tenants onto the document editor.\n\n";
        echo "Usage:\n";
        echo "  whity-cli documents:convert-legacy [" . self::TENANT_FLAG . "=ID] [" . self::DRY_RUN_FLAG . "]\n\n";
        echo "Adds the editor's starters a tenant doesn't have, and copies each old Document\n";
        echo "designer template into an editor document once, as a draft. Starters Whity seeded\n";
        echo "and nobody edited are not copied. The old templates are not changed.\n\n";
        echo "Options:\n";
        echo "  " . self::TENANT_FLAG . "=ID   Only this tenant (default: every tenant but the system tenant).\n";
        echo "  " . self::DRY_RUN_FLAG . "     Report what would be converted; write nothing.\n";

        return true;
    }

    /** @return list<string> */
    public function knownFlags(): ?array
    {
        return [self::TENANT_FLAG, self::DRY_RUN_FLAG, '--help', '-h'];
    }

    public function execute(array $argv): int
    {
        $dryRun = in_array(self::DRY_RUN_FLAG, $argv, true);
        $only = null;
        foreach ($argv as $arg) {
            if (str_starts_with($arg, self::TENANT_FLAG . '=')) {
                $value = substr($arg, strlen(self::TENANT_FLAG) + 1);
                if (!ctype_digit($value)) {
                    fwrite(STDERR, self::TENANT_FLAG . " must be a tenant id\n");

                    return 1;
                }
                $only = (int) $value;
            }
        }

        $pdo = Database::connect()->getPdo();
        $sources = new DocumentSourceRepository($pdo);
        $starters = new DocumentSourceStarterSeeder($sources);
        $migrator = new LegacyTemplateMigrator(new DocumentTemplateRepository($pdo), $sources);

        $stmt = $only !== null
            ? $pdo->prepare('SELECT id, name FROM tenants WHERE id = :id')
            : $pdo->prepare('SELECT id, name FROM tenants WHERE id <> :system ORDER BY id');
        $stmt->execute($only !== null ? [':id' => $only] : [':system' => self::SYSTEM_TENANT_ID]);
        $tenants = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        if ($tenants === []) {
            fwrite(STDERR, "No tenant found\n");

            return 1;
        }

        $failed = 0;
        foreach ($tenants as $tenant) {
            $id = (int) $tenant['id'];
            $added = $dryRun ? count(array_diff_key($starters->starters(), $sources->starterKeys($id))) : $starters->seedForTenant($id);
            $summary = $migrator->migrateTenant($id, $dryRun);
            $failed += $summary['failed'];
            $left = $summary['dropped'] === []
                ? ''
                : ' (left out: ' . implode(', ', array_map(static fn (string $k, int $n): string => "{$k} {$n}", array_keys($summary['dropped']), $summary['dropped'])) . ')';
            printf(
                "%stenant %d %s: %d starters, %d templates converted%s, %d skipped, %d failed\n",
                $dryRun ? '[dry run] ' : '',
                $id,
                (string) $tenant['name'],
                $added,
                $summary['converted'],
                $left,
                $summary['skipped'],
                $summary['failed'],
            );
        }

        return $failed > 0 ? 1 : 0;
    }
}
