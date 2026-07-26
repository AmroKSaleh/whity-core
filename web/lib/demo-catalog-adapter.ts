/**
 * web/'s DemoCatalogAdapter implementation (multi-client feature-extraction
 * pilot) — wires the data-source-agnostic `DemoCatalogList`/`DemoCatalogDetail`
 * components (@amroksaleh/features/demo-catalog) to the DemoCatalog plugin's
 * REST API via web's own cookie-authenticated `apiClient` (silent token
 * refresh on 401, same as every other authenticated fetch in this app — see
 * `lib/plugin-features.ts` for the same pattern).
 *
 * A desktop/Tauri client implements the exact same `DemoCatalogAdapter`
 * interface against local SQLite instead — this file is the ONLY thing that
 * changes between the two; `DemoCatalogList`/`DemoCatalogDetail` themselves
 * are unmodified.
 */

import { apiClient } from '@/lib/api-client';
import type {
  DemoCatalogAdapter,
  DemoCatalogItem,
  DemoCatalogItemInput,
} from '@amroksaleh/features/demo-catalog';

function isItemResponse(body: unknown): body is { data: DemoCatalogItem } {
  return typeof body === 'object' && body !== null && 'data' in body;
}

function isItemListResponse(body: unknown): body is { data: DemoCatalogItem[] } {
  if (typeof body !== 'object' || body === null || !('data' in body)) {
    return false;
  }
  return Array.isArray((body as { data: unknown }).data);
}

export const demoCatalogAdapter: DemoCatalogAdapter = {
  async list(): Promise<DemoCatalogItem[]> {
    const response = await apiClient('/api/v1/demo-catalog/items');
    if (!response.ok) {
      throw new Error(`Failed to list demo-catalog items (${response.status})`);
    }
    const body: unknown = await response.json();
    if (!isItemListResponse(body)) {
      throw new Error('Malformed demo-catalog list response');
    }
    return body.data;
  },

  async get(id: number): Promise<DemoCatalogItem | null> {
    const response = await apiClient(`/api/v1/demo-catalog/items/${id}`);
    if (response.status === 404) {
      return null;
    }
    if (!response.ok) {
      throw new Error(`Failed to fetch demo-catalog item ${id} (${response.status})`);
    }
    const body: unknown = await response.json();
    if (!isItemResponse(body)) {
      throw new Error('Malformed demo-catalog item response');
    }
    return body.data;
  },

  async save(input: DemoCatalogItemInput): Promise<DemoCatalogItem> {
    const isUpdate = input.id !== undefined;
    const response = await apiClient(
      isUpdate ? `/api/v1/demo-catalog/items/${input.id}` : '/api/v1/demo-catalog/items',
      {
        method: isUpdate ? 'PATCH' : 'POST',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({
          name: input.name,
          description: input.description ?? null,
          status: input.status ?? 'active',
        }),
      }
    );
    if (!response.ok) {
      throw new Error(`Failed to save demo-catalog item (${response.status})`);
    }
    const body: unknown = await response.json();
    if (!isItemResponse(body)) {
      throw new Error('Malformed demo-catalog save response');
    }
    return body.data;
  },
};
