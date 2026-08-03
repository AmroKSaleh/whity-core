import type { Meta, StoryObj } from "@storybook/react-vite"
import * as React from "react"

import { BilingualInput, type BilingualValue } from "./bilingual-input"

const meta = {
  title: "Primitives/BilingualInput",
  component: BilingualInput,
  tags: ["autodocs"],
} satisfies Meta<typeof BilingualInput>

export default meta
type Story = StoryObj<typeof meta>

export const WithTitleAndBadges: Story = {
  render: () => {
    const [value, setValue] = React.useState<BilingualValue>({
      ar: "مدير النظام",
      en: "System Administrator",
    })
    return (
      <BilingualInput
        id="sb-bilingual-title"
        label="Role Title"
        description="Provide the official role title in both primary languages."
        value={value}
        onChange={setValue}
        required
      />
    )
  },
}

export const Empty: Story = {
  render: () => {
    const [value, setValue] = React.useState<BilingualValue>({})
    return (
      <BilingualInput
        id="sb-bilingual-empty"
        label="Department Name"
        value={value}
        onChange={setValue}
      />
    )
  },
}

export const CustomLanguagePair: Story = {
  render: () => {
    const [value, setValue] = React.useState<BilingualValue>({
      fr: "Directeur Général",
      de: "Geschäftsführer",
    })
    return (
      <BilingualInput
        id="sb-bilingual-custom-langs"
        label="Job Title (Europe)"
        primaryLang={{ code: "fr", label: "French", badge: "FR" }}
        secondaryLang={{ code: "de", label: "German", badge: "DE" }}
        value={value}
        onChange={setValue}
      />
    )
  },
}

export const Disabled: Story = {
  render: () => (
    <BilingualInput
      id="sb-bilingual-disabled"
      label="Organization Name"
      value={{ ar: "معطل", en: "Disabled" }}
      onChange={() => {}}
      disabled
    />
  ),
}
