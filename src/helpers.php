<?php

namespace Whity;

/**
 * Global service container for dependency resolution
 */
$GLOBALS['whity_services'] = $GLOBALS['whity_services'] ?? [];

/**
 * Register a service in the container
 */
function register_service(string $class, $instance): void
{
    $GLOBALS['whity_services'][$class] = $instance;
}

/**
 * Resolve a service from the container
 */
function app(string $class)
{
    if (isset($GLOBALS['whity_services'][$class])) {
        return $GLOBALS['whity_services'][$class];
    }

    // Fallback: try to instantiate the class if it's a concrete class
    if (class_exists($class)) {
        return new $class();
    }

    throw new \RuntimeException("Service '{$class}' not found in container");
}

function register_shutdown_function(callable $callback, mixed ...$args): void
{
    \Whity\Http\HttpKernel::registerShutdownFunction($callback, ...$args);
}

namespace Whity\Core;

function register_shutdown_function(callable $callback, mixed ...$args): void
{
    \Whity\Http\HttpKernel::registerShutdownFunction($callback, ...$args);
}

namespace Whity\Http;

function register_shutdown_function(callable $callback, mixed ...$args): void
{
    \Whity\Http\HttpKernel::registerShutdownFunction($callback, ...$args);
}

namespace Whity\Auth;

function register_shutdown_function(callable $callback, mixed ...$args): void
{
    \Whity\Http\HttpKernel::registerShutdownFunction($callback, ...$args);
}

namespace Whity\Api;

function register_shutdown_function(callable $callback, mixed ...$args): void
{
    \Whity\Http\HttpKernel::registerShutdownFunction($callback, ...$args);
}

namespace Whity\Database;

function register_shutdown_function(callable $callback, mixed ...$args): void
{
    \Whity\Http\HttpKernel::registerShutdownFunction($callback, ...$args);
}

namespace Whity\Cli;

function register_shutdown_function(callable $callback, mixed ...$args): void
{
    \Whity\Http\HttpKernel::registerShutdownFunction($callback, ...$args);
}

namespace Whity\Console;

function register_shutdown_function(callable $callback, mixed ...$args): void
{
    \Whity\Http\HttpKernel::registerShutdownFunction($callback, ...$args);
}

namespace Whity\Commands;

function register_shutdown_function(callable $callback, mixed ...$args): void
{
    \Whity\Http\HttpKernel::registerShutdownFunction($callback, ...$args);
}

namespace Tests\Http;

function register_shutdown_function(callable $callback, mixed ...$args): void
{
    \Whity\Http\HttpKernel::registerShutdownFunction($callback, ...$args);
}

namespace Tests\Security;

function register_shutdown_function(callable $callback, mixed ...$args): void
{
    \Whity\Http\HttpKernel::registerShutdownFunction($callback, ...$args);
}

