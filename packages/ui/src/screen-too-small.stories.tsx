import type { Meta, StoryObj } from "@storybook/react-vite"
import { IconRulerMeasure } from "@tabler/icons-react"

import { ScreenTooSmall } from "./screen-too-small"
import { Button } from "./button"

const meta = {
  title: "ScreenTooSmall",
  component: ScreenTooSmall,
  tags: ["autodocs"],
  parameters: {
    layout: "fullscreen",
    docs: {
      description: {
        component:
          "Full-page \"this screen is too small\" state, for a workspace that genuinely cannot be made useful on a phone — a canvas editor with a millimetre-accurate page and a properties rail, say. A sibling of `AccessDenied` and `LockedScreen`: domain-free, presentational, `role=\"alert\"`. Deliberately a GATE rather than responsive layout, because some tools are worse squeezed than honestly withheld — so reach for it only where that is true, and let the rest of the app adapt. Pair it with the `useViewportAtLeast(minWidth)` hook exported alongside, which returns `undefined` until the first client measurement so neither branch is guessed during SSR.",
      },
    },
  },
} satisfies Meta<typeof ScreenTooSmall>

export default meta
type Story = StoryObj<typeof meta>

/** Defaults — the minimum a caller has to supply is nothing at all. */
export const Default: Story = {}

/** The real document-editor copy: what's blocked, why, and where to go instead.
 *  An `action` keeps it from being a dead end. */
export const DocumentEditorGate: Story = {
  args: {
    title: "The document editor needs a larger screen",
    description:
      "Designing print-accurate labels and documents needs room for the page plus its layers and properties panels. Open this on a tablet in landscape, laptop or desktop.",
    minWidth: 1024,
    action: (
      <Button variant="outline" size="sm" className="mt-2">
        Back to dashboard
      </Button>
    ),
  },
}

/** Without `minWidth` the pixel hint is omitted — use it when the threshold is
 *  a detail the reader can't act on. */
export const WithoutWidthHint: Story = {
  args: {
    title: "Not available on this screen",
    description: "This view needs a wider window.",
  },
}

/** A custom icon, for a gate about something other than screen size. */
export const CustomIcon: Story = {
  args: {
    icon: <IconRulerMeasure />,
    title: "Window too narrow",
    description: "Widen the window to at least 1280px to use the side-by-side comparison.",
    minWidth: 1280,
  },
}
