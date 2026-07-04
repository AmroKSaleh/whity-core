import type { Meta, StoryObj } from "@storybook/react-vite"

import { Tabs, TabsList, TabsTrigger, TabsContent } from "./tabs"

const meta = {
  title: "Primitives/Tabs",
  component: Tabs,
  tags: ["autodocs"],
} satisfies Meta<typeof Tabs>

export default meta
type Story = StoryObj<typeof meta>

function Panels() {
  return (
    <>
      <TabsContent value="overview">Overview of the tenant.</TabsContent>
      <TabsContent value="usage">Usage metrics and quotas.</TabsContent>
      <TabsContent value="settings">Deployment settings.</TabsContent>
    </>
  )
}

export const Horizontal: Story = {
  render: () => (
    <Tabs defaultValue="overview" className="w-96">
      <TabsList>
        <TabsTrigger value="overview">Overview</TabsTrigger>
        <TabsTrigger value="usage">Usage</TabsTrigger>
        <TabsTrigger value="settings">Settings</TabsTrigger>
      </TabsList>
      <Panels />
    </Tabs>
  ),
}

export const HorizontalLine: Story = {
  render: () => (
    <Tabs defaultValue="overview" className="w-96">
      <TabsList variant="line">
        <TabsTrigger value="overview">Overview</TabsTrigger>
        <TabsTrigger value="usage">Usage</TabsTrigger>
        <TabsTrigger value="settings">Settings</TabsTrigger>
      </TabsList>
      <Panels />
    </Tabs>
  ),
}

export const Vertical: Story = {
  render: () => (
    <Tabs defaultValue="overview" orientation="vertical" className="w-[28rem]">
      <TabsList>
        <TabsTrigger value="overview">Overview</TabsTrigger>
        <TabsTrigger value="usage">Usage</TabsTrigger>
        <TabsTrigger value="settings">Settings</TabsTrigger>
      </TabsList>
      <Panels />
    </Tabs>
  ),
}

export const VerticalLine: Story = {
  render: () => (
    <Tabs defaultValue="overview" orientation="vertical" className="w-[28rem]">
      <TabsList variant="line">
        <TabsTrigger value="overview">Overview</TabsTrigger>
        <TabsTrigger value="usage">Usage</TabsTrigger>
        <TabsTrigger value="settings">Settings</TabsTrigger>
      </TabsList>
      <Panels />
    </Tabs>
  ),
}
