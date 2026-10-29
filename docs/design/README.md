Whity is the platform a company's systems sit on: identity, permissions, documents, forms and apps, where AI agents can see and act across everything while a person approves what matters. It is open source at its core and white-label by design: the surface carries the customer's brand, the platform underneath is Whity's.

The whole identity repeats one picture: **a blank sheet, on the platform beneath it.** The white sheet is the customer's surface, the blue platform is Whity, and the offset between them is the idea. Interfaces say the same thing: quiet white sheets on a grey ground, one main action sitting on its plane, and agent work that stays a loose, dashed sheet until a person sets it down.

**Version 2.2 (29 October 2026).** The brand was rebuilt in nine stages, one decision at a time, and every decision is recorded in `00-decision-register.md`, the source of truth. This guideline, the tokens, the logo files, the components, the 13 document templates and the PDF are all drawn from it. 2.1 added the P2 components; 2.2 adds the design of the **document editor** (section 18): its parts, states and rules, and the contract the Whity team builds it from. Earlier versions are superseded.

## Principles

- **Yours to brand.** A tenant's or partner's name and colour lead. Whity stays underneath, as the platform does in the mark.
- **Quiet and legible.** Pure greys, one main colour, Noto Sans at 14px. Nothing decorative competes with someone else's brand.
- **One thing on the plane.** The filled main button is the one action a view is for, and the only control that sits on the plane.
- **Nothing happens unseen.** Every action is attributed, every agent is scoped, anything that matters waits for a person, and what can be undone shows Undo.
- **Open at the core.** Show how the platform works, in the blueprint style, to anyone who asks.

## Using this system

- **Colour.** Tokens in Light and Dark (`tokens.json`). Whity Blue `#0961bb` is the main colour; greys have no chroma; status colours come as a fill, a text colour and a tint. Tenants change one colour and the rest is derived (Colour, White-label).
- **Type.** Noto Sans for the product at 14px (`text`), Noto Sans Arabic at the same size, Geist Mono for codes and IDs. Readex Pro 600 for brand headlines and document titles, never in the product's screens. No 700. Nothing below 11px.
- **Shape and space.** Controls 36px (28 small, 40 large) with corners ¼ of their height; cards 12px with a 1px border and no shadow; dialogs 16px; a 4px grid; 44px table rows; a 240px sidebar.
- **Depth.** The plane, a hard 3px offset down-left, sits under the main button, approved agent work and a hovered clickable card. Floating layers (menus, toasts, dialogs) get the soft `shadow-float`.
- **Focus.** A 2px ring in the main colour, offset 2px.
- **Icons.** Tabler outline, 116 curated names (`Icon`), 16px beside text. Agents are the robot face.
- **Components.** `components/bundle.js` (`window.Whity`, React 18), styled by `bundle.css`, typed in `index.d.ts`. Section 17 lists them by group; each has a card with a live preview.
- **Document editor.** Section 18 and the Document editor cards: a Notion-, Gutenberg- and Google Docs–inspired editor, designed here and built by the Whity team from `handoff/editor/`.
- **Documents.** `Doc` and `DocPages` lay out printed pages for Whity Render. Section 16 and the 15 document cards.
- **Handoff.** Web CSS variables, TypeScript tokens, a Tailwind v4 theme, the Flutter brand extension, icons and logos in `handoff/` (section 12).

## Never

- Write the name in anything but Latin letters, or translate or transliterate it. There is no Arabic name or logo.
- Rotate, mirror, stretch or recolour the mark, change its offset, or put the colour version on Whity Blue.
- Put the plane on something that has not happened, or on more than one action in a view.
- Use glowing brains, sparkles, robot illustrations or blue-purple gradients for AI. An agent is the robot-face icon on the tint, and is shown by what it does.
- Use the blueprint style for pricing, policy, marketing claims or a tenant's own pages.
- Set text below 11px, in a light weight or in 700, or in all caps outside short mono labels.
- Show a real company's or person's name in a mockup without permission: use clearly fictional names (Example Bakery) or placeholders such as [Tenant name].
