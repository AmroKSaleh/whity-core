/**
 * Tabular data parsers for the document designer's variable-data batch.
 *
 * Two paste/upload sources feed the same row pipeline as the serial generator:
 *  - delimited text (CSV/TSV) with a header row → one record per data row;
 *  - a pasted JSON array of objects.
 *
 * Column headers / object keys map directly onto `{{placeholder}}` tokens.
 * Pure and deterministic — unit-tested without a DOM.
 */

/** Parsed result: the header names and one string-map record per data row. */
export interface ParsedRows {
  headers: string[];
  rows: Record<string, string>[];
}

/** Split delimited text into a matrix of cells, honouring quotes and CRLF. */
function tokenize(text: string, delimiter: string): string[][] {
  const matrix: string[][] = [];
  let row: string[] = [];
  let field = '';
  let inQuotes = false;
  let started = false;

  const pushField = () => {
    row.push(field);
    field = '';
  };
  const pushRow = () => {
    pushField();
    matrix.push(row);
    row = [];
  };

  for (let i = 0; i < text.length; i += 1) {
    const c = text[i];
    started = true;
    if (inQuotes) {
      if (c === '"') {
        if (text[i + 1] === '"') {
          field += '"';
          i += 1;
        } else {
          inQuotes = false;
        }
      } else {
        field += c;
      }
      continue;
    }
    if (c === '"') {
      inQuotes = true;
    } else if (c === delimiter) {
      pushField();
    } else if (c === '\n') {
      pushRow();
    } else if (c === '\r') {
      // swallow; a following \n triggers the row break
    } else {
      field += c;
    }
  }
  // Flush the trailing field/row unless the input ended exactly on a newline.
  if (started && (field !== '' || row.length > 0)) {
    pushRow();
  }
  return matrix;
}

/** Auto-pick tab as the delimiter when the header line has tabs but no commas. */
function detectDelimiter(text: string): string {
  const firstLine = text.split(/\r?\n/, 1)[0] ?? '';
  if (firstLine.includes('\t') && !firstLine.includes(',')) return '\t';
  return ',';
}

/**
 * Parse delimited text (CSV/TSV) whose first non-empty row is the header. Extra
 * cells past the header are ignored; missing trailing cells become ''.
 */
export function parseDelimited(text: string, delimiter?: string): ParsedRows {
  const matrix = tokenize(text, delimiter ?? detectDelimiter(text)).filter(
    (r) => !(r.length === 1 && r[0].trim() === '')
  );
  if (matrix.length === 0) return { headers: [], rows: [] };
  const headers = matrix[0].map((h) => h.trim());
  const rows = matrix.slice(1).map((cells) => {
    const rec: Record<string, string> = {};
    headers.forEach((h, i) => {
      if (h !== '') rec[h] = cells[i] ?? '';
    });
    return rec;
  });
  return { headers, rows };
}

/**
 * Parse a pasted JSON array of flat objects into string-map records. Throws on
 * malformed JSON or a non-array; non-string values are coerced to strings.
 */
export function parseJsonRows(text: string): Record<string, string>[] {
  const parsed: unknown = JSON.parse(text);
  if (!Array.isArray(parsed)) {
    throw new Error('Expected a JSON array of row objects.');
  }
  return parsed.map((row) => {
    const rec: Record<string, string> = {};
    if (row && typeof row === 'object') {
      for (const [k, v] of Object.entries(row as Record<string, unknown>)) {
        rec[k] = v == null ? '' : String(v);
      }
    }
    return rec;
  });
}
