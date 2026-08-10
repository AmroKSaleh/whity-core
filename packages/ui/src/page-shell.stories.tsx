import type { Meta, StoryObj } from "@storybook/react-vite"

import { PageShell } from "./page-shell"

const DemoSidebar = () => (
  <div className="w-56 shrink-0 border-e border-sidebar-border bg-sidebar p-4 text-sm text-sidebar-foreground">
    Sidebar
  </div>
)

const DemoTopBar = () => (
  <div className="flex h-12 items-center border-b border-border px-4 text-sm text-foreground">Top bar</div>
)

const meta = {
  title: "Layout/PageShell",
  component: PageShell,
  tags: ["autodocs"],
  args: {
    sidebar: <DemoSidebar />,
    className: "h-full min-h-0",
    children: <p className="text-sm text-foreground">Page content goes here.</p>,
  },
  // The shell fills its parent, so every story is framed by a fixed-height box
  // the way a real app viewport frames it.
  decorators: [
    (Story) => (
      <div className="h-80 overflow-hidden rounded-lg border border-border">
        <Story />
      </div>
    ),
  ],
} satisfies Meta<typeof PageShell>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {}

export const WithTopBar: Story = {
  args: { topBar: <DemoTopBar /> },
}
