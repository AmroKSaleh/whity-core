import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { DocumentDesignerScreen } from '@amroksaleh/features/document-designer';
import type { DocumentDesignerAdapter } from '@amroksaleh/features/document-designer';

/**
 * "Save as a copy" — starting from an existing template without overwriting it.
 *
 * THE HOLE THIS FILLS IS A SAFETY ONE, not a convenience. `doSave` updates in
 * place whenever the editor has a `currentId`, and an update deliberately
 * leaves the stored scope alone. So opening a TENANT-WIDE template, changing
 * something, and pressing Save rewrote the template the whole tenant uses — and
 * nothing on screen said that was what Save meant here.
 *
 * These tests are therefore mostly about what the copy must NOT do: not touch
 * the original row, not inherit the published scope, and not leave the editor
 * still pointed at the original.
 */

const TENANT_TEMPLATE = {
  id: '77',
  name: 'Company invoice',
  scope: 'tenant',
  updatedAt: '2026-01-02T00:00:00Z',
  data: {
    version: 2 as const,
    name: 'Company invoice',
    page: { widthMm: 210, heightMm: 297, marginMm: 10, background: '#ffffff' },
    placeholders: [],
    pages: [{ id: 'p1', elements: [] }],
  },
};

type SaveCall = { id: string | undefined; scope: string | undefined; name: string };

function makeAdapter() {
  const saves: SaveCall[] = [];
  const adapter: DocumentDesignerAdapter = {
    listTemplates: jest.fn(async () => [TENANT_TEMPLATE] as never),
    saveTemplate: jest.fn(async (template, id, scope) => {
      saves.push({
        id,
        scope: scope as string | undefined,
        name: (template as unknown as { name: string }).name,
      });
      return id ?? '901';
    }),
    deleteTemplate: jest.fn(async () => {}),
    listBlocks: jest.fn(async () => []),
    saveBlock: jest.fn(async () => '1'),
    deleteBlock: jest.fn(async () => {}),
  };
  return { adapter, saves };
}

/**
 * Invoke a command through the `/` palette.
 *
 * The palette is built from the same menu tree (`paletteItemsFromMenus`) and
 * addresses items by id, which the menubar cannot: "Save" and "Save as a copy"
 * share a prefix, and a rendered label carries its keyboard shortcut. It is
 * also the only one of the two that works here — Radix submenu items do not
 * fire their `onSelect` under jsdom, so driving "Templates > Open saved" by
 * clicking silently loads nothing.
 */
async function runCommand(user: ReturnType<typeof userEvent.setup>, id: string) {
  await user.keyboard('/');
  await user.click(await screen.findByTestId(`command-item-${id}`));
}

/** Open the designer and load the saved tenant template into it. */
async function openTenantTemplate(
  user: ReturnType<typeof userEvent.setup>,
  adapter: DocumentDesignerAdapter
) {
  render(<DocumentDesignerScreen adapter={adapter} />);
  await screen.findByTestId('doc-top-bar');
  await waitFor(() => expect(adapter.listTemplates).toHaveBeenCalled());

  await runCommand(user, 'open-saved-77');

  // ASSERT THE PRECONDITION. Without this, a load that silently did nothing
  // leaves the editor on a blank untitled document — where "saved without an
  // id" and "filed as personal" are trivially true, and three of these tests
  // pass while testing nothing at all. They did, before this line existed.
  await waitFor(() => expect(screen.getByTestId('doc-name')).toHaveValue('Company invoice'));
}

describe('saving a copy', () => {
  it('CREATES rather than updating, leaving the original row alone', async () => {
    const user = userEvent.setup();
    const { adapter, saves } = makeAdapter();
    await openTenantTemplate(user, adapter);

    await runCommand(user, 'save-as-copy');

    await waitFor(() => expect(saves).toHaveLength(1));
    // No id passed = a create. Passing '77' would rewrite the tenant's template.
    expect(saves[0].id).toBeUndefined();
  });

  /**
   * A copy of somebody's tenant template is not the same act as publishing your
   * version of it to the tenant. Promotion is a deliberate step, and it lives
   * where the placement and permission tag that belong beside it live.
   */
  it('files the copy as personal, whatever the original was', async () => {
    const user = userEvent.setup();
    const { adapter, saves } = makeAdapter();
    await openTenantTemplate(user, adapter);

    await runCommand(user, 'save-as-copy');

    await waitFor(() => expect(saves).toHaveLength(1));
    expect(saves[0].scope).toBe('personal');
  });

  it('gives the copy a distinct name', async () => {
    const user = userEvent.setup();
    const { adapter, saves } = makeAdapter();
    await openTenantTemplate(user, adapter);

    await runCommand(user, 'save-as-copy');

    await waitFor(() => expect(saves).toHaveLength(1));
    expect(saves[0].name).toBe('Company invoice (copy)');
  });

  /**
   * THE ONE THAT MATTERS MOST. If the editor stayed pointed at the original,
   * the very next Save would land straight back on the tenant's template — the
   * bug this feature exists to prevent, reintroduced one keystroke later.
   */
  it('leaves the editor editing the COPY, so the next save cannot hit the original', async () => {
    const user = userEvent.setup();
    const { adapter, saves } = makeAdapter();
    await openTenantTemplate(user, adapter);

    await runCommand(user, 'save-as-copy');
    await waitFor(() => expect(saves).toHaveLength(1));

    await runCommand(user, 'save');

    await waitFor(() => expect(saves).toHaveLength(2));
    expect(saves[1].id).toBe('901');
    expect(saves[1].id).not.toBe('77');
  });

  /** The ordinary Save must keep updating in place — this adds a path, not a change. */
  it('does not change what plain Save does', async () => {
    const user = userEvent.setup();
    const { adapter, saves } = makeAdapter();
    await openTenantTemplate(user, adapter);

    await runCommand(user, 'save');

    await waitFor(() => expect(saves).toHaveLength(1));
    expect(saves[0].id).toBe('77');
    // An update passes no scope, so it cannot silently re-file the template.
    expect(saves[0].scope).toBeUndefined();
  });

  it('is offered in the command palette too', async () => {
    const user = userEvent.setup();
    const { adapter } = makeAdapter();
    render(<DocumentDesignerScreen adapter={adapter} />);
    await screen.findByTestId('doc-top-bar');

    await user.keyboard('/');

    expect(await screen.findByTestId('command-item-save-as-copy')).toBeInTheDocument();
  });
});
