'use client';

import type { DocTemplate } from '@/lib/documents/types';
import { ElementContent } from './element-content';

/**
 * Off-screen render of EVERY page of the template, used only for printing.
 * Hidden on screen (`.doc-print-doc { display: none }` in the designer's print
 * stylesheet) and revealed in print, one physical page per `.doc-print-page`
 * with a page break between them. Elements are rendered resolved (preview),
 * hidden elements omitted — matching the on-canvas Preview.
 */
export function PrintDocument({
  template,
  data,
}: {
  template: DocTemplate;
  data: Record<string, string>;
}) {
  const { page } = template;
  return (
    <div id="doc-print-root" className="doc-print-doc" aria-hidden data-testid="doc-print-doc">
      {template.pages.map((pg) => (
        <div
          key={pg.id}
          className="doc-print-page"
          data-testid="doc-print-page"
          style={{
            position: 'relative',
            width: `${page.widthMm}mm`,
            height: `${page.heightMm}mm`,
            background: page.background,
            overflow: 'hidden',
          }}
        >
          {[...pg.elements]
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
        </div>
      ))}
    </div>
  );
}
