# MCP Client Connection Guide

How to connect an AI client to the Whity Core MCP endpoint.

Related: [MCP-Server](MCP-Server.md) · [MCP-Operator-Runbook](MCP-Operator-Runbook.md).

---

## Connect an AI app (recommended)

Most people should never need the rest of this page. In the admin, open **AI principals** and choose **Connect an AI app**. You need `mcp:tokens:manage`, as for issuing a token any other way.

1. **Which app?** Pick yours from the gallery: Claude Code, Cursor, VS Code, Windsurf, Codex CLI, Gemini CLI, n8n, or *Another app* for anything that speaks MCP over HTTP. Claude and ChatGPT appear as *Coming with sign-in*: they connect only through OAuth, which this server does not offer yet.
2. **What may it do?** Choose one:
   - *Everything I can do* issues the three capabilities.
   - *Read only* adds every `:read` permission.
   - *Choose areas* gives *No access*, *View* or *View and change* per resource. *View and change* issues `resource:*`.

   Under the choice is the list of actions an app with that scope would get. It is computed by the same filter `tools/list` applies, so it is exact for you. *Choose areas* with nothing selected is refused: a scope with no permission entry is not narrowed at all, so it would quietly mean everything.
3. **Connect.** You get the setup for that exact app, easiest first:
   - a one-click install link where the app has one;
   - the command to paste;
   - the settings file and where it lives.

   The token appears once, inside this setup. It is never shown again.
4. **Test.** The page waits until the app first reaches the server and then says **Connected**. That happens on the first call that passes authentication, the MCP switches and the rate limit. If nothing happens after a minute, it suggests what to check.

Before any of this, the wizard checks `GET /api/mcp/connect`. If MCP is off for the server (`MCP_ENABLED`) or for your organization (**Settings → Integrations**), it says which in plain words and won't issue a token that can't work yet.

To **renew** one of your own connections before it expires, choose **Renew** on its row. Pick the app again to get the new setup; the access stays the same. The old token keeps working until the new one first connects, and is revoked then. Renew is offered only on your own connections, because a new token is always issued to the person asking. Renewing a colleague's connection would silently move their app onto your account.

The connections table shows **Last used** for every token. A call refreshes it at most once a minute.

### Where the app connects

The wizard hands out `APP_URL` + `/mcp`.
- **Headless deployments:** `APP_URL` is the backend itself.
- **Deployments where the bundled admin serves the public origin:** the web tier forwards `/mcp` to the backend. It forwards the bearer and the body, never the browser's cookies.

If `APP_URL` is unset, the wizard warns and uses the address of the page instead.

### Tool search for large catalogues

The endpoint `https://your-whity-host/mcp` gives the app a few everyday tools plus `search_operations` and `call_operation`, which reach everything else on demand, so it stays small however many plugins are installed. Add `?tools=full` for every operation as its own tool (the list before WHIT-750), or `?tools=discover` for only the two search tools. See MCP-Server, *Search and call*.

### Per-app setup, and what is not yet confirmed

The setups come from `@whity-org/features/mcp`, so the desktop shell or your own frontend can offer the same ones. They were checked against each vendor's documentation on 2026-10-28.

| App | Setup | Notes |
|---|---|---|
| Claude Code | `claude mcp add --transport http <name> <url> --header "Authorization: Bearer <token>"`, or `.mcp.json` | |
| Cursor | Install link `cursor://anysphere.cursor-deeplink/mcp/install?name=…&config=<base64>`, or `~/.cursor/mcp.json` | The link is **unconfirmed** for a remote server with headers: Cursor documents it only for local servers. The file always works. |
| VS Code | Install link `vscode:mcp/install?<json>`, or `.vscode/mcp.json` (top-level key `servers`) | The link is **unconfirmed** for `type: http` with `headers`. |
| Windsurf | `mcp_config.json` with `serverUrl` | |
| Codex CLI | `~/.codex/config.toml`, `[mcp_servers.<name>]` with `http_headers` | `codex mcp add` documents only OAuth flags. |
| Gemini CLI | `gemini mcp add --transport http --header … <name> <url>`, or `~/.gemini/settings.json` with `httpUrl` | `url` there means SSE, which is the wrong transport. |
| n8n | MCP Client Tool: endpoint, *HTTP Streamable*, *Bearer Auth*, the token | The transport field is **unconfirmed** from n8n's own docs (they still describe SSE only). |
| Claude, ChatGPT | — | Sign-in (OAuth) only. Claude's static request headers are a limited beta; ChatGPT documents no static bearer. Tracked by WHIT-698. |

Where a link is unconfirmed, the wizard still offers it, but always shows the copy-paste setup underneath it.

### The API behind the wizard

Both endpoints require `mcp:tokens:manage`. Neither is in the OpenAPI catalogue, so neither is an MCP tool: an agent should not walk itself through credential setup.

```bash
# Where to connect, whether it can work yet, and the preset scopes.
curl -s https://your-whity-host/api/mcp/connect -H "Authorization: Bearer <your-access-token>"
# → {"endpoint": "https://your-whity-host/mcp", "serverEnabled": true, "tenantEnabled": true,
#    "presets": {"everything": [...], "readOnly": [...]}, "areas": [{"area": "users", "permissions": [...]}]}

# The tools a token with this scope would offer you. Refused with 422 exactly where issuing would be.
curl -s -X POST https://your-whity-host/api/mcp/connect/preview \
  -H "Authorization: Bearer <your-access-token>" -H "Content-Type: application/json" \
  -d '{"scope": ["tools:call", "users:read"]}'
# → {"tools": [{"name": "get_api_v1_users", "description": "List users"}, ...]}
```

`GET /api/mcp/tokens` returns `last_used_at` for each token: null until its first call.

---

## Endpoint

```
POST /mcp
```

The endpoint accepts JSON-RPC 2.0 messages: single request objects, notifications (no `id`), and batch arrays. Every call requires:

```
Content-Type: application/json
Authorization: Bearer <mcp-token>
```

Send `Accept: application/json, text/event-stream` as well. The MCP spec requires it,
and it is how the server delivers change notifications: when your cached tool, resource
or prompt list has gone stale, the POST response comes back as an event stream carrying
the `notifications/*/list_changed` frames ahead of your JSON-RPC response. Omit it and
you get plain JSON, but you will never be told your cached lists have moved.

`GET /mcp` returns **405**. This server offers no standing SSE stream — a held
connection would occupy one of eight FrankenPHP workers for its lifetime — so
notifications ride POST responses instead.

---

## Obtaining a bearer token

The wizard above does this for you. By hand: MCP tokens are long-lived (90 days) and are issued via the management API using a human-user access token (the cookie or Bearer token your browser session uses).

**Issue a token:**

```bash
curl -s -X POST https://your-whity-host/api/mcp/tokens \
  -H "Authorization: Bearer <your-access-token>" \
  -H "Content-Type: application/json" \
  -d '{"name": "my-agent", "scope": ["tools:call"]}'
```

Response (`201 Created`):

```json
{
  "jti":        "01HXYZ...",
  "token":      "eyJ...",
  "name":       "my-agent",
  "scope":      ["tools:call"],
  "expires_at": "2026-09-01T00:00:00+00:00"
}
```

Store the `token` value. It is only returned once; it cannot be retrieved after issuance but can be revoked by `jti`.

`scope` must name at least one capability: `tools:call`, `resources:read` or `prompts:read`.
You may add permission names (`users:read`, or `users:*`) to restrict the token further. Scopes
never grant more than your own role allows. See the operator runbook's
[Choosing a scope](MCP-Operator-Runbook.md#choosing-a-scope).

**List active tokens:**

```bash
curl -s https://your-whity-host/api/mcp/tokens \
  -H "Authorization: Bearer <your-access-token>"
```

**Revoke a token:**

```bash
curl -s -X DELETE https://your-whity-host/api/mcp/tokens/<jti> \
  -H "Authorization: Bearer <your-access-token>"
```

---

## Protocol

The endpoint speaks every [MCP](https://modelcontextprotocol.io/) version from **2025-03-26** to **2026-07-28** over JSON-RPC 2.0. Legacy clients negotiate with `initialize`. A 2026-07-28 client sends its version in each request's `_meta` with the matching headers, and can call `server/discover` first. See MCP-Server, *Protocol*, for what each version adds.

### Available methods

| Method | Description |
| --- | --- |
| `initialize` | Legacy versions only: negotiate protocol version and capabilities |
| `server/discover` | Supported versions, capabilities and server identity (required by 2026-07-28) |
| `ping` | Legacy versions only: check server reachability (returns an empty result) |
| `tools/list` | List tools the caller is permitted to use |
| `tools/call` | Invoke a tool by name |
| `resources/list` | List static resources and resource templates |
| `resources/read` | Read a resource by URI |
| `prompts/list` | List available prompts |
| `prompts/get` | Retrieve a prompt by name |
| `notifications/cancelled` | Notify the server that a previous request is cancelled (notification, no response) |

### Request shape

```json
{
  "jsonrpc": "2.0",
  "id":      1,
  "method":  "<method>",
  "params":  { }
}
```

Omit `id` to send a notification (no response is returned).

### Response shape (success)

```json
{
  "jsonrpc": "2.0",
  "id":      1,
  "result":  { }
}
```

### Response shape (error)

```json
{
  "jsonrpc": "2.0",
  "id":      1,
  "error": {
    "code":    -32001,
    "message": "Unauthenticated"
  }
}
```

---

## Minimal curl walkthrough

The examples below show a complete interaction from `initialize` through `tools/list` to a `tools/call`.

### 1. Initialize

```bash
curl -s -X POST https://your-whity-host/mcp \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer <mcp-token>" \
  -d '{
    "jsonrpc": "2.0",
    "id": 1,
    "method": "initialize",
    "params": {
      "protocolVersion": "2025-03-26",
      "capabilities": {},
      "clientInfo": { "name": "my-agent", "version": "1.0" }
    }
  }'
```

Response:

```json
{
  "jsonrpc": "2.0",
  "id": 1,
  "result": {
    "protocolVersion": "2025-03-26",
    "capabilities": {
      "tools":     { "listChanged": true },
      "resources": { "subscribe": false, "listChanged": true },
      "prompts":   { "listChanged": true }
    },
    "serverInfo": { "name": "whity-core", "version": "1.0" }
  }
}
```

### 2. List tools

```bash
curl -s -X POST https://your-whity-host/mcp \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer <mcp-token>" \
  -d '{"jsonrpc": "2.0", "id": 2, "method": "tools/list", "params": {}}'
```

Response (abbreviated):

```json
{
  "jsonrpc": "2.0",
  "id": 2,
  "result": {
    "tools": [
      {
        "name": "get_api_users",
        "description": "Get /api/users",
        "inputSchema": {
          "type": "object",
          "properties": {
            "page":     { "type": "integer", "description": "Page number" },
            "per_page": { "type": "integer", "description": "Items per page" }
          }
        }
      }
    ]
  }
}
```

### 3. Call a tool

```bash
curl -s -X POST https://your-whity-host/mcp \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer <mcp-token>" \
  -d '{
    "jsonrpc": "2.0",
    "id": 3,
    "method": "tools/call",
    "params": {
      "name": "get_api_users",
      "arguments": { "page": 1, "per_page": 10 }
    }
  }'
```

Response:

```json
{
  "jsonrpc": "2.0",
  "id": 3,
  "result": {
    "isError": false,
    "content": [
      {
        "type": "text",
        "text": "{\"data\": [{\"id\": 1, \"email\": \"alice@example.com\"}], ...}"
      }
    ]
  }
}
```

When the underlying HTTP handler returns a 4xx or 5xx response, `isError` is `true` and `content[0].text` contains the handler's error body.

---

## Batch requests

Multiple calls can be combined into a single HTTP request as a JSON array:

```bash
curl -s -X POST https://your-whity-host/mcp \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer <mcp-token>" \
  -d '[
    {"jsonrpc": "2.0", "id": 1, "method": "ping", "params": {}},
    {"jsonrpc": "2.0", "id": 2, "method": "tools/list", "params": {}}
  ]'
```

The response is a JSON array of the non-notification results, in the same order as the non-notification requests.

---

## Error codes

| Code | Constant | HTTP | Meaning |
| --- | --- | --- | --- |
| `-32700` | `PARSE_ERROR` | 200 | Request body is not valid JSON |
| `-32600` | `INVALID_REQUEST` | 200 | Request object is structurally invalid |
| `-32601` | `METHOD_NOT_FOUND` | 200 | Unknown method name or unknown tool |
| `-32602` | `INVALID_PARAMS` | 200 | Method-specific parameter validation failed. Bad **tool arguments** are not this: they come back as a tool result with `isError: true` so the model can fix its call |
| `-32603` | `INTERNAL_ERROR` | 200 | Unhandled server error (no details exposed) |
| `-32000` | `RATE_LIMITED` | 429 | Call budget exhausted; retry after `Retry-After` seconds |
| `-32001` | `UNAUTHENTICATED` | 401 | Bearer token absent or invalid; `WWW-Authenticate` says where to sign in when OAuth is on |
| `-32002` | `RESOURCE_NOT_FOUND` | 200 | Referenced resource URI not found |
| `-32003` | `FORBIDDEN` | 200 | Caller lacks the required role or permission. 403 when a genuine OAuth token can't be used here, with a message saying why |

Note: rate limit (`-32000`) and MCP-disabled (`403`) are the only cases that produce a non-200 HTTP status code. All other errors use HTTP 200 with a JSON-RPC error object in the body.

---

## MCP client library configuration

Use the wizard: it produces the right format for each app (see [Per-app setup](#per-app-setup-and-what-is-not-yet-confirmed)). For a client it does not list, most MCP libraries read this shape:

```json
{
  "mcpServers": {
    "whity": {
      "type": "http",
      "url": "https://your-whity-host/mcp",
      "headers": {
        "Authorization": "Bearer <mcp-token>"
      }
    }
  }
}
```
