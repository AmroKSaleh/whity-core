<?php

namespace Whity\OpenAPI;

/**
 * OpenAPI 3.0 Schema Builder
 *
 * Helper class to construct OpenAPI specification documents.
 * Provides fluent interface for building paths, operations, and components.
 */
class SchemaBuilder
{
    /**
     * @var array The OpenAPI specification
     */
    private array $spec = [];

    /**
     * Constructor
     *
     * @param string $title API title
     * @param string $version API version
     */
    public function __construct(string $title, string $version)
    {
        $this->spec = [
            'openapi' => '3.0.0',
            'info' => [
                'title' => $title,
                'version' => $version,
            ],
            'servers' => [
                [
                    'url' => 'http://localhost:8000',
                    'description' => 'Development server',
                ]
            ],
            'paths' => [],
            'components' => [
                'schemas' => [],
                'securitySchemes' => [],
            ],
        ];
    }

    /**
     * Add a path and operation to the spec
     *
     * @param string $path The path (e.g., /api/users)
     * @param string $method The HTTP method (GET, POST, etc.)
     * @param array $operation The operation details
     * @return self
     */
    public function addPath(string $path, string $method, array $operation): self
    {
        $method = strtolower($method);

        if (!isset($this->spec['paths'][$path])) {
            $this->spec['paths'][$path] = [];
        }

        $this->spec['paths'][$path][$method] = $operation;

        return $this;
    }

    /**
     * Add Bearer token authentication scheme
     *
     * @return self
     */
    public function addBearerAuth(): self
    {
        $this->spec['components']['securitySchemes']['bearerAuth'] = [
            'type' => 'http',
            'scheme' => 'bearer',
            'bearerFormat' => 'JWT',
            'description' => 'JWT Bearer token authentication',
        ];

        return $this;
    }

    /**
     * Build and return the OpenAPI specification
     *
     * @return array The complete OpenAPI spec
     */
    public function build(): array
    {
        return $this->spec;
    }
}
