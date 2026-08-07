import type { Meta, StoryObj } from "@storybook/react-vite"

import { Button } from "./button"
import { LockedScreen } from "./locked-screen"

const meta = {
  title: "Primitives/LockedScreen",
  component: LockedScreen,
  tags: ["autodocs"],
  parameters: { layout: "padded" },
} satisfies Meta<typeof LockedScreen>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {
  args: {
    description:
      "Your offline session has expired. Reconnect and sign in again to keep working.",
  },
}

export const WithReloginAction: Story = {
  args: {
    description:
      "Your offline session has expired. Reconnect and sign in again to keep working.",
    action: <Button>Sign in again</Button>,
  },
}
