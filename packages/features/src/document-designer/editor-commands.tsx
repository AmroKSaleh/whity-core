
import type { ReactNode } from 'react';
import type { DocElement, ElementType } from '@amroksaleh/ui/documents/types';
import { BLOCK_SCOPES, type DocBlock } from '@amroksaleh/ui/documents/blocks';
import { STARTER_TEMPLATES } from './starters';
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
import { useTranslation, type TranslateFn } from '../i18n';

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
 * object. The labels are translated, so every builder also takes the translate
 * function as a parameter rather than calling a hook — `useEditorChrome()` at
 * the bottom of this file is the single place it is bound.
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

// `%` is the platform-modifier placeholder (Ctrl on Windows/Linux, ⌘ on Mac).
// Replaced globally: a string-literal `replace` would substitute only the first
// occurrence, so a template that ever needs the modifier twice would render a
// stray `%`.
const hint = (mod: string, id: string): string | undefined => SHORTCUTS[id]?.replace(/%/g, mod);

/** Every shortcut, resolved for the current platform — for the Help sheet. */
export function listEditorShortcuts(
  modLabel: string,
  t: TranslateFn
): Array<{ id: string; label: string; keys: string }> {
  const LABELS: Record<string, string> = {
    save: t('commands.shortcut.save', 'Save template'),
    print: t('commands.shortcut.print', 'Print'),
    undo: t('commands.shortcut.undo', 'Undo'),
    redo: t('commands.shortcut.redo', 'Redo'),
    cut: t('commands.shortcut.cut', 'Cut selection'),
    copy: t('commands.shortcut.copy', 'Copy selection'),
    paste: t('commands.shortcut.paste', 'Paste'),
    duplicate: t('commands.shortcut.duplicate', 'Duplicate selection'),
    'select-all': t('commands.shortcut.selectAll', 'Select all on page'),
    'delete-selection': t('commands.shortcut.deleteSelection', 'Delete selection'),
    deselect: t('commands.shortcut.deselect', 'Deselect'),
    nudge: t('commands.shortcut.nudge', 'Nudge selection by 1mm'),
    'nudge-fast': t('commands.shortcut.nudgeFast', 'Nudge selection by 5mm'),
  };
  return Object.keys(SHORTCUTS).map((id) => ({
    id,
    label: LABELS[id] ?? id,
    keys: SHORTCUTS[id].replace(/%/g, modLabel),
  }));
}

// ── element types offered by Insert ────────────────────────────────────────

const INSERTABLE: ReadonlyArray<{ type: ElementType; icon: ReactNode }> = [
  { type: 'text', icon: <IconTypography /> },
  { type: 'dynamicText', icon: <IconVariable /> },
  { type: 'image', icon: <IconPhoto /> },
  { type: 'barcode', icon: <IconBarcode /> },
  { type: 'qr', icon: <IconQrcode /> },
  { type: 'rect', icon: <IconSquare /> },
  { type: 'line', icon: <IconLine /> },
  { type: 'math', icon: <IconMathFunction /> },
];

/** What Insert calls each element type — in the menu and in the toolbar. */
function insertLabels(t: TranslateFn): Record<ElementType, string> {
  return {
    text: t('commands.insert.text', 'Text'),
    dynamicText: t('commands.insert.dynamicText', 'Dynamic text'),
    image: t('commands.insert.image', 'Image / logo'),
    barcode: t('commands.insert.barcode', 'Barcode'),
    qr: t('commands.insert.qr', 'QR code'),
    rect: t('commands.insert.rect', 'Rectangle'),
    line: t('commands.insert.line', 'Line'),
    math: t('commands.insert.math', 'Math'),
  };
}

const ALIGNMENTS: ReadonlyArray<{ kind: AlignKind; icon: ReactNode }> = [
  { kind: 'left', icon: <IconLayoutAlignLeft /> },
  { kind: 'hcenter', icon: <IconLayoutAlignCenter /> },
  { kind: 'right', icon: <IconLayoutAlignRight /> },
  { kind: 'top', icon: <IconLayoutAlignTop /> },
  { kind: 'vmiddle', icon: <IconLayoutAlignMiddle /> },
  { kind: 'bottom', icon: <IconLayoutAlignBottom /> },
];

/** The Format ▸ Align submenu's names, where "Align" is already the heading. */
function alignLabels(t: TranslateFn): Record<AlignKind, string> {
  return {
    left: t('commands.align.left', 'Left'),
    hcenter: t('commands.align.hcenter', 'Horizontal centre'),
    right: t('commands.align.right', 'Right'),
    top: t('commands.align.top', 'Top'),
    vmiddle: t('commands.align.vmiddle', 'Vertical middle'),
    bottom: t('commands.align.bottom', 'Bottom'),
  };
}

/**
 * The toolbar's names for the same commands. Spelled out per alignment rather
 * than pasted together from "Align" + the submenu name: word order and casing
 * are English-only facts, and a translator cannot fix a sentence assembled
 * after they have seen its halves.
 */
function alignToolbarLabels(t: TranslateFn): Record<AlignKind, string> {
  return {
    left: t('commands.toolbar.alignLeft', 'Align left'),
    hcenter: t('commands.toolbar.alignHcenter', 'Align horizontal centre'),
    right: t('commands.toolbar.alignRight', 'Align right'),
    top: t('commands.toolbar.alignTop', 'Align top'),
    vmiddle: t('commands.toolbar.alignVmiddle', 'Align vertical middle'),
    bottom: t('commands.toolbar.alignBottom', 'Align bottom'),
  };
}

/**
 * The display name of a block's visibility tier.
 *
 * `BLOCK_SCOPES` is a kit constant and carries English labels, which is right —
 * a kit constant may not reach for a translator. Translating is the consumer's
 * job. Literal `t()` calls per id, never `t('commands.blockScope.' + id)`: a
 * computed key is invisible to `i18n:extract` and would never reach a
 * translator. A scope the kit adds later falls back to its English label.
 */
function blockScopeLabel(t: TranslateFn, scope: { id: string; label: string }): string {
  switch (scope.id) {
    case 'system':
      return t('commands.blockScope.system', 'System');
    case 'personal':
      return t('commands.blockScope.personal', 'Personal');
    case 'tenant':
      return t('commands.blockScope.tenant', 'Tenant-wide');
    case 'global':
      return t('commands.blockScope.global', 'Global');
    default:
      return scope.label;
  }
}

/** Blocks as menu nodes, grouped under a heading per visibility scope. */
function blockNodes(ctx: EditorCommandContext, t: TranslateFn): MenuBarNode[] {
  const out: MenuBarNode[] = [];
  for (const scope of BLOCK_SCOPES) {
    const inScope = ctx.blocks.filter((b) => b.scope === scope.id);
    if (inScope.length === 0) continue;
    out.push({ kind: 'label', id: `block-scope-${scope.id}`, label: blockScopeLabel(t, scope) });
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
export function buildEditorMenus(ctx: EditorCommandContext, t: TranslateFn): MenuBarMenu[] {
  const mod = ctx.modLabel;
  const k = (id: string) => hint(mod, id);
  const insert = insertLabels(t);
  const align = alignLabels(t);

  // Most editing commands are meaningless in preview (view-only) or need a
  // selection; these two predicates cover nearly every `disabled` below.
  const noEdit = ctx.preview || ctx.elementCount === 0;
  const noSelection = ctx.preview || ctx.selectedCount === 0;

  return [
    {
      id: 'file',
      label: t('commands.menu.file', 'File'),
      items: [
        { id: 'new', label: t('commands.file.new', 'New document'), icon: <IconFilePlus />, onSelect: ctx.onNew },
        { id: 'save', label: t('commands.file.save', 'Save'), shortcut: k('save'), disabled: ctx.blockEditing, onSelect: ctx.onSave },
        { kind: 'separator', id: 'file-sep-1' },
        { id: 'import', label: t('commands.file.import', 'Import JSON…'), onSelect: ctx.onImport },
        { id: 'export', label: t('commands.file.export', 'Export JSON'), onSelect: ctx.onExport },
        { kind: 'separator', id: 'file-sep-2' },
        { id: 'print', label: t('commands.file.print', 'Print…'), shortcut: k('print'), onSelect: ctx.onPrint },
        { kind: 'separator', id: 'file-sep-3' },
        { id: 'close-editor', label: t('commands.file.close', 'Close editor'), onSelect: ctx.onCloseEditor },
      ],
    },
    {
      id: 'edit',
      label: t('commands.menu.edit', 'Edit'),
      items: [
        { id: 'undo', label: t('commands.edit.undo', 'Undo'), icon: <IconArrowBackUp />, shortcut: k('undo'), disabled: !ctx.canUndo, onSelect: ctx.onUndo },
        { id: 'redo', label: t('commands.edit.redo', 'Redo'), icon: <IconArrowForwardUp />, shortcut: k('redo'), disabled: !ctx.canRedo, onSelect: ctx.onRedo },
        { kind: 'separator', id: 'edit-sep-1' },
        { id: 'cut', label: t('commands.edit.cut', 'Cut'), icon: <IconScissors />, shortcut: k('cut'), disabled: noSelection, onSelect: ctx.onCut },
        { id: 'copy', label: t('commands.edit.copy', 'Copy'), icon: <IconClipboardCopy />, shortcut: k('copy'), disabled: noSelection, onSelect: ctx.onCopy },
        { id: 'paste', label: t('commands.edit.paste', 'Paste'), icon: <IconClipboard />, shortcut: k('paste'), disabled: ctx.preview || !ctx.hasClipboard, onSelect: ctx.onPaste },
        { id: 'duplicate', label: t('commands.edit.duplicate', 'Duplicate'), icon: <IconCopy />, shortcut: k('duplicate'), disabled: noSelection, onSelect: ctx.onDuplicate },
        { kind: 'separator', id: 'edit-sep-2' },
        { id: 'select-all', label: t('commands.edit.selectAll', 'Select all on page'), shortcut: k('select-all'), disabled: noEdit, onSelect: ctx.onSelectAll },
        {
          id: 'delete-selection',
          label: t('commands.edit.delete', 'Delete'),
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
      label: t('commands.menu.insert', 'Insert'),
      items: [
        ...INSERTABLE.map(
          (item): MenuBarNode => ({
            id: `insert-${item.type}`,
            label: insert[item.type],
            icon: item.icon,
            disabled: ctx.preview,
            onSelect: () => ctx.onAddElement(item.type),
          })
        ),
        { kind: 'separator', id: 'insert-sep-1' },
        {
          kind: 'submenu',
          id: 'insert-block',
          label: t('commands.insert.block', 'Block'),
          icon: <IconComponents />,
          items: blockNodes(ctx, t),
          emptyLabel: t('commands.insert.blockEmpty', 'No blocks in your library'),
        },
        { kind: 'separator', id: 'insert-sep-2' },
        { id: 'insert-page', label: t('commands.insert.page', 'Page'), icon: <IconFilePlus />, disabled: ctx.blockEditing, onSelect: ctx.onAddPage },
      ],
    },
    {
      id: 'format',
      label: t('commands.menu.format', 'Format'),
      items: [
        {
          kind: 'submenu',
          id: 'align',
          label: t('commands.format.align', 'Align'),
          icon: <IconLayoutAlignLeft />,
          disabled: noSelection,
          items: ALIGNMENTS.map((a) => ({
            id: `align-${a.kind}`,
            label: align[a.kind],
            icon: a.icon,
            onSelect: () => ctx.onAlign(a.kind),
          })),
        },
        {
          kind: 'submenu',
          id: 'distribute',
          // Needs 3+ elements to mean anything: the outer two stay put and the
          // rest are spaced between them.
          label: t('commands.format.distribute', 'Distribute'),
          icon: <IconArrowsHorizontal />,
          disabled: ctx.preview || ctx.selectedCount < 3,
          items: [
            { id: 'distribute-h', label: t('commands.distribute.horizontally', 'Horizontally'), icon: <IconArrowsHorizontal />, onSelect: () => ctx.onDistribute('h') },
            { id: 'distribute-v', label: t('commands.distribute.vertically', 'Vertically'), icon: <IconArrowsVertical />, onSelect: () => ctx.onDistribute('v') },
          ],
        },
        {
          kind: 'submenu',
          id: 'arrange',
          label: t('commands.format.arrange', 'Arrange'),
          icon: <IconStackPush />,
          disabled: noSelection,
          items: [
            { id: 'bring-forward', label: t('commands.arrange.front', 'Bring to front'), icon: <IconStackPush />, onSelect: () => ctx.onArrange('up') },
            { id: 'send-backward', label: t('commands.arrange.back', 'Send to back'), icon: <IconStackPop />, onSelect: () => ctx.onArrange('down') },
          ],
        },
        { kind: 'separator', id: 'format-sep-1' },
        {
          id: 'toggle-lock',
          label: ctx.selectionLocked ? t('commands.format.unlock', 'Unlock') : t('commands.format.lock', 'Lock'),
          icon: ctx.selectionLocked ? <IconLockOpen /> : <IconLock />,
          disabled: noSelection,
          onSelect: ctx.onToggleSelectionLock,
        },
        {
          id: 'toggle-hidden',
          label: ctx.selectionHidden ? t('commands.format.show', 'Show') : t('commands.format.hide', 'Hide'),
          icon: ctx.selectionHidden ? <IconEye /> : <IconEyeOff />,
          disabled: noSelection,
          onSelect: ctx.onToggleSelectionHidden,
        },
        { kind: 'separator', id: 'format-sep-2' },
        // A block instance is a pointer, so it offers edit/detach; anything else
        // can be promoted INTO a block.
        ...(ctx.soleSelectedType === 'blockInstance'
          ? ([
              { id: 'edit-block', label: t('commands.format.editBlock', 'Edit block…'), icon: <IconComponents />, disabled: ctx.blockEditing, onSelect: ctx.onEditSelectedBlock },
              { id: 'detach-block', label: t('commands.format.detachBlock', 'Detach from block'), onSelect: ctx.onDetachSelectedBlock },
            ] as MenuBarNode[])
          : ([
              {
                id: 'save-as-block',
                label: t('commands.format.saveAsBlock', 'Save selection as block…'),
                icon: <IconComponents />,
                disabled: noSelection || ctx.blockEditing,
                onSelect: ctx.onSaveAsBlock,
              },
            ] as MenuBarNode[])),
      ],
    },
    {
      id: 'page',
      label: t('commands.menu.page', 'Page'),
      items: [
        { id: 'page-setup', label: t('commands.page.setup', 'Page setup…'), onSelect: () => ctx.onOpenInspectorTab('page') },
        { kind: 'separator', id: 'page-sep-1' },
        { id: 'add-page', label: t('commands.page.add', 'Add page'), icon: <IconFilePlus />, disabled: ctx.blockEditing, onSelect: ctx.onAddPage },
        { id: 'duplicate-page', label: t('commands.page.duplicate', 'Duplicate page'), icon: <IconFiles />, disabled: ctx.blockEditing, onSelect: ctx.onDuplicatePage },
        {
          id: 'delete-page',
          label: t('commands.page.delete', 'Delete page'),
          icon: <IconTrash />,
          disabled: ctx.pageCount <= 1,
          destructive: true,
          onSelect: ctx.onDeletePage,
        },
        { kind: 'separator', id: 'page-sep-2' },
        { id: 'move-page-left', label: t('commands.page.moveEarlier', 'Move page earlier'), disabled: ctx.pageIndex === 0, onSelect: () => ctx.onMovePage('left') },
        {
          id: 'move-page-right',
          label: t('commands.page.moveLater', 'Move page later'),
          disabled: ctx.pageIndex >= ctx.pageCount - 1,
          onSelect: () => ctx.onMovePage('right'),
        },
        { kind: 'separator', id: 'page-sep-3' },
        {
          kind: 'submenu',
          id: 'go-to-page',
          label: t('commands.page.goTo', 'Go to page'),
          items: Array.from({ length: ctx.pageCount }, (_, i) => ({
            id: `go-to-page-${i}`,
            label: t('commands.page.nth', 'Page {n}', { n: i + 1 }),
            disabled: i === ctx.pageIndex,
            onSelect: () => ctx.onGoToPage(i),
          })),
        },
      ],
    },
    {
      id: 'view',
      label: t('commands.menu.view', 'View'),
      items: [
        { kind: 'checkbox', id: 'preview', label: t('commands.view.preview', 'Preview'), checked: ctx.preview, onCheckedChange: ctx.onTogglePreview },
        { kind: 'separator', id: 'view-sep-1' },
        // Edit-time aids only — preview and print never show them.
        { kind: 'checkbox', id: 'grid', label: t('commands.view.grid', 'Grid'), checked: ctx.showGrid, disabled: ctx.preview, onCheckedChange: ctx.onSetShowGrid },
        { kind: 'checkbox', id: 'rulers', label: t('commands.view.rulers', 'Rulers'), checked: ctx.showRulers, disabled: ctx.preview, onCheckedChange: ctx.onSetShowRulers },
        { kind: 'checkbox', id: 'snap', label: t('commands.view.snap', 'Snap to grid & guides'), checked: ctx.snap, disabled: ctx.preview, onCheckedChange: ctx.onSetSnap },
        { kind: 'separator', id: 'view-sep-2' },
        { kind: 'checkbox', id: 'rail', label: t('commands.view.rail', 'Side panel'), checked: ctx.railOpen, onCheckedChange: ctx.onSetRailOpen },
        { kind: 'separator', id: 'view-sep-3' },
        { id: 'zoom-in', label: t('commands.view.zoomIn', 'Zoom in'), onSelect: () => ctx.onZoom('in') },
        { id: 'zoom-out', label: t('commands.view.zoomOut', 'Zoom out'), onSelect: () => ctx.onZoom('out') },
        { id: 'zoom-reset', label: t('commands.view.zoomReset', 'Zoom to 100%'), onSelect: () => ctx.onZoom('reset') },
        { id: 'zoom-fit', label: t('commands.view.zoomFit', 'Fit page in window'), onSelect: () => ctx.onZoom('fit') },
      ],
    },
    {
      id: 'data',
      label: t('commands.menu.data', 'Data'),
      items: [
        { id: 'placeholders', label: t('commands.data.placeholders', 'Placeholders…'), onSelect: () => ctx.onOpenInspectorTab('data') },
        { kind: 'separator', id: 'data-sep-1' },
        { id: 'batch', label: t('commands.data.batch', 'Variable data / batch…'), onSelect: () => ctx.onOpenInspectorTab('batch') },
        { id: 'clear-batch', label: t('commands.data.clearBatch', 'Clear batch'), disabled: !ctx.batchActive, onSelect: ctx.onClearBatch },
        { kind: 'separator', id: 'data-sep-2' },
        {
          id: 'batch-prev',
          label: t('commands.data.previousRow', 'Previous data row'),
          disabled: !ctx.batchActive || ctx.batchIndex <= 0,
          onSelect: () => ctx.onStepBatch(-1),
        },
        {
          id: 'batch-next',
          label: t('commands.data.nextRow', 'Next data row'),
          disabled: !ctx.batchActive || ctx.batchIndex >= ctx.batchTotal - 1,
          onSelect: () => ctx.onStepBatch(1),
        },
        { kind: 'separator', id: 'data-sep-3' },
        { id: 'sheet-layout', label: t('commands.data.sheetLayout', 'Label sheet layout…'), onSelect: () => ctx.onOpenInspectorTab('sheet') },
      ],
    },
    {
      id: 'templates',
      label: t('commands.menu.templates', 'Templates'),
      items: [
        {
          kind: 'submenu',
          id: 'start-from',
          label: t('commands.templates.startFrom', 'Start from'),
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
          label: t('commands.templates.openSaved', 'Open saved'),
          items: ctx.savedTemplates.map((s) => ({
            id: `open-saved-${s.id}`,
            label: s.name,
            disabled: s.id === ctx.currentSavedId,
            onSelect: () => ctx.onOpenSaved(s.id),
          })),
          emptyLabel: t('commands.templates.openSavedEmpty', 'No saved templates yet'),
        },
        { kind: 'separator', id: 'templates-sep-2' },
        {
          id: 'delete-saved',
          label: t('commands.templates.deleteSaved', 'Delete this saved template'),
          icon: <IconTrash />,
          disabled: ctx.currentSavedId === null,
          destructive: true,
          onSelect: ctx.onDeleteSaved,
        },
      ],
    },
    {
      id: 'help',
      label: t('commands.menu.help', 'Help'),
      items: [{ id: 'shortcuts', label: t('commands.help.shortcuts', 'Keyboard shortcuts…'), onSelect: ctx.onShowShortcuts }],
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
export function buildEditorToolbar(ctx: EditorCommandContext, t: TranslateFn): ToolbarGroup[] {
  const mod = ctx.modLabel;
  const k = (id: string) => hint(mod, id);
  const insert = insertLabels(t);
  const align = alignToolbarLabels(t);
  const noSelection = ctx.preview || ctx.selectedCount === 0;

  return [
    {
      id: 'history',
      buttons: [
        { id: 'undo', label: t('commands.edit.undo', 'Undo'), icon: <IconArrowBackUp />, shortcut: k('undo'), disabled: !ctx.canUndo, onSelect: ctx.onUndo },
        { id: 'redo', label: t('commands.edit.redo', 'Redo'), icon: <IconArrowForwardUp />, shortcut: k('redo'), disabled: !ctx.canRedo, onSelect: ctx.onRedo },
      ],
    },
    {
      id: 'clipboard',
      buttons: [
        { id: 'cut', label: t('commands.edit.cut', 'Cut'), icon: <IconScissors />, shortcut: k('cut'), disabled: noSelection, onSelect: ctx.onCut },
        { id: 'copy', label: t('commands.edit.copy', 'Copy'), icon: <IconClipboardCopy />, shortcut: k('copy'), disabled: noSelection, onSelect: ctx.onCopy },
        { id: 'paste', label: t('commands.edit.paste', 'Paste'), icon: <IconClipboard />, shortcut: k('paste'), disabled: ctx.preview || !ctx.hasClipboard, onSelect: ctx.onPaste },
        { id: 'duplicate', label: t('commands.edit.duplicate', 'Duplicate'), icon: <IconCopy />, shortcut: k('duplicate'), disabled: noSelection, onSelect: ctx.onDuplicate },
      ],
    },
    {
      id: 'insert',
      buttons: INSERTABLE.map((item) => ({
        id: `insert-${item.type}`,
        label: insert[item.type],
        icon: item.icon,
        disabled: ctx.preview,
        onSelect: () => ctx.onAddElement(item.type),
      })),
    },
    {
      id: 'align',
      buttons: ALIGNMENTS.map((a) => ({
        id: `align-${a.kind}`,
        label: align[a.kind],
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
          label: t('commands.toolbar.distributeHorizontally', 'Distribute horizontally'),
          icon: <IconArrowsHorizontal />,
          disabled: ctx.preview || ctx.selectedCount < 3,
          onSelect: () => ctx.onDistribute('h'),
        },
        {
          id: 'distribute-v',
          label: t('commands.toolbar.distributeVertically', 'Distribute vertically'),
          icon: <IconArrowsVertical />,
          disabled: ctx.preview || ctx.selectedCount < 3,
          onSelect: () => ctx.onDistribute('v'),
        },
      ],
    },
    {
      id: 'arrange',
      buttons: [
        { id: 'bring-forward', label: t('commands.arrange.front', 'Bring to front'), icon: <IconStackPush />, disabled: noSelection, onSelect: () => ctx.onArrange('up') },
        { id: 'send-backward', label: t('commands.arrange.back', 'Send to back'), icon: <IconStackPop />, disabled: noSelection, onSelect: () => ctx.onArrange('down') },
        {
          id: 'toggle-lock',
          label: ctx.selectionLocked ? t('commands.format.unlock', 'Unlock') : t('commands.format.lock', 'Lock'),
          icon: ctx.selectionLocked ? <IconLockOpen /> : <IconLock />,
          disabled: noSelection,
          active: ctx.selectionLocked,
          onSelect: ctx.onToggleSelectionLock,
        },
        {
          id: 'toggle-hidden',
          label: ctx.selectionHidden ? t('commands.format.show', 'Show') : t('commands.format.hide', 'Hide'),
          icon: ctx.selectionHidden ? <IconEyeOff /> : <IconEye />,
          disabled: noSelection,
          active: ctx.selectionHidden,
          onSelect: ctx.onToggleSelectionHidden,
        },
      ],
    },
  ];
}

// ── binding the chrome's translations ──────────────────────────────────────

/**
 * The menu bar and the toolbar, with their labels translated.
 *
 * The builders above stay PURE — they take the translate function as an
 * argument — but a translate function can only come from a hook, so this is
 * the one place the editor chrome's DOMAIN is named. Binding it in this file
 * rather than in the chrome that renders it keeps the domain next to the
 * labels it names, which is also what lets the catalogue extractor resolve
 * every `t()` call above (see docs/wiki/Internationalization.md).
 */
export function useEditorChrome(ctx: EditorCommandContext): {
  menus: MenuBarMenu[];
  groups: ToolbarGroup[];
} {
  const t = useTranslation('documents');

  return { menus: buildEditorMenus(ctx, t), groups: buildEditorToolbar(ctx, t) };
}
