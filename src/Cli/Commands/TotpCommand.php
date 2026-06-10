<?php

declare(strict_types=1);

namespace Whity\Cli\Commands;

use Whity\Auth\TotpSecretReencryptor;
use Whity\Auth\TotpService;
use Whity\Database\Database;

/**
 * `whity-cli totp` — TOTP secret maintenance.
 *
 * Subcommands:
 *   reencrypt   Re-encrypt legacy AES-256-CBC TOTP secrets into the current
 *               authenticated-encryption format (WC-158). Idempotent — safe to re-run.
 */
class TotpCommand extends BaseCommand
{
    /**
     * @param array<int, string> $argv
     */
    public function execute(array $argv): int
    {
        $action = $argv[0] ?? 'help';

        if ($action === 'help' || $action === '--help' || $action === '-h') {
            $this->showHelp();
            return 0;
        }

        if ($action !== 'reencrypt') {
            echo "Unknown totp subcommand: {$action}\n";
            $this->showHelp();
            return 1;
        }

        $key = TotpService::resolveEncryptionKey();
        $reencryptor = new TotpSecretReencryptor(new TotpService($key), $key);
        $stats = $reencryptor->reencrypt(Database::connect()->getPdo());

        echo sprintf(
            "TOTP secret re-encryption complete: %d migrated, %d skipped, %d failed.\n",
            $stats['migrated'],
            $stats['skipped'],
            $stats['failed']
        );

        return $stats['failed'] > 0 ? 1 : 0;
    }

    private function showHelp(): void
    {
        echo "Usage: whity-cli totp <subcommand>\n\n";
        echo "Subcommands:\n";
        echo "  reencrypt   Re-encrypt legacy AES-256-CBC TOTP secrets to authenticated encryption (idempotent)\n";
    }
}
