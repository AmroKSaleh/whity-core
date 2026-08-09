"use client"

import * as React from "react"
import { Menubar as MenubarPrimitive } from "radix-ui"
import { IconCheck, IconChevronRight } from "@tabler/icons-react"

import { cn, useIsDarkMode } from "./utils"

/**
 * A desktop-application menu bar (File / Edit / Insert / …), as found in Google
 * Docs, Word and every canvas editor — the home for a command set far too large
 * for a single toolbar row.
 *
 * Two layers, mirroring `dropdown-menu.tsx`:
 *
 * 1. Styled primitive parts (`MenuBarRoot`, `MenuBarItem`, …) over Radix
 *    `Menubar`, which supplies the behaviour that makes a menu bar feel native
 *    and stays accessible: roving tab focus across the menus, arrow-key
 *    traversal, hover-to-switch once a menu is open, character typeahead,
 *    Escape/click-outside dismissal and focus restoration.
 * 2. A DECLARATIVE renderer — `<MenuBar menus={…} />` — that draws a plain
 *    `MenuBarMenu[]` model. Prefer this: it lets an app describe its commands
 *    once (a command registry) and render them here, in a toolbar and in a
 *    shortcuts sheet without restating any of them. Every node carries its own
 *    `disabled`/`checked` state, so the model is the single source of truth for
 *    whether a command is currently available.
 */

// ── the declarative model ───────────────────────────────────────────────────

/** A command: runs `onSelect` when chosen. */
export interface MenuBarActionNode {
  kind?: "item"
  id: string
  label: React.ReactNode
  icon?: React.ReactNode
  /** Display-only shortcut hint, e.g. "Ctrl+S". Binding it is the app's job. */
  shortcut?: string
  disabled?: boolean
  /** Renders in the destructive tone (delete, remove). */
  destructive?: boolean
  onSelect: () => void
}

/** A toggle: shows a tick when `checked`. */
export interface MenuBarCheckboxNode {
  kind: "checkbox"
  id: string
  label: React.ReactNode
  checked: boolean
  shortcut?: string
  disabled?: boolean
  onCheckedChange: (checked: boolean) => void
}

/** A nested menu. An empty `items` renders a disabled placeholder, never a
 *  dead end that opens onto nothing. */
export interface MenuBarSubmenuNode {
  kind: "submenu"
  id: string
  label: React.ReactNode
  icon?: React.ReactNode
  disabled?: boolean
  items: MenuBarNode[]
  /** Shown (disabled) when `items` is empty. */
  emptyLabel?: React.ReactNode
}

export interface MenuBarSeparatorNode {
  kind: "separator"
  id: string
}

/** A non-interactive heading, for grouping inside a long menu. */
export interface MenuBarLabelNode {
  kind: "label"
  id: string
  label: React.ReactNode
}

export type MenuBarNode =
  | MenuBarActionNode
  | MenuBarCheckboxNode
  | MenuBarSubmenuNode
  | MenuBarSeparatorNode
  | MenuBarLabelNode

export interface MenuBarMenu {
  id: string
  label: React.ReactNode
  disabled?: boolean
  items: MenuBarNode[]
}

// ── styled primitive parts ──────────────────────────────────────────────────

function MenuBarRoot({ className, ...props }: React.ComponentProps<typeof MenubarPrimitive.Root>) {
  return (
    <MenubarPrimitive.Root
      data-slot="menu-bar"
      className={cn("flex items-center gap-0.5", className)}
      {...props}
    />
  )
}

function MenuBarMenuRoot({ ...props }: React.ComponentProps<typeof MenubarPrimitive.Menu>) {
  return <MenubarPrimitive.Menu data-slot="menu-bar-menu" {...props} />
}

function MenuBarTrigger({ className, ...props }: React.ComponentProps<typeof MenubarPrimitive.Trigger>) {
  return (
    <MenubarPrimitive.Trigger
      data-slot="menu-bar-trigger"
      className={cn(
        "flex select-none items-center rounded-md px-2 py-1 text-xs font-medium text-foreground outline-hidden hover:bg-accent hover:text-accent-foreground focus-visible:ring-2 focus-visible:ring-ring/40 data-open:bg-accent data-open:text-accent-foreground data-disabled:pointer-events-none data-disabled:opacity-50",
        className
      )}
      {...props}
    />
  )
}

function MenuBarContent({
  className,
  align = "start",
  sideOffset = 4,
  ...props
}: React.ComponentProps<typeof MenubarPrimitive.Content>) {
  const isDark = useIsDarkMode()

  return (
    <MenubarPrimitive.Portal>
      <MenubarPrimitive.Content
        data-slot="menu-bar-content"
        align={align}
        sideOffset={sideOffset}
        className={cn(
          isDark && "dark",
          "z-50 max-h-(--radix-menubar-content-available-height) min-w-52 origin-(--radix-menubar-content-transform-origin) overflow-x-hidden overflow-y-auto rounded-lg border border-border bg-popover p-1 text-popover-foreground shadow-md duration-100 data-open:animate-in data-open:fade-in-0 data-open:zoom-in-95 data-closed:animate-out data-closed:fade-out-0 data-closed:zoom-out-95",
          className
        )}
        {...props}
      />
    </MenubarPrimitive.Portal>
  )
}

function MenuBarItem({
  className,
  variant = "default",
  ...props
}: React.ComponentProps<typeof MenubarPrimitive.Item> & { variant?: "default" | "destructive" }) {
  return (
    <MenubarPrimitive.Item
      data-slot="menu-bar-item"
      data-variant={variant}
      className={cn(
        "group/menu-bar-item relative flex min-h-7 cursor-default items-center gap-2 rounded-md px-2 py-1 text-xs/relaxed outline-hidden select-none focus:bg-accent focus:text-accent-foreground not-data-[variant=destructive]:focus:**:text-accent-foreground data-[variant=destructive]:text-destructive data-[variant=destructive]:focus:bg-destructive/10 data-[variant=destructive]:focus:text-destructive dark:data-[variant=destructive]:focus:bg-destructive/20 data-disabled:pointer-events-none data-disabled:opacity-50 [&_svg]:pointer-events-none [&_svg]:shrink-0 [&_svg:not([class*='size-'])]:size-3.5 data-[variant=destructive]:*:[svg]:text-destructive",
        className
      )}
      {...props}
    />
  )
}

function MenuBarCheckboxItem({
  className,
  children,
  checked,
  ...props
}: React.ComponentProps<typeof MenubarPrimitive.CheckboxItem>) {
  return (
    <MenubarPrimitive.CheckboxItem
      data-slot="menu-bar-checkbox-item"
      checked={checked}
      className={cn(
        "group/menu-bar-item relative flex min-h-7 cursor-default items-center gap-2 rounded-md py-1 pe-8 ps-2 text-xs/relaxed outline-hidden select-none focus:bg-accent focus:text-accent-foreground focus:**:text-accent-foreground data-disabled:pointer-events-none data-disabled:opacity-50 [&_svg]:pointer-events-none [&_svg]:shrink-0 [&_svg:not([class*='size-'])]:size-3.5",
        className
      )}
      {...props}
    >
      <span className="pointer-events-none absolute end-2 flex items-center justify-center">
        <MenubarPrimitive.ItemIndicator>
          <IconCheck />
        </MenubarPrimitive.ItemIndicator>
      </span>
      {children}
    </MenubarPrimitive.CheckboxItem>
  )
}

function MenuBarLabel({ className, ...props }: React.ComponentProps<typeof MenubarPrimitive.Label>) {
  return (
    <MenubarPrimitive.Label
      data-slot="menu-bar-label"
      className={cn("px-2 py-1 text-[0.625rem] font-semibold uppercase tracking-wide text-muted-foreground", className)}
      {...props}
    />
  )
}

function MenuBarSeparator({ className, ...props }: React.ComponentProps<typeof MenubarPrimitive.Separator>) {
  return (
    <MenubarPrimitive.Separator
      data-slot="menu-bar-separator"
      className={cn("-mx-1 my-1 h-px bg-border/50", className)}
      {...props}
    />
  )
}

function MenuBarShortcut({ className, ...props }: React.ComponentProps<"span">) {
  return (
    <span
      data-slot="menu-bar-shortcut"
      className={cn(
        "ms-auto ps-4 text-[0.625rem] tracking-widest text-muted-foreground group-focus/menu-bar-item:text-accent-foreground",
        className
      )}
      {...props}
    />
  )
}

function MenuBarSub({ ...props }: React.ComponentProps<typeof MenubarPrimitive.Sub>) {
  return <MenubarPrimitive.Sub data-slot="menu-bar-sub" {...props} />
}

function MenuBarSubTrigger({
  className,
  children,
  ...props
}: React.ComponentProps<typeof MenubarPrimitive.SubTrigger>) {
  return (
    <MenubarPrimitive.SubTrigger
      data-slot="menu-bar-sub-trigger"
      className={cn(
        "flex min-h-7 cursor-default items-center gap-2 rounded-md px-2 py-1 text-xs/relaxed outline-hidden select-none focus:bg-accent focus:text-accent-foreground focus:**:text-accent-foreground data-open:bg-accent data-open:text-accent-foreground data-disabled:pointer-events-none data-disabled:opacity-50 [&_svg]:pointer-events-none [&_svg]:shrink-0 [&_svg:not([class*='size-'])]:size-3.5",
        className
      )}
      {...props}
    >
      {children}
      <IconChevronRight className="ms-auto rtl:rotate-180" />
    </MenubarPrimitive.SubTrigger>
  )
}

function MenuBarSubContent({ className, ...props }: React.ComponentProps<typeof MenubarPrimitive.SubContent>) {
  const isDark = useIsDarkMode()

  return (
    <MenubarPrimitive.Portal>
      <MenubarPrimitive.SubContent
        data-slot="menu-bar-sub-content"
        className={cn(
          isDark && "dark",
          "z-50 max-h-(--radix-menubar-content-available-height) min-w-44 origin-(--radix-menubar-content-transform-origin) overflow-x-hidden overflow-y-auto rounded-lg border border-border bg-popover p-1 text-popover-foreground shadow-md duration-100 data-open:animate-in data-open:fade-in-0 data-open:zoom-in-95 data-closed:animate-out data-closed:fade-out-0 data-closed:zoom-out-95",
          className
        )}
        {...props}
      />
    </MenubarPrimitive.Portal>
  )
}

// ── the declarative renderer ────────────────────────────────────────────────

/** Render one model node (recursively, for submenus). */
function MenuBarNodes({ items, emptyLabel }: { items: MenuBarNode[]; emptyLabel?: React.ReactNode }) {
  if (items.length === 0) {
    return <MenuBarItem disabled>{emptyLabel ?? "Nothing here yet"}</MenuBarItem>
  }
  return (
    <>
      {items.map((node) => {
        if ("kind" in node && node.kind === "separator") {
          return <MenuBarSeparator key={node.id} />
        }
        if ("kind" in node && node.kind === "label") {
          return <MenuBarLabel key={node.id}>{node.label}</MenuBarLabel>
        }
        if ("kind" in node && node.kind === "checkbox") {
          return (
            <MenuBarCheckboxItem
              key={node.id}
              data-testid={`menu-item-${node.id}`}
              checked={node.checked}
              disabled={node.disabled}
              // Radix closes the menu on select by default; a toggle is more
              // useful kept open so several can be flipped in one visit.
              onSelect={(e) => e.preventDefault()}
              onCheckedChange={node.onCheckedChange}
            >
              {node.label}
              {node.shortcut && <MenuBarShortcut>{node.shortcut}</MenuBarShortcut>}
            </MenuBarCheckboxItem>
          )
        }
        if ("kind" in node && node.kind === "submenu") {
          return (
            <MenuBarSub key={node.id}>
              <MenuBarSubTrigger data-testid={`menu-item-${node.id}`} disabled={node.disabled}>
                {node.icon}
                {node.label}
              </MenuBarSubTrigger>
              <MenuBarSubContent>
                <MenuBarNodes items={node.items} emptyLabel={node.emptyLabel} />
              </MenuBarSubContent>
            </MenuBarSub>
          )
        }
        const item = node as MenuBarActionNode
        return (
          <MenuBarItem
            key={item.id}
            data-testid={`menu-item-${item.id}`}
            disabled={item.disabled}
            variant={item.destructive ? "destructive" : "default"}
            onSelect={item.onSelect}
          >
            {item.icon}
            {item.label}
            {item.shortcut && <MenuBarShortcut>{item.shortcut}</MenuBarShortcut>}
          </MenuBarItem>
        )
      })}
    </>
  )
}

export interface MenuBarProps extends Omit<React.ComponentProps<typeof MenubarPrimitive.Root>, "children"> {
  menus: MenuBarMenu[]
}

/**
 * Draw a whole menu bar from a `MenuBarMenu[]` model. Each menu gets a
 * `data-testid` of `menu-<id>` and each item `menu-item-<id>`, so a command can
 * be driven end-to-end in tests by the same id the registry declares it with.
 */
function MenuBar({ menus, ...props }: MenuBarProps) {
  return (
    <MenuBarRoot {...props}>
      {menus.map((menu) => (
        <MenuBarMenuRoot key={menu.id}>
          <MenuBarTrigger data-testid={`menu-${menu.id}`} disabled={menu.disabled}>
            {menu.label}
          </MenuBarTrigger>
          <MenuBarContent>
            <MenuBarNodes items={menu.items} />
          </MenuBarContent>
        </MenuBarMenuRoot>
      ))}
    </MenuBarRoot>
  )
}

export {
  MenuBar,
  MenuBarRoot,
  MenuBarMenuRoot,
  MenuBarTrigger,
  MenuBarContent,
  MenuBarItem,
  MenuBarCheckboxItem,
  MenuBarLabel,
  MenuBarSeparator,
  MenuBarShortcut,
  MenuBarSub,
  MenuBarSubTrigger,
  MenuBarSubContent,
}
