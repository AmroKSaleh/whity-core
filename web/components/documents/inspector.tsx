'use client';

import { useState } from 'react';
import type { DocElement, DocTemplate, PageSpec, Placeholder, TextStyle } from '@/lib/documents/types';
import { BARCODE_SYMBOLOGIES } from '@/lib/documents/types';
import { PAGE_PRESETS } from '@/lib/documents/presets';
import { Input } from '@amroksaleh/ui/input';
import { Switch } from '@amroksaleh/ui/switch';
import { Button } from '@amroksaleh/ui/button';
import { IconPlus, IconTrash } from '@tabler/icons-react';

const SELECT_CLASS =
  'h-7 w-full min-w-0 rounded-md border border-input bg-input/20 px-2 text-sm outline-none focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/30';

type Tab = 'element' | 'page' | 'data';

export function Inspector({
  template,
  selected,
  onChangeSelected,
  onChangePage,
  onChangePlaceholders,
}: {
  template: DocTemplate;
  selected: DocElement | null;
  onChangeSelected: (patch: Partial<DocElement>) => void;
  onChangePage: (patch: Partial<PageSpec>) => void;
  onChangePlaceholders: (list: Placeholder[]) => void;
}) {
  const [tab, setTab] = useState<Tab>('element');

  return (
    <div className="flex h-full flex-col">
      <div className="mb-3 flex gap-1 rounded-md bg-muted/40 p-0.5 text-xs">
        {(['element', 'page', 'data'] as Tab[]).map((t) => (
          <button
            key={t}
            type="button"
            data-testid={`doc-tab-${t}`}
            onClick={() => setTab(t)}
            className={`flex-1 rounded px-2 py-1 capitalize ${tab === t ? 'bg-card font-medium text-foreground shadow-sm' : 'text-muted-foreground'}`}
          >
            {t}
          </button>
        ))}
      </div>

      <div className="min-h-0 flex-1 space-y-3 overflow-y-auto pr-1">
        {tab === 'element' && <ElementTab selected={selected} placeholders={template.placeholders} onChange={onChangeSelected} />}
        {tab === 'page' && <PageTab page={template.page} onChange={onChangePage} />}
        {tab === 'data' && <DataTab placeholders={template.placeholders} onChange={onChangePlaceholders} />}
      </div>
    </div>
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
      <Num label="Rotation (°)" value={el.rotation} onChange={(v) => onChange({ rotation: v })} />

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

// ── small field helpers ─────────────────────────────────────────────────────

function Field({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <label className="block space-y-1">
      <span className="text-xs font-medium text-muted-foreground">{label}</span>
      {children}
    </label>
  );
}

function Num({ label, value, onChange, step = 1 }: { label: string; value: number; onChange: (v: number) => void; step?: number }) {
  return (
    <Field label={label}>
      <Input
        type="number"
        step={step}
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
