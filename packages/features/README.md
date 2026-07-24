# @amroksaleh/features

Client-safe, data-source-agnostic feature UI extracted from `web/` — reusable
by non-Next clients (a Tauri/Vite SPA, a future Flutter app) so they inherit
web's feature updates instead of re-porting them. This package is the pilot
for that extraction pattern; see the root `CHANGELOG.md` philosophy in
`packages/ui` for the sibling zero-Next-deps guarantee this package shares.

**Hard rule**: nothing in `src/` imports `next/*`. Components never fetch
data directly — every data access goes through a caller-injected adapter
interface. CI enforces the import rule (`.github/workflows/publish-features.yml`,
mirroring `packages/ui`'s guard).

## App-shell nav contract

`AppSidebar` (from `@amroksaleh/ui/app-sidebar`, shipped in PR #598) already
takes plain `groups`/`items` data and an injectable `linkComponent` — no
`next/navigation` baked in. This package formalizes the config a client
authors once, on top of that:

```tsx
import { resolveNavGroups, type NavConfig, type NavLinkAdapter } from "@amroksaleh/features/nav"
import { AppSidebar } from "@amroksaleh/ui/app-sidebar"

// 1. Author the nav once, as plain data (see `exampleNavConfig` for a full example):
const navConfig: NavConfig = {
  groups: [
    { id: "plugins", label: "Plugins", items: [
      { id: "demo-catalog", label: "Demo Catalog", href: "/demo-catalog", activeMatch: "/demo-catalog/*" },
    ]},
  ],
}

// 2. Supply a link adapter — next/link on web, a hash-router <a> on a Vite SPA:
const NextLinkAdapter: NavLinkAdapter = ({ href, children, ...props }) => (
  <Link href={href} {...props}>{children}</Link>
)
const HashLinkAdapter: NavLinkAdapter = ({ href, children, ...props }) => (
  <a href={`#${href}`} {...props}>{children}</a>
)

// 3. Resolve against the current path (however each client sources it) and render:
<AppSidebar
  groups={resolveNavGroups(navConfig, currentPath, t)}
  linkComponent={NextLinkAdapter /* or HashLinkAdapter */}
/>
```

`resolveNavGroups` is the only logic this contract adds: translating
`translationKey` labels through an injected `t()` (defaults to identity —
literal-string labels — when a client has no i18n layer yet) and marking the
active item against the caller's current path. RTL needs nothing extra:
`AppSidebar` is already fully bidi-aware (logical `start-`/`end-*` Tailwind
classes, `rtl:` variants), so a config never carries direction-specific data.

See `src/nav/example-nav-config.tsx` for a complete, working reference.

## Pilot feature: DemoCatalog

`DemoCatalogList` / `DemoCatalogDetail` are the pilot extraction: a small,
deliberately generic list/detail feature (NOT modeled on any real product
domain — see the `DemoCatalog` plugin in `plugins/DemoCatalog`) proving the
whole pattern end to end:

```tsx
import { DemoCatalogList, type DemoCatalogAdapter } from "@amroksaleh/features/demo-catalog"

// Implement once per data source. web/ wires this to the DemoCatalog plugin's
// REST API via its own api-client; a desktop client wires the same interface
// to local SQLite.
const adapter: DemoCatalogAdapter = {
  list: () => fetch("/api/v1/demo-catalog/items").then((r) => r.json()).then((b) => b.data),
  get: (id) => fetch(`/api/v1/demo-catalog/items/${id}`).then((r) => (r.ok ? r.json().then((b) => b.data) : null)),
  save: (input) => fetch(input.id ? `/api/v1/demo-catalog/items/${input.id}` : "/api/v1/demo-catalog/items", {
    method: input.id ? "PATCH" : "POST",
    body: JSON.stringify(input),
  }).then((r) => r.json()).then((b) => b.data),
}

<DemoCatalogList adapter={adapter} onSelect={(id) => navigate(`/demo-catalog/${id}`)} onCreate={() => navigate("/demo-catalog/new")} />
```

See `web/lib/demo-catalog-adapter.ts` for the real web-side implementation,
and `packages/spa-harness` for a minimal Vite SPA proving the same
components render with an in-memory adapter and a hash-router link adapter —
no Next.js anywhere in the render path.
