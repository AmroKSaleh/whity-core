<?php

namespace Whity\Plugins;

use Whity\Core\PluginInterface;
use Whity\Core\Request;
use Whity\Core\Response;

/**
 * AdminStats plugin - Demonstrates the plugin system with an admin-only stats endpoint
 *
 * This plugin provides a concrete example of how to implement the PluginInterface
 * to create a route that is automatically discovered and registered by the PluginLoader.
 * It requires admin role and returns various system stats along with authenticated user info.
 */
class AdminStats implements PluginInterface
{
    /**
     * Track when the plugin was instantiated (used for uptime calculation)
     *
     * @var float
     */
    private float $startTime;

    /**
     * Constructor - records the start time for uptime calculation
     */
    public function __construct()
    {
        $this->startTime = microtime(true);
    }

    /**
     * Get the route path for this plugin
     *
     * @return string The route path
     */
    public function getRoute(): string
    {
        return '/api/admin/stats';
    }

    /**
     * Get the HTTP method for this route
     *
     * @return string The HTTP method
     */
    public function getMethod(): string
    {
        return 'GET';
    }

    /**
     * Get the required role for this route
     *
     * @return string|null The required role, or null if no role required
     */
    public function getRequiredRole(): ?string
    {
        return 'admin';
    }

    /**
     * Handle the request and return stats
     *
     * Returns a JSON response containing:
     * - timestamp: Current date/time in Y-m-d H:i:s format
     * - uptime: Time elapsed since plugin instantiation in seconds (2 decimal places)
     * - message: A status message
     * - user: The authenticated user from the JWT token
     *
     * @param Request $request The HTTP request object
     * @return Response The HTTP response object
     */
    public function handle(Request $request): Response
    {
        // Calculate uptime
        $endTime = microtime(true);
        $uptime = number_format($endTime - $this->startTime, 2);

        // Build the response data
        $data = [
            'timestamp' => date('Y-m-d H:i:s'),
            'uptime' => "{$uptime} seconds",
            'message' => 'Admin stats endpoint working',
            'user' => $request->user,
        ];

        // Return JSON response
        return Response::json($data);
    }
}
