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
 *  2. Auto-instantiation, but ONLY for a concrete class that takes NO
 *     constructor parameters at all and does not declare itself host-wired.
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
 * Why step 2 is bounded TWICE
 * ---------------------------
 * "Concrete and argument-free" is a statement about constructor SHAPE, and
 * shape is the wrong proxy for the property that matters. PermissionRegistry —
 * concrete, one OPTIONAL argument — walked straight through it: an unregistered
 * lookup returned a fresh, EMPTY permission catalogue rather than the one the
 * plugin loader had filled, so a plugin's `exists('its:permission')` answered
 * false, it failed closed, and nothing threw or logged. Two guards now stand
 * where one did:
 *
 *  - {@see \Whity\Core\Container\HostWiredService} — an explicit, self-declared
 *    opt-out. A class whose emptiness is indistinguishable from a legitimate
 *    state (every registry: "no permissions", "no probes", "no handler for this
 *    job" are all ordinary answers) says so, and is never improvised regardless
 *    of its constructor.
 *  - "no constructor parameters AT ALL", tightened from "no REQUIRED
 *    parameters". An optional constructor argument is a COLLABORATOR the host
 *    passes (a HookManager, a logger, an ownership registry); building the
 *    class without it yields an object that is silently deaf — it dispatches no
 *    events and validates against nothing — which is the same silent-divergence
 *    failure one level down. The narrow convenience this removes (auto-building
 *    an unregistered optional-argument class) has no call site in this
 *    repository; the failure it prevents had one in production.
 *
 * @throws \RuntimeException When the service is not registered and cannot be
 *                           safely auto-instantiated.
 */
function app(string $class)
{
    if (isset($GLOBALS['whity_services'][$class])) {
        return $GLOBALS['whity_services'][$class];
    }

    // Fallback: instantiate only a concrete, parameter-free class that has not
    // declared itself host-wired. Interfaces, abstract classes, anything taking
    // constructor parameters, and anything marked HostWiredService cannot be
    // built correctly here, so they fail closed instead.
    if (class_exists($class)) {
        $reflection = new \ReflectionClass($class);

        // Checked first: a host-wired service gets the message that says WHY,
        // whatever its constructor happens to look like.
        if ($reflection->implementsInterface(\Whity\Core\Container\HostWiredService::class)) {
            throw new \RuntimeException(
                "Service '{$class}' is not registered in the container and is marked "
                . \Whity\Core\Container\HostWiredService::class
                . ', so it is never auto-instantiated: an improvised instance would be EMPTY '
                . 'and an empty one is indistinguishable from a legitimately empty one, so the '
                . 'caller would silently get wrong answers instead of an error. The host entry '
                . 'point must register the populated instance explicitly.'
            );
        }

        if (!$reflection->isInstantiable()) {
            throw new \RuntimeException(
                "Service '{$class}' is not registered in the container and is not instantiable."
            );
        }

        $constructor = $reflection->getConstructor();
        if ($constructor !== null && $constructor->getNumberOfParameters() > 0) {
            throw new \RuntimeException(
                "Service '{$class}' is not registered in the container and declares constructor "
                . 'parameters, so it cannot be auto-instantiated (an optional parameter is a '
                . 'collaborator the host supplies, not one the container may drop). The host must '
                . 'register it explicitly.'
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

