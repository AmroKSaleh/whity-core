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

Two modes. Pick the one that matches where your frontend runs.

**Cookie mode** (default): the same flow as the Whity web app. Every request sends
`credentials: 'include'` (httpOnly cookies) plus the `X-Requested-With:
XMLHttpRequest` CSRF header. The cookies are host-only and SameSite=Lax, so this
only works when the frontend and the API share a site: same origin, a reverse
proxy that forwards `/api/*` (leave `baseUrl` empty, the default), or sibling
subdomains such as `app.example.com` and `api.example.com`.

**Token mode**: for a frontend on another site, a native app or a server. Every
request sends `X-Auth-Mode: token` and `Authorization: Bearer <access token>`,
never cookies.

```ts
import { createApiClient, createMemoryTokenStore } from '@amroksaleh/api-client';

const api = createApiClient({
  baseUrl: 'https://api.example.net', // the backend's origin
  auth: 'token',
  tokenStore: createMemoryTokenStore(), // the default; see "Token storage"
});

const { data, error } = await api.POST('/api/v1/login', {
  body: { email: 'jane@example.com', password },
});

if (data && 'requires_2fa' in data) {
  // 202: finish with the code the user types
  await api.POST('/api/v1/login/2fa', { body: { temp_token: data.temp_token, code } });
} else if (data && 'requires_tenant_selection' in data) {
  // the user belongs to several tenants: let them pick one
  await api.POST('/api/v1/auth/select-tenant', {
    body: { selection_token: data.selection_token, tenant_id: chosen.tenant_id },
  });
}

const me = await api.GET('/api/v1/me'); // carries the bearer automatically
await api.POST('/api/v1/auth/logout'); // revokes both tokens, clears the store
```

The client captures the token pair from login, 2FA, `select-tenant`,
`switch-tenant`, `refresh` and `PATCH /api/v1/me` responses into the store (a
changed email or password re-issues the pair), and clears it on logout.
You never handle the tokens yourself. A request that sets its own
`Authorization` header is sent as-is.

For a browser frontend on another origin, the backend must list that origin in
`CORS_ALLOWED_ORIGINS` (for example `CORS_ALLOWED_ORIGINS=https://portal.example.com`).
Native apps and servers send no `Origin` and need nothing extra.

#### Token storage

`tokenStore` is any object with `get()` and `set(tokens | null)`, either of which
may be async. The default keeps tokens in memory, so the session ends with the
page or process. The refresh token lasts days, so store it like a password: the
platform keychain on mobile, a server-side session for a web frontend. Never
`localStorage`, where any script on the page can read it.

```ts
const tokenStore: TokenStore = {
  get: () => SecureStore.getItemAsync('whity').then((v) => (v ? JSON.parse(v) : null)),
  set: (t) => (t ? SecureStore.setItemAsync('whity', JSON.stringify(t)) : SecureStore.deleteItemAsync('whity')),
};
```

#### Refresh

Access tokens last 15 minutes. On a `401` the client calls `/api/v1/auth/refresh`
once and replays the original request once; a second `401` is returned as-is, so
refresh loops are impossible. Requests that hit a `401` together share a single
refresh, because the refresh token rotates on every use and a second, parallel
refresh would present one the first just revoked. In token mode, a refresh the
server refuses clears the store (the session is over); a network failure leaves
it alone. A `401` from the sign-in endpoints themselves (a wrong password) is
never treated as an expired session.

## Types

```ts
import type { paths, components, operations } from '@amroksaleh/api-client';
```

## Tests

```bash
npm test          # node:test, no extra tooling
npm run typecheck # src, then src + tests
```

## Regenerating the schema

From this package (after the backend regenerates `public/openapi.json`):

```bash
npm run generate
```
