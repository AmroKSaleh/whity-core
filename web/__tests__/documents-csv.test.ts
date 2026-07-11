import { parseDelimited, parseJsonRows } from '@/lib/documents/csv';

describe('parseDelimited', () => {
  it('parses a simple CSV with a header row', () => {
    const { headers, rows } = parseDelimited('serial,model\nSN-1,Widget\nSN-2,Gadget');
    expect(headers).toEqual(['serial', 'model']);
    expect(rows).toEqual([
      { serial: 'SN-1', model: 'Widget' },
      { serial: 'SN-2', model: 'Gadget' },
    ]);
  });

  it('honours quoted fields containing commas, quotes and newlines', () => {
    const csv = 'name,note\n"Acme, Inc.","He said ""hi""\nsecond line"';
    const { rows } = parseDelimited(csv);
    expect(rows[0].name).toBe('Acme, Inc.');
    expect(rows[0].note).toBe('He said "hi"\nsecond line');
  });

  it('handles CRLF line endings and a trailing newline', () => {
    const { rows } = parseDelimited('a,b\r\n1,2\r\n');
    expect(rows).toEqual([{ a: '1', b: '2' }]);
  });

  it('auto-detects tab-separated values', () => {
    const { headers, rows } = parseDelimited('serial\tmodel\nSN-1\tWidget');
    expect(headers).toEqual(['serial', 'model']);
    expect(rows).toEqual([{ serial: 'SN-1', model: 'Widget' }]);
  });

  it('pads missing trailing cells and ignores blank lines', () => {
    const { rows } = parseDelimited('a,b\n1\n\n3,4');
    expect(rows).toEqual([
      { a: '1', b: '' },
      { a: '3', b: '4' },
    ]);
  });

  it('returns empty structures for empty input', () => {
    expect(parseDelimited('')).toEqual({ headers: [], rows: [] });
  });
});

describe('parseJsonRows', () => {
  it('parses an array of flat objects and coerces values to strings', () => {
    const rows = parseJsonRows('[{"serial":"SN-1","qty":5},{"serial":"SN-2","qty":null}]');
    expect(rows).toEqual([
      { serial: 'SN-1', qty: '5' },
      { serial: 'SN-2', qty: '' },
    ]);
  });

  it('throws on non-array JSON', () => {
    expect(() => parseJsonRows('{"serial":"SN-1"}')).toThrow();
  });

  it('throws on malformed JSON', () => {
    expect(() => parseJsonRows('not json')).toThrow();
  });
});
