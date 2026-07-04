import type { Meta, StoryObj } from "@storybook/nextjs-vite"

import { PasswordStrengthIndicator } from "./PasswordStrengthIndicator"

const meta = {
  title: "App/PasswordStrengthIndicator",
  component: PasswordStrengthIndicator,
  tags: ["autodocs"],
  args: { password: "correct horse" },
} satisfies Meta<typeof PasswordStrengthIndicator>

export default meta
type Story = StoryObj<typeof meta>

export const Playground: Story = {
  render: (args) => (
    <div className="w-64">
      <PasswordStrengthIndicator {...args} />
    </div>
  ),
}

const samples = [
  { label: "Weak", password: "abc" },
  { label: "Fair", password: "abcABC12" },
  { label: "Good", password: "abcABC123!" },
  { label: "Strong", password: "C0rrect-Horse-Battery-9!" },
]

export const AllLevels: Story = {
  render: () => (
    <div className="flex w-64 flex-col gap-6">
      {samples.map((s) => (
        <div key={s.label}>
          <p className="text-muted-foreground mb-1 text-xs">{s.label}: {s.password}</p>
          <PasswordStrengthIndicator password={s.password} />
        </div>
      ))}
    </div>
  ),
}
