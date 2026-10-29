# Document editor: build plan

The editor is designed (section 18, decisions E.1–E.39, the Document editor cards, `handoff/editor/`). This plan turns it into code in whity-core, one milestone at a time. Each milestone is a branch and a pull request; don't start the next until the owner has approved the last.

**Visual check for every milestone:** render the built screen at the card's size (see `cards.html`) in light and dark, and in Arabic where there's an Arabic card, and compare with `screenshots/`. Differences in spacing, colour, type or state are bugs unless the register says otherwise.

## M0 · Survey and server proposal (no feature code)

1. Read `docs/design/HANDOFF.md`, `00-decision-register.md`, section 12, section 18, `handoff/editor/README.md` and `whity-document.ts`.
2. Survey the repository: the apps, `packages/ui`, `packages/tokens` (or `src/design/tokens`), the documents and templates code behind `/admin/documents`, Whity Render, the MCP server, AI principals and permissions.
3. Write `docs/design/IMPLEMENTATION-NOTES.md`:
   - **Foundation status:** which of section 12's items 1–12 are done, partly done or missing.
   - **Mapping check:** where each part of `handoff/editor/README.md` lands in this repository, and anything in the design that doesn't fit the code as it is (say so; don't change the design).
   - **Server proposal:** tables and migrations (documents, versions, suggestions, decisions, comment threads, saved blocks, block registry), API routes, MCP tools for agents (read a document, suggest blocks, suggest a rewrite, reply to a comment), the permissions they need (`document:read`, `document:edit`, …), the plugin sandbox `postMessage` protocol, and how a print view reaches Whity Render.
   - **Dependencies and risks**, with an estimate per milestone.
4. **Stop and ask for approval.** Nothing below starts until the owner approves the notes.

Done when: the notes exist, cover every point above, and the owner has approved them.

## M1 · Foundation

The parts of section 12 the editor needs and the repository lacks: tokens v3 (with `tokens.json` and `handoff/web/theme.css`), the fonts including `readex-pro-arabic-600.woff2`, the 116 icons, and the `packages/ui` components the editor reuses (Button, IconButton with Tooltip, Menu, Popover, ToggleGroup, Dialog, Avatar, ActorChip, Badge, AgentRunStatus).

Done when: those components match their cards in both themes; no Tailwind palette classes or hard-coded colours remain in them; no font weight 700 anywhere.

## M2 · Editor core

TipTap set up in `packages/ui` with the schema from `whity-document.ts` for text blocks (paragraph, headings 1–3, quote, code, flat lists with levels, checklist, divider). The shell: header, toolbar, outline, continuous sheet, status bar. The / menu and + inserter, Markdown shortcuts with one-step undo, the selection bubble, links (⌘K with a selection only, E.36), the gutter with drag and the block menu. Load and save `WhityDocument` JSON.

Done when: EditorShell, EditorBlockChrome, EditorInline (formatting and links) and EditorInsert match; a document round-trips through the JSON contract without loss (unit tests); keyboard paths in the cards' READMEs work.

## M3 · Rich blocks

Callout (Note, Important), toggle, columns, table (document table), image, file, page break, record and chart (live), signature and stamp, each with its block toolbar (E.8, E.13–E.16).

Done when: EditorBlocks matches; each block has its toolbar; record and chart blocks read within the viewer's permissions.

## M4 · Templates

Merge fields (the `field` node, chips, `{{` and Field ▾), preview with a record (E.12), the page view with pagination, letterhead, running header and footer (E.7, E.11), Draft and Publish with the publish check (E.9, E.37), PDF through Whity Render with values frozen at render time (E.16), the A33 Document designer screen.

Done when: EditorPageView, EditorInline (fields) and TplDocDesigner match; a template publishes only when the check is clear; a published template prints through Whity Render with field values.

## M5 · Comments and suggestions

Modes (E.10), suggestion marks and block suggestions, the margin rail and the drawer under 1024px (E.6), comment threads with @mentions and resolve, accept and reject with logging (E.29), comment highlights on screen only (E.39).

Done when: EditorCollab matches; people without edit permission can only suggest; every decision is in the audit log with the actor.

## M6 · Agents

Ask an agent, Rewrite, @agent in comments, streaming with AgentRunStatus and Stop, results always as suggestions with the actor, the person it acts for and the permission used (E.4, E.30, E.31), routines (E.32). Requires section 12 items 10 and 11.

Done when: EditorAgents matches; no code path lets an agent change a document without a suggestion; agents read only the document and what it references, within both permission sets.

## M7 · Plugin and saved blocks

The block registry and plugin manifest, native rendering for verified and first-party plugins and the sandboxed frame for others, print views, the plugin-off placeholder (E.17–E.20). Saved blocks: save from a multi-block selection, synced or copy, sharing, per-document parts, detach, the library, and the next-publish rule (E.21–E.24, E.38).

Done when: EditorCustomBlocks matches; a sandboxed block can't reach the page outside its frame; a disabled plugin keeps its data and blocks publishing.

## M8 · Arabic, type and accessibility

Arabic labels throughout, the mirrored shell, per-block direction, Readex Pro Arabic titles (E.35), the screen and print type scales (E.33), full width (E.34), keyboard access to every action, screen reader names, reduced motion.

Done when: EditorArabic and EditorType match; an axe check passes on the editor; every toolbar and menu action works from the keyboard.

Live collaboration (cursors, presence, version history) comes after M8 (E.3).
