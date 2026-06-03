<?php

namespace Whity\Http;

use Whity\Core\Request;
use Whity\Core\Response;
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
    /** @var array<string> */
    private array $coreClasses = [];

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
     * Initialize core classes scanning
     */
    private function initCoreClasses(): void
    {
        $coreDir = dirname(__DIR__) . '/Core';
        if (!is_dir($coreDir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($coreDir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $relativePath = substr($file->getPathname(), strlen($coreDir) + 1);
                $relativePath = str_replace('.php', '', $relativePath);
                $subNamespace = str_replace(DIRECTORY_SEPARATOR, '\\', $relativePath);
                $className = 'Whity\\Core\\' . $subNamespace;

                if (class_exists($className)) {
                    $this->coreClasses[] = $className;
                }
            }
        }
    }

    /**
     * Reset the request state between persistent worker cycles
     */
    private function resetRequestState(): void
    {
        // 1. Reset TenantContext
        TenantContext::reset();

        // 2. Execute namespaced shutdown functions
        self::executeShutdownFunctions();

        // 3. Reset static properties of core classes
        foreach ($this->coreClasses as $className) {
            $refClass = new \ReflectionClass($className);
            if ($refClass->isInterface() || $refClass->isTrait()) {
                continue;
            }
            $defaultProperties = $refClass->getDefaultProperties();
            foreach ($refClass->getProperties(\ReflectionProperty::IS_STATIC) as $property) {
                $property->setAccessible(true);
                $name = $property->getName();
                if (array_key_exists($name, $defaultProperties)) {
                    $property->setValue(null, $defaultProperties[$name]);
                }
            }
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
            $this->initCoreClasses();
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
        }
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

            // Return 404 if no route matches
            if ($matchedRoute === null) {
                return Response::error('Not Found', 404);
            }

            // Extract handler, params, and requiredRole from match result
            $handler = $matchedRoute['handler'];
            $params = $matchedRoute['params'] ?? [];
            $requiredRole = $matchedRoute['requiredRole'];

            // If a role is required, apply RBAC middleware
            if ($requiredRole !== null) {
                // Create a closure that wraps the handler, passing params
                $next = fn(Request $req) => $handler($req, $params);

                // Pass through RBAC middleware
                return $this->rbacMiddleware->handle($request, $next, $requiredRole);
            }

            // Otherwise, call handler directly with params
            return $handler($request, $params);
        };
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
            return $middleware->handle($request, $next);
        };
    }
}
