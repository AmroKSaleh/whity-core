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
@import "@amroksaleh/tokens/css";       /* colors + typography theme */
@import "@amroksaleh/tokens/base.css";  /* shadcn/Tailwind base-reset layer */
@import "@amroksaleh/tokens/fonts.css"; /* self-hosted Noto Sans, Noto Sans Arabic, Geist Mono */
```

```ts
import tokens from "@amroksaleh/tokens"; // tokens.json
```

`./css` gives you the color/typography custom properties and the Tailwind v4
`@theme inline` mapping. `./base.css` is the same border/ring reset,
`body` background/foreground, and default font that `web/app/globals.css`
and this package's own `src/globals.css` used to hand-duplicate — import it
once instead of re-typing that `@layer base` block per client. `./fonts.css`
declares `@font-face` rules for the three type families `--font-sans` /
`--font-mono` reference, backed by the woff2 binaries in this package's
`fonts/` directory (OFL-licensed, see `fonts/LICENSE-*.txt`) — no network
fetch, no Next.js font-loader dependency. A Next.js app can skip `fonts.css`
and keep using `next/font` (as `web/` does) since it gets equivalent
self-hosting plus build-time optimization for free; `fonts.css` exists for
every client that doesn't have that pipeline (Tauri, plain Vite, Storybook).

Flutter clients use the same color/typography source of truth via a git
dependency — see `flutter/whity_tokens/README.md` in this repo. Font
*binaries* aren't bundled there yet; a Flutter app should declare its own
font assets in `pubspec.yaml` (family names must match
`typography.fontFamilyDart` in `src/design/tokens/base.json`: `Noto Sans`,
`Noto Sans Arabic`, `Geist Mono`).

All three (`@amroksaleh/ui`, `@amroksaleh/tokens`, `flutter/whity_tokens`) are
generated from the same single source of truth,
`src/design/tokens/base.json` — run `npm run tokens:generate` (from `web/`)
after changing it to regenerate every target.

### Theme mode (light/dark/system) contract

Every client should implement light/dark switching the same way so a toggle
built in one client looks and behaves identically in another:

- **Strategy**: apply/remove a `.dark` class on the root element (`<html>` on
  web) — the exact selector `@custom-variant dark (&:is(.dark *));` (in
  `@amroksaleh/tokens/css`) and every `.dark { ... }` token block target. No
  class means light mode.
- **Persistence key**: `whity.theme`, storing the raw preference —
  `'light' | 'dark' | 'system'`, not the resolved value.
- **Default**: `'system'` when nothing is stored yet, resolved against the
  OS/platform light-dark setting.
- **FOUC**: apply the class before first paint. On web this is a small
  blocking inline `<script>` in `<head>`, before any stylesheet.

`src/theme-mode.ts` (exported as `@amroksaleh/ui/theme-mode`, also available
via the shadcn registry as `@whity/theme-mode`) is the framework-agnostic
(no React) half of this contract — the storage key constant, the
`resolveIsDark()` resolution rule, and `buildThemeInitScript()` for the
anti-FOUC script source. `web/lib/theme-mode-context.tsx` is the React
provider built on top of it. A non-JS client (Flutter) can't import this file
directly but should replicate the same key, values, and resolution rule by
hand.
