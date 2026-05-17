<?php

/**
 * Whity Core FrankenPHP Entry Point
 *
 * Bootstrap entry point for FrankenPHP persistent workers.
 * Initializes all components and handles incoming HTTP requests in a persistent loop.
 */

declare(strict_types=1);

use Whity\Database\Database;
use Whity\Core\Router;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\PluginLoader;
use Whity\Auth\JwtParser;
use Whity\Auth\RoleChecker;
use Whity\Http\RbacMiddleware;
use Whity\Http\HttpKernel;

// Load environment variables from .env file (skip if already set)
$envFile = dirname(__DIR__) . '/.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        // Skip comments and empty lines
        if (empty(trim($line)) || strpos(trim($line), '#') === 0) {
            continue;
        }

        // Parse KEY=VALUE format
        if (strpos($line, '=') !== false) {
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);

            // Only set if not already in environment
            if (!getenv($key) && !isset($_ENV[$key])) {
                $_ENV[$key] = $value;
                putenv("{$key}={$value}");
            }
        }
    }
}

// Require composer autoloader
require dirname(__DIR__) . '/vendor/autoload.php';

// 1. Initialize database connection
$db = Database::connect();

// 2. Initialize router
$router = new Router();

// 3. Initialize JWT parser
$jwtParser = new JwtParser($_ENV['JWT_SECRET'] ?? 'dev_secret');

// 4. Initialize role checker
$roleChecker = new RoleChecker($db);

// 5. Initialize RBAC middleware
$rbacMiddleware = new RbacMiddleware($jwtParser, $roleChecker);

// 6. Initialize plugin loader and load plugins
$pluginLoader = new PluginLoader(__DIR__ . '/../plugins', $router);
$pluginLoader->load();

// 7. Initialize HTTP kernel
$kernel = new HttpKernel($router, $rbacMiddleware);

// Handle single request
try {
    // Create request from PHP superglobals
    $request = Request::fromGlobals();

    // Handle request through kernel
    $response = $kernel->handle($request);

    // Send response to client
    $response->send();
} catch (\Throwable $e) {
    // Handle any uncaught exceptions
    try {
        $errorResponse = Response::error('Internal server error', 500);
        $errorResponse->send();
    } catch (\Throwable $sendError) {
        // If send also fails, just log it
        error_log('Failed to send error response: ' . $sendError->getMessage());
    }
    error_log($e->getMessage() . "\n" . $e->getTraceAsString());
}
