# PlanGate

Shown where a feature lives when the workspace's plan doesn't include it: the feature greyed behind a card that says which plan has it, what it adds, and what to do. Owners get Upgrade and Compare plans; everyone else is told that only owners can change the plan and can ask one.

**Props:** `feature`, `plan` (default `[plan name]`), `description`, `includes` (a short list), `price`, `canUpgrade` (owners), `variant` (`overlay` over `children`, or `inline`), `onUpgrade`, `onCompare`, `onAsk`, `labels`.

Plan names and prices are not decided (Products, names and plans), so every example uses `[plan name]` and `[price]` until they are. Never hide a gated feature completely: people should see what they'd get. The content behind is inert and hidden from screen readers.
