---
"@amroksaleh/features": minor
---

Tool search helpers in `@amroksaleh/features/mcp` (WHIT-707):
- `recommendsToolSearch(client, toolCount)` says whether an app should connect through tool search, given how many tools the chosen access exposes. It uses each app's reported `toolLimit` (Cursor 40, Windsurf 100) and the general `MCP_TOOL_BUDGET` of 60.
- `mcpEndpoint(endpoint, { toolSearch })` adds `?tools=discover` to the server's MCP URL, keeping any query it already has.

In tool search the server offers three tools (`search_tools`, `describe_tool` and `call_tool`) in place of the full catalogue.
