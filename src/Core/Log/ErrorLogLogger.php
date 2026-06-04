<?php

declare(strict_types=1);

namespace Whity\Core\Log;

use Psr\Log\AbstractLogger;
use Stringable;

/**
 * Minimal PSR-3 logger that writes structured records to PHP's `error_log`.
 *
 * Provides a real PSR-3 sink for bootstrap-time wiring (tenant audit logging via
 * {@see \Whity\Core\Tenant\TenantContext::setLogger()}, cross-tenant bypass
 * auditing in {@see \Whity\Http\Middleware\EnforceTenantIsolation}, and plugin
 * error boundaries) without pulling in a heavyweight logging dependency. In the
 * FrankenPHP/Docker deployment `error_log` lands in the container's stderr, where
 * the platform's log aggregation collects it.
 *
 * Records are emitted as a single line: `[level] message {json-context}`. The
 * PSR-3 `{placeholder}` interpolation contract is honoured so message templates
 * referencing context keys are expanded.
 */
final class ErrorLogLogger extends AbstractLogger
{
    /**
     * Log a record at an arbitrary level.
     *
     * @param mixed                $level   The PSR-3 log level.
     * @param string|Stringable    $message The log message (may contain `{key}` placeholders).
     * @param array<string, mixed> $context Structured context; interpolated into the message
     *                                       and appended as JSON.
     * @return void
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $levelLabel = is_string($level) ? $level : (string) json_encode($level);
        $interpolated = $this->interpolate((string) $message, $context);

        $line = sprintf('[%s] %s', $levelLabel, $interpolated);

        if ($context !== []) {
            $encoded = json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($encoded !== false) {
                $line .= ' ' . $encoded;
            }
        }

        error_log($line);
    }

    /**
     * Interpolate PSR-3 `{key}` placeholders using scalar/Stringable context values.
     *
     * Non-scalar context values are left in place (the full context is still
     * appended as JSON), per the PSR-3 recommendation.
     *
     * @param string               $message The message template.
     * @param array<string, mixed> $context The context values.
     * @return string The interpolated message.
     */
    private function interpolate(string $message, array $context): string
    {
        if ($message === '' || !str_contains($message, '{')) {
            return $message;
        }

        $replacements = [];
        foreach ($context as $key => $value) {
            if (is_scalar($value) || $value instanceof Stringable || $value === null) {
                $replacements['{' . $key . '}'] = (string) $value;
            }
        }

        return strtr($message, $replacements);
    }
}
