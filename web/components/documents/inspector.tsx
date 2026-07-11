'use client';

import { useState } from 'react';
import type { DocElement, DocTemplate, PageSpec, Placeholder, TextStyle } from '@/lib/documents/types';
import { BARCODE_SYMBOLOGIES } from '@/lib/documents/types';
import { generateSequence, type SequenceConfig } from '@/lib/documents/batch';
import { parseDelimited, parseJsonRows } from '@/lib/documents/csv';
import { SHEET_PRESETS, cellsPerSheet, sheetCount, type SheetSpec } from '@/lib/documents/sheet';
import { PAGE_PRESETS } from '@/lib/documents/presets';
import { Input } from '@amroksaleh/ui/input';
import { Switch } from '@amroksaleh/ui/switch';
import { Button } from '@amroksaleh/ui/button';
import { IconPlus, IconTrash, IconChevronLeft, IconChevronRight } from '@tabler/icons-react';

const SELECT_CLASS =
  'h-7 w-full min-w-0 rounded-md border border-input bg-input/20 px-2 text-sm outline-none focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/30';

type Tab = 'element' | 'page' | 'data' | 'batch' | 'sheet';

export interface BatchState {
  active: boolean;
  index: number;
  total: number;
}

export function Inspector({
  template,
  selected,
  batch,
  sheet,
  sequence,
  onChangeSelected,
  onChangePage,
  onChangePlaceholders,
  onGenerateBatch,
  onLoadBatchRecords,
  onClearBatch,
  onBatchIndex,
  onChangeSheet,
  onChangeSequence,
}: {
  template: DocTemplate;
  selected: DocElement | null;
  batch: BatchState;
  sheet: SheetSpec;
  sequence: SequenceConfig;
  onChangeSelected: (patch: Partial<DocElement>) => void;
  onChangePage: (patch: Partial<PageSpec>) => void;
  onChangePlaceholders: (list: Placeholder[]) => void;
  onGenerateBatch: (cfg: SequenceConfig) => void;
  onLoadBatchRecords: (records: Record<string, string>[]) => void;
  onClearBatch: () => void;
  onBatchIndex: (i: number) => void;
  onChangeSheet: (patch: Partial<SheetSpec>) => void;
  onChangeSequence: (patch: Partial<SequenceConfig>) => void;
}) {
  const [tab, setTab] = useState<Tab>('element');
  const unitsTotal = (batch.active ? batch.total : 1) * template.pages.length;

  return (
    <div className="flex h-full flex-col">
      <div className="mb-3 flex gap-0.5 rounded-md bg-muted/40 p-0.5 text-[11px]">
        {(['element', 'page', 'data', 'batch', 'sheet'] as Tab[]).map((t) => (
          <button
            key={t}
            type="button"
            data-testid={`doc-tab-${t}`}
            onClick={() => setTab(t)}
            className={`flex-1 rounded px-1.5 py-1 capitalize ${tab === t ? 'bg-card font-medium text-foreground shadow-sm' : 'text-muted-foreground'}`}
          >
            {t}
          </button>
        ))}
      </div>

      <div className="min-h-0 flex-1 space-y-3 overflow-y-auto pr-1">
        {tab === 'element' && <ElementTab selected={selected} placeholders={template.placeholders} onChange={onChangeSelected} />}
        {tab === 'page' && <PageTab page={template.page} onChange={onChangePage} />}
        {tab === 'data' && <DataTab placeholders={template.placeholders} onChange={onChangePlaceholders} />}
        {tab === 'batch' && (
          <BatchTab
            placeholders={template.placeholders}
            batch={batch}
            sequence={sequence}
            onGenerate={onGenerateBatch}
            onLoadRecords={onLoadBatchRecords}
            onClear={onClearBatch}
            onIndex={onBatchIndex}
            onChangeSequence={onChangeSequence}
          />
        )}
        {tab === 'sheet' && <SheetTab sheet={sheet} unitsTotal={unitsTotal} onChange={onChangeSheet} />}
      </div>
    </div>
  );
}

function SheetTab({
  sheet,
  unitsTotal,
  onChange,
}: {
  sheet: SheetSpec;
  unitsTotal: number;
  onChange: (patch: Partial<SheetSpec>) => void;
}) {
  const per = cellsPerSheet(sheet);
  const sheets = sheetCount(unitsTotal, sheet);
  return (
    <>
      <p className="text-xs text-muted-foreground">
        Tile many labels onto one physical sheet (e.g. an A4 sheet of address labels). The label size is the page
        size; batch rows flow into cells across sheets.
      </p>
      <div className="flex items-center justify-between gap-2">
        <span className="text-xs font-medium text-muted-foreground">Print as label sheet</span>
        <Switch data-testid="doc-sheet-enable" checked={sheet.enabled} onCheckedChange={(v) => onChange({ enabled: v })} />
      </div>
      {sheet.enabled && (
        <>
          <Field label="Preset">
            <select
              className={SELECT_CLASS}
              data-testid="doc-sheet-preset"
              value=""
              onChange={(e) => {
                const p = SHEET_PRESETS.find((x) => x.id === e.target.value);
                if (p) onChange(p.spec);
              }}
            >
              <option value="">Choose a preset…</option>
              {SHEET_PRESETS.map((p) => (
                <option key={p.id} value={p.id}>
                  {p.label}
                </option>
              ))}
            </select>
          </Field>
          <div className="grid grid-cols-2 gap-2">
            <Num label="Columns" value={sheet.cols} onChange={(v) => onChange({ cols: Math.max(1, Math.round(v)) })} testId="doc-sheet-cols" />
            <Num label="Rows" value={sheet.rows} onChange={(v) => onChange({ rows: Math.max(1, Math.round(v)) })} testId="doc-sheet-rows" />
          </div>
          <div className="grid grid-cols-2 gap-2">
            <Num label="Sheet width (mm)" value={sheet.sheetWidthMm} onChange={(v) => onChange({ sheetWidthMm: v })} />
            <Num label="Sheet height (mm)" value={sheet.sheetHeightMm} onChange={(v) => onChange({ sheetHeightMm: v })} />
          </div>
          <div className="grid grid-cols-2 gap-2">
            <Num label="Margin X (mm)" value={sheet.marginXMm} step={0.5} onChange={(v) => onChange({ marginXMm: v })} />
            <Num label="Margin Y (mm)" value={sheet.marginYMm} step={0.5} onChange={(v) => onChange({ marginYMm: v })} />
          </div>
          <div className="grid grid-cols-2 gap-2">
            <Num label="Gutter X (mm)" value={sheet.gutterXMm} step={0.5} onChange={(v) => onChange({ gutterXMm: v })} />
            <Num label="Gutter Y (mm)" value={sheet.gutterYMm} step={0.5} onChange={(v) => onChange({ gutterYMm: v })} />
          </div>
          <p className="text-[10px] text-muted-foreground" data-testid="doc-sheet-summary">
            {per} labels per sheet · {sheets} sheet{sheets === 1 ? '' : 's'} for {unitsTotal} label
            {unitsTotal === 1 ? '' : 's'}
          </p>
        </>
      )}
    </>
  );
}

function ElementTab({
  selected,
  placeholders,
  onChange,
}: {
  selected: DocElement | null;
  placeholders: Placeholder[];
  onChange: (patch: Partial<DocElement>) => void;
}) {
  if (!selected) {
    return <p className="text-xs text-muted-foreground">Select an element on the canvas to edit it.</p>;
  }
  const el = selected;
  const bindingOptions = (
    <>
      <option value="">(no binding)</option>
      {placeholders.map((p) => (
        <option key={p.key} value={p.key}>
          {p.label} ({`{{${p.key}}}`})
        </option>
      ))}
    </>
  );

  return (
    <>
      <div className="grid grid-cols-4 gap-2">
        <Num label="X" value={el.x} onChange={(v) => onChange({ x: v })} />
        <Num label="Y" value={el.y} onChange={(v) => onChange({ y: v })} />
        <Num label="W" value={el.w} onChange={(v) => onChange({ w: v })} />
        <Num label="H" value={el.h} onChange={(v) => onChange({ h: v })} />
      </div>
      <div className="grid grid-cols-2 gap-2">
        <Num label="Rotation (°)" value={el.rotation} onChange={(v) => onChange({ rotation: v })} />
        <Num
          label="Opacity (%)"
          testId="doc-opacity"
          value={Math.round((el.opacity ?? 1) * 100)}
          step={5}
          onChange={(v) => onChange({ opacity: Math.min(1, Math.max(0, v / 100)) })}
        />
      </div>

      {(el.type === 'text' || el.type === 'dynamicText') && (
        <>
          <Field label={el.type === 'text' ? 'Text' : 'Template (use {{placeholder}})'}>
            <textarea
              data-testid="doc-text-value"
              className={`${SELECT_CLASS} h-16 py-1`}
              value={el.type === 'text' ? el.text : el.template}
              onChange={(e) => onChange(el.type === 'text' ? { text: e.target.value } : { template: e.target.value })}
            />
          </Field>
          {el.type === 'dynamicText' && placeholders.length > 0 && (
            <Field label="Insert placeholder">
              <select
                className={SELECT_CLASS}
                value=""
                onChange={(e) => {
                  if (e.target.value) onChange({ template: `${el.template}{{${e.target.value}}}` });
                }}
              >
                <option value="">Append a field…</option>
                {placeholders.map((p) => (
                  <option key={p.key} value={p.key}>
                    {p.label}
                  </option>
                ))}
              </select>
            </Field>
          )}
          <TextStyleFields style={el.style} onChange={(s) => onChange({ style: { ...el.style, ...s } })} />
        </>
      )}

      {el.type === 'image' && (
        <>
          <Field label="Image URL (fallback)">
            <Input value={el.src} onChange={(e) => onChange({ src: e.target.value })} placeholder="https://…" />
          </Field>
          <Field label="Bind to placeholder">
            <select className={SELECT_CLASS} value={el.binding ?? ''} onChange={(e) => onChange({ binding: e.target.value || undefined })}>
              {bindingOptions}
            </select>
          </Field>
          <Field label="Fit">
            <select className={SELECT_CLASS} value={el.fit} onChange={(e) => onChange({ fit: e.target.value as 'contain' | 'cover' | 'fill' })}>
              <option value="contain">Contain</option>
              <option value="cover">Cover</option>
              <option value="fill">Fill</option>
            </select>
          </Field>
        </>
      )}

      {el.type === 'barcode' && (
        <>
          <Field label="Symbology">
            <select className={SELECT_CLASS} value={el.symbology} onChange={(e) => onChange({ symbology: e.target.value })}>
              {BARCODE_SYMBOLOGIES.map((s) => (
                <option key={s.id} value={s.id}>
                  {s.label}
                </option>
              ))}
            </select>
          </Field>
          <Field label="Value (supports {{placeholder}})">
            <Input data-testid="doc-barcode-value" value={el.value} onChange={(e) => onChange({ value: e.target.value })} />
          </Field>
          <Field label="Bind to placeholder">
            <select className={SELECT_CLASS} value={el.binding ?? ''} onChange={(e) => onChange({ binding: e.target.value || undefined })}>
              {bindingOptions}
            </select>
          </Field>
          <ToggleRow label="Show human-readable text" checked={el.showText} onChange={(v) => onChange({ showText: v })} />
        </>
      )}

      {el.type === 'qr' && (
        <>
          <Field label="Value (supports {{placeholder}})">
            <Input data-testid="doc-qr-value" value={el.value} onChange={(e) => onChange({ value: e.target.value })} />
          </Field>
          <Field label="Bind to placeholder">
            <select className={SELECT_CLASS} value={el.binding ?? ''} onChange={(e) => onChange({ binding: e.target.value || undefined })}>
              {bindingOptions}
            </select>
          </Field>
        </>
      )}

      {el.type === 'rect' && (
        <>
          <Color label="Fill" value={el.fill} onChange={(v) => onChange({ fill: v })} />
          <Color label="Stroke" value={el.stroke} onChange={(v) => onChange({ stroke: v })} />
          <Num label="Stroke width (mm)" value={el.strokeWidth} step={0.1} onChange={(v) => onChange({ strokeWidth: v })} />
          <Num label="Corner radius (mm)" value={el.radius} step={0.5} onChange={(v) => onChange({ radius: v })} />
        </>
      )}

      {el.type === 'line' && (
        <>
          <Color label="Color" value={el.stroke} onChange={(v) => onChange({ stroke: v })} />
          <Num label="Thickness (mm)" value={el.h} step={0.1} onChange={(v) => onChange({ h: Math.max(0.1, v) })} />
        </>
      )}
    </>
  );
}

function TextStyleFields({ style, onChange }: { style: TextStyle; onChange: (patch: Partial<TextStyle>) => void }) {
  return (
    <>
      <div className="grid grid-cols-2 gap-2">
        <Num label="Font size (pt)" value={style.fontSize} onChange={(v) => onChange({ fontSize: v })} />
        <Color label="Color" value={style.color} onChange={(v) => onChange({ color: v })} />
      </div>
      <div className="grid grid-cols-2 gap-2">
        <Field label="Weight">
          <select className={SELECT_CLASS} value={style.fontWeight} onChange={(e) => onChange({ fontWeight: e.target.value as 'normal' | 'bold' })}>
            <option value="normal">Normal</option>
            <option value="bold">Bold</option>
          </select>
        </Field>
        <Field label="Style">
          <select className={SELECT_CLASS} value={style.fontStyle} onChange={(e) => onChange({ fontStyle: e.target.value as 'normal' | 'italic' })}>
            <option value="normal">Normal</option>
            <option value="italic">Italic</option>
          </select>
        </Field>
      </div>
      <div className="grid grid-cols-2 gap-2">
        <Field label="Align">
          <select className={SELECT_CLASS} value={style.align} onChange={(e) => onChange({ align: e.target.value as TextStyle['align'] })}>
            <option value="left">Left</option>
            <option value="center">Center</option>
            <option value="right">Right</option>
          </select>
        </Field>
        <Field label="Vertical">
          <select className={SELECT_CLASS} value={style.vAlign} onChange={(e) => onChange({ vAlign: e.target.value as TextStyle['vAlign'] })}>
            <option value="top">Top</option>
            <option value="middle">Middle</option>
            <option value="bottom">Bottom</option>
          </select>
        </Field>
      </div>
      <Field label="Text direction">
        <select
          className={SELECT_CLASS}
          data-testid="doc-text-direction"
          value={style.direction ?? 'auto'}
          onChange={(e) => onChange({ direction: e.target.value as TextStyle['direction'] })}
        >
          <option value="auto">Auto (Arabic / mixed)</option>
          <option value="ltr">Left-to-right</option>
          <option value="rtl">Right-to-left</option>
        </select>
      </Field>
    </>
  );
}

function PageTab({ page, onChange }: { page: PageSpec; onChange: (patch: Partial<PageSpec>) => void }) {
  const activePreset = PAGE_PRESETS.find(
    (p) => Math.abs(p.widthMm - page.widthMm) < 0.1 && Math.abs(p.heightMm - page.heightMm) < 0.1
  );
  return (
    <>
      <Field label="Size preset">
        <select
          className={SELECT_CLASS}
          value={activePreset?.id ?? ''}
          onChange={(e) => {
            const p = PAGE_PRESETS.find((x) => x.id === e.target.value);
            if (p) onChange({ widthMm: p.widthMm, heightMm: p.heightMm });
          }}
        >
          {!activePreset && <option value="">Custom</option>}
          {PAGE_PRESETS.map((p) => (
            <option key={p.id} value={p.id}>
              {p.label}
            </option>
          ))}
        </select>
      </Field>
      <div className="grid grid-cols-2 gap-2">
        <Num label="Width (mm)" value={page.widthMm} onChange={(v) => onChange({ widthMm: v })} />
        <Num label="Height (mm)" value={page.heightMm} onChange={(v) => onChange({ heightMm: v })} />
      </div>
      <Button
        variant="outline"
        size="sm"
        onClick={() => onChange({ widthMm: page.heightMm, heightMm: page.widthMm })}
      >
        Swap orientation
      </Button>
      <Num label="Margin guide (mm)" value={page.marginMm} step={0.5} onChange={(v) => onChange({ marginMm: v })} />
      <Color label="Background" value={page.background} onChange={(v) => onChange({ background: v })} />
    </>
  );
}

function DataTab({ placeholders, onChange }: { placeholders: Placeholder[]; onChange: (list: Placeholder[]) => void }) {
  const update = (i: number, patch: Partial<Placeholder>) =>
    onChange(placeholders.map((p, idx) => (idx === i ? { ...p, ...patch } : p)));
  return (
    <>
      <p className="text-xs text-muted-foreground">
        Placeholders bind to elements and drive the Preview. The <span className="font-medium">Sample</span> value is what
        Preview renders.
      </p>
      {placeholders.map((p, i) => (
        <div key={i} className="space-y-1 rounded-md border border-border p-2">
          <div className="grid grid-cols-2 gap-2">
            <Field label="Key">
              <Input value={p.key} onChange={(e) => update(i, { key: e.target.value.replace(/[^\w.-]/g, '') })} />
            </Field>
            <Field label="Label">
              <Input value={p.label} onChange={(e) => update(i, { label: e.target.value })} />
            </Field>
          </div>
          <Field label="Sample value">
            <div className="flex items-center gap-1">
              <Input value={p.sample} onChange={(e) => update(i, { sample: e.target.value })} />
              <button type="button" aria-label="Remove placeholder" onClick={() => onChange(placeholders.filter((_, idx) => idx !== i))}>
                <IconTrash className="h-4 w-4 text-destructive/80 hover:text-destructive" />
              </button>
            </div>
          </Field>
        </div>
      ))}
      <Button
        variant="outline"
        size="sm"
        className="gap-1"
        data-testid="doc-add-placeholder"
        onClick={() => onChange([...placeholders, { key: `field_${placeholders.length + 1}`, label: `Field ${placeholders.length + 1}`, sample: '' }])}
      >
        <IconPlus className="h-3.5 w-3.5" />
        Add placeholder
      </Button>
    </>
  );
}

type BatchMode = 'sequence' | 'csv' | 'paste';

function BatchTab({
  placeholders,
  batch,
  sequence,
  onGenerate,
  onLoadRecords,
  onClear,
  onIndex,
  onChangeSequence,
}: {
  placeholders: Placeholder[];
  batch: BatchState;
  sequence: SequenceConfig;
  onGenerate: (cfg: SequenceConfig) => void;
  onLoadRecords: (records: Record<string, string>[]) => void;
  onClear: () => void;
  onIndex: (i: number) => void;
  onChangeSequence: (patch: Partial<SequenceConfig>) => void;
}) {
  const [mode, setMode] = useState<BatchMode>('sequence');
  const [paste, setPaste] = useState('');
  const [parseError, setParseError] = useState('');

  // Fall back to the first placeholder until the operator picks one.
  const key = sequence.key || placeholders[0]?.key || '';
  const { prefix, start, count, step, padding, suffix } = sequence;
  const cfg: SequenceConfig = { key, prefix, start, count, step, padding, suffix };
  const canGenerate = key !== '' && count > 0;
  const sample = canGenerate ? generateSequence({ ...cfg, count: Math.min(count, 3) }) : [];

  const loadCsvFile = async (file: File) => {
    setParseError('');
    try {
      const { rows } = parseDelimited(await file.text());
      onLoadRecords(rows);
    } catch {
      setParseError('Could not read that file.');
    }
  };

  const loadPaste = () => {
    setParseError('');
    const text = paste.trim();
    if (text === '') return;
    try {
      // A leading [ or { is treated as JSON; otherwise delimited (CSV/TSV).
      const records = text.startsWith('[') || text.startsWith('{') ? parseJsonRows(text) : parseDelimited(text).rows;
      onLoadRecords(records);
    } catch {
      setParseError('Could not parse — expected CSV rows or a JSON array of objects.');
    }
  };

  return (
    <>
      <div className="flex gap-1 rounded-md bg-muted/40 p-0.5 text-xs">
        {(['sequence', 'csv', 'paste'] as BatchMode[]).map((m) => (
          <button
            key={m}
            type="button"
            data-testid={`doc-batch-mode-${m}`}
            onClick={() => setMode(m)}
            className={`flex-1 rounded px-2 py-1 capitalize ${mode === m ? 'bg-card font-medium text-foreground shadow-sm' : 'text-muted-foreground'}`}
          >
            {m === 'csv' ? 'CSV' : m}
          </button>
        ))}
      </div>

      {mode === 'sequence' && (
        <>
          <p className="text-xs text-muted-foreground">
            Generate a run of serial numbers (or any sequence) into a placeholder — one label per value.
          </p>
          <Field label="Fill placeholder">
            <select
              className={SELECT_CLASS}
              data-testid="doc-batch-key"
              value={key}
              onChange={(e) => onChangeSequence({ key: e.target.value })}
            >
              {placeholders.length === 0 && <option value="">(add a placeholder in the Data tab)</option>}
              {placeholders.map((p) => (
                <option key={p.key} value={p.key}>
                  {p.label} ({`{{${p.key}}}`})
                </option>
              ))}
            </select>
          </Field>
          <div className="grid grid-cols-2 gap-2">
            <Field label="Prefix">
              <Input data-testid="doc-batch-prefix" value={prefix} onChange={(e) => onChangeSequence({ prefix: e.target.value })} />
            </Field>
            <Field label="Suffix">
              <Input data-testid="doc-batch-suffix" value={suffix} onChange={(e) => onChangeSequence({ suffix: e.target.value })} />
            </Field>
          </div>
          <div className="grid grid-cols-2 gap-2">
            <Num label="Start" value={start} onChange={(v) => onChangeSequence({ start: v })} testId="doc-batch-start" />
            <Num label="Count" value={count} onChange={(v) => onChangeSequence({ count: v })} testId="doc-batch-count" />
          </div>
          <div className="grid grid-cols-2 gap-2">
            <Num label="Step" value={step} onChange={(v) => onChangeSequence({ step: v })} />
            <Num label="Zero-pad" value={padding} onChange={(v) => onChangeSequence({ padding: v })} />
          </div>
          {sample.length > 0 && (
            <p className="text-[10px] text-muted-foreground">
              Example: <span className="font-medium">{sample.join(', ')}{count > 3 ? ', …' : ''}</span>
            </p>
          )}
          <Button
            size="sm"
            className="w-full"
            data-testid="doc-batch-generate"
            disabled={!canGenerate}
            onClick={() => onGenerate(cfg)}
          >
            Generate rows
          </Button>
        </>
      )}

      {mode === 'csv' && (
        <>
          <p className="text-xs text-muted-foreground">
            Upload a CSV/TSV whose header row names placeholders (e.g. <span className="font-medium">serial,model</span>).
            Each data row becomes one label; columns fill matching <span className="font-mono">{'{{tokens}}'}</span>.
          </p>
          <input
            type="file"
            accept=".csv,.tsv,text/csv,text/tab-separated-values,text/plain"
            data-testid="doc-batch-csv-file"
            className="block w-full text-xs file:mr-2 file:rounded-md file:border file:border-input file:bg-input/20 file:px-2 file:py-1 file:text-xs"
            onChange={(e) => {
              const f = e.target.files?.[0];
              if (f) void loadCsvFile(f);
              e.target.value = '';
            }}
          />
        </>
      )}

      {mode === 'paste' && (
        <>
          <p className="text-xs text-muted-foreground">
            Paste CSV rows (with a header) or a JSON array of objects, then load them as rows.
          </p>
          <textarea
            data-testid="doc-batch-paste"
            className={`${SELECT_CLASS} h-28 py-1 font-mono`}
            placeholder={'serial,model\nSN-1,Widget\nSN-2,Gadget'}
            value={paste}
            onChange={(e) => setPaste(e.target.value)}
          />
          <Button size="sm" className="w-full" data-testid="doc-batch-load-paste" disabled={paste.trim() === ''} onClick={loadPaste}>
            Load rows
          </Button>
        </>
      )}

      {parseError !== '' && <p className="text-xs text-destructive" data-testid="doc-batch-error">{parseError}</p>}

      {batch.active && (
        <div className="space-y-1.5 rounded-md border border-primary/40 bg-primary/5 p-2" data-testid="doc-batch-active">
          <div className="flex items-center justify-between text-xs">
            <span className="font-medium text-primary">Batch active · {batch.total} rows</span>
            <button
              type="button"
              data-testid="doc-batch-clear"
              className="text-muted-foreground hover:text-foreground"
              onClick={onClear}
            >
              Clear
            </button>
          </div>
          <div className="flex items-center justify-between gap-1">
            <Button
              variant="outline"
              size="icon-sm"
              aria-label="Previous row"
              data-testid="doc-batch-prev"
              disabled={batch.index <= 0}
              onClick={() => onIndex(batch.index - 1)}
            >
              <IconChevronLeft className="h-4 w-4" />
            </Button>
            <span className="text-xs tabular-nums" data-testid="doc-batch-rowcount">
              Row {batch.index + 1} / {batch.total}
            </span>
            <Button
              variant="outline"
              size="icon-sm"
              aria-label="Next row"
              data-testid="doc-batch-next"
              disabled={batch.index >= batch.total - 1}
              onClick={() => onIndex(batch.index + 1)}
            >
              <IconChevronRight className="h-4 w-4" />
            </Button>
          </div>
          <p className="text-[10px] text-muted-foreground">Switch to Preview to see each row.</p>
        </div>
      )}
    </>
  );
}

// ── small field helpers ─────────────────────────────────────────────────────

function Field({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <label className="block space-y-1">
      <span className="text-xs font-medium text-muted-foreground">{label}</span>
      {children}
    </label>
  );
}

function Num({
  label,
  value,
  onChange,
  step = 1,
  testId,
}: {
  label: string;
  value: number;
  onChange: (v: number) => void;
  step?: number;
  testId?: string;
}) {
  return (
    <Field label={label}>
      <Input
        type="number"
        step={step}
        data-testid={testId}
        value={Number.isFinite(value) ? Math.round(value * 100) / 100 : 0}
        onChange={(e) => {
          const v = parseFloat(e.target.value);
          onChange(Number.isFinite(v) ? v : 0);
        }}
      />
    </Field>
  );
}

function Color({ label, value, onChange }: { label: string; value: string; onChange: (v: string) => void }) {
  return (
    <Field label={label}>
      <div className="flex items-center gap-1">
        <input type="color" value={toHex(value)} onChange={(e) => onChange(e.target.value)} className="h-7 w-8 shrink-0 rounded border border-input bg-transparent" />
        <Input value={value} onChange={(e) => onChange(e.target.value)} />
      </div>
    </Field>
  );
}

function ToggleRow({ label, checked, onChange }: { label: string; checked: boolean; onChange: (v: boolean) => void }) {
  return (
    <div className="flex items-center justify-between gap-2">
      <span className="text-xs font-medium text-muted-foreground">{label}</span>
      <Switch checked={checked} onCheckedChange={onChange} />
    </div>
  );
}

/** Coerce arbitrary CSS colors to a #rrggbb the native picker accepts. */
function toHex(value: string): string {
  return /^#[0-9a-fA-F]{6}$/.test(value) ? value : '#000000';
}
