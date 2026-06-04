<?php

declare(strict_types=1);

namespace Whity\Api;

use Throwable;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Database\ConnectionException;
use Whity\Database\Database;

/**
 * Health monitoring endpoint handler (WC-4).
 *
 * Serves `GET /api/health` with a lightweight, dependency-light snapshot of the
 * running FrankenPHP worker so external monitors (load balancers, uptime probes,
 * orchestration health checks) can judge whether the process is serving traffic
 * and whether its database is reachable.
 *
 * The endpoint is intentionally unauthenticated and bypasses the RBAC and tenant
 * isolation middleware (it is registered in the tenant middleware's public-route
 * list, mirroring `/api/login`), so it stays answerable even while the auth and
 * tenant subsystems are unhealthy.
 *
 * Response shape (200 healthy / 503 degraded):
 *  - `status`           : `"ok"` when the database is reachable, `"degraded"` otherwise.
 *  - `workers_active`   : the configured FrankenPHP worker count (see note below).
 *  - `memory_usage_mb`  : the current worker's real memory usage, in megabytes.
 *  - `uptime_seconds`   : seconds since this worker process captured its boot timestamp.
 *  - `db_connected`     : whether a cheap connectivity ping to the database succeeded.
 *
 * Worker-count note: FrankenPHP does not expose live per-worker introspection to
 * PHP userland, so `workers_active` reports the *configured* worker count from the
 * `FRANKENPHP_WORKERS` environment variable (set by docker-compose), not a live
 * count of busy/idle workers.
 *
 * The handler never leaks internal details (DSNs, driver messages, stack traces):
 * a failed database ping is collapsed to `db_connected: false` and an HTTP 503.
 */
class HealthApiHandler
{
    /**
     * Default configured worker count when `FRANKENPHP_WORKERS` is unset.
     */
    private const DEFAULT_WORKERS = 1;

    private Database $db;

    /**
     * Unix timestamp (seconds) captured when the worker booted, used to derive
     * the process uptime reported by the endpoint.
     */
    private int $bootTimestamp;

    /**
     * @param Database $db            Worker-scoped database wrapper used for the connectivity ping.
     * @param int|null $bootTimestamp Unix timestamp captured at worker boot; defaults to "now"
     *                                (so a freshly constructed handler reports ~0s uptime).
     */
    public function __construct(Database $db, ?int $bootTimestamp = null)
    {
        $this->db = $db;
        $this->bootTimestamp = $bootTimestamp ?? time();
    }

    /**
     * Handle `GET /api/health`.
     *
     * Returns HTTP 200 with `status: "ok"` when the database connectivity ping
     * succeeds, or HTTP 503 with `status: "degraded"` and `db_connected: false`
     * when it does not. The ping failure is caught here and never propagated to
     * the client.
     *
     * @param Request $request The incoming request (unused; the endpoint takes no input).
     * @return Response The health snapshot response.
     */
    public function handle(Request $request): Response
    {
        $dbConnected = $this->pingDatabase();

        $body = [
            'status' => $dbConnected ? 'ok' : 'degraded',
            'workers_active' => $this->configuredWorkerCount(),
            'memory_usage_mb' => $this->memoryUsageMb(),
            'uptime_seconds' => $this->uptimeSeconds(),
            'db_connected' => $dbConnected,
        ];

        $statusCode = $dbConnected ? 200 : 503;

        return Response::json($body, $statusCode);
    }

    /**
     * Perform a cheap, guarded database connectivity check.
     *
     * Issues a `SELECT 1` through the WC-21 {@see Database} wrapper, which already
     * handles lazy connect, health-check pinging and transparent reconnect. A
     * {@see ConnectionException} (connection cannot be established) maps to "not
     * connected"; any other failure is also treated defensively as "not
     * connected" so a database problem can never surface as an exception to the
     * health caller.
     *
     * @return bool True if the database answered the ping, false otherwise.
     */
    private function pingDatabase(): bool
    {
        try {
            $this->db->query('SELECT 1');
            return true;
        } catch (ConnectionException) {
            return false;
        } catch (Throwable) {
            // Any other failure (e.g. a driver-level error) is still reported as a
            // degraded database rather than leaking the underlying error.
            return false;
        }
    }

    /**
     * The configured FrankenPHP worker count.
     *
     * Read from the `FRANKENPHP_WORKERS` environment variable (set by
     * docker-compose). FrankenPHP exposes no live worker introspection to PHP, so
     * this is the configured count, not a count of currently-busy workers.
     *
     * @return int The configured worker count (never less than 1).
     */
    private function configuredWorkerCount(): int
    {
        $value = $_ENV['FRANKENPHP_WORKERS'] ?? $_SERVER['FRANKENPHP_WORKERS'] ?? getenv('FRANKENPHP_WORKERS');

        if (is_string($value) && ctype_digit($value) && (int) $value > 0) {
            return (int) $value;
        }

        return self::DEFAULT_WORKERS;
    }

    /**
     * Current worker memory usage in megabytes (real allocation), rounded to 2 dp.
     *
     * @return float Memory usage in MB.
     */
    private function memoryUsageMb(): float
    {
        return round(memory_get_usage(true) / 1024 / 1024, 2);
    }

    /**
     * Seconds elapsed since the worker captured its boot timestamp.
     *
     * Clamped at zero so clock skew can never produce a negative uptime.
     *
     * @return int Worker uptime in seconds.
     */
    private function uptimeSeconds(): int
    {
        return max(0, time() - $this->bootTimestamp);
    }
}
