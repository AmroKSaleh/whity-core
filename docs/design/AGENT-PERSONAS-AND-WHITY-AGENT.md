# Agent personas, presets and whity-agent

Status: **draft, open questions in §9**. Nothing here is built yet. Part A's backend starts once the owner answers Q1 and Q2.
Related: `MCP-TOOL-SURFACE.md` (§5.4 token toolsets, §5.11 agent model, §5.12 splits), `00-decision-register.md` (Scope grant, 5.6a and 5.6b agent words, 7.5a to 7.5c), `15-agents.md`, `12-engineering-handoff.md` items 10 and 11, ADR-0006 (MCP server), ADR-0014 (AGPL with plugin exception), ADR-0015 (OAuth resource server, Whity ID), `WHITY-BUILDER.md` (manifests), `templates/tauri-desktop`.

The owner's words: "agents will need personalities and tools and presets from whity POV to be created... we will also need whity-agent a local desktop app that auto connects your local claude-code codex etc... to whity seemlessly to help you work and chat with whity".

## 1. Summary

**Part A, agents from Whity's side (whity-core).**
- An **agent** is a workspace's definition of an assistant: a **persona** (role name, role, instructions, tone, language, in English and Arabic) and its **tools** (toolsets, plus a grant template of Off, Ask first and Allowed per permission). Agents are stored in a table, `agents`, one row per workspace agent.
- A **preset** is a ready-made agent template: Finance clerk, HR assistant, Document drafter and others. Presets are **manifests**: core ships them as JSON files, and plugins and Whity Builder ship them in their own manifests. Creating an agent from a preset copies it into `agents`, so a workspace can edit its copy and a preset update never changes a live agent silently.
- A person **uses** an agent by granting it. That creates an **assignment**, one per agent and person, which holds the grant on the server. Every credential for that agent and person, a Whity token or an OAuth token, acts as an `ai-agent` principal for that person, under the assignment's grant. Nothing changes in the model already built: never above the person's RBAC, Ask first stores a proposal, and only the granting person approves.
- `init_session` briefs an agent with its persona (role, instructions, tone, language) and its preset. Core's rules always come first and the persona can't override them.
- An external client attaches to a persona by connecting to the agent's MCP resource, **`/mcp/agents/<slug>`**. It is its own OAuth resource, like a split. With a Whity token, the token itself names the assignment.

**Part B, whity-agent (a new desktop app).**
- A Tauri 2 tray app for Windows, macOS and Linux, in English and Arabic, built from `templates/tauri-desktop` without the PHP host, sync or printer parts. Proposed repo: `whity-org/whity-agent`, open source (Q4).
- It **finds** the AI tools on the machine (Claude Code, Codex CLI, Cursor, VS Code, Windsurf, Gemini CLI, Claude Desktop and others). It **signs in** through Whity ID (OAuth with PKCE, loopback redirect), or through a core device code for instances without Whity ID. It then **connects** each tool the person picks, as themselves or as one of their agents.
- **No secret is ever written to a tool's config file.** A tool that can sign in to remote MCP itself gets only the URL and signs in on its own. Any other tool gets a command, `whity-agent mcp`: a small local stdio bridge that reads the token from the OS keychain. Tokens live in the OS keychain, in Rust only, and never in the webview or a file.
- Every config edit is shown first as a diff, needs the person's consent per tool, is backed up, and can be undone. The app prefers each tool's own CLI (`claude mcp add`, `codex mcp add`) and falls back to a format-preserving edit.
- A **chat** with Whity in the app. By default it runs on a tool the person already has (Claude Code or Codex in headless mode) with the Whity connection attached, so no extra model key is needed (Q3).
- Signed auto-updates with `tauri-plugin-updater` and minisign. OS code signing is Q5.

## 2. What exists today (develop at bc0d8ffd)

| Piece | Where | Notes |
|---|---|---|
| `ai-agent` principal | `mcp_tokens.principal_kind` (migration 033); `McpPrincipal::KIND_AGENT`; `POST /api/mcp/tokens {agent: true}` (`McpTokenHandler`, #1477) | An agent **is** a token row. Its id is the token's `jti`, it acts for the token's `profile_id`, and it has a `name`. There is no agent entity, persona or instructions. |
| Scope grant | `AgentScopeGrant`: `grant:<permission>=off\|ask\|allow` entries in the token's `scope` (#1464) | Unnamed permissions default to Allowed for reads and Ask first for writes. `agentPolicy` and "no undo" cap the mode. Every write is Ask first today, because no operation declares undo yet (`ToolsListHandler::agentModeFor`). |
| Proposals | `agent_proposals` (migration 176), `AgentProposalRepository`, `/api/me/agent-proposals` (WHIT-784) | `agent_principal_id` is the token's `jti`. Expiry is 7 days and lazy. Approval rebuilds the principal from the stored scope and runs `ToolsCallHandler::runApproved()`. |
| Toolsets | `CoreToolsets.php`, `PluginMcpToolsetsInterface`, `toolset:` scope entries (enforced) | Splits (`/mcp/<split>`) are designed (§5.12) but not routed yet. |
| `init_session` | `DiscoveryTools::INIT`, `SessionBriefing::brief()` (WHIT-794) | Returns `you`, `canReach`, `howToWork`, `limits`, plus `askFirst`, `rules` and `notGranted` for agents. Nothing persona-shaped. |
| Per-app setups | `packages/features/src/mcp/connect-clients.ts` (WHIT-697) | Formats for Claude Code, Cursor, VS Code, Windsurf, Codex, Gemini CLI, n8n and other. **Every one puts `Authorization: Bearer <token>` in the file or command.** Fine for a copy-paste wizard, not for an app that writes files unattended (§6.4). |
| Desktop template | `templates/tauri-desktop` | Tauri 2, React 19, `keyring` 3 (native per OS), `tauri-plugin-updater`, `reqwest` with rustls, device auth (`POST /devices`, `/devices/token`), tokens kept in Rust. No deep link and no i18n runtime. |
| Persona, preset, agent template | nothing | Searched `src`, `sdk`, `packages`, `database` and `docs`. |

**Two gaps the design has to close:**
1. **The agent's identity is the credential.** A proposal's `agent_principal_id` is a `jti`. With OAuth, tokens rotate on every refresh, so the same agent would show up as many principals in the activity list and the approvals inbox. The agent needs a stable id that outlives its credentials (§4.3).
2. **The grant is stored in the token.** An OAuth token from Whity ID can't carry `grant:` entries the person set in core. The grant has to live on the server (§4.3).

## 3. Design goals

1. **One agent model.** Personas and presets sit on top of the decided model (§5.11 of the MCP design). They add a name and instructions. They never add a way around RBAC, the grant, `agentPolicy` or proposals.
2. **Presets are data.** A preset is a manifest that core, a plugin or Whity Builder ships. It is validated at load, the same way as tool descriptors.
3. **A persona can't loosen the rules.** Instructions are workspace text given to a model. Core's own briefing always comes first, and what the server enforces doesn't depend on the model obeying.
4. **No secrets in files.** whity-agent never writes a token to disk outside the OS keychain.
5. **Consent and a way back.** whity-agent changes another app's config only after the person sees the change and says yes, and it can always put the file back.
6. **Arabic and English everywhere.** Persona fields, preset titles, the briefing and the desktop app.
7. **Core stays free; services stay optional.** Nothing in core needs Whity ID, and whity-agent works with a self-hosted instance without it.

## 4. Part A: agents, personas and presets (whity-core)

### 4.1 Words

| Word | Meaning |
|---|---|
| **Agent** | A workspace's assistant definition: persona plus tools. Named by role (register 5.6b): "Finance clerk", never a human first name or "Whity AI". |
| **Persona** | The agent's name, role, instructions, tone and language, in English and Arabic. |
| **Preset** | A ready-made agent template from core, a plugin or Whity Builder. |
| **Assignment** | One person's use of one agent: the grant (Off, Ask first or Allowed per permission) and the toolsets, within that person's RBAC. The stable principal id the activity list and proposals show. |
| **Credential** | A token for an assignment: a Whity `ai-agent` token, or an OAuth token for `/mcp/agents/<slug>`. There can be many, and they rotate. |

"Billing assistant for Rana · Sent 12 payment reminders" (register 5.6a) reads as: the agent's name, the assignment's person, then the action.

### 4.2 Where definitions live: a table for agents, manifests for presets

| Option | For | Against |
|---|---|---|
| **A. Table for workspace agents, manifests for presets (recommended)** | Workspaces edit their agents in the app, every change is audited and tenant-scoped, and plugin and Builder presets need no database writes on install. | Two shapes, which share one JSON schema. |
| B. Everything in the table, presets seeded as rows | One shape. | Plugins would write rows on install. Updates and uninstalls need migrations of tenant data, and Builder's per-tenant manifests are read per request, not seeded (`WHITY-BUILDER.md`). |
| C. Everything as manifests | No new table. | Workspaces can't create or edit an agent without a plugin. That defeats "a way for a workspace to create agents". |

**`agents` table** (migration number from the orchestrator):

| Column | Type | Notes |
|---|---|---|
| `id` | BIGSERIAL | |
| `tenant_id` | INTEGER, FK tenants, cascade | Every query is tenant-scoped. |
| `slug` | VARCHAR(48) | `[a-z0-9-]`, unique per tenant. Used in `/mcp/agents/<slug>` and the config server name. |
| `name`, `name_ar` | VARCHAR(60) | Role name (register 5.6b). |
| `role`, `role_ar` | VARCHAR(200) | One sentence: what it does. |
| `instructions`, `instructions_ar` | TEXT, at most 4,000 characters | Workspace guidance for the model. Plain text, no templating. |
| `tone` | VARCHAR(16) | `plain` (Whity's voice, 07-voice.md, the default) · `formal` · `brief`. A closed set, not free text. |
| `language` | VARCHAR(8) | `auto` (follow the person) · `en` · `ar`. |
| `toolsets` | JSONB list | Toolset ids or `prefix.*`. Empty means every toolset. Compiled to `toolset:` entries on credentials. |
| `grant_template` | JSONB `{permission: off\|ask\|allow}` | The starting grant for each new assignment, clamped (§4.4). |
| `preset_key`, `preset_version` | VARCHAR, nullable | Where it came from. Lets the agents page show "Preset updated: review changes". |
| `status` | VARCHAR(16) | `active` · `archived`. Archiving stops every assignment's credentials at once. |
| `created_by_profile_id`, `updated_by_profile_id`, timestamps | | Actor columns, as for every write. |

**Who creates agents:** a new core permission, `agents:manage` (tenant admins by default), creates and edits workspace agents. A new `agents:use` (every member by default) lets a person grant an agent for themselves. Personal agents, which a person defines only for themselves, are Q1.

**Routes** (session or token, tenant-scoped, MCP-exposed as `core.agents`, `agentPolicy: refuse` on every write, so agents can't create or edit agents):
- `GET /api/agents`, `GET /api/agents/{id}`, `POST /api/agents` (from scratch or `{presetKey}`), `PATCH /api/agents/{id}`, `POST /api/agents/{id}/archive`.
- `GET /api/agent-presets`: every preset the tenant can use (entitlements applied), with `en` and `ar` fields.
- `GET /api/me/agents`, `POST /api/me/agents/{id}/assignment` (grant it: creates the assignment with the clamped template), `PATCH .../assignment` (change the grant, logged), `DELETE .../assignment` (stop using it, revokes its credentials).
- `POST /api/me/agents/{id}/credentials` issues a Whity `ai-agent` token for the assignment, shown once. It replaces the persona-less `POST /api/mcp/tokens {agent: true}` path for new credentials (§4.6).

### 4.3 Assignments: a stable principal and a server-side grant

**`agent_assignments` table:** `id`, `tenant_id`, `agent_id` (FK agents, cascade), `profile_id` (the granting person), `grant` (JSONB `{permission: mode}`), `toolsets` (JSONB, at most the agent's), `principal_id` (VARCHAR(64), `agent:<id>`, the stable id), `revoked_at`, actor columns and timestamps. Unique on (`agent_id`, `profile_id`).

- **The stable principal.** `McpPrincipal` for an agent credential gets `agentPrincipalId = 'agent:<assignmentId>'`. Proposals, the actor columns (`agent_principal_id`) and rate limits key on it, not on the `jti`. Persona-less agent tokens keep their `jti` as before, so nothing already issued changes.
- **The grant is read from the assignment** on every call, not from the token. Changing the grant applies at once to every credential, including OAuth tokens. `grant:` entries in a token stay valid for persona-less tokens only. A token naming an assignment ignores them.
- **Approval** rebuilds the principal from the assignment, as it does today from the stored scope. A revoked assignment or an archived agent means "can't approve, the agent no longer exists".
- `mcp_tokens` gains `agent_assignment_id` (nullable FK). `principal_kind` stays `ai-agent`.

### 4.4 Grant templates and clamping

A preset's or agent's `grant_template` only sets where a new assignment **starts**. It is clamped when the assignment is created:

1. Only permissions the person holds count. Others are dropped, because the agent can never exceed the person.
2. A template may set any permission to Off or Ask first.
3. **A template may set Allowed only for reads.** Writes start Ask first, as decided (MCP design §10, decision 9). Only the person can set a write to Allowed, on the grant screen. A preset can't say "send invoices without asking" on someone's behalf (Q6).
4. Permissions the template doesn't name follow the decided defaults: reads Allowed, writes Ask first.
5. `agentPolicy` and "no undo means Ask first" still cap everything at call time.

### 4.5 Presets as manifests

```json
{
  "key": "core.finance-clerk",
  "version": 1,
  "name": "Finance clerk",            "name_ar": "كاتب مالية",
  "role": "Prepares invoices, payment reminders and monthly summaries for review.",
  "role_ar": "يجهّز الفواتير وتذكيرات الدفع والملخصات الشهرية للمراجعة.",
  "instructions": "…", "instructions_ar": "…",
  "tone": "plain", "language": "auto",
  "toolsets": ["core.documents", "core.billing", "core.search", "core.inbox"],
  "grant": { "documents:read": "allow", "documents:issue": "ask", "billing:read": "allow" },
  "requiresEntitlement": null
}
```

- **Core presets** live in `resources/agent-presets/*.json`, loaded by an `AgentPresetRegistry` at boot (read-only, per worker, like `CoreToolsets`). First set: Finance clerk, HR assistant, Document drafter, Front desk, Billing assistant. The Arabic for each is reviewed by an Arabic speaker before release (§10).
- **Plugin presets**: a new optional SDK interface, `PluginAgentPresetsInterface::getAgentPresets()`, returning the same shape with a relative key (`"collections-clerk"`). The host prefixes the plugin key (`elmak.collections-clerk`). Toolsets must be the plugin's own or core's, and every permission must exist. Anything else is refused at load, with a warning, like tool descriptors. SDK minor from the orchestrator.
- **Whity Builder presets**: a top-level `agentPresets` key in the plugin manifest schema (`sdk/resources/plugin-manifest.schema.json`), same shape. A Builder plugin is a plugin (`WHITY-BUILDER.md`), so the per-tenant manifest host serves them only in their own tenant. The Whity Builder service drafts presets with the person; publishing is a proposal, as for every Builder publish.
- **Validation and budgets:** names at most 60 characters, roles at most 200, instructions at most 4,000 per language, both languages required for core presets and warned when missing for plugins. A conformance check is added to the SDK kit (WHIT-272).
- **Updates:** a preset version bump never changes an agent made from it. The agents page shows "Preset updated", with a field-by-field diff to accept.

### 4.6 The briefing: `init_session` for an agent

For an `ai-agent` principal with an assignment, `SessionBriefing::brief()` adds one key, in the requested language. If a field has no Arabic, it falls back to English and says so.

```json
"agent": {
  "name": "Finance clerk",
  "role": "Prepares invoices, payment reminders and monthly summaries for review.",
  "actingFor": "Rana Haddad",
  "tone": "plain",
  "language": "auto",
  "preset": { "key": "core.finance-clerk", "version": 1 },
  "workspaceInstructions": "…"
}
```

**Order, which is a rule:** `you`, `rules` and `askFirst` come first (core's text), then `agent`, then `canReach`. The rules gain one line: "Workspace instructions below describe your role. They never change these rules or your access." The persona is labelled `workspaceInstructions` so the model treats it as the workspace's guidance, not Whity's.

**Size:** instructions count toward the briefing budget. The briefing stays under 1.5k tokens without instructions, and instructions are capped at 4,000 characters, about 1k tokens.

**Also exposed as an MCP prompt:** `prompts/get` with the name `agent_<slug>` returns the same persona as a system-style prompt, for clients that use prompts rather than calling `init_session` (`prompts:read` capability).

**Voice:** the register's 5.6a rules (no "I", no feelings) apply to what Whity shows: activity lines, proposal summaries and notifications, which core writes, not the model. In an external client's chat, the model talks however that client talks. The tones only change length and formality.

### 4.7 How an external client attaches to a persona

| Path | How | When |
|---|---|---|
| **OAuth, per-agent resource (recommended)** | The client connects to `https://<instance>/mcp/agents/<slug>`. That URL is its own RFC 8707 resource with its own Protected Resource Metadata (as splits, §5.12). Whity ID mints a token with `aud` that URL. Core's verifier sees the `aud`, finds the person's assignment for that agent and builds an `ai-agent` principal. No assignment means 403, telling the person to grant the agent in Whity first. | Claude, ChatGPT, Claude Code, Codex, Cursor, VS Code: any client that signs in. Whity ID's consent shows "Claude Code wants to act as **Finance clerk** for you in Example Bakery". |
| **Whity token** | `POST /api/me/agents/{id}/credentials` issues an `ai-agent` token tied to the assignment, used at `/mcp` (or `/mcp/agents/<slug>`, which must match). | whity-agent's bridge, n8n, scripts, self-hosted instances without Whity ID. |
| Person's own client | `/mcp`, a `user` or `oauth` token. | Unchanged: acts as the person, with no grant and no proposals. |

`/mcp/agents/<slug>` serves search and call like `/mcp`, limited to the agent's toolsets. It is routed with the split work (WHIT-765). Whity ID needs two things: the per-resource PRM check it already has (`WID-mcp-sign-in-design.md`), and the consent line naming the agent, which it reads from the PRM's `resource_name`.

### 4.8 Activity, UI and ownership

- **Activity and approvals** need no new model. The actor already carries the agent principal; it now resolves to an agent name and a person.
- **Screens.** This design adds the **Agents** admin page (list, create from preset, edit persona in both languages, toolsets, grant template, archive) and the **My agents** page (grant, change grant, credentials, connect). The ScopeGrant component, the approvals inbox and the agent helper belong to the agent-collaboration session (local_fe0f0392); this design gives them the assignment API and doesn't rebuild them.
- **No design cards exist** for the Agents admin page, My agents, preset gallery, or the persona editor. They go to the brand team as design asks (§8). Until then, the pages are built from existing components behind a seam.

## 5. Part B: whity-agent (desktop app)

### 5.1 Repo, stack and licence

- **Repo:** new `whity-org/whity-agent` (needs the orchestrator's check-in). It is a product with its own releases, signing and update channel, so it doesn't belong in whity-core's `templates/`.
- **Stack:** Tauri 2 (Rust core, React 19, `@whity-org/ui`, `@whity-org/features`, `@whity-org/tokens` from npm). Starts from `templates/tauri-desktop`, drops the PHP host, SQLite sync, printer and plugin installer, and keeps `keyring`, `reqwest` with rustls, the updater and the Job Object handling. About 10 to 15 MB installed, against roughly 100 MB or more for Electron, and Rust is the right place for file edits and the keychain.
- **Open or paid (Q4):** recommend **open source and free**. The app is a client: it sells the paid services (Whity ID sign-in, Builder, Render) by making them one click away, and an open app is easier to trust with editing other apps' configs and holding tokens. Licence: MIT, matching the client packages it is built from (`@whity-org/ui`, `features`, `tokens` and the Tauri template are MIT), rather than core's AGPL, since nobody runs it as a network service.

### 5.2 Accounts and sign-in

- **Workspaces.** The app holds one or more connections: instance URL, workspace and person. Each has a display name ("Example Bakery on app.whity.dev").
- **Whity ID** (instances that trust it): OAuth 2.1 authorization code with PKCE S256 in the system browser, loopback redirect on `127.0.0.1:<random>` (RFC 8252, which Whity ID already allows). Client id: a CIMD URL, `https://id.whity.dev/clients/whity-agent.json` or one hosted on whity.dev, marked first-party on Whity ID's allowlist. Resource: `<instance>/mcp` or `<instance>/mcp/agents/<slug>`. Refresh tokens rotate.
- **No Whity ID** (self-hosted): core's device flow, which the template already uses (`POST /devices`, `/devices/token`), shown as a code to approve in the web app. Same storage rules.
- **Storage:** OS keychain through `keyring` (Windows Credential Manager, macOS Keychain, Linux Secret Service), service `dev.whity.agent`, one entry per connection. Access tokens stay in memory. If Linux has no Secret Service, the app says so and keeps the session in memory only: **no plaintext fallback**.
- Tokens never reach the webview: every network and keychain call is a Rust command.

### 5.3 Finding tools

A detector per tool, run on demand and at start-up, **read-only**. CLI commands and paths below are from the vendors' docs as of October 2026 and are re-checked when each detector is built (B2), as `connect-clients.ts` does:

| Tool | Found by | Connects through |
|---|---|---|
| Claude Code | `claude` on PATH (`claude --version`), `~/.claude.json` | Its CLI: `claude mcp add --scope user --transport http …`, which signs in itself |
| Codex CLI | `codex` on PATH, `~/.codex/config.toml` | `codex mcp add`, else a TOML edit; signs in itself (`codex mcp login`) |
| Gemini CLI | `gemini` on PATH, `~/.gemini/settings.json` | `gemini mcp add`, else a JSON edit |
| Cursor | app install paths, `~/.cursor/mcp.json` | JSON edit (user scope), signs in itself |
| VS Code (Copilot) | `code` on PATH, user `mcp.json` in the profile folder | `code --add-mcp '{…}'`, signs in itself |
| Windsurf | the `mcp_config.json` paths in `connect-clients.ts` | JSON edit; bridge if it can't sign in |
| Claude Desktop | `claude_desktop_config.json` in the app's data folder | Bridge (stdio) |
| Others (Zed, Continue, Cline, JetBrains AI) | later, one detector each | Bridge |

The list of tools, file locations and formats is shared with the web connect wizard: `connect-clients.ts` gains a `local` section per app (detection hints, CLI commands, whether the app signs in itself, whether it can run a stdio command). whity-agent and the web wizard then stay in step. The connect-wizard session (local_eaf0e9ab) owns that file. The change is additive and goes to them first.

### 5.4 Connecting a tool: no secrets in files

Per tool, in order of preference:

1. **The tool signs in itself.** whity-agent writes only `{type: "http", url: "<instance>/mcp"}` (or `/mcp/agents/<slug>`). The tool runs OAuth against Whity ID and stores its token the way it stores its own sign-in (where that is, per tool, is checked in B2; a tool that keeps it in a plain file is moved to the bridge). The file holds no secret.
2. **The bridge**, for tools that can't sign in to remote MCP, or for workspaces without Whity ID: whity-agent writes a stdio entry, `{command: "<path>/whity-agent", args: ["mcp", "--connection", "<id>"]}`. The bridge is the same binary in a headless mode. It reads the token from the keychain, refreshes it, and relays MCP between stdio and `<instance>/mcp`. The file holds only a connection id.
3. **Never:** `Authorization: Bearer …` in a file. The web wizard's copy-paste setups keep doing that, because a person chooses it there. whity-agent doesn't.

**As yourself or as an agent.** For each tool, the person picks "Me" (the default, with today's behaviour) or one of the agents they've granted (`/mcp/agents/<slug>`, with Ask first and proposals). The server name in the tool's config shows which: `whity-example-bakery` or `whity-example-bakery-finance-clerk`.

### 5.5 Editing configs safely

1. **Show first.** A side-by-side diff of exactly what will change, for each file, before anything is written. The person ticks each tool. Nothing is connected by default.
2. **Use the tool's CLI** when it has one for adding an MCP server. Only if not, edit the file:
   - JSON with comments (VS Code, Cursor): `jsonc` parsing that keeps comments and order, changing only `mcpServers.<ours>` or `servers.<ours>`.
   - TOML (Codex): `toml_edit`, which keeps formatting, changing only `[mcp_servers.<ours>]`.
   - Unknown or unparseable file: stop and say so. Never overwrite.
3. **Only our entries.** whity-agent touches only server names it created, recorded in its own state (name, file, the hash after writing). An entry with the same name that it didn't create is shown as a conflict for the person to resolve.
4. **Back up.** Before any write, the file is copied to the app's data folder (`backups/<tool>/<timestamp>.<ext>`, last 20 kept) with its hash.
5. **Write safely.** Write to a temp file in the same folder, fsync, then rename over the original (`ReplaceFileW` on Windows). If the file changed since it was read (mtime and hash), abort and re-read. Keep the file's permissions.
6. **Way back.** Each connection has Disconnect (remove our entry) and Restore (put back the backup, if the file hasn't changed since). "Remove Whity from all tools" is under Settings and runs on uninstall when the person agrees.
7. **Scope.** User-level configs only by default. Project files (`.mcp.json`, `.vscode/mcp.json`) are opt-in, per folder, and never get tokens either.

### 5.6 Chat with Whity

A chat window, and a ⌘K-style quick ask from the tray, for "work and chat with Whity". Which model answers is Q3. Options:

| Option | How | For | Against |
|---|---|---|---|
| **A. Use a tool the person has (recommended first)** | Run Claude Code (`claude -p --output-format stream-json --mcp-config …`) or Codex (`codex exec --json`) headless, with the Whity connection attached and the persona in the system prompt | No new key or bill; uses their existing subscription; Whity tools work at once | Only for people with one of those tools; output is shaped by that tool's agent loop |
| B. Bring your own API key | The person's Anthropic or OpenAI key in the keychain; the app runs a small tool loop over MCP | Works without a coding tool | The person pays a separate bill; we maintain a tool loop |
| C. Whity-hosted model | A paid Whity service | One sign-in, no keys; a natural paid tier | A new service, billing, data residency questions |

Recommend A first, B next, and C as a later paid service if people ask. In every option, the chat acts as the person or the chosen agent, through the same MCP server, so Ask first and proposals hold. Approvals open in the web app's inbox (the agent-collaboration session owns it). The desktop shows a count and a link, not a second inbox.

### 5.7 Updates, platforms and languages

- **Auto-update:** `tauri-plugin-updater` with minisign signatures. A static endpoint on GitHub Releases (`latest.json`), unlike the template's runtime endpoint, because whity-agent isn't tied to one instance. Checks daily and on start, downloads in the background, asks before restarting. A stable and a beta channel.
- **Builds:** Windows (MSI and NSIS, x64 and arm64), macOS (universal DMG), Linux (AppImage, deb, rpm). Built on GitHub Actions in the new repo when CI is back. OS code signing (Windows Authenticode, Apple Developer ID and notarisation) costs money: Q5. Without it, Windows SmartScreen and macOS Gatekeeper warn on install.
- **Start-up:** a tray app, start at login opt-in, single instance. A deep link, `whity-agent://connect?instance=…&agent=…`, so the web connect wizard can offer "Open in whity-agent".
- **English and Arabic:** `i18next` with English and Arabic catalogs, RTL by `dir` on the root and logical CSS, Noto Sans and Noto Sans Arabic bundled, the Whity tokens, Western digits. The language follows the OS, with a switch.

### 5.8 Security notes

- Tokens only in Rust and the keychain. The webview gets a strict CSP and no shell or fs capability: every file edit is a narrow Rust command that takes a tool id, not a path.
- The bridge listens on stdio only, never on a port, and only serves the connection its arguments name.
- Detection runs `--version` on binaries found on PATH, with a timeout. It never runs anything else from another tool's folder.
- Persona instructions are workspace text sent to models. Editing them needs `agents:manage` and is audited. Core's rules come first in every briefing (§4.6), and enforcement is on the server.
- Logs never contain tokens or config file contents, only paths and hashes.

## 6. Alternatives considered

- **Agents as tokens only (today) with a longer name field.** Rejected: no stable identity across OAuth refreshes, and no place for instructions in two languages.
- **Persona selected by a header or query (`?agent=slug`) on `/mcp`.** Rejected: OAuth tokens are bound to a resource. A per-agent resource lets Whity ID's consent say which agent, and a token for one agent can't be replayed as another.
- **Writing bearer tokens into tool configs (the wizard's format).** Rejected for the app: the files are plain text, often synced or committed. The bridge and the tools' own sign-in remove the need.
- **Electron.** Rejected: larger, and we already have a Tauri template with keychain, updater and Rust HTTP.
- **Putting whity-agent in whity-core's repo.** Rejected: different licence, release cadence and signing.
- **One agent shared by a team with one grant.** Rejected: the register says an agent acts for the person who granted it. Each person who uses an agent has their own assignment and their own approvals.

## 7. Later

- Agents that run on a schedule, set up by an owner (register E.32), using the assignment as the identity.
- A preset gallery shared between workspaces and whitiers (with the Builder gallery, after the WHIT-667 checkpoint).
- Per-agent rate limits and spending caps.
- More local tools (Zed, Continue, Cline, JetBrains AI).
- A mobile companion for approvals (Flutter), which the app map already lists.

## 8. Design asks for the brand team

No card exists for these, so they are not to be invented. Built from existing components behind a seam until they arrive:

1. **Agents admin page:** list of workspace agents, create from a preset gallery, archive.
2. **Persona editor:** name, role and instructions in English and Arabic side by side, tone, language, toolsets, grant template.
3. **My agents:** the agents a person has granted, with their grant (the ScopeGrant card exists), credentials and "Connect an app".
4. **Preset gallery card** (for core, plugin and Builder presets).
5. **whity-agent:** tray menu, first-run (sign in, found tools, choose and consent), a tool row (connected as me or as an agent, Disconnect, Restore), the config diff sheet, chat window and quick ask, settings, update prompt. Light, dark and Arabic.
6. **Whity ID consent** line for acting as an agent ("Claude Code wants to act as Finance clerk for you").

## 9. Open questions (for the owner, one at a time through the orchestrator)

1. **Who defines agents.** Recommend: workspace agents only, created by people with `agents:manage` (admins), from presets or from scratch; every member can grant one for themselves. Personal agents (only for me) come later. Also confirm this is an extension of the decided agent model and not a new subsystem under WHIT-667.
2. **Connecting as yourself or as an agent.** Recommend: whity-agent and the wizard connect as **yourself** by default (as today), and offer an agent as a choice per tool. The alternative, agent by default, makes every write in a coding tool a proposal.
3. **Chat model in whity-agent.** Recommend: A (use the person's Claude Code or Codex headless) first, B (own API key) next, C (Whity-hosted, paid) later.
4. **whity-agent repo and licence.** Recommend: new public repo `whity-org/whity-agent`, MIT (like the client packages), free. Paid value stays in the services it connects to.
5. **Code signing.** Recommend: buy Windows signing (Azure Trusted Signing is the cheapest current path) and an Apple Developer account before the first public release. Ship unsigned betas to testers until then.
6. **Grant templates.** Recommend: presets may set reads to Allowed, but never writes. Only the person sets a write to Allowed.

## 10. Task breakdown

Taskaat, project WHIT, section **Agents**. WHIT-816 also feeds the credential and grant screen of WHIT-810 (agent-collaboration work), which should use the assignment API. Migration numbers and SDK minors come from the orchestrator.

| Task | What | Depends on |
|---|---|---|
| WHIT-814 umbrella | This design, owner questions §9 | none |
| WHIT-815 (A1) agents and presets | `agents` table, `AgentPresetRegistry` with five core presets (Arabic reviewed), `agents:manage` and `agents:use`, `/api/agents`, `/api/agent-presets`, toolset `core.agents` with `refuse` on writes | Q1 |
| WHIT-816 (A2) assignments | `agent_assignments`, stable `agent:<id>` principal, server-side grant read on every call, clamping (§4.4), `mcp_tokens.agent_assignment_id`, `/api/me/agents`, credentials route; proposals and actor columns use the stable id | A1, Q6 |
| WHIT-817 (A3) briefing | `init_session` `agent` key, rule line, prompt `agent_<slug>`, size budget test | A2 |
| WHIT-818 (A4) SDK presets | `PluginAgentPresetsInterface`, manifest `agentPresets` key and schema, loader validation, conformance check | A1 |
| WHIT-819 (A5) per-agent resource | `/mcp/agents/<slug>` with PRM and verifier mapping to the assignment; Whity ID consent naming the agent (with the Whity ID session) | A2, WHIT-765 |
| WHIT-820 (A6) agents pages | Agents admin, persona editor, My agents (built on existing components until the brand designs arrive); grant UI reused from the agent-collaboration session | A2, design asks |
| WHIT-821 (A7) design asks | Hand §8 to the brand team through the owner | none |
| WHIT-822 (B0) repo | `whity-org/whity-agent` from the Tauri template, CI, licence, i18n, tokens | Q4 |
| WHIT-822 (B1) sign-in | Whity ID OAuth (PKCE, loopback, CIMD client), device flow fallback, keychain storage, connections list | B0 |
| WHIT-823 (B2) detection | Detectors (§5.3); `local` data in `connect-clients.ts` (with the connect-wizard session) | B0 |
| WHIT-823 (B3) config writer | CLI-first connect, jsonc and toml_edit writers, diff, consent, backup, atomic write, restore, disconnect | B2 |
| WHIT-824 (B4) bridge | `whity-agent mcp` stdio relay with keychain tokens and refresh | B1 |
| WHIT-824 (B5) chat | Option per Q3 | B1, B3, Q3 |
| WHIT-824 (B6) release | Updater, signing per Q5, installers for three OSes | B0, Q5 |
