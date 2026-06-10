<?php

declare(strict_types=1);

namespace Whity\Core\Tenant;

use Psr\Log\LoggerInterface;
use Whity\Auth\JwtParser;
use Whity\Core\Request;

/**
 * Request-scoped tenant context holder.
 *
 * Maintains the current request's tenant ID. Once set (via {@see self::setTenantId()}
 * or {@see self::resolve()}), the context is locked to prevent plugins or handlers
 * from mutating it and escaping tenant boundaries. This enforces strict tenant
 * isolation for the duration of request processing.
 *
 * Tenant ids are integers in this codebase. The special tenant id 0 denotes the
 * SYSTEM tenant and is a fully valid, settable value (distinct from the "unset"
 * null state). Downstream code (e.g. system-user detection) relies on
 * {@see self::getTenantId()} === 0.
 *
 * FrankenPHP worker lifecycle: workers persist across requests, so this static
 * state MUST be explicitly cleared between requests via {@see self::reset()}.
 * This is the framework's sanctioned exception to the "no request state in
 * statics" rule; the HTTP kernel/middleware own the reset.
 */
class TenantContext
{
    /**
     * The current tenant ID, or null when no tenant has been resolved/set.
     */
    private static ?int $tenantId = null;

    /**
     * Whether the context is locked (prevents further tenant mutations).
     */
    private static bool $locked = false;

    /**
     * Whether system mode (tenant-scoping bypass) is active.
     *
     * System mode is reserved for trusted, non-request contexts such as
     * migrations and admin CLI tools. It is NOT derived from request input.
     */
    private static bool $systemMode = false;

    /**
     * Optional PSR-3 logger used for audit logging of privileged operations
     * (system-mode activation/deactivation). When null, no audit output is
     * emitted (keeps tests output-clean; production wires a real logger).
     */
    private static ?LoggerInterface $logger = null;

    /**
     * Inject the PSR-3 logger used for audit logging.
     *
     * Pass null to detach the logger (used by tests to avoid state leakage).
     *
     * @param LoggerInterface|null $logger The PSR-3 logger instance, or null.
     * @return void
     */
    public static function setLogger(?LoggerInterface $logger): void
    {
        self::$logger = $logger;
    }

    /**
     * Set the current tenant ID for this request.
     *
     * Once set, the context is locked and cannot be changed. Subsequent attempts
     * to set a tenant ID throw a RuntimeException until {@see self::reset()} is
     * called. This prevents handlers/plugins from escaping tenant boundaries.
     *
     * @param int $tenantId The tenant ID for this request (0 = system tenant).
     * @return void
     * @throws \RuntimeException If the context is already locked.
     */
    public static function setTenantId(int $tenantId): void
    {
        if (self::$locked) {
            throw new \RuntimeException('TenantContext is locked and cannot be mutated');
        }

        self::$tenantId = $tenantId;
        self::$locked = true;
    }

    /**
     * Resolve the current tenant from an authenticated request.
     *
     * When an upstream middleware has already decoded the token and stashed the
     * claims on the request ({@see Request::ATTR_JWT_CLAIMS}, WC-159), those
     * claims are reused without re-decoding. Otherwise the JWT is extracted from
     * the Authorization header ("Bearer <token>") or the access_token cookie and
     * validated via {@see JwtParser}. Either way the resolved tenant id is locked
     * into the context for the rest of the request.
     *
     * There is NO silent fallback: a missing token, an invalid/expired token,
     * or a token without a valid integer tenant_id claim all throw a
     * {@see TenantResolutionException}. (The acceptance criteria use a string
     * example tenant id, but this codebase uses integer ids — 0 = system tenant
     * — so numeric claims are coerced to int and non-numeric claims rejected.)
     *
     * @param Request   $request    The incoming HTTP request.
     * @param JwtParser $jwtParser  Read-only JWT validator/parser.
     * @return int The resolved tenant id (0 = system tenant).
     * @throws TenantResolutionException If the tenant cannot be resolved.
     * @throws \RuntimeException If the context is already locked (resolve()
     *                           called twice without an intervening reset()).
     */
    public static function resolve(Request $request, JwtParser $jwtParser): int
    {
        // Single decode (WC-159): when an upstream middleware has already
        // decoded the token and stashed the claims on the request, reuse them.
        // A stashed null means the token was checked and rejected upstream.
        if ($request->hasAttribute(Request::ATTR_JWT_CLAIMS)) {
            /** @var array<string, mixed>|null $payload */
            $payload = $request->getAttribute(Request::ATTR_JWT_CLAIMS);
            if ($payload === null) {
                throw self::extractToken($request) === null
                    ? TenantResolutionException::missingToken()
                    : TenantResolutionException::invalidToken();
            }

            return self::lockTenantFromPayload($payload);
        }

        $token = self::extractToken($request);
        if ($token === null) {
            throw TenantResolutionException::missingToken();
        }

        $payload = $jwtParser->parse($token);
        if ($payload === null) {
            throw TenantResolutionException::invalidToken();
        }

        return self::lockTenantFromPayload($payload);
    }

    /**
     * Validate the tenant_id claim of a decoded payload and lock it in.
     *
     * @param array<string, mixed> $payload The decoded JWT claims.
     * @return int The resolved tenant id (0 = system tenant).
     * @throws TenantResolutionException If the tenant_id claim is missing/invalid.
     * @throws \RuntimeException If the context is already locked.
     */
    private static function lockTenantFromPayload(array $payload): int
    {
        if (!array_key_exists('tenant_id', $payload)) {
            throw TenantResolutionException::invalidTenantClaim('missing tenant_id claim');
        }

        $claim = $payload['tenant_id'];
        if (!is_int($claim) && !(is_string($claim) && ctype_digit($claim))) {
            throw TenantResolutionException::invalidTenantClaim('tenant_id claim is not a valid integer');
        }

        $tenantId = (int) $claim;
        self::setTenantId($tenantId);

        return $tenantId;
    }

    /**
     * Get the current tenant ID.
     *
     * @return int|null The tenant ID (0 = system tenant), or null if not set.
     */
    public static function getTenantId(): ?int
    {
        return self::$tenantId;
    }

    /**
     * Alias of {@see self::getTenantId()}.
     *
     * Provided to match the WC-18 acceptance-criteria wording (getId()).
     *
     * @return int|null The tenant ID (0 = system tenant), or null if not set.
     */
    public static function getId(): ?int
    {
        return self::$tenantId;
    }

    /**
     * Check if a tenant is currently set.
     *
     * Note: tenant id 0 (system tenant) counts as set.
     *
     * @return bool True if a tenant ID is set, false otherwise.
     */
    public static function hasTenant(): bool
    {
        return self::$tenantId !== null;
    }

    /**
     * Enable or disable system mode (tenant-scoping bypass).
     *
     * System mode is for trusted, non-request operations (migrations, admin CLI
     * tooling) that must operate across all tenants. Every transition is
     * audit-logged with a structured record identifying who/what triggered it,
     * because bypassing tenant scoping is a privileged, security-sensitive act.
     *
     * @param bool                 $enabled Whether to enable system mode.
     * @param string               $actor   Identifier of the operator/process
     *                                       enabling the bypass (who/what).
     * @param array<string, mixed> $context Additional structured audit context
     *                                       (e.g. reason, command, run id).
     * @return void
     */
    public static function setSystemMode(bool $enabled, string $actor, array $context = []): void
    {
        self::$systemMode = $enabled;

        self::audit(
            $enabled
                ? 'TenantContext system mode ENABLED (tenant scoping bypassed)'
                : 'TenantContext system mode DISABLED (tenant scoping restored)',
            array_merge($context, [
                'event' => 'tenant_context.system_mode',
                'enabled' => $enabled,
                'actor' => $actor,
                'tenant_id' => self::$tenantId,
            ])
        );
    }

    /**
     * Whether system mode (tenant-scoping bypass) is currently active.
     *
     * @return bool True if system mode is active.
     */
    public static function isSystemMode(): bool
    {
        return self::$systemMode;
    }

    /**
     * Reset the context to its initial state.
     *
     * Clears the tenant ID, unlocks the context, and disables system mode so it
     * can be set again. This MUST be called between requests in persistent
     * (FrankenPHP) workers to prevent tenant or privilege state from leaking
     * into a subsequent request. The injected logger is intentionally preserved
     * across resets (it is process-scoped infrastructure, not request state).
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$tenantId = null;
        self::$locked = false;
        self::$systemMode = false;
    }

    /**
     * Extract a JWT from the request (Authorization Bearer header or
     * access_token cookie), mirroring the EnforceTenantIsolation middleware.
     *
     * @param Request $request The incoming HTTP request.
     * @return string|null The extracted token, or null if none present.
     */
    private static function extractToken(Request $request): ?string
    {
        $authHeader = $request->getHeader('Authorization');
        if ($authHeader !== null && preg_match('/^Bearer\s+(\S+)$/', $authHeader, $matches) === 1) {
            return $matches[1];
        }

        $cookieHeader = $request->getHeader('Cookie');
        if ($cookieHeader !== null) {
            foreach (explode(';', $cookieHeader) as $cookie) {
                $parts = explode('=', trim($cookie), 2);
                if (count($parts) === 2 && $parts[0] === 'access_token') {
                    return $parts[1];
                }
            }
        }

        return null;
    }

    /**
     * Emit a structured audit log record if a logger is configured.
     *
     * @param string               $message The human-readable audit message.
     * @param array<string, mixed> $context Structured audit context.
     * @return void
     */
    private static function audit(string $message, array $context): void
    {
        if (self::$logger !== null) {
            self::$logger->warning($message, $context);
        }
    }
}
