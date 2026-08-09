<?php

/**
 * Container healthcheck for the `health:watch` collector.
 *
 * Why this file exists
 * --------------------
 * The collector container is built FROM the FrankenPHP base image, which ships
 * a HEALTHCHECK that curls the FrankenPHP admin endpoint on localhost:2019. In
 * a container running a CLI loop there is no such server and never will be, so
 * the collector reported UNHEALTHY forever while collecting perfectly. A red
 * that is always red is worse than no signal at all: it trains everyone reading
 * `docker ps` to ignore the column, and the day the collector actually stops,
 * nothing about the output changes.
 *
 * What it checks
 * --------------
 * The only thing that matters about this container: is a sample being written?
 * Process liveness is not enough — a loop wedged on a socket, or unable to
 * reach Postgres, is a live process producing nothing, which is precisely the
 * silent failure the status page exists to make loud.
 *
 * So: healthy iff `health_samples` carries a row newer than the freshness
 * budget. That is the same reasoning StatusReport applies to a component
 * (silence is not health) turned back on the collector itself.
 *
 * The budget defaults to 5 minutes — comfortably more than the 60s pass
 * interval, so one slow pass is not an alarm — and is overridable per
 * deployment, since the pass interval is itself configurable.
 *
 * Exit 0 = healthy, 1 = unhealthy. Nothing is written; this only reads.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Whity\Database\Database;

// Database::connect() reads $_ENV. A container supplies its configuration as
// real environment variables and a checkout supplies it in .env, so accept
// both — exactly as bin/whity-cli and public/index.php do for the same reason.
// Neither source overwrites a value already present.
foreach (['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASSWORD'] as $key) {
    $value = getenv($key);
    if ($value !== false && !isset($_ENV[$key])) {
        $_ENV[$key] = $value;
    }
}

$envFile = dirname(__DIR__) . '/.env';
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        if (trim($line) === '' || str_starts_with(trim($line), '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        if (!isset($_ENV[$key])) {
            $_ENV[$key] = trim($value);
        }
    }
}

$maxAgeSeconds = (int) (getenv('WHITY_HEALTH_SAMPLE_MAX_AGE') ?: 300);

try {
    $pdo = Database::connect()->getPdo();
    $stmt = $pdo->query('SELECT MAX(observed_at) AS newest FROM health_samples');
    $newest = $stmt === false ? null : ($stmt->fetch(\PDO::FETCH_ASSOC)['newest'] ?? null);
} catch (\Throwable $e) {
    // Cannot even ask. From this container's point of view that IS unhealthy:
    // with no database there is nowhere to record an observation, so nothing is
    // being collected no matter how alive the loop is.
    fwrite(STDERR, '[health-watch] healthcheck could not read health_samples: ' . $e->getMessage() . "\n");
    exit(1);
}

if ($newest === null) {
    fwrite(STDERR, "[health-watch] healthcheck: no samples recorded yet\n");
    exit(1);
}

// Timestamps are stored UTC-naive; read them as UTC rather than the container's
// local zone, or a non-UTC container would compute an age off by hours and
// flap.
$observedAt = strtotime((string) $newest . ' UTC');
if ($observedAt === false) {
    fwrite(STDERR, "[health-watch] healthcheck: unparseable observed_at '{$newest}'\n");
    exit(1);
}

$age = time() - $observedAt;
if ($age > $maxAgeSeconds) {
    fwrite(STDERR, "[health-watch] healthcheck: newest sample is {$age}s old (budget {$maxAgeSeconds}s)\n");
    exit(1);
}

exit(0);
