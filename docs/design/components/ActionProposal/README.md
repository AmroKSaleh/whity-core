# ActionProposal

The approve-before-act card. A proposal is a loose sheet (dashed edge, no plane): nothing has happened yet. Approving sets it down on the platform (solid `brand` edge, `plane-rest`). Declined goes grey with the title struck through; expired goes grey with Ask again; undone turns dashed again and says by whom (`undoneBy`). **Never show the plane on something that has not happened.**

Title: the action as a verb phrase with the count. `changes`: before → after with real values. `scope`: the permission keys it uses (`resource:action`, as in RBAC). Always say whether it can be undone and for how long (`undoWindow`, for example "30 minutes"). With more than three changes it shows two and "Show all" (7.5a). **Props:** `agent`, `onBehalfOf`, `title`, `summary`, `changes`, `scope`, `reversible`, `undoWindow`, `status`, `decidedAt`, `onApprove`, `onReject`, `onEdit`, `onUndo`, `labels`, `dir`.

Intentional addition: Whity's approval gating covers password resets and two-step recovery today; agent proposals need server work (see Engineering handoff).
