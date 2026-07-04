import type { Meta, StoryObj } from "@storybook/nextjs-vite"
import type { Block } from "@/lib/plugin-features"

import { BlockRenderer } from "./block-renderer"
import { defaultHandlers, http, HttpResponse } from "../../../.storybook/mocks"

const meta = {
  title: "App/Plugin/BlockRenderer",
  component: BlockRenderer,
  tags: ["autodocs"],
  parameters: { layout: "padded" },
} satisfies Meta<typeof BlockRenderer>

export default meta
type Story = StoryObj<typeof meta>

// A broad tour of the static (non-fetching) block DSL.
const showcase: Block[] = [
  { type: "heading", level: 1, text: "Plugin dashboard" },
  { type: "text", value: "A platform-neutral screen described entirely as blocks.", tone: "muted" },
  {
    type: "grid",
    columns: 3,
    children: [
      { type: "stat", label: "Active users", value: "1,284", hint: "+12% MoM", trend: "up" },
      { type: "stat", label: "Errors", value: "3", hint: "last 24h", trend: "down" },
      { type: "stat", label: "Uptime", value: "99.98%", trend: "flat" },
    ],
  },
  {
    type: "card",
    title: "Status",
    description: "Current deployment health.",
    children: [
      {
        type: "row",
        align: "between",
        children: [
          { type: "badge", variant: "success", label: "Healthy" },
          { type: "badge", variant: "warning", label: "1 warning" },
          { type: "badge", variant: "info", label: "v2.4.0" },
        ],
      },
      { type: "divider" },
      {
        type: "keyValue",
        items: [
          { label: "Region", value: "eu-central" },
          { label: "Plan", value: "Sovereign" },
          { label: "Last deploy", value: "2026-07-02" },
        ],
      },
    ],
  },
  {
    type: "tabs",
    children: [
      {
        type: "tab",
        label: "Overview",
        children: [
          { type: "text", value: "Overview tab content." },
          { type: "list", ordered: false, items: ["Item one", "Item two", "Item three"] },
        ],
      },
      {
        type: "tab",
        label: "Table",
        children: [
          {
            type: "table",
            columns: [
              { key: "name", label: "Name" },
              { key: "role", label: "Role" },
            ],
            rows: [
              { name: "Ada", role: "admin" },
              { name: "Alan", role: "editor" },
            ],
          },
        ],
      },
    ],
  },
  { type: "alert", variant: "info", title: "Heads up", body: "This screen is rendered from a block tree." },
  { type: "code", language: "json", content: '{ "type": "heading", "level": 1, "text": "Hello" }' },
]

export const Showcase: Story = {
  args: { blocks: showcase },
}

// Data-bound leaves fetch their content at runtime from `source`.
const dataBound: Block[] = [
  { type: "heading", level: 2, text: "Live data" },
  {
    type: "dataTable",
    source: "/api/v1/metrics/rows",
    columns: [
      { key: "day", label: "Day" },
      { key: "signups", label: "Signups" },
    ],
    emptyText: "No rows yet.",
  },
  { type: "dataStat", source: "/api/v1/metrics/summary", label: "Total signups", valueField: "total", hintField: "hint" },
  { type: "dataList", source: "/api/v1/metrics/events", itemField: "message", ordered: true },
]

export const DataBound: Story = {
  args: { blocks: dataBound },
  parameters: {
    msw: {
      // usePluginData expects a `{ data: ... }` envelope; a bare array/object
      // renders the block's error state instead of the content.
      handlers: [
        http.get("*/api/v1/metrics/rows", () =>
          HttpResponse.json({
            data: [
              { day: "Mon", signups: "42" },
              { day: "Tue", signups: "51" },
            ],
          })
        ),
        http.get("*/api/v1/metrics/summary", () =>
          HttpResponse.json({ data: { total: "1,284", hint: "+12% MoM" } })
        ),
        http.get("*/api/v1/metrics/events", () =>
          HttpResponse.json({ data: [{ message: "Deploy succeeded" }, { message: "Cache warmed" }] })
        ),
        ...defaultHandlers,
      ],
    },
  },
}

// A `form` container with interactive input leaves.
const formBlocks: Block[] = [
  { type: "heading", level: 2, text: "Create record" },
  {
    type: "form",
    submit: { method: "POST", endpoint: "/api/v1/records" },
    children: [
      { type: "textInput", name: "title", label: "Title", placeholder: "Enter a title", required: true },
      { type: "textArea", name: "notes", label: "Notes", rows: 3 },
      {
        type: "select",
        name: "status",
        label: "Status",
        options: [
          { value: "draft", label: "Draft" },
          { value: "published", label: "Published" },
        ],
        default: "draft",
      },
      { type: "checkbox", name: "pinned", label: "Pin to top", default: false },
    ],
  },
]

export const InteractiveForm: Story = {
  args: { blocks: formBlocks },
  parameters: {
    msw: {
      handlers: [
        http.post("*/api/v1/records", () => HttpResponse.json({ ok: true })),
        ...defaultHandlers,
      ],
    },
  },
}
