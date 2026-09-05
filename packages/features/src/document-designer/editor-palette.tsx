import type { CommandPaletteItem } from '@amroksaleh/ui/command-palette';
import type { MenuBarMenu, MenuBarNode } from '@amroksaleh/ui/menu-bar';

/**
 * The editor's command palette items, FLATTENED FROM THE MENU REGISTRY.
 *
 * Derived, never listed. `buildEditorMenus()` already describes every command
 * the editor has — label, icon, shortcut, disabled state and what running it
 * does — and already feeds the menu bar and the toolbar. A palette that kept
 * its own list would be a fourth description of the same vocabulary, and the
 * one most likely to fall behind: a command added to the menus would simply be
 * missing here, and nothing would report it.
 *
 * The contract file makes this argument about `visibleWhen` — declared once and
 * merged, because "a facet only some types may carry stops being a small
 * restriction and becomes a second mechanism". Same reasoning, one level up.
 *
 * INSERT COMMANDS COME FIRST. The palette is opened with `/` mid-layout, and
 * the overwhelmingly common intention is "put something on the page" — so the
 * Insert menu is hoisted above everything else rather than appearing wherever
 * the menu bar happens to order it. Typing filters across all of them either
 * way, so nothing is harder to reach; the ordering only decides what an empty
 * query shows first.
 */

/** Menus whose commands are offered before the rest, in this order. */
const PRIORITY_MENUS = ['insert'] as const;

/**
 * Walk one menu's nodes into palette items.
 *
 * Submenus are FLATTENED rather than nested: a palette that made you drill in
 * would have reintroduced the menu-hunting it exists to remove. Their label
 * becomes part of the group, so "Insert ▸ Block ▸ Company header" is still
 * legible as where the command lives.
 *
 * Checkbox nodes become ordinary items that toggle. The palette has no room to
 * render a tick and no way to explain one, and "Show grid" reads as a perfectly
 * good command; the menu bar remains the place that shows current state.
 */
function nodesToItems(
  nodes: MenuBarNode[],
  group: string,
  out: CommandPaletteItem[],
  seen: Set<string>
): void {
  for (const node of nodes) {
    if (node.kind === 'separator') continue;

    // A label is a heading INSIDE a menu — the block-scope headings, for one.
    // It names the group its followers belong to rather than being an item.
    if (node.kind === 'label') {
      continue;
    }

    if (node.kind === 'submenu') {
      nodesToItems(node.items, `${group} ▸ ${textOf(node.label)}`, out, seen);
      continue;
    }

    // Ids repeat across menus (the same command offered in two places), and a
    // duplicate id would break both React keys and aria-activedescendant. First
    // occurrence wins, which is also the priority order established above.
    if (seen.has(node.id)) continue;
    seen.add(node.id);

    out.push({
      id: node.id,
      label: textOf(node.label),
      group,
      shortcut: node.kind === 'checkbox' ? undefined : node.shortcut,
      icon: node.kind === 'checkbox' ? undefined : node.icon,
      disabled: node.disabled,
      onSelect:
        node.kind === 'checkbox'
          ? () => node.onCheckedChange(!node.checked)
          : node.onSelect,
    });
  }
}

/**
 * A node's label as a searchable string.
 *
 * Labels are `React.ReactNode` because the menu bar renders them, and most are
 * plain strings. One that is not cannot be matched on, and returning the empty
 * string would make it unfindable — so its group still carries it, and typing
 * the menu name reaches it.
 */
function textOf(label: unknown): string {
  return typeof label === 'string' ? label : '';
}

/**
 * @param menus the value `useEditorChrome(ctx).menus` already returns
 */
export function paletteItemsFromMenus(menus: MenuBarMenu[]): CommandPaletteItem[] {
  const seen = new Set<string>();
  const priority: CommandPaletteItem[] = [];
  const rest: CommandPaletteItem[] = [];

  for (const id of PRIORITY_MENUS) {
    const menu = menus.find((m) => m.id === id);
    if (menu) nodesToItems(menu.items, textOf(menu.label), priority, seen);
  }

  for (const menu of menus) {
    if ((PRIORITY_MENUS as readonly string[]).includes(menu.id)) continue;
    nodesToItems(menu.items, textOf(menu.label), rest, seen);
  }

  return [...priority, ...rest];
}
