# @amroksaleh/api-client

The typed client for the **Whity** API — build your own frontend or integration
against the same OpenAPI contract the reference web app uses.

Types are generated from the backend's OpenAPI spec (`public/openapi.json`), so
request/response shapes stay in lock-step with the server.

## Install

```bash
npm install @amroksaleh/api-client openapi-fetch
```

(`openapi-fetch` is a peer at runtime; `@amroksaleh/api-client` ships TypeScript
source, so your bundler compiles it.)

## Use

```ts
import { createApiClient } from '@amroksaleh/api-client';

// Standalone frontend: point at the backend origin.
const api = createApiClient({ baseUrl: 'https://your-whity-host' });

const { data, error, response } = await api.GET('/api/v1/settings/global');
if (error) {
  // typed error body
} else {
  // typed data
}
```

Responses are never thrown — branch on `{ data, error, response }`.

### Auth

Every request sends `credentials: 'include'` (httpOnly cookies) plus the
`X-Requested-With: XMLHttpRequest` CSRF header. On a `401` the client POSTs
`/api/v1/auth/refresh` once and replays the original request once; a second `401`
is returned as-is, so refresh loops are impossible.

If you serve the frontend behind a reverse proxy that forwards cookies to the
backend, leave `baseUrl` empty (the default) so `/api/*` stays same-origin.

## Types

```ts
import type { paths, components, operations } from '@amroksaleh/api-client';
```

## Regenerating the schema

From this package (after the backend regenerates `public/openapi.json`):

```bash
npm run generate
```
