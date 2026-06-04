# 8px Grid, Icon Set & Brand System

This page documents three foundational layers of the Whity design system that sit beneath the
components: the **8px spacing grid**, the curated **icon set** (`@tabler/icons-react`), and the
**brand assets**. It **documents** what already exists — it does not redefine spacing tokens
(those live in `src/design/tokens/base.json`, owned by the token system).

- **Tokens:** spacing/radius/type values are referenced from `base.json` by name. Do not
  redefine them here.
- **Brand assets:** live in [`web/public/brand/`](../../web/public/brand/README.md).
- **Components:** see [Component-Library](Component-Library.md); patterns: [UI-Patterns](UI-Patterns.md).

---

## The 8px spacing grid

Whity spaces and sizes UI on an **8px base grid** with a **4px half-step** for fine
adjustments. This is defined in `base.json` and is the rhythm every layout, component, and icon
should snap to.

### Spacing scale (from `base.json`)

> Source of truth: `src/design/tokens/base.json` → `spacing`. Reproduced here for reference —
> **do not edit these values in this doc**; change them in `base.json`.

| Step | Value | On the grid | Typical use |
|------|-------|-------------|-------------|
| `0` | `0px` | 0 | reset / flush |
| `1` | `4px` | ½ unit | hairline gaps, icon-to-text nudges, tight insets |
| `2` | `8px` | 1 unit | base unit — small gaps, compact padding |
| `3` | `12px` | 1½ units | field/control inner padding |
| `4` | `16px` | 2 units | card padding, default content gap |
| `6` | `24px` | 3 units | section gaps |
| `8` | `32px` | 4 units | major section / header separation |

- **Base unit:** `spacing.unit = 8px`. Multiples of 8 (`8, 16, 24, 32, …`) are the default.
- **Half-step:** `4px` is allowed for fine tuning (icon nudges, dense controls) — avoid odd
  values that fall off both the 8px and 4px rhythm.

### How it maps to Tailwind

Tailwind's spacing scale is already 4px-based (`1 = 4px`, `2 = 8px`, `4 = 16px`, `8 = 32px`),
so the grid is expressed directly through utilities — no custom config needed:

| Grid intent | Tailwind | px |
|-------------|----------|----|
| 1 unit gap | `gap-2`, `p-2`, `space-y-2` | 8 |
| 2 unit padding | `p-4`, `gap-4`, `space-y-4` | 16 |
| 3 unit gap | `gap-6`, `space-y-6` | 24 |
| 4 unit section | `space-y-8`, `mb-8` | 32 |

The shipped UI already follows this: admin pages use `space-y-8` between sections, forms use
`space-y-4` between fields, the header uses `mb-8`/`pb-6`, and modals use `gap-4`. (See
`web/app/(protected)/admin/users/page.tsx`, `users/create-modal.tsx`.)

### Applying the grid

- **Component padding:** snap internal padding to 1–2 units (`p-2`/`p-4`). Card content padding
  is 2 units (`px-4`).
- **Layout rhythm:** stack sections on multiples of 8 (`space-y-4` within a group,
  `space-y-8` between groups). Keep vertical rhythm consistent down a page.
- **Control heights:** the components sit on the grid (Button/Input `h-7` = 28px; sizes step in
  4px increments). Don't introduce off-grid heights.
- **Icon sizing:** size icons in grid steps (see [Icon sizing](#icon-sizing-on-the-grid)).
- **Clearspace:** brand clearspace is expressed in grid units (see [Brand](#brand-assets)).

> [!NOTE]
> The token agent is adding an explicit **type scale** to `base.json` alongside the existing
> spacing grid. Line-heights in the type scale should also resolve to grid-friendly values
> (multiples of 4px) so text baselines align to the 8px rhythm. Reference the type scale by
> name once it lands; this doc does not define it.

---

## Icon set

Whity standardizes on **`@tabler/icons-react`** (configured as `"iconLibrary": "tabler"` in
`web/components.json`, dependency `@tabler/icons-react ^3.44.0`). All icons — in shipped
components and app code — come from this one library. **Do not** mix in other icon packs.

### Curated set (icons actually in use)

These are the icons currently used across `web/`; treat this as the starting curated set and
extend it from Tabler when a new need appears.

| Icon | Used for | Where |
|------|----------|-------|
| `IconPlus` | Create / add actions | admin list page headers |
| `IconMenu2` | Row-action / overflow trigger | admin list rows |
| `IconX` | Close / dismiss | Dialog close, Toast dismiss |
| `IconCheck` | Selected / confirmed | Select, Dropdown, Toast (success) |
| `IconSelector` | Select trigger caret | Select |
| `IconChevronUp` / `IconChevronDown` | Sort / scroll / expand | DataTable, Select, permission panel |
| `IconChevronLeft` / `IconChevronRight` | Sidebar collapse, submenu | Sidebar, Dropdown sub |
| `IconAlertCircle` / `IconAlertTriangle` | Error / destructive warning | Toast (error), delete modals |
| `IconInfoCircle` | Info | Toast (info) |
| `IconDashboard`, `IconUsers`, `IconLock`, `IconBuilding`, `IconBuildingCommunity`, `IconSettings` | Navigation | Sidebar |
| `IconUserShield`, `IconDatabase`, `IconServer`, `IconCpu`, `IconLogout` | Stats / nav / session | Stats page, Sidebar |

### Usage conventions

**1. Sizing — snap to the grid.** Icons sit on the 8px/4px grid. Two ways to size, depending on
context:

| Context | How | Result |
|---------|-----|--------|
| Inside `Button`/`Badge`/menu/select | Let the component size it (the `[&_svg]:size-*` rules) | auto, per component size (e.g. `size-3.5` = 14px in a default button) |
| Standalone | `className="size-4"` (Tailwind) — **preferred** | 16px (2 units) |
| Standalone (legacy) | `size={16}` prop | 16px |

Recommended standalone sizes: **`size-4` (16px)** default, `size-3.5` (14px) inline-with-text,
`size-5` (20px) emphasis, `size-8`–`size-12` (32–48px) for empty-state/illustrative use. Avoid
arbitrary px that fall off the 4px grid.

> [!NOTE]
> The codebase mixes the `size={n}` prop (e.g. `IconPlus size={18}` in list headers) with the
> Tailwind `size-*` class. Prefer the `size-*` class for grid consistency; note `size={18}`
> is slightly off the 4px step. Minor cleanup follow-up.

**2. Stroke.** Tabler icons are stroke-based with a default `stroke-width` of 2. Keep the
default for UI consistency; only adjust (`stroke={1.5}`) for large illustrative icons.

**3. Color via `currentColor` / tokens.** Tabler icons inherit `currentColor`, so they take the
text color of their context automatically. Drive color with token-backed text utilities — never
hard-code:

```tsx
<IconAlertCircle className="text-destructive" />     {/* error */}
<IconUsers className="size-8 text-muted-foreground" /> {/* muted, empty state */}
<IconCheck className="text-accent-foreground" />      {/* on accent surface */}
```

Inside components this happens for free (e.g. destructive dropdown items tint their icon via
the `destructive` token). The brand accent dot in the brand mark uses `var(--accent)` so it
follows white-label theming.

**4. Accessibility / labels.**
- **Decorative icons** (next to a text label) need no extra markup — Tabler renders inert SVG.
- **Icon-only controls must have an accessible name.** Add `aria-label` on the control, or an
  `sr-only` text span (the Dialog close button uses `<span className="sr-only">Close</span>`).
  Never ship an icon-only button with no name (the Toast dismiss button is a current gap — see
  [UI-Patterns › Follow-ups](UI-Patterns.md#follow-ups)).

```tsx
// Good — icon-only trigger has a name
<Button variant="ghost" size="icon-sm" aria-label="Open menu"><IconMenu2 className="size-4" /></Button>
```

---

## Brand assets

Brand assets live in **[`web/public/brand/`](../../web/public/brand/README.md)** and are served
at `/brand/*`. The folder README is the authoritative usage guide; this section summarizes how
brand intersects the grid and token system.

> [!IMPORTANT]
> The SVGs in `web/public/brand/` are **clearly-marked placeholders**, not the official logo.
> They exist so layout/spacing/integration have a real target. **Design owns the final assets**
> and will replace them. Do not treat placeholder shapes/proportions as the official identity.

### Why `web/public/brand/` (not `docs/`)

The repo `.gitignore` ignores `docs/*` except `docs/wiki/` and `docs/adr/`, so asset folders
under `docs/` would be dropped from commits. Putting brand assets under `web/public/brand/`
keeps them version-controlled **and** servable by the app, with **no `.gitignore` change
required**. (If a future task needs assets under `docs/`, add a one-line `!docs/assets/`
un-ignore — flagged, not done here.)

### Assets

| File | Role |
|------|------|
| `whity-wordmark.svg` | "Whity" wordmark (logotype) — primary identity where there's width |
| `whity-mark.svg` | App mark/glyph — favicon, collapsed sidebar, compact lockups |

### Color usage (monochrome + accent)

Whity is **white-label**, so the identity is **monochrome-first with one themeable accent**:

| Element | Source | Behavior |
|---------|--------|----------|
| Logo / wordmark / mark ring | `currentColor` | Inherits text color → correct in light **and** dark mode automatically |
| Brand accent | `var(--accent)` token | The single brand-color slot — **white-label-overridable per tenant**. **Never hard-code a hex.** |

Because the accent is the tenant-overridable brand color, brand assets and components must
reference the `--accent` token (or `accent`/`accent-foreground` utilities), letting per-tenant
CSS-variable injection cascade (see [Theme-Customization](Theme-Customization.md)).

### Spacing / clearspace on the grid

Brand spacing uses the same 8px grid:

- **Clearspace:** keep clear margin around the logo equal to **2 grid units (16px)** at the
  default mark size — nothing should intrude within that zone.
- **Minimum sizes:** wordmark ≥ **96px** wide; mark ≥ **16px** (2 units). Below 16px, drop the
  accent dot.
- **Lockups:** when pairing mark + wordmark, separate them by 1–2 grid units (`gap-2`/`gap-4`).

### Don'ts
Don't recolor outside the rules above, stretch/skew/rotate, add shadows/outlines, place on
low-contrast surfaces (keep ≥ 4.5:1), or rebuild the wordmark in another typeface.

---

## Typography (current state)

Fonts are configured in `web/app/layout.tsx` and exposed as CSS variables for Tailwind:

| Role | Token | Current value |
|------|-------|---------------|
| Body / UI | `font-sans` | **Noto Sans** (loaded as `--font-sans` in `layout.tsx`) |
| Mono / code | `font-mono` | **Geist Mono** (`--font-geist-mono`) |
| Headings | `font-heading` | maps to sans (e.g. `CardTitle`/`DialogTitle` use `font-heading`) |

> [!WARNING]
> **Font inconsistency:** `base.json` declares `font-sans`/`font-heading` as **Inter**, and the
> design docs reference Inter, but `layout.tsx` actually loads **Noto Sans** for `--font-sans`
> (Geist/Geist Mono are also imported). Reconcile `base.json`, `layout.tsx`, and the docs on a
> single sans family. Flagged as a follow-up (font choice + token value is owned jointly by the
> token agent and the web owner; not changed here).

The forthcoming **type scale** (token agent, in `base.json`) should resolve line-heights to
4px multiples so text aligns to the 8px grid.

---

## Follow-ups

1. **Icon sizing is mixed** — `size={n}` prop vs Tailwind `size-*` class, and `size={18}` is
   off the 4px step. Standardize on `size-*` classes on grid steps.
2. **Toast dismiss button has no accessible name** (icon-only `IconX`). Add `aria-label`.
3. **Font mismatch** — `base.json`/docs say Inter; `layout.tsx` loads Noto Sans. Pick one and
   align tokens + layout + docs.
4. **Brand assets are placeholders** — replace `web/public/brand/*.svg` with official assets
   (keep filenames), then generate favicon/app-icon raster sizes from the final mark.
5. **`--accent` not yet a distinct brand color** — currently `accent` ≈ a neutral in
   `base.json`. The token agent is introducing a real brand accent; once it lands, the brand
   mark's accent dot and `accent` utilities will reflect it (and per-tenant overrides).

## Related documentation

- [Brand assets README](../../web/public/brand/README.md) — logo usage, clearspace, color rules
- [Design-System-Overview](Design-System-Overview.md) — architecture & principles
- [Theme-Customization](Theme-Customization.md) — tokens & white-label theming
- [Component-Library](Component-Library.md) — component specs & states
- [UI-Patterns](UI-Patterns.md) — loading / error / empty / validation patterns
