/**
 * Data contract for the DemoCatalog pilot feature (the multi-client
 * extraction pilot). Deliberately generic — this is a stand-in resource used
 * to prove the extraction pattern, not a real product domain.
 */
export interface DemoCatalogItem {
  id: number
  name: string
  description: string | null
  status: "active" | "archived"
  createdAt: string | null
  updatedAt: string | null
}

/** The subset of an item a caller may set when creating or updating one. */
export interface DemoCatalogItemInput {
  id?: number
  name: string
  description?: string | null
  status?: "active" | "archived"
}

/**
 * The injected data-source adapter — the hard requirement of the pilot.
 * `DemoCatalogList`/`DemoCatalogDetail` never fetch directly; every data
 * access goes through an adapter instance the caller constructs and passes
 * in. web/ wires this to the DemoCatalog plugin's REST API (via its own
 * cookie-authenticated api-client); a desktop client wires the exact same
 * interface to local SQLite. Kept intentionally minimal and stable per the
 * brief — list/get/save only, no pagination/filtering params yet — since
 * both a server implementation and a SQLite implementation must keep
 * implementing it going forward.
 */
export interface DemoCatalogAdapter {
  list(): Promise<DemoCatalogItem[]>
  get(id: number): Promise<DemoCatalogItem | null>
  /** Creates when `input.id` is absent, updates in place otherwise. */
  save(input: DemoCatalogItemInput): Promise<DemoCatalogItem>
}
