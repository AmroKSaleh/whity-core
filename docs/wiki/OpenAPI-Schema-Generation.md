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
- **Paths:** All registered plugin endpoints
- **Methods:** HTTP method for each endpoint (GET, POST, PATCH, DELETE, etc.)
- **Security:** Bearer token authentication configuration
- **Responses:** Standard response codes (200, 401, 403)
- **Tags:** Endpoints grouped by resource type

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
