import type { Meta, StoryObj } from "@storybook/react-vite"
import { fn } from "storybook/test"
import * as React from "react"

import { BilingualInput, type BilingualValue } from "./bilingual-input"

const meta = {
  title: "Primitives/BilingualInput",
  component: BilingualInput,
  tags: ["autodocs"],
  args: {
    value: {},
    onChange: fn(),
  },
} satisfies Meta<typeof BilingualInput>

export default meta
type Story = StoryObj<typeof meta>

/**
 * `BilingualInput` is fully controlled, so the interactive stories drive it
 * through this wrapper: the `value` arg seeds local state and the `onChange`
 * arg still fires — so the Actions panel logs edits — while the field stays
 * editable in the canvas.
 */
function ControlledBilingualInput({
  value,
  onChange,
  ...props
}: React.ComponentProps<typeof BilingualInput>) {
  const [current, setCurrent] = React.useState<BilingualValue>(value)

  return (
    <BilingualInput
      {...props}
      value={current}
      onChange={(next) => {
        setCurrent(next)
        onChange(next)
      }}
    />
  )
}

export const WithTitleAndBadges: Story = {
  args: {
    id: "sb-bilingual-title",
    label: "Role Title",
    description: "Provide the official role title in both primary languages.",
    value: { ar: "مدير النظام", en: "System Administrator" },
    required: true,
  },
  render: (args) => <ControlledBilingualInput {...args} />,
}

export const Empty: Story = {
  args: {
    id: "sb-bilingual-empty",
    label: "Department Name",
  },
  render: (args) => <ControlledBilingualInput {...args} />,
}

export const CustomLanguagePair: Story = {
  args: {
    id: "sb-bilingual-custom-langs",
    label: "Job Title (Europe)",
    primaryLang: { code: "fr", label: "French", badge: "FR" },
    secondaryLang: { code: "de", label: "German", badge: "DE" },
    value: { fr: "Directeur Général", de: "Geschäftsführer" },
  },
  render: (args) => <ControlledBilingualInput {...args} />,
}

export const Disabled: Story = {
  args: {
    id: "sb-bilingual-disabled",
    label: "Organization Name",
    value: { ar: "معطل", en: "Disabled" },
    disabled: true,
  },
}
