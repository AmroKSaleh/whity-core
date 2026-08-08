<?php

declare(strict_types=1);

namespace Whity\Core\Observability;

use PDO;
use Throwable;

/**
 * Selects the active {@see ErrorTracker} (WC-d, extended by WC-error-tracking).
 *
 * Two configuration sources, in this order:
 *
 *  1. SETTINGS — what an operator chose in the admin UI. `error_tracking.enabled`
 *     is the master switch, `error_tracking.provider` picks `internal` (this
 *     deployment's own database, no extra infrastructure) or `sentry` (any
 *     Sentry-PROTOCOL backend: hosted Sentry, or a self-hosted GlitchTip /
 *     Bugsink), and the DSN comes from the ENCRYPTED reserved key.
 *
 *  2. ENV — `ERROR_TRACKER_DSN` / `SENTRY_DSN`, kept as the bootstrap path. It
 *     still matters: it works before the database is reachable and before anyone
 *     has opened the admin UI, which is exactly when early-boot failures happen,
 *     and it lets an immutable/sovereign deployment configure everything from
 *     the environment without touching settings at all.
 *
 * Error tracking remains OFF by default. Every failure mode here degrades to
 * {@see NullErrorTracker} — an error tracker that breaks the request pipeline
 * would be worse than no error tracker.
 */
final class ErrorTrackerFactory
{
    /**
     * Env-only construction (no database yet). Used at early boot and by
     * deployments that configure purely from the environment.
     *
     * @param array<string, mixed> $env The environment ($_ENV).
     */
    public static function fromEnv(array $env): ErrorTracker
    {
        $dsn = $env['ERROR_TRACKER_DSN'] ?? $env['SENTRY_DSN'] ?? '';
        $dsn = is_string($dsn) ? trim($dsn) : '';

        if ($dsn === '') {
            return new NullErrorTracker();
        }

        $environment = $env['APP_ENV'] ?? null;

        return new SentryErrorTracker(
            $dsn,
            new ErrorScrubber(),
            is_string($environment) ? $environment : null
        );
    }

    /**
     * Settings-aware construction, used once the database is available.
     *
     * @param callable(string): ?string $setting   Reads a global setting by key.
     * @param callable(string): ?string $decrypt   Decrypts the stored DSN ciphertext.
     * @param array<string, mixed>      $env       Fallback when settings say nothing.
     * @param callable(int, string): void|null $alerter Called for new/regressed errors.
     */
    public static function fromSettings(
        PDO $pdo,
        callable $setting,
        callable $decrypt,
        array $env = [],
        ?callable $alerter = null,
    ): ErrorTracker {
        try {
            $config = self::resolve($setting, $decrypt, $env);

            if ($config->isInternal()) {
                return new InternalErrorTracker(
                    new ErrorGroupRepository($pdo),
                    new ErrorScrubber(),
                    $config->environment,
                    $config->notifyAdmins ? $alerter : null,
                );
            }

            if ($config->isSentry()) {
                return new SentryErrorTracker(
                    (string) $config->dsn,
                    new ErrorScrubber(),
                    $config->environment
                );
            }

            // Settings are off, but an env DSN is still honoured — otherwise
            // turning the admin toggle off would silently disable a deployment
            // that never used the admin UI in the first place.
            return self::fromEnv($env);
        } catch (Throwable $e) {
            error_log('[error-tracker] configuration failed, running without error tracking: ' . $e->getMessage());

            return new NullErrorTracker();
        }
    }

    /**
     * @param callable(string): ?string $setting
     * @param callable(string): ?string $decrypt
     * @param array<string, mixed>      $env
     */
    public static function resolve(callable $setting, callable $decrypt, array $env = []): ErrorTrackerConfig
    {
        $enabled = self::truthy($setting(SettingKeys::ENABLED));
        $provider = (string) ($setting(SettingKeys::PROVIDER) ?? 'internal');

        $dsn = null;
        if ($provider === 'sentry') {
            $ciphertext = $setting(ErrorTrackerConfig::DSN_SETTING_KEY);
            if (is_string($ciphertext) && $ciphertext !== '') {
                // A DSN that will not decrypt (rotated-away key, corrupt value)
                // must not take the whole tracker down with it.
                try {
                    $dsn = $decrypt($ciphertext);
                } catch (Throwable) {
                    error_log('[error-tracker] stored DSN could not be decrypted; falling back');
                    $dsn = null;
                }
            }
            if ($dsn === null || $dsn === '') {
                $envDsn = $env['ERROR_TRACKER_DSN'] ?? $env['SENTRY_DSN'] ?? '';
                $dsn = is_string($envDsn) && $envDsn !== '' ? $envDsn : null;
            }
        }

        $environment = $setting(SettingKeys::ENVIRONMENT);
        if ($environment === null || $environment === '') {
            $envName = $env['APP_ENV'] ?? null;
            $environment = is_string($envName) ? $envName : null;
        }

        $retention = (int) ($setting(SettingKeys::RETENTION_DAYS) ?? '90');

        return new ErrorTrackerConfig(
            enabled: $enabled,
            provider: $provider,
            dsn: $dsn,
            environment: $environment,
            notifyAdmins: self::truthy($setting(SettingKeys::NOTIFY_ADMINS)),
            retentionDays: $retention > 0 ? $retention : 90,
        );
    }

    private static function truthy(?string $value): bool
    {
        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }
}
