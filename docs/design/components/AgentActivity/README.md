# AgentActivity

What an agent read, asked, and did, as a timeline. `read` is quiet; `propose` is a dashed node; `approve` is the person's decision; `act` is filled `primary`; `error` is filled `error`. Every row names its actor; agent rows show the MCP tool they called in mono. `filter` adds chips to show one agent at a time (All, then each agent in the list). **Props:** `items` (`[{kind, actor, actorKind?, onBehalfOf?, text, time, tool?}]`), `filter`, `defaultAgent`, `label`, `labels`. Mirrors the audit trail (`docs/wiki/AUDIT_TRAIL.md`).
