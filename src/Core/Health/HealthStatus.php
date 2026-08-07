<?php

declare(strict_types=1);

namespace Whity\Core\Health;

/**
 * The three states a monitored component can be in.
 *
 * Deliberately coarse. A public status page answers "can I use this right now",
 * not "what is the p99" — and every extra state is one more thing a reader has
 * to interpret. DEGRADED exists because the useful middle case (reachable but
 * slow, or a queue that is draining too slowly) is genuinely distinct from
 * both "fine" and "down", and hiding it inside OPERATIONAL is how a slow
 * decline goes unnoticed until it is an outage.
 */
enum HealthStatus: string
{
    case Operational = 'operational';
    case Degraded = 'degraded';
    case Down = 'down';

    /** Rank for aggregation: the worst component decides the overall banner. */
    public function severity(): int
    {
        return match ($this) {
            self::Operational => 0,
            self::Degraded => 1,
            self::Down => 2,
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

    /** Anything that is not fully operational counts against uptime. */
    public function countsAsDowntime(): bool
    {
        return $this !== self::Operational;
    }
}
