---
"@amroksaleh/tokens": major
"@amroksaleh/ui": major
---

Design system 2.2 foundation (document editor milestone M1).

**Tokens v3** (`docs/design/tokens.json`): the 2.6 contrast fixes (`muted-foreground`
oklch(0.52), `input` oklch(0.66) / dark oklch(0.52)), status text and tint colours,
`primary-tint`, `divider`, `table-header`, `chart-6`, `plane-deep`, `mark-sheet` and the
blueprint colours; literal radii (`rounded-control`, `-control-sm`, `-control-lg`, `-card`,
`-dialog`; `rounded-md` is now 7px, `rounded-xl` 12px, and `rounded-3xl`/`-4xl` are gone);
`shadow-plane*`, `shadow-float`, the `plane-lift` and `bg-blueprint-grid` utilities; sizes
and motion variables with reduced motion; `--font-arabic` and `--font-display`; line heights
of 1.5 for text and 1.25 for titles on every `text-*` size; a 14px body; and `text-2xs`
(11px, the floor). `font-weight-bold` is removed: there is no 700. `fonts.css` declares only
Noto Sans and Noto Sans Arabic 400/500/600, Geist Mono 400/500 and Readex Pro 600 (Latin and
Arabic), and the 700 font files are gone.

**Components:** `Button` is 36 / 28 / 40px with corners a quarter of the height, an outline
`secondary`, a filled `destructive` and the plane under `default`; the `info`, `success`,
`warning`, `purple` and `*-solid` variants are removed. `Badge` states are tinted pills; its
`*-solid`, `purple`, `ghost` and `link` variants are removed. Menu, popover and dialog use
`shadow-float`, 32px menu items with 8px corners and a 488px dialog; tooltips are ink, after
500 ms. New: `Icon` (the 116 curated icons by name), `IconButton`, `ToggleGroup`, `Mark`,
`Avatar`, `ActorChip` and `AgentRunStatus`, each also in the shadcn registry.
