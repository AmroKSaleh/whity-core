<?php

declare(strict_types=1);

namespace Whity\Api;

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
    ) {
    }

    /**
     * GET /api/v1/ui/preferences — the acting tenant's display preferences.
     */
    public function get(Request $request): Response
    {
        try {
            $effective = $this->settings->effective($this->resolveDisplayTenant($request));

            // A minute's cache, matching branding. Long enough that the shell
            // is not re-asking on every navigation, short enough that an
            // administrator who has just flipped the setting sees it take
            // effect while they are still looking at the screen they changed it
            // from.
            return Response::json([
                'data' => [
                    'hideDates' => ($effective[SettingsRegistry::UI_HIDE_DATES] ?? 'false') === 'true',
                ],
            ], 200)->withHeaders(['Cache-Control' => 'public, max-age=60']);
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
     * The tenant whose presentation applies, on branding's exact ladder: the
     * authenticated tenant, else the one the request host names, else the
     * global layer.
     */
    private function resolveDisplayTenant(Request $request): int
    {
        $ctx = TenantContext::getTenantId();
        if ($ctx !== null) {
            return $ctx;
        }

        $host = $request->getHeader('X-Forwarded-Host') ?? $request->getHeader('Host') ?? '';

        return $this->hostResolver->resolveTenantIdByHost($host) ?? 0;
    }
}
