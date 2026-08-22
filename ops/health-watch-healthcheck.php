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
 * What it checks, and what it used to check (WC-766)
 * --------------------------------------------------
 * The only thing that matters about this container: is THIS PROCESS writing
 * samples? Process liveness is not enough — a loop wedged on a socket, or
 * unable to reach Postgres, is a live process producing nothing, which is
 * precisely the silent failure the status page exists to make loud.
 *
 * The first version of this probe asked the database instead: "is there a row
 * in `health_samples` newer than the freshness budget?" That is a fact about
 * the TABLE, not about this container, and the difference is the whole bug.
 * `health_samples` is append-only and shared: rows from a second collector, a
 * backfill, a restored dump, or the ones this very process wrote before it
 * wedged all keep the answer green. Worse, the question it could never answer
 * is the important one — "I have recorded nothing" — because a table full of
 * somebody else's recent rows reads exactly like success.
 *
 * A healthcheck that cannot go red when the thing it names is dead is worse
 * than none, because it actively asserts the opposite.
 *
 * So the probe now reads the HEARTBEAT this collector writes after every pass
 * (see Whity\Cli\Commands\HealthWatchCommand::writeHeartbeat). `last_sample_at`
 * is set by, and only by, the process making the claim, and only when an INSERT
 * actually succeeded. Missing file, null timestamp, or a stale one all mean the
 * same thing and all go red:
 *
 *   - no heartbeat at all        -> this collector has never completed a pass
 *   - last_sample_at is null     -> it is running and has recorded NOTHING
 *   - last_sample_at is stale    -> it stopped recording, whatever it is doing
 *
 * It reads no database, which is deliberate twice over: the claim is about this
 * process, and a probe that needs the dependency it is reporting on cannot
 * distinguish "the collector is down" from "the database is down".
 *
 * The budget defaults to 5 minutes — comfortably more than the 60s pass
 * interval, so one slow pass is not an alarm — and is overridable per
 * deployment, since the pass interval is itself configurable.
 *
 * Exit 0 = healthy, 1 = unhealthy. Nothing is written; this only reads.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Whity\Cli\Commands\HealthWatchCommand;

/** Both halves resolve the path the same way, so they cannot drift apart. */
$path = HealthWatchCommand::defaultHeartbeatPath();

$maxAgeSeconds = (int) (getenv('WHITY_HEALTH_SAMPLE_MAX_AGE') ?: 300);

$fail = static function (string $message) use ($path): never {
    fwrite(STDERR, '[health-watch] healthcheck: ' . $message . " (heartbeat: {$path})\n");
    exit(1);
};

if (!is_file($path)) {
    // Nothing has ever completed a pass in this container. During the compose
    // `start_period` that is expected and Docker ignores the result; after it,
    // it is the collector failing to start, which is exactly what the old probe
    // could not say.
    $fail('this collector has recorded no samples yet — no heartbeat file');
}

$raw = @file_get_contents($path);
if ($raw === false || $raw === '') {
    $fail('heartbeat file is unreadable or empty');
}

/** @var mixed $decoded */
$decoded = json_decode((string) $raw, true);
if (!is_array($decoded)) {
    $fail('heartbeat file is not valid JSON');
}

/** @var array<string, mixed> $decoded */
$lastSampleAt = $decoded['last_sample_at'] ?? null;
$failures = (int) ($decoded['consecutive_failures'] ?? 0);
$lastError = is_string($decoded['last_error'] ?? null) ? (string) $decoded['last_error'] : null;

if (!is_int($lastSampleAt) && !is_float($lastSampleAt)) {
    // Running, and has never persisted an observation. The collector is alive
    // and useless, which the previous probe reported as healthy.
    $fail(sprintf(
        'this collector is running but has recorded NOTHING (%d consecutive failed pass(es)%s)',
        $failures,
        $lastError === null ? '' : ': ' . $lastError,
    ));
}

$age = time() - (int) $lastSampleAt;
if ($age > $maxAgeSeconds) {
    $fail(sprintf(
        'this collector last recorded a sample %ds ago, budget %ds (%d consecutive failed pass(es)%s)',
        $age,
        $maxAgeSeconds,
        $failures,
        $lastError === null ? '' : ': ' . $lastError,
    ));
}

exit(0);
