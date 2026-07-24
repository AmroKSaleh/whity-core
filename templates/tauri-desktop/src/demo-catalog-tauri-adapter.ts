import { invoke } from "@tauri-apps/api/core"
import type {
  DemoCatalogAdapter,
  DemoCatalogItem,
  DemoCatalogItemInput,
} from "@amroksaleh/features/demo-catalog"

/**
 * The desktop implementation of `DemoCatalogAdapter` — wires the exact same
 * `DemoCatalogList`/`DemoCatalogDetail` components web/ and
 * packages/spa-harness render to a REAL local SQLite database instead of a
 * server API or an in-memory store.
 *
 * Every method is a thin call into a Rust `#[tauri::command]` (see
 * src-tauri/src/commands/items.rs) — this file is the ONLY thing that
 * changes between web's server-backed adapter
 * (web/lib/demo-catalog-adapter.ts), the SPA harness's in-memory adapter
 * (packages/spa-harness/src/in-memory-adapter.ts), and this one.
 * `DemoCatalogList`/`DemoCatalogDetail` themselves are unmodified.
 */
export const demoCatalogAdapter: DemoCatalogAdapter = {
  list(): Promise<DemoCatalogItem[]> {
    return invoke<DemoCatalogItem[]>("list_items")
  },

  get(id: number): Promise<DemoCatalogItem | null> {
    return invoke<DemoCatalogItem | null>("get_item", { id })
  },

  save(input: DemoCatalogItemInput): Promise<DemoCatalogItem> {
    return invoke<DemoCatalogItem>("save_item", { input })
  },
}
