# ADR 0007: MCP JSON-RPC Dependency — Hand-rolled dispatcher vs PHP SDK

- **Status:** Accepted
- **Date:** 2026-06-24
- **Task / Issue:** Phase C — MCP Server Support (WC-1b68ebce)
- **Deciders:** Amro Saleh

## Context

The MCP protocol runs over JSON-RPC 2.0. Before any implementation lands we must decide
whether to adopt a Composer package (a PHP MCP SDK or a generic JSON-RPC library) or
implement the dispatcher by hand.

### Forces

- **FrankenPHP persistent workers** — no global or static state; any SDK that registers global
  handlers, stores state in statics, or uses `register_shutdown_function` is unsafe.
- **PHP 8.4 strict types** — the entire codebase enforces `declare(strict_types=1)` and
  PHPStan level 8; a dependency that is not PHP 8.4 clean or type-safe fails CI.
- **Composer licence policy** — new packages require a licensing review (no GPL-incompatible
  licences). GPL-licensed SDKs are excluded.
- **JSON-RPC 2.0 core surface** — the protocol is small: parse a JSON body, route by `method`,
  handle `id` / `null` (notification), return `{jsonrpc, id, result}` or `{jsonrpc, id, error}`,
  support batch arrays. Spec is ~15 pages.
- **MCP lifecycle methods** — `initialize`, `ping`, `notifications/cancelled`,
  `tools/list`, `tools/call`, `resources/list`, `resources/read`, `prompts/list`,
  `prompts/get`. Approximately 9 method handlers for Phase C.
- **No official Anthropic PHP MCP SDK** — Anthropic publishes MCP SDKs for TypeScript and
  Python only.

### Packages evaluated

| Package | PHP 8.4 | Worker-safe | Licence | Notes |
|---|---|---|---|---|
| `logiscape/mcp-sdk-php` | Partial | Unknown — uses static session registry | MIT | Community; not production-hardened; global state concerns |
| `datto/json-rpc` | Yes | Yes | LGPL-2.1 | Generic JSON-RPC 2.0 server/client; actively maintained; no MCP awareness |
| `tochka-bank/jsonrpc` | Yes | Unknown | MIT | Laravel-coupled; not usable in plain PHP |
| Hand-rolled | Yes | Yes | N/A | Full control; ~200 lines |

---

## Decision

We will **hand-roll the JSON-RPC 2.0 dispatcher** in `src/Mcp/JsonRpc/`. No new Composer
package is added for the JSON-RPC or MCP layer. The existing dependencies
(`firebase/php-jwt`, `defuse/php-encryption`, `psr/log`) are sufficient.

The dispatcher lives in `src/Mcp/JsonRpc/Dispatcher.php` and implements:

```php
final class Dispatcher
{
    /** @param array<string, MethodHandler> $handlers */
    public function __construct(array $handlers) {}

    public function dispatch(string $rawBody): string  // returns JSON-RPC response string
}
```

- Parses the raw JSON body (single object or array for batch).
- Routes by `method` to a registered `MethodHandler` callable.
- Wraps handler results in `{jsonrpc: "2.0", id, result}`.
- Catches `\Throwable` and maps to `{jsonrpc: "2.0", id, error: {code, message}}`.
- Notifications (`id: null`) invoke the handler but return no response body.
- Batch: all items in the array are dispatched and non-notification responses collected;
  result is a JSON array (or empty if all were notifications).

Error codes are defined as constants in `src/Mcp/JsonRpc/ErrorCode.php`:

```php
final class ErrorCode
{
    const PARSE_ERROR      = -32700;
    const INVALID_REQUEST  = -32600;
    const METHOD_NOT_FOUND = -32601;
    const INVALID_PARAMS   = -32602;
    const INTERNAL_ERROR   = -32603;
    // MCP-layer extensions:
    const UNAUTHENTICATED  = -32001;
    const FORBIDDEN        = -32003;
    const RATE_LIMITED     = -32000;
}
```

The MCP lifecycle and tool handlers (`initialize`, `tools/list`, `tools/call`, etc.) are
separate `MethodHandler` implementations registered into the `Dispatcher` at boot — not baked
into the dispatcher itself.

**composer.json change: none.** No new package is added.

---

## Alternatives Considered

- **`datto/json-rpc`** — technically the best of the evaluated packages (PHP 8.4, worker-safe,
  LGPL-2.1). Rejected because: (a) it adds a production dependency for ~200 lines of logic
  we can own and test ourselves; (b) LGPL-2.1, while not viral, introduces licence-review
  overhead and a packaging constraint on any commercial derivative; (c) it is a generic
  JSON-RPC client/server — we still have to implement all MCP method routing on top of it,
  so the abstraction saves almost nothing.

- **`logiscape/mcp-sdk-php`** — provides MCP-aware method stubs. Rejected because: (a) it
  uses a static session registry that is unsafe in FrankenPHP's persistent-worker model;
  (b) not PHP 8.4 PHPStan-clean; (c) community-maintained with no stability guarantees.

- **Generate the dispatcher from the MCP TypeScript SDK schema** — copy the TypeScript spec
  types into PHP. Rejected: adds a cross-language build step with no real benefit over reading
  the spec directly.

---

## Consequences

### Positive

- Zero new production Composer packages; no licence review burden; no supply-chain risk.
- The dispatcher is ~200 lines, fully tested, and follows the same patterns as the rest of
  the codebase (no framework coupling, strict types, PHPStan level 8 clean).
- `MethodHandler` is a simple interface; adding a new MCP method is registering one more
  handler at boot — no magic.
- Worker safety is guaranteed by design: `Dispatcher` is constructed per-worker-boot with
  stateless handler instances; all per-request state is on the call stack.

### Negative / Trade-offs

- We own the JSON-RPC 2.0 surface: if the spec changes (unlikely — it has been stable since
  2013) or we find a compliance gap, we fix it ourselves.
- Future contributors unfamiliar with the codebase may reach for a JSON-RPC package; the ADR
  and code comments must make the rationale clear.

### Impact on existing conventions

- New namespace `src/Mcp/JsonRpc/` under PSR-4 autoload; no existing files change.
- `composer.json` is **not modified** by this task.
- PHPStan baseline may need updating if any new classes surface false positives from
  `array<string, MethodHandler>` generics — unlikely given the simple structure.

---

## References

- [ADR-0006: MCP Server Architecture](0006-mcp-server-architecture.md)
- [JSON-RPC 2.0 specification](https://www.jsonrpc.org/specification)
- [MCP specification 2025-03-26](https://spec.modelcontextprotocol.io/)
- `logiscape/mcp-sdk-php` — evaluated and rejected (see above)
- `datto/json-rpc` — evaluated and rejected (see above)
