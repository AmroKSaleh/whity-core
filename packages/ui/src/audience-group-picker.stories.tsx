import type { Meta, StoryObj } from "@storybook/react-vite"
import { fn } from "storybook/test"
import * as React from "react"

import {
  AudienceGroupPicker,
  type AudienceGroupOption,
  type AudienceGroupPreview,
} from "./audience-group-picker"

const GROUPS: AudienceGroupOption[] = [
  {
    id: 1,
    name: "Instructors",
    description: "Everyone holding the instructor role, anywhere in the faculty.",
  },
  {
    id: 2,
    name: "Department heads",
    description: "Everyone holding the department-head role.",
  },
  { id: 3, name: "Tender committee", description: null },
]

const SMALL_PREVIEW: AudienceGroupPreview = {
  total: 3,
  truncated: false,
  sampleSize: 10,
  members: [
    { profileId: 11, displayName: "Aisha Karim" },
    { profileId: 12, displayName: "Omar Haddad" },
    { profileId: 13, displayName: "Lena Farouk" },
  ],
}

const LARGE_PREVIEW: AudienceGroupPreview = {
  total: 1043,
  truncated: true,
  sampleSize: 5,
  members: [
    { profileId: 11, displayName: "Aisha Karim" },
    { profileId: 12, displayName: "Omar Haddad" },
    { profileId: 13, displayName: "Lena Farouk" },
    { profileId: 14, displayName: "Yusuf Nabil" },
    { profileId: 15, displayName: "Rania Saleh" },
  ],
}

const meta = {
  title: "Primitives/AudienceGroupPicker",
  component: AudienceGroupPicker,
  tags: ["autodocs"],
  args: {
    groups: GROUPS,
    value: null,
    onChange: fn(),
  },
} satisfies Meta<typeof AudienceGroupPicker>

export default meta
type Story = StoryObj<typeof meta>

/**
 * The picker is fully controlled, so the interactive stories drive it through
 * this wrapper: the `value` arg seeds local state and `onChange` still fires so
 * the Actions panel logs the choice.
 */
function ControlledPicker({
  value,
  onChange,
  ...props
}: React.ComponentProps<typeof AudienceGroupPicker>) {
  const [current, setCurrent] = React.useState<number | null>(value)

  return (
    <AudienceGroupPicker
      {...props}
      value={current}
      onChange={(next) => {
        setCurrent(next)
        onChange(next)
      }}
    />
  )
}

/** Nothing chosen: a placeholder, and no preview to show yet. */
export const Unchosen: Story = {
  render: (args) => <ControlledPicker {...args} />,
}

/**
 * A small group where the sample IS the membership — the picker says so, rather
 * than leaving the reader to compare two numbers.
 */
export const SmallGroup: Story = {
  args: { value: 3, preview: SMALL_PREVIEW, previewStatus: "ready" },
  render: (args) => <ControlledPicker {...args} />,
}

/**
 * The case the whole design exists for: four figures of people, one row. The
 * sample is labelled as a sample, and the dynamic-membership note sits under it.
 */
export const LargeGroup: Story = {
  args: { value: 1, preview: LARGE_PREVIEW, previewStatus: "ready" },
  render: (args) => <ControlledPicker {...args} />,
}

/**
 * A rule nobody currently matches. Not an error — a fact about the organisation
 * that the author needs before they commit, not after the document reaches
 * nobody.
 */
export const ResolvesToNobody: Story = {
  args: {
    value: 2,
    preview: { total: 0, truncated: false, sampleSize: 10, members: [] },
    previewStatus: "ready",
  },
  render: (args) => <ControlledPicker {...args} />,
}

/**
 * The reader may see group definitions but not people's names (`groups:read`
 * without `users:read`). Same payload shape, ids where the names would be.
 */
export const WithoutNames: Story = {
  args: {
    value: 1,
    previewStatus: "ready",
    preview: {
      total: 2,
      truncated: false,
      sampleSize: 10,
      members: [
        { profileId: 11, displayName: null },
        { profileId: 12, displayName: null },
      ],
    },
  },
  render: (args) => <ControlledPicker {...args} />,
}

export const PreviewLoading: Story = {
  args: { value: 1, previewStatus: "loading" },
  render: (args) => <ControlledPicker {...args} />,
}

/** The server's refusal, verbatim, with a way back. */
export const PreviewFailed: Story = {
  args: {
    value: 1,
    previewStatus: "error",
    previewError: "user group 1 does not exist in this tenant.",
    onRetryPreview: fn(),
  },
  render: (args) => <ControlledPicker {...args} />,
}

/**
 * A reason, never an empty dropdown: the author learns the list is missing
 * because of a permission, not because the workspace has no groups.
 */
export const Unavailable: Story = {
  args: {
    groups: [],
    unavailableReason:
      "You cannot list user groups here, so a step cannot name one. An administrator would need to grant you groups:read.",
  },
  render: (args) => <ControlledPicker {...args} />,
}

/** The catalogue is genuinely empty — a different statement, said differently. */
export const NoGroupsDefined: Story = {
  args: { groups: [] },
  render: (args) => <ControlledPicker {...args} />,
}

/**
 * A saved step naming a group the reader's list does not contain — deleted, or
 * past the pages they could load. Said out loud, because Radix would otherwise
 * draw the placeholder and the step would read as unconfigured.
 */
export const NamesAGroupNotInTheList: Story = {
  args: { value: 404 },
  render: (args) => <ControlledPicker {...args} />,
}

/** Right-to-left: the kit ships into Arabic UIs, so every story must survive it. */
export const RightToLeft: Story = {
  args: { value: 1, preview: LARGE_PREVIEW, previewStatus: "ready" },
  render: (args) => (
    <div dir="rtl">
      <ControlledPicker {...args} />
    </div>
  ),
}
