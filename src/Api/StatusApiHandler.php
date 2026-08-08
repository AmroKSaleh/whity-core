<?php

declare(strict_types=1);

namespace Whity\Api;

use Throwable;
use Whity\Core\Health\StatusReport;
use Whity\Core\Request;
use Whity\Core\Response;

/**
 * Public service-status endpoint backing the /status page (WC-status-page).
 *
 * `GET /api/v1/status` — unauthenticated by design. This is the surface anyone
 * can check to answer "is it them or me", the same role a hosted status page
 * plays for a large platform, so gating it behind a session would defeat the
 * point: the people who most need it are the ones who cannot sign in.
 *
 * Distinct from `GET /api/health`, and deliberately so:
 *  - `/api/health` is a LIVENESS probe for orchestrators — one process, right
 *    now, cheap enough to poll every few seconds, and it reports on the worker
 *    answering the request.
 *  - `/api/v1/status` is a HISTORY view — every component, aggregated from
 *    recorded samples, with uptime and past incidents. It reads the
 *    `health_samples` time series and never probes anything itself, so it stays
 *    fast and cannot itself cause load during an incident.
 *
 * Nothing here reveals deployment internals: no hostnames, versions, worker
 * counts, queue depths, driver messages or tenant data. See
 * {@see StatusReport} for what is deliberately excluded.
 */
final class StatusApiHandler
{
    /** Bounds on the caller-supplied window, so the query cannot be widened arbitrarily. */
    private const MIN_WINDOW_DAYS = 1;
    private const MAX_WINDOW_DAYS = 90;
    private const DEFAULT_WINDOW_DAYS = 90;

    public function __construct(private readonly StatusReport $report)
    {
    }

    public function get(Request $request): Response
    {
        $days = self::DEFAULT_WINDOW_DAYS;
        $raw = $this->queryParam($request, 'days');
        if ($raw !== null && ctype_digit($raw)) {
            $days = max(self::MIN_WINDOW_DAYS, min(self::MAX_WINDOW_DAYS, (int) $raw));
        }

        try {
            return Response::json($this->report->build($days), 200);
        } catch (Throwable $e) {
            error_log('[StatusApiHandler] status build failed: ' . $e->getMessage());

            // A status page that 500s during an incident is the least useful
            // moment for it to break. Answer with an honest "we cannot tell"
            // rather than an error the reader has to interpret.
            return Response::json([
                'status' => 'unknown',
                'components' => [],
                'incidents' => [],
                'window_days' => $days,
                'generated_at' => gmdate('c'),
            ], 200);
        }
    }

    /**
     * Read a query parameter from $_GET (production) or the path's query string
     * (tests) — the same dual lookup AuditLogApiHandler uses, since Request
     * exposes no query accessor of its own.
     */
    private function queryParam(Request $request, string $name): ?string
    {
        $value = $_GET[$name] ?? null;
        if (is_string($value) && $value !== '') {
            return $value;
        }

        $qs = parse_url($request->getPath(), PHP_URL_QUERY);
        if (is_string($qs) && $qs !== '') {
            parse_str($qs, $parsed);
            $fromPath = $parsed[$name] ?? null;
            if (is_string($fromPath) && $fromPath !== '') {
                return $fromPath;
            }
        }

        return null;
    }
}
