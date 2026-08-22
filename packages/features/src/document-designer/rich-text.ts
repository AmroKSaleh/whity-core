import type { TextRun } from '@amroksaleh/ui/documents/types';

/**
 * Pure helpers for the rich-text run model (see `TextRun` in `types.ts`).
 * Dependency-free, like the other `lib/documents/*` modules — no React here;
 * the JSX that actually renders runs lives in `components/documents/element-content.tsx`
 * (the one shared rendering path used by the canvas, block content and print).
 *
 * Invariant maintained by every function here: concatenating `run.text` across
 * a runs array, in order, always equals the element's flat plain text.
 */

/** Flatten runs back to plain text (the source-of-truth `text`/`template` field). */
export function runsToPlainText(runs: TextRun[]): string {
  return runs.map((r) => r.text).join('');
}

/** Merge adjacent runs with identical bold/italic, and drop empty-text runs. */
export function normalizeRuns(runs: TextRun[]): TextRun[] {
  const out: TextRun[] = [];
  for (const r of runs) {
    if (r.text === '') continue;
    const prev = out[out.length - 1];
    if (prev && !!prev.bold === !!r.bold && !!prev.italic === !!r.italic) {
      prev.text += r.text;
    } else {
      out.push({ ...r });
    }
  }
  return out;
}

/** Split a runs array at an absolute character offset into [before, after]. */
function splitRunsAt(runs: TextRun[], offset: number): [TextRun[], TextRun[]] {
  const before: TextRun[] = [];
  const after: TextRun[] = [];
  let pos = 0;
  for (const r of runs) {
    const end = pos + r.text.length;
    if (end <= offset) {
      before.push(r);
    } else if (pos >= offset) {
      after.push(r);
    } else {
      // The split point falls inside this run — cut it in two.
      const cut = offset - pos;
      before.push({ ...r, text: r.text.slice(0, cut) });
      after.push({ ...r, text: r.text.slice(cut) });
    }
    pos = end;
  }
  return [before, after];
}

/**
 * Toggle `bold`/`italic` over the [start, end) character range of `plainText`.
 * `runs` is the element's current runs (undefined/empty = the whole text is one
 * unformatted run). If the whole selection already has the format, it's cleared;
 * otherwise it's applied — the common toolbar-toggle behaviour. A no-op (returns
 * the input runs, defaulted to a single run) when start === end (no selection).
 */
export function toggleRunFormat(
  runs: TextRun[] | undefined,
  plainText: string,
  start: number,
  end: number,
  key: 'bold' | 'italic'
): TextRun[] {
  const base: TextRun[] = runs && runs.length > 0 ? runs : [{ text: plainText }];
  if (start >= end) return base;
  const lo = Math.max(0, Math.min(start, plainText.length));
  const hiVal = Math.max(lo, Math.min(end, plainText.length));
  const [pre, restA] = splitRunsAt(base, lo);
  const [mid, post] = splitRunsAt(restA, hiVal - lo);
  const alreadyAll = mid.length > 0 && mid.every((r) => !!r[key]);
  const toggled = mid.map((r) => ({ ...r, [key]: !alreadyAll }));
  return normalizeRuns([...pre, ...toggled, ...post]);
}

/**
 * Re-map `runs` after the plain text changed from `oldPlain` to `newPlain` (the
 * operator typed/deleted in the flattened textarea). Preserves formatting for
 * the unchanged common prefix/suffix; the edited middle span becomes a single
 * unformatted run (simple, predictable — matches most lightweight editors).
 * Returns undefined when there were no runs to begin with (legacy plain-text
 * elements keep using their flat `text`/`template` field only).
 */
export function applyPlainTextEdit(
  runs: TextRun[] | undefined,
  oldPlain: string,
  newPlain: string
): TextRun[] | undefined {
  if (!runs || runs.length === 0) return undefined;
  if (oldPlain === newPlain) return runs;

  const maxCommon = Math.min(oldPlain.length, newPlain.length);
  let prefixLen = 0;
  while (prefixLen < maxCommon && oldPlain[prefixLen] === newPlain[prefixLen]) prefixLen += 1;
  let suffixLen = 0;
  const maxSuffix = maxCommon - prefixLen;
  while (
    suffixLen < maxSuffix &&
    oldPlain[oldPlain.length - 1 - suffixLen] === newPlain[newPlain.length - 1 - suffixLen]
  ) {
    suffixLen += 1;
  }

  const [pre] = splitRunsAt(runs, prefixLen);
  const [, post] = splitRunsAt(runs, oldPlain.length - suffixLen);
  const inserted = newPlain.slice(prefixLen, newPlain.length - suffixLen);
  const middle: TextRun[] = inserted ? [{ text: inserted }] : [];
  return normalizeRuns([...pre, ...middle, ...post]);
}
