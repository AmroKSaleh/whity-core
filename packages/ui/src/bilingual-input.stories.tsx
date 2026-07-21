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

export const Empty: Story = {
  render: () => {
    const [value, setValue] = React.useState<BilingualValue>({})
    return <BilingualInput id="sb-bilingual-empty" value={value} onChange={setValue} />
  },
}

export const BothSet: Story = {
  render: () => {
    const [value, setValue] = React.useState<BilingualValue>({
      ar: "مرحبا",
      en: "Hello",
    })
    return <BilingualInput id="sb-bilingual-both" value={value} onChange={setValue} />
  },
}

export const PartiallyTranslated: Story = {
  render: () => {
    const [value, setValue] = React.useState<BilingualValue>({ en: "Untranslated stem" })
    return <BilingualInput id="sb-bilingual-partial" value={value} onChange={setValue} />
  },
}

export const CustomLabels: Story = {
  render: () => {
    const [value, setValue] = React.useState<BilingualValue>({})
    return (
      <BilingualInput
        id="sb-bilingual-custom"
        value={value}
        onChange={setValue}
        arLabel="السؤال (عربي)"
        enLabel="Question (English)"
        required
      />
    )
  },
}

export const Disabled: Story = {
  render: () => (
    <BilingualInput
      id="sb-bilingual-disabled"
      value={{ ar: "معطل", en: "Disabled" }}
      onChange={() => {}}
      disabled
    />
  ),
}
