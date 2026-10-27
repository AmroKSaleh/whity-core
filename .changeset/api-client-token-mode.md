---
"@amroksaleh/api-client": minor
---

`createApiClient` gains a token mode, so a frontend on another site (or a
native app, or a server) can use the typed client. Until now it could only
authenticate with cookies, and those are host-only and SameSite=Lax: a
frontend at `portal.example.com` talking to `api.example.net` never got them
back.

`createApiClient({ baseUrl, auth: 'token', tokenStore })` sends
`X-Auth-Mode: token` and `Authorization: Bearer …` instead of cookies. The
tokens login, 2FA, tenant selection, tenant switching and refresh return are
captured into the store; logout also revokes the refresh token and clears it.
`tokenStore` is pluggable (`get` / `set`), defaulting to
`createMemoryTokenStore()`. Cookie mode stays the default and is unchanged for
callers.

Both modes now share one refresh between requests that hit a 401 together,
instead of each firing its own. With a rotating refresh token, parallel
refreshes sign the user out: the second one presents a token the first just
rotated away. A 401 from the sign-in endpoints themselves (a wrong password)
no longer triggers a refresh either.

The generated types now describe token mode as well: the token pair on the
session responses, `temp_token` on the 2FA challenge and request, and
`selection_token` on the tenant-selection prompt and request. Before this, the
spec only described the cookie flow, so a typed token-mode caller could not
reach any of them.
