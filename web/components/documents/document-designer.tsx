'use client';

import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import type { DocElement, DocTemplate, ElementType, PageSpec, Placeholder } from '@/lib/documents/types';
import {
  blankTemplate,
} from '@/lib/documents/presets';
import {
  deleteSaved,
  exportTemplateJson,
  isDocTemplate,
  listSaved,
  newElement,
  sampleDataOf,
  saveTemplate,
  type SavedTemplate,
} from '@/lib/documents/storage';
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

const SELECT_CLASS =
  'h-7 rounded-md border border-input bg-input/20 px-2 text-sm outline-none focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/30';

export function DocumentDesigner() {
  const { addToast } = useToast();
  const [template, setTemplate] = useState<DocTemplate>(() => blankTemplate());
  const [selectedId, setSelectedId] = useState<string | null>(null);
  const [preview, setPreview] = useState(false);
  const [zoom, setZoom] = useState(1);
  const [snap, setSnap] = useState(true);
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
  const clipboardRef = useRef<DocElement | null>(null);
  const pasteSeq = useRef(0);
  const [hasClipboard, setHasClipboard] = useState(false);

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
  const kbRef = useRef({ selectedId, preview, template, past, future });
  useEffect(() => {
    kbRef.current = { selectedId, preview, template, past, future };
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
    setSelectedId(null);
    historyRef.current.lastLabel = '';
  }, []);

  const redo = useCallback(() => {
    const { past: p, future: f, template: cur } = kbRef.current;
    if (f.length === 0) return;
    setFuture(f.slice(1));
    setPast([...p, cur]);
    setTemplate(f[0]);
    setSelectedId(null);
    historyRef.current.lastLabel = '';
  }, []);

  // Append a clone of `src` to the template with a fresh id, nudged +3mm and
  // raised to the top; selects it. Shared by duplicate and paste.
  const appendClone = useCallback((src: DocElement) => {
    setTemplate((t) => {
      const maxZ = t.elements.reduce((m, e) => Math.max(m, e.z), 0);
      const clone = {
        ...src,
        id: `${src.type}-${Date.now()}-${(pasteSeq.current += 1)}`,
        x: src.x + 3,
        y: src.y + 3,
        z: maxZ + 1,
        locked: false,
        hidden: false,
      } as DocElement;
      setSelectedId(clone.id);
      return { ...t, elements: [...t.elements, clone] };
    });
  }, []);

  const copySelected = useCallback(() => {
    const { selectedId: sid, template: tpl } = kbRef.current;
    const el = tpl.elements.find((x) => x.id === sid);
    if (!el) return;
    clipboardRef.current = el;
    setHasClipboard(true);
  }, []);

  const cutSelected = useCallback(() => {
    const { selectedId: sid, template: tpl } = kbRef.current;
    const el = tpl.elements.find((x) => x.id === sid);
    if (!el) return;
    clipboardRef.current = el;
    setHasClipboard(true);
    commit('cut');
    setTemplate((t) => ({ ...t, elements: t.elements.filter((x) => x.id !== sid) }));
    setSelectedId(null);
  }, [commit]);

  const pasteClipboard = useCallback(() => {
    const src = clipboardRef.current;
    if (!src) return;
    commit('paste');
    appendClone(src);
  }, [commit, appendClone]);

  const duplicateSelected = useCallback(() => {
    const { selectedId: sid, template: tpl } = kbRef.current;
    const src = tpl.elements.find((x) => x.id === sid);
    if (!src) return;
    commit('duplicate');
    appendClone(src);
  }, [commit, appendClone]);

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
      const { selectedId: sid, preview: pv, template: tpl } = kbRef.current;
      const selEl = tpl.elements.find((x) => x.id === sid);
      if (pv || !selEl) return;
      if (e.key === 'Escape') {
        setSelectedId(null);
        return;
      }
      // Locked elements ignore delete / nudge (unlock via the layers panel).
      if (selEl.locked) return;
      if (e.key === 'Delete' || e.key === 'Backspace') {
        e.preventDefault();
        commit('delete');
        setTemplate((t) => ({ ...t, elements: t.elements.filter((x) => x.id !== sid) }));
        setSelectedId(null);
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
        setTemplate((t) => ({
          ...t,
          elements: t.elements.map((x) => (x.id === sid ? { ...x, x: Math.max(0, x.x + d[0]), y: Math.max(0, x.y + d[1]) } : x)),
        }));
      }
    };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [undo, redo, commit, copySelected, cutSelected, pasteClipboard, duplicateSelected]);

  const data = useMemo(() => sampleDataOf(template), [template]);
  const selected = template.elements.find((e) => e.id === selectedId) ?? null;

  const patchElement = (id: string, patch: Partial<DocElement>) => {
    // Label by field set so a continuous drag / same-field typing coalesces.
    commit(`patch:${Object.keys(patch).sort().join(',')}`);
    setTemplate((t) => ({
      ...t,
      elements: t.elements.map((e) => (e.id === id ? ({ ...e, ...patch } as DocElement) : e)),
    }));
  };

  const addElement = (type: ElementType) => {
    commit('add');
    setTemplate((t) => {
      const el = newElement(type, t.elements);
      setSelectedId(el.id);
      return { ...t, elements: [...t.elements, el] };
    });
  };

  const deleteElement = (id: string) => {
    commit('delete');
    setTemplate((t) => ({ ...t, elements: t.elements.filter((e) => e.id !== id) }));
  };

  const alignSelected = (kind: 'left' | 'hcenter' | 'right' | 'top' | 'vmiddle' | 'bottom') => {
    if (!selected || selected.locked) return;
    const { widthMm: W, heightMm: H } = template.page;
    const patch: Partial<DocElement> =
      kind === 'left'
        ? { x: 0 }
        : kind === 'hcenter'
          ? { x: Math.max(0, (W - selected.w) / 2) }
          : kind === 'right'
            ? { x: Math.max(0, W - selected.w) }
            : kind === 'top'
              ? { y: 0 }
              : kind === 'vmiddle'
                ? { y: Math.max(0, (H - selected.h) / 2) }
                : { y: Math.max(0, H - selected.h) };
    patchElement(selected.id, patch);
  };

  const toggleLock = (id: string) => {
    commit('lock');
    setTemplate((t) => ({
      ...t,
      elements: t.elements.map((e) => (e.id === id ? ({ ...e, locked: !e.locked } as DocElement) : e)),
    }));
  };

  const toggleHidden = (id: string) => {
    commit('hide');
    setTemplate((t) => ({
      ...t,
      elements: t.elements.map((e) => (e.id === id ? ({ ...e, hidden: !e.hidden } as DocElement) : e)),
    }));
  };

  const reorder = (id: string, dir: 'up' | 'down') => {
    commit('reorder');
    setTemplate((t) => {
      const zs = t.elements.map((e) => e.z);
      const target = dir === 'up' ? Math.max(...zs) + 1 : Math.min(...zs) - 1;
      return { ...t, elements: t.elements.map((e) => (e.id === id ? { ...e, z: target } : e)) };
    });
  };

  const doSave = () => {
    const id = saveTemplate(template, currentId ?? undefined);
    setCurrentId(id);
    setSaved(listSaved());
    addToast('Template saved.', 'success');
  };

  const doLoad = (id: string) => {
    const entry = listSaved().find((s) => s.id === id);
    if (!entry) return;
    setTemplate(entry.data);
    setCurrentId(entry.id);
    setSelectedId(null);
    resetHistory();
    addToast(`Loaded “${entry.name}”.`, 'info');
  };

  const doNew = () => {
    setTemplate(blankTemplate());
    setCurrentId(null);
    setSelectedId(null);
    resetHistory();
  };

  const onImportFile = async (file: File) => {
    try {
      const parsed: unknown = JSON.parse(await file.text());
      if (!isDocTemplate(parsed)) {
        addToast('That file is not a valid template.', 'error');
        return;
      }
      setTemplate(parsed);
      setCurrentId(null);
      setSelectedId(null);
      resetHistory();
      addToast('Template imported.', 'success');
    } catch {
      addToast('Could not read that file.', 'error');
    }
  };

  const doPrint = () => {
    setPreview(true);
    // Let the resolved (preview) render commit before invoking print.
    requestAnimationFrame(() => requestAnimationFrame(() => window.print()));
  };

  return (
    <div className="flex flex-col gap-3" data-testid="document-designer">
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
        <Button variant="outline" size="sm" className="gap-1" onClick={() => exportTemplateJson(template)}>
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

        <span className="ml-auto flex items-center gap-2">
          <label className="flex items-center gap-1.5 text-xs text-muted-foreground">
            Snap <Switch checked={snap} onCheckedChange={setSnap} />
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

      {/* Editor body */}
      <div className="grid h-[74vh] grid-cols-[13rem_1fr_18rem] gap-3">
        <aside className="overflow-hidden rounded-lg border border-border bg-card p-3">
          <Palette
            elements={template.elements}
            selectedId={selectedId}
            onAdd={addElement}
            onSelect={setSelectedId}
            onReorder={reorder}
            onToggleLock={toggleLock}
            onToggleHidden={toggleHidden}
            onDelete={(id) => {
              deleteElement(id);
              if (selectedId === id) setSelectedId(null);
            }}
          />
        </aside>

        <main className="overflow-auto rounded-lg border border-border bg-muted/30 p-6">
          <Canvas
            template={template}
            data={data}
            selectedId={selectedId}
            zoom={zoom}
            gridMm={snap ? 1 : 0}
            preview={preview}
            onSelect={setSelectedId}
            onChange={patchElement}
          />
        </main>

        <aside className="overflow-hidden rounded-lg border border-border bg-card p-3">
          {selected && (
            <div className="mb-2 space-y-1.5" data-testid="doc-selected-actions">
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
              <p className="text-[10px] leading-tight text-muted-foreground">
                Tip: ⌘/Ctrl+C/X/V copy/cut/paste, ⌘/Ctrl+D duplicate, arrows nudge
                (Shift = 5mm), Delete removes, Esc deselects.
              </p>
            </div>
          )}
          <Inspector
            template={template}
            selected={selected}
            onChangeSelected={(patch) => selectedId && patchElement(selectedId, patch)}
            onChangePage={(patch: Partial<PageSpec>) => {
              commit('page');
              setTemplate((t) => ({ ...t, page: { ...t.page, ...patch } }));
            }}
            onChangePlaceholders={(list: Placeholder[]) => {
              commit('data');
              setTemplate((t) => ({ ...t, placeholders: list }));
            }}
          />
        </aside>
      </div>

      {/* Print stylesheet: isolate the page and set the physical @page size.
          Rendered as a text child (not innerHTML); the interpolated dims are
          plain numbers from state. */}
      <style>{`@media print {
        body * { visibility: hidden !important; }
        #doc-print-root, #doc-print-root * { visibility: visible !important; }
        #doc-print-root { position: fixed !important; left: 0; top: 0; transform: none !important; box-shadow: none !important; }
        @page { size: ${template.page.widthMm}mm ${template.page.heightMm}mm; margin: 0; }
      }`}</style>
    </div>
  );
}
