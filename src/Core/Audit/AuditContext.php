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
    }
}
