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
     * @var array<array{method: string, path: string, pattern: string, handler: callable, requiredRole: ?string, namespacePrefix: ?string}> Registered routes
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
     * @return void
     */
    public function register(
        string $method,
        string $path,
        callable $handler,
        ?string $requiredRole = null,
        ?string $namespacePrefix = null
    ): void {
        $this->routes[] = [
            'method' => strtoupper($method),
            'path' => $path,
            'pattern' => $this->pathToPattern($path),
            'handler' => $handler,
            'requiredRole' => $requiredRole,
            'namespacePrefix' => $namespacePrefix,
        ];
    }

    /**
     * Match a request against registered routes
     *
     * Returns route information if a match is found, null otherwise.
     *
     * @param Request $request Request object
     * @return array{handler: callable, params: array<string, string>, requiredRole: ?string, namespacePrefix: ?string}|null Array if matched, null otherwise
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
                    'namespacePrefix' => $route['namespacePrefix'] ?? null,
                ];
            }
        }

        return null;
    }

    /**
     * Get all registered routes
     *
     * @return array<array{method: string, path: string, pattern: string, handler: callable, requiredRole: ?string, namespacePrefix: ?string}>
     */
    public function getRoutes(): array
    {
        return $this->routes;
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
     * that can match requests and capture parameters.
     *
     * @param string $path Path pattern with {param} placeholders
     * @return string Regex pattern
     */
    private function pathToPattern(string $path): string
    {
        // Replace {param} placeholders with regex named capture groups first
        $pattern = preg_replace_callback(
            '#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#',
            static function (array $matches): string {
                $paramName = $matches[1];
                return "(?P<{$paramName}>[^/]+)";
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
