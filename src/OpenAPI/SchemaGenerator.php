<?php

namespace Whity\OpenAPI;

use Whity\Core\PluginLoader;

/**
 * OpenAPI 3.0 Schema Generator
 *
 * Generates OpenAPI 3.0 specification from discovered plugins.
 * Introspects PluginInterface implementations and builds a complete API schema.
 */
class SchemaGenerator
{
    /**
     * @var string API title
     */
    private string $title;

    /**
     * @var string API version
     */
    private string $version;

    /**
     * @var PluginLoader Plugin loader instance
     */
    private PluginLoader $pluginLoader;

    /**
     * Constructor
     *
     * @param string $title API title
     * @param string $version API version
     * @param PluginLoader $pluginLoader Plugin loader instance
     */
    public function __construct(string $title, string $version, PluginLoader $pluginLoader)
    {
        $this->title = $title;
        $this->version = $version;
        $this->pluginLoader = $pluginLoader;
    }

    /**
     * Generate OpenAPI spec from all discovered plugins
     *
     * @return array<string, mixed> OpenAPI 3.0 specification
     */
    public function generate(): array
    {
        $builder = new SchemaBuilder($this->title, $this->version);

        // Add Bearer auth
        $builder->addBearerAuth();

        // Add paths from plugins
        $plugins = $this->pluginLoader->getPlugins();
        foreach ($plugins as $plugin) {
            foreach ($plugin->getRoutes() as $route) {
                $path = $route['path'];
                $method = $route['method'];
                $requiredRole = $route['requiredRole'] ?? null;

                $operation = [
                    'summary' => $this->generateSummary($method, $path),
                    'tags' => [$this->getTag($path)],
                    'responses' => [
                        '200' => [
                            'description' => 'Successful response',
                        ],
                        '401' => [
                            'description' => 'Unauthorized',
                        ],
                        '403' => [
                            'description' => 'Forbidden',
                        ],
                    ],
                ];

                // Add security requirement if role is required
                if ($requiredRole !== null) {
                    $operation['security'] = [
                        ['bearerAuth' => []],
                    ];
                }

                $builder->addPath($path, $method, $operation);
            }
        }

        return $builder->build();
    }

    /**
     * Generate summary from method and path
     *
     * @param string $method HTTP method
     * @param string $path Route path
     * @return string Summary
     */
    private function generateSummary(string $method, string $path): string
    {
        $action = match(strtoupper($method)) {
            'GET' => 'Get',
            'POST' => 'Create',
            'PUT' => 'Update',
            'PATCH' => 'Update',
            'DELETE' => 'Delete',
            default => strtoupper($method),
        };

        $resource = ucfirst(trim($path, '/'));
        return "{$action} {$resource}";
    }

    /**
     * Extract tag from path
     *
     * @param string $path Route path
     * @return string Tag
     */
    private function getTag(string $path): string
    {
        $parts = array_values(array_filter(explode('/', $path)));
        if (count($parts) >= 2) {
            return ucfirst($parts[1]); // e.g., /api/users -> Users
        }
        return 'General';
    }
}
