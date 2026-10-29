# Document editor: implementation notes (M0)

Milestone M0 of `PLAN.md`: a survey of whity-core against the design (register E.1–E.39, sections 12 and 18, `handoff/editor/`), and a proposal for the server side, which the design leaves open. No feature code is in this change, and no design file or register entry was changed. Everything that looks wrong or open in the design is under [Questions for the owner](#questions-for-the-owner).

Surveyed on `develop` at `0c525285` (29 Oct 2026). Paths are relative to the repository root.

## Summary

- **The foundation is mostly not in place.** The code runs on tokens 2.0.0. Of section 12's 13 items, none is done, eight are partly done and five are missing. Item 13 is partly done: the repository has moved to `whity-org`, and the npm scope rename is open as PR #1407.
- **A Document designer already exists** at `/admin/documents`: a 1,893-line screen with a canvas mode and a flow mode, its own JSON model (DocTemplate v2), its own tables (`document_templates`, `document_blocks`) and two Whity Render routes. The new editor replaces or absorbs it. Which of the two is the biggest decision before M2 (Q1).
- **No editor library is installed** (TipTap, ProseMirror, Lexical, Slate). Rich text today is an in-house `TextRun[]` model.
- **The server has none of the collaboration and agent pieces**: no document versions, no optimistic concurrency, no comments, no suggestions, no realtime channel, no generic file upload, no agent identity, no proposals and no per-permission modes. An agent today *is* a person's token, and the audit log can't tell the two apart.
- **What fits well:** the permission registry, the tenant-isolation guards, route-derived MCP tools, the event outbox, the render service's JSON-in/PDF-out protocol and its deterministic paginator, which the editor's page view can reuse, and the plugin system's interfaces, which can be extended with block definitions.
- **Estimate:** about 39–51 engineer-weeks for M1–M8 including the server work, or 10–13 months for one engineer (see [Estimates](#dependencies-risks-and-estimates)).
- **The owner answered every question on 29 Oct 2026** (see [Owner's answers](#owners-answers-29-oct-2026)). The editor replaces both modes of the current designer. Four blocks and one page kind are proposed as register additions, waiting for approval.

## 1. Foundation status (section 12, "Changes whity-core needs")

| # | Item | Status | What's there |
| --- | --- | --- | --- |
| 1 | Tokens v3 | **Missing** | `src/design/tokens/base.json` is version 2.0.0. `muted-foreground` is oklch(0.556), not 0.52. `input` is oklch(0.922) light and `oklch(1 0 0 / 15%)` dark, not 0.66 / 0.52. Status colours are single fills with no text or tint. There is no `primary-tint`, `divider`, `table-header`, `chart-6`, `plane-deep`, `mark-sheet` or blueprint colours, and `chart-2…5` are greys. No `shadow-plane*`, `shadow-float` or `bg-blueprint-grid`. The primary colour is already right: oklch(0.5 0.16 255) = #0961bb, asserted in `web/__tests__/generate-tokens.test.ts`. The generator (`src/design/tokens/generate-tokens.js`) writes `web/app/globals.css` (generated block), `packages/tokens/generated/*` and `flutter/whity_tokens/.../tokens.dart`, and CI checks for drift. |
| 2 | Type | **Partly** | Noto Sans, Noto Sans Arabic and Geist Mono load through `next/font/google` (`web/app/layout.tsx`), and `packages/tokens/generated/fonts.css` self-hosts them for non-Next clients, including 700. **Readex Pro isn't loaded anywhere.** No 14px default is set (`body` inherits 16px), and components use `text-xs` about as often as `text-sm`. `font-bold` appears 95 times in 41 files, and the `--font-weight-bold: 700` token exists. Document data uses `fontWeight: 'bold'` about 40 times (`packages/features/src/document-designer/starters.ts`). |
| 3 | Button, input, select | **Missing** | Buttons are 32 / 28 / 36 (default / sm / lg), not 36 / 28 / 40, with an 8px radius, not ¼ of the height (`packages/ui/src/button.tsx`). An outline variant exists, but `secondary` is a grey fill. No plane. |
| 4 | Badge, alert, nav, tabs | **Partly** | Badges and alerts are tinted, but with Tailwind palette classes. 264 palette-class lines across 13 files, nearly all in `packages/ui` (`button.tsx`, `badge.tsx`, `card.tsx`, `alert.tsx`, `access-denied.tsx`, `beta-warning.tsx`, `plugin-card.tsx`, `color-*.tsx`, `route-flow/editor.tsx`). Tabs have an unused `line` variant, and the default is a pill. 82 raw hex values outside tests and stories. |
| 5 | Card, menus, dialogs | **Partly** | The default card has `shadow-2xs` and `rounded-xl`. Floating layers don't use `shadow-float`, which doesn't exist yet. |
| 6 | `data-table.tsx` | **Partly** | `packages/ui/src/data-table.tsx` (1,083 lines) has sort, server pagination and search, a skeleton while loading, empty and error states, and a row-actions render prop. **Missing:** checkbox selection, the bulk bar, the sticky header and the totals row. |
| 7 | App shell | **Partly** | `packages/ui/src/app-sidebar.tsx`: 256px, collapsing to 64px by a manual toggle, not at 1024. Drawer below 768. Tenant logo slot and tenant switcher present. |
| 8 | Icons | **Partly** | Tabler (`@tabler/icons-react`) only, so the set is right. No agent icon (robot face), no stroke 2.25 at 16px, and the 116-icon curated list isn't enforced. |
| 9 | brand.whity.dev logos | **Not in this repository** | Out of scope here. Needs checking where brand.whity.dev is built. |
| 10 | Agent proposals (server) | **Missing** | Nothing called a proposal exists in `src`, `database`, `plugins` or `sdk`. The nearest patterns are human approval queues: password reset (076), two-factor recovery (077) and route verdicts. |
| 11 | Per-permission mode on AI principals | **Missing** | There is no agent principal. `mcp_tokens.principal_kind` is `user`, `session` or `oauth`. ADR 0006 §3 names `ai-agent` and `service`, but neither is minted. `AiPrincipalsApiHandler` only lists and revokes tokens. |
| 12 | Whity Render: `Doc` rules, 13 templates, amount in words, JoFotara | **Missing** | `render-service/` (Express, puppeteer-core, its own paginator) renders the current designer's canvas and flow models. None of stage 6's `Doc` rules, the templates, amount in words or the JoFotara QR is built. The QR it draws is Whity's own verification code (`document_qr_tokens`). |
| 13 | Move to a Whity organisation | **Partly** | The repository is `whity-org/whity-core` and `origin` points there. The npm scope rename to `@whity-org/*` is PR #1407 (`chore/move-to-whity-org`, open; 358 files). `composer.json`, `sdk/composer.json` and several docs still say `amroksaleh`. |

Also relevant:

- **i18n** is in-house: `t(key, 'English')`, with English extracted into `database/i18n/*.json` and Arabic hand-written in `database/i18n/ar/*.json`. Every domain is complete in Arabic except `admin`, which is missing 123 keys. The editor gets its own domain (`editor`), so the "every string has an Arabic label" rule can be checked by the existing drift guard.
- **RTL:** `web/lib/direction-context.tsx` sets `dir` and `lang` before paint, logical classes are widely used, and there's an `rtl-direction` e2e spec.
- **Tests:** Jest (about 190 files), Playwright (about 37 specs, including the document designer), 10 axe tests. **No screenshot or visual-regression tests.** The per-milestone visual check needs a harness (proposed in M1).

## 2. Mapping check: where each part lands

### Packages

The design says "TipTap inside `packages/ui`". In this repository the split is: `packages/ui` holds presentational components with no data fetching, `packages/features` holds feature screens that take an adapter, and `web/` wires adapters to the API. The editor follows that split:

| Part | Lands in |
| --- | --- |
| Contract (`whity-document.ts`) | `packages/ui/src/editor/contract.ts`, copied unchanged. A drift test compares it with `docs/design/handoff/editor/whity-document.ts`. PHP gets matching value objects in `src/Core/Document/Editor/` and a JSON Schema generated from the contract for server-side validation. |
| TipTap schema, nodes, marks, input rules, suggestion filter, pagination decoration | `packages/ui/src/editor/` (`extensions/`, `nodes/`, `marks/`) |
| `DocEditor` and its shell (header, toolbar, outline, canvas, rail, status bar) | `packages/ui/src/editor/` as components that take `DocEditorProps` |
| Document designer screen (A33), notes and wiki screens | `packages/features/src/document-editor/`, with a `DocumentEditorAdapter` like today's `document-designer/adapter.ts` |
| Routes | `web/app/(editor)/admin/documents/...`, which today renders the old designer behind `ScreenTooSmall` (blocked below 1024px) |
| Print renderer (read-only blocks to HTML) | `packages/ui/src/editor/print/`, imported by both the page view and `render-service/harness`, as the current designer does (ADR 0012) |
| Strings | `t('editor.…', 'English')`, with Arabic in `database/i18n/ar/editor.json`. `DocEditorProps.labels` is filled from `t()` by the feature layer. |

### `handoff/editor/README.md` row by row

| Design part | Fits? | Note |
| --- | --- | --- |
| Blocks (E.13) as TipTap nodes, flat lists | Yes | New. Doesn't reuse the current flow blocks (`packages/ui/src/documents/flow.ts`), whose set differs (figure, spacer, facts, question). See Q1. |
| Marks, `bold` at 600 | Yes | The 700 token and the `font-bold` uses go in M1. |
| Merge fields (`field` node) | **Partly** | Today's fields are `{{placeholder}}` strings filled from flat string maps (`VariableData.php`). There is **no field catalogue per record type** and no core "invoice" record type: records come from plugins, data types and forms. See Q6. |
| Block chrome, / menu, +, Markdown shortcuts, bubble | Yes | Client only. |
| Suggesting mode (transaction filter) | Yes, with risk | Client only, but the hardest piece of client logic (see Risks). TipTap's paid Pro extensions aren't used; this is built in-house. |
| Comments (mark, thread store, rail) | Yes | Needs server tables and an update channel. |
| Agents (`onAskAgent` streams `AgentResult`) | **No, not yet** | MCP has no streaming tool results, and **nothing in Whity runs an agent**: agents are external MCP clients. Someone has to decide where "Ask an agent" goes when a person clicks it (Q3). Also needs items 10 and 11. |
| Page view (pagination decoration) | Yes | `render-service/src/flow/assets/paginate.js` is deterministic browser code, written so an editor can run it (`docs/wiki/Document-Render-Service.md`). Reusing it keeps the editor's breaks close to the PDF's. |
| Plugin blocks (native or sandboxed iframe) | **Partly** | Plugins are PHP classes with no JSON manifest and no way to ship JavaScript into the web app. Native rendering is possible only for blocks compiled into `packages/` (first party). Native rendering for third-party "verified" plugins needs a module-loading mechanism that doesn't exist (Q8). The iframe `embed` screen exists, but nothing enforces a sandbox. No "verified" flag exists. Plugin enablement is instance-wide (a `.disabled` file), with per-tenant access through entitlements. |
| Saved blocks | **Partly** | `document_blocks` (059) already holds reusable blocks, with four scopes (personal, tenant, global, system), usage lookup and a 409 on deleting a block in use. This is close to saved blocks, but has no versions, no synced or copy mode, and different sharing (Q2). |
| Collaboration (Yjs, later) | Later | No realtime of any kind today (no Mercure, websockets or SSE streams). |

### What doesn't fit the code as it is

1. **The existing designer's canvas mode has no counterpart in the design.** It places elements absolutely (text, image, barcode, QR, shapes, maths), and the starters and the CSV batch print depend on it. E.13 has no barcode, QR, shapes or maths blocks (Q1).
2. **Names.** The `documents` table already means *issued* documents (PDF artifacts, routing, QR). Permission slugs are plural (`documents:read`, `documents:write`, `documents:publish`, `documents:render`), while the contract's example is `document:edit` (Q5).
3. **Concurrency.** Writes are last-write-wins, with no version columns and no `If-Match`. The editor autosaves, and agents add suggestions to documents people have open, so optimistic concurrency is required from M2.
4. **Audit and actor.** Template and block writes write no audit row and no event. MCP audit rows don't record `principal_kind`, though ADR 0006 §6 says they should, and nothing records "on behalf of". The contract's `Actor` (`agent` with `onBehalfOf` and `scope`) can't be filled from today's data.
5. **Files.** No generic upload API or files table, so Image and File blocks need one.
6. **Live values.** No shared query layer for Record and Chart blocks. The nearest things are `ReportSourceRegistry` (tenant-scoped, permission-gated row sources) and `RecordSectionResolver`. Charts can take `source.query` as a report-source id. Records need a resolver per record type (Q6).
7. **Small screens.** The design keeps the editor usable under 1024px (a rail drawer, a scrolling toolbar). The current screen is blocked there by `ScreenTooSmall`, which goes for the editor.
8. **Repository conventions.** Branches are `type/WC-XX-…` and commits `WC-XX: …` (CONTRIBUTING). This branch is `design/editor-m0` as asked, and commits are prefixed "Editor M0:" for want of a ticket. CONTRIBUTING also forbids AI attribution in commits and PRs, so there is none. `docs/*` is git-ignored apart from `wiki/` and `adr/`, so this branch adds `!docs/design/` to `.gitignore`.
9. **Migrations are consolidated.** No production database exists, and the house rule is to edit the migration that creates a table rather than layer patches. Changes to `document_templates` and `document_blocks` therefore edit 059 (plus 075 and 117), and new tables get new numbers from 160.

## 3. Server proposal

The design leaves all of this open. These are proposals for the owner to approve or change; nothing here is built. They follow the house rules: every table carries `tenant_id` and is registered in `src/Core/Tenant/CoreTables.php`, every query has a literal tenant predicate, every new table gets a case in `CrossTenantRejectionRealEngineTest`, and every new permission ships with its grant migration.

### 3.1 Tables

Names assume the answers recommended in Q1 and Q5: one table for every written document, beside the existing `documents` table for issued ones.

| Table | Milestone | Columns (beyond `id`, `tenant_id`, timestamps) |
| --- | --- | --- |
| `document_sources` (one row per template, letter, report, note or wiki page; replaces `document_templates`) | M2 | `kind` (template · letter · report · note · wiki), `title`, `lang`, `default_view`, `full_width`, `page` JSONB, `record_type`, `owner_profile_id`, `owner_ou_id`, `scope`, `audience` (as today), `draft` JSONB (`blocks` only, no comments or suggestions), **`draft_version` int** (optimistic lock, bumped on every save), `published_version` int null, `updated_by` actor columns |
| `document_source_versions` | M4 | `source_id`, `version`, `blocks` JSONB, `saved_block_pins` JSONB (saved block id → version at publish, E.38), `published_by_profile_id`, `published_at`. Insert-only. |
| `document_suggestions` | M5 | `source_id`, `kind` (text · format · block), `summary`, `request`, `permission`, `routine_id` null, **actor columns**, `base_version`, `patch` JSONB (see 3.3), `status` (open · accepted · rejected · stale), `decided_by_profile_id`, `decided_at` |
| `document_comment_threads` | M5 | `source_id`, `anchor_block_id`, `quote`, `resolved_by_profile_id`, `resolved_at` |
| `document_comments` | M5 | `thread_id`, actor columns, `body` JSONB (`Inline[]`), `mentions` JSONB, `edited_at` |
| `saved_blocks` (evolves `document_blocks`) | M7 | `name`, `description`, `mode` (synced · copy), `sharing`, `owner_profile_id`, `blocks` JSONB, `per_document_keys` JSONB, `version` |
| `saved_block_versions` | M7 | `saved_block_id`, `version`, `blocks` JSONB. Lets a published template keep printing the version it was published with. |
| `saved_block_uses` | M7 | `saved_block_id`, `source_id`, `version`. Backs `usedIn`, the "used in 12 documents" label and the 409 on delete, replacing `BlockReferenceScanner`'s scan. |
| `document_files` | M3 | `source_id` null, `storage_key`, `name`, `mime`, `size`, `checksum_sha256`, `uploaded_by_profile_id`. Stored through `TenantRoutingStorageDriver`, served through the owning document's visibility check. |
| `agent_principals` | M6 (item 11) | `name` (by role, 5.6b), `created_by_profile_id`, `status`. A token's principal can then be an agent acting for a person. |
| `agent_permission_modes` | M6 (item 11) | `agent_principal_id`, `granted_by_profile_id`, `permission`, `mode` (off · ask · allow). Effective rights = person's ∩ the agent's allowed and ask permissions. Changes audited. |
| `agent_proposals` | M6 (item 10) | `agent_principal_id`, `on_behalf_of_profile_id`, `tool`, `arguments` JSONB, `diff` JSONB, `scope` JSONB, `undo_window_seconds`, `status` (waiting · approved · declined · expired · undone), decision columns |
| `agent_routines` | M6 (E.32) | `name`, `set_up_by_profile_id`, `agent_principal_id`, `schedule`, `request`, `target` JSONB. Run through the existing `jobs` queue. |

**Actor columns** are used wherever the contract has an `Actor`: `actor_kind` (person · agent · system), `actor_profile_id`, `agent_principal_id` null and `on_behalf_of_profile_id` null. The same four columns are added to `audit_log` and `domain_events`, so that "Billing assistant for Rana" can be reconstructed everywhere, and MCP audit rows start recording `principal_kind` as ADR 0006 §6 already requires.

**The block registry is not a table.** Definitions come from code: Whity's own from `packages/ui`, and plugins' from a new `PluginDocumentBlocksInterface` read at plugin load. Whether a plugin's block is usable for a tenant is answered by the plugin's state plus entitlements, both of which already exist. A persisted registry adds nothing but drift.

### 3.2 API routes

All routes live under `/v1`, are declared in `CoreApiSchemas` with `public/openapi.json` regenerated, and return `{"data": …}` or `{"error": …}` as today.

| Route | Permission | Notes |
| --- | --- | --- |
| `GET /api/document-sources`, `POST /api/document-sources` | `documents:read`, `documents:write` | List (paginated, unlike today) and create |
| `GET /api/document-sources/{id}` | `documents:read` | The `WhityDocument` projection: draft blocks plus open comments and suggestions, with `ETag: "<draft_version>"` |
| `PUT /api/document-sources/{id}/draft` | `documents:write` | Requires `If-Match`. On a mismatch, the server tries a block-level three-way merge (blocks are keyed by stable ids) and returns 409 with the current version only if the same block changed on both sides. Refused for agent principals: agents never write a draft (E.4). |
| `GET /api/document-sources/{id}/publish-check` | `documents:publish` | Returns `PublishBlocker[]` (E.37) |
| `POST /api/document-sources/{id}/publish` | `documents:publish` | Runs the check again (409 with blockers), writes a version and pins saved blocks |
| `GET /api/document-sources/{id}/versions[/{v}]` | `documents:read` | History of published versions |
| `GET /api/document-sources/{id}/preview?record=type:id` | `documents:read` + the record's own read | Field values for "Preview with a record" (E.12), with each missing value flagged (E.27) |
| `POST /api/document-sources/{id}/render` | `documents:render` | PDF of the published version, or of the draft for a preview. Persists through `DocumentIssuer` as today (see 3.6). |
| `GET/POST /api/document-sources/{id}/suggestions` | read / `documents:suggest` | Create from a patch. People in Suggesting mode use this too. |
| `POST /api/document-sources/{id}/suggestions/{sid}/decision` | `documents:write` | Accept or reject. Applies the patch to the draft on accept, and audits the decision with the actor (E.29). |
| `POST /api/document-sources/{id}/suggestions/decisions` | `documents:write` | "All" or "all from one agent" in the rail menu |
| `GET/POST /api/document-sources/{id}/comments`, `POST …/comments/{tid}/messages`, `POST …/comments/{tid}/resolve`, `…/reopen` | read / `documents:comment` | Threads, replies (with notifications to @mentioned people) and resolve |
| `GET /api/document-sources/{id}/changes?since=<cursor>` | `documents:read` | What changed since the cursor: suggestions, comments, draft version. Polled every 10 s while open in v1, later replaced by an SSE stream (Mercure in FrankenPHP) without changing the payload. |
| `POST /api/document-sources/{id}/files` | `documents:write` | Image and file upload, 1 MiB body limit raised for this route only |
| `GET /api/document-fields?recordType=` | `documents:read` | The field picker's groups, from a `DocumentFieldProvider` per record type (core: Company, Today; plugins add theirs) |
| `GET /api/document-block-definitions` | `documents:read` | Whity's and the tenant's enabled plugins' `BlockDefinition`s, plus `MissingPluginState` for plugins in use but off |
| `GET/POST/PATCH/DELETE /api/saved-blocks[/{id}]`, `GET /api/saved-blocks/{id}/usage` | `documents:read` / `documents:write`; workspace library: `documents:library:manage` | Replaces `/api/document-blocks` |
| `POST /api/document-sources/{id}/agent-requests`, `GET /api/agent-requests/{rid}` | `documents:read` + agent use | Ask an agent (depends on Q3). Returns a run id. The run's status (`AgentRunStatus`) and its suggestions arrive through `changes`. |
| `GET/PUT /api/agents/{id}/permission-modes`, `GET /api/agent-proposals`, `POST /api/agent-proposals/{id}/approve`, `…/decline`, `…/undo` | owner permissions | Items 10 and 11. The approvals queue (7.5b) reads `agent-proposals`. |

### 3.3 Suggestion patches

A suggestion is stored as its own row with a patch anchored to block ids, never as a hidden edit in the draft. The editor renders open suggestions as marks and dashed blocks, which is what the contract's `insertion` / `deletion` marks and `suggestion` attribute describe. Accepting one applies the patch to the draft on the server in one transaction. This is what makes "no code path lets an agent change a document without a suggestion" testable on the server, not just in the client. Patch shapes:

- `insert-blocks`: `{ afterBlockId, blocks: Block[] }`
- `replace-text`: `{ blockId, deleteText, insert: Inline[], occurrence }`. Matched by text, not offset, so it survives edits elsewhere in the block, and marked `stale` when the text has gone.
- `format`: `{ blockId, range, mark, add }`
- `delete-blocks`: `{ blockIds }`
- `move-block`: `{ blockId, afterBlockId }`

### 3.4 MCP tools for agents

These are hand-authored tools (`AuthoredToolRegistry`) rather than derived ones, so each can carry its rule. Every writing tool creates a suggestion, a comment or a proposal, never a draft write. The route-derived tools that write drafts (`PUT …/draft`, `publish`, `decision`) refuse agent principals.

| Tool | Does | Permission |
| --- | --- | --- |
| `document_read` | Returns the `WhityDocument` (draft when the request came from inside the editor, published otherwise; see Q4) and the records its fields and blocks point to, filtered by the agent's and the person's permissions (E.31) | `documents:read` |
| `document_list_fields` | The fields available for the document's record type | `documents:read` |
| `document_suggest_blocks` | Insert blocks after a block, as a suggestion. Plugin blocks only within the plugin's scopes and the agent's. | `documents:suggest` |
| `document_suggest_rewrite` | Replace text in a block, as a suggestion (`replace-text` patch) | `documents:suggest` |
| `document_comment_reply` | Reply in a thread (E.30), optionally with suggestion ids it made | `documents:comment` |
| `document_comment` | Start a thread, for example an agent's review comment with "Suggest the fix" | `documents:comment` |

Each suggestion records the agent, the person, the permission used and the routine if any, and goes to the audit log. If the agent's mode for `documents:suggest` is "Ask first", the suggestion itself is the thing a person decides on: a suggestion already waits for a person, so no proposal is wrapped around it (Q9).

### 3.5 Permissions

The existing plural slugs are reused, and three are added. Each new slug ships with a grant migration to whoever already holds the related capability, as `ci-permission-holder-guard.php` requires.

| Slug | Meaning | Status |
| --- | --- | --- |
| `documents:read` | Open, read, preview | exists |
| `documents:write` | Edit drafts, accept and reject suggestions (E.29), upload files | exists |
| `documents:publish` | Publish a template (E.9), share at workspace level | exists |
| `documents:render` | Make a PDF | exists |
| `documents:suggest` | Suggest changes (E.10; agents' main permission) | **new**; granted to holders of `documents:read` (see Q7) |
| `documents:comment` | Comment and reply | **new**; granted to holders of `documents:read` |
| `documents:library:manage` | Manage the workspace saved-block library (E.22) | **new**; granted to holders of `documents:publish` |

The editor's mode follows from these: write → Editing, suggest → Suggesting, read only → Viewing. `Suggestion.permission` then reads `documents:suggest` on agents' cards.

### 3.6 Plugin blocks and the sandbox protocol

- **PHP side.** A new SDK interface, `PluginDocumentBlocksInterface::getDocumentBlocks(): array`, returns `BlockDefinition`s. The plugin's `data` JSON Schema is validated on the server on every save, not only in the editor, and so are its `scopes`, which must be permissions the plugin owns or declares. `print` names a print view the plugin implements in PHP (see 3.7).
- **Native rendering.** First-party blocks are compiled into `packages/`. Third-party plugins use the frame until Q8 is answered.
- **The frame.** An `<iframe sandbox="allow-scripts">` with no `allow-same-origin`, so the frame has an opaque origin and can't reach cookies or storage. It's loaded from the plugin's own GET route, which is served with a strict CSP (`default-src 'self'; connect-src 'none'; frame-ancestors <app origin>`), so all data goes through the host. The host sizes it (`minHeight`, then whatever height the frame reports, capped).
- **Messages.** The host sends `init` with a `MessagePort` (`MessageChannel`), and every later message goes over that port, never `window.postMessage(*)`. Every message is `{ v: 1, id, type, ... }`.

| Direction | `type` | Payload |
| --- | --- | --- |
| host → frame | `init` | `blockId`, `blockType`, `schemaVersion`, `data`, `mode` (editing · suggesting · viewing · print-preview), `readOnly`, `theme`, `dir`, `lang`, `labels`, the token CSS text (the "same UI kit") |
| host → frame | `data-changed`, `env-changed` | Data accepted elsewhere (undo, a suggestion accepted); theme, direction or mode changed |
| host → frame | `toolbar-action` | `{ actionId }` from the block toolbar the definition declared |
| host → frame | `response` | `{ replyTo, ok, result \| error }` |
| frame → host | `ready` | none |
| frame → host | `resize` | `{ height }` |
| frame → host | `set-data` | `{ data }`. The host validates it against the schema. In Suggesting mode, or for an agent, it becomes a suggestion. |
| frame → host | `read` | `{ path, params }`. The host calls the plugin's API with the viewer's session, allowed only for routes gated by the block's `scopes`, so the effective right is the viewer's permissions ∩ the block's scopes. |
| frame → host | `error` | `{ message }`. Shown with the plugin's name, without taking the editor down. |

The host ignores anything that doesn't come over the port, drops unknown types, and rate-limits `set-data` and `read`. A frame gets no navigation, popups, top-level access or downloads.

### 3.7 The print path through Whity Render

1. **Publish** freezes the blocks and the saved-block versions (`document_source_versions`).
2. **Render** (`POST …/render`) resolves on the server, within the requester's permissions:
   - field values (E.27: an optional field is left blank, and a missing required or fiscal field returns 422 listing the fields);
   - Record and Chart blocks, as values at that moment (E.16);
   - synced saved blocks, at their pinned version with `overrides`;
   - plugin blocks, through the plugin's PHP print view, which returns blocks from a closed print set (the existing flow set: heading, paragraph, table, list, facts, qr, divider, figure), never HTML or code. That keeps third-party code out of the renderer. See Q10, since the contract calls this a "Whity Render template id".
3. **Payload.** The result is a print-ready `WhityDocument`: no comments, no suggestions, no field nodes, only values. It goes to a new `POST /render/document` on render-service, using the same `X-Render-Secret`, synchronous with a 30 s timeout, and returning PDF bytes plus the page-count headers.
4. **Render-service.** The harness imports `packages/ui/src/editor/print/`, the same components the page view uses (ADR 0012), and paginates with the existing `paginate.js`. The stage 6 `Doc` rules (letterhead, running header, "Page x of y", continuation pages, amount in words) are built there as part of item 12.
5. **Storage.** `DocumentIssuer` stores the artifact, and `documents.variable_data` keeps the frozen values, so a re-render can show what was printed.

## 4. Dependencies, risks and estimates

### Dependencies

- **PR #1407 (scope rename) should merge before M1.** It touches 358 files, the same ones M1 restyles.
- **M1 blocks everything visual.** The editor reuses its components.
- **Server work rides with the milestone that needs it**, since PLAN.md has no server milestone:
  - M2: `document_sources` with optimistic concurrency.
  - M3: files.
  - M4: versions, publish check, render.
  - M5: suggestions, comments, the changes feed and the two new permissions.
  - M6: items 10 and 11, agent principals, routines.
  - M7: registry, saved blocks, sandbox.
- **M6 needs Q3 answered.** Nothing can be built for "Ask an agent" until someone decides where the agent runs.
- **Item 12 (the `Doc` rules and templates) is a large piece of work of its own.** M4 needs at least the page rules. The 13 templates and JoFotara can follow, and JoFotara needs the accountant check the register lists.

### Risks

| Risk | Impact | Mitigation |
| --- | --- | --- |
| Suggesting mode as a ProseMirror transaction filter: paste, split and join, lists, tables and IME input all produce odd steps | High: it's E.10 and E.4's whole promise | Server-side patches (3.3) as the record of truth; a property-based test suite on the filter; scope v1 suggestions to text, format and whole blocks |
| Page view pagination drifts from the PDF | Medium | Reuse `paginate.js` in both; a test that compares page counts for the 13 templates |
| Replacing the current designer loses features people use (canvas, barcode, maths, CSV batch) | High | Q1 before M2; migrate starters and saved templates with a script and tests |
| M1 restyles the whole app, not just the editor (button heights, 14px, cards) | Medium: every screen changes at once | Screenshot harness first; review M1 screen by screen |
| No realtime: agent suggestions and comments arrive while someone types | Medium | Patches anchored to blocks, a three-way merge on save, polling at 10 s; SSE later |
| Undefined agent runtime | High for M6 | Q3 |
| A third-party plugin frame escaping its sandbox | High | No `allow-same-origin`, `MessagePort` only, strict CSP, a Playwright test that the frame can't read the parent, cookies or storage (M7's done-when) |
| Bidirectional text in ProseMirror (per-block `dir="auto"`, `bdi` around Latin) | Medium | M8, but design the node views with `dir` from M2 |
| TipTap major version (README says 2; 3 is current) | Low | Q11 |
| Bundle size (TipTap plus the editor) on every admin page | Low | Load the editor route on its own |

### Estimates

In engineer-weeks for one experienced full-stack engineer, including tests and the visual check, but not review time:

| Milestone | Client | Server | Total |
| --- | --- | --- | --- |
| M1 Foundation (tokens v3, fonts, icons, 10 components, screenshot harness) | 4–5 | 0 | **4–5** |
| M2 Editor core | 4–5 | 1 | **5–6** |
| M3 Rich blocks (incl. live Record and Chart, files, and Barcode, QR and Maths per Q1) | 4–5 | 1–3 | **5–8** |
| M4 Templates (fields, page view, publish, Render path, A33, label sheets and CSV batch, moving existing templates per Q1) | 4–5 | 4–5 (incl. the `Doc` page rules) | **8–10** |
| M5 Comments and suggestions | 3–4 | 2 | **5–6** |
| M6 Agents (incl. items 10 and 11) | 2 | 4–5 | **6–7**, once Q3 is settled |
| M7 Plugin and saved blocks | 2–3 | 2–3 | **4–6** |
| M8 Arabic, type, accessibility | 2–3 | 0 | **2–3** |
| **All** | | | **39–51** |

The 13 templates, amount in words and JoFotara (the rest of item 12) aren't in these numbers: add 4–6 weeks.

## 5. What M1 would change

- `src/design/tokens/base.json` → tokens v3 from `docs/design/tokens.json`. Then regenerate `web/app/globals.css`, `packages/tokens/generated/*` and `flutter/whity_tokens`. Import `docs/design/handoff/web/theme.css` after them, adapted into the generator so the drift check still holds.
- **Fonts:** self-host Noto Sans and Noto Sans Arabic 400/500/600, Geist Mono 400/500 and Readex Pro 600 (Latin and Arabic) in `packages/tokens/fonts`. Drop the 700 files and Geist Mono 600, which the handoff's `fonts/` still ships but the register no longer declares. Remove the `--font-weight-bold: 700` token, and change `font-bold` to `font-semibold` throughout (95 uses). Set the 14px default on `body`.
- **Icons:** the 116 icons with their Tabler names from `handoff/icons/icons.json`, an `Icon` component at stroke 2.25 / 16px, and the robot face for agents.
- **Restyle and new components in `packages/ui`:** Button (36 / 28 / 40, ¼ corners, outline secondary, the plane on the main button), IconButton with Tooltip, Menu (`dropdown-menu`), Popover, ToggleGroup (new), Dialog, Avatar (new), ActorChip (new), Badge, AgentRunStatus (new). No palette classes or hex values left in them.
- **A screenshot harness:** Playwright over Storybook, rendering each component's story at its card's size from `cards.html` in light, dark and Arabic, compared against `docs/design/screenshots/`. That makes the per-milestone visual check repeatable.
- **Not in M1:** the rest of the palette-class clean-up outside those components, data table, app shell and cards. They're section 12 items, but the editor doesn't need them. They'd be a separate foundation branch if wanted.

## Owner's answers (29 Oct 2026)

| Q | Answer | What it changes |
| --- | --- | --- |
| 1 | **Replace both modes.** The new editor replaces the flow mode and the canvas mode. Barcode, QR code and Maths become blocks, and labels and certificates become a label sheet layout with CSV batch printing. | Proposed register additions below. M3 gains three blocks. M4 gains the label sheet kind, CSV batch and a migration of existing templates and starters into the new model. `ScreenTooSmall` and the old designer are deleted once nothing uses them (no-legacy rule). |
| 2 | **Evolve `document_blocks`** into `saved_blocks`. | Edit migration 059 (plus 075 and 117). `global` and `system` stay for blocks Whity ships; the Save dialog offers Only me and Everyone in the workspace. |
| 3 | **No Whity-paid model API for now.** In local mode the request goes to the person's own AI client (Claude Code or similar) connected over MCP. What the hosted website does is undecided. | No built-in agent runtime and no model-provider billing. "Ask an agent" writes an agent request to a queue. The person's MCP client picks it up with `document_agent_requests_next`, works with the tools in 3.4, and reports progress with `document_agent_request_update`, which feeds `AgentRunStatus` through the changes feed. The picker lists only agents connected for that person. When none is connected, the sheet says how to connect one rather than failing. The hosted website's behaviour stays open and doesn't block M6. ("Local mode" isn't yet a named concept in the code; it means a Whity the person's own AI client can reach over MCP.) |
| 4 | **Draft inside, published outside.** | As proposed: `document_read` returns the draft for requests started in the editor and the published version everywhere else. |
| 5 | **Plural `documents:*` slugs and a `document_sources` table.** | As proposed in 3.1 and 3.5. |
| 6 | **Field providers.** | `DocumentFieldProvider` per record type, registered by core (Company, Today) and plugins; Record blocks resolve through it. The contract carries the internal id and shows the display number. |
| 7 | **Separate slugs, granted to readers.** | As proposed in 3.5. |
| 8 | **Native rendering for first-party blocks only.** | Every third-party plugin uses the sandboxed frame, verified or not, until a signed-module mechanism exists. |
| 9 | **The suggestion itself is the approval.** | No proposal is wrapped around an agent's suggestion. |
| 10 | **PHP print view.** | As proposed in 3.7. `print.renderer` in the contract needs a register-approved rename or reinterpretation before M7. |
| 11 | **TipTap 3.** | `handoff/editor/README.md` says TipTap 2; the build uses 3. |
| 12 | **Fix the cross-references** in this PR. | Done in a separate commit (see below). The register's values were already right, so it isn't changed. |
| 13 | **The text is enough** for the 1024 and 768px layouts. | Checked against the card READMEs and section 18, not screenshots. |
| 14 | **CONTRIBUTING naming** (`feature/WC-XX-…`, `WC-XX:`) once there's a ticket; until then `design/editor-mN` and "Editor Mn:". | Later milestones use the ticket the owner gives. |

### Cross-reference fixes made (Q12)

- `18-document-editor.md`, `handoff/editor/README.md` and the `whity-document.ts` header: "E.1–E.34" → "E.1–E.39".
- `components/EditorCollab/README.md`: the dashed-means-not-happened rule points at 4.3, not 2.4. `whity-document.ts` (`Actor` comment): attribution points at the stage 7 actor chip, not 2.4.
- `components/EditorAgents/README.md`: the set-down is 5.4, not 2.6.

Section 18's shorter block menu is left as is: the card is the detailed spec, and the build follows the card (Copy link to block included).

### Proposed register additions (Q1), waiting for approval

These are drafts for the owner. The register isn't changed until they're approved, and nothing depending on them is built before then. Numbers continue the editor series.

| # | Decision | Proposed value |
| --- | --- | --- |
| E.40 | Barcode block | A Whity block: Code 128, EAN-13 or Code 39, with the value typed or a merge field and the text under it optional. Ink only, sized in mm in the page view. Group "Whity" in the / menu. |
| E.41 | QR code block | A Whity block: a value or a merge field as a QR, with an optional caption. Distinct from the document's verification QR in the footer (6.7), which stays automatic. |
| E.42 | Maths block | A block (and an inline `math` node) for formulas in TeX, rendered with KaTeX on screen and in print. Group "Basic". |
| E.43 | Label sheets | A document kind `label`: a sheet (A4, Letter) divided into a fixed grid of labels, each one the same blocks with fields; Print fills one label per record or per row of an uploaded CSV. It opens in the page view and never in the continuous view. Replaces the canvas mode's labels and certificates; a certificate is a one-label sheet. |

The contract needs matching additions when these are approved: `BarcodeBlock`, `QrBlock`, `MathBlock` and an inline `MathRef` in `Block` and `Inline`, `'label'` in `DocumentKind`, and a `labels: { size, columns, rows, gapMm }` page setting.

## Questions for the owner

All answered on 29 Oct 2026 (above). Kept here as asked, for the record.

1. **The existing Document designer.** Does the new editor replace it, or sit beside it?
   - It has a canvas mode (absolutely placed text, images, barcodes, QR, shapes, maths), a flow mode, starters and CSV batch printing, none of which are in E.13.
   - Recommended: the new editor replaces the flow mode and becomes A33. Canvas layouts (labels, certificates) stay on the old canvas until a decision on whether they come back as blocks (Barcode, QR, Maths) or as a separate "label" document kind.
2. **Saved blocks and `document_blocks`.** Should saved blocks evolve the existing table? It already has four scopes (personal, tenant, global, system), while E.22 has two (Only me, Everyone in the workspace). Recommended: evolve it. Keep `global` and `system` for blocks Whity ships, and don't offer them in the Save dialog.
3. **Where does an agent run when a person clicks "Ask an agent"?**
   - Today agents are external MCP clients. Whity hosts no model and has no way to push a request to one.
   - Options:
     - (a) Whity calls a model provider itself, as a built-in agent runtime.
     - (b) Requests are queued for a connected agent, which picks them up through an MCP tool (`document_agent_requests_next`) or MCP sampling.
     - (c) Both: built-in for Whity's own assistants, the queue for the tenant's agents.
   - Streaming (`AsyncIterable<AgentResult>`) and `AgentRunStatus` work with any of the three, through the changes feed. M6 can't start without an answer.
4. **Which version do agents read?** E.9 says "Whity Render and agents use the published version". E.31 says an agent asked inside a document reads the document. Proposed reading: inside the editor an agent reads the draft it's suggesting against, and everywhere else (sending an invoice, a routine) it uses the published version. Is that right?
5. **Names.**
   - Permissions: OK to keep the existing plural `documents:*` slugs and add `documents:suggest`, `documents:comment` and `documents:library:manage`, rather than the contract's example `document:edit`?
   - Table: OK to name the new one `document_sources`, given that `documents` already means issued documents?
6. **Fields and records.** Where do record types and their fields come from? There is no core invoice, customer or statement record type; those come from plugins and data types. Proposed: a `DocumentFieldProvider` per record type, registered by core (Company, Today) and by plugins, with Record blocks resolved through the same provider. Also, `RecordRef.id` in the examples is a display number (`INV-1042`). Should the contract carry the internal id and show the number?
7. **Who may suggest and comment?** E.10 gives people without edit permission Suggesting. Is that everyone who can read the document, or only those granted the new `documents:suggest`? Recommended: a separate slug, granted by default to readers, so a tenant can make a read-only audience.
8. **Third-party "verified" plugins rendering natively.** That means loading a plugin's JavaScript into the app, and no mechanism for it exists. Is v1 fine with native rendering for first-party blocks only, and the frame for every third-party plugin, verified or not, until a signed-module mechanism exists? Who sets `verified`, and where is it stored?
9. **Agent modes and suggestions.** If an agent's `documents:suggest` is set to "Ask first", is the suggestion itself the approval, as proposed, or should it first be a proposal to make a suggestion? The second seems like a double approval.
10. **Plugin print views.** The contract says `print.renderer` is "a Whity Render template id". Plugins can't ship code into render-service. Proposed instead: a PHP print view that returns blocks from the closed print set. Acceptable, with the contract field reinterpreted (or renamed through the register)?
11. **TipTap 2 or 3.** `handoff/editor/README.md` suggests TipTap 2. TipTap 3 is the current major and is what would be installed today (MIT, React 19). OK to use 3?
12. **Small cross-reference errors in the design.** Listed so the register can be fixed first if wanted. None changes what gets built.
    - `18-document-editor.md`, `handoff/editor/README.md` and the `whity-document.ts` header say E.1–E.34, but the register goes to E.39.
    - `EditorCollab/README.md` ("the rule from 2.4") and the `Actor` comment in `whity-document.ts` ("2.4, E.4") point the dashed-means-not-happened rule at 2.4 (page ground). The rule is 4.3.
    - `EditorAgents/README.md` calls the set-down "from 2.6". It's 5.4.
    - Section 18's block menu lists Turn into, Comment, Duplicate, Move and Delete, but `EditorBlockChrome/README.md` also has Copy link to block. Built per the card unless told otherwise.
    - `docs/design/fonts/` ships 700 weights and Geist Mono 600, which stage 9 says are no longer declared. M1 won't copy them.
13. **Responsive states have no cards.** Every editor card is 1280px wide, but the design changes layout at 1280, 1024 and 768 (outline closes, rail becomes a drawer, toolbar scrolls). Those can only be checked against the text, not a screenshot. Should cards be added, or is the text enough?
14. **Branch and commit names.** CONTRIBUTING wants `type/WC-XX-…` branches and `WC-XX:` commits. Is there a ticket for the editor, and should later milestones follow that (`feature/WC-XX-editor-m1`), or keep `design/editor-mN`?
