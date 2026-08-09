<?php

declare(strict_types=1);

namespace Whity\Core\Observability;

use Throwable;

/**
 * The built-in, zero-infrastructure error tracker (WC-error-tracking).
 *
 * Writes into the app's own database ({@see ErrorGroupRepository}) and surfaces
 * in the admin error inbox. No extra container, no external service, nothing
 * leaves the deployment — the option for operators who want error tracking
 * without running Sentry's cluster or trusting a third party with exception
 * text from a multi-tenant system.
 *
 * FAIL-SAFE ABOVE ALL. This runs on the error path, frequently while something
 * is already broken. It must never throw, never block, and never turn a handled
 * 500 into a fatal — so every step is guarded and failure degrades to the error
 * log, which is where the exception was going anyway.
 *
 * Alerting is decided here but PERFORMED elsewhere: a new or regressed error
 * enqueues a durable job rather than sending mail inline. Talking to SMTP inside
 * a failing request would add latency exactly when the system is least able to
 * afford it, and a broken mail server would then break error capture too.
 */
final class InternalErrorTracker implements ErrorTracker
{
    public function __construct(
        private readonly ErrorGroupRepository $groups,
        private readonly ErrorScrubber $scrubber,
        private readonly ?string $environment = null,
        /** Invoked with the group id when operators should be alerted; null disables alerting. */
        private readonly mixed $alerter = null,
    ) {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function captureException(Throwable $e, array $context = []): void
    {
        try {
            $scrubbedMessage = $this->scrubber->text($e->getMessage());

            $outcome = $this->groups->record(
                ErrorFingerprint::of($e),
                $e::class,
                $scrubbedMessage,
                $this->scrubber->text($e->getFile()),
                $e->getLine(),
                $this->scrubber->text($e->getTraceAsString()),
                $this->scrubber->context($context),
                $this->environment,
            );

            // Only the transitions a human needs to know about. A repeat of a
            // known, still-open error emails nobody: alert fatigue is how real
            // alerts stop being read.
            if ($this->alerter !== null && in_array($outcome['outcome'], ['new', 'regressed'], true)) {
                ($this->alerter)($outcome['id'], $outcome['outcome']);
            }
        } catch (Throwable $inner) {
            error_log(
                '[error-tracker] internal capture failed: ' . $inner->getMessage()
                . ' (while handling: ' . $e::class . ')'
            );
        }
    }
}
