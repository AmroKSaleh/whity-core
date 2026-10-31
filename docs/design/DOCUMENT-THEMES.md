# Document themes: design (grounding pass)

Status: **proposal for owner review** (WHIT-731). Sibling: `DOCUMENT-AUTOMATION.md` (WHIT-740), plugins creating and rendering documents from templates. Nothing here is built, and no implementation PR opens until the owner approves this document and the register additions in section 9. Written 31 Oct 2026 against `origin/develop` at `0b3e2d4e` (M4b merged; M4c branch has no commits yet).

A document theme decides how a document looks in the editor's page view, in the continuous sheet and in the PDF Whity Render makes. This document proposes how tenants, people and plugins customise that look, through the API and the plugin SDK, without breaking the decision register.

## Summary

- **A theme is data, never CSS.** It is a small, closed set of document tokens with a JSON Schema. Every value is an enum or a validated colour; core maps it to CSS custom properties. Plugins and people can't add keys or ship stylesheets.
- **The register keeps most of the look fixed** (2.8, 3.1–3.7, 6.1–6.7, E.11, E.33, E.35). So v1 themes are deliberately thin: the print colour, the letterhead's logo, and a few options that each need a register decision first (section 9). The bigger win is structural: one resolved token set shared by the editor and Whity Render, which today disagree (section 1.4).
- **Inheritance:** Whity base → plugin or system theme (optional) → workspace theme → document overrides. Personal themes exist for personal documents only.
- **Published means frozen.** Publishing a template stores its resolved theme with the version, as synced saved blocks are pinned (E.38). Changing a theme reaches printed documents only when a person republishes.
- **`documents.print_color` moves into the workspace's default theme** and the setting is removed (no-legacy rule).
- **Plugins** declare themes through a new SDK interface, `PluginDocumentThemesInterface`. The existing `PluginThemeInterface` (app colours over `GET /api/theme`) is unrelated and stays as it is.
- **Sequencing:** the token and Whity Render type work (phase 1) can start now; the model and API (phase 2) wait for M4c to merge, because both touch `public/index.php`, `CoreApiSchemas.php` and `DocumentPublishingApiHandler.php`.

## 1. What exists today

### 1.1 Document model (M2–M4b)

- `document_sources` (migration 160) holds templates, letters, reports, notes, wiki pages and label sheets, told apart by `kind` (`DocumentSourcesApiHandler::KINDS`, line 56). Columns: `title`, `lang`, `default_view`, `full_width`, `page` JSONB, `record_type`, `blocks` JSONB, `draft_version`, `published_version`, `scope`, `required_permission`, owner and actor columns. Migration 163 adds `own_fields`, `starter_key` and `legacy_template_id`.
- **No style, theme, font or colour column exists**, on the table or in the contract. `page` is `{ size, marginMm: 16, letterhead: boolean }` plus `labels` (`whity-document.ts:42`). The only mark with a colour is `highlight`, which has one colour (E.25).
- `document_source_versions` (162) is insert-only and copies `title`, `page` and `blocks` at publish.
- Concurrency: `ETag: "<draft_version>"`, `If-Match` required on `PUT /draft` only. A missing If-Match is 428, a stale one 409 with the current version (`DocumentSourcesApiHandler.php:205-229`). There is no 412 in the codebase; this design follows the same 428/409 convention.
- **Starters** are `document_sources` rows with `starter_key` and `scope='system'`, seeded once per tenant (`DocumentSourceStarterSeeder.php`).
- **The letterhead isn't stored.** A document has the `page.letterhead` switch; the content (seller name or site name, address, tax id) is built per tenant at request time in `DocumentPublishingApiHandler::letterhead()` (lines 540-556). It returns **no logo**, so PDFs have none, against 6.2.

### 1.2 Styling that exists

| Thing | Where | Notes |
| --- | --- | --- |
| Print colour | `documents.print_color` (`SettingsRegistry.php:346-350`), `#RRGGBB` or empty for the brand colour, tenant-overridable | Served as `accent` by `GET /api/document-fields`; editor sets `--doc-accent` inline (`page-view.tsx:201-217`); sent to Whity Render as `theme: {accent}` (`WhityDocumentPrinter.php:115-117`) |
| Render-service theme | `render-service/src/flow/document.js:101-103`: `THEME_KEYS = ink, muted, accent, line, tint`, hex only | Becomes `--flow-*` variables (`html.js:510-520`). Core sends `accent` only. `muted`, `line` and `tint` would break 6.3 if exposed to tenants. |
| App theme overrides | `PluginThemeInterface` (SDK 1.12), `GET /api/theme` (`ThemeApiHandler.php`) | One plugin, hex colours, keys limited to `src/design/tokens/generated/theme.json`. App UI only, not documents. |
| Branding | `BrandingService`, keys `branding_logo_wide`, `branding_logo_square`, `site_name` | Logo reaches the editor's letterhead through `TenantLogo`, not the PDF. |
| Mail colour | `mail.brand_color` | Separate, out of scope. |

There is no core setting for the tenant's main colour; `09-white-label.md` describes one (`Whity.tenantTheme`, 2.8) that isn't built in the backend.

### 1.3 Tokens

`src/design/tokens/base.json` (v3.0.0) is the source; `generate-tokens.js` writes `web/app/globals.css`, `packages/tokens/generated/*` (published as `@whity-org/tokens` by `publish-tokens.yml` on pushes to `main` that change `packages/tokens/**` with a new version) and the Flutter tokens. It rejects non-OKLCH colours, mismatched light and dark keys and any weight ≥ 700. Fonts are self-hosted woff2 in `packages/tokens/fonts`, including Readex Pro 600 Latin and Arabic. **There is no document or print token group**: the print scale lives in prose (`16-documents.md`) and as px literals in `packages/ui/src/editor/editor.css:314-377`.

### 1.4 Rendering, and where it disagrees with the register

- Editor: `editor.css` follows E.33 (16px sheet, 24/20/18px headings; page view 9.5pt body, 14/12/10.5pt headings, title Readex Pro 600 at 22pt in `--doc-accent`). Sizes are literals.
- Whity Render: core sends a flow payload built by `WhityDocumentPrinter::payload()`, not the WhityDocument and not the `/render/document` route IMPLEMENTATION-NOTES 3.7 proposed (it doesn't exist). `flow.css` sets a 10.5pt body with 16/13/11.5pt headings and sizes down to 6.5pt. No `@font-face`: the container's Debian `fonts-noto-core` is used, the stack starts with Noto Naskh Arabic, Latin falls to DejaVu Sans, Readex Pro is missing, and code uses DejaVu Sans Mono. `doc.fontFamily` is read in `html.js:508` but never set.

So today the page view and the PDF differ in type, size and logo. **A theme system that only adds options on top would make that worse.** Phase 1 fixes it first.

### 1.5 Plugin SDK, OpenAPI, MCP, RBAC

- SDK `whity/plugin-sdk` 1.46.0: capability interfaces checked with `instanceof` in `PluginLoader` (settings, roles, entitlements, MCP tools, theme…). No declarative manifest; metadata is the PHP class.
- OpenAPI: routes declared in `CoreApiSchemas::*Routes()` with `permissionRoute()`, components in `components()`; `OpenApiSpecDriftTest` and `RouteCatalogueCompletenessTest` keep the spec honest.
- MCP: `ToolDeriver` exposes every gated route as a tool, names from `operationId`, read-only and destructive hints from `ToolAnnotations`; `ToolArgumentDescriptionsTest` (WHIT-711) needs a description on every body and query argument.
- RBAC: `resource:action`, two segments only (`PermissionRegistry::isValidPermission`, line 213). New slugs ship with a grant migration to roles that already hold an anchor permission (pattern: migration 156; `ci-grant-by-role-name-guard`).

## 2. Where themes fit in the plan

PLAN.md has no theme milestone. Themes touch M4 (print, letterhead), M7 (plugin SDK) and M8 (Arabic and type). Proposal:

| Phase | What | When | Conflicts |
| --- | --- | --- | --- |
| 1 · Document tokens | A `document` group in `base.json` (screen and print scales, E.33 and 6.5); the editor uses it; Whity Render embeds the token fonts and the same values; the logo reaches the PDF letterhead | Now, beside M4c | Low: `base.json`, generator, `editor.css`, `render-service/`. The logo fix touches `DocumentPublishingApiHandler::letterhead()`, so land it after M4c or hand it to M4c. |
| 2 · Theme model and API (M4d) | Table, resolver, publish freezing, routes, OpenAPI, MCP, permission, `print_color` migration | After M4c merges | `public/index.php`, `CoreApiSchemas.php`, `openapi.json`, `schema.d.ts`, `DocumentPublishingApiHandler.php`, migration numbering (after M4c's) |
| 3 · UI | Theme picker in document settings; a Document themes screen under workspace settings | After phase 2, and after cards exist | Needs design cards first (CLAUDE.md: compare with screenshots) |
| 4 · SDK | `PluginDocumentThemesInterface`, loader validation, sandbox `init` tokens | With M7 (plugin blocks), same SDK release | `PluginLoader.php`, `sdk/` |
| 5 · Type options | Curated font pairs (WHIT-558), large print scale | With M8 | Register decisions T.3, T.4 |

M4c moves creation, rerender and QR onto sources and deletes the old designer and canvas route. Nothing here needs M4c to change. One coordination point: once versions carry a theme snapshot (4.4), M4c's rerender from a source version picks it up with no further work, as long as rerender reads the version row rather than the live tenant settings. Worth a line in M4c's PR.

## 3. Token model

### 3.1 Two layers

1. **Document base tokens** (phase 1, not themeable): the full screen and print scales, line heights, rules, spacing, fonts. Generated from `base.json` into `packages/tokens/generated/document.css` and `document.json`, consumed by `editor.css` and by Whity Render. This is the register in code.
2. **Theme tokens** (phase 2): the few values a theme may set. Each one maps onto base tokens through a fixed table in core. A theme never names a CSS property.

### 3.2 Theme tokens, v1

`DocumentThemeTokens` is a closed object (`additionalProperties: false`). All keys optional; missing keys inherit.

| Key | Type | Default | Register | Maps to |
| --- | --- | --- | --- | --- |
| `accent` | colour, `#rrggbb` or `oklch()` | the workspace main colour, else Whity Blue | 6.3, 2.8a | `--doc-accent` (title only). Must reach 4.5:1 on white; if not, lightness is lowered as in 2.8a and the response says what changed. |
| `accentOnDark` | colour | derived from `accent` at L 0.70 (2.8b) | 2.8b, E.11 | Title colour in the continuous sheet in dark mode only. Never printed. |
| `letterhead.logo` | `wide` · `square` · `none` | `wide` | 6.2 | Which branding asset the letterhead prints at the start side |
| `letterhead.lines` | `address`, `taxId`, `phone`, `email` (ordered subset, max 4) | `address`, `taxId` | 6.2 | Which tenant lines show under the name |
| `accentUse` | `title` · `title-and-headings` | `title` | **needs T.1** | Whether H1–H3 also take the accent |
| `titleFace` | `display` · `body` | `display` | **needs T.2** | Readex Pro 600 (3.1, E.35) or Noto Sans 600 for the title |
| `fontPair` | key from a curated catalogue | `noto` | **needs T.3**, WHIT-558 | Latin and Arabic body families together |
| `scale` | `standard` · `large` | `standard` | **needs T.4** | 9.5pt body (6.5) or a large-print step |

Not themeable, ever, in v1: margins (6.1), table rules (6.6), totals layout, footer and QR (6.7), greys (2.3), status colours, the highlight (E.25), callout kinds (E.14), weights (no 700), line heights and Arabic size (3.6, 3.7a), digits (3.7b). If a theme sets anything outside the table, the request is a 422 naming the key.

`page.size` stays where it is, in the document's `page` object: it's a property of the document, not of its look.

### 3.3 Resolution

```
Whity base (code)                        whity.standard
  └ base theme (system or plugin)        e.g. acme_invoicing.classic       optional
      └ workspace theme (tenant row)      "Example Bakery"                 optional
          └ document overrides            WhityDocument.theme.overrides    accent and letterhead.* only
```

- A document names one theme in `theme.ref`; with no ref it uses the workspace default for its kind, then the workspace default, then `whity.standard`.
- A tenant theme `extends` exactly one system or plugin theme (or none, meaning `whity.standard`). One level only: tenant themes don't extend tenant themes. That keeps resolution cheap and "where does this value come from" answerable in one line in the UI.
- Document overrides are limited to `accent` and `letterhead.*`. Anything bigger is a new theme.
- The resolver (`DocumentThemeResolver`, PHP) returns `ResolvedDocumentTheme { ref, version, hash, tokens, sources }`, where `sources` says per key which layer set it. The same resolver serves the editor (through the API) and printing, so the two can't drift.
- Contrast and derivation (2.8a/b) run in the resolver, not in the client. The OKLCH maths already exists in TS (`Whity.tenantTheme`); a PHP port is part of phase 2.

### 3.4 Scopes

| Scope | Who creates | Who can use it | Stored |
| --- | --- | --- | --- |
| `system` | Whity (code) | everyone | not stored; `whity.standard`, later maybe `whity.compact` |
| `plugin` | an enabled plugin (code) | everyone in tenants where the plugin is on and entitled | not stored, like the block registry (IMPLEMENTATION-NOTES 3.1) |
| `tenant` | holders of `documents:themes` | everyone in the workspace | `document_themes` row |
| `personal` | anyone with `documents:write` | the owner, on documents in `personal` scope only | `document_themes` row with `owner_profile_id` |

Personal themes stay narrow on purpose: a shared or published document never depends on something only one person can see or change. The publish check (E.37) gains a blocker for a personal theme on a template, with "Use the workspace theme" as the fix.

A person's reading preferences (for example a larger screen sheet) are not themes; they never print, and belong with M8's accessibility work if wanted.

## 4. Data model

### 4.1 `document_themes` (phase 2, next free migration after M4c)

| Column | Type | Notes |
| --- | --- | --- |
| `id`, `tenant_id`, timestamps | | Registered in `CoreTables.php`; literal tenant predicate on every query; a case in `CrossTenantRejectionRealEngineTest` |
| `key` | VARCHAR(64) | Unique per tenant, `^[a-z0-9_]{1,40}$`; the API addresses themes by `key` (`tenant.<key>` in refs) |
| `name`, `name_ar` | VARCHAR(120) | Both required (every visible string has an Arabic label) |
| `scope` | VARCHAR(16) | `tenant` · `personal` |
| `owner_profile_id` | BIGINT null | Set for `personal` |
| `extends` | VARCHAR(100) null | `whity.standard` or `<plugin>.<key>` |
| `tokens` | JSONB | Partial `DocumentThemeTokens` |
| `version` | INT | Bumped on every change; the ETag |
| `created_by`, `updated_by` | actor columns | As in IMPLEMENTATION-NOTES 3.1 |
| `archived_at` | TIMESTAMP null | Archive instead of delete while published versions reference it |

Tenant defaults are settings, not columns: `documents.theme` (default ref) and `documents.theme_by_kind` (JSON map of kind → ref), tenant-overridable in `SettingsRegistry`. A data migration moves a non-empty `documents.print_color` into a new tenant theme `workspace` with `tokens.accent` set, makes it the default, and removes the setting.

### 4.2 Contract change

`whity-document.ts` (and `packages/ui/src/editor/contract.ts`, `CoreApiSchemas`):

```ts
export interface DocumentThemeRef {
  ref?: string;                       // 'whity.standard' | '<plugin>.<key>' | 'tenant.<key>' | 'personal.<key>'
  overrides?: Pick<DocumentThemeTokens, 'accent' | 'letterhead'>;
}
// WhityDocument gains:
theme?: DocumentThemeRef;
```

Stored as a new `theme` JSONB column on `document_sources` and `document_source_versions`, written by the same `PUT …/draft`. It rides the existing ETag and If-Match, needs no new write route on sources, and adds only one field to the handler M4c is also changing.

### 4.3 Freezing

- `document_source_versions` gains `theme_resolved` JSONB: the full `ResolvedDocumentTheme` at publish time.
- `POST …/render` for a published version uses `theme_resolved`, never the live theme. A draft preview uses the live resolution.
- Issued documents keep the version they were made from (M4c moves issuing onto sources), so a rerender prints exactly the same.
- Editing a theme shows "Used by 4 published templates. They change at their next publish." (`GET /api/document-themes/{key}/usage`), mirroring E.38.

### 4.4 Plugin themes switched off

- Drafts: resolve to the workspace default and show a notice ("Needs the Acme Invoicing plugin"), keeping the `ref`.
- Publish check: blocker, like E.19.
- Published versions: unaffected; they print from `theme_resolved`.

## 5. REST API and OpenAPI

All under `/v1`, `{"data": …}` / `{"error": …}`, declared in a new `CoreApiSchemas::documentThemeRoutes()`; `public/openapi.json` and both `schema.d.ts` regenerated.

| Route | Permission | Notes |
| --- | --- | --- |
| `GET /api/document-themes` | `documents:read` | System, enabled plugin, tenant and the caller's personal themes. `?scope=`, `?kind=` (themes suited to a kind). Paginated. |
| `GET /api/document-themes/schema` | `documents:read` | The token schema with allowed values and their English and Arabic labels, so the UI, plugins and agents build forms from one source |
| `GET /api/document-themes/{ref}` | `documents:read` | Definition plus `resolved`; `ETag: "<version>"` for stored themes |
| `POST /api/document-themes` | `documents:themes` (tenant) or `documents:write` (personal) | 201 with `ETag: "1"` |
| `PATCH /api/document-themes/{ref}` | as above, and owner for personal | `If-Match` required: 428 missing, 409 stale with `{version}` |
| `POST /api/document-themes/{ref}/archive`, `…/restore` | as above | Archiving a default or a theme used by drafts is allowed with a warning; hard delete only when unused (`DELETE` → 409 with usage otherwise) |
| `GET /api/document-themes/{ref}/usage` | `documents:read` | Drafts and published versions using it |
| `POST /api/document-themes/resolve` | `documents:read` | Body `{ ref?, overrides?, kind? }` → `ResolvedDocumentTheme` plus `adjustments` (for example "accent darkened to reach 4.5:1"). Read-only; powers live preview |
| `GET /api/document-sources/{id}` | existing | Adds `theme` and `resolvedTheme` to the projection |
| `PUT /api/document-sources/{id}/draft` | existing | Accepts `theme` |
| Tenant defaults | existing settings routes, `settings:write` → **proposed `documents:themes`** | `documents.theme`, `documents.theme_by_kind` |

Components: `DocumentTheme`, `DocumentThemeInput`, `DocumentThemePatch`, `DocumentThemeTokens` (closed), `DocumentThemeRef`, `ResolvedDocumentTheme`, `DocumentThemeSchema`, `DocumentThemeAdjustment`, `DocumentThemeUsage`. Every body property gets a `description` (WHIT-711 guard).

`GET /api/document-fields` stops returning `accent`; the editor reads `resolvedTheme` instead.

## 6. MCP and agents

- Read routes (`list`, `schema`, `get`, `usage`, `resolve`) are derived tools as usual, annotated read-only.
- Write routes refuse agent principals, the same rule as `PUT …/draft` (E.4: agents never change anything directly). The derived tools still exist for people's own MCP clients acting as themselves.
- Mechanism: the declarative `agentPolicy` (`allow` · `refuse` · `suggest-only` · `propose`) and `toolset` keys proposed by the MCP tool-surface design, set on the route schema and enforced at the route for any agent principal, so REST and MCP behave the same. Theme reads sit in a `documents.themes` toolset (visibility `discoverable`); theme writes are `refuse` now and the document's theme becomes `suggest-only` with M6.
- For agents, a document's theme changes through a suggestion: a new patch type `set-theme` (`{ ref?, overrides? }`) in IMPLEMENTATION-NOTES 3.3, created by an authored tool `document_suggest_theme` in M6. Changing a workspace theme by an agent goes through `agent_proposals` (section 12 item 10) once that exists; until then it isn't offered.

## 7. Plugin SDK

### 7.1 PHP

```php
interface PluginDocumentThemesInterface
{
    /** @return list<DocumentThemeDefinition> */
    public function getDocumentThemes(): array;
}

final class DocumentThemeDefinition
{
    public function __construct(
        public readonly string $key,              // ^[a-z0-9_]{1,40}$, published as '<plugin>.<key>'
        public readonly string $name,
        public readonly string $nameAr,
        public readonly DocumentThemeTokens $tokens,
        public readonly array $kinds = [],        // DocumentKind values it suits; empty = all
        public readonly ?string $entitlement = null, // gate behind the plugin's own entitlement
    ) {}
}
```

- `DocumentThemeTokens` is a typed builder in the SDK (`->accent('#b74c05')->logo(Logo::Square)`) that can only express the closed set, so most mistakes fail at the plugin author's desk.
- `PluginLoader` validates every definition against the same schema the API uses. An invalid theme is dropped with a logged reason (visible in the admin plugins API next to quarantine reasons, WHIT-437); the plugin keeps loading.
- Plugin themes extend `whity.standard` only. A tenant theme can extend a plugin theme.
- Starters a plugin ships (WHIT-687) can name a plugin theme in `theme.ref`.
- SDK minor bump (additive); covered by the conformance kit when WHIT-272 lands.
- **The definition is data.** Its canonical form is JSON (`{key, name, nameAr, extends, tokens, kinds, entitlement}`) validated by the same JSON Schema as `GET /api/document-themes/schema`; the PHP class is a typed way to produce it. A plugin can instead ship `resources/document-themes/*.json` and core validates and loads them without running plugin PHP. The same JSON is the export format: a workspace theme exports to a file a code plugin (or the no-code builder, `WHITY-BUILDER.md`) can ship unchanged.
- Plugins contribute data only, never MCP tools for themes: core's `documents.themes` tools expose plugin themes like any other.

What plugins can't do in v1: add token keys, ship CSS, ship fonts. Fonts go through the core catalogue (WHIT-558) so every font is self-hosted, licensed, has an Arabic partner and is embedded by Whity Render. A plugin asking for a new key is a register conversation, not an SDK feature.

### 7.2 Front end

- Native first-party blocks read `--doc-*` variables; `packages/ui` exports a `DocumentThemeProvider` and `useDocumentTheme()` that sets them from `resolvedTheme`.
- The sandboxed plugin frame (IMPLEMENTATION-NOTES 3.6) already receives `theme` and the token CSS text in `init`; it gains the document token CSS for the current resolved theme, and `env-changed` fires when it changes.
- Plugin print views return blocks from the closed print set (3.7), so plugin blocks print in the document's theme with no extra work.

## 8. Rendering

### 8.1 Editor

- `editor.css` replaces its literals with `--doc-*` tokens from `document.css` (phase 1).
- `DocumentThemeProvider` on the editor root writes the theme's variables; `page-view.tsx` stops setting `--doc-accent` from a prop.
- Page view stays white in dark mode (E.11) and uses `accent`. The continuous sheet follows the app theme and uses `accentOnDark` on dark.
- Changing the theme in document settings is a draft change: it bumps `draft_version` and is undoable.

### 8.2 Whity Render

- The container gets the token fonts from `@whity-org/tokens` (Noto Sans, Noto Sans Arabic, Readex Pro 600 Latin and Arabic, Geist Mono) as `@font-face` with local file URLs, replacing the Debian font stack, and `flow.css` takes its sizes from the generated document tokens (phase 1). This is the fix for 6.5 and E.33 in print, and it's needed with or without themes.
- The payload's `theme` grows from `{accent}` to the resolved theme's render keys: `accent`, `titleFace`, `fontPair`, `scale`, plus `logo` (data URI or an internal URL with the render secret) and letterhead lines through the existing letterhead block. `THEME_KEYS` becomes an enum-checked list; `muted`, `line` and `tint` are no longer accepted from callers (they become base tokens).
- `WhityDocumentPrinter` takes a `ResolvedDocumentTheme` rather than an accent string.

## 9. RTL, Arabic and type

- Every option has English and Arabic labels in `GET /api/document-themes/schema`.
- Fonts come in **pairs**: a catalogue entry is a Latin family and an Arabic family that work together, both self-hosted, both with a 400, 500 and 600 and no 700. A pair without full Arabic coverage isn't accepted into the catalogue. The default pair is Noto Sans and Noto Sans Arabic.
- Arabic titles use Readex Pro's Arabic design when `titleFace = display` (E.35); with `body` they use the pair's Arabic family at 600.
- Arabic stays at 100% of the Latin size (3.7a) and takes its own line heights (1.7 body, 1.5 titles) from base tokens; no theme can change that, and no theme can add letter spacing to Arabic.
- The letterhead uses logical start and end (6.2, 6.4) whichever language leads; Latin values inside Arabic stay isolated with `bdi`. Western digits in every language (3.7b).
- A theme has no direction. Direction comes from `doc.lang` and per-block `dir`, as today.

### Register additions to decide (drafts, not yet in the register)

| # | Decision | Proposed value |
| --- | --- | --- |
| E.44 | Document themes | A document's look comes from a theme: Whity's standard theme, a plugin's, or the workspace's own. A theme sets only the print colour, the letterhead's logo and lines, and the options decided in T.1–T.4; everything else follows stage 6 and E.33. A published template keeps the theme it was published with until it's published again. |
| T.1 | Accent on headings | Option, off by default: H1–H3 in the print colour. Note: this relaxes 6.3 ("title only"). Recommendation: **don't add it** in v1. |
| T.2 | Title face | Option: Readex Pro 600 (default) or the body family at 600. Recommendation: **add it**, for tenants whose brand isn't Whity's. |
| T.3 | Font pairs | A curated catalogue of Latin and Arabic pairs (WHIT-558), self-hosted, no 700. Recommendation: **add it with M8**, starting with two or three pairs. Note: `09-white-label.md` says type "stays". |
| T.4 | Large print | Option: a body of 11pt with headings scaled by the same steps. Recommendation: **add it** (accessibility; helps Arabic). |

## 10. Permissions

| Slug | Meaning | Status |
| --- | --- | --- |
| `documents:read` | List, read and resolve themes | exists |
| `documents:write` | Create and edit personal themes; set a document's theme | exists |
| `documents:themes` | Create, edit and archive workspace themes; set the workspace defaults | **new**, granted to roles holding `documents:publish` (grant migration in the 156 pattern) |

`documents:themes:manage` would match `documents:library:manage` in IMPLEMENTATION-NOTES 3.5, but `PermissionRegistry::isValidPermission` accepts two segments only, so `documents:read:all` and any three-part slug need a look before they're relied on (task in the breakdown).

Theming is part of the free core. A plugin can put its own themes behind its own entitlement (`DocumentThemeDefinition::$entitlement`).

## 11. Risks

- **Scope creep into a style editor.** The register is the guard; every new key is a register change first.
- **Two renderers drifting.** Mitigated by phase 1 (one token source) and a visual check per milestone: render the same document in the page view and through Whity Render and compare.
- **Font licensing and size.** Each catalogue pair must be OFL or similar and subset; Arabic fonts are large. Keep the catalogue small.
- **Logo in PDFs.** Passing images to the render service needs a size cap and a type check (PNG, JPEG, SVG sanitised).
- **M4c overlap.** Phase 2 waits for M4c; phase 1's logo fix touches `letterhead()`, which M4c may also touch.

## 12. Questions for the owner

1. Approve E.44 and the theme model (closed tokens, four-layer inheritance, publish freezing)?
2. T.1–T.4: which options go into v1? (Recommendations: T.1 no, T.2 yes, T.3 with M8, T.4 yes.)
3. Personal themes: keep them (personal documents only), or drop them from v1?
4. Where do themes sit in PLAN.md: phase 2 as "M4d" after M4c, SDK with M7, type options with M8?
5. Who sets the workspace default theme: the new `documents:themes`, or the existing `settings:write`?
6. The logo in the PDF letterhead (6.2) is missing today: fix it in phase 1 as its own small PR, or fold it into M4c?
7. Should the workspace main colour (2.8, `Whity.tenantTheme`) be built in the backend as part of this work, so `accent` defaults to it, or stay separate?
8. Cards: phase 3 needs a theme picker and a Document themes screen designed (extending TplAdminBranding?). Commission them before phase 3 starts?
