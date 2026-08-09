'use client';

import type { ReactNode } from 'react';
import type { DocElement, ElementType } from '@/lib/documents/types';
import { BLOCK_SCOPES, type DocBlock } from '@/lib/documents/blocks';
import { STARTER_TEMPLATES } from '@/lib/documents/starters';
import type { MenuBarMenu, MenuBarNode } from '@amroksaleh/ui/menu-bar';
import {
  IconTypography,
  IconVariable,
  IconPhoto,
  IconBarcode,
  IconQrcode,
  IconSquare,
  IconLine,
  IconMathFunction,
  IconComponents,
  IconArrowBackUp,
  IconArrowForwardUp,
  IconCopy,
  IconClipboard,
  IconScissors,
  IconClipboardCopy,
  IconTrash,
  IconLayoutAlignLeft,
  IconLayoutAlignCenter,
  IconLayoutAlignRight,
  IconLayoutAlignTop,
  IconLayoutAlignMiddle,
  IconLayoutAlignBottom,
  IconArrowsHorizontal,
  IconArrowsVertical,
  IconLock,
  IconLockOpen,
  IconEye,
  IconEyeOff,
  IconStackPush,
  IconStackPop,
  IconFilePlus,
  IconFiles,
} from '@tabler/icons-react';

/**
 * THE COMMAND REGISTRY for the document editor.
 *
 * Every user-invokable command is declared here exactly once — its label, icon,
 * shortcut hint, when it's available, and what it does — and the whole editor
 * chrome is then DERIVED from that one declaration:
 *
 *   • the menu bar        → `buildEditorMenus(ctx)`
 *   • the icon toolbar    → `buildEditorToolbar(ctx)`
 *   • the shortcuts sheet → `listEditorShortcuts(ctx)`
 *
 * That is the point of the indirection: a new command becomes ONE entry that
 * appears in every surface consistently, instead of a button hand-placed in a
 * toolbar, a duplicate menu item, a separate `keydown` branch and a shortcut
 * list that silently drifts out of date. The availability rules (`disabled`)
 * live here too, so "can I do this right now?" has a single answer rather than
 * one per surface.
 *
 * This module is PURE: it holds no state and calls nothing on its own. The
 * designer owns all state and passes it in as `EditorCommandContext`, which
 * also makes the whole chrome trivially renderable in Storybook from a plain
 * object.
 */

// ── the context the designer supplies ───────────────────────────────────────

export type AlignKind = 'left' | 'hcenter' | 'right' | 'top' | 'vmiddle' | 'bottom';
export type ZoomAction = 'in' | 'out' | 'reset' | 'fit';
export type InspectorTab = 'element' | 'page' | 'data' | 'batch' | 'sheet';

export interface EditorCommandContext {
  // ── state the availability rules read ──
  /** Label for the platform modifier, e.g. "Ctrl" or "⌘". */
  modLabel: string;
  canUndo: boolean;
  canRedo: boolean;
  hasClipboard: boolean;
  /** Elements currently selected on the page. */
  selectedCount: number;
  /** Type of the sole selected element, or null when 0 or 2+ are selected. */
  soleSelectedType: DocElement['type'] | null;
  /** True when every selected element is locked (so lock toggles to "Unlock"). */
  selectionLocked: boolean;
  selectionHidden: boolean;
  pageIndex: number;
  pageCount: number;
  elementCount: number;
  preview: boolean;
  showGrid: boolean;
  showRulers: boolean;
  snap: boolean;
  /** Whether the single side rail (layers + properties) is showing. */
  railOpen: boolean;
  /** Saved templates the caller can open (already RBAC-filtered server-side). */
  savedTemplates: ReadonlyArray<{ id: string; name: string }>;
  /** The currently-open saved template, if this document came from one. */
  currentSavedId: string | null;
  blocks: ReadonlyArray<DocBlock>;
  batchActive: boolean;
  batchIndex: number;
  batchTotal: number;
  /** True while the editor is repurposed to edit a single block. */
  blockEditing: boolean;

  // ── the commands themselves ──
  onNew: () => void;
  onStartFrom: (starterId: string) => void;
  onOpenSaved: (id: string) => void;
  onSave: () => void;
  onDeleteSaved: () => void;
  onImport: () => void;
  onExport: () => void;
  onPrint: () => void;
  onCloseEditor: () => void;

  onUndo: () => void;
  onRedo: () => void;
  onCut: () => void;
  onCopy: () => void;
  onPaste: () => void;
  onDuplicate: () => void;
  onDeleteSelection: () => void;
  onSelectAll: () => void;

  onAddElement: (type: ElementType) => void;
  onInsertBlock: (blockId: string) => void;

  onAlign: (kind: AlignKind) => void;
  onDistribute: (axis: 'h' | 'v') => void;
  onArrange: (dir: 'up' | 'down') => void;
  onToggleSelectionLock: () => void;
  onToggleSelectionHidden: () => void;
  onSaveAsBlock: () => void;
  onEditSelectedBlock: () => void;
  onDetachSelectedBlock: () => void;

  onAddPage: () => void;
  onDuplicatePage: () => void;
  onDeletePage: () => void;
  onMovePage: (dir: 'left' | 'right') => void;
  onGoToPage: (index: number) => void;

  onTogglePreview: () => void;
  onSetShowGrid: (v: boolean) => void;
  onSetShowRulers: (v: boolean) => void;
  onSetSnap: (v: boolean) => void;
  onSetRailOpen: (open: boolean) => void;
  onZoom: (action: ZoomAction) => void;

  onOpenInspectorTab: (tab: InspectorTab) => void;
  onClearBatch: () => void;
  onStepBatch: (delta: number) => void;

  onShowShortcuts: () => void;
}

// ── shortcut hints ─────────────────────────────────────────────────────────

/**
 * Shortcut hints, as DISPLAY strings keyed by command id. `%` stands in for the
 * platform modifier so one table serves both Windows/Linux ("Ctrl") and macOS
 * ("⌘") — see `EditorCommandContext.modLabel`.
 *
 * These mirror the bindings the designer's `keydown` handler implements; the
 * handler stays the single place that *listens*, this stays the single place
 * that *describes*, and Help ▸ Keyboard shortcuts reads straight off it.
 */
const SHORTCUTS: Record<string, string> = {
  save: '%+S',
  print: '%+P',
  undo: '%+Z',
  redo: '%+Shift+Z',
  cut: '%+X',
  copy: '%+C',
  paste: '%+V',
  duplicate: '%+D',
  'select-all': '%+A',
  'delete-selection': 'Delete',
  deselect: 'Esc',
  nudge: 'Arrow keys',
  'nudge-fast': 'Shift + Arrows',
};

const hint = (mod: string, id: string): string | undefined => SHORTCUTS[id]?.replace('%', mod);

/** Every shortcut, resolved for the current platform — for the Help sheet. */
export function listEditorShortcuts(modLabel: string): Array<{ id: string; label: string; keys: string }> {
  const LABELS: Record<string, string> = {
    save: 'Save template',
    print: 'Print',
    undo: 'Undo',
    redo: 'Redo',
    cut: 'Cut selection',
    copy: 'Copy selection',
    paste: 'Paste',
    duplicate: 'Duplicate selection',
    'select-all': 'Select all on page',
    'delete-selection': 'Delete selection',
    deselect: 'Deselect',
    nudge: 'Nudge selection by 1mm',
    'nudge-fast': 'Nudge selection by 5mm',
  };
  return Object.keys(SHORTCUTS).map((id) => ({
    id,
    label: LABELS[id] ?? id,
    keys: SHORTCUTS[id].replace('%', modLabel),
  }));
}

// ── element types offered by Insert ────────────────────────────────────────

const INSERTABLE: ReadonlyArray<{ type: ElementType; label: string; icon: ReactNode }> = [
  { type: 'text', label: 'Text', icon: <IconTypography /> },
  { type: 'dynamicText', label: 'Dynamic text', icon: <IconVariable /> },
  { type: 'image', label: 'Image / logo', icon: <IconPhoto /> },
  { type: 'barcode', label: 'Barcode', icon: <IconBarcode /> },
  { type: 'qr', label: 'QR code', icon: <IconQrcode /> },
  { type: 'rect', label: 'Rectangle', icon: <IconSquare /> },
  { type: 'line', label: 'Line', icon: <IconLine /> },
  { type: 'math', label: 'Math', icon: <IconMathFunction /> },
];

const ALIGNMENTS: ReadonlyArray<{ kind: AlignKind; label: string; icon: ReactNode }> = [
  { kind: 'left', label: 'Left', icon: <IconLayoutAlignLeft /> },
  { kind: 'hcenter', label: 'Horizontal centre', icon: <IconLayoutAlignCenter /> },
  { kind: 'right', label: 'Right', icon: <IconLayoutAlignRight /> },
  { kind: 'top', label: 'Top', icon: <IconLayoutAlignTop /> },
  { kind: 'vmiddle', label: 'Vertical middle', icon: <IconLayoutAlignMiddle /> },
  { kind: 'bottom', label: 'Bottom', icon: <IconLayoutAlignBottom /> },
];

/** Blocks as menu nodes, grouped under a heading per visibility scope. */
function blockNodes(ctx: EditorCommandContext): MenuBarNode[] {
  const out: MenuBarNode[] = [];
  for (const scope of BLOCK_SCOPES) {
    const inScope = ctx.blocks.filter((b) => b.scope === scope.id);
    if (inScope.length === 0) continue;
    out.push({ kind: 'label', id: `block-scope-${scope.id}`, label: scope.label });
    for (const b of inScope) {
      out.push({
        id: `insert-block-${b.id}`,
        label: b.name,
        icon: <IconComponents />,
        disabled: ctx.preview,
        onSelect: () => ctx.onInsertBlock(b.id),
      });
    }
  }
  return out;
}

// ── the menu bar ───────────────────────────────────────────────────────────

/**
 * The editor's menu bar. Ordered File → Edit → Insert → Format → Page → View →
 * Data → Templates → Help, i.e. document-level actions first, then editing, then
 * the things you configure once per template, matching where a Docs/Word user
 * already expects to find each of them.
 */
export function buildEditorMenus(ctx: EditorCommandContext): MenuBarMenu[] {
  const mod = ctx.modLabel;
  const k = (id: string) => hint(mod, id);

  // Most editing commands are meaningless in preview (view-only) or need a
  // selection; these two predicates cover nearly every `disabled` below.
  const noEdit = ctx.preview || ctx.elementCount === 0;
  const noSelection = ctx.preview || ctx.selectedCount === 0;

  return [
    {
      id: 'file',
      label: 'File',
      items: [
        { id: 'new', label: 'New document', icon: <IconFilePlus />, onSelect: ctx.onNew },
        { id: 'save', label: 'Save', shortcut: k('save'), disabled: ctx.blockEditing, onSelect: ctx.onSave },
        { kind: 'separator', id: 'file-sep-1' },
        { id: 'import', label: 'Import JSON…', onSelect: ctx.onImport },
        { id: 'export', label: 'Export JSON', onSelect: ctx.onExport },
        { kind: 'separator', id: 'file-sep-2' },
        { id: 'print', label: 'Print…', shortcut: k('print'), onSelect: ctx.onPrint },
        { kind: 'separator', id: 'file-sep-3' },
        { id: 'close-editor', label: 'Close editor', onSelect: ctx.onCloseEditor },
      ],
    },
    {
      id: 'edit',
      label: 'Edit',
      items: [
        { id: 'undo', label: 'Undo', icon: <IconArrowBackUp />, shortcut: k('undo'), disabled: !ctx.canUndo, onSelect: ctx.onUndo },
        { id: 'redo', label: 'Redo', icon: <IconArrowForwardUp />, shortcut: k('redo'), disabled: !ctx.canRedo, onSelect: ctx.onRedo },
        { kind: 'separator', id: 'edit-sep-1' },
        { id: 'cut', label: 'Cut', icon: <IconScissors />, shortcut: k('cut'), disabled: noSelection, onSelect: ctx.onCut },
        { id: 'copy', label: 'Copy', icon: <IconClipboardCopy />, shortcut: k('copy'), disabled: noSelection, onSelect: ctx.onCopy },
        { id: 'paste', label: 'Paste', icon: <IconClipboard />, shortcut: k('paste'), disabled: ctx.preview || !ctx.hasClipboard, onSelect: ctx.onPaste },
        { id: 'duplicate', label: 'Duplicate', icon: <IconCopy />, shortcut: k('duplicate'), disabled: noSelection, onSelect: ctx.onDuplicate },
        { kind: 'separator', id: 'edit-sep-2' },
        { id: 'select-all', label: 'Select all on page', shortcut: k('select-all'), disabled: noEdit, onSelect: ctx.onSelectAll },
        {
          id: 'delete-selection',
          label: 'Delete',
          icon: <IconTrash />,
          shortcut: k('delete-selection'),
          disabled: noSelection,
          destructive: true,
          onSelect: ctx.onDeleteSelection,
        },
      ],
    },
    {
      id: 'insert',
      label: 'Insert',
      items: [
        ...INSERTABLE.map(
          (item): MenuBarNode => ({
            id: `insert-${item.type}`,
            label: item.label,
            icon: item.icon,
            disabled: ctx.preview,
            onSelect: () => ctx.onAddElement(item.type),
          })
        ),
        { kind: 'separator', id: 'insert-sep-1' },
        {
          kind: 'submenu',
          id: 'insert-block',
          label: 'Block',
          icon: <IconComponents />,
          items: blockNodes(ctx),
          emptyLabel: 'No blocks in your library',
        },
        { kind: 'separator', id: 'insert-sep-2' },
        { id: 'insert-page', label: 'Page', icon: <IconFilePlus />, disabled: ctx.blockEditing, onSelect: ctx.onAddPage },
      ],
    },
    {
      id: 'format',
      label: 'Format',
      items: [
        {
          kind: 'submenu',
          id: 'align',
          label: 'Align',
          icon: <IconLayoutAlignLeft />,
          disabled: noSelection,
          items: ALIGNMENTS.map((a) => ({
            id: `align-${a.kind}`,
            label: a.label,
            icon: a.icon,
            onSelect: () => ctx.onAlign(a.kind),
          })),
        },
        {
          kind: 'submenu',
          id: 'distribute',
          // Needs 3+ elements to mean anything: the outer two stay put and the
          // rest are spaced between them.
          label: 'Distribute',
          icon: <IconArrowsHorizontal />,
          disabled: ctx.preview || ctx.selectedCount < 3,
          items: [
            { id: 'distribute-h', label: 'Horizontally', icon: <IconArrowsHorizontal />, onSelect: () => ctx.onDistribute('h') },
            { id: 'distribute-v', label: 'Vertically', icon: <IconArrowsVertical />, onSelect: () => ctx.onDistribute('v') },
          ],
        },
        {
          kind: 'submenu',
          id: 'arrange',
          label: 'Arrange',
          icon: <IconStackPush />,
          disabled: noSelection,
          items: [
            { id: 'bring-forward', label: 'Bring to front', icon: <IconStackPush />, onSelect: () => ctx.onArrange('up') },
            { id: 'send-backward', label: 'Send to back', icon: <IconStackPop />, onSelect: () => ctx.onArrange('down') },
          ],
        },
        { kind: 'separator', id: 'format-sep-1' },
        {
          id: 'toggle-lock',
          label: ctx.selectionLocked ? 'Unlock' : 'Lock',
          icon: ctx.selectionLocked ? <IconLockOpen /> : <IconLock />,
          disabled: noSelection,
          onSelect: ctx.onToggleSelectionLock,
        },
        {
          id: 'toggle-hidden',
          label: ctx.selectionHidden ? 'Show' : 'Hide',
          icon: ctx.selectionHidden ? <IconEye /> : <IconEyeOff />,
          disabled: noSelection,
          onSelect: ctx.onToggleSelectionHidden,
        },
        { kind: 'separator', id: 'format-sep-2' },
        // A block instance is a pointer, so it offers edit/detach; anything else
        // can be promoted INTO a block.
        ...(ctx.soleSelectedType === 'blockInstance'
          ? ([
              { id: 'edit-block', label: 'Edit block…', icon: <IconComponents />, disabled: ctx.blockEditing, onSelect: ctx.onEditSelectedBlock },
              { id: 'detach-block', label: 'Detach from block', onSelect: ctx.onDetachSelectedBlock },
            ] as MenuBarNode[])
          : ([
              {
                id: 'save-as-block',
                label: 'Save selection as block…',
                icon: <IconComponents />,
                disabled: noSelection || ctx.blockEditing,
                onSelect: ctx.onSaveAsBlock,
              },
            ] as MenuBarNode[])),
      ],
    },
    {
      id: 'page',
      label: 'Page',
      items: [
        { id: 'page-setup', label: 'Page setup…', onSelect: () => ctx.onOpenInspectorTab('page') },
        { kind: 'separator', id: 'page-sep-1' },
        { id: 'add-page', label: 'Add page', icon: <IconFilePlus />, disabled: ctx.blockEditing, onSelect: ctx.onAddPage },
        { id: 'duplicate-page', label: 'Duplicate page', icon: <IconFiles />, disabled: ctx.blockEditing, onSelect: ctx.onDuplicatePage },
        {
          id: 'delete-page',
          label: 'Delete page',
          icon: <IconTrash />,
          disabled: ctx.pageCount <= 1,
          destructive: true,
          onSelect: ctx.onDeletePage,
        },
        { kind: 'separator', id: 'page-sep-2' },
        { id: 'move-page-left', label: 'Move page earlier', disabled: ctx.pageIndex === 0, onSelect: () => ctx.onMovePage('left') },
        {
          id: 'move-page-right',
          label: 'Move page later',
          disabled: ctx.pageIndex >= ctx.pageCount - 1,
          onSelect: () => ctx.onMovePage('right'),
        },
        { kind: 'separator', id: 'page-sep-3' },
        {
          kind: 'submenu',
          id: 'go-to-page',
          label: 'Go to page',
          items: Array.from({ length: ctx.pageCount }, (_, i) => ({
            id: `go-to-page-${i}`,
            label: `Page ${i + 1}`,
            disabled: i === ctx.pageIndex,
            onSelect: () => ctx.onGoToPage(i),
          })),
        },
      ],
    },
    {
      id: 'view',
      label: 'View',
      items: [
        { kind: 'checkbox', id: 'preview', label: 'Preview', checked: ctx.preview, onCheckedChange: ctx.onTogglePreview },
        { kind: 'separator', id: 'view-sep-1' },
        // Edit-time aids only — preview and print never show them.
        { kind: 'checkbox', id: 'grid', label: 'Grid', checked: ctx.showGrid, disabled: ctx.preview, onCheckedChange: ctx.onSetShowGrid },
        { kind: 'checkbox', id: 'rulers', label: 'Rulers', checked: ctx.showRulers, disabled: ctx.preview, onCheckedChange: ctx.onSetShowRulers },
        { kind: 'checkbox', id: 'snap', label: 'Snap to grid & guides', checked: ctx.snap, disabled: ctx.preview, onCheckedChange: ctx.onSetSnap },
        { kind: 'separator', id: 'view-sep-2' },
        { kind: 'checkbox', id: 'rail', label: 'Side panel', checked: ctx.railOpen, onCheckedChange: ctx.onSetRailOpen },
        { kind: 'separator', id: 'view-sep-3' },
        { id: 'zoom-in', label: 'Zoom in', onSelect: () => ctx.onZoom('in') },
        { id: 'zoom-out', label: 'Zoom out', onSelect: () => ctx.onZoom('out') },
        { id: 'zoom-reset', label: 'Zoom to 100%', onSelect: () => ctx.onZoom('reset') },
        { id: 'zoom-fit', label: 'Fit page in window', onSelect: () => ctx.onZoom('fit') },
      ],
    },
    {
      id: 'data',
      label: 'Data',
      items: [
        { id: 'placeholders', label: 'Placeholders…', onSelect: () => ctx.onOpenInspectorTab('data') },
        { kind: 'separator', id: 'data-sep-1' },
        { id: 'batch', label: 'Variable data / batch…', onSelect: () => ctx.onOpenInspectorTab('batch') },
        { id: 'clear-batch', label: 'Clear batch', disabled: !ctx.batchActive, onSelect: ctx.onClearBatch },
        { kind: 'separator', id: 'data-sep-2' },
        {
          id: 'batch-prev',
          label: 'Previous data row',
          disabled: !ctx.batchActive || ctx.batchIndex <= 0,
          onSelect: () => ctx.onStepBatch(-1),
        },
        {
          id: 'batch-next',
          label: 'Next data row',
          disabled: !ctx.batchActive || ctx.batchIndex >= ctx.batchTotal - 1,
          onSelect: () => ctx.onStepBatch(1),
        },
        { kind: 'separator', id: 'data-sep-3' },
        { id: 'sheet-layout', label: 'Label sheet layout…', onSelect: () => ctx.onOpenInspectorTab('sheet') },
      ],
    },
    {
      id: 'templates',
      label: 'Templates',
      items: [
        {
          kind: 'submenu',
          id: 'start-from',
          label: 'Start from',
          items: STARTER_TEMPLATES.map((s) => ({
            id: `start-from-${s.id}`,
            label: s.label,
            onSelect: () => ctx.onStartFrom(s.id),
          })),
        },
        { kind: 'separator', id: 'templates-sep-1' },
        {
          kind: 'submenu',
          id: 'open-saved',
          label: 'Open saved',
          items: ctx.savedTemplates.map((s) => ({
            id: `open-saved-${s.id}`,
            label: s.name,
            disabled: s.id === ctx.currentSavedId,
            onSelect: () => ctx.onOpenSaved(s.id),
          })),
          emptyLabel: 'No saved templates yet',
        },
        { kind: 'separator', id: 'templates-sep-2' },
        {
          id: 'delete-saved',
          label: 'Delete this saved template',
          icon: <IconTrash />,
          disabled: ctx.currentSavedId === null,
          destructive: true,
          onSelect: ctx.onDeleteSaved,
        },
      ],
    },
    {
      id: 'help',
      label: 'Help',
      items: [{ id: 'shortcuts', label: 'Keyboard shortcuts…', onSelect: ctx.onShowShortcuts }],
    },
  ];
}

// ── the icon toolbar ───────────────────────────────────────────────────────

export interface ToolbarButton {
  id: string;
  /** Accessible name — the toolbar shows icons only. */
  label: string;
  icon: ReactNode;
  shortcut?: string;
  disabled?: boolean;
  /** Renders pressed (a toggle that is currently on). */
  active?: boolean;
  onSelect: () => void;
}

export interface ToolbarGroup {
  id: string;
  buttons: ToolbarButton[];
}

/**
 * The frequent-command strip under the menu bar. Deliberately a SUBSET of the
 * menus — never a command that exists only here — so the menu bar stays the
 * complete, discoverable index of what the editor can do.
 */
export function buildEditorToolbar(ctx: EditorCommandContext): ToolbarGroup[] {
  const mod = ctx.modLabel;
  const k = (id: string) => hint(mod, id);
  const noSelection = ctx.preview || ctx.selectedCount === 0;

  return [
    {
      id: 'history',
      buttons: [
        { id: 'undo', label: 'Undo', icon: <IconArrowBackUp />, shortcut: k('undo'), disabled: !ctx.canUndo, onSelect: ctx.onUndo },
        { id: 'redo', label: 'Redo', icon: <IconArrowForwardUp />, shortcut: k('redo'), disabled: !ctx.canRedo, onSelect: ctx.onRedo },
      ],
    },
    {
      id: 'clipboard',
      buttons: [
        { id: 'cut', label: 'Cut', icon: <IconScissors />, shortcut: k('cut'), disabled: noSelection, onSelect: ctx.onCut },
        { id: 'copy', label: 'Copy', icon: <IconClipboardCopy />, shortcut: k('copy'), disabled: noSelection, onSelect: ctx.onCopy },
        { id: 'paste', label: 'Paste', icon: <IconClipboard />, shortcut: k('paste'), disabled: ctx.preview || !ctx.hasClipboard, onSelect: ctx.onPaste },
        { id: 'duplicate', label: 'Duplicate', icon: <IconCopy />, shortcut: k('duplicate'), disabled: noSelection, onSelect: ctx.onDuplicate },
      ],
    },
    {
      id: 'insert',
      buttons: INSERTABLE.map((item) => ({
        id: `insert-${item.type}`,
        label: item.label,
        icon: item.icon,
        disabled: ctx.preview,
        onSelect: () => ctx.onAddElement(item.type),
      })),
    },
    {
      id: 'align',
      buttons: ALIGNMENTS.map((a) => ({
        id: `align-${a.kind}`,
        label: `Align ${a.label.toLowerCase()}`,
        icon: a.icon,
        disabled: noSelection,
        onSelect: () => ctx.onAlign(a.kind),
      })),
    },
    {
      id: 'distribute',
      buttons: [
        {
          id: 'distribute-h',
          label: 'Distribute horizontally',
          icon: <IconArrowsHorizontal />,
          disabled: ctx.preview || ctx.selectedCount < 3,
          onSelect: () => ctx.onDistribute('h'),
        },
        {
          id: 'distribute-v',
          label: 'Distribute vertically',
          icon: <IconArrowsVertical />,
          disabled: ctx.preview || ctx.selectedCount < 3,
          onSelect: () => ctx.onDistribute('v'),
        },
      ],
    },
    {
      id: 'arrange',
      buttons: [
        { id: 'bring-forward', label: 'Bring to front', icon: <IconStackPush />, disabled: noSelection, onSelect: () => ctx.onArrange('up') },
        { id: 'send-backward', label: 'Send to back', icon: <IconStackPop />, disabled: noSelection, onSelect: () => ctx.onArrange('down') },
        {
          id: 'toggle-lock',
          label: ctx.selectionLocked ? 'Unlock' : 'Lock',
          icon: ctx.selectionLocked ? <IconLockOpen /> : <IconLock />,
          disabled: noSelection,
          active: ctx.selectionLocked,
          onSelect: ctx.onToggleSelectionLock,
        },
        {
          id: 'toggle-hidden',
          label: ctx.selectionHidden ? 'Show' : 'Hide',
          icon: ctx.selectionHidden ? <IconEyeOff /> : <IconEye />,
          disabled: noSelection,
          active: ctx.selectionHidden,
          onSelect: ctx.onToggleSelectionHidden,
        },
      ],
    },
  ];
}
