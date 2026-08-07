import type { Meta, StoryObj } from "@storybook/react-vite"

import { Input } from "./input"

const meta = {
  title: "Primitives/Input",
  component: Input,
  tags: ["autodocs"],
  args: { placeholder: "you@example.com" },
} satisfies Meta<typeof Input>

export default meta
type Story = StoryObj<typeof meta>

export const Playground: Story = {}

export const Types: Story = {
  render: () => (
    <div className="flex w-80 flex-col gap-4">
      <Input placeholder="Text" />
      <Input type="email" placeholder="Email" />
      <Input type="password" placeholder="Password" />
      <Input type="number" placeholder="42" />
      <Input type="search" placeholder="Search components..." />
      <Input type="date" />
      <Input type="time" />
      <Input type="range" defaultValue={65} min={0} max={100} label="Volume Slider Input" />
    </div>
  ),
}

export const DateTimeAndSearch: Story = {
  render: () => (
    <div className="flex w-80 flex-col gap-4">
      <Input
        type="search"
        label="Search Database"
        placeholder="Type to filter records..."
        tooltip="Performs instant client and server side search."
        helperText="Includes search icon and clear trigger."
      />
      <Input
        type="date"
        label="Start Date"
        required
        tooltip="Select event start date."
        helperText="Native date picker with custom calendar icon."
      />
      <Input
        type="time"
        label="Shift Start Time"
        required
        tooltip="Select shift time with 1-minute granularity and direct wheel scroll."
        helperText="Direct wheel scroll over columns ticks hours and minutes seamlessly."
      />
      <Input
        type="range"
        label="Brightness Control"
        defaultValue={75}
        tooltip="Adjust brightness level from 0 to 100%."
        helperText="Input type='range' renders custom Radix slider with value badge."
      />
    </div>
  ),
}

export const OutsideLabelAndTooltip: Story = {
  render: () => (
    <div className="flex w-80 flex-col gap-4">
      <Input
        label="Email"
        required
        tooltip="Enter your primary work email address."
        placeholder="alex@company.com"
        helperText="Clean static title positioned outside above the field."
      />
      <Input
        type="password"
        label="Password"
        required
        tooltip="Must contain at least 8 characters."
        placeholder="••••••••"
        helperText="Includes show/hide password toggle button."
      />
    </div>
  ),
}

export const ErrorAndNotes: Story = {
  render: () => (
    <div className="flex w-80 flex-col gap-4">
      <Input
        label="Username"
        required
        defaultValue="invalid_user_#99"
        aria-invalid
        errorText="Usernames can only contain letters, numbers, and underscores."
      />
      <Input
        label="Max Users"
        type="number"
        defaultValue={5}
        tooltip="Maximum seats allowed in team subscription."
        helperText="Includes custom stepper buttons."
      />
    </div>
  ),
}

export const DropzoneFileDefault: Story = {
  render: () => (
    <div className="flex w-96 flex-col gap-4">
      <Input
        type="file"
        multiple
        label="Attachment Uploads (Default Dropzone)"
        required
        tooltip="Drag files directly into the box or click to select."
        helperText="Default file upload style is now the prominent drag-and-drop box with larger file cards."
      />
    </div>
  ),
}

export const CompactFileInputAlternative: Story = {
  render: () => (
    <div className="flex w-96 flex-col gap-4">
      <div>
        <Input
          type="file"
          fileVariant="compact"
          label="Single File (Compact Variant)"
          helperText="Inline compact alternative file picker with pill badges."
        />
      </div>
      <div>
        <Input
          type="file"
          fileVariant="compact"
          multiple
          label="Multiple Files (Compact Variant)"
          helperText="Compact inline multi-file picker with interactive pills and remove buttons."
        />
      </div>
    </div>
  ),
}

export const States: Story = {
  render: () => (
    <div className="flex w-72 flex-col gap-3">
      <Input placeholder="Default" />
      <Input placeholder="Disabled" disabled />
      <Input placeholder="Invalid" aria-invalid defaultValue="not-an-email" />
    </div>
  ),
}
