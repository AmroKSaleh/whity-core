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

This generates `public/openapi.json` — the **published** specification: core's
authored contract plus the routes the discovered plugins declare. It is not a
dump of everything the application serves; see
[The two documents](#the-two-documents-published-vs-live) below before
generating a client from it.

### Output

The generated `openapi.json` includes:
- **Paths:** the routes registered on the Router the generator is given — the
  `CoreApiSchemas` catalogue (#167) followed by the discovered plugins' routes,
  in that order, so a plugin can never shadow a core path
  (first-registration-wins, WC-169)
- **Methods:** HTTP method for each endpoint (GET, POST, PATCH, DELETE, etc.)
- **Security:** Bearer token authentication configuration
- **Typed bodies (WC-166):** routes that declare a `schema` get a `requestBody` and per-status `responses` referencing named `components.schemas` via `$ref`
- **Responses:** the declared per-status success shape (or a `200` default) PLUS the standard error surface injected into every operation (see below)
- **Tags:** declared, or derived from the path

Generation is **deterministic** (paths, methods, and component schemas are
sorted — regenerating over the same routes is byte-identical) and
**self-validating**: the command refuses to write a spec with dangling `$ref`s
or response-less operations (exit 1 with the errors listed).

## The two documents: published vs live

A deployment serves the OpenAPI document at **two** URLs, and they do not carry
the same set of routes. Pick deliberately:

| | `/openapi.json` | `/api/openapi.json` |
|---|---|---|
| Produced by | the build, from `generate:openapi`; served as a static file | regenerated from the real router on every request |
| Routes | core's **authored** catalogue + plugin routes | **every** route the instance actually serves |
| Core operations marked `x-whity-undocumented` | none, ever | the not-yet-authored ones |
| Use it for | generating typed clients, review, diffing a contract | discovery against a running instance |

At the time of writing the published document carries 40 fewer core
route-method pairs than the live one. **That gap is intended.** A core route
joins the published contract when someone authors its schema in
`CoreApiSchemas`; until then it is listed, with a reason and the task that will
document it, in `RouteCatalogueCompletenessTest::KNOWN_UNDOCUMENTED`. Adding a
route to `public/index.php` without either a declaration or an opt-out entry
fails CI, so the list cannot silently grow.

What the gap buys is the guarantee stated in the document's own
`info.description`: **every core operation in `public/openapi.json` was
authored**, so an absent `requestBody` there means "takes none" rather than
"nobody wrote it down". Publishing the undeclared routes would make the
published document route-complete at the cost of that guarantee — 40 core
operations would arrive carrying generated defaults and no contract, and a
client generator could no longer tell the two cases apart. The trade was made
in favour of the guarantee; `RequestSchemaContractTest` enforces it in both
directions.

So: **generating a typed client from `/openapi.json` is correct and
supported** — it yields exactly the endpoints core promises to keep stable. If
you need the complete surface of a specific deployment (the desktop app's
device and update endpoints, `/mcp`, `/api/v1/jobs`, …), read
`/api/openapi.json` from that instance instead, and expect operations whose
bodies are undeclared.

Three guards keep this honest, and it is worth knowing which does what:

- `RouteCatalogueCompletenessTest` — every live core route in
  `public/index.php` is either declared in `CoreApiSchemas` or opted out in
  `KNOWN_UNDOCUMENTED` with a reason.
- `OpenApiSpecDriftTest` — the committed `public/openapi.json` matches a fresh
  regeneration byte-for-byte. It regenerates from the **catalogue**, not from
  `public/index.php`, so it does not and should not see the 40.
- `RequestSchemaContractTest` — no core operation in the published document
  claims to be undocumented, and no marked operation carries a declared body.

Between the first two, the set of live core routes absent from the published
spec is pinned to `KNOWN_UNDOCUMENTED`; no separate gate is needed for it.

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
use Whity\Sdk\PluginInterface;
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

#### Standard error surface (WC-216)

The generator injects the application's uniform error envelope into **every**
operation's `responses`, so clients and MCP see the full error surface. Each
injected response references the shared `Error` component
(`#/components/schemas/Error` → `{ "error": string, "details"?: object }`,
the body produced by `Response::error()`).

Injected codes:

- **Always (transport-level):** `404` Not found, `405` Method not allowed,
  `500` Internal server error.
- **Only when the operation requires authentication** (it carries
  `security: [{bearerAuth: []}]`): `401` Unauthorized.
- **Only when the operation is role/permission gated:** `403` Forbidden.
- **Only when the operation declares a request body:** `400` Invalid request
  body.

`422`/`429` are **not** blanket-injected — they are owned by explicit route
declarations.

Injection is **merge-not-clobber**: an explicitly declared response for a
status code always wins (a route declaring a richer `403`, a `422`, etc. keeps
its own object). Public routes therefore no longer falsely advertise `401`/`403`.
Response keys are emitted in ascending status-code order for byte-stable
regeneration.

## Development

The schema generator is in `src/OpenAPI/`:
- `SchemaGenerator.php` — Main generator class
- `SchemaBuilder.php` — OpenAPI spec builder helper

Tests are in `tests/OpenAPI/` and `tests/Console/`.

## Typed Frontend Client (WC-168)

The spec is the generation input for the frontend's typed API client:

- `cd web && npm run generate:api` regenerates `web/lib/api/schema.d.ts` from
  `public/openapi.json` via **openapi-typescript**. The committed file must
  match a fresh generation — CI fails otherwise (`web` job drift check).
- `web/lib/api/client.ts` wraps the schema with **openapi-fetch** and
  preserves the platform auth behavior as middleware: `credentials: 'include'`,
  the `X-Requested-With` CSRF header (WC-160), and the 401 → silent refresh →
  single retry flow. The retry bypasses the middleware, so refresh loops are
  structurally impossible. `web/__tests__/typed-api-client.test.ts` pins this
  contract.
- Screens import the singleton: `import { api } from '@/lib/api/client'` and
  call `api.GET('/api/users')`, `api.POST('/api/delegations', { body })`,
  `api.PATCH('/api/users/{id}', { params: { path: { id } }, body })` — request
  bodies, path params, query params and responses are all typed from the spec.
- Feature `types.ts` files derive their shapes from the schema
  (`components['schemas']['Delegation']`) instead of hand-mirroring the API.

Changing an endpoint therefore means: update the handler + `CoreApiSchemas`,
run `php public/index.php generate:openapi`, then `npm run generate:api`, and
commit all three artifacts together.
