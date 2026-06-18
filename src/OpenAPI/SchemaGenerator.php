<?php

declare(strict_types=1);

namespace Whity\OpenAPI;

use Whity\Core\PluginLoader;
use Whity\Core\Router;

/**
 * OpenAPI 3.0 Schema Generator (WC-166)
 *
 * Generates the OpenAPI specification from registered routes. When a Router
 * is provided, the ROUTER is the source of truth — it carries every
 * registered route (core and plugin alike) together with the optional typed
 * `schema` declaration each route may have made (summary, tags, request
 * component, per-status responses, contributed component schemas). Routes
 * without a declaration keep the legacy default operation, so undeclared
 * plugins remain documented.
 *
 * Without a Router (legacy mode), plugin routes are introspected directly
 * from the PluginLoader as before.
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
     * @var Router|null Router carrying all registered routes (preferred source)
     */
    private ?Router $router;

    /**
     * @var list<string> Component conflicts collected during the current generation.
     */
    private array $conflicts = [];

    /**
     * Constructor
     *
     * @param string $title API title
     * @param string $version API version
     * @param PluginLoader $pluginLoader Plugin loader instance
     * @param Router|null $router Router with the registered routes (preferred).
     */
    public function __construct(string $title, string $version, PluginLoader $pluginLoader, ?Router $router = null)
    {
        $this->title = $title;
        $this->version = $version;
        $this->pluginLoader = $pluginLoader;
        $this->router = $router;
    }

    /**
     * Generate the OpenAPI spec.
     *
     * @return array<string, mixed> OpenAPI 3.0 specification
     */
    public function generate(): array
    {
        return $this->generateAndValidate()['spec'];
    }

    /**
     * Generate the OpenAPI spec AND structurally validate it.
     *
     * @return array{spec: array<string, mixed>, errors: list<string>}
     */
    public function generateAndValidate(): array
    {
        $builder = new SchemaBuilder($this->title, $this->version);
        $builder->addBearerAuth();
        $this->conflicts = [];

        // Every operation references the uniform error envelope (the standard
        // error surface injected by addOperation), so the `Error` component
        // must always be present. Registering the canonical definition here is
        // idempotent with the identical per-route contribution made by
        // CoreApiSchemas — registering the same shape twice is a no-op.
        $builder->addComponentSchema('Error', CoreApiSchemas::components()['Error']);

        foreach ($this->routeDeclarations() as $route) {
            $this->addOperation(
                $builder,
                $route['method'],
                $route['path'],
                $route['requiredRole'] ?? $route['requiredPermission'],
                $route['schema']
            );
        }

        return [
            'spec' => $builder->build(),
            'errors' => array_values(array_merge($builder->validate(), $this->conflicts)),
        ];
    }

    /**
     * JSON-encode a built spec, preserving empty maps as JSON OBJECTS.
     *
     * PHP's empty array is ambiguous and would encode `components.schemas`
     * (and friends) as `[]` — invalid OpenAPI that external validators
     * reject. Output is pretty-printed and slash-preserving, matching the
     * committed public/openapi.json format.
     *
     * @param array<string, mixed> $spec The built spec.
     * @return string Deterministic JSON document.
     */
    public static function encode(array $spec): string
    {
        foreach (['schemas', 'securitySchemes'] as $key) {
            if (($spec['components'][$key] ?? null) === []) {
                $spec['components'][$key] = new \stdClass();
            }
        }
        if (($spec['paths'] ?? null) === []) {
            $spec['paths'] = new \stdClass();
        }

        return (string) json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Collect route declarations from the preferred source.
     *
     * @return list<array{method: string, path: string, requiredRole: ?string, schema: array<string, mixed>|null}>
     */
    private function routeDeclarations(): array
    {
        $declarations = [];

        if ($this->router !== null) {
            foreach ($this->router->getRoutes() as $route) {
                $declarations[] = [
                    'method' => $route['method'],
                    'path' => $route['path'],
                    'requiredRole' => $route['requiredRole'] ?? null,
                    'requiredPermission' => $route['requiredPermission'] ?? null,
                    'schema' => $route['schema'] ?? null,
                ];
            }

            return $declarations;
        }

        // Legacy mode: introspect plugin routes directly.
        foreach ($this->pluginLoader->getPlugins() as $plugin) {
            foreach ($plugin->getRoutes() as $route) {
                $schema = isset($route['schema']) && is_array($route['schema']) ? $route['schema'] : null;
                $declarations[] = [
                    'method' => $route['method'],
                    'path' => $route['path'],
                    'requiredRole' => $route['requiredRole'] ?? null,
                    'requiredPermission' => null,
                    'schema' => $schema,
                ];
            }
        }

        return $declarations;
    }

    /**
     * Add one route's operation to the builder.
     *
     * @param SchemaBuilder $builder The spec builder.
     * @param string $method HTTP method.
     * @param string $path Route path.
     * @param string|null $requiredRole Declared role (maps to bearerAuth security).
     * @param array<string, mixed>|null $schema The route's typed declaration, if any.
     */
    private function addOperation(
        SchemaBuilder $builder,
        string $method,
        string $path,
        ?string $requiredRole,
        ?array $schema
    ): void {
        // Hoist component schemas the route contributes. An identical
        // re-contribution is idempotent; a CONFLICTING redefinition keeps the
        // first definition AND fails validation — the losing route's $refs
        // would otherwise lie about the shape its handler produces.
        $components = $schema['components'] ?? [];
        if (is_array($components)) {
            foreach ($components as $name => $definition) {
                if (!is_string($name) || !is_array($definition)) {
                    error_log("[openapi] {$method} {$path}: ignoring malformed component declaration");
                    continue;
                }
                try {
                    $builder->addComponentSchema($name, $definition);
                } catch (\InvalidArgumentException $e) {
                    $this->conflicts[] = "{$method} {$path}: " . $e->getMessage();
                    error_log("[openapi] {$method} {$path}: " . $e->getMessage());
                }
            }
        } elseif ($components !== null && $components !== []) {
            error_log("[openapi] {$method} {$path}: 'components' must be an array of name => schema");
        }

        // OpenAPI path templates carry plain {name} placeholders: strip
        // routing constraints and DECLARE every path parameter.
        ['path' => $specPath, 'parameters' => $pathParameters] = $this->sanitizePath($path);

        $operation = [
            'operationId' => is_string($schema['operationId'] ?? null)
                ? $schema['operationId']
                : $this->operationId($method, $specPath),
            'summary' => is_string($schema['summary'] ?? null)
                ? $schema['summary']
                : $this->generateSummary($method, $path),
            'tags' => is_array($schema['tags'] ?? null) && ($schema['tags'] ?? []) !== []
                ? array_values($schema['tags'])
                : [$this->getTag($path)],
        ];

        // Declared (query/header) parameters are appended after the
        // auto-declared path parameters.
        $declaredParameters = $schema['parameters'] ?? null;
        if (is_array($declaredParameters)) {
            $pathParameters = array_merge($pathParameters, array_values($declaredParameters));
        }

        if ($pathParameters !== []) {
            $operation['parameters'] = $pathParameters;
        }

        // Typed request body.
        $request = $schema['request'] ?? null;
        if (is_string($request) && $request !== '') {
            $operation['requestBody'] = $this->jsonBody(SchemaBuilder::ref($request));
        } elseif (is_array($request) && $request !== []) {
            $operation['requestBody'] = $this->jsonBody($request);
        }

        // Responses: declared per-status, or the success-only default. The
        // standard error surface (404/405/500 always; 401/403/400
        // conditionally) is injected below — the fallback no longer hard-codes
        // 401/403, so public routes stop falsely advertising auth errors.
        $declaredResponses = $schema['responses'] ?? null;
        if (is_array($declaredResponses) && $declaredResponses !== []) {
            $responses = [];
            foreach ($declaredResponses as $status => $shape) {
                $responses[(string) $status] = $this->responseObject($shape);
            }
            $operation['responses'] = $responses;
        } else {
            if ($declaredResponses !== null && !is_array($declaredResponses)) {
                error_log("[openapi] {$method} {$path}: 'responses' must be an array of status => shape; using defaults");
            }
            $operation['responses'] = [
                '200' => ['description' => 'Successful response'],
            ];
        }

        // Add security requirement if a role/permission is required. This is
        // the SAME signal used to decide the 401 envelope below.
        if ($requiredRole !== null) {
            $operation['security'] = [
                ['bearerAuth' => []],
            ];
        }

        // Inject the standard error envelopes, MERGE-NOT-CLOBBER: an
        // explicitly declared response for a status code always wins. 401 is
        // emitted iff the operation carries security; 403 iff a role/permission
        // gate exists; 400 iff a request body is declared.
        $standard = $this->standardErrorResponses(
            isset($operation['security']),
            $requiredRole !== null,
            isset($operation['requestBody'])
        );
        foreach ($standard as $status => $responseObject) {
            if (!isset($operation['responses'][$status])) {
                $operation['responses'][$status] = $responseObject;
            }
        }

        // Deterministic, ascending status-code ordering for byte-stable output.
        ksort($operation['responses'], SORT_STRING);

        $builder->addPath($specPath, $method, $operation);
    }

    /**
     * Convert a router path into an OpenAPI path template + parameter list.
     *
     * `{id:\d+}` becomes `{id}` with a declared integer path parameter;
     * unconstrained `{name}` placeholders declare string parameters.
     *
     * @param string $path The registered route path.
     * @return array{path: string, parameters: list<array<string, mixed>>}
     */
    private function sanitizePath(string $path): array
    {
        $parameters = [];
        $clean = preg_replace_callback(
            '#\{([a-zA-Z_][a-zA-Z0-9_]*)(?::([^{}]+))?\}#',
            static function (array $matches) use (&$parameters): string {
                $constraint = $matches[2] ?? '';
                $type = in_array($constraint, ['\d+', '[0-9]+'], true) ? 'integer' : 'string';
                $parameters[] = [
                    'name' => $matches[1],
                    'in' => 'path',
                    'required' => true,
                    'schema' => ['type' => $type],
                ];

                return '{' . $matches[1] . '}';
            },
            $path
        );

        return ['path' => is_string($clean) ? $clean : $path, 'parameters' => $parameters];
    }

    /**
     * Deterministic operationId for typed-client generators (stable method
     * names in #168): lowercase method + underscored path.
     */
    private function operationId(string $method, string $specPath): string
    {
        $slug = strtolower(trim((string) preg_replace('/[^a-zA-Z0-9]+/', '_', $specPath), '_'));

        return strtolower($method) . '_' . $slug;
    }

    /**
     * Build a response object from a declared shape.
     *
     * - string: a component schema name → $ref'd application/json body.
     * - array WITH 'description': used verbatim as the response object.
     * - other array: an inline schema for an application/json body.
     *
     * @param mixed $shape The declared response shape.
     * @return array<string, mixed>
     */
    private function responseObject(mixed $shape): array
    {
        if (is_string($shape) && $shape !== '') {
            return [
                'description' => $shape,
                'content' => ['application/json' => ['schema' => SchemaBuilder::ref($shape)]],
            ];
        }

        if (is_array($shape)) {
            if (isset($shape['description'])) {
                return $shape;
            }

            return [
                'description' => 'Response',
                'content' => ['application/json' => ['schema' => $shape]],
            ];
        }

        return ['description' => 'Response'];
    }

    /**
     * Build the standard error-response envelopes injected into every
     * operation. Each response references the uniform `Error` component (the
     * runtime envelope produced by `Response::error()`), so clients and MCP
     * see the full error surface.
     *
     * Universal (transport-level) for every operation:
     *   - 404 Not found, 405 Method not allowed, 500 Internal server error.
     * Conditional:
     *   - 401 Unauthorized        when the operation requires authentication;
     *   - 403 Forbidden           when the operation is permission/role gated;
     *   - 400 Invalid request body when the operation declares a request body.
     *
     * 422/429 are NOT injected — they are not universal and remain owned by
     * explicit route declarations.
     *
     * @param bool $authenticated Whether the operation requires authentication.
     * @param bool $authorized Whether the operation is permission/role gated.
     * @param bool $hasRequestBody Whether the operation declares a request body.
     * @return array<int, array{description: string, content: array{'application/json': array{schema: array{'$ref': string}}}}>
     */
    private function standardErrorResponses(bool $authenticated, bool $authorized, bool $hasRequestBody): array
    {
        $responses = [];

        if ($hasRequestBody) {
            $responses['400'] = $this->errorResponse('Invalid request body');
        }
        if ($authenticated) {
            $responses['401'] = $this->errorResponse('Unauthorized');
        }
        if ($authorized) {
            $responses['403'] = $this->errorResponse('Forbidden');
        }
        $responses['404'] = $this->errorResponse('Not found');
        $responses['405'] = $this->errorResponse('Method not allowed');
        $responses['500'] = $this->errorResponse('Internal server error');

        return $responses;
    }

    /**
     * A single error response object referencing the `Error` component.
     *
     * @param string $description Human-readable description of the error.
     * @return array{description: string, content: array{'application/json': array{schema: array{'$ref': string}}}}
     */
    private function errorResponse(string $description): array
    {
        return [
            'description' => $description,
            'content' => ['application/json' => ['schema' => SchemaBuilder::ref('Error')]],
        ];
    }

    /**
     * Wrap a schema (or $ref) into a required application/json request body.
     *
     * @param array<string, mixed> $schema The schema or $ref.
     * @return array<string, mixed>
     */
    private function jsonBody(array $schema): array
    {
        return [
            'required' => true,
            'content' => ['application/json' => ['schema' => $schema]],
        ];
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
        $action = match (strtoupper($method)) {
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
