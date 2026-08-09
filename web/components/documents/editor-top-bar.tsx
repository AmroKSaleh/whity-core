'use client';

import { useSyncExternalStore } from 'react';
import { Toolbar as ToolbarPrimitive } from 'radix-ui';
import { Button } from '@amroksaleh/ui/button';
import { Input } from '@amroksaleh/ui/input';
import { MenuBar } from '@amroksaleh/ui/menu-bar';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@amroksaleh/ui/dialog';
import {
  IconArrowLeft,
  IconComponents,
  IconDeviceFloppy,
  IconEye,
  IconEyeOff,
  IconPrinter,
  IconZoomIn,
  IconZoomOut,
} from '@tabler/icons-react';

import {
  buildEditorMenus,
  buildEditorToolbar,
  listEditorShortcuts,
  type EditorCommandContext,
} from './editor-commands';

/**
 * The editor's chrome: a Google-Docs-style stack of
 *
 *   1. a title bar   — exit, document name, and the three actions worth a
 *                      permanent home (Save / Preview / Print),
 *   2. a MENU BAR    — the complete command index, and
 *   3. an icon TOOLBAR of the frequent subset, plus zoom.
 *
 * It renders NOTHING of its own invention: rows 2 and 3 are drawn entirely from
 * the command registry (`editor-commands.tsx`) via the same
 * `EditorCommandContext` the designer already builds, so the chrome cannot drift
 * out of step with what the editor can actually do.
 */
export function EditorTopBar({
  ctx,
  name,
  onNameChange,
  zoom,
  blockEdit,
  onExitBlockEdit,
  batchLabel,
}: {
  ctx: EditorCommandContext;
  name: string;
  onNameChange: (name: string) => void;
  zoom: number;
  /** Set while editing one block instead of the document. */
  blockEdit: { id: string; name: string } | null;
  onExitBlockEdit: (save: boolean) => void;
  /** e.g. "×10" when a variable-data batch is loaded. */
  batchLabel: string | null;
}) {
  const menus = buildEditorMenus(ctx);
  const groups = buildEditorToolbar(ctx);

  return (
    <header className="shrink-0 border-b border-border bg-card" data-testid="doc-top-bar">
      {/* The editor deliberately has no visible page header (its own chrome IS
          the header), but the document still needs one top-level heading so
          screen-reader users can orient by heading rather than landing in an
          unnamed page. The visible identity is the template-name field below. */}
      <h1 className="sr-only">Document &amp; Label Designer</h1>

      {/* ── title bar ── */}
      <div className="flex items-center gap-2 px-2 py-1.5">
        <Button
          variant="ghost"
          size="icon-sm"
          aria-label="Close editor"
          title="Close editor"
          data-testid="doc-back"
          onClick={ctx.onCloseEditor}
        >
          <IconArrowLeft className="h-4 w-4 rtl:rotate-180" />
        </Button>
        <Input
          aria-label="Template name"
          data-testid="doc-name"
          value={name}
          onChange={(e) => onNameChange(e.target.value)}
          className="h-7 max-w-[18rem] border-transparent bg-transparent font-medium hover:border-input focus-visible:border-ring"
        />
        {ctx.currentSavedId === null && !blockEdit && (
          <span className="text-[0.625rem] text-muted-foreground" data-testid="doc-unsaved-hint">
            Not saved yet
          </span>
        )}
        {batchLabel && (
          <span
            className="rounded-md bg-primary/10 px-1.5 py-0.5 text-xs font-medium text-primary"
            data-testid="doc-batch-badge"
            title="Print will render one copy per batch row"
          >
            {batchLabel}
          </span>
        )}

        <span className="ms-auto flex items-center gap-1.5">
          <Button variant="outline" size="sm" className="gap-1" data-testid="doc-preview-toggle" onClick={ctx.onTogglePreview}>
            {ctx.preview ? <IconEyeOff className="h-3.5 w-3.5" /> : <IconEye className="h-3.5 w-3.5" />}
            {ctx.preview ? 'Editing' : 'Preview'}
          </Button>
          <Button variant="outline" size="sm" className="gap-1" data-testid="doc-print" onClick={ctx.onPrint}>
            <IconPrinter className="h-3.5 w-3.5" /> Print
          </Button>
          <Button size="sm" className="gap-1" data-testid="doc-save" disabled={ctx.blockEditing} onClick={ctx.onSave}>
            <IconDeviceFloppy className="h-3.5 w-3.5" /> Save
          </Button>
        </span>
      </div>

      {/* ── menu bar ── */}
      <div className="flex items-center border-t border-border/60 px-1">
        <MenuBar menus={menus} aria-label="Document editor menu" />
      </div>

      {/* ── icon toolbar + zoom ── */}
      <ToolbarPrimitive.Root
        aria-label="Document editor toolbar"
        data-testid="doc-toolbar"
        className="flex items-center gap-0.5 border-t border-border/60 px-2 py-1"
      >
        {groups.map((group, gi) => (
          <div key={group.id} className="flex items-center gap-0.5">
            {gi > 0 && <ToolbarPrimitive.Separator className="mx-1 h-5 w-px bg-border" />}
            {group.buttons.map((b) => (
              <ToolbarPrimitive.Button
                key={b.id}
                aria-label={b.label}
                aria-pressed={b.active}
                title={b.shortcut ? `${b.label} (${b.shortcut})` : b.label}
                data-testid={`toolbar-${b.id}`}
                disabled={b.disabled}
                onClick={b.onSelect}
                className={`flex size-7 items-center justify-center rounded-md text-muted-foreground outline-hidden hover:bg-accent hover:text-accent-foreground focus-visible:ring-2 focus-visible:ring-ring/40 disabled:pointer-events-none disabled:opacity-40 ${
                  b.active ? 'bg-accent text-accent-foreground' : ''
                } [&_svg]:size-4`}
              >
                {b.icon}
              </ToolbarPrimitive.Button>
            ))}
          </div>
        ))}

        <span className="ms-auto flex items-center gap-0.5">
          <ToolbarPrimitive.Button
            aria-label="Zoom out"
            title="Zoom out"
            data-testid="toolbar-zoom-out"
            onClick={() => ctx.onZoom('out')}
            className="flex size-7 items-center justify-center rounded-md text-muted-foreground outline-hidden hover:bg-accent hover:text-accent-foreground focus-visible:ring-2 focus-visible:ring-ring/40 [&_svg]:size-4"
          >
            <IconZoomOut />
          </ToolbarPrimitive.Button>
          <button
            type="button"
            title="Zoom to 100%"
            data-testid="toolbar-zoom-reset"
            onClick={() => ctx.onZoom('reset')}
            className="w-12 rounded-md text-center text-xs tabular-nums text-muted-foreground hover:bg-accent hover:text-accent-foreground"
          >
            {Math.round(zoom * 100)}%
          </button>
          <ToolbarPrimitive.Button
            aria-label="Zoom in"
            title="Zoom in"
            data-testid="toolbar-zoom-in"
            onClick={() => ctx.onZoom('in')}
            className="flex size-7 items-center justify-center rounded-md text-muted-foreground outline-hidden hover:bg-accent hover:text-accent-foreground focus-visible:ring-2 focus-visible:ring-ring/40 [&_svg]:size-4"
          >
            <IconZoomIn />
          </ToolbarPrimitive.Button>
        </span>
      </ToolbarPrimitive.Root>

      {/* ── block edit mode banner ──
          Loud on purpose: while this is up, every edit lands on a SHARED block
          that other documents also point at, not on the document you opened. */}
      {blockEdit && (
        <div
          className="flex items-center gap-2 border-t border-primary/40 bg-primary/10 px-3 py-1.5"
          data-testid="doc-block-edit-banner"
        >
          <IconComponents className="h-4 w-4 text-primary" />
          <span className="text-xs font-medium text-primary">
            Editing block: {blockEdit.name} — changes apply to every document using it
          </span>
          <span className="ms-auto flex items-center gap-2">
            <Button size="sm" variant="ghost" data-testid="doc-block-edit-cancel" onClick={() => onExitBlockEdit(false)}>
              Cancel
            </Button>
            <Button size="sm" data-testid="doc-block-edit-done" onClick={() => onExitBlockEdit(true)}>
              Done
            </Button>
          </span>
        </div>
      )}
    </header>
  );
}

/**
 * Help ▸ Keyboard shortcuts. Read straight off the command registry, so a
 * shortcut can't be listed here and unimplemented (or implemented and
 * undocumented) — there is only one table.
 */
export function ShortcutsDialog({
  open,
  onOpenChange,
  modLabel,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  modLabel: string;
}) {
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent data-testid="doc-shortcuts-dialog">
        <DialogHeader>
          <DialogTitle>Keyboard shortcuts</DialogTitle>
          <DialogDescription>
            Shortcuts are ignored while typing in a field, so text editing keeps its native behaviour.
          </DialogDescription>
        </DialogHeader>
        <dl className="divide-y divide-border/60">
          {listEditorShortcuts(modLabel).map((s) => (
            <div key={s.id} className="flex items-center justify-between gap-4 py-1.5">
              <dt className="text-xs text-foreground">{s.label}</dt>
              <dd className="shrink-0 rounded border border-border bg-muted px-1.5 py-0.5 text-[0.625rem] tracking-widest text-muted-foreground">
                {s.keys}
              </dd>
            </div>
          ))}
        </dl>
      </DialogContent>
    </Dialog>
  );
}

/** The platform never changes mid-session, so there is nothing to subscribe to. */
const noSubscribe = () => () => {};

const readModLabel = () =>
  typeof navigator !== 'undefined' && /Mac|iPhone|iPad|iPod/i.test(navigator.platform || navigator.userAgent)
    ? '⌘'
    : 'Ctrl';

/**
 * The platform modifier label for shortcut hints ("Ctrl" or "⌘").
 *
 * Reading the platform is reading an EXTERNAL system, so it goes through
 * `useSyncExternalStore` with a server snapshot of "Ctrl": React renders the
 * server value during hydration and swaps to the real one immediately after,
 * which is hydration-safe by construction. Detecting it in an effect would
 * instead be a setState-in-effect cascade.
 */
export function useModLabel(): string {
  return useSyncExternalStore(noSubscribe, readModLabel, () => 'Ctrl');
}
