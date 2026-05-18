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
