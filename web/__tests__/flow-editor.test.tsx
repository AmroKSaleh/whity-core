import { useState } from 'react';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { FlowEditor } from '@amroksaleh/features/document-designer';
import { newFlowBlock, type FlowBlock, type FlowContent } from '@amroksaleh/ui/documents/flow';

/**
 * Document mode's editor (#1186 slice 1).
 *
 * The property worth testing is that what it emits is what the render service
 * ALREADY accepts — the same vocabulary `render-service/src/flow/document.js`
 * validates. There is no mapping layer to get wrong, by design, so the tests
 * assert the block shapes directly.
 *
 * The second property is that a block is renderable the INSTANT it is added.
 * `newFlowBlock` gives every type a value that validates, because an editor
 * that lets you insert something the printer then refuses is the failure this
 * whole design was arranged to prevent.
 */

/** Drives the editor as a controlled component, as the screen will. */
function Harness({ initial }: { initial: FlowContent }) {
  const [content, setContent] = useState(initial);
  const [selected, setSelected] = useState<number | null>(null);
  return (
    <>
      <FlowEditor content={content} onChange={setContent} selected={selected} onSelect={setSelected} />
      <pre data-testid="emitted">{JSON.stringify(content.blocks)}</pre>
    </>
  );
}

const emitted = (): FlowBlock[] => JSON.parse(screen.getByTestId('emitted').textContent ?? '[]');

describe('the empty state', () => {
  it('names the key that inserts, since the palette is the only inserter', () => {
    render(<Harness initial={{ blocks: [] }} />);

    // An empty state saying "add a block" without saying how is a dead end:
    // this mode has no toolbar of block types to point at.
    expect(screen.getByTestId('flow-editor-empty')).toHaveTextContent('/');
  });
});

describe('editing blocks', () => {
  it('edits a heading’s text and level in place', async () => {
    const user = userEvent.setup();
    render(<Harness initial={{ blocks: [newFlowBlock('heading')] }} />);

    await user.type(screen.getByTestId('flow-input-0'), 'Findings');
    await user.selectOptions(screen.getByTestId('flow-heading-level-0'), '3');

    expect(emitted()[0]).toEqual({ type: 'heading', level: 3, text: 'Findings' });
  });

  it('edits a paragraph', async () => {
    const user = userEvent.setup();
    render(<Harness initial={{ blocks: [newFlowBlock('paragraph')] }} />);

    await user.type(screen.getByTestId('flow-input-0'), 'The quarter closed early.');

    expect(emitted()[0]).toEqual({ type: 'paragraph', text: 'The quarter closed early.' });
  });

  it('edits a table cell without disturbing its neighbours', async () => {
    const user = userEvent.setup();
    render(<Harness initial={{ blocks: [newFlowBlock('table')] }} />);

    await user.type(screen.getByLabelText('Row 1, column 2'), 'x');

    expect(emitted()[0]).toMatchObject({ type: 'table', rows: [['', 'x']] });
  });
});

describe('reordering', () => {
  /**
   * The selection follows the BLOCK, not the position. Anything else means
   * pressing "move down" twice moves two different blocks — which is the bug
   * every list-reorder implementation gets wrong once.
   */
  it('moves a block down and keeps it selected', async () => {
    const user = userEvent.setup();
    render(
      <Harness
        initial={{
          blocks: [
            { type: 'heading', level: 1, text: 'First' },
            { type: 'paragraph', text: 'Second' },
          ],
        }}
      />
    );

    await user.click(screen.getByTestId('flow-down-0'));

    expect(emitted().map((b) => ('text' in b ? b.text : b.type))).toEqual(['Second', 'First']);
    // Now at index 1, and still the block the user was acting on.
    expect(screen.getByTestId('flow-block-1')).toHaveClass('border-primary');
  });

  it('cannot move the first block up or the last block down', () => {
    render(
      <Harness
        initial={{ blocks: [{ type: 'paragraph', text: 'a' }, { type: 'paragraph', text: 'b' }] }}
      />
    );

    expect(screen.getByTestId('flow-up-0')).toBeDisabled();
    expect(screen.getByTestId('flow-down-1')).toBeDisabled();
  });

  it('removes a block', async () => {
    const user = userEvent.setup();
    render(<Harness initial={{ blocks: [{ type: 'paragraph', text: 'gone' }] }} />);

    await user.click(screen.getByTestId('flow-remove-0'));

    expect(emitted()).toEqual([]);
  });
});

describe('every new block is renderable the instant it exists', () => {
  /**
   * Mirrors the render service's own validation
   * (render-service/src/flow/document.js). A default that does not satisfy it
   * would be a block the editor happily creates and the printer refuses with a
   * 422 — discovered at print time, by somebody else.
   */
  it.each(['heading', 'paragraph', 'table', 'figure', 'pageBreak', 'spacer'] as const)(
    'a new %s satisfies the renderer',
    (type) => {
      const b = newFlowBlock(type);

      if (b.type === 'heading') {
        expect(Number.isInteger(b.level)).toBe(true);
        expect(b.level).toBeGreaterThanOrEqual(1);
        expect(b.level).toBeLessThanOrEqual(6);
        expect(typeof b.text).toBe('string');
      }
      if (b.type === 'paragraph') expect(typeof b.text).toBe('string');
      if (b.type === 'table') {
        expect(Array.isArray(b.rows)).toBe(true);
        b.rows.forEach((r) => expect(Array.isArray(r)).toBe(true));
      }
      // The renderer refuses a remote figure source; a data URI is the only
      // honest empty state, and an empty string would be a block that exists
      // and cannot print.
      if (b.type === 'figure') expect(b.dataUri.startsWith('data:')).toBe(true);
      if (b.type === 'spacer') expect(b.heightMm).toBeGreaterThan(0);
    }
  );

  /**
   * The control on the vocabulary itself. `qr` is renderable but NOT
   * authorable — a verification code is the platform's to mint against a
   * document's own id, so a person placing one would be placing a code that
   * resolves to nothing. It must never appear in the author's block list.
   */
  it('does not offer qr as an authorable block', async () => {
    const { FLOW_BLOCK_TYPES } = await import('@amroksaleh/ui/documents/flow');
    expect(FLOW_BLOCK_TYPES).not.toContain('qr');
  });
});
