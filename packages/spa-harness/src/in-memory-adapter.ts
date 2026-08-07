import type {
  DemoCatalogAdapter,
  DemoCatalogItem,
  DemoCatalogItemInput,
} from "@amroksaleh/features/demo-catalog"

/**
 * In-memory `DemoCatalogAdapter` implementation for the SPA harness — the
 * desktop-analogue stand-in for "wire the same interface to local SQLite".
 * `DemoCatalogList`/`DemoCatalogDetail` are otherwise byte-identical to what
 * web/ renders; only this file (and the Next.js-backed one at
 * web/lib/demo-catalog-adapter.ts) differs between the two clients.
 */
export function createInMemoryDemoCatalogAdapter(): DemoCatalogAdapter {
  let nextId = 3
  let items: DemoCatalogItem[] = [
    {
      id: 1,
      name: "Sample item",
      description: "Seeded by the in-memory adapter on harness startup.",
      status: "active",
      createdAt: new Date(0).toISOString(),
      updatedAt: new Date(0).toISOString(),
    },
    {
      id: 2,
      name: "Archived example",
      description: null,
      status: "archived",
      createdAt: new Date(0).toISOString(),
      updatedAt: new Date(0).toISOString(),
    },
  ]

  return {
    async list(): Promise<DemoCatalogItem[]> {
      return items
    },

    async get(id: number): Promise<DemoCatalogItem | null> {
      return items.find((item) => item.id === id) ?? null
    },

    async save(input: DemoCatalogItemInput): Promise<DemoCatalogItem> {
      const now = new Date().toISOString()

      if (input.id !== undefined) {
        const index = items.findIndex((item) => item.id === input.id)
        if (index === -1) {
          throw new Error(`No item with id ${input.id}`)
        }
        const updated: DemoCatalogItem = {
          ...items[index],
          name: input.name,
          description: input.description ?? null,
          status: input.status ?? items[index].status,
          updatedAt: now,
        }
        items = [...items.slice(0, index), updated, ...items.slice(index + 1)]
        return updated
      }

      const created: DemoCatalogItem = {
        id: nextId++,
        name: input.name,
        description: input.description ?? null,
        status: input.status ?? "active",
        createdAt: now,
        updatedAt: now,
      }
      items = [created, ...items]
      return created
    },
  }
}
