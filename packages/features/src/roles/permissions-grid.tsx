'use client';

/**
 * Injected-translator keys this file renders through `t`. Declared here for
 * the i18n catalogue extractor: it cannot infer a domain from a prop-injected
 * translator (see RolesTranslate — deliberately NOT typed `TranslateFn`, so
 * these files stay unscanned like DemoCatalog does via NavTranslate), so the
 * keys are enumerated below instead. Feature copy resolves in the `admin`
 * domain, shared UI chrome in `common`.
 *
 * @i18n-keys admin
 *   roles.permissionsGrid.collapseAll = Collapse all
 *   roles.permissionsGrid.expandAll = Expand all
 *   roles.permissionsGrid.filterPlaceholder = Filter permissions…
 *   roles.permissionsGrid.groupSelectAll = Select all {group} permissions
 *   roles.permissionsGrid.noMatch = No permissions match “{query}”.
 *   roles.permissionsGrid.none = No permissions are defined on this installation.
 *   roles.permissionsGrid.readOnlyNone = This role has no permissions.
 *   roles.permissionsGrid.selectAll = Select all
 *   roles.permissionsGrid.selectNone = Clear all
 *   roles.permissionsGrid.summary = {selected} of {total} selected
 */

import { useMemo, useState } from 'react';
import { Button } from '@amroksaleh/ui/button';
import { Input } from '@amroksaleh/ui/input';
import { IconChevronDown, IconSearch } from '@tabler/icons-react';
import { groupPermissions, titleCase } from './permission-groups';
import type { Permission, RolesTranslate } from './types';

interface PermissionsGridProps {
  /** The full catalogue to choose from (edit mode), or the role's own set (read-only). */
  permissions: Permission[];
  selectedIds: number[];
  onChange: (selectedIds: number[]) => void;
  /** Injected translator (resolved by the record screen). */
  t: RolesTranslate;
  /**
   * Render as a static, non-interactive list. The record page uses this when the
   * caller lacks `roles:write` or the role is a global base role: a read-only
   * page shows the same information laid out the same way, with no inputs to
   * change — rather than a form that 403s or 404s on save.
   */
  readOnly?: boolean;
}

/**
 * The in-page permissions editor for the role RECORD page (#882).
 *
 * WHY THIS IS NOT `PermissionCheckbox`. That component is a picker built for a
 * modal: a trigger button that opens a popover whose body is `max-h-80
 * overflow-y-auto`. Inside a dialog that is itself `max-h-[90vh]
 * overflow-y-auto`, 53 core permissions plus every plugin-declared one land in a
 * ~320px window nested inside another scroll region. No amount of styling fixes
 * a set that size in that space, and that is the concrete complaint the record
 * page exists to answer.
 *
 * So this renders the SAME data with the opposite spatial assumption: the page
 * has room, so use it. Every group is laid out at once in a responsive column
 * grid, expanded by default, with NO height cap and NO scroll container of its
 * own anywhere — the page's own scroll is the only one, which is what makes the
 * browser's find-in-page work across the whole set and what stops a group being
 * discoverable only by scrolling inside a box you first had to open.
 *
 * What it keeps from the picker, because those parts were right: a live filter,
 * per-group tri-state select-all, a global select-all, and a running "N of M
 * selected" count. Grouping is `permission-groups.ts` — one implementation
 * shared with the picker and the read-only panel.
 */
export function PermissionsGrid({
  permissions,
  selectedIds,
  onChange,
  t,
  readOnly = false,
}: PermissionsGridProps) {
  const [query, setQuery] = useState('');
  const [collapsed, setCollapsed] = useState<Set<string>>(new Set());

  const selected = useMemo(() => new Set(selectedIds), [selectedIds]);
  // Tolerate a non-array (defensive against malformed responses).
  const all = useMemo(() => (Array.isArray(permissions) ? permissions : []), [permissions]);

  const trimmedQuery = query.trim();

  const groups = useMemo(() => {
    const q = trimmedQuery.toLowerCase();
    const filtered = q
      ? all.filter(
          (p) =>
            (p.name ?? '').toLowerCase().includes(q) ||
            (p.description ?? '').toLowerCase().includes(q)
        )
      : all;
    return groupPermissions(filtered);
  }, [all, trimmedQuery]);

  const toggle = (id: number) => {
    if (selected.has(id)) onChange(selectedIds.filter((sid) => sid !== id));
    else onChange([...selectedIds, id]);
  };

  const setGroup = (perms: Permission[], on: boolean) => {
    const ids = new Set(perms.map((p) => p.id));
    if (on) onChange([...new Set([...selectedIds, ...ids])]);
    else onChange(selectedIds.filter((id) => !ids.has(id)));
  };

  const selectedCount = selectedIds.length;
  const totalCount = all.length;
  const allCollapsed = groups.length > 0 && groups.every(([g]) => collapsed.has(g));

  if (totalCount === 0) {
    return (
      <p className="py-6 text-center text-sm text-muted-foreground" data-testid="perm-grid-empty">
        {readOnly
          ? t('roles.permissionsGrid.readOnlyNone', 'This role has no permissions.')
          : t(
              'roles.permissionsGrid.none',
              'No permissions are defined on this installation.'
            )}
      </p>
    );
  }

  return (
    <div className="space-y-4" data-testid="perm-grid">
      {/* Toolbar. `flex-wrap` rather than a fixed split so the controls stack on
          a narrow viewport instead of the filter shrinking to nothing. */}
      <div className="flex flex-wrap items-center gap-2">
        <div className="relative min-w-[12rem] flex-1">
          <IconSearch
            size={14}
            className="pointer-events-none absolute inset-s-2 top-1/2 -translate-y-1/2 text-muted-foreground"
          />
          <Input
            data-testid="perm-grid-search"
            value={query}
            onChange={(e) => setQuery(e.target.value)}
            placeholder={t('roles.permissionsGrid.filterPlaceholder', 'Filter permissions…')}
            className="h-9 ps-7 text-sm"
          />
        </div>
        {/* Read-only lists the role's OWN permissions, so "N of N selected"
            would state a tautology and read as a control. The count is already
            on the record's "Permissions granted" stat. */}
        {!readOnly && (
          <span className="text-xs text-muted-foreground" data-testid="perm-grid-summary">
            {t('roles.permissionsGrid.summary', '{selected} of {total} selected', {
              selected: selectedCount,
              total: totalCount,
            })}
          </span>
        )}
        <div className="flex items-center gap-1">
          <Button
            type="button"
            variant="ghost"
            size="sm"
            onClick={() =>
              setCollapsed(allCollapsed ? new Set() : new Set(groups.map(([g]) => g)))
            }
          >
            {allCollapsed
              ? t('roles.permissionsGrid.expandAll', 'Expand all')
              : t('roles.permissionsGrid.collapseAll', 'Collapse all')}
          </Button>
          {!readOnly && (
            <>
              <Button
                type="button"
                variant="outline"
                size="sm"
                data-testid="perm-grid-select-all"
                onClick={() => onChange(all.map((p) => p.id))}
              >
                {t('roles.permissionsGrid.selectAll', 'Select all')}
              </Button>
              <Button
                type="button"
                variant="outline"
                size="sm"
                data-testid="perm-grid-select-none"
                onClick={() => onChange([])}
              >
                {t('roles.permissionsGrid.selectNone', 'Clear all')}
              </Button>
            </>
          )}
        </div>
      </div>

      {groups.length === 0 ? (
        <p className="py-6 text-center text-sm text-muted-foreground" data-testid="perm-grid-no-match">
          {t('roles.permissionsGrid.noMatch', 'No permissions match “{query}”.', {
            query: trimmedQuery,
          })}
        </p>
      ) : (
        /* A COLUMN grid, not a scroll box: groups flow into as many columns as
           the viewport affords and the page grows to fit them. */
        <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
          {groups.map(([group, perms]) => {
            const inGroup = perms.filter((p) => selected.has(p.id)).length;
            const allOn = inGroup === perms.length;
            const isCollapsed = collapsed.has(group);
            return (
              <section
                key={group}
                data-testid={`perm-grid-group-${group}`}
                className="rounded-lg border border-border bg-card"
              >
                <div className="flex items-center gap-2 border-b border-border bg-muted/40 px-3 py-2">
                  {!readOnly && (
                    <input
                      type="checkbox"
                      aria-label={t(
                        'roles.permissionsGrid.groupSelectAll',
                        'Select all {group} permissions',
                        { group }
                      )}
                      data-testid={`perm-grid-group-toggle-${group}`}
                      checked={allOn}
                      ref={(el) => {
                        if (el) el.indeterminate = inGroup > 0 && !allOn;
                      }}
                      onChange={() => setGroup(perms, !allOn)}
                      className="h-4 w-4 rounded border-border text-primary focus:ring-2 focus:ring-ring"
                    />
                  )}
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
                    aria-expanded={!isCollapsed}
                    className="flex flex-1 items-center justify-between gap-2 text-start"
                  >
                    <span className="text-xs font-semibold uppercase tracking-wide text-foreground">
                      {titleCase(group)}
                    </span>
                    <span className="flex items-center gap-1 text-[10px] text-muted-foreground">
                      {inGroup}/{perms.length}
                      {/* `-rotate-90` is direction-agnostic here on purpose: the
                          chevron reads as "closed" pointing at the label in
                          either direction once the row itself is flipped. */}
                      <IconChevronDown
                        size={12}
                        className={`transition-transform ${isCollapsed ? '-rotate-90' : ''}`}
                      />
                    </span>
                  </button>
                </div>
                {!isCollapsed && (
                  <ul className="divide-y divide-border/60">
                    {perms.map((permission) => {
                      const isOn = selected.has(permission.id);
                      if (readOnly) {
                        return (
                          <li key={permission.id} className="px-3 py-2">
                            <span className="block font-mono text-sm text-foreground">
                              {permission.name}
                            </span>
                            {permission.description && (
                              <span className="block text-xs text-muted-foreground">
                                {permission.description}
                              </span>
                            )}
                          </li>
                        );
                      }
                      return (
                        <li key={permission.id}>
                          <label className="flex cursor-pointer items-start gap-2 px-3 py-2 hover:bg-muted">
                            <input
                              type="checkbox"
                              checked={isOn}
                              onChange={() => toggle(permission.id)}
                              className="mt-0.5 h-4 w-4 rounded border-border text-primary focus:ring-2 focus:ring-ring"
                            />
                            <span className="min-w-0 flex-1">
                              <span className="block font-mono text-sm text-foreground">
                                {permission.name}
                              </span>
                              {permission.description && (
                                <span className="block text-xs text-muted-foreground">
                                  {permission.description}
                                </span>
                              )}
                            </span>
                          </label>
                        </li>
                      );
                    })}
                  </ul>
                )}
              </section>
            );
          })}
        </div>
      )}
    </div>
  );
}
