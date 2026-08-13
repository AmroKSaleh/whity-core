<?php

declare(strict_types=1);

namespace Whity\Sdk\Health;

/**
 * What one health probe observed (SDK 1.19).
 *
 * Deliberately the SAME three states the public status page renders, and no
 * more. A status page answers "can I use this right now", not "what is the
 * p99" — every extra state is one more thing a reader has to interpret.
 *
 * The constructor is private on purpose: a result can only be built through
 * {@see operational()}, {@see degraded()} or {@see down()}, so `status` is
 * always one of the three strings the host knows how to map. A plugin cannot
 * hand the host a state it has no rendering for.
 *
 * `detail` is an OPERATOR-facing note (a message, a threshold that was
 * crossed). The host stores it but never serves it on the public status page —
 * error text names hosts, drivers and paths. Do not put anything in it that
 * would be a leak if a future host chose to show it.
 */
final class ProbeResult
{
    public const STATUS_OPERATIONAL = 'operational';
    public const STATUS_DEGRADED = 'degraded';
    public const STATUS_DOWN = 'down';

    /**
     * @param string      $status    One of the STATUS_* constants.
     * @param int|null    $latencyMs Round-trip in milliseconds, where meaningful.
     * @param string|null $detail    Operator-facing note; never shown publicly.
     */
    private function __construct(
        public readonly string $status,
        public readonly ?int $latencyMs = null,
        public readonly ?string $detail = null,
    ) {
    }

    /** The component answered and is healthy. */
    public static function operational(?int $latencyMs = null, ?string $detail = null): self
    {
        return new self(self::STATUS_OPERATIONAL, $latencyMs, $detail);
    }

    /**
     * Reachable but not well — slow, backlogged, partially available.
     *
     * The detail is required here because "degraded" without a reason is not
     * actionable: the operator reading the log needs to know WHAT degraded.
     */
    public static function degraded(string $detail, ?int $latencyMs = null): self
    {
        return new self(self::STATUS_DEGRADED, $latencyMs, $detail);
    }

    /** The component did not answer, or answered that it cannot serve. */
    public static function down(string $detail, ?int $latencyMs = null): self
    {
        return new self(self::STATUS_DOWN, $latencyMs, $detail);
    }
}
