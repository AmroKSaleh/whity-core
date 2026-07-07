<?php

declare(strict_types=1);

namespace Whity\Core\Observability;

/**
 * Captures an uncaught exception to an external error-tracking service
 * (Sentry / GlitchTip / equivalent) — WC-d error tracking.
 *
 * This is the platform-neutral seam (mirrors the email-verification seam):
 * the composition root wires {@see NullErrorTracker} by default and swaps in a
 * concrete provider once ERROR_TRACKER_DSN is configured, without touching the
 * request pipeline. The uncaught-exception handlers in public/index.php call
 * captureException() with a secret-free context (release, tenant_id, request_id)
 * so a captured event is attributable and correlatable to the request log.
 *
 * Implementations MUST be exception-safe: a tracker failure must never mask the
 * original error or break the 500 response path.
 */
interface ErrorTracker
{
    /**
     * Report an uncaught throwable.
     *
     * @param \Throwable           $e       The uncaught exception.
     * @param array<string, mixed> $context Secret-free tags — e.g. release,
     *                                       tenant_id, request_id. Never include
     *                                       credentials or request bodies.
     */
    public function captureException(\Throwable $e, array $context = []): void;
}
