<?php
declare(strict_types=1);
namespace Whity\Api;

use Whity\Auth\RoleChecker;
use Whity\Core\PluginLoader;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Tenant\TenantContext;

/**
 * Theme Override API handler (WC-242).
 *
 * Exposes `GET /api/v1/theme` — the effective set of design-token CSS
 * variable overrides for the calling tenant, contributed by AT MOST ONE
 * installed plugin implementing {@see \Whity\Sdk\PluginThemeInterface} (see
 * {@see PluginLoader::getThemeOverrideRoute()} for the ownership/aggregation
 * rules). Public and always degrades to `{"data": {}}` — a plugin error,
 * a missing plugin, or a failed permission check never breaks page render.
 *
 * Defense in depth: the plugin's own route is already permission-gated (if
 * it declares a permission), but this handler additionally revalidates every
 * returned key against the design system's own known token names and every
 * value against a strict `#rrggbb` hex pattern before it is ever handed back
 * to the client for interpolation into a `<style>` tag — never trust a
 * plugin blindly, mirroring the Blocks DSL renderer's own "the host has
 * already validated, but the last line of defense revalidates" philosophy.
 *
 * Holds no request state — safe for a FrankenPHP worker. The allow-list of
 * known token names IS cached in a static property: it is immutable
 * configuration read from a committed file, not request/tenant state.
 */
final class ThemeApiHandler
{
    private const THEME_JSON_PATH = __DIR__ . '/../design/tokens/generated/theme.json';

    private const HEX_COLOR_RE = '/^#[0-9A-Fa-f]{6}$/';

    /** @var list<string>|null */
    private static ?array $allowedTokenNamesCache = null;

    public function __construct(
        private readonly PluginLoader $pluginLoader,
        private readonly ?RoleChecker $roleChecker = null,
    ) {
    }

    /** GET /api/v1/theme — public effective theme overrides. */
    public function get(Request $request): Response
    {
        $descriptor = $this->pluginLoader->getThemeOverrideRoute();
        if ($descriptor === null) {
            return Response::json(['data' => []], 200);
        }

        if (!$this->isAuthorized($request, $descriptor['requiredPermission'])) {
            // Fail-closed on the permission check, but fail-OPEN on the
            // overall page render: no overrides, not an error page.
            return Response::json(['data' => []], 200);
        }

        try {
            /** @var Response $result */
            $result = ($descriptor['handler'])($request, []);
        } catch (\Throwable $e) {
            error_log('[ThemeApiHandler] plugin theme-override handler threw: ' . $e->getMessage());
            return Response::json(['data' => []], 200);
        }

        if ($result->getStatusCode() !== 200) {
            return Response::json(['data' => []], 200);
        }

        $decoded = json_decode($result->getBody(), true);
        $raw = is_array($decoded) && isset($decoded['data']) && is_array($decoded['data'])
            ? $decoded['data']
            : [];

        return Response::json(['data' => $this->sanitizeOverrides($raw)], 200);
    }

    /**
     * Keep only entries whose key is a KNOWN design-token name and whose
     * value is a well-formed `#rrggbb` hex string. Anything else is silently
     * dropped — never trust a plugin blindly, even one already
     * permission-gated at its own route.
     *
     * @param array<mixed, mixed> $raw
     * @return array<string, string>
     */
    private function sanitizeOverrides(array $raw): array
    {
        $allowed = $this->allowedTokenNames();
        $sanitized = [];
        foreach ($raw as $key => $value) {
            if (
                is_string($key)
                && in_array($key, $allowed, true)
                && is_string($value)
                && preg_match(self::HEX_COLOR_RE, $value) === 1
            ) {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }

    /**
     * The known design-token color names, read once from the generated
     * master theme (src/design/tokens/generated/theme.json — see
     * `npm run tokens:generate theme`). Returns an empty list (fail-closed:
     * no overrides accepted) if the file is missing or malformed.
     *
     * @return list<string>
     */
    private function allowedTokenNames(): array
    {
        if (self::$allowedTokenNamesCache !== null) {
            return self::$allowedTokenNamesCache;
        }

        $names = [];
        $raw = is_file(self::THEME_JSON_PATH) ? file_get_contents(self::THEME_JSON_PATH) : false;
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded) && isset($decoded['colors']) && is_array($decoded['colors'])) {
                $names = array_keys($decoded['colors']);
            }
        }

        /** @var list<string> $names */
        self::$allowedTokenNamesCache = $names;

        return $names;
    }

    /**
     * True when no permission is required, or the caller holds it. Mirrors
     * {@see BrandingApiHandler}'s own authorize() shape but degrades to
     * false (never a Response) so the caller can choose to fail-open.
     */
    private function isAuthorized(Request $request, ?string $permission): bool
    {
        if ($permission === null) {
            return true;
        }
        if ($this->roleChecker === null) {
            return false;
        }
        $tenantId = TenantContext::getTenantId();
        if ($tenantId === null) {
            return false;
        }
        $actor = $request->user;
        $userId = is_object($actor) && isset($actor->profile_id) && is_int($actor->profile_id)
            ? $actor->profile_id
            : null;

        return $userId !== null && $this->roleChecker->hasPermissionForProfile($userId, $permission, $tenantId);
    }
}
