/**
 * Editor milestone M2, "done when": a document round-trips through the
 * WhityDocument contract without loss. Each case goes through a real
 * ProseMirror document (the editor's schema), not just the converters.
 */
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { Editor } from '@tiptap/core';

import type { Block } from '@amroksaleh/ui/editor/contract';
import { fromEditorContent, normalizeBlocks, toEditorContent } from '@amroksaleh/ui/editor/convert';
import { schemaExtensions } from '@amroksaleh/ui/editor/schema';
import { BlockIds } from '@amroksaleh/ui/editor/block-ids';

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
      { type: 'text', text: 'record', marks: [{ type: 'link', href: '/records/invoice/42', recordRef: { type: 'invoice', id: '42' } }] },
    ],
  },
  { id: 'b15', type: 'paragraph', suggestion: { id: 's2', action: 'insert' }, content: [text('Suggested by an agent.')] },
  { id: 'b16', type: 'paragraph', content: [] },
];

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
];

describe('WhityDocument round trip through the editor', () => {
  it('keeps every text block, inline run, mark and attribute', () => {
    expect(throughEditor(EVERY_TEXT_BLOCK)).toEqual(EVERY_TEXT_BLOCK);
  });

  it('keeps blocks a later milestone edits exactly as they were', () => {
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
