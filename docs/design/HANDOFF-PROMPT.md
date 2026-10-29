You're starting work on whity-core's document editor. Its design is finished and lives in `docs/design/`; your job is to build it, carefully and in order.

Read these first, in this order:
1. `docs/design/HANDOFF.md`: what's in the folder and what isn't designed yet.
2. `docs/design/CLAUDE-snippet.md`: the hard rules. Add it to the repository's `CLAUDE.md` (create the file if it doesn't exist) as the first change on your branch.
3. `docs/design/00-decision-register.md`: the source of truth. The editor decisions are E.1–E.39 in the "Document editor" section; earlier stages set the brand, tokens and components.
4. `docs/design/18-document-editor.md` and `docs/design/12-engineering-handoff.md`.
5. `docs/design/handoff/editor/README.md` and `docs/design/handoff/editor/whity-document.ts`: the contract and the TipTap/ProseMirror mapping.
6. `docs/design/PLAN.md`: the milestones and their "done when" checks.

Then do **milestone M0 only**:
- Create a branch `design/editor-m0`.
- Survey the repository as M0 describes, and write `docs/design/IMPLEMENTATION-NOTES.md`: the foundation status against section 12's items, where each part of the editor lands in this code, anything in the design that doesn't fit the code as it is, and a server-side proposal (tables, API routes, MCP tools for agents, permissions, the plugin sandbox protocol, the Whity Render print path), with dependencies, risks and an estimate per milestone.
- Don't write feature code, don't change the design files, and don't change `00-decision-register.md`. If something in the design looks wrong or impossible, write it in the notes under "Questions for the owner".
- Open a pull request with the notes and the `CLAUDE.md` change, then stop and summarise: what you found, the questions, and what M1 would change.

For every later milestone (after I approve): one branch and pull request per milestone; compare what you build with `docs/design/screenshots/` (light, dark, and Arabic where there's a card) at the sizes in `docs/design/cards.html`; run the tests; and stop for review before starting the next.

Hard rules: the register wins over everything else; tokens only (no raw colours or Tailwind palette classes); no font weight 700; Readex Pro only for document titles; agents only ever suggest, never change a document directly; every string has an English default and an Arabic label.
