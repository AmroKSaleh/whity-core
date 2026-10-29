# Engineering handoff (v2.2)

Everything a web or Flutter team, or a coding agent, needs to build Whity screens and bring whity-core in line with the brand. `tokens.json` is the source of truth for this system; if this page and `tokens.json` ever differ, `tokens.json` wins. Every value traces back to `00-decision-register.md`.

## Files

| Path | For | What |
| --- | --- | --- |
| `tokens.json` | all | Colours (Light, Dark), type, spacing, radii, sizes, shadows, plane, motion, each with a usage note |
| `handoff/tokens.flat.json` | all | The same as plain name → value JSON |
| `handoff/web/tokens.css` | web | CSS variables for both themes (`[data-theme]`, `.dark` and the OS setting), `@font-face`, type classes, motion |
| `handoff/web/tokens.ts` | web | A typed token object |
| `handoff/web/theme.css` | web | Tailwind v4 `@theme` additions: `shadow-plane*`, `shadow-float`, the tint, status text and tint colours, `bg-blueprint-grid`, `plane-lift` |
| `components/bundle.js`, `bundle.css`, `index.d.ts` | web | Reference React 18 components (`window.Whity`), their styles and types |
| `fonts/*.woff2` | all | Noto Sans, Noto Sans Arabic, Geist Mono, Readex Pro 600 |
| `handoff/editor/whity-document.ts`, `README.md` | web | The document editor's contract (document model, blocks, marks, comments, suggestions, plugin block registry, saved blocks, props, publish checks) and its TipTap/ProseMirror mapping (section 18) |
| `handoff/flutter/lib/whity_brand.dart` | Flutter | `WhityBrand` ThemeExtension: plane, plane deep, mark sheet, blueprint, plane shadows, logo sizes, durations. Use beside `whity_tokens` |
| `handoff/flutter/pubspec_fonts.yaml` | Flutter | Font entries |
| `handoff/icons/ink/*.svg`, `paper/*.svg`, `icons.json` | all | The 116 icons for light and dark grounds, their Tabler names and which mirror |
| `handoff/logos/` | all | Every cut and colourway (SVG), the lockups, the app icon, `favicon.svg` and `favicon.ico`, PNG exports, Built on Whity |
| `handoff/motion/approve-set-down.json` | all | The set-down transition (200 ms) |

## Web (Next.js, Tailwind v4, shadcn)

1. Replace the values in `@amroksaleh/tokens` (soon `@whity/tokens`) with `tokens.json` v3: it adds the status text and tint colours, the selected tint, the chart palette, the control sizes and the motion tokens, and fixes contrast (see the table below).
2. Import `handoff/web/theme.css` after it for the plane, the soft float shadow and the blueprint utilities.
3. Restyle `packages/ui` to the components here: class names and states in `bundle.css` are the reference. Keep real semantics: `<button>`, `role="switch"`, `aria-current`, `aria-pressed`, `aria-sort`, `aria-expanded`.

## Flutter

Add `whity_brand.dart` next to `whity_tokens`, register `WhityBrand.light` / `.dark` in `ThemeData.extensions`, and draw the plane with `WhityBrand.planeShadow(...)`. Offsets are physical (`Offset(-3, 3)`): the plane never mirrors in RTL. Use tabular figures (`FontFeature.tabularFigures()`) for numbers.

## Changes whity-core needs

| # | Where | Change | Decision |
| --- | --- | --- | --- |
| 1 | `src/design/tokens/base.json` | Adopt tokens v3: `muted-foreground` oklch(0.52), `input` oklch(0.66) / dark oklch(0.52), the status triplets, `primary-tint`, `divider`, `table-header`, `chart-2…6`, `plane-deep`, `mark-sheet`, the blueprint colours; regenerate CSS, JSON and Dart | 2.5, 2.6, 2.7 |
| 2 | Type | 14px default (`text-sm`), titles 24 / 20 / 18 at 600, no 700; Readex Pro only for brand headlines and document titles | 3.1–3.6 |
| 3 | `button`, `input`, `select` | Heights 36 (28 small, 40 large), corners ¼ of the height; the outline secondary button; the plane on the default button only | 4.1, 4.2, 7.1 |
| 4 | `badge`, `alert`, nav, tabs | Tinted badges and alerts from the status tints; selected nav and rows on the tint; tabs underlined. Remove Tailwind palette classes (`blue-500`, `emerald-600`, `purple-…`) | 7.3, 7.4 |
| 5 | `card`, menus, dialogs | Cards: 1px border, no shadow. Floating layers: `shadow-float`. | 4.4, 4.6 |
| 6 | `data-table.tsx` | Checkbox selection with the bulk bar (count, money total, actions), sort, sticky header, row menu, totals row, loading/empty/error | 7.6 |
| 7 | App shell | 240px sidebar, 56px rail below 1024px, drawer below 768px; the tenant logo slot and switcher; Built on Whity optional | 4.5 |
| 8 | Icons | Agent icon to Tabler `robot-face`; 16px icons at stroke 2.25 | 5.1, 5.2 |
| 9 | brand.whity.dev | Replace the logo files with `handoff/logos/`; list Whity Blue as `#0961bb` | 1, 2.1 |
| 10 | Server | Agent proposals: when a tool call needs a permission set to "Ask first", store a proposal (tool, arguments, diff, scope, undo window) instead of executing; approve executes it as the granting person's delegate; Undo while the tool's window is open | 7.5 |
| 11 | Server | Per-permission mode (off · ask · allow) on AI principals, stored with the credential's scope | 7.5 |
| 12 | Whity Render | The `Doc` rules and the 13 templates; amount in words; JoFotara QR and reference on fiscal documents | 6 |
| 13 | npm, GitHub | Move `@amroksaleh/*` packages and repos to a Whity organisation | |

## Component map

| Here | In packages/ui |
| --- | --- |
| Button, IconButton, Tooltip | `button.tsx`, `tooltip.tsx` |
| Input, Textarea, Select, Combobox, Checkbox, RadioGroup, Switch | `input.tsx`, `textarea.tsx`, `select.tsx`, `command.tsx`, `checkbox.tsx`, `radio-group.tsx`, `switch.tsx` |
| DatePicker | `calendar.tsx` + `popover.tsx` |
| MoneyInput, OtpInput, SearchField, TenantLogo, TenantSwitcher | new (MoneyInput beside `money/`) |
| Tabs, Breadcrumb, Pagination, Segmented | `tabs.tsx`, `breadcrumb.tsx`, `pagination.tsx`, `toggle-group.tsx` |
| PageHeader, AppShell | `page-header.tsx`, `app-sidebar.tsx`, `page-shell.tsx`, `top-nav.tsx` |
| DataTable | `data-table.tsx` |
| Card, Dialog, ConfirmDialog, Drawer, Menu, Popover | `card.tsx`, `dialog.tsx`, `alert-dialog.tsx`, `sheet.tsx`, `dropdown-menu.tsx`, `popover.tsx` |
| Toast, ToastRegion | `sonner` / `toast.tsx` |
| Badge, Alert, EmptyState, Skeleton, Avatar | `badge.tsx`, `alert.tsx`, `empty-state.tsx`, `skeleton.tsx`, `avatar.tsx` |
| DescriptionList, Timeline, Money, PluginCard | new, `money/`, `plugin-card.tsx` |
| Mark, Lockup, BuiltOnWhity, Blueprint, Icon | new |
| ActorChip, ActionProposal, AgentActivity, ScopeGrant, ApprovalQueue, UndoBar | new (need server items 10 and 11) |
| Doc, DocPages, DocTable, DocKpis, DocBars, DocNote, DocSign, DocAmount, DocPreview | Whity Render (new) |
| ButtonGroup, SplitButton, ToggleGroup | `button-group.tsx` (new), `dropdown-menu.tsx`, `toggle-group.tsx` |
| PhoneInput, FileUpload | new |
| CommandPalette, Stepper | `command.tsx` in a dialog, new |
| BarChart, LineChart, DonutChart | new (or Recharts styled to these specs; `chart.tsx` if present) |
| AgentRunStatus, PlanGate | new |

## Agent brief

When you build a Whity screen: read `README.md` first; use the tokens, never raw colours; Noto Sans at 14px, never below 11px, never 700; one filled main button per view, and the plane only under it, approved agent work and hovered clickable cards; controls 36px with ¼ corners; cards bordered, floating layers with `shadow-float`; Tabler icons by the names in `icons.json`; every agent action attributed with `ActorChip` and either allowed by its scope or shown as an `ActionProposal`; Undo instead of "Are you sure?" where it can be undone; Western digits and JOD to 3 decimals; the name always "Whity" in Latin letters; fictional names or placeholders for companies, plans and prices.
