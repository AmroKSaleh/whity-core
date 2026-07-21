import type { Meta, StoryObj } from "@storybook/nextjs-vite"
import type { PluginFeature } from "@/lib/plugin-features"

import { CrudScreen } from "./crud-screen"
import { defaultHandlers, http, HttpResponse } from "../../.storybook/mocks"

const BASE = "/api/v1/notes"

const feature: PluginFeature = {
  id: "notes",
  plugin: "Notes",
  label: "Notes",
  icon: "notes",
  group: "plugins",
  order: 1,
  screen: "crud",
  resource: { basePath: BASE, titleField: "title" },
  action: null,
  embed: null,
  requiredPermission: "notes:read",
  capabilities: { canCreate: true, canEdit: true, canDelete: true },
}

// Minimal OpenAPI spec CrudScreen derives its columns + form fields from
// (fetched via a plain `fetch('/openapi.json')`). GET → { data: Note[] } gives
// columns; POST/PATCH/DELETE presence drives create/edit/delete capabilities.
const openapi = {
  paths: {
    [BASE]: {
      get: {
        responses: {
          "200": {
            content: {
              "application/json": {
                schema: {
                  type: "object",
                  properties: { data: { type: "array", items: { $ref: "#/components/schemas/Note" } } },
                },
              },
            },
          },
        },
      },
      post: {
        requestBody: {
          content: { "application/json": { schema: { $ref: "#/components/schemas/NoteInput" } } },
        },
      },
    },
    [`${BASE}/{id}`]: {
      patch: {
        requestBody: {
          content: { "application/json": { schema: { $ref: "#/components/schemas/NoteInput" } } },
        },
      },
      delete: {},
    },
  },
  components: {
    schemas: {
      Note: {
        type: "object",
        properties: {
          id: { type: "integer" },
          title: { type: "string" },
          status: { type: "string", enum: ["draft", "published"] },
          created_at: { type: "string" },
        },
      },
      NoteInput: {
        type: "object",
        required: ["title"],
        properties: {
          title: { type: "string", maxLength: 80 },
          body: { type: "string", maxLength: 200 },
          status: { type: "string", enum: ["draft", "published"] },
        },
      },
    },
  },
}

const rows = [
  { id: 1, title: "Launch checklist", status: "published", created_at: "2026-06-20" },
  { id: 2, title: "Retro notes", status: "draft", created_at: "2026-06-28" },
  { id: 3, title: "Q3 roadmap", status: "draft", created_at: "2026-07-02" },
]

const meta = {
  title: "App/Plugin/CrudScreen",
  component: CrudScreen,
  tags: ["autodocs"],
  parameters: { layout: "padded" },
  args: { feature },
} satisfies Meta<typeof CrudScreen>

export default meta
type Story = StoryObj<typeof meta>

export const WithRecords: Story = {
  parameters: {
    msw: {
      handlers: [
        http.get("*/openapi.json", () => HttpResponse.json(openapi)),
        http.get(`*${BASE}`, () => HttpResponse.json({ data: rows })),
        http.post(`*${BASE}`, () => HttpResponse.json({ data: { id: 99 } })),
        http.patch(`*${BASE}/:id`, () => HttpResponse.json({ data: rows[0] })),
        http.delete(`*${BASE}/:id`, () => new HttpResponse(null, { status: 204 })),
        ...defaultHandlers,
      ],
    },
  },
}

export const Empty: Story = {
  parameters: {
    msw: {
      handlers: [
        http.get("*/openapi.json", () => HttpResponse.json(openapi)),
        http.get(`*${BASE}`, () => HttpResponse.json({ data: [] })),
        ...defaultHandlers,
      ],
    },
  },
}

// WC-532: a resource with a LocalizedText field (`x-whity-localized-text`),
// exercising the list-view dir-preferred/fallback/"Untranslated" rendering
// and the BilingualInput-backed create/edit form field.
const QUESTIONS_BASE = "/api/v1/questions"

const questionsFeature: PluginFeature = {
  id: "questions",
  plugin: "Assessments",
  label: "Questions",
  icon: "notes",
  group: "plugins",
  order: 1,
  screen: "crud",
  resource: { basePath: QUESTIONS_BASE, titleField: null },
  action: null,
  embed: null,
  requiredPermission: "questions:read",
  capabilities: { canCreate: true, canEdit: true, canDelete: true },
}

const questionsOpenapi = {
  paths: {
    [QUESTIONS_BASE]: {
      get: {
        responses: {
          "200": {
            content: {
              "application/json": {
                schema: {
                  type: "object",
                  properties: { data: { type: "array", items: { $ref: "#/components/schemas/Question" } } },
                },
              },
            },
          },
        },
      },
      post: {
        requestBody: {
          content: { "application/json": { schema: { $ref: "#/components/schemas/QuestionInput" } } },
        },
      },
    },
    [`${QUESTIONS_BASE}/{id}`]: {
      patch: {
        requestBody: {
          content: { "application/json": { schema: { $ref: "#/components/schemas/QuestionInput" } } },
        },
      },
      delete: {},
    },
  },
  components: {
    schemas: {
      Question: {
        type: "object",
        properties: {
          id: { type: "integer" },
          stem: {
            type: "object",
            "x-whity-localized-text": true,
            properties: { ar: { type: "string" }, en: { type: "string" } },
          },
        },
      },
      QuestionInput: {
        type: "object",
        required: ["stem"],
        properties: {
          stem: {
            type: "object",
            "x-whity-localized-text": true,
            properties: { ar: { type: "string" }, en: { type: "string" } },
          },
        },
      },
    },
  },
}

const questionRows = [
  { id: 1, stem: { ar: "ما هي عاصمة فرنسا؟", en: "What is the capital of France?" } },
  { id: 2, stem: { en: "Untranslated-only stem (no Arabic yet)" } },
  { id: 3, stem: { ar: "سؤال بدون ترجمة إنجليزية" } },
  { id: 4, stem: {} },
]

export const LocalizedTextField: Story = {
  args: { feature: questionsFeature },
  parameters: {
    msw: {
      handlers: [
        http.get("*/openapi.json", () => HttpResponse.json(questionsOpenapi)),
        http.get(`*${QUESTIONS_BASE}`, () => HttpResponse.json({ data: questionRows })),
        http.post(`*${QUESTIONS_BASE}`, () => HttpResponse.json({ data: { id: 99 } })),
        http.patch(`*${QUESTIONS_BASE}/:id`, () => HttpResponse.json({ data: questionRows[0] })),
        http.delete(`*${QUESTIONS_BASE}/:id`, () => new HttpResponse(null, { status: 204 })),
        ...defaultHandlers,
      ],
    },
  },
}
