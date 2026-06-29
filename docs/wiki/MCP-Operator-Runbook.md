# MCP Operator Runbook

Operational guide for administrators managing the MCP endpoint on a running Whity Core instance.

Related: [MCP-Server](MCP-Server.md) · [MCP-Client-Connection](MCP-Client-Connection.md) · [AUDIT_TRAIL](AUDIT_TRAIL.md) · [PERMISSION_SYSTEM](PERMISSION_SYSTEM.md).

---

## Enabling MCP per tenant

MCP access is controlled by a per-tenant opt-in check. The `Dispatcher` receives a `$tenantMcpEnabled` closure at construction time; when this closure returns `false` for a tenant ID, every request from that tenant receives HTTP 403.

The closure is wired in `public/index.php`. Enabling or disabling MCP for a tenant requires updating the underlying data source that the closure queries (a tenant settings table, an environment variable, or a feature flag store, depending on your deployment). Consult your deployment configuration.

When a tenant is not opted in, the HTTP response is a bare 403 with no body. The JSON-RPC response is never reached, and nothing is written to the audit log.

---

## Managing AI principals

An AI principal is a user-scoped MCP token. Tokens are managed via the REST sub-API (requires a valid human-user access token):

### Issue a token

```bash
curl -s -X POST https://your-whity-host/api/mcp/tokens \
  -H "Authorization: Bearer <admin-access-token>" \
  -H "Content-Type: application/json" \
  -d '{"name": "automation-agent", "scope": ["tools:call"]}'
```

The response body contains the raw `token` string. Store it securely — it is only returned once.

### List active tokens for a user

```bash
curl -s https://your-whity-host/api/mcp/tokens \
  -H "Authorization: Bearer <access-token>"
```

Returns all non-expired, non-revoked tokens for the authenticated user. The raw token value is not included in the listing — only the `jti`, `name`, `scope`, `principal_kind`, `expires_at`, and `created_at`.

### Revoke a token

```bash
curl -s -X DELETE https://your-whity-host/api/mcp/tokens/<jti> \
  -H "Authorization: Bearer <access-token>"
```

Returns 204 on success. Returns 404 when the JTI does not exist or belongs to a different user/tenant. Revocation is immediate: the JTI is inserted into the shared `revoked_tokens` table, which is checked on every subsequent token validation.

Tokens expire automatically after 90 days (`McpTokenService::TOKEN_LIFETIME_SECONDS = 7_776_000`). Expired tokens fail validation even if not explicitly revoked.

---

## Rate limit configuration

`McpRateLimiter` defaults are set at construction time in `public/index.php`:

| Limit | Default | Description |
| --- | --- | --- |
| `$tenantLimit` | 300 calls / 60 s | Total AI calls from all principals in a tenant per window |
| `$principalLimit` | 60 calls / 60 s | Calls from one principal per window |

To change these values, adjust the arguments passed to the `McpRateLimiter` constructor in `public/index.php`. Limits are per-worker; in a multi-worker deployment each worker maintains its own counters via the shared store (`SharedStoreInterface`).

When a limit is hit, the client receives HTTP 429 with a `Retry-After: 60` header. The counter decays naturally when the 60-second window expires; no manual reset is needed.

---

## Monitoring

### Audit log entries

Every `tools/call` invocation is recorded in the `audit_log` table under the action `mcp.tools.call`, regardless of outcome (success, RBAC denial, or handler error). The entry is written in a `finally` block so it is never omitted.

Query recent MCP activity for a tenant (requires `audit:read` permission):

```bash
curl -s "https://your-whity-host/api/audit-log?action=mcp.tools.call" \
  -H "Authorization: Bearer <admin-access-token>"
```

Example audit log entry:

```json
{
  "id":            1234,
  "tenant_id":     42,
  "actor_user_id": 7,
  "action":        "mcp.tools.call",
  "target_type":   "tool",
  "target_id":     null,
  "metadata": {
    "tool": "post_api_users",
    "args": { "email": "alice@example.com" }
  },
  "ip_address":  null,
  "created_at":  "2026-06-29T14:00:00"
}
```

Notes:

- `actor_user_id` is the `userId` from the MCP principal — the user who owns the token, not a human operator.
- `ip_address` is `null` for MCP calls (the call is machine-originated; there is no client IP in the MCP request).
- `metadata.args` is redacted: keys containing `password`, `secret`, `token`, `code`, `hash`, `backup_code`, or `two_factor_secret` are stripped before the entry is written.

### What to watch

| Condition | Signal | Action |
| --- | --- | --- |
| High `mcp.tools.call` volume from a single `actor_user_id` | Possible runaway agent | Review the token, consider revocation |
| Repeated `FORBIDDEN` errors from the same `actor_user_id` | Agent attempting over-privileged calls | Review token scope and role assignment |
| Sustained 429 responses | Rate limit too low or excessive agent traffic | Raise limits or revoke token |
| `mcp.tools.call` from an unexpected `tenant_id` | Token leak or misconfiguration | Immediately revoke the affected token |

---

## Troubleshooting

### HTTP 401 / `UNAUTHENTICATED` (-32001)

The bearer token is absent or invalid.

**Checklist:**
1. Confirm the `Authorization: Bearer <token>` header is being sent.
2. Confirm the token has not expired (90-day lifetime; check `expires_at` from the issue response).
3. Confirm the token has not been revoked (`GET /api/mcp/tokens` lists active tokens).
4. Confirm the token was issued with `aud: mcp` — human access tokens do not work on `/mcp`.

### HTTP 429 (rate limited)

The tenant or principal call budget for the current 60-second window is exhausted.

**Resolution:** Wait for the window to expire (indicated by the `Retry-After` response header), then retry. If the limit is consistently hit, consider raising the `$tenantLimit` or `$principalLimit` in `public/index.php`, or reducing the agent's call frequency.

### HTTP 403 (MCP disabled)

The requesting tenant has not opted in to MCP.

**Resolution:** Enable MCP for the tenant via your deployment's feature flag or configuration mechanism (see "Enabling MCP per tenant" above).

### HTTP 415 (Unsupported Media Type)

`Content-Type: application/json` was not sent with the request.

**Resolution:** Add `Content-Type: application/json` to every `POST /mcp` request.

### `METHOD_NOT_FOUND` (-32601) on `tools/call`

The named tool was not found in the derived tool list.

**Possible causes:**

1. **Typo in the tool name.** Call `tools/list` to enumerate available tools and confirm the exact name.
2. **Plugin route not yet reflected.** If a plugin was loaded after the worker booted, `ToolDeriver::clearCache()` must have been called to invalidate the worker-boot cache. The tool will appear in `tools/list` on the next request after the cache is cleared.
3. **Route has no schema.** Routes without a `schema` array are excluded from tool derivation. Add a `schema` to the route declaration.

### `FORBIDDEN` (-32003)

The caller's MCP principal does not hold the required role or permission for the tool.

**Resolution:**
1. Call `tools/list` — protected tools the caller cannot use are filtered from the list entirely. If the tool is absent, the principal lacks access.
2. Check the route declaration's `requiredRole` / `requiredPermission`.
3. Assign the required role or permission to the user account that owns the MCP token (via the standard RBAC API).

### Tool call returns `isError: true`

The underlying HTTP route handler returned a 4xx or 5xx response. The tool was successfully routed and authorized; the error originates in the handler.

**Resolution:** Inspect `content[0].text` in the response — it contains the handler's raw response body. For 500s the text will be `"Internal error"` (the real exception is not exposed); check the application error log for the underlying cause.
