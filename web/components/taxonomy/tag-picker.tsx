'use client';

import { useCallback, useEffect, useState } from 'react';
import { TagInput, type TagOption } from '@amroksaleh/ui/tag-input';
import { Button } from '@amroksaleh/ui/button';
import { useAuth } from '@/lib/auth-context';
import { useCapabilities } from '@/hooks/useCapabilities';
import { TAGS_MANAGE } from '@/lib/capabilities';

/** A tag row as returned by the taxonomy API (only the fields the picker uses). */
interface TagRow {
  id: number;
  name: string;
}

export interface TagPickerProps {
  /**
   * The opaque, caller-defined entity type the tags attach to (e.g. "invoice").
   * Passed through to the polymorphic `entity_tags` association verbatim.
   */
  entityType: string;
  /** The id of the entity within `entityType`. */
  entityId: number;
  className?: string;
}

/**
 * WC-621 tag picker: a reusable multi-select that attaches/detaches the
 * platform's native tags to ANY entity via the core `/api/v1/entity-tags`
 * endpoints. Presentation is the `@amroksaleh/ui` {@link TagInput} chips
 * control; this component owns the data + per-change mutations.
 *
 * It is a native app component (not a plugin DSL block): the DSL `source`
 * ownership gate only permits plugin-owned routes, and `entity-tags` is a core
 * route with per-tag attach/detach semantics rather than a single form submit.
 *
 * Server stays authoritative: writes are gated on `tags:manage` (the control is
 * read-only otherwise) and each toggle is one POST/DELETE. Changes are applied
 * optimistically and rolled back if the mutation fails.
 */
export function TagPicker({ entityType, entityId, className }: TagPickerProps) {
  const { apiClient } = useAuth();
  const { hasPermission } = useCapabilities();
  const canManage = hasPermission(TAGS_MANAGE);

  const [options, setOptions] = useState<TagOption[]>([]);
  const [selected, setSelected] = useState<string[]>([]);
  const [status, setStatus] = useState<'loading' | 'ready' | 'error'>('loading');
  const [busy, setBusy] = useState(false);

  // Bumping this re-runs the load effect (used by the error-state Retry).
  const [reloadKey, setReloadKey] = useState(0);

  useEffect(() => {
    let cancelled = false;
    // Inline async run so state updates are not synchronous in the effect body
    // (mirrors the shared useFetch hook; satisfies react-hooks/set-state-in-effect).
    const run = async () => {
      setStatus('loading');
      try {
        const [tagsRes, currentRes] = await Promise.all([
          apiClient('/api/v1/tags'),
          apiClient(
            `/api/v1/entity-tags?entity_type=${encodeURIComponent(entityType)}&entity_id=${encodeURIComponent(String(entityId))}`
          ),
        ]);
        if (cancelled) return;
        if (!tagsRes.ok || !currentRes.ok) {
          setStatus('error');
          return;
        }
        const tagsBody: unknown = await tagsRes.json();
        const currentBody: unknown = await currentRes.json();
        if (cancelled) return;
        setOptions(extractRows(tagsBody).map((t) => ({ value: String(t.id), label: t.name })));
        setSelected(extractRows(currentBody).map((t) => String(t.id)));
        setStatus('ready');
      } catch {
        if (!cancelled) setStatus('error');
      }
    };
    void run();
    return () => {
      cancelled = true;
    };
  }, [apiClient, entityType, entityId, reloadKey]);

  const handleChange = useCallback(
    async (next: string[]) => {
      const previous = selected;
      const added = next.filter((v) => !previous.includes(v));
      const removed = previous.filter((v) => !next.includes(v));

      // One POST/DELETE per toggle against the core entity-tags endpoint.
      const mutate = async (method: 'POST' | 'DELETE', tagValue: string) => {
        const res = await apiClient('/api/v1/entity-tags', {
          method,
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            entity_type: entityType,
            entity_id: entityId,
            tag_id: Number(tagValue),
          }),
        });
        if (!res.ok) {
          throw new Error(`entity-tags ${method} failed`);
        }
      };

      // Optimistic: reflect the change immediately, roll back on failure.
      setSelected(next);
      setBusy(true);
      try {
        for (const value of added) {
          await mutate('POST', value);
        }
        for (const value of removed) {
          await mutate('DELETE', value);
        }
      } catch {
        setSelected(previous);
      } finally {
        setBusy(false);
      }
    },
    [apiClient, entityType, entityId, selected]
  );

  if (status === 'loading') {
    return (
      <div className={className} data-slot="tag-picker-loading">
        <span className="text-xs text-muted-foreground">Loading tags…</span>
      </div>
    );
  }

  if (status === 'error') {
    return (
      <div
        className="flex items-center gap-3 rounded-lg border border-border bg-card p-2 text-xs text-muted-foreground"
        data-slot="tag-picker-error"
      >
        <span>Failed to load tags.</span>
        <Button type="button" variant="outline" size="sm" onClick={() => setReloadKey((k) => k + 1)}>
          Retry
        </Button>
      </div>
    );
  }

  return (
    <TagInput
      options={options}
      value={selected}
      onChange={(next) => void handleChange(next)}
      disabled={!canManage || busy}
      className={className}
      placeholder="Add a tag…"
    />
  );
}

/** Narrow a `{ data: TagRow[] }` envelope to its rows; anything else → []. */
function extractRows(body: unknown): TagRow[] {
  if (typeof body !== 'object' || body === null || !('data' in body)) {
    return [];
  }
  const data = (body as { data: unknown }).data;
  if (!Array.isArray(data)) {
    return [];
  }
  return data.flatMap((row): TagRow[] => {
    if (typeof row !== 'object' || row === null) return [];
    const id = (row as { id?: unknown }).id;
    const name = (row as { name?: unknown }).name;
    if (typeof id !== 'number' || typeof name !== 'string') return [];
    return [{ id, name }];
  });
}
