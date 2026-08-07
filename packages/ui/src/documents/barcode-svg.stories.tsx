import type { Meta, StoryObj } from "@storybook/react-vite"

import { BarcodeSvg } from "./barcode-svg"

/** Fixed-size frame — `BarcodeSvg` fills 100% of its container, matching how
 * the document/label designer always places it inside an absolutely-sized
 * element box. */
function Frame({ w, h, children }: { w: number; h: number; children: React.ReactNode }) {
  return (
    <div style={{ width: w, height: h, border: "1px dashed var(--border)", padding: 4 }}>{children}</div>
  )
}

const meta = {
  title: "Documents/BarcodeSvg",
  component: BarcodeSvg,
  tags: ["autodocs"],
  argTypes: {
    symbology: { control: "text" },
    value: { control: "text" },
    showText: { control: "boolean" },
    eclevel: { control: "select", options: ["L", "M", "Q", "H"] },
  },
  args: { symbology: "code128", value: "012345678905", showText: true },
} satisfies Meta<typeof BarcodeSvg>

export default meta
type Story = StoryObj<typeof meta>

export const Barcode: Story = {
  render: (args) => (
    <Frame w={220} h={80}>
      <BarcodeSvg {...args} />
    </Frame>
  ),
}

export const BarcodeWithoutHumanReadableText: Story = {
  args: { showText: false },
  render: (args) => (
    <Frame w={220} h={80}>
      <BarcodeSvg {...args} />
    </Frame>
  ),
}

export const QrLowErrorCorrection: Story = {
  args: { symbology: "qrcode", value: "https://whity.jameedium.org", eclevel: "L", showText: false },
  render: (args) => (
    <Frame w={160} h={160}>
      <BarcodeSvg {...args} />
    </Frame>
  ),
}

export const QrHighErrorCorrection: Story = {
  args: { symbology: "qrcode", value: "https://whity.jameedium.org", eclevel: "H", showText: false },
  render: (args) => (
    <Frame w={160} h={160}>
      <BarcodeSvg {...args} />
    </Frame>
  ),
}

export const InvalidOrEmptyValue: Story = {
  args: { value: "" },
  render: (args) => (
    <Frame w={220} h={80}>
      <BarcodeSvg {...args} />
    </Frame>
  ),
}
