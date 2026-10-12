<?php

declare(strict_types=1);

namespace Whity\Core\Plugin;

use Whity\Core\Router;
use Whity\Sdk\PluginNamespace;

/**
 * The plugin webhook routes that bypass tenant resolution (WHIT-671).
 *
 * WHY THIS EXISTS
 * ---------------
 * An external service delivering a notification (a billing or identity
 * provider's `subscription.updated`, say) holds no session and never will, so
 * {@see \Whity\Http\Middleware\EnforceTenantIsolation} 401s it before it is
 * routed. Core's own such endpoints are listed by hand in that middleware.
 * Before this, a plugin could not get onto that list at all, and core must
 * not name a product to put it there.
 *
 * WHAT A PLUGIN MAY MAKE PUBLIC: exactly one shape, decided by
 * {@see self::refusal()}. All of these must hold:
 *   - the method is POST (a notification, never a read);
 *   - the path is `/api/<the plugin's own slug>/webhooks/<name>`, with
 *     <name> matching `[a-z0-9][a-z0-9-]{0,62}` and no parameters, so a plugin
 *     can never open another plugin's or core's paths, and nothing can be
 *     public beneath an open prefix;
 *   - the route demands no permission and no role (asking for both is a
 *     contradiction, so it is refused rather than guessed at).
 *
 * WHAT IS ADMITTED AT REQUEST TIME is narrower than "this path": the METHOD
 * must be the registered one (POST), and the route the router actually
 * resolves for the request must belong to the plugin that registered it
 * ({@see self::admits()}). So a later route on the same path with another
 * method, or a core PATTERN route that happens to capture the plugin's literal
 * path, is never reached without a tenant because of this registry — the
 * bypass is tied to the plugin's own handler, not to a string.
 *
 * PUBLIC MEANS ONLY "NO TENANT RESOLUTION". It does not authenticate the
 * caller; the plugin's handler must, by verifying the sender's signature over
 * the raw body before acting on anything in it. That is the same rule core's
 * own webhooks follow.
 *
 * PER PROCESS. Every FrankenPHP worker loads plugins at boot and fills its own
 * copy; a disabled, reloaded or uninstalled plugin's routes are removed with
 * its other routes.
 */
final class PluginPublicRoutes
{
    /** The only method a public plugin route may use. */
    public const METHOD = 'POST';

    private const NAME_PATTERN = '[a-z0-9][a-z0-9-]{0,62}';

    /** @var array<string, string> "METHOD path" => owner (the plugin's namespace prefix) */
    private static array $owners = [];

    /**
     * Why a declared public route is refused, or null when it is acceptable.
     */
    public static function refusal(
        string $pluginName,
        string $method,
        string $path,
        mixed $requiredPermission,
        mixed $requiredRole,
    ): ?string {
        if (strtoupper($method) !== self::METHOD) {
            return 'a public plugin route must be POST';
        }
        $slug = PluginNamespace::slug($pluginName);
        if ($slug === null) {
            return 'the plugin name yields no namespace slug';
        }
        // `D`: `$` must not also match before a trailing newline.
        $shape = '#^/api/' . preg_quote($slug, '#') . '/webhooks/' . self::NAME_PATTERN . '$#D';
        if (preg_match($shape, $path) !== 1) {
            return "a public plugin route must be exactly /api/{$slug}/webhooks/<name>, <name> matching " . self::NAME_PATTERN;
        }
        if ($requiredPermission !== null || $requiredRole !== null) {
            return 'a public plugin route cannot also require a permission or a role';
        }
        return null;
    }

    /** Record a served route (the router's /api/v1/... path) as public. */
    public static function add(string $owner, string $method, string $servedPath): void
    {
        self::$owners[strtoupper($method) . ' ' . $servedPath] = $owner;
    }

    /** Forget every public route an owner (a plugin's namespace) registered. */
    public static function removeOwner(string $owner): void
    {
        self::$owners = array_filter(self::$owners, static fn (string $o): bool => $o !== $owner);
    }

    /** Whether this exact method and served path were registered as public. */
    public static function allows(string $method, string $path): bool
    {
        return isset(self::$owners[strtoupper($method) . ' ' . $path]);
    }

    /**
     * Whether a request may skip tenant resolution as a plugin webhook: the
     * method and path were registered public AND the route the router resolves
     * for it is the registering plugin's own. Without a router to ask, nothing
     * is admitted.
     */
    public static function admits(\Whity\Sdk\Http\Request $request, ?Router $router, string $path): bool
    {
        // The router matches core requests; anything else is not admitted.
        if (!$request instanceof \Whity\Core\Request) {
            return false;
        }
        $key = strtoupper($request->getMethod()) . ' ' . $path;
        $owner = self::$owners[$key] ?? null;
        if ($owner === null || $router === null) {
            return false;
        }
        $route = $router->match($request);
        return $route !== null && ($route['namespacePrefix'] ?? null) === $owner;
    }

    /** Tests only: start from nothing. */
    public static function reset(): void
    {
        self::$owners = [];
    }
}
