# Components

The stage 7 inventory: 56 components in 8 groups. The 41 P1 components (what the Front Office demo, approvals and account flows need) were built in 2.0 and the P2 components in 2.1, each with a live card; the two P3 items are still planned. Shared looks for all of them: outline secondary buttons, the main button alone on the plane (7.1); outlined 36px inputs (7.2); selected things on the tint with text in the main colour, tabs underlined (7.3); tinted status badges (7.4).

| Group | Component | Priority | Status · card |
| --- | --- | --- | --- |
| **Foundations** | Icon (16 · 20 · 24) | P1 | Built · Icon |
| | Mark · Lockup · Built on Whity | P1 | Built · Mark, Lockup, BuiltOnWhity |
| | Tenant logo slot (image, initials) | P1 | Built · TenantLogo |
| **Actions** | Button (main, secondary, ghost, destructive, link; 28 · 36 · 40; loading) | P1 | Built · Button |
| | Icon button with tooltip | P1 | Built · IconButton, Tooltip |
| | Split button · button group | P2 | Built · SplitButton |
| | Toggle · toggle group | P2 | Built · ToggleGroup |
| **Inputs** | Text field · textarea (hint, error, counter, disabled, read-only) | P1 | Built · Input, TextFields |
| | Select · combobox (search, empty, async) | P1 | Built · Select, Combobox |
| | Checkbox · radio · switch (indeterminate) | P1 | Built · Switch, Choices |
| | Date · date range (Arabic months, Western digits) | P1 | Built · DatePicker |
| | Money input (JOD, 3 decimals) | P1 | Built · MoneyInput |
| | One-time code (6 digits, paste) | P1 | Built · OtpInput |
| | Search field (⌘K hint) | P1 | Built · SearchField |
| | Phone input (+962 default) | P2 | Built · PhoneInput |
| | File upload (drag, progress, error) | P2 | Built · FileUpload |
| | Tag input | P3 | Planned |
| **Navigation** | App shell · sidebar (rail 56, drawer under 768) | P1 | Built · TplAdminDashboard, AppShellCollapsed, AppShellMobile |
| | Top bar (search, notifications, user) | P1 | Built · in AppShell |
| | Tenant switcher | P1 | Built · TenantSwitcher |
| | Tabs (counts) | P1 | Built · Tabs |
| | Pagination | P1 | Built · Pagination (on the Breadcrumb card) |
| | Breadcrumb | P2 | Built · Breadcrumb |
| | Segmented control | P2 | Built · Preferences |
| | Command palette (⌘K, ask an agent) | P2 | Built · CommandPalette |
| | Stepper | P2 | Built · Stepper |
| **Data display** | Data table (sort, select, bulk bar, sticky header, states, totals) | P1 | Built · DataTable, DataTableSelect, DataTableStates |
| | Card · stat card | P1 | Built · Card |
| | Badge · status | P1 | Built · Badge |
| | Avatar (initials, agent, system) | P1 | Built · Avatar |
| | Money · date | P1 | Built · Money, `formatDate` |
| | Description list | P1 | Built · DescriptionList |
| | Timeline (people and agents) | P1 | Built · Timeline |
| | Tooltip | P1 | Built · Tooltip |
| | Chart (bar, line, donut, 2.7 palette) | P2 | Built · BarChart, LineChart, DonutChart (print: DocBars) |
| **Feedback and layers** | Alert (four statuses, action) | P1 | Built · Alert |
| | Toast with Undo | P1 | Built · Toast |
| | Dialog · confirm with typed name | P1 | Built · Dialog, ConfirmDialog |
| | Drawer (record side panel) | P1 | Built · Drawer |
| | Popover · menu (keyboard) | P1 | Built · Menu |
| | Skeleton · progress | P1 | Built · Skeleton |
| | Empty state | P1 | Built · EmptyState |
| | Error and system states (404, 403, 500, offline) | P1 | Built · TplSystemStates |
| **Agents** | Actor chip (person, agent for person, system) | P1 | Built · ActorChip |
| | Action proposal (waiting, approved, declined, expired, undone) | P1 | Built · ActionProposal |
| | Agent activity (filter by agent) | P1 | Built · AgentActivity |
| | Scope grant (off, ask first, allowed) | P1 | Built · ScopeGrant |
| | Approvals inbox (one at a time) | P1 | Built · ApprovalQueue, TplAgentApprovals |
| | Undo bar (the tool's window) | P1 | Built · UndoBar |
| | Agent run status | P2 | Built · AgentRunStatus |
| **Platform** | Language · theme switch | P1 | Built · Preferences |
| | Document preview (zoom, print, download, send) | P1 | Built · DocPreview |
| | Plugin card | P2 | Built · PluginCard |
| | Branding preview | P2 | Built · TplAdminBranding |
| | Plan gate · upgrade | P2 | Built · PlanGate |
| | Signature · stamp | P3 | Partly: DocSign on documents |

## Document editor

The editor's parts (shell, page view, blocks, block chrome, plugin and saved blocks, inline tools, inserting, comments and suggestions, agents, typography, Arabic) are design definitions in their own card group, with the contract in `handoff/editor/`. The Whity team builds the working editor (section 18); the components above (Menu, Popover, Tooltip, ToggleGroup, Avatar, ActorChip, AgentRunStatus, Dialog) are its parts.

## Charts

Bars at most 24px thick with a 4px rounded end, square on the baseline; lines 2px with an 8px end dot ringed in the card colour; a hairline grid; one axis only. One value is labelled (the highest bar, the line's last point), every mark has a tooltip on hover and keyboard focus, a legend appears for two or more series, and every chart can switch to a table. Colours follow 2.7 in fixed order, series 1 the tenant's colour, never cycled; text is never in a series colour. Time runs left to right in both languages (decision 2.1b). Donuts fold past six slices into "Other".

## Using them

`components/bundle.js` assigns `window.Whity` (React 18); `bundle.css` styles everything; `index.d.ts` types every prop. Every visible string has an English default and takes `labels` for Arabic. Components use logical properties, so `dir="rtl"` mirrors them. In whity-core these map onto `packages/ui` (shadcn); see the component map in section 12.
