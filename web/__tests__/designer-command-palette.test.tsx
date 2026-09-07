import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { DocumentDesignerScreen } from '@amroksaleh/features/document-designer';
import type { DocumentDesignerAdapter } from '@amroksaleh/features/document-designer';
import type { DocBlock } from '@amroksaleh/ui/documents/blocks';

/**
 * The `/` command palette in the document designer.
 *
 * THE ITEMS ARE DERIVED FROM THE MENU REGISTRY, not listed. That is the
 * property worth testing: a command added to the menus has to appear here
 * without anyone remembering, and a saved block has to be insertable by typing
 * its name rather than by walking Insert ▸ Block ▸ …
 *
 * The most important test in this file is the one about typing a slash into a
 * text field. `/` is bound bare, so the guard that distinguishes "the canvas
 * has focus" from "somebody is typing" is the only thing standing between this
 * feature and a keystroke that silently eats a character in every input on the
 * screen.
 */

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

function makeAdapter(blocks: DocBlock[] = []) {
  const adapter: DocumentDesignerAdapter = {
    listTemplates: jest.fn(async () => []),
    saveTemplate: jest.fn(async (_t, id) => id ?? '900'),
    deleteTemplate: jest.fn(async () => {}),
    listBlocks: jest.fn(async () => blocks),
    saveBlock: jest.fn(async (b: DocBlock) => b.id),
    deleteBlock: jest.fn(async () => {}),
  };
  return adapter;
}

async function openDesigner(adapter: DocumentDesignerAdapter) {
  render(<DocumentDesignerScreen adapter={adapter} />);
  // The blocks fetch resolves before anything is stable.
  await screen.findByTestId('doc-top-bar');
}

describe('opening the palette', () => {
  it('opens on / when the canvas has focus', async () => {
    const user = userEvent.setup();
    await openDesigner(makeAdapter());

    await user.keyboard('/');

    expect(await screen.findByTestId('command-palette')).toBeInTheDocument();
  });

  it('opens on Ctrl+K as well', async () => {
    const user = userEvent.setup();
    await openDesigner(makeAdapter());

    await user.keyboard('{Control>}k{/Control}');

    expect(await screen.findByTestId('command-palette')).toBeInTheDocument();
  });

  /**
   * THE TEST THIS FEATURE LIVES OR DIES BY.
   *
   * `/` is bound with no modifier. If the binding fired while somebody was
   * typing, every slash in every text element, placeholder name and batch cell
   * would vanish and open a dialog instead — a data-entry bug affecting text
   * nobody could then type at all.
   *
   * The existing keydown handler already returns early for INPUT / TEXTAREA /
   * contentEditable targets. This asserts the binding sits BELOW that guard
   * rather than above it.
   */
  it('does NOT open when a slash is typed into a text field — the slash is typed', async () => {
    const user = userEvent.setup();
    await openDesigner(makeAdapter());

    const name = screen.getByTestId('doc-name');
    await user.clear(name);
    await user.type(name, 'Q1/2026');

    expect(screen.queryByTestId('command-palette')).not.toBeInTheDocument();
    expect(name).toHaveValue('Q1/2026');
  });

  it('closes on Escape', async () => {
    const user = userEvent.setup();
    await openDesigner(makeAdapter());

    await user.keyboard('/');
    await screen.findByTestId('command-palette');
    await user.keyboard('{Escape}');

    await waitFor(() => expect(screen.queryByTestId('command-palette')).not.toBeInTheDocument());
  });
});

describe('what the palette offers', () => {
  it('carries the editor’s own commands, derived from the menus', async () => {
    const user = userEvent.setup();
    await openDesigner(makeAdapter());
    await user.keyboard('/');

    // Not a hand-written list anywhere: these exist because the menu registry
    // declares them.
    await user.type(screen.getByTestId('command-palette-input'), 'text');

    expect(await screen.findByTestId('command-item-insert-text')).toBeInTheDocument();
  });

  /**
   * The Gutenberg half. A saved block is reachable by typing its name, instead
   * of Insert ▸ Block ▸ <scope> ▸ name.
   */
  it('finds a saved block by name and inserts it', async () => {
    const user = userEvent.setup();
    const block = {
      id: '77',
      name: 'Letterhead',
      scope: 'tenant',
      w: 100,
      h: 20,
      elements: [TEXT_EL],
    } as unknown as DocBlock;
    const adapter = makeAdapter([block]);
    await openDesigner(adapter);
    await screen.findByTestId('doc-block-insert-77');

    await user.keyboard('/');
    await user.type(screen.getByTestId('command-palette-input'), 'letterhead');

    const item = await screen.findByTestId('command-item-insert-block-77');
    await user.click(item);

    // The palette closes and the block is on the page — asserted through the
    // layers list, which names every element currently placed.
    await waitFor(() => expect(screen.queryByTestId('command-palette')).not.toBeInTheDocument());
    expect(await screen.findByText('Block')).toBeInTheDocument();
  });

  it('says so when nothing matches, rather than showing an empty box', async () => {
    const user = userEvent.setup();
    await openDesigner(makeAdapter());
    await user.keyboard('/');

    await user.type(screen.getByTestId('command-palette-input'), 'zzzznotacommand');

    expect(await screen.findByTestId('command-palette-empty')).toBeInTheDocument();
  });
});

describe('keyboard navigation', () => {
  it('runs the highlighted command on Enter', async () => {
    const user = userEvent.setup();
    const adapter = makeAdapter();
    await openDesigner(adapter);

    await user.keyboard('/');
    await user.type(screen.getByTestId('command-palette-input'), 'save');
    await user.keyboard('{Enter}');

    // Save is a registry command like any other; running it through the palette
    // must reach the adapter exactly as the menu item does.
    await waitFor(() => expect(adapter.saveTemplate).toHaveBeenCalled());
  });
});
