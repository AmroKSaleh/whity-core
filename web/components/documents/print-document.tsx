'use client';

import type { DocTemplate } from '@/lib/documents/types';
import { ElementContent } from './element-content';

/**
 * Off-screen render of EVERY page of the template, for each data row, used only
 * for printing. Hidden on screen (`.doc-print-doc { display: none }` in the
 * designer's print stylesheet) and revealed in print, one physical page per
 * `.doc-print-page` with a page break between them. Elements are rendered
 * resolved (preview), hidden elements omitted — matching the on-canvas Preview.
 *
 * `datasets` is the list of data rows: a single-element list for a normal print,
 * or one entry per row for a variable-data / serial batch run (template pages ×
 * rows physical pages).
 */
export function PrintDocument({
  template,
  datasets,
}: {
  template: DocTemplate;
  datasets: Record<string, string>[];
}) {
  const { page } = template;
  return (
    <div id="doc-print-root" className="doc-print-doc" aria-hidden data-testid="doc-print-doc">
      {datasets.map((data, rowIdx) =>
        template.pages.map((pg) => (
          <div
            key={`${rowIdx}-${pg.id}`}
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
        ))
      )}
    </div>
  );
}
