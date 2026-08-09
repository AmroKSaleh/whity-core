<?php

declare(strict_types=1);

namespace Whity\Core\Observability;

/**
 * Redacts secrets and personal data before an error is stored or transmitted
 * (WC-error-tracking).
 *
 * Error payloads are the least-curated data in the system: an exception message
 * can contain a DSN, a bound SQL parameter, an email address, a JWT. This is a
 * MULTI-TENANT deployment whose tenant isolation is enforced by a CI gate, so an
 * unscrubbed error tracker is a cross-tenant exfiltration path — into a
 * third-party UI when the provider is remote. Scrubbing is therefore applied at
 * CAPTURE time, before anything is written or sent, and both providers go
 * through it. There is no "raw" path.
 *
 * The rules are deliberately blunt. A scrubber that tries to be clever about
 * what is safe will eventually be wrong in the direction that matters, and the
 * cost of over-redacting is a slightly less convenient stack trace.
 */
final class ErrorScrubber
{
    public const REDACTED = '[redacted]';

    /**
     * Context keys whose VALUE is dropped outright, matched case-insensitively
     * as a substring — `db_password`, `Authorization`, `smtp_pass` all match.
     */
    private const SENSITIVE_KEY_FRAGMENTS = [
        'password', 'passwd', 'secret', 'token', 'authorization', 'auth',
        'cookie', 'session', 'csrf', 'api_key', 'apikey', 'private',
        'credential', 'dsn', 'signature', 'encryption', 'jwt', 'bearer',
    ];

    /**
     * Value patterns redacted wherever they appear in free text (messages,
     * stack traces, and string context values).
     *
     * @var array<string, string> regex => replacement
     */
    private const VALUE_PATTERNS = [
        // Sentry-style DSN: the project key is a credential.
        '#\b[a-z][a-z0-9+.-]*://[^:\s/@]+(?::[^@\s/]+)?@[^\s/]+#i' => '[redacted-dsn]',
        // Bearer / JWT-shaped triplets.
        '#\beyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}#' => '[redacted-jwt]',
        // Email addresses — personal data, and frequently a tenant's user.
        '#[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}#' => '[redacted-email]',
        // Long opaque hex/base64 runs: keys, hashes, session ids.
        '#\b[A-Fa-f0-9]{32,}\b#' => '[redacted-hex]',
        // Anything that reads like `password=...` / `"secret": "..."` inline.
        '#((?:password|secret|token|api[_-]?key)["\']?\s*[:=]\s*["\']?)[^"\',;\s&)]+#i' => '$1[redacted]',
    ];

    /** Bound on any single stored string, so one enormous payload cannot bloat a row. */
    private const MAX_STRING = 4000;

    /** Bound on nesting, so a self-referential structure cannot spin. */
    private const MAX_DEPTH = 6;

    /**
     * Scrub a free-text string (exception message, stack trace).
     */
    public function text(string $value): string
    {
        foreach (self::VALUE_PATTERNS as $pattern => $replacement) {
            $result = preg_replace($pattern, $replacement, $value);
            // A regex failure must not silently pass the ORIGINAL through.
            $value = is_string($result) ? $result : self::REDACTED;
        }

        if (mb_strlen($value) > self::MAX_STRING) {
            $value = mb_substr($value, 0, self::MAX_STRING) . '… [truncated]';
        }

        return $value;
    }

    /**
     * Scrub a structured context array: sensitive KEYS lose their value
     * entirely, everything else is scrubbed by value.
     *
     * @param array<array-key, mixed> $context
     * @return array<array-key, mixed>
     */
    public function context(array $context, int $depth = 0): array
    {
        if ($depth >= self::MAX_DEPTH) {
            return ['[truncated]' => self::REDACTED];
        }

        $out = [];
        foreach ($context as $key => $value) {
            if (is_string($key) && $this->isSensitiveKey($key)) {
                $out[$key] = self::REDACTED;
                continue;
            }

            $out[$key] = match (true) {
                is_array($value) => $this->context($value, $depth + 1),
                is_string($value) => $this->text($value),
                is_scalar($value), $value === null => $value,
                // Objects/resources are not safely serialisable and may hold
                // anything; record only that something was there.
                default => '[object]',
            };
        }

        return $out;
    }

    private function isSensitiveKey(string $key): bool
    {
        $lower = strtolower($key);
        foreach (self::SENSITIVE_KEY_FRAGMENTS as $fragment) {
            if (str_contains($lower, $fragment)) {
                return true;
            }
        }

        return false;
    }
}
