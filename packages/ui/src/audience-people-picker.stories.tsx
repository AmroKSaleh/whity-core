import type { Meta, StoryObj } from "@storybook/react-vite"
import { fn } from "storybook/test"
import * as React from "react"

import {
  AudiencePeoplePicker,
  type AudiencePersonOption,
} from "./audience-people-picker"

const PEOPLE: AudiencePersonOption[] = [
  { id: 11, name: "Aisha Karim", secondary: "aisha@demo.example.com" },
  { id: 12, name: "Omar Haddad", secondary: "omar@demo.example.com" },
  { id: 13, name: "Lena Farouk", secondary: "lena@demo.example.com" },
  { id: 14, name: "Yusuf Nabil", secondary: "yusuf@demo.example.com" },
  { id: 15, name: "Rania Saleh", secondary: "rania@demo.example.com" },
  { id: 16, name: "Karim Aziz", secondary: "karim@demo.example.com" },
  { id: 17, name: "Nour Adel", secondary: "nour@demo.example.com" },
  { id: 18, name: "Sami Darwish", secondary: "sami@demo.example.com" },
  { id: 19, name: "Hana Mansour", secondary: "hana@demo.example.com" },
  { id: 20, name: "Tarek Amin", secondary: "tarek@demo.example.com" },
]

const meta = {
  title: "Primitives/AudiencePeoplePicker",
  component: AudiencePeoplePicker,
  tags: ["autodocs"],
  args: {
    people: PEOPLE,
    value: [],
    onChange: fn(),
  },
} satisfies Meta<typeof AudiencePeoplePicker>

export default meta
type Story = StoryObj<typeof meta>

/** Fully controlled — the wrapper holds the selection so the canvas is usable. */
function ControlledPeoplePicker({
  value,
  onChange,
  ...props
}: React.ComponentProps<typeof AudiencePeoplePicker>) {
  const [current, setCurrent] = React.useState<number[]>(value)

  return (
    <AudiencePeoplePicker
      {...props}
      value={current}
      onChange={(next) => {
        setCurrent(next)
        onChange(next)
      }}
    />
  )
}

/**
 * The opening state: a search box and nothing else. Deliberately not the whole
 * roster — this control is for naming a handful, and listing everybody invites
 * the reading the `explicit` kind exists to avoid.
 */
export const Empty: Story = {
  render: (args) => <ControlledPeoplePicker {...args} />,
}

/** Three people named: the tender-committee case, which no computed rule expresses. */
export const WithSelection: Story = {
  args: { value: [11, 12, 13] },
  render: (args) => <ControlledPeoplePicker {...args} />,
}

/** A chosen id the catalogue does not cover — shown by id, never as a blank. */
export const UnknownPersonChosen: Story = {
  args: { value: [11, 999] },
  render: (args) => <ControlledPeoplePicker {...args} />,
}

/** Read-only: the chips remain legible, the search and remove controls go. */
export const Disabled: Story = {
  args: { value: [11, 12], disabled: true },
  render: (args) => <ControlledPeoplePicker {...args} />,
}

/** A reason, never an empty search box. */
export const Unavailable: Story = {
  args: {
    people: [],
    unavailableReason:
      "You cannot list people here, so a step cannot name one. An administrator would need to grant you users:read.",
  },
  render: (args) => <ControlledPeoplePicker {...args} />,
}

/** Right-to-left: chips, the remove control and the result rows all mirror. */
export const RightToLeft: Story = {
  args: { value: [11, 12] },
  render: (args) => (
    <div dir="rtl">
      <ControlledPeoplePicker {...args} />
    </div>
  ),
}
