<?php

declare(strict_types=1);

namespace Whity\OpenAPI;

/**
 * OpenAPI 3.0 Schema Builder (WC-166: the contract engine)
 *
 * Constructs OpenAPI specification documents: paths/operations, named
 * component schemas referenced via `$ref`, and security schemes. The built
 * document is DETERMINISTIC — paths, methods, and component schemas are
 * emitted in sorted order so regeneration is byte-stable — and can be
 * structurally validated before it is written anywhere.
 */
class SchemaBuilder
{
    /**
     * @var array<string, mixed> The OpenAPI specification
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
     * Build a `$ref` to a named component schema.
     *
     * @param string $name The component schema name.
     * @return array{'$ref': string}
     */
    public static function ref(string $name): array
    {
        return ['$ref' => '#/components/schemas/' . $name];
    }

    /**
     * Register a named component schema (components.schemas).
     *
     * Registering the identical definition twice is an idempotent no-op (two
     * routes may legitimately contribute the same shared schema); registering
     * a DIFFERENT definition under an existing name throws — silently letting
     * one definition shadow another would corrupt the typed contract.
     *
     * @param string $name The component schema name (used in $ref).
     * @param array<string, mixed> $schema The JSON-Schema definition.
     * @return self
     * @throws \InvalidArgumentException On a conflicting redefinition.
     */
    public function addComponentSchema(string $name, array $schema): self
    {
        $existing = $this->spec['components']['schemas'][$name] ?? null;
        if ($existing !== null) {
            if ($existing === $schema) {
                return $this;
            }

            throw new \InvalidArgumentException(
                "Component schema '{$name}' is already registered with a different definition"
            );
        }

        $this->spec['components']['schemas'][$name] = $schema;

        return $this;
    }

    /**
     * Add a path and operation to the spec
     *
     * @param string $path The path (e.g., /api/users)
     * @param string $method The HTTP method (GET, POST, etc.)
     * @param array<string, mixed> $operation The operation details
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
     * Structurally validate the spec.
     *
     * Checks the invariants a consumer (typed-client generator, schema-driven
     * UI) depends on: required top-level members, every operation carrying a
     * non-empty `responses` object, and every `$ref` in the document resolving
     * to a registered component schema. Returns human-readable error strings;
     * an empty list means valid.
     *
     * @return list<string> Validation errors (empty = valid).
     */
    public function validate(): array
    {
        $errors = [];

        foreach (['openapi', 'info', 'paths'] as $required) {
            if (!isset($this->spec[$required])) {
                $errors[] = "Missing required top-level member '{$required}'";
            }
        }

        foreach ($this->spec['paths'] as $path => $operations) {
            if (!is_array($operations)) {
                $errors[] = "Path '{$path}' must map to an operations object";
                continue;
            }

            foreach ($operations as $method => $operation) {
                if (!is_array($operation)) {
                    $errors[] = "Operation {$method} {$path} must be an object";
                    continue;
                }

                $responses = $operation['responses'] ?? null;
                if (!is_array($responses) || $responses === []) {
                    $errors[] = "Operation {$method} {$path} has no responses";
                }
            }
        }

        $this->collectDanglingRefs($this->spec, $errors);

        return $errors;
    }

    /**
     * Build and return the OpenAPI specification.
     *
     * Output ordering is deterministic: paths, the methods within each path,
     * and component schemas are sorted, so regeneration over the same inputs
     * is byte-identical regardless of registration order.
     *
     * @return array<string, mixed> The complete OpenAPI spec
     */
    public function build(): array
    {
        $spec = $this->spec;

        ksort($spec['paths']);
        foreach ($spec['paths'] as &$operations) {
            if (is_array($operations)) {
                ksort($operations);
            }
        }
        unset($operations);

        ksort($spec['components']['schemas']);
        ksort($spec['components']['securitySchemes']);

        return $spec;
    }

    /**
     * Recursively flag `$ref`s that point to unregistered component schemas.
     *
     * @param array<array-key, mixed> $node The subtree to scan.
     * @param list<string> $errors Accumulated errors (by reference).
     */
    private function collectDanglingRefs(array $node, array &$errors): void
    {
        foreach ($node as $key => $value) {
            if ($key === '$ref' && is_string($value)) {
                if (str_starts_with($value, '#/components/schemas/')) {
                    $name = substr($value, strlen('#/components/schemas/'));
                    if (!isset($this->spec['components']['schemas'][$name])) {
                        $errors[] = "Dangling \$ref '{$value}': component schema '{$name}' is not registered";
                    }
                } else {
                    $errors[] = "Unsupported \$ref target '{$value}' (only #/components/schemas/* is supported)";
                }
                continue;
            }

            if (is_array($value)) {
                $this->collectDanglingRefs($value, $errors);
            }
        }
    }
}
