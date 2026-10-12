<?php

declare(strict_types=1);

namespace Whity\Core\Plugin;

use Whity\Sdk\PluginNamespace;

/**
 * The plugin webhook paths that bypass tenant resolution (WHIT-671).
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
 * {@see self::acceptable()}. All of these must hold:
 *   - the method is POST (a notification, never a read);
 *   - the path is `/api/<the plugin's own slug>/webhooks/<name>`, with
 *     <name> matching `[a-z0-9][a-z0-9-]{0,62}` and no parameters, so a plugin
 *     can never open another plugin's or core's paths, and nothing can be
 *     public beneath an open prefix;
 *   - the route demands no permission and no role (asking for both is a
 *     contradiction, so it is refused rather than guessed at).
 *
 * PUBLIC MEANS ONLY "NO TENANT RESOLUTION". It does not authenticate the
 * caller; the plugin's handler must, by verifying the sender's signature over
 * the raw body before acting on anything in it. That is the same rule core's
 * own webhooks follow.
 *
 * PER PROCESS. Every FrankenPHP worker loads plugins at boot and fills its own
 * copy; a disabled plugin's paths are removed with its routes.
 */
final class PluginPublicRoutes
{
    /** The only methods a public plugin route may use. */
    public const METHOD = 'POST';

    private const NAME_PATTERN = '[a-z0-9][a-z0-9-]{0,62}';

    /** @var array<string, array<string, true>> owner => served path => true */
    private static array $byOwner = [];

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
        $shape = '#^/api/' . preg_quote($slug, '#') . '/webhooks/' . self::NAME_PATTERN . '$#';
        if (preg_match($shape, $path) !== 1) {
            return "a public plugin route must be exactly /api/{$slug}/webhooks/<name>, <name> matching " . self::NAME_PATTERN;
        }
        if ($requiredPermission !== null || $requiredRole !== null) {
            return 'a public plugin route cannot also require a permission or a role';
        }
        return null;
    }

    /** Record a served path (the router's /api/v1/... form) as public. */
    public static function add(string $owner, string $servedPath): void
    {
        self::$byOwner[$owner][$servedPath] = true;
    }

    /** Forget every public path an owner (a plugin's namespace) registered. */
    public static function removeOwner(string $owner): void
    {
        unset(self::$byOwner[$owner]);
    }

    /** Whether this exact served path is a plugin's public webhook. */
    public static function has(string $path): bool
    {
        foreach (self::$byOwner as $paths) {
            if (isset($paths[$path])) {
                return true;
            }
        }
        return false;
    }

    /** Tests only: start from nothing. */
    public static function reset(): void
    {
        self::$byOwner = [];
    }
}
