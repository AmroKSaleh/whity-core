/**
 * Editor milestone M2, "done when": a document round-trips through the
 * WhityDocument contract without loss. Each case goes through a real
 * ProseMirror document (the editor's schema), not just the converters.
 */
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { Editor } from '@tiptap/core';

import type { Block } from '@whity-org/ui/editor/contract';
import { fromEditorContent, normalizeBlocks, toEditorContent } from '@whity-org/ui/editor/convert';
import { schemaExtensions } from '@whity-org/ui/editor/schema';
import { BlockIds } from '@whity-org/ui/editor/block-ids';

function throughEditor(blocks: Block[]): Block[] {
  const editor = new Editor({ extensions: [...schemaExtensions, BlockIds], content: toEditorContent(blocks) });
  const out = fromEditorContent(editor.getJSON());
  editor.destroy();
  return out;
}

const text = (t: string) => ({ type: 'text' as const, text: t });

const EVERY_TEXT_BLOCK: Block[] = [
  { id: 'b1', type: 'paragraph', content: [text('Dear '), { type: 'field', path: 'customer.name', label: 'Customer name', required: true }, text(',')] },
  { id: 'b2', type: 'heading', level: 1, content: [text('Open invoices')] },
  { id: 'b3', type: 'heading', level: 2, content: [text('Due this week')] },
  { id: 'b4', type: 'heading', level: 3, content: [text('Example Clinic')] },
  { id: 'b5', type: 'quote', content: [text('Pay within 14 days.')] },
  { id: 'b6', type: 'code', language: 'json', text: '{\n  "total": "301.600"\n}' },
  { id: 'b7', type: 'bullet', level: 0, content: [text('INV-1042')] },
  { id: 'b8', type: 'bullet', level: 1, content: [text('JOD 301.600')] },
  { id: 'b9', type: 'number', level: 0, content: [text('Check the amount')] },
  { id: 'b10', type: 'number', level: 2, content: [text('Third level')] },
  { id: 'b11', type: 'checklist', level: 0, checked: true, content: [text('Send the reminder')] },
  { id: 'b12', type: 'checklist', level: 1, checked: false, content: [] },
  { id: 'b13', type: 'divider' },
  {
    id: 'b14',
    type: 'paragraph',
    content: [
      { type: 'text', text: 'link', marks: [{ type: 'link', href: 'https://example.jo/pay' }, { type: 'bold' }] },
      { type: 'text', text: ' every ', marks: [{ type: 'italic' }, { type: 'underline' }, { type: 'strike' }] },
      { type: 'text', text: 'mark', marks: [{ type: 'bold' }, { type: 'code' }, { type: 'highlight' }] },
      { type: 'break' },
      { type: 'text', text: 'commented', marks: [{ type: 'comment', threadId: 't1' }] },
      { type: 'text', text: '14', marks: [{ type: 'insertion', suggestionId: 's1' }] },
      { type: 'text', text: '30', marks: [{ type: 'deletion', suggestionId: 's1' }] },
      { type: 'text', text: 'Rana', marks: [{ type: 'perDocument' }] },
      { type: 'math', tex: 'a^2 + b^2' },
      { type: 'text', text: 'record', marks: [{ type: 'link', href: '/records/invoice/42', recordRef: { type: 'invoice', id: '42' } }] },
    ],
  },
  { id: 'b15', type: 'paragraph', suggestion: { id: 's2', action: 'insert' }, content: [text('Suggested by an agent.')] },
  { id: 'b16', type: 'paragraph', content: [] },
];

/** Every M3 block, plus a plugin block (M7), which travels through unknownBlock. */
const LATER_BLOCKS: Block[] = [
  { id: 'c1', type: 'callout', kind: 'important', content: [text('Fiscal fields are required.')] },
  { id: 'c2', type: 'toggle', open: false, summary: [text('Terms')], children: [{ id: 'c2a', type: 'paragraph', content: [text('Net 30.')] }] },
  { id: 'c3', type: 'columns', columns: [[{ id: 'c3a', type: 'paragraph', content: [text('Left')] }], []] },
  { id: 'c4', type: 'table', headerRow: true, columns: [{ align: 'start' }, { align: 'end', widthPct: 30 }], rows: [[[text('Invoice')], [text('Amount')]], [[text('INV-1042')], [text('JOD 301.600')]]] },
  { id: 'c5', type: 'image', src: '/files/7', alt: 'Shop', width: 'half', caption: [text('The shop')] },
  { id: 'c6', type: 'record', ref: { type: 'invoice', id: '42' }, view: 'card', suggestion: { id: 's3', action: 'insert' } },
  { id: 'c7', type: 'plugin', plugin: 'com.example.delivery-tracker', blockType: 'deliveryStatus', schemaVersion: 2, data: { order: 'A-17', nested: { stops: [1, 2] } } },
  { id: 'c8', type: 'barcode', symbology: 'code128', value: [{ type: 'field', path: 'invoice.number', label: 'Invoice number' }], showText: true },
  { id: 'c9', type: 'pageBreak' },
  { id: 'c10', type: 'callout', kind: 'note', content: [text('Background for the reader, '), { type: 'text', text: 'bold', marks: [{ type: 'bold' }] }] },
  {
    id: 'c11',
    type: 'toggle',
    open: true,
    summary: [text('What if I already paid?')],
    children: [
      { id: 'c11a', type: 'bullet', level: 0, content: [text('Ignore this letter.')] },
      { id: 'c11b', type: 'columns', columns: [[{ id: 'c11c', type: 'paragraph', content: [text('Nested left')] }], [{ id: 'c11d', type: 'math', tex: 'x^2' }]] },
    ],
  },
  { id: 'c12', type: 'toggle', open: false, summary: [], children: [] },
  { id: 'c13', type: 'columns', columns: [[], []] },
  { id: 'c14', type: 'table', headerRow: false, columns: [], rows: [] },
  { id: 'c15', type: 'image', src: '/api/v1/document-files/9/content', alt: '', width: 'full' },
  { id: 'c16', type: 'file', fileId: '12', name: 'Bank letter.pdf', size: 493568, mime: 'application/pdf' },
  { id: 'c17', type: 'record', ref: { type: 'document', id: '5' }, view: 'summary' },
  { id: 'c18', type: 'chart', chart: 'bar', source: { query: 'documents_by_month', params: { months: '6' } }, title: 'Documents issued' },
  { id: 'c19', type: 'chart', chart: 'donut', source: { query: 'documents_by_status' } },
  {
    id: 'c20',
    type: 'signature',
    signers: [{ name: [text('Rana Haddad')], role: [text('Owner, Example Bakery')] }],
    date: [{ type: 'field', path: 'today', label: 'Today' }],
    stamp: true,
  },
  { id: 'c21', type: 'barcode', symbology: 'ean13', value: [text('6291041500213')], showText: false, widthMm: 40, heightMm: 15 },
  { id: 'c22', type: 'qr', value: [text('https://example.jo/v/INV-1042')], caption: [text('Scan to verify')], sizeMm: 24 },
  { id: 'c23', type: 'qr', value: [{ type: 'field', path: 'invoice.url', label: 'Invoice link' }] },
  { id: 'c24', type: 'math', tex: '\\sum_{i=1}^{n} a_i' },
];

describe('WhityDocument round trip through the editor', () => {
  it('keeps every text block, inline run, mark and attribute', () => {
    expect(throughEditor(EVERY_TEXT_BLOCK)).toEqual(EVERY_TEXT_BLOCK);
  });

  it('keeps every rich block, nested blocks and optional fields included', () => {
    expect(throughEditor(LATER_BLOCKS)).toEqual(LATER_BLOCKS);
  });

  it('keeps the order of a mixed document', () => {
    const mixed = [EVERY_TEXT_BLOCK[0], LATER_BLOCKS[3], EVERY_TEXT_BLOCK[6], LATER_BLOCKS[6], EVERY_TEXT_BLOCK[12]];
    expect(throughEditor(mixed)).toEqual(mixed);
  });

  it('normalises only what ProseMirror merges: adjacent runs and mark order', () => {
    const loose: Block[] = [
      {
        id: 'n1',
        type: 'paragraph',
        content: [
          { type: 'text', text: 'Pay ', marks: [{ type: 'bold' }] },
          { type: 'text', text: 'now', marks: [{ type: 'bold' }] },
          { type: 'text', text: '', marks: [{ type: 'italic' }] },
          { type: 'text', text: '!', marks: [{ type: 'highlight' }, { type: 'italic' }] },
        ],
      },
    ];
    const expected: Block[] = [
      {
        id: 'n1',
        type: 'paragraph',
        content: [
          { type: 'text', text: 'Pay now', marks: [{ type: 'bold' }] },
          { type: 'text', text: '!', marks: [{ type: 'italic' }, { type: 'highlight' }] },
        ],
      },
    ];
    expect(normalizeBlocks(loose)).toEqual(expected);
    expect(throughEditor(loose)).toEqual(expected);
  });

  it('gives an id to a block without one, and renames a duplicate', () => {
    const editor = new Editor({
      extensions: [...schemaExtensions, BlockIds],
      content: toEditorContent([
        { id: 'same', type: 'paragraph', content: [text('one')] },
        { id: 'same', type: 'paragraph', content: [text('two')] },
      ]),
    });
    editor.commands.insertContentAt(editor.state.doc.content.size, { type: 'paragraph', content: [{ type: 'text', text: 'three' }] });
    const blocks = fromEditorContent(editor.getJSON());
    editor.destroy();
    expect(blocks[0].id).toBe('same');
    expect(new Set(blocks.map((b) => b.id)).size).toBe(3);
    expect(blocks.every((b) => /^[a-z0-9]+$/.test(b.id))).toBe(true);
  });

  it('turns an empty document into one empty paragraph', () => {
    const blocks = throughEditor([]);
    expect(blocks).toHaveLength(1);
    expect(blocks[0]).toMatchObject({ type: 'paragraph', content: [] });
  });
});

describe('the contract in packages/ui is the design handoff, unchanged', () => {
  it('matches docs/design/handoff/editor/whity-document.ts', () => {
    const root = path.resolve(__dirname, '..', '..');
    const read = (p: string) => readFileSync(path.join(root, p), 'utf8').replace(/\r\n/g, '\n');
    expect(read('packages/ui/src/editor/contract.ts')).toBe(read('docs/design/handoff/editor/whity-document.ts'));
  });
});

describe('the editor strings reach the translation catalogue', () => {
  it('packages/features/src/document-editor/editor-labels.ts matches labels.ts', () => {
    const root = path.resolve(__dirname, '..', '..');
    // Throws (and fails the test) when the generated file is stale.
    expect(() =>
      execFileSync(process.execPath, [path.join(root, 'scripts', 'generate-editor-labels.mjs'), '--check'], { cwd: root, stdio: 'pipe' })
    ).not.toThrow();
  });
});
