'use client';

import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import type { DocElement, DocTemplate, ElementType, PageSpec, Placeholder } from '@/lib/documents/types';
import {
  blankTemplate,
  newPageId,
} from '@/lib/documents/presets';
import {
  deleteSaved,
  exportTemplateJson,
  isDocTemplate,
  listSaved,
  migrateTemplate,
  newElement,
  sampleDataOf,
  saveTemplate,
  type SavedTemplate,
} from '@/lib/documents/storage';
import {
  DEFAULT_SEQUENCE,
  generateSequence,
  rowsFromRecords,
  rowsFromValues,
  type SequenceConfig,
} from '@/lib/documents/batch';
import { DEFAULT_SHEET, type SheetSpec } from '@/lib/documents/sheet';
import {
  blocksById,
  deleteBlock,
  listBlocks,
  makeBlockFromElements,
  resolveInstance,
  saveBlock,
  type BlockScope,
  type DocBlock,
} from '@/lib/documents/blocks';
import { useToast } from '@/lib/toast-context';
import { Button } from '@amroksaleh/ui/button';
import { Input } from '@amroksaleh/ui/input';
import { Switch } from '@amroksaleh/ui/switch';
import {
  IconDeviceFloppy,
  IconFileText,
  IconDownload,
  IconUpload,
  IconPrinter,
  IconEye,
  IconEyeOff,
  IconCopy,
  IconClipboardCopy,
  IconClipboard,
  IconScissors,
  IconZoomIn,
  IconZoomOut,
  IconArrowBackUp,
  IconArrowForwardUp,
  IconPlus,
  IconTrash,
  IconChevronLeft,
  IconChevronRight,
  IconFiles,
  IconComponents,
  IconLayoutAlignLeft,
  IconLayoutAlignCenter,
  IconLayoutAlignRight,
  IconLayoutAlignTop,
  IconLayoutAlignMiddle,
  IconLayoutAlignBottom,
} from '@tabler/icons-react';
import { Canvas } from './canvas';
import { Palette } from './palette';
import { Inspector } from './inspector';
import { PrintDocument } from './print-document';

const SELECT_CLASS =
  'h-7 rounded-md border border-input bg-input/20 px-2 text-sm outline-none focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/30';

/** Immutably replace the elements of one page within a template. */
function withPageElements(
  t: DocTemplate,
  idx: number,
  fn: (els: DocElement[]) => DocElement[]
): DocTemplate {
  return { ...t, pages: t.pages.map((p, i) => (i === idx ? { ...p, elements: fn(p.elements) } : p)) };
}

export function DocumentDesigner() {
  const { addToast } = useToast();
  const [template, setTemplate] = useState<DocTemplate>(() => blankTemplate());
  const [currentPage, setCurrentPage] = useState(0);
  const [selectedIds, setSelectedIds] = useState<string[]>([]);
  const [preview, setPreview] = useState(false);
  const [zoom, setZoom] = useState(1);
  const [snap, setSnap] = useState(true);
  const [showGrid, setShowGrid] = useState(false);
  const [saved, setSaved] = useState<SavedTemplate[]>([]);
  const [currentId, setCurrentId] = useState<string | null>(null);
  const fileRef = useRef<HTMLInputElement>(null);

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

  // Reusable blocks (personal, localStorage for the MVP). Documents reference a
  // block by id via a `blockInstance` element; the block store holds the shared
  // definition, so editing a block updates every instance.
  const [blocks, setBlocks] = useState<DocBlock[]>([]);
  useEffect(() => {
    const p = Promise.resolve().then(() => setBlocks(listBlocks()));
    void p;
  }, []);
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

  // Read the saved-template list from localStorage after mount (client-only).
  // Deferred off the synchronous effect tick to stay clear of the
  // set-state-in-effect rule while still populating on first render.
  useEffect(() => {
    const p = Promise.resolve().then(() => setSaved(listSaved()));
    void p;
  }, []);

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
    setTemplate((t) => {
      const els = t.pages[idx]?.elements ?? [];
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
      return withPageElements(t, idx, (e) => [...e, ...clones]);
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
    setTemplate((t) => withPageElements(t, kbRef.current.pageIndex, (e) => e.filter((x) => !ids.has(x.id))));
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

  // Keyboard: Ctrl/Cmd+Z undo, Ctrl/Cmd+Shift+Z or Ctrl+Y redo (work without a
  // selection); Delete removes, arrows nudge (Shift = 5mm), Escape deselects.
  // Ignored while typing in a form field (native undo there).
  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      const target = e.target as HTMLElement | null;
      if (
        target &&
        (target.tagName === 'INPUT' || target.tagName === 'TEXTAREA' || target.tagName === 'SELECT' || target.isContentEditable)
      ) {
        return;
      }
      const mod = e.ctrlKey || e.metaKey;
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
        commit('delete');
        setTemplate((t) => withPageElements(t, idx, (e2) => e2.filter((x) => !movableIds.has(x.id))));
        setSelectedIds([]);
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
        setTemplate((t) =>
          withPageElements(t, idx, (e2) =>
            e2.map((x) => (movableIds.has(x.id) ? { ...x, x: Math.max(0, x.x + d[0]), y: Math.max(0, x.y + d[1]) } : x))
          )
        );
      }
    };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [undo, redo, commit, copySelected, cutSelected, pasteClipboard, duplicateSelected]);

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
    addToast(built.length ? `Generated ${built.length} rows.` : 'No rows generated.', built.length ? 'success' : 'info');
  };

  const loadBatchRecords = (records: Record<string, string>[]) => {
    const built = rowsFromRecords(records, data);
    setBatchRows(built.length ? built : null);
    setBatchIndex(0);
    addToast(built.length ? `Loaded ${built.length} rows.` : 'No rows found.', built.length ? 'success' : 'info');
  };

  const clearBatch = () => {
    setBatchRows(null);
    setBatchIndex(0);
  };

  const patchElement = (id: string, patch: Partial<DocElement>) => {
    // Label by field set so a continuous drag / same-field typing coalesces.
    commit(`patch:${Object.keys(patch).sort().join(',')}`);
    setTemplate((t) =>
      withPageElements(t, pageIndex, (els) =>
        els.map((e) => (e.id === id ? ({ ...e, ...patch } as DocElement) : e))
      )
    );
  };

  const addElement = (type: ElementType) => {
    commit('add');
    setTemplate((t) => {
      const el = newElement(type, t.pages[pageIndex]?.elements ?? []);
      setSelectedIds([el.id]);
      return withPageElements(t, pageIndex, (els) => [...els, el]);
    });
  };

  const deleteElement = (id: string) => {
    commit('delete');
    setTemplate((t) => withPageElements(t, pageIndex, (els) => els.filter((e) => e.id !== id)));
    setSelectedIds((prev) => prev.filter((x) => x !== id));
  };

  // Batch position/size update for a group drag — one coalesced history step.
  const changeMany = (updates: Array<{ id: string; patch: Partial<DocElement> }>) => {
    commit('drag-group');
    const map = new Map(updates.map((u) => [u.id, u.patch]));
    setTemplate((t) =>
      withPageElements(t, pageIndex, (els) =>
        els.map((e) => (map.has(e.id) ? ({ ...e, ...map.get(e.id) } as DocElement) : e))
      )
    );
  };

  // Align every selected element (skipping locked) to a page edge/centre.
  const alignSelected = (kind: 'left' | 'hcenter' | 'right' | 'top' | 'vmiddle' | 'bottom') => {
    const ids = new Set(selectedIds);
    if (!elements.some((e) => ids.has(e.id) && !e.locked)) return;
    const { widthMm: W, heightMm: H } = template.page;
    commit('align');
    setTemplate((t) =>
      withPageElements(t, pageIndex, (els) =>
        els.map((e) => {
          if (!ids.has(e.id) || e.locked) return e;
          const patch: Partial<DocElement> =
            kind === 'left'
              ? { x: 0 }
              : kind === 'hcenter'
                ? { x: Math.max(0, (W - e.w) / 2) }
                : kind === 'right'
                  ? { x: Math.max(0, W - e.w) }
                  : kind === 'top'
                    ? { y: 0 }
                    : kind === 'vmiddle'
                      ? { y: Math.max(0, (H - e.h) / 2) }
                      : { y: Math.max(0, H - e.h) };
          return { ...e, ...patch } as DocElement;
        })
      )
    );
  };

  const toggleLock = (id: string) => {
    commit('lock');
    setTemplate((t) =>
      withPageElements(t, pageIndex, (els) =>
        els.map((e) => (e.id === id ? ({ ...e, locked: !e.locked } as DocElement) : e))
      )
    );
  };

  const toggleHidden = (id: string) => {
    commit('hide');
    setTemplate((t) =>
      withPageElements(t, pageIndex, (els) =>
        els.map((e) => (e.id === id ? ({ ...e, hidden: !e.hidden } as DocElement) : e))
      )
    );
  };

  const reorder = (id: string, dir: 'up' | 'down') => {
    commit('reorder');
    setTemplate((t) =>
      withPageElements(t, pageIndex, (els) => {
        const zs = els.map((e) => e.z);
        const target = dir === 'up' ? Math.max(...zs) + 1 : Math.min(...zs) - 1;
        return els.map((e) => (e.id === id ? { ...e, z: target } : e));
      })
    );
  };

  // ── reusable blocks ───────────────────────────────────────────────────────
  const saveSelectionAsBlock = () => {
    const sel = elements.filter((e) => selectedIds.includes(e.id));
    const block = makeBlockFromElements(`Block ${blocks.length + 1}`, sel);
    if (!block) {
      addToast('Select one or more elements to save as a block.', 'info');
      return;
    }
    saveBlock(block);
    setBlocks(listBlocks());
    addToast(`Saved block “${block.name}”.`, 'success');
  };

  const insertBlock = (blockId: string) => {
    const b = blocksMap[blockId];
    if (!b) return;
    commit('insert-block');
    setTemplate((t) => {
      const els = t.pages[pageIndex]?.elements ?? [];
      const inst = {
        id: `blockInstance-${Date.now()}-${(pasteSeq.current += 1)}`,
        type: 'blockInstance' as const,
        blockId,
        x: 8,
        y: 8,
        w: b.w,
        h: b.h,
        rotation: 0,
        z: els.reduce((m, e) => Math.max(m, e.z), 0) + 1,
      };
      setSelectedIds([inst.id]);
      return withPageElements(t, pageIndex, (e) => [...e, inst]);
    });
  };

  const deleteBlockDef = (id: string) => {
    deleteBlock(id);
    setBlocks(listBlocks());
    addToast('Block deleted from your library.', 'info');
  };

  // Change a block's visibility tier. Tenant/global publishing will be RBAC-gated
  // once the backend store exists; for now it updates the local library.
  const setBlockScope = (id: string, scope: BlockScope) => {
    const b = blocksMap[id];
    if (!b) return;
    saveBlock({ ...b, scope });
    setBlocks(listBlocks());
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
  const exitBlockEdit = (save: boolean) => {
    const stash = blockStashRef.current;
    const editing = blockEdit;
    if (!stash || !editing) return;
    if (save) {
      const els = template.pages[0]?.elements ?? [];
      const rebuilt = makeBlockFromElements(editing.name, els);
      if (rebuilt) {
        saveBlock({ ...rebuilt, id: editing.id });
        setBlocks(listBlocks());
        addToast(`Block “${editing.name}” updated.`, 'success');
      } else {
        addToast('A block needs at least one element; discarded.', 'info');
      }
    }
    setTemplate(stash.template);
    setCurrentPage(stash.currentPage);
    setSelectedIds(stash.selectedIds);
    setCurrentId(stash.currentId);
    setPast(stash.past);
    setFuture(stash.future);
    historyRef.current = { lastLabel: '', lastTime: 0 };
    blockStashRef.current = null;
    setBlockEdit(null);
  };

  // Detach a block instance: replace the pointer with independent copies of the
  // block's elements (inlined at the instance position), unlinking it.
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
    setTemplate((t) =>
      withPageElements(t, pageIndex, (els) => [...els.filter((e) => e.id !== instId), ...resolved])
    );
    setSelectedIds(resolved.map((e) => e.id));
  };

  // ── page operations ─────────────────────────────────────────────────────
  const addPage = () => {
    commit('page-add');
    const at = pageIndex + 1;
    setTemplate((t) => ({
      ...t,
      pages: [...t.pages.slice(0, at), { id: newPageId(), elements: [] }, ...t.pages.slice(at)],
    }));
    setSelectedIds([]);
    setCurrentPage(at);
  };

  const duplicatePage = () => {
    commit('page-duplicate');
    const at = pageIndex;
    setTemplate((t) => {
      const src = t.pages[at];
      const cloned = src.elements.map((el, i) => ({ ...el, id: `${el.type}-${Date.now()}-${i}` }) as DocElement);
      return {
        ...t,
        pages: [...t.pages.slice(0, at + 1), { id: newPageId(), elements: cloned }, ...t.pages.slice(at + 1)],
      };
    });
    setSelectedIds([]);
    setCurrentPage(at + 1);
  };

  const deletePage = () => {
    if (template.pages.length <= 1) return;
    commit('page-delete');
    const at = pageIndex;
    setTemplate((t) => ({ ...t, pages: t.pages.filter((_, i) => i !== at) }));
    setSelectedIds([]);
    setCurrentPage(Math.max(0, at - 1));
  };

  const movePage = (dir: 'left' | 'right') => {
    const at = pageIndex;
    const to = dir === 'left' ? at - 1 : at + 1;
    if (to < 0 || to >= template.pages.length) return;
    commit('page-move');
    setTemplate((t) => {
      const pages = [...t.pages];
      const [p] = pages.splice(at, 1);
      pages.splice(to, 0, p);
      return { ...t, pages };
    });
    setCurrentPage(to);
  };

  const goToPage = (i: number) => {
    setCurrentPage(i);
    setSelectedIds([]);
  };

  // Fold the runtime print settings into the template for save/export.
  const withSettings = (t: DocTemplate): DocTemplate => ({ ...t, sheet, sequence });

  const doSave = () => {
    const id = saveTemplate(withSettings(template), currentId ?? undefined);
    setCurrentId(id);
    setSaved(listSaved());
    addToast('Template saved.', 'success');
  };

  const doLoad = (id: string) => {
    const entry = listSaved().find((s) => s.id === id);
    if (!entry) return;
    setTemplate(entry.data);
    setSheet(entry.data.sheet ?? DEFAULT_SHEET);
    setSequence(entry.data.sequence ?? DEFAULT_SEQUENCE);
    setCurrentId(entry.id);
    setCurrentPage(0);
    setSelectedIds([]);
    setBatchRows(null);
    setBatchIndex(0);
    resetHistory();
    addToast(`Loaded “${entry.name}”.`, 'info');
  };

  const doNew = () => {
    setTemplate(blankTemplate());
    setSheet(DEFAULT_SHEET);
    setSequence(DEFAULT_SEQUENCE);
    setCurrentId(null);
    setCurrentPage(0);
    setSelectedIds([]);
    setBatchRows(null);
    setBatchIndex(0);
    resetHistory();
  };

  const onImportFile = async (file: File) => {
    try {
      const parsed: unknown = JSON.parse(await file.text());
      if (!isDocTemplate(parsed)) {
        addToast('That file is not a valid template.', 'error');
        return;
      }
      const migrated = migrateTemplate(parsed);
      setTemplate(migrated);
      setSheet(migrated.sheet ?? DEFAULT_SHEET);
      setSequence(migrated.sequence ?? DEFAULT_SEQUENCE);
      setCurrentId(null);
      setCurrentPage(0);
      setSelectedIds([]);
      setBatchRows(null);
      setBatchIndex(0);
      resetHistory();
      addToast('Template imported.', 'success');
    } catch {
      addToast('Could not read that file.', 'error');
    }
  };

  const doPrint = () => {
    // The off-screen PrintDocument (all pages) is always mounted; just print.
    requestAnimationFrame(() => window.print());
  };

  return (
    <div className="flex flex-col gap-3" data-testid="document-designer">
      {blockEdit && (
        <div
          className="flex items-center gap-2 rounded-lg border border-primary/50 bg-primary/10 px-3 py-2"
          data-testid="doc-block-edit-banner"
        >
          <IconComponents className="h-4 w-4 text-primary" />
          <span className="text-sm font-medium text-primary">Editing block: {blockEdit.name}</span>
          <span className="ms-auto flex items-center gap-2">
            <Button size="sm" variant="ghost" data-testid="doc-block-edit-cancel" onClick={() => exitBlockEdit(false)}>
              Cancel
            </Button>
            <Button size="sm" data-testid="doc-block-edit-done" onClick={() => exitBlockEdit(true)}>
              Done
            </Button>
          </span>
        </div>
      )}

      {/* Toolbar */}
      <div className="flex flex-wrap items-center gap-2 rounded-lg border border-border bg-card p-2">
        <Input
          aria-label="Template name"
          data-testid="doc-name"
          value={template.name}
          onChange={(e) => {
            commit('name');
            setTemplate((t) => ({ ...t, name: e.target.value }));
          }}
          className="max-w-[16rem]"
        />
        <Button
          variant="ghost"
          size="icon-sm"
          aria-label="Undo"
          data-testid="doc-undo"
          disabled={past.length === 0}
          onClick={undo}
        >
          <IconArrowBackUp className="h-4 w-4" />
        </Button>
        <Button
          variant="ghost"
          size="icon-sm"
          aria-label="Redo"
          data-testid="doc-redo"
          disabled={future.length === 0}
          onClick={redo}
        >
          <IconArrowForwardUp className="h-4 w-4" />
        </Button>
        <span className="mx-1 h-5 w-px bg-border" />
        <Button variant="outline" size="sm" className="gap-1" onClick={doNew}>
          <IconFileText className="h-3.5 w-3.5" /> New
        </Button>
        <Button size="sm" className="gap-1" data-testid="doc-save" onClick={doSave}>
          <IconDeviceFloppy className="h-3.5 w-3.5" /> Save
        </Button>
        {hasClipboard && (
          <Button
            variant="outline"
            size="sm"
            className="gap-1"
            data-testid="doc-paste"
            onClick={pasteClipboard}
          >
            <IconClipboard className="h-3.5 w-3.5" /> Paste
          </Button>
        )}
        <select
          className={SELECT_CLASS}
          aria-label="Load saved template"
          value=""
          onChange={(e) => e.target.value && doLoad(e.target.value)}
        >
          <option value="">Load…</option>
          {saved.map((s) => (
            <option key={s.id} value={s.id}>
              {s.name}
            </option>
          ))}
        </select>
        {currentId && (
          <Button
            variant="ghost"
            size="sm"
            onClick={() => {
              deleteSaved(currentId);
              setSaved(listSaved());
              setCurrentId(null);
              addToast('Saved template deleted.', 'info');
            }}
          >
            Delete saved
          </Button>
        )}
        <span className="mx-1 h-5 w-px bg-border" />
        <Button variant="outline" size="sm" className="gap-1" onClick={() => exportTemplateJson(withSettings(template))}>
          <IconDownload className="h-3.5 w-3.5" /> Export
        </Button>
        <Button variant="outline" size="sm" className="gap-1" onClick={() => fileRef.current?.click()}>
          <IconUpload className="h-3.5 w-3.5" /> Import
        </Button>
        <input
          ref={fileRef}
          type="file"
          accept="application/json,.json"
          className="hidden"
          onChange={(e) => {
            const f = e.target.files?.[0];
            if (f) void onImportFile(f);
            e.target.value = '';
          }}
        />
        <span className="mx-1 h-5 w-px bg-border" />
        <Button variant="outline" size="sm" className="gap-1" data-testid="doc-preview-toggle" onClick={() => setPreview((p) => !p)}>
          {preview ? <IconEyeOff className="h-3.5 w-3.5" /> : <IconEye className="h-3.5 w-3.5" />}
          {preview ? 'Editing' : 'Preview'}
        </Button>
        <Button variant="outline" size="sm" className="gap-1" data-testid="doc-print" onClick={doPrint}>
          <IconPrinter className="h-3.5 w-3.5" /> Print
        </Button>
        {batchActive && (
          <span
            className="rounded-md bg-primary/10 px-1.5 py-0.5 text-xs font-medium text-primary"
            data-testid="doc-batch-badge"
            title="Print will render one copy per batch row"
          >
            ×{rows.length}
          </span>
        )}

        <span className="ms-auto flex items-center gap-2">
          <label className="flex items-center gap-1.5 text-xs text-muted-foreground">
            Snap <Switch checked={snap} onCheckedChange={setSnap} />
          </label>
          <label className="flex items-center gap-1.5 text-xs text-muted-foreground">
            Grid <Switch data-testid="doc-grid-toggle" checked={showGrid} onCheckedChange={setShowGrid} />
          </label>
          <span className="flex items-center gap-1">
            <Button variant="ghost" size="icon-sm" aria-label="Zoom out" onClick={() => setZoom((z) => Math.max(0.25, +(z - 0.25).toFixed(2)))}>
              <IconZoomOut className="h-4 w-4" />
            </Button>
            <span className="w-10 text-center text-xs tabular-nums">{Math.round(zoom * 100)}%</span>
            <Button variant="ghost" size="icon-sm" aria-label="Zoom in" onClick={() => setZoom((z) => Math.min(3, +(z + 0.25).toFixed(2)))}>
              <IconZoomIn className="h-4 w-4" />
            </Button>
          </span>
        </span>
      </div>

      {/* Page navigator */}
      <div className="flex flex-wrap items-center gap-1.5 rounded-lg border border-border bg-card px-2 py-1.5" data-testid="doc-page-nav">
        <span className="me-1 text-xs font-medium text-muted-foreground">Pages</span>
        {template.pages.map((pg, i) => (
          <button
            key={pg.id}
            type="button"
            data-testid={`doc-page-tab-${i}`}
            aria-current={i === pageIndex}
            onClick={() => goToPage(i)}
            className={`h-7 min-w-7 rounded-md border px-2 text-xs tabular-nums ${
              i === pageIndex ? 'border-primary bg-primary/10 font-medium text-foreground' : 'border-border text-muted-foreground hover:text-foreground'
            }`}
          >
            {i + 1}
          </button>
        ))}
        <Button variant="outline" size="icon-sm" aria-label="Add page" data-testid="doc-add-page" onClick={addPage}>
          <IconPlus className="h-4 w-4" />
        </Button>
        <span className="mx-1 h-5 w-px bg-border" />
        <Button variant="ghost" size="icon-sm" aria-label="Duplicate page" data-testid="doc-duplicate-page" onClick={duplicatePage}>
          <IconFiles className="h-4 w-4" />
        </Button>
        <Button
          variant="ghost"
          size="icon-sm"
          aria-label="Move page left"
          disabled={pageIndex === 0}
          onClick={() => movePage('left')}
        >
          <IconChevronLeft className="h-4 w-4" />
        </Button>
        <Button
          variant="ghost"
          size="icon-sm"
          aria-label="Move page right"
          disabled={pageIndex >= template.pages.length - 1}
          onClick={() => movePage('right')}
        >
          <IconChevronRight className="h-4 w-4" />
        </Button>
        <Button
          variant="ghost"
          size="icon-sm"
          aria-label="Delete page"
          data-testid="doc-delete-page"
          disabled={template.pages.length <= 1}
          onClick={deletePage}
        >
          <IconTrash className="h-4 w-4 text-destructive/80" />
        </Button>
        <span className="ms-auto text-xs text-muted-foreground">
          Page {pageIndex + 1} of {template.pages.length}
        </span>
      </div>

      {/* Editor body */}
      <div className="grid h-[74vh] grid-cols-[13rem_1fr_18rem] gap-3">
        <aside className="overflow-hidden rounded-lg border border-border bg-card p-3">
          <Palette
            elements={elements}
            selectedIds={selectedIds}
            blocks={blocks}
            onAdd={addElement}
            onSelect={selectOne}
            onReorder={reorder}
            onToggleLock={toggleLock}
            onToggleHidden={toggleHidden}
            onDelete={deleteElement}
            onInsertBlock={insertBlock}
            onDeleteBlock={deleteBlockDef}
            onSetBlockScope={setBlockScope}
          />
        </aside>

        <main className="overflow-auto rounded-lg border border-border bg-muted/30 p-6">
          <Canvas
            elements={elements}
            page={template.page}
            data={activeData}
            blocks={blocksMap}
            selectedIds={selectedIds}
            zoom={zoom}
            gridMm={snap ? 1 : 0}
            showGrid={showGrid}
            preview={preview}
            onSelect={selectOne}
            onChange={patchElement}
            onChangeMany={changeMany}
            onEditBlock={enterBlockEdit}
          />
        </main>

        <aside className="overflow-hidden rounded-lg border border-border bg-card p-3">
          {selectedIds.length > 0 && (
            <div className="mb-2 space-y-1.5" data-testid="doc-selected-actions">
              {selectedIds.length > 1 && (
                <p className="text-xs font-medium text-primary" data-testid="doc-selection-count">
                  {selectedIds.length} elements selected
                </p>
              )}
              <div className="flex items-center gap-0.5">
                <Button variant="outline" size="icon-sm" aria-label="Align left" onClick={() => alignSelected('left')}>
                  <IconLayoutAlignLeft className="h-4 w-4" />
                </Button>
                <Button variant="outline" size="icon-sm" aria-label="Align horizontal center" onClick={() => alignSelected('hcenter')}>
                  <IconLayoutAlignCenter className="h-4 w-4" />
                </Button>
                <Button variant="outline" size="icon-sm" aria-label="Align right" onClick={() => alignSelected('right')}>
                  <IconLayoutAlignRight className="h-4 w-4" />
                </Button>
                <span className="mx-0.5 h-4 w-px bg-border" />
                <Button variant="outline" size="icon-sm" aria-label="Align top" onClick={() => alignSelected('top')}>
                  <IconLayoutAlignTop className="h-4 w-4" />
                </Button>
                <Button variant="outline" size="icon-sm" aria-label="Align vertical middle" onClick={() => alignSelected('vmiddle')}>
                  <IconLayoutAlignMiddle className="h-4 w-4" />
                </Button>
                <Button variant="outline" size="icon-sm" aria-label="Align bottom" onClick={() => alignSelected('bottom')}>
                  <IconLayoutAlignBottom className="h-4 w-4" />
                </Button>
              </div>
              <div className="flex items-center gap-0.5">
                <Button variant="outline" size="icon-sm" aria-label="Copy" data-testid="doc-copy" onClick={copySelected}>
                  <IconClipboardCopy className="h-4 w-4" />
                </Button>
                <Button variant="outline" size="icon-sm" aria-label="Cut" data-testid="doc-cut" onClick={cutSelected}>
                  <IconScissors className="h-4 w-4" />
                </Button>
                <Button variant="outline" size="icon-sm" aria-label="Duplicate" data-testid="doc-duplicate" onClick={duplicateSelected}>
                  <IconCopy className="h-4 w-4" />
                </Button>
              </div>
              {selected?.type === 'blockInstance' ? (
                <div className="flex items-center gap-1">
                  <Button
                    variant="outline"
                    size="sm"
                    className="flex-1 gap-1"
                    data-testid="doc-block-edit"
                    onClick={() => enterBlockEdit(selected.blockId)}
                  >
                    <IconComponents className="h-3.5 w-3.5" /> Edit block
                  </Button>
                  <Button
                    variant="outline"
                    size="sm"
                    className="flex-1"
                    data-testid="doc-block-detach"
                    onClick={() => detachInstance(selected.id)}
                  >
                    Detach
                  </Button>
                </div>
              ) : (
                <Button
                  variant="outline"
                  size="sm"
                  className="w-full gap-1"
                  data-testid="doc-save-block"
                  onClick={saveSelectionAsBlock}
                >
                  <IconComponents className="h-3.5 w-3.5" /> Save as block
                </Button>
              )}
              <p className="text-[10px] leading-tight text-muted-foreground">
                Tip: ⌘/Ctrl+C/X/V copy/cut/paste, ⌘/Ctrl+D duplicate, arrows nudge
                (Shift = 5mm), Delete removes, Esc deselects.
              </p>
            </div>
          )}
          <Inspector
            template={template}
            selected={selected}
            selectedCount={selectedIds.length}
            batch={{ active: batchActive, index: batchClampIndex, total: rows.length }}
            onChangeSelected={(patch) => selectedId && patchElement(selectedId, patch)}
            onChangePage={(patch: Partial<PageSpec>) => {
              commit('page');
              setTemplate((t) => ({ ...t, page: { ...t.page, ...patch } }));
            }}
            onChangePlaceholders={(list: Placeholder[]) => {
              commit('data');
              setTemplate((t) => ({ ...t, placeholders: list }));
            }}
            sheet={sheet}
            sequence={sequence}
            onGenerateBatch={generateBatch}
            onLoadBatchRecords={loadBatchRecords}
            onClearBatch={clearBatch}
            onBatchIndex={setBatchIndex}
            onChangeSheet={(patch) => setSheet((s) => ({ ...s, ...patch }))}
            onChangeSequence={(patch) => setSequence((s) => ({ ...s, ...patch }))}
          />
        </aside>
      </div>

      {/* Off-screen, all-pages render used only for printing (per data row). */}
      <PrintDocument template={template} datasets={printDatasets} blocks={blocksMap} sheet={sheet} />

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
