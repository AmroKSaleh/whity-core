<?php

declare(strict_types=1);

namespace Whity\Mcp\Resources;

use Whity\Core\Router;

/**
 * Derives MCP resource definitions from GET route declarations (WC-30513809).
 *
 * Routes without path parameters become static resources (listed under
 * 'resources'); routes with path parameters become resource templates (listed
 * under 'resourceTemplates') per the MCP 2025-03-26 spec.
 *
 * URI scheme: whity-api:///api/v1/path (scheme = 'whity-api', empty authority,
 * versioned absolute path). Routing constraints such as {id:\d+} are stripped
 * from URI templates to produce RFC 6570-compliant {id}.
 *
 * Static core declarations carry unversioned paths and need the Router's version
 * prefix applied. Router-native plugin routes are already stored with the prefix
 * by the Router's register() call, so they are used as-is.
 *
 * Worker-safe: stateless — all computation is per-call on the stack.
 */
final class ResourceDeriver
{
    public const URI_SCHEME = 'whity-api://';

    /**
     * @param list<array<string, mixed>> $staticDeclarations
     *   Core (or any pre-built) route declarations. Each entry must contain at
     *   least: 'method' (string), 'path' (string), 'schema' (array|null).
     *   Only GET entries with a non-empty schema are included.
     *   Paths are unversioned; the Router's version prefix is applied when a
     *   Router instance is provided.
     * @param Router|null $router
     *   When provided, GET routes that carry a schema are read at deriveResources()
     *   time and merged with $staticDeclarations. Plugin-contributed routes (loaded
     *   after construction) are automatically included. Their paths are already
     *   versioned by the Router, so no prefix is applied.
     */
    public function __construct(
        private readonly array $staticDeclarations,
        private readonly ?Router $router = null,
    ) {}

    /**
     * Derive the full MCP resources/list payload.
     *
     * @return array{resources: list<array<string, mixed>>, resourceTemplates: list<array<string, mixed>>}
     */
    public function deriveResources(): array
    {
        $resources         = [];
        $resourceTemplates = [];

        // Static declarations: unversioned paths — apply version prefix.
        foreach ($this->staticDeclarations as $decl) {
            if (strtoupper((string) ($decl['method'] ?? '')) !== 'GET') {
                continue;
            }
            $schema = $decl['schema'] ?? null;
            if (!is_array($schema) || $schema === []) {
                continue;
            }
            $path = $this->versionedPath((string) ($decl['path'] ?? ''));
            $this->addToResources($path, $schema, $resources, $resourceTemplates);
        }

        // Router-native routes: paths already versioned by Router::register().
        if ($this->router !== null) {
            foreach ($this->router->getRoutes() as $route) {
                if (strtoupper($route['method']) !== 'GET') {
                    continue;
                }
                $schema = $route['schema'] ?? null;
                if (!is_array($schema) || $schema === []) {
                    continue;
                }
                $this->addToResources($route['path'], $schema, $resources, $resourceTemplates);
            }
        }

        return ['resources' => $resources, 'resourceTemplates' => $resourceTemplates];
    }

    /**
     * Build a map of resource URI → access requirements.
     *
     * Keys are the whity-api:// URIs (or uriTemplates) produced by deriveResources().
     * Values carry the requiredRole and requiredPermission from the declaration or
     * router route — both null means the resource is open (no RBAC required).
     *
     * @return array<string, array{requiredRole: ?string, requiredPermission: ?string}>
     */
    public function buildAccessMap(): array
    {
        $accessMap     = [];

        // Static declarations: unversioned paths — apply version prefix.
        foreach ($this->staticDeclarations as $decl) {
            if (strtoupper((string) ($decl['method'] ?? '')) !== 'GET') {
                continue;
            }
            $schema = $decl['schema'] ?? null;
            if (!is_array($schema) || $schema === []) {
                continue;
            }
            $path = $this->versionedPath((string) ($decl['path'] ?? ''));
            $uri  = self::URI_SCHEME . $this->cleanPathConstraints($path);

            $accessMap[$uri] = [
                'requiredRole'       => is_string($decl['requiredRole'] ?? null) ? $decl['requiredRole'] : null,
                'requiredPermission' => is_string($decl['requiredPermission'] ?? null) ? $decl['requiredPermission'] : null,
            ];
        }

        // Router-native routes: paths already versioned by Router::register().
        if ($this->router !== null) {
            foreach ($this->router->getRoutes() as $route) {
                if (strtoupper($route['method']) !== 'GET') {
                    continue;
                }
                $schema = $route['schema'] ?? null;
                if (!is_array($schema) || $schema === []) {
                    continue;
                }
                $uri = self::URI_SCHEME . $this->cleanPathConstraints($route['path']);

                $accessMap[$uri] = [
                    'requiredRole'       => is_string($route['requiredRole'] ?? null) ? $route['requiredRole'] : null,
                    'requiredPermission' => is_string($route['requiredPermission'] ?? null) ? $route['requiredPermission'] : null,
                ];
            }
        }

        return $accessMap;
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * @param array<string, mixed>            $schema
     * @param list<array<string, mixed>>       &$resources
     * @param list<array<string, mixed>>       &$resourceTemplates
     */
    private function addToResources(
        string $versionedPath,
        array $schema,
        array &$resources,
        array &$resourceTemplates,
    ): void {
        $cleanPath = $this->cleanPathConstraints($versionedPath);
        $uri       = self::URI_SCHEME . $cleanPath;
        $name      = is_string($schema['summary'] ?? null) ? $schema['summary'] : 'GET ' . $cleanPath;
        $entry = [
            'name'        => $name,
            'description' => 'GET ' . $cleanPath,
            'mimeType'    => 'application/json',
        ];

        if ($this->hasPathParams($cleanPath)) {
            $resourceTemplates[] = array_merge(['uriTemplate' => $uri], $entry);
        } else {
            $resources[] = array_merge(['uri' => $uri], $entry);
        }
    }

    /**
     * Strip routing constraints from path param placeholders.
     *
     * e.g. /api/things/{id:\d+} → /api/things/{id}
     */
    private function cleanPathConstraints(string $path): string
    {
        return (string) preg_replace('#\{([a-zA-Z_][a-zA-Z0-9_]*)[^{}]*\}#', '{$1}', $path);
    }

    private function hasPathParams(string $path): bool
    {
        return str_contains($path, '{');
    }

    /**
     * The version prefix applied by the ROUTER, or the bare path when there is
     * no router.
     *
     * This used to mirror `Router::versionPrefix()` with its own copy of the
     * arithmetic, and said so in its docblock — a standing admission that two
     * implementations of one rule existed with nothing comparing them (#1020).
     * Drift would have produced an emitted path nothing serves, which is exactly
     * the #1016 defect; and it would have been QUIETER here than there, because
     * MCP resources are consumed by agents rather than looked at by a person, so
     * there is no rendered surface for a 404 to appear on.
     *
     * THE NULL-ROUTER CASE IS DELIBERATE, not inherited. Without a router there
     * is no version to apply and the bare path is returned, which is what this
     * class already did. It is kept rather than made fatal because the parameter
     * is optional by design — `$staticDeclarations` alone is a legitimate
     * construction, used where nothing is being served — and a derivation that
     * threw would turn a supported call into an outage. Where a router IS given,
     * its answer is now the only answer.
     */
    private function versionedPath(string $path): string
    {
        return $this->router?->versionedPath($path) ?? $path;
    }
}
