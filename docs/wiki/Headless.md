# Running Whity Core headless

Whity Core is an API and an MCP server first. The Next.js admin in `web/` is one
client of that API, not a requirement. A team can ship its own frontend (a
marketing site, a mobile app, a portal on another domain) and use the same
backend underneath, with or without the bundled admin.

Related: [Staging-Deploy](Staging-Deploy.md) · [MCP-Client-Connection](MCP-Client-Connection.md) · [OpenAPI-Schema-Generation](OpenAPI-Schema-Generation.md).

---

## 1. Deploy without the bundled UI

The core image (`ghcr.io/<repo>/app`) never contains `web/`. The admin ships as
its own image and, in `docker-compose.staging-remote.yml`, as an opt-in compose
profile. Leave the profile off and no Node process runs:

```bash
# API + MCP only
docker compose -f docker-compose.staging-remote.yml --env-file .env up -d

# with the bundled admin
docker compose -f docker-compose.staging-remote.yml --env-file .env --profile web up -d
```

`COMPOSE_PROFILES=web` in the env file does the same as `--profile web`. A
deployment that ran the admin before this became opt-in must add it, or its
next `up` stops starting the UI.

## 2. Point the backend at your frontend

Two settings tell the backend where your frontend lives.

| Variable | What it does |
| --- | --- |
| `CORS_ALLOWED_ORIGINS` | Comma-separated origins allowed to call the API from a browser, e.g. `https://portal.example.com`. Exact matches only, never a wildcard. |
| `FRONTEND_URL` | Origin every user-facing link is built on: emails (verify, reset, invitation, 2FA recovery), SSO redirects, public form links, document QR codes. Falls back to `APP_URL`. |

`APP_URL` stays the backend's own address, because the SSO callback is built on
it. If your routes don't match the defaults (`/verify-email`, `/reset-password`,
`/accept-invitation`, `/account-recovery`, `/dashboard`, `/login`, `/f`,
`/verify`, `/admin/errors`), override any single link with its full URL:
`EMAIL_VERIFICATION_URL`, `PASSWORD_RESET_URL`, `TWO_FACTOR_RECOVERY_URL`,
`INVITATION_ACCEPT_URL`, `SSO_SUCCESS_URL`, `SSO_LOGIN_URL`, `PUBLIC_FORM_URL`,
`DOCUMENT_VERIFY_URL`, `ERROR_DETAILS_URL`. See `.env.example` for each one.

What your pages receive:

- **Email links** get the token appended: `/reset-password?token=…`.
- **SSO** bounces back to `SSO_LOGIN_URL` with `?sso_error=<code>` on failure,
  or `?sso=select` when the user must pick a tenant. Success lands on
  `SSO_SUCCESS_URL` with the session cookies set.
- **Public forms and QR codes** append the token as a path segment: `/f/<token>`,
  `/verify/<token>`.

## 3. Authenticate

The API is served under `/api/v1`. Pick one of two modes.

### Cookie mode (browser on an allowlisted origin)

The default. Login sets HttpOnly cookies (SameSite=Lax, `Path=/api`). Send
requests with `credentials: 'include'`, and add `X-Requested-With: XMLHttpRequest`
to every POST, PUT, PATCH and DELETE (CSRF guard). SameSite=Lax means cookies only
flow when the frontend and the API share a site (`app.example.com` and
`api.example.com` do; `example.com` and `example.net` don't). If they don't
share a site, use token mode.

### Token mode (mobile apps, server-side renderers, cross-site frontends)

Add `X-Auth-Mode: token` to the session endpoints. Tokens come back in the JSON
body, no cookies are set, and the CSRF header isn't needed.

```bash
curl -s -X POST https://api.example.com/api/v1/login \
  -H 'Content-Type: application/json' -H 'X-Auth-Mode: token' \
  -d '{"email":"jane@example.com","password":"…"}'
```

```json
{ "access_token": "eyJ…", "refresh_token": "…", "token_type": "Bearer",
  "expires_in": 900, "user": { … } }
```

Then call the API with `Authorization: Bearer <access_token>`. The access token
lasts 15 minutes; renew it before then:

```bash
curl -s -X POST https://api.example.com/api/v1/auth/refresh \
  -H 'Content-Type: application/json' -H 'X-Auth-Mode: token' \
  -d '{"refresh_token":"…"}'
```

Login can stop early with a different response. Each one needs a follow-up call:

| Response | Next call |
| --- | --- |
| `202 {"requires_2fa": true, "temp_token": …}` | `POST /api/v1/login/2fa` with `{"temp_token": …, "code": "123456"}` |
| `200 {"requires_tenant_selection": true, "memberships": […], "selection_token": …}` | `POST /api/v1/auth/select-tenant` with `{"selection_token": …, "tenant_id": 7}` |

Send `X-Auth-Mode: token` on the follow-up too. To change tenants later, call
`POST /api/v1/auth/switch-tenant`. To end the session, call
`POST /api/v1/auth/logout`. `GET /api/v1/me` returns the signed-in user.

Store refresh tokens like passwords: in the platform keychain on mobile, and
server-side for a web frontend. Never put them in `localStorage`.

## 4. Discover the API

`GET /api/openapi.json` serves the OpenAPI 3 description of every route,
including routes added by plugins. Generate a client from it, or browse it in any
OpenAPI viewer. [OpenAPI-Schema-Generation](OpenAPI-Schema-Generation.md) covers
how the schema is built.

## 5. Use MCP

The same capabilities are exposed to AI agents at `POST /mcp` (JSON-RPC over
Streamable HTTP). MCP tools are derived from the route schemas, so a headless
deployment gets them with no extra work. MCP needs `MCP_ENABLED=true` and the
per-tenant `mcp.enabled` setting. Token issuance and client setup are covered in
[MCP-Client-Connection](MCP-Client-Connection.md).

## Checklist

- [ ] `--profile web` left off (or kept, if you also want the admin)
- [ ] `CORS_ALLOWED_ORIGINS` lists every origin your frontend is served from
- [ ] `FRONTEND_URL` set, plus any `*_URL` override whose route differs
- [ ] Your frontend has pages for the email, SSO and public-form links it now receives
- [ ] Token mode for anything that isn't a same-site browser
- [ ] `MCP_ENABLED` and `mcp.enabled` set if agents should connect
