import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import type { DocElement, DocTemplate, ElementType, PageSpec, Placeholder } from '@amroksaleh/ui/documents/types';
import {
  blankTemplate,
  newPageId,
} from './presets';
import {
  exportTemplateJson,
  isDocTemplate,
  migrateTemplate,
  newElement,
  repointBlockInstances,
  sampleDataOf,
} from './template-model';
import {
  DEFAULT_SEQUENCE,
  generateSequence,
  rowsFromRecords,
  rowsFromValues,
  type SequenceConfig,
} from './batch';
import { DEFAULT_SHEET, type SheetSpec } from '@amroksaleh/ui/documents/sheet';
import { STARTER_BLOCKS, STARTER_TEMPLATES } from './starters';
import {
  blocksById,
  makeBlockFromElements,
  resolveInstance,
  wouldCycle,
  type BlockScope,
  type DocBlock,
} from '@amroksaleh/ui/documents/blocks';
import { noopNotify, type DocumentDesignerScreenProps, type SavedTemplate } from './types';
import { useTranslation } from '../i18n';
import { Button } from '@amroksaleh/ui/button';
import { PX_PER_MM } from '@amroksaleh/ui/documents/types';
import {
  IconPlus,
  IconTrash,
  IconChevronLeft,
  IconChevronRight,
  IconFiles,
  IconLayoutSidebarRightExpand,
} from '@tabler/icons-react';
import { Canvas } from './canvas';
import { SideRail, type RailTab } from './side-rail';
import { PrintDocument } from './print-document';
import { EditorTopBar, ShortcutsDialog, useModLabel } from './editor-top-bar';
import {
  buildEditorMenus,
  flowBlockLabel,
  blockDeleteConsequence,
  blockDeletedMessage,
  savedMessage,
  sharedTemplateWarning,
  type EditorCommandContext,
  type ZoomAction,
} from './editor-commands';
import { ConfirmDelete } from './confirm-delete';
import { CommandPalette } from '@amroksaleh/ui/command-palette';
import { FlowEditor } from './flow-editor';
import { canvasToFlow, describeSwitch, flowToCanvas } from './mode-switch';
import { newFlowBlock, type FlowBlockType, type FlowContent } from '@amroksaleh/ui/documents/flow';
import { flowPaletteItems, paletteItemsForFlow, paletteItemsFromMenus } from './editor-palette';

/** Zoom bounds + step for the View menu / toolbar zoom controls. */
const ZOOM_MIN = 0.25;
const ZOOM_MAX = 3;
const ZOOM_STEP = 0.25;

/** Immutably replace the elements of one page within a template. */
function withPageElements(
  tpl: DocTemplate,
  idx: number,
  fn: (els: DocElement[]) => DocElement[]
): DocTemplate {
  return { ...tpl, pages: tpl.pages.map((p, i) => (i === idx ? { ...p, elements: fn(p.elements) } : p)) };
}

export function DocumentDesignerScreen({ adapter, onNotify, onClose }: DocumentDesignerScreenProps) {
  const addToast = onNotify ?? noopNotify;
  const t = useTranslation('documents');
  const modLabel = useModLabel();
  const [template, setTemplate] = useState<DocTemplate>(() => blankTemplate());
  const [currentPage, setCurrentPage] = useState(0);
  const [selectedIds, setSelectedIds] = useState<string[]>([]);
  const [preview, setPreview] = useState(false);
  const [zoom, setZoom] = useState(1);
  const [snap, setSnap] = useState(true);
  const [showGrid, setShowGrid] = useState(false);
  const [showRulers, setShowRulers] = useState(false);
  const [saved, setSaved] = useState<SavedTemplate[]>([]);
  const [currentId, setCurrentId] = useState<string | null>(null);

  /**
   * Who the NEXT create files this template for. Only consulted while unsaved.
   *
   * `personal` matches the server's default for a missing scope, so the control
   * starts by describing what already happens rather than changing it.
   */
  const [pendingScope, setPendingScope] = useState<BlockScope>('personal');

  /**
   * The current document's visibility.
   *
   * DERIVED for a saved template, deliberately: `saved` is re-fetched after
   * every save and delete, so this is the server's answer, not a guess kept in
   * step by hand. Six separate places call `setCurrentId`; a second useState
   * beside it would need all six to remember, and the one that forgot would
   * leave the badge quietly naming the wrong audience — which is the exact
   * failure this whole change exists to fix.
   */
  const templateScope: BlockScope =
    currentId === null
      ? pendingScope
      : ((saved.find((s) => s.id === currentId)?.scope as BlockScope | undefined) ?? 'personal');
  /**
   * Bumped every time the editor swaps to a different document (New, Open
   * saved, Import). In-flight async work started against the previous document
   * compares this to decide whether its result still applies.
   */
  const docEpoch = useRef(0);
  const fileRef = useRef<HTMLInputElement>(null);
  // Which Inspector tab is showing. Owned here (not by the Inspector) so the
  // menu bar can open a specific one — Page setup…, Placeholders…, Batch….
  // The single side rail: which tab it shows, and whether it's showing at all.
  // Collapsing it gives the page the full window — the reason there is only one
  // rail in the first place (see `side-rail.tsx`).
  const [railTab, setRailTab] = useState<RailTab>('layers');
  const [railOpen, setRailOpen] = useState(true);
  const [shortcutsOpen, setShortcutsOpen] = useState(false);
  const [paletteOpen, setPaletteOpen] = useState(false);
  // #1186: which flow block is being edited. Separate from `selectedIds`
  // because a flow block is addressed by INDEX and a canvas element by id —
  // one selection model covering both would have to mean different things
  // depending on the mode, which is how a selection ends up pointing at the
  // wrong thing after a switch.
  const [flowSelected, setFlowSelected] = useState<number | null>(null);
  const mode = template.mode ?? 'canvas';
  // The canvas scroll viewport, measured by View ▸ Fit page in window.
  const viewportRef = useRef<HTMLElement>(null);

  // Undo/redo: full-template snapshots. Consecutive same-kind edits (a drag, a
  // burst of typing) coalesce into one step via `commit` (see below).
  const [past, setPast] = useState<DocTemplate[]>([]);
  const [future, setFuture] = useState<DocTemplate[]>([]);
  const historyRef = useRef({ lastLabel: '', lastTime: 0 });

  // In-app clipboard: a single copied/cut element (deep-cloned on paste). We use
  // our own clipboard rather than the async system Clipboard API to avoid
  // permission prompts and keep paste deterministic/offline. `pasteSeq` keeps
  // pasted ids unique even within the same millisecond.
  const clipboardRef = useRef<DocElement[] | null>(null);
  const pasteSeq = useRef(0);
  const [hasClipboard, setHasClipboard] = useState(false);

  // Variable-data batch: when set, Preview and Print iterate these data rows
  // (e.g. a run of serial numbers) instead of the single sample row. Batch data
  // is runtime-only — it is NOT part of the template and NOT on the undo stack.
  const [batchRows, setBatchRows] = useState<Record<string, string>[] | null>(null);
  const [batchIndex, setBatchIndex] = useState(0);

  // N-up label-sheet layout + serial-sequence settings. Unlike the generated
  // batch rows (runtime-only), these are saved with the template so a label
  // template is reusable — reopen it and just change the serial range.
  const [sheet, setSheet] = useState<SheetSpec>(DEFAULT_SHEET);
  const [sequence, setSequence] = useState<SequenceConfig>(DEFAULT_SEQUENCE);

  // Reusable blocks (tenant-scoped, backend-persisted — WC-521). Documents
  // reference a block by id via a `blockInstance` element; the block store
  // holds the shared definition, so editing a block updates every instance.
  const [blocks, setBlocks] = useState<DocBlock[]>([]);
  // Effective library = the caller's visible blocks (server RBAC-filtered) +
  // any built-in starter blocks not already covered — so the Blocks panel is
  // never empty even for a tenant that predates per-tenant starter seeding.
  //
  // MATCHED BY `starterKey`, WITH NAME AS THE FALLBACK.
  //
  // Not by id: a seeded starter's id is a real backend id, not the client
  // constant's symbolic one, so an id match would show both.
  //
  // Not by name alone, which is what this did: a display name is the one thing
  // about a seeded block a tenant is invited to change. Rename "Company header"
  // to "Acme header" and the match failed, so the built-in starter came back
  // and sat in the palette beside the real block — two entries, same block,
  // one of them a phantom that exists in nobody's library. Since starters are
  // persisted on insert, inserting the phantom then made a third.
  //
  // `starter_key` is the identity the server assigns and never accepts from a
  // client (migration 075), and it has been on every block row since #1013 —
  // the client was simply dropping it. `DocumentDemoSeeder` records making and
  // then abandoning this exact name-matching trade on the server side.
  //
  // The name fallback stays for rows seeded before 075, which have no key.
  const refreshBlocks = useCallback(async () => {
    try {
      const saved = await adapter.listBlocks();
      const extras = STARTER_BLOCKS.filter(
        (b) => !saved.some((s) => (s.starterKey ? s.starterKey === b.id : s.name === b.name))
      );
      setBlocks([...saved, ...extras]);
    } catch (error) {
      addToast(
        error instanceof Error ? error.message : t('designer.blocks.loadFailed', 'Failed to load blocks.'),
        'error'
      );
    }
  }, [addToast, t]);
  useEffect(() => {
    void (async () => {
      await refreshBlocks();
    })();
  }, [refreshBlocks]);
  const blocksMap = useMemo(() => blocksById(blocks), [blocks]);

  // Block edit mode: the whole designer is temporarily repurposed to edit ONE
  // block's elements (as a single-page doc). On Done we write the elements back
  // to the block store — every instance re-resolves, so the edit propagates.
  // The pre-edit editor state is stashed here and restored on exit.
  const [blockEdit, setBlockEdit] = useState<{ id: string; name: string } | null>(null);
  const blockStashRef = useRef<{
    template: DocTemplate;
    currentPage: number;
    selectedIds: string[];
    past: DocTemplate[];
    future: DocTemplate[];
    currentId: string | null;
  } | null>(null);

  // Current page + its elements. `currentPage` may briefly exceed the page count
  // after an undo/delete, so read through a clamped `pageIndex`. ALL element
  // operations target this page.
  const pageIndex = Math.min(currentPage, template.pages.length - 1);
  const elements = template.pages[pageIndex]?.elements ?? [];
  // Single-element affordances (inspector, resize, size readout) apply only when
  // exactly one element is selected; group affordances read the full set.
  const selectedId = selectedIds.length === 1 ? selectedIds[0] : null;
  const selected = selectedId ? elements.find((e) => e.id === selectedId) ?? null : null;

  // Selection helpers. Plain select replaces the set; additive (shift/⌘-click)
  // toggles one element in/out for multi-select.
  const selectOne = (id: string | null, additive = false) => {
    if (id === null) {
      setSelectedIds([]);
      return;
    }
    setSelectedIds((prev) => (additive ? (prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id]) : [id]));
  };

  // Load the saved-template list from the API after mount.
  useEffect(() => {
    void (async () => {
      try {
        setSaved(await adapter.listTemplates());
      } catch (error) {
        addToast(
          error instanceof Error
            ? error.message
            : t('designer.templates.loadFailed', 'Failed to load saved templates.'),
          'error'
        );
      }
    })();
  }, [addToast, t]);

  // Live state for the once-attached keyboard listener + history snapshots,
  // kept fresh by a per-render effect (so the stable listener/callbacks read
  // current values without re-subscribing — lint-safe).
  const kbRef = useRef({ selectedIds, preview, template, past, future, pageIndex });
  useEffect(() => {
    kbRef.current = { selectedIds, preview, template, past, future, pageIndex };
  });

  // Snapshot the pre-mutation template onto the undo stack. Call BEFORE applying
  // a mutation. Consecutive calls with the same label within 600ms coalesce, so
  // one drag / typing burst becomes a single undo step.
  const commit = useCallback((label: string) => {
    const now = Date.now();
    const h = historyRef.current;
    const coalesce = label === h.lastLabel && now - h.lastTime < 600;
    h.lastLabel = label;
    h.lastTime = now;
    if (!coalesce) {
      setPast((p) => [...p.slice(-49), kbRef.current.template]);
      setFuture([]);
    }
  }, []);

  const resetHistory = useCallback(() => {
    setPast([]);
    setFuture([]);
    historyRef.current = { lastLabel: '', lastTime: 0 };
  }, []);

  const undo = useCallback(() => {
    const { past: p, future: f, template: cur } = kbRef.current;
    if (p.length === 0) return;
    setPast(p.slice(0, -1));
    setFuture([cur, ...f]);
    setTemplate(p[p.length - 1]);
    setSelectedIds([]);
    historyRef.current.lastLabel = '';
  }, []);

  const redo = useCallback(() => {
    const { past: p, future: f, template: cur } = kbRef.current;
    if (f.length === 0) return;
    setFuture(f.slice(1));
    setPast([...p, cur]);
    setTemplate(f[0]);
    setSelectedIds([]);
    historyRef.current.lastLabel = '';
  }, []);

  // Append clones of `srcs` to the current page with fresh ids, nudged +3mm and
  // stacked on top; selects the clones. Shared by duplicate and paste.
  const appendClones = useCallback((srcs: DocElement[]) => {
    if (srcs.length === 0) return;
    const idx = kbRef.current.pageIndex;
    setTemplate((tpl) => {
      const els = tpl.pages[idx]?.elements ?? [];
      let maxZ = els.reduce((m, e) => Math.max(m, e.z), 0);
      const clones = srcs.map((src) => {
        maxZ += 1;
        return {
          ...src,
          id: `${src.type}-${Date.now()}-${(pasteSeq.current += 1)}`,
          x: src.x + 3,
          y: src.y + 3,
          z: maxZ,
          locked: false,
          hidden: false,
        } as DocElement;
      });
      setSelectedIds(clones.map((c) => c.id));
      return withPageElements(tpl, idx, (e) => [...e, ...clones]);
    });
  }, []);

  /** The currently-selected elements on the current page, in document order. */
  const currentSelection = useCallback((): DocElement[] => {
    const { selectedIds: ids, template: tpl, pageIndex: idx } = kbRef.current;
    const els = tpl.pages[idx]?.elements ?? [];
    return els.filter((e) => ids.includes(e.id));
  }, []);

  const copySelected = useCallback(() => {
    const sel = currentSelection();
    if (sel.length === 0) return;
    clipboardRef.current = sel;
    setHasClipboard(true);
  }, [currentSelection]);

  const cutSelected = useCallback(() => {
    const sel = currentSelection();
    if (sel.length === 0) return;
    clipboardRef.current = sel;
    setHasClipboard(true);
    const ids = new Set(sel.map((e) => e.id));
    commit('cut');
    setTemplate((tpl) => withPageElements(tpl, kbRef.current.pageIndex, (e) => e.filter((x) => !ids.has(x.id))));
    setSelectedIds([]);
  }, [commit, currentSelection]);

  const pasteClipboard = useCallback(() => {
    const src = clipboardRef.current;
    if (!src || src.length === 0) return;
    commit('paste');
    appendClones(src);
  }, [commit, appendClones]);

  const duplicateSelected = useCallback(() => {
    const sel = currentSelection();
    if (sel.length === 0) return;
    commit('duplicate');
    appendClones(sel);
  }, [commit, appendClones, currentSelection]);

  /**
   * Delete the selection. Locked elements are skipped (unlock them from the
   * layers panel or Format ▸ Unlock first) — the ONE implementation shared by
   * the Delete key and Edit ▸ Delete, so the two can never diverge on that rule.
   */
  const deleteSelection = useCallback(() => {
    const idx = kbRef.current.pageIndex;
    const movable = currentSelection().filter((e) => !e.locked);
    if (movable.length === 0) return;
    const ids = new Set(movable.map((e) => e.id));
    commit('delete');
    setTemplate((tpl) => withPageElements(tpl, idx, (els) => els.filter((e) => !ids.has(e.id))));
    setSelectedIds((prev) => prev.filter((id) => !ids.has(id)));
  }, [commit, currentSelection]);

  const selectAllOnPage = useCallback(() => {
    const { template: tpl, pageIndex: idx } = kbRef.current;
    setSelectedIds((tpl.pages[idx]?.elements ?? []).map((e) => e.id));
  }, []);

  // Keyboard: Ctrl/Cmd+Z undo, Ctrl/Cmd+Shift+Z or Ctrl+Y redo (work without a
  // selection); Delete removes, arrows nudge (Shift = 5mm), Escape deselects.
  // Ignored while typing in a form field (native undo there), and while an open
  // menu or dialog owns the keyboard — otherwise Escape-to-close-the-menu would
  // also silently clear the canvas selection the menu was about to act on.
  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      const target = e.target as HTMLElement | null;
      if (
        target &&
        (target.tagName === 'INPUT' || target.tagName === 'TEXTAREA' || target.tagName === 'SELECT' || target.isContentEditable)
      ) {
        return;
      }
      if (target?.closest('[role="menu"],[role="dialog"]')) {
        return;
      }
      const mod = e.ctrlKey || e.metaKey;

      // The command palette. `/` alone, and Ctrl/Cmd-K as the conventional
      // alternative for anyone who expects it.
      //
      // Both sit BELOW the guard above, which already returns early for an
      // INPUT/TEXTAREA/contentEditable target — so a slash typed into a text
      // element, a placeholder name or the batch editor stays a slash. That
      // guard is the whole reason `/` is safe to bind bare here; without it
      // this would be a keystroke that silently eats a character.
      if (!mod && e.key === '/') {
        e.preventDefault();
        setPaletteOpen(true);
        return;
      }
      if (mod && (e.key === 'k' || e.key === 'K')) {
        e.preventDefault();
        setPaletteOpen(true);
        return;
      }

      if (mod && !e.shiftKey && (e.key === 'z' || e.key === 'Z')) {
        e.preventDefault();
        undo();
        return;
      }
      if (mod && ((e.shiftKey && (e.key === 'z' || e.key === 'Z')) || e.key === 'y' || e.key === 'Y')) {
        e.preventDefault();
        redo();
        return;
      }
      // Clipboard shortcuts. Paste works with no selection; copy/cut/duplicate
      // are no-ops without one. Skipped in preview (view-only).
      if (mod && !kbRef.current.preview) {
        if (e.key === 'c' || e.key === 'C') {
          e.preventDefault();
          copySelected();
          return;
        }
        if (e.key === 'x' || e.key === 'X') {
          e.preventDefault();
          cutSelected();
          return;
        }
        if (e.key === 'v' || e.key === 'V') {
          e.preventDefault();
          pasteClipboard();
          return;
        }
        if (e.key === 'd' || e.key === 'D') {
          e.preventDefault();
          duplicateSelected();
          return;
        }
        if (e.key === 'a' || e.key === 'A') {
          e.preventDefault();
          selectAllOnPage();
          return;
        }
      }
      const { selectedIds: ids, preview: pv, template: tpl, pageIndex: idx } = kbRef.current;
      const els = tpl.pages[idx]?.elements ?? [];
      const sel = els.filter((x) => ids.includes(x.id));
      if (pv || sel.length === 0) return;
      if (e.key === 'Escape') {
        setSelectedIds([]);
        return;
      }
      // Locked elements are skipped by delete / nudge (unlock via the layers panel).
      const movable = sel.filter((x) => !x.locked);
      if (movable.length === 0) return;
      const movableIds = new Set(movable.map((x) => x.id));
      if (e.key === 'Delete' || e.key === 'Backspace') {
        e.preventDefault();
        deleteSelection();
        return;
      }
      const step = e.shiftKey ? 5 : 1;
      const delta: Record<string, [number, number]> = {
        ArrowLeft: [-step, 0],
        ArrowRight: [step, 0],
        ArrowUp: [0, -step],
        ArrowDown: [0, step],
      };
      const d = delta[e.key];
      if (d) {
        e.preventDefault();
        commit('nudge');
        setTemplate((tpl) =>
          withPageElements(tpl, idx, (e2) =>
            e2.map((x) => (movableIds.has(x.id) ? { ...x, x: Math.max(0, x.x + d[0]), y: Math.max(0, x.y + d[1]) } : x))
          )
        );
      }
    };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [
    undo,
    redo,
    commit,
    copySelected,
    cutSelected,
    pasteClipboard,
    duplicateSelected,
    deleteSelection,
    selectAllOnPage,
  ]);

  const data = useMemo(() => sampleDataOf(template), [template]);

  // Effective data for Preview/Print: the current batch row when batching, else
  // the single sample row. Print emits one physical run per dataset entry.
  const rows = batchRows ?? [];
  const batchActive = rows.length > 0;
  const batchClampIndex = batchActive ? Math.min(batchIndex, rows.length - 1) : 0;
  const activeData = batchActive ? rows[batchClampIndex] : data;
  const printDatasets = batchActive ? rows : [data];

  const generateBatch = (cfg: SequenceConfig) => {
    const serials = generateSequence(cfg);
    const built = rowsFromValues(serials, cfg.key, data);
    setBatchRows(built.length ? built : null);
    setBatchIndex(0);
    addToast(
      built.length
        ? t('designer.batch.generated', 'Generated {count} rows.', { count: built.length })
        : t('designer.batch.generatedNone', 'No rows generated.'),
      built.length ? 'success' : 'info'
    );
  };

  const loadBatchRecords = (records: Record<string, string>[]) => {
    const built = rowsFromRecords(records, data);
    setBatchRows(built.length ? built : null);
    setBatchIndex(0);
    addToast(
      built.length
        ? t('designer.batch.loaded', 'Loaded {count} rows.', { count: built.length })
        : t('designer.batch.loadedNone', 'No rows found.'),
      built.length ? 'success' : 'info'
    );
  };

  const clearBatch = () => {
    setBatchRows(null);
    setBatchIndex(0);
  };

  const patchElement = (id: string, patch: Partial<DocElement>) => {
    // Label by field set so a continuous drag / same-field typing coalesces.
    commit(`patch:${Object.keys(patch).sort().join(',')}`);
    setTemplate((tpl) =>
      withPageElements(tpl, pageIndex, (els) =>
        els.map((e) => (e.id === id ? ({ ...e, ...patch } as DocElement) : e))
      )
    );
  };

  const addElement = (type: ElementType) => {
    commit('add');
    setTemplate((tpl) => {
      const el = newElement(type, tpl.pages[pageIndex]?.elements ?? []);
      setSelectedIds([el.id]);
      return withPageElements(tpl, pageIndex, (els) => [...els, el]);
    });
    // Inserting is "I made this, now let me configure it": show the new
    // element's properties instead of leaving the rail on Layers, where a fresh
    // text box can't even be typed into. Only on INSERT — a plain selection
    // change must not yank the tab away from someone reordering layers.
    setRailTab('element');
    setRailOpen(true);
  };

  const deleteElement = (id: string) => {
    commit('delete');
    setTemplate((tpl) => withPageElements(tpl, pageIndex, (els) => els.filter((e) => e.id !== id)));
    setSelectedIds((prev) => prev.filter((x) => x !== id));
  };

  // Batch position/size update for a group drag — one coalesced history step.
  const changeMany = (updates: Array<{ id: string; patch: Partial<DocElement> }>) => {
    commit('drag-group');
    const map = new Map(updates.map((u) => [u.id, u.patch]));
    setTemplate((tpl) =>
      withPageElements(tpl, pageIndex, (els) =>
        els.map((e) => (map.has(e.id) ? ({ ...e, ...map.get(e.id) } as DocElement) : e))
      )
    );
  };

  // Align selected elements (skipping locked) to an edge/centre of a reference
  // frame: the page for a single element, else the selection's bounding box —
  // the standard design-tool behaviour.
  const alignSelected = (kind: 'left' | 'hcenter' | 'right' | 'top' | 'vmiddle' | 'bottom') => {
    const ids = new Set(selectedIds);
    const sel = elements.filter((e) => ids.has(e.id) && !e.locked);
    if (sel.length === 0) return;
    const { widthMm: W, heightMm: H } = template.page;
    const frame =
      sel.length <= 1
        ? { left: 0, top: 0, right: W, bottom: H }
        : {
            left: Math.min(...sel.map((e) => e.x)),
            top: Math.min(...sel.map((e) => e.y)),
            right: Math.max(...sel.map((e) => e.x + e.w)),
            bottom: Math.max(...sel.map((e) => e.y + e.h)),
          };
    commit('align');
    setTemplate((tpl) =>
      withPageElements(tpl, pageIndex, (els) =>
        els.map((e) => {
          if (!ids.has(e.id) || e.locked) return e;
          const patch: Partial<DocElement> =
            kind === 'left'
              ? { x: Math.max(0, frame.left) }
              : kind === 'hcenter'
                ? { x: Math.max(0, frame.left + (frame.right - frame.left - e.w) / 2) }
                : kind === 'right'
                  ? { x: Math.max(0, frame.right - e.w) }
                  : kind === 'top'
                    ? { y: Math.max(0, frame.top) }
                    : kind === 'vmiddle'
                      ? { y: Math.max(0, frame.top + (frame.bottom - frame.top - e.h) / 2) }
                      : { y: Math.max(0, frame.bottom - e.h) };
          return { ...e, ...patch } as DocElement;
        })
      )
    );
  };

  // Evenly space 3+ selected elements along an axis: keep the outermost two
  // fixed and distribute the rest so their centres are equally spaced.
  const distributeSelected = (axis: 'h' | 'v') => {
    const sel = elements.filter((e) => selectedIds.includes(e.id) && !e.locked);
    if (sel.length < 3) return;
    const centre = (e: DocElement) => (axis === 'h' ? e.x + e.w / 2 : e.y + e.h / 2);
    const sorted = [...sel].sort((a, b) => centre(a) - centre(b));
    const first = centre(sorted[0]);
    const last = centre(sorted[sorted.length - 1]);
    const n = sorted.length;
    const targets = new Map<string, number>();
    sorted.forEach((e, i) => {
      const c = first + ((last - first) * i) / (n - 1);
      targets.set(e.id, axis === 'h' ? c - e.w / 2 : c - e.h / 2);
    });
    commit('distribute');
    setTemplate((tpl) =>
      withPageElements(tpl, pageIndex, (els) =>
        els.map((e) =>
          targets.has(e.id)
            ? ({ ...e, ...(axis === 'h' ? { x: Math.max(0, targets.get(e.id)!) } : { y: Math.max(0, targets.get(e.id)!) }) } as DocElement)
            : e
        )
      )
    );
  };

  const toggleLock = (id: string) => {
    commit('lock');
    setTemplate((tpl) =>
      withPageElements(tpl, pageIndex, (els) =>
        els.map((e) => (e.id === id ? ({ ...e, locked: !e.locked } as DocElement) : e))
      )
    );
  };

  const toggleHidden = (id: string) => {
    commit('hide');
    setTemplate((tpl) =>
      withPageElements(tpl, pageIndex, (els) =>
        els.map((e) => (e.id === id ? ({ ...e, hidden: !e.hidden } as DocElement) : e))
      )
    );
  };

  const reorder = (id: string, dir: 'up' | 'down') => {
    commit('reorder');
    setTemplate((tpl) =>
      withPageElements(tpl, pageIndex, (els) => {
        const zs = els.map((e) => e.z);
        const target = dir === 'up' ? Math.max(...zs) + 1 : Math.min(...zs) - 1;
        return els.map((e) => (e.id === id ? { ...e, z: target } : e));
      })
    );
  };

  // ── selection-wide variants of the per-element layer actions ──────────────
  // The layers panel toggles ONE element; Format ▸ Lock / Hide act on the whole
  // selection. Both flip to the same target value (derived below) so a mixed
  // selection converges instead of each element independently inverting.
  const selectionElements = elements.filter((e) => selectedIds.includes(e.id));
  const selectionLocked = selectionElements.length > 0 && selectionElements.every((e) => e.locked);
  const selectionHidden = selectionElements.length > 0 && selectionElements.every((e) => e.hidden);

  const setSelectionFlag = (key: 'locked' | 'hidden', value: boolean) => {
    const ids = new Set(selectedIds);
    if (ids.size === 0) return;
    commit(key);
    setTemplate((tpl) =>
      withPageElements(tpl, pageIndex, (els) =>
        els.map((e) => (ids.has(e.id) ? ({ ...e, [key]: value } as DocElement) : e))
      )
    );
  };

  /** Move the whole selection to the front or back, preserving its internal
   *  stacking order (so a group keeps looking the same after arranging). */
  const arrangeSelection = (dir: 'up' | 'down') => {
    const ids = new Set(selectedIds);
    if (ids.size === 0) return;
    commit('reorder');
    setTemplate((tpl) =>
      withPageElements(tpl, pageIndex, (els) => {
        const moving = els.filter((e) => ids.has(e.id)).sort((a, b) => a.z - b.z);
        if (moving.length === 0) return els;
        const zs = els.map((e) => e.z);
        const base = dir === 'up' ? Math.max(...zs) + 1 : Math.min(...zs) - moving.length;
        const rank = new Map(moving.map((e, i) => [e.id, base + i]));
        return els.map((e) => (rank.has(e.id) ? { ...e, z: rank.get(e.id)! } : e));
      })
    );
  };

  const clampZoom = (z: number) => Math.max(ZOOM_MIN, Math.min(ZOOM_MAX, Number(z.toFixed(2))));

  const doZoom = (action: ZoomAction) => {
    if (action === 'reset') {
      setZoom(1);
      return;
    }
    if (action === 'fit') {
      // Measure the real scroll viewport rather than the window: the rails and
      // chrome take a variable slice of it, so only its own box knows what will
      // actually fit. Subtract its padding (p-6 → 24px each side).
      const vp = viewportRef.current;
      if (!vp) return;
      const availW = vp.clientWidth - 48;
      const availH = vp.clientHeight - 48;
      const pageW = template.page.widthMm * PX_PER_MM;
      const pageH = template.page.heightMm * PX_PER_MM;
      if (pageW <= 0 || pageH <= 0 || availW <= 0 || availH <= 0) return;
      setZoom(clampZoom(Math.min(availW / pageW, availH / pageH)));
      return;
    }
    setZoom((z) => clampZoom(action === 'in' ? z + ZOOM_STEP : z - ZOOM_STEP));
  };

  // ── reusable blocks ───────────────────────────────────────────────────────

  /** Whether an id came from the backend. Everything else — a starter's
   *  `sys-header`, a locally-minted uuid — is a block that does not exist
   *  server-side yet, which is the distinction the whole section below turns
   *  on. Same test `saveBlock` uses to pick CREATE over UPDATE. */
  const isBackendId = (id: string) => /^\d+$/.test(id);

  /**
   * Follow a block that has just been given a new id — in the live document AND
   * IN THE UNDO STACKS.
   *
   * Saving a block whose id was not a backend id creates it, and the backend
   * mints a fresh numeric one. `refreshBlocks()` then drops the old entry from
   * the library (it matches by name), so every instance still pointing at the
   * old id resolves to nothing.
   *
   * The history stacks matter as much as the current template, and used to be
   * left behind: the old id resolves to nothing ANYWHERE now, so a snapshot
   * holding it is a snapshot that renders a hole. Repointing only the live
   * document leaves undo as a way to travel back to the broken state — which is
   * worse than not fixing it, because it looks fixed until somebody presses
   * ctrl-Z.
   *
   * Deliberately NOT a `commit()`: this is not an edit the author made, it is
   * the same document described correctly. Pushing it as an undo step would
   * offer "undo" as a way to re-break the pointers.
   */
  const followBlockId = useCallback((fromId: string, toId: string) => {
    if (fromId === toId) return;
    setTemplate((tpl) => repointBlockInstances(tpl, fromId, toId));
    setPast((p) => p.map((tpl) => repointBlockInstances(tpl, fromId, toId)));
    setFuture((f) => f.map((tpl) => repointBlockInstances(tpl, fromId, toId)));
  }, []);

  const saveSelectionAsBlock = async () => {
    const sel = elements.filter((e) => selectedIds.includes(e.id));
    const block = makeBlockFromElements(`Block ${blocks.length + 1}`, sel);
    if (!block) {
      addToast(t('designer.block.selectFirst', 'Select one or more elements to save as a block.'), 'info');
      return;
    }
    try {
      await adapter.saveBlock(block);
      await refreshBlocks();
      addToast(t('designer.block.saved', 'Saved block “{name}”.', { name: block.name }), 'success');
    } catch (error) {
      addToast(
        error instanceof Error ? error.message : t('designer.block.saveFailed', 'Failed to save block.'),
        'error'
      );
    }
  };

  /**
   * Place a block on the page — PERSISTING IT FIRST if it is one of the
   * client-side starters.
   *
   * THE BUG THIS FIXES IS INVISIBLE UNTIL THE PDF ARRIVES. A starter
   * (`sys-header`) exists only in this bundle: `refreshBlocks` merges
   * STARTER_BLOCKS into the library so the panel is never empty for a tenant
   * that predates per-tenant seeding. Inserting one used to write a
   * `blockInstance` pointing at `sys-header` straight into the template, and
   * the canvas renders it perfectly, because the canvas resolves against that
   * same in-memory library.
   *
   * The SERVER does not have that library. `DocumentRenderer::resolveBlocks()`
   * skips any id that is not all digits and the harness renders a missing block
   * as empty — so the document the customer receives has a blank space where
   * its header was, with nothing anywhere reporting a problem. The designer,
   * the preview, and the print view all agree it is fine.
   *
   * So a starter becomes real before anything points at it. Saved as
   * `personal`, not with its own `system` scope: creating a system/tenant block
   * is a publish action, which most authors may not perform, and it would fail
   * exactly the people this fallback exists for. Personal is enough — the
   * renderer resolves blocks by id within the tenant, not by who is reading, so
   * the PDF is correct for every recipient.
   */
  const insertBlock = async (blockId: string) => {
    const b = blocksMap[blockId];
    if (!b) return;

    // THE ONLY PLACE A CYCLE CAN BE CREATED (#1186 slice 3). Blocks may hold
    // blocks, so inserting one INTO a block being edited can close a loop —
    // directly (a block into itself) or through a chain nobody can see on
    // screen, which is the case actually built by accident: A already holds B,
    // and someone drops A into B months later.
    //
    // Refused here rather than survived at render time. `flattenBlock` cuts a
    // cycle and reports it, so the document still prints — but the honest
    // moment to say no is when somebody builds one, not when part of a
    // document quietly stops appearing.
    if (blockEdit && wouldCycle(blocksMap, blockEdit.id, blockId)) {
      addToast(
        t(
          'designer.block.wouldCycle',
          'That block already contains this one, so adding it here would make a loop.'
        ),
        'error'
      );
      return;
    }

    let id = blockId;
    if (!isBackendId(blockId)) {
      try {
        id = await adapter.saveBlock({ ...b, scope: 'personal' });
        await refreshBlocks();
      } catch (error) {
        // Refuse to place it rather than placing a pointer that renders as a
        // hole. A visible failure now beats a silent one at print time.
        addToast(
          error instanceof Error ? error.message : t('designer.block.saveFailed', 'Failed to save block.'),
          'error'
        );
        return;
      }
    }

    commit('insert-block');
    setTemplate((tpl) => {
      const els = tpl.pages[pageIndex]?.elements ?? [];
      const inst = {
        id: `blockInstance-${Date.now()}-${(pasteSeq.current += 1)}`,
        type: 'blockInstance' as const,
        blockId: id,
        x: 8,
        y: 8,
        w: b.w,
        h: b.h,
        rotation: 0,
        z: els.reduce((m, e) => Math.max(m, e.z), 0) + 1,
      };
      setSelectedIds([inst.id]);
      return withPageElements(tpl, pageIndex, (e) => [...e, inst]);
    });
  };

  /**
   * Actually delete a block. Reached only through the confirmation below.
   *
   * The toast no longer says "from your library". A tenant or global block is
   * not in your library, it is in everybody's, and telling the person who just
   * removed one that they tidied their own shelf is the most misleading moment
   * in the whole flow. It now names the same audience the dialog warned about,
   * so the confirmation and the result agree.
   */
  const deleteBlockDef = async (b: DocBlock) => {
    try {
      await adapter.deleteBlock(b.id);
      await refreshBlocks();
      addToast(blockDeletedMessage(t, b.scope, b.name), 'info');
    } catch (error) {
      // A 409 here is the server's reference-integrity guard ("still referenced
      // by N templates") — the most useful thing it can say, so it is relayed
      // verbatim rather than flattened into a generic failure.
      addToast(
        error instanceof Error ? error.message : t('designer.block.deleteFailed', 'Failed to delete block.'),
        'error'
      );
    }
  };

  /**
   * What the confirmation is currently asking about, or null when it is closed.
   *
   * One slot for both deletes rather than a pair of booleans: two of these can
   * never be open at once, and a single nullable makes that a fact of the type
   * instead of an invariant somebody has to maintain.
   */
  const [pendingDelete, setPendingDelete] = useState<{
    title: string;
    body: string;
    consequence: string | null;
    confirmLabel: string;
    run: () => void;
  } | null>(null);

  const askDeleteBlock = (id: string) => {
    const b = blocksMap[id];
    if (!b) return;
    setPendingDelete({
      title: t('designer.block.confirmDeleteTitle', 'Delete “{name}”?', { name: b.name }),
      body: t(
        'designer.block.confirmDeleteBody',
        'The block is removed for good. Documents that already use it keep working only if nothing points at it — a block still in use cannot be deleted.'
      ),
      consequence: blockDeleteConsequence(t, b),
      confirmLabel: t('designer.block.confirmDeleteAction', 'Delete block'),
      run: () => void deleteBlockDef(b),
    });
  };

  // Change a block's visibility tier — a real, server-enforced publish action
  // (WC-521): promoting to tenant/global requires documents:publish, which the
  // caller may not hold (surfaced here as an error toast rather than a silent
  // no-op).
  const setBlockScope = async (id: string, scope: BlockScope) => {
    const b = blocksMap[id];
    if (!b) return;
    try {
      const savedId = await adapter.saveBlock({ ...b, scope });
      await refreshBlocks();
      // Publishing a STARTER creates it, so the backend hands back a different
      // id — the same fork `exitBlockEdit` has always handled and this one did
      // not. Without it, changing a starter's visibility silently stranded
      // every instance of it already on the page: the starter leaves the
      // library (refreshBlocks matches by name), the instances keep pointing at
      // `sys-header`, and they render as "missing block". A visibility change
      // is the last action anyone would expect to damage the document.
      //
      // `insertBlock` now persists a starter before placing it, so newly placed
      // instances already hold a backend id. This still matters for every
      // document saved before that.
      followBlockId(id, savedId);
    } catch (error) {
      addToast(
        error instanceof Error
          ? error.message
          : t('designer.block.scopeFailed', 'Failed to change the block’s visibility.'),
        'error'
      );
    }
  };

  // Enter block edit mode: stash the current editor state and load the block's
  // elements into a synthetic single-page document sized to the block.
  const enterBlockEdit = (blockId: string) => {
    const b = blocksMap[blockId];
    if (!b || blockEdit) return;
    blockStashRef.current = {
      template,
      currentPage,
      selectedIds,
      past: kbRef.current.past,
      future: kbRef.current.future,
      currentId,
    };
    const editTemplate: DocTemplate = {
      version: 2,
      name: b.name,
      page: { widthMm: Math.max(10, b.w), heightMm: Math.max(10, b.h), marginMm: 0, background: '#ffffff' },
      placeholders: template.placeholders,
      pages: [{ id: newPageId(), elements: b.elements }],
    };
    setBlockEdit({ id: b.id, name: b.name });
    setTemplate(editTemplate);
    setCurrentPage(0);
    setSelectedIds([]);
    setBatchRows(null);
    setBatchIndex(0);
    resetHistory();
  };

  // Leave block edit mode. `save` writes the edited elements back to the block
  // (keeping its id, so all instances update); either way the pre-edit document
  // is restored.
  const exitBlockEdit = async (save: boolean) => {
    const stash = blockStashRef.current;
    const editing = blockEdit;
    if (!stash || !editing) return;
    // The pre-edit document to put back, and the history it came with. All
    // three are rewritten below when saving minted a new block id, so the
    // restored page — and every state undo can travel back to — follows the
    // block rather than dangling at its old id.
    let restored = stash.template;
    let restoredPast = stash.past;
    let restoredFuture = stash.future;
    if (save) {
      const els = template.pages[0]?.elements ?? [];
      const rebuilt = makeBlockFromElements(editing.name, els);
      if (rebuilt) {
        // makeBlockFromElements always builds a fresh 'personal'-scope block
        // (it has no idea this is an in-place edit of an existing one) — keep
        // the block's CURRENT scope so an in-place content edit can never
        // silently demote a published tenant/global/system block back to
        // personal (which would drop it out of every other user's library).
        const currentScope = blocksMap[editing.id]?.scope ?? rebuilt.scope;
        try {
          const savedId = await adapter.saveBlock({ ...rebuilt, id: editing.id, scope: currentScope });
          await refreshBlocks();
          // A block whose id was not a backend id — a starter (`sys-header`),
          // or one authored locally and never persisted — is CREATED rather
          // than updated, so the backend hands back a different id. Every
          // instance on the page still points at the old one, and
          // `refreshBlocks` has just dropped the starter from the library
          // (same name as the block now saved), so leaving them alone renders
          // them all as "missing block". Follow the id instead — in the undo
          // stacks too, since the old id now resolves to nothing anywhere and a
          // snapshot still holding it is a snapshot that renders a hole.
          if (savedId !== editing.id) {
            const follow = (tpl: DocTemplate) => repointBlockInstances(tpl, editing.id, savedId);
            restored = follow(restored);
            restoredPast = restoredPast.map(follow);
            restoredFuture = restoredFuture.map(follow);
          }
          addToast(t('designer.block.updated', 'Block “{name}” updated.', { name: editing.name }), 'success');
        } catch (error) {
          addToast(
            error instanceof Error ? error.message : t('designer.block.saveFailed', 'Failed to save block.'),
            'error'
          );
        }
      } else {
        addToast(t('designer.block.empty', 'A block needs at least one element; discarded.'), 'info');
      }
    }
    setTemplate(restored);
    setCurrentPage(stash.currentPage);
    setSelectedIds(stash.selectedIds);
    setCurrentId(stash.currentId);
    setPast(restoredPast);
    setFuture(restoredFuture);
    historyRef.current = { lastLabel: '', lastTime: 0 };
    blockStashRef.current = null;
    setBlockEdit(null);
  };

  // Detach a block instance: replace the pointer with independent copies of the
  // block's elements (inlined at the instance position), unlinking it.
  //
  // ONE LEVEL, deliberately, now that blocks may nest (#1186). Detaching a
  // letterhead inlines its elements and leaves the logo instance inside it
  // still a live pointer — you unlinked the letterhead, not everything the
  // letterhead was built from. `resolveInstance` rather than `flattenBlock` is
  // what says so: flattening would quietly sever every nested block too, and a
  // person who wanted that can detach the inner one next.
  const detachInstance = (instId: string) => {
    const inst = elements.find((e) => e.id === instId);
    if (!inst || inst.type !== 'blockInstance') return;
    const b = blocksMap[inst.blockId];
    if (!b) return;
    commit('detach');
    const resolved = resolveInstance(inst, b).map((e, i) => ({
      ...e,
      id: `${e.type}-${Date.now()}-${(pasteSeq.current += 1)}-${i}`,
      z: inst.z + i,
    }));
    setTemplate((tpl) =>
      withPageElements(tpl, pageIndex, (els) => [...els.filter((e) => e.id !== instId), ...resolved])
    );
    setSelectedIds(resolved.map((e) => e.id));
  };

  // ── page operations ─────────────────────────────────────────────────────
  const addPage = () => {
    commit('page-add');
    const at = pageIndex + 1;
    setTemplate((tpl) => ({
      ...tpl,
      pages: [...tpl.pages.slice(0, at), { id: newPageId(), elements: [] }, ...tpl.pages.slice(at)],
    }));
    setSelectedIds([]);
    setCurrentPage(at);
  };

  const duplicatePage = () => {
    commit('page-duplicate');
    const at = pageIndex;
    setTemplate((tpl) => {
      const src = tpl.pages[at];
      const cloned = src.elements.map((el, i) => ({ ...el, id: `${el.type}-${Date.now()}-${i}` }) as DocElement);
      return {
        ...tpl,
        pages: [...tpl.pages.slice(0, at + 1), { id: newPageId(), elements: cloned }, ...tpl.pages.slice(at + 1)],
      };
    });
    setSelectedIds([]);
    setCurrentPage(at + 1);
  };

  const deletePage = () => {
    if (template.pages.length <= 1) return;
    commit('page-delete');
    const at = pageIndex;
    setTemplate((tpl) => ({ ...tpl, pages: tpl.pages.filter((_, i) => i !== at) }));
    setSelectedIds([]);
    setCurrentPage(Math.max(0, at - 1));
  };

  const movePage = (dir: 'left' | 'right') => {
    const at = pageIndex;
    const to = dir === 'left' ? at - 1 : at + 1;
    if (to < 0 || to >= template.pages.length) return;
    commit('page-move');
    setTemplate((tpl) => {
      const pages = [...tpl.pages];
      const [p] = pages.splice(at, 1);
      pages.splice(to, 0, p);
      return { ...tpl, pages };
    });
    setCurrentPage(to);
  };

  const goToPage = (i: number) => {
    setCurrentPage(i);
    setSelectedIds([]);
  };

  // Fold the runtime print settings into the template for save/export.
  const withSettings = (tpl: DocTemplate): DocTemplate => ({ ...tpl, sheet, sequence });

  const doSave = async () => {
    // Which document this save belongs to. If the editor moves on to a
    // DIFFERENT document while the request is in flight (New, Open saved,
    // Import), binding the returned id afterwards would leave that new document
    // secretly pointing at the row we just wrote — and the next Save would
    // overwrite the saved template with it.
    const epoch = docEpoch.current;
    try {
      const creating = currentId === null;
      const id = await adapter.saveTemplate(
        withSettings(template),
        currentId ?? undefined,
        // Create only. An update leaves the stored scope alone so a save here
        // cannot overwrite a visibility somebody set in Templates & Blocks,
        // where the OU placement and permission tag that belong with it live.
        creating ? pendingScope : undefined
      );
      const stillSameDoc = docEpoch.current === epoch;
      if (stillSameDoc) {
        setCurrentId(id);
      }
      const list = await adapter.listTemplates();
      setSaved(list);

      // NAME THE AUDIENCE. "Template saved." was true and useless: it was also
      // what you got when the save filed the template where nobody but you
      // could ever see it, which is how a designer full of work looked like an
      // empty product to everyone else.
      //
      // Read back from the list rather than echoing what we sent — the server
      // has the last word on scope, and a refused promotion must not be
      // reported here as if it had happened.
      const filedAs = (list.find((s) => s.id === id)?.scope as BlockScope | undefined) ?? 'personal';
      addToast(savedMessage(t, filedAs), 'success');
    } catch (error) {
      addToast(
        error instanceof Error ? error.message : t('designer.template.saveFailed', 'Failed to save template.'),
        'error'
      );
    }
  };

  /**
   * SAVE AS A COPY — start from an existing template without overwriting it.
   *
   * The hole this fills is not "a convenience". Opening a TENANT-WIDE template,
   * changing something, and pressing Save rewrites the template everyone in the
   * tenant uses: `doSave` updates in place whenever `currentId` is set, and an
   * update deliberately leaves the stored scope alone, so the edit stays
   * published. Somebody exploring "what if the header looked like this" had no
   * way to keep the result that did not also change everyone else's document,
   * and nothing on screen said so.
   *
   * Three things make this a copy rather than a second save:
   *
   *  - it CREATES (no id passed), so the original row is untouched;
   *  - it is filed as PERSONAL whatever the original was, because copying
   *    somebody's tenant template is not the same act as publishing your
   *    version of it to the tenant — and promoting it is a deliberate step that
   *    lives in Templates & Blocks with the placement and permission tag that
   *    belong beside it;
   *  - the editor then follows the COPY. Leaving it pointed at the original
   *    would put the next Ctrl+S straight back into the bug this exists to
   *    prevent.
   */
  const doSaveAsCopy = async () => {
    const epoch = docEpoch.current;
    // Named, not prompted, matching how a block is saved from a selection. The
    // library is where things get renamed, and two rows with one name is the
    // confusion worth avoiding here.
    const copy = withSettings({
      ...template,
      name: t('designer.template.copyName', '{name} (copy)', { name: template.name }),
    });

    try {
      const id = await adapter.saveTemplate(copy, undefined, 'personal');
      const list = await adapter.listTemplates();
      setSaved(list);

      if (docEpoch.current === epoch) {
        setCurrentId(id);
        setTemplate(copy);
      }

      const filedAs = (list.find((s) => s.id === id)?.scope as BlockScope | undefined) ?? 'personal';
      addToast(savedMessage(t, filedAs), 'success');
    } catch (error) {
      addToast(
        error instanceof Error ? error.message : t('designer.template.saveFailed', 'Failed to save template.'),
        'error'
      );
    }
  };

  const doLoad = (id: string) => {
    // Read from the already-loaded `saved` state rather than re-fetching —
    // it is kept current by the mount effect and every save/delete below.
    const entry = saved.find((s) => s.id === id);
    if (!entry) return;
    docEpoch.current += 1;
    setTemplate(entry.data);
    setSheet(entry.data.sheet ?? DEFAULT_SHEET);
    setSequence(entry.data.sequence ?? DEFAULT_SEQUENCE);
    setCurrentId(entry.id);
    setCurrentPage(0);
    setSelectedIds([]);
    setBatchRows(null);
    setBatchIndex(0);
    resetHistory();
    addToast(t('designer.template.loaded', 'Loaded “{name}”.', { name: entry.name }), 'info');
  };

  const doDeleteSaved = async (id: string) => {
    try {
      await adapter.deleteTemplate(id);
      setSaved(await adapter.listTemplates());
      setCurrentId(null);
      addToast(t('designer.template.deleted', 'Saved template deleted.'), 'info');
    } catch (error) {
      addToast(
        error instanceof Error
          ? error.message
          : t('designer.template.deleteFailed', 'Failed to delete template.'),
        'error'
      );
    }
  };

  /**
   * Ask before deleting the open template.
   *
   * The menu item sits directly under "Open saved" and used to delete on the
   * first click, with nothing to undo it — the undo stack holds document edits,
   * not rows. Naming the template is most of the value: it is the difference
   * between confirming an action and confirming *which* action.
   */
  const askDeleteSaved = () => {
    if (!currentId) return;
    const id = currentId;
    setPendingDelete({
      title: t('designer.template.confirmDeleteTitle', 'Delete “{name}”?', { name: template.name }),
      body: t(
        'designer.template.confirmDeleteBody',
        'The saved template is removed for good. The document open in the editor stays as it is, unsaved.'
      ),
      consequence: templateScope === 'personal' ? null : sharedTemplateWarning(t, templateScope),
      confirmLabel: t('designer.template.confirmDeleteAction', 'Delete template'),
      run: () => void doDeleteSaved(id),
    });
  };

  /**
   * Insert a flow block, after the selected one or at the end.
   *
   * "After the selected one" is what makes the `/` palette feel like writing
   * rather than appending: you are somewhere in the document, and the new block
   * arrives where you are. With nothing selected it goes to the end, which is
   * where an author with no cursor is.
   */
  const insertFlowBlock = (type: FlowBlockType) => {
    commit('flow-insert');
    const at = flowSelected === null ? (template.flow?.blocks.length ?? 0) : flowSelected + 1;
    setTemplate((tpl) => {
      const content: FlowContent = tpl.flow ?? { blocks: [] };
      const blocks = [...content.blocks];
      blocks.splice(at, 0, newFlowBlock(type));
      return { ...tpl, flow: { ...content, blocks } };
    });
    setFlowSelected(at);
  };

  /**
   * Switch the document between canvas and flow (#1186 slice 2).
   *
   * Routed through the SAME confirmation the deletes use, because canvas ->
   * flow discards placement and cannot recover it. The count comes from
   * `describeSwitch`, so the number the author is shown is the number that
   * actually survives — promising more than converts would be worse than not
   * saying anything.
   *
   * Flow -> canvas is additive and asks nothing: it lays the blocks out and
   * every one of them is draggable from that moment. An "are you sure" on an
   * action that loses nothing trains people to dismiss the ones that do.
   */
  const switchMode = (to: 'canvas' | 'flow') => {
    if (to === mode) return;
    const cost = describeSwitch(template, to);

    const apply = () => {
      commit('switch-mode');
      setSelectedIds([]);
      setFlowSelected(null);
      if (to === 'flow') {
        setTemplate((tpl) => ({ ...tpl, mode: 'flow', flow: canvasToFlow(tpl) }));
      } else {
        setTemplate((tpl) => flowToCanvas(tpl, () => `el-${Date.now()}-${(pasteSeq.current += 1)}`));
      }
    };

    if (!cost.lossy) {
      apply();
      return;
    }

    setPendingDelete({
      title: t('flow.switchTitle', 'Switch to document mode?'),
      body: t(
        'flow.switchBody',
        'Document mode arranges blocks one below another, so the positions you set on the canvas are not kept. Your canvas layout stays saved and comes back if you switch again.'
      ),
      consequence: t(
        'flow.switchCost',
        '{carried} of {total} items carry over as text or images. Shapes, lines, barcodes and placed blocks have no document-mode equivalent and are left behind.',
        {
          carried: String(cost.carried),
          total: String(template.pages.reduce((n, p) => n + p.elements.length, 0)),
        }
      ),
      confirmLabel: t('flow.switchAction', 'Switch to document mode'),
      run: apply,
    });
  };

  // Load a fresh document (blank or a starter), resetting all editor state.
  const loadFreshTemplate = (tpl: DocTemplate) => {
    docEpoch.current += 1;
    setTemplate(tpl);
    setSheet(tpl.sheet ?? DEFAULT_SHEET);
    setSequence(tpl.sequence ?? DEFAULT_SEQUENCE);
    setCurrentId(null);
    setCurrentPage(0);
    setSelectedIds([]);
    setBatchRows(null);
    setBatchIndex(0);
    resetHistory();
  };

  const doNew = () => loadFreshTemplate(blankTemplate());

  const doStartFrom = (starterId: string) => {
    const s = STARTER_TEMPLATES.find((x) => x.id === starterId);
    if (!s) return;
    loadFreshTemplate(s.make());
    addToast(t('designer.template.startedFrom', 'Started from “{name}”.', { name: s.label }), 'info');
  };

  const onImportFile = async (file: File) => {
    try {
      const parsed: unknown = JSON.parse(await file.text());
      if (!isDocTemplate(parsed)) {
        addToast(t('designer.import.invalid', 'That file is not a valid template.'), 'error');
        return;
      }
      const migrated = migrateTemplate(parsed);
      docEpoch.current += 1;
      setTemplate(migrated);
      setSheet(migrated.sheet ?? DEFAULT_SHEET);
      setSequence(migrated.sequence ?? DEFAULT_SEQUENCE);
      setCurrentId(null);
      setCurrentPage(0);
      setSelectedIds([]);
      setBatchRows(null);
      setBatchIndex(0);
      resetHistory();
      addToast(t('designer.import.done', 'Template imported.'), 'success');
    } catch {
      addToast(t('designer.import.unreadable', 'Could not read that file.'), 'error');
    }
  };

  const doPrint = () => {
    // The off-screen PrintDocument (all pages) is always mounted; just print.
    requestAnimationFrame(() => window.print());
  };

  /**
   * The COMMAND CONTEXT — this component's entire public command surface,
   * assembled in one place and handed to the chrome. The menu bar, the icon
   * toolbar and the shortcuts sheet are all rendered from it (see
   * `editor-commands.tsx`), so adding a command means adding it here and in the
   * registry, never in three separate pieces of markup.
   */
  const ctx: EditorCommandContext = {
    modLabel,
    canUndo: past.length > 0,
    canRedo: future.length > 0,
    hasClipboard,
    selectedCount: selectedIds.length,
    soleSelectedType: selected?.type ?? null,
    selectionLocked,
    selectionHidden,
    pageIndex,
    pageCount: template.pages.length,
    elementCount: elements.length,
    preview,
    showGrid,
    showRulers,
    snap,
    railOpen,
    savedTemplates: saved.map((s) => ({ id: s.id, name: s.name })),
    currentSavedId: currentId,
    blocks,
    batchActive,
    batchIndex: batchClampIndex,
    batchTotal: rows.length,
    blockEditing: blockEdit !== null,
    mode,
    onSwitchMode: switchMode,

    onNew: doNew,
    onStartFrom: doStartFrom,
    onOpenSaved: doLoad,
    onSave: () => void doSave(),
    onSaveAsCopy: () => void doSaveAsCopy(),
    onDeleteSaved: askDeleteSaved,
    onImport: () => fileRef.current?.click(),
    onExport: () => exportTemplateJson(withSettings(template)),
    onPrint: doPrint,
    onCloseEditor: () => onClose?.(),

    onUndo: undo,
    onRedo: redo,
    onCut: cutSelected,
    onCopy: copySelected,
    onPaste: pasteClipboard,
    onDuplicate: duplicateSelected,
    onDeleteSelection: deleteSelection,
    onSelectAll: selectAllOnPage,

    onAddElement: addElement,
    onInsertBlock: (id) => void insertBlock(id),

    onAlign: alignSelected,
    onDistribute: distributeSelected,
    onArrange: arrangeSelection,
    onToggleSelectionLock: () => setSelectionFlag('locked', !selectionLocked),
    onToggleSelectionHidden: () => setSelectionFlag('hidden', !selectionHidden),
    onSaveAsBlock: () => void saveSelectionAsBlock(),
    onEditSelectedBlock: () => {
      if (selected?.type === 'blockInstance') enterBlockEdit(selected.blockId);
    },
    onDetachSelectedBlock: () => {
      if (selected?.type === 'blockInstance') detachInstance(selected.id);
    },

    onAddPage: addPage,
    onDuplicatePage: duplicatePage,
    onDeletePage: deletePage,
    onMovePage: movePage,
    onGoToPage: goToPage,

    onTogglePreview: () => setPreview((p) => !p),
    onSetShowGrid: setShowGrid,
    onSetShowRulers: setShowRulers,
    onSetSnap: setSnap,
    onSetRailOpen: setRailOpen,
    onZoom: doZoom,

    // A menu item that targets a rail tab must also REVEAL the rail — otherwise
    // "Page setup…" would silently do nothing while it's collapsed.
    onOpenInspectorTab: (tab) => {
      setRailTab(tab);
      setRailOpen(true);
    },
    onClearBatch: clearBatch,
    onStepBatch: (delta) => setBatchIndex((i) => Math.max(0, Math.min(rows.length - 1, i + delta))),

    onShowShortcuts: () => setShortcutsOpen(true),
  };

  return (
    // `h-full min-h-0` + `flex-1 min-h-0` on the body: the editor fills whatever
    // height its host gives it and the CANVAS is the only thing that scrolls, so
    // the chrome and status bar stay pinned however tall the document gets.
    <div className="flex h-full min-h-0 flex-col bg-background" data-testid="document-designer">
      <EditorTopBar
        ctx={ctx}
        name={template.name}
        onNameChange={(name) => {
          commit('name');
          setTemplate((tpl) => ({ ...tpl, name }));
        }}
        scope={templateScope}
        onScopeChange={setPendingScope}
        zoom={zoom}
        blockEdit={blockEdit}
        onExitBlockEdit={(save) => void exitBlockEdit(save)}
        batchLabel={batchActive ? `×${rows.length}` : null}
      />

      {/* Hidden JSON picker, opened by File ▸ Import. */}
      <input
        ref={fileRef}
        type="file"
        accept="application/json,.json"
        className="hidden"
        data-testid="doc-import-input"
        onChange={(e) => {
          const f = e.target.files?.[0];
          if (f) void onImportFile(f);
          e.target.value = '';
        }}
      />

      {/* Editor body — the canvas takes everything the rail doesn't. No outer
          padding or gaps: the page is the subject, so chrome gets borders
          rather than margins. */}
      <div className="flex min-h-0 flex-1">
        <main
          ref={viewportRef}
          data-testid="doc-canvas-viewport"
          className="min-h-0 flex-1 overflow-auto bg-muted/30 p-6"
        >
          {mode === 'flow' ? (
            /* Document mode. The canvas viewport keeps its scroll and padding —
               only what sits inside it changes — so switching modes does not
               rebuild the whole editor chrome around the author. */
            <FlowEditor
              content={template.flow ?? { blocks: [] }}
              onChange={(next) => {
                commit('flow-edit');
                setTemplate((tpl) => ({ ...tpl, flow: next }));
              }}
              selected={flowSelected}
              onSelect={setFlowSelected}
              // A refused image is REPORTED. Without this the picker closes and
              // nothing changes, which reads exactly like the editor ignoring
              // the click — the failure the picker was added to fix.
              onError={(message) => addToast(message, 'error')}
            />
          ) : (
          <Canvas
            elements={elements}
            page={template.page}
            data={activeData}
            blocks={blocksMap}
            selectedIds={selectedIds}
            zoom={zoom}
            gridMm={snap ? 1 : 0}
            showGrid={showGrid}
            showRulers={showRulers}
            preview={preview}
            onSelect={selectOne}
            onChange={patchElement}
            onChangeMany={changeMany}
            onEditBlock={enterBlockEdit}
          />
          )}
        </main>

        {railOpen ? (
          <SideRail
            tab={railTab}
            onTabChange={setRailTab}
            onCollapse={() => setRailOpen(false)}
            palette={{
              elements,
              selectedIds,
              blocks,
              onSelect: selectOne,
              onReorder: reorder,
              onToggleLock: toggleLock,
              onToggleHidden: toggleHidden,
              onDelete: deleteElement,
              onInsertBlock: (id) => void insertBlock(id),
              onDeleteBlock: askDeleteBlock,
              onSetBlockScope: setBlockScope,
            }}
            inspector={{
              template,
              selected,
              selectedCount: selectedIds.length,
              batch: { active: batchActive, index: batchClampIndex, total: rows.length },
              sheet,
              sequence,
              onChangeSelected: (patch) => selectedId && patchElement(selectedId, patch),
              onChangePage: (patch: Partial<PageSpec>) => {
                commit('page');
                setTemplate((tpl) => ({ ...tpl, page: { ...tpl.page, ...patch } }));
              },
              onChangePlaceholders: (list: Placeholder[]) => {
                commit('data');
                setTemplate((tpl) => ({ ...tpl, placeholders: list }));
              },
              onGenerateBatch: generateBatch,
              onLoadBatchRecords: loadBatchRecords,
              onClearBatch: clearBatch,
              onBatchIndex: setBatchIndex,
              onChangeSheet: (patch) => setSheet((s) => ({ ...s, ...patch })),
              onChangeSequence: (patch) => setSequence((s) => ({ ...s, ...patch })),
            }}
          />
        ) : (
          // Collapsed: a one-button strip, so the rail is always one click back
          // and never only findable through the View menu.
          <div className="flex shrink-0 flex-col border-s border-border bg-card p-1">
            <button
              type="button"
              data-testid="doc-rail-expand"
              aria-label={t('designer.rail.show', 'Show side panel')}
              title={t('designer.rail.show', 'Show side panel')}
              onClick={() => setRailOpen(true)}
              className="flex size-7 items-center justify-center rounded-md text-muted-foreground outline-hidden hover:bg-accent hover:text-accent-foreground focus-visible:ring-2 focus-visible:ring-ring/40 [&_svg]:size-4"
            >
              <IconLayoutSidebarRightExpand className="rtl:rotate-180" />
            </button>
          </div>
        )}
      </div>

      {/* Status bar — page navigation, selection count and the batch row
          stepper: the "where am I" readouts, kept out of the command chrome
          above so the toolbar stays commands-only. */}
      <footer
        className="flex shrink-0 flex-wrap items-center gap-1.5 border-t border-border bg-card px-2 py-1"
        data-testid="doc-page-nav"
      >
        {/* Page management is hidden while editing a block: a block is a single
            fragment, so adding or deleting "pages" there is meaningless. */}
        {!blockEdit && (
          <>
        <span className="me-1 text-xs font-medium text-muted-foreground">
          {t('designer.status.pages', 'Pages')}
        </span>
        {template.pages.map((pg, i) => (
          <button
            key={pg.id}
            type="button"
            data-testid={`doc-page-tab-${i}`}
            aria-current={i === pageIndex}
            onClick={() => goToPage(i)}
            className={`h-6 min-w-6 rounded-md border px-1.5 text-xs tabular-nums ${
              i === pageIndex
                ? 'border-primary bg-primary/10 font-medium text-foreground'
                : 'border-border text-muted-foreground hover:text-foreground'
            }`}
          >
            {i + 1}
          </button>
        ))}
        <Button
          variant="ghost"
          size="icon-sm"
          aria-label={t('designer.page.add', 'Add page')}
          data-testid="doc-add-page"
          onClick={addPage}
        >
          <IconPlus className="h-4 w-4" />
        </Button>
        <Button
          variant="ghost"
          size="icon-sm"
          aria-label={t('designer.page.duplicate', 'Duplicate page')}
          data-testid="doc-duplicate-page"
          onClick={duplicatePage}
        >
          <IconFiles className="h-4 w-4" />
        </Button>
        <Button
          variant="ghost"
          size="icon-sm"
          aria-label={t('designer.page.moveEarlier', 'Move page earlier')}
          disabled={pageIndex === 0}
          onClick={() => movePage('left')}
        >
          <IconChevronLeft className="h-4 w-4 rtl:rotate-180" />
        </Button>
        <Button
          variant="ghost"
          size="icon-sm"
          aria-label={t('designer.page.moveLater', 'Move page later')}
          disabled={pageIndex >= template.pages.length - 1}
          onClick={() => movePage('right')}
        >
          <IconChevronRight className="h-4 w-4 rtl:rotate-180" />
        </Button>
        <Button
          variant="ghost"
          size="icon-sm"
          aria-label={t('designer.page.delete', 'Delete page')}
          data-testid="doc-delete-page"
          disabled={template.pages.length <= 1}
          onClick={deletePage}
        >
          <IconTrash className="h-4 w-4 text-destructive/80" />
        </Button>
          </>
        )}

        <span className="ms-auto flex items-center gap-3 text-xs text-muted-foreground">
          {selectedIds.length > 0 && (
            <span className="font-medium text-primary" data-testid="doc-selection-count">
              {t('designer.status.selected', '{count} selected', { count: selectedIds.length })}
            </span>
          )}
          {batchActive && (
            <span className="flex items-center gap-1" data-testid="doc-status-batch">
              <Button
                variant="ghost"
                size="icon-sm"
                aria-label={t('designer.status.previousRow', 'Previous data row')}
                data-testid="doc-status-batch-prev"
                disabled={batchClampIndex <= 0}
                onClick={() => setBatchIndex(batchClampIndex - 1)}
              >
                <IconChevronLeft className="h-4 w-4 rtl:rotate-180" />
              </Button>
              <span className="tabular-nums">
                {t('designer.status.row', 'Row {index} / {total}', {
                  index: batchClampIndex + 1,
                  total: rows.length,
                })}
              </span>
              <Button
                variant="ghost"
                size="icon-sm"
                aria-label={t('designer.status.nextRow', 'Next data row')}
                data-testid="doc-status-batch-next"
                disabled={batchClampIndex >= rows.length - 1}
                onClick={() => setBatchIndex(batchClampIndex + 1)}
              >
                <IconChevronRight className="h-4 w-4 rtl:rotate-180" />
              </Button>
            </span>
          )}
          <span className="tabular-nums">
            {blockEdit
              ? t('designer.status.blockEdit', 'Editing a reusable block')
              : t('designer.status.page', 'Page {index} of {total}', {
                  index: pageIndex + 1,
                  total: template.pages.length,
                })}
          </span>
        </span>
      </footer>

      {/* Off-screen, all-pages render used only for printing (per data row). */}
      <PrintDocument template={template} datasets={printDatasets} blocks={blocksMap} sheet={sheet} />

      <ShortcutsDialog open={shortcutsOpen} onOpenChange={setShortcutsOpen} modLabel={modLabel} />

      {/* The `/` palette. Its items are DERIVED from the same registry the menu
          bar renders (see editor-palette.tsx), so a command added to the menus
          appears here without anyone remembering to add it — and one removed
          cannot linger. Built only while open: flattening ~60 nodes on every
          render of the editor would be work nobody asked for. */}
      <CommandPalette
        open={paletteOpen}
        onOpenChange={setPaletteOpen}
        items={
          !paletteOpen
            ? []
            : mode === 'flow'
              ? paletteItemsForFlow(
                  buildEditorMenus(ctx, t),
                  flowPaletteItems(
                    t('commands.menu.insert', 'Insert'),
                    (type) => flowBlockLabel(t, type),
                    insertFlowBlock
                  )
                )
              : paletteItemsFromMenus(buildEditorMenus(ctx, t))
        }
        label={t('palette.command.label', 'Command palette')}
        placeholder={t('palette.command.placeholder', 'Type a command or search for a block…')}
        emptyLabel={t('palette.command.empty', 'No matching command')}
      />

      <ConfirmDelete
        open={pendingDelete !== null}
        onOpenChange={(open) => {
          if (!open) setPendingDelete(null);
        }}
        title={pendingDelete?.title ?? ''}
        body={pendingDelete?.body ?? ''}
        consequence={pendingDelete?.consequence ?? null}
        confirmLabel={pendingDelete?.confirmLabel ?? ''}
        onConfirm={() => {
          // Read the action out before clearing: AlertDialogAction closes the
          // dialog as part of the same click, and `pendingDelete` is null by the
          // time a later tick would look at it.
          const run = pendingDelete?.run;
          setPendingDelete(null);
          run?.();
        }}
      />

      {/* Print stylesheet: hide the app chrome and emit each page at the physical
          @page size with a break between pages. Rendered as a text child (not
          innerHTML); the interpolated dims are plain numbers from state. */}
      <style>{`.doc-print-doc { display: none; }
      @media print {
        body * { visibility: hidden !important; }
        .doc-print-doc, .doc-print-doc * { visibility: visible !important; }
        .doc-print-doc { display: block !important; position: absolute; left: 0; top: 0; }
        .doc-print-page { break-after: page; box-shadow: none !important; }
        .doc-print-page:last-child { break-after: auto; }
        @page { size: ${sheet.enabled ? sheet.sheetWidthMm : template.page.widthMm}mm ${sheet.enabled ? sheet.sheetHeightMm : template.page.heightMm}mm; margin: 0; }
      }`}</style>
    </div>
  );
}
