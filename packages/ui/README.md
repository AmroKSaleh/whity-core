# @amroksaleh/ui

Shared UI primitives for Whity products, built on Radix + Tailwind v4.

## Consuming from a non-Next.js client (Tauri, plain Vite SPA, etc.)

Every component in `src/` is plain React with zero Next.js dependency —
confirmed by a CI guard (`.github/workflows/publish-ui.yml`) that fails the
build if a `next/*` import ever appears here. `"use client"` directives exist
on the components below for Next.js/RSC's benefit only; they're inert
(harmless no-ops) outside Next, so a plain Vite/Tauri SPA can import and use
every component in this package as-is:

`alert-dialog`, `checkbox`, `data-table`, `dialog`, `dropdown-menu`, `select`,
`sheet`, `switch`, `tabs`, `tooltip`.

Everything else has no directive at all (nothing RSC-sensitive to mark).

### Getting the components

Two ways to consume this package, matching how it's used inside whity-core
itself:

1. **npm package** — `npm install @amroksaleh/ui` (published to GitHub
   Packages under the `@amroksaleh` scope) for the components' compiled
   exports and the theme (`@amroksaleh/ui/globals.css`, itself built on top of
   `@amroksaleh/tokens` — see below).
2. **shadcn registry** — for a locally-customizable, re-pullable copy of a
   component's actual source (the same mechanism whity-core's own `web/`
   package uses): add a `registries` entry to your own project's
   `components.json`:
   ```json
   {
     "registries": {
       "@whity": "https://whity.jameedium.org/r/{name}.json"
     }
   }
   ```
   then `npx shadcn add @whity/button` (or any other component name from
   `web/registry.json`). Re-run the same command to pull updates.

### Design tokens without the component library

If a client only needs the design tokens (colors, typography, spacing,
radius) — not the React components — depend on `@amroksaleh/tokens` directly
instead:

```css
@import "tailwindcss";
@import "@amroksaleh/tokens/css";
```

```ts
import tokens from "@amroksaleh/tokens"; // tokens.json
```

Flutter clients use the same source of truth via a git dependency —
see `flutter/whity_tokens/README.md` in this repo.

All three (`@amroksaleh/ui`, `@amroksaleh/tokens`, `flutter/whity_tokens`) are
generated from the same single source of truth,
`src/design/tokens/base.json` — run `npm run tokens:generate` (from `web/`)
after changing it to regenerate every target.
