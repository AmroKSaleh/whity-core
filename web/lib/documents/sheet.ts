/**
 * N-up label-sheet layout for the document designer.
 *
 * A sheet tiles many label instances onto one physical page (e.g. an A4 sheet of
 * Avery address labels). The label size is the template's page size; the sheet
 * defines the physical page, the grid (cols × rows), its margins and the gutters
 * between cells. Combined with a variable-data batch, successive data rows flow
 * into successive cells across as many sheets as needed.
 *
 * Pure and deterministic — unit-tested without a DOM.
 */

export interface SheetSpec {
  /** When false, print emits one label per physical page (no tiling). */
  enabled: boolean;
  cols: number;
  rows: number;
  sheetWidthMm: number;
  sheetHeightMm: number;
  /** Outer margins from the sheet edge to the first cell. */
  marginXMm: number;
  marginYMm: number;
  /** Gaps between adjacent cells. */
  gutterXMm: number;
  gutterYMm: number;
}

export interface CellPos {
  x: number;
  y: number;
}

/** Common label-sheet presets (label/cell size is the template page size). */
export const SHEET_PRESETS: ReadonlyArray<{ id: string; label: string; spec: Omit<SheetSpec, 'enabled'> }> = [
  {
    id: 'avery-l7160',
    label: 'Avery L7160 — A4, 3 × 7 (63.5 × 38.1)',
    spec: { cols: 3, rows: 7, sheetWidthMm: 210, sheetHeightMm: 297, marginXMm: 7, marginYMm: 15, gutterXMm: 2.5, gutterYMm: 0 },
  },
  {
    id: 'avery-l7159',
    label: 'Avery L7159 — A4, 3 × 8 (63.5 × 33.9)',
    spec: { cols: 3, rows: 8, sheetWidthMm: 210, sheetHeightMm: 297, marginXMm: 7, marginYMm: 13, gutterXMm: 2.5, gutterYMm: 0 },
  },
  {
    id: 'avery-l7651',
    label: 'Avery L7651 — A4, 5 × 13 (38.1 × 21.2)',
    spec: { cols: 5, rows: 13, sheetWidthMm: 210, sheetHeightMm: 297, marginXMm: 4.75, marginYMm: 10.7, gutterXMm: 2.5, gutterYMm: 0 },
  },
  {
    id: 'us-letter-2x5',
    label: 'US Letter — 2 × 5 (4 × 2″)',
    spec: { cols: 2, rows: 5, sheetWidthMm: 215.9, sheetHeightMm: 279.4, marginXMm: 4.8, marginYMm: 12.7, gutterXMm: 4.8, gutterYMm: 0 },
  },
];

/** Cells per sheet (at least 1). */
export function cellsPerSheet(sheet: SheetSpec): number {
  return Math.max(1, Math.trunc(sheet.cols) * Math.trunc(sheet.rows));
}

/** Number of physical sheets needed to hold `totalUnits` labels. */
export function sheetCount(totalUnits: number, sheet: SheetSpec): number {
  if (totalUnits <= 0) return 0;
  return Math.ceil(totalUnits / cellsPerSheet(sheet));
}

/**
 * Top-left position (mm) of every cell on a sheet, row-major (left→right,
 * top→bottom), given the label (cell) size.
 */
export function cellPositions(sheet: SheetSpec, labelW: number, labelH: number): CellPos[] {
  const cols = Math.max(1, Math.trunc(sheet.cols));
  const rows = Math.max(1, Math.trunc(sheet.rows));
  const out: CellPos[] = [];
  for (let r = 0; r < rows; r += 1) {
    for (let c = 0; c < cols; c += 1) {
      out.push({
        x: sheet.marginXMm + c * (labelW + sheet.gutterXMm),
        y: sheet.marginYMm + r * (labelH + sheet.gutterYMm),
      });
    }
  }
  return out;
}

/** Split a flat list of units into per-sheet chunks (row-major fill). */
export function chunkIntoSheets<T>(units: T[], sheet: SheetSpec): T[][] {
  const per = cellsPerSheet(sheet);
  const out: T[][] = [];
  for (let i = 0; i < units.length; i += per) {
    out.push(units.slice(i, i + per));
  }
  return out;
}
