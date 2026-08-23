<?php

declare(strict_types=1);

namespace Whity\Core\Audit;

/**
 * Request-scoped holder for the acting user and client IP of the current request.
 *
 * The audit trail records WHO performed each action and from WHERE. That actor
 * identity and IP are request-specific, but the {@see AuditLogger} that writes
 * the records is process-scoped infrastructure subscribed to hooks fired deep
 * inside the handlers — it has no direct access to the {@see \Whity\Core\Request}.
 * This context bridges that gap: the HTTP layer (EnforceTenantIsolation, which
 * already decodes the JWT first) sets the actor/IP once per request, and the
 * AuditLogger reads them when it writes a record.
 *
 * Worker safety: like {@see \Whity\Core\Tenant\TenantContext}, this is the
 * sanctioned exception to the "no request state in statics" rule on FrankenPHP
 * persistent workers. It MUST be reset between requests. The HTTP kernel clears
 * it via {@see self::reset()} from its explicit request-scoped reset registry
 * (HttpKernel::resetRequestState(), WC-181) and the worker loop's finally block
 * calls {@see self::reset()} too. It holds only scalar identity data, never a
 * live request object.
 */
final class AuditContext
{
    /**
     * The acting user id for the current request, or null when there is none
     * (unauthenticated request, system/CLI action).
     */
    private static ?int $actorUserId = null;

    /**
     * The client IP for the current request, or null when unavailable.
     */
    private static ?string $ipAddress = null;

    /**
     * The queued job currently being executed, or null outside the worker (#935).
     *
     * Lives here rather than on {@see AuditOrigin} because it changes per unit of
     * work: one worker process runs many jobs, while an origin is decided once at
     * bootstrap and is immutable by design. {@see \Whity\Core\Queue\JobRunner}
     * sets it before a handler runs and {@see self::reset()} clears it in the same
     * `finally` that already clears the actor — so a job name cannot leak into the
     * next job any more than a tenant or an actor can.
     */
    private static ?string $job = null;

    /**
     * Set the acting user id and client IP for the current request.
     *
     * @param int|null    $actorUserId The authenticated user id, or null.
     * @param string|null $ipAddress   The client IP, or null when unavailable.
     * @return void
     */
    public static function set(?int $actorUserId, ?string $ipAddress): void
    {
        self::$actorUserId = $actorUserId;
        self::$ipAddress = $ipAddress;
    }

    /**
     * Get the acting user id for the current request.
     *
     * @return int|null The acting user id, or null when there is none.
     */
    public static function getActorUserId(): ?int
    {
        return self::$actorUserId;
    }

    /**
     * Get the client IP for the current request.
     *
     * @return string|null The client IP, or null when unavailable.
     */
    public static function getIpAddress(): ?string
    {
        return self::$ipAddress;
    }

    /**
     * Record which queued job is executing (#935).
     *
     * @param string|null $job The job's registry name, or null to clear it.
     * @return void
     */
    public static function setJob(?string $job): void
    {
        self::$job = $job;
    }

    /**
     * The queued job currently executing.
     *
     * @return string|null The job name, or null when not inside a job.
     */
    public static function getJob(): ?string
    {
        return self::$job;
    }

    /**
     * Clear the request-scoped actor and IP.
     *
     * Called between requests so no actor identity leaks into the next request
     * served by the same persistent worker.
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$actorUserId = null;
        self::$ipAddress = null;
        self::$job = null;
    }
}
