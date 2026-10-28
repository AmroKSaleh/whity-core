---
"@amroksaleh/features": minor
---

New `@amroksaleh/features/mcp`: how each AI app connects to a Whity MCP server.
`MCP_CLIENTS` lists Claude Code, Cursor, VS Code, Windsurf, Codex CLI, Gemini
CLI, n8n and a generic "other", plus Claude and ChatGPT marked
`requiresSignIn` until OAuth sign-in lands. Each entry's `setup({ name, url,
token })` returns what that app reads: an install link (Cursor, VS Code), a
command (`claude mcp add`, `gemini mcp add`), a config file with its paths
(JSON or TOML), or the form fields to fill in (n8n). It holds no user-facing
prose, so every client can wrap the same setup in its own translated steps.
Install links not confirmed by the vendor for a remote server carry
`verified: false`; show the copy-paste setup beside them.
