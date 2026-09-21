<?php

declare(strict_types=1);

namespace Whity\Cli\Commands;

use Whity\Core\Payment\PaymentSecrets;
use Whity\Core\Security\EncryptedSecretStore;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Database\Database;

/**
 * `whity-cli payments:secret` — the webhook signing secrets for payment rails.
 *
 * WHY A CLI COMMAND AND NOT A SETTINGS FIELD. The settings API iterates
 * registry keys and hands their values back on `GET /settings`, so a secret
 * registered there could be read by anybody holding the settings permission —
 * which is not a secret. These live outside the registry, encrypted, and this
 * is how an operator reaches them. It is the same shape the SMTP password
 * already has, and following it means one arrangement to audit rather than two.
 *
 * NOTHING HERE EVER PRINTS A SECRET. `status` answers whether one is set and
 * readable, which is the only question about a secret that can be answered out
 * loud — an operator checking their configuration needs to know it is there,
 * and does not need it echoed into a terminal, a shell history or a CI log.
 *
 * SETTING A SECRET IS WHAT SWITCHES A RAIL ON. With none, the CliQ adapter
 * refuses every callback rather than trusting it, so `payments:secret set` is
 * the step between "the rail is configured" and "the rail can settle an
 * invoice". Clearing it is a complete stop, not a half-configured state.
 */
class PaymentsCommand extends BaseCommand implements CliCommand
{
    public function printHelp(string $commandName): bool
    {
        $this->showHelp();

        return true;
    }

    /** @return list<string>|null */
    public function knownFlags(): ?array
    {
        return [];
    }

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

        return match ($action) {
            'set' => $this->set($argv[1] ?? '', $argv[2] ?? ''),
            'clear' => $this->set($argv[1] ?? '', ''),
            'status' => $this->status(),
            default => $this->unknown($action),
        };
    }

    private function set(string $provider, string $secret): int
    {
        $key = PaymentSecrets::KEY_FOR_PROVIDER[$provider] ?? null;

        if ($key === null) {
            echo "Unknown payment rail: '{$provider}'.\n";
            echo 'Known rails: ' . implode(', ', array_keys(PaymentSecrets::KEY_FOR_PROVIDER)) . "\n";

            return 1;
        }

        [$globals, $secrets] = $this->stores();

        try {
            PaymentSecrets::write($globals, $secrets, $key, $secret);
        } catch (\InvalidArgumentException $e) {
            echo "Refused: {$e->getMessage()}\n";

            return 1;
        }

        if ($secret === '') {
            echo "Cleared the {$provider} signing secret. That rail will now refuse every callback.\n";

            return 0;
        }

        // The secret is never echoed. Its length is not sensitive and confirms
        // the argument arrived intact rather than truncated by a shell.
        echo sprintf(
            "Stored the %s signing secret (%d characters, encrypted at rest).\n",
            $provider,
            strlen($secret)
        );
        echo "Restart the workers for a running instance to pick it up.\n";

        return 0;
    }

    private function status(): int
    {
        [$globals, $secrets] = $this->stores();

        echo "Payment rail signing secrets\n\n";

        foreach (PaymentSecrets::KEY_FOR_PROVIDER as $provider => $key) {
            $configured = PaymentSecrets::isConfigured($globals, $secrets, $key);

            printf(
                "  %-6s %s\n",
                $provider,
                $configured
                    ? 'set and readable'
                    : 'NOT SET — this rail refuses every callback'
            );
        }

        echo "\nSecrets are never printed. A rail reporting 'set and readable' has a value\n";
        echo "that decrypts with the current key; one that does not is either unset or was\n";
        echo "encrypted with a key this instance no longer holds.\n";

        return 0;
    }

    private function unknown(string $action): int
    {
        echo "Unknown payments subcommand: {$action}\n";
        $this->showHelp();

        return 1;
    }

    /**
     * @return array{0: GlobalSettingsRepository, 1: EncryptedSecretStore}
     */
    private function stores(): array
    {
        $db = Database::connect();

        return [
            new GlobalSettingsRepository($db->getPdo()),
            EncryptedSecretStore::fromEnv($_ENV),
        ];
    }

    private function showHelp(): void
    {
        echo <<<HELP
        whity-cli payments:secret — webhook signing secrets for payment rails

        A rail with no signing secret REFUSES EVERY CALLBACK rather than trusting
        it, so setting one is the step between "configured" and "able to settle an
        invoice". Secrets are encrypted at rest and are never printed back.

        Subcommands:
          status                    Which rails have a readable secret. Prints no secrets.
          set <rail> <secret>       Store a rail's signing secret (16+ characters).
          clear <rail>              Remove it. That rail then refuses every callback.

        Rails: mock

        Example:
          whity-cli payments:secret set mock "\$(openssl rand -base64 32)"
          whity-cli payments:secret status

        After setting one on a running instance, restart the workers: the rails are
        built once at worker boot, so a live pool keeps the value it started with.

        HELP;
    }
}
