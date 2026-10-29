# Button group and split button

`ButtonGroup` joins outline buttons that act on the same thing (previous, current, next) so they share one border. `SplitButton` pairs the usual action with a menu of its variants: Download PDF, or as Excel or CSV.

**Props:** `ButtonGroup`: `label`, `size` (`sm`), children. `SplitButton`: `variant` (`outline` by default, or `default` for the view's main action), `size`, `icon`, `items` (the same items as `Menu`), `onClick`, `label`, `labels.more`.

The main variant keeps one plane under the pair, so the rule holds: the main action is the only filled button and the only one on the plane (7.1). Use a split button only when one choice is clearly the usual one; otherwise use a plain menu button. The menu opens from the chevron, and its items follow `Menu` (keyboard, shortcuts, hints).
