import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { DocumentDesignerScreen } from '@amroksaleh/features/document-designer';
import type { DocumentDesignerAdapter } from '@amroksaleh/features/document-designer';

/**
 * Document mode, through the real screen (#1186 slice 2).
 *
 * The unit tests cover the converters. These cover the two things only the
 * whole screen can show:
 *
 *   1. the `/` palette offers FLOW blocks in flow mode and canvas elements in
 *      canvas mode — and that is a correctness rule, not a tidiness one. The
 *      canvas Insert commands add absolutely-positioned elements to `pages`,
 *      while a flow document renders from `flow`. Offering them here would let
 *      somebody insert something that appears nowhere and reports nothing.
 *
 *   2. switching to flow ASKS FIRST, because it discards placement.
 */

function makeAdapter() {
  const saved: Array<Record<string, unknown>> = [];
  const adapter: DocumentDesignerAdapter = {
    listTemplates: jest.fn(async () => []),
    saveTemplate: jest.fn(async (template, id) => {
      saved.push(template as unknown as Record<string, unknown>);
      return id ?? '900';
    }),
    deleteTemplate: jest.fn(async () => {}),
    listBlocks: jest.fn(async () => []),
    saveBlock: jest.fn(async () => '1'),
    deleteBlock: jest.fn(async () => {}),
  };
  return { adapter, saved };
}

async function openDesigner(adapter: DocumentDesignerAdapter) {
  render(<DocumentDesignerScreen adapter={adapter} />);
  await screen.findByTestId('doc-top-bar');
}

/** Switch to document mode, accepting the confirmation if one appears. */
async function switchToFlow(user: ReturnType<typeof userEvent.setup>) {
  await user.click(screen.getByRole('menuitem', { name: /view/i }));
  await user.click(await screen.findByRole('menuitem', { name: /document mode/i }));
  const confirm = screen.queryByTestId('doc-confirm-accept');
  if (confirm) await user.click(confirm);
}

/** Put real elements on the canvas the way an author would. */
async function insertOnCanvas(user: ReturnType<typeof userEvent.setup>, id: string) {
  await user.keyboard('/');
  await user.click(await screen.findByTestId(`command-item-${id}`));
}

describe('switching into document mode', () => {
  /**
   * THE CONFIRMATION. Canvas -> flow discards placement and cannot recover it,
   * so it must say so BEFORE it happens.
   *
   * This test earns its place: with it removed, deleting the confirmation
   * outright leaves the rest of the suite green, because every other test here
   * starts from an empty canvas where there is nothing to lose and the
   * confirmation correctly never fires.
   */
  it('asks first when the canvas has something to lose', async () => {
    const user = userEvent.setup();
    const { adapter } = makeAdapter();
    await openDesigner(adapter);

    await insertOnCanvas(user, 'insert-text');
    await user.click(screen.getByRole('menuitem', { name: /view/i }));
    await user.click(await screen.findByRole('menuitem', { name: /document mode/i }));

    expect(await screen.findByTestId('doc-confirm-delete')).toBeInTheDocument();
    // Still on the canvas: asking is not doing.
    expect(screen.getByTestId('doc-page')).toBeInTheDocument();
  });

  it('counts what survives, not what is on the page', async () => {
    const user = userEvent.setup();
    const { adapter } = makeAdapter();
    await openDesigner(adapter);

    await insertOnCanvas(user, 'insert-text');
    await insertOnCanvas(user, 'insert-rect');
    await user.click(screen.getByRole('menuitem', { name: /view/i }));
    await user.click(await screen.findByRole('menuitem', { name: /document mode/i }));

    // 1 of 2 — the rectangle has no flow equivalent. Saying "2 carry over"
    // would be a promise the conversion does not keep.
    expect(await screen.findByTestId('doc-confirm-consequence')).toHaveTextContent(/1 of 2/);
  });

  it('leaves the canvas alone when the author declines', async () => {
    const user = userEvent.setup();
    const { adapter } = makeAdapter();
    await openDesigner(adapter);

    await insertOnCanvas(user, 'insert-text');
    await user.click(screen.getByRole('menuitem', { name: /view/i }));
    await user.click(await screen.findByRole('menuitem', { name: /document mode/i }));
    await user.click(await screen.findByTestId('doc-confirm-cancel'));

    expect(screen.getByTestId('doc-page')).toBeInTheDocument();
    expect(screen.queryByTestId('flow-editor-empty')).not.toBeInTheDocument();
  });

  it('carries the text across once accepted', async () => {
    const user = userEvent.setup();
    const { adapter } = makeAdapter();
    await openDesigner(adapter);

    await insertOnCanvas(user, 'insert-text');
    await user.click(screen.getByRole('menuitem', { name: /view/i }));
    await user.click(await screen.findByRole('menuitem', { name: /document mode/i }));
    await user.click(await screen.findByTestId('doc-confirm-accept'));

    expect(await screen.findByTestId('flow-block-0')).toHaveAttribute('data-block-type', 'paragraph');
  });

  /**
   * An empty canvas loses nothing, so it must not ask. A confirmation on an
   * action with no cost trains people to dismiss the ones that have one.
   */
  it('does not ask when the canvas is empty', async () => {
    const user = userEvent.setup();
    const { adapter } = makeAdapter();
    await openDesigner(adapter);

    await user.click(screen.getByRole('menuitem', { name: /view/i }));
    await user.click(await screen.findByRole('menuitem', { name: /document mode/i }));

    expect(screen.queryByTestId('doc-confirm-delete')).not.toBeInTheDocument();
    expect(await screen.findByTestId('flow-editor-empty')).toBeInTheDocument();
  });

  it('replaces the canvas with the flowing editor', async () => {
    const user = userEvent.setup();
    const { adapter } = makeAdapter();
    await openDesigner(adapter);

    await switchToFlow(user);

    expect(screen.queryByTestId('doc-page')).not.toBeInTheDocument();
    expect(await screen.findByTestId('flow-editor-empty')).toBeInTheDocument();
  });
});

describe('the palette follows the mode', () => {
  it('offers canvas elements in canvas mode', async () => {
    const user = userEvent.setup();
    const { adapter } = makeAdapter();
    await openDesigner(adapter);

    await user.keyboard('/');
    await user.type(screen.getByTestId('command-palette-input'), 'text');

    expect(await screen.findByTestId('command-item-insert-text')).toBeInTheDocument();
  });

  /**
   * THE CORRECTNESS RULE. A canvas insert in flow mode would add an element to
   * `pages`, which a flow document never renders — it would appear nowhere and
   * say nothing, indistinguishable from the editor ignoring the click.
   */
  it('offers flow blocks in flow mode, and NOT canvas elements', async () => {
    const user = userEvent.setup();
    const { adapter } = makeAdapter();
    await openDesigner(adapter);
    await switchToFlow(user);

    await user.keyboard('/');

    expect(await screen.findByTestId('command-item-flow-insert-heading')).toBeInTheDocument();
    expect(screen.queryByTestId('command-item-insert-text')).not.toBeInTheDocument();
    expect(screen.queryByTestId('command-item-insert-barcode')).not.toBeInTheDocument();
  });

  it('inserts the chosen block into the document', async () => {
    const user = userEvent.setup();
    const { adapter } = makeAdapter();
    await openDesigner(adapter);
    await switchToFlow(user);

    await user.keyboard('/');
    await user.click(await screen.findByTestId('command-item-flow-insert-heading'));

    const block = await screen.findByTestId('flow-block-0');
    expect(block).toHaveAttribute('data-block-type', 'heading');
  });
});

describe('what gets saved', () => {
  it('saves the flow content and records the mode', async () => {
    const user = userEvent.setup();
    const { adapter, saved } = makeAdapter();
    await openDesigner(adapter);
    await switchToFlow(user);

    await user.keyboard('/');
    await user.click(await screen.findByTestId('command-item-flow-insert-paragraph'));
    await user.type(screen.getByTestId('flow-input-0'), 'Hello');
    await user.click(screen.getByTestId('doc-save'));

    await waitFor(() => expect(saved).toHaveLength(1));
    expect(saved[0]).toMatchObject({
      mode: 'flow',
      flow: { blocks: [{ type: 'paragraph', text: 'Hello' }] },
    });
  });

  /**
   * The canvas body survives the trip. Both bodies are held at once precisely
   * so switching is not destructive — a document switched to flow and back
   * should not come home having lost its layout.
   */
  it('keeps the canvas pages on the template while in flow mode', async () => {
    const user = userEvent.setup();
    const { adapter, saved } = makeAdapter();
    await openDesigner(adapter);
    await switchToFlow(user);

    await user.click(screen.getByTestId('doc-save'));

    await waitFor(() => expect(saved).toHaveLength(1));
    expect(saved[0]).toHaveProperty('pages');
  });
});
