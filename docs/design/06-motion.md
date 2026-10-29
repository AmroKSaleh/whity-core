# Motion

Short, calm and only where it explains a change. Everything settles on one curve, without bounce.

| Token | Duration | Use |
| --- | --- | --- |
| `dur-fast` | 120 ms | Hover, press, focus, the button lift |
| `dur-base` | 180 ms | State changes: switches, tabs, selection, the bulk bar rising |
| `dur-slow` | 250 ms | Things entering: dialogs, drawers, the mobile sidebar |
| `ease-out` | cubic-bezier(0.2, 0, 0, 1) | Every transition |

## The signature moment: set down

When a person approves an agent's proposal, the loose sheet is set down on the platform: the dashed edge turns solid in the main colour and the plane slides in underneath, **200 ms**, once (decision 5.4). Undo plays it backwards and the card turns dashed again. File: `approve-set-down.mp4` in Motion; keyframes in `handoff/motion/approve-set-down.json`.

## Timed things

- **Toast with Undo**: 10 seconds, a thin bar runs down along its foot and pauses while the pointer or focus is on it.
- **Undo window**: after the toast, the activity item keeps Undo with a meter for the tool's window (for example 30 minutes for email).
- **Tooltips** appear after 500 ms on hover and at once on keyboard focus.

## Rules

- The main button lifts 1px up-right on hover as its plane grows from 3 to 5px, and presses back down when clicked. That is the only movement on buttons.
- Clickable cards lift onto the plane; informational cards never move.
- Floating layers fade and move 4–8px into place; drawers slide from the end edge (from the left in Arabic).
- Show `Skeleton` rows for loads over 300 ms instead of a spinner.
- The mark never animates in the product. On a splash screen it may set the sheet down once (200 ms).
- **Reduced motion** shows the end state only: no movement, toasts without the running bar, skeletons hold still.
