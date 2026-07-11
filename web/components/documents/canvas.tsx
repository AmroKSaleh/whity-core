'use client';

import { useEffect, useState } from 'react';
import type { DocElement, DocTemplate, PageSpec } from '@/lib/documents/types';
import { PX_PER_MM } from '@/lib/documents/types';
import { ElementContent } from './element-content';

interface HandleDef {
  name: string;
  left: string;
  top: string;
  cursor: string;
  l?: boolean;
  r?: boolean;
  t?: boolean;
  b?: boolean;
}

const HANDLES: HandleDef[] = [
  { name: 'nw', left: '0%', top: '0%', cursor: 'nwse-resize', l: true, t: true },
  { name: 'n', left: '50%', top: '0%', cursor: 'ns-resize', t: true },
  { name: 'ne', left: '100%', top: '0%', cursor: 'nesw-resize', r: true, t: true },
  { name: 'e', left: '100%', top: '50%', cursor: 'ew-resize', r: true },
  { name: 'se', left: '100%', top: '100%', cursor: 'nwse-resize', r: true, b: true },
  { name: 's', left: '50%', top: '100%', cursor: 'ns-resize', b: true },
  { name: 'sw', left: '0%', top: '100%', cursor: 'nesw-resize', l: true, b: true },
  { name: 'w', left: '0%', top: '50%', cursor: 'ew-resize', l: true },
];

const MIN_MM = 1;

interface Interaction {
  kind: 'move' | 'resize';
  handle?: HandleDef;
  id: string;
  startX: number;
  startY: number;
  orig: { x: number; y: number; w: number; h: number };
}

export function Canvas({
  template,
  data,
  selectedId,
  zoom,
  gridMm,
  preview,
  onSelect,
  onChange,
}: {
  template: DocTemplate;
  data: Record<string, string>;
  selectedId: string | null;
  zoom: number;
  gridMm: number;
  preview: boolean;
  onSelect: (id: string | null) => void;
  onChange: (id: string, patch: Partial<DocElement>) => void;
}) {
  const { page } = template;
  const [drag, setDrag] = useState<Interaction | null>(null);

  useEffect(() => {
    if (!drag) return;
    const mmPerPx = 1 / (PX_PER_MM * zoom);
    const snap = (v: number) => (gridMm > 0 ? Math.round(v / gridMm) * gridMm : v);

    const onMove = (e: PointerEvent) => {
      const dx = (e.clientX - drag.startX) * mmPerPx;
      const dy = (e.clientY - drag.startY) * mmPerPx;
      const o = drag.orig;
      if (drag.kind === 'move') {
        onChange(drag.id, { x: Math.max(0, snap(o.x + dx)), y: Math.max(0, snap(o.y + dy)) });
        return;
      }
      const hd = drag.handle!;
      let { x, y, w, h } = o;
      if (hd.r) w = o.w + dx;
      if (hd.l) w = o.w - dx;
      if (hd.b) h = o.h + dy;
      if (hd.t) h = o.h - dy;
      w = Math.max(MIN_MM, snap(w));
      h = Math.max(MIN_MM, snap(h));
      if (hd.l) x = Math.max(0, o.x + o.w - w);
      if (hd.t) y = Math.max(0, o.y + o.h - h);
      onChange(drag.id, { x, y, w, h });
    };
    const onUp = () => setDrag(null);
    window.addEventListener('pointermove', onMove);
    window.addEventListener('pointerup', onUp);
    return () => {
      window.removeEventListener('pointermove', onMove);
      window.removeEventListener('pointerup', onUp);
    };
  }, [drag, zoom, gridMm, onChange]);

  const start = (e: React.PointerEvent, kind: 'move' | 'resize', el: DocElement, handle?: HandleDef) => {
    if (preview) return;
    e.stopPropagation();
    onSelect(el.id);
    // Locked: selectable (to inspect/unlock) but not draggable/resizable.
    if (el.locked) return;
    e.preventDefault();
    setDrag({ kind, handle, id: el.id, startX: e.clientX, startY: e.clientY, orig: { x: el.x, y: el.y, w: el.w, h: el.h } });
  };

  const ordered = [...template.elements].sort((a, b) => a.z - b.z);

  return (
    <div
      className="mx-auto"
      style={{ width: `calc(${page.widthMm}mm * ${zoom})`, height: `calc(${page.heightMm}mm * ${zoom})` }}
    >
      <div
        id="doc-print-root"
        data-testid="doc-page"
        onPointerDown={() => !preview && onSelect(null)}
        style={{
          position: 'relative',
          width: `${page.widthMm}mm`,
          height: `${page.heightMm}mm`,
          background: page.background,
          transform: `scale(${zoom})`,
          transformOrigin: 'top left',
          boxShadow: '0 1px 8px rgba(0,0,0,0.15)',
          overflow: 'hidden',
        }}
      >
        <MarginGuide page={page} preview={preview} />
        {ordered.map((el) => {
          // Hidden elements are omitted from Preview/print, shown dimmed while editing.
          if (preview && el.hidden) return null;
          const selected = el.id === selectedId && !preview;
          return (
            <div
              key={el.id}
              data-testid={`doc-el-${el.id}`}
              onPointerDown={(e) => start(e, 'move', el)}
              style={{
                position: 'absolute',
                left: `${el.x}mm`,
                top: `${el.y}mm`,
                width: `${el.w}mm`,
                height: `${el.h}mm`,
                transform: el.rotation ? `rotate(${el.rotation}deg)` : undefined,
                zIndex: el.z,
                cursor: preview || el.locked ? 'default' : 'move',
                opacity: el.hidden ? 0.4 : undefined,
                outline: selected ? '1px solid var(--color-primary)' : undefined,
              }}
            >
              <ElementContent el={el} data={data} preview={preview} />
              {selected &&
                !el.locked &&
                HANDLES.map((hd) => (
                  <span
                    key={hd.name}
                    onPointerDown={(e) => start(e, 'resize', el, hd)}
                    style={{
                      position: 'absolute',
                      left: hd.left,
                      top: hd.top,
                      width: '2mm',
                      height: '2mm',
                      transform: 'translate(-50%, -50%)',
                      background: 'var(--color-primary)',
                      border: '0.3mm solid #fff',
                      cursor: hd.cursor,
                      zIndex: 9999,
                    }}
                  />
                ))}
            </div>
          );
        })}
      </div>
    </div>
  );
}

function MarginGuide({ page, preview }: { page: PageSpec; preview: boolean }) {
  if (preview || page.marginMm <= 0) return null;
  return (
    <div
      aria-hidden
      style={{
        position: 'absolute',
        left: `${page.marginMm}mm`,
        top: `${page.marginMm}mm`,
        right: `${page.marginMm}mm`,
        bottom: `${page.marginMm}mm`,
        border: '0.2mm dashed rgba(79,70,229,0.4)',
        pointerEvents: 'none',
      }}
    />
  );
}
