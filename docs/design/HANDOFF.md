# Whity design system 2.2: handoff

This folder is the Whity brand and design system, exported for building in whity-core. Unzip it at the repository root so it lands in `docs/design/`.

**Source of truth:** `00-decision-register.md`. Every value in this folder comes from it. Where anything disagrees with the register, the register wins. Change the register first, then the files.

## What's here

| Path | What it is |
| --- | --- |
| `00-decision-register.md` | Every decision, stage by stage, with its number (1.1 … 7.6, 2.1a–d, E.1–E.39) |
| `README.md`, `01-…` to `18-…md` | The guideline sections. Section 12 is the engineering handoff for the whole system; section 18 is the document editor |
| `Whity-Brand-Guidelines-v2.2.pdf` | The same guideline as a PDF, with screens |
| `tokens.json`, `tokens.css`, `fonts/` | Design tokens (light and dark) and the fonts they declare |
| `handoff/web/` | CSS variables, a typed token object, the Tailwind v4 `@theme` additions |
| `handoff/editor/` | **The document editor contract** (`whity-document.ts`) and its TipTap/ProseMirror mapping (`README.md`) |
| `handoff/icons/` | 116 icons (ink and paper) and `icons.json` with each Tabler name |
| `handoff/logos/`, `assets/` | Logo files, social images, the set-down motion clip |
| `handoff/flutter/` | The Flutter brand extension and font list |
| `components/bundle.js`, `bundle.css`, `index.d.ts` | The reference React implementation (`window.Whity`) and its types. Class names, states and measurements here are the reference for `packages/ui` |
| `components/<Name>/` | One folder per card: `README.md` (the spec), `preview.html` (as authored), `index.html` (opens on its own in a browser; add `#theme=dark`) |
| `cards.html` | An index of every card with links to its live page and screenshots |
| `screenshots/<Name>-light.png`, `-dark.png` | Every card rendered in both themes: the visual target |
| `PLAN.md` | The build plan for the document editor, milestone by milestone, with "done when" checks |
| `CLAUDE-snippet.md` | Rules to add to the repository's `CLAUDE.md` |
| `HANDOFF-PROMPT.md` | The prompt to start a Claude Code session with |

## What is and isn't designed

- **Designed:** brand, tokens, components, document templates, and the document editor's interface, behaviour, states and data contract.
- **Not designed yet:** the editor's server side: database tables, API endpoints, the MCP tools agents use to edit documents, the plugin sandbox message protocol, and how Whity Render receives a block's print view. `PLAN.md` milestone 0 asks for a proposal before any of it is built.
- **Prerequisites in whity-core:** section 12's "Changes whity-core needs", especially items 10 and 11 (agent proposals and per-permission modes), which the editor's agent features depend on.
