---
"@amroksaleh/ui": minor
"@amroksaleh/features": minor
---

A BLOCK SETTINGS view in document mode: the spacing around the selected block,
how it behaves at a page boundary, and how wide it is.

`@amroksaleh/ui` gains `FlowBlockLayout` (six optional keys on the four block
types that have a box), `FlowBoxedBlock`, `blockAcceptsLayout` and the two
bounds the renderer validates. `@amroksaleh/features` gains `FlowBlockSettings`,
and `SideRail` gains an optional `blockSettings` slot.

Document mode edits a block AS ITSELF — a heading is a line at heading size, a
paragraph is a text area — which is most of why it reads like writing. That left
nowhere for the things that are not the content. The panel goes in the rail's
existing properties slot rather than a new tab, because "the properties of what
I have selected" is the question that slot already answers on the canvas; a
seventh tab would sit in the strip doing nothing whenever the canvas was open.

`blockAcceptsLayout` is a TYPE PREDICATE, not a boolean. Written as a boolean it
compiled at the call site and TypeScript then refused every property access
after it — which was the check working: the renderer refuses layout keys on
`pageBreak` and `spacer`, and a UI able to reach them would be offering settings
the printer rejects.

Every control is read by the renderer, and the bounds are the ones it validates,
so an author cannot compose a document that is then refused. Defaults are stored
as ABSENT rather than as a value meaning the same thing.
