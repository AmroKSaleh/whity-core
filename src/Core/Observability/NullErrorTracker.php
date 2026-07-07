<?php

declare(strict_types=1);

namespace Whity\Core\Observability;

/**
 * No-op {@see ErrorTracker} — the default until an error-tracking DSN is
 * configured (WC-d). Errors are still written to the log by the existing
 * uncaught-exception handlers; this simply does not forward them anywhere.
 *
 * Selecting the concrete provider is {@see ErrorTrackerFactory}'s job, so
 * flipping error tracking on is purely a configuration change.
 */
final class NullErrorTracker implements ErrorTracker
{
    public function captureException(\Throwable $e, array $context = []): void
    {
        // Intentionally does nothing.
    }
}
