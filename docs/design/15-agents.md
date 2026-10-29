# Agent UI

Whity's promise is AI that sees and acts across a company while a person stays in control. These rules make that visible on every screen where an agent works.

## Six rules

1. **Attribute everything.** Every action shows who did it with `ActorChip`: a person (initials), an agent (the robot face on the tint, named by role) with the person it acts **for**, or the system (the mark).
2. **Show before you act.** Anything set to "Ask first" becomes an `ActionProposal`: one line saying what will happen and how much, two examples then "Show all", before → after, the permissions it uses, and the undo window (decision 7.5a). Approve, Edit, Decline.
3. **One at a time.** The approvals inbox is a queue (`ApprovalQueue`): "1 of 3 waiting · oldest first", the next cards stacked behind (7.5b).
4. **Undo is an action, not an apology.** A 10-second toast with Undo, then Undo stays on the activity item for the tool's window with a meter (`UndoBar`, 7.5c). A confirm dialog is only for what can't be undone.
5. **Scope is visible.** `ScopeGrant` shows each `resource:action` permission as Off, Ask first or Allowed. An agent never exceeds the person who granted it, and every change is logged.
6. **Nothing sits on the plane until it has happened.** A proposal is a loose, dashed sheet; approving sets it down (solid, on the plane, 200 ms).

## Proposal states

| State | Look |
| --- | --- |
| Waiting | Dashed edge, flat, "Waiting for your approval" |
| Approved | Solid edge in the main colour, on the plane |
| Declined | Grey, title struck through |
| Expired | Grey, with "Ask again" |
| Undone | Dashed again, "Undone by …" |

## Run status

An agent's run shows what it is doing in plain words (`AgentRunStatus`): **Running** ("Reading 48 invoices", with progress and Stop), **Waiting for approval** (dashed, with Review), **Done**, **Failed** (with what to do next and Try again). A compact pill shows it in the top bar. Reads are quiet in the activity list; changes are filled; errors are red.

The command palette (⌘K) ends with an "Ask an agent" row: what was typed becomes a request, and the agent answers with a proposal to approve (decision 2.1c).

## Words

Agents are named by role (Billing assistant, HR assistant, Front desk), never human first names or "Whity AI". They don't say "I" and don't have feelings. Messages put the agent first, then who it acts for, then the action: "Billing assistant for Rana · Sent 12 payment reminders". Documents an agent sent say so in their footer.

## Where it shows

- **Approvals** (`TplAgentApprovals`, `TplAgentApprovalsAr`, `ApprovalQueue`).
- **AI principal** (`TplAgentScope`): scope, credential, rotate and revoke.
- **Agent activity** (`AgentActivity`, filterable by agent) and every record's history (`Timeline`).
- **Documents**: the payment reminder, the report and the leave approval credit the agent and the person.

## In whity-core today

- **AI principals** are MCP bearer credentials issued to AI clients (`admin/ai-principals`); every MCP call runs with tenant isolation and RBAC.
- **Delegations** pass a subset of a person's permissions to roles or users (`admin/delegations`).
- **Approval gating** exists for password resets and two-step recovery (`admin/approval-gating`).
- Per-action "Ask first" proposals and per-permission modes are new: see Engineering handoff, items 10 and 11.
