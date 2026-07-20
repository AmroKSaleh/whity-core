import type { Meta, StoryObj } from "@storybook/react-vite"

import { Button } from "./button"
import { AccessDenied } from "./access-denied"

const meta = {
  title: "Primitives/AccessDenied",
  component: AccessDenied,
  tags: ["autodocs"],
  parameters: { layout: "padded" },
} satisfies Meta<typeof AccessDenied>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {
  args: {
    description: (
      <>
        You do not have the required permission (<code>settings:read</code>) to view
        Website Settings.
      </>
    ),
  },
}

export const WithGoBackAction: Story = {
  args: {
    description: (
      <>
        You do not have the required permission (<code>settings:read</code>) to view
        Website Settings.
      </>
    ),
    action: (
      <Button variant="outline" onClick={() => window.history.back()}>
        Go Back
      </Button>
    ),
  },
}

export const WrongTenantScope: Story = {
  args: {
    description: (
      <>
        Sign-up governance can only be managed from the system tenant. Your tenant&rsquo;s
        settings are on the General page.
      </>
    ),
  },
}
