import { useSyncExternalStore } from 'react';
import { Toolbar as ToolbarPrimitive } from 'radix-ui';
import { Button } from '@amroksaleh/ui/button';
import { Input } from './ui-i18n';
import { MenuBar } from '@amroksaleh/ui/menu-bar';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from './ui-i18n';
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

import { useTranslation } from '../i18n';

import {
  blockScopeLabel,
  listEditorShortcuts,
  useEditorChrome,
  type EditorCommandContext,
} from './editor-commands';
import { BLOCK_SCOPES, type BlockScope } from '@amroksaleh/ui/documents/blocks';

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
  scope,
  onScopeChange,
  zoom,
  blockEdit,
  onExitBlockEdit,
  batchLabel,
}: {
  ctx: EditorCommandContext;
  name: string;
  onNameChange: (name: string) => void;
  /** Who can see this document: the pending choice while unsaved, the server's
   *  answer once saved. */
  scope: BlockScope;
  /** Only called while unsaved — see the visibility control below. */
  onScopeChange: (scope: BlockScope) => void;
  zoom: number;
  /** Set while editing one block instead of the document. */
  blockEdit: { id: string; name: string } | null;
  onExitBlockEdit: (save: boolean) => void;
  /** e.g. "×10" when a variable-data batch is loaded. */
  batchLabel: string | null;
}) {
  const t = useTranslation('documents');
  const { menus, groups } = useEditorChrome(ctx);
  const unsaved = ctx.currentSavedId === null && !blockEdit;

  return (
    <header className="shrink-0 border-b border-border bg-card" data-testid="doc-top-bar">
      {/* The editor deliberately has no visible page header (its own chrome IS
          the header), but the document still needs one top-level heading so
          screen-reader users can orient by heading rather than landing in an
          unnamed page. The visible identity is the template-name field below. */}
      <h1 className="sr-only">{t('topBar.heading', 'Document & Label Designer')}</h1>

      {/* ── title bar ── */}
      <div className="flex items-center gap-2 px-2 py-1.5">
        <Button
          variant="ghost"
          size="icon-sm"
          aria-label={t('topBar.close', 'Close editor')}
          title={t('topBar.close', 'Close editor')}
          data-testid="doc-back"
          onClick={ctx.onCloseEditor}
        >
          <IconArrowLeft className="h-4 w-4 rtl:rotate-180" />
        </Button>
        <Input
          aria-label={t('topBar.name', 'Template name')}
          data-testid="doc-name"
          value={name}
          onChange={(e) => onNameChange(e.target.value)}
          className="h-7 max-w-[18rem] border-transparent bg-transparent font-medium hover:border-input focus-visible:border-ring"
        />
        {unsaved && (
          <span className="text-[0.625rem] text-muted-foreground" data-testid="doc-unsaved-hint">
            {t('topBar.unsaved', 'Not saved yet')}
          </span>
        )}

        {/* ── who will be able to see this ──
            A CONTROL while unsaved, a BADGE once saved.
            The control exists because the answer used to be decided by
            omission: the designer never sent a scope, and the server reads a
            missing one as `personal` — the author and nobody else. It defaults
            to `personal` still, so nothing is published by accident; the change
            is that the author can see the decision and make a different one.
            It becomes a badge after the first save rather than staying live,
            because changing a SAVED template's visibility travels with its unit
            placement and permission tag, and those live together in Templates &
            Blocks. A partial duplicate here could also silently revert a scope
            set there, since the editor holds whatever it loaded. Naming the
            state truthfully and saying where to change it beats a second
            control that can lie. */}
        {!blockEdit &&
          (unsaved ? (
            <label className="flex items-center gap-1 text-[0.625rem] text-muted-foreground">
              <span>{t('topBar.visibility', 'Visible to')}</span>
              <select
                data-testid="doc-scope"
                className="h-6 rounded-md border border-input bg-transparent px-1 text-[0.625rem]"
                value={scope}
                onChange={(e) => onScopeChange(e.target.value as BlockScope)}
              >
                {/* `system` is deliberately not offered: it marks the rows the
                    seeder owns, not something a person authors here. The badge
                    above still NAMES it, so opening a system template shows
                    what it is rather than a blank or a wrong tier. */}
                {BLOCK_SCOPES.filter((s) => s.id !== 'system').map((s) => (
                  <option key={s.id} value={s.id}>
                    {blockScopeLabel(t, s)}
                  </option>
                ))}
              </select>
            </label>
          ) : (
            <span
              className="rounded-md bg-muted px-1.5 py-0.5 text-[0.625rem] text-muted-foreground"
              data-testid="doc-scope-badge"
              title={t(
                'topBar.visibilityBadgeHint',
                'Change who can see this template in Templates & Blocks'
              )}
            >
              {blockScopeLabel(t, { id: scope, label: scope })}
            </span>
          ))}
        {batchLabel && (
          <span
            className="rounded-md bg-primary/10 px-1.5 py-0.5 text-xs font-medium text-primary"
            data-testid="doc-batch-badge"
            title={t('topBar.batchBadge', 'Print will render one copy per batch row')}
          >
            {batchLabel}
          </span>
        )}

        <span className="ms-auto flex items-center gap-1.5">
          <Button variant="outline" size="sm" className="gap-1" data-testid="doc-preview-toggle" onClick={ctx.onTogglePreview}>
            {ctx.preview ? <IconEyeOff className="h-3.5 w-3.5" /> : <IconEye className="h-3.5 w-3.5" />}
            {ctx.preview ? t('topBar.editing', 'Editing') : t('topBar.preview', 'Preview')}
          </Button>
          <Button variant="outline" size="sm" className="gap-1" data-testid="doc-print" onClick={ctx.onPrint}>
            <IconPrinter className="h-3.5 w-3.5" /> {t('topBar.print', 'Print')}
          </Button>
          <Button size="sm" className="gap-1" data-testid="doc-save" disabled={ctx.blockEditing} onClick={ctx.onSave}>
            <IconDeviceFloppy className="h-3.5 w-3.5" /> {t('topBar.save', 'Save')}
          </Button>
        </span>
      </div>

      {/* ── menu bar ── */}
      <div className="flex items-center border-t border-border/60 px-1">
        <MenuBar menus={menus} aria-label={t('topBar.menuBar', 'Document editor menu')} />
      </div>

      {/* ── icon toolbar + zoom ── */}
      <ToolbarPrimitive.Root
        aria-label={t('topBar.toolbar', 'Document editor toolbar')}
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
                title={
                  b.shortcut
                    ? t('topBar.buttonWithShortcut', '{label} ({shortcut})', {
                        label: b.label,
                        shortcut: b.shortcut,
                      })
                    : b.label
                }
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
            aria-label={t('topBar.zoomOut', 'Zoom out')}
            title={t('topBar.zoomOut', 'Zoom out')}
            data-testid="toolbar-zoom-out"
            onClick={() => ctx.onZoom('out')}
            className="flex size-7 items-center justify-center rounded-md text-muted-foreground outline-hidden hover:bg-accent hover:text-accent-foreground focus-visible:ring-2 focus-visible:ring-ring/40 [&_svg]:size-4"
          >
            <IconZoomOut />
          </ToolbarPrimitive.Button>
          <button
            type="button"
            title={t('topBar.zoomReset', 'Zoom to 100%')}
            data-testid="toolbar-zoom-reset"
            onClick={() => ctx.onZoom('reset')}
            className="w-12 rounded-md text-center text-xs tabular-nums text-muted-foreground hover:bg-accent hover:text-accent-foreground"
          >
            {Math.round(zoom * 100)}%
          </button>
          <ToolbarPrimitive.Button
            aria-label={t('topBar.zoomIn', 'Zoom in')}
            title={t('topBar.zoomIn', 'Zoom in')}
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
            {t(
              'topBar.blockEditBanner',
              'Editing block: {name} — changes apply to every document using it',
              { name: blockEdit.name }
            )}
          </span>
          <span className="ms-auto flex items-center gap-2">
            <Button size="sm" variant="ghost" data-testid="doc-block-edit-cancel" onClick={() => onExitBlockEdit(false)}>
              {t('topBar.blockEditCancel', 'Cancel')}
            </Button>
            <Button size="sm" data-testid="doc-block-edit-done" onClick={() => onExitBlockEdit(true)}>
              {t('topBar.blockEditDone', 'Done')}
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
  const t = useTranslation('documents');

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent data-testid="doc-shortcuts-dialog">
        <DialogHeader>
          <DialogTitle>{t('topBar.shortcuts.title', 'Keyboard shortcuts')}</DialogTitle>
          <DialogDescription>
            {t(
              'topBar.shortcuts.description',
              'Shortcuts are ignored while typing in a field, so text editing keeps its native behaviour.'
            )}
          </DialogDescription>
        </DialogHeader>
        <dl className="divide-y divide-border/60">
          {listEditorShortcuts(modLabel, t).map((s) => (
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
