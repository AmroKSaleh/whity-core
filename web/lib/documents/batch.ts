/**
 * Variable-data ("mail-merge") helpers for the document designer.
 *
 * A batch run renders the template once per data row. This module builds those
 * rows. First data source: a serial/sequence generator (device serial numbers,
 * asset tags, …). CSV / pasted-JSON sources are layered on later and reuse
 * `rowsFromValues`. Pure and deterministic — unit-tested without a DOM.
 */

/** Configuration for the serial/sequence generator. */
export interface SequenceConfig {
  /** Placeholder key the generated value is written to (e.g. "sku", "serial"). */
  key: string;
  prefix: string;
  /** First numeric value. */
  start: number;
  /** How many rows to generate. */
  count: number;
  /** Increment between rows (may be negative). */
  step: number;
  /** Zero-pad the numeric part to this width (0 = no padding). */
  padding: number;
  suffix: string;
}

/** Hard cap on generated rows — a runaway-input backstop, not a product limit. */
export const MAX_BATCH_ROWS = 100000;

/** Zero-pad a (possibly negative) integer's magnitude to `width` digits. */
function padNumber(n: number, width: number): string {
  const digits = Math.abs(Math.trunc(n)).toString().padStart(Math.max(0, width), '0');
  return n < 0 ? `-${digits}` : digits;
}

/** Produce the ordered list of serial strings described by `cfg`. */
export function generateSequence(cfg: SequenceConfig): string[] {
  const n = Math.max(0, Math.min(Math.trunc(cfg.count), MAX_BATCH_ROWS));
  const out: string[] = [];
  for (let i = 0; i < n; i += 1) {
    out.push(`${cfg.prefix}${padNumber(cfg.start + i * cfg.step, cfg.padding)}${cfg.suffix}`);
  }
  return out;
}

/**
 * Turn a list of values for one key into full data rows, each layered over the
 * shared `base` (the template's sample data), so unrelated placeholders still
 * resolve.
 */
export function rowsFromValues(
  values: string[],
  key: string,
  base: Record<string, string>
): Record<string, string>[] {
  return values.map((v) => ({ ...base, [key]: v }));
}

/**
 * Layer parsed records (from CSV/JSON) over the shared `base` sample data, so
 * columns present in the file win and unrelated placeholders still resolve.
 */
export function rowsFromRecords(
  records: Record<string, string>[],
  base: Record<string, string>
): Record<string, string>[] {
  return records.map((r) => ({ ...base, ...r }));
}
