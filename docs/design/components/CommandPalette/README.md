# CommandPalette

Opened with ⌘K (Ctrl K on Windows) or the search field in the top bar. One box finds a record (INV-1042), a page, or an action, and the last row hands the words to an agent, which comes back with a proposal to approve. Arrow keys move, Enter opens, Esc closes; the active row is the tint.

**Props:** `groups` (`[{heading, items: [{label, icon?, hint?, shortcut?, keywords?, agent?, onSelect}], always?}]`), `defaultQuery`, `onClose`, `inline` (for previews), `labels`, `dir`.

Results are filtered by label, hint and keywords; a group with `always` stays visible (the agent row). An agent row is dashed until a person acts on what it proposes, like every agent sheet. Records show their key numbers in the hint so the right one is found without opening it.
