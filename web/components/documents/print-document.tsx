'use client';

import type { DocElement, DocTemplate } from '@/lib/documents/types';
import { chunkIntoSheets, cellPositions, type SheetSpec } from '@/lib/documents/sheet';
import type { DocBlock } from '@/lib/documents/blocks';
import { ElementContent } from './element-content';
import { BlockInstanceContent } from './element-layer';

/** One render unit: a single template page paired with one data row. */
interface Unit {
  key: string;
  elements: DocElement[];
  data: Record<string, string>;
}

/** The visible content of one label/page: its resolved, non-hidden elements. */
function LabelBody({
  elements,
  data,
  blocks,
}: {
  elements: DocElement[];
  data: Record<string, string>;
  blocks: Record<string, DocBlock>;
}) {
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
            {el.type === 'blockInstance' ? (
              <BlockInstanceContent block={blocks[el.blockId]} data={data} preview />
            ) : (
              <ElementContent el={el} data={data} preview />
            )}
          </div>
        ))}
    </>
  );
}

/**
 * Off-screen render used only for printing. Hidden on screen and revealed in
 * print (see the designer's print stylesheet), one physical page per
 * `.doc-print-page` with a page break between them.
 *
 * `datasets` is the list of data rows (one entry for a normal print, one per row
 * for a variable-data batch). Physical output is `datasets × template.pages`
 * render units. When `sheet` is enabled those units are tiled N-up onto
 * sheet-sized pages; otherwise each unit is its own physical page.
 */
export function PrintDocument({
  template,
  datasets,
  blocks,
  sheet,
}: {
  template: DocTemplate;
  datasets: Record<string, string>[];
  blocks: Record<string, DocBlock>;
  sheet?: SheetSpec;
}) {
  const { page } = template;

  // Flatten to render units in print order (row-major: row 0 all pages, …).
  const units: Unit[] = [];
  datasets.forEach((data, ri) => {
    template.pages.forEach((pg) => {
      units.push({ key: `${ri}-${pg.id}`, elements: pg.elements, data });
    });
  });

  const tiled = sheet?.enabled ?? false;

  return (
    <div id="doc-print-root" className="doc-print-doc" aria-hidden data-testid="doc-print-doc">
      {!tiled &&
        units.map((u) => (
          <div
            key={u.key}
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
            <LabelBody elements={u.elements} data={u.data} blocks={blocks} />
          </div>
        ))}

      {tiled &&
        sheet &&
        chunkIntoSheets(units, sheet).map((cellUnits, si) => {
          const positions = cellPositions(sheet, page.widthMm, page.heightMm);
          return (
            <div
              key={`sheet-${si}`}
              className="doc-print-page"
              data-testid="doc-print-page"
              style={{
                position: 'relative',
                width: `${sheet.sheetWidthMm}mm`,
                height: `${sheet.sheetHeightMm}mm`,
                background: '#ffffff',
                overflow: 'hidden',
              }}
            >
              {cellUnits.map((u, ci) => {
                const pos = positions[ci];
                if (!pos) return null;
                return (
                  <div
                    key={u.key}
                    data-testid="doc-sheet-cell"
                    style={{
                      position: 'absolute',
                      left: `${pos.x}mm`,
                      top: `${pos.y}mm`,
                      width: `${page.widthMm}mm`,
                      height: `${page.heightMm}mm`,
                      background: page.background,
                      overflow: 'hidden',
                    }}
                  >
                    <LabelBody elements={u.elements} data={u.data} blocks={blocks} />
                  </div>
                );
              })}
            </div>
          );
        })}
    </div>
  );
}
