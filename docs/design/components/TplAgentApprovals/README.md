# Approvals (agent proposals)

The page where a person decides what agents may do. Proposals come one at a time (decision 7.5b): the oldest waiting proposal as a loose sheet, the rest stacked behind it, Previous and Next to move through the queue; today's activity beside it. Each proposal reads summary first, shows the permissions it uses and whether it can be undone. Irreversible proposals say so and never pre-select Approve.

Built from `AppShell`, `PageHeader`, `Tabs`, `ActionProposal`, `AgentActivity`. Tenant, people and amounts are example data. Needs server work: proposals are not in whity-core yet (see Engineering handoff).
