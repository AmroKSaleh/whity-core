---
"@amroksaleh/features": minor
---

`FlowEditor` can now set the four block properties the render service already
honoured and nothing could reach: `heading.unnumbered`, `heading.inContents`,
`paragraph.align` and `table.caption`.

Each is read by `render-service/src/flow` — numbering and the contents front
matter in `document.js`, the alignment class and the table caption in `html.js`
— and each was declared in the model with no control anywhere. The features
existed, shipped, and were unreachable. `table.caption` is the sharpest case:
`flowToCanvas` READS it to represent a table when a document is switched to
canvas mode, so a converted table always fell back to its "N x M" summary,
because nothing could ever set one.

THE HEADING PAIR SHOWS ITS DEPENDENCY. The renderer lists a heading only when
`inContents !== false && !unnumbered`, so an unnumbered heading is never listed
whatever the other setting says. The contents box is disabled and unchecked for
an unnumbered heading rather than left live — a control that silently does
nothing is the same failure as a control that is missing, arrived at from the
other side.

Defaults are stored as ABSENT rather than as a value meaning the same thing:
turning numbering back on clears `unnumbered` instead of writing `false`,
"aligned to the start" clears `align`, and an emptied caption clears rather than
storing `''`. The renderer branches on presence, so the two are identical to it
and only one of them adds a key every future reader has to learn is redundant.

Alignment is logical, not left/right: `flow-align-end` is the end of the reading
direction, which stays correct in Arabic.
