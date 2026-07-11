'use client';

import { useEffect, useRef, useState } from 'react';
import type { DocElement, PageSpec } from '@/lib/documents/types';
import { PX_PER_MM } from '@/lib/documents/types';
import { snapMove } from '@/lib/documents/geometry';
import type { DocBlock } from '@/lib/documents/blocks';
import { ElementContent } from './element-content';
import { BlockInstanceContent } from './element-layer';

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
/** Alignment-guide snap tolerance, in millimetres (edge/centre to target). */
const SNAP_MM = 1.5;

interface Interaction {
  kind: 'move' | 'resize';
  handle?: HandleDef;
  id: string;
  startX: number;
  startY: number;
  orig: { x: number; y: number; w: number; h: number };
  /** For a group move: the original x/y of every dragged element. */
  group?: Array<{ id: string; x: number; y: number }>;
}

export function Canvas({
  elements,
  page,
  data,
  blocks,
  selectedIds,
  zoom,
  gridMm,
  showGrid,
  preview,
  onSelect,
  onChange,
  onChangeMany,
  onEditBlock,
}: {
  elements: DocElement[];
  page: PageSpec;
  data: Record<string, string>;
  blocks: Record<string, DocBlock>;
  selectedIds: string[];
  zoom: number;
  gridMm: number;
  showGrid: boolean;
  preview: boolean;
  onSelect: (id: string | null, additive?: boolean) => void;
  onChange: (id: string, patch: Partial<DocElement>) => void;
  onChangeMany: (updates: Array<{ id: string; patch: Partial<DocElement> }>) => void;
  onEditBlock: (blockId: string) => void;
}) {
  const [drag, setDrag] = useState<Interaction | null>(null);
  // Alignment guide lines to draw while dragging (x/y positions in mm).
  const [guides, setGuides] = useState<{ v: number[]; h: number[] }>({ v: [], h: [] });
  const selectedSet = new Set(selectedIds);

  // Live scene + change callbacks read by the drag listener via refs, so the
  // listener subscribes ONCE per drag instead of re-subscribing on every render
  // (which would drop fast pointer events in the unsubscribe gap).
  const sceneRef = useRef({ elements, page });
  const onChangeRef = useRef(onChange);
  const onChangeManyRef = useRef(onChangeMany);
  useEffect(() => {
    sceneRef.current = { elements, page };
    onChangeRef.current = onChange;
    onChangeManyRef.current = onChangeMany;
  });

  useEffect(() => {
    if (!drag) return;
    const mmPerPx = 1 / (PX_PER_MM * zoom);
    const snap = (v: number) => (gridMm > 0 ? Math.round(v / gridMm) * gridMm : v);

    const onMove = (e: PointerEvent) => {
      const dx = (e.clientX - drag.startX) * mmPerPx;
      const dy = (e.clientY - drag.startY) * mmPerPx;
      const o = drag.orig;
      if (drag.kind === 'move') {
        // Group move: shift every dragged element by the same delta (grid-snap
        // the delta; no alignment guides for groups to keep it predictable).
        if (drag.group && drag.group.length > 1) {
          const sdx = snap(dx);
          const sdy = snap(dy);
          onChangeManyRef.current(
            drag.group.map((g) => ({ id: g.id, patch: { x: Math.max(0, g.x + sdx), y: Math.max(0, g.y + sdy) } }))
          );
          return;
        }
        let nx = o.x + dx;
        let ny = o.y + dy;
        // Alignment snapping (tied to the Snap toggle): align edges/centre to
        // the page and other elements; grid-snap any axis that doesn't align.
        if (gridMm > 0) {
          const { elements, page: pg } = sceneRef.current;
          const others = elements
            .filter((el) => el.id !== drag.id && !el.hidden)
            .map((el) => ({ x: el.x, y: el.y, w: el.w, h: el.h }));
          const r = snapMove({ x: nx, y: ny, w: o.w, h: o.h }, others, pg, SNAP_MM);
          nx = r.vGuides.length ? r.x : snap(nx);
          ny = r.hGuides.length ? r.y : snap(ny);
          setGuides({ v: r.vGuides, h: r.hGuides });
        }
        onChangeRef.current(drag.id, { x: Math.max(0, nx), y: Math.max(0, ny) });
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
      onChangeRef.current(drag.id, { x, y, w, h });
    };
    const onUp = () => {
      setDrag(null);
      setGuides({ v: [], h: [] });
    };
    window.addEventListener('pointermove', onMove);
    window.addEventListener('pointerup', onUp);
    return () => {
      window.removeEventListener('pointermove', onMove);
      window.removeEventListener('pointerup', onUp);
    };
  }, [drag, zoom, gridMm]);

  const start = (e: React.PointerEvent, kind: 'move' | 'resize', el: DocElement, handle?: HandleDef) => {
    if (preview) return;
    e.stopPropagation();
    const additive = e.shiftKey || e.metaKey || e.ctrlKey;

    // Additive click just toggles membership — no drag.
    if (kind === 'move' && additive) {
      onSelect(el.id, true);
      return;
    }

    // Dragging an element already in a multi-selection keeps the whole set and
    // moves it as a group; otherwise this element becomes the sole selection.
    const inSelection = selectedSet.has(el.id);
    const groupIds = kind === 'move' && inSelection && selectedSet.size > 1 ? selectedIds : [el.id];
    if (!inSelection || selectedSet.size <= 1) {
      onSelect(el.id, false);
    }

    // Locked: selectable (to inspect/unlock) but not draggable/resizable.
    if (el.locked) return;
    e.preventDefault();
    const byId = new Map(elements.map((x) => [x.id, x]));
    const group = groupIds
      .map((id) => byId.get(id))
      .filter((x): x is DocElement => !!x && !x.locked)
      .map((x) => ({ id: x.id, x: x.x, y: x.y }));
    setDrag({
      kind,
      handle,
      id: el.id,
      startX: e.clientX,
      startY: e.clientY,
      orig: { x: el.x, y: el.y, w: el.w, h: el.h },
      group,
    });
  };

  const ordered = [...elements].sort((a, b) => a.z - b.z);

  return (
    <div
      className="mx-auto"
      style={{ width: `calc(${page.widthMm}mm * ${zoom})`, height: `calc(${page.heightMm}mm * ${zoom})` }}
    >
      <div
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
        {showGrid && !preview && (
          <div
            data-testid="doc-grid"
            aria-hidden
            style={{
              position: 'absolute',
              inset: 0,
              pointerEvents: 'none',
              zIndex: 0,
              backgroundImage:
                'repeating-linear-gradient(to right, var(--color-border), var(--color-border) 0.1mm, transparent 0.1mm, transparent 5mm), repeating-linear-gradient(to bottom, var(--color-border), var(--color-border) 0.1mm, transparent 0.1mm, transparent 5mm)',
            }}
          />
        )}
        <MarginGuide page={page} preview={preview} />
        {drag &&
          guides.v.map((x) => (
            <div
              key={`v-${x}`}
              data-testid="doc-guide-v"
              aria-hidden
              style={{
                position: 'absolute',
                left: `${x}mm`,
                top: 0,
                bottom: 0,
                width: 0,
                borderLeft: '0.25mm solid var(--color-primary)',
                pointerEvents: 'none',
                zIndex: 9998,
              }}
            />
          ))}
        {drag &&
          guides.h.map((y) => (
            <div
              key={`h-${y}`}
              data-testid="doc-guide-h"
              aria-hidden
              style={{
                position: 'absolute',
                top: `${y}mm`,
                left: 0,
                right: 0,
                height: 0,
                borderTop: '0.25mm solid var(--color-primary)',
                pointerEvents: 'none',
                zIndex: 9998,
              }}
            />
          ))}
        {ordered.map((el) => {
          // Hidden elements are omitted from Preview/print, shown dimmed while editing.
          if (preview && el.hidden) return null;
          const selected = selectedSet.has(el.id) && !preview;
          // Resize handles + size readout only make sense for a single element.
          const solo = selected && selectedSet.size === 1;
          return (
            <div
              key={el.id}
              data-testid={`doc-el-${el.id}`}
              onPointerDown={(e) => start(e, 'move', el)}
              onDoubleClick={el.type === 'blockInstance' && !preview ? () => onEditBlock(el.blockId) : undefined}
              style={{
                position: 'absolute',
                left: `${el.x}mm`,
                top: `${el.y}mm`,
                width: `${el.w}mm`,
                height: `${el.h}mm`,
                transform: el.rotation ? `rotate(${el.rotation}deg)` : undefined,
                zIndex: el.z,
                cursor: preview || el.locked ? 'default' : 'move',
                // Hidden (edit-only) dims to 0.4; otherwise honour the element's own opacity.
                opacity: el.hidden ? 0.4 : el.opacity ?? undefined,
                outline: selected ? '1px solid var(--color-primary)' : undefined,
              }}
            >
              {el.type === 'blockInstance' ? (
                <BlockInstanceContent block={blocks[el.blockId]} data={data} preview={preview} />
              ) : (
                <ElementContent el={el} data={data} preview={preview} />
              )}
              {solo && (
                <span
                  data-testid="doc-readout"
                  aria-hidden
                  style={{
                    position: 'absolute',
                    left: 0,
                    bottom: 0,
                    transform: 'translateY(115%)',
                    fontSize: '2.4mm',
                    lineHeight: 1,
                    padding: '0.4mm 0.8mm',
                    borderRadius: '0.6mm',
                    background: 'var(--color-primary)',
                    color: '#fff',
                    whiteSpace: 'nowrap',
                    pointerEvents: 'none',
                    zIndex: 9999,
                  }}
                >
                  {Math.round(el.w)} × {Math.round(el.h)} mm
                </span>
              )}
              {solo &&
                !el.locked &&
                el.type !== 'blockInstance' &&
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
