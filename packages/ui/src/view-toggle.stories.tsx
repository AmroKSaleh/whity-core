import type { Meta, StoryObj } from "@storybook/react-vite"
import { fn } from "storybook/test"
import * as React from "react"
import { IconLayoutGrid, IconList, IconTable } from "@tabler/icons-react"

import { ViewToggle } from "./view-toggle"

const LAYOUTS = [
  { value: "list", label: "List", icon: <IconList size={16} aria-hidden /> },
  { value: "grid", label: "Grid", icon: <IconLayoutGrid size={16} aria-hidden /> },
]

const meta = {
  title: "Inputs/ViewToggle",
  component: ViewToggle,
  tags: ["autodocs"],
  args: {
    options: LAYOUTS,
    value: "list",
    label: "Layout",
    onChange: fn(),
  },
} satisfies Meta<typeof ViewToggle>

export default meta
type Story = StoryObj<typeof meta>

/**
 * Controlled, so the stories drive it through this wrapper: the selection is
 * held locally and the `onChange` arg still fires, so the Actions panel logs it.
 */
function Controlled({ value, onChange, ...props }: React.ComponentProps<typeof ViewToggle>) {
  const [current, setCurrent] = React.useState(value)
  return (
    <ViewToggle
      {...props}
      value={current}
      onChange={(next) => {
        setCurrent(next)
        onChange(next)
      }}
    />
  )
}

/** The document library's list/grid switch — the case this was extracted from. */
export const Default: Story = { render: (args) => <Controlled {...args} /> }

/** Labels beside the icons, for a toolbar with room and options that are not obvious. */
export const WithLabels: Story = {
  args: { showLabels: true },
  render: (args) => <Controlled {...args} />,
}

/** Three options, to show the control is not hard-wired to a pair. */
export const ThreeOptions: Story = {
  args: {
    options: [
      ...LAYOUTS,
      { value: "table", label: "Table", icon: <IconTable size={16} aria-hidden /> },
    ],
  },
  render: (args) => <Controlled {...args} />,
}

/**
 * An option the viewer cannot pick keeps its place and carries the CAUSE, rather
 * than disappearing — a hidden option makes "not available to you", "removed"
 * and "broken" pixel-identical.
 */
export const OneOptionUnavailable: Story = {
  args: {
    options: [
      ...LAYOUTS,
      {
        value: "table",
        label: "Table",
        icon: <IconTable size={16} aria-hidden />,
        disabledReason: "The table view needs column definitions this screen does not supply.",
      },
    ],
  },
  render: (args) => <Controlled {...args} />,
}
