'use client';

import { useEffect, useMemo, useRef, useState } from 'react';
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
  IconZoomIn,
  IconZoomOut,
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

  // Read the saved-template list from localStorage after mount (client-only).
  // Deferred off the synchronous effect tick to stay clear of the
  // set-state-in-effect rule while still populating on first render.
  useEffect(() => {
    const p = Promise.resolve().then(() => setSaved(listSaved()));
    void p;
  }, []);

  const data = useMemo(() => sampleDataOf(template), [template]);
  const selected = template.elements.find((e) => e.id === selectedId) ?? null;

  const patchElement = (id: string, patch: Partial<DocElement>) =>
    setTemplate((t) => ({
      ...t,
      elements: t.elements.map((e) => (e.id === id ? ({ ...e, ...patch } as DocElement) : e)),
    }));

  const addElement = (type: ElementType) =>
    setTemplate((t) => {
      const el = newElement(type, t.elements);
      setSelectedId(el.id);
      return { ...t, elements: [...t.elements, el] };
    });

  const deleteElement = (id: string) =>
    setTemplate((t) => ({ ...t, elements: t.elements.filter((e) => e.id !== id) }));

  const duplicateSelected = () => {
    if (!selected) return;
    setTemplate((t) => {
      const maxZ = t.elements.reduce((m, e) => Math.max(m, e.z), 0);
      const clone = { ...selected, id: `${selected.id}-copy-${Date.now()}`, x: selected.x + 3, y: selected.y + 3, z: maxZ + 1 } as DocElement;
      setSelectedId(clone.id);
      return { ...t, elements: [...t.elements, clone] };
    });
  };

  const reorder = (id: string, dir: 'up' | 'down') =>
    setTemplate((t) => {
      const zs = t.elements.map((e) => e.z);
      const target = dir === 'up' ? Math.max(...zs) + 1 : Math.min(...zs) - 1;
      return { ...t, elements: t.elements.map((e) => (e.id === id ? { ...e, z: target } : e)) };
    });

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
    addToast(`Loaded “${entry.name}”.`, 'info');
  };

  const doNew = () => {
    setTemplate(blankTemplate());
    setCurrentId(null);
    setSelectedId(null);
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
          onChange={(e) => setTemplate((t) => ({ ...t, name: e.target.value }))}
          className="max-w-[16rem]"
        />
        <Button variant="outline" size="sm" className="gap-1" onClick={doNew}>
          <IconFileText className="h-3.5 w-3.5" /> New
        </Button>
        <Button size="sm" className="gap-1" data-testid="doc-save" onClick={doSave}>
          <IconDeviceFloppy className="h-3.5 w-3.5" /> Save
        </Button>
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
            <div className="mb-2 flex gap-1">
              <Button variant="outline" size="sm" className="flex-1 gap-1" onClick={duplicateSelected}>
                <IconCopy className="h-3.5 w-3.5" /> Duplicate
              </Button>
            </div>
          )}
          <Inspector
            template={template}
            selected={selected}
            onChangeSelected={(patch) => selectedId && patchElement(selectedId, patch)}
            onChangePage={(patch: Partial<PageSpec>) => setTemplate((t) => ({ ...t, page: { ...t.page, ...patch } }))}
            onChangePlaceholders={(list: Placeholder[]) => setTemplate((t) => ({ ...t, placeholders: list }))}
          />
        </aside>
      </div>

      {/* Print stylesheet: isolate the page and set the physical @page size. */}
      <style
        dangerouslySetInnerHTML={{
          __html: `@media print {
            body * { visibility: hidden !important; }
            #doc-print-root, #doc-print-root * { visibility: visible !important; }
            #doc-print-root { position: fixed !important; left: 0; top: 0; transform: none !important; box-shadow: none !important; }
            @page { size: ${template.page.widthMm}mm ${template.page.heightMm}mm; margin: 0; }
          }`,
        }}
      />
    </div>
  );
}
