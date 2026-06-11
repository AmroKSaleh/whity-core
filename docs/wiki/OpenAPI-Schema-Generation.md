# OpenAPI Schema Generation

## Overview

Whity Core automatically generates OpenAPI 3.0 schemas from discovered plugins. This enables type-safe TypeScript client generation and API documentation.

## What is OpenAPI?

OpenAPI (formerly Swagger) is a specification for describing HTTP APIs. It enables:
- Automated client code generation
- Interactive API documentation
- API testing tools integration
- Schema validation

Learn more: https://spec.openapis.org/oas/v3.0.3

## Generating the Schema

### Command

```bash
php public/index.php generate:openapi
```

This generates `public/openapi.json` containing the complete API specification.

### Output

The generated `openapi.json` includes:
- **Paths:** All registered endpoints (read from the Router, so core and plugin routes alike)
- **Methods:** HTTP method for each endpoint (GET, POST, PATCH, DELETE, etc.)
- **Security:** Bearer token authentication configuration
- **Typed bodies (WC-166):** routes that declare a `schema` get a `requestBody` and per-status `responses` referencing named `components.schemas` via `$ref`
- **Responses:** declared per-status, or the standard defaults (200, 401, 403) for undeclared routes
- **Tags:** declared, or derived from the path

Generation is **deterministic** (paths, methods, and component schemas are
sorted — regenerating over the same routes is byte-identical) and
**self-validating**: the command refuses to write a spec with dangling `$ref`s
or response-less operations (exit 1 with the errors listed).

## Declaring typed request/response bodies (WC-166)

Any route — core (`Router::register(..., schema:)`) or plugin (the optional
`'schema'` key in the route array, SDK ≥ 1.1.1) — can declare its contract:

```php
'schema' => [
    'summary' => 'Create a widget',
    'tags' => ['widgets'],
    'request' => 'WidgetCreate',          // component name => $ref, or inline JSON-Schema array
    'responses' => [
        201 => 'Widget',                  // component name => $ref'd application/json body
        400 => ['description' => 'Validation failed'],   // raw response object
    ],
    'components' => [                     // schemas this route contributes to components.schemas
        'WidgetCreate' => ['type' => 'object', 'required' => ['name'], 'properties' => ['name' => ['type' => 'string']]],
        'Widget' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer'], 'name' => ['type' => 'string']]],
    ],
]
```

Identical component contributions from multiple routes are idempotent; a
CONFLICTING redefinition keeps the first definition and logs a warning. The
shipped `plugins/HelloWorld` declares its `/api/hello` response (`Greeting`)
as a working reference.

## Integration with Plugins

When you create a new plugin, the schema generator automatically includes it:

1. Plugin implements `PluginInterface`
2. Run `php public/index.php generate:openapi`
3. New endpoint appears in `public/openapi.json`

### Example Plugin

```php
<?php
namespace Whity\Plugins;
use Whity\Core\PluginInterface;
use Whity\Core\Request;
use Whity\Core\Response;

class UserList implements PluginInterface
{
    public function getRoute(): string { return '/api/users'; }
    public function getMethod(): string { return 'GET'; }
    public function getRequiredRole(): ?string { return null; }
    public function handle(Request $request): Response
    {
        // ... implementation
    }
}
```

## Schema Features

### Route Detection

Routes extracted from `PluginInterface::getRoute()` support:
- Simple paths: `/api/users`
- Parameterized paths: `/api/users/{id}`

### HTTP Methods

All standard HTTP methods supported:
- **GET** — Retrieve resource
- **POST** — Create resource
- **PATCH** — Update resource
- **DELETE** — Delete resource
- **PUT** — Replace resource

### Authentication

Endpoints with `getRequiredRole()` returning non-null are marked as requiring Bearer token authentication.

Endpoints with `getRequiredRole() === null` are public (no auth required).

### Response Codes

All endpoints include standard responses:
- **200** — Successful response
- **401** — Unauthorized (auth required but missing/invalid)
- **403** — Forbidden (authenticated but insufficient permissions)

## Development

The schema generator is in `src/OpenAPI/`:
- `SchemaGenerator.php` — Main generator class
- `SchemaBuilder.php` — OpenAPI spec builder helper

Tests are in `tests/OpenAPI/` and `tests/Console/`.
