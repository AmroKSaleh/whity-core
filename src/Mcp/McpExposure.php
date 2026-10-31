<?php

declare(strict_types=1);

namespace Whity\Mcp;

use Whity\Core\Router;
use Whity\Mcp\Tools\ToolSurface;

/**
 * Which routes the MCP server offers to agents at all (ADR-0006 §4, WHIT-695).
 *
 * A route is exposed — derived into a tool, listed as a resource, readable
 * through resources/read — when an access gate guards it (a requiredPermission
 * or a requiredRole), or when its schema opts in with `'mcp' => true` (or
 * `'mcp' => ['expose' => true]`). `'mcp' => ['expose' => false]` or
 * `['visibility' => 'off']` keeps even a gated route off (WHIT-747).
 *
 * Everything else stays off the agent surface. The ungated routes are the
 * session and credential machinery (login, refresh, 2FA, password, emails,
 * linked identities), unauthenticated public endpoints and webhooks signed for
 * another server. Their handlers do authenticate, so listing them was never an
 * open door, but a model reads tools/list to decide what it may attempt, and
 * "disable two-factor authentication" should not be on that list.
 *
 * The opt-in exists for ungated routes whose handler authorizes the caller
 * itself (per-record permissions, "only the recipient may act"). Opt in only
 * then: the flag removes the route-level gate from consideration, so a route
 * that relies on that gate must not carry it.
 */
final class McpExposure
{
    /**
     * @param array<string, mixed> $route A route declaration or Router route:
     *   method, path, requiredPermission, requiredRole and schema are read
     *   when present.
     * @param array<string, true> $gatedRouterRoutes From {@see self::gatedRouterRoutes()}.
     *   A static declaration carries the schema while the live Router route
     *   carries the gate that tools/call actually enforces; when they
     *   disagree, the Router's gate counts.
     */
    public static function exposes(array $route, array $gatedRouterRoutes = [], ?Router $router = null): bool
    {
        $options = ToolSurface::routeOptions(is_array($route['schema'] ?? null) ? $route['schema'] : []);
        // WHIT-747: an explicit opt-out wins over the gate. For routes a model
        // can't use well (bulk imports, binary uploads), however they are gated.
        if ($options['expose'] === false || $options['visibility'] === ToolSurface::VISIBILITY_OFF) {
            return false;
        }

        if (self::gated($route)) {
            return true;
        }

        $method = strtoupper((string) ($route['method'] ?? ''));
        $path   = (string) ($route['path'] ?? '');
        if ($gatedRouterRoutes !== [] && $path !== '') {
            if (isset($gatedRouterRoutes["{$method} {$path}"])) {
                return true;
            }
            if ($router !== null && isset($gatedRouterRoutes[$method . ' ' . $router->versionedPath($path)])) {
                return true;
            }
        }

        return $options['expose'] === true;
    }

    /**
     * "METHOD /path" of every Router route that carries a gate.
     *
     * @return array<string, true>
     */
    public static function gatedRouterRoutes(?Router $router): array
    {
        if ($router === null) {
            return [];
        }
        $gated = [];
        foreach ($router->getRoutes() as $route) {
            if (self::gated($route)) {
                $gated[strtoupper((string) $route['method']) . ' ' . $route['path']] = true;
            }
        }
        return $gated;
    }

    /** @param array<string, mixed> $route */
    private static function gated(array $route): bool
    {
        foreach (['requiredPermission', 'requiredRole'] as $field) {
            $value = $route[$field] ?? null;
            if (is_string($value) && $value !== '') {
                return true;
            }
        }
        return false;
    }
}
