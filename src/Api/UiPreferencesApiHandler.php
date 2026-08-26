<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Auth\JwtParser;
use Whity\Core\Branding\HostResolver;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Tenant\TenantContext;

/**
 * How this tenant wants its interface to PRESENT itself (#1068).
 *
 * GET /api/v1/ui/preferences — public, tenant-resolved, cacheable for a minute.
 *
 * WHY THIS IS NOT PART OF /api/v1/settings
 * ---------------------------------------
 * That surface is gated on `settings:read`, which is an ADMINISTRATIVE right
 * almost nobody holds. A presentation preference has to reach every reader —
 * the clerk who will never open the settings console is precisely the person
 * whose screens the preference governs — so a payload only administrators can
 * fetch would make the feature true for administrators and false for everybody
 * else. That is the "90% true" failure #1068 is written to avoid, reached
 * through the permission system instead of through a missed call site.
 *
 * WHY IT IS PUBLIC
 * ----------------
 * For the same reason {@see BrandingApiHandler::get()} is: the sign-in screen
 * and the public status page render before any session exists, and both of them
 * show dates. Tenant resolution therefore follows branding's exact ladder — the
 * authenticated JWT tenant if there is one, else the request host, else the
 * global layer — so a reader gets their organisation's answer whether or not
 * they have signed in yet.
 *
 * The disclosure is one boolean about how a page looks. It names no tenant, no
 * document and no person, and knowing it tells a stranger nothing they could
 * not learn by loading the login screen and looking.
 *
 * WHY IT IS A PAYLOAD AND NOT ONE FIELD
 * -------------------------------------
 * `hideDates` is the first display preference, not the only conceivable one, and
 * a second such key should be a field here rather than a second endpoint. The
 * shape is therefore an object from the start.
 *
 * WHAT IT IS NOT
 * --------------
 * It is NOT a data control. Every timestamp keeps being written, keeps being
 * returned by every other endpoint, and keeps its place in the audit trail. This
 * endpoint reports a rendering choice; nothing behind it is filtered, and a
 * client that ignores the answer sees exactly what it sees today.
 *
 * Holds no request state — safe for a FrankenPHP worker.
 */
final class UiPreferencesApiHandler
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly HostResolver $hostResolver,
        private readonly ?JwtParser $jwtParser = null,
    ) {
    }

    /**
     * GET /api/v1/ui/preferences — the acting tenant's display preferences.
     */
    public function get(Request $request): Response
    {
        try {
            $effective = $this->settings->effective($this->resolveDisplayTenant($request));

            // NOT CACHEABLE, and this is the one place branding's shape had to
            // be departed from. Branding's answer varies by HOST; this one
            // varies by WHO IS ASKING, because the tenant comes from the
            // caller's own token when they have one. A `public, max-age=60`
            // response — which this carried until a browser walk caught it —
            // is reused by the browser across the sign-in boundary, so a reader
            // who loaded the login screen (answered from the global layer) kept
            // that answer for the first minute of their session and saw every
            // date their tenant had asked to hide.
            //
            // A shared cache would be worse still: one tenant's preference
            // served to another's reader. `no-store` costs one small request
            // per page render, which is what branding already costs.
            return Response::json([
                'data' => [
                    'hideDates' => ($effective[SettingsRegistry::UI_HIDE_DATES] ?? 'false') === 'true',
                ],
            ], 200)->withHeaders([
                'Cache-Control' => 'no-store',
                'Vary' => 'Cookie, Authorization',
            ]);
        } catch (\Throwable $e) {
            error_log('[UiPreferencesApiHandler] get failed: ' . $e->getMessage());

            // Fail OPEN, and the choice is deliberate. The two ways to be wrong
            // are not symmetric in the way a security default's are: reporting
            // "hidden" when the tenant did not ask for it blanks every date on
            // every screen for as long as the database is unreachable, which
            // looks like the product has broken. Reporting "not hidden" leaves
            // the interface exactly as it was before the feature existed.
            //
            // This is not a confidentiality control — the timestamps are still
            // on the wire either way, and a reader who wants one can read it
            // from the API — so there is no secret to protect by failing shut.
            return Response::json(['data' => ['hideDates' => false]], 200);
        }
    }

    /**
     * The tenant whose presentation applies: the authenticated tenant, else the
     * one the request host names, else the global layer.
     *
     * IT READS THE TOKEN ITSELF, and that is not redundancy. A route on
     * {@see \Whity\Http\Middleware\EnforceTenantIsolation}'s public list is
     * returned to the pipeline BEFORE tenant resolution runs, so
     * `TenantContext` is empty here even for a caller holding a perfectly good
     * session. Reading the context first and stopping there is what a browser
     * walk caught: with the setting on for tenant 1 and an administrator signed
     * in, every screen still showed its dates, because the endpoint had fallen
     * through to the global layer for a request that named its tenant in the
     * cookie it carried.
     *
     * The context check stays first because a NON-public caller (a test, or
     * this handler mounted behind the middleware later) has one, and it is
     * cheaper and more authoritative than re-parsing.
     *
     * It does NOT lock the context. This route is public; a public request that
     * silently pinned a request-scoped tenant would be a side effect nothing
     * downstream expects. The claim is read and used, and that is all.
     */
    private function resolveDisplayTenant(Request $request): int
    {
        $ctx = TenantContext::getTenantId();
        if ($ctx !== null) {
            return $ctx;
        }

        $fromToken = $this->tenantFromToken($request);
        if ($fromToken !== null) {
            return $fromToken;
        }

        $host = $request->getHeader('X-Forwarded-Host') ?? $request->getHeader('Host') ?? '';

        return $this->hostResolver->resolveTenantIdByHost($host) ?? 0;
    }

    /**
     * The active tenant a valid session token names, or null.
     *
     * Fails SILENTLY on anything it does not like — no parser, no token, a
     * token that will not parse, a claim that is not an integer. This endpoint
     * has no authentication decision to make: the worst outcome of a token it
     * cannot read is that a reader gets the global layer's answer, which is the
     * behaviour that existed before the setting did.
     */
    private function tenantFromToken(Request $request): ?int
    {
        if ($this->jwtParser === null) {
            return null;
        }

        $token = $this->bearerOrCookieToken($request);
        if ($token === null) {
            return null;
        }

        $payload = $this->jwtParser->parse($token);
        if (!is_array($payload)) {
            return null;
        }

        $claim = $payload['active_tenant_id'] ?? $payload['tenant_id'] ?? null;

        return is_int($claim) || (is_string($claim) && ctype_digit($claim)) ? (int) $claim : null;
    }

    /**
     * The access token, from the Authorization header or the session cookie.
     *
     * Mirrors {@see TenantContext}'s own extraction. Duplicated rather than
     * exposed because that one is `private` and making it public would invite
     * handlers to resolve tenants for themselves, which is precisely what the
     * middleware exists to stop them doing.
     */
    private function bearerOrCookieToken(Request $request): ?string
    {
        $authHeader = $request->getHeader('Authorization');
        if ($authHeader !== null && preg_match('/^Bearer\s+(\S+)$/', $authHeader, $matches) === 1) {
            return $matches[1];
        }

        $cookieHeader = $request->getHeader('Cookie');
        if ($cookieHeader === null) {
            return null;
        }

        foreach (explode(';', $cookieHeader) as $cookie) {
            $parts = explode('=', trim($cookie), 2);
            if (count($parts) === 2 && $parts[0] === 'access_token') {
                return $parts[1];
            }
        }

        return null;
    }
}
