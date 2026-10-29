## Design system (docs/design)

- `docs/design/00-decision-register.md` is the source of truth for every visual and interaction decision. If code, a card or a guideline section disagrees with it, the register wins. Never change a decided value in code without changing the register first, and ask the owner before changing the register.
- Use tokens, never raw colours or Tailwind palette classes (`blue-500`, `emerald-600`, …). Tokens: `docs/design/tokens.json`, `handoff/web/tokens.css`, `handoff/web/theme.css`.
- Type: Noto Sans (Noto Sans Arabic), 14px in the product, 16px in the editor sheet; Readex Pro 600 only for document titles and brand headlines, never in product screens; Geist Mono for codes. **No font weight 700** anywhere (bold is 600). Nothing below 11px.
- One filled main button per view, and it is the only thing on the plane. Secondary buttons are outline.
- Agents never change anything directly: what they do is a suggestion or a proposal a person accepts, attributed to the agent and the person it acts for, with the permission it used. Dashed means "not happened yet".
- Every visible string has an English default and an Arabic label. Use logical CSS properties; numbers, codes and amounts stay Western digits and left to right.
- Example data uses Example Bakery and friends; plan names and prices stay `[plan name]` and `[price]`.
- The reference for components is `docs/design/components/` (each card's `README.md`, `index.html` and `screenshots/`). Compare built screens with the screenshots in both themes before calling UI work done.
- The document editor: section 18, `docs/design/handoff/editor/` (contract and TipTap mapping) and `docs/design/PLAN.md`. Work one milestone per branch and pull request, and stop for approval between milestones.
