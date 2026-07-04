import * as React from "react"
import type { Meta, StoryObj } from "@storybook/nextjs-vite"
import { Button } from "@amroksaleh/ui/button"

import { ToastContainer } from "./toast-container"
import { useToast, type ToastType } from "@/lib/toast-context"

const meta = {
  title: "App/UI/ToastContainer",
  component: ToastContainer,
  tags: ["autodocs"],
} satisfies Meta<typeof ToastContainer>

export default meta
type Story = StoryObj<typeof meta>

const TYPES: ToastType[] = ["success", "error", "warning", "info"]

// The container reads from ToastProvider (mounted by the global decorator);
// this harness pushes one toast of each type so all variants are visible.
function Seeded() {
  const { addToast } = useToast()
  React.useEffect(() => {
    TYPES.forEach((type, i) =>
      addToast(`This is a ${type} toast`, type, 0 /* no auto-dismiss */)
    )
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])
  return (
    <>
      <div className="flex flex-wrap gap-2">
        {TYPES.map((type) => (
          <Button key={type} size="sm" variant="outline" onClick={() => addToast(`Another ${type}`, type)}>
            Add {type}
          </Button>
        ))}
      </div>
      <ToastContainer />
    </>
  )
}

export const AllTypes: Story = {
  render: () => <Seeded />,
}
