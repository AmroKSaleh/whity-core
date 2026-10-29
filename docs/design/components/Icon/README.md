# Icon

Tabler outline icons (MIT): a 24 grid, round caps and joins, drawn in `currentColor`. The set here is curated to Whity's own words: tenant, role, permission, delegation, AI principal, approval, routing, inbox.

**Props:** `name` (one of `Whity.iconNames`), `size` (16 beside 14px text, 20 in touch navigation, 24 in headers and empty states, 12–14 in badges; decision 5.2), `stroke` (default 2.25 at 16px and below, 2 at 20 and 24px, so every size reads at the same weight), `label` (when the icon stands alone and carries meaning), `mirror` (flip in right-to-left layouts; on by default for chevrons, arrows, undo, send, logout and external, `mirror: false` to stop it). 116 icons, including the editor set (bold to page break), menu, sort arrows, minus, zoom and light, dark and system for the theme switch.

In code, import from `@tabler/icons-react` by the Tabler name in `handoff/icons/icons.json`. Don't mix in other icon sets. An agent is always Tabler's robot face (`ai-principal` and `robot`, decision 5.1), tinted; never sparkles, brains or glowing effects.
