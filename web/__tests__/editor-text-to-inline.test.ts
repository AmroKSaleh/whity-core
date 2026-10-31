/**
 * Editor M3: the block toolbar edits a caption or value as plain text and
 * turns it back into inline content, keeping the merge fields that were there.
 */
import { inlineText, textToInline } from '@whity-org/ui/editor/services';
import type { Inline } from '@whity-org/ui/editor/contract';

const field = { type: 'field', path: 'customer.name', label: 'Customer' } as Inline;
const text = (t: string) => ({ type: 'text' as const, text: t });

describe('textToInline', () => {
  it('keeps a field that was there before', () => {
    const before: Inline[] = [text('For '), field, text('.')];
    expect(textToInline(inlineText(before), before)).toEqual(before);
  });

  it('keeps a field at either end and repeated fields', () => {
    expect(textToInline('{{Customer}} and {{Customer}}', [field])).toEqual([field, text(' and '), field]);
  });

  it('leaves an unknown {{token}} as text', () => {
    expect(textToInline('Dear {{Someone}}', [field])).toEqual([text('Dear {{Someone}}')]);
  });

  it('finds a field after extra braces', () => {
    expect(textToInline('{{{Customer}}', [field])).toEqual([text('{'), field]);
  });

  it('returns nothing for empty text', () => {
    expect(textToInline('', [field])).toEqual([]);
  });

  it('stays fast on a long run of braces', () => {
    const start = Date.now();
    textToInline('{{'.repeat(50000) + 'x', [field]);
    expect(Date.now() - start).toBeLessThan(500);
  });
});
