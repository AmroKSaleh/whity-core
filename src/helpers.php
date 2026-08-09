<?php

namespace Whity;

/**
 * Global service container for dependency resolution
 */
$GLOBALS['whity_services'] = $GLOBALS['whity_services'] ?? [];

/**
 * Register a service in the container
 *
 * The key is a class OR interface name. Registering under an interface (e.g.
 * {@see \Whity\Sdk\Rbac\PermissionResolver}) is the preferred form for anything
 * plugins consume: the plugin type-hints the SDK contract and the host stays
 * free to swap the implementation behind it.
 */
function register_service(string $class, $instance): void
{
    $GLOBALS['whity_services'][$class] = $instance;
}

/**
 * Resolve a service from the container
 *
 * Resolution order:
 *  1. An explicitly registered instance (the only path that can return a
 *     properly WIRED collaborator).
 *  2. Auto-instantiation, but ONLY for a concrete class that needs no
 *     constructor arguments.
 *  3. Otherwise a \RuntimeException.
 *
 * Why step 2 is bounded (WC-712)
 * ------------------------------
 * The fallback used to be a bare `new $class()`. For anything with required
 * constructor arguments — which is every non-trivial service, and notably every
 * security-relevant one — that raised an \ArgumentCountError. That is an \Error,
 * not an \Exception, so a plugin guarding its lookup with the documented
 * `catch (\Exception)` never caught it and the whole request died with a 500.
 * Reflecting first turns that into the documented, catchable \RuntimeException,
 * and states plainly that the service was never wired.
 *
 * The fallback is deliberately NOT widened into constructor autowiring. A
 * security service resolved from a container must be the instance the host
 * wired, not a fresh one the container improvised: an auto-built RoleChecker
 * pointed at a different database handle, an empty permission registry, or no
 * delegation resolver would answer authorization questions differently from the
 * middleware — the precise failure this container is now used to prevent. An
 * unwired service therefore fails CLOSED (throws) rather than resolving to a
 * plausible-looking object.
 *
 * @throws \RuntimeException When the service is not registered and cannot be
 *                           safely auto-instantiated.
 */
function app(string $class)
{
    if (isset($GLOBALS['whity_services'][$class])) {
        return $GLOBALS['whity_services'][$class];
    }

    // Fallback: instantiate only a concrete, argument-free class. Interfaces,
    // abstract classes and anything with required constructor arguments cannot
    // be built correctly here, so they fail closed instead.
    if (class_exists($class)) {
        $reflection = new \ReflectionClass($class);

        if (!$reflection->isInstantiable()) {
            throw new \RuntimeException(
                "Service '{$class}' is not registered in the container and is not instantiable."
            );
        }

        $constructor = $reflection->getConstructor();
        if ($constructor !== null && $constructor->getNumberOfRequiredParameters() > 0) {
            throw new \RuntimeException(
                "Service '{$class}' is not registered in the container and requires constructor "
                . 'arguments, so it cannot be auto-instantiated. The host must register it explicitly.'
            );
        }

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

