# Agent collaboration layer and app shell: survey and plan

The plan for making whity-core easier to understand and use: one app shell and sidenav as the app map draws it, one settings area, shared page patterns, and the agent collaboration layer (proposals, the approvals inbox, undo, scope grants, actor chips). Umbrella task **WHIT-804**.

Surveyed on `develop` at `bc0d8ffd` (31 Oct 2026, after v0.2.20). Sources: `00-decision-register.md` (4.5, 7.1–7.6, 5.4, 5.6, 2.1c), `13-app-map.md`, `15-agents.md`, `10-patterns.md`, `17-components.md`, `12-engineering-handoff.md` and the cards in `components/`. Where this plan and the register disagree, the register wins.

## 1. What's there today

**Foundations are in place.** Tokens v3 (`src/design/tokens/base.json` 3.0.0), the type rules, the curated icons, Button and Badge to their cards, and `ActorChip`, `AgentRunStatus`, `CommandPalette`, `TenantLogo`, `Mark` in `packages/ui` came with Editor M1. Section 12 items 1–5 and 8 are done or nearly; items 10 and 11 (proposals, per-permission modes) landed on the server in 0.2.20.

**The shell is the old one.**

- `web/components/sidebar.tsx` renders `AppSidebar` from `packages/ui`. Groups come from the server (`navigation.register` in `public/index.php`) as `overview · access · documents · records · extend · system · plugins`, not the app map's `Overview · People · AI · Documents · Platform · Settings`. Tenants, billing and promotions sit under "Access"; AI principals and MCP tools under "Extend"; forms and tags under "Records"; approval gating under "Documents".
- The sidebar collapses by a manual toggle, not at 1024px (4.5). Widths are 256/64, not 240/56.
- The tenant switcher sits at the foot, under the user, as a generic `Switcher`; the card puts it under the logo with each workspace's logo and the person's role.
- There is no top bar (WHIT-147). `CommandPalette` exists but is only used inside the document editor (WHIT-419). `GET /api/search` (WHIT-783) has no web caller.
- Every page sets its own header (`web/components/admin/admin-header.tsx` and inline variants); `PageHeader` in `packages/ui` is not the card's (breadcrumb, 24px title, one line, actions at the end).

**Settings are scattered.** `/admin/settings` has ten horizontal link-tabs from `GET /api/v1/settings/tabs`; the personal page is `/settings`, reached only from the user row; and settings-like pages live in other groups (languages, translations, tags, tag groups, notifications, approval gating).

**The agent layer has a server and no screens.**

| Server piece | Status | Web |
| --- | --- | --- |
| Proposals: `GET /api/me/agent-proposals?status=`, `POST /{id}/approve {arguments?}`, `POST /{id}/decline` (WHIT-784) | Shipped. 7-day expiry, only the granting person decides | Nothing |
| Per-permission grant, `grant:<permission>=<mode>` in the credential's scope (WHIT-753) | Shipped. Set when the credential is issued; no way to change it after | Nothing |
| Agent credentials, `POST /api/mcp/tokens` with `agent: true` (WHIT-754, #1477) | Shipped | Connect wizard issues personal tokens only |
| Actor columns on `audit_log` and `domain_events` (#1462, migration 169) | Written | `GET /api/audit-logs` doesn't return or filter them |
| Undo windows and undo | **Not built.** WHIT-784 deferred them until an undoable operation exists | — |
| `init_session`, `GET /api/search` | Shipped | Search not used by the web app |

## 2. Gaps

| # | Gap | Where | Task |
| --- | --- | --- | --- |
| G1 | Sidenav groups don't match the app map; no counts on Inbox and Approvals | `public/index.php`, `web/components/sidebar.tsx` | WHIT-805 |
| G2 | No responsive rail at 1024, widths 256/64 | `packages/ui/src/app-sidebar.tsx` | WHIT-805 |
| G3 | Tenant switcher at the foot, without logos or roles | `sidebar.tsx`, new `TenantSwitcher` | WHIT-805 |
| G4 | No top bar: search with ⌘K hint, notifications, help | new, in the protected layout | WHIT-147 |
| G5 | No app-wide ⌘K; no "Ask an agent" row | `CommandPalette` + `GET /api/search` + nav | WHIT-419 |
| G6 | Settings spread over three places | `/admin/settings/*`, `/settings` | WHIT-806 |
| G7 | No shared page header, list or record pattern | `packages/ui/src/page-header.tsx`, admin pages | WHIT-807 |
| G8 | No `ActionProposal`, `ApprovalQueue`, `ScopeGrant`, `UndoBar`, `AgentActivity`; toast has no Undo | `packages/ui` | WHIT-808 |
| G9 | No approvals screen (A03); proposals don't carry the agent's name | `/admin/approvals`; `AgentProposalsApiHandler::present()` | WHIT-809 |
| G10 | No AI principal page (A21); grants can't be read back per credential or changed | `/admin/ai-principals/[id]`; a grant endpoint | WHIT-810 |
| G11 | Audit API hides who acted; no agent activity (A23) | `AuditLogApiHandler`, new page | WHIT-811 |
| G12 | Nothing can be undone | server design, then the UI | WHIT-812 |

## 3. Phases

One PR per row, each starting with its WHIT ID and adding a `changelog.d/<id>.<section>.md` fragment. Every screen is compared with its card screenshots in both themes and in Arabic before it's called done.

### Phase 1: app shell

1. **WHIT-805 Sidenav.** Move every core `navigation.register` item to the app map's groups (below), with EN/AR labels. 240px, a 56px rail with tooltips from 768 to 1023, a drawer under 768. Tenant switcher under the logo; user and account at the foot. Waiting counts on Inbox and Approvals.
2. **WHIT-147 Top bar.** Search field (opens ⌘K), notifications, help; the run-status pill slot for later.
3. **WHIT-419 Command palette.** ⌘K / Ctrl K anywhere: pages from the navigation the caller already gets (never a page they can't open), records from `GET /api/search`, a few actions; the last row asks an agent (see decision D2).

Sidenav groups (per `13-app-map.md`; the system tenant also sees Platform):

| Group | Items |
| --- | --- |
| (no heading) | Dashboard · Inbox · Approvals · My submissions |
| People | Users · User groups · Roles · Organisational units · Delegations · Relations · Registrations |
| AI | AI principals · MCP tools · Agent activity |
| Documents | Library · Templates · Routing · Notes and wiki · Forms · Meetings (the engines) |
| Platform | Tenants · Plugins · Plugin store · Billing · Promotions · Audit log · Errors · Notifications · Languages · Translations · Tags |
| Settings | one entry, opening the settings area (D1) |

Plugin items keep their own `group` (default `plugins`, after the core groups).

### Phase 2: settings and page patterns

4. **WHIT-806 Settings area.** One layout with a grouped side sub-nav, driven by the existing RBAC-filtered tabs API, forms at most 720px wide. Each route keeps its own gate and tests.
5. **WHIT-807 Page patterns.** `PageHeader` to its card; a list pattern (header + `DataTable` with its states, full width) and a record pattern (header + `DescriptionList` + tabs with a `Timeline`, capped at 1280). Rolled out in batches.

### Phase 3: the agent collaboration layer

6. **WHIT-808 Components.** `ActionProposal` (five states; two changes then Show all; permissions used; undo window or "Can't be undone"), `ApprovalQueue`, `ScopeGrant`, `UndoBar`, `AgentActivity`, toast with Undo; the set-down motion (200 ms, reversed on undo).
7. **WHIT-809 Approvals (A03).** Waiting · Decided · All activity; one at a time, "1 of 4 waiting · oldest first"; Approve, Edit (approve with edited arguments), Decline; toast after each. Server: add the agent's name and the person it acts for to the presented proposal.
8. **WHIT-810 AI principal (A21).** Issue an agent credential with its grant (defaults per owner decision 9), show the grant per permission, rotate and revoke. Server: read one credential's grant and change it (narrow or widen within the person's own permissions, logged).
9. **WHIT-811 Actor chips and agent activity (A23).** Audit API returns and filters the actor triple; `ActorChip` on audit rows and record timelines; the Agent activity page filtered by agent.
10. **WHIT-812 Undo.** Server design first (which operations declare an undo, how it runs, `undone` with who), then the toast and `UndoBar`. Until then cards say "Can't be undone".

**Not in this plan:** `/admin/mcp-tools` and the connect wizard (the MCP session's WHIT-754 UI half); the document editor's agent features (Editor M5+).

## 4. Decisions for the owner

Asked one at a time through the orchestrator.

- **D1 Settings structure.** The app map lists Settings as a sidebar group of four items; the owner asked to "unify settings". Recommended: one Settings entry opening a settings area with its own grouped sub-nav (Workspace, Sign-in and security, Email, Data, Advanced), and the personal account in the user menu.
- **D2 "Ask an agent" in ⌘K.** 2.1c says the last row hands the words to an agent that comes back with a proposal. Whity has no agent of its own: agents are outside MCP clients. Options: (a) build an in-product agent, (b) show the row and send the request to a connected agent as a task, (c) show the row only when one is connected, otherwise "Connect an agent". Recommended: (c) now, (a) later as a paid service.
- **D3 Undo.** No operation declares an undo today. Recommended: build WHIT-812's server design next on the server side, starting with sending email and document status changes.
