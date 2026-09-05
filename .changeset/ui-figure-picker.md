---
"@amroksaleh/ui": minor
"@amroksaleh/features": minor
---

Document mode's `figure` block can now be given an image.

It could not before, and that made **Insert ▸ Image a dead command**.
`newFlowBlock('figure')` starts as a 1×1 transparent PNG — the only honest
empty state, since the flowing renderer refuses a remote source and an empty
string would be a block that exists and cannot print — and nothing anywhere let
the author replace it. The command appeared to work and put an invisible dot on
the page.

`@amroksaleh/ui` gains `judgeFigureFile`, `FIGURE_MIME_TYPES` and
`DEFAULT_MAX_FIGURE_BYTES`; `FlowEditor` gains optional `maxFigureBytes` and
`onError`. Both props are optional, so existing callers are unaffected — though
a caller that omits `onError` gets a picker that refuses silently, which is why
the designer wires it to a toast.

**SVG is refused, and that is not an oversight about vectors.** An SVG can carry
script, and this value becomes a `data:` URI rendered both in the browser and in
headless Chromium; `element-content` already refuses script-carrying SVG data
URIs on the canvas side. `accept` on the input is a convenience — the file
dialog's "All files" option defeats it — so the JS check is the actual guard.

The size cap is a client-side pre-flight, not the rule: the server caps a whole
template at `documents.render_max_template_bytes` and the render service caps
the payload again. It exists so somebody is told "that image is too big" while
choosing it, rather than having a later save refused by a limit that names bytes
instead of the picture they just picked. The default sits well under the server's
because base64 inflates by about a third, and a client guard set equal to the
server's would pass files the encoded template then fails on.
