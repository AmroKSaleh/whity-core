<?php

declare(strict_types=1);

namespace Whity\Core\Observability;

/**
 * Selects the active {@see ErrorTracker} from configuration (WC-d).
 *
 * Error tracking is OFF by default: with no DSN configured the app runs with
 * {@see NullErrorTracker} (uncaught errors still hit the log). Setting
 * ERROR_TRACKER_DSN (or SENTRY_DSN) activates it — the concrete provider is
 * wired here once its SDK dependency is installed (the activation step). Until
 * then a configured-but-unwired DSN fails SAFE to Null (logged once) so a
 * half-configured deployment can never break the request pipeline.
 */
final class ErrorTrackerFactory
{
    /**
     * @param array<string, mixed> $env The environment ($_ENV).
     */
    public static function fromEnv(array $env): ErrorTracker
    {
        $dsn = $env['ERROR_TRACKER_DSN'] ?? $env['SENTRY_DSN'] ?? '';
        $dsn = is_string($dsn) ? trim($dsn) : '';

        if ($dsn === '') {
            return new NullErrorTracker();
        }

        // Concrete provider selection lands here when the error-tracker SDK is
        // installed (WC-d activation, gated on the DSN being provided). A string
        // class-name check keeps this forward-compatible without a hard compile
        // dependency on the not-yet-added provider class.
        $provider = 'Whity\\Core\\Observability\\SentryErrorTracker';
        if (class_exists($provider)) {
            /** @var ErrorTracker $tracker */
            $tracker = new $provider($dsn);
            return $tracker;
        }

        error_log('[error-tracker] DSN configured but no provider installed — running without error tracking. Install the provider SDK to activate (WC-d).');
        return new NullErrorTracker();
    }
}
