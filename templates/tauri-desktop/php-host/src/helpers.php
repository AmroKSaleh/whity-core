<?php

declare(strict_types=1);

namespace Whity;

/**
 * Minimal reimplementation of production's global service container
 * (src/helpers.php in the main whity-core repo). Plugins call \Whity\app()
 * to resolve host services (Database, TenantContext, NativeBridgeClient) —
 * this is the entire mechanism, no auto-instantiation fallback.
 *
 * Deliberately simpler than production's version: this offline host only
 * ever registers services explicitly (see public/worker.php's boot
 * sequence), so the bounded-fallback/HostWiredService machinery production
 * needs for its much larger service surface has nothing to guard here.
 */
$GLOBALS['whity_services'] = $GLOBALS['whity_services'] ?? [];

/**
 * Register a service instance under a class or interface name.
 */
function register_service(string $class, $instance): void
{
    $GLOBALS['whity_services'][$class] = $instance;
}

/**
 * Resolve a previously registered service.
 *
 * @throws \RuntimeException When the service was never registered — fails
 *   closed, same as production, rather than improvising an instance.
 */
function app(string $class)
{
    if (isset($GLOBALS['whity_services'][$class])) {
        return $GLOBALS['whity_services'][$class];
    }

    throw new \RuntimeException(
        "Service '{$class}' is not registered in the offline PHP host container."
    );
}
