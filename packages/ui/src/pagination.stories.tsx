import type { Meta, StoryObj } from "@storybook/react-vite"
import * as React from "react"

import { Pagination } from "./pagination"

const meta = {
  title: "Primitives/Pagination",
  component: Pagination,
  tags: ["autodocs"],
} satisfies Meta<typeof Pagination>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {
  args: { page: 2, perPage: 25, total: 214, onPageChange: () => {} },
  render: (args) => {
    const [page, setPage] = React.useState(args.page)
    return <Pagination {...args} page={page} onPageChange={setPage} />
  },
}

export const FirstPage: Story = {
  args: { page: 1, perPage: 25, total: 214, onPageChange: () => {} },
  render: (args) => {
    const [page, setPage] = React.useState(args.page)
    return <Pagination {...args} page={page} onPageChange={setPage} />
  },
}

export const LastPage: Story = {
  args: { page: 9, perPage: 25, total: 214, onPageChange: () => {} },
  render: (args) => {
    const [page, setPage] = React.useState(args.page)
    return <Pagination {...args} page={page} onPageChange={setPage} />
  },
}

export const SinglePage: Story = {
  args: { page: 1, perPage: 25, total: 12, onPageChange: () => {} },
  render: (args) => {
    const [page, setPage] = React.useState(args.page)
    return <Pagination {...args} page={page} onPageChange={setPage} />
  },
}
