# ADR-0015: MCP sign-in: whity-core is the resource server, the authorization server is configuration

- **Status:** Accepted (2026-10-28)
- **Deciders:** project owner
- **Tasks:** WHIT-698 (decision), WHIT-709 (implementation)
- **Related:** ADR-0006 (MCP server architecture), WHIT-667 (scope freeze), WHIT-697 (connect wizard)

## Context

The apps that non-technical users connect most easily are Claude (web, desktop and mobile custom connectors) and ChatGPT. Both expect a remote MCP server to be either open or protected by OAuth: the user pastes the server URL and then signs in. Neither takes a pasted bearer token in its interface. The static MCP tokens behind the "Connect an AI app" wizard (WHIT-697) therefore cover developer tools (Claude Code, Cursor, VS Code, Codex, Gemini CLI, n8n) but not these two.

The MCP authorization spec makes the server an OAuth 2.1 **protected resource**:
- On an unauthenticated call it answers 401 with `WWW-Authenticate` naming its Protected Resource Metadata (RFC 9728).
- The metadata names the authorization server the client signs in with.
- The server accepts that authorization server's access tokens, issued for this resource.

Someone has to be that authorization server: issuing tokens, showing the sign-in and consent screens, registering clients through Client ID Metadata Documents or dynamic client registration. WHIT-667 froze identity machinery out of core ("identity and money belong outside core").

## Decision

1. **The authorization server is Whity-Auth**, a separate, paid, hosted service rebuilt from Nuqta. whity-core stays free and open source, and durable external services that cost money to run, Whity-Auth here and Almolakhas Pay for payments, are the paid layer.
2. **whity-core is only the resource server.**
   - It serves Protected Resource Metadata at `/.well-known/oauth-protected-resource` and at the path-suffixed `/.well-known/oauth-protected-resource/mcp`, which clients try first.
   - It answers an unauthenticated `/mcp` call with `401` and `WWW-Authenticate: Bearer resource_metadata="…", scope="…"`.
   - It accepts the authorization server's access tokens (part B; see Amendment 1).
3. **The authorization server is configuration** (`MCP_OAUTH_ISSUER`), not a dependency. Unset means OAuth is off and nothing changes. A self-hoster can point it at any OAuth 2.1 server. Core never needs the paid service to work, and stays inside the WHIT-667 freeze.
4. **Static MCP tokens stay.** They're the right tool for apps that accept a pasted token, and they keep working alongside OAuth.
5. The metadata's `resource` is `APP_URL` + `/mcp`, the same address the wizard hands out. With `APP_URL` unset the resource is unknown and OAuth stays off rather than guessing. When the bundled admin serves the public origin, the web tier forwards the metadata documents just as it forwards `/mcp`.

## Consequences

- An unauthenticated `/mcp` call is now HTTP 401 for every client, OAuth or not, as each MCP authorization revision requires. It used to be 200 with a JSON-RPC error. A client relying on the old status gets a 401 with the same JSON-RPC body.
- With part B (Amendment 1), Claude and ChatGPT can complete sign-in. A person needs a Whity account linked to the authorization server through single sign-on first.

## Open (part B, as it stood before Amendment 1)

1. **Identity mapping.** An access token names a subject at the authorization server, and core has to map it to a Whity profile and tenant. Candidates:
   - the existing linked-identity table (issuer + subject), as SSO uses;
   - a profile and tenant claim that Whity-Auth mints;
   - a verified-email match.

   The tenant needs a source too: a claim, the profile's only tenant, or a per-tenant MCP URL.
2. **Signature verification.** The JWKS fetch-and-cache with RS256/ES256 can be done by an approved JWT library (the dependency policy requires architect approval, a licence review and a security audit) or in-house on `openssl_verify`.
3. **Scope.** The authorization server's consent screen should offer the wizard's plain-language presets, mapped onto the `McpScope` vocabulary.

## Amendment 1 (2026-10-28): accepting access tokens (part B)

The owner decided the open questions:

1. **Identity: the token's `(iss, sub)` through the existing linked-identity table**, in the global-trust namespace only. The issuer is operator configuration, which is what global trust means (migration 047); a tenant's own identity provider could claim any issuer, so its links never answer. Core does no linking or provisioning on this path. A person links once by signing in to Whity with the same provider, and a token for an unlinked subject is refused with a message saying so.
2. **Tenant: a claim if present, else the profile's only tenant, else refused.** The claim is `tenant_id` by default (`MCP_OAUTH_TENANT_CLAIM`). An active membership is required either way, so suspension applies at once. The system tenant (id 0) is refused outright. It needs no membership row, so an outside server naming it would otherwise grant cross-tenant authority to any linked account.
3. **Signatures: firebase/php-jwt**, already a core dependency for SSO ID tokens, so there is no new library. The JWKS comes from `MCP_OAUTH_JWKS_URI` or the issuer's RFC 8414 or OIDC metadata, cached through the SSO path's `JwksProvider`. Symmetric keys are discarded, and each key's own algorithm binds the token's, so a token cannot choose `none` or HMAC.
4. **Audience:** `aud` must include the resource (`APP_URL/mcp`), so a token issued for another service is not accepted here.
5. **Errors:** an invalid token gets 401 and the client signs in again. A genuine token that can't be used gets 403 and a plain message, because signing in again would only produce the same token.

Scope presets on the authorization server's consent screen (Open item 3) belong to Whity-Auth, not core.

## Alternatives considered

- **whity-core as its own authorization server.** This needs login, consent, token and client-registration endpoints in core. It's identity machinery the scope freeze excludes, and every self-hoster would have to run and secure it.
- **Nuqta as the authorization server.** Nuqta serves Almolakhas and its own clients under a separate brand, so it can't be the provider every whity-core deployment points at. Whity-Auth reuses its code instead.
- **A third-party hosted provider (e.g. WorkOS AuthKit) as the default.** Fastest to ship, but it makes a vendor the default dependency of a free, open-source core. It stays possible as configuration.
