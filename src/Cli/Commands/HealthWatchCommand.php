<?php

declare(strict_types=1);

namespace Whity\Cli\Commands;

use PDO;
use Throwable;
use Whity\Core\Health\HealthProbe;
use Whity\Core\Health\HealthProbeRegistry;
use Whity\Core\Health\HealthSampleRepository;
use Whity\Core\Health\HealthStatus;
use Whity\Core\PluginLoader;
use Whity\Core\Router;
use Whity\Database\Database;

/**
 * `health:watch` — the status-page collector (WC-status-page).
 *
 * Runs as its OWN process, in its own container, and that is the entire point.
 *
 * An in-app probe can measure the database, the queue and the scheduler, but it
 * can never record the app being down: if FrankenPHP is not serving, nothing
 * inside it executes and the last sample simply goes stale. That is exactly the
 * failure this deployment already had — the web tier and the tunnel were dead
 * for three days and nothing noticed, because the only thing that could have
 * noticed was the thing that was down.
 *
 * So this process:
 *  1. probes the internal components — core's database, queue, scheduler and
 *     render, plus whatever plugins contributed to the
 *     {@see HealthProbeRegistry} — and
 *  2. probes the PUBLIC URL over HTTP, from outside the app process, recording
 *     `web` as down when it cannot be reached.
 *
 * It writes straight to Postgres, so it keeps recording while the app tier is
 * unreachable. It shares the app's image (one build, one set of migrations) but
 * NOT its fate: `docker compose` keeps it alive independently of frankenphp.
 *
 * It is NOT a substitute for a probe on separate infrastructure — if the whole
 * host or Docker itself dies, this dies with it. Pair it with an off-host check
 * (Cloudflare health check, UptimeRobot) against the public URL for full
 * outside-in coverage.
 *
 * Surviving a dependency blip, and saying so (WC-766)
 * ---------------------------------------------------
 * The loop NEVER gives up. A pass that cannot reach the database records
 * nothing, says so, backs off, and comes back — indefinitely. There is no
 * attempt budget, because a monitor that exhausts one stops being a monitor at
 * exactly the moment the thing it watches is in trouble.
 *
 * It also emits POSITIVE evidence of liveness, which matters more than it
 * sounds. This process used to be silent on success and loud only on failure,
 * so `docker logs --tail 1` showed the last thing that went wrong — with no way
 * to tell a collector that died six days ago from one that recovered six days
 * ago and has been fine since. (That is not hypothetical: on 2026-08-22 a
 * healthy collector's last log line was a six-day-old "database unreachable",
 * and it was read as six days of silence.) So: an explicit line when the
 * database comes BACK, and a periodic "alive" line while it is up.
 *
 * Finally it writes a HEARTBEAT FILE — the one artefact that lets the container
 * healthcheck assert something about THIS process rather than about the shared
 * `health_samples` table. See {@see writeHeartbeat()} and
 * ops/health-watch-healthcheck.php.
 *
 *   health:watch                 # loop forever, 60s between passes
 *   health:watch --once          # single pass (cron-style, and what tests use)
 *   health:watch --interval=30   # override the pass interval
 *   health:watch --url=https://… # public URL to probe (else WHITY_PUBLIC_URL)
 */
final class HealthWatchCommand
{
    private const DEFAULT_INTERVAL_SECONDS = 60;

    /** Slower than this and the site is up but not usable. */
    private const WEB_DEGRADED_MS = 3000;
    private const WEB_TIMEOUT_SECONDS = 10;

    /** Samples older than this are pruned; matches the status page's window. */
    private const RETENTION_DAYS = 90;

    /**
     * Ceiling on the retry backoff after consecutive failed passes.
     *
     * Backoff exists so a multi-hour outage is not hammered once a minute, NOT
     * so the loop can wind down to nothing — it is capped, never abandoned. The
     * cap is the healthcheck's default freshness budget
     * (WHITY_HEALTH_SAMPLE_MAX_AGE, 300s): while the dependency is down the
     * container is correctly UNHEALTHY, and once it returns the next pass is at
     * most one budget away, so the green comes back promptly.
     */
    private const MAX_BACKOFF_SECONDS = 300;

    /**
     * Successful passes between "still alive" log lines — 60 passes, i.e.
     * roughly hourly at the default interval.
     *
     * Low enough that `docker logs --tail 1` on a working collector shows a
     * RECENT line rather than whatever last failed, high enough not to bury the
     * log in noise.
     */
    private const HEARTBEAT_LOG_EVERY_PASSES = 60;

    /** Default heartbeat location; overridable with WHITY_HEALTH_WATCH_HEARTBEAT. */
    private const HEARTBEAT_FILENAME = 'whity-health-watch.heartbeat.json';

    private ?PDO $pdo = null;

    /** @var callable(int): void */
    private $sleeper;

    /**
     * The component catalogue, built once on the first pass.
     *
     * This process runs OUTSIDE both hosts — it is neither a FrankenPHP worker
     * nor a {@see BaseCommand} kernel — so nothing has registered a catalogue
     * for it. It therefore builds its own (see {@see probeRegistry()}); tests
     * inject one directly.
     */
    private ?HealthProbeRegistry $probes;

    /**
     * Where operator-facing lines go. STDERR by default — the container log.
     * Injectable so tests can read what was said without writing to the
     * PHPUnit process's own stderr.
     *
     * @var callable(string): void
     */
    private $logger;

    /** Absolute path of the heartbeat file this process owns. */
    private string $heartbeatPath;

    /** Consecutive passes that recorded nothing; 0 while collection is working. */
    private int $consecutiveFailures = 0;

    /** Unix time the current unbroken failure streak began, or null. */
    private ?int $downSince = null;

    /** Unix time of the last pass that actually persisted at least one sample. */
    private ?int $lastSampleAt = null;

    /** Successful passes since the last "alive" line; forces one on the first. */
    private int $passesSinceHeartbeatLog = self::HEARTBEAT_LOG_EVERY_PASSES;

    public function __construct(
        ?PDO $pdo = null,
        ?callable $sleeper = null,
        ?HealthProbeRegistry $probes = null,
        ?callable $logger = null,
        ?string $heartbeatPath = null,
    ) {
        $this->pdo = $pdo;
        $this->probes = $probes;
        $this->sleeper = $sleeper ?? static function (int $seconds): void {
            if ($seconds > 0) {
                sleep($seconds);
            }
        };
        $this->logger = $logger ?? static function (string $line): void {
            fwrite(STDERR, $line . "\n");
        };
        $this->heartbeatPath = $heartbeatPath ?? self::defaultHeartbeatPath();
    }

    /**
     * The heartbeat path both this command and ops/health-watch-healthcheck.php
     * resolve, so the collector and its probe cannot drift apart.
     */
    public static function defaultHeartbeatPath(): string
    {
        $configured = getenv('WHITY_HEALTH_WATCH_HEARTBEAT');
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        return rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . self::HEARTBEAT_FILENAME;
    }

    /**
     * @param list<string> $argv
     */
    public function execute(array $argv): int
    {
        $once = in_array('--once', $argv, true);
        $interval = self::DEFAULT_INTERVAL_SECONDS;
        $url = getenv('WHITY_PUBLIC_URL') ?: null;

        foreach ($argv as $arg) {
            if (str_starts_with($arg, '--interval=')) {
                $interval = max(5, (int) substr($arg, 11));
            }
            if (str_starts_with($arg, '--url=')) {
                $url = substr($arg, 6);
            }
        }

        while (true) {
            $recorded = $this->pass(is_string($url) && $url !== '' ? $url : null);

            if ($once) {
                break;
            }

            // A pass that recorded nothing waits longer than one that worked,
            // but the loop itself is unconditional: there is no exit from here
            // other than --once. Whatever is broken, this process keeps trying.
            ($this->sleeper)($recorded ? $interval : $this->backoffSeconds($interval));
        }

        return 0;
    }

    /**
     * One full pass: internal components, then the public URL, then GC.
     *
     * @return bool Whether at least one sample was actually PERSISTED. Not
     *              "did the probes run" — a probe whose INSERT failed produced
     *              no observation, and this process must not claim otherwise.
     */
    private function pass(?string $publicUrl): bool
    {
        $pdo = $this->pdo();
        if ($pdo === null) {
            // The database is the one dependency this process cannot work
            // without — there is nowhere to record the observation. Note it,
            // back off, and come back. Never stop.
            $this->noteFailedPass('database unreachable');

            return false;
        }

        $samples = new HealthSampleRepository($pdo);
        $recorded = 0;

        try {
            $renderUrl = getenv('WHITY_RENDER_URL') ?: null;
            $probe = new HealthProbe(
                $pdo,
                $samples,
                is_string($renderUrl) ? $renderUrl : null,
                $this->probeRegistry()
            );
            $probe->runAll();
            $recorded += $probe->recordedCount();
        } catch (Throwable $e) {
            $this->log('[health:watch] internal probe failed: ' . $e->getMessage());
        }

        if ($publicUrl !== null) {
            $recorded += $this->probePublicUrl($samples, $publicUrl);
        }

        try {
            $samples->pruneOlderThan(gmdate('Y-m-d H:i:s', time() - (self::RETENTION_DAYS * 86400)));
        } catch (Throwable) {
            // Retention is best-effort; never let GC failure stop collection.
        }

        if ($recorded === 0) {
            // Connected, probed, and still wrote nothing — every INSERT failed
            // (read-only replica, missing table, exhausted disk). Identical in
            // consequence to being unable to connect, so treat it identically
            // rather than reporting a pass that produced no observation.
            $this->noteFailedPass('connected but recorded no samples');

            return false;
        }

        $this->noteSuccessfulPass($recorded);

        return true;
    }

    /**
     * A pass that produced no observation.
     *
     * Every one of these is logged, with the streak length and how long the
     * outage has lasted, so a log that has gone quiet means the process is gone
     * — not that it gave up quietly, which is the ambiguity that cost six days
     * of misplaced confidence.
     */
    private function noteFailedPass(string $reason): void
    {
        $this->consecutiveFailures++;
        $this->downSince ??= time();
        // Force an "alive" line on the pass that recovers, whenever that is.
        $this->passesSinceHeartbeatLog = self::HEARTBEAT_LOG_EVERY_PASSES;

        $this->log(sprintf(
            '[health:watch] %s; cannot record samples '
            . '(failed pass %d, down %ds) — retrying, this process does not give up',
            $reason,
            $this->consecutiveFailures,
            time() - $this->downSince,
        ));

        $this->writeHeartbeat($reason);
    }

    /** A pass that persisted at least one sample. */
    private function noteSuccessfulPass(int $recorded): void
    {
        if ($this->consecutiveFailures > 0) {
            // The recovery line. Without it the last thing in the log is the
            // failure, forever, and a collector that healed is indistinguishable
            // from one that died.
            $this->log(sprintf(
                '[health:watch] recovered: recording again after %d failed pass(es) over %ds',
                $this->consecutiveFailures,
                time() - ($this->downSince ?? time()),
            ));
        }

        $this->consecutiveFailures = 0;
        $this->downSince = null;
        $this->lastSampleAt = time();

        if (++$this->passesSinceHeartbeatLog >= self::HEARTBEAT_LOG_EVERY_PASSES) {
            $this->passesSinceHeartbeatLog = 0;
            $this->log(sprintf(
                '[health:watch] alive — recorded %d sample(s) at %sZ',
                $recorded,
                gmdate('Y-m-d\TH:i:s', $this->lastSampleAt),
            ));
        }

        $this->writeHeartbeat(null);
    }

    /**
     * Publish this process's own liveness, for the container healthcheck.
     *
     * Why a file and not a query. The healthcheck used to ask the DATABASE "is
     * there a recent row in health_samples?", which is a fact about the TABLE,
     * not about this container: any other writer — a second collector, a
     * backfill, or the rows this process itself wrote before it wedged — keeps
     * it green while nothing is being collected here. It could never report the
     * one thing it existed to report: "I have recorded nothing."
     *
     * `last_sample_at` is written by, and only by, the process making the
     * claim, so a stale or absent value IS the failure. Best-effort and never
     * fatal: an unwritable heartbeat turns the container red (correctly — the
     * claim cannot be substantiated) but must not stop collection.
     */
    private function writeHeartbeat(?string $lastError): void
    {
        $payload = json_encode([
            'pid' => getmypid(),
            'updated_at' => time(),
            'last_sample_at' => $this->lastSampleAt,
            'consecutive_failures' => $this->consecutiveFailures,
            'last_error' => $lastError,
        ], JSON_PRETTY_PRINT);

        if ($payload === false) {
            return;
        }

        try {
            // Write-then-rename: a probe reading mid-write must never see a
            // truncated file and conclude the collector is dead.
            $tmp = $this->heartbeatPath . '.tmp';
            if (@file_put_contents($tmp, $payload . "\n") !== false) {
                @rename($tmp, $this->heartbeatPath);
            }
        } catch (Throwable) {
            // Never let the liveness bookkeeping kill the liveness it reports.
        }
    }

    /**
     * Seconds to wait after a failed pass: the interval doubled per consecutive
     * failure, capped at {@see MAX_BACKOFF_SECONDS}. Bounded, never abandoned.
     */
    private function backoffSeconds(int $interval): int
    {
        $doublings = min(max($this->consecutiveFailures - 1, 0), 16);

        return (int) min($interval * (1 << $doublings), self::MAX_BACKOFF_SECONDS);
    }

    private function log(string $line): void
    {
        ($this->logger)($line);
    }

    /**
     * The outside-in check: fetch the public health endpoint over real HTTP.
     * A non-2xx or a timeout is recorded against `web`, which is the signal an
     * in-app probe structurally cannot produce.
     *
     * @return int How many samples this actually persisted (0 or 2), so the
     *             caller's liveness claim counts writes rather than intentions.
     */
    private function probePublicUrl(HealthSampleRepository $samples, string $baseUrl): int
    {
        $target = rtrim($baseUrl, '/') . '/api/health';
        $start = microtime(true);

        $context = stream_context_create([
            'http' => [
                'timeout' => self::WEB_TIMEOUT_SECONDS,
                'ignore_errors' => true,
                'method' => 'GET',
                'header' => "User-Agent: whity-health-watch\r\n",
            ],
        ]);

        $body = @file_get_contents($target, false, $context);
        $ms = (int) round((microtime(true) - $start) * 1000);

        // $http_response_header is a magic local the stream wrapper injects — but
        // ONLY when a response was actually received. On connection-refused it
        // is never defined at all, so it can only be read on the success path.
        // (Reading it unconditionally is a runtime warning; PHPStan disagrees,
        // because it reasons about the variable as always-present.)
        $code = 0;
        if ($body !== false) {
            foreach ($http_response_header as $header) {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m) === 1) {
                    $code = (int) $m[1];
                    break;
                }
            }
        }

        [$status, $detail] = match (true) {
            $body === false || $code === 0 => [HealthStatus::Down, 'public URL unreachable'],
            $code >= 500 => [HealthStatus::Down, "public URL returned {$code}"],
            $code >= 400 => [HealthStatus::Degraded, "public URL returned {$code}"],
            $ms > self::WEB_DEGRADED_MS => [HealthStatus::Degraded, "public URL took {$ms}ms"],
            default => [HealthStatus::Operational, null],
        };

        try {
            $samples->record('web', $status, 'external', $ms, $detail);
            // `api` is the same request seen from outside: reachable or not.
            $samples->record(
                'api',
                $status === HealthStatus::Down ? HealthStatus::Down : HealthStatus::Operational,
                'external',
                $ms,
                $detail
            );

            return 2;
        } catch (Throwable $e) {
            $this->log('[health:watch] could not record web sample: ' . $e->getMessage());

            return 0;
        }
    }

    /**
     * The component catalogue this process samples, built once and reused for
     * every pass.
     *
     * Why plugins are loaded HERE. Collection happens only in this process —
     * neither host bootstrap runs in it — so a probe a plugin contributes would
     * be registered in the web workers, where nothing collects, and be absent
     * in the one place that does. Loading the plugin tree here is what makes a
     * contributed probe actually sampled rather than merely declarable.
     *
     * Loading is best-effort and non-fatal by construction: the loader already
     * error-boundaries each plugin, and any failure of the whole tree is logged
     * to the container log and leaves core's four probes collecting. A status
     * page that keeps reporting the database is far more valuable than one that
     * refuses to report anything because a plugin could not be read.
     *
     * The plugins register into a throwaway Router with no permission registry,
     * hook manager or role seeder: nothing in this process serves HTTP, so the
     * only capability being harvested is the probe declaration.
     */
    private function probeRegistry(): HealthProbeRegistry
    {
        if ($this->probes !== null) {
            return $this->probes;
        }

        $registry = new HealthProbeRegistry();
        $registry->registerCoreProbes();

        try {
            $loader = new PluginLoader(
                dirname(__DIR__, 3) . '/plugins',
                new Router(''),
                null,
                null,
                null,
                null,
                null,
                $registry
            );
            $loader->load();
        } catch (Throwable $e) {
            $this->log(
                '[health:watch] plugin probes unavailable, collecting core components only: '
                . $e->getMessage()
            );
        }

        return $this->probes = $registry;
    }

    /** Reconnect lazily: the database may come back between passes. */
    private function pdo(): ?PDO
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }

        try {
            return Database::connect()->getPdo();
        } catch (Throwable) {
            return null;
        }
    }
}
