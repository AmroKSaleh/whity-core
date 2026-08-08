<?php

declare(strict_types=1);

namespace Whity\Cli\Commands;

use PDO;
use Throwable;
use Whity\Core\Health\HealthProbe;
use Whity\Core\Health\HealthSampleRepository;
use Whity\Core\Health\HealthStatus;
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
 *  1. probes the internal components (database, queue, scheduler, render), and
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

    private ?PDO $pdo = null;

    /** @var callable(int): void */
    private $sleeper;

    public function __construct(?PDO $pdo = null, ?callable $sleeper = null)
    {
        $this->pdo = $pdo;
        $this->sleeper = $sleeper ?? static function (int $seconds): void {
            if ($seconds > 0) {
                sleep($seconds);
            }
        };
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

        $passes = 0;
        while (true) {
            $this->pass(is_string($url) && $url !== '' ? $url : null);
            $passes++;

            if ($once) {
                break;
            }
            ($this->sleeper)($interval);
        }

        return 0;
    }

    /** One full pass: internal components, then the public URL, then GC. */
    private function pass(?string $publicUrl): void
    {
        $pdo = $this->pdo();
        if ($pdo === null) {
            // The database is the one dependency this process cannot work
            // without — there is nowhere to record the observation. Say so on
            // stderr (the container log) and try again next pass.
            fwrite(STDERR, "[health:watch] database unreachable; cannot record samples\n");

            return;
        }

        $samples = new HealthSampleRepository($pdo);

        try {
            $renderUrl = getenv('WHITY_RENDER_URL') ?: null;
            (new HealthProbe($pdo, $samples, is_string($renderUrl) ? $renderUrl : null))->runAll();
        } catch (Throwable $e) {
            fwrite(STDERR, '[health:watch] internal probe failed: ' . $e->getMessage() . "\n");
        }

        if ($publicUrl !== null) {
            $this->probePublicUrl($samples, $publicUrl);
        }

        try {
            $samples->pruneOlderThan(gmdate('Y-m-d H:i:s', time() - (self::RETENTION_DAYS * 86400)));
        } catch (Throwable) {
            // Retention is best-effort; never let GC failure stop collection.
        }
    }

    /**
     * The outside-in check: fetch the public health endpoint over real HTTP.
     * A non-2xx or a timeout is recorded against `web`, which is the signal an
     * in-app probe structurally cannot produce.
     */
    private function probePublicUrl(HealthSampleRepository $samples, string $baseUrl): void
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
        } catch (Throwable $e) {
            fwrite(STDERR, '[health:watch] could not record web sample: ' . $e->getMessage() . "\n");
        }
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
