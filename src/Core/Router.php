<?php

namespace Whity\Core;

/**
 * HTTP route matcher
 *
 * Manages route registration and matching for different HTTP methods and paths.
 * Supports path parameters (e.g., /users/{id}) and middleware support.
 */
class Router
{
    /**
     * @var array<array{method: string, path: string, pattern: string, handler: callable, requiredRole: ?string, requiredPermission: ?string, namespacePrefix: ?string}> Registered routes
     */
    private array $routes = [];

    /**
     * @var array<callable> Middleware stack
     */
    private array $middleware = [];

    /**
     * Register a route
     *
     * @param string $method HTTP method (GET, POST, etc.)
     * @param string $path Route path (supports {param} syntax for path parameters)
     * @param callable $handler Route handler callback
     * @param string|null $requiredRole Optional required role for authorization
     * @param string|null $namespacePrefix Optional plugin namespace prefix
     * @param string|null $requiredPermission Optional required permission (resource:action) for authorization
     * @return void
     */
    public function register(
        string $method,
        string $path,
        callable $handler,
        ?string $requiredRole = null,
        ?string $namespacePrefix = null,
        ?string $requiredPermission = null
    ): void {
        $this->routes[] = [
            'method' => strtoupper($method),
            'path' => $path,
            'pattern' => $this->pathToPattern($path),
            'handler' => $handler,
            'requiredRole' => $requiredRole,
            'requiredPermission' => $requiredPermission,
            'namespacePrefix' => $namespacePrefix,
        ];
    }

    /**
     * Match a request against registered routes
     *
     * Returns route information if a match is found, null otherwise.
     *
     * @param Request $request Request object
     * @return array{handler: callable, params: array<string, string>, requiredRole: ?string, requiredPermission: ?string, namespacePrefix: ?string}|null Array if matched, null otherwise
     */
    public function match(Request $request): ?array
    {
        $method = $request->getMethod();
        $path = $request->getPath();

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }

            $matches = [];
            if (preg_match($route['pattern'], $path, $matches) === 1) {
                // Extract named parameters
                $params = [];
                foreach ($matches as $key => $value) {
                    if (!is_numeric($key)) {
                        $params[$key] = $value;
                    }
                }

                return [
                    'handler' => $route['handler'],
                    'params' => $params,
                    'requiredRole' => $route['requiredRole'],
                    'requiredPermission' => $route['requiredPermission'] ?? null,
                    'namespacePrefix' => $route['namespacePrefix'] ?? null,
                ];
            }
        }

        return null;
    }

    /**
     * List the HTTP methods registered for a path (WC-160)
     *
     * Matches the path against every route pattern regardless of method, so the
     * kernel can answer a method mismatch with 405 + Allow instead of 404. An
     * empty result means no route knows the path at all (a true 404).
     *
     * @param string $path Request path (no query string)
     * @return list<string> Unique methods in registration order
     */
    public function allowedMethods(string $path): array
    {
        $methods = [];
        foreach ($this->routes as $route) {
            if (preg_match($route['pattern'], $path) === 1 && !in_array($route['method'], $methods, true)) {
                $methods[] = $route['method'];
            }
        }

        return $methods;
    }

    /**
     * Get all registered routes
     *
     * @return array<array{method: string, path: string, pattern: string, handler: callable, requiredRole: ?string, requiredPermission: ?string, namespacePrefix: ?string}>
     */
    public function getRoutes(): array
    {
        return $this->routes;
    }

    /**
     * Remove every route registered under the given plugin namespace prefix
     *
     * Used by the plugin hot-reload mechanism to drop routes belonging to a
     * plugin that has been modified or removed from disk before its updated
     * definition is registered again.
     *
     * @param string $namespacePrefix The plugin namespace prefix to remove
     * @return int Number of routes removed
     */
    public function unregisterByNamespace(string $namespacePrefix): int
    {
        $before = count($this->routes);

        $this->routes = array_values(array_filter(
            $this->routes,
            static fn(array $route): bool => ($route['namespacePrefix'] ?? null) !== $namespacePrefix
        ));

        return $before - count($this->routes);
    }

    /**
     * Add a middleware to the middleware stack
     *
     * @param callable $middleware Middleware callable
     * @return void
     */
    public function addMiddleware(callable $middleware): void
    {
        $this->middleware[] = $middleware;
    }

    /**
     * Get all registered middleware
     *
     * @return array<callable> Middleware stack
     */
    public function getMiddleware(): array
    {
        return $this->middleware;
    }

    /**
     * Convert a path pattern to a regex pattern
     *
     * Converts path patterns like /users/{id}/posts/{postId} to regex patterns
     * that can match requests and capture parameters. A placeholder may carry
     * an inline regex constraint — `{id:\d+}` only matches digit segments
     * (WC-160). Constraints must not contain `{`, `}`, parentheses or `#`
     * (the pattern delimiter) — such constraints are rejected at registration
     * time. Without a constraint the parameter matches any single segment
     * ([^/]+).
     *
     * @param string $path Path pattern with {param} or {param:regex} placeholders
     * @return string Regex pattern
     * @throws \InvalidArgumentException When a constraint contains unsupported characters.
     */
    private function pathToPattern(string $path): string
    {
        // Replace {param} / {param:regex} placeholders with named capture groups
        $pattern = preg_replace_callback(
            '#\{([a-zA-Z_][a-zA-Z0-9_]*)(?::([^{}]+))?\}#',
            static function (array $matches) use ($path): string {
                $paramName = $matches[1];
                $constraint = $matches[2] ?? '';
                if ($constraint === '') {
                    $constraint = '[^/]+';
                } elseif (preg_match('/[()#]/', $constraint) === 1) {
                    // These would corrupt the compiled pattern (capture-group
                    // protection stops at ')'; '#' is the delimiter) and emit
                    // preg warnings on EVERY match() call — fail loudly now.
                    throw new \InvalidArgumentException(
                        "Route '{$path}': constraint for {{$paramName}} must not contain '(', ')' or '#'"
                    );
                }
                return "(?P<{$paramName}>{$constraint})";
            },
            $path
        );

        $pattern = is_string($pattern) ? $pattern : '';

        // Now escape the remaining special regex characters (but not our capture groups)
        // We do this by replacing our capture groups temporarily
        $placeholders = [];
        $pattern = preg_replace_callback(
            '#\(\?P<[a-zA-Z_][a-zA-Z0-9_]*>[^)]+\)#',
            static function (array $matches) use (&$placeholders): string {
                $key = '__PARAM_' . count($placeholders) . '__';
                $placeholders[$key] = $matches[0];
                return $key;
            },
            $pattern
        );

        $pattern = is_string($pattern) ? $pattern : '';

        // Escape regex special chars in the remaining path
        $pattern = preg_quote($pattern, '#');

        // Restore the capture groups
        foreach ($placeholders as $key => $value) {
            $pattern = str_replace(preg_quote($key, '#'), $value, $pattern);
        }

        // Anchor the pattern to match the entire path
        return "#^{$pattern}$#";
    }
}
