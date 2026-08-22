/**
 * Permission grouping — the single implementation of "which resource does this
 * permission belong to".
 *
 * A permission slug is `resource:action` (`users:write`, `roles:delete`), so the
 * segment before the first colon is the resource the permission is ABOUT, and
 * grouping by it is what turns a flat catalogue of 53+ core permissions plus
 * every plugin-declared one into a readable set of sections.
 *
 * This lived twice — once in `permission-checkbox.tsx` as `groupOf`, once in
 * `permissions-panel.tsx` as `groupPermissions` — and #882's record page would
 * have been the third copy. Three implementations of one rule disagree the first
 * time a slug shows up that none of them anticipated (a plugin using two colons,
 * a bare slug with none), and they disagree in different UIs on the same screen.
 *
 * NOTE ON TRANSLATION: a group key comes from the permissions table — tenant
 * DATA, not a source string — so it renders verbatim and never enters the i18n
 * catalogue. There is deliberately no `t()` here.
 */

import type { Permission } from './types';

/**
 * The resource segment a permission belongs to: everything before the first
 * colon, or `general` for a slug with no colon (or one starting with it, which
 * would otherwise produce an empty group name).
 */
export function groupOf(name: string): string {
  const i = (name ?? '').indexOf(':');
  return i > 0 ? name.slice(0, i) : 'general';
}

/** Title-case a group key for display (`users` → `Users`). */
export function titleCase(s: string): string {
  return s.charAt(0).toUpperCase() + s.slice(1);
}

/**
 * Group permissions by resource. Groups are sorted by key; order WITHIN a group
 * is the caller's (the API returns them ordered by name), so a permission never
 * moves between renders.
 */
export function groupPermissions(permissions: Permission[]): [string, Permission[]][] {
  const map = new Map<string, Permission[]>();
  for (const p of permissions) {
    const g = groupOf(p.name ?? '');
    const list = map.get(g);
    if (list) list.push(p);
    else map.set(g, [p]);
  }
  return [...map.entries()].sort(([a], [b]) => a.localeCompare(b));
}
