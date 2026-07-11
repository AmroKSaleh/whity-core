import { cellsPerSheet, sheetCount, cellPositions, chunkIntoSheets, type SheetSpec } from '@/lib/documents/sheet';

const SHEET: SheetSpec = {
  enabled: true,
  cols: 3,
  rows: 2,
  sheetWidthMm: 210,
  sheetHeightMm: 297,
  marginXMm: 10,
  marginYMm: 10,
  gutterXMm: 5,
  gutterYMm: 4,
};

describe('sheet layout', () => {
  it('computes cells per sheet', () => {
    expect(cellsPerSheet(SHEET)).toBe(6);
    expect(cellsPerSheet({ ...SHEET, cols: 0, rows: 0 })).toBe(1);
  });

  it('computes the number of sheets for a unit count', () => {
    expect(sheetCount(0, SHEET)).toBe(0);
    expect(sheetCount(6, SHEET)).toBe(1);
    expect(sheetCount(7, SHEET)).toBe(2);
    expect(sheetCount(13, SHEET)).toBe(3);
  });

  it('lays out cells row-major with margins and gutters', () => {
    const pos = cellPositions(SHEET, 60, 40);
    expect(pos).toHaveLength(6);
    // First cell at the margins.
    expect(pos[0]).toEqual({ x: 10, y: 10 });
    // Second column: +labelW + gutterX.
    expect(pos[1]).toEqual({ x: 10 + 60 + 5, y: 10 });
    // Third column.
    expect(pos[2]).toEqual({ x: 10 + 2 * (60 + 5), y: 10 });
    // Second row (index 3): back to x-margin, y advanced by labelH + gutterY.
    expect(pos[3]).toEqual({ x: 10, y: 10 + 40 + 4 });
  });

  it('chunks units into per-sheet groups', () => {
    const units = [1, 2, 3, 4, 5, 6, 7];
    const chunks = chunkIntoSheets(units, SHEET);
    expect(chunks).toHaveLength(2);
    expect(chunks[0]).toHaveLength(6);
    expect(chunks[1]).toEqual([7]);
  });
});
