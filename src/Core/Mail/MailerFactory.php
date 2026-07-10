<?php

declare(strict_types=1);

namespace Whity\Core\Mail;

use Psr\Log\LoggerInterface;
use Whity\Core\Security\EncryptedSecretStore;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;

/**
 * Selects the {@see Mailer} transport (WC-235 / WC-email).
 *
 * Two sources:
 *   - {@see fromEnv()} — the boot-time fallback driven by MAIL_TRANSPORT
 *     ('' / 'null' → Null, 'log' → Log). SMTP is NOT built from env: it needs
 *     the settings-backed config below.
 *   - {@see fromSettings()} — the real, operator-configured transport read from
 *     the GLOBAL instance settings (`mail.*`). This is what the app uses.
 *
 * Fail-safe throughout: an unknown/misconfigured transport degrades to
 * {@see NullMailer} with a warning rather than crashing the worker on boot —
 * email is a non-critical side channel.
 */
final class MailerFactory
{
    /** Environment variable naming the transport (legacy/boot fallback). */
    public const ENV_TRANSPORT = 'MAIL_TRANSPORT';

    /**
     * The app_settings key holding the SMTP password, encrypted at rest. It is
     * deliberately NOT a {@see SettingsRegistry} key: it lives in `app_settings`
     * but never surfaces on the typed settings API (which only iterates registry
     * keys), so the secret cannot leak through GET /settings.
     */
    public const SMTP_PASSWORD_SETTING_KEY = 'mail.smtp.password_encrypted';

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

    /**
     * Build the transport from GLOBAL instance settings (`mail.*`).
     *
     * `mail.transport` selects the backend:
     *   - 'none' (default) → {@see NullMailer} (email disabled).
     *   - 'log'            → {@see LogMailer}.
     *   - 'smtp'           → {@see SmtpMailer} from `mail.smtp.*` + `mail.from_*`,
     *                        with the password decrypted from the encrypted-secret
     *                        store.
     *
     * A 'smtp' selection with missing host/from-address degrades to Null (with a
     * warning): a half-configured server must not crash boot nor throw on every
     * send. A password that fails to decrypt is treated as "no password" (warned),
     * so a key-rotation mishap can't wedge the worker.
     */
    public static function fromSettings(
        SettingsService $settings,
        GlobalSettingsRepository $globals,
        EncryptedSecretStore $secrets,
        LoggerInterface $logger,
    ): Mailer {
        // Reading the settings hits the database. A failure here (transient DB
        // outage, a migration lagging behind a deploy) must NOT propagate — email
        // is best-effort, so degrade to a no-op rather than break the caller. This
        // is what makes it safe to (re)build the mailer on every send.
        try {
            $global = $settings->getGlobal();
        } catch (\Throwable $e) {
            $logger->warning('[mail] could not read mail settings; email disabled for this send: ' . $e->getMessage());

            return new NullMailer();
        }

        $transport = (string) ($global[SettingsRegistry::MAIL_TRANSPORT] ?? 'none');

        if ($transport === 'none') {
            return new NullMailer();
        }
        if ($transport === 'log') {
            return new LogMailer($logger);
        }
        if ($transport !== 'smtp') {
            return self::unknown($transport, $logger);
        }

        $host = trim((string) ($global[SettingsRegistry::MAIL_SMTP_HOST] ?? ''));
        $fromEmail = trim((string) ($global[SettingsRegistry::MAIL_FROM_ADDRESS] ?? ''));

        if ($host === '' || $fromEmail === '') {
            $logger->warning('[mail] transport is "smtp" but host/from_address is not configured; email disabled', [
                'has_host' => $host !== '',
                'has_from' => $fromEmail !== '',
            ]);

            return new NullMailer();
        }

        $username = trim((string) ($global[SettingsRegistry::MAIL_SMTP_USERNAME] ?? ''));
        $password = self::decryptPassword($globals, $secrets, $logger);

        $config = new SmtpConfig(
            host: $host,
            port: (int) ($global[SettingsRegistry::MAIL_SMTP_PORT] ?? '587'),
            fromEmail: $fromEmail,
            fromName: trim((string) ($global[SettingsRegistry::MAIL_FROM_NAME] ?? '')),
            encryption: (string) ($global[SettingsRegistry::MAIL_SMTP_ENCRYPTION] ?? 'tls'),
            username: $username !== '' ? $username : null,
            password: $password,
        );

        return new SmtpMailer($config);
    }

    /**
     * Decrypt the stored SMTP password, or null when unset. A decryption failure
     * (e.g. missing rotation key) is logged and treated as "no password" rather
     * than propagated — email stays best-effort.
     */
    private static function decryptPassword(
        GlobalSettingsRepository $globals,
        EncryptedSecretStore $secrets,
        LoggerInterface $logger,
    ): ?string {
        $stored = $globals->get(self::SMTP_PASSWORD_SETTING_KEY);
        if ($stored === null || $stored === '') {
            return null;
        }

        try {
            return $secrets->decrypt($stored);
        } catch (\RuntimeException) {
            // Never log the ciphertext or the underlying crypto detail.
            $logger->warning('[mail] stored SMTP password could not be decrypted; sending without auth password');

            return null;
        }
    }

    private static function unknown(string $transport, LoggerInterface $logger): Mailer
    {
        $logger->warning('[mail] unknown mail transport; falling back to no-op', [
            'transport' => $transport,
        ]);

        return new NullMailer();
    }
}
