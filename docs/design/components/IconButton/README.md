# IconButton

A 36px square button with one icon (`size: 'sm'` is 28px, for table rows and toolbars). **Always** pass `label`: it becomes the accessible name and shows as a tooltip on hover and focus (`tooltip: false` turns the tooltip off, `tooltip: 'bottom'` puts it below, `shortcut` adds a key hint).

**Props:** `icon`, `label`, `variant` (`ghost` default · `outline` · `secondary`), `size`, `tooltip`, `shortcut`. Direction icons (chevrons, arrows, send, undo) mirror in Arabic automatically. Never the main action of a view.
