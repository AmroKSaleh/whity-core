import type { Meta, StoryObj } from "@storybook/react-vite"
import {
  IconAlertTriangle,
  IconCircleCheck,
  IconInfoCircle,
} from "@tabler/icons-react"

import { Alert, AlertTitle, AlertDescription, AlertAction } from "./alert"
import { Button } from "./button"

const meta = {
  title: "Primitives/Alert",
  component: Alert,
  tags: ["autodocs"],
  argTypes: {
    variant: {
      control: "select",
      options: ["default", "info", "success", "warning", "destructive"],
    },
  },
} satisfies Meta<typeof Alert>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {
  render: (args) => (
    <Alert {...args} className="max-w-md">
      <IconInfoCircle />
      <AlertTitle>Heads up</AlertTitle>
      <AlertDescription>This tenant has 3 pending updates to apply.</AlertDescription>
    </Alert>
  ),
}

export const Info: Story = {
  args: { variant: "info" },
  render: (args) => (
    <Alert {...args} className="max-w-md">
      <IconInfoCircle />
      <AlertTitle>New version available</AlertTitle>
      <AlertDescription>Version 2.4 is ready to install.</AlertDescription>
    </Alert>
  ),
}

export const Success: Story = {
  args: { variant: "success" },
  render: (args) => (
    <Alert {...args} className="max-w-md">
      <IconCircleCheck />
      <AlertTitle>Update applied</AlertTitle>
      <AlertDescription>The plugin was updated to version 2.4 with no downtime.</AlertDescription>
    </Alert>
  ),
}

export const Warning: Story = {
  args: { variant: "warning" },
  render: (args) => (
    <Alert {...args} className="max-w-md">
      <IconAlertTriangle />
      <AlertTitle>Backup is 9 days old</AlertTitle>
      <AlertDescription>Run a fresh backup before applying this update.</AlertDescription>
    </Alert>
  ),
}

export const Destructive: Story = {
  args: { variant: "destructive" },
  render: (args) => (
    <Alert {...args} className="max-w-md">
      <IconAlertTriangle />
      <AlertTitle>Update failed</AlertTitle>
      <AlertDescription>The plugin update could not be verified. No data was changed.</AlertDescription>
    </Alert>
  ),
}

export const WithAction: Story = {
  render: (args) => (
    <Alert {...args} className="max-w-md">
      <IconInfoCircle />
      <AlertTitle>New version available</AlertTitle>
      <AlertDescription>Version 2.4 is ready to install.</AlertDescription>
      <AlertAction>
        <Button size="xs" variant="outline">Update</Button>
      </AlertAction>
    </Alert>
  ),
}
