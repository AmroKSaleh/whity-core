import { generateSequence, rowsFromValues, MAX_BATCH_ROWS, type SequenceConfig } from '@/lib/documents/batch';

const base: SequenceConfig = { key: 'sku', prefix: '', start: 1, count: 3, step: 1, padding: 0, suffix: '' };

describe('generateSequence', () => {
  it('generates a simple incrementing run', () => {
    expect(generateSequence(base)).toEqual(['1', '2', '3']);
  });

  it('applies prefix, suffix and zero-padding', () => {
    expect(generateSequence({ ...base, prefix: 'SN-', suffix: '-A', padding: 4, count: 2 })).toEqual([
      'SN-0001-A',
      'SN-0002-A',
    ]);
  });

  it('honours start and step (including step > 1)', () => {
    expect(generateSequence({ ...base, start: 100, step: 5, count: 3 })).toEqual(['100', '105', '110']);
  });

  it('supports negative steps and negative values', () => {
    expect(generateSequence({ ...base, start: 1, step: -1, count: 3, padding: 2 })).toEqual(['01', '00', '-01']);
  });

  it('returns nothing for a zero or negative count', () => {
    expect(generateSequence({ ...base, count: 0 })).toEqual([]);
    expect(generateSequence({ ...base, count: -5 })).toEqual([]);
  });

  it('caps runaway counts at MAX_BATCH_ROWS', () => {
    expect(generateSequence({ ...base, count: MAX_BATCH_ROWS + 500 })).toHaveLength(MAX_BATCH_ROWS);
  });
});

describe('rowsFromValues', () => {
  it('layers each value over the base data under the chosen key', () => {
    const rows = rowsFromValues(['A', 'B'], 'sku', { company: 'Acme', sku: 'seed' });
    expect(rows).toEqual([
      { company: 'Acme', sku: 'A' },
      { company: 'Acme', sku: 'B' },
    ]);
  });

  it('does not mutate the base object', () => {
    const b = { sku: 'seed' };
    rowsFromValues(['A'], 'sku', b);
    expect(b).toEqual({ sku: 'seed' });
  });
});
