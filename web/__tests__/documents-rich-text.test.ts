import type { TextRun } from '@/lib/documents/types';
import { applyPlainTextEdit, normalizeRuns, runsToPlainText, toggleRunFormat } from '@/lib/documents/rich-text';

describe('runsToPlainText', () => {
  it('concatenates run text in order', () => {
    const runs: TextRun[] = [{ text: 'Hello ' }, { text: 'bold', bold: true }, { text: ' world' }];
    expect(runsToPlainText(runs)).toBe('Hello bold world');
  });
});

describe('normalizeRuns', () => {
  it('merges adjacent runs with identical formatting', () => {
    const runs: TextRun[] = [
      { text: 'a', bold: true },
      { text: 'b', bold: true },
      { text: 'c' },
    ];
    expect(normalizeRuns(runs)).toEqual([{ text: 'ab', bold: true }, { text: 'c' }]);
  });

  it('drops empty-text runs', () => {
    expect(normalizeRuns([{ text: 'a' }, { text: '' }, { text: 'b' }])).toEqual([{ text: 'ab' }]);
  });
});

describe('toggleRunFormat', () => {
  it('promotes a plain string to runs, bolding the selected range only', () => {
    const runs = toggleRunFormat(undefined, 'Hello world', 6, 11, 'bold');
    expect(runsToPlainText(runs)).toBe('Hello world');
    expect(runs).toEqual([{ text: 'Hello ' }, { text: 'world', bold: true }]);
  });

  it('un-bolds when the whole selection is already bold (toggle off)', () => {
    const bolded = toggleRunFormat(undefined, 'Hello world', 6, 11, 'bold');
    const toggled = toggleRunFormat(bolded, 'Hello world', 6, 11, 'bold');
    // Back to a single unformatted run covering the whole string.
    expect(normalizeRuns(toggled)).toEqual([{ text: 'Hello world' }]);
  });

  it('applies italic independently of bold on an overlapping range', () => {
    const bolded = toggleRunFormat(undefined, 'Hello world', 0, 5, 'bold');
    const both = toggleRunFormat(bolded, 'Hello world', 0, 5, 'italic');
    expect(both).toEqual([{ text: 'Hello', bold: true, italic: true }, { text: ' world' }]);
  });

  it('is a no-op without a selection (start === end)', () => {
    const runs = toggleRunFormat(undefined, 'Hello world', 3, 3, 'bold');
    expect(runs).toEqual([{ text: 'Hello world' }]);
  });

  it('preserves the invariant that run text concatenates back to the plain string', () => {
    const runs = toggleRunFormat(undefined, 'abcdef', 2, 4, 'italic');
    expect(runsToPlainText(runs)).toBe('abcdef');
  });
});

describe('applyPlainTextEdit', () => {
  it('returns undefined when there are no runs yet (legacy plain-text path)', () => {
    expect(applyPlainTextEdit(undefined, 'old', 'new')).toBeUndefined();
    expect(applyPlainTextEdit([], 'old', 'new')).toBeUndefined();
  });

  it('preserves formatting for the unchanged prefix/suffix around an edit', () => {
    const runs: TextRun[] = [{ text: 'Hello ', bold: true }, { text: 'world' }];
    // Insert "big " before "world" — the bold "Hello " prefix is untouched.
    const edited = applyPlainTextEdit(runs, 'Hello world', 'Hello big world');
    expect(runsToPlainText(edited!)).toBe('Hello big world');
    expect(edited![0]).toEqual({ text: 'Hello ', bold: true });
  });

  it('is a no-op when the text has not actually changed', () => {
    const runs: TextRun[] = [{ text: 'same', bold: true }];
    expect(applyPlainTextEdit(runs, 'same', 'same')).toBe(runs);
  });

  it('handles deletion (new text is a substring)', () => {
    const runs: TextRun[] = [{ text: 'Hello', bold: true }, { text: ' world' }];
    const edited = applyPlainTextEdit(runs, 'Hello world', 'Hello');
    expect(runsToPlainText(edited!)).toBe('Hello');
  });
});
