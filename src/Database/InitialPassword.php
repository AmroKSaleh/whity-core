<?php

declare(strict_types=1);

namespace Whity\Database;

/**
 * Resolves initial (bootstrap) account passwords for seeding and migrations.
 *
 * Initial passwords for the default/seed accounts are sourced from environment
 * variables so that no installation ships with a known, hardcoded credential.
 * When the relevant environment variable is absent, a cryptographically random
 * password is generated and printed ONCE to stdout so a fresh install remains
 * recoverable. There is intentionally NO static literal fallback.
 *
 * Recognised environment variables:
 * - INITIAL_ADMIN_PASSWORD         seed admin (admin@example.com)
 * - INITIAL_USER_PASSWORD          seed regular user (user@example.com)
 * - INITIAL_SYSTEM_ADMIN_PASSWORD  system-tenant admin (system@whity.local)
 * - INITIAL_SUPERUSER_PASSWORD     system-tenant superuser (superuser@example.com)
 */
final class InitialPassword
{
    /**
     * Number of random bytes used when generating a fallback password.
     * 16 bytes => a 32-character hex string, comfortably above the 16-char minimum.
     */
    private const RANDOM_BYTES = 16;

    /**
     * Resolve a bcrypt hash for an initial account password.
     *
     * The plaintext is taken from the given environment variable when present and
     * non-empty; otherwise a cryptographically random password is generated and
     * announced once on stdout (labelled with the account) so the operator can
     * capture it. The returned value is always a bcrypt hash, never plaintext.
     *
     * @param string $envVar      Environment variable to read the plaintext from.
     * @param string $accountLabel Human-readable account label used in the
     *                             "generated password" notice (e.g. an email).
     * @return string Bcrypt password hash suitable for storage.
     */
    public static function hashFor(string $envVar, string $accountLabel): string
    {
        return password_hash(self::resolvePlaintext($envVar, $accountLabel), PASSWORD_BCRYPT);
    }

    /**
     * Resolve the plaintext password for an initial account.
     *
     * Prefers the environment variable; when it is missing or empty, generates a
     * random password and prints it once to stdout. Never returns a static literal.
     *
     * @param string $envVar      Environment variable to read from.
     * @param string $accountLabel Account label used in the generated-password notice.
     * @return string Plaintext password.
     */
    public static function resolvePlaintext(string $envVar, string $accountLabel): string
    {
        $configured = $_ENV[$envVar] ?? getenv($envVar);
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        $generated = bin2hex(random_bytes(self::RANDOM_BYTES));
        self::announceGeneratedPassword($envVar, $accountLabel, $generated);

        return $generated;
    }

    /**
     * Print a one-time notice of a generated password so a fresh install is
     * recoverable. Written to stdout (and error_log for container stderr).
     *
     * @param string $envVar       Environment variable that would override this value.
     * @param string $accountLabel Account the password belongs to.
     * @param string $password     The generated plaintext password.
     * @return void
     */
    private static function announceGeneratedPassword(string $envVar, string $accountLabel, string $password): void
    {
        $message = sprintf(
            "[whity] No %s set; generated initial password for %s: %s (store it now; set %s to choose your own)",
            $envVar,
            $accountLabel,
            $password,
            $envVar
        );

        // Echo to the active output stream so an operator running the seeder/
        // migration interactively sees it (and it is captured under output
        // buffering), and error_log so it also reaches the container's stderr.
        echo $message . "\n";
        error_log($message);
    }
}
