<?php

declare(strict_types=1);

namespace Whity\Core\Payment\Cliq;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Whity\Core\Security\EncryptedSecretStore;
use Whity\Core\Settings\GlobalSettingsRepository;

/**
 * The payment rails' shared secrets: where they live, and how they are read.
 *
 * THEY ARE DELIBERATELY NOT {@see \Whity\Core\Settings\SettingsRegistry} KEYS.
 * The settings API iterates registry keys, so anything registered there is
 * readable through `GET /settings` by whoever holds the settings permission —
 * and a shared secret that can be read back over an API is not a shared secret.
 * This is the arrangement {@see \Whity\Core\Mail\MailerFactory} uses for the
 * SMTP password, and following it rather than inventing a second scheme means
 * one place to audit rather than two.
 *
 * WHAT WAS WRONG WITH THE FIRST VERSION, AND WHY IT IS WORTH RECORDING. This
 * class originally held nothing but two key strings and a docblock claiming
 * they were "encrypted at rest by EncryptedSecretStore". Nothing encrypted
 * them, nothing decrypted them, and the wiring read the raw stored value — so a
 * deployment that followed the docblock and stored ciphertext would have had
 * every signature check fail, while one that stored plaintext would have worked
 * with a secret sitting in the clear. There was also no way to SET either
 * value: no API, no CLI, no seeder. The rail could not be configured at all,
 * and the comment describing how it was protected was the only evidence that
 * anybody had thought about it.
 *
 * A docblock is not a mechanism. {@see self::read()} and {@see self::write()}
 * are, and `whity-cli payments:secret` is how an operator reaches them.
 */
final class CliqSecrets
{
    /**
     * The HMAC secret a CliQ settlement callback is signed with.
     *
     * When it is absent the rail refuses EVERY callback rather than trusting
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

    /** The provider names `whity-cli payments:secret` accepts. */
    public const KEY_FOR_PROVIDER = [
        'cliq' => self::WEBHOOK_SECRET_KEY,
        'mock' => self::MOCK_SECRET_KEY,
    ];

    /**
     * Read a rail's secret, decrypting it.
     *
     * RETURNS AN EMPTY STRING WHEN UNSET OR UNREADABLE, and that is the whole
     * safety property: the CliQ adapter treats an empty secret as "refuse every
     * callback". So a missing key, a rotated-away encryption key, or a value
     * somebody wrote by hand in the wrong format all converge on the rail
     * refusing to settle anything — never on it accepting an unverified
     * payload.
     *
     * The failure is logged without the ciphertext or the crypto detail. An
     * operator needs to know the secret could not be read; nobody needs the
     * bytes in a log file.
     */
    public static function read(
        GlobalSettingsRepository $globals,
        EncryptedSecretStore $secrets,
        string $key,
        ?LoggerInterface $logger = null,
    ): string {
        $stored = $globals->get($key);

        if ($stored === null || $stored === '') {
            return '';
        }

        try {
            return $secrets->decrypt($stored);
        } catch (\RuntimeException) {
            ($logger ?? new NullLogger())->warning(
                '[payments] a stored rail secret could not be decrypted; that rail will refuse every callback',
                ['key' => $key]
            );

            return '';
        }
    }

    /**
     * Store a rail's secret, encrypted.
     *
     * Passing an empty secret CLEARS it, which is how a rail is turned off
     * properly: with no secret it refuses every callback, so clearing is a
     * complete stop rather than a half-configured state.
     *
     * @throws \InvalidArgumentException When the secret is too weak to be one.
     */
    public static function write(
        GlobalSettingsRepository $globals,
        EncryptedSecretStore $secrets,
        string $key,
        string $secret,
    ): void {
        if ($secret === '') {
            $globals->delete($key);

            return;
        }

        // A short shared secret is not a shared secret. HMAC's strength is the
        // key's entropy, and a memorable one is guessable offline at leisure by
        // anybody who has seen one signed payload — which, for a webhook, is
        // anybody who can post to the endpoint and observe the outcome.
        if (strlen($secret) < 16) {
            throw new \InvalidArgumentException(
                'A webhook signing secret must be at least 16 characters. HMAC is only as strong '
                . 'as its key, and a short one is guessable offline by anyone who has seen a '
                . 'signed payload.'
            );
        }

        $globals->set($key, $secrets->encrypt($secret));
    }

    /**
     * Whether a rail's secret is set and readable — WITHOUT returning it.
     *
     * What an operator checking their configuration actually needs, and the
     * only question about a secret that can be answered out loud.
     */
    public static function isConfigured(
        GlobalSettingsRepository $globals,
        EncryptedSecretStore $secrets,
        string $key,
    ): bool {
        return self::read($globals, $secrets, $key) !== '';
    }
}
