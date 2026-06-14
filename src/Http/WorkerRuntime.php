<?php

declare(strict_types=1);

namespace Whity\Http;

/**
 * Pure decision helpers for the FrankenPHP persistent-worker loop (WC-182).
 *
 * The worker loop in public/index.php cannot be unit-tested directly (it depends
 * on frankenphp_handle_request), so the two per-request policy decisions that
 * previously lived inline are extracted here as pure, deterministic functions:
 *
 *  - shouldLogLifecycle(): whether to emit the per-request "Request start" /
 *    "Request end" lifecycle log lines. These are useful when tracing locally
 *    but in production they flood the log with one pair of lines per request,
 *    so they are gated behind development/DEBUG.
 *
 *  - shouldCollectCycles(): whether to force gc_collect_cycles() for a given
 *    request iteration. Forcing a full cycle collection on EVERY request adds
 *    avoidable CPU work to the hot path. In production the forced sweep becomes
 *    opportunistic (only every GC_CADENCE iterations), letting PHP's automatic
 *    cycle collector handle the gaps; the worker's own memory-recycle safety
 *    path (Kernel::hasExceededMemoryLimit) remains the backstop and is
 *    unaffected. In development/DEBUG the sweep still runs every request so
 *    leaks surface eagerly while iterating.
 *
 * "Debug logging is on" is defined as: APP_ENV === 'development' OR a truthy
 * DEBUG flag. No prior DEBUG convention existed in the repo, so a conservative
 * boolean parse is used (1/true/yes/on, case-insensitive) and everything else
 * — including unset — is treated as off.
 */
final class WorkerRuntime
{
    /**
     * Production cadence for the forced cycle collection: force a sweep on
     * every GC_CADENCE-th worker iteration. PHP's automatic cycle collector
     * still runs in between, and the memory-recycle path is the hard backstop,
     * so this only bounds worst-case uncollected-cycle growth without paying
     * the full-sweep cost on every single request.
     */
    public const int GC_CADENCE = 50;

    /**
     * Whether per-request lifecycle log lines should be emitted.
     *
     * True only in development or when a truthy DEBUG flag is set; production
     * (the default) stays quiet so the log is not spammed one pair of lines per
     * request.
     *
     * @param array<string, mixed> $env Environment map (typically $_ENV).
     */
    public static function shouldLogLifecycle(array $env): bool
    {
        return self::isDebug($env);
    }

    /**
     * Whether a forced gc_collect_cycles() should run for this iteration.
     *
     * In development/DEBUG: every request (eager reclamation while iterating).
     * In production: opportunistic — only on the fixed GC_CADENCE boundary, so
     * the first request and the requests between boundaries skip the forced
     * sweep and rely on PHP's automatic collector. The 0th iteration never
     * forces a sweep (0 % N === 0 would otherwise fire on the very first
     * request, defeating the point).
     *
     * @param int                  $requestIndex Zero-based worker loop counter
     *                                            (the loop's $nbRequests).
     * @param array<string, mixed> $env          Environment map (typically $_ENV).
     */
    public static function shouldCollectCycles(int $requestIndex, array $env): bool
    {
        if (self::isDebug($env)) {
            return true;
        }

        return $requestIndex > 0 && $requestIndex % self::GC_CADENCE === 0;
    }

    /**
     * Resolve whether debug-level behavior is enabled for the given environment.
     *
     * @param array<string, mixed> $env
     */
    private static function isDebug(array $env): bool
    {
        if (($env['APP_ENV'] ?? 'production') === 'development') {
            return true;
        }

        return self::isTruthyFlag($env['DEBUG'] ?? null);
    }

    /**
     * Conservative truthiness parse for an env flag.
     *
     * Accepts 1/true/yes/on (case-insensitive) and boolean true; everything
     * else — including null/unset, empty string and 0/false/off/no — is false.
     */
    private static function isTruthyFlag(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (!is_scalar($value)) {
            return false;
        }

        return in_array(
            strtolower(trim((string)$value)),
            ['1', 'true', 'yes', 'on'],
            true
        );
    }
}
