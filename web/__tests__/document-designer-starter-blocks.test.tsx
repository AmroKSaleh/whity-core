import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { DocumentDesignerScreen } from '@amroksaleh/features/document-designer';
import type {
  DocumentDesignerAdapter,
  SavedTemplate,
} from '@amroksaleh/features/document-designer';
import type { DocBlock } from '@amroksaleh/ui/documents/blocks';
import type { DocTemplate } from '@amroksaleh/ui/documents/types';

/**
 * THE STARTER BLOCKS, AND THE HOLE THEY USED TO LEAVE IN THE PDF.
 *
 * `STARTER_BLOCKS` ("Company header", "Company footer") exist only in the
 * client bundle: `refreshBlocks` merges them into the library so the Blocks
 * panel is never empty for a tenant that predates per-tenant seeding. They
 * carry symbolic ids — `sys-header` — that no backend row has.
 *
 * Placing one used to write `{type:'blockInstance', blockId:'sys-header'}`
 * straight into the template. The canvas resolves that against the same
 * in-memory library and draws it perfectly. The SERVER cannot:
 * `DocumentRenderer::resolveBlocks()` skips any id that is not all digits, and
 * the render harness draws a missing block as empty. The document the customer
 * receives has a blank space where its header was, and nothing anywhere reports
 * it — designer, preview and print all agree the document is fine.
 *
 * These tests are written against the ADAPTER — the only seam the designer
 * talks to the world through — so they assert what actually reaches the server
 * rather than what the component believes. The screen had no test file at all
 * before this, which is a large part of why two defects of this kind could sit
 * in it.
 */

const PAGE = { widthMm: 210, heightMm: 297, marginMm: 10, background: '#ffffff' };

/** A minimal element, enough for `makeBlockFromElements` to accept. */
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

/**
 * A recording adapter. `saveBlock` answers with backend-shaped NUMERIC ids, the
 * way the real API does — which is the whole point: the id a starter is placed
 * under must be one of these, never `sys-header`.
 */
function makeAdapter(overrides: Partial<DocumentDesignerAdapter> = {}) {
  const savedBlocks: DocBlock[] = [];
  const savedTemplates: Array<{ template: DocTemplate; id?: string; scope?: string }> = [];
  let nextBlockId = 500;

  const adapter: DocumentDesignerAdapter = {
    listTemplates: jest.fn(async (): Promise<SavedTemplate[]> => []),
    saveTemplate: jest.fn(async (template, id, scope) => {
      savedTemplates.push({ template, id, scope });
      return id ?? '900';
    }),
    deleteTemplate: jest.fn(async () => {}),
    listBlocks: jest.fn(async () => [...savedBlocks]),
    saveBlock: jest.fn(async (block: DocBlock) => {
      if (/^\d+$/.test(block.id)) {
        return block.id;
      }
      const id = String((nextBlockId += 1));
      savedBlocks.push({ ...block, id });
      return id;
    }),
    deleteBlock: jest.fn(async () => {}),
    ...overrides,
  };

  return { adapter, savedBlocks, savedTemplates };
}

/** Every blockInstance pointer in a template, in page order. */
function instancePointers(template: DocTemplate): string[] {
  return template.pages.flatMap((p) =>
    p.elements.filter((e) => e.type === 'blockInstance').map((e) => (e as { blockId: string }).blockId)
  );
}

async function renderDesigner(adapter: DocumentDesignerAdapter) {
  render(<DocumentDesignerScreen adapter={adapter} />);
  // The starters arrive only after the mount effect's listBlocks() resolves.
  await screen.findByTestId('doc-block-insert-sys-header');
}

describe('inserting a starter block', () => {
  it('persists the starter FIRST, so the template never points at a client-only id', async () => {
    const user = userEvent.setup();
    const { adapter, savedTemplates } = makeAdapter();
    await renderDesigner(adapter);

    await user.click(screen.getByTestId('doc-block-insert-sys-header'));
    await user.click(screen.getByTestId('doc-save'));

    await waitFor(() => expect(savedTemplates).toHaveLength(1));

    const pointers = instancePointers(savedTemplates[0].template);
    expect(pointers).toHaveLength(1);
    // The assertion that matters. `sys-header` here is the bug: it renders on
    // the canvas and as nothing at all in the PDF.
    expect(pointers[0]).not.toBe('sys-header');
    expect(pointers[0]).toMatch(/^\d+$/);
  });

  it('saves the starter as personal, not with its own system scope', async () => {
    const user = userEvent.setup();
    const { adapter } = makeAdapter();
    await renderDesigner(adapter);

    await user.click(screen.getByTestId('doc-block-insert-sys-header'));

    await waitFor(() => expect(adapter.saveBlock).toHaveBeenCalled());
    // STARTER_BLOCKS carry scope 'system'. Creating a system-scoped block is a
    // publish action, which most authors may not perform — sending it would 403
    // exactly the people this fallback exists for. The renderer resolves blocks
    // by id within the tenant rather than by who is reading, so personal is
    // enough for the PDF to be right for every recipient.
    expect((adapter.saveBlock as jest.Mock).mock.calls[0][0]).toMatchObject({
      name: 'Company header',
      scope: 'personal',
    });
  });

  it('places nothing when the starter cannot be saved', async () => {
    const user = userEvent.setup();
    const { adapter, savedTemplates } = makeAdapter({
      saveBlock: jest.fn(async () => {
        throw new Error('Publishing a shared block requires documents:publish');
      }),
    });
    await renderDesigner(adapter);

    await user.click(screen.getByTestId('doc-block-insert-sys-header'));
    await user.click(screen.getByTestId('doc-save'));

    await waitFor(() => expect(savedTemplates).toHaveLength(1));
    // A visible failure now beats a silent hole at print time: refusing to
    // place it is the point, not a side effect.
    expect(instancePointers(savedTemplates[0].template)).toEqual([]);
  });

  it('does not re-save a block that already has a backend id', async () => {
    const user = userEvent.setup();
    const existing: DocBlock = {
      id: '77',
      name: 'Letterhead',
      scope: 'tenant',
      w: 100,
      h: 20,
      elements: [TEXT_EL],
    } as unknown as DocBlock;
    const { adapter, savedTemplates } = makeAdapter({
      listBlocks: jest.fn(async () => [existing]),
    });
    render(<DocumentDesignerScreen adapter={adapter} />);
    await screen.findByTestId('doc-block-insert-77');

    await user.click(screen.getByTestId('doc-block-insert-77'));
    await user.click(screen.getByTestId('doc-save'));

    await waitFor(() => expect(savedTemplates).toHaveLength(1));
    expect(adapter.saveBlock).not.toHaveBeenCalled();
    expect(instancePointers(savedTemplates[0].template)).toEqual(['77']);
  });
});

describe('changing a block’s visibility', () => {
  /**
   * Publishing a STARTER creates it, so the backend answers with a different
   * id. `refreshBlocks` then drops the starter from the library (it matches by
   * name), so an instance still pointing at `sys-header` resolves to nothing
   * and renders as "missing block".
   *
   * A visibility change is the last action anyone would expect to damage the
   * document, which is exactly why this went unnoticed.
   */
  it('carries already-placed instances over to the new id', async () => {
    const user = userEvent.setup();
    const { adapter, savedTemplates } = makeAdapter();
    await renderDesigner(adapter);

    await user.click(screen.getByTestId('doc-block-insert-sys-header'));
    await waitFor(() => expect(adapter.saveBlock).toHaveBeenCalledTimes(1));

    // The block is real now and appears under its backend id. Publish it.
    const backendId = await (adapter.saveBlock as jest.Mock).mock.results[0].value;
    const scopeSelect = await screen.findByTestId(`doc-block-scope-${backendId}`);
    await user.selectOptions(scopeSelect, 'tenant');

    await user.click(screen.getByTestId('doc-save'));
    await waitFor(() => expect(savedTemplates).toHaveLength(1));

    const pointers = instancePointers(savedTemplates[0].template);
    expect(pointers).toEqual([backendId]);
  });
});

/**
 * MERGING THE BUILT-IN STARTERS WITH THE TENANT'S SEEDED ONES.
 *
 * `refreshBlocks` adds a built-in starter to the library only when the tenant
 * has no equivalent. It used to decide that by comparing DISPLAY NAMES — and a
 * display name is the one thing about a seeded block a tenant is invited to
 * change.
 *
 * `starter_key` is the identity the server assigns and never accepts from a
 * client (migration 075). It has been on every block row since #1013; the
 * client was dropping it.
 */
describe('starter blocks merged into the tenant library', () => {
  const seeded = (over: Record<string, unknown> = {}) =>
    ({
      id: '90',
      name: 'Company header',
      scope: 'tenant',
      isSystem: true,
      starterKey: 'sys-header',
      w: 180,
      h: 21,
      elements: [TEXT_EL],
      ...over,
    }) as unknown as DocBlock;

  it('does not re-add a built-in whose seeded twin is present', async () => {
    const { adapter } = makeAdapter({ listBlocks: jest.fn(async () => [seeded()]) });
    render(<DocumentDesignerScreen adapter={adapter} />);
    await screen.findByTestId('doc-block-insert-90');

    // The seeded row stands in for the built-in; the symbolic id must be absent.
    expect(screen.queryByTestId('doc-block-insert-sys-header')).toBeNull();
  });

  it('still does not re-add it after the tenant RENAMES it', async () => {
    // The regression. Under a name match this returned no hit, so the built-in
    // came back and sat beside the real block: two entries for one block, one
    // of them a phantom in nobody's library — and, since starters are persisted
    // on insert, inserting the phantom would then have made a third.
    const { adapter } = makeAdapter({
      listBlocks: jest.fn(async () => [seeded({ name: 'Acme letterhead' })]),
    });
    render(<DocumentDesignerScreen adapter={adapter} />);
    await screen.findByTestId('doc-block-insert-90');

    expect(screen.queryByTestId('doc-block-insert-sys-header')).toBeNull();
  });

  it('falls back to the name for a row seeded before starter_key existed', async () => {
    const { adapter } = makeAdapter({
      listBlocks: jest.fn(async () => [seeded({ starterKey: undefined })]),
    });
    render(<DocumentDesignerScreen adapter={adapter} />);
    await screen.findByTestId('doc-block-insert-90');

    expect(screen.queryByTestId('doc-block-insert-sys-header')).toBeNull();
  });

  it('still offers a built-in the tenant has no row for', async () => {
    // Only the header is seeded here, so the footer must still appear —
    // otherwise "never re-add" would have quietly become "never offer".
    const { adapter } = makeAdapter({ listBlocks: jest.fn(async () => [seeded()]) });
    render(<DocumentDesignerScreen adapter={adapter} />);

    await screen.findByTestId('doc-block-insert-sys-footer');
  });
});
