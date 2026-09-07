<?php

declare(strict_types=1);

namespace Whity\Core\Payment\Cliq;

/**
 * The keys under which payment-rail shared secrets live.
 *
 * THEY ARE DELIBERATELY NOT {@see \Whity\Core\Settings\SettingsRegistry} KEYS.
 * The settings API iterates registry keys, so anything registered there is
 * readable through `GET /settings` by whoever holds the settings permission —
 * and a shared secret that can be read back over an API is not a shared secret.
 * It is the exact arrangement {@see \Whity\Core\Mail\MailerFactory} uses for the
 * SMTP password, for the same reason, and following it rather than inventing a
 * second scheme means one place to audit rather than two.
 *
 * The values live in `app_settings` and are encrypted at rest by
 * `EncryptedSecretStore`. Nothing here reads them; this class exists only so
 * that the key strings are written down once, next to an explanation, instead
 * of being string literals in the wiring where their absence from the registry
 * would look like an oversight.
 */
final class CliqSecrets
{
    /**
     * The HMAC secret a CliQ settlement callback is signed with.
     *
     * When it is empty the rail refuses EVERY callback rather than trusting
     * them — see {@see CliqPaymentProvider::translateWebhook()}. That is the
     * safe direction: an instance quietly accepting anything posted to its
     * callback URL loses money to whoever finds it, while one that refuses is
     * noticed the same day.
     */
    public const WEBHOOK_SECRET_KEY = 'payments.cliq.webhook_secret_encrypted';

    /**
     * The fake rail's signing secret, so the development mock behaves like a
     * real provider rather than accepting anything.
     */
    public const MOCK_SECRET_KEY = 'payments.mock.webhook_secret_encrypted';
}
