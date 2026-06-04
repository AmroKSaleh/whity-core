<?php

declare(strict_types=1);

namespace Whity\Database;

use Closure;
use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;
use Throwable;

/**
 * Worker-scoped PostgreSQL connection manager.
 *
 * Designed for FrankenPHP persistent workers: each worker process holds a
 * single, lazily-initialised PDO connection that is reused across every request
 * the worker serves. With N workers this yields exactly N PostgreSQL
 * connections rather than one-per-request, which is the whole point of running
 * persistent workers.
 *
 * Reliability features:
 *  - Lazy initialisation: the socket is only opened on first use.
 *  - Health-check ping (throttled by {@see $pingIntervalSeconds}) before a query
 *    so a connection that died while the worker was idle is detected proactively.
 *  - Transparent reconnect-and-retry: if a query fails because the server closed
 *    the connection, the connection is rebuilt once and the query retried, so the
 *    failure never reaches the caller.
 *  - Max connection lifetime ({@see $maxLifetimeSeconds}): connections are
 *    recycled after a configurable age to bound the impact of server-side
 *    resource creep and to interplay cleanly with FrankenPHP's MAX_REQUESTS
 *    worker recycling.
 *  - Per-request session reset ({@see resetSessionState()}): rolls back any
 *    dangling transaction and discards session-local state so nothing
 *    request-specific (temp tables, SET LOCAL, prepared plans) leaks across the
 *    requests that share a worker connection.
 *
 * Worker-scoped state is deliberate here: the PDO handle is worker-level, not
 * request-level. No request-specific data (tenant, user, open transaction) is
 * permitted to survive between requests — that is enforced by
 * {@see resetSessionState()}, which the worker loop calls between requests.
 *
 * Stability over 10,000+ requests per worker
 * ------------------------------------------
 * A FrankenPHP worker may serve many thousands of requests before it is
 * recycled (see MAX_REQUESTS in docker-compose.yml). To stay stable across that
 * volume on a single shared connection:
 *
 *  1. Connection reuse, not re-open. The PDO socket is opened once and reused;
 *     no per-request connect/disconnect churn, so request count alone never
 *     exhausts file descriptors or PostgreSQL backends. With 8 workers and
 *     MAX_REQUESTS=500 (the docker-compose default) PostgreSQL sees a steady 8
 *     backends regardless of request throughput.
 *  2. Session hygiene every request. {@see resetSessionState()} runs DISCARD ALL
 *     between requests, so temp tables, cached plans, advisory locks and SET
 *     values cannot accumulate over thousands of requests and leak memory on the
 *     server side or bleed state between tenants.
 *  3. Bounded connection age. {@see $maxLifetimeSeconds} (DB_MAX_LIFETIME)
 *     recycles the connection after a configurable age even if the worker keeps
 *     running, capping the blast radius of any server-side per-connection
 *     resource growth and refreshing the socket before the server's own idle
 *     timeout can drop it.
 *  4. Self-healing. A connection dropped by the server (deploy, failover, idle
 *     reaper) is detected by the pre-query ping or the mid-query SQLSTATE-08
 *     check and rebuilt transparently, so a long-lived worker survives database
 *     restarts without erroring out the requests it is serving.
 *
 * Interplay with worker recycling: FrankenPHP tears the worker process down
 * after MAX_REQUESTS, which closes the PDO socket as the process exits; the
 * connection is therefore released on recycle without any explicit hook. The
 * worker loop may also call {@see disconnect()} on shutdown to release it
 * eagerly and roll back anything left open.
 */
class Database
{
    /** Default maximum connection lifetime in seconds (30 minutes). */
    private const DEFAULT_MAX_LIFETIME_SECONDS = 1800;

    /** Default minimum seconds between health-check pings (5 seconds). */
    private const DEFAULT_PING_INTERVAL_SECONDS = 5;

    /** Maximum number of automatic reconnect attempts when establishing a connection. */
    private const MAX_CONNECT_ATTEMPTS = 3;

    /**
     * SQLSTATE class codes that indicate the connection is gone and a reconnect
     * may recover the operation. Class 08 = connection exception; 57P = admin
     * shutdown / cannot connect now.
     *
     * @var array<int, string>
     */
    private const RECOVERABLE_SQLSTATE_PREFIXES = ['08', '57P01', '57P02', '57P03'];

    /** Lazily-created connection; null until first use or after disconnect/recycle. */
    private ?PDO $pdo = null;

    /** Unix timestamp (float) when the current connection was opened. */
    private float $connectedAt = 0.0;

    /** Unix timestamp (float) of the last successful health-check ping. */
    private float $lastPingAt = 0.0;

    /** Factory that produces a fresh PDO connection. */
    private Closure $factory;

    /** Maximum connection lifetime in seconds before it is recycled. */
    private int $maxLifetimeSeconds;

    /** Minimum seconds between health-check pings (0 = ping before every query). */
    private int $pingIntervalSeconds;

    /**
     * @param Closure(): PDO $factory             Produces a new PDO connection.
     * @param int|null       $maxLifetimeSeconds  Override max lifetime; null reads DB_MAX_LIFETIME env.
     * @param int|null       $pingIntervalSeconds Override ping interval; null reads DB_PING_INTERVAL env.
     */
    private function __construct(
        Closure $factory,
        ?int $maxLifetimeSeconds = null,
        ?int $pingIntervalSeconds = null
    ) {
        $this->factory = $factory;
        $this->maxLifetimeSeconds = $maxLifetimeSeconds ?? self::envInt(
            'DB_MAX_LIFETIME',
            self::DEFAULT_MAX_LIFETIME_SECONDS
        );
        $this->pingIntervalSeconds = $pingIntervalSeconds ?? self::envInt(
            'DB_PING_INTERVAL',
            self::DEFAULT_PING_INTERVAL_SECONDS
        );
    }

    /**
     * Create a Database from a DSN and credentials.
     *
     * The connection is established lazily on first use (no socket is opened
     * here), then reused and self-healed across the worker's requests.
     *
     * @param string $dsn      Database DSN (e.g. "pgsql:host=localhost;port=5432;dbname=whity_core").
     * @param string $user     Database user.
     * @param string $password Database password.
     */
    public static function fromDsn(string $dsn, string $user, string $password): self
    {
        $factory = static function () use ($dsn, $user, $password): PDO {
            return new PDO($dsn, $user, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                // Persistent at the PDO level is intentionally NOT used: FrankenPHP
                // workers already provide one connection per worker, and PDO
                // persistence interacts badly with per-request session resets.
                PDO::ATTR_PERSISTENT => false,
            ]);
        };

        return new self($factory);
    }

    /**
     * Create a Database backed by an arbitrary connection factory.
     *
     * Primarily a test seam (inject a mock-PDO factory), but also usable to
     * supply a customised connection builder.
     *
     * @param Closure(): PDO $factory             Produces a new PDO connection.
     * @param int|null       $maxLifetimeSeconds  Optional max-lifetime override (seconds).
     * @param int|null       $pingIntervalSeconds Optional ping-interval override (seconds).
     */
    public static function withFactory(
        Closure $factory,
        ?int $maxLifetimeSeconds = null,
        ?int $pingIntervalSeconds = null
    ): self {
        return new self($factory, $maxLifetimeSeconds, $pingIntervalSeconds);
    }

    /**
     * Create a database connection using environment variables.
     *
     * Required environment variables:
     * - DB_USER: Database user (required)
     * - DB_PASSWORD: Database password (required)
     *
     * Optional environment variables:
     * - DB_HOST: Database host (default: localhost)
     * - DB_PORT: Database port (default: 5432)
     * - DB_NAME: Database name (default: whity_core)
     * - DB_CONNECT_TIMEOUT: Connection timeout in seconds (default: 5)
     * - DB_MAX_LIFETIME: Max connection lifetime in seconds before recycle (default: 1800)
     * - DB_PING_INTERVAL: Min seconds between health-check pings (default: 5)
     *
     * The connection is established lazily on first query, so this is safe to
     * call at worker boot without paying the connect cost up front.
     *
     * @return self Database connection instance.
     * @throws RuntimeException If required environment variables are missing.
     */
    public static function connect(): self
    {
        $required = ['DB_USER', 'DB_PASSWORD'];
        foreach ($required as $var) {
            if (empty($_ENV[$var] ?? null)) {
                throw new RuntimeException("Missing required environment variable: {$var}");
            }
        }

        $host = $_ENV['DB_HOST'] ?? 'localhost';
        $port = $_ENV['DB_PORT'] ?? 5432;
        $dbName = $_ENV['DB_NAME'] ?? 'whity_core';
        $user = (string) $_ENV['DB_USER'];
        $password = (string) $_ENV['DB_PASSWORD'];
        $connectTimeout = self::envInt('DB_CONNECT_TIMEOUT', 5);

        $dsn = "pgsql:host={$host};port={$port};dbname={$dbName}";

        $factory = static function () use ($dsn, $user, $password, $connectTimeout): PDO {
            return new PDO($dsn, $user, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_PERSISTENT => false,
                PDO::ATTR_TIMEOUT => $connectTimeout,
            ]);
        };

        return new self($factory);
    }

    /**
     * Execute a prepared statement with parameters.
     *
     * The connection is health-checked (and recycled/reconnected if needed)
     * before the statement runs. If the query fails because the server dropped
     * the connection, the connection is rebuilt once and the query retried, so
     * a transient disconnect is invisible to the caller.
     *
     * @param string               $sql    SQL statement with placeholders.
     * @param array<mixed>         $params Parameters to bind.
     * @return PDOStatement Executed statement.
     * @throws ConnectionException If the connection cannot be (re-)established.
     * @throws PDOException For non-connection query errors (e.g. SQL syntax).
     */
    public function query(string $sql, array $params = []): PDOStatement
    {
        return $this->run(static function (PDO $pdo) use ($sql, $params): PDOStatement {
            $statement = $pdo->prepare($sql);
            $statement->execute($params);
            return $statement;
        });
    }

    /**
     * Execute a raw SQL statement.
     *
     * Subject to the same health-check and reconnect-and-retry behaviour as
     * {@see query()}.
     *
     * @param string $sql SQL statement.
     * @return int Number of affected rows.
     * @throws ConnectionException If the connection cannot be (re-)established.
     * @throws PDOException For non-connection query errors.
     */
    public function exec(string $sql): int
    {
        return $this->run(static function (PDO $pdo) use ($sql): int {
            return (int) $pdo->exec($sql);
        });
    }

    /**
     * Get the underlying PDO instance.
     *
     * Triggers lazy initialisation and a health-check first, so the returned
     * handle is live. Note that a handle captured by a caller will not benefit
     * from a later transparent reconnect — long-lived consumers should hold the
     * Database wrapper and call {@see query()}/{@see exec()} instead.
     *
     * @return PDO Live PDO instance.
     * @throws ConnectionException If the connection cannot be established.
     */
    public function getPdo(): PDO
    {
        $this->ensureHealthyConnection();
        /** @var PDO $pdo */
        $pdo = $this->pdo;
        return $pdo;
    }

    /**
     * Whether a live connection is currently held (does not open one).
     */
    public function isConnected(): bool
    {
        return $this->pdo instanceof PDO;
    }

    /**
     * Eagerly establish the connection (e.g. for warm-up or tests).
     *
     * @throws ConnectionException If the connection cannot be established.
     */
    public function forceConnect(): void
    {
        if (!$this->pdo instanceof PDO) {
            $this->openConnection();
        }
    }

    /**
     * Close and release the current connection (worker-recycle / shutdown path).
     *
     * Any dangling transaction is rolled back first. Safe to call when not
     * connected.
     */
    public function disconnect(): void
    {
        if ($this->pdo instanceof PDO) {
            try {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
            } catch (Throwable $e) {
                error_log('[Database] Error rolling back during disconnect: ' . $e->getMessage());
            }
        }

        $this->pdo = null;
        $this->connectedAt = 0.0;
        $this->lastPingAt = 0.0;
    }

    /**
     * Reset per-request session state on the worker connection.
     *
     * Called by the worker request loop between requests. It:
     *  1. Rolls back any transaction a request left open (defensive — handlers
     *     should commit/rollback themselves, but a thrown error may skip that).
     *  2. Discards PostgreSQL session-local state (temp tables, prepared plans,
     *     SET LOCAL/SET SESSION values, advisory locks via DISCARD ALL) so the
     *     next request on this worker starts clean and nothing request-specific
     *     leaks across the shared connection.
     *
     * This is a no-op when no connection is open (it never forces a connect).
     */
    public function resetSessionState(): void
    {
        if (!$this->pdo instanceof PDO) {
            return;
        }

        try {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            // DISCARD ALL cannot run inside a transaction block; we've ensured
            // there is none above. It resets temp tables, plans, session GUCs
            // and advisory locks for the connection.
            $this->pdo->exec('DISCARD ALL');
        } catch (PDOException $e) {
            // If the connection died, drop it so the next use reconnects cleanly
            // rather than carrying a poisoned handle into the next request.
            if ($this->isRecoverable($e)) {
                $this->disconnect();
                return;
            }
            error_log('[Database] Session reset failed: ' . $e->getMessage());
        }
    }

    /**
     * Run an operation against the live connection with health-check, transparent
     * reconnect and single retry on a recoverable disconnect.
     *
     * @template T
     * @param Closure(PDO): T $operation
     * @return T
     * @throws ConnectionException If the connection cannot be (re-)established.
     * @throws PDOException For non-connection query errors.
     */
    private function run(Closure $operation)
    {
        $this->ensureHealthyConnection();

        /** @var PDO $pdo */
        $pdo = $this->pdo;

        try {
            return $operation($pdo);
        } catch (PDOException $e) {
            // Only a recoverable disconnect — and only when we are not inside a
            // transaction (retrying mid-transaction would silently lose prior
            // statements) — is eligible for a transparent retry.
            if (!$this->isRecoverable($e) || $this->safelyInTransaction($pdo)) {
                throw $e;
            }

            error_log('[Database] Connection lost mid-query, reconnecting: ' . $e->getMessage());
            $this->disconnect();
            $this->openConnection();

            /** @var PDO $fresh */
            $fresh = $this->pdo;
            return $operation($fresh);
        }
    }

    /**
     * Ensure a usable connection exists: open lazily, recycle if past max
     * lifetime, and ping (throttled) to detect a connection that died while idle.
     *
     * @throws ConnectionException If a connection cannot be established.
     */
    private function ensureHealthyConnection(): void
    {
        if (!$this->pdo instanceof PDO) {
            $this->openConnection();
            return;
        }

        if ($this->isExpired()) {
            error_log('[Database] Recycling connection past max lifetime');
            $this->disconnect();
            $this->openConnection();
            return;
        }

        if ($this->shouldPing() && !$this->ping()) {
            error_log('[Database] Health-check ping failed, reconnecting');
            $this->disconnect();
            $this->openConnection();
        }
    }

    /**
     * Open a fresh connection via the factory, retrying transient failures.
     *
     * @throws ConnectionException When all attempts fail.
     */
    private function openConnection(): void
    {
        $lastError = null;

        for ($attempt = 1; $attempt <= self::MAX_CONNECT_ATTEMPTS; $attempt++) {
            try {
                $pdo = ($this->factory)();
                $this->pdo = $pdo;
                $this->connectedAt = microtime(true);
                $this->lastPingAt = $this->connectedAt;
                return;
            } catch (PDOException $e) {
                $lastError = $e;
                error_log(sprintf(
                    '[Database] Connection attempt %d/%d failed: %s',
                    $attempt,
                    self::MAX_CONNECT_ATTEMPTS,
                    $e->getMessage()
                ));
            }
        }

        // Never leak the raw driver error/stack trace to callers/clients.
        throw new ConnectionException(
            'Unable to establish a database connection.',
            0,
            $lastError
        );
    }

    /**
     * Lightweight liveness check ("SELECT 1"). Updates the last-ping timestamp on
     * success.
     */
    private function ping(): bool
    {
        if (!$this->pdo instanceof PDO) {
            return false;
        }

        try {
            $this->pdo->query('SELECT 1');
            $this->lastPingAt = microtime(true);
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Whether the current connection has exceeded its configured max lifetime.
     */
    private function isExpired(): bool
    {
        if ($this->maxLifetimeSeconds <= 0) {
            return true;
        }

        return (microtime(true) - $this->connectedAt) >= $this->maxLifetimeSeconds;
    }

    /**
     * Whether enough time has elapsed since the last ping to ping again.
     */
    private function shouldPing(): bool
    {
        if ($this->pingIntervalSeconds <= 0) {
            return true;
        }

        return (microtime(true) - $this->lastPingAt) >= $this->pingIntervalSeconds;
    }

    /**
     * Whether the PDOException represents a recoverable connection-level failure.
     */
    private function isRecoverable(PDOException $e): bool
    {
        $sqlState = $this->extractSqlState($e);

        if ($sqlState !== null) {
            foreach (self::RECOVERABLE_SQLSTATE_PREFIXES as $prefix) {
                if (str_starts_with($sqlState, $prefix)) {
                    return true;
                }
            }
        }

        // Fall back to message sniffing for drivers/cases that do not populate
        // a SQLSTATE on the exception object.
        $message = strtolower($e->getMessage());
        $needles = [
            'server closed the connection',
            'connection refused',
            'could not connect',
            'no connection to the server',
            'connection reset',
            'connection timed out',
            'terminating connection',
            'gone away',
            'broken pipe',
            'eof detected',
        ];

        foreach ($needles as $needle) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extract the 5-character SQLSTATE from a PDOException, if present.
     */
    private function extractSqlState(PDOException $e): ?string
    {
        // PDOException::$errorInfo[0] is the SQLSTATE when set by the driver.
        if (is_array($e->errorInfo) && isset($e->errorInfo[0]) && is_string($e->errorInfo[0])) {
            return $e->errorInfo[0];
        }

        // Otherwise parse the conventional "SQLSTATE[XXXXX]" message prefix.
        if (preg_match('/SQLSTATE\[([0-9A-Z]{5})\]/', $e->getMessage(), $m) === 1) {
            return $m[1];
        }

        return null;
    }

    /**
     * Safely check whether a transaction is open without letting a dead handle
     * blow up the recoverability decision.
     */
    private function safelyInTransaction(PDO $pdo): bool
    {
        try {
            return $pdo->inTransaction();
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Read an integer environment variable with a default.
     */
    private static function envInt(string $key, int $default): int
    {
        $value = $_ENV[$key] ?? getenv($key);

        if ($value === false || $value === null || $value === '') {
            return $default;
        }

        if (!is_numeric($value)) {
            return $default;
        }

        return (int) $value;
    }

    /**
     * Max connection lifetime in seconds.
     */
    public function getMaxLifetimeSeconds(): int
    {
        return $this->maxLifetimeSeconds;
    }

    /**
     * Override the max connection lifetime (seconds). <= 0 recycles every use.
     */
    public function setMaxLifetimeSeconds(int $seconds): void
    {
        $this->maxLifetimeSeconds = $seconds;
    }

    /**
     * Minimum seconds between health-check pings.
     */
    public function getPingIntervalSeconds(): int
    {
        return $this->pingIntervalSeconds;
    }

    /**
     * Override the ping interval (seconds). <= 0 pings before every query.
     */
    public function setPingIntervalSeconds(int $seconds): void
    {
        $this->pingIntervalSeconds = $seconds;
    }
}
