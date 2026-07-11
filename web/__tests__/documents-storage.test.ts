import type { DocTemplate } from '@/lib/documents/types';
import { isDocTemplate, migrateTemplate } from '@/lib/documents/storage';

const PAGE = { widthMm: 100, heightMm: 100, marginMm: 0, background: '#ffffff' };

describe('template validation + migration', () => {
  it('accepts a legacy v1 template', () => {
    const v1 = { version: 1, name: 'Legacy', page: PAGE, placeholders: [], elements: [] };
    expect(isDocTemplate(v1)).toBe(true);
  });

  it('accepts a v2 template', () => {
    const v2 = { version: 2, name: 'New', page: PAGE, placeholders: [], pages: [] };
    expect(isDocTemplate(v2)).toBe(true);
  });

  it('rejects non-templates and structurally incomplete objects', () => {
    expect(isDocTemplate(null)).toBe(false);
    expect(isDocTemplate({ version: 2, name: 'x' })).toBe(false);
    expect(isDocTemplate({ version: 3, name: 'x', page: PAGE, placeholders: [], pages: [] })).toBe(false);
  });

  it('migrates a v1 template into a single-page v2 (elements → pages[0])', () => {
    const v1 = {
      version: 1,
      name: 'Legacy',
      page: PAGE,
      placeholders: [{ key: 'k', label: 'K', sample: 's' }],
      elements: [{ id: 'e1' }, { id: 'e2' }],
    };
    const m = migrateTemplate(v1 as unknown as DocTemplate);
    expect(m.version).toBe(2);
    expect(m.pages).toHaveLength(1);
    expect(m.pages[0].elements).toHaveLength(2);
    expect(m.pages[0].id).toBeTruthy();
    expect((m as unknown as Record<string, unknown>).elements).toBeUndefined();
  });

  it('treats a v1 template with no elements as an empty single page', () => {
    const v1 = { version: 1, name: 'Empty', page: PAGE, placeholders: [] };
    const m = migrateTemplate(v1 as unknown as DocTemplate);
    expect(m.pages).toHaveLength(1);
    expect(m.pages[0].elements).toEqual([]);
  });

  it('passes a v2 template through unchanged (idempotent)', () => {
    const v2 = {
      version: 2 as const,
      name: 'New',
      page: PAGE,
      placeholders: [],
      pages: [
        { id: 'p1', elements: [] },
        { id: 'p2', elements: [] },
      ],
    };
    const m = migrateTemplate(v2 as unknown as DocTemplate);
    expect(m.version).toBe(2);
    expect(m.pages).toHaveLength(2);
    expect(m.pages[1].id).toBe('p2');
  });
});
