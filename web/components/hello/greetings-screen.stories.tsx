import type { Meta, StoryObj } from "@storybook/nextjs-vite"
import type { PluginFeature } from "@/lib/plugin-features"

import { HelloGreetingsScreen } from "./greetings-screen"
import { defaultHandlers, http, HttpResponse } from "../../.storybook/mocks"

const feature: PluginFeature = {
  id: "greetings",
  plugin: "HelloWorld",
  label: "Greetings",
  icon: "message-circle",
  group: "plugins",
  order: 1,
  screen: "custom",
  resource: { basePath: "/api/v1/hello/greetings", titleField: "message" },
  action: null,
  embed: null,
  requiredPermission: "hello:read",
  capabilities: { canCreate: true, canEdit: true, canDelete: true },
}

const meta = {
  title: "App/Plugin/HelloGreetingsScreen",
  component: HelloGreetingsScreen,
  tags: ["autodocs"],
  parameters: { layout: "padded" },
  args: { feature },
} satisfies Meta<typeof HelloGreetingsScreen>

export default meta
type Story = StoryObj<typeof meta>

const greetings = [
  { id: 1, message: "Hello, world!", createdAt: "2026-06-30 09:12" },
  { id: 2, message: "Welcome aboard.", createdAt: "2026-07-01 14:03" },
]

export const WithData: Story = {
  parameters: {
    msw: {
      handlers: [
        http.get("*/api/v1/hello/greetings", () => HttpResponse.json({ data: greetings })),
        http.post("*/api/v1/hello/greetings", () => HttpResponse.json({ ok: true })),
        ...defaultHandlers,
      ],
    },
  },
}

export const EmptyList: Story = {
  parameters: {
    msw: {
      handlers: [
        http.get("*/api/v1/hello/greetings", () => HttpResponse.json({ data: [] })),
        ...defaultHandlers,
      ],
    },
  },
}

export const Forbidden: Story = {
  parameters: {
    msw: {
      handlers: [
        http.get("*/api/v1/hello/greetings", () => new HttpResponse(null, { status: 403 })),
        ...defaultHandlers,
      ],
    },
  },
}
