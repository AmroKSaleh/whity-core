<?php

declare(strict_types=1);

namespace Whity\Core\Health;

/**
 * The states a monitored component can be in.
 *
 * Deliberately coarse. A public status page answers "can I use this right now",
 * not "what is the p99" — and every extra state is one more thing a reader has
 * to interpret. DEGRADED exists because the useful middle case (reachable but
 * slow, or a queue that is draining too slowly) is genuinely distinct from
 * both "fine" and "down", and hiding it inside OPERATIONAL is how a slow
 * decline goes unnoticed until it is an outage.
 *
 * UNKNOWN exists because the probe's honest answer is sometimes "I could not
 * measure this", and the alternative was reporting that as OPERATIONAL. It was
 * not hypothetical: the scheduler probe reads MAX(last_run_at) of enabled
 * schedules, returned OPERATIONAL when the column was NULL, and so reported
 * 100% uptime for a component that had never run once. A check that cannot
 * fail until the thing has worked at least once is not a check.
 *
 * UNKNOWN is NOT downtime and must never be counted as such — an unconfigured
 * render tier has not had an outage. It is excluded from uptime entirely
 * rather than scored either way, which is the same position this project's
 * watchdog already takes on its history bar: a day nobody looked at is drawn
 * as a gap, not as green.
 */
enum HealthStatus: string
{
    case Operational = 'operational';
    /** Not measurable right now — not a verdict about the component. */
    case Unknown = 'unknown';
    case Degraded = 'degraded';
    case Down = 'down';

    /** Rank for aggregation: the worst component decides the overall banner. */
    public function severity(): int
    {
        return match ($this) {
            self::Operational => 0,
            // Above operational so one unmeasured component stops the banner
            // claiming everything is fine, and below degraded so it never
            // outranks an actual fault in the roll-up.
            self::Unknown => 1,
            self::Degraded => 2,
            self::Down => 3,
        };
    }

    /** Worst-of, used to roll component states up into one overall state. */
    public static function worst(self ...$statuses): self
    {
        $worst = self::Operational;
        foreach ($statuses as $status) {
            if ($status->severity() > $worst->severity()) {
                $worst = $status;
            }
        }

        return $worst;
    }

    /**
     * Whether this state counts against uptime.
     *
     * UNKNOWN does not: it is the absence of a measurement, and charging it as
     * downtime would invent an outage out of a probe that never ran. It is also
     * excluded from the denominator — see HealthSampleRepository::countsSince().
     */
    public function countsAsDowntime(): bool
    {
        return $this !== self::Operational && $this !== self::Unknown;
    }
}
