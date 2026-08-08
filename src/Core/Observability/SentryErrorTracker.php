<?php

declare(strict_types=1);

namespace Whity\Core\Observability;

use Throwable;

/**
 * Ships exceptions to any Sentry-PROTOCOL backend (WC-error-tracking).
 *
 * Works with hosted Sentry, and equally with a self-hosted Sentry-compatible
 * server (GlitchTip, Bugsink) — they accept the same DSN and store endpoint. So
 * this one provider covers both "remote subscription" and "I run my own bigger
 * error tracker", and switching between them is a DSN change with no code
 * change at all.
 *
 * NO SDK DEPENDENCY, deliberately. `sentry/sentry` brings a PSR-7/PSR-18 HTTP
 * stack and a large surface for what {@see ErrorTracker} actually asks for: one
 * method, captureException. The store endpoint is a documented JSON POST, so the
 * whole integration is the ~40 lines below. That keeps the dependency audit's
 * result intact — this repo owns its small things — at the cost of the SDK's
 * extras (performance tracing, breadcrumbs, release health), none of which the
 * interface exposes.
 *
 * FAIL-SAFE: never throws, and never blocks a request for long. A short timeout
 * is essential — the tracker runs while something is already broken, and an
 * unreachable error backend must not add seconds to every failing request.
 */
final class SentryErrorTracker implements ErrorTracker
{
    /** Deliberately short: a slow error backend must not amplify an incident. */
    private const TIMEOUT_SECONDS = 3;

    private ?string $storeUrl = null;
    private ?string $publicKey = null;

    public function __construct(
        string $dsn,
        private readonly ?ErrorScrubber $scrubber = null,
        private readonly ?string $environment = null,
    ) {
        $this->parseDsn($dsn);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function captureException(Throwable $e, array $context = []): void
    {
        if ($this->storeUrl === null || $this->publicKey === null) {
            return;
        }

        try {
            $scrubber = $this->scrubber ?? new ErrorScrubber();

            $payload = [
                'event_id' => bin2hex(random_bytes(16)),
                'timestamp' => gmdate('c'),
                'platform' => 'php',
                'level' => 'error',
                'logger' => 'whity',
                'environment' => $this->environment ?? 'production',
                'exception' => [
                    'values' => [[
                        'type' => $e::class,
                        'value' => $scrubber->text($e->getMessage()),
                        'stacktrace' => ['frames' => $this->frames($e, $scrubber)],
                    ]],
                ],
                'extra' => $scrubber->context($context),
            ];

            $this->post($this->storeUrl, $payload);
        } catch (Throwable $inner) {
            error_log('[error-tracker] sentry capture failed: ' . $inner->getMessage());
        }
    }

    /**
     * Sentry orders frames oldest-first, the reverse of PHP's trace.
     *
     * @return list<array{filename: string, lineno: int, function: string}>
     */
    private function frames(Throwable $e, ErrorScrubber $scrubber): array
    {
        $frames = [];
        foreach (array_reverse($e->getTrace()) as $frame) {
            $frames[] = [
                'filename' => $scrubber->text((string) ($frame['file'] ?? '[internal]')),
                'lineno' => (int) ($frame['line'] ?? 0),
                'function' => (string) $frame['function'],
            ];
        }
        $frames[] = [
            'filename' => $scrubber->text($e->getFile()),
            'lineno' => $e->getLine(),
            'function' => '{throw}',
        ];

        return $frames;
    }

    /** @param array<string, mixed> $payload */
    private function post(string $storeUrl, array $payload): void
    {
        $auth = sprintf(
            'Sentry sentry_version=7, sentry_client=whity/1.0, sentry_key=%s',
            $this->publicKey
        );

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'timeout' => self::TIMEOUT_SECONDS,
                'ignore_errors' => true,
                'header' => "Content-Type: application/json\r\nX-Sentry-Auth: {$auth}\r\n",
                'content' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ],
        ]);

        // Suppressed: an unreachable error backend is not itself an error worth
        // raising from inside the error handler.
        @file_get_contents($storeUrl, false, $context);
    }

    /**
     * DSN shape: https://<publicKey>@<host>[:port][/path]/<projectId>
     * The same format hosted Sentry, GlitchTip and Bugsink all issue.
     */
    private function parseDsn(string $dsn): void
    {
        $parts = parse_url($dsn);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'], $parts['user'], $parts['path'])) {
            error_log('[error-tracker] DSN is malformed; error reporting is inactive');

            return;
        }

        $path = trim((string) $parts['path'], '/');
        $segments = explode('/', $path);
        $projectId = array_pop($segments);
        // explode() always yields at least one element, so array_pop() cannot
        // return null here — only the empty string is reachable.
        if ($projectId === '') {
            error_log('[error-tracker] DSN has no project id; error reporting is inactive');

            return;
        }

        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $prefix = $segments === [] ? '' : '/' . implode('/', $segments);

        $this->publicKey = (string) $parts['user'];
        $this->storeUrl = sprintf(
            '%s://%s%s%s/api/%s/store/',
            $parts['scheme'],
            $parts['host'],
            $port,
            $prefix,
            $projectId
        );
    }
}
