import type { Meta, StoryObj } from "@storybook/react-vite"
import * as React from "react"

import { ActionProposal } from "./action-proposal"
import { AgentActivity } from "./agent-activity"
import { ApprovalQueue, type ApprovalQueueItem } from "./approval-queue"
import { ScopeGrant, type ScopeGrantMode } from "./scope-grant"
import { UndoBar } from "./undo-bar"

/**
 * The agent collaboration components (WHIT-808) with the cards' example data,
 * for comparing with docs/design/screenshots/<Card>-light.png and -dark.png.
 */
const meta = {
  title: "Agents/Collaboration",
  parameters: { layout: "padded" },
} satisfies Meta

export default meta
type Story = StoryObj<typeof meta>

const reminders = {
  agent: "Billing assistant",
  onBehalfOf: "Rana",
  title: "Send payment reminders to 12 customers",
  summary: "These invoices are more than 14 days overdue. Each customer gets your standard reminder by email.",
  changes: [
    { label: "Invoices", after: "12 overdue · JOD 4,860" },
    { label: "Status", before: "Overdue", after: "Reminder sent" },
  ],
  scope: ["invoice:read", "invoice:remind"],
  undoWindow: "30 minutes",
}

export const Proposals: Story = {
  render: () => (
    <div style={{ display: "grid", gap: 16, maxWidth: 560 }}>
      <ActionProposal {...reminders} onApprove={() => {}} onDecline={() => {}} onEdit={() => {}} />
      <ActionProposal
        agent="Billing assistant"
        onBehalfOf="Rana"
        title="Mark invoice INV-1042 as paid"
        changes={[{ label: "INV-1042", before: "Unpaid", after: "Paid · JOD 320" }]}
        scope={["invoice:update"]}
        undoWindow="30 minutes"
        status="approved"
        decidedAt="14:02"
        onUndo={() => {}}
      />
      <ActionProposal
        agent="Front desk"
        onBehalfOf="Omar"
        title="Create a quote for Example Hotel"
        changes={[{ label: "Total", after: "JOD 1,240.000" }]}
        scope={["quote:create"]}
        status="declined"
      />
      <ActionProposal
        agent="HR assistant"
        onBehalfOf="Rana"
        title="Approve 2 days of leave for Lina"
        changes={[{ label: "Leave", after: "3–4 Nov 2026" }]}
        scope={["leave:approve"]}
        status="expired"
        onAskAgain={() => {}}
      />
      <ActionProposal
        agent="Billing assistant"
        onBehalfOf="Rana"
        title="Email the October statement to Example Clinic"
        changes={[{ label: "To", after: "clinic@example.jo" }]}
        scope={["statement:send"]}
        status="undone"
        undoneBy="Rana Haddad"
      />
    </div>
  ),
}

function Queue() {
  const items: ApprovalQueueItem[] = [
    { id: "1", ...reminders, onApprove: () => {}, onDecline: () => {}, onEdit: () => {} },
    { id: "2", agent: "HR assistant", onBehalfOf: "Rana", title: "Approve 2 days of leave for Lina", scope: ["leave:approve"] },
    { id: "3", agent: "Front desk", onBehalfOf: "Omar", title: "Create a quote for Example Hotel", scope: ["quote:create"] },
  ]
  const [current, setCurrent] = React.useState("1")
  return <ApprovalQueue items={items} currentId={current} onCurrentChange={setCurrent} />
}

export const ApprovalsQueue: Story = { render: () => <Queue /> }

function Scope() {
  const [modes, setModes] = React.useState<Record<string, ScopeGrantMode>>({
    "invoice:read": "allow",
    "invoice:remind": "ask",
    "invoice:void": "off",
    "customer:read": "allow",
    "customer:update": "ask",
  })
  return (
    <ScopeGrant
      agent="Billing assistant"
      onBehalfOf="Rana"
      modes={modes}
      onChange={(key, mode) => setModes({ ...modes, [key]: mode })}
      groups={[
        {
          key: "invoice",
          label: "Invoices",
          actions: [
            { key: "invoice:read", label: "See invoices" },
            { key: "invoice:remind", label: "Send reminders" },
            { key: "invoice:void", label: "Void an invoice" },
          ],
        },
        {
          key: "customer",
          label: "Customers",
          actions: [
            { key: "customer:read", label: "See customers" },
            { key: "customer:update", label: "Edit contact details" },
          ],
        },
      ]}
    />
  )
}

export const Scopes: Story = { render: () => <Scope /> }

export const Activity: Story = {
  render: () => (
    <div style={{ display: "grid", gap: 24, maxWidth: 420 }}>
      <AgentActivity
        filter
        items={[
          { id: "1", kind: "read", actor: "Billing assistant", text: "Read 48 open invoices", timeLabel: "13:58", tool: "invoices.list" },
          { id: "2", kind: "propose", actor: "Billing assistant", text: "Asked to send 12 reminders", timeLabel: "13:59", tool: "invoices.remind" },
          { id: "3", kind: "approve", actor: "Rana Haddad", actorKind: "person", text: "Approved INV-1042 as paid", timeLabel: "11:20" },
          {
            id: "4",
            kind: "act",
            actor: "Billing assistant",
            text: "Marked INV-1042 paid",
            timeLabel: "11:20",
            tool: "invoices.update",
            extra: <UndoBar text="Marked INV-1042 paid" remainingLabel="24 minutes" remaining={24} total={30} onUndo={() => {}} />,
          },
          { id: "5", kind: "act", actor: "HR assistant", text: "Recorded leave for Lina", timeLabel: "10:02", tool: "leave.create" },
        ]}
      />
      <UndoBar text="Emailed the statement" state="undone" undoneBy="Rana Haddad" />
      <UndoBar text="Sent 12 reminders" state="expired" />
    </div>
  ),
}
