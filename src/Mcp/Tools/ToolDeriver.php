<?php

declare(strict_types=1);

namespace Whity\Mcp\Tools;

use Whity\Core\Router;

/**
 * Derives MCP tool definitions from route declarations (WC-001754c6).
 *
 * Consumes a list of normalized route declarations — each carrying at minimum
 * a method, path, and schema — and converts every schema-bearing route into
 * an MCP tool with a stable name (operationId or derived), a human-readable
 * description, and a flat JSON-Schema inputSchema that merges path parameters,
 * declared query parameters, and request-body component properties.
 *
 * The operationId and path-sanitization logic mirrors SchemaGenerator so that
 * tool names stay in sync with the OpenAPI spec.
 *
 * The optional $router parameter enables lazy inclusion of plugin routes: the
 * Router is read at deriveTools() call time (not at construction), so plugin
 * routes registered after the ToolDeriver is built are naturally included.
 * Core route declarations are passed as the static $staticDeclarations list.
 *
 * Worker-safe: stateless — all computation is per-call on the stack. Callers
 * that want to avoid re-deriving on every tools/list call should cache the
 * result at worker boot (see WC-951d99d3).
 */
final class ToolDeriver
{
    /**
     * @param list<array<string, mixed>> $staticDeclarations
     *   Core (or any pre-built) route declarations. Each entry must contain at
     *   least: 'method' (string), 'path' (string), 'schema' (array|null).
     *   Entries with null or empty schema are silently skipped.
     * @param array<string, array<string, mixed>> $components
     *   Global component schemas keyed by name (e.g. 'UserCreateRequest').
     *   Used to resolve string request-body references in schema['request'].
     * @param Router|null $router
     *   When provided, routes that carry a schema are read from the router at
     *   deriveTools() time and merged with $staticDeclarations. This allows
     *   plugin-contributed routes (loaded after construction) to appear as
     *   tools without requiring a rebuild of the ToolDeriver.
     */
    public function __construct(
        private readonly array $staticDeclarations,
        private readonly array $components = [],
        private readonly ?Router $router = null,
    ) {}

    /**
     * Derive MCP tool definitions for all schema-bearing route declarations.
     *
     * Merges static declarations with any schema-bearing routes currently in
     * the router (for plugin routes, which are loaded after construction).
     *
     * @return list<array<string, mixed>> MCP tool objects ready for tools/list.
     */
    public function deriveTools(): array
    {
        $declarations = $this->staticDeclarations;

        if ($this->router !== null) {
            foreach ($this->router->getRoutes() as $route) {
                $schema = $route['schema'] ?? null;
                if (is_array($schema) && $schema !== []) {
                    $declarations[] = $route;
                }
            }
        }

        $tools = [];
        foreach ($declarations as $route) {
            $schema = $route['schema'] ?? null;
            if (!is_array($schema) || $schema === []) {
                continue;
            }
            $tools[] = $this->deriveTool(
                (string) ($route['method'] ?? ''),
                (string) ($route['path'] ?? ''),
                $schema,
            );
        }
        return $tools;
    }

    /**
     * Return the JSON-Schema inputSchema for the named tool, or null if the
     * tool is not found.
     *
     * The schema is derived the same way as for tools/list — so the validation
     * spec the AI client sees is identical to the spec used for server-side
     * argument validation (WC-b570dccd).
     *
     * @return array<string, mixed>|null
     */
    public function getToolInputSchema(string $toolName): ?array
    {
        $decl = $this->findDeclarationByName($toolName);
        if ($decl === null) {
            return null;
        }
        ['parameters' => $pathParams] = $this->sanitizePath((string) ($decl['path'] ?? ''));
        return $this->buildInputSchema($pathParams, (array) ($decl['schema'] ?? []));
    }

    /**
     * Find the route declaration whose derived tool name matches $toolName.
     *
     * Searches static declarations first, then router routes (if a Router was
     * provided). Returns the raw declaration array — which includes 'method',
     * 'path', and 'schema' — or null when no match is found.
     *
     * @return array<string, mixed>|null
     */
    public function findDeclarationByName(string $toolName): ?array
    {
        foreach ($this->staticDeclarations as $decl) {
            $schema = $decl['schema'] ?? null;
            if (!is_array($schema) || $schema === []) {
                continue;
            }
            ['path' => $cleanPath] = $this->sanitizePath((string) ($decl['path'] ?? ''));
            $name = is_string($schema['operationId'] ?? null)
                ? $schema['operationId']
                : $this->operationId((string) ($decl['method'] ?? ''), $cleanPath);
            if ($name === $toolName) {
                return $decl;
            }
        }

        if ($this->router !== null) {
            foreach ($this->router->getRoutes() as $route) {
                $schema = $route['schema'] ?? null;
                if (!is_array($schema) || $schema === []) {
                    continue;
                }
                ['path' => $cleanPath] = $this->sanitizePath($route['path']);
                $name = is_string($schema['operationId'] ?? null)
                    ? $schema['operationId']
                    : $this->operationId($route['method'], $cleanPath);
                if ($name === $toolName) {
                    return $route;
                }
            }
        }

        return null;
    }

    /**
     * Convert one route declaration into an MCP tool definition.
     *
     * @param array<string, mixed> $schema Route schema declaration.
     * @return array<string, mixed> MCP tool object.
     */
    private function deriveTool(string $method, string $path, array $schema): array
    {
        ['path' => $cleanPath, 'parameters' => $pathParams] = $this->sanitizePath($path);

        $name = is_string($schema['operationId'] ?? null)
            ? $schema['operationId']
            : $this->operationId($method, $cleanPath);

        $description = is_string($schema['summary'] ?? null)
            ? $schema['summary']
            : $this->generateSummary($method, $path);

        return [
            'name'        => $name,
            'description' => $description,
            'inputSchema' => $this->buildInputSchema($pathParams, $schema),
        ];
    }

    /**
     * Build a flat JSON-Schema object that represents all inputs to the tool.
     *
     * Merges: (1) auto-extracted path parameters, (2) declared query parameters
     * from schema['parameters'], and (3) request-body properties from
     * schema['request'] (either a component name or an inline schema object).
     *
     * @param list<array<string, mixed>> $pathParams  Auto-extracted path parameters.
     * @param array<string, mixed>       $schema      Route schema declaration.
     * @return array<string, mixed>
     */
    private function buildInputSchema(array $pathParams, array $schema): array
    {
        $properties = [];
        $required   = [];

        // 1. Path parameters — always required.
        foreach ($pathParams as $param) {
            $name = (string) ($param['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $type = (string) ($param['schema']['type'] ?? 'string');
            $properties[$name] = ['type' => $type];
            $required[] = $name;
        }

        // 2. Declared query parameters from schema['parameters'].
        $declaredParams = $schema['parameters'] ?? null;
        if (is_array($declaredParams)) {
            foreach ($declaredParams as $param) {
                if (!is_array($param)) {
                    continue;
                }
                $in = (string) ($param['in'] ?? 'query');
                if ($in !== 'query') {
                    continue;
                }
                $name = (string) ($param['name'] ?? '');
                if ($name === '') {
                    continue;
                }
                $paramSchema = is_array($param['schema'] ?? null) ? $param['schema'] : ['type' => 'string'];
                if (isset($param['description']) && is_string($param['description'])) {
                    $paramSchema['description'] = $param['description'];
                }
                $properties[$name] = $paramSchema;
                if ($param['required'] ?? false) {
                    $required[] = $name;
                }
            }
        }

        // 3. Request body: component reference (string) or inline schema (array).
        $request    = $schema['request'] ?? null;
        $bodySchema = null;

        if (is_string($request) && $request !== '') {
            $bodySchema = $this->components[$request] ?? null;
            $bodySchema = is_array($bodySchema) ? $bodySchema : null;
        } elseif (is_array($request) && $request !== []) {
            // Skip multipart/custom bodies (have a 'content' key) — they are
            // not expressible as a flat JSON-Schema object input.
            if (!isset($request['content'])) {
                $bodySchema = $request;
            }
        }

        if (is_array($bodySchema)) {
            $this->mergeObjectSchema($bodySchema, $properties, $required);
        }

        $result = ['type' => 'object'];
        if ($properties !== []) {
            $result['properties'] = $properties;
        }
        $required = array_values(array_unique($required));
        if ($required !== []) {
            $result['required'] = $required;
        }

        return $result;
    }

    /**
     * Merge the properties and required fields of an object schema.
     *
     * @param array<string, mixed>  $schema     Source object schema.
     * @param array<string, mixed>  $properties Properties accumulator (modified in-place).
     * @param list<string>          $required   Required-key accumulator (modified in-place).
     */
    private function mergeObjectSchema(array $schema, array &$properties, array &$required): void
    {
        $schemaProps = $schema['properties'] ?? null;
        if (is_array($schemaProps)) {
            foreach ($schemaProps as $name => $propSchema) {
                if (!is_string($name) || !is_array($propSchema)) {
                    continue;
                }
                $properties[$name] = $propSchema;
            }
        }

        $schemaRequired = $schema['required'] ?? null;
        if (is_array($schemaRequired)) {
            foreach ($schemaRequired as $name) {
                if (is_string($name)) {
                    $required[] = $name;
                }
            }
        }
    }

    /**
     * Strip routing constraints from path segments and collect declared path
     * parameters with their inferred types.
     *
     * Mirrors SchemaGenerator::sanitizePath() so operationIds stay consistent.
     *
     * @param string $path Route path (may contain `{name:constraint}` segments).
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
                    'name'     => $matches[1],
                    'in'       => 'path',
                    'required' => true,
                    'schema'   => ['type' => $type],
                ];
                return '{' . $matches[1] . '}';
            },
            $path
        );

        return [
            'path'       => is_string($clean) ? $clean : $path,
            'parameters' => $parameters,
        ];
    }

    /**
     * Derive a deterministic operationId from the HTTP method and clean path.
     *
     * Mirrors SchemaGenerator::operationId() for consistency.
     */
    private function operationId(string $method, string $specPath): string
    {
        $slug = strtolower(trim((string) preg_replace('/[^a-zA-Z0-9]+/', '_', $specPath), '_'));
        return strtolower($method) . '_' . $slug;
    }

    /**
     * Generate a human-readable summary when none is declared.
     *
     * Mirrors SchemaGenerator::generateSummary().
     */
    private function generateSummary(string $method, string $path): string
    {
        $action = match (strtoupper($method)) {
            'GET'    => 'Get',
            'POST'   => 'Create',
            'PUT', 'PATCH' => 'Update',
            'DELETE' => 'Delete',
            default  => strtoupper($method),
        };
        $resource = ucfirst(trim($path, '/'));
        return "{$action} {$resource}";
    }
}
