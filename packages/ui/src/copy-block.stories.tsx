import type { Meta, StoryObj } from "@storybook/react-vite"

import { CopyBlock } from "./copy-block"

const meta = {
  title: "Primitives/CopyBlock",
  component: CopyBlock,
  tags: ["autodocs"],
} satisfies Meta<typeof CopyBlock>

export default meta
type Story = StoryObj<typeof meta>

export const Command: Story = {
  args: {
    value: 'claude mcp add --transport http whity "https://api.example.com/mcp" --header "Authorization: Bearer eyJ…"',
  },
}

export const File: Story = {
  args: {
    caption: "~/.cursor/mcp.json",
    multiline: true,
    value: JSON.stringify(
      { mcpServers: { whity: { url: "https://api.example.com/mcp", headers: { Authorization: "Bearer eyJ…" } } } },
      null,
      2
    ),
  },
}

export const MaskedSecret: Story = {
  args: {
    caption: "Token",
    value: "eyJhbGciOiJIUzI1NiJ9.eyJqdGkiOiJ4In0.signature",
    display: "eyJhbGci••••••••••••",
  },
}
