# Editor in Arabic

The same editor with `dir="rtl"` and Arabic labels (section 8). The header, toolbar, gutter, outline and rail mirror: the outline moves to the right, the rail to the left, the gutter to the right of each block, and direction icons (undo, redo, lists, the back chevron) flip. Formatting icons (bold, italic) don't.

- Body text is Noto Sans Arabic at the same size with line height 1.7; the title is Readex Pro 600 in its Arabic design (E.35).
- Each block takes its direction from its own text, so an English paragraph in an Arabic letter runs left to right.
- Numbers, codes, IBANs, invoice numbers and amounts stay Western digits and left to right, isolated with `bdi`; merge field chips show Arabic labels and print the record's values.
- Field labels, block names in the / menu and every string come from `labels`; agent names stay as named by the workspace.
