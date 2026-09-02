import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { DocumentDesignerScreen } from '@amroksaleh/features/document-designer';
import type {
  DocumentDesignerAdapter,
  SavedTemplate,
} from '@amroksaleh/features/document-designer';
import type { DocBlock } from '@amroksaleh/ui/documents/blocks';

/**
 * THE TWO DELETES THAT USED TO FIRE ON THE FIRST CLICK.
 *
 * `File ▸ Delete this saved template` sits directly under "Open saved", and the
 * palette's × sits a few pixels from a scope dropdown. Both went straight to
 * the API, and neither is undoable — the designer's undo stack holds document
 * edits, not rows.
 *
 * The first test in each pair is the one that matters: it asserts the adapter
 * is NOT called. A confirmation that appears but does not actually gate the
 * action is worse than none, because it reads as protection.
 *
 * The rest assert the dialog SAYS what will be lost. A block can be shared with
 * a whole tenant, and the designer knew that and mentioned it nowhere — it even
 * reported a tenant-wide deletion as "Block deleted from your library".
 */

/** Seeded and shared blocks, as the API returns them. */
const TEXT_EL = {
  id: 'e1',
  type: 'text',
  x: 0,
  y: 0,
  w: 50,
  h: 10,
  rotation: 0,
  z: 1,
  text: 'Hi',
  style: {},
};

function blockRow(over: Partial<DocBlock> = {}): DocBlock {
  return {
    id: '77',
    name: 'Letterhead',
    scope: 'personal',
    w: 100,
    h: 20,
    elements: [TEXT_EL],
    ...over,
  } as unknown as DocBlock;
}

function makeAdapter(blocks: DocBlock[], templates: SavedTemplate[] = []) {
  const adapter: DocumentDesignerAdapter = {
    listTemplates: jest.fn(async () => templates),
    saveTemplate: jest.fn(async (_t, id) => id ?? '900'),
    deleteTemplate: jest.fn(async () => {}),
    listBlocks: jest.fn(async () => blocks),
    saveBlock: jest.fn(async (b: DocBlock) => b.id),
    deleteBlock: jest.fn(async () => {}),
  };
  return adapter;
}

describe('deleting a block', () => {
  it('does not call the adapter until the confirmation is accepted', async () => {
    const user = userEvent.setup();
    const adapter = makeAdapter([blockRow()]);
    render(<DocumentDesignerScreen adapter={adapter} />);
    await screen.findByTestId('doc-block-delete-77');

    await user.click(screen.getByTestId('doc-block-delete-77'));

    await screen.findByTestId('doc-confirm-delete');
    expect(adapter.deleteBlock).not.toHaveBeenCalled();

    await user.click(screen.getByTestId('doc-confirm-cancel'));
    expect(adapter.deleteBlock).not.toHaveBeenCalled();
  });

  it('deletes once confirmed', async () => {
    const user = userEvent.setup();
    const adapter = makeAdapter([blockRow()]);
    render(<DocumentDesignerScreen adapter={adapter} />);
    await screen.findByTestId('doc-block-delete-77');

    await user.click(screen.getByTestId('doc-block-delete-77'));
    await user.click(await screen.findByTestId('doc-confirm-accept'));

    await waitFor(() => expect(adapter.deleteBlock).toHaveBeenCalledWith('77'));
  });

  it('warns that a tenant-wide block goes for everyone', async () => {
    const user = userEvent.setup();
    const adapter = makeAdapter([blockRow({ scope: 'tenant' })]);
    render(<DocumentDesignerScreen adapter={adapter} />);
    await screen.findByTestId('doc-block-delete-77');

    await user.click(screen.getByTestId('doc-block-delete-77'));

    expect(await screen.findByTestId('doc-confirm-consequence')).toHaveTextContent(
      /everyone in your tenant/i
    );
  });

  it('says a seeded block is the organisation’s, not the author’s', async () => {
    const user = userEvent.setup();
    // `isSystem` reaches the client only because the row mapper now carries
    // is_system. Before that the designer could not tell a seeded starter from
    // a block somebody wrote, and offered the same bare × for both.
    const adapter = makeAdapter([blockRow({ scope: 'tenant', isSystem: true })]);
    render(<DocumentDesignerScreen adapter={adapter} />);
    await screen.findByTestId('doc-block-delete-77');

    await user.click(screen.getByTestId('doc-block-delete-77'));

    expect(await screen.findByTestId('doc-confirm-consequence')).toHaveTextContent(
      /set up for your organisation/i
    );
  });

  it('stays quiet for a personal block, which costs nobody else anything', async () => {
    const user = userEvent.setup();
    const adapter = makeAdapter([blockRow({ scope: 'personal' })]);
    render(<DocumentDesignerScreen adapter={adapter} />);
    await screen.findByTestId('doc-block-delete-77');

    await user.click(screen.getByTestId('doc-block-delete-77'));

    await screen.findByTestId('doc-confirm-delete');
    // A warning that fires every time is a warning nobody reads.
    expect(screen.queryByTestId('doc-confirm-consequence')).toBeNull();
  });
});
