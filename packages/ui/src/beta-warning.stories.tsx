import type { Meta, StoryObj } from "@storybook/react-vite"

import { BetaWarning } from "./beta-warning"

const meta = {
  title: "Primitives/BetaWarning",
  component: BetaWarning,
  tags: ["autodocs"],
  argTypes: {
    variant: {
      control: "select",
      options: ["card", "banner", "inline"],
    },
    color: {
      control: "select",
      options: ["purple", "amber", "blue"],
    },
    badgeText: { control: "text" },
    title: { control: "text" },
    description: { control: "text" },
    dismissible: { control: "boolean" },
  },
} satisfies Meta<typeof BetaWarning>

export default meta
type Story = StoryObj<typeof meta>

export const DefaultCard: Story = {
  args: {
    variant: "card",
    title: "AI Analytics Assistant",
    description: "This feature uses experimental LLM models to generate predictive reports. Results should be verified independently.",
    action: {
      label: "Give Feedback",
      onClick: () => {},
    },
    dismissible: true,
  },
}

export const AmberWarning: Story = {
  args: {
    variant: "card",
    color: "amber",
    badgeText: "EXPERIMENTAL",
    title: "Real-time Multi-Region Sync",
    description: "Data replication latency may vary during peak load while this feature is in open preview.",
    action: {
      label: "View Docs",
      href: "#",
    },
    dismissible: true,
  },
}

export const BlueInfoBanner: Story = {
  args: {
    variant: "banner",
    color: "blue",
    badgeText: "PREVIEW",
    title: "New Admin Dashboard v2.5",
    description: "You are previewing the redesigned administrative portal. Switch back anytime from account settings.",
    action: {
      label: "Provide Feedback",
      onClick: () => {},
    },
    dismissible: true,
  },
}

export const InlineBadgeStrip: Story = {
  render: () => (
    <div className="flex flex-wrap items-center gap-3">
      <BetaWarning variant="inline" title="AI Search" color="purple" badgeText="BETA" />
      <BetaWarning variant="inline" title="GraphQL V2 API" color="amber" badgeText="PREVIEW" />
      <BetaWarning variant="inline" title="Automated Export" color="blue" badgeText="LABS" dismissible />
    </div>
  ),
}
