# MCP Client Connection Guide

How to connect an AI client to the Whity Core MCP endpoint.

Related: [MCP-Server](MCP-Server.md) · [MCP-Operator-Runbook](MCP-Operator-Runbook.md).

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

`GET /mcp` is reserved for server-initiated SSE notifications; it returns 501 in the current release.

---

## Obtaining a bearer token

MCP tokens are long-lived (90 days) and are issued via the management API using a human-user access token (the cookie or Bearer token your browser session uses).

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

The endpoint implements [MCP 2025-03-26](https://modelcontextprotocol.io/) over JSON-RPC 2.0.

### Available methods

| Method | Description |
| --- | --- |
| `initialize` | Begin an MCP session; negotiate protocol version and capabilities |
| `ping` | Check server reachability (returns an empty result) |
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
      "tools":     { "listChanged": false },
      "resources": { "subscribe": false, "listChanged": false },
      "prompts":   { "listChanged": false }
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
| `-32602` | `INVALID_PARAMS` | 200 | Method-specific parameter validation failed |
| `-32603` | `INTERNAL_ERROR` | 200 | Unhandled server error (no details exposed) |
| `-32000` | `RATE_LIMITED` | 429 | Call budget exhausted; retry after `Retry-After` seconds |
| `-32001` | `UNAUTHENTICATED` | 200 | Bearer token absent or invalid |
| `-32002` | `RESOURCE_NOT_FOUND` | 200 | Referenced resource URI not found |
| `-32003` | `FORBIDDEN` | 200 | Caller lacks the required role or permission |

Note: rate limit (`-32000`) and MCP-disabled (`403`) are the only cases that produce a non-200 HTTP status code. All other errors use HTTP 200 with a JSON-RPC error object in the body.

---

## MCP client library configuration

Most MCP client libraries expect a server URL and an auth header. A typical configuration targeting Whity Core looks like:

```json
{
  "mcpServers": {
    "whity": {
      "url": "https://your-whity-host/mcp",
      "headers": {
        "Authorization": "Bearer <mcp-token>"
      }
    }
  }
}
```

Consult your specific client library's documentation for the exact configuration format.
