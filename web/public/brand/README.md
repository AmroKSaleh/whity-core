# Whity Brand Assets

This folder holds the Whity brand assets that ship with the web app. Files here are
served statically by Next.js under the `/brand/` path (e.g. `/brand/whity-wordmark.svg`).

> [!IMPORTANT]
> **The SVGs in this folder are PLACEHOLDERS, not official brand assets.**
> They exist so layouts, spacing, and integration code have something real to point at.
> Design owns the final logo, mark, and wordmark and will replace these files.
> Do not treat the placeholder shapes, weights, or proportions as the official identity.

## Why this folder lives under `web/public/` (not `docs/`)

The repository `.gitignore` ignores everything under `docs/` except `docs/wiki/` and
`docs/adr/`. Binary/asset folders under `docs/` would be silently dropped from commits.
Placing brand assets under `web/public/brand/` keeps them version-controlled **and** makes
them directly servable by the app. If a future task needs assets under `docs/`, add a
matching `!docs/assets/` un-ignore line to `.gitignore` (flagged as a one-line change).

## Files

| File | Purpose | Notes |
|------|---------|-------|
| `whity-wordmark.svg` | The "Whity" wordmark (text logotype) | Placeholder. Uses `currentColor` so it inherits text color. |
| `whity-mark.svg` | The app mark / glyph (favicon, compact lockups) | Placeholder. Ring uses `currentColor`; accent dot uses `var(--accent)`. |

When the final assets arrive, keep these filenames so import paths and docs stay valid,
or update every reference (search the repo for `/brand/`).

## Logo usage

### Lockups
- **Wordmark** — primary identity in headers, login, and marketing surfaces where there is
  horizontal room.
- **Mark** — compact contexts: favicon, collapsed sidebar, avatars, app icons, anywhere the
  wordmark would be too small to read.

### Clearspace
Maintain clearspace around the logo equal to the **height of the mark's inner element**
(roughly `16px` at the default 32px mark size — i.e. **2 grid units** on the 8px grid; see
[UI-Patterns › 8px grid](../../../docs/wiki/Design-System-Grid.md)). Never crowd the logo
with text, icons, or container edges inside that clearspace.

### Minimum size
- Wordmark: do not render below **96px** wide (legibility of the lettering).
- Mark: do not render below **16px** (2 grid units); below that, omit the accent dot.

### Don'ts
- Do not recolor outside the documented color rules below.
- Do not stretch, skew, rotate, or add drop shadows / outlines.
- Do not place the logo on a low-contrast background (keep ≥ 4.5:1 against the surface).
- Do not reconstruct the wordmark in a different typeface.

## Color usage

Whity is a **white-label** product, so the brand identity is intentionally
**monochrome-first** with a single themeable accent.

| Use | Source | Behavior |
|-----|--------|----------|
| Monochrome logo | `currentColor` | Inherits the surrounding text color — renders correctly in light mode (dark logo on light) and dark mode (light logo on dark) with no extra work. |
| Brand accent | `var(--accent)` token | The single brand-color slot. It is **white-label-overridable per tenant** (CSS variable injection — see Theme-Customization). Do **not** hard-code a hex for the accent. |

Because the accent is the tenant-overridable brand color, never bake a literal hex value
into a brand asset or component. Reference the `--accent` token (or its Tailwind
`accent` / `accent-foreground` utilities) so per-tenant theming cascades automatically.
The placeholder SVGs include a neutral gray hex **only** as a fallback for when they are
opened outside a token context (e.g. a file preview).

## Favicon / app icon

`whity-mark.svg` doubles as the source for favicons and app icons. Generate raster sizes
(16, 32, 180, 192, 512) from the final mark when it lands; until then the placeholder is a
stand-in only.

## Related documentation

- [Design-System-Overview](../../../docs/wiki/Design-System-Overview.md) — system architecture
- [Theme-Customization](../../../docs/wiki/Theme-Customization.md) — tokens & white-label theming
- [Design-System-Grid](../../../docs/wiki/Design-System-Grid.md) — 8px grid, icon sizing, brand spacing
- [Component-Library](../../../docs/wiki/Component-Library.md) — component specs
- [UI-Patterns](../../../docs/wiki/UI-Patterns.md) — loading / error / empty / validation patterns
