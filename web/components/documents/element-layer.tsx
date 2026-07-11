'use client';

import type { DocElement } from '@/lib/documents/types';
import type { DocBlock } from '@/lib/documents/blocks';
import { ElementContent } from './element-content';

/**
 * Renders a list of elements as absolutely-positioned children (z-ordered,
 * hidden ones omitted), resolved for display. Used inside a block instance and
 * by the print renderer. Non-interactive — no selection/drag handles.
 */
export function ElementLayer({ elements, data }: { elements: DocElement[]; data: Record<string, string> }) {
  return (
    <>
      {[...elements]
        .sort((a, b) => a.z - b.z)
        .filter((el) => !el.hidden)
        .map((el) => (
          <div
            key={el.id}
            style={{
              position: 'absolute',
              left: `${el.x}mm`,
              top: `${el.y}mm`,
              width: `${el.w}mm`,
              height: `${el.h}mm`,
              transform: el.rotation ? `rotate(${el.rotation}deg)` : undefined,
              opacity: el.opacity ?? undefined,
              zIndex: el.z,
            }}
          >
            <ElementContent el={el} data={data} preview />
          </div>
        ))}
    </>
  );
}

/**
 * The visual content of a block instance: the referenced block's elements
 * (positioned relative to the instance box). Shows a placeholder when the block
 * is missing (deleted) — omitted entirely in print/preview to avoid printing it.
 */
export function BlockInstanceContent({
  block,
  data,
  preview,
}: {
  block: DocBlock | undefined;
  data: Record<string, string>;
  preview: boolean;
}) {
  if (!block) {
    if (preview) return null;
    return (
      <div className="flex h-full w-full items-center justify-center rounded-sm border border-dashed border-destructive/60 bg-destructive/5 text-[8px] text-destructive">
        missing block
      </div>
    );
  }
  return <ElementLayer elements={block.elements} data={data} />;
}
