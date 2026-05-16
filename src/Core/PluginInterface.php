<?php

namespace Whity\Core;

/**
 * Interface for plugins that extend the application with additional routes
 *
 * Plugins implement this interface to provide route handlers that are automatically
 * discovered and registered with the router.
 */
interface PluginInterface
{
    /**
     * Get the route path
     *
     * @return string Route path (e.g., '/api/admin/stats', '/health')
     */
    public function getRoute(): string;

    /**
     * Get the HTTP method
     *
     * @return string HTTP method (GET, POST, PUT, DELETE, PATCH, etc.)
     */
    public function getMethod(): string;

    /**
     * Get the required role for this route, if any
     *
     * @return string|null Required role name (e.g., 'admin'), or null for public routes
     */
    public function getRequiredRole(): ?string;

    /**
     * Handle the request
     *
     * @param Request $request The HTTP request
     * @return Response The HTTP response
     */
    public function handle(Request $request): Response;
}
