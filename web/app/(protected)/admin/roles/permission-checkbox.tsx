'use client';

import { useMemo, useState } from 'react';
import { Button } from '@amroksaleh/ui/button';
import { Input } from '@amroksaleh/ui/input';
import { IconChevronDown, IconSearch } from '@tabler/icons-react';
import type { Permission } from './types';

interface PermissionCheckboxProps {
  permissions: Permission[];
  selectedIds: number[];
  onChange: (selectedIds: number[]) => void;
}

/** Group key = the resource segment before the first colon (e.g. users:write → users). */
function groupOf(name: string): string {
  const i = name.indexOf(':');
  return i > 0 ? name.slice(0, i) : 'general';
}

const title = (s: string) => s.charAt(0).toUpperCase() + s.slice(1);

/**
 * Permission picker for the granular RBAC role editor. Presents permissions
 * GROUPED by resource with a live filter, per-group tri-state select-all, and a
 * global select-all — so assigning across a large, granular permission set is
 * fast instead of scrolling one flat list. The external contract (a toggle
 * showing "N selected", `<label>`-wrapped rows, and a Select All/Deselect All
 * control) is preserved so it drops into the create/edit modals unchanged.
 */
export function PermissionCheckbox({ permissions, selectedIds, onChange }: PermissionCheckboxProps) {
  const [isOpen, setIsOpen] = useState(false);
  const [query, setQuery] = useState('');
  const [collapsed, setCollapsed] = useState<Set<string>>(new Set());

  const selected = useMemo(() => new Set(selectedIds), [selectedIds]);
  // Tolerate a non-array (defensive against malformed responses).
  const all = useMemo(() => (Array.isArray(permissions) ? permissions : []), [permissions]);

  // Filter, then group by resource (sorted groups, stable within group).
  const groups = useMemo(() => {
    const q = query.trim().toLowerCase();
    const filtered = q
      ? all.filter(
          (p) => (p.name ?? '').toLowerCase().includes(q) || (p.description ?? '').toLowerCase().includes(q)
        )
      : all;
    const map = new Map<string, Permission[]>();
    for (const p of filtered) {
      const g = groupOf(p.name ?? '');
      const list = map.get(g);
      if (list) list.push(p);
      else map.set(g, [p]);
    }
    return [...map.entries()].sort(([a], [b]) => a.localeCompare(b));
  }, [all, query]);

  const toggle = (id: number) => {
    if (selected.has(id)) onChange(selectedIds.filter((sid) => sid !== id));
    else onChange([...selectedIds, id]);
  };

  const setGroup = (perms: Permission[], on: boolean) => {
    const ids = new Set(perms.map((p) => p.id));
    if (on) onChange([...new Set([...selectedIds, ...ids])]);
    else onChange(selectedIds.filter((id) => !ids.has(id)));
  };

  const toggleGlobal = () => {
    if (selectedIds.length === all.length) onChange([]);
    else onChange(all.map((p) => p.id));
  };

  const selectedCount = selectedIds.length;
  const totalCount = all.length;

  return (
    <div className="relative w-full">
      <Button
        type="button"
        variant="outline"
        onClick={() => setIsOpen(!isOpen)}
        className="w-full justify-between text-start font-normal"
      >
        <span className="truncate">
          {selectedCount === 0
            ? 'Select permissions...'
            : `${selectedCount} permission${selectedCount !== 1 ? 's' : ''} selected`}
        </span>
        <IconChevronDown size={16} className={`transition-transform ${isOpen ? 'rotate-180' : ''}`} />
      </Button>

      {isOpen && (
        <div className="absolute top-full inset-x-0 z-50 mt-1 rounded-md border border-border bg-popover shadow-lg">
          <div className="sticky top-0 space-y-2 border-b border-border bg-popover p-2">
            <div className="relative">
              <IconSearch size={14} className="pointer-events-none absolute inset-s-2 top-1/2 -translate-y-1/2 text-muted-foreground" />
              <Input
                data-testid="perm-search"
                value={query}
                onChange={(e) => setQuery(e.target.value)}
                placeholder="Filter permissions…"
                className="h-8 ps-7 text-sm"
              />
            </div>
            <div className="flex items-center justify-between px-1">
              <span className="text-xs text-muted-foreground" data-testid="perm-summary">
                {selectedCount} of {totalCount} selected
              </span>
              <button
                type="button"
                onClick={toggleGlobal}
                className="rounded px-2 py-1 text-xs font-medium text-foreground hover:bg-muted"
              >
                {selectedCount === totalCount && totalCount > 0 ? 'Deselect All' : 'Select All'}
              </button>
            </div>
          </div>

          <div className="max-h-80 overflow-y-auto p-2">
            {groups.length === 0 ? (
              <div className="px-2 py-4 text-center text-sm text-muted-foreground">No permissions match.</div>
            ) : (
              groups.map(([group, perms]) => {
                const inGroup = perms.filter((p) => selected.has(p.id)).length;
                const allOn = inGroup === perms.length;
                const isCollapsed = collapsed.has(group);
                return (
                  <div key={group} className="mb-2" data-testid={`perm-group-${group}`}>
                    <div className="flex items-center gap-2 rounded bg-muted/40 px-2 py-1">
                      <input
                        type="checkbox"
                        aria-label={`Select all ${group} permissions`}
                        data-testid={`perm-group-toggle-${group}`}
                        checked={allOn}
                        ref={(el) => {
                          if (el) el.indeterminate = inGroup > 0 && !allOn;
                        }}
                        onChange={() => setGroup(perms, !allOn)}
                        className="h-4 w-4 rounded border-border text-primary focus:ring-2 focus:ring-ring"
                      />
                      <button
                        type="button"
                        onClick={() =>
                          setCollapsed((prev) => {
                            const next = new Set(prev);
                            if (next.has(group)) next.delete(group);
                            else next.add(group);
                            return next;
                          })
                        }
                        className="flex flex-1 items-center justify-between text-start"
                      >
                        <span className="text-xs font-semibold uppercase tracking-wide text-foreground">{title(group)}</span>
                        <span className="flex items-center gap-1 text-[10px] text-muted-foreground">
                          {inGroup}/{perms.length}
                          <IconChevronDown size={12} className={`transition-transform ${isCollapsed ? '-rotate-90' : ''}`} />
                        </span>
                      </button>
                    </div>
                    {!isCollapsed && (
                      <div className="mt-1 space-y-1 ps-1">
                        {perms.map((permission) => (
                          <label
                            key={permission.id}
                            className="flex cursor-pointer items-start gap-2 rounded p-2 hover:bg-muted"
                          >
                            <input
                              type="checkbox"
                              checked={selected.has(permission.id)}
                              onChange={() => toggle(permission.id)}
                              className="mt-0.5 h-4 w-4 rounded border-border text-primary focus:ring-2 focus:ring-ring"
                            />
                            <span className="min-w-0 flex-1">
                              <span className="block font-mono text-sm text-foreground">{permission.name}</span>
                              {permission.description && (
                                <span className="block text-xs text-muted-foreground">{permission.description}</span>
                              )}
                            </span>
                          </label>
                        ))}
                      </div>
                    )}
                  </div>
                );
              })
            )}
          </div>
        </div>
      )}
    </div>
  );
}
