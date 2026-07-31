import type { Meta, StoryObj } from "@storybook/react-vite"

import { PageShell } from "./page-shell"

const meta = {
  title: "Layout/PageShell",
  component: PageShell,
  tags: ["autodocs"],
} satisfies Meta<typeof PageShell>

export default meta
type Story = StoryObj<typeof meta>

const DemoSidebar = () => (
  <div className="w-56 shrink-0 border-e border-sidebar-border bg-sidebar p-4 text-sm text-sidebar-foreground">
    Sidebar
  </div>
)

const DemoTopBar = () => (
  <div className="flex h-12 items-center border-b border-border px-4 text-sm text-foreground">Top bar</div>
)

export const Default: Story = {
  render: () => (
    <div className="h-80 overflow-hidden rounded-lg border border-border">
      <PageShell sidebar={<DemoSidebar />} className="h-full min-h-0">
        <p className="text-sm text-foreground">Page content goes here.</p>
      </PageShell>
    </div>
  ),
}

export const WithTopBar: Story = {
  render: () => (
    <div className="h-80 overflow-hidden rounded-lg border border-border">
      <PageShell sidebar={<DemoSidebar />} topBar={<DemoTopBar />} className="h-full min-h-0">
        <p className="text-sm text-foreground">Page content goes here.</p>
      </PageShell>
    </div>
  ),
}
