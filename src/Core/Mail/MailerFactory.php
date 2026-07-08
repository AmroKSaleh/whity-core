<?php

declare(strict_types=1);

namespace Whity\Core\Mail;

use Psr\Log\LoggerInterface;

/**
 * Selects the {@see Mailer} transport from configuration (WC-235).
 *
 * Reads MAIL_TRANSPORT from the environment:
 *   - unset / "null" / ""  → {@see NullMailer} (default; sends nothing)
 *   - "log"                → {@see LogMailer}  (writes to the PSR-3 logger)
 *
 * A real SMTP/API transport is a future addition: add a case here that
 * constructs it from its own env (host/key/etc.) and returns it. Until then an
 * unknown value falls back to Null (fail-safe) with a warning, so a typo can
 * never crash the worker on boot.
 */
final class MailerFactory
{
    /** Environment variable naming the transport. */
    public const ENV_TRANSPORT = 'MAIL_TRANSPORT';

    /**
     * @param array<string, mixed> $env Environment map (e.g. $_ENV).
     */
    public static function fromEnv(array $env, LoggerInterface $logger): Mailer
    {
        $transport = strtolower(trim((string) ($env[self::ENV_TRANSPORT] ?? '')));

        return match ($transport) {
            '', 'null' => new NullMailer(),
            'log'      => new LogMailer($logger),
            default    => self::unknown($transport, $logger),
        };
    }

    private static function unknown(string $transport, LoggerInterface $logger): Mailer
    {
        $logger->warning('[mail] unknown MAIL_TRANSPORT; falling back to no-op', [
            'transport' => $transport,
        ]);

        return new NullMailer();
    }
}
