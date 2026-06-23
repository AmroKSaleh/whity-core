<?php

declare(strict_types=1);

namespace Whity\Http;

use Whity\Sdk\Http\Request;
use Whity\Sdk\Http\Response;
use Whity\Core\Audit\AuditContext;
use Whity\Core\Router;
use Whity\Core\Tenant\TenantContext;

/**
 * HTTP Kernel for handling request dispatching
 *
 * The main entry point for HTTP request processing. Matches routes, applies middleware,
 * and dispatches requests to appropriate handlers. Supports a pipeline of middleware
 * that are executed in order before routing and authorization checks.
 *
 * Typical middleware order:
 * 1. EnforceTenantIsolation - Sets tenant context from JWT
 * 2. RbacMiddleware - Validates JWT and enforces role-based access control
 * 3. Route handlers - Application logic
 */
class HttpKernel
{
    private Router $router;
    private RbacMiddleware $rbacMiddleware;
    /** @var array<object> */
    private array $middleware = [];
    /** @var array<array{callback:callable,args:array<mixed>}> */
    private static array $shutdownFunctions = [];
    /** @var array<string> */
    private array $initialGlobals = [];
    private bool $memoryLimitExceeded = false;

    /**
     * Explicit allowlist of the request-scoped static state that MUST be cleared
     * between requests on a persistent (FrankenPHP) worker.
     *
     * Each entry is a `Class::reset` callable owned by the class that holds the
     * request-scoped statics. This is deliberately an EXPLICIT registry rather
     * than a reflective sweep of every static under Whity\Core: BOOT-scoped
     * statics (injected loggers, the plugin autoloader registry/flag, memoized
     * metadata) are wired ONCE at bootstrap and must SURVIVE across requests.
     * The old whole-tree reflection reset re-nulled them every request, which
     * silently disabled the boot-wired audit logger from request #2 onward
     * (WC-181 / issue #179). Anything not listed here is treated as boot-scoped
     * and is intentionally left untouched.
     *
     * Adding a new class with request-scoped statics? Give it a public static
     * reset() and register it here.
     *
     * @var list<callable():void>
     */
    private array $requestScopedResetters;

    /**
     * Constructor
     *
     * @param Router $router Route matcher and manager
     * @param RbacMiddleware $rbacMiddleware RBAC middleware for authorization
     */
    public function __construct(Router $router, RbacMiddleware $rbacMiddleware)
    {
        $this->router = $router;
        $this->rbacMiddleware = $rbacMiddleware;

        // The explicit set of request-scoped statics to clear between requests.
        // Note: TenantContext::reset() is invoked first and separately (step 1 of
        // resetRequestState) because the rest of the lifecycle reasons about a
        // clean tenant context; it is intentionally NOT duplicated here.
        $this->requestScopedResetters = [
            // Actor user id + client IP of the current request (WC-34). Holds only
            // scalar identity data; previously cleared ONLY by the reflection
            // sweep inside the kernel, so it must be reset explicitly now.
            AuditContext::reset(...),
        ];
    }

    /**
     * Register a shadowed shutdown function
     */
    public static function registerShutdownFunction(callable $callback, mixed ...$args): void
    {
        self::$shutdownFunctions[] = [
            'callback' => $callback,
            'args' => $args
        ];
    }

    /**
     * Execute and clear all shadowed shutdown functions
     */
    public static function executeShutdownFunctions(): void
    {
        $callbacks = self::$shutdownFunctions;
        self::$shutdownFunctions = [];
        foreach ($callbacks as $entry) {
            try {
                ($entry['callback'])(...$entry['args']);
            } catch (\Throwable $e) {
                error_log("Error in shutdown function: " . $e->getMessage());
            }
        }
    }

    /**
     * Reset the request state between persistent worker cycles
     */
    private function resetRequestState(): void
    {
        // 1. Reset TenantContext (tenant id, lock, system mode). The injected
        //    audit logger is process-scoped and intentionally preserved.
        TenantContext::reset();

        // 2. Execute namespaced shutdown functions
        self::executeShutdownFunctions();

        // 3. Reset the remaining request-scoped core statics via the EXPLICIT
        //    registry (WC-181). This replaces the previous reflection sweep that
        //    reset EVERY static under Whity\Core and so re-nulled boot-wired
        //    infrastructure (e.g. TenantContext's audit logger), silently killing
        //    the audit trail from request #2 onward. Only genuinely request-scoped
        //    statics are listed; boot-scoped statics are left to survive.
        foreach ($this->requestScopedResetters as $reset) {
            $reset();
        }

        // 4. Reset custom globals
        foreach (array_keys($GLOBALS) as $key) {
            if ($key !== 'GLOBALS' && !in_array($key, $this->initialGlobals, true)) {
                unset($GLOBALS[$key]);
            }
        }

        // 5. Reset standard superglobals
        $_GET = [];
        $_POST = [];
        $_COOKIE = [];
        $_FILES = [];
        $_REQUEST = [];

        // 6. Destroy session if active
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    /**
     * Register middleware in the request pipeline
     *
     * Middleware is executed in the order it is registered.
     * Recommended order:
     * 1. EnforceTenantIsolation - Sets tenant context from JWT
     * 2. RbacMiddleware - Validates JWT and enforces authorization
     *
     * @param object $middleware Middleware instance with handle(Request, callable): Response
     * @return self For method chaining
     */
    public function use(object $middleware): self
    {
        $this->middleware[] = $middleware;
        return $this;
    }

    /**
     * Handle incoming HTTP request
     *
     * Executes the complete request pipeline:
     * 1. Applies all registered middleware in order
     * 2. Matches request to route
     * 3. Applies RBAC middleware if route requires a role
     * 4. Calls route handler
     * 5. Cleans up TenantContext
     *
     * Wraps the entire request lifecycle in a try-finally block to ensure
     * TenantContext is cleaned up even if an exception occurs, preventing
     * tenant data from bleeding across requests in persistent workers.
     *
     * @param Request $request The incoming HTTP request
     * @return Response HTTP response
     */
    public function handle(Request $request): Response
    {
        // Initialize state isolation tracking on the first request
        if (empty($this->initialGlobals)) {
            $this->initialGlobals = array_keys($GLOBALS);
        }

        try {
            // Build the middleware pipeline
            // Start with the core request handler that matches routes and applies RBAC
            $pipeline = $this->buildCorePipeline();

            // Apply all registered middleware (in order, wrapping the core pipeline)
            foreach (array_reverse($this->middleware) as $middleware) {
                $pipeline = $this->wrapWithMiddleware($middleware, $pipeline);
            }

            // Execute the complete pipeline
            return $pipeline($request);
        } finally {
            // Clean up request state after request completes
            $this->resetRequestState();
            $this->checkMemoryLimit();
        }
    }

    /**
     * Check memory usage and set flag if limit is exceeded
     */
    private function checkMemoryLimit(): void
    {
        $limitMb = (int)($_ENV['WORKER_MEMORY_LIMIT_MB'] ?? $_SERVER['WORKER_MEMORY_LIMIT_MB'] ?? 128);
        $limitBytes = $limitMb * 1024 * 1024;
        $thresholdBytes = $limitBytes * 0.9;
        $currentMemory = memory_get_usage();

        if ($currentMemory > $thresholdBytes) {
            $this->memoryLimitExceeded = true;
            error_log(sprintf(
                "[HttpKernel] Memory limit threshold exceeded: %d MB / %d MB (%.2f%%). Triggering worker recycling.",
                (int)round($currentMemory / 1024 / 1024),
                $limitMb,
                ($currentMemory / $limitBytes) * 100
            ));
        }
    }

    /**
     * Check if memory limit has been exceeded
     */
    public function hasExceededMemoryLimit(): bool
    {
        return $this->memoryLimitExceeded;
    }

    /**
     * Build the core request handler (routes + RBAC)
     *
     * This is the base of the middleware pipeline.
     *
     * @return callable Handler that takes Request and returns Response
     */
    private function buildCorePipeline(): callable
    {
        return function(Request $request): Response {
            // Attempt to match the request to a registered route
            $matchedRoute = $this->router->match($request);

            // No route matches: distinguish "path unknown" (404) from "path
            // registered under other methods" (405 + Allow, RFC 9110) — WC-160.
            if ($matchedRoute === null) {
                $allowed = $this->router->allowedMethods($request->getPath());
                if ($allowed !== []) {
                    return new Response(
                        405,
                        (string) json_encode(['error' => 'Method Not Allowed']),
                        [
                            'Content-Type' => 'application/json',
                            'Allow' => implode(', ', $allowed),
                        ]
                    );
                }

                return Response::error('Not Found', 404);
            }

            // Extract handler, params, and the role/permission requirements.
            $handler = $matchedRoute['handler'];
            $params = $matchedRoute['params'];
            $requiredRole = $matchedRoute['requiredRole'];
            $requiredPermission = $matchedRoute['requiredPermission'] ?? null;
            $schema = $matchedRoute['schema'] ?? null;

            // If the route declares a required role and/or permission, apply RBAC
            // middleware. Both are forwarded so route-level permissions (WC-14)
            // are actually enforced, not just roles.
            if ($requiredRole !== null || $requiredPermission !== null) {
                // Create a closure that wraps the handler, passing params
                $next = fn(Request $req) => $handler($req, $params);

                // Pass through RBAC middleware (role + permission).
                $response = $this->rbacMiddleware->handle($request, $next, $requiredRole, $requiredPermission);
            } else {
                // Otherwise, call handler directly with params
                $response = $handler($request, $params);
            }

            // Emit RFC 8594 Deprecation / Sunset headers when the route schema
            // declares deprecated:true (WC-317). Headers are merged last so they
            // survive any withHeaders() chain applied by the outer dispatch loop.
            $deprecationHeaders = $this->deprecationHeaders($schema);
            if ($deprecationHeaders !== []) {
                $response = $response->withHeaders($deprecationHeaders);
            }

            return $response;
        };
    }

    /**
     * Build RFC 8594 Deprecation / Sunset headers from a route schema.
     *
     * The schema may declare:
     *   - `deprecated => true`  → `Deprecation: true`
     *   - `sunset => '<date>'`  → `Sunset: <date>` (RFC 7231 HTTP-date recommended)
     *
     * @param array<string, mixed>|null $schema
     * @return array<string, string>
     */
    private function deprecationHeaders(?array $schema): array
    {
        if (empty($schema['deprecated'])) {
            return [];
        }

        $headers = ['Deprecation' => 'true'];

        if (isset($schema['sunset']) && is_string($schema['sunset']) && $schema['sunset'] !== '') {
            $headers['Sunset'] = $schema['sunset'];
        }

        return $headers;
    }

    /**
     * Wrap a pipeline with middleware
     *
     * Creates a new pipeline that executes the middleware before the wrapped pipeline.
     *
     * @param object $middleware Middleware instance
     * @param callable $next The next handler in the pipeline
     * @return callable The wrapped pipeline
     */
    private function wrapWithMiddleware(object $middleware, callable $next): callable
    {
        return function(Request $request) use ($middleware, $next): Response {
            /** @var mixed $middleware */
            return $middleware->handle($request, $next);
        };
    }
}
