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
        $versionPrefix     = $this->router !== null ? $this->router->getVersionPrefix() : '';

        // Static declarations: unversioned paths — apply version prefix.
        foreach ($this->staticDeclarations as $decl) {
            if (strtoupper((string) ($decl['method'] ?? '')) !== 'GET') {
                continue;
            }
            $schema = $decl['schema'] ?? null;
            if (!is_array($schema) || $schema === []) {
                continue;
            }
            $path = $this->applyVersionPrefix((string) ($decl['path'] ?? ''), $versionPrefix);
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
     * Insert the version prefix after the first path segment.
     *
     * Mirrors Router::versionPrefix(): /api/users + /v1 → /api/v1/users.
     */
    private function applyVersionPrefix(string $path, string $versionPrefix): string
    {
        if ($versionPrefix === '') {
            return $path;
        }
        $pos = strpos($path, '/', 1);
        if ($pos === false) {
            return $path . $versionPrefix;
        }
        return substr($path, 0, $pos) . $versionPrefix . substr($path, $pos);
    }
}
