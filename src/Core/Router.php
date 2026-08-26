<?php

declare(strict_types=1);

namespace Whity\Core;

use Whity\Sdk\Http\Request;

/**
 * HTTP route matcher
 *
 * Manages route registration and matching for different HTTP methods and paths.
 * Supports path parameters (e.g., /users/{id}) and middleware support.
 *
 * All routes registered via register() are considered versioned and have the
 * version prefix (default '/v1') prepended to their path automatically.
 * Routes registered via registerUnversioned() bypass the prefix entirely and
 * are stored exactly as given — use this for infrastructure paths such as
 * GET /api/health, GET /api/version, and GET /api/openapi.json that must
 * never move under a version segment.
 */
class Router
{
    /**
     * @var array<array{method: string, path: string, pattern: string, handler: callable, requiredRole: ?string, requiredPermission: ?string, namespacePrefix: ?string, schema: array<string, mixed>|null}> Registered routes
     */
    private array $routes = [];

    /**
     * @var array<callable> Middleware stack
     */
    private array $middleware = [];

    /**
     * URL version prefix prepended to every versioned route path.
     *
     * Defaults to '/v1'. Pass an empty string in tests that do not care about
     * versioning to keep existing fixture paths working unchanged.
     */
    private string $versionPrefix;

    /**
     * Constructor
     *
     * @param string $versionPrefix URL segment prepended to every versioned
     *   route path (e.g. '/v1'). Pass an empty string to disable prefixing —
     *   useful in unit tests that register plain /api/... paths directly.
     */
    public function __construct(string $versionPrefix = '/v1')
    {
        $this->versionPrefix = $versionPrefix;
    }

    /**
     * Register a versioned route
     *
     * The $versionPrefix (default '/v1') is prepended to $path before storage,
     * so callers pass bare resource paths (e.g. '/api/users') and the router
     * writes '/api/v1/users' automatically.
     *
     * @param string $method HTTP method (GET, POST, etc.)
     * @param string $path Route path WITHOUT the version prefix (supports {param} syntax for path parameters)
     * @param callable $handler Route handler callback
     * @param string|null $requiredRole Optional required role for authorization
     * @param string|null $namespacePrefix Optional plugin namespace prefix
     * @param string|null $requiredPermission Optional required permission (resource:action) for authorization
     * @param array<string, mixed>|null $schema Optional OpenAPI declaration for the route (WC-166):
     *   summary/tags plus typed 'request' and 'responses' (component name or
     *   inline schema) and optional 'components' contributed to the spec.
     * @return bool True when the route registered; false when an identical
     *   method+path was already registered (first registration wins — WC-169:
     *   a plugin can never shadow a core route or another plugin's route).
     */
    public function register(
        string $method,
        string $path,
        callable $handler,
        ?string $requiredRole = null,
        ?string $namespacePrefix = null,
        ?string $requiredPermission = null,
        ?array $schema = null
    ): bool {
        // Prepend the version prefix so '/api/users' becomes '/api/v1/users'.
        $prefixedPath = $this->versionPrefix !== '' ? $this->versionPrefix($path) : $path;
        return $this->doRegister($method, $prefixedPath, $handler, $requiredRole, $namespacePrefix, $requiredPermission, $schema);
    }

    /**
     * Register an unversioned route (no version prefix applied)
     *
     * Use this for infrastructure endpoints that must never move under a version
     * segment: GET /api/health, GET /api/version, GET /api/openapi.json.
     *
     * @param string $method HTTP method (GET, POST, etc.)
     * @param string $path Full route path stored exactly as given
     * @param callable $handler Route handler callback
     * @param string|null $requiredRole Optional required role for authorization
     * @param string|null $namespacePrefix Optional plugin namespace prefix
     * @param string|null $requiredPermission Optional required permission (resource:action) for authorization
     * @param array<string, mixed>|null $schema Optional OpenAPI declaration for the route
     * @return bool True when the route registered; false when a duplicate method+path exists
     */
    public function registerUnversioned(
        string $method,
        string $path,
        callable $handler,
        ?string $requiredRole = null,
        ?string $namespacePrefix = null,
        ?string $requiredPermission = null,
        ?array $schema = null
    ): bool {
        return $this->doRegister($method, $path, $handler, $requiredRole, $namespacePrefix, $requiredPermission, $schema);
    }

    /**
     * Internal registration shared by register() and registerUnversioned().
     *
     * @param array<string, mixed>|null $schema
     */
    private function doRegister(
        string $method,
        string $path,
        callable $handler,
        ?string $requiredRole,
        ?string $namespacePrefix,
        ?string $requiredPermission,
        ?array $schema
    ): bool {
        $method = strtoupper($method);

        // First registration wins: an exact method+path duplicate is refused
        // so later registrants (plugins load after core since WC-169) cannot
        // shadow an existing handler. match() returns the first hit anyway —
        // refusing the duplicate makes that ordering an enforced contract
        // instead of an accident, and lets callers detect the collision.
        foreach ($this->routes as $route) {
            if ($route['method'] === $method && $route['path'] === $path) {
                return false;
            }
        }

        $this->routes[] = [
            'method' => $method,
            'path' => $path,
            'pattern' => $this->pathToPattern($path),
            'handler' => $handler,
            'requiredRole' => $requiredRole,
            'requiredPermission' => $requiredPermission,
            'namespacePrefix' => $namespacePrefix,
            'schema' => $schema,
        ];

        return true;
    }

    /**
     * Get the version prefix applied to versioned routes.
     */
    public function getVersionPrefix(): string
    {
        return $this->versionPrefix;
    }

    /**
     * The client-callable form of an unversioned path.
     *
     * The PUBLIC counterpart of {@see versionPrefix()}, for code that EMITS a
     * URL a browser will fetch rather than registering a route to serve one.
     *
     * Route paths are written unversioned throughout this codebase and gain the
     * prefix at registration, so anything handing a path back to a client has to
     * apply the same transformation or it emits an address nothing serves. That
     * is not hypothetical: `content_url` returned '/api/documents/{id}/content'
     * while the router served '/api/v1/documents/{id}/content', and every
     * document in the viewer showed an error box instead of its file.
     *
     * {@see \Whity\Core\PluginLoader} does this for plugin-declared paths and
     * {@see \Whity\OpenAPI\CoreApiSchemas} for `x-whity-reference` resources;
     * both predate this method and still carry their own inlined copy of the
     * arithmetic. New emitters should call this instead of adding a seventh.
     *
     * @param string $path The bare path (e.g. '/api/documents/6/content')
     * @return string The prefixed path (e.g. '/api/v1/documents/6/content')
     */
    public function versionedPath(string $path): string
    {
        return $this->versionPrefix !== '' ? $this->versionPrefix($path) : $path;
    }

    /**
     * Inject the version prefix into a path after its first path segment.
     *
     * The version prefix is inserted after the first slash-delimited segment so
     * that '/api/users' becomes '/api/v1/users', not '/v1/api/users'.
     *
     * For paths that have no prefix segment (i.e. start immediately with a
     * resource name like '/users'), the version is prepended directly:
     * '/users' → '/v1/users'.
     *
     * @param string $path The bare path (e.g. '/api/users')
     * @return string The prefixed path (e.g. '/api/v1/users')
     */
    private function versionPrefix(string $path): string
    {
        // Paths begin with '/'. Split off the first segment ('/api') and
        // insert the version between it and the remainder.
        // '/api/users'  → ['', 'api', 'users']  → '/api' + '/v1' + '/users'
        // '/api'        → ['', 'api']            → '/api' + '/v1'
        $pos = strpos($path, '/', 1); // find the second '/'
        if ($pos === false) {
            // e.g. '/api'  — just append the prefix
            return $path . $this->versionPrefix;
        }
        return substr($path, 0, $pos) . $this->versionPrefix . substr($path, $pos);
    }

    /**
     * Match a request against registered routes
     *
     * Returns route information if a match is found, null otherwise. Captured
     * parameters are percent-DECODED — see the note inside.
     *
     * @param Request $request Request object
     * @return array{handler: callable, params: array<string, string>, requiredRole: ?string, requiredPermission: ?string, namespacePrefix: ?string, schema: array<string, mixed>|null}|null Array if matched, null otherwise
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
                // Extract named parameters, PERCENT-DECODED (#1078).
                //
                // A path segment is percent-encoded on the wire by definition —
                // that is how a space, a slash, or any non-ASCII character
                // survives a URL at all. `Request::fromGlobals()` takes the path
                // straight out of `REQUEST_URI` through `parse_url()`, which
                // does not decode, and until this line nothing else did either.
                // So a handler was handed `%D8%B7%D8%A7%D9%84%D8%A8` for a
                // record called `طالب`, looked it up by a string no record has
                // ever been called, and MISSED — and because a lookup that
                // misses usually falls back to something rather than failing, it
                // answered 200 with a different record.
                //
                // An Arabic identifier percent-encodes ENTIRELY, so this was not
                // an edge case about spaces in names: on a platform whose
                // domains are fully bilingual, it was the default for every
                // institution whose records are named in Arabic.
                //
                // `rawurldecode`, NOT `urldecode`. They differ on exactly one
                // character and it matters: `+` means "space" in a query string
                // and means a literal plus in a path segment. `urldecode` would
                // silently corrupt every identifier containing one, into a
                // plausible string that is not the one requested.
                //
                // EXACTLY ONCE. An identifier that legitimately contains the
                // text `%20` arrives as `%2520`; one pass gives `%20`, and a
                // second would give a space and address a different record.
                // Decoding twice is also how a traversal filter gets bypassed —
                // `%252E` survives one pass and becomes `.` on the next — so
                // "once" is a security property, not only a correctness one.
                //
                // DECODING THE VALUES, NOT THE PATH. Matching still runs against
                // the raw path above, so an encoded `%2F` cannot smuggle a
                // segment boundary past a `[^/]+` placeholder and turn a
                // one-segment route into a two-segment one, and a `{id:\d+}`
                // constraint still applies to the raw segment rather than to
                // whatever happened to decode into digits. Only what the handler
                // receives changes.
                //
                // What a handler owes in return: a decoded parameter is
                // UNTRUSTED INPUT, exactly as a query parameter or a body field
                // is. It can now contain a slash (from `%2F`) or a dot segment,
                // so anything building a filesystem path out of one must
                // validate it first. Every core handler that touches the
                // filesystem already does — `DesktopPluginsApiHandler::download`
                // against a name/version regex, `BrandingApiHandler` against the
                // `BrandingAssetKind` whitelist — and both of those now run
                // their check against the real value rather than an escaped one.
                $params = [];
                foreach ($matches as $key => $value) {
                    if (!is_numeric($key)) {
                        $params[$key] = rawurldecode($value);
                    }
                }

                return [
                    'handler' => $route['handler'],
                    'params' => $params,
                    'requiredRole' => $route['requiredRole'],
                    'requiredPermission' => $route['requiredPermission'] ?? null,
                    'namespacePrefix' => $route['namespacePrefix'] ?? null,
                    'schema' => $route['schema'] ?? null,
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
     * @return array<array{method: string, path: string, pattern: string, handler: callable, requiredRole: ?string, requiredPermission: ?string, namespacePrefix: ?string, schema: array<string, mixed>|null}>
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
     * (WC-160). A constraint MAY itself contain `{n}` / `{n,m}` quantifiers
     * (e.g. `{code:[a-f0-9]{10}}` for exactly 10 hex chars — WC-569); any other
     * unescaped `{`/`}` inside a constraint, or parentheses, or `#` (the
     * pattern delimiter), are rejected at registration time. Without a
     * constraint the parameter matches any single segment ([^/]+).
     *
     * @param string $path Path pattern with {param} or {param:regex} placeholders
     * @return string Regex pattern
     * @throws \InvalidArgumentException When a constraint contains unsupported characters.
     */
    private function pathToPattern(string $path): string
    {
        // How many placeholders APPEAR to open in $path — used below to detect
        // one that the extraction regex failed to fully consume (WC-569): a
        // constraint with brace usage that isn't a simple {n}/{n,m} quantifier
        // leaves the raw '{...}' text behind, which would otherwise silently
        // become a literal-text requirement a real request can never satisfy.
        $placeholderStarts = preg_match_all('#\{[a-zA-Z_][a-zA-Z0-9_]*(?::|\})#', $path);
        $placeholdersMatched = 0;

        // Replace {param} / {param:regex} placeholders with named capture
        // groups. The constraint body allows runs of non-brace characters
        // interleaved with `{digits}` / `{digits,digits}` quantifier tokens,
        // so `[a-f0-9]{10}` is captured as part of the constraint rather than
        // prematurely closing the placeholder at its first '}'.
        $pattern = preg_replace_callback(
            '#\{([a-zA-Z_][a-zA-Z0-9_]*)(?::((?:[^{}]|\{\d+(?:,\d*)?\})+))?\}#',
            static function (array $matches) use ($path, &$placeholdersMatched): string {
                $placeholdersMatched++;
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

        if ($placeholdersMatched !== $placeholderStarts) {
            throw new \InvalidArgumentException(
                "Route '{$path}': a placeholder constraint has unbalanced or unsupported '{'/'}' usage "
                . '(only simple {n} / {n,m} quantifiers are allowed inside a constraint)'
            );
        }

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
